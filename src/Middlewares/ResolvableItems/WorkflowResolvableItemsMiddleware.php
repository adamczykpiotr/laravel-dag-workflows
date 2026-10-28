<?php

declare(strict_types=1);

namespace AdamczykPiotr\DagWorkflows\Middlewares\ResolvableItems;

interface WorkflowResolvableItemsMiddleware
{
    /**
     * @param iterable<int|string, mixed> $items
     *
     * @return iterable<int|string, mixed>
     */
    public function handle(iterable $items): iterable;
}
