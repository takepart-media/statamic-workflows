<?php

namespace TakepartMedia\Workflows\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use StatamicRadPack\Runway\Traits\HasRunwayResource;

/**
 * A minimal Runway-backed model for model-subject tests. Lives on the
 * `workflow` connection so it shares the in-memory database (and transactions)
 * with the workflow tables.
 */
class Ticket extends Model
{
    use HasRunwayResource;

    protected $connection = 'workflow';

    protected $guarded = [];
}
