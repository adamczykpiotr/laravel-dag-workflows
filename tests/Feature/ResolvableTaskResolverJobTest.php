<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Jobs\ResolvableTaskResolverJob;
use AdamczykPiotr\DagWorkflows\Middlewares\ResolvableItems\PassthroughMiddleware;
use AdamczykPiotr\DagWorkflows\Middlewares\ResolvableItems\TakeFirstMiddleware;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use AdamczykPiotr\DagWorkflows\Services\WorkflowDefinitionParser;
use AdamczykPiotr\DagWorkflows\Services\WorkflowRepository;
use AdamczykPiotr\DagWorkflows\Tests\TestCase;
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Laravel\SerializableClosure\SerializableClosure;

class ResolverTrackedJob implements ShouldQueue {
    use HasWorkflowTracking, InteractsWithQueue, Queueable;
    public function handle(): void {}
}

class ResolvableTaskResolverJobTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        config()->set('dag-workflows.resolvable_items_middleware', PassthroughMiddleware::class);
    }


    /**
     * Build a workflow with one host task/step and hand back a resolver job whose
     * tracking step points at it (mimicking how a resolvable task is dispatched).
     *
     * @param callable $items
     * @param callable $jobs
     */
    private function makeResolver(callable $items, callable $jobs): ResolvableTaskResolverJob {
        $workflow = new Workflow();
        $workflow->name = 'wf';
        $workflow->status = RunStatus::RUNNING;
        $workflow->save();

        $task = new WorkflowTask();
        $task->workflow_id = $workflow->id;
        $task->name = 'resolvable';
        $task->status = RunStatus::RUNNING;
        $task->save();

        $step = new WorkflowTaskStep();
        $step->task_id = $task->id;
        $step->workflow_id = $workflow->id;
        $step->order = 1;
        $step->class = ResolvableTaskResolverJob::class;
        $step->status = RunStatus::RUNNING;
        $step->payload = '';
        $step->save();

        $job = new ResolvableTaskResolverJob(
            name: 'resolvable',
            dependsOn: [],
            itemProvider: new SerializableClosure($items),
            jobProvider: new SerializableClosure($jobs),
        );
        $job->workflowTaskStep = $step;

        return $job;
    }


    private function handleJob(ResolvableTaskResolverJob $job): void {
        $job->handle(
            resolve(WorkflowDefinitionParser::class),
            resolve(WorkflowRepository::class),
        );
    }


    public function test_appends_one_task_per_resolved_item(): void {
        $job = $this->makeResolver(
            items: fn() => ['x', 'y'],
            jobs: fn($item) => new ResolverTrackedJob(),
        );

        $this->handleJob($job);

        $names = WorkflowTask::query()->pluck(WorkflowTask::ATTRIBUTE_NAME)->toArray();
        $this->assertContains('resolvable:0', $names);
        $this->assertContains('resolvable:1', $names);

        $appended = WorkflowTask::query()->where(WorkflowTask::ATTRIBUTE_NAME, 'resolvable:0')->firstOrFail();
        $this->assertSame(1, $appended->steps()->count());
        $this->assertSame(ResolverTrackedJob::class, $appended->steps()->first()->class);
        $this->assertSame(RunStatus::PENDING, $appended->status);
    }


    public function test_applies_the_configured_items_middleware(): void {
        config()->set('dag-workflows.resolvable_items_middleware', TakeFirstMiddleware::class);

        $job = $this->makeResolver(
            items: fn() => ['x', 'y', 'z'],
            jobs: fn($item) => new ResolverTrackedJob(),
        );

        $this->handleJob($job);

        // TakeFirstMiddleware defaults to a single item, so only one task is appended.
        $appended = WorkflowTask::query()
            ->where(WorkflowTask::ATTRIBUTE_NAME, 'like', 'resolvable:%')
            ->pluck(WorkflowTask::ATTRIBUTE_NAME)
            ->toArray();

        $this->assertSame(['resolvable:0'], $appended);
    }


    public function test_rethrows_and_leaves_no_tasks_when_the_item_provider_fails(): void {
        $job = $this->makeResolver(
            items: fn() => throw new RuntimeException('provider boom'),
            jobs: fn($item) => new ResolverTrackedJob(),
        );

        try {
            $this->handleJob($job);
            $this->fail('Expected the resolver to rethrow.');
        } catch (RuntimeException $e) {
            $this->assertSame('provider boom', $e->getMessage());
        }

        $this->assertSame(0, WorkflowTask::query()->where(WorkflowTask::ATTRIBUTE_NAME, 'like', 'resolvable:%')->count());
    }
}
