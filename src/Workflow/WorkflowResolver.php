<?php

namespace TakepartMedia\Workflows\Workflow;

use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Site;
use TakepartMedia\Workflows\Content\ContentFieldResolver;
use TakepartMedia\Workflows\Content\ContentTimestamp;

/**
 * Resolves which workflow governs a collection and whether an entry is allowed
 * to be published. Publishing is gated on the workflow state: an entry may only
 * be published once it has reached a place flagged `metadata.allow_publish` in
 * the workflow definition (e.g. post_update's `published` place).
 */
class WorkflowResolver
{
    /**
     * The `metadata.detail` value that marks a localization workflow.
     *
     * A named constant because three parties now read it: this class, and the
     * writer's two mirror-image rules — the ageing block belongs only to an
     * authoring workflow, `source_draft_place` only to a localization one.
     */
    public const DETAIL_TRANSLATION = 'translation';

    public function __construct(
        private DefinitionLoader $loader,
        private WorkflowStateStore $states,
    ) {}

    /**
     * The workflow that OWNS a collection: who authors it, who may create in
     * it, which manager a collection-level listing belongs to.
     *
     * An authoring workflow wins over a localization one, and only then does
     * first-wins over the alphabetically sorted definition list decide. Plain
     * alphabetical order was enough while every collection had at most one
     * localization workflow; with two of them a name like `plainlanguage`
     * sorts ahead of `postmanager` and would take ownership of `posts` — which
     * removes the "new post" button, breaks storePost() and repoints the
     * AssignedPosts widget. A localization workflow never creates entries
     * (storePost() aborts for them outright), so it can never be the owner
     * while an authoring workflow exists.
     *
     * Deliberately entry-agnostic. For "which workflow governs THIS version of
     * an entry", use forEntry().
     */
    public function forCollection(string $collection): ?string
    {
        $localization = null;

        foreach ($this->loader->all() as $name) {
            $raw = $this->loader->raw($name);

            if (! in_array($collection, (array) ($raw['collections'] ?? []), true)) {
                continue;
            }

            if (! $this->isLocalizationWorkflow($raw)) {
                return $name;
            }

            $localization ??= $name;
        }

        return $localization;
    }

    /**
     * The workflow that governs one specific entry version.
     *
     * A localization is the subject of the localization workflow, its origin is
     * the subject of the authoring one — so the entry editor of an English
     * translation shows the translation manager's transitions, while the German
     * source keeps the post manager's. Without this, `forCollection()` handed
     * every version to whichever workflow sorts first (`postmanager` before
     * `translation`), and a translation showed authoring actions.
     *
     * `isRoot()` is the test: an entry created directly in a site is a root and
     * stays with the authoring workflow, no matter which site that is. Collections
     * without a localization workflow are unaffected.
     *
     * Only callers that hold an actual entry switch to this. Collection-level
     * questions stay on forCollection() — ownership, the settings and installer
     * paths, the listing filters, the AssignedPosts widget. So does
     * InjectWorkflowFields, which only decides WHETHER the panel is
     * injected and must never depend on a version: blueprints are cached, and
     * whichever request resolves one first decides its shape for everyone.
     *
     * The version's own site is what tells two localization workflows apart —
     * see localizationWorkflowFor().
     */
    public function forEntry($entry): ?string
    {
        if (! is_object($entry) || ! method_exists($entry, 'collectionHandle')) {
            return null;
        }

        $collection = $entry->collectionHandle();

        if (method_exists($entry, 'isRoot') && ! $entry->isRoot()) {
            $site = method_exists($entry, 'locale') ? (string) $entry->locale() : null;
            $localization = $this->localizationWorkflowFor($collection, $site);

            if ($localization !== null) {
                return $localization;
            }
        }

        return $this->forCollection($collection);
    }

