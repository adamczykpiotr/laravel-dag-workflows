<?php

namespace AdamczykPiotr\DagWorkflows\Events;

use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkflowCancelled
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly ?WorkflowTaskStep $step = null,
    ) {
    }
}
