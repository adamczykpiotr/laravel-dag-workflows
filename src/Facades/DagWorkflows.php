<?php

namespace AdamczykPiotr\DagWorkflows\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \AdamczykPiotr\DagWorkflows\DagWorkflows
 */
class DagWorkflows extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AdamczykPiotr\DagWorkflows\DagWorkflows::class;
    }
}
