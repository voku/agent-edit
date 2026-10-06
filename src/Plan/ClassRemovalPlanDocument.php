<?php

declare(strict_types=1);

namespace voku\AgentEdit\Plan;

use RuntimeException;

/** Fail-closed wire envelope for agent-map class_removal_plan@1.0: one whole-file deletion, no edits, no moves. */
final readonly class ClassRemovalPlanDocument
{
    public const PLAN_TYPE = 'class_removal_plan';

    public function __construct(
        public string $targetId,
        public RenamePlanProvenanceEvidence $provenance,
        public string $path,
        public string $sourceSha256,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['type'] ?? null) !== self::PLAN_TYPE) {
            throw new RuntimeException('Unsupported agent-map class removal plan type.');
        }
        if (($data['contract_version'] ?? null) !== '1.0') {
            throw new RuntimeException('Unsupported agent-map class removal plan contract version.');
        }
        if (($data['status'] ?? null) !== 'safe') {
            throw PlanRefusal::because('Class removal plan is not safe; no source was changed.', $data);
        }

        self::requireEmptyList($data, 'stale_evidence', 'Class removal plan contains stale evidence; rebuild the map and re-plan before applying.');
        self::requireEmptyList($data, 'blockers', 'Class removal plan has semantic blockers; no source was changed.');
        self::requireEmptyList($data, 'blind_spots', 'Class removal plan requires explicit review; no source was changed.');

        $notObservable = $data['not_observable'] ?? null;
        if (!is_array($notObservable) || !array_is_list($notObservable)) {
            throw new RuntimeException('Class removal plan requires not_observable list evidence.');
        }
        foreach ($notObservable as $boundary) {
            if (!is_string($boundary) || trim($boundary) === '') {
                throw new RuntimeException('Class removal plan contains invalid not_observable evidence.');
            }
        }

        $targetId = self::string($data, 'target_id');
        if (!str_starts_with($targetId, 'class:')) {
            throw new RuntimeException('Class removal plan target identity must be a class.');
        }

        $rawProvenance = $data['provenance'] ?? null;
        if (!is_array($rawProvenance)) {
            throw new RuntimeException('Class removal plan requires typed provenance evidence.');
        }
        if (($data['edits'] ?? null) !== []) {
            throw new RuntimeException('Class removal plans cannot publish source edits.');
        }
        if (($data['moves'] ?? []) !== []) {
            throw new RuntimeException('Class removal plans cannot publish file moves.');
        }

        $deletions = $data['deletions'] ?? null;
        if (!is_array($deletions) || !array_is_list($deletions) || count($deletions) !== 1 || !is_array($deletions[0])) {
            throw new RuntimeException('Safe class removal plan requires exactly one whole-file deletion.');
        }
        $deletion = $deletions[0];
        $path = self::string($deletion, 'path');
        $sourceSha256 = self::string($deletion, 'source_sha256');
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $sourceSha256) !== 1) {
            throw new RuntimeException('Class removal deletion requires a sha256 source hash.');
        }
        self::string($deletion, 'reason');

        return new self($targetId, RenamePlanProvenanceEvidence::fromArray($rawProvenance), $path, $sourceSha256);
    }

    /** @param array<string, mixed> $data */
    private static function requireEmptyList(array $data, string $key, string $message): void
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Class removal plan requires ' . $key . ' list evidence.');
        }
        if ($value !== []) {
            throw PlanRefusal::because($message, $data);
        }
    }

    /** @param array<mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Class removal plan requires non-empty string ' . $key . '.');
        }

        return $value;
    }
}
