<?php

namespace AdamczykPiotr\DagWorkflows\Http\Resources;

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Lightweight workflow representation: status counts and completion percentages
 * instead of the full tasks/steps tree.
 *
 * @mixin Workflow
 */
class WorkflowSummaryResource extends JsonResource {

    /**
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        /** @var Workflow $workflow */
        $workflow = $this->resource;

        $taskStatuses = $workflow->tasks
            ->countBy(fn(WorkflowTask $task): string => $task->status->value);

        /** @var Collection<int, WorkflowTaskStep> $steps */
        $steps = $workflow->tasks->flatMap(fn(WorkflowTask $task) => $task->steps);
        $stepStatuses = $steps->countBy(fn(WorkflowTaskStep $step): string => $step->status->value);

        return [
            ...$this->headerFields(),

            'taskStatuses' => $taskStatuses,
            'stepStatuses' => $stepStatuses,

            'taskCompletionPercentage' => $this->completionPercentage($taskStatuses),
            'stepCompletionPercentage' => $this->completionPercentage($stepStatuses),
            'stepProgressPercentage' => $this->progressPercentage($steps),
        ];
    }


    /**
     * The workflow's own fields, shared by every format that skips the task tree.
     *
     * @return array<string, mixed>
     */
    protected function headerFields(): array {
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


    /**
     * Average effective progress across every step: done steps weigh 100, running
     * steps weigh their self-reported progress, everything else weighs 0.
     *
     * @param Collection<int, WorkflowTaskStep> $steps
     * @return float
     */
    private function progressPercentage(Collection $steps): float {
        if ($steps->isEmpty()) {
            return 0.0;
        }

        $effective = $steps->reduce(fn(int $carry, WorkflowTaskStep $step): int => $carry + match (true) {
            $step->status === RunStatus::COMPLETED, $step->status === RunStatus::SKIPPED => 100,
            default => $step->progress ?? 0,
        }, 0);

        return round($effective / $steps->count(), 2);
    }
}
