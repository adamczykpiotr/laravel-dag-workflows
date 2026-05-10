# dag-workflows

[![Latest Version on Packagist](https://img.shields.io/packagist/v/adamczykpiotr/laravel-dag-workflows.svg?style=flat-square)](https://packagist.org/packages/adamczykpiotr/laravel-dag-workflows)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/adamczykpiotr/laravel-dag-workflows/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/adamczykpiotr/laravel-dag-workflows/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/adamczykpiotr/laravel-dag-workflows/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/adamczykpiotr/laravel-dag-workflows/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/adamczykpiotr/laravel-dag-workflows.svg?style=flat-square)](https://packagist.org/packages/adamczykpiotr/laravel-dag-workflows)

A lightweight library to define and dispatch directed acyclic graph (DAG) based workflows composed of Tasks and TaskGroups. Each Task can contain one or more jobs and may declare
dependencies on other tasks. This package helps model, persist and execute complex multi-step workflows in Laravel applications.

Key features:

- Expressive workflow definitions using `Workflow`, `Task` and `TaskGroup` building blocks
- Support for single and grouped tasks
- Task dependencies and ordering
- Easy dispatching and inspection via Eloquent models
- Per-step progress reporting with built-in debounce
- Pausable tasks for manual intervention (anomaly detection, user approval, etc.)
- Events for workflow state changes (paused, resumed, cancelled)

## Installation

Install the package via Composer and run migrations:

```bash
composer require adamczykpiotr/laravel-dag-workflows
php artisan migrate
```

Migrations ship with the package and run in place. If you want to customise the
schema, publish them first: `php artisan vendor:publish --tag="dag-workflows-migrations"`.

## Usage

Below is a concise example showing how to define and dispatch a workflow. This example mirrors the structure of the included tinker snippet but models an "Image Import Pipeline":

```php
<?php

use AdamczykPiotr\DagWorkflows\Definitions\Task;
use AdamczykPiotr\DagWorkflows\Definitions\TaskGroup;
use AdamczykPiotr\DagWorkflows\Definitions\Workflow;

const TASK_FETCH_FEEDS = 'fetch_feeds';
const TASK_PARSE_CATALOGS = 'parse_catalogs';
const TASK_PARSE_ALBUMS = 'parse_albums';
const TASK_PARSE_IMAGES = 'parse_images';
const TASK_PARSE_METADATA = 'parse_metadata';

const TASK_SYNC_CATALOG_ALBUM_RELATIONS = 'sync_catalog_album_relations';
const TASK_SYNC_ALBUM_IMAGE_RELATIONS = 'sync_album_image_relations';
const TASK_SYNC_IMAGE_METADATA_RELATIONS = 'sync_image_metadata_relations';

$workflow = new Workflow(
    name: 'Image Import Pipeline',
    tasks: [
        new Task(
            name: TASK_FETCH_FEEDS,
            jobs: new DownloadFeedsJob(),
        ),

        new TaskGroup(
            tasks: [
                new Task(
                    name: TASK_PARSE_CATALOGS,
                    jobs: [
                        new ParseCatalogsJob('source-a'),
                        new ParseCatalogsJob('source-b'),
                    ],
                ),

                new Task(
                    name: TASK_PARSE_ALBUMS,
                    jobs: new ParseAlbumsJob(),
                ),

                new Task(
                    name: TASK_PARSE_IMAGES,
                    jobs: new ParseImagesJob(),
                ),

                new Task(
                    name: TASK_PARSE_METADATA,
                    jobs: new ParseImageMetadataJob(),
                ),
            ],
            dependsOn: TASK_FETCH_FEEDS,
        ),

        new Task(
            name: TASK_SYNC_CATALOG_ALBUM_RELATIONS,
            jobs: new SyncCatalogAlbumRelationsJob(),
            dependsOn: [TASK_PARSE_CATALOGS, TASK_PARSE_ALBUMS],
        ),

        new Task(
            name: TASK_SYNC_ALBUM_IMAGE_RELATIONS,
            jobs: new SyncAlbumImageRelationsJob(),
            dependsOn: [TASK_PARSE_ALBUMS, TASK_SYNC_CATALOG_ALBUM_RELATIONS],
        ),

        new Task(
            name: TASK_SYNC_IMAGE_METADATA_RELATIONS,
            jobs: new SyncImageMetadataRelationsJob(),
            dependsOn: [TASK_PARSE_IMAGES, TASK_SYNC_ALBUM_IMAGE_RELATIONS],
        ),
    ],
);

$model = $workflow->dispatch();
dump($model->id);
```

## Reporting progress

Jobs using `HasWorkflowTracking` can call `$this->progress(int $percentage)` (0–100).
Writes are debounced against the step row's `updated_at` (30s window); `100` and `progress(..., force: true)` always write.

## Pausable Tasks

Workflows, tasks, and steps can be paused for manual intervention. This is useful when:
- An anomaly is detected that requires human review
- User approval is needed before continuing
- External validation is required

### Pausing

```php
use AdamczykPiotr\DagWorkflows\Models\Workflow;

$workflow = Workflow::find($id);

// Pause the entire workflow (pauses all active tasks and steps)
$workflow->pause('Anomaly detected - awaiting manual review');

// Or pause a specific task
$task = $workflow->tasks()->where('name', 'process_data')->first();
$task->pause('Data validation required');

// Or pause a specific step
$step = $task->steps()->where('order', 2)->first();
$step->pause('Unusual pattern detected');
```

### Resuming

```php
// Resume from workflow level
$workflow->resume();

// Or resume a specific task
$task->resume();

// Or resume a specific step
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

### Status Helpers

```php
use AdamczykPiotr\DagWorkflows\Enums\RunStatus;

// Check if paused
if ($workflow->status === RunStatus::PAUSED) {
    // Handle paused state
}

// Or use helper methods
$workflow->status->isPaused();    // true if PAUSED
$workflow->status->canBePaused(); // true if PENDING or RUNNING
$workflow->status->canBeResumed(); // true if PAUSED
$workflow->status->isTerminal();  // true if COMPLETED, FAILED, or CANCELLED
```

## Events

The package dispatches events when workflows are paused, resumed, or cancelled. Use these to notify users, trigger alerts, or integrate with external systems.

### Available Events

- `WorkflowPaused` - Dispatched when a workflow, task, or step is paused
- `WorkflowResumed` - Dispatched when a workflow, task, or step is resumed  
- `WorkflowCancelled` - Dispatched when a workflow, task, or step is cancelled

### Listening to Events

```php
// In your EventServiceProvider
use AdamczykPiotr\DagWorkflows\Events\WorkflowPaused;
use AdamczykPiotr\DagWorkflows\Events\WorkflowResumed;
use AdamczykPiotr\DagWorkflows\Events\WorkflowCancelled;

protected $listen = [
    WorkflowPaused::class => [
        SendPauseNotification::class,
    ],
    WorkflowResumed::class => [
        LogWorkflowResumed::class,
    ],
    WorkflowCancelled::class => [
        CleanupCancelledWorkflow::class,
    ],
];
```

### Event Properties

All events provide access to the affected entities:

```php
use AdamczykPiotr\DagWorkflows\Events\WorkflowPaused;

class SendPauseNotification
{
    public function handle(WorkflowPaused $event): void
    {
        $workflow = $event->workflow;  // Always available
        $task = $event->task;          // Available if task was paused
        $step = $event->step;          // Available if step was paused
        $reason = $event->reason;      // The pause reason (WorkflowPaused only)

        // Send notification, create alert, etc.
        Notification::send(
            $admins,
            new WorkflowNeedsAttention($workflow->id, $reason)
        );
    }
}
```

## Limiting `ResolvableTask` items per environment

`config/dag-workflows.php` points at a middleware applied to the items before tasks are materialised. The default is `PassthroughMiddleware` although for testing purposes there's also handy implementation of `TakeFirstMiddleware`.

Custom middlewares have to implement `WorkflowResolvableItemsMiddleware::handle(iterable $items): iterable` interface.

## Testing
Run the package and application tests:

```bash
composer test
composer analyse
```

## Contributing

Contributions are welcome. Please read `CONTRIBUTING.md` in the repository for guidelines.

## Security

If you discover a security vulnerability, please follow the repository's security policy to report it.

## Credits

- Piotr Adamczyk (maintainer)
- All contributors

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
