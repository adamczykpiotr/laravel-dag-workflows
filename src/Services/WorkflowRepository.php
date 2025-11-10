<?php

namespace AdamczykPiotr\DagWorkflows\Services;

use AdamczykPiotr\DagWorkflows\Dto\TaskDto;
use AdamczykPiotr\DagWorkflows\Dto\TaskStepDto;
use AdamczykPiotr\DagWorkflows\Dto\WorkflowDto;
use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use DB;
use Illuminate\Support\Collection;
use Throwable;

class WorkflowRepository {

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
        $tasks = $taskDtos->map(function(TaskDto $taskDto) use ($workflow) {
            return [
                WorkflowTask::ATTRIBUTE_WORKFLOW_ID => $workflow->id,
                WorkflowTask::ATTRIBUTE_NAME => $taskDto->name,
                WorkflowTask::ATTRIBUTE_STATUS => RunStatus::PENDING,
                WorkflowTask::ATTRIBUTE_STARTED_AT => null,
                WorkflowTask::ATTRIBUTE_FAILED_AT => null,
                WorkflowTask::ATTRIBUTE_COMPLETED_AT => null,
                WorkflowTask::ATTRIBUTE_CREATED_AT => now(),
                WorkflowTask::ATTRIBUTE_UPDATED_AT => now(),
            ];
        });
        WorkflowTask::insert($tasks->toArray());

        $mapping = WorkflowTask::query()
            ->where(WorkflowTask::ATTRIBUTE_WORKFLOW_ID, $workflow->id)
            ->pluck(WorkflowTask::ATTRIBUTE_ID, WorkflowTask::ATTRIBUTE_NAME);

        $steps = $taskDtos->map(function(TaskDto $taskDto) use ($mapping) {
            $taskId = $mapping->get($taskDto->name);
            return collect($taskDto->steps)->map(function(TaskStepDto $stepDto) use ($taskId) {
                return [
                    WorkflowTaskStep::ATTRIBUTE_TASK_ID => $taskId,
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
        WorkflowTaskStep::insert($steps->toArray());

        $dependencies = $taskDtos->map(function(TaskDto $taskDto) use ($mapping) {
            $taskId = $mapping->get($taskDto->name);
            return collect($taskDto->dependsOn)
                ->map(function(string $dependencyName) use ($mapping, $taskId) {
                    $dependencyId = $mapping->get($dependencyName);
                    return [
                        WorkflowTask::PIVOT_COLUMN_TASK_ID => $taskId,
                        WorkflowTask::PIVOT_COLUMN_DEPENDANT_TASK_ID => $dependencyId,
                    ];
                });
        })->flatten(1);
        DB::table(WorkflowTask::PIVOT_DEPENDENCIES_TABLE)->insert($dependencies->toArray());
    }
}
