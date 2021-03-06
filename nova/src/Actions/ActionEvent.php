<?php

namespace Laravel\Nova\Actions;

use DateTime;
use Laravel\Nova\Nova;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Http\Requests\ActionRequest;

class ActionEvent extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * Get the user that initiated the action.
     */
    public function user()
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'user_id');
    }

    /**
     * Get the target of the action for user interface linking.
     */
    public function target()
    {
        return $this->morphTo('target', 'target_type', 'target_id')->withTrashed();
    }

    /**
     * Create a new action event instance for a resource update.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $user
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Model
     */
    public static function forResourceUpdate($user, $model)
    {
        return new static([
            'batch_id' => (string) Str::orderedUuid(),
            'user_id' => $user->getKey(),
            'name' => 'Update',
            'actionable_type' => get_class($model),
            'actionable_id' => $model->getKey(),
            'target_type' => get_class($model),
            'target_id' => $model->getKey(),
            'model_type' => get_class($model),
            'model_id' => $model->getKey(),
            'fields' => '',
            'status' => 'finished',
            'exception' => '',
        ]);
    }

    /**
     * Create a new action event instance for an attached resource update.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  \Illuminate\Database\Eloquent\Model  $pivot
     * @return \Illuminate\Database\Eloquent\Model
     */
    public static function forAttachedResourceUpdate(NovaRequest $request, $parent, $pivot)
    {
        return new static([
            'batch_id' => (string) Str::orderedUuid(),
            'user_id' => $request->user()->getKey(),
            'name' => 'Update Attached',
            'actionable_type' => get_class($parent),
            'actionable_id' => $parent->getKey(),
            'target_type' => get_class(Nova::modelInstanceForKey($request->relatedResource)),
            'target_id' => $request->relatedResourceId,
            'model_type' => get_class($pivot),
            'model_id' => $pivot->getKey(),
            'fields' => '',
            'status' => 'finished',
            'exception' => '',
        ]);
    }

    /**
     * Create new action event instances for resource deletes.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $user
     * @param  \Illuminate\Support\Collection  $models
     * @return \Illuminate\Support\Collection
     */
    public static function forResourceDelete($user, Collection $models)
    {
        return static::forSoftDeleteAction('Delete', $user, $models);
    }

    /**
     * Create new action event instances for resource restorations.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $user
     * @param  \Illuminate\Support\Collection  $models
     * @return \Illuminate\Support\Collection
     */
    public static function forResourceRestore($user, Collection $models)
    {
        return static::forSoftDeleteAction('Restore', $user, $models);
    }

    /**
     * Create new action event instances for resource soft deletions.
     *
     * @param  string  $action
     * @param  \Illuminate\Database\Eloquent\Model  $user
     * @param  \Illuminate\Support\Collection  $models
     * @return \Illuminate\Support\Collection
     */
    public static function forSoftDeleteAction($action, $user, Collection $models)
    {
        $batchId = (string) Str::orderedUuid();

        return $models->map(function ($model) use ($action, $user, $batchId) {
            return new static([
                'batch_id' => $batchId,
                'user_id' => $user->getKey(),
                'name' => $action,
                'actionable_type' => get_class($model),
                'actionable_id' => $model->getKey(),
                'target_type' => get_class($model),
                'target_id' => $model->getKey(),
                'model_type' => get_class($model),
                'model_id' => $model->getKey(),
                'fields' => '',
                'status' => 'finished',
                'exception' => '',
                'created_at' => new DateTime,
                'updated_at' => new DateTime,
            ]);
        });
    }

    /**
     * Create new action event instances for resource detachments.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $user
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  \Illuminate\Support\Collection  $models
     * @param  string  $pivotClass
     * @return \Illuminate\Support\Collection
     */
    public static function forResourceDetach($user, $parent, Collection $models, $pivotClass)
    {
        $batchId = (string) Str::orderedUuid();

        return $models->map(function ($model) use ($user, $parent, $pivotClass, $batchId) {
            return new static([
                'batch_id' => $batchId,
                'user_id' => $user->getKey(),
                'name' => 'Detach',
                'actionable_type' => get_class($parent),
                'actionable_id' => $parent->getKey(),
                'target_type' => get_class($model),
                'target_id' => $model->getKey(),
                'model_type' => $pivotClass,
                'model_id' => null,
                'fields' => '',
                'status' => 'finished',
                'exception' => '',
                'created_at' => new DateTime,
                'updated_at' => new DateTime,
            ]);
        });
    }

    /**
     * Create the action records for the given models.
     *
     * @param  \Laravel\Nova\Http\Requests\ActionRequest  $request
     * @param  \Laravel\Nova\Actions\Action  $action
     * @param  string  $batchId
     * @param  \Illuminate\Support\Collection  $models
     * @param  string  $status
     * @return void
     */
    public static function createForModels(ActionRequest $request, Action $action,
                                           $batchId, Collection $models, $status = 'running')
    {
        $models = $models->map(function ($model) use ($request, $action, $batchId, $status) {
            return array_merge(
                static::defaultAttributes($request, $action, $batchId, $status),
                [
                    'actionable_id' => $request->actionableKey($model),
                    'target_id' => $request->targetKey($model),
                    'model_id' => $model->getKey(),
                ]
            );
        });

        static::insert($models->all());

        static::prune($models);
    }

    /**
     * Get the default attributes for creating a new action event.
     *
     * @param  \Laravel\Nova\Http\Requests\ActionRequest  $request
     * @param  \Laravel\Nova\Actions\Action  $action
     * @param  string  $batchId
     * @param  string  $status
     * @return array
     */
    public static function defaultAttributes(ActionRequest $request, Action $action,
                                             $batchId, $status = 'running')
    {
        return [
            'batch_id' => $batchId,
            'user_id' => $request->user()->getKey(),
            'name' => $action->name(),
            'actionable_type' => get_class($request->actionableModel()),
            'target_type' => get_class($request->model()),
            'model_type' => $request->isPivotAction()
                                ? $request->pivotRelation()->getPivotClass()
                                : get_class($request->actionableModel()),
            'fields' => serialize($request->resolveFieldsForStorage()),
            'status' => $status,
            'exception' => '',
            'created_at' => new DateTime,
            'updated_at' => new DateTime,
        ];
    }

    /**
     * Prune the action events for the given types.
     *
     * @param  \Illuminate\Support\Collection  $models
     * @param  int  $limit
     */
    public static function prune($models, $limit = 25)
    {
        $models->each(function ($model) use ($limit) {
            static::where('actionable_id', $model['actionable_id'])
                ->where('actionable_type', $model['actionable_type'])
                ->whereNotIn('id', function ($query) use ($model, $limit) {
                    $query->select('id')->fromSub(
                        static::select('id')->orderBy('id', 'desc')
                                ->where('actionable_id', $model['actionable_id'])
                                ->where('actionable_type', $model['actionable_type'])
                                ->limit($limit)->toBase(),
                        'action_events_temp'
                    );
                })->delete();
        });
    }

    /**
     * Mark the given batch as running.
     *
     * @param  string  $batchId
     * @return int
     */
    public static function markBatchAsRunning($batchId)
    {
        return static::where('batch_id', $batchId)
                    ->whereNotIn('status', ['finished', 'failed'])->update([
                        'status' => 'running',
                    ]);
    }

    /**
     * Mark the given batch as finished.
     *
     * @param  string  $batchId
     * @return int
     */
    public static function markBatchAsFinished($batchId)
    {
        return static::where('batch_id', $batchId)
                    ->whereNotIn('status', ['finished', 'failed'])->update([
                        'status' => 'finished',
                    ]);
    }

    /**
     * Mark a given action event record as finished.
     *
     * @param  string  $batchId
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return int
     */
    public static function markAsFinished($batchId, $model)
    {
        return static::updateStatus($batchId, $model, 'finished');
    }

    /**
     * Mark the given batch as failed.
     *
     * @param  string  $batchId
     * @param  \Throwable  $e
     * @return int
     */
    public static function markBatchAsFailed($batchId, $e = null)
    {
        return static::where('batch_id', $batchId)
                    ->whereNotIn('status', ['finished', 'failed'])->update([
                        'status' => 'failed',
                        'exception' => $e ? (string) $e : '',
                    ]);
    }

    /**
     * Mark a given action event record as failed.
     *
     * @param  string  $batchId
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  \Throwable|string  $e
     * @return int
     */
    public static function markAsFailed($batchId, $model, $e = null)
    {
        return static::updateStatus($batchId, $model, 'failed', $e);
    }

    /**
     * Update the status of a given action event.
     *
     * @param  string  $batchId
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $status
     * @param  \Throwable|string  $e
     * @return int
     */
    public static function updateStatus($batchId, $model, $status, $e = null)
    {
        return static::where('batch_id', $batchId)
                        ->where('model_type', get_class($model))
                        ->where('model_id', $model->getKey())
                        ->update(['status' => $status, 'exception' => (string) $e]);
    }

    /**
     * Get the table associated with the model.
     *
     * @return string
     */
    public function getTable()
    {
        return 'action_events';
    }
}
