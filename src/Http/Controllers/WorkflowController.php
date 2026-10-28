<?php

namespace TakepartMedia\Workflows\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use Statamic\Http\Controllers\CP\CpController;
use TakepartMedia\Workflows\Http\Controllers\Concerns\WorkflowControllerHelpers;
use TakepartMedia\Workflows\Content\ContentTimestamp;
use TakepartMedia\Workflows\Content\EntryContentPreview;
use TakepartMedia\Workflows\Content\EntrySnapshot;
use TakepartMedia\Workflows\Content\SnapshotDiffer;
use TakepartMedia\Workflows\Support\Privileges;
use TakepartMedia\Workflows\Support\EventLogSuppressor;
use TakepartMedia\Workflows\Translation\DeeplConfig;
use TakepartMedia\Workflows\Translation\DeeplTranslator;
use TakepartMedia\Workflows\Translation\LanguageCodes;
use TakepartMedia\Workflows\Translation\PlainLanguageConfig;
use TakepartMedia\Workflows\Translation\PlainLanguageLeafDocument;
use TakepartMedia\Workflows\Translation\PlainLanguageJobStore;
use TakepartMedia\Workflows\Translation\PlainLanguageSubmission;
use TakepartMedia\Workflows\Translation\PlainLanguageTranslator;
use TakepartMedia\Workflows\Translation\TranslatableText;
use TakepartMedia\Workflows\Translation\TranslationFailed;
use TakepartMedia\Workflows\Translation\TranslationRunner;
use TakepartMedia\Workflows\Translation\TranslationService;
use TakepartMedia\Workflows\Translation\Translator;
use TakepartMedia\Workflows\Workflow\AssignmentCandidates;
use TakepartMedia\Workflows\Workflow\CommentMentionNotifier;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\InstanceUrl;
use TakepartMedia\Workflows\Workflow\FailedTranslationRetry;
use TakepartMedia\Workflows\Workflow\TransitionActions;
use TakepartMedia\Workflows\Workflow\TransitionGates;
use TakepartMedia\Workflows\Workflow\WorkflowDefinitionWriter;
use TakepartMedia\Workflows\Workflow\Filters\AssigneeFilter;
use TakepartMedia\Workflows\Workflow\Filters\AuthorFilter;
use TakepartMedia\Workflows\Workflow\Filters\SiteFilter;
use TakepartMedia\Workflows\Workflow\Filters\StaleFilter;
use TakepartMedia\Workflows\Workflow\Filters\StateFilter;
use TakepartMedia\Workflows\Workflow\Filters\WaitingFilter;
use TakepartMedia\Workflows\Workflow\PersonalWorkItems;
use TakepartMedia\Workflows\Workflow\ProgressSteps;
use TakepartMedia\Workflows\Workflow\PublishedFlag;
use TakepartMedia\Workflows\Workflow\SelfAssignmentGuard;
use TakepartMedia\Workflows\Workflow\StatsPresets;
use TakepartMedia\Workflows\Workflow\SubjectLinks;
use TakepartMedia\Workflows\Workflow\WorkflowAssigneeStore;
use TakepartMedia\Workflows\Workflow\WorkflowComment;
use TakepartMedia\Workflows\Workflow\WorkflowCommentStore;
use TakepartMedia\Workflows\Workflow\WorkflowEvent;
use TakepartMedia\Workflows\Workflow\WorkflowEventStore;
use TakepartMedia\Workflows\Workflow\WorkflowNotifier;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;
use TakepartMedia\Workflows\Workflow\SourceViewRules;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;
use TakepartMedia\Workflows\Workflow\WorkflowTransitioner;
use TakepartMedia\Workflows\Workflow\WorkflowWatchStore;

/**
 * Serves the CP workflow manager: listings, the instance summary, and the
 * mutation endpoints behind them. Every subject is a real Statamic entry —
 * the addon never invents content of its own.
 */
class WorkflowController extends CpController
{
    use WorkflowControllerHelpers;

    /**
     * Event types that record a plain save rather than a workflow step. The
     * History diff skips them when anchoring a step's comparison point, so a
     * transition keeps showing everything that changed since the previous STEP
     * even when several saves happened in between.
     */
    private const SAVE_TYPES = ['draft_save'];

    /** The "waiting longer than" filter's steps, in days. */
    private const WAITING_DAYS = [3, 7, 14, 30];

    /**
     * Per-request memos for the unread-comment lookups, keyed by workflow name /
     * subject id. Both exist because `listing()` builds its row set twice per
     * request — see newCommentCounts().
     *
     * @var array<string, array<string, int>>
     */
    private array $newCommentCountsCache = [];

    /** @var array<string, int> */
    private array $newCommentCountCache = [];

    /** @var array<string, array<string, int>> */
    private array $commentCountsCache = [];

    /**
     * entryGroups() per workflow, for matchingPostCount() only: the stat row
     * asks it four times on one dashboard load (three cards plus last week's
     * comparison), mostly about the same workflows.
     *
     * @var array<string, \Illuminate\Support\Collection>
     */
    private array $postGroupsCache = [];

    private function currentUserName(): string
    {
        return User::current()?->name() ?? User::current()?->email() ?? $this->t('event_actor_current_user');
    }

    // ---- Listing data ------------------------------------------------------

    /**
     * Resolve the listing rows for a workflow. A workflow that governs no
     * collection has no subjects, so it lists nothing — the addon never
     * substitutes demo content.
     *
     * `$collection` narrows an entry-backed workflow to a single one of its
     * collections — that's what the per-collection tabs on the manager page send.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(?string $workflow, ?string $collection = null): array
    {
        return $this->isEntryWorkflow($workflow)
            ? $this->entryRows($workflow, $collection)
            : [];
    }

    private function isEntryWorkflow(?string $workflow): bool
    {
        if (! $workflow) {
            return false;
        }

        $loader = app(DefinitionLoader::class);

        if (! in_array($workflow, $loader->all(), true)) {
            return false;
        }

        return ! empty($loader->raw($workflow)['collections'] ?? []);
    }

    /**
     * Build pivot listing rows from the real entries of an entry-backed workflow:
     * one row per logical post (grouped by origin root), with one field per site
     * holding that site-version's state (or null). State comes from the persisted
     * store (default: the published-derived state). No SLA.
     *
     * Pass `$collection` to restrict the rows to one of the workflow's
     * collections (the per-collection tabs). A handle the workflow doesn't govern
     * falls back to all of them rather than yielding an empty listing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function entryRows(string $workflow, ?string $collection = null): array
    {
        $raw = app(DefinitionLoader::class)->raw($workflow);
        $states = app(WorkflowStateStore::class)->statesFor($workflow);
        $enteredAt = app(WorkflowStateStore::class)->enteredAtLabels($workflow);
        $enteredTimes = app(WorkflowStateStore::class)->enteredAtTimes($workflow);
        $openPlaces = $this->openPlaces($raw);
        $assignees = app(WorkflowAssigneeStore::class)->byWorkflow($workflow);
        $sites = $this->listingSitesFor($workflow);
        $resolver = app(WorkflowResolver::class);
        $isLocalization = $resolver->isLocalizationWorkflow($raw);

        return $this->entryGroups($workflow, $collection)
            ->map(function ($group, $rootId) use ($raw, $states, $enteredAt, $enteredTimes, $openPlaces, $assignees, $sites, $workflow, $resolver, $isLocalization) {
                $byLocale = $group->keyBy(fn ($entry) => $entry->locale());
                $root = $group->first(fn ($entry) => $entry->isRoot()) ?? $group->first()->root();

                // The group already names every site this post exists in, so the
                // derived state needs no per-entry descendant lookup.
                $groupLocales = $byLocale->keys()->all();

                $rowEntry = $isLocalization
                    ? $this->localizationRowEntry($raw, $workflow, $byLocale, $groupLocales, $root)
                    : $root;

                $row = [
                    'id' => $rootId,
                    'url' => $this->instanceUrl((string) $rowEntry->id(), $workflow),
                    // The post's own title, from its origin: that is what an editor
                    // recognises the row by, whichever version this manager reads.
                    'title' => (string) $root->value('title'),
                ];

                $assigneeIds = [];
                $authorIds = [];
                $waitingSince = null;
                $stateList = [];
                $urls = [];
                $entered = [];
                $previewUrls = [];
                $previewModes = [];
                $commentCounts = [];
                $newComments = [];
                $stale = [];

                foreach ($sites as $site) {
                    $localization = $byLocale->get($site);

                    if (! $localization) {
                        $row[$site] = null;

                        continue;
                    }

                    // A null state means the version is not part of this workflow yet
                    // (an unpublished post in a localization manager). The cell then
                    // renders empty, exactly like a site without a version — and it
                    // stays out of `_states`, so the state filter does not offer a
                    // value that no row displays.
                    $state = $states[$localization->id()] ?? $this->defaultState($raw, $localization, $groupLocales);
                    $row[$site] = $state;

                    if ($state !== null) {
                        $stateList[] = $state;
                    }

                    // Link the cell to this site-version's workflow detail page.
                    $urls[$site] = $this->instanceUrl($localization->id(), $workflow);
                    // The state badge's tooltip: since when it sits in that place.
                    if (isset($enteredAt[$localization->id()])) {
                        $entered[$site] = $enteredAt[$localization->id()];
                    }
                    // The longest any version of the post has been waiting —
                    // only in places that lead somewhere: sitting in "published"
                    // for a month is not waiting.
                    $time = $enteredTimes[$localization->id()] ?? null;
                    if ($time !== null && in_array($state, $openPlaces, true) && ($waitingSince === null || $time->lt($waitingSince))) {
                        $waitingSince = $time;
                    }
                    // The cell's "Live preview" button, null where there is none.
                    $previewUrls[$site] = $this->previewUrlFor($localization);
                    $previewModes[$site] = EntryPreviewController::modeFor($localization);
                    $assigneeIds = array_merge($assigneeIds, $this->assigneeIds($localization, $workflow, $assignees));
                    // Read per version: a localization may name its own author
                    // or inherit the origin's — authors() covers both.
                    $authorIds = array_merge($authorIds, $localization->authors()->map(fn ($a) => (string) $a)->all());

                    // Per site, not per row: in a pivot the useful information is
                    // WHICH site-version was commented on. The cell renders the total
                    // as a grey flag and the unread part as `+2` beside it.
                    if ($total = $this->commentCounts($workflow)[$localization->id()] ?? 0) {
                        $commentCounts[$site] = $total;
                    }

                    if ($count = $this->newCommentCount($localization, $workflow)) {
                        $newComments[$site] = $count;
                    }

                    // Behind the post it belongs to: shown as a small marker next to
                    // the state, never as a state of its own. Both timestamps sit on
                    // the entries, so this costs no query.
                    if ($resolver->contentStale($localization)) {
                        $stale[$site] = true;
                    }
                }

                $updatedAt = app(ContentTimestamp::class)->for($root);

                $row['urls'] = $urls;
                $row['entered_at'] = $entered;
                $row['preview_urls'] = $previewUrls;
                $row['preview_modes'] = $previewModes;
                $row['comment_counts'] = $commentCounts;
                $row['new_comments'] = $newComments;
                $row['stale'] = $stale;
                // Formatted for the cell, sortable via the private key below —
                // listing() sorts strings, and a localized date would sort by its
                // first character.
                $row['content_updated_at'] = $updatedAt?->isoFormat('L') ?? '';
                $row['_content_updated_at'] = $updatedAt?->getTimestamp() ?? 0;
                $row['_stale'] = $stale !== [];
                $row['_states'] = array_values(array_unique($stateList));
                $row['_assignee_ids'] = array_values(array_unique($assigneeIds));
                $row['_author_ids'] = array_values(array_unique($authorIds));
                $row['_waiting_since'] = $waitingSince?->getTimestamp();
                // What the stat row's presets match against — see
                // cellSubjectIds().
                $row['_subject_ids'] = $this->cellSubjectIds($group, $sites);

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * The posts of an entry-backed workflow as its pivot sees them: every entry
     * of its collections, grouped by origin root (one group = one row), minus
     * what a localization manager cannot work on.
     *
     * Split out of entryRows() so the dashboard's stat cards can count the rows
     * a preset leaves in the overview (matchingPostCount()) without building
     * every cell of every row first — the grouping and the source rule are what
     * decide whether a row exists, and they must be the same rule in both
     * places.
     *
     * Pass `$collection` to restrict the groups to one of the workflow's
     * collections (the per-collection tabs). A handle the workflow doesn't govern
     * falls back to all of them rather than yielding an empty listing.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, mixed>>
     */
    private function entryGroups(string $workflow, ?string $collection = null)
    {
        $raw = app(DefinitionLoader::class)->raw($workflow);
        $collections = $raw['collections'] ?? [];

        if ($collection !== null && in_array($collection, $collections, true)) {
            $collections = [$collection];
        }

        $resolver = app(WorkflowResolver::class);
        $isLocalization = $resolver->isLocalizationWorkflow($raw);

        return Entry::query()
            ->whereIn('collection', $collections)
            ->get()
            ->groupBy(fn ($entry) => $entry->root()->id())
            // A localization manager lists what it can actually work on: a post
            // without a version in its source site has nothing to translate FROM, so
            // it is not this manager's work — it belongs to whoever produces that
            // source. With no source configured this drops nothing (the fallback is
            // the post's own origin, which exists by definition); with
            // `translation_source_site: simple` the list collapses to the posts that
            // have an Einfache-Sprache version.
            ->filter(function ($group) use ($isLocalization, $resolver, $raw, $workflow) {
                if (! $isLocalization) {
                    return true;
                }

                $locales = $group->map(fn ($entry) => (string) $entry->locale())->all();
                $origin = (string) ($group->first()->root()?->locale() ?? '');
                $sourceSite = $resolver->sourceSiteFor($raw, $locales, $origin);

                if (! in_array($sourceSite, $locales, true)) {
                    return false;
                }

                // Having a source version is not enough — it must also have
                // finished ITS OWN workflow (published in the post manager,
                // `plain_approved` in the plain-language manager). A post still
                // mid-review has nothing worth translating yet, and the start
                // transition refuses it anyway (see readySourceSites()) — better
                // to not list it as work at all than to list it and then block it.
                $source = $group->first(fn ($entry) => (string) $entry->locale() === $sourceSite);

                return $source !== null && $resolver->sourceIsReady($source, $workflow);
            });
    }

    /**
     * The ids of a row's site-versions that have a CELL in the pivot — the
     * versions in one of the listed sites. A stat preset matches a row through
     * these and nothing else: a version the row does not show cannot be the
     * reason the row is in a filtered table.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $group
     * @param  array<int, string>  $sites
     * @return array<int, string>
     */
    private function cellSubjectIds($group, array $sites): array
    {
        return $group
            ->filter(fn ($entry) => in_array((string) $entry->locale(), $sites, true))
            ->map(fn ($entry) => (string) $entry->id())
            ->values()
            ->all();
    }

    /**
     * The "your source has moved on" notice for a localization's summary, or null
     * when there is nothing to say.
     *
     * A version can be finished in its own workflow and still be out of date: its
     * place is correct, its content is not. The listings show that as a small marker
     * next to the state; on the summary there is room to say what actually happened
     * and when, which is what makes it actionable — the editor needs to know whether
     * the source moved a day or a year ago before deciding to redo the work.
     *
     * @return array{source_at: string, version_at: string|null}|null
     */
    private function sourceChangedNotice($entry): ?array
    {
        if (! app(WorkflowResolver::class)->contentStale($entry)) {
            return null;
        }

        $root = $entry->root();
        $timestamps = app(ContentTimestamp::class);
        $sourceAt = $root === null ? null : $timestamps->for($root);

        if ($sourceAt === null) {
            return null;
        }

        // The version's OWN clock, never the inherited one: a version that has never
        // been approved has no date of its own, and saying "unchanged since <the
        // post's date>" would be exactly the wrong claim.
        $versionAt = $timestamps->own($entry);

        // Nur das Datum, wie das Feld selbst. Die Uhrzeit stand hier eine Weile,
        // weil zwei Stempel desselben Tages sonst identisch aussahen — das kann
        // seit dem Tagesvergleich in isStale() nicht mehr vorkommen: gleicher Tag
        // heißt gleichauf, und dann gibt es diese Meldung gar nicht.
        return [
            'source_at' => $sourceAt->isoFormat('L'),
            'version_at' => $versionAt?->isoFormat('L'),
        ];
    }

    /**
     * Which version a row in a LOCALIZATION manager's listing opens.
     *
     * The manager's own work first: if a version already exists in one of the sites
     * this manager produces, that is the thing an editor wants to reach — the
     * translation itself, with its state, its history and its comments. Only while
     * no such version exists is the source the useful destination, because then the
     * only thing to do on this row is start the localization.
     *
     * Sending you to the source unconditionally was the older behaviour and it read
     * as a bug: on a post that was translated months ago, clicking the row opened
     * the German (or plain-language) version and offered a start button that is
     * correctly blocked, with the finished translation nowhere in sight. The per-site
     * cells linked to it all along — the title did not.
     *
     * Target order follows managedSites(), which is the order of the columns, so a
     * manager producing several sites opens the leftmost one that exists rather than
     * an arbitrary pick.
     *
     * Falls back to the source version, then the root. The listing filters out posts
     * with no source version, so the last step only ever covers a definition whose
     * source site is not in the post's group at all.
     *
     * @param  array<string, mixed>  $raw
     * @param  \Illuminate\Support\Collection<string, mixed>  $byLocale
     * @param  array<int, string>  $groupLocales
     */
    private function localizationRowEntry(array $raw, string $workflow, $byLocale, array $groupLocales, $root)
    {
        $resolver = app(WorkflowResolver::class);
        $source = $resolver->sourceSiteFor($raw, $groupLocales, (string) $root->locale());

        foreach ($resolver->managedSites($workflow) as $site) {
            if ($site === $source) {
                continue;
            }

            if ($version = $byLocale->get($site)) {
                return $version;
            }
        }

        return $byLocale->get($source) ?? $root;
    }

