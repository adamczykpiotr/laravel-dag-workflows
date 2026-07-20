<?php

namespace AdamczykPiotr\DagWorkflows\Http\Controllers;

use AdamczykPiotr\DagWorkflows\Http\Resources\WorkflowFailedResource;
use AdamczykPiotr\DagWorkflows\Http\Resources\WorkflowResource;
use AdamczykPiotr\DagWorkflows\Http\Resources\WorkflowSummaryResource;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Services\WorkflowEstimator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * See docs/WORKFLOW_ENDPOINT.md for the response payloads of each format.
 */
class WorkflowController {

    public const string FORMAT_FULL = 'full';
    public const string FORMAT_FAILED = 'failed';


    public function show(Request $request, int $id): JsonResponse {
        $format = $request->query('format');
        $isFull = $format === self::FORMAT_FULL;

        $workflow = Workflow::query()
            ->with([
                Workflow::RELATION_TASKS => $isFull
                    ? [WorkflowTask::RELATION_STEPS, WorkflowTask::RELATION_DEPENDENCIES, WorkflowTask::RELATION_DEPENDANTS]
                    : [WorkflowTask::RELATION_STEPS],
            ])
            ->findOrFail($id);

        return response()->json(match ($format) {
            self::FORMAT_FULL => new WorkflowResource($workflow, (new WorkflowEstimator())->build($workflow)),
            self::FORMAT_FAILED => new WorkflowFailedResource($workflow),
            default => new WorkflowSummaryResource($workflow),
        });
    }
}
