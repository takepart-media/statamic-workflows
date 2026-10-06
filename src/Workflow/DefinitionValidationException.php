<?php

namespace TakepartMedia\Workflows\Workflow;

use InvalidArgumentException;

/**
 * A workflow definition the writer refuses, with WHERE it is wrong.
 *
 * Still an InvalidArgumentException, so every existing `catch` around save()
 * keeps working and `getMessage()` is the same translated sentence it always
 * was. On top of that it carries the lang key (`errorCode`, without the
 * `writer_` prefix), the dot path of the offending part of the definition
 * (`places.draft.metadata.actions.0`, `transitions.submit`,
 * `metadata.sla`, `collections.1`, or `workflow`) and — when thrown by
 * WorkflowDefinitionWriter::validate() — the complete list of problems found,
 * of which this exception's own message is the first.
 */
class DefinitionValidationException extends InvalidArgumentException
{
    /**
     * @param  array<int, array{path: string, code: string, message: string, node: array{kind: string, handle: string|null}}>  $errors
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly string $path = 'workflow',
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /**
     * The same failure with the full error list attached.
     *
     * @param  array<int, array{path: string, code: string, message: string, node: array{kind: string, handle: string|null}}>  $errors
     */
    public function withErrors(array $errors): self
    {
        return new self($this->getMessage(), $this->errorCode, $this->path, $errors);
    }

    /**
     * This failure as one entry of an error list.
     *
     * @return array{path: string, code: string, message: string, node: array{kind: string, handle: string|null}}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'node' => self::nodeFor($this->path),
        ];
    }

    /**
     * The graph node a path belongs to: a place or transition when the path
     * starts with `places.<handle>` / `transitions.<handle>`, the workflow
     * itself otherwise.
     *
     * @return array{kind: string, handle: string|null}
     */
    public static function nodeFor(string $path): array
    {
        $segments = explode('.', $path);
        $kind = ['places' => 'place', 'transitions' => 'transition'][$segments[0]] ?? null;

        if ($kind !== null && isset($segments[1]) && $segments[1] !== '') {
            return ['kind' => $kind, 'handle' => $segments[1]];
        }

        return ['kind' => 'workflow', 'handle' => null];
    }
}
