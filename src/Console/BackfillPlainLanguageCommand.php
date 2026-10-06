<?php

namespace TakepartMedia\Workflows\Console;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use TakepartMedia\Workflows\Support\EventLogSuppressor;
use TakepartMedia\Workflows\Translation\PlainLanguageDocument;
use TakepartMedia\Workflows\Translation\PlainLanguageJobStore;
use TakepartMedia\Workflows\Translation\PlainLanguageSeo;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowEvent;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;

/**
 * Brings plain-language versions that were rewritten BEFORE a pipeline rule existed
 * up to what the live path produces today. Two rules so far, one pass:
 *
 *   1. `seo.title` / `seo.description` are derived from the version's own content.
 *      Without this, a plain-language page shows plain language to a reader and the
 *      original's German sentences to a search engine, forever;
 *   2. the fields the workflow lists in `metadata.plain_language_clear_fields` are
 *      emptied — the block of scientific sources this project carries under every
 *      post, which the full copy brought into the plain version and which no rewrite
 *      ever removes on its own.
 *
 * NAMED `backfill-plain-language`, not `-plain-seo`. It was the latter while SEO was
 * all it did; the second rule made that name a lie, and the command had not shipped,
 * so it was renamed rather than left to mislead. Expect a third rule eventually: this
 * is the place where "what the live path would have done" gets applied to what came
 * before it, and one pass over the entries is cheaper than one command per rule.
 *
 * Deliberately the same rules as the live path, applied through the same classes:
 * PlainLanguageSeo::plan() decides per SEO subkey, PlainLanguageDocument decides what
 * an emptied field contains, and this command only reports and saves. A backfill that
 * did it "almost like" the live path would leave two versions of the truth in the
 * project, and the difference would only show up months later on one entry.
 *
 * ONE rule is this command's own, and it applies to both halves: a value somebody has
 * edited since the rewrite is left alone. The live path overwrites on a resubmit (see
 * apply() — it replaces the content those values describe in the same breath), but a
 * backfill has no such mandate: it runs over content that has been live for months,
 * and an editor who tuned a meta description, or rewrote the source list in plain
 * language by hand, did that on purpose. Such a key is reported as `edited` and
 * skipped; `--force` is the explicit way to overrule that. "Edited" means the version
 * has an own value that differs from the copy its origin still holds — a version that
 * never had its own value at all inherits the origin's verbatim, which is a copy and
 * not an edit.
 *
 * Gated three times before any of that, so the command cannot touch content that was
 * never rewritten: a version with a rewrite still running (its content is about to be
 * replaced), a version that never carried a completed rewrite at all (an empty copy, a
 * test fixture), and — for the SEO half only — a version with SEO switched off are each
 * reported and skipped.
 *
 * Written without a workflow event, like BackfillContentTimestampCommand: this is a
 * mechanical correction of values the pipeline should have produced, not something
 * that happened to the post. A row per entry in the history would say "changed" on a
 * day when nobody did anything, and the diff view would show it as editorial work.
 *
 * Idempotent: a second run reports every entry as `unchanged`. For the SEO half see
 * PlainLanguageSeo::reasonFor(), whose check order is what makes that true; for the
 * clearing half the stored value simply already IS the empty one.
 */
class BackfillPlainLanguageCommand extends Command
{
    /** The event that proves a rewrite finished — the same type the poll command logs. */
    private const COMPLETED_EVENT = 'plain_language_completed';

    /** What happened to one version, for the counters in handle(). */
    private const WRITTEN = 'written';

    private const UNCHANGED = 'unchanged';

    private const SKIPPED = 'skipped';

    protected $signature = 'workflows:backfill-plain-language
        {--workflow= : Only this plain-language workflow (defaults to all of them)}
        {--force : Overwrite values that were edited after the rewrite}
        {--dry-run : Report what would be written, change nothing}';

    protected $description = 'Derive seo.title/seo.description of already rewritten plain-language versions and empty their plain_language_clear_fields.';

