<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Events\WorkflowCancelled;
use AdamczykPiotr\DagWorkflows\Events\WorkflowPaused;
use AdamczykPiotr\DagWorkflows\Events\WorkflowResumed;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Services\WorkflowDispatcher;
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

    it('PAUSED returns true for isPaused', function() {
        expect(RunStatus::PAUSED->isPaused())->toBeTrue();
    });

    it('SUSPENDED returns true for isSuspended', function() {
        expect(RunStatus::SUSPENDED->isSuspended())->toBeTrue();
    });

    it('PAUSED and SUSPENDED return true for isBlocked', function() {
        expect(RunStatus::PAUSED->isBlocked())->toBeTrue();
        expect(RunStatus::SUSPENDED->isBlocked())->toBeTrue();
    });

    it('other statuses return false for isPaused', function() {
        expect(RunStatus::PENDING->isPaused())->toBeFalse();
        expect(RunStatus::RUNNING->isPaused())->toBeFalse();
        expect(RunStatus::COMPLETED->isPaused())->toBeFalse();
        expect(RunStatus::FAILED->isPaused())->toBeFalse();
        expect(RunStatus::CANCELLED->isPaused())->toBeFalse();
        expect(RunStatus::SUSPENDED->isPaused())->toBeFalse();
    });

    it('other statuses return false for isSuspended', function() {
        expect(RunStatus::PENDING->isSuspended())->toBeFalse();
        expect(RunStatus::RUNNING->isSuspended())->toBeFalse();
        expect(RunStatus::PAUSED->isSuspended())->toBeFalse();
        expect(RunStatus::COMPLETED->isSuspended())->toBeFalse();
        expect(RunStatus::FAILED->isSuspended())->toBeFalse();
        expect(RunStatus::CANCELLED->isSuspended())->toBeFalse();
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
});

/*
|--------------------------------------------------------------------------
| Workflow Pause Tests
|--------------------------------------------------------------------------
*/

describe('Workflow pause', function() {
    it('can pause a pending workflow', function() {
        $workflow = createWorkflow(RunStatus::PENDING);

        $result = $workflow->pause('Anomaly detected');

        expect($result)->toBeTrue();
        $workflow->refresh();
        expect($workflow->status)->toBe(RunStatus::PAUSED);
        expect($workflow->paused_at)->not->toBeNull();
        expect($workflow->pause_reason)->toBe('Anomaly detected');
    });

    it('can pause a running workflow', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $workflow->started_at = now();
        $workflow->save();

        $result = $workflow->pause('Manual intervention required');

        expect($result)->toBeTrue();
        $workflow->refresh();
        expect($workflow->status)->toBe(RunStatus::PAUSED);
        expect($workflow->pause_reason)->toBe('Manual intervention required');
    });

    it('cannot pause a completed workflow', function() {
        $workflow = createWorkflow(RunStatus::COMPLETED);
        $workflow->completed_at = now();
        $workflow->save();

        $result = $workflow->pause();

        expect($result)->toBeFalse();
        $workflow->refresh();
        expect($workflow->status)->toBe(RunStatus::COMPLETED);
    });

    it('cannot pause a failed workflow', function() {
        $workflow = createWorkflow(RunStatus::FAILED);
        $workflow->failed_at = now();
        $workflow->save();

        $result = $workflow->pause();

        expect($result)->toBeFalse();
        $workflow->refresh();
        expect($workflow->status)->toBe(RunStatus::FAILED);
    });

    it('cannot pause an already paused workflow', function() {
        $workflow = createWorkflow(RunStatus::PAUSED);
        $workflow->paused_at = now()->subMinute();
        $workflow->pause_reason = 'Original reason';
        $workflow->save();

        $result = $workflow->pause('New reason');

        expect($result)->toBeFalse();
        $workflow->refresh();
        expect($workflow->pause_reason)->toBe('Original reason');
    });

    it('pauses all pending tasks when workflow is paused', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task1 = createTask($workflow, RunStatus::PENDING, 'task1');
        $task2 = createTask($workflow, RunStatus::RUNNING, 'task2');
        $task3 = createTask($workflow, RunStatus::COMPLETED, 'task3');

        $workflow->pause('Test pause');

        $task1->refresh();
        $task2->refresh();
        $task3->refresh();

        expect($task1->status)->toBe(RunStatus::PAUSED);
        expect($task2->status)->toBe(RunStatus::PAUSED);
        expect($task3->status)->toBe(RunStatus::COMPLETED);
    });

    it('pauses all pending and running steps when workflow is paused', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $step1 = createStep($task, RunStatus::COMPLETED, 1);
        $step2 = createStep($task, RunStatus::RUNNING, 2);
        $step3 = createStep($task, RunStatus::PENDING, 3);

        $workflow->pause('Pause all');

        $step1->refresh();
        $step2->refresh();
        $step3->refresh();

        expect($step1->status)->toBe(RunStatus::COMPLETED);
        expect($step2->status)->toBe(RunStatus::PAUSED);
        expect($step2->pause_reason)->toBe('Pause all');
        expect($step3->status)->toBe(RunStatus::PAUSED);
        expect($step3->pause_reason)->toBe('Pause all');
    });

    it('workflow pause can have null reason', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);

        $workflow->pause();

        $workflow->refresh();
        expect($workflow->status)->toBe(RunStatus::PAUSED);
        expect($workflow->pause_reason)->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| Workflow Resume Tests
