<?php

namespace AdamczykPiotr\DagWorkflows\Tests;

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Services\WorkflowDispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class TestJob implements ShouldQueue
{
    use Dispatchable;

    public $workflowTaskStep;
    public $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function handle()
    {
        // Do nothing
    }
}

class WorkflowRetryTest extends TestCase
{
    public function test_retry_step_resets_state_and_dependants()
    {
        Queue::fake();

        // Arrange: Create Workflow with Task A (2 steps) -> Task B (1 step)
        $workflow = Workflow::create([
            'name' => 'Test Workflow',
            'status' => RunStatus::FAILED
        ]);

        $taskA = WorkflowTask::create([
            'workflow_id' => $workflow->id,
            'name' => 'Task A',
            'status' => RunStatus::FAILED,
        ]);

        $stepA1 = WorkflowTaskStep::create([
            'workflow_id' => $workflow->id,
            'task_id' => $taskA->id,
            'order' => 1,
            'status' => RunStatus::FAILED,
            'payload' => base64_encode(serialize(new TestJob('job A1'))),
        ]);

        $stepA2 = WorkflowTaskStep::create([
            'workflow_id' => $workflow->id,
            'task_id' => $taskA->id,
            'order' => 2,
            'status' => RunStatus::CANCELLED,
            'payload' => base64_encode(serialize(new TestJob('job A2'))),
        ]);

        $taskB = WorkflowTask::create([
            'workflow_id' => $workflow->id,
            'name' => 'Task B',
            'status' => RunStatus::CANCELLED,
        ]);

        $taskA->dependants()->attach($taskB);

        $stepB1 = WorkflowTaskStep::create([
            'workflow_id' => $workflow->id,
            'task_id' => $taskB->id,
            'order' => 1,
            'status' => RunStatus::CANCELLED,
            'payload' => base64_encode(serialize(new TestJob('job B1'))),
        ]);

        /** @var WorkflowDispatcher $dispatcher */
        $dispatcher = resolve(WorkflowDispatcher::class);

        // Act
        $dispatcher->retryStep($stepA1);

        // Assert
        $stepA1->refresh();
        $this->assertEquals(RunStatus::PENDING, $stepA1->status);

        $stepA2->refresh();
        $this->assertEquals(RunStatus::PENDING, $stepA2->status);

        $taskA->refresh();
        $this->assertEquals(RunStatus::RUNNING, $taskA->status);

        $workflow->refresh();
        $this->assertEquals(RunStatus::RUNNING, $workflow->status);

        $taskB->refresh();
        $this->assertEquals(RunStatus::PENDING, $taskB->status);

        $stepB1->refresh();
        $this->assertEquals(RunStatus::PENDING, $stepB1->status);

        // Assert Job Dispatched
        Queue::assertPushed(TestJob::class, function (TestJob $job) use ($stepA1) {
             return isset($job->workflowTaskStep) && $job->workflowTaskStep->id === $stepA1->id;
        });
    }
}

