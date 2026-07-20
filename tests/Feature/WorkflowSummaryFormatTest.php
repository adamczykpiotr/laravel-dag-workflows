<?php

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Http\Controllers\WorkflowController;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

/**
 * The workflow endpoint defaults to a cheap summary payload (status counts +
 * completion percentages, no tasks/steps tree); ?format=full returns the full
 * resource exactly as before.
 */
class WorkflowSummaryFormatTest extends TestCase {

    use InteractsWithStatusFlow;

    protected function setUp(): void {
        parent::setUp();

        Queue::fake();
        StatusFlowJob::$behaviours = [];
    }


    protected function tearDown(): void {
        StatusFlowJob::$behaviours = [];

        parent::tearDown();
    }


    /**
     * @param Workflow $workflow
     * @param string|null $format
     * @return array<string, mixed>
     */
    private function payload(Workflow $workflow, ?string $format = null): array {
        $uri = "/workflows/{$workflow->id}" . ($format !== null ? "?format={$format}" : '');
        $response = (new WorkflowController())->show(Request::create($uri), $workflow->id);

        return json_decode($response->getContent(), true);
    }


    /**
     * Diamond with mixed progress: b/c done, d never released because c failed.
     *
     * @return Workflow
     */
    private function makeSampleWorkflow(): Workflow {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 2],
            'b' => ['deps' => ['a'], 'steps' => 1],
            'c' => ['deps' => ['a'], 'steps' => 1],
            'd' => ['deps' => ['b', 'c'], 'steps' => 1],
        ]);
        StatusFlowJob::$behaviours[$steps['c'][1]->id] = 'fail';

        $this->runWorkflow($workflow);

        return $workflow->refresh();
    }


    public function test_defaults_to_the_summary_payload_without_the_task_tree(): void {
        $payload = $this->payload($this->makeSampleWorkflow());

        $this->assertArrayNotHasKey('tasks', $payload);
        $this->assertSame(RunStatus::FAILED->value, $payload['status']);
        $this->assertArrayHasKey('durationSeconds', $payload);
    }


    public function test_summary_counts_task_and_step_statuses(): void {
        $payload = $this->payload($this->makeSampleWorkflow());

        // a, b completed; c failed; d cancelled (dependant of the failed branch).
        $this->assertSame(
            [RunStatus::CANCELLED->value => 1, RunStatus::COMPLETED->value => 2, RunStatus::FAILED->value => 1],
            collect($payload['taskStatuses'])->sortKeys()->toArray()
        );

        // 3 completed steps (a×2, b), 1 failed (c), 1 cancelled (d).
        $this->assertSame(
            [RunStatus::CANCELLED->value => 1, RunStatus::COMPLETED->value => 3, RunStatus::FAILED->value => 1],
            collect($payload['stepStatuses'])->sortKeys()->toArray()
        );
    }


    public function test_summary_reports_completion_percentages(): void {
        $payload = $this->payload($this->makeSampleWorkflow());

        $this->assertSame(50.0, (float) $payload['taskCompletionPercentage']);  // 2 of 4
        $this->assertSame(60.0, (float) $payload['stepCompletionPercentage']);  // 3 of 5
    }


    public function test_skipped_steps_count_as_done_in_the_percentage(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 4],
        ]);
        StatusFlowJob::$behaviours[$steps['a'][2]->id] = 'early';

        $this->runWorkflow($workflow);
        $payload = $this->payload($workflow->refresh());

        // 2 completed + 2 skipped by the early completion = fully done.
        $this->assertSame(100.0, (float) $payload['stepCompletionPercentage']);
        $this->assertSame(100.0, (float) $payload['taskCompletionPercentage']);
    }


    public function test_step_progress_percentage_credits_running_steps_fractionally(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 1],
            'b' => ['steps' => 1],
            'c' => ['steps' => 1],
            'd' => ['steps' => 1],
        ]);

        $steps['a'][1]->status = RunStatus::COMPLETED;
        $steps['a'][1]->save();
        $steps['b'][1]->status = RunStatus::RUNNING;
        $steps['b'][1]->progress = 50;
        $steps['b'][1]->save();
        $steps['c'][1]->status = RunStatus::RUNNING;  // no self-reported progress
        $steps['c'][1]->save();

        $payload = $this->payload($workflow);

        // (100 + 50 + 0 + 0) / 4
        $this->assertSame(37.5, (float) $payload['stepProgressPercentage']);
    }


    public function test_format_full_returns_the_task_tree(): void {
        $payload = $this->payload($this->makeSampleWorkflow(), format: 'full');

        $this->assertArrayHasKey('tasks', $payload);
        $this->assertCount(4, $payload['tasks']);
        $this->assertArrayNotHasKey('taskStatuses', $payload);
    }


    public function test_format_failed_returns_the_full_shape_with_only_failed_tasks(): void {
        $payload = $this->payload($this->makeSampleWorkflow(), format: 'failed');

        $this->assertArrayNotHasKey('taskStatuses', $payload);
        $this->assertSame(RunStatus::FAILED->value, $payload['status']);

        // Only task c failed — b, a completed and d was cancelled, none of them listed.
        $this->assertCount(1, $payload['tasks']);
        $failedTask = $payload['tasks'][0];
        $this->assertSame('c', $failedTask['name']);
        $this->assertNotNull($failedTask['failedAt']);

        $this->assertCount(1, $failedTask['steps']);
        $failedStep = $failedTask['steps'][0];
        $this->assertSame(StatusFlowJob::class, $failedStep['class']);
        $this->assertSame(RunStatus::FAILED->value, $failedStep['status']);
        $this->assertNotNull($failedStep['failedAt']);
    }


    public function test_format_failed_includes_all_steps_of_a_failed_task(): void {
        [$workflow, $tasks, $steps] = $this->buildWorkflow([
            'a' => ['steps' => 3],
        ]);
        StatusFlowJob::$behaviours[$steps['a'][2]->id] = 'fail';

        $this->runWorkflow($workflow);
        $payload = $this->payload($workflow->refresh(), format: 'failed');

        // Step 1 completed before step 2 failed; step 3 never ran — all three listed.
        $statuses = collect($payload['tasks'][0]['steps'])->pluck('status', 'order')->toArray();
        $this->assertSame(RunStatus::COMPLETED->value, $statuses[1]);
        $this->assertSame(RunStatus::FAILED->value, $statuses[2]);
        $this->assertCount(3, $statuses);
    }


    public function test_format_failed_returns_no_tasks_when_nothing_failed(): void {
        [$workflow] = $this->buildWorkflow([
            'a' => ['steps' => 1],
            'b' => ['deps' => ['a'], 'steps' => 1],
        ]);

        $this->runWorkflow($workflow);
        $payload = $this->payload($workflow->refresh(), format: 'failed');

        $this->assertSame(RunStatus::COMPLETED->value, $payload['status']);
        $this->assertSame([], $payload['tasks']);
    }
}
