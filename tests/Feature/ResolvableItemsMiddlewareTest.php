<?php

use AdamczykPiotr\DagWorkflows\Middlewares\ResolvableItems\PassthroughMiddleware;
use AdamczykPiotr\DagWorkflows\Middlewares\ResolvableItems\TakeFirstMiddleware;
use AdamczykPiotr\DagWorkflows\Middlewares\ResolvableItems\WorkflowResolvableItemsMiddleware;
use AdamczykPiotr\DagWorkflows\Tests\TestCase;
use Illuminate\Support\Collection;

class ResolvableItemsMiddlewareTest extends TestCase {

    public function test_passthrough_middleware_returns_the_items_unchanged(): void {
        $items = ['a', 'b', 'c'];

        $this->assertSame($items, (new PassthroughMiddleware())->handle($items));
    }


    public function test_passthrough_middleware_accepts_any_iterable(): void {
        $generator = (function() { yield 'a'; yield 'b'; })();

        $out = (new PassthroughMiddleware())->handle($generator);

        $this->assertSame(['a', 'b'], iterator_to_array($out));
    }


    public function test_take_first_middleware_trims_to_the_configured_count(): void {
        $items = ['a', 'b', 'c', 'd'];

        $result = (new TakeFirstMiddleware(count: 2))->handle($items);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame(['a', 'b'], $result->toArray());
    }


    public function test_take_first_middleware_handles_count_larger_than_item_set(): void {
        $result = (new TakeFirstMiddleware(count: 10))->handle(['a', 'b']);

        $this->assertSame(['a', 'b'], $result->toArray());
    }


    public function test_take_first_middleware_handles_an_empty_iterable(): void {
        $result = (new TakeFirstMiddleware(count: 3))->handle([]);

        $this->assertSame([], $result->toArray());
    }


    public function test_take_first_middleware_defaults_to_1_item(): void {
        $result = (new TakeFirstMiddleware())->handle(['a', 'b', 'c']);

        $this->assertSame(['a'], $result->toArray());
    }


    public function test_both_shipped_implementations_satisfy_the_interface(): void {
        $this->assertInstanceOf(WorkflowResolvableItemsMiddleware::class, new PassthroughMiddleware());
        $this->assertInstanceOf(WorkflowResolvableItemsMiddleware::class, new TakeFirstMiddleware());
    }
}
