<?php

use AdamczykPiotr\DagWorkflows\Definitions\Task;
use AdamczykPiotr\DagWorkflows\Definitions\TaskGroup;
use AdamczykPiotr\DagWorkflows\Dto\TaskDto;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskCircularDependencyException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskDuplicateNameException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskMissingTrackingTraitException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskUnresolvedDependencyException;
use AdamczykPiotr\DagWorkflows\Exceptions\WorkflowTaskWithoutJobException;
use AdamczykPiotr\DagWorkflows\Services\WorkflowDefinitionParser;
use AdamczykPiotr\DagWorkflows\Tests\TestCase;
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class ParserTrackedJob implements ShouldQueue {
    use HasWorkflowTracking, InteractsWithQueue, Queueable;
    public function handle(): void {}
}

// A job whose tracking trait is inherited from a parent (exercises usesTrait's
// parent-class walk).
class ParserChildJob extends ParserTrackedJob {}

// A job that does NOT use the HasWorkflowTracking trait.
class ParserUntrackedJob implements ShouldQueue {
    use InteractsWithQueue, Queueable;
    public function handle(): void {}
}

class WorkflowDefinitionParserTest extends TestCase {

    private function parser(): WorkflowDefinitionParser {
        return resolve(WorkflowDefinitionParser::class);
    }


    private function definition(array $tasks): \AdamczykPiotr\DagWorkflows\Definitions\Workflow {
        return new \AdamczykPiotr\DagWorkflows\Definitions\Workflow('wf', $tasks);
    }


    private function taskByName(\AdamczykPiotr\DagWorkflows\Dto\WorkflowDto $dto, string $name): TaskDto {
        return $dto->tasks->firstWhere(fn(TaskDto $t) => $t->name === $name);
    }


    // --- happy path ---

    public function test_parses_a_task_into_ordered_steps(): void {
        $dto = $this->parser()->parse($this->definition([
            new Task('a', [new ParserTrackedJob(), new ParserTrackedJob()]),
        ]));

        $this->assertSame('wf', $dto->name);
        $this->assertCount(1, $dto->tasks);

        $task = $this->taskByName($dto, 'a');
        $this->assertCount(2, $task->steps);
        $this->assertSame(1, $task->steps[0]->order);
        $this->assertSame(2, $task->steps[1]->order);
        $this->assertInstanceOf(ParserTrackedJob::class, $task->steps[0]->job);
    }


    public function test_preserves_dependencies_between_tasks(): void {
        $dto = $this->parser()->parse($this->definition([
            new Task('a', new ParserTrackedJob()),
            new Task('b', new ParserTrackedJob(), dependsOn: 'a'),
        ]));

        $this->assertSame(['a'], $this->taskByName($dto, 'b')->dependsOn->toArray());
        $this->assertSame([], $this->taskByName($dto, 'a')->dependsOn->toArray());
    }


    public function test_accepts_a_job_that_inherits_the_tracking_trait_from_a_parent(): void {
        $dto = $this->parser()->parse($this->definition([
            new Task('a', new ParserChildJob()),
        ]));

        $this->assertCount(1, $dto->tasks);
        $this->assertInstanceOf(ParserChildJob::class, $this->taskByName($dto, 'a')->steps[0]->job);
    }


    // --- task groups ---

    public function test_task_group_merges_its_dependencies_into_every_member(): void {
        $dto = $this->parser()->parse($this->definition([
            new Task('x', new ParserTrackedJob()),
            new TaskGroup(
                tasks: [
                    new Task('a', new ParserTrackedJob()),
                    new Task('b', new ParserTrackedJob(), dependsOn: 'x'),
                ],
                dependsOn: 'x',
            ),
        ]));

        $this->assertContains('x', $this->taskByName($dto, 'a')->dependsOn->toArray());
        // Member's own dependency is not duplicated when it matches the group's.
        $this->assertSame(['x'], $this->taskByName($dto, 'b')->dependsOn->toArray());
    }


    // --- validation failures ---

    public function test_throws_when_a_task_has_no_jobs(): void {
        $this->expectException(WorkflowTaskWithoutJobException::class);

        $this->parser()->parse($this->definition([
            new Task('a', []),
        ]));
    }


    public function test_throws_when_a_job_is_missing_the_tracking_trait(): void {
        $this->expectException(WorkflowTaskMissingTrackingTraitException::class);

        $this->parser()->parse($this->definition([
            new Task('a', new ParserUntrackedJob()),
        ]));
    }


    public function test_throws_on_duplicate_task_names(): void {
        $this->expectException(WorkflowTaskDuplicateNameException::class);

        $this->parser()->parse($this->definition([
            new Task('dup', new ParserTrackedJob()),
            new Task('dup', new ParserTrackedJob()),
        ]));
    }


    public function test_throws_on_a_dependency_that_does_not_exist(): void {
        $this->expectException(WorkflowTaskUnresolvedDependencyException::class);

        $this->parser()->parse($this->definition([
            new Task('a', new ParserTrackedJob(), dependsOn: 'ghost'),
        ]));
    }


    public function test_throws_on_a_direct_circular_dependency(): void {
        $this->expectException(WorkflowTaskCircularDependencyException::class);

        $this->parser()->parse($this->definition([
            new Task('a', new ParserTrackedJob(), dependsOn: 'b'),
            new Task('b', new ParserTrackedJob(), dependsOn: 'a'),
        ]));
    }


    public function test_throws_on_a_self_referencing_dependency(): void {
        $this->expectException(WorkflowTaskCircularDependencyException::class);

        $this->parser()->parse($this->definition([
            new Task('a', new ParserTrackedJob(), dependsOn: 'a'),
        ]));
    }


    public function test_circular_dependency_message_includes_the_cycle_path(): void {
        try {
            $this->parser()->parse($this->definition([
                new Task('a', new ParserTrackedJob(), dependsOn: 'b'),
                new Task('b', new ParserTrackedJob(), dependsOn: 'a'),
            ]));
            $this->fail('Expected a circular dependency exception.');
        } catch (WorkflowTaskCircularDependencyException $e) {
            $this->assertStringContainsString('a -> b -> a', $e->getMessage());
        }
    }
}
