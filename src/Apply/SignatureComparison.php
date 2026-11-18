<?php

declare(strict_types=1);

namespace voku\AgentEdit\Apply;

/**
 * The verdict of {@see SignatureGuard::compare()}: `unchanged`, `changed` (with the changes), or `unknown` when either
 * side does not parse and no comparison was possible. `unknown` is not `unchanged`: a caller must not read it as
 * "no API change".
 */
final readonly class SignatureComparison
{
    public const UNCHANGED = 'unchanged';
    public const CHANGED = 'changed';
    public const UNKNOWN = 'unknown';

    /** @param list<string> $changes */
    private function __construct(public string $status, public array $changes, public ?string $reason)
    {
    }

    /** @param list<string> $changes */
    public static function of(array $changes): self
    {
        return new self($changes === [] ? self::UNCHANGED : self::CHANGED, $changes, null);
    }

    public static function unknown(string $reason): self
    {
        return new self(self::UNKNOWN, [], $reason);
    }

    public function isUnknown(): bool
    {
        return $this->status === self::UNKNOWN;
    }
}
