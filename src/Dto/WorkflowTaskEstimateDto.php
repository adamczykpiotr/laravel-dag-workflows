<?php

declare(strict_types=1);

namespace AdamczykPiotr\DagWorkflows\Dto;

use Illuminate\Support\Collection;

readonly class WorkflowTaskEstimateDto
{
    /**
     * @param Collection<int, WorkflowTaskStepEstimateDto> $steps
     * @param int $durationSeconds
     * @param int $estimatedSecondsRemaining
     */
    public function __construct(
        public int $durationSeconds,
        public int $estimatedSecondsRemaining,
        public Collection $steps,
    ) {}
}
