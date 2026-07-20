<?php

namespace AdamczykPiotr\DagWorkflows\Http\Resources;

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use Illuminate\Http\Request;

/**
 * Summary payload plus a `failedTasks` list: every FAILED task with its
 * FAILED steps, so failures can be inspected without the full tasks/steps tree.
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

        $failedTasks = $workflow->tasks
            ->filter(fn(WorkflowTask $task): bool => $task->status === RunStatus::FAILED)
            ->values()
            ->map(fn(WorkflowTask $task): array => $this->failedTask($task));

        return [
            ...parent::toArray($request),
            'failedTasks' => $failedTasks,
        ];
    }


    /**
     * @param WorkflowTask $task
     * @return array<string, mixed>
     */
    private function failedTask(WorkflowTask $task): array {
        $failedSteps = $task->steps
            ->filter(fn(WorkflowTaskStep $step): bool => $step->status === RunStatus::FAILED)
            ->values()
            ->map(fn(WorkflowTaskStep $step): array => [
                'id' => $step->id,
                'order' => $step->order,
                'class' => $step->class,
                'attempts' => $step->attempts,
                'startedAt' => $step->started_at,
                'failedAt' => $step->failed_at,
            ]);

        return [
            'id' => $task->id,
            'name' => $task->name,
            'startedAt' => $task->started_at,
            'failedAt' => $task->failed_at,
            'failedSteps' => $failedSteps,
        ];
    }
}
