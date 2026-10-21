<?php

declare(strict_types=1);

namespace voku\AgentEdit\Plan;

/** One caller-authored exact-text replacement inside an existing file. */
final readonly class SourceEdit
{
    public function __construct(
        public string $path,
        public string $find,
        public string $replace,
        public ?int $occurrence,
        public bool $all,
        public ?string $expectedSourceSha256,
    ) {
    }
}
