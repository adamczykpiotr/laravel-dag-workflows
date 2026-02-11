<?php

namespace AdamczykPiotr\DagWorkflows\Services;

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;

class WorkflowDispatcher {

    /**
     * @param Workflow $workflow
     * @param bool $force
     * @return bool
     */
    public function dispatchWorkflow(Workflow $workflow, bool $force = false): bool {
        $entrypoint = WorkflowTask::query()
            ->where(WorkflowTask::ATTRIBUTE_WORKFLOW_ID, $workflow->id)
            ->where(WorkflowTask::ATTRIBUTE_STATUS, RunStatus::PENDING)
            ->whereDoesntHave(WorkflowTask::RELATION_DEPENDENCIES)
            ->with(WorkflowTask::RELATION_INITIAL_STEP)
            ->get();

        // Prevent overlaps
        if ($workflow->status !== RunStatus::PENDING && $force === false) {
            return false;
        }

        $entrypoint->each(fn(WorkflowTask $task) => $this->dispatchTask($task, $force));
        return true;
    }


    /**
     * @param WorkflowTask $task
     * @param bool $force
     * @return bool
     */
    public function dispatchTask(WorkflowTask $task, bool $force = false): bool {
        // Prevent overlaps
        if ($task->status !== RunStatus::PENDING && $force === false) {
            return false;
        }

        if ($task->initialStep === null) {
            return false;
        }

        return $this->dispatchStep($task->initialStep, $force);
    }


    /**
     * @param WorkflowTask $task
     * @return bool
     */
    public function dispatchDependantTasks(WorkflowTask $task): bool {
        $dependantTasks = $task->dependants()
            ->where(WorkflowTask::ATTRIBUTE_STATUS, RunStatus::PENDING)
            ->whereDoesntHave(
                WorkflowTask::RELATION_DEPENDENCIES,
                fn(BuilderContract $builder) => $builder->where(WorkflowTask::ATTRIBUTE_STATUS, '!=', RunStatus::COMPLETED) // @phpstan-ignore-line
            )
            ->with(WorkflowTask::RELATION_INITIAL_STEP)
            ->get();

        $dependantTasks->each(fn(WorkflowTask $dependantTask) => $this->dispatchTask($dependantTask));
        return true;
    }


    /**
     * @param WorkflowTaskStep $step
     * @param bool $force
     * @return bool
     */
    public function dispatchStep(WorkflowTaskStep $step, bool $force = false): bool {
        // Prevent overlaps
        if ($force === false && $step->status !== RunStatus::PENDING) {
            return false;
        }

        // Status will be updated when job will be picked up by queue worker

        /** @var object{workflowTaskStep: WorkflowTaskStep} $job */
        $job = unserialize(
            base64_decode($step->payload)
        );

        $job->workflowTaskStep = $step; // @phpstan-ignore-line
        dispatch($job);
        return true;
    }
}
