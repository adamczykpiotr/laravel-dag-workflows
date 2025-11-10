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

## Installation

Install the package via Composer and run migrations:

```bash
composer require adamczykpiotr/laravel-dag-workflows
php artisan vendor:publish --tag="dag-workflows-migrations"
php artisan migrate
```

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
