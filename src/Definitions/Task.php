<?php

namespace AdamczykPiotr\DagWorkflows\Definitions;

class Task {

    /**
     * @param string $name
     * @param object|array<int, object> $jobs
     * @param string|array<int, string> $dependsOn
     */
    public function __construct(
        public string $name,
        public object|array $jobs,
        public string|array $dependsOn = [],
    ) {
    }
}
