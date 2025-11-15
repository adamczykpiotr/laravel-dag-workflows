<?php

namespace AdamczykPiotr\DagWorkflows\Definitions;

class ResolvableTask {

    /**
     * @template TEntry
     *
     * @param string $name
     * @param callable(): iterable<TEntry> $items
     * @param callable(TEntry):object|array<object> $jobs
     * @param string|array<int, string> $dependsOn
     */
    public function __construct(
        public string $name,
        public mixed $items,
        public mixed $jobs,
        public string|array $dependsOn = [],
    ) {
    }
}
