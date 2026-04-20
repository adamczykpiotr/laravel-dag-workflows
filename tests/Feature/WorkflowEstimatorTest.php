<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Services\WorkflowEstimator;
use Illuminate\Support\Carbon;

function makeWorkflow(RunStatus $status = RunStatus::RUNNING, ?Carbon $createdAt = null, ?Carbon $completedAt = null): Workflow {
    $workflow = new Workflow();
    $workflow->name = 'test';
    $workflow->status = $status;
    $workflow->completed_at = $completedAt;
    $workflow->save();

    if ($createdAt !== null) {
        $workflow->created_at = $createdAt;
        $workflow->saveQuietly();
        $workflow->refresh();
    }

    return $workflow;
}

function makeTask(Workflow $workflow, RunStatus $status = RunStatus::RUNNING, ?Carbon $createdAt = null, ?Carbon $completedAt = null): WorkflowTask {
    $task = new WorkflowTask();
    $task->workflow_id = $workflow->id;
    $task->name = 'task';
    $task->status = $status;
    $task->completed_at = $completedAt;
    $task->save();

    if ($createdAt !== null) {
        $task->created_at = $createdAt;
        $task->saveQuietly();
        $task->refresh();
    }

    return $task;
}

function makeCompletedStep(WorkflowTask $task, string $class, int $durationSeconds, int $order = 1): WorkflowTaskStep {
    $createdAt = now()->subSeconds($durationSeconds + 60);
    $completedAt = $createdAt->copy()->addSeconds($durationSeconds);

    $step = new WorkflowTaskStep();
    $step->task_id = $task->id;
    $step->workflow_id = $task->workflow_id;
    $step->order = $order;
    $step->class = $class;
    $step->status = RunStatus::COMPLETED;
    $step->payload = '';
    $step->completed_at = $completedAt;
    $step->save();

    $step->created_at = $createdAt;
    $step->saveQuietly();
    $step->refresh();

    return $step;
}

function addStep(WorkflowTask $task, RunStatus $status, string $class, int $order = 1, ?Carbon $createdAt = null): WorkflowTaskStep {
    $step = new WorkflowTaskStep();
    $step->task_id = $task->id;
    $step->workflow_id = $task->workflow_id;
    $step->order = $order;
    $step->class = $class;
    $step->status = $status;
    $step->payload = '';
    $step->save();

    if ($createdAt !== null) {
        $step->created_at = $createdAt;
        $step->saveQuietly();
        $step->refresh();
    }

    return $step;
}

function estimateFor(Workflow $workflow) {
    return (new WorkflowEstimator())->build($workflow->load('tasks.steps'));
}

beforeEach(function() {
    Carbon::setTestNow('2026-01-15 12:00:00');
});

afterEach(function() {
    Carbon::setTestNow();
});

it('reports zero estimatedDurationSeconds for a step class with no completed history', function() {
    $wf = makeWorkflow();
    $t = makeTask($wf);
    $step = addStep($t, RunStatus::PENDING, 'NewJob');

    $dto = estimateFor($wf);

    expect($dto->tasks[$t->id]->steps[$step->id]->estimatedDurationSeconds)->toBe(0);
});

it('averages durations of completed steps of the same class', function() {
    $wf = makeWorkflow();
    $t = makeTask($wf);
    makeCompletedStep($t, 'JobA', durationSeconds: 30);
    makeCompletedStep($t, 'JobA', durationSeconds: 50, order: 2);
    makeCompletedStep($t, 'JobA', durationSeconds: 70, order: 3);
    $pending = addStep($t, RunStatus::PENDING, 'JobA', order: 4);

    $dto = estimateFor($wf);

    expect($dto->tasks[$t->id]->steps[$pending->id]->estimatedDurationSeconds)->toBe(50);
});

it('step estimated remaining falls back to 0 when no class history', function() {
    $wf = makeWorkflow();
    $t = makeTask($wf);
    $pending = addStep($t, RunStatus::PENDING, 'NewJob');

    $dto = estimateFor($wf);

    expect($dto->tasks[$t->id]->steps[$pending->id]->estimatedSecondsRemaining)->toBe(0);
});

it('pending step remaining equals the class mean', function() {
    $wf = makeWorkflow();
    $t = makeTask($wf);
    makeCompletedStep($t, 'JobA', durationSeconds: 40);
    makeCompletedStep($t, 'JobA', durationSeconds: 60, order: 2);
    $pending = addStep($t, RunStatus::PENDING, 'JobA', order: 3);

    $dto = estimateFor($wf);

    expect($dto->tasks[$t->id]->steps[$pending->id]->estimatedSecondsRemaining)->toBe(50);
});

it('running step remaining subtracts elapsed from the class mean', function() {
    $wf = makeWorkflow();
    $t = makeTask($wf);
    makeCompletedStep($t, 'JobA', durationSeconds: 100);
    $running = addStep($t, RunStatus::RUNNING, 'JobA', order: 2, createdAt: now()->subSeconds(30));

    $dto = estimateFor($wf);

    expect($dto->tasks[$t->id]->steps[$running->id]->estimatedSecondsRemaining)->toBe(70);
});

it('running step remaining clamps to 0 when elapsed exceeds the class mean', function() {
    $wf = makeWorkflow();
    $t = makeTask($wf);
    makeCompletedStep($t, 'JobA', durationSeconds: 10);
    $running = addStep($t, RunStatus::RUNNING, 'JobA', order: 2, createdAt: now()->subSeconds(60));

    $dto = estimateFor($wf);

    expect($dto->tasks[$t->id]->steps[$running->id]->estimatedSecondsRemaining)->toBe(0);
});

