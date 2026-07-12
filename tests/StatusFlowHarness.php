<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Middlewares\DagWorkflowTrackerJobMiddleware;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Services\WorkflowDispatcher;
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Queue;

/*
 * Shared harness: builds a real workflow graph and drives it through the actual
 * job middleware + dispatcher, draining the faked queue like a real worker.
 * Per-step behaviour comes from StatusFlowJob::$behaviours (step id => 'ok'
 * (default) | 'fail' | 'early').
 */

class StatusFlowJob implements ShouldQueue {
    use HasWorkflowTracking, InteractsWithQueue, Queueable;

    /** @var array<int, string> stepId => 'ok'|'fail'|'early' */
    public static array $behaviours = [];

    /** @var array<int, int> step ids in the order their handlers actually ran */
    public static array $executionLog = [];

    /** Guards the drain loop against re-processing the same faked job. */
    public bool $drained = false;

    public function handle(): void {
        self::$executionLog[] = $this->workflowTaskStep->id;

        $behaviour = self::$behaviours[$this->workflowTaskStep->id] ?? 'ok';

        if ($behaviour === 'fail') {
            throw new RuntimeException('boom');
        }

        if ($behaviour === 'early') {
            $this->completeTaskEarly('done early');
        }

        // 'ok' — no-op, the step just succeeds.
    }
}

/**
 * Shared helpers for the end-to-end status-propagation tests.
 */
trait InteractsWithStatusFlow {

    /**
     * Build a workflow with the given tasks, each defined as
     *   'task-name' => ['deps' => [...task names...], 'steps' => <count>]
     *
     * @param array<string, array{deps?: array<int, string>, steps?: int}> $definition
     * @return array{0: Workflow, 1: array<string, WorkflowTask>, 2: array<string, array<int, WorkflowTaskStep>>}
     */
    protected function buildWorkflow(array $definition): array {
        $workflow = new Workflow();
        $workflow->name = 'flow';
        $workflow->status = RunStatus::PENDING;
        $workflow->save();

        /** @var array<string, WorkflowTask> $tasks */
        $tasks = [];
        /** @var array<string, array<int, WorkflowTaskStep>> $steps */
        $steps = [];

        foreach ($definition as $name => $config) {
            $task = new WorkflowTask();
            $task->workflow_id = $workflow->id;
            $task->name = $name;
            $task->status = RunStatus::PENDING;
            $task->save();
            $tasks[$name] = $task;

            $stepCount = $config['steps'] ?? 1;
            $steps[$name] = [];
            for ($order = 1; $order <= $stepCount; $order++) {
                $step = new WorkflowTaskStep();
                $step->task_id = $task->id;
                $step->workflow_id = $workflow->id;
                $step->order = $order;
                $step->class = StatusFlowJob::class;
                $step->status = RunStatus::PENDING;
                $step->payload = base64_encode(serialize(new StatusFlowJob()));
                $step->save();
                $steps[$name][$order] = $step;
            }
        }

        foreach ($definition as $name => $config) {
            foreach ($config['deps'] ?? [] as $dependsOn) {
                $tasks[$name]->dependencies()->attach($tasks[$dependsOn]->id);
            }
        }

        return [$workflow, $tasks, $steps];
    }


    /**
     * Drain the faked queue: run every dispatched StatusFlowJob through the real
     * middleware, exactly like a worker would, until no new jobs remain.
     */
    protected function drainWorkflowQueue(): void {
        $middleware = resolve(DagWorkflowTrackerJobMiddleware::class);

        do {
            $ran = false;

            foreach (Queue::pushed(StatusFlowJob::class) as $job) {
                if ($job->drained) {
                    continue;
                }
                $job->drained = true;
                $ran = true;

                try {
                    $middleware->handle($job, fn(StatusFlowJob $j) => $j->handle());
                } catch (Throwable) {
                    // failStep() already ran inside the middleware; keep draining.
                }
            }
        } while ($ran);
    }


    /** Kick a workflow off from its entrypoints and run it to a standstill. */
    protected function runWorkflow(Workflow $workflow): void {
        /** @var WorkflowDispatcher $dispatcher */
        $dispatcher = resolve(WorkflowDispatcher::class);
        $dispatcher->dispatchWorkflow($workflow);
        $this->drainWorkflowQueue();
    }


    /** Fetch a fresh step model (avoids stale in-memory relations before retry). */
    protected function freshStep(WorkflowTaskStep $step): WorkflowTaskStep {
        return WorkflowTaskStep::query()->findOrFail($step->id);
    }
}
