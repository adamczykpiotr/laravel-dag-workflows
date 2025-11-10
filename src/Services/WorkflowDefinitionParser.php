<?php

namespace AdamczykPiotr\DagWorkflows\Services;

use AdamczykPiotr\DagWorkflows\Definitions\Task;
use AdamczykPiotr\DagWorkflows\Definitions\TaskGroup;
use AdamczykPiotr\DagWorkflows\Definitions\Workflow;
use AdamczykPiotr\DagWorkflows\Dto\TaskDto;
use AdamczykPiotr\DagWorkflows\Dto\TaskStepDto;
use AdamczykPiotr\DagWorkflows\Dto\WorkflowDto;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskCircularDependencyException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskDuplicateNameException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskMissingTrackingTraitException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskUnresolvedDependencyException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskWithoutJobException;
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;
use Illuminate\Support\Collection;

class WorkflowDefinitionParser {

    /**
     * @param Workflow $definition
     * @return WorkflowDto
     * @throws WorkflowTaskMissingTrackingTraitException
     * @throws WorkflowTaskWithoutJobException
     * @throws WorkflowTaskUnresolvedDependencyException
     * @throws WorkflowTaskCircularDependencyException
     * @throws WorkflowTaskDuplicateNameException
     */
    public function parse(Workflow $definition): WorkflowDto {
        /** @var Collection<int, TaskDto> $tasks */
        $tasks = collect($definition->tasks)
            ->filter(fn($task) => $task instanceof Task || $task instanceof TaskGroup) // @phpstan-ignore-line
            ->map(fn(Task|TaskGroup $task) => ($task instanceof Task)
                ? collect([$this->parseTask($task)])
                : $this->parseTaskGroup($task)
            )
            ->flatten(1)
            ->values();

        $this->validateUnresolvedDependencies($tasks);
        $this->validateCircularDependencies($tasks);

        return new WorkflowDto(
            name: $definition->name,
            tasks: $tasks,
        );
    }


    /**
     * @param TaskGroup $definition
     * @return Collection<int, TaskDto>
     * @throws WorkflowTaskMissingTrackingTraitException
     * @throws WorkflowTaskWithoutJobException
     */
    protected function parseTaskGroup(TaskGroup $definition): Collection {
        $tasks = Collection::wrap($definition->tasks)
            ->filter(fn($task) => $task instanceof Task) // @phpstan-ignore-line
            ->values();

        // Merge dependencies
        $tasks = $tasks->map(function(Task $task) use ($definition) {
            $dependencies = Collection::wrap($task->dependsOn)
                ->merge($definition->dependsOn)
                ->unique()
                ->values();

            $task->dependsOn = $dependencies->toArray();
            return $task;
        });

        return $tasks->map(fn(Task $task) => $this->parseTask($task));
    }


    /**
     * @param Task $definition
     * @return TaskDto
     * @throws WorkflowTaskMissingTrackingTraitException
     * @throws WorkflowTaskWithoutJobException
     */
    protected function parseTask(Task $definition): TaskDto {
        $jobs = Collection::wrap($definition->jobs)
            ->filter(fn($job) => is_object($job))  // @phpstan-ignore-line
            ->values();

        if ($jobs->isEmpty()) {
            throw new WorkflowTaskWithoutJobException(
                "Task {$definition->name} does not contain any valid job."
            );
        }

        foreach ($jobs as $job) {
            $usedTraits = class_uses($job);

            if (in_array(HasWorkflowTracking::class, $usedTraits) === false) {
                $class = get_class($job);
                throw new WorkflowTaskMissingTrackingTraitException(
                    "Task {$definition->name} contains a job of class {$class} which does not use required HasWorkflowTracking trait."
                );
            }
        }

        return new TaskDto(
            name: $definition->name,
            steps: $jobs->map(fn(object $job, int $index) => new TaskStepDto($index + 1, $job)),
            dependsOn: Collection::wrap($definition->dependsOn)->values()
        );
    }


    /**
     * @param Collection<int, TaskDto> $tasks
     * @return void
     * @throws WorkflowTaskCircularDependencyException
     */
    protected function validateCircularDependencies(Collection $tasks): void {
        $namedTasks = $tasks->keyBy(fn(TaskDto $task) => $task->name);

        $visited = collect();
        $recursionStack = collect();

        $checkForCycles = function(string $taskName) use (&$checkForCycles, $namedTasks, $visited, $recursionStack) {
            if ($visited->has($taskName)) {
                return;
            }

            if ($recursionStack->has($taskName)) {
                $cyclePath = $recursionStack->keys()
                    ->skipUntil(fn($name) => $name === $taskName)
                    ->push($taskName);

                throw new WorkflowTaskCircularDependencyException(
                    "Circular dependency detected for task {$taskName}: {$cyclePath->implode(' -> ')}"
                );
            }

            $recursionStack->put($taskName, true);

            $dependencies = $namedTasks->get($taskName)->dependsOn;
            $dependencies->each(fn(string $dependency) => $checkForCycles($dependency));

            $recursionStack->pull($taskName);
            $visited->put($taskName, true);
        };

        $namedTasks->keys()->each($checkForCycles);
    }


    /**
     * @param Collection<int, TaskDto> $tasks
     * @return void
     * @throws WorkflowTaskUnresolvedDependencyException
     * @throws WorkflowTaskDuplicateNameException
     */
    protected function validateUnresolvedDependencies(Collection $tasks): void {
        $namedTasks = $tasks->keyBy(fn(TaskDto $task) => $task->name);

        if ($namedTasks->count() !== $tasks->count()) {
            $duplicate = $tasks->groupBy(fn(TaskDto $task) => $task->name)
                ->filter(fn(Collection $group) => $group->count() > 1)
                ->keys()
                ->implode(', ');

            throw new WorkflowTaskDuplicateNameException(
                "Workflow contains tasks with duplicate names: {$duplicate}."
            );
        }

        foreach ($tasks as $taskName => $task) {
            foreach ($task->dependsOn as $dependency) {
                if ($namedTasks->has($dependency) === false) {
                    throw new WorkflowTaskUnresolvedDependencyException(
                        "Task {$taskName} has an unresolved dependency on task {$dependency}."
                    );
                }
            }
        }
    }
}
