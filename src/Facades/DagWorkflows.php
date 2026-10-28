<?php

declare(strict_types=1);

namespace AdamczykPiotr\DagWorkflows\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \AdamczykPiotr\DagWorkflows\DagWorkflows
 */
class DagWorkflows extends Facade
{
    /**
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return \AdamczykPiotr\DagWorkflows\DagWorkflows::class;
    }
}
