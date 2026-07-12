<?php

use AdamczykPiotr\DagWorkflows\Definitions\ResolvableTask;
use AdamczykPiotr\DagWorkflows\Definitions\Task;
use AdamczykPiotr\DagWorkflows\Definitions\Workflow as WorkflowDefinition;
use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskCircularDependencyException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskReservedCharacterException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskUnresolvedDependencyException;
use AdamczykPiotr\DagWorkflows\Jobs\ResolvableTaskResolverJob;
use AdamczykPiotr\DagWorkflows\Middlewares\ResolvableItems\PassthroughMiddleware;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTask;
use AdamczykPiotr\DagWorkflows\Services\WorkflowDefinitionParser;
use AdamczykPiotr\DagWorkflows\Services\WorkflowDispatcher;
use AdamczykPiotr\DagWorkflows\Services\WorkflowRepository;
use AdamczykPiotr\DagWorkflows\Tests\TestCase;
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Queue;

class DynamicDepsTrackedJob implements ShouldQueue {
    use HasWorkflowTracking, InteractsWithQueue, Queueable;
    public function handle(): void {}
}

// Distinct class for the dynamically-dependant task so queue assertions can
// tell it apart from the jobs of the spawned tasks.
class DynamicDepsSinkJob implements ShouldQueue {
    use HasWorkflowTracking, InteractsWithQueue, Queueable;
    public function handle(): void {}
}

/**
 * Covers dynamic dependencies: a dependsOn entry with a trailing wildcard
 * ("src:*") gates a task on the base task AND on every task it spawns at
 * runtime (a ResolvableTask's children), which do not exist at store time.
 */
class DynamicDependenciesTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        config()->set('dag-workflows.resolvable_items_middleware', PassthroughMiddleware::class);
        Queue::fake();
    }


    private function parser(): WorkflowDefinitionParser {
        return resolve(WorkflowDefinitionParser::class);
    }


    private function dispatcher(): WorkflowDispatcher {
        return resolve(WorkflowDispatcher::class);
    }


    /**
     * Resolvable task "src" spawning one child per item, and a "sink" task that
     * dynamically depends on it.
     *
     * @param callable|null $items
     * @return Workflow
     */
    private function dispatchSample(?callable $items = null): Workflow {
        $items ??= fn() => ['x' => 'x', 'y' => 'y'];

        return (new WorkflowDefinition('wf', [
            new ResolvableTask(
                name: 'src',
                items: $items,
                jobs: fn($item) => new DynamicDepsTrackedJob(),
            ),
            new Task('sink', new DynamicDepsSinkJob(), dependsOn: 'src:*'),
        ]))->dispatch();
    }


    private function taskByName(Workflow $model, string $name): WorkflowTask {
        return $model->tasks()->where(WorkflowTask::ATTRIBUTE_NAME, $name)->firstOrFail();
    }


    /**
     * Run a resolvable task's resolver the way a queue worker would: from its
     * persisted step payload.
     *
     * @param Workflow $model
     * @param string $taskName
     */
    private function runResolver(Workflow $model, string $taskName): void {
        $step = $this->taskByName($model, $taskName)->initialStep;

        /** @var ResolvableTaskResolverJob $job */
        $job = unserialize(base64_decode($step->payload));
        $job->workflowTaskStep = $step;

        $job->handle($this->parser(), resolve(WorkflowRepository::class));
    }


    private function completeTaskAndDispatchDependants(Workflow $model, string $taskName): void {
        $task = $this->taskByName($model, $taskName);
        $task->status = RunStatus::COMPLETED;
        $task->completed_at = now();
        $task->save();

        $this->dispatcher()->dispatchDependantTasks($task);
    }


    // --- parsing ---

    public function test_parser_rejects_a_dynamic_dependency_whose_base_task_does_not_exist(): void {
        $this->expectException(WorkflowTaskUnresolvedDependencyException::class);

        $this->parser()->parse(new WorkflowDefinition('wf', [
            new Task('a', new DynamicDepsTrackedJob()),
            new Task('b', new DynamicDepsTrackedJob(), dependsOn: 'missing:*'),
        ]));
    }


    public function test_parser_accepts_a_dynamic_dependency_on_an_existing_task(): void {
        $dto = $this->parser()->parse(new WorkflowDefinition('wf', [
            new ResolvableTask('src', items: fn() => [], jobs: fn($item) => new DynamicDepsTrackedJob()),
            new Task('sink', new DynamicDepsSinkJob(), dependsOn: 'src:*'),
        ]));

        $sink = $dto->tasks->firstWhere(fn($task) => $task->name === 'sink');
        $this->assertSame(['src:*'], $sink->dependsOn->toArray());
    }


    public function test_parser_rejects_a_task_name_containing_the_reserved_wildcard_character(): void {
        $this->expectException(WorkflowTaskReservedCharacterException::class);

        $this->parser()->parse(new WorkflowDefinition('wf', [
            new Task('a*b', new DynamicDepsTrackedJob()),
        ]));
    }


    public function test_parser_detects_a_cycle_through_a_dynamic_dependency(): void {
        $this->expectException(WorkflowTaskCircularDependencyException::class);

        $this->parser()->parse(new WorkflowDefinition('wf', [
            new Task('a', new DynamicDepsTrackedJob(), dependsOn: 'b'),
            new Task('b', new DynamicDepsTrackedJob(), dependsOn: 'a:*'),
        ]));
    }


    // --- storing ---

    public function test_store_persists_the_dynamic_dependency_and_gates_on_the_base_task(): void {
        $model = $this->dispatchSample();

        $src = $this->taskByName($model, 'src');
        $sink = $this->taskByName($model, 'sink');

        $this->assertSame(['src:*'], $sink->dynamic_dependencies);
        $this->assertNull($src->dynamic_dependencies);

        // Before the resolver runs, the sink is gated on the base task only —
        // enough to keep it out of the entrypoint set.
        $this->assertSame([$src->id], $sink->dependencies()->pluck(WorkflowTask::ATTRIBUTE_ID)->toArray());
        Queue::assertNotPushed(DynamicDepsSinkJob::class);
    }


    // --- resolving ---

    public function test_resolver_wires_spawned_tasks_into_stored_dynamic_dependants(): void {
        $model = $this->dispatchSample();

        $this->runResolver($model, 'src');

        $dependencyNames = $this->taskByName($model, 'sink')
            ->dependencies()
            ->pluck(WorkflowTask::ATTRIBUTE_NAME)
            ->sort()
            ->values()
            ->toArray();

        $this->assertSame(['src', 'src:x', 'src:y'], $dependencyNames);
    }


    public function test_dynamic_dependant_waits_for_every_spawned_task(): void {
        $model = $this->dispatchSample();
        $this->runResolver($model, 'src');

        $this->completeTaskAndDispatchDependants($model, 'src');
        Queue::assertNotPushed(DynamicDepsSinkJob::class);

        $this->completeTaskAndDispatchDependants($model, 'src:x');
        Queue::assertNotPushed(DynamicDepsSinkJob::class);

        $this->completeTaskAndDispatchDependants($model, 'src:y');
        Queue::assertPushed(DynamicDepsSinkJob::class, 1);
    }


    public function test_dynamic_dependant_runs_after_the_base_task_when_nothing_is_spawned(): void {
        $model = $this->dispatchSample(items: fn() => []);
        $this->runResolver($model, 'src');

        $this->completeTaskAndDispatchDependants($model, 'src');

        Queue::assertPushed(DynamicDepsSinkJob::class, 1);
    }


    // --- resolvable dependency inheritance ---

    public function test_spawned_tasks_inherit_every_static_dependency_of_their_resolvable(): void {
        $model = (new WorkflowDefinition('wf', [
            new Task('a', new DynamicDepsTrackedJob()),
            new Task('b', new DynamicDepsTrackedJob()),
            new ResolvableTask(
                name: 'src',
                items: fn() => ['x' => 'x'],
                jobs: fn($item) => new DynamicDepsTrackedJob(),
                dependsOn: ['a', 'b'],
            ),
        ]))->dispatch();

        $this->runResolver($model, 'src');

        $dependencyNames = $this->taskByName($model, 'src:x')
            ->dependencies()
            ->pluck(WorkflowTask::ATTRIBUTE_NAME)
            ->sort()
            ->values()
            ->toArray();

        $this->assertSame(['a', 'b', 'src'], $dependencyNames);
    }
}