    /**
     * The localization workflow (`detail: translation`) that governs a
     * collection — and, when a site is named, the one responsible for THAT site.
     *
     * Several localization workflows can share a collection: a translation
     * manager and a plain-language manager are both about getting an existing
     * entry into further sites, they just answer to different services. What
     * separates them is the site each one claims via
     * `metadata.plain_language_site`. So the lookup is, in order:
     *
     *   1. the workflow claiming $site — a `simple` version belongs to the
     *      plain-language manager, never to the translation manager;
     *   2. the workflow claiming NO site — the general translation manager owns
     *      every site nobody claimed;
     *   3. first-wins, so a single-workflow setup behaves as it always did.
     *
     * @param  string|null  $site  The version's own site handle, when known.
     * @param  string|null  $excluding  Workflow to skip — used to ask "does
     *                                  SOMEONE ELSE own the site distribution?"
     */
    public function localizationWorkflowFor(string $collection, ?string $site = null, ?string $excluding = null): ?string
    {
        $candidates = [];

        foreach ($this->loader->all() as $name) {
            if ($name === $excluding) {
                continue;
            }

            $raw = $this->loader->raw($name);

            if (! $this->isLocalizationWorkflow($raw)
                || ! in_array($collection, (array) ($raw['collections'] ?? []), true)) {
                continue;
            }

            $candidates[$name] = $this->plainLanguageSite($raw);
        }

        if ($candidates === []) {
            return null;
        }

        if ($site !== null) {
            foreach ($candidates as $name => $claimed) {
                if ($claimed !== null && $claimed === $site) {
                    return $name;
                }
            }
        }

        foreach ($candidates as $name => $claimed) {
            if ($claimed === null) {
                return $name;
            }
        }

        return (string) array_key_first($candidates);
    }

