<?php

declare(strict_types=1);

namespace AdamczykPiotr\DagWorkflows\Traits;

use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskEarlyCompletionException;
use AdamczykPiotr\DagWorkflows\Middlewares\DagWorkflowTrackerJobMiddleware;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;

trait HasWorkflowTracking
{
    protected const int WORKFLOW_TASK_STEP_PROGRESS_DEBOUNCE_SECONDS = 30;

    public WorkflowTaskStep $workflowTaskStep;

    /**
     * @return int
     */
    public function getWorkflowId(): int
    {
        return $this->workflowTaskStep->workflow_id;
    }

    /**
     * @return int
     */
    public function getWorkflowTaskId(): int
    {
        return $this->workflowTaskStep->task_id;
    }

    /**
     * @return int
     */
    public function getWorkflowTaskStepId(): int
    {
        return $this->workflowTaskStep->id;
    }

    /**
     * Invoked by the tracker middleware right before handle(), but only when a
     * previous attempt of this step already ran — never on the first attempt.
     * Override to undo whatever that attempt may have left behind (downloaded
     * files, half-executed queries) so handle() starts from a clean slate.
     * A throwing rollbackStep() fails the step exactly like a throwing handle().
     *
     * @return void
     */
    public function rollbackStep(): void
    {
        // default: no-op
    }

    /**
     * @param int $percentage
     * @param bool $force
     *
     * @return void
     */
    public function progress(int $percentage, bool $force = false): void
    {
        $clamped = max(0, min(100, $percentage));
        $isFirstReport = $this->workflowTaskStep->progress === null;
        $this->workflowTaskStep->progress = $clamped;

        if ($isFirstReport || $clamped === 100 || $force) {
            $this->workflowTaskStep->save();
            return;
        }

        $updatedAt = $this->workflowTaskStep->updated_at;
        if ($updatedAt !== null && $updatedAt->diffInSeconds(now()) < self::WORKFLOW_TASK_STEP_PROGRESS_DEBOUNCE_SECONDS) {
            return;
        }

        $this->workflowTaskStep->save();
    }

    /**
     * Complete the whole task early: the current step is marked COMPLETED, all
     * remaining steps of the task are marked SKIPPED (they never run), and the
     * task completes as if every step had run — dependant tasks are dispatched
     * and the workflow status is finalized as usual.
     *
     * Call from within a tracked job's handle() when the remaining steps are
     * known to be unnecessary (e.g. the downloaded source file is unchanged).
     * This method never returns.
     *
     * @param string|null $reason
     *
     * @return never
     *
     * @throws WorkflowTaskEarlyCompletionException
     */
    public function completeTaskEarly(?string $reason = null): never
    {
        throw new WorkflowTaskEarlyCompletionException($reason ?? '');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            resolve(DagWorkflowTrackerJobMiddleware::class),
        ];
    }
}
