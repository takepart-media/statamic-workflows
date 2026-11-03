<?php

namespace TakepartMedia\Workflows;

use Statamic\Facades\CP\Nav;
use Statamic\Facades\User;
use Statamic\Providers\AddonServiceProvider;
use TakepartMedia\Workflows\Console\AutoTransitionCommand;
use TakepartMedia\Workflows\Console\BackfillContentTimestampCommand;
use TakepartMedia\Workflows\Console\BackfillPlainLanguageCommand;
use TakepartMedia\Workflows\Console\DumpWorkflowCommand;
use TakepartMedia\Workflows\Console\InstallCommand;
use TakepartMedia\Workflows\Console\MakeWorkflowCommand;
use TakepartMedia\Workflows\Console\PollPlainLanguageCommand;
use TakepartMedia\Workflows\Console\PruneStatesCommand;
use TakepartMedia\Workflows\Console\SeedStatesCommand;
use TakepartMedia\Workflows\Console\SendPlaceRemindersCommand;
use TakepartMedia\Workflows\Console\TransitionAgedPostsCommand;
use TakepartMedia\Workflows\Console\UndoAutoTransitionCommand;
use TakepartMedia\Workflows\Support\ActivationBroker;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;

class ServiceProvider extends AddonServiceProvider
{
    protected $commands = [
        AutoTransitionCommand::class,
        BackfillContentTimestampCommand::class,
        BackfillPlainLanguageCommand::class,
        DumpWorkflowCommand::class,
        InstallCommand::class,
        MakeWorkflowCommand::class,
        PollPlainLanguageCommand::class,
        PruneStatesCommand::class,
        SeedStatesCommand::class,
        SendPlaceRemindersCommand::class,
        TransitionAgedPostsCommand::class,
        UndoAutoTransitionCommand::class,
    ];

    protected $widgets = [
        \TakepartMedia\Workflows\Widgets\AssignedPosts::class,
        \TakepartMedia\Workflows\Widgets\CreatedByMe::class,
        \TakepartMedia\Workflows\Widgets\MyQueue::class,
        \TakepartMedia\Workflows\Widgets\QueueStats::class,
        \TakepartMedia\Workflows\Widgets\UserHeader::class,
        \TakepartMedia\Workflows\Widgets\Watched::class,
        \TakepartMedia\Workflows\Widgets\WorkflowStatus::class,
        \TakepartMedia\Workflows\Widgets\WorkflowsOverview::class,
    ];

    protected $listen = [
        // Only a super admin may set `content_updated_at` by hand — enforced on the
        // way in, because the blueprint's read-only rendering is a client-side
        // courtesy and the SLA reads this field.
        \Statamic\Events\EntrySaving::class => [
            \TakepartMedia\Workflows\Listeners\ProtectContentTimestamp::class,
        ],
        \Statamic\Events\EntrySaved::class => [
            \TakepartMedia\Workflows\Listeners\SyncWorkflowOnUnpublish::class,
            // A post that went to draft takes its other versions with it — into
            // draft and into the place their own workflow names. Ordered after
            // the listener above so the post's own state is settled first.
            \TakepartMedia\Workflows\Listeners\CascadeSourceDraft::class,
            // Records a `draft_save` History row for a save that changed something.
            // Drafts take this path; a published entry's edits go to a working copy
            // and are logged from SyncWorkflowOnRevision instead.
            \TakepartMedia\Workflows\Listeners\LogDraftSave::class,
        ],
        // A deleted entry takes its workflow rows with it — state, assignments,
        // history, comments, outstanding plain-language jobs. Nothing in the CP
        // could reach them afterwards, so they would only accumulate.
        \Statamic\Events\EntryDeleted::class => [
            \TakepartMedia\Workflows\Listeners\PurgeWorkflowDataOnEntryDeleted::class,
        ],
        // Move between the publish place and `pending_revision` as the entry's
        // working copy is created and published (revision-enabled collections).
        \Statamic\Events\RevisionSaved::class => [
            \TakepartMedia\Workflows\Listeners\SyncWorkflowOnRevision::class,
        ],
        \Statamic\Events\RevisionDeleted::class => [
            \TakepartMedia\Workflows\Listeners\SyncWorkflowOnRevision::class,
        ],
        // Inject the addon's own fields — the `workflow_actions` panel and the
        // `content_updated_at` clock — into every entry blueprint whose collection
        // is governed by a workflow. Keeps the addon self-contained.
        \Statamic\Events\EntryBlueprintFound::class => [
            \TakepartMedia\Workflows\Listeners\InjectWorkflowFields::class,
        ],
    ];