|--------------------------------------------------------------------------
*/

describe('Workflow resume', function() {
    it('can resume a paused workflow', function() {
        $workflow = createWorkflow(RunStatus::PAUSED);
        $workflow->paused_at = now()->subMinute();
        $workflow->pause_reason = 'Test reason';
        $workflow->save();

        $task = createTask($workflow, RunStatus::PAUSED);
        $step = createStep($task, RunStatus::PAUSED);

        $result = $workflow->resume();

        expect($result)->toBeTrue();
        $workflow->refresh();
        expect($workflow->status)->toBe(RunStatus::RUNNING);
        expect($workflow->paused_at)->toBeNull();
        expect($workflow->pause_reason)->toBeNull();
    });

    it('cannot resume a pending workflow', function() {
        $workflow = createWorkflow(RunStatus::PENDING);

        $result = $workflow->resume();

        expect($result)->toBeFalse();
        $workflow->refresh();
        expect($workflow->status)->toBe(RunStatus::PENDING);
    });

    it('cannot resume a running workflow', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);

        $result = $workflow->resume();

        expect($result)->toBeFalse();
    });

    it('cannot resume a completed workflow', function() {
        $workflow = createWorkflow(RunStatus::COMPLETED);

        $result = $workflow->resume();

        expect($result)->toBeFalse();
    });

    it('resumes paused tasks to pending state', function() {
        $workflow = createWorkflow(RunStatus::PAUSED);
        $task = createTask($workflow, RunStatus::PAUSED);
        $task->paused_at = now();
        $task->save();

        $step = createStep($task, RunStatus::PAUSED);

        $workflow->resume();

        $task->refresh();
        expect($task->status)->toBe(RunStatus::PENDING);
        expect($task->paused_at)->toBeNull();
    });

    it('resumes paused steps to pending state', function() {
        $workflow = createWorkflow(RunStatus::PAUSED);
        $task = createTask($workflow, RunStatus::PAUSED);
        $step = createStep($task, RunStatus::PAUSED);
        $step->paused_at = now();
        $step->pause_reason = 'Paused step';
        $step->save();

        $workflow->resume();

        $step->refresh();
        expect($step->status)->toBe(RunStatus::PENDING);
        expect($step->paused_at)->toBeNull();
        expect($step->pause_reason)->toBeNull();
    });

    it('does not affect completed tasks when resuming', function() {
        $workflow = createWorkflow(RunStatus::PAUSED);
        $completedTask = createTask($workflow, RunStatus::COMPLETED, 'completed');
        $pausedTask = createTask($workflow, RunStatus::PAUSED, 'paused');
        $step = createStep($pausedTask, RunStatus::PAUSED);

        $workflow->resume();

        $completedTask->refresh();
        $pausedTask->refresh();

        expect($completedTask->status)->toBe(RunStatus::COMPLETED);
        expect($pausedTask->status)->toBe(RunStatus::PENDING);
    });
});

