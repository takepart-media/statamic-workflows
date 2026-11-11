<?php

namespace TakepartMedia\Workflows\Subjects;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use StatamicRadPack\Runway\Resource;

/**
 * An Eloquent model governed by a workflow, exposed in the CP as a Runway
 * resource.
 *
 * Its id is Runway's own reference (`runway::<resource>::<key>`), so every
 * workflow table keeps keying on one string `subject_id` and an entry's UUID can
 * never collide with it. The methods mirror the handful of Entry methods the
 * workflow core reads (`id()`, `value()`, `fresh()`, …); everything that only
 * makes sense for entries — sites, revisions, localizations, the publish flag —
 * is simply absent, and the core checks for it.
 */
class EloquentSubject
{
    public function __construct(
        private Model $model,
        private readonly Resource $resource,
    ) {}

    /**
     * The workflow subject id: Runway's reference of the model.
     */
    public function id(): string
    {
        return Subjects::PREFIX.$this->resource->handle().'::'.$this->model->getKey();
    }

    /**
     * The wrapped model.
     */
    public function model(): Model
    {
        return $this->model;
    }

    /**
     * The Runway resource the model belongs to.
     */
    public function resource(): Resource
    {
        return $this->resource;
    }

    /**
     * The Runway resource handle — what a workflow lists under `runway:`.
     */
    public function resourceHandle(): string
    {
        return $this->resource->handle();
    }

    /**
     * A model attribute, read like an entry field.
     */
    public function value(string $key): mixed
    {
        $value = $this->model->getAttribute($key);

        // Mails, widgets and the instance page read `title` like an entry field.
        return $key === 'title' && ($value === null || $value === '') ? $this->title() : $value;
    }

    /**
     * Stand-in for an entry slug, where the core falls back to one.
     */
    public function slug(): string
    {
        return Str::slug($this->title());
    }

    /**
     * Alias of value(), as entries offer both.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->model->getAttribute($key) ?? $default;
    }

    /**
     * The subject reloaded from the database, or null when it is gone.
     */
    public function fresh(): ?self
    {
        $fresh = $this->model->fresh();

        return $fresh === null ? null : new self($fresh, $this->resource);
    }

    /**
     * The display title: Runway's title field, else the key.
     */
    public function title(): string
    {
        $field = $this->resource->titleField();
        $title = $field ? $this->model->getAttribute($field) : null;

        return is_scalar($title) && (string) $title !== ''
            ? (string) $title
            : $this->resource->singular().' '.$this->model->getKey();
    }

    /**
     * The model's edit page in the CP, or null while Runway's CP routes are not
     * registered.
     */
    public function editUrl(): ?string
    {
        if (! Route::has('statamic.cp.runway.edit')) {
            return null;
        }

        return cp_route('runway.edit', [
            'resource' => $this->resource->handle(),
            'model' => $this->model->getRouteKey(),
        ]);
    }

    /**
     * Last change of the model, when it keeps timestamps.
     */
    public function lastModified(): ?CarbonInterface
    {
        $column = $this->model->getUpdatedAtColumn();

        return $column ? $this->model->getAttribute($column) : null;
    }

    /**
     * Models carry no site; the CP's site checks do not apply to them.
     */
    public function locale(): ?string
    {
        return null;
    }

    /**
     * Whether the user may edit the model — Runway's own permission.
     */
    public function editableBy($user): bool
    {
        return $user !== null && ! $this->resource->readOnly()
            && ($user->isSuper() || $user->hasPermission('edit '.$this->resource->handle()));
    }

    /**
     * Whether the user may see the model — Runway's own permission.
     */
    public function viewableBy($user): bool
    {
        return $user !== null
            && ($user->isSuper() || $user->hasPermission('view '.$this->resource->handle()));
    }
}
