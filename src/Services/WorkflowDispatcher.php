<?php

namespace AdamczykPiotr\DagWorkflows\Services;

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Events\WorkflowCancelled;
use AdamczykPiotr\DagWorkflows\Events\WorkflowPaused;
use AdamczykPiotr\DagWorkflows\Events\WorkflowResumed;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkflowDispatcher {

    private const array ACTIVE_STATUSES = [RunStatus::PENDING, RunStatus::RUNNING];
    private const array NON_TERMINAL_STATUSES = [RunStatus::PENDING, RunStatus::RUNNING, RunStatus::PAUSED];


    /**
     * @param Workflow $workflow
     * @param bool $force
     * @return bool
     */
    public function dispatchWorkflow(Workflow $workflow, bool $force = false): bool {
        if ($workflow->status !== RunStatus::PENDING && $force === false) {
            return false;
        }

        $entrypoint = WorkflowTask::query()
            ->where(WorkflowTask::ATTRIBUTE_WORKFLOW_ID, $workflow->id)
            ->where(WorkflowTask::ATTRIBUTE_STATUS, RunStatus::PENDING)
            ->whereDoesntHave(WorkflowTask::RELATION_DEPENDENCIES)
            ->with(WorkflowTask::RELATION_INITIAL_STEP)
            ->get();

        $entrypoint->each(fn(WorkflowTask $task) => $this->dispatchTask($task, $force));
        return true;
    }


    /**
     * @param WorkflowTask $task
     * @param bool $force
     * @return bool
     */
    public function dispatchTask(WorkflowTask $task, bool $force = false): bool {
        if ($task->status !== RunStatus::PENDING && $force === false) {
            return false;
        }

        if ($task->initialStep === null) {
            return false;
        }

        return $this->dispatchStep($task->initialStep, $force);
    }


    /**
     * @param WorkflowTask $task
     * @return bool
     */
    public function dispatchDependantTasks(WorkflowTask $task): bool {
        $dependantTasks = $task->dependants()
            ->where(WorkflowTask::ATTRIBUTE_STATUS, RunStatus::PENDING)
            ->whereDoesntHave(
                WorkflowTask::RELATION_DEPENDENCIES,
                fn(Builder $builder) => $builder->where(WorkflowTask::ATTRIBUTE_STATUS, '!=', RunStatus::COMPLETED) // @phpstan-ignore-line
            )
            ->with(WorkflowTask::RELATION_INITIAL_STEP)
            ->get();

        $dependantTasks->each(fn(WorkflowTask $dependantTask) => $this->dispatchTask($dependantTask));
        return true;
    }


    /**
     * @param WorkflowTaskStep $step
     * @param bool $force
     * @return bool
     */
    public function dispatchStep(WorkflowTaskStep $step, bool $force = false): bool {
        if ($force === false && $step->status !== RunStatus::PENDING) {
            return false;
        }

        /** @var object{workflowTaskStep: WorkflowTaskStep} $job */
        $job = unserialize(
            base64_decode($step->payload)
        );

        $job->workflowTaskStep = $step; // @phpstan-ignore-line
        dispatch($job);
        return true;
    }


    /**
     * @param WorkflowTaskStep $step
     * @return bool
     * @throws Throwable
     */
    public function retryStep(WorkflowTaskStep $step): bool {
        DB::transaction(function() use ($step) {
            $step->status = RunStatus::PENDING;
            $step->failed_at = null;
            $step->completed_at = null;
            $step->started_at = null;
            $step->save();

            WorkflowTaskStep::query()
                ->where(WorkflowTaskStep::ATTRIBUTE_TASK_ID, $step->task_id)
                ->where(WorkflowTaskStep::ATTRIBUTE_ORDER, '>', $step->order)
                ->update([
                    WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::PENDING,
                    WorkflowTaskStep::ATTRIBUTE_FAILED_AT => null,
                    WorkflowTaskStep::ATTRIBUTE_COMPLETED_AT => null,
                    WorkflowTaskStep::ATTRIBUTE_STARTED_AT => null,
                ]);

            $task = $step->task;
            $task->status = RunStatus::RUNNING;
            $task->failed_at = null;
            $task->completed_at = null;
            $task->save();

            $workflow = $task->workflow;
            if ($workflow->status !== RunStatus::PENDING) {
                $workflow->status = RunStatus::RUNNING;
                $workflow->failed_at = null;
                $workflow->completed_at = null;
                $workflow->save();
            }

            $this->resetDependantTasksToPending($task);
        });

        return $this->dispatchStep($step);
    }


    /**
     * @param WorkflowTaskStep $step
     * @return void
     * @throws Throwable
     */
    public function failStep(WorkflowTaskStep $step): void {
        DB::transaction(function() use ($step) {
            $step->status = RunStatus::FAILED;
            $step->failed_at = now();
            $step->save();

            $task = $step->task;
            $task->status = RunStatus::FAILED;
            $task->failed_at = now();
            $task->save();

            $workflow = $task->workflow;
            $workflow->status = RunStatus::FAILED;
            $workflow->failed_at = now();
            $workflow->save();

            $task->steps()
                ->where(WorkflowTask::ATTRIBUTE_STATUS, RunStatus::PENDING)
                ->update([
                    WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::CANCELLED,
                    WorkflowTaskStep::ATTRIBUTE_FAILED_AT => now(),
                ]);

            $this->cancelDependantTasks($task, includePaused: false);
        });
    }


    /*
    |--------------------------------------------------------------------------
    | Pause Operations
    |--------------------------------------------------------------------------
    */


    /**
     * @param Workflow $workflow
     * @param string|null $reason
     * @return bool
     * @throws Throwable
     */
    public function pauseWorkflow(Workflow $workflow, ?string $reason = null): bool {
        if ($workflow->status->canBePaused() === false) {
            return false;
        }

        DB::transaction(function() use ($workflow, $reason) {
            $workflow->status = RunStatus::PAUSED;
            $workflow->paused_at = now();
            $workflow->pause_reason = $reason;
            $workflow->save();

            $workflow->tasks()
                ->whereIn(WorkflowTask::ATTRIBUTE_STATUS, self::ACTIVE_STATUSES)
                ->update($this->buildPauseUpdate());

            WorkflowTaskStep::query()
                ->where(WorkflowTaskStep::ATTRIBUTE_WORKFLOW_ID, $workflow->id)
                ->whereIn(WorkflowTaskStep::ATTRIBUTE_STATUS, self::ACTIVE_STATUSES)
                ->update($this->buildStepPauseUpdate($reason));
        });

        WorkflowPaused::dispatch($workflow, reason: $reason);

        return true;
    }


    /**
     * @param Workflow $workflow
     * @return bool
     * @throws Throwable
     */
    public function resumeWorkflow(Workflow $workflow): bool {
        if ($workflow->status->canBeResumed() === false) {
            return false;
        }

        DB::transaction(function() use ($workflow) {
            $workflow->status = RunStatus::RUNNING;
            $workflow->paused_at = null;
            $workflow->pause_reason = null;
            $workflow->save();

            $workflow->tasks()
                ->where(WorkflowTask::ATTRIBUTE_STATUS, RunStatus::PAUSED)
                ->update($this->buildResumeUpdate());

            WorkflowTaskStep::query()
                ->where(WorkflowTaskStep::ATTRIBUTE_WORKFLOW_ID, $workflow->id)
                ->where(WorkflowTaskStep::ATTRIBUTE_STATUS, RunStatus::PAUSED)
                ->update($this->buildStepResumeUpdate());
        });

        WorkflowResumed::dispatch($workflow);

        return $this->dispatchWorkflow($workflow, force: true);
    }


    /**
     * @param Workflow $workflow
     * @return bool
     * @throws Throwable
     */
    public function cancelWorkflow(Workflow $workflow): bool {
        if ($workflow->status->isTerminal()) {
            return false;
        }

        DB::transaction(function() use ($workflow) {
            $workflow->status = RunStatus::CANCELLED;
            $workflow->failed_at = now();
            $workflow->paused_at = null;
            $workflow->pause_reason = null;
            $workflow->save();

            $workflow->tasks()
                ->whereIn(WorkflowTask::ATTRIBUTE_STATUS, self::NON_TERMINAL_STATUSES)
                ->update($this->buildCancelUpdate());

            WorkflowTaskStep::query()
                ->where(WorkflowTaskStep::ATTRIBUTE_WORKFLOW_ID, $workflow->id)
                ->whereIn(WorkflowTaskStep::ATTRIBUTE_STATUS, self::NON_TERMINAL_STATUSES)
                ->update($this->buildStepCancelUpdate());
        });

        WorkflowCancelled::dispatch($workflow);

        return true;
    }


    /**
     * @param WorkflowTask $task
     * @param string|null $reason
     * @return bool
     * @throws Throwable
     */
    public function pauseTask(WorkflowTask $task, ?string $reason = null): bool {
        if ($task->status->canBePaused() === false) {
            return false;
        }

        $workflow = $task->workflow;

        DB::transaction(function() use ($task, $workflow, $reason) {
            $task->status = RunStatus::PAUSED;
            $task->paused_at = now();
            $task->save();

            $task->steps()
                ->whereIn(WorkflowTaskStep::ATTRIBUTE_STATUS, self::ACTIVE_STATUSES)
                ->update($this->buildStepPauseUpdate($reason));

            $this->pauseWorkflowIfNoActiveTasks($workflow, $reason);
        });

        WorkflowPaused::dispatch($workflow, $task, reason: $reason);

        return true;
    }


    /**
     * @param WorkflowTask $task
     * @return bool
     * @throws Throwable
     */
    public function resumeTask(WorkflowTask $task): bool {
        if ($task->status->canBeResumed() === false) {
            return false;
        }

        $workflow = $task->workflow;

        DB::transaction(function() use ($task, $workflow) {
            $task->status = RunStatus::PENDING;
            $task->paused_at = null;
            $task->save();

            $task->steps()
                ->where(WorkflowTaskStep::ATTRIBUTE_STATUS, RunStatus::PAUSED)
                ->update($this->buildStepResumeUpdate());

            $this->resumeWorkflowIfPaused($workflow);
        });

        WorkflowResumed::dispatch($workflow, $task);

        return $this->dispatchTask($task, force: true);
    }


    /**
     * @param WorkflowTask $task
     * @return bool
     * @throws Throwable
     */
    public function cancelTask(WorkflowTask $task): bool {
        if ($task->status->isTerminal()) {
            return false;
        }

        $workflow = $task->workflow;

        DB::transaction(function() use ($task, $workflow) {
            $task->status = RunStatus::CANCELLED;
            $task->failed_at = now();
            $task->paused_at = null;
            $task->save();

            $task->steps()
                ->whereIn(WorkflowTaskStep::ATTRIBUTE_STATUS, self::NON_TERMINAL_STATUSES)
                ->update($this->buildStepCancelUpdate());

            $this->cancelDependantTasks($task);
            $this->finalizeWorkflowStatus($workflow);
        });

        WorkflowCancelled::dispatch($workflow, $task);

        return true;
    }


    /**
     * @param WorkflowTaskStep $step
     * @param string|null $reason
     * @return bool
     * @throws Throwable
     */
    public function pauseStep(WorkflowTaskStep $step, ?string $reason = null): bool {
        if ($step->status->canBePaused() === false) {
            return false;
        }

        $task = $step->task;
        $workflow = $task->workflow;

        DB::transaction(function() use ($step, $task, $workflow, $reason) {
            $step->status = RunStatus::PAUSED;
            $step->paused_at = now();
            $step->pause_reason = $reason;
            $step->save();

            $this->pauseTaskIfNoActiveSteps($task);
            $this->pauseWorkflowIfNoActiveTasks($workflow, $reason);
        });

        WorkflowPaused::dispatch($workflow, $task, $step, $reason);

        return true;
    }


    /**
     * @param WorkflowTaskStep $step
     * @return bool
     * @throws Throwable
     */
    public function resumeStep(WorkflowTaskStep $step): bool {
        if ($step->status->canBeResumed() === false) {
            return false;
        }

        $task = $step->task;
        $workflow = $task->workflow;

        DB::transaction(function() use ($step, $task, $workflow) {
            $step->status = RunStatus::PENDING;
            $step->paused_at = null;
            $step->pause_reason = null;
            $step->save();

            $this->resumeTaskIfPaused($task);
            $this->resumeWorkflowIfPaused($workflow);
        });

        WorkflowResumed::dispatch($workflow, $task, $step);

        return $this->dispatchStep($step, force: true);
    }


    /**
     * @param WorkflowTaskStep $step
     * @return bool
     * @throws Throwable
     */
    public function cancelStep(WorkflowTaskStep $step): bool {
        if ($step->status->isTerminal()) {
            return false;
        }

        $task = $step->task;
        $workflow = $task->workflow;

        DB::transaction(function() use ($step, $task, $workflow) {
            $step->status = RunStatus::CANCELLED;
            $step->failed_at = now();
            $step->paused_at = null;
            $step->pause_reason = null;
            $step->save();

            $task->steps()
                ->where(WorkflowTaskStep::ATTRIBUTE_ORDER, '>', $step->order)
                ->whereIn(WorkflowTaskStep::ATTRIBUTE_STATUS, self::NON_TERMINAL_STATUSES)
                ->update($this->buildStepCancelUpdate());

            $task->status = RunStatus::CANCELLED;
            $task->failed_at = now();
            $task->paused_at = null;
            $task->save();

            $this->cancelDependantTasks($task);
            $this->finalizeWorkflowStatus($workflow);
        });

        WorkflowCancelled::dispatch($workflow, $task, $step);

        return true;
    }


    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */


    /**
     * @param WorkflowTask $task
     * @param bool $includePaused
     * @return void
     */
    protected function cancelDependantTasks(WorkflowTask $task, bool $includePaused = true): void {
        $task->load(WorkflowTask::RELATION_RECURSIVE_DEPENDANTS);
        $dependantTaskIds = $task->getRecursiveDependantIds();

        if ($dependantTaskIds->isEmpty()) {
            return;
        }

        $statuses = $includePaused ? self::NON_TERMINAL_STATUSES : self::ACTIVE_STATUSES;
        $update = $includePaused ? $this->buildCancelUpdate() : [
            WorkflowTask::ATTRIBUTE_STATUS => RunStatus::CANCELLED,
            WorkflowTask::ATTRIBUTE_FAILED_AT => now(),
        ];
        $stepUpdate = $includePaused ? $this->buildStepCancelUpdate() : [
            WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::CANCELLED,
            WorkflowTaskStep::ATTRIBUTE_FAILED_AT => now(),
        ];

        WorkflowTask::query()
            ->whereIn(WorkflowTask::ATTRIBUTE_ID, $dependantTaskIds)
            ->whereIn(WorkflowTask::ATTRIBUTE_STATUS, $statuses)
            ->update($update);

        WorkflowTaskStep::query()
            ->whereIn(WorkflowTaskStep::ATTRIBUTE_TASK_ID, $dependantTaskIds)
            ->whereIn(WorkflowTaskStep::ATTRIBUTE_STATUS, $statuses)
            ->update($stepUpdate);
    }


    /**
     * @param WorkflowTask $task
     * @return void
     */
    protected function resetDependantTasksToPending(WorkflowTask $task): void {
        $task->load(WorkflowTask::RELATION_RECURSIVE_DEPENDANTS);
        $dependantTaskIds = $task->getRecursiveDependantIds();

        if ($dependantTaskIds->isEmpty()) {
            return;
        }

        $resetUpdate = [
            WorkflowTask::ATTRIBUTE_STATUS => RunStatus::PENDING,
            WorkflowTask::ATTRIBUTE_FAILED_AT => null,
            WorkflowTask::ATTRIBUTE_COMPLETED_AT => null,
            WorkflowTask::ATTRIBUTE_STARTED_AT => null,
        ];

        WorkflowTask::query()
            ->whereIn(WorkflowTask::ATTRIBUTE_ID, $dependantTaskIds)
            ->update($resetUpdate);

        WorkflowTaskStep::query()
            ->whereIn(WorkflowTaskStep::ATTRIBUTE_TASK_ID, $dependantTaskIds)
            ->update([
                WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::PENDING,
                WorkflowTaskStep::ATTRIBUTE_FAILED_AT => null,
                WorkflowTaskStep::ATTRIBUTE_COMPLETED_AT => null,
                WorkflowTaskStep::ATTRIBUTE_STARTED_AT => null,
            ]);
    }


    /**
     * @param Workflow $workflow
     * @return void
     */
    protected function finalizeWorkflowStatus(Workflow $workflow): void {
        $hasActiveTask = $workflow->tasks()
            ->whereIn(WorkflowTask::ATTRIBUTE_STATUS, self::NON_TERMINAL_STATUSES)
            ->exists();

        if ($hasActiveTask) {
            return;
        }

        $allCompleted = $workflow->tasks()
            ->where(WorkflowTask::ATTRIBUTE_STATUS, '!=', RunStatus::COMPLETED)
            ->doesntExist();

        if ($allCompleted) {
            $workflow->status = RunStatus::COMPLETED;
            $workflow->completed_at = now();
        } else {
            $workflow->status = RunStatus::CANCELLED;
            $workflow->failed_at = now();
        }

        $workflow->paused_at = null;
        $workflow->pause_reason = null;
        $workflow->save();
    }


    /**
     * @param Workflow $workflow
     * @param string|null $reason
     * @return void
     */
    protected function pauseWorkflowIfNoActiveTasks(Workflow $workflow, ?string $reason): void {
        if ($workflow->status !== RunStatus::RUNNING) {
            return;
        }

        $hasActiveTask = $workflow->tasks()
            ->whereIn(WorkflowTask::ATTRIBUTE_STATUS, self::ACTIVE_STATUSES)
            ->exists();

        if ($hasActiveTask === false) {
            $workflow->status = RunStatus::PAUSED;
            $workflow->paused_at = now();
            $workflow->pause_reason = $reason;
            $workflow->save();
        }
    }


    /**
     * @param WorkflowTask $task
     * @return void
     */
    protected function pauseTaskIfNoActiveSteps(WorkflowTask $task): void {
        if ($task->status !== RunStatus::RUNNING) {
            return;
        }

        $hasActiveStep = $task->steps()
            ->whereIn(WorkflowTaskStep::ATTRIBUTE_STATUS, self::ACTIVE_STATUSES)
            ->exists();

        if ($hasActiveStep === false) {
            $task->status = RunStatus::PAUSED;
            $task->paused_at = now();
            $task->save();
        }
    }


    /**
     * @param Workflow $workflow
     * @return void
     */
    protected function resumeWorkflowIfPaused(Workflow $workflow): void {
        if ($workflow->status !== RunStatus::PAUSED) {
            return;
        }

        $workflow->status = RunStatus::RUNNING;
        $workflow->paused_at = null;
        $workflow->pause_reason = null;
        $workflow->save();
    }


    /**
     * @param WorkflowTask $task
     * @return void
     */
    protected function resumeTaskIfPaused(WorkflowTask $task): void {
        if ($task->status !== RunStatus::PAUSED) {
            return;
        }

        $task->status = RunStatus::RUNNING;
        $task->paused_at = null;
        $task->save();
    }


    /**
     * @return array<string, mixed>
     */
    protected function buildPauseUpdate(): array {
        return [
            WorkflowTask::ATTRIBUTE_STATUS => RunStatus::PAUSED,
            WorkflowTask::ATTRIBUTE_PAUSED_AT => now(),
        ];
    }


    /**
     * @param string|null $reason
     * @return array<string, mixed>
     */
    protected function buildStepPauseUpdate(?string $reason): array {
        return [
            WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::PAUSED,
            WorkflowTaskStep::ATTRIBUTE_PAUSED_AT => now(),
            WorkflowTaskStep::ATTRIBUTE_PAUSE_REASON => $reason,
        ];
    }


    /**
     * @return array<string, mixed>
     */
    protected function buildResumeUpdate(): array {
        return [
            WorkflowTask::ATTRIBUTE_STATUS => RunStatus::PENDING,
            WorkflowTask::ATTRIBUTE_PAUSED_AT => null,
        ];
    }


    /**
     * @return array<string, mixed>
     */
    protected function buildStepResumeUpdate(): array {
        return [
            WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::PENDING,
            WorkflowTaskStep::ATTRIBUTE_PAUSED_AT => null,
            WorkflowTaskStep::ATTRIBUTE_PAUSE_REASON => null,
        ];
    }


    /**
     * @return array<string, mixed>
     */
    protected function buildCancelUpdate(): array {
        return [
            WorkflowTask::ATTRIBUTE_STATUS => RunStatus::CANCELLED,
            WorkflowTask::ATTRIBUTE_FAILED_AT => now(),
            WorkflowTask::ATTRIBUTE_PAUSED_AT => null,
        ];
    }


    /**
     * @return array<string, mixed>
     */
    protected function buildStepCancelUpdate(): array {
        return [
            WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::CANCELLED,
            WorkflowTaskStep::ATTRIBUTE_FAILED_AT => now(),
            WorkflowTaskStep::ATTRIBUTE_PAUSED_AT => null,
            WorkflowTaskStep::ATTRIBUTE_PAUSE_REASON => null,
        ];
    }
}
