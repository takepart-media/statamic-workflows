<?php

namespace TakepartMedia\Workflows\Workflow;

use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * A post dropping to Statamic draft takes its other versions with it.
 *
 * The trigger is the POST's published flag, not a particular place. An author who
 * gives their "outdated" step `allow_publish: false` gets the cascade; one who
 * leaves the step online does not. The same cascade fires when somebody
 * unpublishes a post by hand, which is the point rather than a side effect: a
 * translation of a withdrawn post has no business staying online either.
 *
 * Where each version lands is that version's OWN workflow's business —
 * `metadata.source_draft_place`, chosen from its own places. The post manager says
 * WHEN, each localization manager says WHERE. The alternative, listing foreign
 * place handles in the post manager's settings, would put the choice where the
 * places do not exist and the writer could not validate it.
 *
 * This is the first operation in the addon that touches versions OTHER than the
 * one being acted on, which is why it is a class of its own rather than a few
 * lines in a listener. Everything else in here — every WorkflowStateStore::set()
 * call — writes exactly one (subject, workflow) row.
 *
 * The move itself goes through PlaceMover — a direct state set rather than a
 * transition, for the reasons stated there. The arrival mail still goes out,
 * because that hangs on the place rather than on the transition.
 *
 * Nobody comes back automatically. A refreshed post does not re-approve a
 * translation nobody has looked at — each version earns its way forward through
 * its own workflow again, which is exactly why demoting it is worth more than
 * flagging it: it puts the job back in somebody's queue.
 */
class StaleLocalizations
{
    /** Metadata key naming the place a version falls back to. */
    public const PLACE_KEY = 'source_draft_place';

    /** Event type recorded on the demoted version. */
    public const EVENT_TYPE = 'source_draft';

    public function __construct(
        private DefinitionLoader $loader,
        private WorkflowResolver $resolver,
        private WorkflowStateStore $states,
    ) {}

    /**
     * Demote every version of $post that names a fallback place, and return how
     * many actually moved.
     */
    public function cascade(EntryContract $post): int
    {
        $root = $post->root() ?? $post;

        if (! method_exists($root, 'descendants')) {
            return 0;
        }

        $moved = 0;

        foreach ($root->descendants() as $version) {
            if (! $version instanceof EntryContract || $version->id() === $root->id()) {
                continue;
            }

            $moved += $this->demote($version) ? 1 : 0;
        }

        return $moved;
    }

    private function demote(EntryContract $version): bool
    {
        $workflow = $this->resolver->forEntry($version);

        if ($workflow === null) {
            return false;
        }

        $raw = $this->loader->raw($workflow);
        $place = $raw['metadata'][self::PLACE_KEY] ?? null;

        if (! is_string($place) || $place === '' || ! isset($raw['places'][$place])) {
            return false;
        }

        $current = $this->states->get($version->id(), $workflow);

        // A place that shows the rewrite spinner has an open job at the service, and
        // PollPlainLanguageCommand is still waiting for its answer. Pulling the
        // version out from under it would orphan that job — the result would arrive
        // for a version that has moved on, and nobody would ever write it back.
        if (PlaceActions::showsPendingRewriteSpinner($raw['places'][$current] ?? [])) {
            return false;
        }

        return app(PlaceMover::class)->move(
            $version,
            $workflow,
            $raw,
            $place,
            self::EVENT_TYPE,
            __('workflows::messages.event_note_source_draft'),
        ) !== null;
    }
}
