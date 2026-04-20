<?php

namespace AdamczykPiotr\DagWorkflows\Http\Resources;

use AdamczykPiotr\DagWorkflows\Dto\WorkflowTaskStepEstimateDto;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkflowTaskStep
 */
class WorkflowTaskStepResource extends JsonResource {

    public function __construct(
        WorkflowTaskStep $step,
        private readonly WorkflowTaskStepEstimateDto $estimate,
    ) {
        parent::__construct($step);
    }


    /**
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'order' => $this->order,
            'class' => $this->class,

            'status' => $this->status,
            'progress' => $this->progress,

            'startedAt' => $this->started_at,
            'failedAt' => $this->failed_at,
            'completedAt' => $this->completed_at,

            'durationSeconds' => $this->estimate->durationSeconds,
            'estimatedDurationSeconds' => $this->estimate->estimatedDurationSeconds,
            'estimatedSecondsRemaining' => $this->estimate->estimatedSecondsRemaining,

            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
