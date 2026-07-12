<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Services\WorkflowEstimator;
use AdamczykPiotr\DagWorkflows\Tests\TestCase;
use Illuminate\Support\Carbon;

class WorkflowEstimatorTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        Carbon::setTestNow('2026-01-15 12:00:00');
    }


    protected function tearDown(): void {
        Carbon::setTestNow();

        parent::tearDown();
    }


    public function test_reports_zero_estimated_duration_seconds_for_a_step_class_with_no_completed_history(): void {
        $wf = $this->makeWorkflow();
        $t = $this->makeTask($wf);
        $step = $this->addStep($t, RunStatus::PENDING, 'NewJob');

        $dto = $this->estimateFor($wf);

        $this->assertSame(0, $dto->tasks[$t->id]->steps[$step->id]->estimatedDurationSeconds);
    }


    public function test_averages_durations_of_completed_steps_of_the_same_class(): void {
        $wf = $this->makeWorkflow();
        $t = $this->makeTask($wf);
        $this->makeCompletedStep($t, 'JobA', durationSeconds: 30);
        $this->makeCompletedStep($t, 'JobA', durationSeconds: 50, order: 2);
        $this->makeCompletedStep($t, 'JobA', durationSeconds: 70, order: 3);
        $pending = $this->addStep($t, RunStatus::PENDING, 'JobA', order: 4);

        $dto = $this->estimateFor($wf);

        $this->assertSame(50, $dto->tasks[$t->id]->steps[$pending->id]->estimatedDurationSeconds);
    }


    public function test_step_estimated_remaining_falls_back_to_0_when_no_class_history(): void {
        $wf = $this->makeWorkflow();
        $t = $this->makeTask($wf);
        $pending = $this->addStep($t, RunStatus::PENDING, 'NewJob');

        $dto = $this->estimateFor($wf);

        $this->assertSame(0, $dto->tasks[$t->id]->steps[$pending->id]->estimatedSecondsRemaining);
    }


    public function test_pending_step_remaining_equals_the_class_mean(): void {
        $wf = $this->makeWorkflow();
        $t = $this->makeTask($wf);
        $this->makeCompletedStep($t, 'JobA', durationSeconds: 40);
        $this->makeCompletedStep($t, 'JobA', durationSeconds: 60, order: 2);
        $pending = $this->addStep($t, RunStatus::PENDING, 'JobA', order: 3);

        $dto = $this->estimateFor($wf);

        $this->assertSame(50, $dto->tasks[$t->id]->steps[$pending->id]->estimatedSecondsRemaining);
    }


    public function test_running_step_remaining_subtracts_elapsed_from_the_class_mean(): void {
        $wf = $this->makeWorkflow();
        $t = $this->makeTask($wf);
        $this->makeCompletedStep($t, 'JobA', durationSeconds: 100);
        $running = $this->addStep($t, RunStatus::RUNNING, 'JobA', order: 2, createdAt: now()->subSeconds(30));

        $dto = $this->estimateFor($wf);

        $this->assertSame(70, $dto->tasks[$t->id]->steps[$running->id]->estimatedSecondsRemaining);
    }


    public function test_running_step_remaining_clamps_to_0_when_elapsed_exceeds_the_class_mean(): void {
        $wf = $this->makeWorkflow();
        $t = $this->makeTask($wf);
        $this->makeCompletedStep($t, 'JobA', durationSeconds: 10);
        $running = $this->addStep($t, RunStatus::RUNNING, 'JobA', order: 2, createdAt: now()->subSeconds(60));

        $dto = $this->estimateFor($wf);

        $this->assertSame(0, $dto->tasks[$t->id]->steps[$running->id]->estimatedSecondsRemaining);
    }


    public function test_terminal_steps_report_0_remaining(): void {
        $wf = $this->makeWorkflow();
        $t = $this->makeTask($wf);
        $this->makeCompletedStep($t, 'JobA', durationSeconds: 100);
        $done = $this->addStep($t, RunStatus::COMPLETED, 'JobA', order: 2);
        $failed = $this->addStep($t, RunStatus::FAILED, 'JobA', order: 3);
        $cancelled = $this->addStep($t, RunStatus::CANCELLED, 'JobA', order: 4);

        $dto = $this->estimateFor($wf);

        $this->assertSame(0, $dto->tasks[$t->id]->steps[$done->id]->estimatedSecondsRemaining);
        $this->assertSame(0, $dto->tasks[$t->id]->steps[$failed->id]->estimatedSecondsRemaining);
        $this->assertSame(0, $dto->tasks[$t->id]->steps[$cancelled->id]->estimatedSecondsRemaining);
    }


    public function test_task_remaining_sums_its_non_terminal_steps(): void {
        $wf = $this->makeWorkflow();
        $history = $this->makeTask($wf);
        $this->makeCompletedStep($history, 'JobA', durationSeconds: 40);
        $this->makeCompletedStep($history, 'JobB', durationSeconds: 60, order: 2);

        $active = $this->makeTask($wf);
        $this->addStep($active, RunStatus::RUNNING, 'JobA', order: 1, createdAt: now()->subSeconds(10));
        $this->addStep($active, RunStatus::PENDING, 'JobB', order: 2);

        $dto = $this->estimateFor($wf);

        // JobA: 40 - 10 elapsed = 30; JobB: 60; sum = 90
        $this->assertSame(90, $dto->tasks[$active->id]->estimatedSecondsRemaining);
    }


    public function test_workflow_remaining_is_the_max_across_unfinished_tasks(): void {
        $wf = $this->makeWorkflow();
        $history = $this->makeTask($wf, RunStatus::COMPLETED);
        $this->makeCompletedStep($history, 'Short', durationSeconds: 10);
        $this->makeCompletedStep($history, 'Long', durationSeconds: 200, order: 2);

        $short = $this->makeTask($wf);
        $this->addStep($short, RunStatus::PENDING, 'Short');
        $long = $this->makeTask($wf);
        $this->addStep($long, RunStatus::PENDING, 'Long');

        $this->assertSame(200, $this->estimateFor($wf)->estimatedSecondsRemaining);
    }


    public function test_workflow_remaining_takes_the_longest_parallel_task_not_the_sum(): void {
        $wf = $this->makeWorkflow();
        $history = $this->makeTask($wf, RunStatus::COMPLETED);
        $this->makeCompletedStep($history, 'JobA', durationSeconds: 30);
        $this->makeCompletedStep($history, 'JobB', durationSeconds: 100, order: 2);

        $fast = $this->makeTask($wf);
        $this->addStep($fast, RunStatus::PENDING, 'JobA');
        $slow = $this->makeTask($wf);
        $this->addStep($slow, RunStatus::PENDING, 'JobB');

        $this->assertSame(100, $this->estimateFor($wf)->estimatedSecondsRemaining);
    }


    public function test_workflow_duration_spans_created_at_to_completed_at_when_finished(): void {
        $start = Carbon::parse('2026-01-15 11:00:00');
        $end = Carbon::parse('2026-01-15 11:05:30');
        $wf = $this->makeWorkflow(RunStatus::COMPLETED, createdAt: $start, completedAt: $end);

        $this->assertSame(330, $this->estimateFor($wf)->durationSeconds);
    }


    public function test_workflow_duration_spans_created_at_to_now_when_still_running(): void {
        $wf = $this->makeWorkflow(RunStatus::RUNNING, createdAt: now()->subMinutes(3));

        $this->assertSame(180, $this->estimateFor($wf)->durationSeconds);
    }


    public function test_estimated_completion_is_null_for_terminal_workflows(): void {
        $done = $this->makeWorkflow(RunStatus::COMPLETED, createdAt: now()->subMinute(), completedAt: now());

        $this->assertNull($this->estimateFor($done)->estimatedCompletionAt);
    }


    public function test_estimated_completion_is_non_null_when_the_workflow_has_remaining_work_with_history(): void {
        $wf = $this->makeWorkflow();
        $t = $this->makeTask($wf);
        $this->makeCompletedStep($t, 'JobA', durationSeconds: 120);
        $this->addStep($t, RunStatus::PENDING, 'JobA', order: 2);

        $this->assertNotNull($this->estimateFor($wf)->estimatedCompletionAt);
    }


    public function test_serialises_new_timing_fields_on_the_workflow_resource_tree(): void {
        $wf = $this->makeWorkflow(RunStatus::RUNNING, createdAt: now()->subMinutes(2));
        $history = $this->makeTask($wf, RunStatus::COMPLETED);
        $this->makeCompletedStep($history, 'JobA', durationSeconds: 60);
        $active = $this->makeTask($wf, RunStatus::RUNNING, createdAt: now()->subMinutes(2));
        $running = $this->addStep($active, RunStatus::RUNNING, 'JobA', createdAt: now()->subSeconds(20));

        $controller = new \AdamczykPiotr\DagWorkflows\Http\Controllers\WorkflowController();
        $response = $controller->show(\Illuminate\Http\Request::create("/workflows/{$wf->id}?format=full"), $wf->id);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(120, $payload['durationSeconds']);
        $this->assertSame(40, $payload['estimatedSecondsRemaining']);  // 60 mean - 20 elapsed
        $this->assertSame(120, $payload['tasks'][1]['durationSeconds']);
        $this->assertSame(40, $payload['tasks'][1]['estimatedSecondsRemaining']);
        $this->assertArrayHasKey('progress', $payload['tasks'][1]['steps'][0]);
        $this->assertArrayHasKey('durationSeconds', $payload['tasks'][1]['steps'][0]);
        $this->assertArrayHasKey('estimatedDurationSeconds', $payload['tasks'][1]['steps'][0]);
        $this->assertArrayHasKey('estimatedSecondsRemaining', $payload['tasks'][1]['steps'][0]);
        $this->assertSame(60, $payload['tasks'][1]['steps'][0]['estimatedDurationSeconds']);
        $this->assertSame(40, $payload['tasks'][1]['steps'][0]['estimatedSecondsRemaining']);
    }


    private function makeWorkflow(RunStatus $status = RunStatus::RUNNING, ?Carbon $createdAt = null, ?Carbon $completedAt = null): Workflow {
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


    private function makeTask(Workflow $workflow, RunStatus $status = RunStatus::RUNNING, ?Carbon $createdAt = null, ?Carbon $completedAt = null): WorkflowTask {
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


    private function makeCompletedStep(WorkflowTask $task, string $class, int $durationSeconds, int $order = 1): WorkflowTaskStep {
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


    private function addStep(WorkflowTask $task, RunStatus $status, string $class, int $order = 1, ?Carbon $createdAt = null): WorkflowTaskStep {
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


    private function estimateFor(Workflow $workflow) {
        return resolve(WorkflowEstimator::class)->build($workflow->load('tasks.steps'));
    }
}
