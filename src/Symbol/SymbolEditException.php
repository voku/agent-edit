<?php

declare(strict_types=1);

namespace voku\AgentEdit\Symbol;

use RuntimeException;

/**
 * A symbol-addressed edit that cannot be resolved or applied as written. The message is meant for the coding agent that
 * wrote the edit: it says what was found instead (for example which symbols the file declares) and how to fix it.
 */
final class SymbolEditException extends RuntimeException
{
}
