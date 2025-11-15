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
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskUnresolvedDependencyException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskWithoutJobException;
use AdamczykPiotr\DagWorkflows\Jobs\ResolvableTaskResolverJob;
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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
     */
    public function parse(Workflow $definition): WorkflowDto {
        /** @var Collection<int, TaskDto> $tasks */
        $tasks = collect($definition->tasks)
            ->filter(fn(mixed $task) => $task instanceof Task || $task instanceof ResolvableTask || $task instanceof TaskGroup) // @phpstan-ignore-line
            ->map(fn(Task|ResolvableTask|TaskGroup $task) => match ($task::class) {
                TaskGroup::class => $this->parseTaskGroup($task),
                Task::class => $this->parseTask($task),
                ResolvableTask::class => $this->parseResolvableTask($task),
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
            ->filter(fn($task) => $task instanceof Task) // @phpstan-ignore-line
            ->values();

        // Merge dependencies
        $tasks = $tasks->map(function(Task $task) use ($definition) {
            /** @var array<int, string> $dependencies */
            $dependencies = Collection::wrap($task->dependsOn)
                ->merge(Collection::wrap($definition->dependsOn))
                ->unique()
                ->values()
                ->toArray();

            $task->dependsOn = $dependencies;
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
     */
    private function parseResolvableTask(ResolvableTask $definition): TaskDto {
        $dependsOn = Collection::wrap($definition->dependsOn)->values();

        $job = new ResolvableTaskResolverJob(
            name: $definition->name,
            dependsOn: collect(...$dependsOn)->push($definition->name)->toArray(),
            itemProvider: new SerializableClosure($definition->items),
            jobProvider: new SerializableClosure($definition->jobs),
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
                // Skip dynamic dependencies
                if (Str::endsWith($dependency, ':')) {
                    continue;
                }

                if ($namedTasks->has($dependency) === false) {
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
