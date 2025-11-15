<?php

namespace AdamczykPiotr\DagWorkflows\Definitions;

class TaskGroup {

    /**
     * @param array<int, Task|ResolvableTask> $tasks
     * @param string|array<int, string> $dependsOn
     */
    public function __construct(
        public array $tasks,
        public string|array $dependsOn
    ) {
    }
}
