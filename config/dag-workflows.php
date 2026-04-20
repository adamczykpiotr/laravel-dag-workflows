<?php

use AdamczykPiotr\DagWorkflows\Middlewares\ResolvableItems\PassthroughMiddleware;

return [

    /*
    |--------------------------------------------------------------------------
    | Resolvable items middleware
    |--------------------------------------------------------------------------
    |
    | Applied to the iterable returned by a ResolvableTask's items callback
    | before tasks are materialized. Point it at a different implementation
    | (e.g. TakeFirstMiddleware) in non-production environments to keep local
    | runs small.
    |
    */

    'resolvable_items_middleware' => PassthroughMiddleware::class,

];
