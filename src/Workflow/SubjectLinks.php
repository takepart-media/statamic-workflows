<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Subject ids → a list of openable posts, plus what the cap left out.
 *
 * The one answer to "the reader can see a number, now let them see the posts
 * behind it". Two callers ask it today and they must not answer it differently:
 * the stat row's stacks (QueueStats) and the status row's per-state stacks
 * (WorkflowController::stateSubjects, lazily, on click). It lived inside
 * QueueStats first and moved out when the second caller appeared — a second
 * copy would have meant two caps, two ideas of what a site chip is, and two
 * places to remember that a state row can outlive its entry.
 *
 * Deleted entries are skipped WITHOUT using up a slot: a state row can outlive
 * its entry, and a list that shows 47 links because three subjects are orphans
 * would look like a bug. They fall out of the remainder too — `items` plus every
 * `more` count can therefore come to slightly less than the figure the reader
 * clicked, which is the honest direction to be wrong in: nothing is named that
 * cannot be opened.
 *
 * Two shapes, because the two callers ask different questions:
 *
 * - forSubjects() — a capped list plus "+N more per workflow". For sets that
 *   span several workflows, where a pager has no honest per-workflow total.
 *   In source order: sorting by title would mean resolving every subject just
 *   to decide which fifty to show.
 * - paginate() — one workflow, real pages, sorted by title. See there for why
 *   that one does resolve everything first.
 */
class SubjectLinks
{
    /**
     * How many posts get named by title before the list stops and says "+N
     * more".
     *
     * Fifty, because the list opens in the CP's slide-over `Stack` and that
     * panel scrolls on its own (`StackContent` is `flex-1 overflow-auto`). It
     * was ten while the list hung in a popover beside the card, where the
     * container's own height was the limit — the wrong reason for a cap, and the
     * reason the popover was replaced: a card counting thirty posts awaiting
     * approval has to be usable.
     *
     * A cap remains, at a height nobody scrolls past on purpose: beyond it the
     * `more` entries hand over to the manager page, which is the thing that
     * does long lists properly (filters, sorting, pagination).
     */
    public const ITEM_CAP = 50;

    /**
     * Page size for paginate(). Twenty-five, because that list sits in the CP's
     * slide-over `Stack` under a real pager: it should fill the panel without
     * making the reader scroll past the controls to reach them.
     */
    public const PER_PAGE = 25;

    public function __construct(
        private DefinitionLoader $loader,
        private InstanceUrl $urls,
    ) {}

    /**
     * @param  array<string, array<int, string>>  $perWorkflow  workflow name => subject ids
     * @return array{items: array<int, array{title: string, url: string, site_code: string|null}>, more: array<int, array{count: int, label: string, url: string|null}>}
     */
    public function forSubjects(array $perWorkflow): array
    {
        $multiSite = Site::hasMultiple();

        $items = [];
        $more = [];

        foreach ($perWorkflow as $workflow => $subjectIds) {
            $workflow = (string) $workflow;
            // How far into THIS workflow's list the cap let us get. The
            // remainder counts from here, so a workflow the cap never reached
            // reports all of its subjects as hidden.
            $consumed = 0;

            foreach ($subjectIds as $subjectId) {
                if (count($items) >= self::ITEM_CAP) {
                    break;
                }

                $consumed++;
                $entry = Entry::find($subjectId);

                if ($entry === null) {
                    continue;
                }

                $items[] = [
                    'title' => (string) ($entry->get('title') ?? $entry->slug()),
                    'url' => $this->urls->for((string) $subjectId, $workflow),
                    // Uppercased locale, and only where the project has more
                    // than one site — the same convention (and the same reason)
                    // as PersonalWorkItems::row().
                    'site_code' => $multiSite ? strtoupper((string) $entry->locale()) : null,
                ];
            }

            $hidden = count($subjectIds) - $consumed;

            if ($hidden > 0) {
                $more[] = [
                    'count' => $hidden,
                    'label' => $this->workflowLabel($workflow),
                    'url' => $this->managerUrl($workflow),
                ];
            }
        }

        return ['items' => $items, 'more' => $more];
    }

