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
}
