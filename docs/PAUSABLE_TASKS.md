# Pausable Tasks

Workflows, tasks, and steps can be paused for manual intervention. This feature is essential when:
- An anomaly is detected that requires human review
- User approval is needed before continuing
- External validation is required
- Data quality checks fail and need manual inspection

## Table of Contents

- [Overview](#overview)
- [Pause Reason Hierarchy](#pause-reason-hierarchy)
- [Basic Usage](#basic-usage)
- [Job Example: Self-Pausing on Anomaly Detection](#job-example-self-pausing-on-anomaly-detection)
- [Events](#events)
- [Status Helpers](#status-helpers)
- [Best Practices](#best-practices)

## Overview

The pausable tasks feature introduces a `PAUSED` status to the workflow system. When an entity is paused:

1. **Steps**: The step execution halts; the job is not retried
2. **Tasks**: All active steps within the task are paused
3. **Workflows**: All active tasks and their steps are paused

Pausing cascades downward (workflow → tasks → steps), while resuming restores entities to their pre-pause state.

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

## Job Example: Self-Pausing on Anomaly Detection

Here's a complete example of a job that detects anomalies and pauses itself for manual review:

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

    public function __construct(
        private readonly int $batchId,
        private readonly float $anomalyThreshold = 0.15,
    ) {}

    public function handle(): void
    {
        $records = $this->fetchRecords();
        $totalRecords = count($records);
        $processedCount = 0;
        $anomalies = [];

        foreach ($records as $index => $record) {
            $result = $this->processRecord($record);

            if ($result['anomaly_score'] > $this->anomalyThreshold) {
                $anomalies[] = [
                    'record_id' => $record['id'],
                    'score' => $result['anomaly_score'],
                    'details' => $result['anomaly_details'],
                ];
            }

            $processedCount++;

            // Report progress (debounced to every 30s by default)
            $this->progress((int) (($processedCount / $totalRecords) * 100));
        }

        // Check if anomalies exceed acceptable threshold
        $anomalyRate = count($anomalies) / $totalRecords;

        if ($anomalyRate > 0.05) {
            // Store anomaly details for review
            cache()->put(
                "workflow:{$this->workflowTaskStep->workflow_id}:anomalies",
                $anomalies,
                now()->addDays(7)
            );

            // Pause the step for manual review
            $this->workflowTaskStep->pause(
                sprintf(
                    'High anomaly rate detected: %.1f%% (%d/%d records). Manual review required.',
                    $anomalyRate * 100,
                    count($anomalies),
                    $totalRecords
                )
            );

            return;
        }

        // Mark step as completed if no issues
        $this->progress(100, force: true);
    }

    private function fetchRecords(): array
    {
        // Your data fetching logic
        return [];
    }

    private function processRecord(array $record): array
    {
        // Your processing logic that returns anomaly detection results
        return [
            'anomaly_score' => 0.0,
            'anomaly_details' => null,
        ];
    }
}
```

### Using the Job in a Workflow

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
            name: 'process_data',
            jobs: [
                new ProcessDataJob(batchId: 1, anomalyThreshold: 0.10),
                new ProcessDataJob(batchId: 2, anomalyThreshold: 0.10),
                new ProcessDataJob(batchId: 3, anomalyThreshold: 0.10),
            ],
            dependsOn: 'fetch_data',
        ),
        new Task(
            name: 'aggregate_results',
            jobs: new AggregateResultsJob(),
            dependsOn: 'process_data',
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

Example controller for reviewing and managing paused workflows:

```php
<?php

namespace App\Http\Controllers;

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use Illuminate\Http\Request;

class WorkflowReviewController extends Controller
{
    public function index()
    {
        $pausedWorkflows = Workflow::query()
            ->where('status', RunStatus::PAUSED)
            ->with(['tasks' => fn($q) => $q->where('status', RunStatus::PAUSED)])
            ->orderBy('paused_at', 'desc')
            ->paginate(20);

        return view('workflows.review.index', compact('pausedWorkflows'));
    }

    public function show(Workflow $workflow)
    {
        $anomalies = cache()->get("workflow:{$workflow->id}:anomalies", []);

        $pausedSteps = $workflow->tasks()
            ->with(['steps' => fn($q) => $q->where('status', RunStatus::PAUSED)])
            ->get()
            ->pluck('steps')
            ->flatten();

        return view('workflows.review.show', compact('workflow', 'anomalies', 'pausedSteps'));
    }

    public function resume(Request $request, Workflow $workflow)
    {
        $request->validate([
            'resolution_notes' => 'nullable|string|max:1000',
        ]);

        // Log the resolution
        activity()
            ->performedOn($workflow)
            ->withProperties([
                'resolution_notes' => $request->resolution_notes,
                'resolved_by' => auth()->id(),
            ])
            ->log('Workflow resumed after review');

        // Clear cached anomalies
        cache()->forget("workflow:{$workflow->id}:anomalies");

        // Resume the workflow
        $workflow->resume();

        return redirect()
            ->route('workflows.review.index')
            ->with('success', "Workflow #{$workflow->id} resumed successfully.");
    }

    public function cancel(Request $request, Workflow $workflow)
    {
        $request->validate([
            'cancellation_reason' => 'required|string|max:1000',
        ]);

        activity()
            ->performedOn($workflow)
            ->withProperties([
                'cancellation_reason' => $request->cancellation_reason,
                'cancelled_by' => auth()->id(),
            ])
            ->log('Workflow cancelled after review');

        cache()->forget("workflow:{$workflow->id}:anomalies");

        $workflow->cancel();

        return redirect()
            ->route('workflows.review.index')
            ->with('success', "Workflow #{$workflow->id} cancelled.");
    }
}
```

## Events

The package dispatches events when workflows are paused, resumed, or cancelled:

| Event              | When Dispatched                              |
|--------------------|----------------------------------------------|
| `WorkflowPaused`   | When a workflow, task, or step is paused     |
| `WorkflowResumed`  | When a workflow, task, or step is resumed    |
| `WorkflowCancelled`| When a workflow, task, or step is cancelled  |

### Event Properties

```php
// WorkflowPaused
$event->workflow;  // Always available
$event->task;      // Available if task was paused
$event->step;      // Available if step was paused
$event->reason;    // The pause reason

// WorkflowResumed
$event->workflow;
$event->task;
$event->step;

// WorkflowCancelled
$event->workflow;
$event->task;
$event->step;
```

## Status Helpers

The `RunStatus` enum provides helper methods for working with pause states:

```php
use AdamczykPiotr\DagWorkflows\Enums\RunStatus;

$status = $workflow->status;

$status->isPaused();      // true if PAUSED
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
Workflow::query()
    ->where('status', RunStatus::PAUSED)
    ->where('paused_at', '<', now()->subHours(24))
    ->each(function ($workflow) {
        // Alert: Workflow has been paused for over 24 hours
        Alert::critical("Workflow {$workflow->id} paused for over 24 hours");
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
