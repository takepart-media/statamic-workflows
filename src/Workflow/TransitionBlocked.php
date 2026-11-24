<?php

namespace TakepartMedia\Workflows\Workflow;

/**
 * A TransitionGuard refused the step. The message is the guard's own reason,
 * meant for the editor.
 */
class TransitionBlocked extends \RuntimeException {}
