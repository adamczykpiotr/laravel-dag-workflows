<?php

namespace AdamczykPiotr\DagWorkflows\Middlewares;

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskEarlyCompletionException;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Services\WorkflowDispatcher;
use Closure;
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

        // Paused: release the job to be retried once resumed.
        if ($step->status === RunStatus::PAUSED) {
            $job->release(60); // @phpstan-ignore-line
            return null;
        }

        // Any other non-pending step is a stale/duplicate delivery (e.g. an
        // orphaned reservation redelivered after retry_after). Drop it:
        // re-running would duplicate the work and failing would be spurious.
        // The claim below is atomic (UPDATE ... WHERE status = PENDING), so two
        // workers holding duplicate deliveries of the same step cannot both run
        // the handler — only the one winning the row proceeds.
        if ($this->beginWorkflowTaskStep($step) === false) {
            // A redelivery of a step whose task already completed means a worker
            // died in the window between committing the completion and pushing the
            // dependant jobs. Re-firing the dispatch is idempotent (pending-only
            // + atomic claim), so use the redelivery to heal the stranded window.
            $this->redispatchDependantsOfCompletedTask($step);

            return null;
        }

        try {
            // A previous attempt of this step already ran: let the job undo its
            // leftovers before handle(). A throwing rollbackStep() fails the step.
            if ($step->attempts > 1 && method_exists($job, 'rollbackStep')) {
                $job->rollbackStep();
            }

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
     */
    protected function redispatchDependantsOfCompletedTask(WorkflowTaskStep $step): void {
        $step->refresh();

        if ($step->status !== RunStatus::COMPLETED) {
            return;
        }

        $task = $step->task;

        if ($task->status !== RunStatus::COMPLETED) {
            return;
        }

        $this->dispatcher->dispatchDependantTasks($task);
        $this->dispatcher->finalizeWorkflowStatus($task->workflow);
    }


    /**
     * Atomically claim the step (PENDING -> RUNNING). Returns false when another
     * delivery of the same step already claimed or processed it.
     *
     * @param WorkflowTaskStep $step
     * @return bool
     * @throws Throwable
     */
    protected function beginWorkflowTaskStep(WorkflowTaskStep $step): bool {
        $startedAt = now();

        $claimed = WorkflowTaskStep::query()
            ->whereKey($step->id)
            ->where(WorkflowTaskStep::ATTRIBUTE_STATUS, RunStatus::PENDING)
            ->increment(WorkflowTaskStep::ATTRIBUTE_ATTEMPTS, 1, [
                WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::RUNNING,
                WorkflowTaskStep::ATTRIBUTE_STARTED_AT => $startedAt,
                WorkflowTaskStep::ATTRIBUTE_FAILED_AT => null,
                WorkflowTaskStep::ATTRIBUTE_COMPLETED_AT => null,
                WorkflowTaskStep::ATTRIBUTE_UPDATED_AT => $startedAt,
            ]);

        if ($claimed === 0) {
            return false;
        }

        // Sync the in-memory model with the claim so later saves stay consistent.
        // The atomic claim guarantees no concurrent increment, so +1 is exact.
        $step->status = RunStatus::RUNNING;
        $step->attempts += 1;
        $step->started_at = $startedAt;
        $step->failed_at = null;
        $step->completed_at = null;
        $step->syncChanges();
        $step->syncOriginal();

        if ($step->order > 1) {
            return true;
        }

        DB::transaction(function() use ($step) {
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

        return true;
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

        $completedTask = DB::transaction(function() use ($step) {
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
                return null;
            }

            // If next step exists but is SUSPENDED, don't dispatch - waiting for approval
            if ($nextStep instanceof WorkflowTaskStep && $nextStep->status === RunStatus::SUSPENDED) {
                return null;
            }

            // Task has succeeded
            $task = $step->task;
            $task->status = RunStatus::COMPLETED;
            $task->completed_at = now();
            $task->failed_at = null;
            $task->save();

            return $task;
        });

        if ($completedTask === null) {
            return;
        }

        // Deliberately OUTSIDE the transaction: the dependant-readiness check and the
        // workflow finalization must observe this task's committed COMPLETED status.
        // Inside the transaction, two dependencies completing concurrently on separate
        // workers could each miss the other's uncommitted completion and BOTH skip the
        // dependant (or leave the workflow unfinalized) — stranding the workflow.
        // Post-commit, the last committer is guaranteed to see every completion; the
        // overlap can at worst double-dispatch, which the stale-delivery guard above
        // drops.
        $this->dispatcher->dispatchDependantTasks($completedTask);
        $this->dispatcher->finalizeWorkflowStatus($completedTask->workflow);
    }
}
