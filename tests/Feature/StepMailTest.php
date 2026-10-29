<?php

use Illuminate\Support\Facades\Mail;
use Statamic\Facades\User;
use TakepartMedia\Workflows\Mail\MailTypes;
use TakepartMedia\Workflows\Mail\WorkflowNotification;
use TakepartMedia\Workflows\Workflow\CustomActions;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowNotifier;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;
use TakepartMedia\Workflows\Workflow\WorkflowTransitioner;
use TakepartMedia\Workflows\Workflow\WorkflowWatchStore;

function mailAction(?string $type = null): array
{
    return ['metadata' => ['actions' => [array_filter(['type' => 'mail', 'mail_type' => $type])]]];
}

it('takes the transition mail before the place mail', function () {
    $raw = $this->minimalWorkflow(overrides: [
        'places' => ['review' => mailAction('reminder')],
        'transitions' => ['submit' => mailAction()],
    ]);

    expect(WorkflowNotifier::stepMail($raw, 'submit'))
        ->toBe(['type' => 'assigned', 'scope' => CustomActions::SCOPE_TRANSITION, 'trigger' => 'submit']);
});

it('falls back to the mail of the place the transition lands in', function () {
    $raw = $this->minimalWorkflow(overrides: ['places' => ['review' => mailAction()]]);

    expect(WorkflowNotifier::stepMail($raw, 'submit'))
        ->toBe(['type' => 'assigned', 'scope' => CustomActions::SCOPE_PLACE, 'trigger' => 'review'])
        ->and(WorkflowNotifier::stepMail($raw, 'approve'))->toBeNull();
});

it('reaches users with an email who did not trigger the step', function () {
    $user = tap(User::make()->id('user-a')->email('a@example.com'))->save();
    $silent = tap(User::make()->id('user-b'))->save();

    expect(WorkflowNotifier::reaches($user, null))->toBeTrue()
        ->and(WorkflowNotifier::reaches($user, 'user-c'))->toBeTrue()
        ->and(WorkflowNotifier::reaches($user, 'user-a'))->toBeFalse()
        ->and(WorkflowNotifier::reaches($silent, null))->toBeFalse()
        ->and(WorkflowNotifier::reaches(null, null))->toBeFalse();
});

it('mails exactly the picked assignees the picker announces', function () {
    config(['workflows.mail_delivery' => 'sync']);
    Mail::fake();

    $this->makeCollection('pages');
    $this->writeWorkflow('simple', $this->minimalWorkflow(overrides: ['places' => ['review' => mailAction()]]));
    $entry = $this->makeEntry('pages', ['title' => 'Post']);
    app(WorkflowStateStore::class)->set($entry->id(), 'simple', 'draft');

    $actor = tap(User::make()->id('actor')->email('actor@example.com'))->save();
    $picked = tap(User::make()->id('picked')->email('picked@example.com'))->save();

    $raw = app(DefinitionLoader::class)->raw('simple');
    app(WorkflowTransitioner::class)->apply($entry, 'simple', 'submit', $raw, 'actor', ['actor', 'picked']);

    Mail::assertSent(WorkflowNotification::class, 1);
    Mail::assertSent(WorkflowNotification::class, fn ($mail) => $mail->hasTo('picked@example.com'));

    expect(WorkflowNotifier::reaches($picked, 'actor'))->toBeTrue()
        ->and(WorkflowNotifier::reaches($actor, 'actor'))->toBeFalse();
});

it('sends no step mail when the picker switched it off', function () {
    config(['workflows.mail_delivery' => 'sync']);
    Mail::fake();

    $this->makeCollection('pages');
    $this->writeWorkflow('simple', $this->minimalWorkflow(overrides: [
        'places' => ['review' => mailAction()],
        'transitions' => ['submit' => mailAction()],
    ]));
    $entry = $this->makeEntry('pages', ['title' => 'Post']);
    app(WorkflowStateStore::class)->set($entry->id(), 'simple', 'draft');

    tap(User::make()->id('picked')->email('picked@example.com'))->save();

    $raw = app(DefinitionLoader::class)->raw('simple');
    app(WorkflowTransitioner::class)->apply($entry, 'simple', 'submit', $raw, 'actor', ['picked'], notify: false);

    Mail::assertNothingSent();
});

it('mails the published type to the watchers', function () {
    config(['workflows.mail_delivery' => 'sync']);
    Mail::fake();

    $this->makeCollection('pages');
    $this->writeWorkflow('simple', $this->minimalWorkflow(overrides: ['transitions' => ['submit' => mailAction('published')]]));
    $entry = $this->makeEntry('pages', ['title' => 'Post']);
    app(WorkflowStateStore::class)->set($entry->id(), 'simple', 'draft');

    tap(User::make()->id('actor')->email('actor@example.com'))->save();
    tap(User::make()->id('assignee')->email('assignee@example.com'))->save();
    tap(User::make()->id('watcher')->email('watcher@example.com'))->save();
    $watches = app(WorkflowWatchStore::class);
    $watches->watch('watcher', $entry->id(), 'simple');
    $watches->watch('actor', $entry->id(), 'simple');

    $raw = app(DefinitionLoader::class)->raw('simple');
    app(WorkflowTransitioner::class)->apply($entry, 'simple', 'submit', $raw, 'actor', ['assignee']);

    // The assignee watches too (assigning makes a watcher); the actor, who
    // watches as well, gets no mail about their own click.
    Mail::assertSent(WorkflowNotification::class, 2);
    Mail::assertSent(WorkflowNotification::class, fn ($mail) => $mail->hasTo('watcher@example.com'));
    Mail::assertSent(WorkflowNotification::class, fn ($mail) => $mail->hasTo('assignee@example.com'));
    Mail::assertNotSent(WorkflowNotification::class, fn ($mail) => $mail->hasTo('actor@example.com'));

    expect(MailTypes::recipients('published'))->toBe(MailTypes::TO_WATCHERS)
        ->and(MailTypes::recipients('assigned'))->toBe(MailTypes::TO_ASSIGNEES)
        ->and(WorkflowNotifier::mailsAssignees($raw, 'submit'))->toBeFalse();
});
