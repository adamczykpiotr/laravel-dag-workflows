<?php

namespace AdamczykPiotr\DagWorkflows\Services;

use AdamczykPiotr\DagWorkflows\Definitions\ResolvableTask;
use AdamczykPiotr\DagWorkflows\Definitions\Task;
use AdamczykPiotr\DagWorkflows\Definitions\TaskGroup;
use AdamczykPiotr\DagWorkflows\Definitions\Workflow;
use AdamczykPiotr\DagWorkflows\Dto\TaskDto;
use AdamczykPiotr\DagWorkflows\Dto\TaskStepDto;
use AdamczykPiotr\DagWorkflows\Dto\WorkflowDto;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskCircularDependencyException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskDuplicateNameException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskMissingTrackingTraitException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskReservedCharacterException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskUnresolvedDependencyException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskWithoutJobException;
use AdamczykPiotr\DagWorkflows\Jobs\ResolvableTaskResolverJob;
use AdamczykPiotr\DagWorkflows\Services\Support\DynamicDependencies;
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;
use Closure;
use Illuminate\Support\Collection;
use Laravel\SerializableClosure\SerializableClosure;

class WorkflowDefinitionParser {

    /**
     * @param Workflow $definition
     * @return WorkflowDto
     * @throws WorkflowTaskMissingTrackingTraitException
     * @throws WorkflowTaskWithoutJobException
     * @throws WorkflowTaskUnresolvedDependencyException
     * @throws WorkflowTaskCircularDependencyException
     * @throws WorkflowTaskDuplicateNameException
     * @throws WorkflowTaskReservedCharacterException
     */
    public function parse(Workflow $definition): WorkflowDto {
        /** @var Collection<int, TaskDto> $tasks */
        $tasks = collect($definition->tasks)
            ->filter(fn(mixed $task) => $task instanceof Task || $task instanceof ResolvableTask || $task instanceof TaskGroup) // @phpstan-ignore-line
            ->map(fn(Task|ResolvableTask|TaskGroup $task) => match ($task::class) {
                TaskGroup::class => $this->parseTaskGroup($task),
                Task::class => $this->parseTask($task),
                ResolvableTask::class => $this->parseResolvableTask($task),
                default => [],
            })
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
     * @param Collection<int, Task> $tasks
     * @return Collection<int, TaskDto>
     */
    public function parseTasksFromResolvable(Collection $tasks): Collection {
        return $tasks->map(fn(Task $task) => $this->parseTask($task));
    }


    /**
     * @param TaskGroup $definition
     * @return Collection<int, TaskDto>
     * @throws WorkflowTaskMissingTrackingTraitException
     * @throws WorkflowTaskWithoutJobException
     */
    protected function parseTaskGroup(TaskGroup $definition): Collection {
        $tasks = Collection::wrap($definition->tasks)
            ->filter(fn($task) => $task instanceof Task || $task instanceof ResolvableTask) // @phpstan-ignore-line
            ->values();

        // Merge dependencies
        $tasks = $tasks->map(function(Task|ResolvableTask $task) use ($definition) {
            /** @var array<int, string> $dependencies */
            $dependencies = Collection::wrap($task->dependsOn)
                ->merge(Collection::wrap($definition->dependsOn))
                ->unique()
                ->values()
                ->toArray();

            $task->dependsOn = $dependencies;
            return $task;
        });

        return $tasks->map(fn(Task|ResolvableTask $task) => $task instanceof Task
            ? $this->parseTask($task)
            : $this->parseResolvableTask($task)
        );
    }


    /**
     * @param Task $definition
     * @return TaskDto
     * @throws WorkflowTaskMissingTrackingTraitException
     * @throws WorkflowTaskWithoutJobException
     * @throws WorkflowTaskReservedCharacterException
     */
    protected function parseTask(Task $definition): TaskDto {
        $this->guardReservedCharacters($definition->name);

        $jobs = Collection::wrap($definition->jobs)
            ->filter(fn($job) => is_object($job))  // @phpstan-ignore-line
            ->values();

        if ($jobs->isEmpty()) {
            throw new WorkflowTaskWithoutJobException(
                "Task {$definition->name} does not contain any valid job."
            );
        }

        foreach ($jobs as $job) {
            if ($this->usesTrait($job, HasWorkflowTracking::class) === false) {
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
     * @param ResolvableTask $definition
     * @return TaskDto
     * @throws WorkflowTaskReservedCharacterException
     */
    private function parseResolvableTask(ResolvableTask $definition): TaskDto {
        $this->guardReservedCharacters($definition->name);

        $dependsOn = Collection::wrap($definition->dependsOn)->values();

        $job = new ResolvableTaskResolverJob(
            name: $definition->name,
            dependsOn: [...$dependsOn->all(), $definition->name],
            itemProvider: new SerializableClosure($definition->items), // @phpstan-ignore-line
            jobProvider: new SerializableClosure($definition->jobs), // @phpstan-ignore-line
        );

        // It's nearly impossible without resorting to parsing php in php to detect missing traits or empty jobs beforehand
        // Runs from above will be executed in runtime when the ResolvableTaskResolverJob is handled

        return new TaskDto(
            name: $definition->name,
            steps: collect([
                new TaskStepDto(
                    order: 1,
                    job: $job
                ),
            ]),
            dependsOn: $dependsOn
        );
    }


    /**
     * "*" is reserved for the dynamic dependency syntax ("Task name:*") and would
     * make such dependency declarations ambiguous if allowed in task names.
     *
     * @param string $taskName
     * @return void
     * @throws WorkflowTaskReservedCharacterException
     */
    protected function guardReservedCharacters(string $taskName): void {
        if (str_contains($taskName, DynamicDependencies::RESERVED_CHARACTER)) {
            throw new WorkflowTaskReservedCharacterException(
                "Task name {$taskName} contains the reserved character " . DynamicDependencies::RESERVED_CHARACTER . '.'
            );
        }
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

            $dependencies = $namedTasks->get($taskName)->dependsOn ?? collect();
            $dependencies->each(fn(string $dependency) => $checkForCycles(DynamicDependencies::baseTaskName($dependency)));

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
                // Dynamic dependencies ("Task name:*") gate on the base task and on every
                // task it spawns at runtime — the base task must exist upfront.
                if ($namedTasks->has(DynamicDependencies::baseTaskName($dependency)) === false) {
                    throw new WorkflowTaskUnresolvedDependencyException(
                        "Task {$taskName} has an unresolved dependency on task {$dependency}."
                    );
                }
            }
        }
    }


    /**
     * @param object|class-string $class
     * @param class-string $trait
     * @return bool
     */
    protected function usesTrait(object|string $class, string $trait): bool {
        while (true) {
            $traits = class_uses($class);

            if (in_array($trait, $traits)) {
                return true;
            }

            $class = get_parent_class($class);
            if ($class === false) {
                return false;
            }
        }
    }
}
