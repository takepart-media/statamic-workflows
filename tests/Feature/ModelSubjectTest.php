<?php

use Illuminate\Support\Facades\Event;
use TakepartMedia\Workflows\Events\AssigneesChanged;
use TakepartMedia\Workflows\Events\TransitionApplied;
use TakepartMedia\Workflows\Events\TransitionApplying;
use TakepartMedia\Workflows\Subjects\EloquentSubject;
use TakepartMedia\Workflows\Subjects\Subjects;
use TakepartMedia\Workflows\Tests\Concerns\CreatesModels;
use TakepartMedia\Workflows\Tests\Support\TitleGuard;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\TransitionBlocked;
use TakepartMedia\Workflows\Workflow\WorkflowAssigneeStore;
use TakepartMedia\Workflows\Workflow\WorkflowEventStore;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;
use TakepartMedia\Workflows\Workflow\WorkflowTransitioner;

/*
 * Eloquent models behind a Runway resource as workflow subjects: id, lookup,
 * binding via `runway:` and a transition end to end.
 */

uses(CreatesModels::class);

beforeEach(function () {
    config()->set('workflows.mail_delivery', 'sync');
    $this->setUpTickets();
    $this->writeWorkflow('tickets', $this->ticketWorkflow());
    $this->ticket = $this->makeTicket(['title' => 'Broken printer']);
});

it('identifies a model by its Runway reference and finds it again', function () {
    $subject = Subjects::forModel($this->ticket);

    expect($subject->id())->toBe('runway::ticket::'.$this->ticket->id)
        ->and($subject->id())->toBe($this->ticket->reference())
        ->and(Subjects::find($subject->id()))->toBeInstanceOf(EloquentSubject::class)
        ->and(Subjects::find($subject->id())->model()->is($this->ticket))->toBeTrue()
        ->and(Subjects::find('runway::ticket::999'))->toBeNull()
        ->and(Subjects::find('runway::unknown::1'))->toBeNull()
        ->and($subject->value('title'))->toBe('Broken printer');
});

it('binds the workflow listing the resource under runway', function () {
    $resolver = app(WorkflowResolver::class);
    $subject = Subjects::forModel($this->ticket);

    expect($resolver->forResource('ticket'))->toBe('tickets')
        ->and($resolver->forEntry($subject))->toBe('tickets')
        ->and($resolver->forCollection('pages'))->toBeNull()
        ->and($resolver->stateFor($subject, 'tickets'))->toBe('draft');
});

it('applies a transition to a model and logs it under the subject id', function () {
    $subject = Subjects::forModel($this->ticket);
    $raw = app(DefinitionLoader::class)->raw('tickets');

    $to = app(WorkflowTransitioner::class)->apply($subject, 'tickets', 'submit', $raw, actorId: 'someone');

    expect($to)->toBe('review')
        ->and(app(WorkflowStateStore::class)->get($subject->id(), 'tickets'))->toBe('review')
        ->and(app(WorkflowEventStore::class)->forSubject($subject->id(), 'tickets')->last()?->to_state)->toBe('review');
});

it('mirrors the place into the model attribute the workflow names', function () {
    $this->writeWorkflow('tickets', $this->ticketWorkflow(overrides: ['metadata' => ['state_attribute' => 'status']]));
    $subject = Subjects::forModel($this->ticket);

    app(WorkflowTransitioner::class)->apply($subject, 'tickets', 'submit', app(DefinitionLoader::class)->raw('tickets'));

    expect($this->ticket->fresh()->status)->toBe('review');
});

it('rolls the whole step back when a listener refuses it', function () {
    $this->writeWorkflow('tickets', $this->ticketWorkflow(overrides: ['metadata' => ['state_attribute' => 'status']]));
    $subject = Subjects::forModel($this->ticket);
    Event::listen(TransitionApplied::class, fn () => throw new RuntimeException('invariant'));

    expect(fn () => app(WorkflowTransitioner::class)->apply($subject, 'tickets', 'submit', app(DefinitionLoader::class)->raw('tickets')))
        ->toThrow(RuntimeException::class, 'invariant');

    expect(app(WorkflowStateStore::class)->get($subject->id(), 'tickets'))->toBeNull()
        ->and($this->ticket->fresh()->status)->toBeNull()
        ->and(app(WorkflowEventStore::class)->forSubject($subject->id(), 'tickets'))->toHaveCount(0);
});

it('lets a guard veto a transition before anything is written', function () {
    config()->set('workflows.actions', ['title_guard' => ['label' => 'Title', 'scope' => 'transition', 'guard' => TitleGuard::class]]);
    $this->writeWorkflow('tickets', $this->ticketWorkflow(overrides: ['transitions' => ['submit' => ['metadata' => ['actions' => [['type' => 'title_guard']]]]]]));
    $this->ticket->update(['status' => 'blocked']);
    $subject = Subjects::forModel($this->ticket->fresh());
    $raw = app(DefinitionLoader::class)->raw('tickets');

    expect(app(WorkflowTransitioner::class)->blocker($subject, 'tickets', 'submit', $raw))->toBe('Ticket is blocked.')
        ->and(fn () => app(WorkflowTransitioner::class)->apply($subject, 'tickets', 'submit', $raw))
        ->toThrow(TransitionBlocked::class, 'Ticket is blocked.')
        ->and(app(WorkflowStateStore::class)->get($subject->id(), 'tickets'))->toBeNull();
});

it('announces new assignees', function () {
    Event::fake([AssigneesChanged::class]);

    app(WorkflowAssigneeStore::class)->set('runway::ticket::1', 'tickets', ['a', 'b']);

    Event::assertDispatched(AssigneesChanged::class, fn ($e) => $e->userIds === ['a', 'b'] && $e->previous === []);
});