/*
|--------------------------------------------------------------------------
| Workflow Cancel Tests
|--------------------------------------------------------------------------
*/

describe('Workflow cancel', function() {
    it('can cancel a pending workflow', function() {
        $workflow = createWorkflow(RunStatus::PENDING);

        $result = $workflow->cancel();

        expect($result)->toBeTrue();
        $workflow->refresh();
        expect($workflow->status)->toBe(RunStatus::CANCELLED);
        expect($workflow->failed_at)->not->toBeNull();
    });

    it('can cancel a running workflow', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);

        $result = $workflow->cancel();

        expect($result)->toBeTrue();
        $workflow->refresh();
        expect($workflow->status)->toBe(RunStatus::CANCELLED);
    });

    it('can cancel a paused workflow', function() {
        $workflow = createWorkflow(RunStatus::PAUSED);
        $workflow->paused_at = now();
        $workflow->pause_reason = 'Test';
        $workflow->save();

        $result = $workflow->cancel();

        expect($result)->toBeTrue();
        $workflow->refresh();
        expect($workflow->status)->toBe(RunStatus::CANCELLED);
        expect($workflow->paused_at)->toBeNull();
        expect($workflow->pause_reason)->toBeNull();
    });

    it('cannot cancel a completed workflow', function() {
        $workflow = createWorkflow(RunStatus::COMPLETED);

        $result = $workflow->cancel();

        expect($result)->toBeFalse();
        $workflow->refresh();
        expect($workflow->status)->toBe(RunStatus::COMPLETED);
    });

    it('cancels all non-terminal tasks', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $pendingTask = createTask($workflow, RunStatus::PENDING, 'pending');
        $runningTask = createTask($workflow, RunStatus::RUNNING, 'running');
        $pausedTask = createTask($workflow, RunStatus::PAUSED, 'paused');
        $completedTask = createTask($workflow, RunStatus::COMPLETED, 'completed');

        $workflow->cancel();

        $pendingTask->refresh();
        $runningTask->refresh();
        $pausedTask->refresh();
        $completedTask->refresh();

        expect($pendingTask->status)->toBe(RunStatus::CANCELLED);
        expect($runningTask->status)->toBe(RunStatus::CANCELLED);
        expect($pausedTask->status)->toBe(RunStatus::CANCELLED);
        expect($completedTask->status)->toBe(RunStatus::COMPLETED);
    });

    it('cancels all non-terminal steps', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $completedStep = createStep($task, RunStatus::COMPLETED, 1);
        $runningStep = createStep($task, RunStatus::RUNNING, 2);
        $pausedStep = createStep($task, RunStatus::PAUSED, 3);
        $pendingStep = createStep($task, RunStatus::PENDING, 4);

        $workflow->cancel();

        $completedStep->refresh();
        $runningStep->refresh();
        $pausedStep->refresh();
        $pendingStep->refresh();

        expect($completedStep->status)->toBe(RunStatus::COMPLETED);
        expect($runningStep->status)->toBe(RunStatus::CANCELLED);
        expect($pausedStep->status)->toBe(RunStatus::CANCELLED);
        expect($pendingStep->status)->toBe(RunStatus::CANCELLED);
    });
});

/*
|--------------------------------------------------------------------------
| Task Pause Tests
|--------------------------------------------------------------------------
*/

