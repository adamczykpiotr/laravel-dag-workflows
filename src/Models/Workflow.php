<?php

namespace AdamczykPiotr\DagWorkflows\Models;

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use AdamczykPiotr\DagWorkflows\Services\WorkflowDispatcher;
use Eloquent;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property RunStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, WorkflowTask> $tasks
 * @property-read int|null $tasks_count
 * @method static Builder<static>|Workflow newModelQuery()
 * @method static Builder<static>|Workflow newQuery()
 * @method static Builder<static>|Workflow query()
 * @mixin Eloquent
 */
class Workflow extends BaseModel
{

    const string ATTRIBUTE_ID = 'id';
    const string ATTRIBUTE_NAME = 'name';
    const string ATTRIBUTE_STATUS = 'status';
    const string ATTRIBUTE_STARTED_AT = 'started_at';
    const string ATTRIBUTE_FAILED_AT = 'failed_at';
    const string ATTRIBUTE_COMPLETED_AT = 'completed_at';
    const string ATTRIBUTE_CREATED_AT = 'created_at';
    const string ATTRIBUTE_UPDATED_AT = 'updated_at';

    const string RELATION_TASKS = 'tasks';

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            self::ATTRIBUTE_STATUS => RunStatus::class,
            self::ATTRIBUTE_STARTED_AT => 'datetime',
            self::ATTRIBUTE_FAILED_AT => 'datetime',
            self::ATTRIBUTE_COMPLETED_AT => 'datetime',
        ];
    }

    /**
     * @return HasMany<$this, WorkflowTask>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(WorkflowTask::class, WorkflowTask::ATTRIBUTE_WORKFLOW_ID);
    }


    // Helper

    /**
     * @return void
     * @throws BindingResolutionException
     */
    public function dispatch(): void
    {
        $dispatcher = app()->make(WorkflowDispatcher::class);
        $dispatcher->dispatchWorkflow($this);
    }

}