/**
 * A super admin, saved and reloaded.
 */
function modelSubjectAdmin()
{
    $user = Statamic\Facades\User::make()->email('admin@example.com')->set('name', 'Admin')->makeSuper();
    $user->save();

    return Statamic\Facades\User::find($user->id());
}

it('renders the instance page for a model', function () {
    $subject = Subjects::forModel($this->ticket);

    $page = $this->actingAs(modelSubjectAdmin())
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->get(cp_route('workflows.show', ['id' => $subject->id(), 'workflow' => 'tickets']))
        ->assertOk()
        ->json();

    expect($page['props']['instance']['subject'])->toMatchArray(['kind' => 'model', 'title' => 'Broken printer', 'site' => null])
        ->and($page['props']['instance']['current_state'])->toBe('draft')
        ->and(collect($page['props']['actions'])->pluck('transition.name')->all())->toContain('submit');
});

it('applies a transition to a model over the API and reports a guard veto', function () {
    config()->set('workflows.actions', ['title_guard' => ['label' => 'Title', 'scope' => 'transition', 'guard' => TitleGuard::class]]);
    $this->writeWorkflow('tickets', $this->ticketWorkflow(overrides: ['transitions' => ['approve' => ['metadata' => ['actions' => [['type' => 'title_guard']]]]]]));
    $subject = Subjects::forModel($this->ticket);
    $url = fn (string $name) => cp_route('workflows.api.apply', ['id' => $subject->id(), 'name' => $name, 'workflow' => 'tickets']);
    $admin = modelSubjectAdmin();

    $this->actingAs($admin)->postJson($url('submit'))->assertOk();
    expect(app(WorkflowStateStore::class)->get($subject->id(), 'tickets'))->toBe('review');

    $this->ticket->update(['status' => 'blocked']);
    $this->actingAs($admin)->postJson($url('approve'))->assertForbidden()->assertJsonPath('message', 'Ticket is blocked.');
    expect(app(WorkflowStateStore::class)->get($subject->id(), 'tickets'))->toBe('review');
});

it('serves the other instance endpoints for a model', function () {
    $subject = Subjects::forModel($this->ticket);
    $admin = modelSubjectAdmin();
    $params = ['id' => $subject->id(), 'workflow' => 'tickets'];

    $this->actingAs($admin)->getJson(cp_route('workflows.api.actions', $params))->assertOk();
    $this->actingAs($admin)->getJson(cp_route('workflows.api.instance.status', $params))->assertOk();
    $this->actingAs($admin)->postJson(cp_route('workflows.api.assignees', $params), ['assignees' => [(string) $admin->id()]])->assertOk();
    $this->actingAs($admin)->postJson(cp_route('workflows.api.comments', $params), ['body' => 'Bitte prüfen', 'passage_ref' => null])->assertSuccessful();
    $this->actingAs($admin)->postJson(cp_route('workflows.api.watch', $params))->assertSuccessful();
    $this->actingAs($admin)->postJson(cp_route('workflows.api.state', $params), ['state' => 'published'])->assertOk();

    expect(app(WorkflowAssigneeStore::class)->idsFor($subject->id(), 'tickets'))->toBe([(string) $admin->id()])
        ->and(app(WorkflowStateStore::class)->get($subject->id(), 'tickets'))->toBe('published');
});

it('feeds the workflow panel on a Runway form', function () {
    $field = (new Statamic\Fields\Field('workflow_actions', ['type' => 'workflow_actions']))->setParent($this->ticket);

    $data = $field->fieldtype()->preProcess(null);

    expect($data['entry_id'])->toBe($this->ticket->reference())
        ->and($data['workflow'])->toBe('tickets')
        ->and($data['lock_sites'])->toBeFalse();
});

it('lets a listener do the domain work before the state is written', function () {
    $this->writeWorkflow('tickets', $this->ticketWorkflow(overrides: ['metadata' => ['state_attribute' => 'status']]));
    $subject = Subjects::forModel($this->ticket);
    $seen = null;
    Event::listen(TransitionApplying::class, function (TransitionApplying $e) use (&$seen) {
        $seen = app(WorkflowStateStore::class)->get($e->subject->id(), $e->workflow);
        $e->subject->model()->update(['status' => 'review', 'title' => 'Handled']);
    });

    app(WorkflowTransitioner::class)->apply($subject, 'tickets', 'submit', app(DefinitionLoader::class)->raw('tickets'));

    expect($seen)->toBeNull()
        ->and($this->ticket->fresh()->only(['status', 'title']))->toBe(['status' => 'review', 'title' => 'Handled']);
});

it('purges the workflow data of a deleted model and leaves other models alone', function () {
    $subject = Subjects::forModel($this->ticket);
    $other = Subjects::forModel($this->makeTicket(['title' => 'Other']));
    $raw = app(DefinitionLoader::class)->raw('tickets');
    foreach ([$subject, $other] as $s) {
        app(WorkflowTransitioner::class)->apply($s, 'tickets', 'submit', $raw);
        app(WorkflowAssigneeStore::class)->set($s->id(), 'tickets', ['a']);
    }

    $this->ticket->delete();

    $rows = fn (string $id) => collect([
        TakepartMedia\Workflows\Workflow\WorkflowState::class,
        TakepartMedia\Workflows\Workflow\WorkflowEvent::class,
        TakepartMedia\Workflows\Workflow\WorkflowAssignee::class,
    ])->sum(fn ($model) => $model::query()->where('subject_id', $id)->count());

    expect($rows($subject->id()))->toBe(0)
        ->and($rows($other->id()))->toBe(3);
});
