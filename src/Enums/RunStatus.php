<?php

namespace AdamczykPiotr\DagWorkflows\Enums;

enum RunStatus: string
{
    case PENDING = 'PENDING';

    case RUNNING = 'RUNNING';

    case PAUSED = 'PAUSED';

    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';


    public function isTerminal(): bool {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::CANCELLED => true,
            self::PENDING, self::RUNNING, self::PAUSED => false,
        };
    }


    public function isPaused(): bool {
        return $this === self::PAUSED;
    }


    public function canBePaused(): bool {
        return match ($this) {
            self::PENDING, self::RUNNING => true,
            default => false,
        };
    }


    public function canBeResumed(): bool {
        return $this === self::PAUSED;
    }
}
