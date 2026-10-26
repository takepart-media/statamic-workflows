<?php

use TakepartMedia\Workflows\Workflow\PersonalWorkItems;

it('lists the posts whose author field names the user', function () {
    $this->makeCollection('pages');
    $this->writeWorkflow('simple', $this->minimalWorkflow());

    $this->makeEntry('pages', ['title' => 'Single author', 'author' => 'user-a']);
    $this->makeEntry('pages', ['title' => 'Co-authored', 'author' => ['user-b', 'user-a']]);
    $this->makeEntry('pages', ['title' => 'Someone else', 'author' => 'user-b']);
    $this->makeEntry('pages', ['title' => 'No author']);

    $titles = fn (string $user) => app(PersonalWorkItems::class)
        ->authoredBy($user)
        ->pluck('title')
        ->sort()
        ->values()
        ->all();

    expect($titles('user-a'))->toBe(['Co-authored', 'Single author'])
        ->and($titles('user-b'))->toBe(['Co-authored', 'Someone else'])
        ->and($titles('user-c'))->toBe([]);
});
