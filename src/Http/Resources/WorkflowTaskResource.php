<?php

namespace AdamczykPiotr\DagWorkflows\Http\Resources;

use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkflowTask
 */
class WorkflowTaskResource extends JsonResource {

    /**
     * @param Request $request
     * @return array<string, mixed>
 */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'name' => $this->name,

            'status' => $this->status,

            'startedAt' => $this->started_at,
            'failedAt' => $this->failed_at,
            'completedAt' => $this->completed_at,

            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,

            'steps' => WorkflowTaskStepResource::collection(
                $this->whenLoaded(WorkflowTask::RELATION_STEPS)
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