    /**
     * Flat listing rows — one per entry localization (used for "My Queue", which
     * is not a pivot).
     *
     * @return array<int, array<string, mixed>>
     */
    private function entryRowsFlat(string $workflow): array
    {
        $raw = app(DefinitionLoader::class)->raw($workflow);
        $collections = $raw['collections'] ?? [];
        $states = app(WorkflowStateStore::class)->statesFor($workflow);
        $enteredAt = app(WorkflowStateStore::class)->enteredAtLabels($workflow);
        $assigneeMap = app(WorkflowAssigneeStore::class)->byWorkflow($workflow);
        $resolver = app(WorkflowResolver::class);
        $isLocalization = $resolver->isLocalizationWorkflow($raw);
        // The sites this manager reads and writes — the same set its pivot shows
        // columns for.
        $managedSites = $isLocalization ? $this->listingSitesFor($workflow) : [];

        return Entry::query()
            ->whereIn('collection', $collections)
            ->get()
            // Only entries that participate in THIS workflow: the collection's
            // owning workflow covers all of them; a secondary workflow (e.g.
            // translation for posts) only those that have an explicit stored
            // state — so post_update items don't leak into the translation queue.
            ->filter(fn ($entry) => isset($states[$entry->id()])
                || $this->workflowForCollection($entry->collectionHandle()) === $workflow)
            // Then the same source rule the big listing applies, per version rather
            // than per post: a localization manager's personal lists hold what it can
            // work on. Two things drop out — versions of a post that has no source to
            // translate FROM, and versions in a site this manager neither reads nor
            // writes. With `translation_source_site: simple` the German versions go
            // with the second rule, which also stops states left over from an earlier
            // source setting from haunting somebody's queue.
            ->filter(function ($entry) use ($isLocalization, $managedSites, $resolver, $raw, $states) {
                if (! $isLocalization) {
                    return true;
                }

                if (! in_array((string) $entry->locale(), $managedSites, true)) {
                    return false;
                }

                $locales = $resolver->groupLocales($entry);
                $origin = (string) ($entry->root()?->locale() ?? '');

                if (! in_array($resolver->sourceSiteFor($raw, $locales, $origin), $locales, true)) {
                    return false;
                }

                // No state means not part of this workflow — a personal queue is a
                // list of tasks, and there is no task without a state to act on.
                //
                // The STORE is asked first, and that order is the whole point: a
                // version somebody has already worked on stays on their list even
                // when the derivation would drop it. The derivation is strict on
                // purpose — derivedState() answers null for every version of an
                // unpublished post, because a localization workflow does not begin
                // before the post is public. Applied to a version that is already
                // being translated, that rule would take an in-progress task off
                // the assignee's queue because somebody unpublished the German
                // original. A stored row is evidence that the work exists; the
                // same reasoning PruneStatesCommand uses when it refuses to delete
                // rows with history behind them.
                //
                // `$states` has to be captured explicitly — this is a `function`,
                // not an `fn`. It was missing, isset() swallowed the undefined
                // variable without a warning, and the store was never consulted at
                // all: every such task silently vanished from "Assigned to me" and
                // "My posts".
                return isset($states[$entry->id()]) || $this->defaultState($raw, $entry) !== null;
            })
            ->map(function ($entry) use ($raw, $states, $enteredAt, $assigneeMap, $workflow) {
                $id = $entry->id();
                $assignees = $this->assigneeNames($entry, $workflow, $assigneeMap);
                $state = $states[$id] ?? $this->defaultState($raw, $entry);

                return [
                    'id' => $id,
                    // Always the row's own summary PAGE. There used to be a
                    // `can_open_summary` flag beside it, because the queue opened an
                    // overlay that could only ever mount the generic summary — a
                    // localization had to be sent to its full-page route to get the
                    // source-vs-target comparison it exists for. Navigation resolves
                    // that per workflow through InstanceUrl, so the exception is gone.
                    'url' => $this->instanceUrl($id, $workflow),
                    'edit_url' => $entry->editUrl(),
                    'preview_url' => $this->previewUrlFor($entry),
                    'preview_mode' => EntryPreviewController::modeFor($entry),
                    'title' => (string) $entry->value('title'),
                    'site' => $entry->locale(),
                    // The personal lists stay merged across the workflow's
                    // collections, so the row has to say where it came from.
                    'collection' => $this->collectionTitle($entry->collectionHandle()),
                    'state' => $state,
                    'state_label' => $this->stateLabel($state),
                    // Keyed by column field, like the pivot's per-site map, so the
                    // listing reads both the same way: row.entered_at[col.field].
                    'entered_at' => isset($enteredAt[$id]) ? ['state' => $enteredAt[$id]] : [],
                    'assignees' => implode(', ', $assignees) ?: '—',
                    // One row is one site-version here, so single numbers are enough
                    // (the pivot needs per-site maps instead — see entryRows()).
                    'comment_count' => $this->commentCounts($workflow)[$id] ?? 0,
                    'new_comment_count' => $this->newCommentCount($entry, $workflow),
                    '_assignees' => $assignees,
                    '_assignee_ids' => $this->assigneeIds($entry, $workflow, $assigneeMap),
                    // The entry's `author` field — inherited from the origin by
                    // a localization through value() — for "My posts".
                    '_author_ids' => $entry->authors()->map(fn ($author) => (string) $author)->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Sites that the pivot listing of a given workflow should render columns
     * for. Default: every site the CP knows.
     *
     * Two narrowings:
     *
     * - A localization manager shows the distribution it is responsible for, not
     *   the whole site list. The plain-language manager is a two-column view by
     *   nature — source and Einfache Sprache — and a translation manager has no
     *   business showing a column another manager owns.
     * - A workflow that is NOT a localization workflow, but shares a collection
     *   with one, collapses to just the Statamic default site: the language
     *   columns belong to that manager's listing then, not here.
     *
     * @return array<int, string>
     */
    private function listingSitesFor(?string $workflow): array
    {
        $handles = $this->siteHandles();

        if (! $this->isEntryWorkflow($workflow)) {
            return $handles;
        }

        $raw = app(DefinitionLoader::class)->raw($workflow);
        $resolver = app(WorkflowResolver::class);

        if ($resolver->isLocalizationWorkflow($raw)) {
            // WorkflowResolver::managedSites() owns this — the prune command asks the
            // same question ("which sites does this manager answer for") and two
            // implementations would drift.
            return $resolver->managedSites($workflow);
        }

        $hasCompanion = collect($raw['collections'] ?? [])
            ->contains(fn (string $col) => $resolver->hasCompanionLocalizationWorkflow($workflow, $col));

        if (! $hasCompanion) {
            return $handles;
        }

        $defaultHandle = Site::default()?->handle();
        $mainSite = in_array($defaultHandle, $handles, true)
            ? $defaultHandle
            : ($handles[0] ?? null);

        return $mainSite !== null ? [$mainSite] : [];
    }

    // ---- Cross-workflow overview (dashboard widget) --------------------------

    /**
     * The workflows the dashboard's "all posts" table spans: every active
     * entry-backed workflow, in definition order. `$param` narrows that — a
     * comma-separated string (the listing's query parameter) or a list (the
     * widget's config). Unknown and inactive names are dropped rather than
     * rejected; a narrowing that keeps nothing yields an empty scope.
     *
     * @param  string|array<int, string>|null  $param
     * @return array<int, string>
     */
    private function overviewWorkflows($param): array
    {
        $names = collect(app(DefinitionLoader::class)->all())
            ->filter(fn (string $name) => $this->isEntryWorkflow($name))
            ->values();

        $requested = is_array($param)
            ? array_values(array_filter(array_map('strval', $param)))
            : array_values(array_filter(array_map('trim', explode(',', (string) $param))));

        if ($requested !== []) {
            $names = $names->filter(fn (string $name) => in_array($name, $requested, true))->values();
        }

        return $names->all();
    }

    /**
     * The site columns of the overview: every site at least one of the scoped
     * workflows lists, in sites.yaml order. A site no scoped manager shows (the
     * language columns when only the post manager is in scope) gets no column.
     *
     * @param  array<int, string>  $workflows
     * @return array<int, string>
     */
    private function overviewSites(array $workflows): array
    {
        $listed = collect($workflows)
            ->flatMap(fn (string $name) => $this->listingSitesFor($name))
            ->unique()
            ->all();

        return array_values(array_filter(
            $this->siteHandles(),
            fn (string $handle) => in_array($handle, $listed, true)
        ));
    }

    /**
     * The pivot rows of every scoped workflow in one list — a post governed by
     * two workflows appears once per workflow, since its place in each is a
     * different question. The row id is prefixed with the workflow so the two
     * stay distinct keys; nothing links by it (the cells and the title use
     * `url`/`urls`, built per workflow in entryRows()).
     *
     * Each row names the sites ITS workflow lists, so the table can tell "not
     * this workflow's column" from "no version in this site" — both arrive as
     * a missing / null cell otherwise.
     *
     * @param  array<int, string>  $workflows
     * @return array<int, array<string, mixed>>
     */
    private function overviewRows(array $workflows): array
    {
        $all = app(DefinitionLoader::class)->all();
        $links = app(SubjectLinks::class);

        return collect($workflows)
            ->flatMap(function (string $name) use ($all, $links) {
                $tags = [
                    'workflow' => $name,
                    'workflow_label' => $links->workflowLabel($name),
                    // The same dot the dashboard's other cards paint for it.
                    'workflow_color' => PersonalWorkItems::workflowColor($name, app(DefinitionLoader::class)->raw($name), $all),
                    'manager_url' => $links->managerUrl($name),
                    'sites' => $this->listingSitesFor($name),
                ];

                return collect($this->rowsFor($name))
                    ->map(fn (array $row) => array_merge($row, $tags, ['id' => "{$name}:{$row['id']}"]));
            })
            ->values()
            ->all();
    }

    /**
     * How many rows of the overview the given subjects light up: the posts
     * (one per workflow and origin root, as overviewRows() lists them) that hold
     * at least one of the subjects in a listed site.
     *
     * This is the figure of the dashboard's stat cards. Those cards used to
     * count SUBJECTS — site-versions — and now filter the "Alle Beiträge" table,
     * which is one row per post; two versions of one post waiting at the same
     * gate would have read "2" above a table showing one row. So the card counts
     * rows, through the same grouping (entryGroups()) and the same match
     * (cellSubjectIds()) the listing's `preset` filter uses, and without building
     * a single cell: the number and the filtered table's total cannot differ as
     * long as both widgets span the same workflows.
     *
     * Orphaned state rows (the entry is gone) and versions a localization
     * manager does not list drop out here exactly as they drop out of the table.
     *
     * @param  array<string, array<int, string>>  $perWorkflow  workflow => subject ids
     * @param  array<int, string>|null  $only  Workflow names to restrict to.
     */
    public function matchingPostCount(array $perWorkflow, ?array $only = null): int
    {
        $count = 0;

        foreach ($this->overviewWorkflows($only) as $workflow) {
            $lookup = array_flip(array_map('strval', $perWorkflow[$workflow] ?? []));

            if ($lookup === []) {
                continue;
            }

            $sites = $this->listingSitesFor($workflow);

            $count += ($this->postGroupsCache[$workflow] ??= $this->entryGroups($workflow))
                ->filter(fn ($group) => $this->matchesSubjects($this->cellSubjectIds($group, $sites), $lookup))
                ->count();
        }

        return $count;
    }

    /**
     * The overview rows a stat preset filters to — the listing's `preset`
     * parameter, see StatsPresets. Same match as matchingPostCount().
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $scope
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function applyStatsPreset($rows, string $preset, array $scope)
    {
        $lookups = array_map(
            fn (array $ids) => array_flip(array_map('strval', $ids)),
            app(StatsPresets::class)->subjects($preset, $scope)
        );

        return $rows->filter(fn (array $row) => $this->matchesSubjects(
            $row['_subject_ids'] ?? [],
            $lookups[$row['workflow'] ?? ''] ?? []
        ));
    }

    /**
     * @param  array<int, string>  $ids
     * @param  array<string, int>  $lookup  Subject id => anything (array_flip()).
     */
    private function matchesSubjects(array $ids, array $lookup): bool
    {
        foreach ($ids as $id) {
            if (isset($lookup[$id])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Columns of the overview: the post, its workflow, one state column per
     * overview site (built like a manager's pivot columns, see columns()), and
     * the content date.
     *
     * The workflow column starts hidden when the scope is a single workflow
     * (the widget's workflow buttons narrow it to one) — every row would say
     * the same thing, and the active button already does.
     *
     * @param  array<int, string>  $workflows
     * @return array<int, array<string, mixed>>
     */
    private function overviewColumns(array $workflows): array
    {
        $columns = [
            ['field' => 'title', 'label' => $this->t('subject'), 'type' => 'title', 'visible' => true, 'sortable' => true, 'required' => true],
            ['field' => 'workflow_label', 'label' => $this->t('column_workflow'), 'type' => 'workflow', 'sortable' => true, 'visible' => count($workflows) > 1],
        ];

        foreach ($this->overviewSites($workflows) as $site) {
            $columns[] = [
                'field' => $site,
                'label' => $this->siteName($site),
                'type' => 'state',
                'visible' => true,
                'sortable' => false,
                'code' => $this->siteCode($site),
            ];
        }

        // Shown when any scoped workflow owns that clock — see columns() for why a
        // localization manager keeps it hidden on its own.
        $resolver = app(WorkflowResolver::class);

        $columns[] = [
            'field' => 'content_updated_at',
            'label' => $this->t('column_content_updated_at'),
            'type' => 'text',
            'visible' => collect($workflows)->contains(
                fn (string $name) => ! $resolver->isLocalizationWorkflow(app(DefinitionLoader::class)->raw($name))
            ),
            'sortable' => true,
            'sort_field' => '_content_updated_at',
        ];

        return $columns;
    }

    /**
     * Initial props for the dashboard's "all posts" widget — what workflow()
     * hands a manager page for its listing, across every scoped workflow. Null
     * when the scope holds no workflow; the widget then renders nothing.
     *
     * `columns` / `filters` are those of the whole scope (the widget's "All"
     * button). `workflowOptions` holds one entry per scoped workflow, in nav
     * order — its
     * button (`value`, `label`, `color`) plus the `columns` / `filters` of the
     * listing narrowed to it (`workflows=<value>`), so a switch remounts the
     * table with exactly what the endpoint will answer: only that workflow's
     * site columns, only its places in the state filter. `defaultWorkflow` is
     * the button active on load, see overviewDefaultWorkflow().
     *
     * `creates` lists the workflows the current user may create a post in, each
     * with the same create props a manager page gets (see createOptions()).
     *
     * @param  array<int, string>|null  $only  Workflow names to restrict to.
     * @param  string|null  $default  The widget's `default_workflow` config.
     * @return array<string, mixed>|null
     */
    public function overviewProps(?array $only, ?string $default = null): ?array
    {
        $scope = $this->overviewWorkflows($only);

        if ($scope === []) {
            return null;
        }

        $loader = app(DefinitionLoader::class);
        $links = app(SubjectLinks::class);
        $all = $loader->all();

        // Built once and split per workflow below — the stale filter is read off
        // the rows, and building every workflow's row set twice doubles the load.
        $rows = collect($this->overviewRows($scope));

        return [
            'listingUrl' => cp_route('workflows.listing'),
            'columns' => $this->overviewColumns($scope),
            'filters' => $this->filters($rows->all(), null, $scope),
            // Buttons in nav order (`metadata.nav.order`, as Settings › Workflows
            // lists them), so the post manager leads; without an order a workflow
            // goes last, ties keep definition order (the sort is stable).
            'workflowOptions' => collect($scope)
                ->sortBy(fn (string $name) => $loader->raw($name)['metadata']['nav']['order'] ?? PHP_INT_MAX)
                ->values()
                ->map(fn (string $name) => [
                    'value' => $name,
                    'label' => $links->workflowLabel($name),
                    'color' => PersonalWorkItems::workflowColor($name, $loader->raw($name), $all),
                    'columns' => $this->overviewColumns([$name]),
                    'filters' => $this->filters($rows->where('workflow', $name)->values()->all(), null, [$name]),
                ])
                ->all(),
            'defaultWorkflow' => $this->overviewDefaultWorkflow($scope, $default),
            'stateColors' => $loader->placeColors(),
            'stateLabels' => $loader->placeLabels(),
            'creates' => collect($scope)
                ->map(function (string $name) use ($loader, $links) {
                    $options = $this->createOptions($name, $loader->raw($name));

                    return $options === null
                        ? null
                        : ['workflow' => $name, 'label' => $links->workflowLabel($name)] + $options;
                })
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * The workflow the overview widget opens on: the authoring workflow, the
     * one posts are created in (the post manager). `$preferred` — the widget's
     * `default_workflow` config — wins when it is in scope. Otherwise the first
     * scoped workflow that is not a localization workflow and owns a collection
     * (see createOptions() — independent of the current user's right to create,
     * so everyone lands on the same table), then the first non-localization
     * one, then simply the first.
     *
     * @param  array<int, string>  $scope  Non-empty.
     */
    private function overviewDefaultWorkflow(array $scope, ?string $preferred): string
    {
        if ($preferred !== null && in_array($preferred, $scope, true)) {
            return $preferred;
        }

        $loader = app(DefinitionLoader::class);
        $resolver = app(WorkflowResolver::class);

        $authoring = array_values(array_filter(
            $scope,
            fn (string $name) => ! $resolver->isLocalizationWorkflow($loader->raw($name))
        ));

        $owner = collect($authoring)->first(fn (string $name) => collect($loader->raw($name)['collections'] ?? [])
            ->contains(fn (string $handle) => $this->workflowForCollection($handle) === $name));

        return $owner ?? $authoring[0] ?? $scope[0];
    }

    /** Human label for a collection handle, falling back to the handle itself. */
    private function collectionTitle(string $handle): string
    {
        return (string) (Collection::find($handle)?->title() ?? $handle);
    }

    /**
     * The short code a site is shown as in badges.
     *
     * The HANDLE, uppercased — not the locale's language or region. Two sites can
     * share a locale (a plain-language site is `de_DE` just like the German one), and
     * a badge that cannot tell those apart is worse than none: the pivot columns and
     * the version pills exist precisely to distinguish site-versions.
     */
    private function siteCode(string $handle): string
    {
        return strtoupper($handle);
    }

    /**
     * Split a site handle into its base site and a lowercase language code —
     * kept for the translation manager, which groups an entry's localizations
     * by base site (e.g. `main` + `main_en`). Variants follow the
     * `{base}_{lang}` convention: if a `_`-prefixed base site exists, the
     * suffix is the language. Otherwise the handle is its own base and the code
     * comes from the site's own locale.
     *
     * @return array{0: string, 1: string} [baseHandle, languageCode]
     */
    private function siteGroup(string $handle): array
    {
        [$prefix, $suffix] = array_pad(explode('_', $handle, 2), 2, null);

        if ($suffix !== null && Site::get($prefix)) {
            return [$prefix, strtolower($suffix)];
        }

        $lang = optional(Site::get($handle))->shortLocale() ?: $handle;

        return [$handle, strtolower($lang)];
    }

    /**
     * All existing site-versions (localizations) of a post as a flat list, each
     * linked to its own workflow detail page — rendered as a listing
     * (VersionListing.vue) on both the summary and the translation view, so the
     * two products read as one. A site IS a version here, so there is no
     * language sub-grouping.
     *
     * Every row carries the state of the workflow that OWNS that version, not of the
     * workflow being viewed. See the note inside.
     *
     * @return array<int, array<string, mixed>>
     */
    private function entryVersions($entry, string $workflow, string $currentId): array
    {
        $loader = app(DefinitionLoader::class);
        $store = app(WorkflowStateStore::class);
        $root = $entry->root();

        $byLocale = collect([$root->locale() => $root]);
        foreach ($root->descendants() as $locale => $descendant) {
            $byLocale[$locale] = $descendant;
        }

        // $byLocale already names every site this post exists in.
        $groupLocales = $byLocale->keys()->all();

        return collect($this->siteHandles())
            ->filter(fn ($site) => $byLocale->has($site))
            ->map(function ($site) use ($byLocale, $store, $loader, $workflow, $currentId, $groupLocales) {
                $localization = $byLocale->get($site);

                // Version-native workflow: the origin belongs to the authoring
                // manager (postmanager), each localization to the translation
                // manager. Falls back to the current workflow when no better match
                // exists, so a collection with just one governing workflow still works.
                $versionWorkflow = $this->workflowForVersion($localization, $workflow, $currentId);

                // State read from THAT workflow, not from the one being viewed. A
                // translated entry never enters the post manager, so asking the post
                // manager for its state used to yield a state derived from the wrong
                // definition — a translation in `linguistic_review` showed up as
                // whatever postmanager derives for an unpublished entry.
                $versionRaw = $loader->raw($versionWorkflow);
                $state = $store->get($localization->id(), $versionWorkflow)
                    ?? $this->defaultState($versionRaw, $localization, $groupLocales);

                return [
                    'site' => $site,
                    // The localization's own site name — never a group base, so
                    // language-suffixed handles keep their distinct labels.
                    'siteName' => $this->siteName($site),
                    'code' => $this->siteCode($site),
                    // This version's OWN title. A translated entry may carry a
                    // different one from its origin, and in a listing that is the
                    // column an editor scans first.
                    'title' => (string) $localization->value('title'),
                    // Both null where the version is not part of its workflow yet —
                    // the pill then shows the site without a state badge.
                    'state' => $state,
                    'state_label' => $state === null ? null : $this->stateLabel($state),
                    'url' => $this->instanceUrl($localization->id(), $versionWorkflow),
                    'current' => $localization->id() === $currentId,
                    'has_new_comments' => $this->hasNewComments($localization, $versionWorkflow),
                    // Finished in its own workflow, but the post has moved on since.
                    // Read from the version's own definition, like its state above.
                    'stale' => app(WorkflowResolver::class)->contentStale($localization, $versionRaw),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Which workflow a version pill links to, and whose state it shows.
     *
     * Every version has ONE home: the German original belongs to the post manager,
     * a plain-language version to the plain-language manager, a translation to the
     * translation manager. WorkflowResolver::forEntry() answers that — the same
     * authority the entry editor and the save listeners use — so the pills are a
     * map of the post across managers, and clicking one lands where that version is
     * actually worked on.
     *
     * The one exception is the version being VIEWED: it stays in the manager it is
     * being viewed in, because it would otherwise claim to live somewhere else than
     * the page you are looking at. That case is real: with
     * `translation_source_site: simple` the plain-language version is the
     * translation manager's source, and its pivot row opens it there.
     *
     * So the pills do not double as a shortcut to that source's start button — the
     * way there is the manager's own listing, which is where the work is queued.
     */
    private function workflowForVersion($localization, string $currentWorkflow, string $currentId): string
    {
        if ((string) $localization->id() === $currentId) {
            return $currentWorkflow;
        }

        return app(WorkflowResolver::class)->forEntry($localization) ?? $currentWorkflow;
    }

    /**
     * The user ids assigned to THIS specific localization within the workflow.
     * Assignment is owned by the workflow store (not the entry), so it is read
     * per subject-version and per workflow. Pass $map (subject_id => [ids]) from
     * a bulk byWorkflow() load to avoid a query per entry in listings.
     *
     * @param  array<string, array<int, string>>|null  $map
     * @return array<int, string>
     */
    private function assigneeIds($entry, string $workflow, ?array $map = null): array
    {
        $ids = $map !== null
            ? ($map[$entry->id()] ?? [])
            : app(WorkflowAssigneeStore::class)->idsFor($entry->id(), $workflow);

        return collect($ids)->filter()->values()->all();
    }

    /**
     * Names of this site-version's own assignees.
     *
     * @param  array<string, array<int, string>>|null  $map
     * @return array<int, string>
     */
    private function assigneeNames($entry, string $workflow, ?array $map = null): array
    {
        return collect($this->assigneeIds($entry, $workflow, $map))
            ->map(fn ($id) => User::find($id)?->name() ?? User::find($id)?->email())
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Default workflow state for an entry that has no explicit stored state.
     * Thin wrapper — WorkflowResolver::derivedState() is the single authority, so
     * what a listing shows always matches what the seeder would persist and what
     * a transition validates against.
     *
     * @param  array<int, string>|null  $groupLocales  Site handles the logical
     *                                                 post exists in, when the
     *                                                 caller already has them.
     */
    private function defaultState(array $raw, $entry, ?array $groupLocales = null): ?string
    {
        return app(WorkflowResolver::class)->derivedState($raw, $entry, $groupLocales);
    }

    /**
     * Whether the current user can actually open Statamic's entry editor for this
     * entry — checked before handing out an edit link anywhere.
     *
     * Needs the entry ability AND access to the entry's own site. The site part is
     * the one that bites: EntriesController builds the publish form's
     * `localizations` from the sites the user may access, so without
     * `access <site> site` the entry's own site is missing, no localization is
     * flagged active, and Statamic's publish form throws on
     * `localizations.find(l => l.active).status` / `.livePreviewUrl` — it has no
     * null-guard there. The result is a blank editor and two console errors, so a
     * link we know leads there is worse than no link.
     */
    private function canOpenEntryEditor($entry): bool
    {
        $user = User::current();

        if ($user === null || ! is_object($entry)) {
            return false;
        }

        return $user->can('view', $entry) && $this->canAccessEntrySite($entry);
    }

    /**
     * The "Live preview" button's URL for an entry, or null to hide the button.
     *
     * Gated on SITE access only, not on the entry ability the "Edit" button
     * needs. The two buttons answer different questions: editing is a Statamic
     * permission on the collection; looking at the rendered page is what every
     * reviewer on the workflow detail page does anyway — the page already shows
     * them the content as blocks. A topic owner or an expert typically holds no
     * `view posts entries` (they review, they do not edit), and hiding the
     * preview from exactly the people the workflow asks for a judgement would
     * defeat the button. Site access stays: someone who may not enter a site
     * has no business with its pages, rendered or not (see canAccessEntrySite()).
     *
     * Plus Statamic's own condition for offering a live preview at all: the
     * collection needs a route in the entry's site, otherwise there is no
     * frontend page (Entry::livePreviewUrl() returns null for the same reason).
     * The route redirects to the public page or a tokenised preview, see
     * EntryPreviewController::modeFor(), which enforces the same gate.
     */
    private function previewUrlFor($entry): ?string
    {
        if (! $this->canAccessEntrySite($entry)) {
            return null;
        }

        if (! $entry->collection()?->route($entry->locale())) {
            return null;
        }

        return cp_route('workflows.api.preview', ['id' => $entry->id()]);
    }

    /**
     * Whether the current user may change WHO IS RESPONSIBLE for a subject — the
     * assignee picker on an instance page and the endpoint behind it.
     *
     * Handing a task to someone else is a coordination decision, so it is gated by
     * group rather than by being an assignee: the person who currently holds the task
     * is often exactly the one who should not be able to pass it on unilaterally.
     * Super admins always may — a lock without a key is not a permission model.
     *
     * Site access is NOT re-checked here; every caller has already done it, and
     * repeating it would suggest this method is the whole gate when it is one half.
     *
     * @param  array<string, mixed>|null  $raw  The workflow definition, when known.
     */
    private function canAssign(?array $raw = null, $user = null): bool
    {
        $user = $user ?? User::current();

        if ($user === null) {
            return false;
        }

        if ($user->isSuper()) {
            return true;
        }

        foreach ($this->assignerGroups($raw) as $group) {
            if ((bool) $user->isInGroup($group)) {
                return true;
            }
        }

        return false;
    }

    /** Memo for canModerateComments() — it is asked once per rendered comment. */
    private ?bool $canModerateComments = null;

    /**
     * Whether the current user may delete ANY comment, not just their own. Super
     * admins always may; everyone else needs one of `config/workflows.php →
     * comment_moderator_groups` (default `['admins']`). Empty means SUPER ADMINS
     * ONLY. Like canViewHistory(), deliberately not `isPrivilegedUser()`.
     */
    private function canModerateComments(): bool
    {
        if ($this->canModerateComments !== null) {
            return $this->canModerateComments;
        }

        $user = User::current();

        if ($user === null) {
            return false;
        }

        if ($user->isSuper()) {
            return $this->canModerateComments = true;
        }

        foreach ((array) config('workflows.comment_moderator_groups', []) as $group) {
            if (is_string($group) && $group !== '' && (bool) $user->isInGroup($group)) {
                return $this->canModerateComments = true;
            }
        }

        return $this->canModerateComments = false;
    }

    /**
     * Whether the current user may see an entry's workflow history (button,
     * drawer, diff endpoint). Super admins always may; everyone else needs one of
     * `config/workflows.php → history_groups`. Empty means SUPER ADMINS ONLY.
     *
     * Deliberately not `isPrivilegedUser()`: `privileged_groups` relaxes workflow
     * gates, it is not a licence to read the full audit trail.
     */
    private function canViewHistory($user = null): bool
    {
        $user = $user ?? User::current();

        if ($user === null) {
            return false;
        }

        if ($user->isSuper()) {
            return true;
        }

        foreach ((array) config('workflows.history_groups', []) as $group) {
            if (is_string($group) && $group !== '' && (bool) $user->isInGroup($group)) {
                return true;
            }
        }

        return false;
    }

    /**
     * User-group handles allowed to assign responsibles in this workflow.
     *
     * Same shape and same precedence as postCreatorGroups(): the workflow's
     * `metadata.assigner_groups` (editable in the settings editor) wins over
     * `config/workflows.php → assigner_groups`, the project-wide fallback. Both
     * default to empty, and empty means SUPER ADMINS ONLY — deciding who owns a task
     * is a permission this addon refuses to hand out on its own.
     *
     * Not to be confused with `default_assignee_group`, which says who a subject is
     * handed to; this says who is allowed to do the handing.
     *
     * @param  array<string, mixed>|null  $raw
     * @return array<int, string>
     */
    private function assignerGroups(?array $raw = null): array
    {
        $groups = $raw !== null && array_key_exists('assigner_groups', $raw['metadata'] ?? [])
            ? $raw['metadata']['assigner_groups']
            : config('workflows.assigner_groups', []);

        return collect($groups)
            ->filter(fn ($handle) => is_string($handle) && $handle !== '')
            ->values()
            ->all();
    }

    /**
     * Whether the current user may act on this entry's site at all
     * (`access <handle> site`, checked through Statamic's SitePolicy).
     *
     * This is a HARD precondition for every write to an entry and is deliberately
     * NOT bypassed by `isPrivilegedUser()`. The addon's `privileged_groups` may
     * relax the addon's OWN rules — assignee and `required_group` — but it must
     * never grant more than Statamic itself does. Someone who cannot even view a
     * site has no business transitioning, reassigning or commenting on its
     * content. Super admins pass anyway: `isSuper()` satisfies every permission.
     */
    private function canAccessEntrySite($entry): bool
    {
        $user = User::current();

        if ($user === null || ! is_object($entry) || ! method_exists($entry, 'locale')) {
            return false;
        }

        $site = Site::get($entry->locale());

        // An unknown site handle can't be authorised against — don't invent access.
        return $site !== null && (bool) $user->can('view', $site);
    }

    /**
     * Resolve the workflow context for an entry. Honours an explicitly requested
     * workflow (e.g. ?workflow=translation from the translation manager) when it
     * exists and covers the entry's collection — that is how each manager keeps
     * its own context on a shared collection.
     *
     * Without one, the fallback goes through `WorkflowResolver::forEntry()`, so
     * an entry opened on its own resolves to the workflow that governs THIS
     * version: a localization to the localization workflow, an origin to the
     * authoring one.
     */
    private function workflowFor($entry, ?string $requested): ?string
    {
        $loader = app(DefinitionLoader::class);

        if ($requested && in_array($requested, $loader->all(), true)
            && in_array($entry->collectionHandle(), $loader->raw($requested)['collections'] ?? [], true)) {
            return $requested;
        }

        return app(WorkflowResolver::class)->forEntry($entry);
    }

    /** @return array<int, array<string, mixed>> */
    private function columns(?string $workflow = null, bool $mine = false): array
    {
        // "My Queue" stays a flat per-localization list (not a pivot).
        if ($this->isEntryWorkflow($workflow) && ! $mine) {
            // Entry-backed workflows render a pivot: Betreff + one column per site.
            $columns = [
                ['field' => 'title', 'label' => $this->t('subject'), 'type' => 'title', 'visible' => true, 'sortable' => true, 'required' => true],
            ];

            foreach ($this->listingSitesFor($workflow) as $site) {
                $columns[] = [
                    'field' => $site,
                    'label' => $this->siteName($site),
                    'type' => 'state',
                    'visible' => true,
                    'sortable' => false,
                    'code' => $this->siteCode($site),
                ];
            }

            // When the content was last brought up to date — the number the ageing
            // counts from. Shown by default in the manager that OWNS that clock:
            // there the age of a post is the question the listing is read for, and
            // sorting on it is how the next candidates for a rewrite are found.
            //
            // A localization manager keeps it off by default. The value there is the
            // SOURCE's clock, the same number on every one of its rows for a given
            // post, while the question that matters for a version is whether it has
            // fallen behind that source — and the „veraltet" marker beside its state
            // already answers that one.
            //
            // Costs nothing either way: entryRows() reads the number for every row
            // regardless of visibility, off two fields of entries the listing has
            // already loaded, so showing the column adds no query and no file read.
            // Sorted on the raw timestamp, see listing().
            $columns[] = [
                'field' => 'content_updated_at',
                'label' => $this->t('column_content_updated_at'),
                'type' => 'text',
                'visible' => ! app(WorkflowResolver::class)->isLocalizationWorkflow(
                    app(DefinitionLoader::class)->raw($workflow)
                ),
                'sortable' => true,
                'sort_field' => '_content_updated_at',
            ];

            return $columns;
        }

        if ($this->isEntryWorkflow($workflow) && $mine) {
            $columns = [
                ['field' => 'title', 'label' => $this->t('subject'), 'type' => 'title', 'visible' => true, 'sortable' => true, 'required' => true],
                ['field' => 'state', 'label' => $this->t('state'), 'type' => 'state', 'visible' => true, 'sortable' => true],
                ['field' => 'site', 'label' => $this->t('site'), 'type' => 'text', 'visible' => true, 'sortable' => true],
            ];

            // The personal lists span every collection of the workflow (unlike
            // "all posts", which is split into per-collection tabs), so they name
            // the collection — but only when there is more than one to tell apart.
            if (count(app(DefinitionLoader::class)->raw($workflow)['collections'] ?? []) > 1) {
                // Statamic's own word for it („Sammlung" in German), so the column
                // header matches the CP sidenav instead of staying English.
                $columns[] = ['field' => 'collection', 'label' => (string) __('Collection'), 'type' => 'text', 'visible' => true, 'sortable' => true];
            }

            return $columns;
        }

        return [
            ['field' => 'title', 'label' => $this->t('subject'), 'type' => 'title', 'visible' => true, 'sortable' => true, 'required' => true],
            ['field' => 'state', 'label' => $this->t('state'), 'type' => 'state', 'visible' => true, 'sortable' => true],
            ['field' => 'site', 'label' => $this->t('site'), 'type' => 'text', 'visible' => true, 'sortable' => true],
            ['field' => 'assignees', 'label' => $this->t('assignees'), 'type' => 'text', 'visible' => true, 'sortable' => false],
        ];
    }

    /**
     * Apply the requested column visibility (the CP column customizer sends the
     * visible fields as the `columns` query param). Without it, all columns are
     * visible by default. The `title` column always stays visible.
     *
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<int, array<string, mixed>>
     */
    private function applyColumnVisibility(array $columns, $param): array
    {
        $requested = array_values(array_filter(explode(',', (string) $param)));

        if ($requested === []) {
            return $columns;
        }

        return array_map(function (array $column) use ($requested) {
            $column['visible'] = $column['field'] === 'title' || in_array($column['field'], $requested, true);

            return $column;
        }, $columns);
    }

    /**
     * The select options for each filter, derived from the available rows.
     *
     * `$scope` is the cross-workflow listing of the dashboard widget (see
     * overviewRows()): its states are the union of every scoped workflow's
     * places. Without it nothing here changes for the single-workflow listings.
     *
     * @param  array<int, string>|null  $scope
     * @return array<string, array<string, string>>
     */
    private function filterOptions(array $rows, ?string $workflow = null, ?array $scope = null): array
    {
        $rows = collect($rows);
        $loader = app(DefinitionLoader::class);

        // For a known workflow the states come from its definition (authoritative —
        // includes places not yet present in any row); otherwise from the rows.
        // A scope reads every one of its definitions the same way, in scope order,
        // a place shared by two workflows offered once.
        $stateOptions = match (true) {
            $scope !== null => collect($scope)
                ->flatMap(fn (string $name) => array_keys($loader->raw($name)['places'] ?? []))
                ->unique()
                ->mapWithKeys(fn ($s) => [$s => $this->stateLabel($s)])->all(),
            $this->isKnownWorkflow($workflow) => collect(array_keys($loader->raw($workflow)['places'] ?? []))
                ->mapWithKeys(fn ($s) => [$s => $this->stateLabel($s)])->all(),
            default => $rows->pluck('state')->filter()->unique()->sort()
                ->mapWithKeys(fn ($v) => [$v => $this->stateLabel($v)])->all(),
        };

        return [
            'site' => $rows->pluck('site')->filter()->unique()->sort()
                ->mapWithKeys(fn ($v) => [$v => $v])->all(),
            'state' => $stateOptions,
            // The pivot carries no names, only ids — matched by id, labelled by
            // name, so two people of the same name stay apart.
            'assignee' => $this->isPivot($workflow, $scope)
                ? $this->userOptions($rows, '_assignee_ids')
                : $rows->flatMap(fn ($r) => $r['_assignees'] ?? [])->filter()->unique()->sort()
                    ->mapWithKeys(fn ($v) => [$v => $v])->all(),
            'author' => $this->userOptions($rows, '_author_ids'),
            // Fixed steps rather than the rows' spread: "longer than a week" is
            // the question, whatever the oldest post happens to be.
            'waiting' => $rows->contains(fn ($r) => ($r['_waiting_since'] ?? null) !== null)
                ? collect(self::WAITING_DAYS)->mapWithKeys(fn (int $days) => [
                    (string) $days => trans_choice('workflows::messages.filter_waiting_days', $days, ['count' => $days]),
                ])->all()
                : [],
            // Offered only when some row actually has an out-of-date version — a
            // filter that can only ever return nothing is worse than no filter.
            'stale' => $rows->contains(fn ($r) => ($r['_stale'] ?? false) === true)
                ? ['1' => $this->t('filter_stale_only')]
                : [],
        ];
    }

    /**
     * Places a subject can still move on from — those named as `from` by some
     * transition. Time spent in any other place is not waiting.
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, string>
     */
    private function openPlaces(array $raw): array
    {
        return collect($raw['transitions'] ?? [])
            ->flatMap(fn ($transition) => (array) ($transition['from'] ?? []))
            ->filter(fn ($place) => is_string($place))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * id => display name of every user named under `$key` in any row.
     *
     * @return array<string, string>
     */
    private function userOptions(\Illuminate\Support\Collection $rows, string $key): array
    {
        return $rows->flatMap(fn ($r) => $r[$key] ?? [])->filter()->unique()
            ->mapWithKeys(function ($id) {
                $user = User::find($id);

                return $user ? [(string) $id => $user->name() ?? $user->email()] : [];
            })
            ->sort()
            ->all();
    }

    /**
     * The site-pivot listings: the dashboard overview and an entry workflow's
     * manager page.
     *
     * @param  array<int, string>|null  $scope
     */
    private function isPivot(?string $workflow, ?array $scope): bool
    {
        return $scope !== null || $this->isEntryWorkflow($workflow);
    }

    private function isKnownWorkflow(?string $workflow): bool
    {
        return $workflow !== null && in_array($workflow, app(DefinitionLoader::class)->all(), true);
    }

    /**
     * Native CP filter definitions (site / state / assignee), scoped to the
     * options actually present in the given rows.
     *
     * @param  array<int, string>|null  $scope  See filterOptions().
     * @return array<int, array<string, mixed>>
     */
    private function filters(array $rows, ?string $workflow = null, ?array $scope = null): array
    {
        $options = $this->filterOptions($rows, $workflow, $scope);

        // Pivot view: site becomes columns, so the filters are the post's, across
        // its sites — state, assignee, author, and the out-of-date question. The
        // dashboard's cross-workflow pivot is the same; which workflow it shows
        // is its buttons' business, not a filter's.
        if ($this->isPivot($workflow, $scope)) {
            return array_values(array_filter([
                (new StateFilter)->context(['options' => $options['state']])->toArray(),
                $options['assignee'] === []
                    ? null
                    : (new AssigneeFilter)->context(['options' => $options['assignee']])->toArray(),
                $options['author'] === []
                    ? null
                    : (new AuthorFilter)->context(['options' => $options['author']])->toArray(),
                $options['waiting'] === []
                    ? null
                    : (new WaitingFilter)->context(['options' => $options['waiting']])->toArray(),
                $options['stale'] === []
                    ? null
                    : (new StaleFilter)->context(['options' => $options['stale']])->toArray(),
            ]));
        }

        return [
            (new SiteFilter)->context(['options' => $options['site']])->toArray(),
            (new StateFilter)->context(['options' => $options['state']])->toArray(),
            (new AssigneeFilter)->context(['options' => $options['assignee']])->toArray(),
        ];
    }

    /**
     * The base64'd JSON the Listing sends as the `filters` query parameter.
     *
     * @return array<string, mixed>
     */
    private function decodeFilters($param): array
    {
        if (! $param) {
            return [];
        }

        $json = base64_decode($param, true);

        return $json === false ? [] : (json_decode($json, true) ?: []);
    }

    private function humanize(string $value): string
    {
        return $value === '' ? '' : (string) Str::of($value)->replace('_', ' ')->title();
    }

    /**
     * User-group handles required to apply a transition (metadata.required_group,
     * string or list). Empty means the transition is open to every assignee.
     *
     * The reading itself lives in TransitionGates, not here: the dashboard's
     * QueueStats card counts the places these keys guard, and a second reading
     * of the same YAML would let the disabled button and the number on the
     * dashboard disagree about the same workflow.
     *
     * @param  array<string, mixed>  $transition
     * @return array<int, string>
     */
    private function requiredGroups(array $transition): array
    {
        return TransitionGates::groups($transition);
    }

    /**
     * The role counterpart — `metadata.required_role`, same shape and same
     * meaning, asking `hasRole()` instead of `isInGroup()`.
     *
     * Added for the SLA block, which lets a project say whether its people are
     * addressed by group or by role and then uses that ONE answer for both the
     * hand-over and the "mark up to date" gate. Without a role-shaped gate the
     * role option would have been half a feature: assignment would honour it and
     * the gate would silently ignore it.
     *
     * Ordinary transitions may declare it too — nothing about it is SLA-specific,
     * and a project organised by roles rather than groups had no way to gate a
     * step at all before.
     *
     * @param  array<string, mixed>  $transition
     * @return array<int, string>
     */
    private function requiredRoles(array $transition): array
    {
        return TransitionGates::roles($transition);
    }

    /**
     * Super admins and members of any group listed in `config/workflows.php →
     * privileged_groups` get unconditional access to every workflow operation.
     * Defaults to `['admins']`.
     */
    private function isPrivilegedUser($user = null): bool
    {
        return Privileges::isPrivileged($user);
    }

    /**
     * Whether the current user may create entries from a workflow index. Super
     * admins always may; everyone else needs one of the workflow's creator groups
     * (see postCreatorGroups()). Without a workflow only the project-wide config
     * applies — that is the bare `/cp/workflows` page.
     *
     * @param  array<string, mixed>|null  $raw  The workflow definition, when known.
     */
    private function canCreatePost(?array $raw = null, $user = null): bool
    {
        $user = $user ?? User::current();

        if ($user === null) {
            return false;
        }

        if ($user->isSuper()) {
            return true;
        }

        foreach ($this->postCreatorGroups($raw) as $group) {
            if ((bool) $user->isInGroup($group)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The transition the "New post" modal's confirm button runs right after
     * creating the post — `metadata.create_transition` — as `['name', 'label']`,
     * or null when the workflow couples none (the button then only creates).
     *
     * Only a transition that exists and is not a system one (the revision loop's
     * auto-injected steps are driven by saves, not by buttons). The writer
     * checks more — that it leaves the initial place, that this is an entry
     * workflow — and rejects the rest at save time; this is the runtime floor
     * for a YAML edited by hand, so a stale handle degrades to "just create"
     * instead of a broken button.
     *
     * @param  array<string, mixed>  $raw
     * @return array{name: string, label: string}|null
     */
    private function createTransition(array $raw): ?array
    {
        $name = $raw['metadata']['create_transition'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        $transition = $raw['transitions'][$name] ?? null;

        if (! is_array($transition) || ($transition['metadata']['system'] ?? false)) {
            return null;
        }

        return [
            'name' => $name,
            'label' => $this->transitionLabel($name, $transition['metadata']['label'] ?? null),
        ];
    }

    /**
     * What the "New post" modal needs to know about the coupled transition: its
     * name and label, plus — when its user assignment is MANUAL — the people the
     * dialog offers to hand the post to, so the modal can ask the same question
     * the detail page's button asks (needs_pick / pickable_assignees there).
     *
     * The candidates are resolved against a STUB entry of the collection, because
     * the post does not exist yet. Group and role criteria do not look at the
     * entry, so they resolve exactly; a project criterion that compares entry
     * fields (`matches_entry`) finds nothing on a blank stub and offers nobody —
     * which is the honest answer before there is anything to match against.
     * storePost() validates the picks against the same stub, so "offered" and
     * "accepted" stay one set.
     *
     * @param  array<string, mixed>  $raw
     * @return array{name: string, label: string, needs_pick: bool, pick_hint: string|null, sends_mail: bool, pickable_assignees: array<int, array<string, mixed>>}|null
     */
    private function createCta(array $raw, string $collection): ?array
    {
        $cta = $this->createTransition($raw);

        if ($cta === null) {
            return null;
        }

        $transition = $raw['transitions'][$cta['name']];
        $stub = $this->stubEntryFor($collection);
        $actorId = (string) (User::current()?->id() ?? '') ?: null;
        $guarded = SelfAssignmentGuard::applies($raw, $transition, $stub, $actorId);
        $needsPick = TransitionActions::opensUserDialog($transition) || $guarded;

        return $cta + [
            'needs_pick' => $needsPick,
            'pick_hint' => $guarded ? SelfAssignmentGuard::hint($raw) : null,
            'sends_mail' => WorkflowNotifier::stepMail($raw, $cta['name']) !== null,
            'pickable_assignees' => $needsPick
                ? $this->pickableAssignees($raw, $cta['name'], $stub)
                : [],
        ];
    }

    /**
     * An unsaved entry of a collection — what the assignment criteria are resolved
     * against before a post exists (see createCta()). Never saved.
     */
    private function stubEntryFor(string $collection)
    {
        return Entry::make()->collection($collection)->locale($this->siteHandles()[0] ?? Site::default()->handle());
    }

    /**
     * User-group handles allowed to create entries in this workflow.
     *
     * `metadata.post_creator_groups` (editable in the settings editor) wins over
     * `config/workflows.php → post_creator_groups`, which is only the project-wide
     * fallback. Both default to empty: creating content is a permission the addon
     * refuses to grant on its own — a fresh install lets Super Admins create and
     * nobody else, until someone says otherwise in the CP.
     *
     * @param  array<string, mixed>|null  $raw
     * @return array<int, string>
     */
    private function postCreatorGroups(?array $raw = null): array
    {
        $groups = $raw !== null && array_key_exists('post_creator_groups', $raw['metadata'] ?? [])
            ? $raw['metadata']['post_creator_groups']
            : config('workflows.post_creator_groups', []);

        return collect($groups)
            ->filter(fn ($handle) => is_string($handle) && $handle !== '')
            ->values()
            ->all();
    }

    /**
     * Whether the current user may apply a transition. Super admins and
     * members of the `admins` group bypass the permission checks; everyone else
     * must be assigned to this entry-version AND belong to at least one of the
     * transition's required groups (if any).
     *
     * The mechanical blockers (see mechanicalBlocker) sit ABOVE the privileged
     * bypass: they are not about who you are but about whether the step can be
     * carried out at all, and nobody — super admin included — should be handed a
     * button that will fail.
     *
     * PUBLIC because it is the addon's single answer to "may this person take
     * this step", and one caller sits outside this controller:
     * FailedTranslationRetry asks it before it offers the dashboard's retry
     * button and again before it acts. Not a route — Statamic only mounts what
     * routes/cp.php names, and nothing names this. The alternative was a copy of
     * the check in the widget, which is exactly the drift this method exists to
     * prevent.
     *
     * @param  array<string, mixed>  $transition
     * @param  string|null  $name  The transition's handle. Required for the
     *                            mechanical blockers, which need to know WHICH
     *                            step this is; without it only permissions are
     *                            checked.
     */
    public function canApplyTransition(array $transition, $entry, string $workflow, ?string $name = null): bool
    {
        $user = User::current();

        if ($user === null) {
            return false;
        }

        // Site access first, and above the privileged bypass: transitioning an
        // entry writes to its site, so a user who may not access that site may not
        // move it either — no matter what the addon's privileged_groups say.
        if (! $this->canAccessEntrySite($entry)) {
            return false;
        }

        if ($name !== null && $this->mechanicalBlocker($transition, $entry, $workflow, $name) !== null) {
            return false;
        }

        if ($this->isPrivilegedUser($user)) {
            return true;
        }

        // Must be one of this entry-version's assignees.
        if (! in_array((string) $user->id(), $this->assigneeIds($entry, $workflow), true)) {
            return false;
        }

        // And, if the transition names groups or roles, match at least one of
        // them. The two lists are a UNION, not two hurdles: a step open to
        // "the medical group or anyone holding the reviewer role" is one
        // audience described two ways. Declaring neither leaves the step open to
        // every assignee.
        $groups = $this->requiredGroups($transition);
        $roles = $this->requiredRoles($transition);

        if ($groups === [] && $roles === []) {
            return true;
        }

        foreach ($groups as $group) {
            if ((bool) $user->isInGroup($group)) {
                return true;
            }
        }

        foreach ($roles as $role) {
            if ((bool) $user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why a transition cannot be carried out right now, regardless of who is
     * asking — as a localized message, or null when nothing is in the way.
     *
     * Two kinds of check live here. Most exist only because a localization
     * workflow does more than move a state — it creates or rewrites a version —
     * and only run for the transition that starts or resubmits one. The
     * `target_missing` gate at the top is the exception: it is an explicit,
     * assignable precondition (TransitionActions::TARGET_MISSING) that runs for
     * ANY transition that declares it, localization or not.
     *
     * Each one has a counterpart 422 in applyTransition(), and both read this
     * method, so the disabled button, its tooltip and the endpoint can never tell
     * three different stories.
     *
     * @param  array<string, mixed>  $transition
     */
    private function mechanicalBlocker(array $transition, $entry, string $workflow, string $name): ?string
    {
        // The explicit, assignable gate: metadata.actions declaring
        // `target_missing` — see TransitionActions::TARGET_MISSING. Checked first
        // and unconditionally, ahead of the localization-specific machinery below:
        // it is additive by design, so it has to run whether or not this
        // transition starts or resubmits anything, and a transition that declares
        // no such action is completely unaffected.
        foreach (TransitionActions::requiredMissingSites($transition) as $site) {
            if ($this->siteHasVersion($entry, $site)) {
                return $this->t('target_site_taken', ['site' => $this->siteName($site)]);
            }
        }

        // An AUTOMATIC user assignment with nobody to assign to. Blocked rather
        // than run, because the transition's whole promise is that somebody takes
        // the subject over — running it anyway would leave the post in a place
        // whose queue nobody sees, and the failure would surface days later as
        // "why has nothing happened".
        //
        // Deliberately not extended to the manual mode: there the empty candidate
        // list is something the editor SEES (the picker says so and cannot be
        // confirmed), which is a better answer than a disabled button with a
        // reason tucked into a tooltip.
        $assignment = TransitionActions::userAssignment($transition);

        if ($assignment !== null
            && $assignment['mode'] === TransitionActions::MODE_AUTOMATIC
            && app(AssignmentCandidates::class)->for($assignment, $entry)->isEmpty()) {
            return $this->t('assignment_no_candidates');
        }

        $raw = app(DefinitionLoader::class)->raw($workflow);
        $starts = TransitionActions::startsLocalization($raw, $name);
        $resubmits = TransitionActions::resubmitsPlainLanguage($transition);

        if (! $starts && ! $resubmits) {
            return null;
        }

        // The source has to exist before anything can be translated out of it.
        // Reported here rather than only on submit: a 422 after the click is a dead
        // end an editor cannot diagnose.
        //
        // Skipped where the workflow lets the run pick its own source: the missing
        // configured version is then not a blocker but one unavailable option among
        // several, and the dialog only offers versions that exist.
        if (! $this->picksSourcePerRun($workflow)) {
            [, $sourceError] = $this->sourceEntryFor($entry, $workflow);

            if ($sourceError !== null) {
                return $sourceError;
            }
        }

        if ($resubmits) {
            // "Rewrite again" is offered on two versions, and means the same thing on
            // both: take the source text and replace the plain-language version with
            // a fresh rewrite.
            //
            // - on the SOURCE version, because that is where the manager's work is
            //   started from ("make it" when none exists, "make it again" when one
            //   does) — the same place the start transition sits;
            // - on the plain-language version itself, where an editor who has read
            //   the result decides it is not good enough.
            //
            // Anywhere else it is meaningless, and it must never fire against a
            // version it would overwrite by accident: applied to the German original
            // it would replace the source everything else is made from.
            $claimed = (string) $this->plainLanguageSite($workflow);
            $sourceSite = $this->sourceSiteFor($entry, $workflow);
            $site = (string) $entry->locale();

            if ($site !== $claimed && $site !== $sourceSite) {
                return $this->t('transition_resubmit_wrong_version', [
                    'source' => $this->siteName($sourceSite),
                    'target' => $this->siteName($claimed),
                ]);
            }

            [$target, $targetError] = $this->plainLanguageVersionFor($entry, $workflow);

            if ($targetError !== null) {
                return $targetError;
            }

            // The state that moves is the plain-language version's, so it is that
            // version's state the transition has to lead out of — not the one of the
            // version the button happens to sit on.
            $targetState = app(WorkflowResolver::class)->stateFor($target, $workflow, $raw);

            if (! in_array($targetState, (array) ($transition['from'] ?? []), true)) {
                return $this->t('transition_resubmit_state', [
                    'site' => $this->siteName($claimed),
                    'state' => $this->stateLabel($targetState),
                ]);
            }

            // Rewriting reads the source just like the first run does, so the same
            // precondition holds: no rewriting out of a draft.
            if (($notReady = $this->sourceReadinessBlocker($entry, $workflow)) !== null) {
                return $notReady;
            }

            // A second rewrite while one is still running would race the poll
            // command for the same entry, and the later answer would win by
            // accident.
            return app(PlainLanguageJobStore::class)->hasOpen((string) $target->id(), $workflow)
                ? $this->t('translation_plain_job_running')
                : null;
        }

        // Exactly one version per post starts a translation: the one this manager
        // reads from. Set `translation_source_site: simple` and the plain-language
        // version becomes "source ready" — the German original is then this
        // manager's business no more (it belongs to the post manager, and to the
        // plain-language manager as ITS target). Without this the original kept
        // offering a start button that would translate somebody else's version.
        //
        // The preselected source, not the configured one: where the run may pick its
        // own source, the button still belongs to one version — the one the dialog
        // opens on — and from there any existing version can be chosen.
        $source = $this->preselectedSourceFor($entry, $workflow);

        if ((string) $entry->locale() !== $source) {
            return $this->t('transition_start_at_source', ['site' => $this->siteName($source)]);
        }

        // Nothing gets translated out of unfinished work: the source has to have
        // reached the last place of the workflow that owns it. Checked after the
        // "wrong version" message above, which is the more specific answer when both
        // would apply.
        if (($notReady = $this->sourceReadinessBlocker($entry, $workflow)) !== null) {
            return $notReady;
        }

        // A fixed target has no dialog in front of it to hide taken sites, so this
        // is what stops a second click from creating a second version. For a picked
        // target the same set feeds the dialog; an empty one means there is nothing
        // left to translate into.
        $fixedTarget = $this->plainLanguageSite($workflow);

        if ($fixedTarget !== null) {
            // "Already exists" is answered on its own, authoritative terms — the
            // post's real site list — rather than by asking whether the target
            // survived localizableSitesFor()'s filters. That set answers several
            // questions at once (in the collection? not claimed elsewhere? not the
            // source?), and a target missing from it for ANY of those reasons used
            // to be reported as "already exists" even when it plainly was not: a
            // `plain_language_site` that names a site outside the collection, for
            // instance, made every post's start button claim a version existed
            // that never did.
            if ($this->siteHasVersion($entry, $fixedTarget)) {
                return $this->t('target_site_taken', ['site' => $this->siteName($fixedTarget)]);
            }

            return in_array($fixedTarget, $this->localizableSitesFor($entry, $workflow), true)
                ? null
                : $this->t('target_site_unavailable', ['site' => $this->siteName($fixedTarget)]);
        }

        $open = $this->localizableSitesFor($entry, $workflow);

        return $open === [] ? $this->t('no_target_sites') : null;
    }

    /**
     * Whether the post already has a version in the given site — the one
     * authority both the explicit `target_missing` gate and the fixed-target
     * start transition read, so "already exists" always means the same thing.
     *
     * Root-based (WorkflowResolver::groupLocales()), so it answers the same
     * regardless of which version of the post the button was clicked on.
     */
    private function siteHasVersion($entry, string $site): bool
    {
        return in_array($site, app(WorkflowResolver::class)->groupLocales($entry), true);
    }

    /**
     * Localized reason why a transition can't be applied (blocked, not assigned,
     * or missing group), or null when it is allowed.
     *
     * @param  array<string, mixed>  $transition
     */
    private function transitionReason(array $transition, $entry, string $workflow, ?string $name = null): ?string
    {
        if ($this->canApplyTransition($transition, $entry, $workflow, $name)) {
            return null;
        }

        $user = User::current();

        // Most specific reason first — being locked out of the site explains it
        // fully, and pointing at the assignment instead would send the editor
        // chasing the wrong thing.
        if (! $this->canAccessEntrySite($entry)) {
            $site = method_exists($entry, 'locale') ? Site::get($entry->locale()) : null;

            return $this->t('requires_site_access', [
                'site' => $site?->name() ?? (string) $entry->locale(),
                // The permission string uses the handle, not the display name —
                // naming the wrong one sends the admin looking for a permission
                // that doesn't exist.
                'handle' => (string) $entry->locale(),
            ]);
        }

        // Before the permission reasons: "there is already a version in Einfache
        // Sprache" is the true answer even for a super admin, who passes every
        // permission check.
        if ($name !== null && ($blocked = $this->mechanicalBlocker($transition, $entry, $workflow, $name)) !== null) {
            return $blocked;
        }

        if ($user !== null && ! in_array((string) $user->id(), $this->assigneeIds($entry, $workflow), true)) {
            return $this->t('requires_assignee');
        }

        // Both lists in one sentence, since canApplyTransition() accepts either.
        $titles = collect($this->requiredGroups($transition))
            ->map(fn ($handle) => UserGroup::find($handle)?->title() ?? $handle)
            ->merge(collect($this->requiredRoles($transition))
                ->map(fn ($handle) => Role::find($handle)?->title() ?? $handle))
            ->implode(' / ');

        return $this->t('requires_group', ['group' => $titles]);
    }

    /**
     * Localized workflow state label. Preference: translation file →
     * YAML `places.<handle>.label` (edited via settings) → humanized handle.
     * Full implementation lives in DefinitionLoader so widgets and other
     * consumers share the same resolution rules.
     */
    private function stateLabel(string $state): string
    {
        return app(DefinitionLoader::class)->stateLabel($state);
    }

    /**
     * Localized transition label. Full implementation lives in DefinitionLoader so
     * the transitioner resolves revision messages by the same rules.
     */
    private function transitionLabel(string $name, ?string $fallback = null): string
    {
        return DefinitionLoader::transitionLabel($name, $fallback);
    }

    /**
     * Button variant for a transition's action button. An explicit
     * `metadata.variant` wins; otherwise `reject` gets `danger` (rejecting is
     * destructive to the editorial flow and should look like it) and everything
     * else stays `primary` — the variant every action button used before this
     * became configurable.
     */
    private function transitionVariant(string $name, ?string $configured = null): string
    {
        if (is_string($configured) && $configured !== ''
            && in_array($configured, WorkflowDefinitionWriter::TRANSITION_VARIANTS, true)) {
            return $configured;
        }

        return $name === 'reject' ? 'danger' : 'primary';
    }

    /**
     * Persisted comments for a subject in display shape.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Whether this entry/version has any comment that is "new" for the current
     * user (authored by someone else, after they last viewed that passage).
     *
     * Only comments whose passage still exists in the entry's content count —
     * orphaned comments (passage removed or its stable id changed) are ignored
     * so they don't leave a permanent "new" marker.
     */
    private function hasNewComments($entry, ?string $workflow): bool
    {
        return $this->newCommentCount($entry, $workflow) > 0;
    }

    /**
     * How many comments on this entry/version are new for the current user. The
     * queue shows this as `+N`, so a bare boolean is not enough.
     *
     * Same orphan rule as the boolean above: a comment whose passage no longer
     * exists in the entry's content is not counted, or a removed set would leave a
     * `+1` nobody can ever clear.
     */
    private function newCommentCount($entry, ?string $workflow): int
    {
        if (! $workflow) {
            return 0;
        }

        $id = $entry->id();

        // The listings ask this for every row. The bulk map answers "could there be
        // anything unread here" from two queries for the whole workflow, so the
        // expensive part below — two more queries plus a full content parse to weed
        // out orphaned passages — only runs for the handful of entries that
        // actually have unread comments.
        if (($this->newCommentCounts($workflow)[$id] ?? 0) === 0) {
            return 0;
        }

        if (array_key_exists($id, $this->newCommentCountCache)) {
            return $this->newCommentCountCache[$id];
        }

        $uid = User::current()?->id();
        $seen = app(WorkflowCommentStore::class)->seenAt($uid, $id, $workflow);
        $validRefs = collect(app(EntryContentPreview::class)->blocks($entry, $workflow))
            ->pluck('uuid')
            ->map(fn ($uuid) => (string) $uuid)
            ->all();

        $comments = app(WorkflowCommentStore::class)->forSubject($id, $workflow);
        $resolvedRoots = $this->resolvedRootIds($comments);

        // is_new is false for every comment in a resolved thread, so closed
        // threads drop out here exactly as they do from the bulk counts.
        return $this->newCommentCountCache[$id] = $comments
            ->filter(fn ($comment) => in_array((string) $comment->passage_ref, $validRefs, true)
                && $this->commentResource($comment, $uid, $seen, [], [], $resolvedRoots)['is_new'])
            ->count();
    }

    /**
     * Unread comment counts per subject for the current user, memoized per request.
     * `listing()` builds its rows twice — once for the page, once for the filter
     * badges — so without the memo the bulk query would run twice for no reason.
     *
     * @return array<string, int>
     */
    private function newCommentCounts(string $workflow): array
    {
        return $this->newCommentCountsCache[$workflow] ??= app(WorkflowCommentStore::class)
            ->newCountBySubject(User::current()?->id(), $workflow);
    }

    /**
     * Total comment counts per subject, memoized per request. Same reason as
     * newCommentCounts(): `listing()` builds its rows twice.
     *
     * @return array<string, int>
     */
    private function commentCounts(string $workflow): array
    {
        return $this->commentCountsCache[$workflow] ??= app(WorkflowCommentStore::class)
            ->countBySubject($workflow);
    }

    private function commentsFor(string $id, ?string $workflow): array
    {
        if (! $workflow) {
            return [];
        }

        $uid = User::current()?->id();
        $seen = app(WorkflowCommentStore::class)->seenAt($uid, $id, $workflow);
        $comments = app(WorkflowCommentStore::class)->forSubject($id, $workflow);

        // Compute current passage + container hashes ONCE, not per-comment.
        // Only touches passages that at least one comment references, and
        // only when the entry is still resolvable.
        $currentHashes = [];
        $currentContainerHashes = [];
        if ($entry = Entry::find($id)) {
            $passageRefs = $comments
                ->pluck('passage_ref')
                ->filter(fn ($ref) => is_string($ref) && $ref !== '')
                ->unique()
                ->values()
                ->all();

            if ($passageRefs !== []) {
                $preview = app(EntryContentPreview::class);
                $currentHashes = $preview->contentHashesForPassages($entry, $passageRefs, $workflow);
                $currentContainerHashes = $preview->containerHashesForPassages($entry, $passageRefs, $workflow);
            }
        }

        $resolvedRoots = $this->resolvedRootIds($comments);

        return $comments
            ->map(fn ($comment) => $this->commentResource($comment, $uid, $seen, $currentHashes, $currentContainerHashes, $resolvedRoots))
            ->values()
            ->all();
    }

    /**
     * Ids of the RESOLVED roots among a subject's comments — what decides whether
     * a reply sits in a closed thread without a query per reply.
     *
     * @param  \Illuminate\Support\Collection<int, WorkflowComment>  $comments
     * @return array<int, int>
     */
    private function resolvedRootIds($comments): array
    {
        return $comments
            ->filter(fn (WorkflowComment $comment) => $comment->isRoot() && $comment->isResolved())
            ->map(fn (WorkflowComment $comment) => (int) $comment->id)
            ->values()
            ->all();
    }

    /**
     * Whether a comment sits in a resolved thread. `$resolvedRoots` from
     * resolvedRootIds() when the caller has the whole subject loaded; otherwise
     * a reply's root is read.
     *
     * @param  array<int, int>|null  $resolvedRoots
     */
    private function inResolvedThread(WorkflowComment $comment, ?array $resolvedRoots = null): bool
    {
        if ($comment->isRoot()) {
            return $comment->isResolved();
        }

        if ($resolvedRoots !== null) {
            return in_array((int) $comment->parent_id, $resolvedRoots, true);
        }

        return WorkflowComment::query()
            ->where('id', $comment->parent_id)
            ->whereNotNull('resolved_at')
            ->exists();
    }

    /**
     * Display shape for a single comment — the contract the detail pages and
     * every comment endpoint share:
     *
     *   id, parent_id, at, edited_at, author{id,name,avatar,initials}, body,
     *   mentions[{id,name}], ref{uuid,handle}|null, resolved{at,by{id,name}}|null,
     *   can_edit, can_delete, can_resolve, is_new, content_stale, container_changed
     *
     * - `resolved` and `can_resolve` concern the THREAD and live on its root;
     *   replies carry null / false.
     * - `can_edit`: the author only (a system comment has none). `can_delete`:
     *   the author or a moderator (canModerateComments()). `can_resolve`: see
     *   canResolveThread().
     * - `is_new`: by someone else, after the user last viewed that passage
     *   (per-user read state) — and never inside a resolved thread, so a closed
     *   discussion stops nagging.
     * - `content_stale`: the referenced passage was edited since the comment was
     *   written; `container_changed`: anything else in the surrounding Bard-field
     *   container was. Independent; a reply inherits its root's hashes, so both
     *   describe the passage as it was when the THREAD started.
     *
     * @param  array<string, Carbon>  $seen
     * @param  array<string, string|null>  $currentHashes           keyed by passage_ref
     * @param  array<string, string|null>  $currentContainerHashes  keyed by passage_ref
     * @param  array<int, int>|null  $resolvedRoots  see resolvedRootIds()
     * @return array<string, mixed>
     */
    private function commentResource(
        WorkflowComment $comment,
        ?string $uid = null,
        array $seen = [],
        array $currentHashes = [],
        array $currentContainerHashes = [],
        ?array $resolvedRoots = null,
    ): array {
        $uid = $uid ?? User::current()?->id();
        $uid = $uid === null ? null : (string) $uid;
        $seenAt = $comment->passage_ref ? ($seen[$comment->passage_ref] ?? null) : null;
        $isAuthor = $comment->user_id !== null && (string) $comment->user_id === $uid;

        $isNew = ! $isAuthor
            && $comment->passage_ref !== null
            && ($seenAt === null || ($comment->created_at && $comment->created_at->gt($seenAt)))
            && ! $this->inResolvedThread($comment, $resolvedRoots);

        // Stale = we have a stored hash from write-time AND the current hash
        // for the same passage exists AND differs. If either side is null
        // (pre-migration comment, or passage no longer exists) we do NOT
        // flag as stale — orphaned passages are their own separate signal.
        $storedHash = $comment->content_hash;
        $currentHash = $comment->passage_ref ? ($currentHashes[$comment->passage_ref] ?? null) : null;
        $contentStale = $storedHash !== null && $currentHash !== null && $storedHash !== $currentHash;

        // Same idea, one scope wider: has anything else in the surrounding
        // Bard-field container been edited since the comment was written?
        $storedContainer = $comment->container_hash;
        $currentContainer = $comment->passage_ref ? ($currentContainerHashes[$comment->passage_ref] ?? null) : null;
        $containerChanged = $storedContainer !== null && $currentContainer !== null && $storedContainer !== $currentContainer;

        $isRoot = $comment->isRoot();

        return [
            'id' => (int) $comment->id,
            'parent_id' => $isRoot ? null : (int) $comment->parent_id,
            'at' => optional($comment->created_at)->toIso8601String(),
            'edited_at' => optional($comment->edited_at)->toIso8601String(),
            'author' => $this->commentAuthor($comment),
            'body' => $comment->body,
            'mentions' => $this->commentMentions($comment),
            'ref' => $comment->passage_ref
                ? ['uuid' => $comment->passage_ref, 'handle' => $comment->passage_handle]
                : null,
            'resolved' => $isRoot && $comment->isResolved()
                ? [
                    'at' => $comment->resolved_at->toIso8601String(),
                    'by' => [
                        'id' => $comment->resolved_by,
                        'name' => $comment->resolved_by_name
                            ?? $this->userDisplayName($this->commentUser($comment->resolved_by))
                            ?? '—',
                    ],
                ]
                : null,
            'can_edit' => $isAuthor,
            'can_delete' => $isAuthor || $this->canModerateComments(),
            'can_resolve' => $isRoot && $this->canResolveThread($comment, $uid),
            'is_new' => (bool) $isNew,
            'content_stale' => $contentStale,
            'container_changed' => $containerChanged,
        ];
    }

    /** @var array<string, \Statamic\Contracts\Auth\User|null> user id => user, per request */
    private array $commentUserCache = [];

    /** @var array<string, array<int, array<string, mixed>>> entry id => mentionable users, per request */
    private array $mentionableCache = [];

    private function commentUser(?string $id): ?\Statamic\Contracts\Auth\User
    {
        if ($id === null || $id === '') {
            return null;
        }

        return array_key_exists($id, $this->commentUserCache)
            ? $this->commentUserCache[$id]
            : $this->commentUserCache[$id] = User::find($id);
    }

    private function userDisplayName(?\Statamic\Contracts\Auth\User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $name = (string) ($user->name() ?: $user->email());

        return $name !== '' ? $name : null;
    }

    /**
     * `{id, name, avatar, initials}` — the one user shape the comment UI speaks
     * (author, currentUser, mentionableUsers).
     *
     * @return array{id: string, name: string, avatar: string|null, initials: string}
     */
    private function commentUserSummary(\Statamic\Contracts\Auth\User $user): array
    {
        $name = $this->userDisplayName($user) ?? (string) $user->id();
        $avatar = $user->avatar();

        return [
            'id' => (string) $user->id(),
            'name' => $name,
            'avatar' => is_string($avatar) && $avatar !== '' ? $avatar : null,
            'initials' => $user->name() ? (string) $user->initials() : $this->initialsFrom($name),
        ];
    }

    /**
     * The author as stored: `author_name` is frozen at write time (a renamed or
     * deleted account still reads as whoever wrote it), avatar and initials come
     * from the live account when it still exists.
     *
     * @return array{id: string|null, name: string, avatar: string|null, initials: string}
     */
    private function commentAuthor(WorkflowComment $comment): array
    {
        $name = (string) $comment->author_name;
        $user = $this->commentUser($comment->user_id === null ? null : (string) $comment->user_id);

        if ($user === null) {
            return [
                'id' => $comment->user_id === null ? null : (string) $comment->user_id,
                'name' => $name,
                'avatar' => null,
                'initials' => $this->initialsFrom($name),
            ];
        }

        return ['name' => $name !== '' ? $name : $this->commentUserSummary($user)['name']]
            + $this->commentUserSummary($user);
    }

    /**
     * Up to two initials from a display name — Statamic's User::initials() rule
     * (first letter of the first word and of the rest), for authors without a
     * live account.
     */
    private function initialsFrom(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            return '?';
        }

        $parts = preg_split('/\s+/u', $name, 2) ?: [$name];

        return mb_strtoupper(mb_substr($parts[0], 0, 1).(isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
    }

    /**
     * Mentioned users that still exist, as `{id, name}`. A deleted account
     * drops out; the `@Name` in the body text stays as written.
     *
     * @return array<int, array{id: string, name: string}>
     */
    private function commentMentions(WorkflowComment $comment): array
    {
        return collect((array) ($comment->mentions ?? []))
            ->map(fn ($id) => $this->commentUser((string) $id))
            ->filter()
            ->map(fn ($user) => ['id' => (string) $user->id(), 'name' => $this->userDisplayName($user) ?? (string) $user->id()])
            ->values()
            ->all();
    }

    /**
     * Who may resolve or reopen a thread (ROOT comments only): its author, a
     * comment moderator, or anyone currently assigned to the entry in this
     * workflow — the people whose job the discussion is about.
     */
    private function canResolveThread(WorkflowComment $root, ?string $uid): bool
    {
        if (! $root->isRoot() || $uid === null) {
            return false;
        }

        if ($root->user_id !== null && (string) $root->user_id === $uid) {
            return true;
        }

        if ($this->canModerateComments()) {
            return true;
        }

        // Not memoised: assignments change between requests, and a controller
        // instance can outlive one (it is cached on its route). One indexed
        // query per ROOT, and only for users who are neither author nor moderator.
        $assignees = array_map(
            'strval',
            app(WorkflowAssigneeStore::class)->idsFor((string) $root->subject_id, (string) $root->workflow),
        );

        return in_array($uid, $assignees, true);
    }

    /**
     * Users who can be @-mentioned on this entry: everyone who could open its
     * instance page — Control Panel access plus access to the entry's site (the
     * same SitePolicy check canAccessEntrySite() makes for the current user).
     * Sorted by name.
     *
     * @return array<int, array{id: string, name: string, avatar: string|null, initials: string}>
     */
    private function mentionableUsers($entry): array
    {
        $key = (string) $entry->id();

        if (isset($this->mentionableCache[$key])) {
            return $this->mentionableCache[$key];
        }

        $site = Site::get($entry->locale());

        return $this->mentionableCache[$key] = User::all()
            ->filter(fn ($user) => ($user->isSuper() || $user->hasPermission('access cp'))
                && $site !== null
                && (bool) $user->can('view', $site))
            ->map(fn ($user) => $this->commentUserSummary($user))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    // ---- Inertia pages -----------------------------------------------------

    /**
     * The bare `/cp/workflows` page. The cross-workflow "all posts" table lives
     * on the dashboard now (see overviewProps()), so this only forwards there —
     * the route stays for bookmarks and for the links that still name it.
     */
    public function queue()
    {
        return redirect(cp_route('dashboard'));
    }

    public function workflow(string $slug, DefinitionLoader $loader)
    {
        $name = collect($loader->all())->first(
            fn (string $name) => ($loader->raw($name)['metadata']['nav']['slug'] ?? null) === $slug
        );

        abort_if($name === null, 404);

        $raw = $loader->raw($name);
        $collections = $raw['collections'] ?? [];
        $create = $this->createOptions($name, $raw);

        return Inertia::render('WorkflowsQueue', [
            'currentUser' => $this->currentUserName(),
            'workflow' => [
                'name' => $name,
                'label' => isset($raw['metadata']['nav'])
                    ? DefinitionLoader::navLabel($raw['metadata']['nav'])
                    : ($raw['metadata']['label'] ?? $name),
            ],
            'workflowName' => $name,
            // What the big listing is called. "Alle Beiträge" is a lie in a
            // localization manager: it lists the posts this manager can work on,
            // which is those that have a version in its source site.
            'listingHeading' => app(WorkflowResolver::class)->isLocalizationWorkflow($raw)
                ? $this->t('translatable_posts')
                : $this->t('all_posts'),
            'listingUrl' => cp_route('workflows.listing'),
            'columns' => $this->columns($name),
            'myColumns' => $this->columns($name, true),
            'filters' => $this->filters($this->rowsFor($name), $name),
            // Drives the per-collection tabs of the "all posts" listing. YAML
            // order is kept so the settings editor decides the tab order.
            'collections' => collect($collections)
                ->map(fn (string $handle) => ['handle' => $handle, 'title' => $this->collectionTitle($handle)])
                ->values()
                ->all(),
            'createUrl' => $create['createUrl'] ?? null,
            'createCta' => $create['createCta'] ?? null,
            'createCollections' => $create['createCollections'] ?? [],
            'stateColors' => $loader->placeColors(),
            'stateLabels' => $loader->placeLabels(),
        ]);
    }

    /**
     * The "New post" props of a workflow — `createUrl`, `createCta`,
     * `createCollections` — or null when the current user may not create in it.
     * Shared by the manager page and the dashboard's overview widget, so both
     * offer creating under the same rules.
     *
     * @param  array<string, mixed>  $raw
     * @return array{createUrl: string, createCta: array<string, mixed>|null, createCollections: array<int, array{value: string, label: string}>}|null
     */
    private function createOptions(string $name, array $raw): ?array
    {
        // Creating entries belongs to the workflow that OWNS a collection (the
        // first entry-backed workflow for it). A secondary workflow such as
        // translation governs shared collections but must not create into them,
        // so it gets no "new post" button.
        $ownCollections = collect($raw['collections'] ?? [])
            ->filter(fn (string $handle) => $this->workflowForCollection($handle) === $name)
            ->values();

        // A localization workflow never creates: it only ever moves entries that
        // already exist into further sites, and that runs through transfer(). Note
        // this is NOT covered by the ownership check above — a translation workflow
        // can legitimately be the only one governing a collection and would
        // otherwise own it, and offer a create button.
        $canCreate = $ownCollections->isNotEmpty()
            && ! app(WorkflowResolver::class)->isLocalizationWorkflow($raw)
            && $this->canCreatePost($raw);

        if (! $canCreate) {
            return null;
        }

        return [
            'createUrl' => cp_route('workflows.api.posts'),
            // What the modal's confirm button does besides creating: the coupled
            // transition, with the people to pick from when its assignment is
            // manual — see createCta() and storePost(). Null for a plain "create".
            'createCta' => $this->createCta($raw, (string) $ownCollections->first()),
            'createCollections' => $ownCollections
                ->map(fn (string $handle) => ['value' => $handle, 'label' => $this->collectionTitle($handle)])
                ->values()
                ->all(),
        ];
    }

    /**
     * Instance summary under its manager's slug — the canonical URL.
     *
     * The slug segment is what keeps the CP nav open on this page: Statamic
     * matches a nav item's active pattern against the request PATH only, so a
     * workflow passed as `?workflow=` is invisible to it. Resolving the slug here
     * and handing the name on as the query parameter keeps
     * `buildInstancePayload()` unaware of which of the two routes it was reached
     * through.
     */
    public function showInWorkflow(Request $request, string $slug, string $id)
    {
        $workflow = $this->workflowBySlug($slug, app(DefinitionLoader::class));
        abort_if($workflow === null, 404);

        $request->query->set('workflow', $workflow);

        [$component, $props] = $this->buildInstancePayload($request, $id);

        return Inertia::render($component, $props);
    }

    /**
     * Instance summary by id alone. Kept for bookmarks and links stored before
     * the canonical route existed — it resolves the workflow and forwards, so
     * those land on a page whose nav behaves. A workflow without `metadata.nav`
     * has no manager page to nest under and renders here directly.
     */
    public function show(Request $request, string $id)
    {
        $entry = Entry::find($id);
        abort_if($entry === null, 404);

        $workflow = $this->workflowFor($entry, $request->query('workflow'));

        if ($workflow !== null && ($slug = $this->navSlug($workflow)) !== null) {
            return redirect()->to(cp_route('workflows.workflow.show', ['slug' => $slug, 'id' => $id]));
        }

        [$component, $props] = $this->buildInstancePayload($request, $id);

        return Inertia::render($component, $props);
    }

    /**
     * Every link to an instance summary goes through InstanceUrl so the
     * nav-friendly form is the one that actually gets used — building
     * `workflows.show` by hand still works, but sends the user through a
     * redirect. One instance per request keeps its slug memoisation useful
     * across the hundreds of rows a listing builds.
     */
    private ?InstanceUrl $instanceUrls = null;

    private function instanceUrl(string $id, ?string $workflow = null): string
    {
        return ($this->instanceUrls ??= app(InstanceUrl::class))->for($id, $workflow);
    }

    private function navSlug(string $workflow): ?string
    {
        return ($this->instanceUrls ??= app(InstanceUrl::class))->navSlug($workflow);
    }

    /**
     * Assemble the props for the instance-summary page. Returns
     * [componentName, propsArray] — the component slot is what picks between the
     * standard summary and the translation source-vs-target view.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    /**
     * The plain-language QA report for this version, or null.
     *
     * Read back out of the comment it was filed as — that comment IS the durable
     * record: the job rows it came from are deleted the moment a rewrite settles
     * (`PlainLanguageJobStore::forget()`), so there is nothing else left to ask.
     * Found by `kind` rather than by "the one with no author", which happens to
     * be unambiguous today and would stop being so the first time anything else
     * writes a system comment.
     *
     * The stored body carries the heading as its first line, because the comment
     * thread needs it to make sense on its own. The alert has its own heading, so
     * that line is stripped here — tolerantly: `Str::after()` hands back the whole
     * string when the marker is absent, which is what an older or hand-edited
     * comment should do.
     *
     * @param  array<string, mixed>  $raw
     */
    /**
     * The failure note to show on the instance page while a version sits in the
     * plain-language failure place. Keyed on the current place, like
     * qsNotesHighlight() next door: null everywhere else, so the alert cannot outlive
     * the state it describes. Reads the newest `plain_language_failed` event, which both the poll
     * command and the refused-submit path write.
     */
    private function plainLanguageFailureFor(string $id, string $workflow, array $raw, ?string $current): ?array
    {
        $place = $raw['metadata']['plain_language_failed_place'] ?? null;

        if (! is_string($place) || $place === '' || $current !== $place) {
            return null;
        }

        $event = app(WorkflowEventStore::class)->latestOfType($id, $workflow, 'plain_language_failed');

        $note = trim((string) ($event->note ?? ''));

        // The timestamp rides along so the page can seed its poll baseline with it. Without
        // that, the first poll after every page load sees a failure it has no record of and
        // raises a toast about something that happened hours ago.
        return $note === '' ? null : [
            'note' => $note,
            'at' => $event->created_at?->toIso8601String(),
        ];
    }

    /**
     * The service's QA report for the instance page, without its heading, or null
     * when there is none. Not bound to a place: it sits behind a button now, and a
     * button that stays around does not wear out the way a permanent alert does.
     * Where the report should be read FIRST is qsNotesHighlight()'s business.
     */
    private function qsNotesFor(string $id, string $workflow): ?string
    {
        $comment = app(WorkflowCommentStore::class)
            ->latestOfKind($id, $workflow, WorkflowComment::KIND_PLAIN_LANGUAGE_QS);

        if ($comment === null) {
            return null;
        }

        $body = trim(Str::after((string) $comment->body, $this->t('translation_plain_qs_notes')));

        return $body === '' ? null : $body;
    }

    /**
     * Whether the QA-notes button should stand out: while the version sits in
     * `plain_language_completed_place`, where a finished rewrite lands and the
     * review starts. A workflow that names no completed place never highlights.
     *
     * @param  array<string, mixed>  $raw
     */
    private function qsNotesHighlight(array $raw, ?string $current): bool
    {
        $place = $raw['metadata']['plain_language_completed_place'] ?? null;

        return is_string($place) && $place !== '' && $current === $place;
    }

    private function buildInstancePayload(Request $request, string $id): array
    {
        // Honour an explicitly requested workflow (e.g. the translation manager)
        // for the context.
        $entry = Entry::find($id);
        abort_if($entry === null, 404);

        $workflow = $this->workflowFor($entry, $request->query('workflow'));
        abort_if($workflow === null, 404);

        $raw = app(DefinitionLoader::class)->raw($workflow);
        $current = app(WorkflowStateStore::class)->get($id, $workflow) ?? $this->defaultState($raw, $entry);

        // Transitions available from the current state. System transitions
        // (e.g. the revision loop) are driven automatically, not by buttons.
        $actions = $this->entryActions($entry, $workflow, $current);

        $applyUrls = collect($actions)
            ->mapWithKeys(fn ($action) => [
                $action['transition']['name'] => cp_route('workflows.api.apply', [
                    'id' => $id,
                    'name' => $action['transition']['name'],
                    'workflow' => $workflow,
                ]),
            ])
            ->all();

        $instance = [
            'id' => $id,
            'type' => $workflow,
            'subject' => [
                'kind' => 'entry',
                'id' => $id,
                'collection' => $entry->collectionHandle(),
                'title' => (string) $entry->value('title'),
                'site' => $entry->locale(),
                'site_name' => $this->siteName($entry->locale()),
                // Short badge next to the title. The HANDLE, uppercased — see
                // siteCode() for why not the locale's language.
                'code' => $this->siteCode($entry->locale()),
                'lang' => strtoupper($this->siteGroup($entry->locale())[1]),
                // null hides the "Edit" button in both detail views — see
                // canOpenEntryEditor() for why a link would be actively harmful.
                'cp_url' => $this->canOpenEntryEditor($entry) ? $entry->editUrl() : null,
                // null hides the "Live preview" button — see previewUrlFor().
                'preview_url' => $this->previewUrlFor($entry),
                // `live` | `working_copy` | `draft`: what that button opens — the
                // public page for a published post, otherwise a tokenised preview.
                // Label, icon and tooltip follow it.
                'preview_mode' => EntryPreviewController::modeFor($entry),
            ],
            'current_state' => $current,
            // When the subject arrived in its current place — "in this state
            // since" under the title. Null for a row the backfill could not date.
            'entered_at' => app(WorkflowStateStore::class)->enteredAt($id, $workflow)?->toIso8601String(),
            // The baseline a PlaceActions::REFRESH_INTERVAL timer compares its
            // periodic instanceStatus() check against — see there for why both
            // this and current_state matter.
            'updated_at' => $entry->lastModified()?->toIso8601String(),
            // This site-version's own assignees (managed by the workflow, not the entry).
            'assignee_ids' => $this->assigneeIds($entry, $workflow),
            'assignees' => $this->assigneeNames($entry, $workflow),
            'assignee' => implode(', ', $this->assigneeNames($entry, $workflow)),
        ];

        // Not loaded at all without the right — see canViewHistory().
        $canViewHistory = $this->canViewHistory();

        $props = [
            'instance' => $instance,
            'content' => app(EntryContentPreview::class)->blocks($entry, $workflow),
            'versions' => $this->entryVersions($entry, $workflow, $id),
            'canViewHistory' => $canViewHistory,
            'history' => $canViewHistory ? $this->entryHistory($id, $workflow) : [],
            'historyDiffUrlTemplate' => cp_route('workflows.api.history.diff', ['id' => $id, 'event' => '__EVENT__']),
            'comments' => $this->commentsFor($id, $workflow),
            'commentUrl' => cp_route('workflows.api.comments', ['id' => $id, 'workflow' => $workflow]),
            // Named routes, not `commentUrl` with a suffix glued on. `commentUrl`
            // ends in `?workflow=<name>`, so `${commentUrl}/read` puts the path
            // segment INSIDE the query string — the request then lands on
            // storeComment() and fails validation, and the delete lands on a
            // POST-only route. Both failures were swallowed by the client's catch,
            // which is why read markers never actually persisted.
            'commentReadUrl' => cp_route('workflows.api.comments.read', ['id' => $id, 'workflow' => $workflow]),
            // The bookmark button in the header — see watch()/unwatch().
            'watching' => app(WorkflowWatchStore::class)->isWatching(User::current()?->id(), $id, $workflow),
            'watchUrl' => cp_route('workflows.api.watch', ['id' => $id, 'workflow' => $workflow]),
            'commentDeleteUrlTemplate' => cp_route('workflows.api.comments.destroy', ['id' => $id, 'comment' => '__COMMENT__', 'workflow' => $workflow]),
            'commentUpdateUrlTemplate' => cp_route('workflows.api.comments.update', ['id' => $id, 'comment' => '__COMMENT__', 'workflow' => $workflow]),
            'commentResolveUrlTemplate' => cp_route('workflows.api.comments.resolve', ['id' => $id, 'comment' => '__COMMENT__', 'workflow' => $workflow]),
            'commentReopenUrlTemplate' => cp_route('workflows.api.comments.reopen', ['id' => $id, 'comment' => '__COMMENT__', 'workflow' => $workflow]),
            // Who is writing (avatar in the composer) and whom they may @-mention —
            // the same list storeComment()/updateComment() validate `mentions` against.
            'currentUser' => ($me = User::current()) ? $this->commentUserSummary($me) : null,
            'mentionableUsers' => $this->mentionableUsers($entry),
            // Set only when this version is behind the post it belongs to — the same
            // predicate as the „veraltet" marker in the listings, so the notice and
            // the badge can never contradict each other. Carries both dates,
            // because "the source changed" is only actionable once you can see how
            // far apart the two are.
            'sourceChanged' => $this->sourceChangedNotice($entry),
            'actions' => $actions,
            'applyUrls' => $applyUrls,
            'assigneeUrl' => cp_route('workflows.api.assignees', ['id' => $id, 'workflow' => $workflow]),
            'assignableUsers' => $this->assignableUsers(),
            // Super admins and admins group may set the workflow state manually (correction escape hatch).
            'canSetState' => $this->isPrivilegedUser(),
            // Whether the assignee picker is offered at all — `metadata.assigner_groups`,
            // see canAssign(). The endpoint enforces the same rule; this only decides
            // whether the pencil is there to click.
            'canAssign' => $this->canAssign($raw),
            'stateUrl' => cp_route('workflows.api.state', ['id' => $id, 'workflow' => $workflow]),
            // Every place, reserved ones included — this feeds the privileged
            // "set the state by hand" Select, which has to be able to name any
            // place a subject could legitimately be in. Deliberately NOT narrowed
            // to the progress bar's steps below: those are two different
            // questions, and answering both from one list is what would force the
            // Select to lose `pending_revision`.
            'places' => collect($raw['places'] ?? [])
                ->map(fn ($place, $handle) => ['value' => $handle, 'label' => $this->stateLabel($handle)])
                ->values()
                ->all(),
            // The same places as a path: the ordered steps plus, when the subject
            // is standing off the line, the branch it took. See ProgressSteps for
            // why this is resolved here and not in the component.
            'steps' => ProgressSteps::for($raw, $current),
            // Layout AND position of that bar, from `metadata.progress_orientation`
            // — one value decides both, see ProgressSteps::ORIENTATION.
            'progressOrientation' => ProgressSteps::orientation($raw),
            // Back to the dashboard, whose overview widget lists the posts of
            // every workflow — the old `/cp/workflows` page only forwards there.
            'queueUrl' => cp_route('dashboard'),
            'stateColors' => app(DefinitionLoader::class)->placeColors(),
            'stateLabels' => app(DefinitionLoader::class)->placeLabels(),
            // General-purpose reminder, not tied to the plain-language service —
            // see PlaceActions::REFRESH_INTERVAL. Null when the current place
            // carries none, which is the common case; the client only starts a
            // timer when it gets a number.
            'refreshAfterMinutes' => $current === null
                ? null
                : app(WorkflowResolver::class)->refreshIntervalMinutesFor($raw, $current),
            'statusUrl' => cp_route('workflows.api.instance.status', ['id' => $id, 'workflow' => $workflow]),
        ];

        // Site-by-site translation detail: source vs this localization.
        if (($raw['metadata']['detail'] ?? 'default') === 'translation') {
            // The left panel is a provenance comparison: "this version, next to the
            // one it was made from". Two versions have nothing to the left of them —
            // the resolved source itself, and the post's ORIGIN.
            //
            // The origin matters because the configured source may well be a version
            // derived from it: with `translation_source_site: simple`, opening the
            // German original showed the Einfache-Sprache version as its source,
            // which inverts what happened — that version was written FROM the German
            // one. The source setting says what future translations read; it cannot
            // turn the original into a translation of its own derivative.
            //
            // A configured source that does not exist falls back to the origin HERE
            // and only here: this is a read-only comparison. The transitions refuse
            // to run instead — see mechanicalBlocker().
            $root = $entry->root();
            $source = $this->sourceEntryFor($entry, $workflow)[0] ?? $root;
            $isOrigin = $entry->id() === $root->id();

            // `isSource` means "this version has no source to show", which is what
            // the client uses it for (it hides the panel and headlines the remaining
            // one as the source).
            $props['isSource'] = $isOrigin || $source->id() === $entry->id();
            // How THIS reader gets the source column — the workflow's audience
            // rules (metadata.translation_source_view, see SourceViewRules).
            // Separate from `isSource` on purpose: that one says whether a
            // source exists at all and headlines the remaining column; this one
            // says whether the reader is meant to see it.
            $sourceView = SourceViewRules::modeFor($raw, User::current());
            $props['sourceView'] = $sourceView;
            // Skipped entirely when there is nothing to compare — a full content
            // preview of a version nobody will look at is wasted work — and when
            // the rule says `hidden`: then the source must not reach the browser,
            // not merely stay out of view. (The translation dry run,
            // translationPayload(), also carries source text; it is gated on its
            // own by canApplyTransition and is a tool for the source side, not a
            // reading view, so it is left alone.)
            $props['source'] = ($props['isSource'] || $sourceView === SourceViewRules::HIDDEN)
                ? []
                : app(EntryContentPreview::class)->blocks($source, $workflow);
            $props['sourceName'] = $this->siteName($source->locale());
            $props['sourceLang'] = strtoupper($this->siteGroup($source->locale())[1]);
            // Where the client falls back to if THIS version disappears from under
            // it — see instanceStatus() and PollPlainLanguageCommand's delete-on-
            // failure path. Resolved now, while the source is known to exist, so
            // the client never has to ask a (possibly gone) entity for it later.
            $props['sourceUrl'] = $this->instanceUrl((string) $source->id(), $workflow);
            $props['canTransfer'] = $this->canTransfer($workflow);
            $props['transferUrl'] = cp_route('workflows.api.transfer', ['id' => $id, 'workflow' => $workflow]);
            $props['transferSites'] = collect($this->transferableSitesFor($entry, $workflow))
                ->map(fn (string $site) => ['value' => $site, 'label' => $this->siteName($site)])
                ->values()
                ->all();

            // Whether THIS page's own content panel should show a spinner instead
            // of `content` above — see WorkflowResolver::showsPendingRewriteSpinner()
            // and the place metadata it reads. Both conditions matter: the CURRENT
            // place has to have opted in (a translation manager reading DeepL
            // synchronously has nothing async to wait for, and a place the version
            // has already moved past is done waiting), and the version being
            // VIEWED — not the source, not the post in general — has to actually
            // have an open job. `$id` is the entity `buildInstancePayload()` was
            // called for, i.e. exactly the one `content` describes.
            $props['pendingRewrite'] = app(WorkflowResolver::class)->showsPendingRewriteSpinner($raw, $current)
                && app(PlainLanguageJobStore::class)->hasOpen($id, $workflow);

            // Which of the configured attempts is running, so the spinner can say "2 of 3"
            // instead of leaving an editor to wonder whether anything is happening. Null
            // when nothing is in flight — the spinner is then not shown anyway.
            $props['plainLanguageAttempt'] = $props['pendingRewrite']
                ? [
                    'submission' => app(PlainLanguageJobStore::class)->currentSubmission($id, $workflow),
                    'max' => app(PlainLanguageConfig::class)->maxAttempts(),
                ]
                : null;

            // The service's QA report, behind its own button in the header instead
            // of only sitting in the comment thread. It says what was simplified,
            // added and — the part that decides whether a plain-language version
            // may go out at all — left out.
            //
            // Available whenever a report exists; highlighted only in
            // `plain_language_completed_place`, where the review starts reading.
            $props['qsNotes'] = $this->qsNotesFor($id, $workflow);
            $props['qsNotesHighlight'] = $this->qsNotesHighlight($raw, $current);
            $props['plainLanguageFailure'] = $this->plainLanguageFailureFor($id, $workflow, $raw, $current);
            // Gates the "technical details" switch (field handle + passage uuid)
            // in the content columns — see the default summary below.
            $props['isSuper'] = $this->isSuperAdmin();

            return ['WorkflowsTranslationInstance', $props];
        }

        // Gates the "technical details" switch over the content (field handle +
        // passage uuid). Deliberately the
        // strict isSuperAdmin() and not isPrivilegedUser() — an admins-group
        // editor has no use for a blueprint handle either.
        $props['isSuper'] = $this->isSuperAdmin();

        return ['WorkflowsInstance', $props];
    }

    /**
     * Users that can be assigned in the workflow, shaped for the header picker.
     *
     * @return array<int, array<string, string>>
     */
    private function assignableUsers(): array
    {
        return User::all()
            ->map(fn ($user) => [
                'id' => (string) $user->id(),
                'name' => $user->name() ?? $user->email(),
                // Group titles the user belongs to; drives the drawer's filter
                // dropdown + badges (Super Admin status intentionally hidden).
                'groups' => $user->groups()->map->title()->filter()->unique()->values()->all(),
                // `site_allocation` (blueprint field on the User) — the site
                // this user is assigned to. Rendered as a leading tag in the
                // assignee drawer. Empty when not set.
                'site_allocation' => $this->userSiteAllocation($user),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Resolve a user's `site_allocation` value (blueprint field: sites,
     * max_items 1) to the site's display name. Returns null when the field
     * is unset or the referenced site does not exist.
     */
    private function userSiteAllocation($user): ?string
    {
        $value = $user->get('site_allocation');

        $handle = is_array($value) ? ($value[0] ?? null) : $value;

        if (! is_string($handle) || $handle === '') {
            return null;
        }

        return optional(Site::get($handle))->name();
    }

    // ---- JSON API ----------------------------------------------------------

    public function actionsJson(Request $request, string $id)
    {
        $entry = Entry::find($id);
        abort_if($entry === null, 404);

        $workflow = $this->workflowFor($entry, $request->query('workflow'));
        abort_if($workflow === null, 404);

        $raw = app(DefinitionLoader::class)->raw($workflow);
        $current = app(WorkflowStateStore::class)->get($id, $workflow) ?? $this->defaultState($raw, $entry);
        // No state, no place to read a publish flag from — and no actions either,
        // since no transition leads out of nothing.
        $allowPublish = $current !== null
            && ($raw['places'][$current]['metadata']['allow_publish'] ?? false) === true;

        return response()->json([
            'data' => $this->entryActions($entry, $workflow, $current),
            'current_state' => $current,
            'current_state_label' => $current === null ? null : $this->stateLabel($current),
            'allow_publish' => $allowPublish,
            // Same pair the fieldtype ships on mount — the panel re-reads both after
            // every transition, and a refresh that dropped this one would re-hide the
            // native publish button the moment somebody clicked something.
            'gates_publishing' => app(WorkflowResolver::class)->publishPlaces($raw) !== [],
        ]);
    }

    /**
     * The cheap fingerprint a PlaceActions::REFRESH_INTERVAL timer polls: the
     * current place plus the entry's own last-modified timestamp. Both matter —
     * the place changes when a human applies a transition, the timestamp changes
     * when the entry's content is overwritten without a place change (e.g. the
     * plain-language poll command landing a rewrite while the version stays in
     * `in_plain_language` until somebody submits it). Either one differing from
     * what the client loaded at page-open means "something happened", which is
     * all the client needs to decide whether to offer a reload.
     */
    public function instanceStatus(Request $request, string $id)
    {
        $entry = Entry::find($id);
        abort_if($entry === null, 404);

        $workflow = $this->workflowFor($entry, $request->query('workflow'));
        abort_if($workflow === null, 404);

        $raw = app(DefinitionLoader::class)->raw($workflow);
        $current = app(WorkflowStateStore::class)->get($id, $workflow) ?? $this->defaultState($raw, $entry);

        // The newest plain-language failure, so the polling detail pages can raise an
        // error toast the moment one appears. The poll command runs unattended with no
        // session to flash a Toast into, and this endpoint is the only recurring request
        // there is — so it carries the news instead. The client compares `at` against its
        // page-load baseline, which is why the timestamp matters more than the note.
        //
        // Bound to the failure PLACE, exactly like the alert: a failure event only exists
        // once a run has been given up on, and giving up parks the version. Reporting the
        // event unconditionally would keep announcing it after a human restarted the
        // rewrite — the version is then working again in the waiting place, and a toast
        // about the previous run is worse than no toast at all.
        $failure = $this->plainLanguageFailureFor($id, $workflow, $raw, $current);

        // The running attempt, so a page that is watching a slow rewrite can update the
        // spinner without a reload. Null once nothing is in flight.
        $submission = app(PlainLanguageJobStore::class)->currentSubmission($id, $workflow);

        return response()->json([
            'current_state' => $current,
            'updated_at' => $entry->lastModified()?->toIso8601String(),
            'title' => (string) ($entry->get('title') ?? $entry->slug()),
            'plain_language_failure' => $failure,
            'plain_language_attempt' => $submission === null ? null : [
                'submission' => $submission,
                'max' => app(PlainLanguageConfig::class)->maxAttempts(),
            ],
        ]);
    }

    /**
     * Transitions available from the given state, in display shape (used by
     * the instance-detail page and by the `workflow_actions` fieldtype).
     *
     * @return array<int, array<string, mixed>>
     */
    private function entryActions($entry, string $workflow, ?string $current): array
    {
        // Not part of this workflow (an unpublished post in a localization manager):
        // there is no state to lead out of, so there is nothing to offer.
        if ($current === null) {
            return [];
        }

        $raw = app(DefinitionLoader::class)->raw($workflow);
        $actorId = (string) (User::current()?->id() ?? '') ?: null;

        $claimed = $this->plainLanguageSite($workflow);
        $onSourceOfPlainLanguage = $claimed !== null && (string) $entry->locale() !== $claimed;

        return collect($raw['transitions'] ?? [])
            ->reject(fn ($transition) => ($transition['metadata']['system'] ?? false) === true)
            ->filter(fn ($transition) => in_array($current, (array) ($transition['from'] ?? []), true))
            // In the plain-language manager the source version does two things and
            // two things only: produce a plain-language version, or produce it again.
            // Everything else in that workflow is the review of the produced text and
            // belongs to the version carrying it.
            //
            // Needed because the source version's derived state is the workflow's
            // done-place (a version exists), which is also where the review ends — so
            // the review's outgoing transitions would otherwise surface here and, if
            // clicked, move the SOURCE version into "in plain language".
            ->reject(fn ($transition, $name) => $onSourceOfPlainLanguage
                && ! TransitionActions::startsLocalization($raw, $name)
                && ! TransitionActions::resubmitsPlainLanguage($transition))
            ->map(fn ($transition, $name) => [
                'transition' => [
                    'name' => $name,
                    'label' => $this->transitionLabel($name, $transition['metadata']['label'] ?? null),
                ],
                'variant' => $this->transitionVariant($name, $transition['metadata']['variant'] ?? null),
                'can_apply' => $this->canApplyTransition($transition, $entry, $workflow, $name),
                'reason' => $this->transitionReason($transition, $entry, $workflow, $name),
                // The transition's dialog, if it has one (metadata.actions — see
                // TransitionActions). The two flags below stay in the payload
                // shape the client has always consumed: which metadata spelling
                // produced them is not the Vue side's business.
                //
                // `needs_pick` is true whenever a MANUAL user assignment exists,
                // even if the resulting list is empty, so the empty state is
                // visible instead of failing silently. An automatic assignment
                // opens nothing and leaves this false — the client then applies
                // the transition directly and the server decides the assignees.
                //
                // Also true for an assignment of either mode that would hand the
                // subject back to the clicker alone, when the workflow forbids
                // that (SelfAssignmentGuard) — `pick_hint` then says why.
                'needs_pick' => TransitionActions::opensUserDialog($transition)
                    || SelfAssignmentGuard::applies($raw, $transition, $entry, $actorId),
                'pick_hint' => SelfAssignmentGuard::applies($raw, $transition, $entry, $actorId)
                    ? SelfAssignmentGuard::hint($raw)
                    : null,
                // The action's candidates (may be empty), with site_allocation.
                'pickable_assignees' => $this->pickableAssignees($raw, $name, $entry),
                // Whether the step mails anybody — the picker offers its "send
                // email" toggle only then. Same answer the send uses.
                'sends_mail' => WorkflowNotifier::stepMail($raw, $name) !== null,
                // Target-site pick: the starting transition of a translation
                // manager asks which site to localize into. The pickable list is
                // localizableSitesFor() — see there for what is subtracted.
                //
                // False for the plain-language manager: its target is a setting, so
                // there is nothing to ask. The client then applies the transition
                // directly, which is the whole behavioural difference between the
                // two managers.
                'needs_site_pick' => TransitionActions::opensSitePicker($raw, $name),
                // The version the texts would come from. Not the clicked entry: in
                // the pivot you click whatever cell you are looking at, and naming
                // that as the source would be wrong as soon as a source site is
                // configured. With a free source pick this is the preselection.
                'source_site' => $this->siteDescriptor($this->preselectedSourceFor($entry, $workflow)),
                // Free source pick — off by default. The configured source is
                // binding, which is the point of configuring it; a project that
                // wants the decision per translation switches
                // `metadata.translation_source_pick` on and gets a second select in
                // the same dialog. Only ever true where that dialog exists.
                'needs_source_pick' => TransitionActions::opensSitePicker($raw, $name)
                    && $this->picksSourcePerRun($workflow),
                'source_sites' => TransitionActions::opensSitePicker($raw, $name) && $this->picksSourcePerRun($workflow)
                    ? $this->sourceCandidatesFor($entry, $workflow)
                    : [],
                'pickable_sites' => TransitionActions::opensSitePicker($raw, $name)
                    ? $this->pickableSitesFor($entry, $workflow)
                    : [],
                // Dry run for the translation payload, carried on the action so
                // every consumer of entryActions() (detail page, fieldtype) gets
                // it without a prop of its own. `__SITE__` is filled in once the
                // user has picked, same placeholder convention as `__NAME__`.
                'translation_payload_url' => TransitionActions::opensSitePicker($raw, $name)
                    ? cp_route('workflows.api.translation.payload', [
                        'id' => $entry->id(),
                        'workflow' => $workflow,
                        'transition' => $name,
                        'target_site' => '__SITE__',
                    ])
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Sites THIS workflow may still create a version in, for this post.
     *
     * THE single authority for that question — the site picker, the transfer
     * dialog, both of their server-side re-validations and the payload preview
     * all read it here. It used to be computed four times over, which is how the
     * variants drifted apart: one of them built the existing-set from the clicked
     * entry plus its descendants instead of from the root, so opening a
     * localization that had been sent back to `source_ready` offered a site that
     * already had a version — and the endpoint accepted it, producing a second
     * entry in the same site.
     *
     * Four subtractions, in order of how surprising they are:
     *
     * - not in the collection's site list — the entry cannot live there at all;
     * - a version already exists — root-based, so it holds for every version;
     * - anything OUTSIDE what this manager is responsible for: a workflow that
     *   claims a site via `plain_language_site` is responsible for exactly that
     *   one, every other localization workflow for everything nobody claimed.
     *   This is what makes "the translation manager can no longer produce plain
     *   language" true without a second setting — and, in the other direction,
     *   keeps the plain-language manager from producing English;
     * - the workflow's own source site — translating a version into itself is
     *   meaningless, and it must stay available as the reference.
     *
     * @return array<int, string>
     */
    private function localizableSitesFor($entry, string $workflow): array
    {
        $collection = (string) $entry->collectionHandle();
        $collectionSites = optional(Collection::find($collection))->sites()?->all() ?? [];
        $resolver = app(WorkflowResolver::class);

        $existing = $resolver->groupLocales($entry);
        $claimed = $this->plainLanguageSite($workflow);
        $foreignClaims = collect($resolver->claimedSites($collection))
            ->reject(fn (string $owner) => $owner === $workflow)
            ->keys()
            ->all();
        $source = $this->sourceSiteFor($entry, $workflow);

        return collect($this->siteHandles())
            ->filter(fn (string $site) => in_array($site, $collectionSites, true))
            ->reject(fn (string $site) => in_array($site, $existing, true))
            ->filter(fn (string $site) => $claimed === null
                ? ! in_array($site, $foreignClaims, true)
                : $site === $claimed)
            ->reject(fn (string $site) => $site === $source)
            ->values()
            ->all();
    }

    /**
     * Sites a plain "transfer to another site" may create, which is
     * localizableSitesFor() minus the workflow's OWN claimed site.
     *
     * Transfer copies the origin's content and translates nothing. For a normal
     * target that is the point — somebody will translate by hand. For the
     * plain-language site it would be a way around the whole manager: a version
     * that looks done, was never rewritten, and blocks the start transition from
     * then on. So the one site this manager exists to produce is the one site
     * transfer may not produce.
     *
     * @return array<int, string>
     */
    private function transferableSitesFor($entry, string $workflow): array
    {
        $claimed = $this->plainLanguageSite($workflow);

        return collect($this->localizableSitesFor($entry, $workflow))
            ->reject(fn (string $site) => $claimed !== null && $site === $claimed)
            ->values()
            ->all();
    }

    /**
     * localizableSitesFor() in the shape the site pickers consume.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function pickableSitesFor($entry, string $workflow): array
    {
        return collect($this->localizableSitesFor($entry, $workflow))
            ->map(fn (string $site) => $this->siteDescriptor($site))
            ->values()
            ->all();
    }

    /**
     * Uniform site descriptor for the action payload: handle and human name.
     *
     * No short code here — the site pickers already render `value` next to the
     * label, so a badge would repeat the handle a second time in the same row.
     *
     * @return array{value: string, label: string}
     */
    private function siteDescriptor(?string $handle): array
    {
        return [
            'value' => (string) $handle,
            'label' => $handle ? $this->siteName($handle) : '',
        ];
    }

    /**
     * The workflow event log for a single subject-version, shaped as
     * `{id, type, at, actor, from, to, transition, note}`.
     * State handles are rendered via `stateLabel()` so the CP shows the
     * localized place labels rather than raw handles.
     *
     * `type` is what lets the History list tell a workflow step apart from a plain
     * save: a `draft_save` carries no from/to state and renders as a sub-row.
     *
     * @return array<int, array<string, mixed>>
     */
    private function entryHistory(string $subjectId, string $workflow): array
    {
        $events = app(WorkflowEventStore::class)->forSubject($subjectId, $workflow);

        // Batch-check which comment_ids referenced by comment events still
        // exist in the comments table. Deleted comments still keep the audit
        // event, but we mark them so the timeline can render "removed".
        $commentIds = $events
            ->filter(fn ($event) => in_array($event->type, WorkflowEventStore::COMMENT_TYPES, true))
            ->map(fn ($event) => data_get($event->data_snapshot, 'comment_id'))
            ->filter(fn ($id) => $id !== null)
            ->unique()
            ->values()
            ->all();

        $liveCommentIds = $commentIds === []
            ? []
            : WorkflowComment::query()
                ->whereIn('id', $commentIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

        return $events
            ->map(function ($event) use ($liveCommentIds) {
                $row = [
                    'id' => 'evt_'.$event->id,
                    'type' => $event->type,
                    'at' => optional($event->created_at)->toIso8601String(),
                    'actor' => [
                        'id' => $event->user_id,
                        'name' => $event->user_id
                            ? (User::find($event->user_id)?->name() ?? User::find($event->user_id)?->email() ?? '—')
                            : __('workflows::messages.event_actor_system'),
                    ],
                    'from' => $event->from_state ? $this->stateLabel($event->from_state) : null,
                    'to' => $event->to_state ? $this->stateLabel($event->to_state) : null,
                    'transition' => $event->transition
                        ? $this->transitionLabel($event->transition)
                        : null,
                    'note' => $event->note,
                ];

                // Every comment-layer event (created / edited / deleted /
                // resolved / reopened) carries the same `comment` block; `type`
                // says which, and the label is `history_event_<type>`.
                // `parent_id` set = the comment is a reply; `auto` = a reopen
                // caused by a reply rather than by the reopen button.
                if (in_array($event->type, WorkflowEventStore::COMMENT_TYPES, true)) {
                    $snapshot = $event->data_snapshot ?? [];
                    $commentId = data_get($snapshot, 'comment_id');
                    $row['comment'] = [
                        'id' => $commentId,
                        'parent_id' => data_get($snapshot, 'parent_id'),
                        'auto' => (bool) data_get($snapshot, 'auto', false),
                        'passage_ref' => data_get($snapshot, 'passage_ref'),
                        'passage_handle' => data_get($snapshot, 'passage_handle'),
                        'body_preview' => data_get($snapshot, 'body_preview'),
                        'deleted' => $commentId !== null && ! in_array((int) $commentId, $liveCommentIds, true),
                    ];
                }

                return $row;
            })
            ->all();
    }

    /**
     * Field-level diff of an entry-version between one workflow event and
     * either the immediately preceding event (`vs=previous`, default) or the
     * very first event on the same subject (`vs=original`). Snapshot data is
     * stored per event in `workflow_events.data_snapshot`; older events without
     * a snapshot yield an empty `changes` array with `note = 'no_diff_data'`.
     *
     * `vs=previous` means something different per event type, and deliberately so:
     * a workflow STEP compares against the previous step and skips the
     * `draft_save` rows in between, so it keeps reporting everything that happened
     * during that step. A `draft_save` compares against its immediate predecessor,
     * so it reports only what that one save changed. Together the two readings add
     * up: the steps still tell the coarse story, the saves break it down.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function historyDiff(Request $request, string $id, int $event)
    {
        abort_unless($this->canViewHistory(), 403);

        $target = WorkflowEvent::query()
            ->where('id', $event)
            ->where('subject_id', $id)
            ->first();

        abort_if($target === null, 404);

        $vs = $request->query('vs') === 'original' ? 'original' : 'previous';

        $comparison = $vs === 'previous'
            ? WorkflowEvent::query()
                ->where('subject_id', $target->subject_id)
                ->where('workflow', $target->workflow)
                ->when(
                    ! in_array($target->type, self::SAVE_TYPES, true),
                    fn ($q) => $q->whereNotIn('type', self::SAVE_TYPES),
                )
                // Comment events carry comment metadata, not an entry snapshot.
                ->whereNotIn('type', WorkflowEventStore::COMMENT_TYPES)
                ->where(function ($q) use ($target) {
                    $q->where('created_at', '<', $target->created_at)
                        ->orWhere(function ($q) use ($target) {
                            $q->where('created_at', '=', $target->created_at)
                                ->where('id', '<', $target->id);
                        });
                })
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first()
            : WorkflowEvent::query()
                ->where('subject_id', $target->subject_id)
                ->where('workflow', $target->workflow)
                ->whereNotIn('type', WorkflowEventStore::COMMENT_TYPES)
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();

        // Same event (only one event exists) → nothing to compare against.
        if ($comparison === null || $comparison->id === $target->id) {
            return response()->json([
                'vs' => $vs,
                'changes' => [],
                'note' => 'no_comparison',
            ]);
        }

        $targetSnapshot = $target->data_snapshot;
        $comparisonSnapshot = $comparison->data_snapshot;

        if (! is_array($targetSnapshot) || ! is_array($comparisonSnapshot)) {
            return response()->json([
                'vs' => $vs,
                'changes' => [],
                'note' => 'no_diff_data',
            ]);
        }

        return response()->json([
            'vs' => $vs,
            'changes' => app(SnapshotDiffer::class)->changes(
                $comparisonSnapshot,
                $targetSnapshot,
                Entry::find($target->subject_id),
                app(DefinitionLoader::class)->historyFields($target->workflow),
            ),
        ]);
    }

    /**
     * Who a pick for this transition may name, or null when the transition asks
     * nobody. Usually the MANUAL assignment's candidates (minus the clicker where
     * the workflow forbids self-assignment); for an assignment of either mode
     * that would hand the subject back to the clicker alone, the workflow's
     * self-assignment fallback instead (SelfAssignmentGuard). The picker is
     * filled from here and every pick is validated against here, so "offered"
     * and "accepted" stay one set in both cases.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $transition
     * @return \Illuminate\Support\Collection<int, \Statamic\Contracts\Auth\User>|null
     */
    private function pickPool(array $raw, array $transition, $entry, ?string $actorId): ?\Illuminate\Support\Collection
    {
        if (SelfAssignmentGuard::applies($raw, $transition, $entry, $actorId)) {
            return collect(SelfAssignmentGuard::candidateIds($raw, $actorId))
                ->map(fn (string $id) => User::find($id))
                ->filter()
                ->values();
        }

        if (! TransitionActions::opensUserDialog($transition)) {
            return null;
        }

        $ids = SelfAssignmentGuard::withoutClicker(
            $raw,
            app(AssignmentCandidates::class)->ids(TransitionActions::userAssignment($transition), $entry),
            $actorId,
        );

        return app(AssignmentCandidates::class)
            ->for(TransitionActions::userAssignment($transition), $entry)
            ->filter(fn ($user) => in_array((string) $user->id(), $ids, true))
            ->values();
    }

    /**
     * Users a `user_assignment` action wants to be picked from — its candidates
     * (see Workflow\AssignmentCandidates), with site_allocation labels (blueprint
     * field). Empty array when the transition opens no picker, which includes an
     * assignment running in automatic mode.
     *
     * The candidate set comes from ONE place, which is what makes "offered" and
     * "accepted" the same set: `applyTransition()` validates every pick against
     * the very same resolver, so a request naming one valid and one ineligible
     * user is rejected whole rather than applied in part.
     *
     * `notified` says whether picking this user sends them a mail — asked of
     * WorkflowNotifier with the same two questions the send asks (does the step
     * mail at all, does a mail reach this user with the current user as actor),
     * so the picker's hint is true exactly when the mail goes out.
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, array<string, mixed>>
     */
    private function pickableAssignees(array $raw, string $name, $entry): array
    {
        $actorId = (string) (User::current()?->id() ?? '') ?: null;
        $pool = $this->pickPool($raw, $raw['transitions'][$name] ?? [], $entry, $actorId);

        if ($pool === null) {
            return [];
        }

        $mails = WorkflowNotifier::stepMail($raw, $name) !== null;

        return $pool
            ->map(fn ($user) => [
                'id' => (string) $user->id(),
                'name' => $user->name() ?? $user->email(),
                'site_allocation' => $this->userSiteAllocation($user),
                'notified' => $mails && WorkflowNotifier::reaches($user, $actorId),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function applyTransition(Request $request, string $id, string $name)
    {
        // Validate the transition against the workflow definition, then persist.
        $entry = Entry::find($id);
        abort_if($entry === null, 404);

        $workflow = $this->workflowFor($entry, $request->query('workflow'));
        abort_if($workflow === null, 404);

        $raw = app(DefinitionLoader::class)->raw($workflow);
        $store = app(WorkflowStateStore::class);
        $current = $store->get($id, $workflow) ?? $this->defaultState($raw, $entry);

        $transition = $raw['transitions'][$name] ?? null;
        abort_if($transition === null, 404);

        // Not part of this workflow — an unpublished post in a localization manager.
        // Its own message, because the from-guard below would blame the transition
        // for what is really a missing precondition on the post.
        if ($current === null) {
            return response()->json(['message' => $this->t('workflow_not_started')], 422);
        }

        if (! in_array($current, (array) ($transition['from'] ?? []), true)) {
            return response()->json([
                'message' => $this->t('transition_not_allowed'),
            ], 422);
        }

        if (! $this->canApplyTransition($transition, $entry, $workflow, $name)) {
            return response()->json([
                'message' => $this->transitionReason($transition, $entry, $workflow, $name),
            ], 403);
        }

        $assignment = TransitionActions::userAssignment($transition);

        // Resolve the assignees BEFORE touching the state store, so a rejected
        // request leaves nothing half-applied.
        //
        // `assignee` (singular) is still read: the picker was single-select
        // before, and a client that has not reloaded still posts that shape.
        $explicit = collect((array) ($request->input('assignees') ?? $request->input('assignee')))
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $actorId = (string) (User::current()?->id() ?? '') ?: null;

        if (SelfAssignmentGuard::applies($raw, $transition, $entry, $actorId)) {
            // An assignment (either mode) that would land on the clicker alone,
            // in a workflow that forbids it: the picker asked the fallback
            // audience instead, so the picks are checked against that.
            if ($explicit === []) {
                return response()->json(['message' => $this->t('assignee_required')], 422);
            }

            $eligible = SelfAssignmentGuard::candidateIds($raw, $actorId);

            foreach ($explicit as $userId) {
                if (! in_array($userId, $eligible, true)) {
                    return response()->json(['message' => $this->t('assignee_invalid')], 422);
                }
            }
        } elseif ($assignment !== null && $assignment['mode'] === TransitionActions::MODE_AUTOMATIC) {
            // Nothing was asked, so nothing posted may be trusted: the candidates
            // ARE the answer. A client that posts assignees anyway is not an
            // error worth a 422 — it is simply answering a question that was
            // never put to it.
            $explicit = app(AssignmentCandidates::class)->ids($assignment, $entry);

            // Unreachable in practice — mechanicalBlocker() already refuses the
            // transition with `assignment_no_candidates`, and canApplyTransition()
            // above therefore returned 403. Kept because "assign nobody" is the
            // one outcome this branch must never produce silently.
            if ($explicit === []) {
                return response()->json(['message' => $this->t('assignment_no_candidates')], 422);
            }
        } elseif ($assignment !== null) {
            if ($explicit === []) {
                return response()->json(['message' => $this->t('assignee_required')], 422);
            }

            // Every pick has to qualify — accepting a request that names one
            // valid and one ineligible user would assign a set the picker could
            // never have produced. Checked against the same resolver the picker
            // was filled from, so "offered" and "accepted" cannot drift; criteria
            // that match nobody mean nobody qualifies, which is the same answer
            // the picker gives by showing an empty list.
            $eligible = SelfAssignmentGuard::withoutClicker(
                $raw, app(AssignmentCandidates::class)->ids($assignment, $entry), $actorId,
            );

            foreach ($explicit as $userId) {
                if (! in_array($userId, $eligible, true)) {
                    return response()->json(['message' => $this->t('assignee_invalid')], 422);
                }
            }
        } else {
            // Only a user assignment may set assignees explicitly; anything else
            // in the request is ignored rather than trusted.
            $explicit = [];
        }

        // The starting transition of a localization workflow does not fire against
        // the current entry — it creates a version in the target site and applies
        // the transition to THAT version instead. Rationale: for translation-style
        // flows, the source entry stays in its ready state as the reference; the
        // actual work happens on the fresh target-site copy which the transition
        // moves into e.g. `in_translation`.
        //
        // Where the target comes from differs per manager, and that is the whole
        // point of the split: the translation manager asks (a site dialog with the
        // sites still open to it), the plain-language manager already knows
        // (`metadata.plain_language_site`) and asks nothing.
        if (TransitionActions::startsLocalization($raw, $name)) {
            $fixedTarget = $this->plainLanguageSite($workflow);

            if ($fixedTarget !== null) {
                $target = $fixedTarget;
            } else {
                $target = trim((string) $request->input('target_site'));

                if ($target === '') {
                    return response()->json(['message' => $this->t('target_site_required')], 422);
                }
            }

            // Re-validated server-side even for the fixed target: with no dialog in
            // front of it, this check IS the guard against a second click creating a
            // second version in the same site. canApplyTransition() disables the
            // button for the same reason, so this should be unreachable.
            if (! in_array($target, $this->localizableSitesFor($entry, $workflow), true)) {
                return response()->json([
                    'message' => $this->t($fixedTarget !== null ? 'target_site_taken' : 'target_site_invalid', [
                        'site' => $this->siteName($target),
                    ]),
                ], 422);
            }

            // Which version the texts come from — the configured source site, or a
            // per-run pick where the workflow allows one. A configured source that
            // does not exist stops here rather than quietly translating the original
            // instead.
            [$source, $sourceError] = $this->sourceEntryFor(
                $entry,
                $workflow,
                $request->input('source_site'),
            );

            if ($sourceError !== null) {
                return response()->json(['message' => $sourceError], 422);
            }

            // Two shapes of service, two orders of operation.
            //
            // DeepL answers in the same request, so it still translates BEFORE anything
            // is created: a failed call leaves no entry behind. The plain-language
            // service only accepts a job and finishes minutes later, so there the copy
            // is created first and PollPlainLanguageCommand writes the texts into it —
            // see submitPlainLanguage(). That gives up "no half-written entry on
            // failure", which an asynchronous service cannot honour anyway; what it buys
            // is a task the translator can see in their queue right away.
            //
            // The catch is needed either way: TranslationFailed extends RuntimeException
            // and would otherwise be swallowed by the handler below and reported as
            // "transition not allowed", hiding the real cause.
            $data = $source->data()->all();
            $service = $this->translatorFor($workflow, $target);
            $isPlainLanguage = $service instanceof PlainLanguageTranslator;

            if (! $isPlainLanguage && $service->isConfigured()) {
                try {
                    $data = app(TranslationRunner::class)->run(
                        $source,
                        $target,
                        $service,
                        $this->translationExcludeFields($workflow),
                    )['data'];
                } catch (TranslationFailed $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
            }

            try {
                // Localized off the ROOT, not off the source: Statamic's origin chain
                // wants one level, and a version whose origin is another localization
                // inherits that one's edits. The texts still come from $source.
                $localization = $this->localizeInto($entry->root() ?? $entry, $target, $workflow, $raw, $data);
                $to = app(WorkflowTransitioner::class)->apply(
                    $localization,
                    $workflow,
                    $name,
                    $raw,
                    actorId: (string) (User::current()?->id() ?? '') ?: null,
                    explicitAssignees: $explicit !== [] ? $explicit : null,
                    notify: $request->boolean('notify', true),
                );
            } catch (\RuntimeException $e) {
                report($e);

                return response()->json([
                    'message' => $this->t('transition_not_allowed'),
                ], 422);
            }

            // Submitted AFTER the localization exists, because the job rows point at it.
            // A refused submission is reported but does not undo the transition: the copy
            // and the task are legitimate on their own, and somebody can translate by
            // hand or start the rewrite again.
            $pending = 0;

            // DeepL answers synchronously, so its texts are already there — landing in
            // the entry editor is "get to work" with something to work on. The
            // plain-language service does not: the copy is created empty and the rewrite
            // lands minutes later, so the entry editor would show blank/half-written
            // content. The workflow summary is what the pending-rewrite spinner and the
            // refresh reminder exist for, so that is where this manager lands instead.
            $landingUrl = $isPlainLanguage ? $this->instanceUrl($localization->id(), $workflow) : $localization->editUrl();

            if ($isPlainLanguage && $service->isConfigured()) {
                [$pending, $error] = $this->submitPlainLanguage($source, $localization, $target, $workflow);

                if ($error !== null) {
                    // A refused submission leaves NOTHING behind. The localization is
                    // removed again, so the click has no visible effect beyond the error
                    // toast — which is the only honest outcome: the copy carries the
                    // verbatim source text, no job row exists (submit never got far
                    // enough to write one), so PollPlainLanguageCommand will never look
                    // at this version again and `pendingRewrite` is false. Left standing
                    // it would render the untranslated German as if it were the finished
                    // rewrite, in a place with no transition leading out.
                    //
                    // Deliberately NOT parked in `plain_language_failed_place`. That place
                    // is for a version whose rewrite was accepted and then failed — there
                    // is something to look at, retry or discard. A refused submission is
                    // the earlier case: nothing was ever accepted, so there is nothing to
                    // park, and an entry in the queue would only invite someone to fix a
                    // version that should not exist.
                    //
                    // Safe to delete because localizeInto() hangs everything it wrote on
                    // THIS localization — its state, its assignees, its `localized` event,
                    // its revision. The source's own state is untouched by the transition
                    // (it applies to the new copy), so removing the copy restores the
                    // exact situation before the click. PurgeWorkflowDataOnEntryDeleted
                    // takes the rest.
                    //
                    // No failure event either: it would be written against a subject id
                    // that is about to stop existing, and the purge would erase it in the
                    // same request.
                    $localization->delete();

                    return response()->json([
                        'message' => $this->t('translation_plain_failed_toast', [
                            'title' => (string) ($source->get('title') ?? $source->slug()),
                        ]).' '.$error,
                        // Nothing to navigate to — the client keeps the user where they
                        // are and shows the message.
                        'data' => ['id' => null, 'current_state' => null, 'url' => null],
                    ], 422);
                }
            }

            return response()->json([
                'data' => [
                    'id' => $localization->id(),
                    'current_state' => $to,
                    // How many rewrites are still running. The client says so instead of
                    // implying the texts are already there.
                    'pending_jobs' => $pending,
                    'url' => $landingUrl,
                ],
            ]);
        }

        // "Rewrite again" creates nothing — it replaces the content of a version
        // that already exists. Like the start transition it acts on the
        // plain-language version, NOT on the entry the button sat on: pressed from
        // the source version it must not move the source's state, and it must never
        // write the rewrite into the source itself.
        if (TransitionActions::resubmitsPlainLanguage($transition)) {
            [$target, $targetError] = $this->plainLanguageVersionFor($entry, $workflow);
            [$source, $sourceError] = $this->sourceEntryFor($entry, $workflow);
            $service = app(PlainLanguageTranslator::class);

            $problem = $targetError ?? $sourceError
                ?? (! $service->isConfigured() ? $this->t('translation_plain_not_configured') : null);

            if ($problem !== null) {
                return response()->json(['message' => $problem], 422);
            }

            try {
                $to = app(WorkflowTransitioner::class)->apply(
                    $target,
                    $workflow,
                    $name,
                    $raw,
                    actorId: (string) (User::current()?->id() ?? '') ?: null,
                    explicitAssignees: $explicit !== [] ? $explicit : null,
                    notify: $request->boolean('notify', true),
                );
            } catch (\RuntimeException $e) {
                report($e);

                return response()->json([
                    'message' => $this->t('transition_not_allowed'),
                ], 422);
            }

            // Stale rows go first, otherwise an abandoned job would keep the new one
            // from ever settling — the poll command only clears a subject once none
            // of its jobs are open.
            app(PlainLanguageJobStore::class)->forget((string) $target->id(), $workflow);

            [$pending, $error] = $this->submitPlainLanguage($source, $target, (string) $target->locale(), $workflow);

            if ($error !== null) {
                return response()->json([
                    'message' => $error,
                    'data' => ['id' => $target->id(), 'current_state' => $to],
                ], 422);
            }

            return response()->json([
                'data' => [
                    'id' => $target->id(),
                    'current_state' => $to,
                    'pending_jobs' => $pending,
                    // Pressed from the source version, the interesting page is the
                    // one being rewritten — its summary, not its editor: the texts
                    // are not there yet, the History is where the job shows up.
                    'url' => $target->id() === $entry->id()
                        ? null
                        : $this->instanceUrl((string) $target->id(), $workflow),
                ],
            ]);
        }

        // The transitioner re-validates the from-guard. It agrees with the check
        // above (both go through WorkflowResolver), so this should be unreachable
        // — but a divergence must surface as a 422 the UI can show, never as an
        // uncaught 500.
        try {
            $to = app(WorkflowTransitioner::class)->apply(
                $entry,
                $workflow,
                $name,
                $raw,
                actorId: (string) (User::current()?->id() ?? '') ?: null,
                explicitAssignees: $explicit !== [] ? $explicit : null,
                notify: $request->boolean('notify', true),
            );
        } catch (\RuntimeException $e) {
            report($e);

            return response()->json([
                'message' => $this->t('transition_not_allowed'),
            ], 422);
        }

        return response()->json([
            'data' => ['id' => $id, 'current_state' => $to],
        ]);
    }

    /**
     * Restart every parked failed translation the current user is allowed to
     * restart — the dashboard's "erneut starten" button (QueueStats, card 3).
     *
     * A bulk wrapper around applyTransition(), not a second way to move a
     * subject: FailedTranslationRetry re-enters this controller once per
     * subject, so a restart from the dashboard and a click on the instance page
     * produce the same revision, the same event and the same re-submitted
     * rewrite. Anything it may not or cannot do is skipped rather than refused —
     * see the class docblock.
     *
     * No permission short-circuit of its own: the per-subject
     * canApplyTransition() IS the check, and it is the same one that decided
     * whether the button was drawn. A user with nothing to restart therefore
     * gets `{retried: 0, skipped: n}` rather than a 403 — the honest answer to
     * "restart what you can" when the answer is "nothing".
     */
    public function retryFailedTranslations()
    {
        abort_if(User::current() === null, 403);

        $result = app(FailedTranslationRetry::class)->run();

        // The card counts POSTS (the rows its preset leaves in the overview,
        // see matchingPostCount()), the retry counts subjects — the figure the
        // card re-prints has to be the card's own unit.
        $result['remaining_failed'] = $this->matchingPostCount(
            app(StatsPresets::class)->subjects(StatsPresets::FAILED_TRANSLATIONS)
        );

        return response()->json($result);
    }

    /**
     * The posts currently sitting in one (workflow, state) pair — what a
     * segment of the status row's doughnut, or its legend row, opens
     * (WorkflowStatus / WorkflowStatusWidget.vue).
     *
     * Its own endpoint rather than part of the widget's payload, because the
     * payload would then carry every post of every state of every workflow: on
     * the reference project that is 600 entry lookups on every dashboard load,
     * for a list nobody may click. Here it costs one request for the one state
     * that was clicked, and the client keeps the answer — per page — for the
     * rest of the page's life.
     *
     * Answers `{items, meta, manager_url}`: `meta` is the `meta` of a Laravel
     * API resource, which is exactly what the CP's own `Pagination` component
     * takes, so the stack's footer is the pager the CP uses everywhere else.
     *
     * Read-only and open to any signed-in CP user, like the widget it serves:
     * it says nothing the dashboard has not already put on screen as a number,
     * and every link it hands out lands on a page that does its own permission
     * check.
     *
     * Both parameters are validated against server-side sets, never trusted:
     * the workflow must be ACTIVE, and the state must be a place of it — or,
     * failing that, a state that workflow's store actually holds. The second
     * half matters because a place can be deleted in the settings editor while
     * entries are still parked in it: the widget draws those rows (it reads the
     * store, not the definition), and a legend row that 404s when clicked would
     * be a bug at exactly the moment somebody needs to find those posts.
     */
    public function stateSubjects(Request $request, DefinitionLoader $loader, WorkflowStateStore $states, SubjectLinks $links)
    {
        abort_if(User::current() === null, 403);

        $workflow = (string) $request->query('workflow', '');
        $state = (string) $request->query('state', '');

        abort_unless(in_array($workflow, $loader->all(), true), 404);

        $stored = $states->statesFor($workflow);

        abort_unless(
            array_key_exists($state, $loader->raw($workflow)['places'] ?? [])
                || in_array($state, array_map('strval', $stored), true),
            404,
        );

        $subjectIds = [];

        foreach ($stored as $subjectId => $current) {
            if ((string) $current === $state) {
                $subjectIds[] = (string) $subjectId;
            }
        }

        // Paged, not capped: this endpoint is asked about ONE workflow and one
        // state, so every post in it is reachable and there is nothing to hand
        // over to a "+N more" link. (The stat row's stacks still cap — their
        // sets span workflows; see SubjectLinks.)
        $page = $links->paginate($workflow, $subjectIds, (int) $request->query('page', 1));

        return response()->json($page + [
            // Once, at the top level, for the footer's way out of the stack:
            // the pager walks this state, the manager page is where the reader
            // goes to do something about it. null without `metadata.nav`.
            'manager_url' => $links->managerUrl($workflow),
        ]);
    }

    /**
     * Top-level field handles the machine translation leaves alone
     * (`metadata.translation_exclude_fields`).
     *
     * Read from the workflow, and resolved here rather than at each call site so
     * the translation run and the read-only payload preview cannot disagree
     * about what they are looking at.
     *
     * There is deliberately no transition-level override: the list belongs to
     * "how this workflow translates", and a second level would only invite the
     * two from drifting apart.
     *
     * @return array<int, string>
     */
    private function translationExcludeFields(string $workflow): array
    {
        return app(WorkflowResolver::class)->translationExcludeFields(
            app(DefinitionLoader::class)->raw($workflow)
        );
    }

    /**
     * The version a workflow translates FROM, for a given post.
     *
     * Returns `[sourceEntry, errorMessage]` — exactly one of the two is null.
     *
     * Which site that is comes from WorkflowResolver::sourceSiteFor(): the
     * configured `metadata.translation_source_site`, else the main site, else the
     * post's origin. Resolution always goes through `root()` + `descendants()`,
     * never through the clicked entry, so it answers the same for the origin and
     * for any of its localizations.
     *
     * A CONFIGURED source that does not exist for this post is an error, not a
     * fallback. Falling back would translate the German original while the
     * operator believes the text came from the plain-language version — a wrong
     * result that looks right. Callers surface it twice: as the reason a transition
     * button is disabled (canApplyTransition), and as a 422 if somebody posts
     * anyway. The main-site default never errors: it steps back to the origin,
     * because a post authored outside the main site has no other source.
     *
     * @param  string|null  $picked  A source chosen for this one run. Only honoured
     *                              when the workflow allows it
     *                              (`metadata.translation_source_pick`) and the post
     *                              actually has that version — an unusable pick is
     *                              ignored rather than turned into an error, because
     *                              the fixed source is still a valid answer.
     * @return array{0: mixed, 1: string|null}
     */
    private function sourceEntryFor($entry, string $workflow, ?string $picked = null): array
    {
        $root = $entry->root() ?? $entry;
        $site = $this->resolveSourcePick($entry, $workflow, $picked)
            ?? $this->sourceSiteFor($entry, $workflow);

        if ($site === (string) $root->locale()) {
            return [$root, null];
        }

        $source = $root->descendants()->get($site);

        if ($source === null) {
            return [null, $this->t('translation_source_missing', ['site' => $this->siteName($site)])];
        }

        return [$source, null];
    }

    /**
     * The site this workflow reads from for this post — configured source, else
     * main site, else origin. Thin wrapper so the controller never re-implements
     * the rule; WorkflowResolver::sourceSiteFor() is the single authority.
     */
    private function sourceSiteFor($entry, string $workflow): string
    {
        $resolver = app(WorkflowResolver::class);
        $root = $entry->root() ?? $entry;

        return $resolver->sourceSiteFor(
            app(DefinitionLoader::class)->raw($workflow),
            $resolver->groupLocales($entry),
            (string) $root->locale(),
        );
    }

    /**
     * The plain-language version of a post — the one a rewrite writes into.
     *
     * Returns `[entry, errorMessage]`, exactly one of them null. Resolved from the
     * post's root, so it answers the same whether the button was pressed on the
     * source version or on the plain-language version itself.
     *
     * @return array{0: mixed, 1: string|null}
     */
    private function plainLanguageVersionFor($entry, string $workflow): array
    {
        $claimed = $this->plainLanguageSite($workflow);

        if ($claimed === null) {
            return [null, $this->t('translation_plain_no_site')];
        }

        $root = $entry->root() ?? $entry;

        if ((string) $root->locale() === $claimed) {
            return [$root, null];
        }

        $target = $root->descendants()->get($claimed);

        return $target === null
            ? [null, $this->t('transition_resubmit_no_target', ['site' => $this->siteName($claimed)])]
            : [$target, null];
    }

    /**
     * A per-run source pick, reduced to what may actually be used: null unless the
     * workflow allows picking and the post has a SIGNED-OFF version in the picked
     * site — the same set the dialog offered.
     *
     * Returning null rather than an error keeps a stale or hand-crafted value
     * harmless — the caller falls back to the configured source, which is the
     * behaviour of every workflow that does not allow picking at all, and which the
     * readiness blocker then judges on its own merits.
     */
    private function resolveSourcePick($entry, string $workflow, ?string $picked): ?string
    {
        $picked = trim((string) $picked);

        if ($picked === '' || ! $this->picksSourcePerRun($workflow)) {
            return null;
        }

        return in_array($picked, $this->readySourceSites($entry, $workflow), true)
            ? $picked
            : null;
    }

    /**
     * Whether this workflow lets the translator pick the source per run
     * (`metadata.translation_source_pick`).
     *
     * Off by default, and deliberately so: a configured source is binding, or it
     * would not be a setting but a suggestion. The flag exists for projects that
     * want the decision at the moment of translating — the dialog then offers the
     * source next to the target language, preselected from the setting.
     */
    private function picksSourcePerRun(string $workflow): bool
    {
        $raw = app(DefinitionLoader::class)->raw($workflow);

        return ($raw['metadata']['translation_source_pick'] ?? false) === true;
    }

    /**
     * Sites that could serve as the source for this post: every site it has a
     * version in that its OWN workflow has signed off, in the CP's own site order.
     *
     * A claimed plain-language site is included — being able to translate out of
     * Einfache Sprache is the reason somebody turns the free pick on. The target
     * list excludes existing versions, so a picked source can never also be the
     * target.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function sourceCandidatesFor($entry, string $workflow): array
    {
        return collect($this->readySourceSites($entry, $workflow))
            ->map(fn (string $site) => $this->siteDescriptor($site))
            ->values()
            ->all();
    }

    /**
     * The post's versions that are finished as far as their own workflow is
     * concerned — the only ones worth translating out of.
     *
     * @return array<int, string>
     */
    private function readySourceSites($entry, string $workflow): array
    {
        $resolver = app(WorkflowResolver::class);
        $root = $entry->root() ?? $entry;
        $available = $resolver->groupLocales($entry);

        return collect($this->siteHandles())
            ->filter(fn (string $site) => in_array($site, $available, true))
            ->filter(function (string $site) use ($root, $resolver, $workflow) {
                $version = $site === (string) $root->locale()
                    ? $root
                    : $root->descendants()->get($site);

                return $version !== null && $resolver->sourceIsReady($version, $workflow);
            })
            ->values()
            ->all();
    }

    /**
     * Why the version this workflow reads from cannot be translated out of yet, or
     * null when it can.
     *
     * "Ready" means the source has reached the LAST place of the workflow that owns
     * it — published in the post manager, `plain_approved` in the plain-language
     * manager. Translating out of anything earlier writes a text that is about to
     * change: a German draft, or a rewrite still in review.
     *
     * With the free source pick on, the block is not about one version but about all
     * of them: the dialog offers only signed-off versions (sourceCandidatesFor), so
     * the button stays usable as long as ONE exists.
     */
    private function sourceReadinessBlocker($entry, string $workflow): ?string
    {
        $resolver = app(WorkflowResolver::class);

        if ($this->picksSourcePerRun($workflow)) {
            return $this->readySourceSites($entry, $workflow) === []
                ? $this->t('translation_source_none_ready')
                : null;
        }

        [$source] = $this->sourceEntryFor($entry, $workflow);

        // A missing source is a different blocker, already reported by the caller.
        if ($source === null) {
            return null;
        }

        $readiness = $resolver->sourceReadiness($source, $workflow);

        if ($readiness['ready']) {
            return null;
        }

        $site = $this->siteName((string) $source->locale());

        // No owning workflow to name a place from — readiness was the entry's own
        // published flag, and saying "state X, needed Y" would invent two places
        // that do not exist.
        if ($readiness['workflow'] === null || $readiness['required'] === null) {
            return $this->t('translation_source_unpublished', ['site' => $site]);
        }

        return $this->t('translation_source_not_ready', [
            'site' => $site,
            'state' => $this->stateLabel((string) $readiness['state']),
            'required' => $this->stateLabel((string) $readiness['required']),
        ]);
    }

    /**
     * The source the dialog preselects, and the one a run without an explicit pick
     * uses: the resolved source site, unless the post has no version there.
     *
     * The exception only matters with the free pick on. With it off, a configured
     * source that is missing must stay visible as the source — the transition is
     * blocked in that case, and the reason names that very site. Silently offering
     * a different one would explain the block with the wrong site.
     */
    private function preselectedSourceFor($entry, string $workflow): string
    {
        $resolved = $this->sourceSiteFor($entry, $workflow);

        if (! $this->picksSourcePerRun($workflow)) {
            return $resolved;
        }

        $available = app(WorkflowResolver::class)->groupLocales($entry);

        if (in_array($resolved, $available, true)) {
            return $resolved;
        }

        $root = $entry->root() ?? $entry;
        $main = (string) (Site::default()?->handle() ?? '');

        return $main !== '' && in_array($main, $available, true) ? $main : (string) $root->locale();
    }

    /**
     * Hand the source's segments to the plain-language service and record the job
     * against the fresh localization.
     *
     * Returns `[submittedJobs, errorMessage]`. A refused submission leaves no row, and
     * the caller then removes the localization again — a copy holding the verbatim
     * source, with no job anyone will ever poll, is not a legitimate intermediate state.
     *
     * @return array{0: int, 1: string|null}
     */
    private function submitPlainLanguage($source, $localization, string $target, string $workflow): array
    {
        // The body moved to PlainLanguageSubmission so PollPlainLanguageCommand can
        // submit the same way for its automatic retry — see there. Kept as a method
        // rather than inlined at the two call sites because the docblock above is what
        // those call sites are reading.
        return app(PlainLanguageSubmission::class)->submit($source, $localization, $target, $workflow);
    }

    /**
     * Which service translates INTO $targetSite.
     *
     * The workflow's `plain_language_site` goes to the project's own rewriting
     * endpoint, every other site to DeepL. Resolved here, right next to
     * translationExcludeFields(), because this is the layer that reads workflow
     * metadata: the runner stays unaware of the choice, and the dry-run preview and
     * the real run cannot end up disagreeing about who would be called.
     *
     * The two are NOT interchangeable — only DeepL is a `Translator` that answers in
     * the same call. Callers that translate have to narrow the type themselves, which
     * is exactly what applyTransition() does.
     */
    private function translatorFor(string $workflow, string $targetSite): TranslationService
    {
        return $targetSite !== '' && $targetSite === $this->plainLanguageSite($workflow)
            ? app(PlainLanguageTranslator::class)
            : app(DeeplTranslator::class);
    }

    /**
     * The site carrying the plain-language ("Einfache Sprache") version, or null
     * when the workflow names none. Rewriting into plain language is a different
     * job from translating, which is why it is a different service rather than a
     * different target language — the site's locale is German either way.
     *
     * Declaring it is also what makes a workflow the plain-language manager: the
     * target is then fixed, so its start transition asks no language question.
     * WorkflowResolver owns that reading — the resolver has to answer the same
     * question about OTHER workflows (which site does somebody else claim), and
     * two readers of one key drift.
     */
    private function plainLanguageSite(string $workflow): ?string
    {
        return app(WorkflowResolver::class)
            ->plainLanguageSite(app(DefinitionLoader::class)->raw($workflow));
    }

    /**
     * The site this workflow translates FROM (`metadata.translation_source_site`),
     * or null for "the entry's origin". See sourceEntryFor() for the resolution.
     */
    private function sourceSite(string $workflow): ?string
    {
        return app(WorkflowResolver::class)
            ->sourceSite(app(DefinitionLoader::class)->raw($workflow));
    }

    /**
     * How the payload preview names the service the run would use. Derived from
     * translatorFor(), never decided a second time.
     *
     * Only the env variable NAMES and a boolean leave the server — the endpoint URL
     * itself does not, for the same reason envStatus() reports no credentials: this
     * endpoint is open to every translator, not just super admins.
     *
     * @return array{key: string, env: array<int, string>, configured: bool}
     */
    private function serviceDescriptor(TranslationService $translator): array
    {
        $plain = $translator instanceof PlainLanguageTranslator;

        return [
            'key' => $plain ? 'plain_language' : 'deepl',
            'env' => $plain
                ? [PlainLanguageConfig::ENV_URL, PlainLanguageConfig::ENV_TOKEN]
                : [DeeplConfig::ENV_KEY],
            'configured' => $translator->isConfigured(),
        ];
    }

    /**
     * The source entry's content, prepared for a machine-translation API.
     *
     * Read-only, and no part of the normal flow — this is the diagnostic for
     * "what exactly would be sent, and where would each answer be written
     * back?", which is the first question when a translation comes out wrong.
     * It creates nothing and saves nothing.
     *
     * `data.request` is the API body verbatim (DeepL's shape today). `text[i]`
     * corresponds to `segments[i]`, which is the contract every translation API
     * of this shape keeps, so the result maps back by index; `segments[i].key`
     * is the same mapping by path, for anything that reorders.
     */
    public function translationPayload(Request $request, string $id)
    {
        $entry = Entry::find($id);
        abort_if($entry === null, 404);

        $workflow = $this->workflowFor($entry, $request->query('workflow'));
        abort_if($workflow === null, 404);

        $raw = app(DefinitionLoader::class)->raw($workflow);
        $name = (string) $request->query('transition');
        $transition = $raw['transitions'][$name] ?? null;
        abort_if($transition === null, 404);

        // Same gate as the transition this payload belongs to — being allowed to
        // look at the source content is exactly "being allowed to start the
        // translation", no more and no less.
        if (! $this->canApplyTransition($transition, $entry, $workflow, $name)) {
            return response()->json([
                'message' => $this->transitionReason($transition, $entry, $workflow, $name),
            ], 403);
        }

        // The fixed target needs no query parameter — same rule as the transition
        // itself, so the diagnostic stays callable for the plain-language manager
        // without inventing a site handle by hand.
        $target = $this->plainLanguageSite($workflow) ?? trim((string) $request->query('target_site'));

        if (! in_array($target, $this->localizableSitesFor($entry, $workflow), true)) {
            return response()->json(['message' => $this->t('target_site_invalid')], 422);
        }

        // The version that would actually be read — not the entry this was called
        // on. A preview of the wrong source is worse than no preview. `source_site`
        // mirrors the transition's own parameter so the diagnostic can preview a
        // per-run pick too.
        [$source, $sourceError] = $this->sourceEntryFor(
            $entry,
            $workflow,
            $request->query('source_site'),
        );

        if ($sourceError !== null) {
            return response()->json(['message' => $sourceError], 422);
        }

        $codes = app(LanguageCodes::class);

        // Resolved BEFORE the extraction, because the two paths do not send the same
        // thing: DeepL gets one segment per text (TranslatableText), the rewriting path
        // one section per text leaf of the workflow's content fields
        // (PlainLanguageLeafDocument) — and a preview of the other path's body would
        // show texts nobody sends.
        $translator = $this->translatorFor($workflow, $target);
        $isPlainLanguage = $translator instanceof PlainLanguageTranslator;

        // Straight from the runner and from the adapter's own constant, so the preview
        // cannot describe a different body than the one that would actually be sent.
        $context = app(TranslationRunner::class)->contextOf($source);

        if ($isPlainLanguage) {
            $document = app(PlainLanguageLeafDocument::class);
            $plan = $document->plan($source, $raw);
            $texts = $document->texts($plan, $source);
            $extras = app(PlainLanguageTranslator::class)->bodyExtras($document->requestExtras($plan));
            $extracted = [
                'segments' => [],
                // What goes out and where its answer is written: one row per section.
                'leaves' => array_map(fn (array $leaf) => [
                    'n' => $leaf['n'],
                    'path' => $leaf['path'],
                    'type' => $leaf['type'],
                    'field' => $leaf['field'],
                    'context' => $leaf['context'],
                    'modules' => $leaf['modules'],
                    'intro' => $leaf['intro'],
                    'sets' => $leaf['sets'],
                ], $plan['leaves']),
                'content_fields' => $plan['fields'],
                'skipped' => array_map(fn (array $row) => ['key' => $row['path'], 'reason' => $row['reason']], $plan['skipped']),
                'warnings' => [],
            ];
        } else {
            $extracted = app(TranslatableText::class)->extract($source, $this->translationExcludeFields($workflow));
            $texts = array_column($extracted['segments'], 'text');
            $extras = [];
        }

        return response()->json([
            'data' => [
                'entry' => [
                    'id' => $source->id(),
                    'collection' => $source->collectionHandle(),
                    'title' => (string) $source->get('title'),
                    'site' => $source->locale(),
                    // The entity the POST belongs to — not to be confused with a
                    // segment's `modules`, which is the per-block restriction. Sent
                    // on the wire as `modules` too, same rename.
                    'modules' => $context['modules'] ?? [],
                ],
                'source' => $codes->describe((string) $source->locale(), asTarget: false),
                'target' => $codes->describe($target, asTarget: true),
                // Which service this target would go to. The first question when a
                // translation looks wrong is "who did this", and for the
                // plain-language site that is not DeepL.
                'service' => $this->serviceDescriptor($translator),
                // Segments carry their block's module scope both structurally
                // (`modules`) and on the wire (a `data-modules` wrapper around
                // `text`), so the preview shows exactly what the service receives.
                'segments' => $extracted['segments'],
                // Plain-language only: the text leaves, one per sent section.
                'leaves' => $extracted['leaves'] ?? null,
                'content_fields' => $extracted['content_fields'] ?? null,
                // Copy-pasteable API body, merged in the same order the adapter uses:
                // document-level context first, then the shared fields, then the
                // plain-language endpoint's own fixed ones. Every segment is HTML
                // (plain fields entity-escaped), which is why one `tag_handling`
                // covers all of them and no second request is needed.
                'request' => array_merge($isPlainLanguage ? $context : [], [
                    'text' => $texts,
                    'source_lang' => $codes->source((string) $source->locale()),
                    'target_lang' => $codes->target($target),
                    'tag_handling' => 'html',
                ], $extras),
                'stats' => [
                    'segments' => count($texts),
                    'characters' => array_sum(array_map('mb_strlen', $texts)),
                ],
                'skipped' => $this->collapseSkipped($extracted['skipped']),
                'warnings' => $extracted['warnings'],
            ],
        ]);
    }

    /**
     * Collapse the skipped-field list to one row per field, with indices
     * replaced by `*`. A replicator with 30 rows otherwise reports the same
     * four non-text fields 30 times over and buries everything else.
     *
     * @param  array<int, array{key: string, reason: string}>  $skipped
     * @return array<int, array{field: string, type: string, count: int}>
     */
    private function collapseSkipped(array $skipped): array
    {
        return collect($skipped)
            ->groupBy(fn (array $row) => preg_replace('/\.\d+(?=\.|$)/', '.*', $row['key']).'|'.$row['reason'])
            ->map(fn ($rows, $group) => [
                'field' => Str::before($group, '|'),
                'type' => Str::after($group, '|'),
                'count' => $rows->count(),
            ])
            ->sortBy('field')
            ->values()
            ->all();
    }

    /**
     * Create a workflow-aware localization of $entry into $siteHandle: makes the
     * localization (unpublished), sets it to the workflow's initial place, assigns
     * it, and records a `localized` history event.
     *
     * The one primitive for it, used by both paths a localization comes into
     * being: `applyTransition()` for a `site_modal` translation-start, and
     * `transfer()` in a loop for the bulk "move to other sites" action.
     *
     * `$data` is where the two differ, and only there. A translation-start passes
     * the payload the target should hold — the finished translation, or a copy of
     * the source when no translation service is configured. It has to be written
     * explicitly: leave it out and Statamic keeps the localization empty and
     * merges origin values at read time, so editing one field would detach only
     * that field and the translator could not overwrite field by field.
     *
     * `null` means "write nothing", which is what a plain `transfer()` wants —
     * there the inherit-from-origin behaviour is the point.
     *
     * @param  array<string, mixed>|null  $data
     */
    private function localizeInto($entry, string $siteHandle, string $workflow, array $raw, ?array $data = null)
    {
        $store = app(WorkflowStateStore::class);
        $assigneeStore = app(WorkflowAssigneeStore::class);
        $events = app(WorkflowEventStore::class);
        $initial = app(WorkflowResolver::class)->initialPlace($raw);

        // A localization is how a subject enters a localization workflow, so it
        // has to arrive assigned — exactly like a new post does (see
        // initialAssignees()). Without this the translation manager's personal
        // lists stayed empty and only privileged users could move a task on.
        $assignees = $this->initialAssignees($raw, (string) (User::current()?->id() ?? ''));

        return EventLogSuppressor::while(function () use ($entry, $siteHandle, $data, $store, $assigneeStore, $events, $workflow, $initial, $assignees) {
            $localization = $entry->makeLocalization($siteHandle);

            if ($data !== null) {
                $localization->data($data);
            }

            $localization->published(false);
            $localization->save();

            $store->set($localization->id(), $workflow, $initial);
            $assigneeStore->set($localization->id(), $workflow, $assignees);
            $events->log($localization->id(), $workflow, 'localized', [
                'to' => $initial,
                'note' => __('workflows::messages.event_note_localized', ['site' => $siteHandle]),
                'snapshot' => app(EntrySnapshot::class)->take($localization),
            ]);
            $this->createStatamicRevision($localization, __('workflows::messages.event_note_localized', ['site' => $siteHandle]));

            return $localization;
        });
    }

    /**
     * Write a Statamic revision for this entry with the given message. Fires
     * only from workflow-triggered contexts (transition, setState, create,
     * localize) so revision-creation stays owned by the addon. Failures are
     * swallowed — the revision is a history convenience, not a hard
     * dependency of the workflow move.
     */
    private function createStatamicRevision($entry, string $message): void
    {
        if (! is_object($entry) || ! method_exists($entry, 'createRevision')) {
            return;
        }

        try {
            $entry->createRevision([
                'user' => (string) (User::current()?->id() ?? ''),
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            // No Pro license / driver failure / disk write error — non-fatal.
        }
    }


    /**
     * The assignee set a brand-new workflow subject starts with: every member of
     * `metadata.default_assignee_group`, plus — with `metadata.assign_creator:
     * true` — whoever brought it into the workflow. Omit both keys to start with
     * nobody assigned.
     *
     * The creator matters because they are often NOT in that group (a super admin,
     * say), and `canApplyTransition()` gates on being an assignee: without this
     * they could not move their own subject out of the initial place, and it would
     * never show up in their queue.
     *
     * Snapshot at creation time — later group changes do not rewrite existing
     * assignments, and this set now stands until a transition's `user_assignment`
     * action replaces it. Nothing else does: not a plain "Save", not the state
     * override, and no longer the place a transition leads into.
     *
     * Shared by both ways a subject enters a workflow: `storePost()` for a new
     * entry, `transfer()` for a localization. They used to differ — transfer wrote
     * no assignees at all, which left the translation manager's personal lists
     * empty and its transitions blocked for everyone but privileged users.
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, string>
     */
    private function initialAssignees(array $raw, string $userId): array
    {
        $defaultGroup = $raw['metadata']['default_assignee_group'] ?? null;

        return collect($defaultGroup ? UserGroup::find($defaultGroup)?->users() ?? [] : [])
            ->map(fn ($user) => (string) $user->id())
            ->when(
                ($raw['metadata']['assign_creator'] ?? false) === true && $userId !== '',
                fn ($ids) => $ids->push($userId)
            )
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Set the assignees of a single entry-version within its workflow. Replaces
     * the assignment with exactly the posted user ids (per-site responsibility).
     */
    public function setAssignees(Request $request, string $id)
    {
        $entry = Entry::find($id);
        abort_if($entry === null, 404);

        abort_unless($this->canAccessEntrySite($entry), 403);

        $workflow = $this->workflowFor($entry, $request->query('workflow'));
        abort_if($workflow === null, 404);

        // The picker is hidden for anyone who may not assign, but hiding a control is
        // not a permission — this is the check that actually holds.
        abort_unless($this->canAssign(app(DefinitionLoader::class)->raw($workflow)), 403);

        $ids = collect($request->input('assignees', []))
            ->map(fn ($value) => (string) $value)
            ->filter(fn ($value) => User::find($value) !== null)
            ->unique()
            ->values()
            ->all();

        app(WorkflowAssigneeStore::class)->set($id, $workflow, $ids);

        return response()->json([
            'data' => [
                'id' => $id,
                'assignee_ids' => $this->assigneeIds($entry, $workflow),
                'assignees' => $this->assigneeNames($entry, $workflow),
            ],
        ]);
    }

    /**
     * Manually set the workflow state of an entry, bypassing the normal
     * transition flow. Restricted to super admins and members of the `admins`
     * group — an escape hatch for corrections. The state must be a valid
     * place of the entry's workflow.
     */
    public function setState(Request $request, string $id)
    {
        abort_unless($this->isPrivilegedUser(), 403);

        $entry = Entry::find($id);
        abort_if($entry === null, 404);

        // Even a manual override may not write into a site the user cannot access.
        abort_unless($this->canAccessEntrySite($entry), 403);

        $workflow = $this->workflowFor($entry, $request->query('workflow'));
        abort_if($workflow === null, 404);

        $raw = app(DefinitionLoader::class)->raw($workflow);
        $state = (string) $request->input('state');

        if (! array_key_exists($state, $raw['places'] ?? [])) {
            return response()->json(['message' => $this->t('invalid_state')], 422);
        }

        $previous = app(WorkflowStateStore::class)->get($id, $workflow);

        // Suppressed for the same reason as WorkflowTransitioner::apply(): the
        // publish-flag sync and the revision are this endpoint's own saves, and the
        // `set_state` event below already carries the snapshot.
        EventLogSuppressor::while(function () use ($entry, $id, $workflow, $raw, $state, $previous) {
            app(WorkflowStateStore::class)->set($id, $workflow, $state);
            app(PublishedFlag::class)->sync($entry, $raw, $state);
            // Assignment is deliberately NOT touched. This endpoint used to hand
            // the subject to the destination place's group — the last automatic
            // assignment left after transitions stopped doing it. Whoever puts a
            // post into a place by hand is standing in the CP with the assignee
            // drawer next to them; a reassignment they did not ask for is a
            // surprise rather than a service. Nothing assigns implicitly now.

            $entry = $entry->fresh() ?? $entry;
            app(WorkflowEventStore::class)->log($id, $workflow, 'set_state', [
                'from' => $previous,
                'to' => $state,
                'note' => __('workflows::messages.event_note_set_state'),
                'snapshot' => app(EntrySnapshot::class)->take($entry),
            ]);
            $this->createStatamicRevision($entry, __('workflows::messages.event_note_set_state'));
        });

        return response()->json([
            'data' => ['id' => $id, 'current_state' => $state],
        ]);
    }

    /**
     * Whether the current user may transfer (localize) an entry into another
     * site within a workflow. Super admins and privileged users always may;
     * otherwise they need to belong to the workflow's `default_assignee_group`.
     * A dedicated `transfer_group` used to be a separate metadata key but was
     * merged into `default_assignee_group` — one group per workflow is enough.
     */
    private function canTransfer(string $workflow): bool
    {
        $user = User::current();

        if ($user === null) {
            return false;
        }

        if ($this->isPrivilegedUser($user)) {
            return true;
        }

        $group = app(DefinitionLoader::class)->raw($workflow)['metadata']['default_assignee_group'] ?? null;

        return $group !== null && (bool) $user->isInGroup($group);
    }

    /**
     * Transfer (localize) an entry into one or more other sites — the
     * translation manager's "move to another site" action. Group-gated; each
     * new localization starts in the workflow's initial place.
     */
    public function transfer(Request $request, string $id)
    {
        $entry = Entry::find($id);
        abort_if($entry === null, 404);

        abort_unless($this->canAccessEntrySite($entry), 403);

        // No hardcoded fallback name here: with more than one localization workflow
        // per collection, guessing 'translation' would validate the request against
        // the wrong definition. workflowFor() resolves it from the entry's own site.
        $workflow = $this->workflowFor($entry, $request->query('workflow'));
        abort_if($workflow === null, 404);

        $raw = app(DefinitionLoader::class)->raw($workflow);

        if (! $this->canTransfer($workflow)) {
            $group = $raw['metadata']['default_assignee_group'] ?? '';
            $title = $group ? (UserGroup::find($group)?->title() ?? $group) : '';

            return response()->json(['message' => $this->t('requires_group', ['group' => $title])], 403);
        }

        // Valid target sites — same authority as the dialog that offered them, so a
        // hand-crafted POST cannot reach a site the UI hides (a foreign manager's
        // claimed site, or this manager's own one, which must go through its
        // rewrite instead).
        $offered = $this->transferableSitesFor($entry, $workflow);

        $targets = collect($request->input('sites', []))
            ->map(fn ($value) => (string) $value)
            ->filter(fn (string $site) => in_array($site, $offered, true))
            ->unique()
            ->values();

        if ($targets->isEmpty()) {
            return response()->json(['message' => $this->t('no_target_sites')], 422);
        }

        $first = null;

        foreach ($targets as $site) {
            // No `$data` keeps this endpoint's long-standing behaviour — the
            // localization inherits the origin's values at read time instead of
            // being seeded with a detached copy. Everything else (initial place,
            // assignees, the `localized` history row, the revision) is the shared
            // primitive's job.
            $localization = $this->localizeInto($entry, $site, $workflow, $raw);

            $first ??= $localization;
        }

        return response()->json([
            'data' => [
                'id' => $first->id(),
                'url' => $this->instanceUrl($first->id(), $workflow),
            ],
        ]);
    }

    /**
     * Create a new post as a draft from the workflow index. The modal asks for
     * the title and, where the workflow owns several, the collection; the rest
     * is decided here: the slug from the title, and one site. It starts in the
     * initial workflow place — and, when the workflow couples one, moves on
     * through `metadata.create_transition` in the same request (see below).
     *
     * The slug is derived from the title (uniqueSlug()). There was a period
     * without a title prompt, when the entry got a placeholder title and
     * deliberately NO slug — Statamic's slug field only auto-generates while it
     * is empty, and a slug from "Ohne Titel" would have stuck. With a real title
     * that reason is gone, and a real slug is what a real title deserves.
     *
     * THE COUPLED TRANSITION runs after the `created` row, through the same
     * gates as a button on the detail page (transitionReason(), then
     * WorkflowTransitioner::apply()). It is the "workflow CTA": the modal's
     * confirm button carries the transition's label, so what the click promises
     * is what happens. A `manual` user_assignment is answered in the modal: it
     * lists the candidates (createCta()) and posts the picks as `assignees`,
     * validated here against the same candidate set BEFORE anything is created —
     * a rejected pick must not leave a half-made post behind. What the request
     * will not do is fail the creation when the transition itself is refused
     * (no candidates, a gate): the post exists in the initial place either way,
     * and the response carries the reason for the toast. A transition that
     * cannot run is a fact about the workflow's setup, not about this post.
     *
     * One site, resolved from the collection: its first site (in CP order) that
     * the creator may actually enter. Creating into a site they cannot access
     * would leave them with a post they could never open in the editor.
     * Additional sites are the localization workflow's job, see `transfer()`.
     *
     * Assignees are auto-set from the workflow metadata (snapshot at creation
     * time): every member of `metadata.default_assignee_group`, plus the
     * creator when `metadata.assign_creator: true`. Omit both keys to skip
     * auto-assignment.
     *
     * Target collection: the caller names the workflow, and the entry may land in
     * any collection that workflow OWNS (first-wins per WorkflowResolver). A
     * secondary workflow must not create into a collection it merely shares, so
     * those are filtered out. `collection` is optional and defaults to the first
     * owned one — which is what a single-collection workflow always sends.
     */
    public function storePost(Request $request)
    {
        $workflow = (string) $request->input('workflow', '');
        abort_unless($this->isEntryWorkflow($workflow), 404);

        $raw = app(DefinitionLoader::class)->raw($workflow);

        // Creator groups are per workflow, so the definition has to be loaded
        // before the permission can be judged.
        abort_unless($this->canCreatePost($raw), 403);

        // The UI hides the button, but the endpoint is the actual gate.
        abort_if(app(WorkflowResolver::class)->isLocalizationWorkflow($raw), 403);

        $allowed = collect($raw['collections'] ?? [])
            ->filter(fn (string $handle) => $this->workflowForCollection($handle) === $workflow)
            ->values()
            ->all();
        abort_if($allowed === [], 404);

        $collection = (string) $request->input('collection', '') ?: $allowed[0];

        if (! in_array($collection, $allowed, true)) {
            return response()->json(['message' => $this->t('post_collection_invalid')], 422);
        }

        $title = trim((string) $request->input('title', ''));

        if ($title === '') {
            return response()->json(['message' => $this->t('post_title_required')], 422);
        }

        $siteHandles = $this->siteHandles();
        $collectionObject = Collection::find($collection);
        $currentUser = User::current();

        // Sites of the collection, in CP order — a collection without an explicit
        // list belongs to every site the CP knows. Prefer one the user may enter.
        $collectionSites = collect($collectionObject?->sites()?->all() ?? []);
        $candidates = collect($siteHandles)
            ->filter(fn (string $handle) => $collectionSites->isEmpty() || $collectionSites->contains($handle))
            ->values();

        $target = $candidates->first(
            fn (string $handle) => ($site = Site::get($handle)) !== null
                && $currentUser !== null
                && $currentUser->can('view', $site)
        ) ?? $candidates->first() ?? $siteHandles[0];

        // The coupled transition's manual picks, checked BEFORE the post exists —
        // see the docblock. Resolved against a stub of the target collection, the
        // same stub createCta() filled the modal's list from.
        $cta = $this->createTransition($raw);
        $explicitAssignees = null;

        if ($cta !== null) {
            $ctaTransition = $raw['transitions'][$cta['name']];
            $assignment = TransitionActions::userAssignment($ctaTransition);
            $stub = $this->stubEntryFor($collection);
            $creatorId = (string) ($currentUser?->id() ?? '') ?: null;
            $guarded = SelfAssignmentGuard::applies($raw, $ctaTransition, $stub, $creatorId);

            if ($assignment !== null && ($guarded || $assignment['mode'] !== TransitionActions::MODE_AUTOMATIC)) {
                $explicitAssignees = collect((array) $request->input('assignees', []))
                    ->map(fn ($id) => trim((string) $id))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($explicitAssignees === []) {
                    return response()->json(['message' => $this->t('assignee_required')], 422);
                }

                // The same pool createCta() offered: the manual candidates, or
                // the self-assignment fallback (SelfAssignmentGuard).
                $eligible = $guarded
                    ? SelfAssignmentGuard::candidateIds($raw, $creatorId)
                    : SelfAssignmentGuard::withoutClicker(
                        $raw, app(AssignmentCandidates::class)->ids($assignment, $stub), $creatorId,
                    );

                foreach ($explicitAssignees as $pickedId) {
                    if (! in_array($pickedId, $eligible, true)) {
                        return response()->json(['message' => $this->t('assignee_invalid')], 422);
                    }
                }
            }
        }

        // Collections that build their titles from a `title_format` are left
        // alone: a hardcoded value in the data would shadow the computed one. The
        // typed title still names the slug there — better than the id.
        $data = $collectionObject?->autoGeneratesTitles()
            ? []
            : ['title' => $title];

        $slug = $this->uniqueSlug($collection, $title);
        $dated = (bool) $collectionObject?->dated();

        $userId = (string) ($currentUser?->id() ?? '');
        $assignees = $this->initialAssignees($raw, $userId);

        $store = app(WorkflowStateStore::class);
        $assigneeStore = app(WorkflowAssigneeStore::class);
        $initial = app(WorkflowResolver::class)->initialPlace($raw);

        // `created` is the History row for a new post; the save and the initial
        // revision that produce it are not separate draft saves.
        $entry = EventLogSuppressor::while(function () use ($collection, $target, $slug, $data, $dated, $store, $assigneeStore, $workflow, $initial, $assignees) {
            $entry = Entry::make()
                ->collection($collection)
                ->locale($target)
                ->slug($slug)
                ->published(false)
                ->data($data);

            if ($dated) {
                $entry->date(now());
            }

            $entry->save();

            // New draft → explicit initial place + group members (if any) as assignees.
            $store->set($entry->id(), $workflow, $initial);
            $assigneeStore->set($entry->id(), $workflow, $assignees);
            app(WorkflowEventStore::class)->log($entry->id(), $workflow, 'created', [
                'to' => $initial,
                'note' => __('workflows::messages.event_note_created'),
                'snapshot' => app(EntrySnapshot::class)->take($entry),
            ]);
            $this->createStatamicRevision($entry, __('workflows::messages.event_note_created'));

            return $entry;
        });

        // The coupled transition — see the docblock. `$transitionResult` is what
        // the client toasts about when the button promised more than "create".
        $transitionResult = ['name' => null, 'label' => null, 'applied' => false, 'reason' => null];

        if ($cta !== null) {
            $transition = $raw['transitions'][$cta['name']];
            $reason = null;

            if (($reason = $this->transitionReason($transition, $entry, $workflow, $cta['name'])) === null) {
                try {
                    // Manual picks were validated above; an automatic assignment
                    // leaves this null and apply() resolves the candidates itself.
                    app(WorkflowTransitioner::class)->apply(
                        $entry,
                        $workflow,
                        $cta['name'],
                        $raw,
                        actorId: $userId !== '' ? $userId : null,
                        explicitAssignees: $explicitAssignees,
                        notify: $request->boolean('notify', true),
                    );
                    $transitionResult['applied'] = true;
                } catch (\RuntimeException $e) {
                    report($e);
                    $reason = $this->t('transition_not_allowed');
                }
            }

            $transitionResult['name'] = $cta['name'];
            $transitionResult['label'] = $cta['label'];
            $transitionResult['reason'] = $reason;
        }

        // No coupled transition ran, so nothing announced the arrival: send the
        // initial place's own `mail` action, the same one a transition INTO it
        // would fire. When the transition did run, WorkflowTransitioner already
        // sent its mail or this one — never both.
        // The modal's mail toggle covers this mail as well: whoever switched it
        // off asked for a silent creation, not just a silent transition.
        if (! $transitionResult['applied'] && $request->boolean('notify', true)) {
            app(WorkflowNotifier::class)->notifyPlaceArrival(
                $entry, $workflow, $raw, $initial, $userId !== '' ? $userId : null,
            );
        }

        // Nobody is sent into the editor from here: the modal creates the post
        // and, through the CTA, hands it on — the creator's part is done, and the
        // queue is where they see where it went. (Earlier builds opened the
        // editor for privileged users and assignees; the house decided against
        // it once the CTA meant the post usually belongs to somebody else by now.)
        return response()->json([
            'data' => [
                'id' => $entry->id(),
                'url' => $this->instanceUrl($entry->id()),
                'edit_url' => $entry->editUrl(),
                'transition' => $transitionResult,
            ],
        ]);
    }

    /**
     * A collection+slug that does not yet exist, suffixing -2, -3, … on collision.
     * `beitrag` when the title slugs to nothing (all punctuation, say).
     */
    private function uniqueSlug(string $collection, string $title): string
    {
        $base = Str::slug($title) ?: 'beitrag';
        $slug = $base;
        $i = 1;

        while (Entry::query()->where('collection', $collection)->where('slug', $slug)->count() > 0) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    /**
     * Server-side data source for the CP Listing (search, filters, sort,
     * pagination). Returns the contract the Listing component expects:
     * `{ data: [...rows], meta: { columns, activeFilterBadges, ...pagination } }`.
     */
    public function listing(Request $request)
    {
        // The dashboard widget's cross-workflow table: the pivot rows of every
        // scoped workflow at once (see overviewRows()). `workflows` narrows the
        // scope — to the widget's configured list ("All"), or to the one workflow
        // its buttons select. Null on every other listing.
        $scope = $request->boolean('all_workflows')
            ? $this->overviewWorkflows($request->query('workflows'))
            : null;

        $workflow = $scope === null ? $request->query('workflow') : null;
        $isEntry = $this->isEntryWorkflow($workflow);
        $mine = $request->boolean('mine');
        $createdByMe = $request->boolean('created_by_me');

        // "Assigned to me" and "My posts" are both flat per-localization lists,
        // not the site-pivot.
        $flat = $isEntry && ($mine || $createdByMe);
        $pivot = $scope !== null || ($isEntry && ! $flat);

        // Per-collection tabs on the manager page scope the pivot to one
        // collection. The flat personal lists ignore it and stay merged.
        $collection = $flat ? null : $request->query('collection');

        $rows = collect(match (true) {
            $scope !== null => $this->overviewRows($scope),
            $flat => $this->entryRowsFlat($workflow),
            default => $this->rowsFor($workflow, $collection),
        });

        // A stat card of the dashboard (QueueStats) narrowed the overview to the
        // posts behind its figure: `preset=awaiting_approval` and so on, see
        // StatsPresets. Overview only; an unknown name is ignored rather than
        // answered with an empty table.
        $preset = $request->query('preset');

        if ($scope !== null && StatsPresets::isPreset($preset)) {
            $rows = $this->applyStatsPreset($rows, $preset, $scope);
        }

        // The overview's badges are read off these same rows below; building the
        // row set of every workflow a second time would double the request.
        $unfiltered = $rows->all();

        $columns = $scope !== null ? $this->overviewColumns($scope) : $this->columns($workflow, $flat);

        // Search across the available text fields (pivot rows only carry a title,
        // plus the workflow's name in the overview).
        if ($search = trim((string) $request->query('search'))) {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(function (array $row) use ($needle) {
                $haystack = mb_strtolower(trim(implode(' ', array_filter([
                    $row['title'] ?? '', $row['site'] ?? '', $row['state_label'] ?? '',
                    $row['assignees'] ?? '', $row['collection'] ?? '', $row['workflow_label'] ?? '',
                ]))));

                return str_contains($haystack, $needle);
            });
        }

        // Filters.
        $active = $this->decodeFilters($request->query('filters'));

        if ($pivot) {
            // Pivot: keep posts that have the state in any site.
            if ($state = $active['state']['state'] ?? null) {
                $rows = $rows->filter(fn (array $row) => in_array($state, $row['_states'] ?? [], true));
            }
            if (($active['stale']['stale'] ?? null) !== null) {
                $rows = $rows->filter(fn (array $row) => ($row['_stale'] ?? false) === true);
            }
            if ($assignee = $active['assignee']['assignee'] ?? null) {
                $rows = $rows->filter(fn (array $row) => in_array((string) $assignee, $row['_assignee_ids'] ?? [], true));
            }
            if ($days = (int) ($active['waiting']['waiting'] ?? 0)) {
                $cutoff = now()->subDays($days)->getTimestamp();
                $rows = $rows->filter(fn (array $row) => ($row['_waiting_since'] ?? null) !== null && $row['_waiting_since'] <= $cutoff);
            }
        } else {
            if ($site = $active['site']['site'] ?? null) {
                $rows = $rows->where('site', $site);
            }
            if ($state = $active['state']['state'] ?? null) {
                $rows = $rows->where('state', $state);
            }
            // By name on the flat lists' own filter; by id where the entry
            // workflow's flat lists share the pivot's filter definitions.
            if ($assignee = $active['assignee']['assignee'] ?? null) {
                $rows = $rows->filter(fn (array $row) => in_array($assignee, $row['_assignees'] ?? [], true)
                    || in_array((string) $assignee, $row['_assignee_ids'] ?? [], true));
            }
        }

        if ($author = $active['author']['author'] ?? null) {
            $rows = $rows->filter(fn (array $row) => in_array((string) $author, $row['_author_ids'] ?? [], true));
        }

        // "My Queue": only rows assigned to the current user.
        if ($mine) {
            $uid = User::current()?->id();
            $rows = $rows->filter(fn (array $row) => in_array($uid, $row['_assignee_ids'] ?? [], true));
        }

        // "My posts": only rows whose `author` field names the current user,
        // regardless of who created them or who is currently assigned.
        if ($createdByMe) {
            $uid = (string) (User::current()?->id() ?? '');
            $rows = $rows->filter(fn (array $row) => in_array($uid, $row['_author_ids'] ?? [], true));
        }

        // Sort.
        $sort = $request->query('sort');
        $order = $request->query('order', 'asc');
        if ($sort) {
            // A column may name a private key to sort on instead of its own value.
            // The date column needs it: its cell holds a localized date, and sorting
            // that string would order by whatever character the locale puts first.
            $numeric = collect($columns)
                ->firstWhere('field', $sort)['sort_field'] ?? null;

            $rows = $numeric !== null
                ? $rows->sortBy(fn (array $row) => (int) ($row[$numeric] ?? 0), SORT_NUMERIC)
                : $rows->sortBy(fn (array $row) => mb_strtolower((string) ($row[$sort] ?? '')), SORT_NATURAL);

            if ($order === 'desc') {
                $rows = $rows->reverse();
            }
        }

        $rows = $rows->values();

        // Paginate.
        $perPage = max(1, (int) $request->query('perPage', 15));
        $page = max(1, (int) $request->query('page', 1));
        $total = $rows->count();
        $items = $rows->forPage($page, $perPage)->values()
            ->map(fn (array $row) => collect($row)
                ->except(['_assignees', '_assignee_ids', '_states', '_author_ids', '_content_updated_at', '_stale', '_subject_ids'])
                ->all())
            ->all();

        return response()->json([
            'data' => $items,
            'meta' => [
                'columns' => $this->applyColumnVisibility($columns, $request->query('columns')),
                'activeFilterBadges' => ($scope !== null
                    ? $this->filterBadges($active, $unfiltered, null, $scope)
                    : $this->filterBadges($active, $this->rowsFor($workflow, $collection), $workflow)) ?: (object) [],
                'sortColumn' => $sort,
                'sortDirection' => $order,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total ? (($page - 1) * $perPage) + 1 : null,
                'to' => $total ? min($page * $perPage, $total) : null,
            ],
        ]);
    }

    /**
     * Build the active-filter badge strings keyed by filter handle.
     *
     * @param  array<int, string>|null  $scope  See filterOptions().
     * @return array<string, string>
     */
    private function filterBadges(array $active, array $rows, ?string $workflow = null, ?array $scope = null): array
    {
        $options = $this->filterOptions($rows, $workflow, $scope);
        $filters = [
            'site' => (new SiteFilter)->context(['options' => $options['site']]),
            'state' => (new StateFilter)->context(['options' => $options['state']]),
            'assignee' => (new AssigneeFilter)->context(['options' => $options['assignee']]),
            'author' => (new AuthorFilter)->context(['options' => $options['author']]),
            'waiting' => (new WaitingFilter)->context(['options' => $options['waiting']]),
            'stale' => (new StaleFilter)->context(['options' => $options['stale']]),
        ];

        $badges = [];
        foreach ($filters as $handle => $filter) {
            if (! empty($active[$handle])) {
                $badges[$handle] = $filter->badge($active[$handle]);
            }
        }

        return $badges;
    }

    /**
     * The workflow governing a subject id.
     */
    private function resolveWorkflow(string $id, ?string $requested = null): ?string
    {
        if ($entry = Entry::find($id)) {
            return $this->workflowFor($entry, $requested);
        }

        return null;
    }

    /**
     * Create a comment — a new thread (root) or, with `parent_id`, a reply.
     *
     * A reply hangs off a ROOT of the same subject and workflow (one level deep;
     * a reply id, or a comment elsewhere, is a 422) and inherits the root's
     * passage and its write-time hashes, so the whole thread describes one
     * passage at one moment. Replying to a RESOLVED thread is allowed and
     * reopens it (logged as `comment_reopened`, snapshot `auto: true`): an
     * answer is a sign the discussion is not over, and a reply nobody sees
     * because its thread stays collapsed would be worse.
     *
     * `mentions`: user ids, each one of mentionableUsers() or the request is a
     * 422. The mentioned users (never the author) get a `comment_mention` mail.
     *
     * Response: `{data: comment}`, plus `root` (the root's fresh resource) for
     * a reply, so the client sees a reopen without a reload.
     */
    public function storeComment(Request $request, string $id)
    {
        $workflow = $this->resolveWorkflow($id, $request->query('workflow'));
        abort_if($workflow === null, 404);

        // Entry-backed subjects only — a fixture-less workflow has no site to check.
        // markCommentsRead() stays ungated on purpose: it writes nothing but the
        // current user's own "seen" marker and has no effect on anyone else.
        $entry = Entry::find($id);
        if ($entry) {
            abort_unless($this->canAccessEntrySite($entry), 403);
        }

        $data = $request->validate([
            'body' => 'required|string',
            'passage_ref' => 'nullable|string',
            'passage_handle' => 'nullable|string',
            'parent_id' => 'nullable|integer',
            'mentions' => 'nullable|array',
            'mentions.*' => 'string',
        ]);

        $mentions = $this->validatedMentions($data['mentions'] ?? [], $entry);
        $store = app(WorkflowCommentStore::class);

        $parent = null;
        if (isset($data['parent_id'])) {
            $parent = $store->findRoot((int) $data['parent_id'], $id, $workflow);

            if ($parent === null) {
                throw ValidationException::withMessages(['parent_id' => $this->t('comment_parent_invalid')]);
            }
        }

        if ($parent !== null) {
            // A reply is about its root's passage, as the root saw it.
            $passageRef = $parent->passage_ref;
            $passageHandle = $parent->passage_handle;
            $contentHash = $parent->content_hash;
            $containerHash = $parent->container_hash;
        } else {
            $passageRef = $data['passage_ref'] ?? null;
            $passageHandle = $data['passage_handle'] ?? null;

            // Snapshot the passage's current content as a hash so later renders
            // can detect that the referenced content has since been edited. If
            // the entry or passage cannot be resolved right now, the hashes stay
            // null and the comment is treated the same as older, pre-migration
            // comments (never flagged as stale / container-changed).
            // Two hashes are captured:
            //   • content_hash  — only the specific passage's payload
            //   • container_hash — the whole surrounding container (siblings,
            //                      order, and their contents). Flips on "anything
            //                      else in this area changed after the comment".
            $contentHash = null;
            $containerHash = null;
            if ($entry && $passageRef !== null) {
                $preview = app(EntryContentPreview::class);
                $contentHash = $preview->contentHashForPassage($entry, $passageRef, $workflow);
                $containerHash = $preview->containerHashForPassage($entry, $passageRef, $workflow);
            }
        }

        $comment = $store->add([
            'subject_id' => $id,
            'workflow' => $workflow,
            'parent_id' => $parent?->id,
            'passage_ref' => $passageRef,
            'passage_handle' => $passageHandle,
            'body' => $data['body'],
            'user_id' => User::current()?->id(),
            'author_name' => $this->currentUserName(),
            'content_hash' => $contentHash,
            'container_hash' => $containerHash,
            'mentions' => $mentions === [] ? null : $mentions,
        ]);

        // Timeline audit trail: log a comment_created event so the workflow
        // history includes the comment alongside transitions and draft saves.
        // The event stays even if the comment is deleted later — the "who
        // said something, when, in which state" trace is the point.
        // Metadata goes into `data_snapshot` (already a nullable JSON field)
        // instead of adding a new column just for this event type.
        $this->logCommentEvent($comment, 'comment_created');

        if ($parent !== null && $parent->isResolved()) {
            $this->setThreadResolved($parent, false);
            $this->logCommentEvent($parent, 'comment_reopened', ['auto' => true, 'reply_id' => (int) $comment->id]);
        }

        app(CommentMentionNotifier::class)->queue($comment, $mentions, User::current()?->id());

        $response = ['data' => $this->commentResource($comment)];

        if ($parent !== null) {
            $response['root'] = $this->commentResource($parent->fresh());
        }

        return response()->json($response);
    }

    /**
     * Edit a comment's body and mentions — the AUTHOR only (moderators may
     * delete, not put words in someone's mouth). Sets `edited_at`. `mentions`
     * omitted keeps the stored ones; given, it replaces them, and only the users
     * it ADDS are mailed — nobody gets the same mention twice.
     */
    public function updateComment(Request $request, string $id, string $comment)
    {
        [$model, $entry] = $this->findCommentForRequest($request, $id, $comment);

        abort_unless($this->isCommentAuthor($model), 403);

        $data = $request->validate([
            'body' => 'required|string',
            'mentions' => 'nullable|array',
            'mentions.*' => 'string',
        ]);

        $before = array_map('strval', (array) ($model->mentions ?? []));
        $mentions = $request->has('mentions')
            ? $this->validatedMentions($data['mentions'] ?? [], $entry)
            : $before;
        $previousBody = (string) $model->body;

        $model->body = $data['body'];
        $model->mentions = $mentions === [] ? null : $mentions;
        $model->edited_at = now();
        $model->save();

        $this->logCommentEvent($model, 'comment_edited', [
            'previous_body_preview' => Str::limit($previousBody, 120),
        ]);

        $added = array_values(array_diff($mentions, $before));
        app(CommentMentionNotifier::class)->queue($model, $added, User::current()?->id());

        return response()->json(['data' => $this->commentResource($model)]);
    }

    /**
     * Resolve a thread. ROOT comments only (a reply id is a 422); allowed for
     * canResolveThread(). Idempotent — resolving a resolved thread keeps the
     * original who/when and logs nothing. Returns the root.
     */
    public function resolveComment(Request $request, string $id, string $comment)
    {
        return $this->toggleThread($request, $id, $comment, true);
    }

    /** Reopen a resolved thread — the mirror of resolveComment(). */
    public function reopenComment(Request $request, string $id, string $comment)
    {
        return $this->toggleThread($request, $id, $comment, false);
    }

    private function toggleThread(Request $request, string $id, string $comment, bool $resolve)
    {
        [$model] = $this->findCommentForRequest($request, $id, $comment);

        if (! $model->isRoot()) {
            throw ValidationException::withMessages(['comment' => $this->t('comment_resolve_root_only')]);
        }

        $uid = User::current()?->id();
        abort_unless($this->canResolveThread($model, $uid === null ? null : (string) $uid), 403);

        if ($model->isResolved() !== $resolve) {
            $this->setThreadResolved($model, $resolve);
            $this->logCommentEvent($model, $resolve ? 'comment_resolved' : 'comment_reopened');
        }

        return response()->json(['data' => $this->commentResource($model)]);
    }

    private function setThreadResolved(WorkflowComment $root, bool $resolved): void
    {
        $user = User::current();

        $root->resolved_at = $resolved ? now() : null;
        $root->resolved_by = $resolved && $user ? (string) $user->id() : null;
        $root->resolved_by_name = $resolved ? $this->currentUserName() : null;
        $root->save();
    }

    /**
     * Delete a comment — the author or a moderator. A ROOT takes its whole
     * thread with it (replies without their root have nowhere to be shown).
     * Logged as `comment_deleted`. Response: `{deleted: [ids]}`, root first.
     */
    public function deleteComment(Request $request, string $id, string $comment)
    {
        [$model] = $this->findCommentForRequest($request, $id, $comment);

        // The author, or a comment moderator — see canModerateComments().
        abort_unless($this->isCommentAuthor($model) || $this->canModerateComments(), 403);

        $snapshotSource = clone $model;
        $deleted = app(WorkflowCommentStore::class)->deleteThread($model);

        $this->logCommentEvent($snapshotSource, 'comment_deleted', [
            'deleted_ids' => $deleted,
        ]);

        return response()->json(['deleted' => $deleted]);
    }

    /**
     * The comment named in the URL, within the subject AND the workflow in
     * scope (`?workflow=`), or a 404. Returns the entry alongside (null for a
     * subject that is no entry).
     *
     * @return array{0: WorkflowComment, 1: mixed}
     */
    private function findCommentForRequest(Request $request, string $id, string $comment): array
    {
        $workflow = $this->resolveWorkflow($id, $request->query('workflow'));

        $model = WorkflowComment::query()
            ->where('id', $comment)
            ->where('subject_id', $id)
            ->when($workflow !== null, fn ($q) => $q->where('workflow', $workflow))
            ->first();

        abort_if($model === null, 404);

        $entry = Entry::find($id);

        if ($entry) {
            abort_unless($this->canAccessEntrySite($entry), 403);
        }

        return [$model, $entry];
    }

    private function isCommentAuthor(WorkflowComment $comment): bool
    {
        $uid = User::current()?->id();

        return $comment->user_id !== null && $uid !== null && (string) $comment->user_id === (string) $uid;
    }

    /**
     * `mentions` from a request, made safe: unique string ids, every one of them
     * in mentionableUsers() — otherwise a 422 naming the field. A subject that
     * is no entry has nobody to mention.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function validatedMentions(array $ids, $entry): array
    {
        $ids = collect($ids)->map(fn ($id) => (string) $id)->filter(fn ($id) => $id !== '')->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $allowed = $entry ? array_column($this->mentionableUsers($entry), 'id') : [];
        $unknown = array_values(array_diff($ids, $allowed));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['mentions' => $this->t('comment_mentions_invalid')]);
        }

        return $ids;
    }

    /**
     * One history row for the comment layer. `to` is the state the subject was
     * in at the time (the history shows it); the snapshot carries the comment's
     * metadata — see WorkflowEventStore::COMMENT_TYPES for why readers of entry
     * snapshots skip these rows.
     *
     * @param  array<string, mixed>  $extra
     */
    private function logCommentEvent(WorkflowComment $comment, string $type, array $extra = []): void
    {
        $entry = Entry::find($comment->subject_id);
        $state = $entry ? app(WorkflowResolver::class)->stateFor($entry, $comment->workflow) : null;
        $preview = Str::limit((string) $comment->body, 120);

        app(WorkflowEventStore::class)->log((string) $comment->subject_id, (string) $comment->workflow, $type, [
            'to' => $state,
            'note' => $preview,
            'snapshot' => array_merge([
                'comment_id' => (int) $comment->id,
                'parent_id' => $comment->parent_id === null ? null : (int) $comment->parent_id,
                'passage_ref' => $comment->passage_ref,
                'passage_handle' => $comment->passage_handle,
                'body_preview' => $preview,
            ], $extra),
        ]);
    }

    public function markCommentsRead(Request $request, string $id)
    {
        $workflow = $this->resolveWorkflow($id, $request->query('workflow'));
        abort_if($workflow === null, 404);

        $data = $request->validate(['passage_ref' => 'required|string']);

        app(WorkflowCommentStore::class)->markRead(
            User::current()?->id(),
            $id,
            $workflow,
            $data['passage_ref'],
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Start watching a subject: it appears on the current user's "Watched"
     * dashboard card. Gated on site access like every other per-entry action —
     * watching is reading, and someone who may not view the site has nothing to
     * watch there.
     */
    public function watch(Request $request, string $id)
    {
        $entry = Entry::find($id);
        abort_if($entry === null, 404);

        $workflow = $this->resolveWorkflow($id, $request->query('workflow'));
        abort_if($workflow === null, 404);
        abort_unless($this->canAccessEntrySite($entry), 403);

        $user = User::current();
        abort_if($user === null, 403);

        app(WorkflowWatchStore::class)->watch((string) $user->id(), $id, $workflow);

        return response()->json(['watching' => true]);
    }

    /**
     * Stop watching. Ungated like markCommentsRead(): it only removes the
     * caller's own bookmark. And it must work for an entry that is gone — the
     * widget's remove button is how a stale row gets cleared — so the workflow
     * falls back to the one named in the URL.
     */
    public function unwatch(Request $request, string $id)
    {
        $workflow = $this->resolveWorkflow($id, $request->query('workflow')) ?? $request->query('workflow');
        abort_unless(is_string($workflow) && $workflow !== '', 404);

        $user = User::current();
        abort_if($user === null, 403);

        app(WorkflowWatchStore::class)->unwatch((string) $user->id(), $id, $workflow);

        return response()->json(['watching' => false]);
    }

    public function definitions(DefinitionLoader $loader)
    {
        $data = collect($loader->all())
            ->map(function (string $name) use ($loader) {
                $raw = $loader->raw($name);

                return [
                    'name' => $raw['name'] ?? $name,
                    'label' => $raw['metadata']['label'] ?? $name,
                    'collections' => $raw['collections'] ?? [],
                    'initial_place' => $raw['initial_place'] ?? null,
                    'diagram' => "/docs/workflows/{$name}.svg",
                    'places' => collect($raw['places'] ?? [])
                        ->map(fn ($place, $handle) => [
                            'name' => $handle,
                            // Resolved: the raw `label` may be a per-language map.
                            'label' => $this->stateLabel((string) $handle),
                            // A place carries no group any more — the gate lives on
                            // the transition, and that is the row below.
                        ])->values()->all(),
                    'transitions' => collect($raw['transitions'] ?? [])
                        ->map(fn ($transition, $handle) => [
                            'name' => $handle,
                            'from' => (array) $transition['from'],
                            'to' => $transition['to'],
                            'required_group' => $transition['metadata']['required_group'] ?? null,
                            'label' => $transition['metadata']['label'] ?? $handle,
                        ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        return response()->json(['data' => $data]);
    }
}
