<?php

namespace AdamczykPiotr\DagWorkflows;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DagWorkflowsServiceProvider extends PackageServiceProvider
{
    /**
     * @param Package $package
     * @return void
     */
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('dag-workflows')
            ->hasMigrations('create_dag_workflows_table');
    }
}