describe('Task pause', function() {
    it('can pause a pending task', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::PENDING);

        $result = $task->pause('Task anomaly');

        expect($result)->toBeTrue();
        $task->refresh();
        expect($task->status)->toBe(RunStatus::PAUSED);
        expect($task->paused_at)->not->toBeNull();
    });

    it('can pause a running task', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);

        $result = $task->pause();

        expect($result)->toBeTrue();
        $task->refresh();
        expect($task->status)->toBe(RunStatus::PAUSED);
    });

    it('cannot pause a completed task', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::COMPLETED);

        $result = $task->pause();

        expect($result)->toBeFalse();
        $task->refresh();
        expect($task->status)->toBe(RunStatus::COMPLETED);
    });

    it('pauses task steps when task is paused', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $runningStep = createStep($task, RunStatus::RUNNING, 1);
        $pendingStep = createStep($task, RunStatus::PENDING, 2);

        $task->pause('Pause task');

        $runningStep->refresh();
        $pendingStep->refresh();

        expect($runningStep->status)->toBe(RunStatus::PAUSED);
        expect($runningStep->pause_reason)->toBe('Pause task');
        expect($pendingStep->status)->toBe(RunStatus::PAUSED);
    });

    it('suspends dependant tasks when task is paused', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task1 = createTask($workflow, RunStatus::RUNNING, 'task1');
        createStep($task1, RunStatus::RUNNING);
        $task2 = createTask($workflow, RunStatus::PENDING, 'task2');
        createStep($task2, RunStatus::PENDING);
        linkDependency($task2, $task1);

        $task1->pause('Review task');

        $task2->refresh();
        expect($task2->status)->toBe(RunStatus::SUSPENDED);
    });

    it('does not cascade up to pause workflow', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING, 'task');
        createStep($task, RunStatus::RUNNING);

        $task->pause();

        $workflow->refresh();
        expect($workflow->status)->toBe(RunStatus::RUNNING);
    });
});

/*
|--------------------------------------------------------------------------
| Task Resume Tests
|--------------------------------------------------------------------------
*/

describe('Task resume', function() {
    it('can resume a paused task', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::PAUSED);
        $task->paused_at = now();
        $task->save();
        $step = createStep($task, RunStatus::PAUSED);

        $result = $task->resume();

        expect($result)->toBeTrue();
        $task->refresh();
        expect($task->status)->toBe(RunStatus::PENDING);
        expect($task->paused_at)->toBeNull();
    });

    it('cannot resume a running task', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);

        $result = $task->resume();

        expect($result)->toBeFalse();
    });

    it('resumes paused and suspended steps to pending state', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::PAUSED);
        $pausedStep = createStep($task, RunStatus::PAUSED, 1);
        $suspendedStep = createStep($task, RunStatus::SUSPENDED, 2);

        $task->resume();

        $pausedStep->refresh();
        $suspendedStep->refresh();
        expect($pausedStep->status)->toBe(RunStatus::PENDING);
        expect($suspendedStep->status)->toBe(RunStatus::PENDING);
    });

    it('unsuspends dependant tasks when task is resumed', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task1 = createTask($workflow, RunStatus::PAUSED, 'task1');
        createStep($task1, RunStatus::PAUSED);
        $task2 = createTask($workflow, RunStatus::SUSPENDED, 'task2');
        $step2 = createStep($task2, RunStatus::SUSPENDED);
        linkDependency($task2, $task1);

        $task1->resume();

        $task2->refresh();
        $step2->refresh();
        expect($task2->status)->toBe(RunStatus::PENDING);
        expect($step2->status)->toBe(RunStatus::PENDING);
    });
});

/*
|--------------------------------------------------------------------------
| Task Cancel Tests
|--------------------------------------------------------------------------
*/

