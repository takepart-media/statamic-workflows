<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Subject ids → a page of openable posts, plus the manager-page and label
 * lookups the dashboard widgets share.
 *
 * The one answer to "the reader can see a number, now let them see the posts
 * behind it" for the status row's per-state stacks
 * (WorkflowController::stateSubjects, lazily, on click). The stat row
 * (QueueStats) asked it too, for a capped list plus "+N more per workflow",
 * until its cards started linking to the dashboard's own lists instead.
 *
 * Deleted entries are skipped: a state row can outlive its entry, and nothing
 * is named that cannot be opened.
 */
class SubjectLinks
{
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
     * The posts behind a set of subjects of ONE workflow, as a page of a real
     * pager.
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
