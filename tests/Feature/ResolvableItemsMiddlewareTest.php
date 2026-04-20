<?php

use AdamczykPiotr\DagWorkflows\Middlewares\ResolvableItems\PassthroughMiddleware;
use AdamczykPiotr\DagWorkflows\Middlewares\ResolvableItems\TakeFirstMiddleware;
use AdamczykPiotr\DagWorkflows\Middlewares\ResolvableItems\WorkflowResolvableItemsMiddleware;
use Illuminate\Support\Collection;

it('PassthroughMiddleware returns the items unchanged', function() {
    $items = ['a', 'b', 'c'];

    expect((new PassthroughMiddleware())->handle($items))->toBe($items);
});

it('PassthroughMiddleware accepts any iterable', function() {
    $generator = (function() { yield 'a'; yield 'b'; })();

    $out = (new PassthroughMiddleware())->handle($generator);

    expect(iterator_to_array($out))->toBe(['a', 'b']);
});

it('TakeFirstMiddleware trims to the configured count', function() {
    $items = ['a', 'b', 'c', 'd'];

    expect((new TakeFirstMiddleware(count: 2))->handle($items))
        ->toBeInstanceOf(Collection::class)
        ->toArray()->toBe(['a', 'b']);
});

it('TakeFirstMiddleware handles count larger than item set', function() {
    expect((new TakeFirstMiddleware(count: 10))->handle(['a', 'b']))
        ->toArray()->toBe(['a', 'b']);
});

it('TakeFirstMiddleware handles an empty iterable', function() {
    expect((new TakeFirstMiddleware(count: 3))->handle([]))
        ->toArray()->toBe([]);
});

it('TakeFirstMiddleware defaults to 1 item', function() {
    expect((new TakeFirstMiddleware())->handle(['a', 'b', 'c']))
        ->toArray()->toBe(['a']);
});

it('both shipped implementations satisfy the interface', function() {
    expect(new PassthroughMiddleware())->toBeInstanceOf(WorkflowResolvableItemsMiddleware::class)
        ->and(new TakeFirstMiddleware())->toBeInstanceOf(WorkflowResolvableItemsMiddleware::class);
});
