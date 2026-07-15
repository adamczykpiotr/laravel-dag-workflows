<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * rollbackStep() hook (issue #36).
 *
 * Reuses the end-to-end harness from tests/StatusFlowHarness.php. The tracker
 * middleware must invoke rollbackStep() right before handle() whenever a previous
 * attempt of the step already ran — and never on the first attempt.
 */
class RollbackStepTest extends TestCase {

    use InteractsWithStatusFlow;

    protected function setUp(): void {
        parent::setUp();

        Carbon::setTestNow('2026-07-15 12:00:00');
        Queue::fake();
        StatusFlowJob::$behaviours = [];
        StatusFlowJob::$executionLog = [];
        StatusFlowJob::$rollbackStepLog = [];
    }


    protected function tearDown(): void {
        Carbon::setTestNow();
        StatusFlowJob::$behaviours = [];
        StatusFlowJob::$executionLog = [];
        StatusFlowJob::$rollbackStepLog = [];

        parent::tearDown();
    }


    public function test_rollback_step_does_not_run_on_first_attempts(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 2],
        ]);

        $this->runWorkflow($workflow);

        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
        $this->assertSame([], StatusFlowJob::$rollbackStepLog);
        $this->assertSame(1, $this->freshStep($steps['a'][1])->attempts);
        $this->assertSame(1, $this->freshStep($steps['a'][2])->attempts);
    }


    public function test_rollback_step_runs_before_handle_when_retrying_a_failed_step(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 1],
        ]);
        $stepId = $steps['a'][1]->id;
        StatusFlowJob::$behaviours[$stepId] = 'fail';

        $this->runWorkflow($workflow);
        $this->assertSame(RunStatus::FAILED, $workflow->refresh()->status);
        $this->assertSame([], StatusFlowJob::$rollbackStepLog);

        StatusFlowJob::$behaviours[$stepId] = 'ok';
        $this->freshStep($steps['a'][1])->retry();
        $this->drainWorkflowQueue();

        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
        $this->assertSame([['step_id' => $stepId, 'attempts' => 2]], StatusFlowJob::$rollbackStepLog);
        $this->assertSame([$stepId, $stepId], StatusFlowJob::$executionLog);
        $this->assertSame(2, $this->freshStep($steps['a'][1])->attempts);
    }


    public function test_a_throwing_rollback_step_fails_the_step_without_running_handle(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 1],
        ]);
        $stepId = $steps['a'][1]->id;
        StatusFlowJob::$behaviours[$stepId] = 'fail';

        $this->runWorkflow($workflow);
        $this->assertSame(RunStatus::FAILED, $workflow->refresh()->status);

        StatusFlowJob::$behaviours[$stepId] = 'rollbackStep-fail';
        $this->freshStep($steps['a'][1])->retry();
        $this->drainWorkflowQueue();

        $this->assertSame(RunStatus::FAILED, $this->freshStep($steps['a'][1])->status);
        $this->assertSame(RunStatus::FAILED, $workflow->refresh()->status);
        $this->assertCount(1, StatusFlowJob::$rollbackStepLog);

        // handle() ran only on the first attempt — the throwing rollbackStep stopped the retry.
        $this->assertSame([$stepId], StatusFlowJob::$executionLog);
    }


    public function test_upstream_retry_rolls_back_only_steps_that_already_ran(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 3],
        ]);
        // Step 2 fails on the first pass; step 1 completes, step 3 is cancelled.
        StatusFlowJob::$behaviours[$steps['a'][2]->id] = 'fail';

        $this->runWorkflow($workflow);
        $this->assertSame(RunStatus::FAILED, $workflow->refresh()->status);

        // Retrying step 1 resets the later steps and re-runs the whole task.
        StatusFlowJob::$behaviours[$steps['a'][2]->id] = 'ok';
        $this->freshStep($steps['a'][1])->retry();
        $this->drainWorkflowQueue();

        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);

        // Steps 1 and 2 already ran once, so their re-runs roll back first.
        // Step 3 never ran (it was cancelled), so it starts clean without one.
        $this->assertSame(
            [$steps['a'][1]->id, $steps['a'][2]->id],
            array_column(StatusFlowJob::$rollbackStepLog, 'step_id')
        );
    }


    public function test_rollback_step_does_not_run_for_a_stale_duplicate_delivery(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 1],
        ]);

        $this->runWorkflow($workflow);
        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);

        // Redeliver the already-completed step: the claim fails, nothing runs.
        $this->freshStep($steps['a'][1])->dispatch(force: true);
        $this->drainWorkflowQueue();

        $this->assertSame([], StatusFlowJob::$rollbackStepLog);
        $this->assertSame(1, $this->freshStep($steps['a'][1])->attempts);
    }
}
