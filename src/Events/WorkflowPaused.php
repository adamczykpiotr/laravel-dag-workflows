<?php

namespace AdamczykPiotr\DagWorkflows\Events;

use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Models\WorkflowTaskStep;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkflowPaused
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly ?WorkflowTaskStep $step = null,
        public readonly ?Workflow $workflow = null,
        public readonly ?string $reason = null,
    ) {
    }


    public function getWorkflow(): Workflow {
        if ($this->workflow !== null) {
            return $this->workflow;
        }

        if ($this->step !== null) {
            return $this->step->task->workflow;
        }

        throw new \InvalidArgumentException('Either workflow or step must be provided');
    }
}