    /**
     * The same posts, for ONE workflow, as a page of a real pager instead of a
     * capped list plus a remainder.
     *
     * A second method rather than a switch inside forSubjects(): the stat row's
     * stacks are still cap-plus-remainder (their sets are unions across several
     * workflows, and a pager over a union has no honest total per workflow),
     * and that behaviour must not move underneath them. What the two share is
     * everything below the shape — the orphan rule, the site chip, the URL.
     *
     * ORDER is by title, natural and case-insensitive, the same ordering
     * PersonalWorkItems gives its rows. Pagination needs a total order that
     * survives between two requests, and the store's own row order is not one:
     * it is whatever the database returned without an ORDER BY. Sorting by the
     * subject id would be stable too and cost nothing, but it would scatter the
     * titles across pages in an order no reader can predict.
     *
     * That ordering is why every entry is resolved BEFORE the page is cut,
     * rather than only the 25 the page shows: a title cannot be sorted on
     * without reading it. The same pass buys the other half of an honest pager —
     * orphaned state rows (the entry is gone, the row is not) drop out of
     * `total`, so pages stay full and the last page is where it says it is. The
     * cost is one pass over the Stache, which holds the entries in memory: 277
     * ids resolve in ~60ms on the reference project, once per click, and the
     * set is bounded by "the posts in one state of one workflow". If that ever
     * stops being a few hundred, this is the line to revisit.
     *
     * @param  array<int, string>  $subjectIds
     * @return array{items: array<int, array{title: string, url: string, site_code: string|null}>, meta: array{current_page: int, from: int|null, last_page: int, per_page: int, to: int|null, total: int}}
     */
    public function paginate(string $workflow, array $subjectIds, int $page, int $perPage = self::PER_PAGE): array
    {
        $multiSite = Site::hasMultiple();
        $rows = [];

        foreach ($subjectIds as $subjectId) {
            $subjectId = (string) $subjectId;
            $entry = Entry::find($subjectId);

            if ($entry === null) {
                continue;
            }

            $rows[] = [
                'id' => $subjectId,
                'title' => (string) ($entry->get('title') ?? $entry->slug()),
                // Uppercased locale, and only where the project has more than
                // one site — the same convention (and the same reason) as
                // PersonalWorkItems::row().
                'site_code' => $multiSite ? strtoupper((string) $entry->locale()) : null,
            ];
        }

        // The subject id breaks ties, and it has to: two posts can carry the
        // same title (the same headline in two sites, most often), and equal
        // rows left in the store's own order could swap places between the
        // request for page 1 and the request for page 2 — which is how a pager
        // shows one post twice and never shows another.
        usort($rows, fn (array $a, array $b) => strnatcasecmp($a['title'], $b['title'])
            ?: strcmp($a['id'], $b['id']));

        // A page below the first is a broken request, not an empty result. Above
        // the last it is Laravel's own answer that stands: `current_page` is
        // what was asked for and `items` is empty — the pager then shows the
        // reader they have walked off the end instead of silently moving them.
        $page = max(1, $page);

        $paginator = new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            count($rows),
            $perPage,
            $page,
        );

        return [
            'items' => array_map(
                fn (array $row) => [
                    'title' => $row['title'],
                    // Built for the page only: the other few hundred rows were
                    // resolved to be counted and sorted, not to be linked.
                    'url' => $this->urls->for($row['id'], $workflow),
                    'site_code' => $row['site_code'],
                ],
                array_values($paginator->items()),
            ),
            // The `meta` of a Laravel API resource, which is what the CP's
            // `Pagination` component takes (it reads current_page, last_page,
            // total, from and to). `from`/`to` are null on an empty page, and
            // the component handles that — it renders no totals below one.
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * A workflow's manager page, or null where it has none (`metadata.nav`
     * absent). Callers render that case unlinked rather than as a dead link.
     */
    public function managerUrl(string $workflow): ?string
    {
        $slug = $this->urls->navSlug($workflow);

        return $slug === null ? null : cp_route('workflows.workflow', ['slug' => $slug]);
    }

    /**
     * A workflow's human name: its nav label, else the handle in headline case.
     * The same fallback chain as PersonalWorkItems and the widgets, so a
     * workflow is named identically wherever it is named.
     */
    public function workflowLabel(string $workflow): string
    {
        $raw = $this->loader->raw($workflow);

        return DefinitionLoader::navLabel($raw['metadata']['nav'] ?? [])
            ?: (string) Str::headline($workflow);
    }
}
