<?php

namespace AdamczykPiotr\DagWorkflows\Traits;

use AdamczykPiotr\DagWorkflows\Middlewares\DagWorkflowTrackerJobMiddleware;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use Illuminate\Contracts\Container\BindingResolutionException;

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
     * @throws BindingResolutionException
     */
    public function middleware(): array {
        return [
            app()->make(DagWorkflowTrackerJobMiddleware::class),
        ];
    }
}
