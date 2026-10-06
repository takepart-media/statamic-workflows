<?php

namespace TakepartMedia\Workflows\Workflow;

/**
 * The URL of a workflow instance summary — in one place, because three callers
 * need it and the shape is not obvious.
 *
 * The canonical form nests the instance under its manager's nav slug
 * (`workflows/<slug>/instances/<id>`), so an instance URL reads as part of its
 * workflow. This used to drive CP nav highlighting too; with the per-workflow
 * nav items gone it is purely URL structure. The id-only route stays for
 * bookmarks and older links and redirects to this form.
 *
 * A workflow without `metadata.nav` has no manager page to nest under and falls
 * back to the id-only route.
 */
class InstanceUrl
{
    /** @var array<string, string|null> workflow name => nav slug */
    private array $slugs = [];

    public function __construct(private DefinitionLoader $loader) {}

    public function for(string $id, ?string $workflow = null): string
    {
        $slug = $workflow === null ? null : $this->navSlug($workflow);

        return $slug === null
            ? cp_route('workflows.show', array_filter(['id' => $id, 'workflow' => $workflow]))
            : cp_route('workflows.workflow.show', ['slug' => $slug, 'id' => $id]);
    }

    /**
     * Memoised: a listing asks once per row, and `raw()` re-reads the YAML every
     * time — without this a 400-row queue would parse the same file 400 times.
     */
    public function navSlug(string $workflow): ?string
    {
        if (! array_key_exists($workflow, $this->slugs)) {
            try {
                $this->slugs[$workflow] = $this->loader->raw($workflow)['metadata']['nav']['slug'] ?? null;
            } catch (\InvalidArgumentException $e) {
                // Unknown workflow — no manager page, so the id-only route is the
                // right answer rather than a 500 while building a link.
                $this->slugs[$workflow] = null;
            }
        }

        return $this->slugs[$workflow];
    }
}
