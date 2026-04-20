<?php

namespace AdamczykPiotr\DagWorkflows\Dto;

readonly class WorkflowTaskStepEstimateDto {

    public function __construct(
        public int $durationSeconds,
        public int $estimatedDurationSeconds,
        public int $estimatedSecondsRemaining,
    ) {}
}
