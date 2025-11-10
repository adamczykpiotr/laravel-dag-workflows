<?php

namespace AdamczykPiotr\DagWorkflows\Models;

use AdamczykPiotr\DagWorkflows\Enums\RunStatus;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;


/**
 * @property int $id
 * @property int $workflow_id
 * @property string $name
 * @property RunStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, WorkflowTask> $dependants
 * @property-read int|null $dependants_count
 * @property-read Collection<int, WorkflowTask> $dependencies
 * @property-read int|null $dependencies_count
 * @property-read WorkflowTaskStep|null $initialStep
 * @property-read Collection<int, WorkflowTask> $recursiveDependants
 * @property-read int|null $recursive_dependants_count
 * @property-read Collection<int, WorkflowTaskStep> $steps
 * @property-read int|null $steps_count
 * @property-read Workflow $workflow
 * @method static Builder<static>|WorkflowTask newModelQuery()
 * @method static Builder<static>|WorkflowTask newQuery()
 * @method static Builder<static>|WorkflowTask query()
 * @mixin Eloquent
 */
class WorkflowTask extends BaseModel {

    const string ATTRIBUTE_ID = 'id';
    const string ATTRIBUTE_WORKFLOW_ID = 'workflow_id';
    const string ATTRIBUTE_NAME = 'name';
    const string ATTRIBUTE_STATUS = 'status';
    const string ATTRIBUTE_STARTED_AT = 'started_at';
    const string ATTRIBUTE_FAILED_AT = 'failed_at';
    const string ATTRIBUTE_COMPLETED_AT = 'completed_at';
    const string ATTRIBUTE_CREATED_AT = 'created_at';
    const string ATTRIBUTE_UPDATED_AT = 'updated_at';

    const string RELATION_WORKFLOW = 'workflow';
    const string RELATION_STEPS = 'steps';
    const string RELATION_INITIAL_STEP = 'initialStep';
    const string RELATION_DEPENDENCIES = 'dependencies';
    const string RELATION_DEPENDANTS = 'dependants';
    const string RELATION_RECURSIVE_DEPENDANTS = 'recursiveDependants';

    const string PIVOT_DEPENDENCIES_TABLE = 'workflow_task_dependencies';
    const string PIVOT_COLUMN_TASK_ID = 'task_id';
    const string PIVOT_COLUMN_DEPENDANT_TASK_ID = 'dependant_task_id';


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
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo {
        return $this->belongsTo(Workflow::class);
    }


    /**
     * @return HasMany<WorkflowTaskStep, $this>
     */
    public function steps(): HasMany {
        return $this->hasMany(WorkflowTaskStep::class, WorkflowTaskStep::ATTRIBUTE_TASK_ID)
            ->orderBy(WorkflowTaskStep::ATTRIBUTE_ORDER);
    }


    /**
     * @return HasOne<WorkflowTaskStep, $this>
     */
    public function initialStep(): HasOne {
        return $this->hasOne(WorkflowTaskStep::class, WorkflowTaskStep::ATTRIBUTE_TASK_ID)
            ->where(WorkflowTaskStep::ATTRIBUTE_ORDER, 1);
    }


    /**
     * @return BelongsToMany<WorkflowTask, $this, Pivot>
     */
    public function dependencies(): BelongsToMany {
        return $this->belongsToMany(
            WorkflowTask::class,
            self::PIVOT_DEPENDENCIES_TABLE,
            self::PIVOT_COLUMN_TASK_ID,
            self::PIVOT_COLUMN_DEPENDANT_TASK_ID
        )->orderBy(WorkflowTask::ATTRIBUTE_ID);
    }


    /**
     * @return BelongsToMany<WorkflowTask, $this, Pivot>
     */
    public function dependants(): BelongsToMany {
        return $this->belongsToMany(
            WorkflowTask::class,
            self::PIVOT_DEPENDENCIES_TABLE,
            self::PIVOT_COLUMN_DEPENDANT_TASK_ID,
            self::PIVOT_COLUMN_TASK_ID
        );
    }


    /**
     * @return BelongsToMany<WorkflowTask, $this, Pivot>
     */
    public function recursiveDependants(): BelongsToMany {
        return $this->dependants()
            ->with(self::RELATION_RECURSIVE_DEPENDANTS);
    }
}
