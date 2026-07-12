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
     * Complete the step's task early: the current step becomes COMPLETED, all
     * remaining non-terminal steps become SKIPPED (they never run), and the task
     * completes as usual — dependant tasks are dispatched and the workflow status
     * is finalized. Triggered by a job calling
     * {@see \AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking::completeTaskEarly()}.
     *
     * @param WorkflowTaskStep $step
     * @return bool
     * @throws Throwable
     */
    public function completeTaskEarly(WorkflowTaskStep $step): bool {
        DB::transaction(function() use ($step) {
            $step->status = RunStatus::COMPLETED;
            $step->completed_at = now();
            $step->failed_at = null;
            if ($step->progress !== null) {
                $step->progress = 100;
            }
            $step->save();

            WorkflowTaskStep::query()
                ->where(WorkflowTaskStep::ATTRIBUTE_TASK_ID, $step->task_id)
                ->where(WorkflowTaskStep::ATTRIBUTE_ORDER, '>', $step->order)
                ->whereIn(WorkflowTaskStep::ATTRIBUTE_STATUS, RunStatus::nonTerminal())
                ->update([
                    WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::SKIPPED,
                    WorkflowTaskStep::ATTRIBUTE_COMPLETED_AT => now(),
                ]);
        });

        return $this->completeTaskAndDispatchDependants($step->task);
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
    | Step Pause Operations
    |--------------------------------------------------------------------------
    */


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

        DB::transaction(function() use ($step, $task, $reason) {
            $step->status = RunStatus::PAUSED;
            $step->paused_at = now();
            $step->pause_reason = $reason;
            $step->save();

            $this->suspendSubsequentSteps($step);
            $this->suspendDependantTasks($task);
        });

        WorkflowPaused::dispatch($step, $reason);

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

        DB::transaction(function() use ($step, $task) {
            // Mark the paused step as completed (job already ran successfully)
            $step->status = RunStatus::COMPLETED;
            $step->completed_at = now();
            $step->paused_at = null;
            $step->pause_reason = null;
            $step->save();

            $this->unsuspendSubsequentSteps($step);
            $this->unsuspendDependantTasks($task);
        });

        WorkflowResumed::dispatch($step);

        // Dispatch the next step if it exists
        $nextStep = $step->nextStep;
        if ($nextStep instanceof WorkflowTaskStep) {
            return $this->dispatchStep($nextStep, force: true);
        }

        // No next step - complete the task and dispatch dependants
        return $this->completeTaskAndDispatchDependants($task);
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
                ->whereIn(WorkflowTaskStep::ATTRIBUTE_STATUS, RunStatus::nonTerminal())
                ->update($this->buildStepCancelUpdate());

            $task->status = RunStatus::CANCELLED;
            $task->failed_at = now();
            $task->paused_at = null;
            $task->save();

            $this->cancelDependantTasks($task);
            $this->finalizeWorkflowStatus($workflow);
        });

        WorkflowCancelled::dispatch($step);

        return true;
    }


    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */


    /**
     * @param WorkflowTask $task
     * @return bool
     * @throws Throwable
     */
    protected function completeTaskAndDispatchDependants(WorkflowTask $task): bool {
        DB::transaction(function() use ($task) {
            $task->status = RunStatus::COMPLETED;
            $task->completed_at = now();
            $task->failed_at = null;
            $task->paused_at = null;
            $task->save();
        });

        // Post-commit on purpose — see DagWorkflowTrackerJobMiddleware: the readiness
        // check must see this completion committed, or concurrent completers can all
        // skip a shared dependant and strand the workflow.
        $this->dispatchDependantTasks($task);
        $this->finalizeWorkflowStatus($task->workflow);

        return true;
    }


    /**
     * @param WorkflowTaskStep $step
     * @return void
     */
    protected function suspendSubsequentSteps(WorkflowTaskStep $step): void {
        WorkflowTaskStep::query()
            ->where(WorkflowTaskStep::ATTRIBUTE_TASK_ID, $step->task_id)
            ->where(WorkflowTaskStep::ATTRIBUTE_ORDER, '>', $step->order)
            ->where(WorkflowTaskStep::ATTRIBUTE_STATUS, RunStatus::PENDING)
            ->update([
                WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::SUSPENDED,
            ]);
    }


    /**
     * @param WorkflowTaskStep $step
     * @return void
     */
    protected function unsuspendSubsequentSteps(WorkflowTaskStep $step): void {
        WorkflowTaskStep::query()
            ->where(WorkflowTaskStep::ATTRIBUTE_TASK_ID, $step->task_id)
            ->where(WorkflowTaskStep::ATTRIBUTE_ORDER, '>', $step->order)
            ->where(WorkflowTaskStep::ATTRIBUTE_STATUS, RunStatus::SUSPENDED)
            ->update([
                WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::PENDING,
            ]);
    }


    /**
     * @param WorkflowTask $task
     * @return void
     */
    protected function suspendDependantTasks(WorkflowTask $task): void {
        $task->load(WorkflowTask::RELATION_RECURSIVE_DEPENDANTS);
        $dependantTaskIds = $task->getRecursiveDependantIds();

        if ($dependantTaskIds->isEmpty()) {
            return;
        }

        WorkflowTask::query()
            ->whereIn(WorkflowTask::ATTRIBUTE_ID, $dependantTaskIds)
            ->where(WorkflowTask::ATTRIBUTE_STATUS, RunStatus::PENDING)
            ->update([
                WorkflowTask::ATTRIBUTE_STATUS => RunStatus::SUSPENDED,
            ]);

        WorkflowTaskStep::query()
            ->whereIn(WorkflowTaskStep::ATTRIBUTE_TASK_ID, $dependantTaskIds)
            ->where(WorkflowTaskStep::ATTRIBUTE_STATUS, RunStatus::PENDING)
            ->update([
                WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::SUSPENDED,
            ]);
    }


    /**
     * @param WorkflowTask $task
     * @return void
     */
    protected function unsuspendDependantTasks(WorkflowTask $task): void {
        $task->load(WorkflowTask::RELATION_RECURSIVE_DEPENDANTS);
        $dependantTaskIds = $task->getRecursiveDependantIds();

        if ($dependantTaskIds->isEmpty()) {
            return;
        }

        WorkflowTask::query()
            ->whereIn(WorkflowTask::ATTRIBUTE_ID, $dependantTaskIds)
            ->where(WorkflowTask::ATTRIBUTE_STATUS, RunStatus::SUSPENDED)
            ->update([
                WorkflowTask::ATTRIBUTE_STATUS => RunStatus::PENDING,
            ]);

        WorkflowTaskStep::query()
            ->whereIn(WorkflowTaskStep::ATTRIBUTE_TASK_ID, $dependantTaskIds)
            ->where(WorkflowTaskStep::ATTRIBUTE_STATUS, RunStatus::SUSPENDED)
            ->update([
                WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::PENDING,
            ]);
    }


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

        $statuses = $includePaused ? RunStatus::nonTerminal() : RunStatus::active();
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
     * Finalize the workflow status once no task is still running: COMPLETED if
     * all tasks completed, FAILED if any task failed, otherwise CANCELLED.
     *
     * @param Workflow $workflow
     * @return void
     */
    public function finalizeWorkflowStatus(Workflow $workflow): void {
        $hasActiveTask = $workflow->tasks()
            ->whereIn(WorkflowTask::ATTRIBUTE_STATUS, RunStatus::nonTerminal())
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
            $workflow->failed_at = null;
            $workflow->paused_at = null;
            $workflow->pause_reason = null;
            $workflow->save();
            return;
        }

        $hasFailedTask = $workflow->tasks()
            ->where(WorkflowTask::ATTRIBUTE_STATUS, RunStatus::FAILED)
            ->exists();

        $workflow->status = $hasFailedTask ? RunStatus::FAILED : RunStatus::CANCELLED;
        $workflow->completed_at = null;
        $workflow->failed_at = $workflow->failed_at ?? now();
        $workflow->paused_at = null;
        $workflow->pause_reason = null;
        $workflow->save();
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
