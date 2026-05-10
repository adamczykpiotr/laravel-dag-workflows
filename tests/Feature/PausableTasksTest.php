<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Events\WorkflowCancelled;
use AdamczykPiotr\DagWorkflows\Events\WorkflowPaused;
use AdamczykPiotr\DagWorkflows\Events\WorkflowResumed;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
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

function createWorkflow(RunStatus $status = RunStatus::PENDING): Workflow {
    $workflow = new Workflow();
    $workflow->name = 'test-workflow';
    $workflow->status = $status;
    $workflow->save();
    return $workflow;
}

function createTask(Workflow $workflow, RunStatus $status = RunStatus::PENDING, string $name = 'task'): WorkflowTask {
    $task = new WorkflowTask();
    $task->workflow_id = $workflow->id;
    $task->name = $name;
    $task->status = $status;
    $task->save();
    return $task;
}

function createStep(WorkflowTask $task, RunStatus $status = RunStatus::PENDING, int $order = 1): WorkflowTaskStep {
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

function linkDependency(WorkflowTask $task, WorkflowTask $dependsOn): void {
    $task->dependencies()->attach($dependsOn->id);
}

beforeEach(function() {
    Carbon::setTestNow('2026-05-09 12:00:00');
    Queue::fake();
});

afterEach(function() {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| RunStatus Enum Tests
|--------------------------------------------------------------------------
*/

describe('RunStatus enum', function() {
    it('PAUSED is not terminal', function() {
        expect(RunStatus::PAUSED->isTerminal())->toBeFalse();
    });

    it('SUSPENDED is not terminal', function() {
        expect(RunStatus::SUSPENDED->isTerminal())->toBeFalse();
    });

    it('PAUSED and SUSPENDED return true for isBlocked', function() {
        expect(RunStatus::PAUSED->isBlocked())->toBeTrue();
        expect(RunStatus::SUSPENDED->isBlocked())->toBeTrue();
    });

    it('PENDING and RUNNING return true for isActive', function() {
        expect(RunStatus::PENDING->isActive())->toBeTrue();
        expect(RunStatus::RUNNING->isActive())->toBeTrue();
    });

    it('other statuses return false for isActive', function() {
        expect(RunStatus::PAUSED->isActive())->toBeFalse();
        expect(RunStatus::SUSPENDED->isActive())->toBeFalse();
        expect(RunStatus::COMPLETED->isActive())->toBeFalse();
        expect(RunStatus::FAILED->isActive())->toBeFalse();
        expect(RunStatus::CANCELLED->isActive())->toBeFalse();
    });

    it('PENDING and RUNNING can be paused', function() {
        expect(RunStatus::PENDING->canBePaused())->toBeTrue();
        expect(RunStatus::RUNNING->canBePaused())->toBeTrue();
    });

    it('terminal statuses cannot be paused', function() {
        expect(RunStatus::COMPLETED->canBePaused())->toBeFalse();
        expect(RunStatus::FAILED->canBePaused())->toBeFalse();
        expect(RunStatus::CANCELLED->canBePaused())->toBeFalse();
    });

    it('PAUSED and SUSPENDED cannot be paused again', function() {
        expect(RunStatus::PAUSED->canBePaused())->toBeFalse();
        expect(RunStatus::SUSPENDED->canBePaused())->toBeFalse();
    });

    it('only PAUSED can be resumed', function() {
        expect(RunStatus::PAUSED->canBeResumed())->toBeTrue();
        expect(RunStatus::PENDING->canBeResumed())->toBeFalse();
        expect(RunStatus::RUNNING->canBeResumed())->toBeFalse();
        expect(RunStatus::COMPLETED->canBeResumed())->toBeFalse();
        expect(RunStatus::FAILED->canBeResumed())->toBeFalse();
        expect(RunStatus::CANCELLED->canBeResumed())->toBeFalse();
        expect(RunStatus::SUSPENDED->canBeResumed())->toBeFalse();
    });

    it('returns correct active statuses array', function() {
        expect(RunStatus::active())->toBe([RunStatus::PENDING, RunStatus::RUNNING]);
    });

    it('returns correct blocked statuses array', function() {
        expect(RunStatus::blocked())->toBe([RunStatus::PAUSED, RunStatus::SUSPENDED]);
    });

    it('returns correct non-terminal statuses array', function() {
        expect(RunStatus::nonTerminal())->toBe([RunStatus::PENDING, RunStatus::RUNNING, RunStatus::PAUSED, RunStatus::SUSPENDED]);
    });
});

/*
|--------------------------------------------------------------------------
| Step Pause Tests
|--------------------------------------------------------------------------
*/

describe('Step pause', function() {
    it('can pause a pending step', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::PENDING);

        $result = $step->pause('Step anomaly');

        expect($result)->toBeTrue();
        $step->refresh();
        expect($step->status)->toBe(RunStatus::PAUSED);
        expect($step->pause_reason)->toBe('Step anomaly');
        expect($step->paused_at)->not->toBeNull();
    });

    it('can pause a running step', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::RUNNING);

        $result = $step->pause();

        expect($result)->toBeTrue();
        $step->refresh();
        expect($step->status)->toBe(RunStatus::PAUSED);
    });

    it('cannot pause a completed step', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::COMPLETED);

        $result = $step->pause();

        expect($result)->toBeFalse();
        $step->refresh();
        expect($step->status)->toBe(RunStatus::COMPLETED);
    });

    it('suspends subsequent steps when step is paused', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step1 = createStep($task, RunStatus::RUNNING, 1);
        $step2 = createStep($task, RunStatus::PENDING, 2);
        $step3 = createStep($task, RunStatus::PENDING, 3);

        $step1->pause('Needs review');

        $step2->refresh();
        $step3->refresh();
        expect($step2->status)->toBe(RunStatus::SUSPENDED);
        expect($step3->status)->toBe(RunStatus::SUSPENDED);
    });

    it('suspends dependant tasks when step is paused', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task1 = createTask($workflow, RunStatus::RUNNING, 'task1');
        $step1 = createStep($task1, RunStatus::RUNNING);
        $task2 = createTask($workflow, RunStatus::PENDING, 'task2');
        createStep($task2, RunStatus::PENDING);
        linkDependency($task2, $task1);

        $step1->pause('Needs review');

        $task2->refresh();
        expect($task2->status)->toBe(RunStatus::SUSPENDED);
    });

    it('does not cascade up to pause task or workflow', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::RUNNING);

        $step->pause('Needs review');

        $task->refresh();
        $workflow->refresh();
        expect($task->status)->toBe(RunStatus::RUNNING);
        expect($workflow->status)->toBe(RunStatus::RUNNING);
    });

    it('step pause with null reason works correctly', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::RUNNING);

        $step->pause();

        $step->refresh();
        expect($step->status)->toBe(RunStatus::PAUSED);
        expect($step->pause_reason)->toBeNull();
        expect($step->paused_at)->not->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| Step Resume Tests
