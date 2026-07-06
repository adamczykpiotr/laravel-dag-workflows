<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Retry status propagation.
 *
 * Reuses the end-to-end harness from tests/StatusFlowHarness.php. Each test:
 *   1. runs a workflow to FAILED,
 *   2. flips the offending step(s) to succeed,
 *   3. retries the failed step and drains again,
 *   4. asserts the workflow is driven to a correct terminal status.
 *
 * The reported bug: after a retry the task completes but the workflow never
 * leaves RUNNING.
 */
class RetryStatusPropagationTest extends TestCase {

    use InteractsWithStatusFlow;

    protected function setUp(): void {
        parent::setUp();

        Carbon::setTestNow('2026-07-06 12:00:00');
        Queue::fake();
        StatusFlowJob::$behaviours = [];
    }


    protected function tearDown(): void {
        Carbon::setTestNow();
        StatusFlowJob::$behaviours = [];

        parent::tearDown();
    }


    /*
    |--------------------------------------------------------------------------
    | Retry drives the workflow to COMPLETED
    |--------------------------------------------------------------------------
    */


    public function test_retries_a_mid_task_step_in_a_single_task(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 3],
        ]);
        StatusFlowJob::$behaviours[$steps['a'][2]->id] = 'fail';

        $this->runWorkflow($workflow);
        $this->assertSame(RunStatus::FAILED, $workflow->refresh()->status);

        // Fix the transient failure and retry the failed step.
        StatusFlowJob::$behaviours[$steps['a'][2]->id] = 'ok';
        $this->freshStep($steps['a'][2])->retry();
        $this->drainWorkflowQueue();

        $this->assertSame(RunStatus::COMPLETED, $tasks['a']->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $steps['a'][3]->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
        $this->assertNotNull($workflow->completed_at);
    }


    public function test_retries_the_first_step_of_a_single_task(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 2],
        ]);
        StatusFlowJob::$behaviours[$steps['a'][1]->id] = 'fail';

        $this->runWorkflow($workflow);
        $this->assertSame(RunStatus::FAILED, $workflow->refresh()->status);

        StatusFlowJob::$behaviours[$steps['a'][1]->id] = 'ok';
        $this->freshStep($steps['a'][1])->retry();
        $this->drainWorkflowQueue();

        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
    }


    public function test_retries_a_failed_task_that_has_a_downstream_dependant(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 2],
            'b' => ['deps' => ['a'], 'steps' => 1],
        ]);
        StatusFlowJob::$behaviours[$steps['a'][2]->id] = 'fail';

        $this->runWorkflow($workflow);
        $this->assertSame(RunStatus::FAILED, $workflow->refresh()->status);
        $this->assertSame(RunStatus::CANCELLED, $tasks['b']->refresh()->status);

        StatusFlowJob::$behaviours[$steps['a'][2]->id] = 'ok';
        $this->freshStep($steps['a'][2])->retry();
        $this->drainWorkflowQueue();

        $this->assertSame(RunStatus::COMPLETED, $tasks['a']->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $tasks['b']->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
    }


    public function test_retries_a_failed_downstream_task_when_upstream_already_completed(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 1],
            'b' => ['deps' => ['a'], 'steps' => 2],
        ]);
        StatusFlowJob::$behaviours[$steps['b'][1]->id] = 'fail';

        $this->runWorkflow($workflow);
        $this->assertSame(RunStatus::FAILED, $workflow->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $tasks['a']->refresh()->status);

        StatusFlowJob::$behaviours[$steps['b'][1]->id] = 'ok';
        $this->freshStep($steps['b'][1])->retry();
        $this->drainWorkflowQueue();

        $this->assertSame(RunStatus::COMPLETED, $tasks['b']->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
    }


    public function test_retries_a_failed_branch_of_a_diamond(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 1],
            'b' => ['deps' => ['a'], 'steps' => 1],
            'c' => ['deps' => ['a'], 'steps' => 1],
            'd' => ['deps' => ['b', 'c'], 'steps' => 1],
        ]);
        StatusFlowJob::$behaviours[$steps['b'][1]->id] = 'fail';

        $this->runWorkflow($workflow);
        $this->assertSame(RunStatus::FAILED, $workflow->refresh()->status);
        $this->assertSame(RunStatus::CANCELLED, $tasks['d']->refresh()->status);

        StatusFlowJob::$behaviours[$steps['b'][1]->id] = 'ok';
        $this->freshStep($steps['b'][1])->retry();
        $this->drainWorkflowQueue();

        foreach ($tasks as $task) {
            $this->assertSame(RunStatus::COMPLETED, $task->refresh()->status);
        }
        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
    }


    /*
    |--------------------------------------------------------------------------
    | Retry always leaves the workflow in a consistent state
    |--------------------------------------------------------------------------
    */


    public function test_does_not_leave_the_workflow_running_with_a_sibling_still_failed(): void {
        // Two independent branches both fail; the user retries only one of them.
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 1],
            'b' => ['steps' => 1],
        ]);
        StatusFlowJob::$behaviours[$steps['a'][1]->id] = 'fail';
        StatusFlowJob::$behaviours[$steps['b'][1]->id] = 'fail';

        $this->runWorkflow($workflow);
        $this->assertSame(RunStatus::FAILED, $workflow->refresh()->status);

        // Retry only branch a.
        StatusFlowJob::$behaviours[$steps['a'][1]->id] = 'ok';
        $this->freshStep($steps['a'][1])->retry();
        $this->drainWorkflowQueue();

        // Branch b is still FAILED and there is no active work left, so the
        // workflow must NOT be stuck RUNNING.
        $this->assertSame(RunStatus::COMPLETED, $tasks['a']->refresh()->status);
        $this->assertSame(RunStatus::FAILED, $tasks['b']->refresh()->status);
        $this->assertNotSame(RunStatus::RUNNING, $workflow->refresh()->status);
    }


    public function test_completes_the_workflow_after_retrying_both_failed_branches(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 1],
            'b' => ['steps' => 1],
        ]);
        StatusFlowJob::$behaviours[$steps['a'][1]->id] = 'fail';
        StatusFlowJob::$behaviours[$steps['b'][1]->id] = 'fail';

        $this->runWorkflow($workflow);
        $this->assertSame(RunStatus::FAILED, $workflow->refresh()->status);

        StatusFlowJob::$behaviours[$steps['a'][1]->id] = 'ok';
        StatusFlowJob::$behaviours[$steps['b'][1]->id] = 'ok';
        $this->freshStep($steps['a'][1])->retry();
        $this->drainWorkflowQueue();
        $this->freshStep($steps['b'][1])->retry();
        $this->drainWorkflowQueue();

        $this->assertSame(RunStatus::COMPLETED, $tasks['a']->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $tasks['b']->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
    }
}
