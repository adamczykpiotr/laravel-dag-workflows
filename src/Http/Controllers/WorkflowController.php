<?php

namespace AdamczykPiotr\DagWorkflows\Http\Controllers;

use AdamczykPiotr\DagWorkflows\Http\Resources\WorkflowResource;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Services\WorkflowEstimator;
use Illuminate\Http\JsonResponse;

class WorkflowController {

    public function show(int $id): JsonResponse {
        $workflow = Workflow::query()
            ->with([
                Workflow::RELATION_TASKS => [
                    WorkflowTask::RELATION_STEPS,
                    WorkflowTask::RELATION_DEPENDENCIES,
                    WorkflowTask::RELATION_DEPENDANTS,
                ],
            ])->findOrFail($id);

        $estimate = (new WorkflowEstimator())->build($workflow);

        return response()->json(
            new WorkflowResource($workflow, $estimate)
        );
    }
}