|--------------------------------------------------------------------------
*/

describe('Step resume', function() {
    it('can resume a paused step and marks it completed', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::PAUSED);
        $step->paused_at = now();
        $step->pause_reason = 'Test';
        $step->save();

        $result = $step->resume();

        expect($result)->toBeTrue();
        $step->refresh();
        expect($step->status)->toBe(RunStatus::COMPLETED);
        expect($step->completed_at)->not->toBeNull();
        expect($step->paused_at)->toBeNull();
        expect($step->pause_reason)->toBeNull();
    });

    it('cannot resume a pending step', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::PENDING);

        $result = $step->resume();

        expect($result)->toBeFalse();
    });

    it('cannot resume a completed step', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::COMPLETED);
        $step = createStep($task, RunStatus::COMPLETED);

        $result = $step->resume();

        expect($result)->toBeFalse();
    });

    it('unsuspends subsequent steps when step is resumed', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step1 = createStep($task, RunStatus::PAUSED, 1);
        $step2 = createStep($task, RunStatus::SUSPENDED, 2);
        $step3 = createStep($task, RunStatus::SUSPENDED, 3);

        $step1->resume();

        $step2->refresh();
        $step3->refresh();
        expect($step2->status)->toBe(RunStatus::PENDING);
        expect($step3->status)->toBe(RunStatus::PENDING);
    });

    it('unsuspends dependant tasks when step is resumed', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task1 = createTask($workflow, RunStatus::RUNNING, 'task1');
        $step1 = createStep($task1, RunStatus::PAUSED);
        $task2 = createTask($workflow, RunStatus::SUSPENDED, 'task2');
        $step2 = createStep($task2, RunStatus::SUSPENDED);
        linkDependency($task2, $task1);

        $step1->resume();

        $task2->refresh();
        $step2->refresh();
        expect($task2->status)->toBe(RunStatus::PENDING);
        expect($step2->status)->toBe(RunStatus::PENDING);
    });

    it('dispatches next step when step is resumed', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step1 = createStep($task, RunStatus::PAUSED, 1);
        $step2 = createStep($task, RunStatus::SUSPENDED, 2);

        $step1->resume();

        Queue::assertPushed(TestPausableJob::class);
    });
});

