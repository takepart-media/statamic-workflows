<?php

namespace TakepartMedia\Workflows\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use Statamic\Http\Controllers\CP\CpController;
use TakepartMedia\Workflows\Http\Controllers\Concerns\WorkflowControllerHelpers;
use TakepartMedia\Workflows\Mail\MailTypes;
use TakepartMedia\Workflows\Translation\DeeplConfig;
use TakepartMedia\Workflows\Translation\PlainLanguageConfig;
use TakepartMedia\Workflows\Workflow\AssignmentCriteria;
use TakepartMedia\Workflows\Workflow\CustomActions;
use TakepartMedia\Workflows\Workflow\DefinitionLinter;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\DefinitionValidationException;
use TakepartMedia\Workflows\Workflow\ProgressSteps;
use TakepartMedia\Workflows\Workflow\WorkflowDataPurger;
use TakepartMedia\Workflows\Workflow\WorkflowDefinitionWriter;
use TakepartMedia\Workflows\Workflow\WorkflowEventStore;
use TakepartMedia\Workflows\Workflow\WorkflowInstaller;
use TakepartMedia\Workflows\Workflow\WorkflowState;
use TakepartMedia\Workflows\Workflow\WorkflowStateSeeder;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;

/**
 * Super-admin-only workflow administration: the settings index (install,
 * on/off, delete, seed states) and the per-workflow settings editor with its
 * save/delete-place endpoints. Split out of WorkflowController, which serves
 * the editorial side (queues, instance summary, transitions); the helpers both
 * need live in WorkflowControllerHelpers.
 */
class WorkflowSettingsController extends CpController
{
    use WorkflowControllerHelpers;

    /**
     * The top-level definition keys an editor save may carry. `name` is not
     * among them (the writer pins it to the file name), nor `active` (that has
     * its own switch).
     */
    private const DEFINITION_KEYS = ['places', 'transitions', 'metadata', 'collections', 'initial_place', 'marking_store', 'type'];

    /**
     * Super-admin-only index of every workflow in the project. Landing page for
     * "Settings > Workflows" in the CP nav — mirrors /cp/forms: a single Statamic
     * `Listing` with per-workflow rows and edit/queue links.
     */
    public function settingsIndex(DefinitionLoader $loader)
    {
        abort_unless($this->isSuperAdmin(), 403);

        $columns = [
            \Statamic\CP\Column::make('title')->label(__('workflows::messages.nav_label')),
            \Statamic\CP\Column::make('handle')->label($this->t('handle')),
            \Statamic\CP\Column::make('places')->label(__('workflows::messages.places'))->numeric(true),
            \Statamic\CP\Column::make('transitions')->label(__('workflows::messages.transitions'))->numeric(true),
            \Statamic\CP\Column::make('collections')->label(__('workflows::messages.collections')),
            \Statamic\CP\Column::make('status')->label(__('workflows::messages.status')),
        ];

        // Inactive workflows must stay listed — this screen is where they get
        // switched back on or removed.
        $workflows = collect($loader->allIncludingInactive())
            ->map(function (string $name) use ($loader) {
                $raw = $loader->raw($name);
                $navMeta = $raw['metadata']['nav'] ?? null;
                $slug = is_array($navMeta) ? ($navMeta['slug'] ?? null) : null;
                $active = $loader->isActive($name);
                $collections = (array) ($raw['collections'] ?? []);

                return [
                    'id' => $name,
                    'handle' => $name,
                    'title' => is_array($navMeta)
                        ? DefinitionLoader::navLabel($navMeta)
                        : ($raw['metadata']['label'] ?? $name),
                    'icon' => (is_array($navMeta) ? ($navMeta['icon'] ?? null) : null) ?: 'workflow',
                    'places' => count($raw['places'] ?? []),
                    'transitions' => count($raw['transitions'] ?? []),
                    'collections' => $collections,
                    'active' => $active,
                    'edit_url' => cp_route('workflows.settings.edit', ['workflow' => $name]),
                    // An inactive workflow has no manager page — the route
                    // resolves through the active list and would 404.
                    'queue_url' => $slug && $active ? cp_route('workflows.workflow', ['slug' => $slug]) : null,
                    'active_url' => cp_route('workflows.settings.active', ['workflow' => $name]),
                    'delete_url' => cp_route('workflows.settings.destroy', ['workflow' => $name]),
                    // Only offered where there is something to seed: the seeder
                    // no-ops on an inactive workflow (its guard reads the active
                    // list), and a workflow without collections has no entries.
                    'seed_url' => $active && $collections !== []
                        ? cp_route('workflows.settings.seed', ['workflow' => $name])
                        : null,
                ];
            })
            ->values()
            ->all();

        // Installer state — powers the "Install workflow" modal in the CP. Only
        // types the project does NOT have yet: an installed workflow is managed
        // from its own row (settings, on/off, delete), never by re-running the
        // installer over it.
        $installer = app(WorkflowInstaller::class);
        // Labelled from the example's own metadata (WorkflowInstaller::describedTypes),
        // so the modal offers "Einfache Sprache" rather than the file name — and says
        // in one line what the flow is for.
        $installTypes = collect($installer->describedTypes())
            ->reject(fn (array $type) => $type['installed'])
            ->map(fn (array $type) => [
                'value' => $type['value'],
                'label' => $type['label'],
                'description' => $type['description'],
            ])
            ->values()
            ->all();

        return Inertia::render('WorkflowsSettingsIndex', [
            'workflows' => $workflows,
            'initialColumns' => $columns,
            'install' => [
                'types' => $installTypes,
                'collections' => $installer->projectCollections(),
                'submit_url' => cp_route('workflows.settings.install'),
            ],
        ]);
    }

