<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\User;
use TakepartMedia\Workflows\Mail\AccountReminderMail;
use TakepartMedia\Workflows\Support\ActivationBroker;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowDefinitionWriter;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;
use TakepartMedia\Workflows\Workflow\WorkflowTransitioner;

beforeEach(function () {
    config(['workflows.mail_delivery' => 'sync']);
    Mail::fake();

    // Laravel's token table — the activation link is stored there.
    Schema::create('password_reset_tokens', function (Blueprint $table) {
        $table->string('email')->primary();
        $table->string('token');
        $table->timestamp('created_at')->nullable();
    });

    $this->makeCollection('pages');
    $this->writeWorkflow('simple', $this->minimalWorkflow());
    $this->entry = $this->makeEntry('pages', ['title' => 'Post']);

    tap(User::make()->id('actor')->email('actor@example.com'))->save();
    tap(User::make()->id('pending')->email('pending@example.com'))->save();
    tap(User::make()->id('active')->email('active@example.com')->password('secret'))->save();
    tap(User::make()->id('outsider')->email('outsider@example.com'))->save();
});

function submitTo(array $assignees, string $actor = 'actor'): void
{
    $entry = test()->entry;
    app(WorkflowStateStore::class)->set($entry->id(), 'simple', 'draft');
    $raw = app(DefinitionLoader::class)->raw('simple');
    app(WorkflowTransitioner::class)->apply($entry, 'simple', 'submit', $raw, $actor, $assignees);
}

it('reminds unactivated assignees on every transition, without any action', function () {
    submitTo(['pending', 'active']);

    Mail::assertSent(AccountReminderMail::class, 1);
    Mail::assertSent(AccountReminderMail::class, fn ($mail) => $mail->hasTo('pending@example.com')
        && str_contains($mail->url, 'activate'));
    // Test broker: Laravel's default, 60 minutes.
    Mail::assertSent(AccountReminderMail::class, fn ($mail) => str_contains($mail->render(), 'Post')
        && str_contains($mail->render(), '1 hour'));
});

it('leaves out the actor even when their account is not activated', function () {
    User::find('actor')->password(null)->save();

    submitTo(['actor']);

    Mail::assertNothingSent();
});

it('can be switched off', function () {
    config(['workflows.account_reminder.enabled' => false]);

    submitTo(['pending']);

    Mail::assertNotSent(AccountReminderMail::class);
});

it('waits until the last link has expired before reminding again', function () {
    submitTo(['pending']);
    submitTo(['pending']);

    Mail::assertSent(AccountReminderMail::class, 1);

    $this->travel(ActivationBroker::expireMinutes() + 1)->minutes();
    submitTo(['pending']);

    Mail::assertSent(AccountReminderMail::class, 2);
});

it('keeps Statamic\'s link duration unless the addon sets one', function () {
    $before = config('statamic.users.passwords.activations');

    ActivationBroker::register();

    expect(config('statamic.users.passwords.activations'))->toBe($before)
        ->and(ActivationBroker::expireHours())->toBe(1);

    config(['workflows.account_reminder.expire_hours' => 48]);
    ActivationBroker::register();

    expect(ActivationBroker::name())->toBe(ActivationBroker::OWN)
        ->and(config('auth.passwords.'.ActivationBroker::OWN.'.expire'))->toBe(2880)
        ->and(config('auth.passwords.'.ActivationBroker::OWN.'.table'))->toBe('password_reset_tokens');

    submitTo(['pending']);

    Mail::assertSent(AccountReminderMail::class, fn ($mail) => str_contains($mail->render(), '2 days'));
});

it('drops a retired account_reminder action when the definition is saved', function () {
    $raw = app(DefinitionLoader::class)->raw('simple');
    $raw['transitions']['submit']['metadata']['actions'] = [['type' => 'account_reminder']];

    app(WorkflowDefinitionWriter::class)->save('simple', $raw);

    expect(app(DefinitionLoader::class)->raw('simple')['transitions']['submit']['metadata']['actions'] ?? [])->toBe([]);
});
