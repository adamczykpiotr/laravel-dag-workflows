<?php

namespace AdamczykPiotr\DagWorkflows\Http\Controllers;

use AdamczykPiotr\DagWorkflows\Http\Resources\WorkflowResource;
use AdamczykPiotr\DagWorkflows\Http\Resources\WorkflowSummaryResource;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Services\WorkflowEstimator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkflowController {

    public const string FORMAT_FULL = 'full';


    public function show(Request $request, int $id): JsonResponse {
        if ($request->query('format') === self::FORMAT_FULL) {
            return $this->showFull($id);
        }

        return $this->showSummary($id);
    }


    protected function showFull(int $id): JsonResponse {
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


    protected function showSummary(int $id): JsonResponse {
        $workflow = Workflow::query()->findOrFail($id);

        return response()->json(
            new WorkflowSummaryResource(
                $workflow,
                $this->statusCounts(WorkflowTask::query()->where(WorkflowTask::ATTRIBUTE_WORKFLOW_ID, $id)),
                $this->statusCounts(WorkflowTaskStep::query()->where(WorkflowTaskStep::ATTRIBUTE_WORKFLOW_ID, $id)),
            )
        );
    }


    /**
     * @param \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @return Collection<string, int>
     */
    protected function statusCounts($query): Collection {
        /** @var Collection<string, int> $counts */
        $counts = $query
            ->toBase()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return $counts;
    }
}
