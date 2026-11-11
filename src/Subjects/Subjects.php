<?php

namespace TakepartMedia\Workflows\Subjects;

use Illuminate\Database\Eloquent\Model;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use StatamicRadPack\Runway\Exceptions\ResourceNotFound;
use StatamicRadPack\Runway\Runway;

/**
 * Resolves a workflow subject id to what it names: a Statamic entry (its UUID)
 * or an Eloquent model behind a Runway resource (`runway::<resource>::<key>`).
 *
 * Entries are returned as they are, not wrapped, so every entry-only path of
 * the addon keeps working unchanged.
 */
class Subjects
{
    public const PREFIX = 'runway::';

    /**
     * The subject behind an id, or null when it does not exist (any more).
     */
    public static function find(?string $id): EntryContract|EloquentSubject|null
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (! self::isModelId($id)) {
            return Entry::find($id);
        }

        [$handle, $key] = explode('::', substr($id, strlen(self::PREFIX)), 2) + [null, null];
        $resource = class_exists(Runway::class) && $handle && Runway::hasResource($handle) ? Runway::findResource($handle) : null;
        $model = $resource && $key !== null ? $resource->model()->newQuery()->find($key) : null;

        return $model ? new EloquentSubject($model, $resource) : null;
    }

    /**
     * Wrap a model of a Runway resource.
     */
    public static function forModel(Model $model): ?EloquentSubject
    {
        if (! class_exists(Runway::class)) {
            return null;
        }

        try {
            return new EloquentSubject($model, Runway::findResourceByModel($model));
        } catch (ResourceNotFound) {
            return null;
        }
    }

    /**
     * Whether an id names a model rather than an entry.
     */
    public static function isModelId(string $id): bool
    {
        return str_starts_with($id, self::PREFIX);
    }

    /**
     * Whether a subject is a model rather than an entry.
     */
    public static function isModel(mixed $subject): bool
    {
        return $subject instanceof EloquentSubject;
    }
}
