<?php

namespace AdamczykPiotr\DagWorkflows\Services;

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkflowDispatcher {

    /**
     * @param Workflow $workflow
     * @param bool $force
     * @return bool
     */
    public function dispatchWorkflow(Workflow $workflow, bool $force = false): bool {
        $entrypoint = WorkflowTask::query()
            ->where(WorkflowTask::ATTRIBUTE_WORKFLOW_ID, $workflow->id)
            ->where(WorkflowTask::ATTRIBUTE_STATUS, RunStatus::PENDING)
            ->whereDoesntHave(WorkflowTask::RELATION_DEPENDENCIES)
            ->with(WorkflowTask::RELATION_INITIAL_STEP)
            ->get();

        // Prevent overlaps
        if ($workflow->status !== RunStatus::PENDING && $force === false) {
            return false;
        }

        $entrypoint->each(fn(WorkflowTask $task) => $this->dispatchTask($task, $force));
        return true;
    }


    /**
     * @param WorkflowTask $task
     * @param bool $force
     * @return bool
     */
    public function dispatchTask(WorkflowTask $task, bool $force = false): bool {
        // Prevent overlaps
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
                fn(BuilderContract $builder) => $builder->where(WorkflowTask::ATTRIBUTE_STATUS, '!=', RunStatus::COMPLETED)
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
        // Prevent overlaps
        if ($force === false && $step->status !== RunStatus::PENDING) {
            return false;
        }

        // Status will be updated when job will be picked up by queue worker

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

            $task->load(WorkflowTask::RELATION_RECURSIVE_DEPENDANTS);
            $dependantTaskIds = $task->getRecursiveDependantIds();

            if ($dependantTaskIds->isNotEmpty()) {
                WorkflowTask::query()
                    ->whereIn(WorkflowTask::ATTRIBUTE_ID, $dependantTaskIds)
                    ->update([
                        WorkflowTask::ATTRIBUTE_STATUS => RunStatus::PENDING,
                        WorkflowTask::ATTRIBUTE_FAILED_AT => null,
                        WorkflowTask::ATTRIBUTE_COMPLETED_AT => null,
                        WorkflowTask::ATTRIBUTE_STARTED_AT => null,
                    ]);

                WorkflowTaskStep::query()
                    ->whereIn(WorkflowTaskStep::ATTRIBUTE_TASK_ID, $dependantTaskIds)
                    ->update([
                        WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::PENDING,
                        WorkflowTaskStep::ATTRIBUTE_FAILED_AT => null,
                        WorkflowTaskStep::ATTRIBUTE_COMPLETED_AT => null,
                        WorkflowTaskStep::ATTRIBUTE_STARTED_AT => null,
                    ]);
            }
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

            // Cancel rest of the steps from this task
            $task->steps()
                ->where(WorkflowTask::ATTRIBUTE_STATUS, RunStatus::PENDING)
                ->update([
                    WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::CANCELLED,
                    WorkflowTaskStep::ATTRIBUTE_FAILED_AT => now(),
                ]);

            // Cancel dependant tasks (all levels deep) & their steps
            $task->load(WorkflowTask::RELATION_RECURSIVE_DEPENDANTS);
            $cancelledTaskIds = $task->getRecursiveDependantIds();

            if ($cancelledTaskIds->isNotEmpty()) {
                WorkflowTask::query()
                    ->whereIn(WorkflowTask::ATTRIBUTE_ID, $cancelledTaskIds)
                    ->update([
                        WorkflowTask::ATTRIBUTE_STATUS => RunStatus::CANCELLED,
                        WorkflowTask::ATTRIBUTE_FAILED_AT => now(),
                    ]);

                WorkflowTaskStep::query()
                    ->whereIn(WorkflowTaskStep::ATTRIBUTE_TASK_ID, $cancelledTaskIds)
                    ->update([
                        WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::CANCELLED,
                        WorkflowTaskStep::ATTRIBUTE_FAILED_AT => now(),
                    ]);
            }
        });
    }
}
