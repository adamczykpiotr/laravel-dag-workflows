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
- `rollbackStep()` hook to undo a failed attempt's leftovers before a retry
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

## Rolling back failed attempts

When a step is retried, the previous attempt may have left things behind — a
downloaded file, rows from queries that already ran. Override `rollbackStep()` to
undo them; it runs right before `handle()`, but only when a previous attempt of
the step already ran. First attempts skip it, and the default is a no-op, so
existing jobs are unaffected. A throwing `rollbackStep()` fails the step like a
throwing `handle()`.

```php
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;

class BuildDatasetJob implements ShouldQueue {
    use HasWorkflowTracking;

    public function rollbackStep(): void {
        // Undo whatever the failed attempt left behind.
        File::deleteDirectory($this->workDirectory());
    }

    public function handle(): void {
        // ... always starts from a clean slate
    }
}
```

This also covers steps re-run because an *upstream* step was retried: if the
step already ran, it rolls back first; if it never ran, it starts clean.

## Pausable Tasks

Workflows, tasks, and steps can be paused for manual intervention. This is useful when:
- An anomaly is detected that requires human review
- User approval is needed before continuing
- External validation is required

```php
use AdamczykPiotr\DagWorkflows\Models\Workflow;

$workflow = Workflow::find($id);

// Pause the entire workflow
$workflow->pause('Anomaly detected - awaiting manual review');

// Resume when ready
$workflow->resume();

// Or cancel if not recoverable
$workflow->cancel();
```

The package dispatches events (`WorkflowPaused`, `WorkflowResumed`, `WorkflowCancelled`) that you can listen to for notifications and integrations.

For comprehensive documentation including job examples, event handling, and best practices, see **[docs/PAUSABLE_TASKS.md](docs/PAUSABLE_TASKS.md)**.

## Early Task Completion

A job can complete its whole task early when the remaining steps are known to be
unnecessary — for example when a freshly downloaded source file is byte-identical
to the one already processed:

```php
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;

class ScrapeDatasetJob implements ShouldQueue {
    use HasWorkflowTracking;

    public function handle(): void {
        $file = $this->download();

        if ($this->isUnchanged($file)) {
            $this->completeTaskEarly('source file unchanged'); // never returns
        }

        // ... continue as usual
    }
}
```

The current step is marked `COMPLETED`, all remaining steps of the task are marked
`SKIPPED` (a terminal, non-failing status), and the task completes as if every step
had run — dependant tasks are dispatched and the workflow status is finalized as usual.

## Workflow endpoint formats

`WorkflowController::show` defaults to a lightweight summary —
no dependency wiring, no estimator:

```json
{
    "id": 134,
    "name": "Sync POIs",
    "status": "RUNNING",
    "durationSeconds": 512,
    "taskStatuses": { "COMPLETED": 7, "RUNNING": 2, "PENDING": 105 },
    "stepStatuses": { "COMPLETED": 21, "RUNNING": 2, "PENDING": 211 },
    "taskCompletionPercentage": 6.14,
    "stepCompletionPercentage": 8.97,
    "stepProgressPercentage": 9.42
}
```

`SKIPPED` counts as done in the percentages (it is the terminal, non-failing
status of steps bypassed by an early task completion), and
`stepProgressPercentage` additionally credits running steps with their
self-reported `progress`. Append `?format=full`
for the previous behaviour: the complete tasks/steps tree with dependencies
and timing estimates.

## Dynamic dependencies (waiting for a `ResolvableTask`'s spawned tasks)

A `ResolvableTask` completes once its resolver has spawned the child tasks — depending
on it by name therefore does NOT wait for the children. A dependency ending with `*`
is a prefix glob: it gates the task on every OTHER task whose name starts with the
prefix, including tasks that only come into existence at runtime:

```php
new Task(name: 'Import: Customers', jobs: new ImportCustomersJob()),
new ResolvableTask(
    name: 'Import: Orders',
    items: fn() => $regions,
    jobs: fn(string $region) => new ImportOrdersJob($region),
),
new Task(
    name: 'Import: Summary',
    jobs: new BuildImportSummaryJob(),
    dependsOn: 'Import: *', // waits for Import: Customers, Import: Orders and every Import: Orders:<region> task
),
```

The declaring task never matches its own wildcard, so the aggregate may live in the
namespace it waits for. At least one other task must match the glob at definition
time — that anchor keeps the dependant parked until runtime-spawned matches (which
are wired in while their resolver is still running) have been attached.

`*` is reserved for this syntax — task names containing it are rejected with a
`WorkflowTaskReservedCharacterException`.

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
