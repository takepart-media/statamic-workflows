<?php

namespace TakepartMedia\Workflows\Workflow;

use Statamic\Contracts\Entries\Entry as EntryContract;
use TakepartMedia\Workflows\Content\EntrySnapshot;
use TakepartMedia\Workflows\Support\EventLogSuppressor;

/**
 * Move a version into a place without a transition.
 *
 * The two unattended movers need this — the ageing scheduler and the
 * source-in-draft cascade — and neither can go through WorkflowTransitioner:
 * there is no click to gate on a `required_group`, and no definition can be
 * expected to declare a transition into every place these two may aim at. Same
 * reasoning PollPlainLanguageCommand::moveToCompletedPlace() has followed all
 * along; this is that pattern with a name.
 *
 * What a caller still gets: the published flag follows the target place's
 * `allow_publish`, the History records the move, and the place's own arrival
 * actions fire — so a `mail` action on the destination notifies exactly as it
 * would after a transition. What it does NOT get is a transition's assignment:
 * an unattended move leaves responsibility where it was, because nothing in the
 * definition says who should take over.
 *
 * The ORDER inside is the part worth having in one place: state first, published
 * flag second. Reversed, SyncWorkflowOnUnpublish sees an unpublished entry whose
 * stored state is still a publish place and resets it to the workflow's INITIAL
 * place — the version would land somewhere nobody asked for.
 */
class PlaceMover
{
    public function __construct(
        private WorkflowStateStore $states,
        private WorkflowEventStore $events,
    ) {}

    /**
     * Returns the place actually moved to, or null when the version was already
     * there — so callers can count real movement and stay idempotent.
     *
     * @param  array<string, mixed>  $raw
     * @param  string  $eventType  History row type, e.g. `sla_aged` / `source_draft`
     */
    public function move(
        EntryContract $entry,
        string $workflow,
        array $raw,
        string $to,
        string $eventType,
        ?string $note = null,
    ): ?string {
        $current = $this->states->get($entry->id(), $workflow);

        // Already there: nothing to record, but the published flag is still worth
        // reasserting. A version can sit in the right place and be online anyway —
        // somebody published it by hand — and leaving that alone would mean the
        // cascade ran, reported nothing, and left the page on the site.
        if ($current === $to) {
            EventLogSuppressor::while(fn () => app(PublishedFlag::class)->sync($entry, $raw, $to));

            return null;
        }

        EventLogSuppressor::while(function () use ($entry, $workflow, $raw, $to, $eventType, $note, $current) {
            $this->states->set($entry->id(), $workflow, $to);
            app(PublishedFlag::class)->sync($entry, $raw, $to);

            $this->events->log($entry->id(), $workflow, $eventType, [
                'from' => $current,
                'to' => $to,
                'user_id' => null,
                'note' => $note,
                // Without a snapshot this event is a dead end for the History diff:
                // it becomes the comparison point for the NEXT event, which then
                // reports no_diff_data as well.
                'snapshot' => app(EntrySnapshot::class)->take($entry),
            ]);
        });

        app(WorkflowNotifier::class)->notifyPlaceArrival($entry->fresh() ?? $entry, $workflow, $raw, $to);

        return $to;
    }
}
