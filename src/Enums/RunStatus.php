<?php

namespace AdamczykPiotr\DagWorkflows\Enums;

enum RunStatus: string
{
    case PENDING = 'PENDING';

    case RUNNING = 'RUNNING';

    case PAUSED = 'PAUSED';

    case SUSPENDED = 'SUSPENDED';

    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';


    public function isTerminal(): bool {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::CANCELLED => true,
            self::PENDING, self::RUNNING, self::PAUSED, self::SUSPENDED => false,
        };
    }


    public function isPaused(): bool {
        return $this === self::PAUSED;
    }


    public function isSuspended(): bool {
        return $this === self::SUSPENDED;
    }


    public function isBlocked(): bool {
        return $this === self::PAUSED || $this === self::SUSPENDED;
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
