<?php

namespace AdamczykPiotr\DagWorkflows\Enums;

enum RunStatus: string
{
    case PENDING = 'PENDING';

    case RUNNING = 'RUNNING';

    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
}
