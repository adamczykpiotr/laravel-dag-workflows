<?php

namespace AdamczykPiotr\DagWorkflows\Http\Controllers;

use AdamczykPiotr\DagWorkflows\Http\Resources\WorkflowResource;
use AdamczykPiotr\DagWorkflows\Http\Resources\WorkflowSummaryResource;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Services\WorkflowEstimator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController {

    public const string FORMAT_FULL = 'full';


    public function show(Request $request, int $id): JsonResponse {
        $isFull = $request->query('format') === self::FORMAT_FULL;

        $workflow = Workflow::query()
            ->with([
                Workflow::RELATION_TASKS => $isFull
                    ? [WorkflowTask::RELATION_STEPS, WorkflowTask::RELATION_DEPENDENCIES, WorkflowTask::RELATION_DEPENDANTS]
                    : [WorkflowTask::RELATION_STEPS],
            ])
            ->findOrFail($id);

        return response()->json($isFull
            ? new WorkflowResource($workflow, (new WorkflowEstimator())->build($workflow))
            : new WorkflowSummaryResource($workflow)
        );
    }
}
