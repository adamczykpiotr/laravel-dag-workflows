# Workflow endpoint

`WorkflowController::show(Request $request, int $id)` returns a single workflow as JSON.
The package does not register a route for it — mount it wherever it fits your app:

```php
use AdamczykPiotr\DagWorkflows\Http\Controllers\WorkflowController;

Route::get('/workflows/{id}', [WorkflowController::class, 'show']);
```

Unknown workflow ids throw `ModelNotFoundException` (a 404 with Laravel's default
exception handling). The response shape is selected with the `format` query parameter:

| `format`         | Payload                                                              |
|------------------|----------------------------------------------------------------------|
| _(omitted)_      | Lightweight summary: status counts and completion percentages        |
| `failed`         | The `full` payload with `tasks` filtered to failed tasks only        |
| `full`           | The complete tasks/steps tree with dependencies and timing estimates |

Any other value falls back to the summary.

## Default: summary

Cheap to serve (no dependency wiring, no estimator) — intended for polling:

```json
{
    "id": 134,
    "name": "Sync POIs",
    "status": "RUNNING",
    "startedAt": "2026-07-20T09:41:03.000000Z",
    "failedAt": null,
    "completedAt": null,
    "durationSeconds": 512,
    "createdAt": "2026-07-20T09:41:02.000000Z",
    "updatedAt": "2026-07-20T09:49:34.000000Z",
    "taskStatuses": { "COMPLETED": 7, "RUNNING": 2, "PENDING": 105 },
    "stepStatuses": { "COMPLETED": 21, "RUNNING": 2, "PENDING": 211 },
    "taskCompletionPercentage": 6.14,
    "stepCompletionPercentage": 8.97,
    "stepProgressPercentage": 9.42
}
```

- `taskStatuses` / `stepStatuses` — counts per `RunStatus`; statuses with a zero
  count are omitted.
- `taskCompletionPercentage` / `stepCompletionPercentage` — done ÷ total.
  `SKIPPED` counts as done: it is the terminal, non-failing status of steps
  bypassed by an early task completion.
- `stepProgressPercentage` — like `stepCompletionPercentage`, but running steps
  are additionally credited with their self-reported `progress` (0–100).
- `durationSeconds` — from workflow creation until `completedAt`/`failedAt`,
  or until now while it is still running.

## `?format=failed`

The same payload as `format=full`, with `tasks` filtered at the query level to
those whose status is `FAILED` — each with **all** of its steps, whose statuses
show which ones completed before the failure and which never ran. Tasks that
were merely `CANCELLED` because an upstream dependency failed are not listed —
they carry no failure information of their own.

Two differences from `full` beyond the filtering: `dependencies`/`dependants`
are omitted (they are not loaded), and since everything failed is terminal, the
per-task and per-step `estimatedSecondsRemaining` are always `0`.

`tasks` is an empty array while nothing has failed, so the format is safe to
poll with throughout a run.

## `?format=full`

The heavyweight, original payload: every task with its steps, dependency ids and
timing estimates from `WorkflowEstimator` (`estimatedSecondsRemaining`,
`estimatedCompletionAt` on the workflow, per-task and per-step estimates).

```json
{
    "id": 134,
    "name": "Sync POIs",
    "status": "RUNNING",
    "startedAt": "2026-07-20T09:41:03.000000Z",
    "failedAt": null,
    "completedAt": null,
    "durationSeconds": 512,
    "estimatedSecondsRemaining": 5203,
    "estimatedCompletionAt": "2026-07-20T11:16:17.000000Z",
    "createdAt": "2026-07-20T09:41:02.000000Z",
    "updatedAt": "2026-07-20T09:49:34.000000Z",
    "tasks": [
        {
            "id": 501,
            "name": "Import: Customers",
            "status": "COMPLETED",
            "startedAt": "2026-07-20T09:41:03.000000Z",
            "failedAt": null,
            "completedAt": "2026-07-20T09:43:40.000000Z",
            "durationSeconds": 157,
            "estimatedSecondsRemaining": 0,
            "createdAt": "2026-07-20T09:41:02.000000Z",
            "updatedAt": "2026-07-20T09:43:40.000000Z",
            "steps": [
                {
                    "id": 2001,
                    "order": 1,
                    "class": "App\\Jobs\\ImportCustomersJob",
                    "status": "COMPLETED",
                    "attempts": 1,
                    "progress": 100,
                    "startedAt": "2026-07-20T09:41:03.000000Z",
                    "failedAt": null,
                    "completedAt": "2026-07-20T09:43:40.000000Z",
                    "durationSeconds": 157,
                    "estimatedDurationSeconds": 157,
                    "estimatedSecondsRemaining": 0,
                    "createdAt": "2026-07-20T09:41:02.000000Z",
                    "updatedAt": "2026-07-20T09:43:40.000000Z"
                }
            ],
            "dependencies": [],
            "dependants": [{ "childTaskId": 502 }]
        }
    ]
}
```
