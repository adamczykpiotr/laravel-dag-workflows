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

    /**
     * Step never ran because its task was completed early
     * ({@see \AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking::completeTaskEarly()}).
     * Terminal and non-failing.
     */
    case SKIPPED = 'SKIPPED';


    public function isActive(): bool {
        return $this === self::PENDING || $this === self::RUNNING;
    }


    public function isBlocked(): bool {
        return $this === self::PAUSED || $this === self::SUSPENDED;
    }


    public function isTerminal(): bool {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::CANCELLED, self::SKIPPED => true,
            default => false,
        };
    }


    public function canBePaused(): bool {
        return $this->isActive();
    }


    public function canBeResumed(): bool {
        return $this === self::PAUSED;
    }


    /**
     * @return array<int, self>
     */
    public static function active(): array {
        return [self::PENDING, self::RUNNING];
    }


    /**
     * @return array<int, self>
     */
    public static function blocked(): array {
        return [self::PAUSED, self::SUSPENDED];
    }


    /**
     * @return array<int, self>
     */
    public static function nonTerminal(): array {
        return [self::PENDING, self::RUNNING, self::PAUSED, self::SUSPENDED];
    }
}
