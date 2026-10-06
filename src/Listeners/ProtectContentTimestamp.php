<?php

namespace TakepartMedia\Workflows\Listeners;

use Statamic\Events\EntrySaving;
use Statamic\Facades\User;
use TakepartMedia\Workflows\Content\ContentTimestamp;
use TakepartMedia\Workflows\Support\EventLogSuppressor;

/**
 * Only a super admin may change `content_updated_at` by hand. Everybody else's
 * save keeps whatever was stored.
 *
 * The blueprint renders the field read-only for non-super-admins, but a rendered
 * `read_only` is a client-side courtesy: Statamic's entry save does not reject
 * read-only values (only UsersController does), and the field config itself is
 * resolved per request, which is exactly the kind of thing that must not be the
 * only line of defence. So the rule lives here, on the way in, where no form and
 * no cache can route around it.
 *
 * That split is also what makes the per-user field config safe to have at all.
 * InjectWorkflowFields carries a warning against user-dependent blueprints —
 * earned by a per-user `visibility` that once handed a super admin the read-only
 * variant. It still applies to anything the blueprint alone has to get right; this
 * field is no longer such a case. If the shape were ever wrong for somebody, the
 * worst outcomes are a super admin looking at a field they cannot type in, or an
 * editor typing into one whose value is then quietly kept as it was. Neither is a
 * data problem.
 *
 * The addon's OWN writes pass through untouched — a transition stamping the clock,
 * the backfill command seeding it. They are recognisable by EventLogSuppressor
 * being active, which every one of them already wraps its save in for the History's
 * sake. Without that check this listener would revert the very writes the SLA
 * depends on.
 */
class ProtectContentTimestamp
{
    public function handle(EntrySaving $event): void
    {
        $entry = $event->entry;

        if (! method_exists($entry, 'get') || ! method_exists($entry, 'getOriginal')) {
            return;
        }

        // The addon writing on somebody's behalf, not a human editing a form.
        if (EventLogSuppressor::active()) {
            return;
        }

        if (User::current()?->isSuper() ?? false) {
            return;
        }

        $original = $entry->getOriginal(ContentTimestamp::FIELD);
        $submitted = $entry->get(ContentTimestamp::FIELD);

        if ($submitted === $original) {
            return;
        }

        // Restoring rather than refusing the save: the rest of the form is a
        // legitimate edit, and blocking it over a field the editor cannot even type
        // in would be a dead end with no way out. `remove()` for the case where
        // there was nothing before — writing null would leave a null-only key in
        // the front matter.
        $original === null
            ? $entry->remove(ContentTimestamp::FIELD)
            : $entry->set(ContentTimestamp::FIELD, $original);
    }
}
