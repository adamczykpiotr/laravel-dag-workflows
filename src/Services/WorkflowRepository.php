<?php

namespace AdamczykPiotr\DagWorkflows\Services;

use AdamczykPiotr\DagWorkflows\Dto\TaskDto;
use AdamczykPiotr\DagWorkflows\Dto\TaskStepDto;
use AdamczykPiotr\DagWorkflows\Dto\WorkflowDto;
use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskUnresolvedDependencyException;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Services\Support\DynamicDependencies;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class WorkflowRepository {

    const int INSERT_CHUNK_SIZE = 5_000;


    /**
     * @param WorkflowDto $dto
     * @return Workflow
     * @throws Throwable
     */
    public function store(WorkflowDto $dto): Workflow {
        return DB::transaction(function() use ($dto) {
            $workflow = $this->storeWorkflow($dto);
            $this->storeTasks($workflow, $dto->tasks);

            return $workflow;
        });
    }


    /**
     * @param Workflow $workflow
     * @param Collection<int, TaskDto> $taskDtos
     * @return void
     * @throws Throwable
     */
    public function appendTasks(Workflow $workflow, Collection $taskDtos): void {
        DB::transaction(function() use ($workflow, $taskDtos) {
            $this->storeTasks($workflow, $taskDtos);
        });
    }


    /**
     * @param WorkflowDto $dto
     * @return Workflow
     */
    protected function storeWorkflow(WorkflowDto $dto): Workflow {
        $workflow = new Workflow();
        $workflow->name = $dto->name;
        $workflow->status = RunStatus::PENDING;
        $workflow->started_at = null;
        $workflow->failed_at = null;
        $workflow->completed_at = null;
        $workflow->save();

        return $workflow;
    }


    /**
     * @param Workflow $workflow
     * @param Collection<int, TaskDto> $taskDtos
     * @return void
     */
    protected function storeTasks(Workflow $workflow, Collection $taskDtos): void {
        // Captured before the insert below so it only holds tasks stored in earlier
        // calls (e.g. the initial definition) whose dynamic dependencies may match
        // the tasks appended now.
        $storedDynamicDependants = WorkflowTask::query()
            ->where(WorkflowTask::ATTRIBUTE_WORKFLOW_ID, $workflow->id)
            ->whereNotNull(WorkflowTask::ATTRIBUTE_DYNAMIC_DEPENDENCIES)
            ->get();

        $tasks = $taskDtos->map(function(TaskDto $taskDto) use ($workflow) {
            $dynamicDependencies = collect($taskDto->dependsOn)
                ->filter(fn(string $dependencyName) => DynamicDependencies::isDynamic($dependencyName))
                ->values();

            return [
                WorkflowTask::ATTRIBUTE_WORKFLOW_ID => $workflow->id,
                WorkflowTask::ATTRIBUTE_NAME => $taskDto->name,
                WorkflowTask::ATTRIBUTE_DYNAMIC_DEPENDENCIES => $dynamicDependencies->isEmpty() ? null : $dynamicDependencies->toJson(),
                WorkflowTask::ATTRIBUTE_STATUS => RunStatus::PENDING,
                WorkflowTask::ATTRIBUTE_STARTED_AT => null,
                WorkflowTask::ATTRIBUTE_FAILED_AT => null,
                WorkflowTask::ATTRIBUTE_COMPLETED_AT => null,
                WorkflowTask::ATTRIBUTE_CREATED_AT => now(),
                WorkflowTask::ATTRIBUTE_UPDATED_AT => now(),
            ];
        });

        foreach ($tasks->chunk(self::INSERT_CHUNK_SIZE) as $chunk) {
            WorkflowTask::insert($chunk->toArray());
        }

        $mapping = WorkflowTask::query()
            ->where(WorkflowTask::ATTRIBUTE_WORKFLOW_ID, $workflow->id)
            ->get()
            ->mapWithKeys(fn(WorkflowTask $task) => [$task->name => $task->id]);

        $steps = $taskDtos->map(function(TaskDto $taskDto) use ($mapping, $workflow) {
            $taskId = $mapping->get($taskDto->name);
            $workflowId = $workflow->id;

            return collect($taskDto->steps)->map(function(TaskStepDto $stepDto) use ($taskId, $workflowId) {
                return [
                    WorkflowTaskStep::ATTRIBUTE_TASK_ID => $taskId,
                    WorkflowTaskStep::ATTRIBUTE_WORKFLOW_ID => $workflowId,
                    WorkflowTaskStep::ATTRIBUTE_CLASS => $stepDto->job::class,
                    WorkflowTaskStep::ATTRIBUTE_ORDER => $stepDto->order,
                    WorkflowTaskStep::ATTRIBUTE_STATUS => RunStatus::PENDING,
                    WorkflowTaskStep::ATTRIBUTE_STARTED_AT => null,
                    WorkflowTaskStep::ATTRIBUTE_FAILED_AT => null,
                    WorkflowTaskStep::ATTRIBUTE_COMPLETED_AT => null,
                    WorkflowTaskStep::ATTRIBUTE_PAYLOAD => base64_encode(serialize($stepDto->job)),
                    WorkflowTaskStep::ATTRIBUTE_CREATED_AT => now(),
                    WorkflowTaskStep::ATTRIBUTE_UPDATED_AT => now(),
                ];
            });
        })->flatten(1);

        foreach ($steps->chunk(self::INSERT_CHUNK_SIZE) as $chunk) {
            WorkflowTaskStep::insert($chunk->toArray());
        }

        $dependencies = $taskDtos->map(function(TaskDto $taskDto) use ($mapping) {
            $taskId = $mapping->get($taskDto->name);

            return collect($taskDto->dependsOn)
                ->flatMap(fn(string $dependencyName) => $this->resolveDependencyIds($mapping, $dependencyName))
                ->unique()
                ->map(function(int $dependencyId) use ($taskId) {
                    return [
                        WorkflowTask::PIVOT_COLUMN_TASK_ID => $taskId,
                        WorkflowTask::PIVOT_COLUMN_DEPENDANT_TASK_ID => $dependencyId,
                    ];
                });
        })->flatten(1);

        $dependencies = $dependencies->merge(
            $this->resolveStoredDynamicDependants($storedDynamicDependants, $taskDtos, $mapping)
        );

        foreach ($dependencies->chunk(self::INSERT_CHUNK_SIZE) as $chunk) {
            DB::table(WorkflowTask::PIVOT_DEPENDENCIES_TABLE)->insert($chunk->toArray());
        }
    }


    /**
     * Task IDs a dependsOn entry gates on within the current workflow. A static
     * dependency resolves to its named task; a dynamic one ("Task name:*") resolves
     * to the base task plus every already-stored task matching the name prefix.
     *
     * @param Collection<string, int> $mapping
     * @param string $dependencyName
     * @return Collection<int, int>
     */
    protected function resolveDependencyIds(Collection $mapping, string $dependencyName): Collection {
        if (DynamicDependencies::isDynamic($dependencyName) === false) {
            $dependencyId = $mapping->get($dependencyName);

            if ($dependencyId === null) {
                throw new WorkflowTaskUnresolvedDependencyException(
                    "Dependency on task {$dependencyName} cannot be resolved within the stored workflow."
                );
            }

            return collect([$dependencyId]);
        }

        $baseTaskName = DynamicDependencies::baseTaskName($dependencyName);
        $childPrefix = DynamicDependencies::childPrefix($dependencyName);

        return $mapping
            ->filter(fn(int $taskId, string $taskName) => $taskName === $baseTaskName || Str::startsWith($taskName, $childPrefix))
            ->values();
    }


    /**
     * Pivot rows wiring previously stored dynamic dependants to the tasks appended
     * in the current call (e.g. tasks spawned by a ResolvableTask at runtime).
     *
     * @param Collection<int, WorkflowTask> $storedDynamicDependants
     * @param Collection<int, TaskDto> $taskDtos
     * @param Collection<string, int> $mapping
     * @return Collection<int, array{task_id: int, dependant_task_id: int}>
     */
    protected function resolveStoredDynamicDependants(
        Collection $storedDynamicDependants,
        Collection $taskDtos,
        Collection $mapping
    ): Collection {
        $appendedIds = $mapping->only($taskDtos->map(fn(TaskDto $taskDto) => $taskDto->name));

        return $storedDynamicDependants->flatMap(function(WorkflowTask $dependant) use ($appendedIds) {
            return collect($dependant->dynamic_dependencies)
                ->flatMap(fn(string $wildcard) => $appendedIds->filter(fn(int $taskId, string $taskName) => Str::startsWith($taskName, DynamicDependencies::childPrefix($wildcard))))
                ->unique()
                ->values()
                ->map(function(int $dependencyId) use ($dependant) {
                    return [
                        WorkflowTask::PIVOT_COLUMN_TASK_ID => $dependant->id,
                        WorkflowTask::PIVOT_COLUMN_DEPENDANT_TASK_ID => $dependencyId,
                    ];
                });
        });
    }
}
