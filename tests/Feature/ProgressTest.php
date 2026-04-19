<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;

function makeStep(?int $updatedAtSecondsAgo = null, ?int $seedProgress = null): WorkflowTaskStep {
    $workflow = new Workflow();
    $workflow->name = 'test';
    $workflow->status = RunStatus::PENDING;
    $workflow->save();

    $task = new WorkflowTask();
    $task->workflow_id = $workflow->id;
    $task->name = 'test-task';
    $task->status = RunStatus::PENDING;
    $task->save();

    $step = new WorkflowTaskStep();
    $step->task_id = $task->id;
    $step->workflow_id = $workflow->id;
    $step->order = 1;
    $step->class = 'Fake';
    $step->status = RunStatus::PENDING;
    $step->payload = '';
    $step->progress = $seedProgress;
    $step->save();

    if ($updatedAtSecondsAgo !== null) {
        $step->updated_at = now()->subSeconds($updatedAtSecondsAgo);
        $step->saveQuietly();
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

it('defaults progress to null for steps that never report', function() {
    $step = makeStep();

    expect($step->fresh()->progress)->toBeNull();
});

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

it('always saves the first progress report, bypassing debounce', function() {
    $step = makeStep(updatedAtSecondsAgo: 5);
    makeJob($step)->progress(10);

    expect($step->fresh()->progress)->toBe(10);
});

it('skips the DB write when the row was updated within the debounce window', function() {
    $step = makeStep(updatedAtSecondsAgo: 5, seedProgress: 10);
    makeJob($step)->progress(42);

    expect($step->fresh()->progress)->toBe(10);
});

it('always keeps the in-memory value current even when debounced', function() {
    $step = makeStep(updatedAtSecondsAgo: 5, seedProgress: 10);
    makeJob($step)->progress(42);

    expect($step->progress)->toBe(42)
        ->and($step->fresh()->progress)->toBe(10);
});

it('bypasses debounce when reaching 100', function() {
    $step = makeStep(updatedAtSecondsAgo: 5, seedProgress: 10);
    makeJob($step)->progress(100);

    expect($step->fresh()->progress)->toBe(100);
});

it('bypasses debounce when $force is true', function() {
    $step = makeStep(updatedAtSecondsAgo: 5, seedProgress: 10);
    makeJob($step)->progress(42, force: true);

    expect($step->fresh()->progress)->toBe(42);
});

it('persists debounced in-memory progress via Eloquent dirty tracking on next save', function() {
    $step = makeStep(updatedAtSecondsAgo: 60, seedProgress: 0);
    $job = makeJob($step);

    $job->progress(30);   // outside debounce → saves
    $job->progress(75);   // within debounce → in-memory only

    expect($step->fresh()->progress)->toBe(30)
        ->and($step->isDirty('progress'))->toBeTrue();

    $step->save();

    expect($step->fresh()->progress)->toBe(75);
});
