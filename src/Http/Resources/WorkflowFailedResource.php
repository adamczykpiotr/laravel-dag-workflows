<?php

namespace AdamczykPiotr\DagWorkflows\Http\Resources;

use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use Illuminate\Http\Request;

/**
 * Only the failed stuff: the workflow header plus a `failedTasks` list.
 * Expects the tasks relation to be eager-loaded constrained to FAILED tasks
 * with their FAILED steps ({@see \AdamczykPiotr\DagWorkflows\Http\Controllers\WorkflowController::show()}).
 *
 * @mixin Workflow
 */
class WorkflowFailedResource extends WorkflowSummaryResource {

    /**
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        /** @var Workflow $workflow */
        $workflow = $this->resource;

        return [
            ...$this->headerFields(),

            'failedTasks' => $workflow->tasks
                ->map(fn(WorkflowTask $task): array => $this->failedTask($task))
                ->values(),
        ];
    }


    /**
     * @param WorkflowTask $task
     * @return array<string, mixed>
     */
    private function failedTask(WorkflowTask $task): array {
        return [
            'id' => $task->id,
            'name' => $task->name,
            'startedAt' => $task->started_at,
            'failedAt' => $task->failed_at,
            'failedSteps' => $task->steps
                ->map(fn(WorkflowTaskStep $step): array => [
                    'id' => $step->id,
                    'order' => $step->order,
                    'class' => $step->class,
                    'attempts' => $step->attempts,
                    'startedAt' => $step->started_at,
                    'failedAt' => $step->failed_at,
                ])
                ->values(),
        ];
    }
}
