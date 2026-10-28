<?php

declare(strict_types=1);

namespace AdamczykPiotr\DagWorkflows\Events;

use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkflowPaused
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param WorkflowTaskStep $step
     * @param string|null $reason
     */
    public function __construct(
        public readonly WorkflowTaskStep $step,
        public readonly ?string $reason = null,
    ) {}
}
