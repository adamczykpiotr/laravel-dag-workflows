<?php

namespace AdamczykPiotr\DagWorkflows\Definitions;

use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskCircularDependencyException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskDuplicateNameException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskMissingTrackingTraitException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskUnresolvedDependencyException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskWithoutJobException;
use AdamczykPiotr\DagWorkflows\Models\Workflow as WorkflowModel;
use AdamczykPiotr\DagWorkflows\Services\WorkflowDefinitionParser;
use AdamczykPiotr\DagWorkflows\Services\WorkflowRepository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Throwable;

class Workflow {

    /**
     * @param string $name
     * @param array<int, Task|ResolvableTask|TaskGroup> $tasks
     */
    public function __construct(
        public string $name,
        public array $tasks,
    ) {
    }


    /**
     * @return WorkflowModel
     * @throws BindingResolutionException
     * @throws WorkflowTaskCircularDependencyException
     * @throws WorkflowTaskMissingTrackingTraitException
     * @throws WorkflowTaskUnresolvedDependencyException
     * @throws WorkflowTaskWithoutJobException
     * @throws WorkflowTaskDuplicateNameException
     * @throws Throwable
     */
    public function dispatch(): WorkflowModel {
        /** @var WorkflowDefinitionParser $parser */
        $parser = app()->make(WorkflowDefinitionParser::class);
        $workflow = $parser->parse($this);

        $repository = app()->make(WorkflowRepository::class);
        $model = $repository->store($workflow);

        $model->dispatch();
        return $model;
    }
}
