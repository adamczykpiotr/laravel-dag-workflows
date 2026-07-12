<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Middlewares\DagWorkflowTrackerJobMiddleware;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Tests\TestCase;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * A redelivered job for an already-processed step must be dropped: no handler
 * re-run (duplicate work) and no failure (the step already succeeded). Happens
 * when an orphaned reservation is redelivered after retry_after.
 */
class RedeliveredStepTest extends TestCase {

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


    /** Redeliver a job for an already-terminal step; returns whether the handler ran. */
    private function redeliver(WorkflowTaskStep $step): bool {
        $middleware = resolve(DagWorkflowTrackerJobMiddleware::class);

        $underlyingJob = \Mockery::mock(QueueJobContract::class);
        $underlyingJob->shouldReceive('fail')->never();

        $job = new StatusFlowJob();
        $job->workflowTaskStep = $this->freshStep($step);
        $job->setJob($underlyingJob);

        $handlerRan = false;
        $result = $middleware->handle($job, function (StatusFlowJob $j) use (&$handlerRan) {
            $handlerRan = true;
            return $j->handle();
        });

        $this->assertNull($result, 'A redelivered, already-processed job must be dropped (return null).');

        return $handlerRan;
    }


    public function test_redelivered_completed_step_is_dropped_without_rerunning_the_handler(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 1],
        ]);

        $this->runWorkflow($workflow);
        $this->assertSame(RunStatus::COMPLETED, $steps['a'][1]->refresh()->status);

        $completedAt = $steps['a'][1]->refresh()->completed_at;

        $handlerRan = $this->redeliver($steps['a'][1]);

        $this->assertFalse($handlerRan, 'The handler must not run again for an already-completed step.');
        $this->assertSame(RunStatus::COMPLETED, $steps['a'][1]->refresh()->status);
        $this->assertEquals($completedAt, $steps['a'][1]->refresh()->completed_at, 'completed_at must not be re-stamped.');
        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
    }


    public function test_redelivered_failed_step_is_dropped_without_rerunning_the_handler(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 1],
        ]);
        StatusFlowJob::$behaviours[$steps['a'][1]->id] = 'fail';

        $this->runWorkflow($workflow);
        $this->assertSame(RunStatus::FAILED, $steps['a'][1]->refresh()->status);

        $handlerRan = $this->redeliver($steps['a'][1]);

        $this->assertFalse($handlerRan, 'The handler must not run again for an already-failed step.');
        $this->assertSame(RunStatus::FAILED, $steps['a'][1]->refresh()->status);
    }


    /**
     * True concurrency: two workers hold duplicate deliveries of the SAME step
     * and both read it as PENDING before either writes. The status check alone
     * cannot catch this — the PENDING -> RUNNING claim must be atomic so only
     * one delivery runs the handler.
     */
    public function test_concurrent_duplicate_delivery_runs_the_handler_exactly_once(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 1],
        ]);
        $step = $steps['a'][1];

        $middleware = resolve(DagWorkflowTrackerJobMiddleware::class);
        $handlerRuns = 0;
        $handler = function (StatusFlowJob $j) use (&$handlerRuns) {
            $handlerRuns++;
            return $j->handle();
        };

        // Both jobs deserialize the step BEFORE either starts processing —
        // both in-memory models say PENDING, exactly like two racing workers.
        $first = new StatusFlowJob();
        $first->workflowTaskStep = $this->freshStep($step);
        $second = new StatusFlowJob();
        $second->workflowTaskStep = $this->freshStep($step);

        $middleware->handle($first, $handler);
        $middleware->handle($second, $handler);

        $this->assertSame(1, $handlerRuns, 'Duplicate concurrent delivery re-ran the step handler.');
        $this->assertSame(RunStatus::COMPLETED, $this->freshStep($step)->status);
        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
    }


    /**
     * Crash window: a worker commits a task's COMPLETED status but dies before
     * pushing the dependant jobs. The queue redelivers the completed step —
     * the drop path must use that redelivery to re-dispatch the dependants
     * instead of stranding them.
     */
    public function test_redelivered_completed_step_heals_undispatched_dependants(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 1],
            'b' => ['deps' => ['a'], 'steps' => 1],
        ]);

        // Simulate the crash aftermath: a fully completed, b never dispatched.
        $steps['a'][1]->status = RunStatus::COMPLETED;
        $steps['a'][1]->completed_at = now();
        $steps['a'][1]->save();
        $tasks['a']->status = RunStatus::COMPLETED;
        $tasks['a']->completed_at = now();
        $tasks['a']->save();

        $handlerRan = $this->redeliver($steps['a'][1]);

        $this->assertFalse($handlerRan);
        $this->drainWorkflowQueue();
        $this->assertSame(RunStatus::COMPLETED, $tasks['b']->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
    }
}
