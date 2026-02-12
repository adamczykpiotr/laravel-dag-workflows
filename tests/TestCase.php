<?php

namespace AdamczykPiotr\DagWorkflows\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase as Orchestra;
use AdamczykPiotr\DagWorkflows\DagWorkflowsServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Model::unguard();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'AdamczykPiotr\\DagWorkflows\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            DagWorkflowsServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        $migration = include __DIR__ . '/../database/migrations/create_dag_workflows_table.php';
        $migration->up();
    }
}