/*
|--------------------------------------------------------------------------
| Step Cancel Tests
|--------------------------------------------------------------------------
*/

describe('Step cancel', function() {
    it('can cancel a pending step', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::PENDING);

        $result = $step->cancel();

        expect($result)->toBeTrue();
        $step->refresh();
        expect($step->status)->toBe(RunStatus::CANCELLED);
        expect($step->failed_at)->not->toBeNull();
    });

    it('can cancel a paused step', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::PAUSED);
        $step->paused_at = now();
        $step->pause_reason = 'Test';
        $step->save();

        $result = $step->cancel();

        expect($result)->toBeTrue();
        $step->refresh();
        expect($step->status)->toBe(RunStatus::CANCELLED);
        expect($step->paused_at)->toBeNull();
    });

    it('cannot cancel a completed step', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::COMPLETED);

        $result = $step->cancel();

        expect($result)->toBeFalse();
    });

    it('cancels subsequent steps in the task', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step1 = createStep($task, RunStatus::COMPLETED, 1);
        $step2 = createStep($task, RunStatus::RUNNING, 2);
        $step3 = createStep($task, RunStatus::PENDING, 3);
        $step4 = createStep($task, RunStatus::PENDING, 4);

        $step2->cancel();

        $step1->refresh();
        $step3->refresh();
        $step4->refresh();

        expect($step1->status)->toBe(RunStatus::COMPLETED);
        expect($step3->status)->toBe(RunStatus::CANCELLED);
        expect($step4->status)->toBe(RunStatus::CANCELLED);
    });

    it('cancels the task when step is cancelled', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::RUNNING);

        $step->cancel();

        $task->refresh();
        expect($task->status)->toBe(RunStatus::CANCELLED);
    });

    it('cancels dependant tasks when step is cancelled', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task1 = createTask($workflow, RunStatus::RUNNING, 'task1');
        $step1 = createStep($task1, RunStatus::RUNNING);
        $task2 = createTask($workflow, RunStatus::PENDING, 'task2');
        createStep($task2, RunStatus::PENDING);

        linkDependency($task2, $task1);

        $step1->cancel();

        $task2->refresh();
        expect($task2->status)->toBe(RunStatus::CANCELLED);
    });

    it('cancels workflow when step cancel leaves no active tasks', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::RUNNING);

        $step->cancel();

        $workflow->refresh();
        expect($workflow->status)->toBe(RunStatus::CANCELLED);
    });
});

/*
|--------------------------------------------------------------------------
| Complex Scenario Tests
|--------------------------------------------------------------------------
*/

describe('Complex scenarios', function() {
    it('handles pause-resume-complete cycle with downstream suspension', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task1 = createTask($workflow, RunStatus::RUNNING, 'task1');
        $step1 = createStep($task1, RunStatus::RUNNING);
        $task2 = createTask($workflow, RunStatus::PENDING, 'task2');
        $step2 = createStep($task2, RunStatus::PENDING);
        linkDependency($task2, $task1);

        // Pause step - downstream gets suspended, task/workflow stay running
        $step1->pause('Needs review');
        expect($step1->refresh()->status)->toBe(RunStatus::PAUSED);
        expect($task2->refresh()->status)->toBe(RunStatus::SUSPENDED);
        expect($workflow->refresh()->status)->toBe(RunStatus::RUNNING);

        // Resume - step becomes COMPLETED, downstream unsuspended
        $step1->resume();
        expect($step1->refresh()->status)->toBe(RunStatus::COMPLETED);
        expect($task2->refresh()->status)->toBe(RunStatus::PENDING);
    });

    it('preserves pause reason on step only (no cascade up)', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step1 = createStep($task, RunStatus::COMPLETED, 1);
        $step2 = createStep($task, RunStatus::RUNNING, 2);

        $step2->pause('Data validation required');

        expect($step2->refresh()->pause_reason)->toBe('Data validation required');
        expect($task->refresh()->status)->toBe(RunStatus::RUNNING);
        expect($workflow->refresh()->status)->toBe(RunStatus::RUNNING);
    });

    it('handles cascading cancellation through step cancel', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);

        $task1 = createTask($workflow, RunStatus::COMPLETED, 'task1');
        createStep($task1, RunStatus::COMPLETED);

        $task2 = createTask($workflow, RunStatus::RUNNING, 'task2');
        $step2 = createStep($task2, RunStatus::RUNNING);
        linkDependency($task2, $task1);

        $task3 = createTask($workflow, RunStatus::PENDING, 'task3');
        createStep($task3, RunStatus::PENDING);
        linkDependency($task3, $task2);

        // Cancel step2 - should cascade to task3
        $step2->cancel();

        expect($task1->refresh()->status)->toBe(RunStatus::COMPLETED);
        expect($task2->refresh()->status)->toBe(RunStatus::CANCELLED);
        expect($task3->refresh()->status)->toBe(RunStatus::CANCELLED);
        expect($workflow->refresh()->status)->toBe(RunStatus::CANCELLED);
    });
});