    protected $policies = [
        \Statamic\Contracts\Entries\Entry::class => \TakepartMedia\Workflows\Policies\EntryPolicy::class,
        \Statamic\Entries\Entry::class => \TakepartMedia\Workflows\Policies\EntryPolicy::class,
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $vite = [
        'input' => [
            'resources/js/addon.js',
            'resources/css/addon.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    public function register()
    {
        parent::register();

        // Merge our defaults so the config keys resolve even without the
        // project publishing `config/workflows.php`. `publishes()` in bootAddon
        // then lets projects override any of them.
        $this->mergeConfigFrom(__DIR__.'/../config/workflows.php', 'workflows');

        $this->defaultDashboardWidgets();

        // Scoped, not a plain resolve: AssignmentCandidates memoises the user
        // filtering, and a fresh instance per app() call would throw that away —
        // the instance page resolves the same action twice per transition (once
        // for `can_apply`, once for `pickable_assignees`), each time over the
        // full user list. Scoped rather than singleton so the memo cannot survive
        // a request under Octane.
        $this->app->scoped(\TakepartMedia\Workflows\Workflow\AssignmentCandidates::class);

        // Same reason, for the dashboard. These three memoise per request, and
        // every caller reaches them through `app(...)` — which, unbound, news up
        // a fresh instance on each call and would throw the memo away between
        // two widgets. The dashboard renders six widgets that ask the same
        // questions: the same row set twice (PersonalWorkItems), one workflow's
        // whole state map up to three times (WorkflowStateStore::statesFor), and
        // the per-workflow event aggregates in both the queue rows and the stats
        // cards (WorkflowEventStore). Scoped rather than singleton for the same
        // reason as above, and because these memos may not survive a write: each
        // store drops the affected workflow in its write methods, which only
        // holds within one request.
        $this->app->scoped(\TakepartMedia\Workflows\Workflow\PersonalWorkItems::class);
        $this->app->scoped(\TakepartMedia\Workflows\Workflow\WorkflowStateStore::class);
        $this->app->scoped(\TakepartMedia\Workflows\Workflow\WorkflowEventStore::class);

        // Dedicated, isolated database for the workflow engine — pulled from
        // config so projects may switch it to their primary connection.
        config([
            'database.connections.workflow' => config('workflows.database'),
        ]);

        // The workflow engine writes a Statamic revision on every transition.
        // Turn the global kill-switch on when opted-in via config. Each
        // collection still needs `revisions: true` itself (the editor warns
        // about a governed one without — DefinitionLinter), and revision
        // creation still requires a Statamic Pro license.
        if (config('workflows.revisions', true) && config('statamic.revisions.enabled') !== false) {
            config(['statamic.revisions.enabled' => true]);
        }
    }

    /**
     * Fill the CP dashboard with `workflows.dashboard_widgets` when the project
     * configures no widgets of its own (`statamic.cp.widgets` empty, which is
     * Statamic's default). A non-empty project list always wins and nothing is
     * merged into it; `dashboard_widgets => false` turns the fallback off.
     *
     * Runs in register() so the list is in place before CoreNav decides
     * whether to show the Dashboard nav item (it only does with widgets).
     * Per-user/role widget preferences still take precedence over both.
     */
    private function defaultDashboardWidgets(): void
    {
        $defaults = config('workflows.dashboard_widgets');

        if (! is_array($defaults) || $defaults === [] || ! empty(config('statamic.cp.widgets'))) {
            return;
        }

        config(['statamic.cp.widgets' => $defaults]);
    }

    public function bootAddon()
    {
        $this->ensureDatabaseExists();

        ActivationBroker::register();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/workflows.php' => config_path('workflows.php'),
        ], 'workflows-config');

        $this->bootNav();
    }

    /**
     * The super-admin "Settings > Workflows" CP nav entry, with one child per
     * workflow that declares `metadata.nav`.
     *
     * The addon has no nav section of its own any more: the per-workflow
     * managers are reached from the dashboard's workflow widget (and stay
     * reachable by URL under `workflows/{slug}`), so only the settings entry
     * is registered here.
     *
     * Registered in `Nav::extend` rather than eagerly, for three reasons:
     * `cp_route()` throws at boot (Statamic loads its routes on the line AFTER
     * `Statamic::runBootedCallbacks()`, and addon routes even later), the CP
     * locale is not set until the `statamic.cp.authenticated` middleware group
     * so `navLabel()` would resolve in the app default instead of the user's
     * language, and `NavBuilder` edits the NavItem objects it is handed IN PLACE
     * (`@hide`, `@modify`) -- objects that would then outlive the build and leak
     * into the next of the 3+ builds a CP request performs, which do not all use
     * the same preferences.
     *
     * Keeping the workflow-dependent work in `Nav::extend` also keeps it off the
     * public frontend entirely: those bodies only run from `Nav::build()`, so a
     * frontend request still reads zero workflow YAMLs.
     */
    private function bootNav(): void
    {
        Nav::extend(function ($nav) {
            // Super admins get a "Workflows" entry inside the built-in
            // "Settings" section (alongside Sites, Preferences etc.). Clicking
            // the parent lands on an index page; children link straight to each
            // workflow's settings editor. `Nav::settings(...)` maps via __call
            // to findOrCreate('Settings', 'Workflows'), and appending to a
            // section that already exists does not move it.
            //
            // The gate has to be checked here rather than through an eager
            // `can()`: User::current() does not exist at boot, and "super admin"
            // cannot be expressed as an ability at all, because the Gate only
            // grants the super bypass for registered permissions.
            if (! (User::current()?->isSuper() ?? false)) {
                return;
            }

            $loader = app(DefinitionLoader::class);

            // Workflows that opt into the nav via `metadata.nav`, in declared
            // order. Not cached -- raw() reads from disk every time, on purpose
            // (see DefinitionLoader).
            $workflowNavs = collect($loader->all())
                // Keyed by handle: the settings editor's URL is the handle, the
                // nav metadata only supplies label, icon and order.
                ->mapWithKeys(fn (string $name) => [$name => $loader->raw($name)['metadata']['nav'] ?? null])
                // A nav entry needs a slug for its URL and a label to show. The
                // label may come from either side of navLabel(): the explicitly
                // configured one, or the slug's translation when that field is
                // left empty. Filtering on the RESOLVED value rather than on
                // `$meta['label']` is what lets someone clear "Nav-Titel" to
                // hand the label back to the translations, instead of the entry
                // vanishing from the menu entirely.
                ->filter(fn ($meta) => is_array($meta)
                    && ! empty($meta['slug'])
                    && DefinitionLoader::navLabel($meta) !== '')
                ->sortBy(fn ($meta) => $meta['order'] ?? PHP_INT_MAX);

            // Fresh objects on every build, so NavBuilder's in-place edits
            // cannot leak between builds.
            $settingsChildren = $workflowNavs
                ->map(fn ($meta, $name) => Nav::item(DefinitionLoader::navLabel($meta))
                    ->route('workflows.settings.edit', ['workflow' => $name])
                    ->icon($meta['icon'] ?? 'workflow'))
                ->values();

            $nav->settings('Workflows')
                ->route('workflows.settings.index')
                ->icon('workflow')
                ->children($settingsChildren->all());
        });
    }

    /**
     * Register scheduled tasks with the Laravel scheduler. Statamic's
     * AddonServiceProvider calls this from bootSchedule() guarded by
     * runningInConsole(). Requires `php artisan schedule:run` on cron in the
     * host project — see docs/en/commands.md.
     */
    public function schedule(\Illuminate\Console\Scheduling\Schedule $schedule): void
    {
        if (config('workflows.sla_scheduler', true)) {
            $schedule->command('workflows:transition-aged')
                ->hourly()
                ->onOneServer()
                ->withoutOverlapping();
        }

        // Once a day rather than hourly: a reminder counted in days gains nothing
        // from a finer check, and a fixed hour keeps the mails out of the night.
        // Same switch and time as the reminders, and registered FIRST: the
        // scheduler runs due events in order, so a post that moves on today is
        // no longer in the place its reminder would talk about.
        if (config('workflows.reminder_scheduler', true)) {
            $schedule->command('workflows:auto-transition')
                ->dailyAt((string) config('workflows.reminder_time', '08:00'))
                ->onOneServer()
                ->withoutOverlapping();

            $schedule->command('workflows:send-reminders')
                ->dailyAt((string) config('workflows.reminder_time', '08:00'))
                ->onOneServer()
                ->withoutOverlapping();
        }

        // The plain-language service finishes its work asynchronously, so somebody has
        // to collect the results — see PollPlainLanguageCommand. Every minute because
        // that latency is what an editor waits out; the command returns immediately
        // when the job table is empty, which is the normal case.
        if (config('workflows.translation.plain_language.poll_scheduler', true)) {
            $schedule->command('workflows:plain-language-poll')
                ->everyMinute()
                ->onOneServer()
                ->withoutOverlapping();
        }
    }

    /**
     * Provision the dedicated workflow SQLite file if it is missing, so the
     * `workflow` connection and `php artisan migrate` work out of the box.
     */
    private function ensureDatabaseExists(): void
    {
        $path = config('database.connections.workflow.database');

        // `:memory:` (the test suite's connection) has no file to provision —
        // touching it would leave a stray file named `:memory:` in the cwd.
        if (! $path || $path === ':memory:' || file_exists($path)) {
            return;
        }

        if (! is_dir($dir = dirname($path))) {
            @mkdir($dir, 0755, true);
        }

        touch($path);
    }
}
