<?php

namespace AdamczykPiotr\DagWorkflows;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use AdamczykPiotr\DagWorkflows\Commands\DagWorkflowsCommand;

class DagWorkflowsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('dag-workflows')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_dag_workflows_table')
            ->hasCommand(DagWorkflowsCommand::class);
    }
}
