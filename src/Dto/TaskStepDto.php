<?php

namespace AdamczykPiotr\DagWorkflows\Dto;

class TaskStepDto {

    /**
     * @param int $order
     * @param object $job
     */
    public function __construct(
        public int $order,
        public object $job,
    ) {
    }
}
