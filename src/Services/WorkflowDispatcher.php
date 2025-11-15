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
     * @return void
     */
    public function dispatchWorkflow(Workflow $workflow): void {
        $entrypoint = WorkflowTask::query()
            ->where(WorkflowTask::ATTRIBUTE_WORKFLOW_ID, $workflow->id)
            ->where(WorkflowTask::ATTRIBUTE_STATUS, RunStatus::PENDING)
            ->whereDoesntHave(WorkflowTask::RELATION_DEPENDENCIES)
            ->with(WorkflowTask::RELATION_INITIAL_STEP)
            ->get();

        // Prevent overlaps
        if ($workflow->status !== RunStatus::PENDING) {
            return;
        }

        $entrypoint->each(fn(WorkflowTask $task) => $this->dispatchTask($task));
    }


    /**
     * @param WorkflowTask $task
     * @return void
     */
    public function dispatchTask(WorkflowTask $task): void {
        // Prevent overlaps
        if ($task->status !== RunStatus::PENDING) {
            return;
        }

        if ($task->initialStep === null) {
            return;
        }

        $this->dispatchStep($task->initialStep);
    }


    /**
     * @param WorkflowTask $task
     * @return void
     */
    public function dispatchDependantTasks(WorkflowTask $task): void {
        $dependantTasks = $task->dependants()
            ->where(WorkflowTask::ATTRIBUTE_STATUS, RunStatus::PENDING)
            ->whereDoesntHave(
                WorkflowTask::RELATION_DEPENDENCIES,
                fn(BuilderContract $builder) => $builder->where(WorkflowTask::ATTRIBUTE_STATUS, '!=', RunStatus::COMPLETED) // @phpstan-ignore-line
            )
            ->with(WorkflowTask::RELATION_INITIAL_STEP)
            ->get();

        $dependantTasks->each(fn(WorkflowTask $dependantTask) => $this->dispatchTask($dependantTask));
    }


    /**
     * @param WorkflowTaskStep $step
     * @return void
     */
    public function dispatchStep(WorkflowTaskStep $step): void {
        // Prevent overlaps
        if ($step->status !== RunStatus::PENDING) {
            return;
        }

        // Status will be updated when job will be picked up by queue worker

        /** @var object{workflowTaskStep: WorkflowTaskStep} $job */
        $job = unserialize(
            base64_decode($step->payload)
        );

        $job->workflowTaskStep = $step; // @phpstan-ignore-line
        dispatch($job);
    }
}
