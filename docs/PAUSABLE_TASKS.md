# Pausable Tasks

Workflows, tasks, and steps can be paused for manual intervention. This feature is essential when:
- An anomaly is detected that requires human review
- User approval is needed before continuing
- External validation is required
- Data quality checks fail and need manual inspection

## Table of Contents

- [Overview](#overview)
- [Status Types](#status-types)
- [Pause Reason Hierarchy](#pause-reason-hierarchy)
- [Basic Usage](#basic-usage)
- [How Step Pause Works](#how-step-pause-works)
- [Job Example: Self-Pausing on Anomaly Detection](#job-example-self-pausing-on-anomaly-detection)
- [Events](#events)
- [Status Helpers](#status-helpers)
- [Best Practices](#best-practices)

## Overview

The pausable tasks feature introduces two blocking statuses to the workflow system:

- **PAUSED** - The entity was explicitly paused and requires approval to continue
- **SUSPENDED** - The entity is blocked because an upstream dependency is paused

When a step or task is paused, the workflow **does not cascade upward**. Instead, it **suspends downstream** dependencies:
- Subsequent steps in the same task become SUSPENDED
- Dependant tasks and their steps become SUSPENDED
- The workflow and task can continue running other independent branches

## Status Types

| Status | Meaning |
|--------|---------|
| PENDING | Ready to run, waiting for dependencies |
| RUNNING | Currently executing |
| PAUSED | Explicitly paused, awaiting approval |
| SUSPENDED | Blocked by upstream PAUSED entity |
| COMPLETED | Successfully finished |
| FAILED | Execution failed |
| CANCELLED | Manually cancelled |

## Pause Reason Hierarchy

Pause reasons are stored at the **workflow** and **step** levels only:

| Entity   | Has `paused_at` | Has `pause_reason` |
|----------|-----------------|---------------------|
| Workflow | Yes             | Yes                 |
| Task     | Yes             | No                  |
| Step     | Yes             | Yes                 |

This design keeps the model lean while preserving the reason at the points where it matters most:
- The **workflow** level for high-level monitoring and notifications
- The **step** level for debugging and understanding exactly which job detected the issue

## Basic Usage

### Pausing

```php
use AdamczykPiotr\DagWorkflows\Models\Workflow;

$workflow = Workflow::find($id);

// Pause the entire workflow
$workflow->pause('Anomaly detected - awaiting manual review');

// Pause a specific task
$task = $workflow->tasks()->where('name', 'process_data')->first();
$task->pause('Data validation required');

// Pause a specific step
$step = $task->steps()->where('order', 2)->first();
$step->pause('Unusual pattern detected');
```

### Resuming

```php
// Resume from workflow level (resumes all paused tasks/steps)
$workflow->resume();

// Resume a specific task (resumes its paused steps)
$task->resume();

// Resume a specific step
$step->resume();
```

### Cancelling

```php
// Cancel the entire workflow
$workflow->cancel();

// Cancel a specific task (also cancels dependent tasks)
$task->cancel();

// Cancel a specific step (also cancels subsequent steps and dependent tasks)
$step->cancel();
```

## How Step Pause Works

When you call `$step->pause()` from within a running job:

1. **The current job continues to execute** - calling `pause()` does not stop PHP execution
2. **The step is marked as PAUSED** - the middleware detects this and does not auto-complete the step
3. **Subsequent steps are SUSPENDED** - they won't run until the step is approved
4. **Dependant tasks are SUSPENDED** - downstream tasks wait for approval
5. **Workflow stays RUNNING** - other independent branches continue executing

When you call `$step->resume()` to approve:

1. **The step is marked COMPLETED** - the job already ran successfully
2. **Suspended steps become PENDING** - ready to be dispatched
3. **Suspended tasks become PENDING** - ready to run when dependencies complete
4. **The next step is dispatched** - workflow continues

**Important**: Since you cannot re-run a paused step, jobs that need approval should be **split into two steps**:
1. **Validation job** - analyzes data and calls `pause()` if issues are found
2. **Processing job** - runs after approval, does the actual work

## Job Example: Self-Pausing on Anomaly Detection

Here's a complete example using two jobs - one for validation that can pause, and one for processing:

### Step 1: Validation Job (can pause)

```php
<?php

namespace App\Jobs;

use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ValidateDataJob implements ShouldQueue
{
    use HasWorkflowTracking, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $batchId,
        private readonly float $anomalyThreshold = 0.05,
    ) {}

    public function handle(): void
    {
        $records = $this->fetchRecords();
        $anomalies = $this->detectAnomalies($records);
        $anomalyRate = count($anomalies) / count($records);

        if ($anomalyRate > $this->anomalyThreshold) {
            // Store anomaly details for review
            cache()->put(
                "workflow:{$this->workflowTaskStep->workflow_id}:anomalies",
                $anomalies,
                now()->addDays(7)
            );

            // Pause the step - job continues but downstream is blocked
            $this->workflowTaskStep->pause(
                sprintf(
                    'High anomaly rate: %.1f%% (%d/%d records). Review required.',
                    $anomalyRate * 100,
                    count($anomalies),
                    count($records)
                )
            );

            // Job continues executing and finishes normally
            // But the step will be PAUSED, not COMPLETED
            return;
        }

        // No issues - step will auto-complete and next step runs
    }

    private function fetchRecords(): array { /* ... */ return []; }
    private function detectAnomalies(array $records): array { /* ... */ return []; }
}
```

### Step 2: Processing Job (runs after approval)

```php
<?php

namespace App\Jobs;

use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDataJob implements ShouldQueue
{
    use HasWorkflowTracking, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $batchId) {}

    public function handle(): void
    {
        // This job only runs after validation is approved
        $records = $this->fetchRecords();
        
        foreach ($records as $record) {
            $this->processRecord($record);
        }
    }
    
    private function fetchRecords(): array { /* ... */ return []; }
    private function processRecord(array $record): void { /* ... */ }
}
```

### Using the Jobs in a Workflow

```php
use AdamczykPiotr\DagWorkflows\Definitions\Task;
use AdamczykPiotr\DagWorkflows\Definitions\Workflow;

$workflow = new Workflow(
    name: 'Data Processing Pipeline',
    tasks: [
        new Task(
            name: 'fetch_data',
            jobs: new FetchDataJob(),
        ),
        new Task(
            name: 'validate_and_process',
            jobs: [
                // Step 1: Validation - can pause if issues found
                new ValidateDataJob(batchId: 1),
                // Step 2: Processing - only runs after validation is approved
                new ProcessDataJob(batchId: 1),
            ],
            dependsOn: 'fetch_data',
        ),
        new Task(
            name: 'aggregate_results',
            jobs: new AggregateResultsJob(),
            dependsOn: 'validate_and_process',
        ),
    ],
);

$model = $workflow->dispatch();
```

### Handling Paused Workflows

Create an event listener to notify administrators when a workflow pauses:

```php
<?php

namespace App\Listeners;

use AdamczykPiotr\DagWorkflows\Events\WorkflowPaused;
use App\Notifications\WorkflowNeedsReview;
use Illuminate\Support\Facades\Notification;

class NotifyOnWorkflowPause
{
    public function handle(WorkflowPaused $event): void
    {
        $admins = User::where('role', 'admin')->get();

        Notification::send($admins, new WorkflowNeedsReview(
            workflowId: $event->workflow->id,
            workflowName: $event->workflow->name,
            taskName: $event->task?->name,
            stepOrder: $event->step?->order,
            reason: $event->reason,
        ));
    }
}
```

Register the listener in your `EventServiceProvider`:

```php
use AdamczykPiotr\DagWorkflows\Events\WorkflowPaused;
use App\Listeners\NotifyOnWorkflowPause;

protected $listen = [
    WorkflowPaused::class => [
        NotifyOnWorkflowPause::class,
    ],
];
```

### Building a Review Interface

Example controller for reviewing and managing paused steps:

```php
<?php

namespace App\Http\Controllers;

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use Illuminate\Http\Request;

class WorkflowReviewController extends Controller
{
    public function index()
    {
        // Find all paused steps awaiting approval
        $pausedSteps = WorkflowTaskStep::query()
            ->where('status', RunStatus::PAUSED)
            ->with(['task.workflow'])
            ->orderBy('paused_at', 'desc')
            ->paginate(20);

        return view('workflows.review.index', compact('pausedSteps'));
    }

    public function show(WorkflowTaskStep $step)
    {
        $workflow = $step->task->workflow;
        $anomalies = cache()->get("workflow:{$workflow->id}:anomalies", []);

        // Show suspended downstream
        $suspendedSteps = $step->task->steps()
            ->where('status', RunStatus::SUSPENDED)
            ->get();

        return view('workflows.review.show', compact('step', 'workflow', 'anomalies', 'suspendedSteps'));
    }

    public function approve(Request $request, WorkflowTaskStep $step)
    {
        $request->validate([
            'resolution_notes' => 'nullable|string|max:1000',
        ]);

        $workflow = $step->task->workflow;

        // Log the approval
        activity()
            ->performedOn($step)
            ->withProperties([
                'resolution_notes' => $request->resolution_notes,
                'approved_by' => auth()->id(),
            ])
            ->log('Step approved after review');

        // Clear cached anomalies
        cache()->forget("workflow:{$workflow->id}:anomalies");

        // Resume the step - marks it COMPLETED and unsuspends downstream
        $step->resume();

        return redirect()
            ->route('workflows.review.index')
            ->with('success', "Step approved. Workflow continuing.");
    }

    public function reject(Request $request, WorkflowTaskStep $step)
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $workflow = $step->task->workflow;

        activity()
            ->performedOn($step)
            ->withProperties([
                'rejection_reason' => $request->rejection_reason,
                'rejected_by' => auth()->id(),
            ])
            ->log('Step rejected - cancelling');

        cache()->forget("workflow:{$workflow->id}:anomalies");

        // Cancel the step - also cancels subsequent steps and dependant tasks
        $step->cancel();

        return redirect()
            ->route('workflows.review.index')
            ->with('success', "Step rejected and cancelled.");
    }
}
```

## Events

The package dispatches events when steps are paused, resumed, or cancelled:

| Event              | When Dispatched           |
|--------------------|---------------------------|
| `WorkflowPaused`   | When a step is paused     |
| `WorkflowResumed`  | When a step is resumed    |
| `WorkflowCancelled`| When a step is cancelled  |

**Note:** Events are only dispatched for step-level operations. Workflow and task level pause/resume/cancel do not dispatch events.

### Event Properties

```php
// WorkflowPaused
$event->step;    // The step that was paused
$event->reason;  // The pause reason

// WorkflowResumed
$event->step;    // The step that was resumed

// WorkflowCancelled
$event->step;    // The step that was cancelled

// Access task and workflow via step relationships
$task = $event->step->task;
$workflow = $event->step->task->workflow;
```

## Status Helpers

The `RunStatus` enum provides helper methods for working with pause states:

```php
use AdamczykPiotr\DagWorkflows\Enums\RunStatus;

$status = $step->status;

$status->isBlocked();     // true if PAUSED or SUSPENDED
$status->canBePaused();   // true if PENDING or RUNNING
$status->canBeResumed();  // true if PAUSED
$status->isTerminal();    // true if COMPLETED, FAILED, or CANCELLED
```

## Best Practices

### 1. Be Specific with Pause Reasons

```php
// Good: Specific and actionable
$step->pause('Duplicate customer detected: ID 12345 matches existing ID 67890. Manual merge required.');

// Bad: Vague and unhelpful
$step->pause('Error found');
```

### 2. Store Context for Review

```php
// Store detailed context that reviewers will need
cache()->put("workflow:{$workflowId}:context", [
    'anomalies' => $anomalies,
    'processed_records' => $processedCount,
    'sample_data' => $sampleRecords,
    'detection_timestamp' => now(),
], now()->addDays(7));

$step->pause('Anomaly detected. See cached context for details.');
```

### 3. Set Up Monitoring

```php
// In your monitoring/alerting system
WorkflowTaskStep::query()
    ->where('status', RunStatus::PAUSED)
    ->where('paused_at', '<', now()->subHours(24))
    ->with('task.workflow')
    ->each(function ($step) {
        // Alert: Step has been paused for over 24 hours
        Alert::critical("Step {$step->id} in workflow {$step->task->workflow->name} paused for over 24 hours");
    });
```

### 4. Use Appropriate Granularity

- Pause at the **step level** when only one job in a task needs review
- Pause at the **task level** when all steps in a task should wait
- Pause at the **workflow level** when the entire pipeline should halt

### 5. Handle Resume Gracefully

Jobs should be idempotent and handle being re-run after a pause:

```php
public function handle(): void
{
    // Check if we've already processed some records (resuming from pause)
    $checkpoint = cache()->get("job:{$this->workflowTaskStep->id}:checkpoint");
    
    $records = $this->fetchRecords();
    
    foreach ($records as $index => $record) {
        if ($checkpoint && $index < $checkpoint) {
            continue; // Skip already processed records
        }
        
        $this->processRecord($record);
        
        // Save checkpoint periodically
        if ($index % 100 === 0) {
            cache()->put("job:{$this->workflowTaskStep->id}:checkpoint", $index, now()->addDay());
        }
    }
    
    // Clean up checkpoint on completion
    cache()->forget("job:{$this->workflowTaskStep->id}:checkpoint");
}
```