    /**
     * The site a localization workflow claims as its plain-language target.
     *
     * Declaring `metadata.plain_language_site` is what MAKES a workflow the
     * plain-language manager: it names the one site this manager writes into,
     * which is why the site is never asked for at runtime, and it is what routes
     * the request to the asynchronous rewrite service instead of DeepL (see
     * WorkflowController::translatorFor()). One key, two consequences —
     * deliberately, so there is no second `mode:` switch that could disagree
     * with it.
     *
     * @param  array<string, mixed>  $raw
     */
    public function plainLanguageSite(array $raw): ?string
    {
        $handle = $raw['metadata']['plain_language_site'] ?? null;

        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    /**
     * Whether this workflow is the plain-language manager — see
     * plainLanguageSite() for why the target site is the marker.
     *
     * @param  array<string, mixed>  $raw
     */
    public function isPlainLanguageWorkflow(array $raw): bool
    {
        return $this->plainLanguageSite($raw) !== null;
    }

    /**
     * Top-level field handles a finished rewrite empties on the plain-language
     * version (`metadata.plain_language_clear_fields`).
     *
     * A localization is created as a FULL copy, so every field the rewrite has no
     * answer for keeps the source's German text. For most of them that is the
     * right call — they are structure, or they are invisible. For some it is not:
     * a block of scientific references under a page written in plain language is
     * the opposite of plain language, and no rewrite will ever produce one,
     * because such a field is not part of the document the service answers with.
     *
     * Which fields those are is a PROJECT's decision, not the addon's. The addon
     * has no business knowing that this project calls its source list `dd_text`,
     * so the handles are configured per workflow and nothing here is hardcoded —
     * the same stance siteFields() takes, and for the same reason.
     *
     * Empty for a workflow that is not the plain-language manager, whatever its
     * YAML says. The key describes what happens to a REWRITE, and a translation
     * workflow produces none: its English version keeps its sources, because
     * DeepL answers per field and a translated reference list is still a correct
     * reference list. The writer refuses the combination as well (see
     * WorkflowDefinitionWriter), so this is the second of two locks — cheap, and
     * it also covers YAML that was never written through the editor.
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, string>
     */
    public function plainLanguageClearFields(array $raw): array
    {
        if (! $this->isPlainLanguageWorkflow($raw)) {
            return [];
        }

        return collect($raw['metadata']['plain_language_clear_fields'] ?? [])
            ->filter(fn ($handle) => is_string($handle) && $handle !== '')
            ->values()
            ->all();
    }

    /**
     * The site a workflow translates FROM (`metadata.translation_source_site`),
     * or null for "the entry's origin".
     *
     * It exists because the source is a decision, not a fact: a translation
     * manager may well want to translate out of the plain-language version
     * rather than out of the German original. Null means "not configured" — see
     * sourceSiteFor() for what happens then.
     *
     * @param  array<string, mixed>  $raw
     */
    public function sourceSite(array $raw): ?string
    {
        $handle = $raw['metadata']['translation_source_site'] ?? null;

        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    /**
     * Field handles a translation run leaves alone (`metadata.translation_exclude_fields`).
     *
     * Lives here because this class owns reading workflow metadata, and because two
     * readers of one key drift: the controller asks it for the click-driven run and the
     * payload preview, PlainLanguageSubmission for the scheduled retry, and all three
     * have to answer the same. Workflow-level only — there is no per-transition
     * override.
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, string>
     */
    public function translationExcludeFields(array $raw): array
    {
        return array_values(array_filter(
            (array) ($raw['metadata']['translation_exclude_fields'] ?? []),
            fn ($handle) => is_string($handle) && $handle !== '',
        ));
    }

    /**
     * The top-level fields that are this entry's content under this workflow
     * (`metadata.content_fields`, else auto-detection — see ContentFieldResolver).
     *
     * Per entry rather than per workflow: one workflow can govern collections
     * with different blueprints, and only the entry's own blueprint says which
     * of the configured fields exist.
     *
     * @param  array<string, mixed>  $raw
     * @param  \Statamic\Contracts\Entries\Entry  $entry
     * @return array<int, string>
     */
    public function contentFields(array $raw, $entry): array
    {
        return ContentFieldResolver::for($entry, $raw);
    }

    /**
     * The site a workflow reads from for one particular post — THE definition of
     * "the source", used by the run, the detail view's left panel, the payload
     * preview, the site sets and the derived state.
     *
     * Three steps:
     *
     *   1. `metadata.translation_source_site`, if configured. It is returned even
     *      when the post has no version there — the caller decides, and for a
     *      transition that means refusing rather than translating something else.
     *   2. otherwise the MAIN site (Statamic's default site), which is where
     *      editors author. That is the plain-language-free default a translation
     *      manager wants: German in, foreign language out.
     *   3. otherwise the post's own origin — a post authored directly in another
     *      site has no main-site version, and its origin is the only source there
     *      is. Refusing here would make such a post untranslatable forever.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $groupLocales  Sites the post exists in.
     */
    public function sourceSiteFor(array $raw, array $groupLocales, string $originSite): string
    {
        if (($configured = $this->sourceSite($raw)) !== null) {
            return $configured;
        }

        $main = (string) (Site::default()?->handle() ?? '');

        return $main !== '' && in_array($main, $groupLocales, true) ? $main : $originSite;
    }

    /**
     * The sites a localization workflow is responsible for: the site it reads FROM,
     * plus the sites it writes INTO. Every other site is somebody else's business —
     * its pivot has no column for them, and a stored state on one of them is a
     * leftover (see PruneStatesCommand).
     *
     * The source comes first, so a pivot reads left to right as "out of this, into
     * those".
     *
     * Which site the source is follows the SETTING, not the main site: with
     * `translation_source_site: simple` the plain-language version is the one that
     * is ready to be translated from, and the German original drops out of that
     * manager entirely (it is the post manager's subject, and the plain-language
     * manager's target). A site another manager claims stays in when it is THIS
     * manager's source — one version holds a state per workflow, so the same
     * `simple` entry is "approved" in the plain-language manager and "source ready"
     * in the translation manager without the two touching. And the main site drops
     * out unless it IS the source: you never translate into the site a post was
     * authored in.
     *
     * A workflow that distributes nothing (no `detail: translation`) is not
     * restricted here and gets every site; whether its LISTING narrows the columns
     * is a display question and stays in the controller.
     *
     * @return array<int, string>
     */
    public function managedSites(string $workflow): array
    {
        $handles = Site::all()->map->handle()->values()->all();
        $raw = $this->loader->raw($workflow);

        if (! $this->isLocalizationWorkflow($raw)) {
            return $handles;
        }

        $claimed = $this->plainLanguageSite($raw);
        // Same rule as sourceSiteFor(), minus its per-post last step: this list is
        // decided once for the whole workflow, so there is no single post whose
        // origin could stand in. Configured source, else the main site.
        $main = Site::default()?->handle();
        $source = $this->sourceSite($raw) ?? $main;

        $foreign = collect($raw['collections'] ?? [])
            ->flatMap(fn (string $collection) => collect($this->claimedSites($collection))
                ->reject(fn (string $owner) => $owner === $workflow)
                ->keys())
            ->unique()
            ->all();

        return collect($handles)
            ->filter(fn (string $site) => $site === $source
                // A claimed target means exactly two sites: source and the one site
                // this manager produces.
                || ($claimed !== null
                    ? $site === $claimed
                    // Everything else this manager may produce: not claimed by
                    // somebody else, and not the authoring site.
                    : ! in_array($site, $foreign, true) && $site !== $main))
            ->sortBy(fn (string $site) => $site === $source ? -1 : 0)
            ->values()
            ->all();
    }

    /**
     * Sites that a localization workflow of this collection claims for itself,
     * as `site handle => workflow name`.
     *
     * This is what makes "the translation manager can no longer produce plain
     * language" a consequence rather than a second setting: a claimed site is
     * not a target any other manager may offer.
     *
     * @return array<string, string>
     */
    public function claimedSites(string $collection): array
    {
        $claims = [];

        foreach ($this->loader->all() as $name) {
            $raw = $this->loader->raw($name);

            if (! $this->isLocalizationWorkflow($raw)
                || ! in_array($collection, (array) ($raw['collections'] ?? []), true)) {
                continue;
            }

            $site = $this->plainLanguageSite($raw);

            if ($site !== null) {
                $claims[$site] = $name;
            }
        }

        return $claims;
    }

    /**
     * Place handles that unlock publishing (metadata.allow_publish === true).
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, string>
     */
    public function publishPlaces(array $raw): array
    {
        return collect($raw['places'] ?? [])
            ->filter(fn ($place) => ($place['metadata']['allow_publish'] ?? false) === true)
            ->keys()
            ->all();
    }

    /**
     * The place that means "this is finished" — the target for an entry that was
     * already live before the workflow governed it. Returns null when the
     * workflow declares none, in which case such entries stay in the initial
     * place. Every step is checked against the real place list, so this can
     * never hand back a handle the workflow doesn't define.
     *
     * `metadata.seed_published_place` exists because "finished" is not always a
     * publish place: the translation workflow ends in `approved` and has no
     * `allow_publish` at all.
     *
     * @param  array<string, mixed>  $raw
     */
    public function donePlace(array $raw): ?string
    {
        $places = $raw['places'] ?? [];
        $explicit = $raw['metadata']['seed_published_place'] ?? null;

        if (is_string($explicit) && array_key_exists($explicit, $places)) {
            return $explicit;
        }

        $publish = $this->publishPlaces($raw)[0] ?? null;

        return $publish !== null && array_key_exists($publish, $places) ? $publish : null;
    }

    /**
     * The LAST place the definition declares — the end of the flow as it is listed
     * in the settings editor.
     *
     * Order, not a flag: the places table in the editor is the picture an operator
     * has of the workflow, and its last row is what "this is through" means to them.
     *
     * The cost of that is real and worth knowing: append a place that happens
     * chronologically after the end — "being translated", say — and the flow's
     * "done" silently moves with it, taking every reader of sourceIsReady() along.
     * The other end of the flow was read positionally too and stopped being
     * (TransitionActions::START_LOCALIZATION names the creating step now); this one
     * still is.
     *
     * Not to be confused with donePlace(), which answers "where does a backfill put
     * an entry whose work already happened" and may be steered by
     * `metadata.seed_published_place`. The two coincide in every shipped definition;
     * this one is a statement about the diagram, that one about seeding.
     *
     * Two kinds of place are subtracted first, for the same reason and with
     * different origins:
     *
     * - the injected `pending_revision`, which DefinitionLoader APPENDS — a plain
     *   array_key_last() would return a place nobody put there, and a workflow
     *   whose collection has revisions on would suddenly end in "Unpublished
     *   changes";
     * - the ageing places the author configured in `metadata.sla`. Those are
     *   ordinary places now and will usually be declared AFTER the publish place,
     *   because that is where they belong in the picture. Left in, "Outdated"
     *   would become the flow's "done" — and `sourceIsReady()` would then hand
     *   every localization manager its start button on a stale post;
     * - anything the author marked `branch: true`. That flag already says "not a
     *   step on the way, a turn-off from it", and ProgressSteps has always read it
     *   that way. Honouring it here as well is what keeps an ageing place harmless
     *   BEFORE it is wired into `metadata.sla` — declared but not yet configured,
     *   it would otherwise silently become the end of the flow.
     *
     * @param  array<string, mixed>  $raw
     */
    public function finalPlace(array $raw): ?string
    {
        $places = collect($raw['places'] ?? [])
            ->reject(fn ($place, $handle) => in_array((string) $handle, array_merge(
                WorkflowDefinitionWriter::RESERVED_PLACE_HANDLES,
                SlaSettings::places($raw),
            ), true))
            ->reject(fn ($place) => is_array($place) && ProgressSteps::isBranch($place))
            ->all();

        $last = array_key_last($places);

        return $last === null ? null : (string) $last;
    }

    /**
     * Whether a version is finished as far as ITS OWN workflow is concerned — the
     * precondition for translating out of it.
     *
     * The question has to be asked in the workflow that owns the version, not in the
     * one asking: the `simple` version belongs to the plain-language manager (done =
     * `plain_approved`), the German one to the post manager (done = `published`). So
     * this resolves the home workflow the same way the entry editor and the version
     * pills do, via forEntry().
     *
     * Two fallbacks to the entry's own published flag:
     *
     * - no workflow governs the version at all;
     * - the home workflow IS the one asking. That happens when a collection is
     *   governed by nothing but a localization workflow, and there the source sits in
     *   the initial place by definition (see derivedState) — asking it for its final
     *   place would deadlock the manager forever.
     *
     * A revision-pending state counts as ready: the live version is published, an
     * unpublished working copy exists alongside it. canPublish() tolerates it for the
     * same reason.
     *
     * @param  string  $askingWorkflow  the workflow that wants to translate
     */
    public function sourceIsReady($source, string $askingWorkflow): bool
    {
        return $this->sourceReadiness($source, $askingWorkflow)['ready'];
    }

    /**
     * sourceIsReady() with the parts a caller needs to explain a "no".
     *
     * `workflow` is null where readiness came from the entry's own published flag
     * rather than from a workflow's final place — the two cases need different
     * wording, and deciding that here keeps the branching in one place.
     *
     * @param  string  $askingWorkflow  the workflow that wants to translate
     * @return array{ready: bool, workflow: string|null, state: string|null, required: string|null}
     */
    public function sourceReadiness($source, string $askingWorkflow): array
    {
        $unknown = ['ready' => false, 'workflow' => null, 'state' => null, 'required' => null];

        if (! is_object($source) || ! method_exists($source, 'id')) {
            return $unknown;
        }

        $home = $this->forEntry($source);

        if ($home === null || $home === $askingWorkflow) {
            $published = ! method_exists($source, 'published') || (bool) $source->published();

            return ['ready' => $published, 'workflow' => null, 'state' => null, 'required' => null];
        }

        $raw = $this->loader->raw($home);
        $final = $this->finalPlace($raw);
        $state = $this->stateFor($source, $home, $raw);

        if ($final === null) {
            return ['ready' => true, 'workflow' => $home, 'state' => $state, 'required' => null];
        }

        // The revision-pending state counts as ready. Recognised by its handle as
        // well as by the place's metadata: the place is only injected while the
        // collection has revisions on, and a stored row outlives that switch — so
        // the metadata check alone would call a published post with an open working
        // copy unfinished.
        $revisionPending = $state === DefinitionLoader::REVISION_PLACE
            || $this->isRevisionPending($raw, $state);

        return [
            'ready' => $state === $final || $revisionPending,
            'workflow' => $home,
            'state' => $state,
            'required' => $final,
        ];
    }

    /**
     * The initial place, guaranteed to exist. Falls back to the first declared
     * place rather than a literal like 'draft' — a handle no workflow defines
     * would leave the entry stuck with no outgoing transition.
     *
     * @param  array<string, mixed>  $raw
     */
    public function initialPlace(array $raw): string
    {
        $places = $raw['places'] ?? [];
        $initial = $raw['initial_place'] ?? null;

        if (is_string($initial) && array_key_exists($initial, $places)) {
            return $initial;
        }

        return (string) (array_key_first($places) ?? 'draft');
    }

    /**
     * Whether the workflow's subject is a localization relative to its source —
     * a translation/distribution flow rather than an authoring one.
     *
     * `metadata.detail: translation` is the marker. It already selects the
     * source-vs-target detail view, and it is exactly the same statement: this
     * workflow is about getting an existing entry into further sites. Everything
     * that follows from that lives behind this one predicate — it never creates
     * entries, and it owns the site distribution instead of the editor.
     *
     * @param  array<string, mixed>  $raw
     */
    public function isLocalizationWorkflow(array $raw): bool
    {
        return ($raw['metadata']['detail'] ?? null) === self::DETAIL_TRANSLATION;
    }

    /**
     * True when some OTHER workflow (with `detail: translation`) also
     * governs this collection. The post manager uses this to decide
     * whether to render only its main-site column in the listing — as
     * soon as a translation manager exists for the same collection, the
     * other language columns are that manager's responsibility.
     */
    public function hasCompanionLocalizationWorkflow(string $selfName, string $collection): bool
    {
        return $this->localizationWorkflowFor($collection, null, $selfName) !== null;
    }

    /**
     * Blueprint field handles that let an editor pick sites by hand (e.g. a
     * taxonomy of portals). A localization workflow owns that decision, so these
     * are forced read-only in the entry editor.
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, string>
     */
    public function siteFields(array $raw): array
    {
        return collect($raw['metadata']['site_fields'] ?? [])
            ->filter(fn ($handle) => is_string($handle) && $handle !== '')
            ->values()
            ->all();
    }

    /**
     * Whether the POST is public — read from its origin, whichever version was
     * handed in.
     *
     * "Statamic published" is the ceiling for every localization workflow: nothing
     * gets distributed out of a post that is not live yet. The origin carries that
     * answer because it is the version the authoring workflow publishes; the
     * localizations are drafts by design (see derivedState).
     */
    public function postIsPublished($entry): bool
    {
        if (! is_object($entry) || ! method_exists($entry, 'root')) {
            return false;
        }

        $root = $entry->root() ?? $entry;

        return method_exists($root, 'published') ? (bool) $root->published() : false;
    }

    /**
     * The state an entry sits in when the store holds no row for it yet.
     *
     * THIS IS THE ONLY PLACE THAT ANSWERS THAT QUESTION. Both the read paths
     * (listings, summary, fieldtype, publish gate) and the seeder use it, so a
     * displayed state and a persisted one can never disagree — that divergence
     * was what made a transition button appear enabled and then fail.
     *
     * Returns NULL for "not part of this workflow yet", which is a real answer and
     * not the absence of one: a localization workflow only starts once the post is
     * published. Statamic's published flag is the ceiling — the workflow may never be
     * further along than the post itself is public, so an unpublished draft gets no
     * state at all rather than sitting in `source_ready` claiming to be ready. The
     * pivot renders such a cell empty, no transition leads out of it, and the seeder
     * writes no row for it.
     *
     * Deliberately the ORIGIN's flag, not the version's own: localizations are
     * created as drafts and the localization workflows have no `allow_publish` place
     * at all, so a plain-language version is never Statamic-published. Asking for its
     * own flag would keep every localization manager empty forever.
     *
     * Two more rules on top, because "already done" means different things:
     *
     * - A localization-oriented workflow (`metadata.detail: translation`) asks
     *   whether the sites IT is responsible for already exist. If they do, the
     *   work this workflow tracks has happened — every version of the post
     *   counts as done, the origin included. The published flag is irrelevant
     *   here: a post can be live on its main site and still unpublished on the
     *   others while being, as far as this workflow is concerned, distributed.
     *   Which sites it is responsible for is the same question the site pickers
     *   answer: the plain-language manager owns exactly its claimed site, the
     *   translation manager everything nobody claimed, minus its own source.
     *   Asking "does the post span more than one site" instead would make an
     *   English translation count as plain language and vice versa.
     * - Every other workflow derives from the published flag.
     *
     * Bulk callers that already know the group pass `$groupLocales` (the site
     * handles the logical post exists in) to skip the per-entry lookup. It
     * replaces an earlier "spans multiple sites" boolean, which cannot answer a
     * per-site question — and two hints that can disagree are worse than one.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>|null  $groupLocales
     */
    public function derivedState(array $raw, $entry, ?array $groupLocales = null): ?string
    {
        $initial = $this->initialPlace($raw);

        if ($this->isLocalizationWorkflow($raw)) {
            if (! $this->postIsPublished($entry)) {
                return null;
            }

            $groupLocales ??= $this->groupLocales($entry);

            // The version this workflow reads FROM is never "done": it is what
            // everything else is made of, and it has to keep offering the step that
            // starts the next language. Without this exception the source counted as
            // distributed the moment the first translation existed, and the second
            // one could not be started from it at all.
            $origin = is_object($entry) && method_exists($entry, 'root')
                ? (string) ($entry->root()?->locale() ?? '')
                : '';
            $site = is_object($entry) && method_exists($entry, 'locale') ? (string) $entry->locale() : '';

            if ($site !== '' && $site === $this->sourceSiteFor($raw, $groupLocales, $origin)) {
                return $initial;
            }

            return $this->ownsAnySite($raw, $entry, $groupLocales)
                ? ($this->donePlace($raw) ?? $initial)
                : $initial;
        }

        if (! is_object($entry) || ! method_exists($entry, 'published') || ! $entry->published()) {
            return $initial;
        }

        return $this->donePlace($raw) ?? $initial;
    }

    /**
     * Whether any of the sites this localization workflow is responsible for
     * already carries a version of the post.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $groupLocales
     */
    private function ownsAnySite(array $raw, $entry, array $groupLocales): bool
    {
        $claimed = $this->plainLanguageSite($raw);

        if ($claimed !== null) {
            return in_array($claimed, $groupLocales, true);
        }

        $collection = is_object($entry) && method_exists($entry, 'collectionHandle')
            ? (string) $entry->collectionHandle()
            : '';

        $foreign = $collection === '' ? [] : array_keys($this->claimedSites($collection));
        $origin = is_object($entry) && method_exists($entry, 'root')
            ? (string) ($entry->root()?->locale() ?? '')
            : '';
        // The source is not itself evidence of distribution, so it drops out — same
        // definition the run and the site pickers use.
        $source = $this->sourceSiteFor($raw, $groupLocales, $origin);

        return collect($groupLocales)
            ->reject(fn (string $site) => in_array($site, $foreign, true))
            ->reject(fn (string $site) => $site === $source)
            // Nor is the ORIGIN. It exists because somebody wrote the post, not
            // because this manager distributed it — and once the source is a site
            // other than the origin (`translation_source_site: simple`), leaving it
            // in made every untranslated post count as finished: its German version
            // was the evidence.
            ->reject(fn (string $site) => $site === $origin)
            ->isNotEmpty();
    }

    /**
     * The site handles the entry's logical post exists in (origin included).
     *
     * @return array<int, string>
     */
    public function groupLocales($entry): array
    {
        if (! is_object($entry) || ! method_exists($entry, 'root')) {
            return [];
        }

        $root = $entry->root();

        if ($root === null) {
            return [];
        }

        return collect([(string) $root->locale()])
            ->merge(method_exists($root, 'descendants') ? array_keys($root->descendants()->all()) : [])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The current state of an entry in a workflow: the stored one, or the derived
     * default when nothing is stored. Callers that need to know "what state is
     * this in" should use this rather than reading the store directly.
     *
     * NULL means "not part of this workflow" — see derivedState(). A stored row
     * still wins over that: a post somebody has already worked on keeps its state
     * even if it gets unpublished later, because the work happened.
     *
     * Pass `$raw` when it is already loaded to save a YAML read.
     *
     * @param  array<string, mixed>|null  $raw
     * @param  array<int, string>|null  $groupLocales
     */
    public function stateFor($entry, string $workflow, ?array $raw = null, ?array $groupLocales = null): ?string
    {
        $raw ??= $this->loader->raw($workflow);

        // An entry Statamic has not assigned an id to yet (the in-memory instance
        // behind the "create entry" form, before the first save) cannot have a
        // stored row — nothing could reference an id that does not exist yet.
        // Skipping straight to the derived default avoids handing the store a
        // null it is typed to reject, and derivedState() never reads the id.
        $id = is_object($entry) && method_exists($entry, 'id') ? $entry->id() : null;

        return ($id === null ? null : $this->states->get($id, $workflow))
            ?? $this->derivedState($raw, $entry, $groupLocales);
    }

    /**
     * Is this version's content behind the post it belongs to?
     *
     * The answer nobody could give before: the post's clock and the version's own
     * clock are both on the entries, so comparing them costs no query. It is what
     * makes "everything is out of date" visible without moving anything, which is
     * the whole point of the derived marker — a state change would rewrite history
     * for every version of every aged post.
     *
     * Three conditions, and the middle one is the one that stops the marker from
     * becoming noise:
     *
     * 1. The root IS the post. It has real places for being out of date, so it is
     *    never "stale" in this sense.
     * 2. Only a version that is FINISHED in its own workflow can be stale. One
     *    still being translated is in progress — flagging it would put a warning on
     *    every open job. A version that the cascade demoted is likewise not flagged:
     *    it already shows the place it fell back to, and saying both would be
     *    saying the same thing twice.
     * 3. The post's clock is newer than the version's own — see
     *    ContentTimestamp::isStale(), including the case of a version that has no
     *    clock of its own at all.
     *
     * @param  array<string, mixed>|null  $raw  the version's own workflow definition
     */
    public function contentStale($version, ?array $raw = null): bool
    {
        if (! is_object($version) || ! method_exists($version, 'root')) {
            return false;
        }

        $root = $version->root();

        if ($root === null || $root->id() === $version->id()) {
            return false;
        }

        $workflow = $this->forEntry($version);

        if ($workflow === null) {
            return false;
        }

        $raw ??= $this->loader->raw($workflow);
        $state = $this->stateFor($version, $workflow, $raw);

        if ($state === null) {
            return false;
        }

        $finished = array_merge($this->publishPlaces($raw), array_filter([$this->finalPlace($raw)]));

        if (! in_array($state, $finished, true)) {
            return false;
        }

        return app(ContentTimestamp::class)->isStale($version, $root);
    }

    /**
     * Whether the given entry may be published. Returns true (no gating) unless
     * a governing workflow declares publish places; then the entry's stored
     * workflow state must be a publish place, or a revision-pending place.
     * Strictly state-based — never inferred from the entry's own published flag.
     *
     * The revision-pending state is publishable because publishing from it is
     * exactly the intended `pending_revision -> published` step: it lets an
     * authorised user publish the working copy of an already-published entry.
     */
    public function canPublish($entry): bool
    {
        if (! $entry instanceof EntryContract) {
            return true;
        }

        $workflow = $this->forCollection($entry->collectionHandle());

        if ($workflow === null) {
            return true;
        }

        $raw = $this->loader->raw($workflow);
        $publishPlaces = $this->publishPlaces($raw);

        if ($publishPlaces === []) {
            return true;
        }

        $state = $this->stateFor($entry, $workflow, $raw);

        // No state at all cannot satisfy a publish place. Reachable only in theory:
        // this reads the AUTHORING workflow, and only a localization workflow ever
        // answers null.
        if ($state === null) {
            return false;
        }

        return in_array($state, $publishPlaces, true)
            || $this->isRevisionPending($raw, $state);
    }

    /**
     * A revision-pending place marks that an unpublished working copy exists
     * (injected by DefinitionLoader::withRevisionStep when revisions are on).
     *
     * @param  array<string, mixed>  $raw
     */
    public function isRevisionPending(array $raw, string $state): bool
    {
        return ($raw['places'][$state]['metadata']['revision_pending'] ?? false) === true;
    }

    /**
     * Whether a subject sitting in this place should show the plain-language
     * manager's loading state instead of its content — see
     * WorkflowDefinitionWriter's validation of `pending_rewrite_spinner` for why
     * it only means something alongside an open PlainLanguageJobStore entry.
     *
     * @param  array<string, mixed>  $raw
     */
    public function showsPendingRewriteSpinner(array $raw, string $state): bool
    {
        return PlaceActions::showsPendingRewriteSpinner($raw['places'][$state] ?? []);
    }

    /**
     * How long to wait, in minutes, before the instance page asks the server
     * whether anything changed and offers a reload — see PlaceActions::REFRESH_INTERVAL.
     * Null when this place carries no such action.
     *
     * @param  array<string, mixed>  $raw
     */
    public function refreshIntervalMinutesFor(array $raw, string $state): ?int
    {
        return PlaceActions::refreshIntervalMinutes($raw['places'][$state] ?? []);
    }
}
