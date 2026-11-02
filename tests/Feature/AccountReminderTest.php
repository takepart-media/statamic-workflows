<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\User;
use TakepartMedia\Workflows\Mail\AccountReminderMail;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\DefinitionValidationException;
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
    $this->entry = $this->makeEntry('pages', ['title' => 'Post']);
    app(WorkflowStateStore::class)->set($this->entry->id(), 'simple', 'draft');

    tap(User::make()->id('actor')->email('actor@example.com'))->save();
    tap(User::make()->id('pending')->email('pending@example.com'))->save();
    tap(User::make()->id('active')->email('active@example.com')->password('secret'))->save();
    tap(User::make()->id('outsider')->email('outsider@example.com'))->save();
});

function withAccountReminder(int $count = 1): array
{
    return ['metadata' => ['actions' => array_fill(0, $count, ['type' => 'account_reminder'])]];
}

it('reminds only assignees who never activated their account', function () {
    $this->writeWorkflow('simple', $this->minimalWorkflow(overrides: ['transitions' => ['submit' => withAccountReminder()]]));

    $raw = app(DefinitionLoader::class)->raw('simple');
    app(WorkflowTransitioner::class)->apply($this->entry, 'simple', 'submit', $raw, 'actor', ['pending', 'active']);

    Mail::assertSent(AccountReminderMail::class, 1);
    Mail::assertSent(AccountReminderMail::class, fn ($mail) => $mail->hasTo('pending@example.com')
        && str_contains($mail->url, 'activate'));

    Mail::assertSent(AccountReminderMail::class, fn ($mail) => str_contains($mail->render(), 'Post'));
});

it('leaves out the actor even when their account is not activated', function () {
    User::find('actor')->password(null)->save();
    $this->writeWorkflow('simple', $this->minimalWorkflow(overrides: ['transitions' => ['submit' => withAccountReminder()]]));

    $raw = app(DefinitionLoader::class)->raw('simple');
    app(WorkflowTransitioner::class)->apply($this->entry, 'simple', 'submit', $raw, 'actor', ['actor']);

    Mail::assertNothingSent();
});

it('sends nothing without the action', function () {
    $this->writeWorkflow('simple', $this->minimalWorkflow());

    $raw = app(DefinitionLoader::class)->raw('simple');
    app(WorkflowTransitioner::class)->apply($this->entry, 'simple', 'submit', $raw, 'actor', ['pending']);

    Mail::assertNotSent(AccountReminderMail::class);
});

it('saves one reminder per transition and refuses a second', function () {
    $this->writeWorkflow('simple', $this->minimalWorkflow());
    $raw = app(DefinitionLoader::class)->raw('simple');
    $writer = app(WorkflowDefinitionWriter::class);

    $raw['transitions']['submit']['metadata']['actions'] = withAccountReminder()['metadata']['actions'];
    $writer->save('simple', $raw);

    expect(app(DefinitionLoader::class)->raw('simple')['transitions']['submit']['metadata']['actions'])
        ->toBe([['type' => 'account_reminder']]);

    $raw['transitions']['submit']['metadata']['actions'] = withAccountReminder(2)['metadata']['actions'];

    expect(fn () => $writer->save('simple', $raw))->toThrow(DefinitionValidationException::class);
});
