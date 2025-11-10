<?php

namespace AdamczykPiotr\DagWorkflows\Middlewares;

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Services\WorkflowDispatcher;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Throwable;

class DagWorkflowTrackerJobMiddleware {

    /**
     * @param WorkflowDispatcher $dispatcher
     */
    public function __construct(
        protected WorkflowDispatcher $dispatcher,
    ) {
    }


    /**
     * @param object{workflowTaskStep: WorkflowTaskStep} $job
     * @param Closure $next
     * @return mixed
     * @throws Throwable
     */
    public function handle(object $job, Closure $next): mixed {
        $step = $job->workflowTaskStep;

        // Already processed or cancelled due to other failures
        if ($step->status !== RunStatus::PENDING) {
            $job->fail(); // @phpstan-ignore-line
            return $next($job);
        }

        $this->beginWorkflowTaskStep($step);

        try {
            $result = $next($job);
            $this->completeWorkflowTaskStep($step);
            return $result;
        } catch (Throwable $t) {
            $this->failWorkflowTaskStep($step);

            // Re-throw the exception so Laravel's queue system can handle it properly
            throw $t;
        }
    }


    /**
     * @param WorkflowTaskStep $step
     * @return void
     */
    protected function beginWorkflowTaskStep(WorkflowTaskStep $step): void {
        $step->status = RunStatus::RUNNING;
        $step->started_at = now();
        $step->failed_at = null;
        $step->completed_at = null;
        $step->save();
    }


    /**
     * @param WorkflowTaskStep $step
     * @return void
     * @throws Throwable
     */
    protected function completeWorkflowTaskStep(WorkflowTaskStep $step): void {
        DB::transaction(function() use ($step) {
            $step->status = RunStatus::COMPLETED;
            $step->completed_at = now();
            $step->failed_at = null;
            $step->save();

            $nextStep = $step->nextStep;

            // Continuing steps
            if ($nextStep instanceof WorkflowTaskStep) {
                $this->dispatcher->dispatchStep($nextStep);
                return;
            }

            // Task has succeeded
            $task = $step->task;
            $task->status = RunStatus::COMPLETED;
            $task->completed_at = now();
            $task->failed_at = null;
            $task->save();

            $this->dispatcher->dispatchDependantTasks($task);

            // Check if workflow has succeeded
            $workflow = $task->workflow;
            $allTasksCompleted = $workflow->tasks()
                ->where(WorkflowTask::ATTRIBUTE_STATUS, '!=', RunStatus::COMPLETED)
                ->doesntExist();

            if ($allTasksCompleted === true) {
                $workflow->status = RunStatus::COMPLETED;
                $workflow->completed_at = now();
                $workflow->failed_at = null;
                $workflow->save();
            }
        });
    }


    /**
     * @param WorkflowTaskStep $step
     * @return void
     * @throws Throwable
     */
    protected function failWorkflowTaskStep(WorkflowTaskStep $step): void {
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
            $cancelledTaskIds = $this->retrieveRecursiveDependants($task);

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
        });
    }


    /**
     * @param WorkflowTask $task
     * @param int $level
     * @return Collection<int, int>
     */
    protected function retrieveRecursiveDependants(WorkflowTask $task, int $level = 0): Collection {
        $ids = collect($level === 0 ? [] : [$task->id]);

        foreach ($task->recursiveDependants as $dependency) {
            $ids->push(...$this->retrieveRecursiveDependants($dependency, $level + 1));
        }

        return $ids;
    }
}
