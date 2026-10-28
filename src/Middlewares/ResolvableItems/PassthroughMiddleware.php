<?php

declare(strict_types=1);

namespace AdamczykPiotr\DagWorkflows\Middlewares\ResolvableItems;

class PassthroughMiddleware implements WorkflowResolvableItemsMiddleware
{
    /**
     * @param iterable<int|string, mixed> $items
     *
     * @return iterable<int|string, mixed>
     */
    public function handle(iterable $items): iterable
    {
        return $items;
    }
}
