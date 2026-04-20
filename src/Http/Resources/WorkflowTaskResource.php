<?php

namespace AdamczykPiotr\DagWorkflows\Http\Resources;

use AdamczykPiotr\DagWorkflows\Dto\WorkflowTaskEstimateDto;
use AdamczykPiotr\DagWorkflows\Dto\WorkflowTaskStepEstimateDto;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkflowTask
 */
class WorkflowTaskResource extends JsonResource {

    public function __construct(
        WorkflowTask $task,
        private readonly WorkflowTaskEstimateDto $estimate,
    ) {
        parent::__construct($task);
    }


    /**
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        /** @var WorkflowTask $task */
        $task = $this->resource;
        $estimate = $this->estimate;

        return [
            'id' => $this->id,
            'name' => $this->name,

            'status' => $this->status,

            'startedAt' => $this->started_at,
            'failedAt' => $this->failed_at,
            'completedAt' => $this->completed_at,

            'durationSeconds' => $this->estimate->durationSeconds,
            'estimatedSecondsRemaining' => $this->estimate->estimatedSecondsRemaining,

            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,

            'steps' => $this->when(
                $task->relationLoaded(WorkflowTask::RELATION_STEPS),
                fn() => $task->steps->map(function(WorkflowTaskStep $step) use ($estimate) {
                    $stepEstimate = $estimate->steps[$step->id];
                    assert($stepEstimate instanceof WorkflowTaskStepEstimateDto);

                    return new WorkflowTaskStepResource($step, $stepEstimate);
                }),
            ),

            'dependencies' => WorkflowTaskDependencyResource::collection(
                $this->whenLoaded(WorkflowTask::RELATION_DEPENDENCIES)
            ),

            'dependants' => WorkflowTaskDependantsResource::collection(
                $this->whenLoaded(WorkflowTask::RELATION_DEPENDANTS)
            ),
        ];
    }
}
