<?php

declare(strict_types=1);

namespace voku\AgentEdit\Plan;

/**
 * One caller-authored edit inside an existing file: an exact-text replacement (`find` / `replace`), or, when
 * `$symbolOperation` is set, a symbol-addressed operation that agent-edit resolves against the current source.
 */
final readonly class SourceEdit
{
    public function __construct(
        public string $path,
        public string $find,
        public string $replace,
        public ?int $occurrence,
        public bool $all,
        public ?string $expectedSourceSha256,
        /** @var array<string, mixed>|null validated `op` and fields of a symbol-addressed edit */
        public ?array $symbolOperation = null,
    ) {
    }
}
