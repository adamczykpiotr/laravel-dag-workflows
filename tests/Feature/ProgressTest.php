<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;

function makeStep(?int $updatedAtSecondsAgo = null): WorkflowTaskStep {
    $workflow = (new Workflow())->forceFill([
        'name' => 'test',
        'status' => RunStatus::PENDING,
    ]);
    $workflow->save();

    $task = (new WorkflowTask())->forceFill([
        'workflow_id' => $workflow->id,
        'name' => 'test-task',
        'status' => RunStatus::PENDING,
    ]);
    $task->save();

    $step = (new WorkflowTaskStep())->forceFill([
        'task_id' => $task->id,
        'workflow_id' => $workflow->id,
        'order' => 1,
        'class' => 'Fake',
        'status' => RunStatus::PENDING,
        'payload' => '',
        'progress' => 0,
    ]);
    $step->save();

    if ($updatedAtSecondsAgo !== null) {
        $step->forceFill(['updated_at' => now()->subSeconds($updatedAtSecondsAgo)])->saveQuietly();
        $step->refresh();
    }

    return $step;
}

function makeJob(WorkflowTaskStep $step): object {
    $job = new class {
        use HasWorkflowTracking;
    };
    $job->workflowTaskStep = $step;

    return $job;
}

it('clamps percentages below 0 to 0', function() {
    $step = makeStep(updatedAtSecondsAgo: 60);
    makeJob($step)->progress(-15);

    expect($step->fresh()->progress)->toBe(0);
});

it('clamps percentages above 100 to 100', function() {
    $step = makeStep(updatedAtSecondsAgo: 60);
    makeJob($step)->progress(150);

    expect($step->fresh()->progress)->toBe(100);
});

it('saves when the row was updated more than the debounce window ago', function() {
    $step = makeStep(updatedAtSecondsAgo: 60);
    makeJob($step)->progress(30);

    expect($step->fresh()->progress)->toBe(30);
});

it('skips the DB write when the row was updated within the debounce window', function() {
    $step = makeStep(updatedAtSecondsAgo: 5);
    makeJob($step)->progress(42);

    expect($step->fresh()->progress)->toBe(0);
});

it('always keeps the in-memory value current even when debounced', function() {
    $step = makeStep(updatedAtSecondsAgo: 5);
    makeJob($step)->progress(42);

    expect($step->progress)->toBe(42)
        ->and($step->fresh()->progress)->toBe(0);
});

it('bypasses debounce when reaching 100', function() {
    $step = makeStep(updatedAtSecondsAgo: 5);
    makeJob($step)->progress(100);

    expect($step->fresh()->progress)->toBe(100);
});

it('bypasses debounce when $force is true', function() {
    $step = makeStep(updatedAtSecondsAgo: 5);
    makeJob($step)->progress(42, force: true);

    expect($step->fresh()->progress)->toBe(42);
});

it('persists debounced in-memory progress via Eloquent dirty tracking on next save', function() {
    $step = makeStep(updatedAtSecondsAgo: 60);
    $job = makeJob($step);

    $job->progress(30);   // saves
    $job->progress(75);   // debounced — in-memory only

    expect($step->fresh()->progress)->toBe(30)
        ->and($step->isDirty('progress'))->toBeTrue();

    $step->save();

    expect($step->fresh()->progress)->toBe(75);
});
