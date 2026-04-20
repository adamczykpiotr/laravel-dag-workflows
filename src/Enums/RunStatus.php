<?php

namespace AdamczykPiotr\DagWorkflows\Enums;

enum RunStatus: string
{
    case PENDING = 'PENDING';

    case RUNNING = 'RUNNING';

    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';


    public function isTerminal(): bool {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::CANCELLED => true,
            self::PENDING, self::RUNNING => false,
        };
    }
}
