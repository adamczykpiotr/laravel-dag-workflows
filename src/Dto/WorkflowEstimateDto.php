<?php

namespace AdamczykPiotr\DagWorkflows\Dto;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

readonly class WorkflowEstimateDto {

    /**
     * @param Collection<int, WorkflowTaskEstimateDto> $tasks
     */
    public function __construct(
        public int $durationSeconds,
        public int $estimatedSecondsRemaining,
        public ?CarbonInterface $estimatedCompletionAt,
        public Collection $tasks,
    ) {
    }
}
