<?php

declare(strict_types=1);

namespace AdamczykPiotr\DagWorkflows\Exceptions;

use Exception;

class WorkflowTaskCircularDependencyException extends Exception {}