    /**
     * CP-side counterpart of `php artisan workflows:install`: given a picked
     * workflow type, a chosen collection list, and an optional backfill
     * flag, run the same primitive steps the CLI does (copy example, rewrite
     * collections, optionally seed states) via the shared `WorkflowInstaller`
     * service. Super-admin-only.
     *
     * Installing over an existing workflow is rejected. The definition is the
     * project's own from the moment it lands — its collections are edited in the
     * settings editor, and switching it off or removing it happens from its row
     * in the settings index. The UI hides installed types; this is the gate.
     */
    public function installWorkflow(Request $request)
    {
        abort_unless($this->isSuperAdmin(), 403);

        $installer = app(WorkflowInstaller::class);
        $availableTypes = $installer->availableTypes();

        if ($availableTypes === []) {
            return response()->json([
                'error' => __('workflows::messages.install_no_types'),
            ], 422);
        }

        $data = $request->validate([
            'type' => 'required|string',
            'collections' => 'array',
            'collections.*' => 'string',
            'backfill' => 'boolean',
        ]);

        if (! in_array($data['type'], $availableTypes, true)) {
            return response()->json([
                'error' => __('workflows::messages.install_unknown_type', ['type' => $data['type']]),
            ], 422);
        }

        if ($installer->isInstalled($data['type'])) {
            return response()->json([
                'error' => __('workflows::messages.install_already_installed', ['type' => $data['type']]),
            ], 422);
        }

        $installer->copyExampleIfMissing($data['type']);
        $collections = $installer->rewriteCollections($data['type'], $data['collections'] ?? []);

        // Backfill only makes sense with something to attach the workflow to.
        $seeded = ($data['backfill'] ?? false) && $collections !== []
            ? $installer->backfill($data['type'])
            : 0;

        return response()->json([
            'data' => [
                'type' => $data['type'],
                'collections' => $collections,
                'seeded' => $seeded,
            ],
        ]);
    }

    /**
     * Switch an installed workflow on or off. Off means `active: false` in its
     * YAML, which `DefinitionLoader::all()` filters out — the workflow keeps its
     * definition and its recorded history but disappears from the nav, the
     * manager routes, the entry editor and the console commands.
     * Super-admin-only.
     */
    public function setWorkflowActive(Request $request, string $workflow)
    {
        abort_unless($this->isSuperAdmin(), 403);

        $loader = app(DefinitionLoader::class);
        abort_unless(in_array($workflow, $loader->allIncludingInactive(), true), 404);

        $data = $request->validate(['active' => 'required|boolean']);

        app(WorkflowDefinitionWriter::class)->setActive($workflow, (bool) $data['active']);

        return response()->json([
            'data' => ['workflow' => $workflow, 'active' => (bool) $data['active']],
        ]);
    }

    /**
     * Remove an installed workflow's definition. The recorded states, assignees,
     * history and comments stay — see `WorkflowDefinitionWriter::delete()` for
     * why. Super-admin-only.
     */
    public function destroyWorkflow(string $workflow)
    {
        abort_unless($this->isSuperAdmin(), 403);

        $loader = app(DefinitionLoader::class);
        abort_unless(in_array($workflow, $loader->allIncludingInactive(), true), 404);

        app(WorkflowDefinitionWriter::class)->delete($workflow);

        return response()->json(['data' => ['workflow' => $workflow, 'deleted' => true]]);
    }

    /**
     * CP counterpart of `php artisan workflows:seed-states --workflow=<name>`:
     * backfill the state rows of entries that predate the workflow, so they take
     * part in SLA aging and show up in personal queues instead of only existing
     * via the derived display default.
     *
     * Idempotent — entries that already carry a state are skipped — so this needs
     * no confirmation and may be clicked twice. The command's `--redo` variant
     * (re-derive existing states nobody has worked on) stays CLI-only: that is
     * the repair case, not the everyday one.
     *
     * Requires the workflow to be ACTIVE. `WorkflowStateSeeder::seed()` guards on
     * the active list and would otherwise report a truthful but useless `0`; the
     * settings index hides the action for inactive rows and this is the gate.
     * Super-admin-only.
     */
    public function seedWorkflowStates(string $workflow)
    {
        abort_unless($this->isSuperAdmin(), 403);

        $loader = app(DefinitionLoader::class);
        abort_unless(in_array($workflow, $loader->allIncludingInactive(), true), 404);

        if (! $loader->isActive($workflow)) {
            return response()->json(['message' => $this->t('states_seed_inactive')], 422);
        }

        $seeder = app(WorkflowStateSeeder::class);

        return response()->json([
            'data' => [
                'workflow' => $workflow,
                'seeded' => $seeder->seed($workflow),
                // Worth saying out loud, same as the command does: with no place
                // meaning "finished", EVERY entry lands in the initial place.
                'lacks_done_place' => $seeder->lacksDonePlace($workflow),
            ],
        ]);
    }

