<?php

namespace AdamczykPiotr\DagWorkflows\Models;

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;


/**
 * @property int $id
 * @property int $task_id
 * @property int $order
 * @property RunStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $completed_at
 * @property string $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkflowTaskStep|null $nextStep
 * @property-read WorkflowTask $task
 * @method static Builder<static>|WorkflowTaskStep newModelQuery()
 * @method static Builder<static>|WorkflowTaskStep newQuery()
 * @method static Builder<static>|WorkflowTaskStep query()
 * @mixin Eloquent
 */
class WorkflowTaskStep extends BaseModel {

    const string ATTRIBUTE_ID = 'id';
    const string ATTRIBUTE_TASK_ID = 'task_id';
    const string ATTRIBUTE_ORDER = 'order';
    const string ATTRIBUTE_STATUS = 'status';
    const string ATTRIBUTE_STARTED_AT = 'started_at';
    const string ATTRIBUTE_FAILED_AT = 'failed_at';
    const string ATTRIBUTE_COMPLETED_AT = 'completed_at';
    const string ATTRIBUTE_PAYLOAD = 'payload';
    const string ATTRIBUTE_CREATED_AT = 'created_at';
    const string ATTRIBUTE_UPDATED_AT = 'updated_at';

    const string RELATION_TASK = 'task';
    const string RELATION_WORKFLOW = 'workflow';
    const string RELATION_NEXT_STEP = 'nextStep';


    /**
     * @return array<string, string>
     */
    public function casts(): array {
        return [
            self::ATTRIBUTE_STATUS => RunStatus::class,
            self::ATTRIBUTE_STARTED_AT => 'datetime',
            self::ATTRIBUTE_FAILED_AT => 'datetime',
            self::ATTRIBUTE_COMPLETED_AT => 'datetime',
        ];
    }


    /**
     * @return BelongsTo<WorkflowTask, $this>
     */
    public function task(): BelongsTo {
        return $this->belongsTo(WorkflowTask::class, self::ATTRIBUTE_TASK_ID);
    }


    /**
     * @return HasOne<WorkflowTaskStep, $this>
     */
    public function nextStep(): HasOne {
        return $this->hasOne(WorkflowTaskStep::class, self::ATTRIBUTE_TASK_ID, self::ATTRIBUTE_TASK_ID)
            ->where(self::ATTRIBUTE_ORDER, $this->order + 1);
    }
}