it('terminal steps report 0 remaining', function() {
    $wf = makeWorkflow();
    $t = makeTask($wf);
    makeCompletedStep($t, 'JobA', durationSeconds: 100);
    $done = addStep($t, RunStatus::COMPLETED, 'JobA', order: 2);
    $failed = addStep($t, RunStatus::FAILED, 'JobA', order: 3);
    $cancelled = addStep($t, RunStatus::CANCELLED, 'JobA', order: 4);

    $dto = estimateFor($wf);

    expect($dto->tasks[$t->id]->steps[$done->id]->estimatedSecondsRemaining)->toBe(0)
        ->and($dto->tasks[$t->id]->steps[$failed->id]->estimatedSecondsRemaining)->toBe(0)
        ->and($dto->tasks[$t->id]->steps[$cancelled->id]->estimatedSecondsRemaining)->toBe(0);
});

it('task remaining sums its non-terminal steps', function() {
    $wf = makeWorkflow();
    $history = makeTask($wf);
    makeCompletedStep($history, 'JobA', durationSeconds: 40);
    makeCompletedStep($history, 'JobB', durationSeconds: 60, order: 2);

    $active = makeTask($wf);
    addStep($active, RunStatus::RUNNING, 'JobA', order: 1, createdAt: now()->subSeconds(10));
    addStep($active, RunStatus::PENDING, 'JobB', order: 2);

    $dto = estimateFor($wf);

    // JobA: 40 - 10 elapsed = 30; JobB: 60; sum = 90
    expect($dto->tasks[$active->id]->estimatedSecondsRemaining)->toBe(90);
});

it('workflow remaining is the max across unfinished tasks', function() {
    $wf = makeWorkflow();
    $history = makeTask($wf, RunStatus::COMPLETED);
    makeCompletedStep($history, 'Short', durationSeconds: 10);
    makeCompletedStep($history, 'Long', durationSeconds: 200, order: 2);

    $short = makeTask($wf);
    addStep($short, RunStatus::PENDING, 'Short');
    $long = makeTask($wf);
    addStep($long, RunStatus::PENDING, 'Long');

    expect(estimateFor($wf)->estimatedSecondsRemaining)->toBe(200);
});

it('workflow remaining takes the longest parallel task, not the sum', function() {
    $wf = makeWorkflow();
    $history = makeTask($wf, RunStatus::COMPLETED);
    makeCompletedStep($history, 'JobA', durationSeconds: 30);
    makeCompletedStep($history, 'JobB', durationSeconds: 100, order: 2);

    $fast = makeTask($wf);
    addStep($fast, RunStatus::PENDING, 'JobA');
    $slow = makeTask($wf);
    addStep($slow, RunStatus::PENDING, 'JobB');

    expect(estimateFor($wf)->estimatedSecondsRemaining)->toBe(100);
});

it('workflow duration spans created_at → completed_at when finished', function() {
    $start = Carbon::parse('2026-01-15 11:00:00');
    $end = Carbon::parse('2026-01-15 11:05:30');
    $wf = makeWorkflow(RunStatus::COMPLETED, createdAt: $start, completedAt: $end);

    expect(estimateFor($wf)->durationSeconds)->toBe(330);
});

it('workflow duration spans created_at → now when still running', function() {
    $wf = makeWorkflow(RunStatus::RUNNING, createdAt: now()->subMinutes(3));

    expect(estimateFor($wf)->durationSeconds)->toBe(180);
});

it('estimated completion is null for terminal workflows', function() {
    $done = makeWorkflow(RunStatus::COMPLETED, createdAt: now()->subMinute(), completedAt: now());

    expect(estimateFor($done)->estimatedCompletionAt)->toBeNull();
});

it('estimated completion is non-null when the workflow has remaining work with history', function() {
    $wf = makeWorkflow();
    $t = makeTask($wf);
    makeCompletedStep($t, 'JobA', durationSeconds: 120);
    addStep($t, RunStatus::PENDING, 'JobA', order: 2);

    expect(estimateFor($wf)->estimatedCompletionAt)->not->toBeNull();
});

it('serialises new timing fields on the WorkflowResource tree', function() {
    $wf = makeWorkflow(RunStatus::RUNNING, createdAt: now()->subMinutes(2));
    $history = makeTask($wf, RunStatus::COMPLETED);
    makeCompletedStep($history, 'JobA', durationSeconds: 60);
    $active = makeTask($wf, RunStatus::RUNNING, createdAt: now()->subMinutes(2));
    $running = addStep($active, RunStatus::RUNNING, 'JobA', createdAt: now()->subSeconds(20));

    $controller = new \AdamczykPiotr\DagWorkflows\Http\Controllers\WorkflowController();
    $response = $controller->show($wf->id);
    $payload = json_decode($response->getContent(), true);

    expect($payload['durationSeconds'])->toBe(120)
        ->and($payload['estimatedSecondsRemaining'])->toBe(40)  // 60 mean - 20 elapsed
        ->and($payload['tasks'][1]['durationSeconds'])->toBe(120)
        ->and($payload['tasks'][1]['estimatedSecondsRemaining'])->toBe(40)
        ->and($payload['tasks'][1]['steps'][0])
            ->toHaveKey('progress')
            ->toHaveKey('durationSeconds')
            ->toHaveKey('estimatedDurationSeconds')
            ->toHaveKey('estimatedSecondsRemaining')
        ->and($payload['tasks'][1]['steps'][0]['estimatedDurationSeconds'])->toBe(60)
        ->and($payload['tasks'][1]['steps'][0]['estimatedSecondsRemaining'])->toBe(40);
});
