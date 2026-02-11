<?php

namespace AdamczykPiotr\DagWorkflows\Traits;

use AdamczykPiotr\DagWorkflows\Middlewares\DagWorkflowTrackerJobMiddleware;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;

trait HasWorkflowTracking {

    public WorkflowTaskStep $workflowTaskStep;


    /*
     * @return int
     */
    public function getWorkflowId(): int {
        return $this->workflowTaskStep->workflow_id;
    }


    /**
     * @return int
     */
    public function getWorkflowTaskId(): int {
        return $this->workflowTaskStep->task_id;
    }


    /**
     * @return int
     */
    public function getWorkflowTaskStepId(): int {
        return $this->workflowTaskStep->id;
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
