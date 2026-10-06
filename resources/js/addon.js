import WorkflowQueue from './pages/WorkflowQueue.vue'
import WorkflowInstanceDetail from './pages/WorkflowInstanceDetail.vue'
import TranslationInstanceDetail from './pages/TranslationInstanceDetail.vue'
import WorkflowSettings from './pages/WorkflowSettings.vue'
import WorkflowsSettingsIndex from './pages/WorkflowsSettingsIndex.vue'
import RevisionStatusIndex from './fieldtypes/RevisionStatusIndex.vue'
import RevisionStatusFieldtype from './fieldtypes/RevisionStatusFieldtype.vue'
import WorkflowActionsFieldtype from './fieldtypes/WorkflowActionsFieldtype.vue'
import UserHeaderWidget from './components/UserHeaderWidget.vue'
import MyQueueWidget from './components/MyQueueWidget.vue'
import CreatedByMeWidget from './components/CreatedByMeWidget.vue'
import WatchedWidget from './components/WatchedWidget.vue'
import QueueStatsWidget from './components/QueueStatsWidget.vue'
import WorkflowStatusWidget from './components/WorkflowStatusWidget.vue'
import WorkflowsOverviewWidget from './components/WorkflowsOverviewWidget.vue'

Statamic.booting(() => {
    Statamic.$inertia.register('WorkflowsQueue', WorkflowQueue)
    Statamic.$inertia.register('WorkflowsInstance', WorkflowInstanceDetail)
    Statamic.$inertia.register('WorkflowsTranslationInstance', TranslationInstanceDetail)
    Statamic.$inertia.register('WorkflowsSettings', WorkflowSettings)
    Statamic.$inertia.register('WorkflowsSettingsIndex', WorkflowsSettingsIndex)

    // `revision_status` fieldtype: listing-column cell + read-only editor display.
    Statamic.component('revision_status-fieldtype-index', RevisionStatusIndex)
    Statamic.component('revision_status-fieldtype', RevisionStatusFieldtype)

    // `workflow_actions` fieldtype: renders workflow transition buttons inside
    // the entry editor and hides the native publish button for non-privileged
    // users. Panel lives in a blueprint section, driven by preProcess payload.
    Statamic.component('workflow_actions-fieldtype', WorkflowActionsFieldtype)

    // Dashboard widget for `workflows_user_header`, so it can use the CP's own
    // `Avatar` component instead of a Blade-rendered initials circle.
    Statamic.component('workflows-user-header-widget', UserHeaderWidget)

    // The two cross-workflow list widgets. Component widgets so the queue can
    // filter by workflow without reloading the dashboard, and so both cards can
    // share one row component (`PersonalWidgetRow.vue`) — they sit side by side
    // and have to stay identical below the header.
    Statamic.component('workflows-my-queue-widget', MyQueueWidget)
    Statamic.component('workflows-created-by-me-widget', CreatedByMeWidget)
    // The bookmarks: same rows, plus a per-row button to stop watching.
    Statamic.component('workflows-watched-widget', WatchedWidget)

    // The stat row above them. A component widget for the CP's own `Card` /
    // `Heading` / `Description`, which have no Blade equivalent — everything it
    // shows is computed and translated in QueueStats::component().
    Statamic.component('workflows-queue-stats-widget', QueueStatsWidget)

    // The status row: one card per active workflow with a Chart.js doughnut of
    // its state distribution. A component widget because a canvas has to be
    // drawn after mount — there is no server-rendered form of it — and because
    // the card shell is the CP's own `Card`/`Heading`/`Subheading`.
    Statamic.component('workflows-status-widget', WorkflowStatusWidget)

    // "Alle Beiträge": the manager pages' pivot listing across every workflow,
    // as a dashboard card. A component widget because it IS the listing — the
    // same WorkflowListing, search, filters and paging included.
    Statamic.component('workflows-overview-widget', WorkflowsOverviewWidget)
})