    /**
     * Super-admin-only workflow settings editor. Renders the definition of a
     * workflow (places, transitions, metadata) for editing and reports how many
     * entries currently sit in each place — the migration prompt uses that
     * count when a place is about to be deleted.
     */
    public function editSettings(string $workflow)
    {
        abort_unless($this->isSuperAdmin(), 403);

        $loader = app(DefinitionLoader::class);

        // Keyed by the workflow HANDLE: a workflow without `metadata.nav` has no
        // slug, and under the old slug URL it simply could not be edited. Old
        // slug links (bookmarks, history) still resolve — they get a permanent
        // redirect to the handle URL instead of a 404.
        if (! in_array($workflow, $loader->allIncludingInactive(), true)) {
            $handle = $this->workflowBySlug($workflow, $loader);
            abort_if($handle === null, 404);

            return redirect(cp_route('workflows.settings.edit', ['workflow' => $handle]), 301);
        }

        $name = $workflow;
        $raw = $loader->raw($name);
        $navMeta = $raw['metadata']['nav'] ?? null;
        $slug = is_array($navMeta) ? ($navMeta['slug'] ?? null) : null;
        $editable = $this->stripAutoInjected($raw);
        $states = app(WorkflowStateStore::class)->statesFor($name);
        $usage = collect($states)
            ->countBy()
            ->all();

        return Inertia::render('WorkflowsSettings', [
            'workflow' => $name,
            'handle' => $name,
            // Sent back with every save: a mismatch means the file changed under
            // the editor and the save answers 409 instead of overwriting it.
            'version' => app(WorkflowDefinitionWriter::class)->fingerprint($name),
            'active' => $loader->isActive($name),
            'activeUrl' => cp_route('workflows.settings.active', ['workflow' => $name]),
            'saveDefinitionUrl' => cp_route('workflows.settings.save', ['workflow' => $name]),
            // Dry run of the same payload (errors + warnings, never writes), and
            // a fresh per-place count for the usage badges.
            'validateUrl' => cp_route('workflows.settings.validate', ['workflow' => $name]),
            'usageUrl' => cp_route('workflows.settings.usage', ['workflow' => $name]),
            // What DefinitionLoader adds at read time (pending_revision and its
            // two system transitions) — not part of `definition`, never saved,
            // but shown read-only so the graph matches what runs.
            'injected' => $this->injectedParts($raw, $editable),
            // Empty for a workflow without `metadata.nav` — the editor shows the
            // slug read-only and expects a string.
            'slug' => (string) ($slug ?? ''),
            'definition' => $editable,
            'stateUsage' => $usage,
            'saveUrl' => cp_route('workflows.api.settings.save', ['workflow' => $name]),
            'deletePlaceUrlTemplate' => cp_route('workflows.api.settings.place.delete', ['workflow' => $name, 'place' => '__PLACE__']),
            // Without a slug (or while inactive) there is no manager page of its
            // own — the button falls back to the general queue instead of a 404.
            'queueUrl' => $slug && $loader->isActive($name)
                ? cp_route('workflows.workflow', ['slug' => $slug])
                : cp_route('workflows.queue'),
            'reservedPlaces' => WorkflowDefinitionWriter::RESERVED_PLACE_HANDLES,
            'reservedTransitions' => WorkflowDefinitionWriter::RESERVED_TRANSITION_HANDLES,
            // One name field per language. The shipped wording per place and
            // language is what an EMPTY field falls back to — the editor shows it as
            // the placeholder, so the author sees what they would override.
            'labelLocales' => DefinitionLoader::LABEL_LOCALES,
            'labelLocale' => DefinitionLoader::labelLocale(),
            'shippedPlaceLabels' => collect($editable['places'] ?? [])
                ->mapWithKeys(fn ($place, $handle) => [$handle => collect(DefinitionLoader::LABEL_LOCALES)
                    ->mapWithKeys(fn (string $locale) => [$locale => DefinitionLoader::shippedStateLabel((string) $handle, $locale)])
                    ->all()])
                ->all(),
            'colorOptions' => collect(WorkflowDefinitionWriter::PLACE_COLORS)
                ->map(fn (string $color) => ['value' => $color, 'label' => $color])
                ->all(),
            'variantOptions' => collect(WorkflowDefinitionWriter::TRANSITION_VARIANTS)
                ->map(fn (string $variant) => ['value' => $variant, 'label' => $variant])
                ->all(),
            // Labelled here rather than in the editor because the label is a
            // translation and the list is a server constant — the same split
            // assignmentCriteria uses. Colours and variants above ship their handle
            // as the label because a colour name IS its own label; "horizontal" is
            // not.
            'progressOrientationOptions' => collect(ProgressSteps::ORIENTATIONS)
                ->map(fn (string $value) => [
                    'value' => $value,
                    'label' => (string) __('workflows::messages.progress_orientation_'.$value),
                ])
                ->all(),
            'collectionOptions' => Collection::all()
                ->map(fn ($collection) => ['value' => $collection->handle(), 'label' => $collection->title()])
                ->sortBy('label')
                ->values()
                ->all(),
            // handle => name of the workflow that OWNS the collection under the
            // first-wins rule of WorkflowResolver::forCollection(). Overlap is
            // legitimate, but a workflow that isn't the owner only governs
            // entries with an explicitly stored state — the editor shows that
            // instead of letting it surprise someone later.
            'collectionOwners' => Collection::all()
                ->mapWithKeys(fn ($collection) => [
                    $collection->handle() => $this->workflowForCollection($collection->handle()),
                ])
                ->filter()
                ->all(),
            'groupOptions' => UserGroup::all()
                ->map(fn ($group) => ['value' => $group->handle(), 'label' => $group->title()])
                ->sortBy('label')
                ->values()
                ->all(),
            'roleOptions' => Role::all()
                ->map(fn ($role) => ['value' => $role->handle(), 'label' => $role->title()])
                ->sortBy('label')
                ->values()
                ->all(),
            // Every user as [{value: id, label: name}] — the third audience the
            // source-view rules of a localization workflow can name (see
            // SourceViewRules). By id, not e-mail: the id is what the addon keys
            // everything else on (assignees, creators), and an address changes.
            'userOptions' => User::all()
                ->map(fn ($user) => ['value' => (string) $user->id(), 'label' => (string) ($user->name() ?: $user->email())])
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
            // What a `user_assignment` action may filter on: the two built-in
            // criteria plus whatever the project declared in
            // `config/workflows.php → assignment_criteria`. Options are resolved
            // HERE rather than in the editor — only the server knows what a
            // project criterion points at, and this page is super-admin-only, so
            // the term/role lookups it costs are paid once by one person.
            'assignmentCriteria' => collect(AssignmentCriteria::all())
                ->map(fn (array $criterion) => [
                    'value' => $criterion['handle'],
                    'label' => $criterion['label'],
                    'operators' => AssignmentCriteria::operatorsFor($criterion),
                    'options' => AssignmentCriteria::options($criterion),
                ])
                ->values()
                ->all(),
            // Action types this PROJECT declared, already narrowed to the workflow
            // being edited and with every field's options resolved — same
            // reasoning as assignmentCriteria above. Keyed by scope, because the
            // editor asks two separate questions ("what can a transition carry",
            // "what can a place carry") and mixing them would mean filtering in
            // the template.
            'customActions' => collect(CustomActions::SCOPES)
                ->mapWithKeys(fn (string $scope) => [$scope => collect(CustomActions::forScope($scope, $name))
                    ->map(fn (array $action) => [
                        'value' => $action['handle'],
                        'label' => $action['label'],
                        'fields' => collect($action['fields'])
                            ->map(fn (array $field) => [
                                'handle' => $field['handle'],
                                'type' => $field['type'],
                                'label' => $field['label'],
                                'min' => $field['min'],
                                'max' => $field['max'],
                                'options' => CustomActions::fieldOptions($field),
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all()])
                ->all(),
            // Every configured site, in CP order — the plain-language picker
            // deliberately does NOT narrow this to the workflow's collections:
            // a stored site would silently become invalid the moment somebody
            // edits the collection list.
            'siteOptions' => collect($this->siteHandles())
                ->map(fn (string $handle) => ['value' => $handle, 'label' => $this->siteName($handle)])
                ->values()
                ->all(),
            'envStatus' => $this->envStatus(),
            // Whether a `mail` action would actually reach anybody.
            // Credentials deliberately absent — see mailStatus().
            'mailStatus' => $this->mailStatus(),
            'reminderTime' => (string) config('workflows.reminder_time', '08:00'),
            // WHICH mail a notification can send. Resolved here rather than in the
            // editor for the same reason customActions is: a project type exists
            // only in `config/workflows.php`, so the client cannot enumerate them,
            // and its label may come from a project language file the client would
            // have to guess the key of.
            // Über die SCHLÜSSEL, nicht die Werte: all() liefert die aufgelösten
            // Deklarationen (Label, Composer-Klasse, View), von denen der Editor
            // nur den Handle und die Beschriftung braucht — die Klasse hat im
            // Client nichts zu suchen.
            'mailTypes' => collect(MailTypes::all())
                ->keys()
                ->map(fn (string $type) => ['value' => $type, 'label' => MailTypes::label($type)])
                ->values()
                ->all(),
            // What applies when the workflow declares nothing — so the editor can
            // say whether an empty selection means "super admins only" or "falls
            // back to the project config".
            'postCreatorFallback' => collect(config('workflows.post_creator_groups', []))
                ->filter()
                ->map(fn ($handle) => UserGroup::find($handle)?->title() ?? $handle)
                ->values()
                ->all(),
            'assignerFallback' => collect(config('workflows.assigner_groups', []))
                ->filter()
                ->map(fn ($handle) => UserGroup::find($handle)?->title() ?? $handle)
                ->values()
                ->all(),
        ]);
    }

    /**
     * Whether the translation services have their credentials — never what those
     * credentials are. Only a boolean leaves the server; the DeepL entry adds the
     * detected plan, one bit derived from the key's `:fx` suffix, so the admin can
     * see the endpoint resolved the way they expect.
     *
     * The credentials themselves are not editable in the CP: they live in
     * `config/workflows.php` → `.env`, because this addon's settings editor
     * writes a committed YAML. Without this read-out, though, a missing `.env`
     * entry would first surface as a failed API call that looks like a broken
     * integration — so the editor at least says which variable is missing.
     *
     * @return array<string, array{name: string, set: bool, plan: ?string}>
     */
    private function envStatus(): array
    {
        $deepl = app(DeeplConfig::class);
        $plain = app(PlainLanguageConfig::class);

        return [
            'deepl' => [
                'name' => DeeplConfig::ENV_KEY,
                'set' => $deepl->isConfigured(),
                'plan' => $deepl->plan(),
            ],
            // Reported per variable rather than as one "plain language is
            // configured" bit: a URL without a token is the failure an admin needs
            // pointed at, and isConfigured() only says that both are there.
            'plain_language_url' => [
                'name' => PlainLanguageConfig::ENV_URL,
                'set' => $plain->baseUrl() !== null,
                'plan' => null,
            ],
            'plain_language_token' => [
                'name' => PlainLanguageConfig::ENV_TOKEN,
                'set' => $plain->token() !== null,
                'plan' => null,
            ],
        ];
    }

    /**
     * Which mailer is configured — the line the settings editor shows next to a
     * `mail` action.
     *
     * Same rule as envStatus(): NO credentials leave the server. Host, port,
     * username and password are all irrelevant to the question being answered
     * ("will a mail actually go out?") and every one of them would be a secret
     * shipped into a Vue prop.
     *
     * It exists because the failure it describes is invisible. `MAIL_MAILER=log`
     * is the Laravel default and a perfectly normal local setup — the transition
     * succeeds, the log fills up, and nobody receives anything. Somebody attaching
     * a notification action is exactly the person who needs to know that, at
     * exactly the moment they attach it.
     *
     * @return array{env: string, mailer: string, transport: string, delivers: bool, from: string}
     */
    private function mailStatus(): array
    {
        $mailer = (string) config('mail.default', '');
        $transport = (string) config("mail.mailers.{$mailer}.transport", '');

        return [
            'env' => 'MAIL_MAILER',
            'mailer' => $mailer,
            'transport' => $transport,
            'delivers' => $this->transportDelivers($mailer, $transport),
            'from' => (string) config('mail.from.address', ''),
        ];
    }

    /**
     * Whether a transport hands the message to anything outside this process.
     *
     * `log` writes it to the log file and `array` keeps it in memory — both are
     * legitimate setups and neither delivers. `failover` and `roundrobin` deliver
     * nothing themselves: they delegate to a nested list, so the honest answer for
     * them is whether ANY member does. Without that branch a failover over two
     * `log` mailers would report as delivering, which is the one wrong answer this
     * whole line exists to prevent.
     */
    private function transportDelivers(string $mailer, string $transport): bool
    {
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            return collect((array) config("mail.mailers.{$mailer}.mailers", []))
                ->contains(fn ($nested) => is_string($nested)
                    && $this->transportDelivers($nested, (string) config("mail.mailers.{$nested}.transport", '')));
        }

        // An unknown/empty transport counts as not delivering: a misspelled
        // MAIL_MAILER resolves to no mailer config at all, and claiming delivery
        // for it would be the same false reassurance as the failover case above.
        return $transport !== '' && ! in_array($transport, ['log', 'array'], true);
    }

    /**
     * Persist an editor payload. `places` is merged additively (deletions run
     * through deletePlace); `transitions`, `metadata`, and the other top-level
     * keys are wholesale-replaced by whatever the editor sends. The writer
     * validates the merged result and refuses to save a broken workflow.
     *
     * Removing a collection is destructive: its entries leave the workflow and
     * their states, assignments, history and comments are purged. That needs
     * `confirm_collection_removal` — without it the endpoint answers 422 with
     * the affected count so the editor can prompt first (same shape as
     * deletePlace's migration prompt).
     */
    public function saveSettings(Request $request, string $workflow)
    {
        abort_unless($this->isSuperAdmin(), 403);

        $loader = app(DefinitionLoader::class);
        $name = $this->workflowByHandleOrSlug($workflow, $loader);
        abort_if($name === null, 404);

        $payload = $request->only(self::DEFINITION_KEYS);
        $collections = $request->has('collections') ? (array) $request->input('collections', []) : null;

        if ($response = $this->unknownCollectionResponse((array) $collections)) {
            return $response;
        }

        $change = $this->collectionChange($name, $collections, $loader);

        if ($response = $this->collectionRemovalResponse($change, $request->boolean('confirm_collection_removal'))) {
            return $response;
        }

        try {
            app(WorkflowDefinitionWriter::class)->save($name, $payload);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $seeded = $this->applyCollectionChange($name, $change);

        app(WorkflowEventStore::class)->log(
            'workflow:'.$name,
            $name,
            'definition_saved',
            ['note' => __('workflows::messages.event_note_definition_saved')]
        );

        $raw = $loader->raw($name);

        return response()->json([
            'data' => [
                'definition' => $this->stripAutoInjected($raw),
                'purged' => count($change['orphaned']),
                'seeded' => $seeded,
                // Recomputed because a purge changes the per-place counts the
                // editor uses for its "place still holds entries" prompt.
                'state_usage' => $this->stateUsage($name),
            ],
        ]);
    }

    /**
     * The graph editor's save. Same writer, same collection gate as
     * saveSettings(), plus:
     *
     * - optimistic locking: `version` must be the fingerprint the editor was
     *   loaded with (WorkflowDefinitionWriter::fingerprint()). Anything else
     *   means the file changed underneath it — 409 with the current definition
     *   and version, unless `force` says to overwrite;
     * - the payload is nested under `definition` and must name every existing
     *   place — either in `definition.places` or in `removed_places`
     *   (`[{place, migrate_to?}]`). Places are never deleted by omission: a
     *   place in neither is a 422 (`places_mismatch`) rather than something the
     *   additive merge silently puts back;
     * - removed places that still hold entries need a `migrate_to` among the
     *   remaining places (see removalChecks()) — else 422 `needs_migration`;
     * - `definition_saved` is only logged when something besides
     *   `metadata.editor` changed — dragging nodes around is not a workflow
     *   change worth a history line.
     *
     * Order: version → place set → removals → collection gate → validation →
     * backup + YAML write → one transaction on the workflow connection that
     * migrates the entries of removed places and purges removed collections →
     * seeding → events. The YAML goes first because a migration target may be
     * a place created by this very save; if the transaction fails, the backup
     * is put back so file and data never disagree.
     */
    public function saveDefinition(Request $request, string $workflow)
    {
        abort_unless($this->isSuperAdmin(), 403);

        $loader = app(DefinitionLoader::class);
        abort_unless(in_array($workflow, $loader->allIncludingInactive(), true), 404);

        $name = $workflow;
        $writer = app(WorkflowDefinitionWriter::class);
        $version = $writer->fingerprint($name);
        $before = $this->stripAutoInjected($loader->raw($name));

        if ((string) $request->input('version', '') !== $version && ! $request->boolean('force')) {
            return response()->json([
                'message' => $this->t('settings_version_conflict'),
                'conflict' => true,
                'version' => $version,
                'definition' => $before,
            ], 409);
        }

        $payload = $this->definitionPayload($request);
        $removedPlaces = $this->removedPlaces($request);

        $sent = is_array($payload['places'] ?? null) ? array_map('strval', array_keys($payload['places'])) : [];
        $missing = array_values(array_diff(
            array_map('strval', array_keys($before['places'] ?? [])),
            $sent,
            array_keys($removedPlaces),
        ));

        if ($missing !== []) {
            return response()->json([
                'message' => $this->t('writer_places_mismatch', ['places' => implode(', ', $missing)]),
                'places_mismatch' => true,
                'missing_places' => $missing,
            ], 422);
        }

        $removals = $this->removalChecks($name, $before, $payload, $removedPlaces);

        if (($problem = $this->firstRemovalProblem($removals)) !== null) {
            return response()->json([
                'message' => $problem,
                'needs_migration' => true,
                'removals' => $removals,
            ], 422);
        }

        $collections = array_key_exists('collections', $payload) ? (array) $payload['collections'] : null;

        if ($response = $this->unknownCollectionResponse((array) $collections)) {
            return $response;
        }

        $change = $this->collectionChange($name, $collections, $loader);

        if ($response = $this->collectionRemovalResponse($change, $request->boolean('confirm_collection_removal'))) {
            return $response;
        }

        $result = $writer->dryRun($name, $payload, array_keys($removedPlaces));

        if ($result['errors'] !== []) {
            // `message` is the first problem (what the toast shows), `errors`
            // all of them with their paths, for the graph.
            return response()->json(['message' => $result['errors'][0]['message'], 'errors' => $result['errors']], 422);
        }

        $path = $loader->path($name);
        $original = is_file($path) ? (string) file_get_contents($path) : null;
        $backup = $writer->persist($name, $result['definition']);
        $store = app(WorkflowStateStore::class);

        try {
            $migrated = WorkflowState::query()->getConnection()->transaction(function () use ($name, $removals, $change, $store) {
                $migrated = [];

                foreach ($removals as $removal) {
                    $migrated[$removal['place']] = $removal['needs_migration']
                        ? $store->migrateState($name, $removal['place'], $removal['migrate_to'])
                        : 0;
                }

                if ($change['orphaned'] !== []) {
                    $this->purgeWorkflowData($name, $change['orphaned']);
                }

                return $migrated;
            });
        } catch (\Throwable $e) {
            report($e);

            // Back to the file as it was before this save: the data side rolled
            // back, so the new definition would describe places the entries no
            // longer match.
            if ($backup !== null) {
                $writer->restoreBackup($name, $backup);
            } elseif ($original !== null) {
                File::put($path, $original);
            }

            DefinitionLoader::flush();
            $store->forget($name);

            return response()->json(['message' => $this->t('settings_save_rolled_back')], 500);
        }

        foreach ($removals as $removal) {
            app(WorkflowEventStore::class)->log('workflow:'.$name, $name, 'place_deleted', $this->placeDeletedEvent(
                $removal['place'],
                $removal['needs_migration'] ? $removal['migrate_to'] : null,
                $migrated[$removal['place']] ?? 0,
            ));
        }

        $this->logCollectionsRemoved($name, $change);
        $seeded = $this->seedAddedCollections($name, $change);
        $after = $this->stripAutoInjected($loader->raw($name));

        if ($this->withoutEditorMeta($before) !== $this->withoutEditorMeta($after)) {
            app(WorkflowEventStore::class)->log(
                'workflow:'.$name,
                $name,
                'definition_saved',
                ['note' => __('workflows::messages.event_note_definition_saved')]
            );
        }

        return response()->json([
            'data' => [
                'definition' => $after,
                'version' => $writer->fingerprint($name),
                'purged' => count($change['orphaned']),
                'seeded' => $seeded,
                'migrated' => (object) $migrated,
                'state_usage' => $this->stateUsage($name),
            ],
        ]);
    }

    /**
     * Dry run of a saveDefinition() payload: the writer's errors (all of them,
     * with paths), DefinitionLinter's warnings and the per-place checks of
     * `removed_places` (see removalChecks()). Never writes, ignores
     * `version`/`force` and answers 200 either way — `valid` says whether the
     * save would pass validation and its removal checks. The version returned
     * is the current one, so the editor can notice a change underneath it
     * early.
     */
    public function validateDefinition(Request $request, string $workflow)
    {
        abort_unless($this->isSuperAdmin(), 403);

        $loader = app(DefinitionLoader::class);
        abort_unless(in_array($workflow, $loader->allIncludingInactive(), true), 404);

        $writer = app(WorkflowDefinitionWriter::class);
        $payload = $this->definitionPayload($request);
        $removedPlaces = $this->removedPlaces($request);
        $result = $writer->dryRun($workflow, $payload, array_keys($removedPlaces));
        $removals = $this->removalChecks($workflow, $this->stripAutoInjected($loader->raw($workflow)), $payload, $removedPlaces);

        return response()->json([
            'valid' => $result['errors'] === [] && $this->firstRemovalProblem($removals) === null,
            'version' => $writer->fingerprint($workflow),
            'errors' => $result['errors'],
            'warnings' => app(DefinitionLinter::class)->lint($workflow, $result['definition']),
            'removals' => $removals,
        ]);
    }

    /**
     * `removed_places` of a graph-editor request as `place => migrate_to`
     * (null when none was given). Malformed rows are skipped; a place named
     * twice keeps its first row.
     *
     * @return array<string, string|null>
     */
    private function removedPlaces(Request $request): array
    {
        $removed = [];

        foreach ((array) $request->input('removed_places', []) as $row) {
            $place = is_array($row) ? ($row['place'] ?? null) : $row;

            if (! is_string($place) || $place === '' || array_key_exists($place, $removed)) {
                continue;
            }

            $target = is_array($row) ? ($row['migrate_to'] ?? null) : null;
            $removed[$place] = is_string($target) && $target !== '' ? $target : null;
        }

        return $removed;
    }

    /**
     * One check per removed place, against the definition as it is now
     * (`$current`, auto-injected parts stripped) and as the payload would leave
     * it. `error` is a problem with the place itself (unknown, still part of
     * `definition.places`, the new `initial_place`); `target_error` one with its
     * migration: a place holding entries (`affected` > 0 → `needs_migration`)
     * needs a `migrate_to` that is among the remaining places — possibly one
     * this save creates — and not itself being removed. Empty places are
     * removed without one; a `migrate_to` sent for them is ignored.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @param  array<string, string|null>  $removedPlaces
     * @return array<int, array{place: string, migrate_to: string|null, affected: int, needs_migration: bool, error: string|null, target_error: string|null}>
     */
    private function removalChecks(string $name, array $current, array $payload, array $removedPlaces): array
    {
        if ($removedPlaces === []) {
            return [];
        }

        $store = app(WorkflowStateStore::class);
        $existing = array_map('strval', array_keys($current['places'] ?? []));
        $remaining = array_diff(
            is_array($payload['places'] ?? null) ? array_map('strval', array_keys($payload['places'])) : $existing,
            WorkflowDefinitionWriter::RESERVED_PLACE_HANDLES,
            array_keys($removedPlaces),
        );
        $initial = (string) ($payload['initial_place'] ?? $current['initial_place'] ?? '');
        $checks = [];

        foreach ($removedPlaces as $place => $target) {
            $place = (string) $place;
            $affected = in_array($place, $existing, true) ? count($store->subjectsInState($name, $place)) : 0;

            $error = match (true) {
                ! in_array($place, $existing, true) => $this->t('removed_place_unknown', ['place' => $place]),
                is_array($payload['places'] ?? null) && array_key_exists($place, $payload['places']) => $this->t('removed_place_still_present', ['place' => $place]),
                $place === $initial => $this->t('removed_place_initial', ['place' => $place]),
                default => null,
            };

            $targetError = match (true) {
                $error !== null || $affected === 0 => null,
                $target === null => $this->t('removed_place_needs_target', ['place' => $place, 'count' => $affected]),
                ! in_array($target, $remaining, true) => $this->t('removed_place_target_invalid', ['place' => $place, 'target' => $target]),
                default => null,
            };

            $checks[] = [
                'place' => $place,
                'migrate_to' => $affected > 0 ? $target : null,
                'affected' => $affected,
                'needs_migration' => $affected > 0,
                'error' => $error,
                'target_error' => $targetError,
            ];
        }

        return $checks;
    }

    /**
     * The first problem among removalChecks(), or null when every removal can
     * go ahead.
     *
     * @param  array<int, array{error: string|null, target_error: string|null}>  $removals
     */
    private function firstRemovalProblem(array $removals): ?string
    {
        foreach ($removals as $removal) {
            if (($removal['error'] ?? null) !== null || ($removal['target_error'] ?? null) !== null) {
                return $removal['error'] ?? $removal['target_error'];
            }
        }

        return null;
    }

    /**
     * The `place_deleted` event data, in the fields WorkflowEventStore::log()
     * actually stores: the removed place as `from`, the migration target (if
     * entries were moved) as `to`, the count in the note.
     *
     * @return array{from: string, to: string|null, note: string}
     */
    private function placeDeletedEvent(string $place, ?string $migratedTo, int $moved): array
    {
        return [
            'from' => $place,
            'to' => $migratedTo,
            'note' => $migratedTo !== null && $moved > 0
                ? $this->t('event_note_place_migrated', ['place' => $place, 'target' => $migratedTo, 'count' => $moved])
                : $this->t('event_note_place_deleted', ['place' => $place]),
        ];
    }

    /**
     * Entries per place right now, plus the current version — the editor's
     * usage badges and delete prompts refresh from here without reloading the
     * page.
     */
    public function usage(string $workflow)
    {
        abort_unless($this->isSuperAdmin(), 403);

        $loader = app(DefinitionLoader::class);
        abort_unless(in_array($workflow, $loader->allIncludingInactive(), true), 404);

        return response()->json([
            'state_usage' => (object) $this->stateUsage($workflow),
            'version' => app(WorkflowDefinitionWriter::class)->fingerprint($workflow),
        ]);
    }

    /**
     * The `definition` of a graph-editor request, reduced to the keys a save
     * may carry.
     *
     * @return array<string, mixed>
     */
    private function definitionPayload(Request $request): array
    {
        $input = $request->input('definition');
        $input = is_array($input) ? $input : [];

        return array_intersect_key($input, array_flip(self::DEFINITION_KEYS));
    }

    /**
     * Reject unknown collection handles up front. The writer validates them too
     * (it is the authority), but the removal gate would otherwise fire first and
     * make the user confirm a destructive purge before ever learning that the
     * payload was invalid and nothing would be saved.
     *
     * @param  array<int, mixed>  $collections
     */
    private function unknownCollectionResponse(array $collections): ?JsonResponse
    {
        foreach (array_values($collections) as $index => $handle) {
            if (! is_string($handle) || Collection::find($handle) === null) {
                $message = (string) __('workflows::messages.collection_unknown', [
                    'collection' => is_string($handle) ? $handle : gettype($handle),
                ]);

                // Same `errors` entry the writer would produce, so the graph
                // editor can mark the field.
                return response()->json([
                    'message' => $message,
                    'errors' => [[
                        'path' => "collections.{$index}",
                        'code' => 'collection_unknown',
                        'message' => $message,
                        'node' => DefinitionValidationException::nodeFor("collections.{$index}"),
                    ]],
                ], 422);
            }
        }

        return null;
    }

    /**
     * Which collections a save removes and adds, and which subjects removing
     * them orphans. Read BEFORE writing — afterwards the removed collections
     * are gone and their entries can no longer be resolved. `$collections` is
     * null when the payload does not touch them.
     *
     * @param  array<int, string>|null  $collections
     * @return array{removed: array<int, string>, added: array<int, string>, orphaned: array<int, string>}
     */
    private function collectionChange(string $name, ?array $collections, DefinitionLoader $loader): array
    {
        if ($collections === null) {
            return ['removed' => [], 'added' => [], 'orphaned' => []];
        }

        $before = (array) ($loader->raw($name)['collections'] ?? []);
        $removed = array_values(array_diff($before, $collections));

        return [
            'removed' => $removed,
            'added' => array_values(array_diff($collections, $before)),
            'orphaned' => $removed === [] ? [] : $this->subjectIdsInCollections($name, $removed),
        ];
    }

    /**
     * Removing a collection is destructive: its entries leave the workflow and
     * their data is purged. Without `confirm_collection_removal` the save stops
     * here with the affected count, so the editor can prompt first.
     *
     * @param  array{removed: array<int, string>, added: array<int, string>, orphaned: array<int, string>}  $change
     */
    private function collectionRemovalResponse(array $change, bool $confirmed): ?JsonResponse
    {
        if ($change['orphaned'] === [] || $confirmed) {
            return null;
        }

        return response()->json([
            'message' => __('workflows::messages.collection_removal_needs_confirmation'),
            'needs_collection_confirmation' => true,
            'removed_collections' => $change['removed'],
            'affected_count' => count($change['orphaned']),
        ], 422);
    }

    /**
     * The data side of a collection change, run only after the YAML is safely
     * written — a validation failure leaves both the definition and the
     * workflow data untouched. Purges the orphaned subjects (and logs it), then
     * seeds state rows for entries of freshly added collections, so they take
     * part in SLA aging and show up in personal queues instead of only existing
     * via the derived display default. Returns the number of rows seeded.
     *
     * @param  array{removed: array<int, string>, added: array<int, string>, orphaned: array<int, string>}  $change
     */
    private function applyCollectionChange(string $name, array $change): int
    {
        if ($change['orphaned'] !== []) {
            $this->purgeWorkflowData($name, $change['orphaned']);
        }

        $this->logCollectionsRemoved($name, $change);

        return $this->seedAddedCollections($name, $change);
    }

    /**
     * The `collections_removed` history line, once the purge went through.
     *
     * @param  array{removed: array<int, string>, added: array<int, string>, orphaned: array<int, string>}  $change
     */
    private function logCollectionsRemoved(string $name, array $change): void
    {
        if ($change['orphaned'] === []) {
            return;
        }

        app(WorkflowEventStore::class)->log('workflow:'.$name, $name, 'collections_removed', [
            'note' => __('workflows::messages.event_note_collections_removed', [
                'collections' => implode(', ', $change['removed']),
                'count' => count($change['orphaned']),
            ]),
        ]);
    }

    /**
     * Seed state rows for the entries of freshly added collections. Returns
     * the number of rows seeded.
     *
     * @param  array{removed: array<int, string>, added: array<int, string>, orphaned: array<int, string>}  $change
     */
    private function seedAddedCollections(string $name, array $change): int
    {
        return $change['added'] === [] ? 0 : app(WorkflowStateSeeder::class)->seed($name, $change['added']);
    }

    /**
     * Entries per place — what the editor's "place still holds entries"
     * prompts and usage badges read.
     *
     * @return array<string, int>
     */
    private function stateUsage(string $name): array
    {
        return collect(app(WorkflowStateStore::class)->statesFor($name))->countBy()->all();
    }

    /**
     * A definition without `metadata.editor` — the comparison basis for
     * "did this save change the workflow, or only its layout".
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function withoutEditorMeta(array $definition): array
    {
        if (is_array($definition['metadata'] ?? null)) {
            unset($definition['metadata']['editor']);
        }

        return $definition;
    }

    /**
     * The places and transitions DefinitionLoader injected into `$raw` that the
     * editable definition does not carry. Objects rather than arrays, so an
     * empty side still serialises as `{}`.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $editable
     * @return array{places: object, transitions: object}
     */
    private function injectedParts(array $raw, array $editable): array
    {
        return [
            'places' => (object) array_diff_key((array) ($raw['places'] ?? []), (array) ($editable['places'] ?? [])),
            'transitions' => (object) array_diff_key((array) ($raw['transitions'] ?? []), (array) ($editable['transitions'] ?? [])),
        ];
    }

    /**
     * Delete a place from a workflow. When the place still holds entries the
     * caller MUST supply `migrate_to` — every affected entry is bulk-moved to
     * that place, then the place (and any transition that references it) is
     * removed from the YAML. Without `migrate_to` on a non-empty place the
     * endpoint returns 422 with the affected count so the client can open the
     * migration modal.
     */
    public function deletePlace(Request $request, string $workflow, string $place)
    {
        abort_unless($this->isSuperAdmin(), 403);

        $loader = app(DefinitionLoader::class);
        $name = $this->workflowByHandleOrSlug($workflow, $loader);
        abort_if($name === null, 404);

        $raw = $loader->raw($name);
        $editable = $this->stripAutoInjected($raw);

        if (! array_key_exists($place, $editable['places'] ?? [])) {
            return response()->json(['message' => $this->t('place_not_found')], 404);
        }

        if (($editable['initial_place'] ?? null) === $place) {
            return response()->json(['message' => $this->t('place_delete_initial')], 422);
        }

        $store = app(WorkflowStateStore::class);
        $affected = $store->subjectsInState($name, $place);
        $migrateTo = (string) $request->input('migrate_to', '');

        if (count($affected) > 0) {
            if ($migrateTo === '') {
                return response()->json([
                    'message' => __('workflows::messages.place_delete_needs_migration'),
                    'affected_count' => count($affected),
                    'affected_ids' => $affected,
                ], 422);
            }

            if ($migrateTo === $place || ! array_key_exists($migrateTo, $editable['places'] ?? [])) {
                return response()->json(['message' => $this->t('migrate_target_invalid')], 422);
            }

            $store->migrateState($name, $place, $migrateTo);
        }

        // Drop the place + any transition that references it.
        unset($editable['places'][$place]);
        foreach ($editable['transitions'] ?? [] as $handle => $transition) {
            $froms = (array) ($transition['from'] ?? []);
            $touches = in_array($place, $froms, true) || ($transition['to'] ?? null) === $place;
            if ($touches) {
                unset($editable['transitions'][$handle]);
            }
        }

        try {
            // The handle has to be named explicitly: merge() is additive for
            // places, so omitting it from the payload alone would restore it.
            app(WorkflowDefinitionWriter::class)->save($name, $editable, [$place]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        app(WorkflowEventStore::class)->log(
            'workflow:'.$name,
            $name,
            'place_deleted',
            $this->placeDeletedEvent($place, count($affected) > 0 ? $migrateTo : null, count($affected))
        );

        $freshRaw = $loader->raw($name);

        return response()->json([
            'data' => [
                'definition' => $this->stripAutoInjected($freshRaw),
                'affected' => count($affected),
            ],
        ]);
    }

    /**
     * Resolve the `{workflow}` segment of the save/delete-place API routes. The
     * editor builds those URLs from the HANDLE (a workflow without `metadata.nav`
     * has no slug); the slug is still accepted as a fallback, since these routes
     * used to be keyed by it.
     */
    private function workflowByHandleOrSlug(string $key, DefinitionLoader $loader): ?string
    {
        return in_array($key, $loader->allIncludingInactive(), true)
            ? $key
            : $this->workflowBySlug($key, $loader);
    }

    /**
     * Subject ids of the entries in the given collections that actually carry
     * data in this workflow. Gated on a stored state rather than mere collection
     * membership: a secondary workflow (e.g. translation on `posts`) only governs
     * entries that were explicitly pulled into it, so counting every entry of the
     * collection would overstate what removing it destroys.
     *
     * @param  array<int, string>  $collections
     * @return array<int, string>
     */
    private function subjectIdsInCollections(string $workflow, array $collections): array
    {
        if ($collections === []) {
            return [];
        }

        $states = app(WorkflowStateStore::class)->statesFor($workflow);

        return Entry::query()
            ->whereIn('collection', $collections)
            ->get()
            ->map->id()
            ->filter(fn ($id) => array_key_exists($id, $states))
            ->values()
            ->all();
    }

    /**
     * Erase every trace of the given subjects from a workflow. Irreversible — this
     * caller confirms with the user first (`confirm_collection_removal`).
     *
     * WorkflowDataPurger owns the list of tables, because the CLI prune and the
     * entry-deleted listener erase the same way and a fifth table added here only
     * would leave the other two behind.
     *
     * @param  array<int, string>  $subjectIds
     */
    private function purgeWorkflowData(string $workflow, array $subjectIds): void
    {
        app(WorkflowDataPurger::class)->purge($workflow, $subjectIds);
    }

    /**
     * Strip the auto-injected `pending_revision` place and its two system
     * transitions from a raw definition so the editor never renders them as
     * editable rows.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function stripAutoInjected(array $raw): array
    {
        foreach (WorkflowDefinitionWriter::RESERVED_PLACE_HANDLES as $handle) {
            unset($raw['places'][$handle]);
        }

        foreach (WorkflowDefinitionWriter::RESERVED_TRANSITION_HANDLES as $handle) {
            unset($raw['transitions'][$handle]);
        }

        return $raw;
    }
}
