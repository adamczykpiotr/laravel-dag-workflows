<?php

namespace AdamczykPiotr\DagWorkflows\Middlewares\ResolvableItems;

class TakeFirstMiddleware implements WorkflowResolvableItemsMiddleware {

    public function __construct(
        private readonly int $count = 1,
    ) {}


    public function handle(iterable $items): iterable {
        return collect($items)->take($this->count);
    }
}
