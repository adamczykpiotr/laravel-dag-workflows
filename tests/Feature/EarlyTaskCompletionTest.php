<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskEarlyCompletionException;
use AdamczykPiotr\DagWorkflows\Middlewares\DagWorkflowTrackerJobMiddleware;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Tests\TestCase;
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Queue;

class EarlyCompletionTestJob implements ShouldQueue {
    use HasWorkflowTracking, InteractsWithQueue, Queueable;

    public function handle(): void {
        $this->completeTaskEarly('source unchanged');
    }
}

class EarlyTaskCompletionTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        Queue::fake();
    }


    public function test_skipped_status_is_terminal(): void {
        $this->assertTrue(RunStatus::SKIPPED->isTerminal());
    }


    public function test_skipped_status_is_not_active_and_not_blocked(): void {
        $this->assertFalse(RunStatus::SKIPPED->isActive());
        $this->assertFalse(RunStatus::SKIPPED->isBlocked());
    }


    public function test_trait_method_throws_the_early_completion_exception(): void {
        $job = new EarlyCompletionTestJob();

        $this->expectException(WorkflowTaskEarlyCompletionException::class);
        $this->expectExceptionMessage('why not');

        $job->completeTaskEarly('why not');
    }


    public function test_completes_the_current_step_and_skips_the_remaining_steps(): void {
        $workflow = $this->makeWorkflow();
        $task = $this->makeTask($workflow);
        $step1 = $this->makeStep($task, order: 1);
        $step2 = $this->makeStep($task, order: 2);
        $step3 = $this->makeStep($task, order: 3);

        $result = $this->runThroughMiddleware($step1);

        $this->assertNull($result);
        $this->assertSame(RunStatus::COMPLETED, $step1->refresh()->status);
        $this->assertNotNull($step1->completed_at);
        $this->assertSame(RunStatus::SKIPPED, $step2->refresh()->status);
        $this->assertSame(RunStatus::SKIPPED, $step3->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $task->refresh()->status);
        $this->assertNotNull($task->completed_at);
    }


    public function test_completes_the_workflow_when_it_was_the_only_task(): void {
        $workflow = $this->makeWorkflow();
        $task = $this->makeTask($workflow);
        $step1 = $this->makeStep($task, order: 1);
        $this->makeStep($task, order: 2);

        $this->runThroughMiddleware($step1);

        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
        $this->assertNotNull($workflow->completed_at);
    }


    public function test_dispatches_dependant_tasks_as_a_normal_completion_would(): void {
        $workflow = $this->makeWorkflow();
        $task = $this->makeTask($workflow, 'first');
        $step1 = $this->makeStep($task, order: 1);
        $this->makeStep($task, order: 2);

        $dependantTask = $this->makeTask($workflow, 'second');
        $this->makeStep($dependantTask, order: 1);
        $dependantTask->dependencies()->attach($task->id);

        $this->runThroughMiddleware($step1);

        Queue::assertPushed(EarlyCompletionTestJob::class, 1);
        $this->assertNotSame(RunStatus::COMPLETED, $workflow->refresh()->status);
    }


    public function test_does_not_skip_steps_of_other_tasks(): void {
        $workflow = $this->makeWorkflow();
        $task = $this->makeTask($workflow, 'first');
        $step1 = $this->makeStep($task, order: 1);

        $otherTask = $this->makeTask($workflow, 'independent');
        $otherStep = $this->makeStep($otherTask, order: 1);

        $this->runThroughMiddleware($step1);

        $this->assertSame(RunStatus::PENDING, $otherStep->refresh()->status);
    }


    public function test_marks_a_mid_task_step_as_completed_and_only_skips_later_steps(): void {
        $workflow = $this->makeWorkflow();
        $task = $this->makeTask($workflow);
        $step1 = $this->makeStep($task, order: 1);
        $step2 = $this->makeStep($task, order: 2);
        $step3 = $this->makeStep($task, order: 3);

        // Step 1 already ran normally.
        $step1->status = RunStatus::COMPLETED;
        $step1->save();

        $this->runThroughMiddleware($step2);

        $this->assertSame(RunStatus::COMPLETED, $step1->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $step2->refresh()->status);
        $this->assertSame(RunStatus::SKIPPED, $step3->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $task->refresh()->status);
    }


    private function makeWorkflow(): Workflow {
        $workflow = new Workflow();
        $workflow->name = 'etc-workflow';
        $workflow->status = RunStatus::PENDING;
        $workflow->save();

        return $workflow;
    }


    private function makeTask(Workflow $workflow, string $name = 'etc-task'): WorkflowTask {
        $task = new WorkflowTask();
        $task->workflow_id = $workflow->id;
        $task->name = $name;
        $task->status = RunStatus::PENDING;
        $task->save();

        return $task;
    }


    private function makeStep(WorkflowTask $task, int $order = 1): WorkflowTaskStep {
        $step = new WorkflowTaskStep();
        $step->task_id = $task->id;
        $step->workflow_id = $task->workflow_id;
        $step->order = $order;
        $step->class = EarlyCompletionTestJob::class;
        $step->status = RunStatus::PENDING;
        $step->payload = base64_encode(serialize(new EarlyCompletionTestJob()));
        $step->save();

        return $step;
    }


    private function runThroughMiddleware(WorkflowTaskStep $step): mixed {
        $job = new EarlyCompletionTestJob();
        $job->workflowTaskStep = $step;

        $middleware = resolve(DagWorkflowTrackerJobMiddleware::class);

        return $middleware->handle($job, fn(object $j) => $j->handle());
    }
}
