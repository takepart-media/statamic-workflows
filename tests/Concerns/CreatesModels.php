<?php

namespace TakepartMedia\Workflows\Tests\Concerns;

use Illuminate\Support\Facades\Schema;
use StatamicRadPack\Runway\Runway;
use TakepartMedia\Workflows\Tests\Support\Ticket;

/**
 * Sets up the `ticket` Runway resource and its table for model-subject tests.
 */
trait CreatesModels
{
    /**
     * Create the tickets table and register the `ticket` resource.
     */
    protected function setUpTickets(): void
    {
        Schema::connection('workflow')->create('tickets', function ($table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Runway::clearRegisteredResources();
        Runway::registerResource(Ticket::class, ['handle' => 'ticket', 'name' => 'Tickets', 'title_field' => 'title']);
        Runway::discoverResources();
    }

    /**
     * A saved ticket.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeTicket(array $attributes = []): Ticket
    {
        return Ticket::create($attributes + ['title' => 'Ticket']);
    }

    /**
     * A workflow governing tickets instead of a collection.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function ticketWorkflow(string $name = 'tickets', array $overrides = []): array
    {
        $definition = $this->minimalWorkflow($name, $overrides);
        $definition['collections'] = [];
        $definition['runway'] = ['ticket'];

        return $definition;
    }
}
