<?php

declare(strict_types=1);

namespace AdamczykPiotr\DagWorkflows\Tests;

use AdamczykPiotr\DagWorkflows\DagWorkflowsServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName): string => 'AdamczykPiotr\\DagWorkflows\\Database\\Factories\\' . class_basename($modelName) . 'Factory'
        );
    }

    /**
     * @param mixed $app
     */
    protected function getPackageProviders($app)
    {
        return [
            DagWorkflowsServiceProvider::class,
        ];
    }

    /**
     * @param mixed $app
     *
     * @return void
     */
    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');

        foreach (File::allFiles(__DIR__ . '/../database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
        }
    }
}
