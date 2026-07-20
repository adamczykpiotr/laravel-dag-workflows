<?php

namespace AdamczykPiotr\DagWorkflows\Http\Controllers;

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Http\Resources\WorkflowResource;
use AdamczykPiotr\DagWorkflows\Http\Resources\WorkflowSummaryResource;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Services\WorkflowEstimator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        $isFailed = $format === self::FORMAT_FAILED;

        $workflow = Workflow::query()
            ->when($isFull, fn(Builder $query) => $query->with([
                Workflow::RELATION_TASKS => [WorkflowTask::RELATION_STEPS, WorkflowTask::RELATION_DEPENDENCIES, WorkflowTask::RELATION_DEPENDANTS],
            ]))
            ->when($isFailed, fn(Builder $query) => $query->with([ // @phpstan-ignore-line
                Workflow::RELATION_TASKS => fn(HasMany $tasks) => $tasks
                    ->where(WorkflowTask::ATTRIBUTE_STATUS, RunStatus::FAILED) // @phpstan-ignore-line
                    ->with(WorkflowTask::RELATION_STEPS),
            ]))
            ->when(!$isFull && !$isFailed, fn(Builder $query) => $query->with([
                Workflow::RELATION_TASKS => [WorkflowTask::RELATION_STEPS],
            ]))
            ->findOrFail($id);

        $resource = match ($format) {
            self::FORMAT_FULL, self::FORMAT_FAILED => new WorkflowResource($workflow, (new WorkflowEstimator())->build($workflow)),
            default => new WorkflowSummaryResource($workflow),
        };

        return response()->json($resource);
    }
}
