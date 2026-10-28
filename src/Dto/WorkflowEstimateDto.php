<?php

declare(strict_types=1);

namespace AdamczykPiotr\DagWorkflows\Dto;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

readonly class WorkflowEstimateDto
{
    /**
     * @param Collection<int, WorkflowTaskEstimateDto> $tasks
     * @param int $durationSeconds
     * @param int $estimatedSecondsRemaining
     * @param CarbonInterface|null $estimatedCompletionAt
     */
    public function __construct(
        public int $durationSeconds,
        public int $estimatedSecondsRemaining,
        public ?CarbonInterface $estimatedCompletionAt,
        public Collection $tasks,
    ) {}
}
