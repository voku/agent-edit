<?php

declare(strict_types=1);

namespace voku\AgentEdit\Apply;

/**
 * One precondition-guarded file change inside a `FileTransaction`.
 *
 * `$expectedSha256` is the lowercase hex digest the file must have right now; `null` on a write means the path must not
 * exist yet (a creation). A delete always names the digest it is allowed to remove.
 */
final readonly class FileOperation
{
    private function __construct(
        public string $path,
        public ?string $content,
        public ?string $expectedSha256,
    ) {
    }

    public static function write(string $path, string $content, ?string $expectedSha256): self
    {
        return new self($path, $content, $expectedSha256);
    }

    public static function delete(string $path, string $expectedSha256): self
    {
        return new self($path, null, $expectedSha256);
    }

    public function isDelete(): bool
    {
        return $this->content === null;
    }

    public function isCreate(): bool
    {
        return $this->content !== null && $this->expectedSha256 === null;
    }
}
