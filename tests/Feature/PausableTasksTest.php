<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Events\WorkflowCancelled;
use AdamczykPiotr\DagWorkflows\Events\WorkflowPaused;
use AdamczykPiotr\DagWorkflows\Events\WorkflowResumed;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Tests\TestCase;
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

class TestPausableJob implements ShouldQueue {
    use HasWorkflowTracking, InteractsWithQueue, Queueable;
    public function handle(): void {}
}

class PausableTasksTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        Carbon::setTestNow('2026-05-09 12:00:00');
        Queue::fake();
    }


    protected function tearDown(): void {
        Carbon::setTestNow();

        parent::tearDown();
    }


    // --- RunStatus enum ---

    public function test_paused_is_not_terminal(): void {
        $this->assertFalse(RunStatus::PAUSED->isTerminal());
    }


    public function test_suspended_is_not_terminal(): void {
        $this->assertFalse(RunStatus::SUSPENDED->isTerminal());
    }


    public function test_paused_and_suspended_return_true_for_is_blocked(): void {
        $this->assertTrue(RunStatus::PAUSED->isBlocked());
        $this->assertTrue(RunStatus::SUSPENDED->isBlocked());
    }


    public function test_pending_and_running_return_true_for_is_active(): void {
        $this->assertTrue(RunStatus::PENDING->isActive());
        $this->assertTrue(RunStatus::RUNNING->isActive());
    }


    public function test_other_statuses_return_false_for_is_active(): void {
        $this->assertFalse(RunStatus::PAUSED->isActive());
        $this->assertFalse(RunStatus::SUSPENDED->isActive());
        $this->assertFalse(RunStatus::COMPLETED->isActive());
        $this->assertFalse(RunStatus::FAILED->isActive());
        $this->assertFalse(RunStatus::CANCELLED->isActive());
    }


    public function test_pending_and_running_can_be_paused(): void {
        $this->assertTrue(RunStatus::PENDING->canBePaused());
        $this->assertTrue(RunStatus::RUNNING->canBePaused());
    }


    public function test_terminal_statuses_cannot_be_paused(): void {
        $this->assertFalse(RunStatus::COMPLETED->canBePaused());
        $this->assertFalse(RunStatus::FAILED->canBePaused());
        $this->assertFalse(RunStatus::CANCELLED->canBePaused());
    }


    public function test_paused_and_suspended_cannot_be_paused_again(): void {
        $this->assertFalse(RunStatus::PAUSED->canBePaused());
        $this->assertFalse(RunStatus::SUSPENDED->canBePaused());
    }


    public function test_only_paused_can_be_resumed(): void {
        $this->assertTrue(RunStatus::PAUSED->canBeResumed());
        $this->assertFalse(RunStatus::PENDING->canBeResumed());
        $this->assertFalse(RunStatus::RUNNING->canBeResumed());
        $this->assertFalse(RunStatus::COMPLETED->canBeResumed());
        $this->assertFalse(RunStatus::FAILED->canBeResumed());
        $this->assertFalse(RunStatus::CANCELLED->canBeResumed());
        $this->assertFalse(RunStatus::SUSPENDED->canBeResumed());
    }


    public function test_returns_correct_active_statuses_array(): void {
        $this->assertSame([RunStatus::PENDING, RunStatus::RUNNING], RunStatus::active());
    }


    public function test_returns_correct_blocked_statuses_array(): void {
        $this->assertSame([RunStatus::PAUSED, RunStatus::SUSPENDED], RunStatus::blocked());
    }


    public function test_returns_correct_non_terminal_statuses_array(): void {
        $this->assertSame([RunStatus::PENDING, RunStatus::RUNNING, RunStatus::PAUSED, RunStatus::SUSPENDED], RunStatus::nonTerminal());
    }


    // --- Step pause ---

    public function test_can_pause_a_pending_step(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::PENDING);

        $result = $step->pause('Step anomaly');

        $this->assertTrue($result);
        $step->refresh();
        $this->assertSame(RunStatus::PAUSED, $step->status);
        $this->assertSame('Step anomaly', $step->pause_reason);
        $this->assertNotNull($step->paused_at);
    }


    public function test_can_pause_a_running_step(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::RUNNING);

        $result = $step->pause();

        $this->assertTrue($result);
        $step->refresh();
        $this->assertSame(RunStatus::PAUSED, $step->status);
    }


    public function test_cannot_pause_a_completed_step(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::COMPLETED);

        $result = $step->pause();

        $this->assertFalse($result);
        $step->refresh();
        $this->assertSame(RunStatus::COMPLETED, $step->status);
    }


    public function test_suspends_subsequent_steps_when_step_is_paused(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step1 = $this->createStep($task, RunStatus::RUNNING, 1);
        $step2 = $this->createStep($task, RunStatus::PENDING, 2);
        $step3 = $this->createStep($task, RunStatus::PENDING, 3);

        $step1->pause('Needs review');

        $step2->refresh();
        $step3->refresh();
        $this->assertSame(RunStatus::SUSPENDED, $step2->status);
        $this->assertSame(RunStatus::SUSPENDED, $step3->status);
    }


    public function test_suspends_dependant_tasks_when_step_is_paused(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task1 = $this->createTask($workflow, RunStatus::RUNNING, 'task1');
        $step1 = $this->createStep($task1, RunStatus::RUNNING);
        $task2 = $this->createTask($workflow, RunStatus::PENDING, 'task2');
        $this->createStep($task2, RunStatus::PENDING);
        $this->linkDependency($task2, $task1);

        $step1->pause('Needs review');

        $task2->refresh();
        $this->assertSame(RunStatus::SUSPENDED, $task2->status);
    }


    public function test_does_not_cascade_up_to_pause_task_or_workflow(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::RUNNING);

        $step->pause('Needs review');

        $task->refresh();
        $workflow->refresh();
        $this->assertSame(RunStatus::RUNNING, $task->status);
        $this->assertSame(RunStatus::RUNNING, $workflow->status);
    }


    public function test_step_pause_with_null_reason_works_correctly(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::RUNNING);

        $step->pause();

        $step->refresh();
        $this->assertSame(RunStatus::PAUSED, $step->status);
        $this->assertNull($step->pause_reason);
        $this->assertNotNull($step->paused_at);
    }


    // --- Step resume ---

    public function test_can_resume_a_paused_step_and_marks_it_completed(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::PAUSED);
        $step->paused_at = now();
        $step->pause_reason = 'Test';
        $step->save();

        $result = $step->resume();

        $this->assertTrue($result);
        $step->refresh();
        $this->assertSame(RunStatus::COMPLETED, $step->status);
        $this->assertNotNull($step->completed_at);
        $this->assertNull($step->paused_at);
        $this->assertNull($step->pause_reason);
    }


    public function test_cannot_resume_a_pending_step(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::PENDING);

        $result = $step->resume();

        $this->assertFalse($result);
    }


    public function test_cannot_resume_a_completed_step(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::COMPLETED);
        $step = $this->createStep($task, RunStatus::COMPLETED);

        $result = $step->resume();

        $this->assertFalse($result);
    }


    public function test_unsuspends_subsequent_steps_when_step_is_resumed(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step1 = $this->createStep($task, RunStatus::PAUSED, 1);
        $step2 = $this->createStep($task, RunStatus::SUSPENDED, 2);
        $step3 = $this->createStep($task, RunStatus::SUSPENDED, 3);

        $step1->resume();

        $step2->refresh();
        $step3->refresh();
        $this->assertSame(RunStatus::PENDING, $step2->status);
        $this->assertSame(RunStatus::PENDING, $step3->status);
    }


    public function test_unsuspends_dependant_tasks_when_step_is_resumed(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task1 = $this->createTask($workflow, RunStatus::RUNNING, 'task1');
        $step1 = $this->createStep($task1, RunStatus::PAUSED);
        $task2 = $this->createTask($workflow, RunStatus::SUSPENDED, 'task2');
        $step2 = $this->createStep($task2, RunStatus::SUSPENDED);
        $this->linkDependency($task2, $task1);

        $step1->resume();

        $task2->refresh();
        $step2->refresh();
        $this->assertSame(RunStatus::PENDING, $task2->status);
        $this->assertSame(RunStatus::PENDING, $step2->status);
    }


    public function test_dispatches_next_step_when_step_is_resumed(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step1 = $this->createStep($task, RunStatus::PAUSED, 1);
        $step2 = $this->createStep($task, RunStatus::SUSPENDED, 2);

        $step1->resume();

        Queue::assertPushed(TestPausableJob::class);
    }


    // --- Step cancel ---

    public function test_can_cancel_a_pending_step(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::PENDING);

        $result = $step->cancel();

        $this->assertTrue($result);
        $step->refresh();
        $this->assertSame(RunStatus::CANCELLED, $step->status);
        $this->assertNotNull($step->failed_at);
    }


    public function test_can_cancel_a_paused_step(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::PAUSED);
        $step->paused_at = now();
        $step->pause_reason = 'Test';
        $step->save();

        $result = $step->cancel();

        $this->assertTrue($result);
        $step->refresh();
        $this->assertSame(RunStatus::CANCELLED, $step->status);
        $this->assertNull($step->paused_at);
    }


    public function test_cannot_cancel_a_completed_step(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::COMPLETED);

        $result = $step->cancel();

        $this->assertFalse($result);
    }


    public function test_cancels_subsequent_steps_in_the_task(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step1 = $this->createStep($task, RunStatus::COMPLETED, 1);
        $step2 = $this->createStep($task, RunStatus::RUNNING, 2);
        $step3 = $this->createStep($task, RunStatus::PENDING, 3);
        $step4 = $this->createStep($task, RunStatus::PENDING, 4);

        $step2->cancel();

        $step1->refresh();
        $step3->refresh();
        $step4->refresh();

        $this->assertSame(RunStatus::COMPLETED, $step1->status);
        $this->assertSame(RunStatus::CANCELLED, $step3->status);
        $this->assertSame(RunStatus::CANCELLED, $step4->status);
    }


    public function test_cancels_the_task_when_step_is_cancelled(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::RUNNING);

        $step->cancel();

        $task->refresh();
        $this->assertSame(RunStatus::CANCELLED, $task->status);
    }


    public function test_cancels_dependant_tasks_when_step_is_cancelled(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task1 = $this->createTask($workflow, RunStatus::RUNNING, 'task1');
        $step1 = $this->createStep($task1, RunStatus::RUNNING);
        $task2 = $this->createTask($workflow, RunStatus::PENDING, 'task2');
        $this->createStep($task2, RunStatus::PENDING);

        $this->linkDependency($task2, $task1);

        $step1->cancel();

        $task2->refresh();
        $this->assertSame(RunStatus::CANCELLED, $task2->status);
    }


    public function test_cancels_workflow_when_step_cancel_leaves_no_active_tasks(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::RUNNING);

        $step->cancel();

        $workflow->refresh();
        $this->assertSame(RunStatus::CANCELLED, $workflow->status);
    }


    // --- Complex scenarios ---

    public function test_handles_pause_resume_complete_cycle_with_downstream_suspension(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task1 = $this->createTask($workflow, RunStatus::RUNNING, 'task1');
        $step1 = $this->createStep($task1, RunStatus::RUNNING);
        $task2 = $this->createTask($workflow, RunStatus::PENDING, 'task2');
        $step2 = $this->createStep($task2, RunStatus::PENDING);
        $this->linkDependency($task2, $task1);

        // Pause step - downstream gets suspended, task/workflow stay running
        $step1->pause('Needs review');
        $this->assertSame(RunStatus::PAUSED, $step1->refresh()->status);
        $this->assertSame(RunStatus::SUSPENDED, $task2->refresh()->status);
        $this->assertSame(RunStatus::RUNNING, $workflow->refresh()->status);

        // Resume - step becomes COMPLETED, downstream unsuspended
        $step1->resume();
        $this->assertSame(RunStatus::COMPLETED, $step1->refresh()->status);
        $this->assertSame(RunStatus::PENDING, $task2->refresh()->status);
    }


    public function test_preserves_pause_reason_on_step_only_no_cascade_up(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step1 = $this->createStep($task, RunStatus::COMPLETED, 1);
        $step2 = $this->createStep($task, RunStatus::RUNNING, 2);

        $step2->pause('Data validation required');

        $this->assertSame('Data validation required', $step2->refresh()->pause_reason);
        $this->assertSame(RunStatus::RUNNING, $task->refresh()->status);
        $this->assertSame(RunStatus::RUNNING, $workflow->refresh()->status);
    }


    public function test_handles_cascading_cancellation_through_step_cancel(): void {
        $workflow = $this->createWorkflow(RunStatus::RUNNING);

        $task1 = $this->createTask($workflow, RunStatus::COMPLETED, 'task1');
        $this->createStep($task1, RunStatus::COMPLETED);

        $task2 = $this->createTask($workflow, RunStatus::RUNNING, 'task2');
        $step2 = $this->createStep($task2, RunStatus::RUNNING);
        $this->linkDependency($task2, $task1);

        $task3 = $this->createTask($workflow, RunStatus::PENDING, 'task3');
        $this->createStep($task3, RunStatus::PENDING);
        $this->linkDependency($task3, $task2);

        // Cancel step2 - should cascade to task3
        $step2->cancel();

        $this->assertSame(RunStatus::COMPLETED, $task1->refresh()->status);
        $this->assertSame(RunStatus::CANCELLED, $task2->refresh()->status);
        $this->assertSame(RunStatus::CANCELLED, $task3->refresh()->status);
        $this->assertSame(RunStatus::CANCELLED, $workflow->refresh()->status);
    }


    // --- Model attributes ---

    public function test_workflow_has_pause_attributes_defined(): void {
        $this->assertSame('paused_at', Workflow::ATTRIBUTE_PAUSED_AT);
        $this->assertSame('pause_reason', Workflow::ATTRIBUTE_PAUSE_REASON);
    }


    public function test_task_has_pause_attributes_defined(): void {
        $this->assertSame('paused_at', WorkflowTask::ATTRIBUTE_PAUSED_AT);
    }


    public function test_step_has_pause_attributes_defined(): void {
        $this->assertSame('paused_at', WorkflowTaskStep::ATTRIBUTE_PAUSED_AT);
        $this->assertSame('pause_reason', WorkflowTaskStep::ATTRIBUTE_PAUSE_REASON);
    }


    public function test_paused_at_is_cast_to_datetime(): void {
        $workflow = $this->createWorkflow(RunStatus::PAUSED);
        $workflow->paused_at = '2026-05-09 12:00:00';
        $workflow->save();

        $workflow->refresh();
        $this->assertInstanceOf(Carbon::class, $workflow->paused_at);
    }


    // --- Events ---

    public function test_dispatches_workflow_paused_event_when_step_is_paused(): void {
        Event::fake([WorkflowPaused::class]);

        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::RUNNING);

        $step->pause('Step paused');

        Event::assertDispatched(WorkflowPaused::class, function ($event) use ($workflow, $task, $step) {
            return $event->step->task->workflow->id === $workflow->id
                && $event->step->task->id === $task->id
                && $event->step->id === $step->id
                && $event->reason === 'Step paused';
        });
    }


    public function test_dispatches_workflow_resumed_event_when_step_is_resumed(): void {
        Event::fake([WorkflowResumed::class]);

        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::PAUSED);

        $step->resume();

        Event::assertDispatched(WorkflowResumed::class, function ($event) use ($workflow, $task, $step) {
            return $event->step->task->workflow->id === $workflow->id
                && $event->step->task->id === $task->id
                && $event->step->id === $step->id;
        });
    }


    public function test_dispatches_workflow_cancelled_event_when_step_is_cancelled(): void {
        Event::fake([WorkflowCancelled::class]);

        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::RUNNING);

        $step->cancel();

        Event::assertDispatched(WorkflowCancelled::class, function ($event) use ($workflow, $task, $step) {
            return $event->step->task->workflow->id === $workflow->id
                && $event->step->task->id === $task->id
                && $event->step->id === $step->id;
        });
    }


    public function test_does_not_dispatch_event_when_pause_fails(): void {
        Event::fake([WorkflowPaused::class]);

        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::COMPLETED);

        $step->pause();

        Event::assertNotDispatched(WorkflowPaused::class);
    }


    public function test_does_not_dispatch_event_when_resume_fails(): void {
        Event::fake([WorkflowResumed::class]);

        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::PENDING);

        $step->resume();

        Event::assertNotDispatched(WorkflowResumed::class);
    }


    public function test_does_not_dispatch_event_when_cancel_fails(): void {
        Event::fake([WorkflowCancelled::class]);

        $workflow = $this->createWorkflow(RunStatus::RUNNING);
        $task = $this->createTask($workflow, RunStatus::RUNNING);
        $step = $this->createStep($task, RunStatus::COMPLETED);

        $step->cancel();

        Event::assertNotDispatched(WorkflowCancelled::class);
    }


    private function createWorkflow(RunStatus $status = RunStatus::PENDING): Workflow {
        $workflow = new Workflow();
        $workflow->name = 'test-workflow';
        $workflow->status = $status;
        $workflow->save();
        return $workflow;
    }


    private function createTask(Workflow $workflow, RunStatus $status = RunStatus::PENDING, string $name = 'task'): WorkflowTask {
        $task = new WorkflowTask();
        $task->workflow_id = $workflow->id;
        $task->name = $name;
        $task->status = $status;
        $task->save();
        return $task;
    }


    private function createStep(WorkflowTask $task, RunStatus $status = RunStatus::PENDING, int $order = 1): WorkflowTaskStep {
        $step = new WorkflowTaskStep();
        $step->task_id = $task->id;
        $step->workflow_id = $task->workflow_id;
        $step->order = $order;
        $step->class = TestPausableJob::class;
        $step->status = $status;
        $step->payload = base64_encode(serialize(new TestPausableJob()));
        $step->save();
        return $step;
    }


    private function linkDependency(WorkflowTask $task, WorkflowTask $dependsOn): void {
        $task->dependencies()->attach($dependsOn->id);
    }
}
