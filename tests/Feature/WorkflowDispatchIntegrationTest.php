<?php

use AdamczykPiotr\DagWorkflows\Definitions\Task;
use AdamczykPiotr\DagWorkflows\Definitions\Workflow as WorkflowDefinition;
use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Tests\TestCase;
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Queue;

/**
 * Exercises the real public entrypoint Workflow::dispatch() (parse → store →
 * dispatch) and asserts the persisted graph and what gets queued.
 */
class IntegrationTrackedJob implements ShouldQueue {
    use HasWorkflowTracking, InteractsWithQueue, Queueable;
    public function handle(): void {}
}

class WorkflowDispatchIntegrationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        Queue::fake();
    }


    private function dispatchSample(): Workflow {
        return (new WorkflowDefinition('wf', [
            new Task('a', [new IntegrationTrackedJob(), new IntegrationTrackedJob()]),
            new Task('b', new IntegrationTrackedJob(), dependsOn: 'a'),
        ]))->dispatch();
    }


    public function test_persists_the_workflow_task_and_step_rows(): void {
        $model = $this->dispatchSample();

        $this->assertSame('wf', $model->name);
        $this->assertSame(RunStatus::PENDING, $model->refresh()->status);

        $this->assertSame(2, $model->tasks()->count());

        $a = $model->tasks()->where(WorkflowTask::ATTRIBUTE_NAME, 'a')->firstOrFail();
        $b = $model->tasks()->where(WorkflowTask::ATTRIBUTE_NAME, 'b')->firstOrFail();

        $this->assertSame(2, $a->steps()->count());
        $this->assertSame(1, $b->steps()->count());
        $this->assertSame(RunStatus::PENDING, $a->status);

        // Steps are ordered and carry the job class.
        $orders = $a->steps()->pluck(WorkflowTaskStep::ATTRIBUTE_ORDER)->toArray();
        $this->assertSame([1, 2], $orders);
        $this->assertSame(IntegrationTrackedJob::class, $a->steps()->first()->class);
    }


    public function test_step_payload_deserializes_to_the_original_job(): void {
        $model = $this->dispatchSample();

        $step = $model->tasks()->where(WorkflowTask::ATTRIBUTE_NAME, 'a')->firstOrFail()
            ->steps()->orderBy(WorkflowTaskStep::ATTRIBUTE_ORDER)->first();

        $job = unserialize(base64_decode($step->payload));

        $this->assertInstanceOf(IntegrationTrackedJob::class, $job);
    }


    public function test_wires_the_dependency_pivot_in_the_right_direction(): void {
        $model = $this->dispatchSample();

        $a = $model->tasks()->where(WorkflowTask::ATTRIBUTE_NAME, 'a')->firstOrFail();
        $b = $model->tasks()->where(WorkflowTask::ATTRIBUTE_NAME, 'b')->firstOrFail();

        // b depends on a; a is depended on by b.
        $this->assertSame([$a->id], $b->dependencies()->pluck(WorkflowTask::ATTRIBUTE_ID)->toArray());
        $this->assertSame([$b->id], $a->dependants()->pluck(WorkflowTask::ATTRIBUTE_ID)->toArray());
        $this->assertSame(0, $a->dependencies()->count());
    }


    public function test_queues_only_the_first_step_of_entrypoint_tasks(): void {
        $this->dispatchSample();

        // Only task a is an entrypoint, and only its first step is dispatched;
        // task b waits on a, and a's second step waits on the first.
        Queue::assertPushed(IntegrationTrackedJob::class, 1);
    }
}
