<?php

namespace AdamczykPiotr\DagWorkflows\Dto;

use Illuminate\Support\Collection;

class TaskDto {

    /**
     * @param string $name
     * @param Collection<int, TaskStepDto> $steps
     * @param Collection<int, string> $dependsOn
     */
    public function __construct(
        public string $name,
        public Collection $steps,
        public Collection $dependsOn,
    ) {
    }
}
