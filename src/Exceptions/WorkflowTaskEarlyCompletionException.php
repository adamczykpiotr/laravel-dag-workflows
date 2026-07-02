<?php

namespace AdamczykPiotr\DagWorkflows\Exceptions;

use Exception;

/**
 * Control-flow signal thrown by {@see \AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking::completeTaskEarly()}.
 *
 * Caught by the tracker middleware, which marks the current step as COMPLETED,
 * skips all remaining steps of the task, and completes the task as if every
 * step had run. Never treated as a failure.
 */
class WorkflowTaskEarlyCompletionException extends Exception {

}
