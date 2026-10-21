<?php

declare(strict_types=1);

namespace voku\AgentEdit\Plan;

/** One caller-authored new file; the path must not exist when the plan is applied. */
final readonly class SourceCreate
{
    public function __construct(
        public string $path,
        public string $content,
    ) {
    }
}
