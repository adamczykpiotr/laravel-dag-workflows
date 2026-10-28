<?php

declare(strict_types=1);

namespace AdamczykPiotr\DagWorkflows\Services;

use AdamczykPiotr\DagWorkflows\Dto\WorkflowEstimateDto;
use AdamczykPiotr\DagWorkflows\Dto\WorkflowTaskEstimateDto;
use AdamczykPiotr\DagWorkflows\Dto\WorkflowTaskStepEstimateDto;
use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use Carbon\CarbonInterface;

/**
 * Produces a duration / ETA estimate from a workflow's eager-loaded tasks.steps` tree.
 * Durations span created_at → completed_at, so estimates include queue wait time.
 */
class WorkflowEstimator
{
    /**
     * @param Workflow $workflow
     *
     * @return WorkflowEstimateDto
     */
    public function build(Workflow $workflow): WorkflowEstimateDto
    {
        $classMeans = $this->computeClassMeans($workflow);

        return new WorkflowEstimateDto(
            durationSeconds: $this->workflowDuration($workflow),
            estimatedSecondsRemaining: $this->workflowRemaining($workflow, $classMeans),
            estimatedCompletionAt: $this->workflowEstimatedCompletionAt($workflow, $classMeans),
            tasks: $workflow->tasks->mapWithKeys(
                fn(WorkflowTask $task): array => [$task->id => $this->buildTaskEstimate($task, $classMeans)]
            ),
        );
    }

    /**
     * @param array<string, float> $classMeans
     * @param WorkflowTask $task
     *
     * @return WorkflowTaskEstimateDto
     */
    private function buildTaskEstimate(WorkflowTask $task, array $classMeans): WorkflowTaskEstimateDto
    {
        return new WorkflowTaskEstimateDto(
            durationSeconds: $this->taskDuration($task),
            estimatedSecondsRemaining: $this->taskRemaining($task, $classMeans),
            steps: $task->steps->mapWithKeys(
                fn(WorkflowTaskStep $step): array => [$step->id => new WorkflowTaskStepEstimateDto(
                    durationSeconds: $this->stepDuration($step),
                    estimatedDurationSeconds: (int)round($classMeans[$step->class] ?? 0.0),
                    estimatedSecondsRemaining: $this->stepRemaining($step, $classMeans),
                )],
            ),
        );
    }

    /**
     * @param WorkflowTaskStep $step
     *
     * @return int
     */
    private function stepDuration(WorkflowTaskStep $step): int
    {
        return $this->elapsedSeconds($step->created_at, $step->completed_at, $step->failed_at);
    }

    /**
     * @param array<string, float> $classMeans
     * @param WorkflowTaskStep $step
     *
     * @return int
     */
    private function stepRemaining(WorkflowTaskStep $step, array $classMeans): int
    {
        if ($step->status->isTerminal()) {
            return 0;
        }

        $mean = $classMeans[$step->class] ?? null;
        if ($mean === null) {
            return 0;
        }

        $elapsed = $step->status === RunStatus::RUNNING ? $this->stepDuration($step) : 0;

        return max(0, (int)round($mean - $elapsed));
    }

    /**
     * @param WorkflowTask $task
     *
     * @return int
     */
    private function taskDuration(WorkflowTask $task): int
    {
        return $this->elapsedSeconds($task->created_at, $task->completed_at, $task->failed_at);
    }

    /**
     * @param array<string, float> $classMeans
     * @param WorkflowTask $task
     *
     * @return int
     */
    private function taskRemaining(WorkflowTask $task, array $classMeans): int
    {
        if ($task->status->isTerminal()) {
            return 0;
        }

        return (int)$task->steps->sum(fn(WorkflowTaskStep $step): int => $this->stepRemaining($step, $classMeans));
    }

    /**
     * @param Workflow $workflow
     *
     * @return int
     */
    private function workflowDuration(Workflow $workflow): int
    {
        return $this->elapsedSeconds($workflow->created_at, $workflow->completed_at, $workflow->failed_at);
    }

    /**
     * Longest task remaining among unfinished tasks. Ignores DAG dependency edges
     * accurate enough for parallel tasks, loose for deep chains.
     *
     * @param array<string, float> $classMeans
     * @param Workflow $workflow
     *
     * @return int
     */
    private function workflowRemaining(Workflow $workflow, array $classMeans): int
    {
        $max = $workflow->tasks
            ->reject(fn(WorkflowTask $task) => $task->status->isTerminal())
            ->map(fn(WorkflowTask $task): int => $this->taskRemaining($task, $classMeans))
            ->max();

        return is_int($max) ? $max : 0;
    }

    /**
     * @param array<string, float> $classMeans
     * @param Workflow $workflow
     *
     * @return CarbonInterface|null
     */
    private function workflowEstimatedCompletionAt(Workflow $workflow, array $classMeans): ?CarbonInterface
    {
        if ($workflow->status->isTerminal()) {
            return null;
        }

        $remaining = $this->workflowRemaining($workflow, $classMeans);

        return $remaining > 0 ? now()->addSeconds($remaining) : null;
    }

    /**
     * @param Workflow $workflow
     *
     * @return array<string, float>
     */
    private function computeClassMeans(Workflow $workflow): array
    {
        return $workflow->tasks
            ->flatMap(fn(WorkflowTask $task) => $task->steps)
            ->filter(fn(WorkflowTaskStep $step): bool =>
                $step->status === RunStatus::COMPLETED
                && $step->completed_at !== null
                && $step->created_at !== null)
            ->groupBy(WorkflowTaskStep::ATTRIBUTE_CLASS)
            ->map(fn($samples): float => (float)$samples->avg(
                fn(WorkflowTaskStep $step): int => $this->stepDuration($step)
            ))
            ->all();
    }

    /**
     * @param CarbonInterface|null $createdAt
     * @param CarbonInterface|null $completedAt
     * @param CarbonInterface|null $failedAt
     *
     * @return int
     */
    private function elapsedSeconds(?CarbonInterface $createdAt, ?CarbonInterface $completedAt, ?CarbonInterface $failedAt): int
    {
        $end = $completedAt ?? $failedAt ?? now();
        $start = $createdAt ?? $end;

        return (int)$start->diffInSeconds($end);
    }
}
