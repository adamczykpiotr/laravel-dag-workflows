<?php

namespace AdamczykPiotr\DagWorkflows\Http\Resources;

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Lightweight workflow representation: status counts and completion percentages
 * instead of the full tasks/steps tree. Built from two aggregate queries, so it
 * stays cheap for workflows with thousands of tasks.
 *
 * @mixin Workflow
 */
class WorkflowSummaryResource extends JsonResource {

    /**
     * @param Workflow $workflow
     * @param Collection<string, int> $taskStatuses status value => count
     * @param Collection<string, int> $stepStatuses status value => count
     */
    public function __construct(
        Workflow $workflow,
        private readonly Collection $taskStatuses,
        private readonly Collection $stepStatuses,
    ) {
        parent::__construct($workflow);
    }


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

            'durationSeconds' => $this->durationSeconds(),

            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,

            'taskStatuses' => $this->taskStatuses,
            'stepStatuses' => $this->stepStatuses,

            'taskCompletionPercentage' => $this->completionPercentage($this->taskStatuses),
            'stepCompletionPercentage' => $this->completionPercentage($this->stepStatuses),
        ];
    }


    private function durationSeconds(): int {
        /** @var Workflow $workflow */
        $workflow = $this->resource;

        $end = $workflow->completed_at ?? $workflow->failed_at ?? now();

        return (int) max(0, round($workflow->created_at?->diffInSeconds($end) ?? 0));
    }


    /**
     * COMPLETED and SKIPPED both count as done: SKIPPED is the terminal,
     * non-failing status of steps bypassed by an early task completion.
     *
     * @param Collection<string, int> $statuses
     * @return float
     */
    private function completionPercentage(Collection $statuses): float {
        $total = $statuses->reduce(fn(int $carry, int $count): int => $carry + $count, 0);

        if ($total === 0) {
            return 0.0;
        }

        $done = ($statuses->get(RunStatus::COMPLETED->value) ?? 0)
            + ($statuses->get(RunStatus::SKIPPED->value) ?? 0);

        return round($done / $total * 100, 2);
    }
}
