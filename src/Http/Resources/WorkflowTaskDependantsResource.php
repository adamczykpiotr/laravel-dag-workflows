<?php

namespace AdamczykPiotr\DagWorkflows\Http\Resources;

use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkflowTask
 */
class WorkflowTaskDependantsResource extends JsonResource {

    /**
     * @param Request $request
     * @return array
     */
    public function toArray(Request $request): array {
        return [
            'childTaskId' => $this->id,
        ];
    }
}
