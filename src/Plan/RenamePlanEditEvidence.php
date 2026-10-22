<?php

declare(strict_types=1);

namespace voku\AgentEdit\Plan;

use RuntimeException;

/** One exact source edit decoded from the public agent-map rename wire contract. */
final readonly class RenamePlanEditEvidence
{
    public function __construct(
        public string $path,
        public string $sourceSha256,
        public int $startFilePos,
        public int $endFilePos,
        public int $lineStart,
        public int $lineEnd,
        public string $expected,
        public string $replacement,
        public string $role,
        public string $symbolId,
        public string $resolution,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param bool $allowInsertion accept a zero-length insertion (`expected` empty, start equal to end, the text inserted
     *                             before the byte at that offset); only the insertion plan decoder opts in
     */
    public static function fromArray(array $data, bool $allowEmptyReplacement = false, bool $allowInsertion = false): self
    {
        $edit = new self(
            path: self::string($data, 'path'),
            sourceSha256: self::string($data, 'source_sha256'),
            startFilePos: self::integer($data, 'start_file_pos'),
            endFilePos: self::integer($data, 'end_file_pos'),
            lineStart: self::integer($data, 'line_start'),
            lineEnd: self::integer($data, 'line_end'),
            expected: self::string($data, 'expected', $allowInsertion),
            replacement: self::string($data, 'replacement', $allowEmptyReplacement),
            role: self::string($data, 'role'),
            symbolId: self::string($data, 'symbol_id'),
            resolution: self::string($data, 'resolution'),
        );

        if ($edit->startFilePos < 0 || $edit->endFilePos < $edit->startFilePos) {
            throw new RuntimeException('Rename plan edit contains an invalid byte range.');
        }
        if ($edit->isInsertion() && ($edit->startFilePos !== $edit->endFilePos || $edit->replacement === '')) {
            throw new RuntimeException('Plan insertion edit must name one offset and carry non-empty text.');
        }
        if ($edit->lineStart < 1 || $edit->lineEnd < $edit->lineStart) {
            throw new RuntimeException('Rename plan edit contains an invalid line range.');
        }
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/D', $edit->sourceSha256)) {
            throw new RuntimeException('Rename plan edit contains an invalid source SHA-256.');
        }

        return $edit;
    }

    /** A pure insertion replaces no bytes: `expected` is empty and the text goes before the byte at `startFilePos`. */
    public function isInsertion(): bool
    {
        return $this->expected === '';
    }

    /** Number of source bytes this edit replaces; zero for an insertion. */
    public function replacedLength(): int
    {
        return $this->isInsertion() ? 0 : $this->endFilePos - $this->startFilePos + 1;
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key, bool $allowEmpty = false): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || (!$allowEmpty && $value === '')) {
            throw new RuntimeException('Rename plan edit requires ' . ($allowEmpty ? 'string ' : 'non-empty string ') . $key . '.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException('Rename plan edit requires integer ' . $key . '.');
        }

        return $value;
    }
}