describe('Task cancel', function() {
    it('can cancel a pending task', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::PENDING);

        $result = $task->cancel();

        expect($result)->toBeTrue();
        $task->refresh();
        expect($task->status)->toBe(RunStatus::CANCELLED);
        expect($task->failed_at)->not->toBeNull();
    });

    it('can cancel a paused task', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::PAUSED);
        $task->paused_at = now();
        $task->save();

        $result = $task->cancel();

        expect($result)->toBeTrue();
        $task->refresh();
        expect($task->status)->toBe(RunStatus::CANCELLED);
        expect($task->paused_at)->toBeNull();
    });

    it('cannot cancel a completed task', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::COMPLETED);

        $result = $task->cancel();

        expect($result)->toBeFalse();
    });

    it('cancels all non-terminal steps of the task', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        $completedStep = createStep($task, RunStatus::COMPLETED, 1);
        $runningStep = createStep($task, RunStatus::RUNNING, 2);
        $pausedStep = createStep($task, RunStatus::PAUSED, 3);

        $task->cancel();

        $completedStep->refresh();
        $runningStep->refresh();
        $pausedStep->refresh();

        expect($completedStep->status)->toBe(RunStatus::COMPLETED);
        expect($runningStep->status)->toBe(RunStatus::CANCELLED);
        expect($pausedStep->status)->toBe(RunStatus::CANCELLED);
    });

    it('cancels dependant tasks recursively', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task1 = createTask($workflow, RunStatus::RUNNING, 'task1');
        createStep($task1, RunStatus::RUNNING);
        $task2 = createTask($workflow, RunStatus::PENDING, 'task2');
        createStep($task2, RunStatus::PENDING);
        $task3 = createTask($workflow, RunStatus::PENDING, 'task3');
        createStep($task3, RunStatus::PENDING);

        linkDependency($task2, $task1);
        linkDependency($task3, $task2);

        $task1->cancel();

        $task2->refresh();
        $task3->refresh();

        expect($task2->status)->toBe(RunStatus::CANCELLED);
        expect($task3->status)->toBe(RunStatus::CANCELLED);
    });

    it('cancels workflow when last task is cancelled and not all completed', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        createStep($task, RunStatus::RUNNING);

        $task->cancel();

        $workflow->refresh();
        expect($workflow->status)->toBe(RunStatus::CANCELLED);
    });

    it('completes workflow when cancelled task leaves only completed tasks', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $completedTask = createTask($workflow, RunStatus::COMPLETED, 'completed');
        $runningTask = createTask($workflow, RunStatus::RUNNING, 'running');
        createStep($runningTask, RunStatus::RUNNING);

        $runningTask->cancel();

        $workflow->refresh();
        expect($workflow->status)->toBe(RunStatus::CANCELLED);
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

    it('handles partial pause with multiple parallel tasks - workflow stays running', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task1 = createTask($workflow, RunStatus::RUNNING, 'task1');
        $step1 = createStep($task1, RunStatus::RUNNING);
        $task2 = createTask($workflow, RunStatus::RUNNING, 'task2');
        $step2 = createStep($task2, RunStatus::RUNNING);

        // Pause only task1 - workflow stays running because task2 is still active
        $task1->pause('Review task1');

        expect($task1->refresh()->status)->toBe(RunStatus::PAUSED);
        expect($task2->refresh()->status)->toBe(RunStatus::RUNNING);
        expect($workflow->refresh()->status)->toBe(RunStatus::RUNNING);

        // Pause task2 - workflow still stays running (tasks don't cascade up)
        $task2->pause('Review task2');

        expect($task2->refresh()->status)->toBe(RunStatus::PAUSED);
        expect($workflow->refresh()->status)->toBe(RunStatus::RUNNING);
    });

    it('handles cascading cancellation through dependencies', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);

        $task1 = createTask($workflow, RunStatus::COMPLETED, 'task1');
        createStep($task1, RunStatus::COMPLETED);

        $task2 = createTask($workflow, RunStatus::RUNNING, 'task2');
        createStep($task2, RunStatus::RUNNING);
        linkDependency($task2, $task1);

        $task3 = createTask($workflow, RunStatus::PENDING, 'task3');
        createStep($task3, RunStatus::PENDING);
        linkDependency($task3, $task2);

        $task4 = createTask($workflow, RunStatus::PENDING, 'task4');
        createStep($task4, RunStatus::PENDING);
        linkDependency($task4, $task3);

        // Cancel task2 - should cascade to task3 and task4
        $task2->cancel();

        expect($task1->refresh()->status)->toBe(RunStatus::COMPLETED);
        expect($task2->refresh()->status)->toBe(RunStatus::CANCELLED);
        expect($task3->refresh()->status)->toBe(RunStatus::CANCELLED);
        expect($task4->refresh()->status)->toBe(RunStatus::CANCELLED);
        expect($workflow->refresh()->status)->toBe(RunStatus::CANCELLED);
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

    it('clears pause fields when cancelling paused entities', function() {
        $workflow = createWorkflow(RunStatus::PAUSED);
        $workflow->paused_at = now();
        $workflow->pause_reason = 'Paused workflow';
        $workflow->save();

        $task = createTask($workflow, RunStatus::PAUSED);
        $task->paused_at = now();
        $task->save();

        $step = createStep($task, RunStatus::PAUSED);
        $step->paused_at = now();
        $step->pause_reason = 'Paused step';
        $step->save();

        $workflow->cancel();

        $workflow->refresh();
        $task->refresh();
        $step->refresh();

        expect($workflow->paused_at)->toBeNull();
        expect($workflow->pause_reason)->toBeNull();
        expect($task->paused_at)->toBeNull();
        expect($step->paused_at)->toBeNull();
        expect($step->pause_reason)->toBeNull();
    });

    it('handles resume with mixed step states', function() {
        $workflow = createWorkflow(RunStatus::PAUSED);
        $task = createTask($workflow, RunStatus::PAUSED);
        $completedStep = createStep($task, RunStatus::COMPLETED, 1);
        $pausedStep = createStep($task, RunStatus::PAUSED, 2);
        $anotherPausedStep = createStep($task, RunStatus::PAUSED, 3);

        $workflow->resume();

        $completedStep->refresh();
        $pausedStep->refresh();
        $anotherPausedStep->refresh();

        expect($completedStep->status)->toBe(RunStatus::COMPLETED);
        expect($pausedStep->status)->toBe(RunStatus::PENDING);
        expect($anotherPausedStep->status)->toBe(RunStatus::PENDING);
    });

    it('workflow cancel with dependencies does not affect completed branches', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);

        $task1 = createTask($workflow, RunStatus::COMPLETED, 'branch1');
        createStep($task1, RunStatus::COMPLETED);

        $task2 = createTask($workflow, RunStatus::RUNNING, 'branch2');
        createStep($task2, RunStatus::RUNNING);

        $task3 = createTask($workflow, RunStatus::PENDING, 'dependent');
        createStep($task3, RunStatus::PENDING);
        linkDependency($task3, $task1);
        linkDependency($task3, $task2);

        $workflow->cancel();

        expect($task1->refresh()->status)->toBe(RunStatus::COMPLETED);
        expect($task2->refresh()->status)->toBe(RunStatus::CANCELLED);
        expect($task3->refresh()->status)->toBe(RunStatus::CANCELLED);
    });
});

