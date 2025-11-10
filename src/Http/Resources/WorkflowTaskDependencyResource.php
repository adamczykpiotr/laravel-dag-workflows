<?php

namespace AdamczykPiotr\DagWorkflows\Http\Resources;

use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkflowTask
 */
class WorkflowTaskDependencyResource extends JsonResource {

    /**
     * @param Request $request
     * @return array<string, mixed>
 */
    public function toArray(Request $request): array {
        return [
            'parentTaskId' => $this->id,
        ];
    }
}
