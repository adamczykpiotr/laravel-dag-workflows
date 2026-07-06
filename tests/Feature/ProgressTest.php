<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Tests\TestCase;
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;

class ProgressTest extends TestCase {

    public function test_defaults_progress_to_null_for_steps_that_never_report(): void {
        $step = $this->makeStep();

        $this->assertNull($step->fresh()->progress);
    }


    public function test_clamps_percentages_below_0_to_0(): void {
        $step = $this->makeStep(updatedAtSecondsAgo: 60);
        $this->makeJob($step)->progress(-15);

        $this->assertSame(0, $step->fresh()->progress);
    }


    public function test_clamps_percentages_above_100_to_100(): void {
        $step = $this->makeStep(updatedAtSecondsAgo: 60);
        $this->makeJob($step)->progress(150);

        $this->assertSame(100, $step->fresh()->progress);
    }


    public function test_always_saves_the_first_progress_report_bypassing_debounce(): void {
        $step = $this->makeStep(updatedAtSecondsAgo: 5);
        $this->makeJob($step)->progress(10);

        $this->assertSame(10, $step->fresh()->progress);
    }


    public function test_skips_the_db_write_when_the_row_was_updated_within_the_debounce_window(): void {
        $step = $this->makeStep(updatedAtSecondsAgo: 5, seedProgress: 10);
        $this->makeJob($step)->progress(42);

        $this->assertSame(10, $step->fresh()->progress);
    }


    public function test_always_keeps_the_in_memory_value_current_even_when_debounced(): void {
        $step = $this->makeStep(updatedAtSecondsAgo: 5, seedProgress: 10);
        $this->makeJob($step)->progress(42);

        $this->assertSame(42, $step->progress);
        $this->assertSame(10, $step->fresh()->progress);
    }


    public function test_bypasses_debounce_when_reaching_100(): void {
        $step = $this->makeStep(updatedAtSecondsAgo: 5, seedProgress: 10);
        $this->makeJob($step)->progress(100);

        $this->assertSame(100, $step->fresh()->progress);
    }


    public function test_bypasses_debounce_when_force_is_true(): void {
        $step = $this->makeStep(updatedAtSecondsAgo: 5, seedProgress: 10);
        $this->makeJob($step)->progress(42, force: true);

        $this->assertSame(42, $step->fresh()->progress);
    }


    public function test_persists_debounced_in_memory_progress_via_eloquent_dirty_tracking_on_next_save(): void {
        $step = $this->makeStep(updatedAtSecondsAgo: 60, seedProgress: 0);
        $job = $this->makeJob($step);

        $job->progress(30);   // outside debounce → saves
        $job->progress(75);   // within debounce → in-memory only

        $this->assertSame(30, $step->fresh()->progress);
        $this->assertTrue($step->isDirty('progress'));

        $step->save();

        $this->assertSame(75, $step->fresh()->progress);
    }


    private function makeStep(?int $updatedAtSecondsAgo = null, ?int $seedProgress = null): WorkflowTaskStep {
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


    private function makeJob(WorkflowTaskStep $step): object {
        $job = new class {
            use HasWorkflowTracking;
        };
        $job->workflowTaskStep = $step;

        return $job;
    }
}