/*
|--------------------------------------------------------------------------
| Model Attribute Tests
|--------------------------------------------------------------------------
*/

describe('Model attributes', function() {
    it('workflow has pause attributes defined', function() {
        expect(Workflow::ATTRIBUTE_PAUSED_AT)->toBe('paused_at');
        expect(Workflow::ATTRIBUTE_PAUSE_REASON)->toBe('pause_reason');
    });

    it('task has pause attributes defined', function() {
        expect(WorkflowTask::ATTRIBUTE_PAUSED_AT)->toBe('paused_at');
    });

    it('step has pause attributes defined', function() {
        expect(WorkflowTaskStep::ATTRIBUTE_PAUSED_AT)->toBe('paused_at');
        expect(WorkflowTaskStep::ATTRIBUTE_PAUSE_REASON)->toBe('pause_reason');
    });

    it('paused_at is cast to datetime', function() {
        $workflow = createWorkflow(RunStatus::PAUSED);
        $workflow->paused_at = '2026-05-09 12:00:00';
        $workflow->save();

        $workflow->refresh();
        expect($workflow->paused_at)->toBeInstanceOf(Carbon::class);
    });
});

/*
|--------------------------------------------------------------------------
| Event Tests
|--------------------------------------------------------------------------
*/

describe('Events', function() {
    it('dispatches WorkflowPaused event when step is paused', function() {
        Event::fake([WorkflowPaused::class]);

        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::RUNNING);

        $step->pause('Step paused');

        Event::assertDispatched(WorkflowPaused::class, function ($event) use ($workflow, $task, $step) {
            return $event->step->task->workflow->id === $workflow->id
                && $event->step->task->id === $task->id
                && $event->step->id === $step->id
                && $event->reason === 'Step paused';
        });
    });

    it('dispatches WorkflowResumed event when step is resumed', function() {
        Event::fake([WorkflowResumed::class]);

        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::PAUSED);

        $step->resume();

        Event::assertDispatched(WorkflowResumed::class, function ($event) use ($workflow, $task, $step) {
            return $event->step->task->workflow->id === $workflow->id
                && $event->step->task->id === $task->id
                && $event->step->id === $step->id;
        });
    });

    it('dispatches WorkflowCancelled event when step is cancelled', function() {
        Event::fake([WorkflowCancelled::class]);

        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::RUNNING);

        $step->cancel();

        Event::assertDispatched(WorkflowCancelled::class, function ($event) use ($workflow, $task, $step) {
            return $event->step->task->workflow->id === $workflow->id
                && $event->step->task->id === $task->id
                && $event->step->id === $step->id;
        });
    });

    it('does not dispatch event when pause fails', function() {
        Event::fake([WorkflowPaused::class]);

        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::COMPLETED);

        $step->pause();

        Event::assertNotDispatched(WorkflowPaused::class);
    });

    it('does not dispatch event when resume fails', function() {
        Event::fake([WorkflowResumed::class]);

        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::PENDING);

        $step->resume();

        Event::assertNotDispatched(WorkflowResumed::class);
    });

    it('does not dispatch event when cancel fails', function() {
        Event::fake([WorkflowCancelled::class]);

        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step = createStep($task, RunStatus::COMPLETED);

        $step->cancel();

        Event::assertNotDispatched(WorkflowCancelled::class);
    });
});
