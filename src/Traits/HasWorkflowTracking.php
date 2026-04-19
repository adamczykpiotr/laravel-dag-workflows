<?php

namespace AdamczykPiotr\DagWorkflows\Traits;

use AdamczykPiotr\DagWorkflows\Middlewares\DagWorkflowTrackerJobMiddleware;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;

trait HasWorkflowTracking {

    protected const int WORKFLOW_TASK_STEP_PROGRESS_DEBOUNCE_SECONDS = 30;
    public WorkflowTaskStep $workflowTaskStep;


    public function getWorkflowId(): int {
        return $this->workflowTaskStep->workflow_id;
    }


    public function getWorkflowTaskId(): int {
        return $this->workflowTaskStep->task_id;
    }


    public function getWorkflowTaskStepId(): int {
        return $this->workflowTaskStep->id;
    }


    public function progress(int $percentage, bool $force = false): void {
        $clamped = max(0, min(100, $percentage));
        $this->workflowTaskStep->progress = $clamped;

        if ($clamped === 100 || $force) {
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
     * @return array<int, object>
     */
    public function middleware(): array {
        return [
            resolve(DagWorkflowTrackerJobMiddleware::class),
        ];
    }
}
