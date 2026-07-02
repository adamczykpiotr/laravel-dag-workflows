<?php

namespace AdamczykPiotr\DagWorkflows\Middlewares;

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskEarlyCompletionException;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Services\WorkflowDispatcher;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
     * @param object $job
     * @param Closure $next
     * @return mixed
     * @throws Throwable
     */
    public function handle(object $job, Closure $next): mixed {
        // Skip middleware execution for jobs without injected $workflowTaskStep
        if (property_exists($job, 'workflowTaskStep') === false || $job->workflowTaskStep instanceof WorkflowTaskStep === false) {
            return $next($job);
        }

        $step = $job->workflowTaskStep;

        // Already processed or canceled due to other failures
        if ($step->status !== RunStatus::PENDING) {
            // If paused, release job back to queue for later
            if ($step->status === RunStatus::PAUSED) {
                $job->release(60); // @phpstan-ignore-line
                return null;
            }
            $job->fail(); // @phpstan-ignore-line
            return $next($job);
        }

        $this->beginWorkflowTaskStep($step);

        try {
            $result = $next($job);
            $this->completeWorkflowTaskStep($step);
            return $result;
        } catch (WorkflowTaskEarlyCompletionException) {
            // Deliberate short-circuit, not a failure: complete this step, skip the
            // task's remaining steps, and let the task/workflow complete as usual.
            $this->dispatcher->completeTaskEarly($step);
            return null;
        } catch (Throwable $t) {
            $this->dispatcher->failStep($step);

            // Re-throw the exception so Laravel's queue system can handle it properly
            throw $t;
        }
    }


    /**
     * @param WorkflowTaskStep $step
     * @return void
     * @throws Throwable
     */
    protected function beginWorkflowTaskStep(WorkflowTaskStep $step): void {
        $step->status = RunStatus::RUNNING;
        $step->started_at = now();
        $step->failed_at = null;
        $step->completed_at = null;

        if ($step->order > 1) {
            $step->save();
            return;
        }

        DB::transaction(function() use ($step) {
            $step->save();

            $task = $step->task;
            $task->status = RunStatus::RUNNING;
            $task->started_at = now();
            $task->failed_at = null;
            $task->completed_at = null;
            $task->save();

            $workflow = $task->workflow;
            if ($workflow->status === RunStatus::PENDING) {
                $workflow->status = RunStatus::RUNNING;
                $workflow->started_at = now();
                $workflow->failed_at = null;
                $workflow->completed_at = null;
                $workflow->save();
            }
        });
    }


    /**
     * @param WorkflowTaskStep $step
     * @return void
     * @throws Throwable
     */
    protected function completeWorkflowTaskStep(WorkflowTaskStep $step): void {
        $step->refresh();

        // If step was paused during execution (approval required), don't auto-complete
        if ($step->status === RunStatus::PAUSED) {
            return;
        }

        DB::transaction(function() use ($step) {
            $step->status = RunStatus::COMPLETED;
            $step->completed_at = now();
            $step->failed_at = null;
            if ($step->progress !== null) {
                $step->progress = 100;
            }
            $step->save();

            $nextStep = $step->nextStep;

            // Continuing steps - only if next step is PENDING (not SUSPENDED)
            if ($nextStep instanceof WorkflowTaskStep && $nextStep->status === RunStatus::PENDING) {
                $this->dispatcher->dispatchStep($nextStep);
                return;
            }

            // If next step exists but is SUSPENDED, don't dispatch - waiting for approval
            if ($nextStep instanceof WorkflowTaskStep && $nextStep->status === RunStatus::SUSPENDED) {
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
}