    public function handle(
        DefinitionLoader $loader,
        WorkflowResolver $resolver,
        PlainLanguageJobStore $jobs,
        PlainLanguageSeo $seo,
        PlainLanguageDocument $document,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $keepEdited = ! (bool) $this->option('force');
        $workflows = $this->workflows($loader, $resolver, $this->option('workflow'));

        if ($workflows === []) {
            $this->warn('No plain-language workflow claims a site — nothing to backfill.');

            return self::SUCCESS;
        }

        $written = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($workflows as $workflow => $target) {
            $this->line("Workflow [{$workflow}] → site [{$target['site']}]: ".implode(', ', $target['collections']));

            $entries = Entry::query()
                ->whereIn('collection', $target['collections'])
                ->where('site', $target['site'])
                ->get();

            if ($entries->isEmpty()) {
                $this->warn("  no versions in [{$target['site']}] — nothing to backfill.");

                continue;
            }

            foreach ($entries as $entry) {
                $result = $this->backfill($entry, $workflow, $jobs, $seo, $document, $target['clear'], $keepEdited, $dryRun);

                $written += $result === self::WRITTEN ? 1 : 0;
                $unchanged += $result === self::UNCHANGED ? 1 : 0;
                $skipped += $result === self::SKIPPED ? 1 : 0;
            }
        }

        $verb = $dryRun ? 'Would update' : 'Updated';
        $this->info("{$verb} {$written} version(s); {$unchanged} unchanged, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /**
     * One version: report the decision per SEO subkey and per clearable field, then
     * store whatever came out of it.
     *
     * The description is derived from the version's OWN `content_sets`, never through
     * `value()`. A localization without its own content inherits the origin's, and
     * deriving from that would put the German introduction into the plain version's
     * meta description — the exact bug this command exists to remove. The same reading
     * applies to the cleared fields, for the mirror-image reason: what matters there is
     * whether the version has an own value at all.
     *
     * Intro nodes are deliberately NOT passed to plan(). The live path prefers the
     * intro it has just written back (PlainLanguageSeo), but every version this command
     * walks predates that — their intro field still holds the German copy, and there is
     * nothing there worth preferring.
     *
     * @param  array<int, string>  $clear
     */
    private function backfill(
        $entry,
        string $workflow,
        PlainLanguageJobStore $jobs,
        PlainLanguageSeo $seo,
        PlainLanguageDocument $document,
        array $clear,
        bool $keepEdited,
        bool $dryRun,
    ): string {
        $label = $this->label($entry);
        $root = $this->rootOf($entry);

        if ($root === null) {
            // A root entry sitting in the plain-language site itself: written there by
            // hand, not produced by a rewrite. There is no origin to compare against
            // and no rewritten document to derive from.
            $this->line("  {$label}: not a localization — skipped.");

            return self::SKIPPED;
        }

        if ($jobs->hasOpen($entry->id(), $workflow)) {
            // The rewrite that will replace this content is still running, and its
            // write-back derives both texts itself. Deriving them now would describe a
            // document that is minutes away from being replaced.
            $this->line("  {$label}: rewrite still running — skipped.");

            return self::SKIPPED;
        }

        if (! $this->wasRewritten($entry, $root, $workflow)) {
            $this->line("  {$label}: no completed rewrite — skipped.");

            return self::SKIPPED;
        }

        $own = $entry->data()->get(PlainLanguageSeo::field());
        $origin = $root->data()->get(PlainLanguageSeo::field());

        $plan = $seo->plan(
            (string) ($entry->value(PlainLanguageDocument::titleField()) ?? ''),
            (array) $entry->data()->get(PlainLanguageDocument::contentField()),
            $own,
            $origin,
            $keepEdited,
        );

        // SEO off is about the SEO FIELD, not about the version: the sources under a
        // page in plain language are just as wrong there as anywhere else. So the
        // clearing half runs on, and only the two derived texts are dropped.
        $seoOff = $plan === null;
        $merged = $seoOff ? null : $seo->withPlan($plan, $own, $origin);
        $clearPlan = $this->clearPlan($entry, $root, $document, $clear, $keepEdited);

        $this->line("  {$label}:");

        if ($seoOff) {
            $this->line(sprintf('    %-13s %s', 'seo:', 'switched off'));
        }

        foreach (array_merge($plan ?? [], $clearPlan) as $key => $decision) {
            $this->line($decision['reason'] === PlainLanguageSeo::WRITE
                ? sprintf('    %-13s %s → %s', $key.':', $this->show($decision['from']), $this->show($decision['to']))
                : sprintf('    %-13s %s (%s)', $key.':', $decision['reason'], $this->show($decision['from'])));
        }

        $writes = collect($clearPlan)
            ->filter(fn (array $decision) => $decision['reason'] === PlainLanguageSeo::WRITE)
            ->map(fn (array $decision) => $decision['to'])
            ->all();

        if ($merged === null && $writes === []) {
            return self::UNCHANGED;
        }

        if (! $dryRun) {
            EventLogSuppressor::while(function () use ($entry, $merged, $writes) {
                if ($merged !== null) {
                    $entry->set(PlainLanguageSeo::field(), $merged);
                }

                foreach ($writes as $handle => $value) {
                    // set(), never remove(): an absent key is not an empty field but an
                    // inherited one — see PlainLanguageDocument::clear() for the whole
                    // argument. This is the line that would otherwise quietly restore
                    // the German sources it was told to remove.
                    $entry->set($handle, $value);
                }

                $entry->save();
            });
        }

        return self::WRITTEN;
    }

    /**
     * What would happen to each clearable field, in the shape the SEO plan uses so one
     * loop can report both.
     *
     * The four outcomes, in the order they have to be asked:
     *
     *   1. the own value already IS the empty one → `unchanged`. First, so a second run
     *      reports nothing and writes nothing;
     *   2. NO own value, and nothing inherited worth shadowing (the origin has none
     *      either) → `unchanged`. Writing an empty into a field that is empty on both
     *      sides would touch every entry for nothing;
     *   3. no own value, or an own value byte-identical to the origin's → `write`. Both
     *      are the copy the localization was created with: the first inherits it live,
     *      the second carries a snapshot of it. Neither is anybody's work;
     *   4. an own value that DIFFERS from the origin's → somebody wrote it after the
     *      copy. `edited`, kept, unless `--force`.
     *
     * @param  array<int, string>  $handles
     * @return array<string, array{from: mixed, to: mixed, reason: string}>
     */
    private function clearPlan($entry, $root, PlainLanguageDocument $document, array $handles, bool $keepEdited): array
    {
        $plan = [];

        foreach ($handles as $handle) {
            $empty = $document->emptyValueFor($entry, $handle);
            $hasOwn = $entry->data()->has($handle);
            $current = $hasOwn ? $entry->data()->get($handle) : $root->data()->get($handle);
            $inherited = $root->data()->get($handle);

            $reason = match (true) {
                $hasOwn && $current === $empty => PlainLanguageSeo::UNCHANGED,
                ! $hasOwn && $this->isBlank($inherited) => PlainLanguageSeo::UNCHANGED,
                ! $hasOwn || $current === $inherited => PlainLanguageSeo::WRITE,
                $keepEdited => PlainLanguageSeo::EDITED,
                default => PlainLanguageSeo::WRITE,
            };

            $plan[$handle] = ['from' => $current, 'to' => $empty, 'reason' => $reason];
        }

        return $plan;
    }

    /** Whether a stored value holds nothing worth shadowing. */
    private function isBlank($value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * Whether this version ever carried a finished rewrite.
     *
     * The `plain_language_completed` event is the direct answer, and the only one for
     * a rewrite that ran after that event existed. It cannot be the ONLY test: the
     * versions this command was written for predate it, and
     * PurgeWorkflowDataOnEntryDeleted-style history pruning means an absent row is not
     * proof of absence either.
     *
     * So there is a fallback, and it asks the content itself: the version has
     * `content_sets` of its OWN, and that content differs from the origin's. The
     * second half carries the whole test. A localization is created as a full copy of
     * its source, so own content alone proves nothing — an empty copy waiting for its
     * rewrite has exactly the same shape. Content that has DIVERGED from the origin is
     * either a rewrite or an editor's work on the plain version, and both are a
     * document worth deriving from.
     *
     * The title is checked too, for the case where the rewrite changed the heading but
     * a project has content_sets excluded from the run.
     */
    private function wasRewritten($entry, $root, string $workflow): bool
    {
        $completed = WorkflowEvent::query()
            ->where('workflow', $workflow)
            ->where('subject_id', $entry->id())
            ->where('type', self::COMPLETED_EVENT)
            ->exists();

        if ($completed) {
            return true;
        }

        $content = (array) $entry->data()->get(PlainLanguageDocument::contentField());

        if ($content === []) {
            return false;
        }

        return $content !== (array) $root->data()->get(PlainLanguageDocument::contentField())
            || $entry->data()->get(PlainLanguageDocument::titleField())
                !== $root->data()->get(PlainLanguageDocument::titleField());
    }

    /**
     * The version this one was copied from, or null when it is not a localization.
     *
     * `root()` for the reason PlainLanguageDocument::originSeo() gives: localizations
     * are created off the root, so the origin chain is one level deep and the root IS
     * the origin. An entry that is its own root has none.
     */
    private function rootOf($entry)
    {
        $root = method_exists($entry, 'root') ? $entry->root() : null;

        return $root === null || $root->id() === $entry->id() ? null : $root;
    }

    /**
     * The plain-language workflows to walk, with the site each one claims and the
     * collections it governs.
     *
     * `plain_language_site` is what MAKES a workflow the plain-language manager (see
     * WorkflowResolver::plainLanguageSite), and it names the one site its rewrites
     * land in — so it answers both questions this command has at once: which
     * workflows are in scope, and which versions belong to them.
     *
     * @return array<string, array{site: string, collections: array<int, string>, clear: array<int, string>}>
     */
    private function workflows(DefinitionLoader $loader, WorkflowResolver $resolver, ?string $only): array
    {
        $names = $only !== null && $only !== '' ? [$only] : $loader->all();
        $workflows = [];

        foreach ($names as $name) {
            if (! in_array($name, $loader->all(), true)) {
                $this->warn("Workflow [{$name}] not found — skipped.");

                continue;
            }

            $raw = $loader->raw($name);
            $site = $resolver->plainLanguageSite($raw);

            if ($site === null) {
                // Named explicitly, so say why nothing happens. Without --workflow this
                // is every authoring and translation workflow in the project and the
                // warning would be noise.
                if ($only !== null && $only !== '') {
                    $this->warn("Workflow [{$name}] claims no plain-language site — skipped.");
                }

                continue;
            }

            $collections = [];

            foreach ((array) ($raw['collections'] ?? []) as $collection) {
                if (is_string($collection) && $collection !== '') {
                    $collections[$collection] = true;
                }
            }

            if ($collections === []) {
                $this->warn("Workflow [{$name}] governs no collection — skipped.");

                continue;
            }

            $workflows[$name] = [
                'site' => $site,
                'collections' => array_keys($collections),
                // Read through the resolver, so "which fields" is the same answer the
                // live path gets — including its refusal to hand any out for a
                // workflow that is not the plain-language manager.
                'clear' => $resolver->plainLanguageClearFields($raw),
            ];
        }

        return $workflows;
    }

    /** A version in the report: its slug where it has one, its id otherwise. */
    private function label($entry): string
    {
        $slug = (string) ($entry->slug() ?? '');

        return $slug === '' ? (string) $entry->id() : $slug.' ('.$entry->id().')';
    }

    /**
     * A value as the report shows it: quoted, and cut so one long description cannot
     * push the arrow of the next line off the terminal.
     */
    private function show($value): string
    {
        // A cleared field is a Bard document, not a string: counted rather than
        // printed, so one source list cannot bury the report it appears in.
        if (is_array($value)) {
            return $value === [] ? '[empty]' : '['.count($value).' node(s)]';
        }

        if (! is_string($value)) {
            return $value === null ? '—' : '['.gettype($value).']';
        }

        return "'".(mb_strlen($value) > 70 ? mb_substr($value, 0, 69).'…' : $value)."'";
    }
}