/*
|--------------------------------------------------------------------------
| Edge Case Tests
|--------------------------------------------------------------------------
*/

describe('Edge cases', function() {
    it('handles pausing empty workflow', function() {
        $workflow = createWorkflow(RunStatus::PENDING);

        $result = $workflow->pause('Empty pause');

        expect($result)->toBeTrue();
        expect($workflow->refresh()->status)->toBe(RunStatus::PAUSED);
    });

    it('handles resuming workflow with no tasks', function() {
        $workflow = createWorkflow(RunStatus::PAUSED);
        $workflow->paused_at = now();
        $workflow->save();

        $result = $workflow->resume();

        expect($result)->toBeTrue();
        expect($workflow->refresh()->status)->toBe(RunStatus::RUNNING);
    });

    it('handles cancelling workflow with no tasks', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);

        $result = $workflow->cancel();

        expect($result)->toBeTrue();
        expect($workflow->refresh()->status)->toBe(RunStatus::CANCELLED);
    });

    it('preserves timestamps when pausing', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $workflow->started_at = now()->subHour();
        $workflow->save();

        $workflow->pause();

        $workflow->refresh();
        expect($workflow->started_at)->not->toBeNull();
        expect($workflow->paused_at)->not->toBeNull();
        expect($workflow->completed_at)->toBeNull();
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

    it('multiple pause calls on same entity are idempotent', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);

        $workflow->pause('First pause');
        $originalPausedAt = $workflow->refresh()->paused_at;

        Carbon::setTestNow(now()->addMinute());

        $result = $workflow->pause('Second pause');

        expect($result)->toBeFalse();
        $workflow->refresh();
        expect($workflow->pause_reason)->toBe('First pause');
        expect($workflow->paused_at->toDateTimeString())->toBe($originalPausedAt->toDateTimeString());
    });

    it('handles task with all completed steps being paused', function() {
        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::COMPLETED);
        createStep($task, RunStatus::COMPLETED, 1);
        createStep($task, RunStatus::COMPLETED, 2);

        $result = $task->pause();

        expect($result)->toBeFalse();
        expect($task->refresh()->status)->toBe(RunStatus::COMPLETED);
    });

    it('handles workflow with only failed tasks being paused', function() {
        $workflow = createWorkflow(RunStatus::FAILED);
        $task = createTask($workflow, RunStatus::FAILED);
        createStep($task, RunStatus::FAILED);

        $result = $workflow->pause();

        expect($result)->toBeFalse();
        expect($workflow->refresh()->status)->toBe(RunStatus::FAILED);
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
    it('dispatches WorkflowPaused event when workflow is paused', function() {
        Event::fake([WorkflowPaused::class]);

        $workflow = createWorkflow(RunStatus::RUNNING);
        $workflow->pause('Test reason');

        Event::assertDispatched(WorkflowPaused::class, function ($event) {
            return $event->step === null
                && $event->reason === 'Test reason';
        });
    });

    it('dispatches WorkflowPaused event when task is paused', function() {
        Event::fake([WorkflowPaused::class]);

        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        createStep($task, RunStatus::RUNNING);

        $task->pause('Task paused');

        Event::assertDispatched(WorkflowPaused::class, function ($event) {
            return $event->step === null
                && $event->reason === 'Task paused';
        });
    });

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

    it('dispatches WorkflowResumed event when workflow is resumed', function() {
        Event::fake([WorkflowResumed::class]);

        $workflow = createWorkflow(RunStatus::PAUSED);
        $task = createTask($workflow, RunStatus::PAUSED);
        createStep($task, RunStatus::PAUSED);

        $workflow->resume();

        Event::assertDispatched(WorkflowResumed::class, function ($event) {
            return $event->step === null;
        });
    });

    it('dispatches WorkflowResumed event when task is resumed', function() {
        Event::fake([WorkflowResumed::class]);

        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::PAUSED);
        createStep($task, RunStatus::PAUSED);

        $task->resume();

        Event::assertDispatched(WorkflowResumed::class, function ($event) {
            return $event->step === null;
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

    it('dispatches WorkflowCancelled event when workflow is cancelled', function() {
        Event::fake([WorkflowCancelled::class]);

        $workflow = createWorkflow(RunStatus::RUNNING);
        $workflow->cancel();

        Event::assertDispatched(WorkflowCancelled::class, function ($event) {
            return $event->step === null;
        });
    });

    it('dispatches WorkflowCancelled event when task is cancelled', function() {
        Event::fake([WorkflowCancelled::class]);

        $workflow = createWorkflow(RunStatus::RUNNING);
        $task = createTask($workflow, RunStatus::RUNNING);
        createStep($task, RunStatus::RUNNING);

        $task->cancel();

        Event::assertDispatched(WorkflowCancelled::class, function ($event) {
            return $event->step === null;
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

        $workflow = createWorkflow(RunStatus::COMPLETED);
        $workflow->pause();

        Event::assertNotDispatched(WorkflowPaused::class);
    });

    it('does not dispatch event when resume fails', function() {
        Event::fake([WorkflowResumed::class]);

        $workflow = createWorkflow(RunStatus::RUNNING);
        $workflow->resume();

        Event::assertNotDispatched(WorkflowResumed::class);
    });

    it('does not dispatch event when cancel fails', function() {
        Event::fake([WorkflowCancelled::class]);

        $workflow = createWorkflow(RunStatus::COMPLETED);
        $workflow->cancel();

        Event::assertNotDispatched(WorkflowCancelled::class);
    });
});
