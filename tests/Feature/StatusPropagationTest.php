<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * End-to-end status propagation.
 *
 * Uses the shared harness in tests/StatusFlowHarness.php (buildWorkflow /
 * runWorkflow / drainWorkflowQueue / StatusFlowJob) to build a real workflow
 * graph and drive it through the actual job middleware + dispatcher, draining
 * the faked queue so that every dispatched follow-up step/task runs exactly as
 * it would on a real worker. The goal is to assert that the workflow status is
 * always driven to a correct terminal value.
 */
class StatusPropagationTest extends TestCase {

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
    | Successful workflows reach COMPLETED
    |--------------------------------------------------------------------------
    */


    public function test_completes_a_single_single_step_task(): void {
        [$workflow] = $this->buildWorkflow([
            'a' => ['steps' => 1],
        ]);

        $this->runWorkflow($workflow);

        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
        $this->assertNotNull($workflow->completed_at);
    }


    public function test_completes_a_single_multi_step_task(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 3],
        ]);

        $this->runWorkflow($workflow);

        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $tasks['a']->refresh()->status);
        foreach ($steps['a'] as $step) {
            $this->assertSame(RunStatus::COMPLETED, $step->refresh()->status);
        }
    }


    public function test_completes_a_linear_chain_of_dependent_tasks(): void {
        [$workflow, $tasks] = $this->buildWorkflow([
            'a' => ['steps' => 2],
            'b' => ['deps' => ['a'], 'steps' => 2],
            'c' => ['deps' => ['b'], 'steps' => 1],
        ]);

        $this->runWorkflow($workflow);

        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $tasks['a']->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $tasks['b']->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $tasks['c']->refresh()->status);
    }


    public function test_completes_a_diamond_graph(): void {
        [$workflow, $tasks] = $this->buildWorkflow([
            'a' => ['steps' => 1],
            'b' => ['deps' => ['a'], 'steps' => 1],
            'c' => ['deps' => ['a'], 'steps' => 1],
            'd' => ['deps' => ['b', 'c'], 'steps' => 1],
        ]);

        $this->runWorkflow($workflow);

        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
        foreach ($tasks as $task) {
            $this->assertSame(RunStatus::COMPLETED, $task->refresh()->status);
        }
    }


    public function test_completes_when_a_task_finishes_early(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 3],
            'b' => ['deps' => ['a'], 'steps' => 1],
        ]);
        StatusFlowJob::$behaviours[$steps['a'][1]->id] = 'early';

        $this->runWorkflow($workflow);

        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $tasks['a']->refresh()->status);
        $this->assertSame(RunStatus::SKIPPED, $steps['a'][2]->refresh()->status);
        $this->assertSame(RunStatus::SKIPPED, $steps['a'][3]->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $tasks['b']->refresh()->status);
    }


    /*
    |--------------------------------------------------------------------------
    | Failing workflows reach FAILED
    |--------------------------------------------------------------------------
    */


    public function test_fails_the_workflow_when_a_single_task_fails(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 2],
        ]);
        StatusFlowJob::$behaviours[$steps['a'][1]->id] = 'fail';

        $this->runWorkflow($workflow);

        $this->assertSame(RunStatus::FAILED, $workflow->refresh()->status);
        $this->assertSame(RunStatus::FAILED, $tasks['a']->refresh()->status);
    }


    public function test_fails_the_workflow_and_cancels_downstream_tasks(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 1],
            'b' => ['deps' => ['a'], 'steps' => 1],
        ]);
        StatusFlowJob::$behaviours[$steps['a'][1]->id] = 'fail';

        $this->runWorkflow($workflow);

        $this->assertSame(RunStatus::FAILED, $workflow->refresh()->status);
        $this->assertSame(RunStatus::FAILED, $tasks['a']->refresh()->status);
        $this->assertSame(RunStatus::CANCELLED, $tasks['b']->refresh()->status);
    }


    public function test_never_leaves_the_workflow_running_once_every_task_is_terminal(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 1],
            'b' => ['deps' => ['a'], 'steps' => 2],
            'c' => ['deps' => ['a'], 'steps' => 1],
        ]);
        // b fails mid-way, c succeeds.
        StatusFlowJob::$behaviours[$steps['b'][1]->id] = 'fail';

        $this->runWorkflow($workflow);

        foreach ($tasks as $task) {
            $this->assertTrue($task->refresh()->status->isTerminal());
        }
        $this->assertNotSame(RunStatus::RUNNING, $workflow->refresh()->status);
    }
}
