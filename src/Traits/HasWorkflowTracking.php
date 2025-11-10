<?php

namespace AdamczykPiotr\DagWorkflows\Traits;

use AdamczykPiotr\DagWorkflows\Middlewares\DagWorkflowTrackerJobMiddleware;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use Illuminate\Contracts\Container\BindingResolutionException;

trait HasWorkflowTracking {

    public WorkflowTaskStep $workflowTaskStep;


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
