<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Middlewares\DagWorkflowTrackerJobMiddleware;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Services\WorkflowDispatcher;
use AdamczykPiotr\DagWorkflows\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

/**
 * Guards the core dependsOn invariant: a task's steps NEVER run before every
 * one of its dependencies has completed. The status-propagation tests only
 * assert terminal statuses — a scheduler that dispatched dependants too early
 * would still drain to all-COMPLETED, so execution order is asserted here
 * explicitly via the harness execution log.
 */
class DependencyOrderingTest extends TestCase {

    use InteractsWithStatusFlow;

    protected function setUp(): void {
        parent::setUp();

        Queue::fake();
        StatusFlowJob::$behaviours = [];
        StatusFlowJob::$executionLog = [];
    }


    protected function tearDown(): void {
        StatusFlowJob::$behaviours = [];
        StatusFlowJob::$executionLog = [];

        parent::tearDown();
    }


    /**
     * Run only the pushed-but-not-yet-drained jobs belonging to the given task,
     * mimicking one branch of workers finishing while another lags behind.
     *
     * @param WorkflowTask $task
     */
    private function drainOnlyTask(WorkflowTask $task): void {
        $middleware = resolve(DagWorkflowTrackerJobMiddleware::class);

        do {
            $ran = false;

            foreach (Queue::pushed(StatusFlowJob::class) as $job) {
                if ($job->drained || $job->workflowTaskStep->task_id !== $task->id) {
                    continue;
                }

                $job->drained = true;
                $ran = true;
                $middleware->handle($job, fn(StatusFlowJob $j) => $j->handle());
            }
        } while ($ran);
    }


    /**
     * @param WorkflowTask $task
     * @return bool
     */
    private function taskWasDispatched(WorkflowTask $task): bool {
        foreach (Queue::pushed(StatusFlowJob::class) as $job) {
            if ($job->workflowTaskStep->task_id === $task->id) {
                return true;
            }
        }

        return false;
    }


    public function test_every_task_runs_only_after_all_of_its_dependencies_completed(): void {
        // Diamond feeding a chain: a → (b, c) → d → e, plus an independent task f.
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 2],
            'b' => ['deps' => ['a'], 'steps' => 1],
            'c' => ['deps' => ['a'], 'steps' => 2],
            'd' => ['deps' => ['b', 'c'], 'steps' => 1],
            'e' => ['deps' => ['d'], 'steps' => 1],
            'f' => ['steps' => 1],
        ]);
        $dependencies = [
            'b' => ['a'],
            'c' => ['a'],
            'd' => ['b', 'c'],
            'e' => ['d'],
        ];

        $this->runWorkflow($workflow);

        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);

        $executionOrder = array_flip(StatusFlowJob::$executionLog);

        foreach ($dependencies as $taskName => $dependsOn) {
            $firstOwnStep = min(array_map(
                fn($step) => $executionOrder[$step->id],
                $steps[$taskName]
            ));

            foreach ($dependsOn as $dependencyName) {
                $lastDependencyStep = max(array_map(
                    fn($step) => $executionOrder[$step->id],
                    $steps[$dependencyName]
                ));

                $this->assertGreaterThan(
                    $lastDependencyStep,
                    $firstOwnStep,
                    "Task {$taskName} started before its dependency {$dependencyName} finished."
                );
            }
        }
    }


    public function test_a_task_with_multiple_dependencies_is_not_dispatched_until_the_last_one_completes(): void {
        [$workflow, $tasks] = $this->buildWorkflow([
            'b' => ['steps' => 1],
            'c' => ['steps' => 1],
            'd' => ['deps' => ['b', 'c'], 'steps' => 1],
        ]);

        resolve(WorkflowDispatcher::class)->dispatchWorkflow($workflow);

        // Only one branch finishes: d must stay parked.
        $this->drainOnlyTask($tasks['b']);
        $this->assertSame(RunStatus::COMPLETED, $tasks['b']->refresh()->status);
        $this->assertSame(RunStatus::PENDING, $tasks['d']->refresh()->status);
        $this->assertFalse($this->taskWasDispatched($tasks['d']));

        // The last dependency finishes: d is released exactly now.
        $this->drainOnlyTask($tasks['c']);
        $this->assertTrue($this->taskWasDispatched($tasks['d']));

        $this->drainOnlyTask($tasks['d']);
        $this->assertSame(RunStatus::COMPLETED, $workflow->refresh()->status);
    }


    public function test_a_failed_dependency_never_releases_its_dependants(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'b' => ['steps' => 1],
            'c' => ['steps' => 1],
            'd' => ['deps' => ['b', 'c'], 'steps' => 1],
        ]);
        StatusFlowJob::$behaviours[$steps['c'][1]->id] = 'fail';

        $this->runWorkflow($workflow);

        $this->assertSame(RunStatus::FAILED, $workflow->refresh()->status);
        $this->assertSame(RunStatus::COMPLETED, $tasks['b']->refresh()->status);
        $this->assertSame(RunStatus::FAILED, $tasks['c']->refresh()->status);
        $this->assertFalse($this->taskWasDispatched($tasks['d']));
        $this->assertNotContains($steps['d'][1]->id, StatusFlowJob::$executionLog);
    }
}
