<?php

namespace TakepartMedia\Workflows\Translation;

use Illuminate\Database\Eloquent\Model;

/**
 * One outstanding plain-language request: a chunk of an entry's segments handed to
 * the service, waiting for its result. Lives in the dedicated `workflow` SQLite
 * connection.
 *
 * The service answers `202 Accepted` with a job id and finishes later, so a run
 * cannot complete inside the request that started it — see PollPlainLanguageCommand
 * for the other half.
 *
 * @property string $subject_id
 * @property string $workflow
 * @property string $text_id
 * @property int $chunk
 * @property array<int, string> $segment_keys
 * @property string $status
 * @property string|null $error_message
 * @property int $attempts
 * @property int $submission
 * @property string|null $format  null = legacy whole document, `sections` = per-leaf
 * @property array<string, mixed>|null $plan  what a `sections` job was sent (PlainLanguageLeafDocument::plan())
 */
class PlainLanguageJob extends Model
{
    /** Statuses the service reports, mirrored verbatim so no mapping can drift. */
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /** Write-back format of a job submitted with leaf sections (PlainLanguageLeafDocument). */
    public const FORMAT_SECTIONS = 'sections';

    /** The two that are still worth polling. */
    public const OPEN = [self::PENDING, self::PROCESSING];

    protected $connection = 'workflow';

    protected $table = 'workflow_plain_language_jobs';

    protected $guarded = [];

    protected $casts = [
        'segment_keys' => 'array',
        'chunk' => 'integer',
        'attempts' => 'integer',
        'submission' => 'integer',
        'plan' => 'array',
    ];
}
