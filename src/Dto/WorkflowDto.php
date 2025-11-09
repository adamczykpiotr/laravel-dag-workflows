<?php

namespace AdamczykPiotr\DagWorkflows\Dto;

use Illuminate\Support\Collection;

class WorkflowDto
{

    /**
     * @param string $name
     * @param Collection<int, TaskDto> $tasks
     */
    public function __construct(
        public string $name,
        public Collection $tasks
    ) {
    }

}
