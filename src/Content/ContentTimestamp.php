<?php

namespace TakepartMedia\Workflows\Content;

use Illuminate\Support\Carbon;
use Statamic\Contracts\Entries\Entry as EntryContract;
use TakepartMedia\Workflows\Support\EventLogSuppressor;

/**
 * When a version was last brought up to date — the one clock the SLA reads.
 *
 * Lives on the ENTRY, not in the workflow database, and that is the whole point.
 * The ageing used to measure from the newest `to_state = <publish>` row in
 * `workflow_events`, which meant the age was only readable through the event log:
 * not sortable in a listing, not comparable between two versions of the same post,
 * and dependent on a fallback (`workflow_states.updated_at`) that `migrateState()`
 * silently leaves untouched. A field on the entry is readable everywhere the entry
 * is, which is what makes both the ageing and the stale-marker possible from one
 * number.
 *
 * NOT Statamic's own `updated_at`, though that exists and looks close enough.
 * Statamic bumps it on every CP save (`EntriesController::update()` calls
 * `updateLastModified()`), so a typo fix would extend a post's deadline by a year.
 * This one moves only when a version ENTERS a publish place — see
 * WorkflowTransitioner::apply() and SyncWorkflowOnRevision. Statamic's value is
 * still the seed for entries that predate the field, which is what `for()` is for.
 *
 * The distinction between `own()` and `for()` carries the stale-marker, so it is
 * worth stating plainly: Statamic's `ContainsData::get()` reads a localization's
 * OWN data only, while `HasOrigin::value()` falls back to the origin. A
 * localization that has never been approved therefore has no own value — and
 * "never approved" must not read as "as current as the German original", which is
 * exactly what an origin fallback would make it say.
 */
class ContentTimestamp
{
    /**
     * The blueprint handle, injected into every governed collection by
     * InjectWorkflowFields.
     *
     * A handle of its own rather than Statamic's `updated_at`: that one is
     * shadowed by `AugmentedEntry::updatedAt()` and overwritten by every CP save,
     * so a value written into it would neither survive nor read back.
     */
    public const FIELD = 'content_updated_at';

    /**
     * This version's own timestamp, without the origin fallback. Null means the
     * version has never been brought up to date under its own name.
     */
    public function own(EntryContract $entry): ?Carbon
    {
        return $this->parse($entry->get(self::FIELD));
    }

    /**
     * This version's timestamp, falling back to seed() for content that has none.
     */
    public function for(EntryContract $entry): ?Carbon
    {
        return $this->own($entry) ?? $this->seed($entry);
    }

    /**
     * What the clock reads for an entry that has never been stamped — the
     * publication date, and only failing that the file's last-modified date.
     *
     * The DATE first, because it is the editorial statement about the content: it
     * says when this text was put in front of readers, which is the thing an ageing
     * deadline is supposed to count from. `lastModified()` says when somebody last
     * touched the file, and for imported or bulk-edited content that is the date of
     * the import — an article from 2023 then looks days old and would never age.
     * Measured on this project: by publication date the oldest published post is 798
     * days old and 55 of 271 are past a year, by last-modified none of them is.
     *
     * `lastModified()` stays as the second step for undated collections, where
     * `date()` is null and there is nothing else to ask.
     *
     * Also used by the backfill command, so the value it writes is the value the
     * fallback would have produced — the seed and the fallback must not disagree,
     * or running the backfill would silently change every deadline.
     */
    public function seed(EntryContract $entry): ?Carbon
    {
        $date = method_exists($entry, 'date') ? $entry->date() : null;

        if ($date !== null) {
            return $date;
        }

        return method_exists($entry, 'lastModified') ? $entry->lastModified() : null;
    }

    /**
     * Record that this version is now up to date.
     *
     * Saves immediately, inside the suppressor, because every caller is already
     * in the middle of an operation that logs its own workflow event — a
     * `draft_save` row next to it would say nothing and arrive out of order.
     */
    public function touch(EntryContract $entry): void
    {
        EventLogSuppressor::while(function () use ($entry) {
            $entry->set(self::FIELD, self::stamp(Carbon::now()));
            $entry->save();
        });
    }

    /**
     * A moment as the date this field stores — in the PROJECT's timezone.
     *
     * The conversion is the whole point and was missing once, with a visible
     * result: `Entry::date()` hands back a UTC Carbon, so a post filed as
     * `2025-12-10-0000` (midnight in Berlin) reads as `2025-12-09T23:00:00+00:00`.
     * Formatting that straight away stored the ninth for a post dated the tenth,
     * and the publish form showed the two fields a day apart.
     *
     * Everything that reads the value parses it back as a local date, so the
     * timezone only ever matters here, at the moment of writing it down.
     */
    public static function stamp(Carbon $at): string
    {
        return $at->copy()->setTimezone(config('app.timezone'))->toDateString();
    }

    /**
     * Is $version behind the post it belongs to?
     *
     * True when the post's clock is newer than the version's own — and also when
     * the version has no own clock at all, because a version that was never
     * approved cannot be current. The post's side uses `for()`, so an untouched
     * post still answers with something rather than making every version look
     * fine by default.
     */
    public function isStale(EntryContract $version, EntryContract $post): bool
    {
        $postAt = $this->for($post);

        if ($postAt === null) {
            return false;
        }

        $ownAt = $this->own($version);

        // Compared by DAY, not by instant. The clock is a date — the field carries
        // no time — but stored values can still hold one: Statamic's file date does,
        // and so did everything written before this was a date-only field. Without
        // the truncation those seconds decide the comparison, and approving the
        // source a minute after the translation in the same sitting marked every
        // translation as out of date. Same-day means level, which is what an
        // editor means by it.
        return $ownAt === null || $ownAt->startOfDay()->lessThan($postAt->startOfDay());
    }

    /**
     * Tolerates every shape the field can hold: the `Y-m-d` this class writes, the
     * ISO strings it wrote while the field still had a time, a `Y-m-d H:i` from the
     * date fieldtype, and the bare Unix timestamp that Statamic's own `updated_at`
     * uses (which is what a backfill from an older project may have copied in).
     * Anything with a time still parses — isStale() truncates it away.
     */
    private function parse(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return Carbon::createFromTimestamp((int) $value, config('app.timezone'));
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            // A hand-edited YAML can hold anything. An unreadable value means "no
            // information", never an exception in the middle of a listing.
            return null;
        }
    }
}
