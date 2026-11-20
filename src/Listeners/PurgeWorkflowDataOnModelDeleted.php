<?php

namespace TakepartMedia\Workflows\Listeners;

use Illuminate\Database\Eloquent\Model;
use TakepartMedia\Workflows\Subjects\Subjects;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowDataPurger;

/**
 * When an Eloquent model behind a Runway resource is deleted, take its workflow
 * data with it — the model counterpart of PurgeWorkflowDataOnEntryDeleted.
 *
 * Runway fires no event of its own (its delete action just calls `delete()`), so
 * this listens to Eloquent's `eloquent.deleted: *`, which also catches deletes from
 * project code. Models without a Runway resource are ignored. A soft delete keeps
 * the data, since the model can be restored; the force delete purges it.
 */
class PurgeWorkflowDataOnModelDeleted
{
    /**
     * Purge the deleted model's rows from every workflow, switched-off ones included.
     *
     * @param  string  $event  the wildcard event name, e.g. `eloquent.deleted: App\Models\Foo`
     * @param  array<int, mixed>  $payload  the deleted model as its only element
     */
    public function handle(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;

        if (! $model instanceof Model || (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting())) {
            return;
        }

        $id = Subjects::forModel($model)?->id();

        if ($id === null) {
            return;
        }

        $purger = app(WorkflowDataPurger::class);

        foreach (app(DefinitionLoader::class)->allIncludingInactive() as $workflow) {
            $purger->purge($workflow, [$id]);
        }
    }
}
