<?php

namespace AdamczykPiotr\DagWorkflows\Dto;

use Illuminate\Support\Collection;

readonly class WorkflowTaskEstimateDto {

    /**
     * @param Collection<int, WorkflowTaskStepEstimateDto> $steps
     */
    public function __construct(
        public int $durationSeconds,
        public int $estimatedSecondsRemaining,
        public Collection $steps,
    ) {}
}
