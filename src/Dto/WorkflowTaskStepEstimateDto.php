<?php

declare(strict_types=1);

namespace AdamczykPiotr\DagWorkflows\Dto;

readonly class WorkflowTaskStepEstimateDto
{
    /**
     * @param int $durationSeconds
     * @param int $estimatedDurationSeconds
     * @param int $estimatedSecondsRemaining
     */
    public function __construct(
        public int $durationSeconds,
        public int $estimatedDurationSeconds,
        public int $estimatedSecondsRemaining,
    ) {}
}
