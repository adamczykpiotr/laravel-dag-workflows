<?php

namespace AdamczykPiotr\DagWorkflows\Http\Resources;

use AdamczykPiotr\DagWorkflows\Dto\WorkflowEstimateDto;
use AdamczykPiotr\DagWorkflows\Dto\WorkflowTaskEstimateDto;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Workflow
 */
class WorkflowResource extends JsonResource {

    public function __construct(
        Workflow $workflow,
        private readonly WorkflowEstimateDto $estimate,
    ) {
        parent::__construct($workflow);
    }


    /**
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        /** @var Workflow $workflow */
        $workflow = $this->resource;
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
            'estimatedCompletionAt' => $this->estimate->estimatedCompletionAt,

            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,

            'tasks' => $this->when(
                $workflow->relationLoaded(Workflow::RELATION_TASKS),
                fn() => $workflow->tasks->map(function(WorkflowTask $task) use ($estimate) {
                    $taskEstimate = $estimate->tasks[$task->id];
                    assert($taskEstimate instanceof WorkflowTaskEstimateDto);

                    return new WorkflowTaskResource($task, $taskEstimate);
                }),
            ),
        ];
    }
}
