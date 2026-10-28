<?php

declare(strict_types=1);

namespace AdamczykPiotr\DagWorkflows\Middlewares\ResolvableItems;

class TakeFirstMiddleware implements WorkflowResolvableItemsMiddleware
{
    /**
     * @param int $count
     */
    public function __construct(
        private readonly int $count = 1,
    ) {}

    /**
     * @param iterable<int|string, mixed> $items
     *
     * @return iterable<int|string, mixed>
     */
    public function handle(iterable $items): iterable
    {
        return collect($items)->take($this->count);
    }
}
