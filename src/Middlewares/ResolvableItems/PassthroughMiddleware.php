<?php

namespace AdamczykPiotr\DagWorkflows\Middlewares\ResolvableItems;

class PassthroughMiddleware implements WorkflowResolvableItemsMiddleware {

    public function handle(iterable $items): iterable {
        return $items;
    }
}
