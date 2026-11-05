<?php

declare(strict_types=1);

namespace voku\AgentEdit\Plan;

use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;

/** Fail-closed decoder for released `method_copy_plan@1.0`. */
final readonly class MethodCopyPlanDocument implements EditMovePlanEvidence
{
    /**
     * @param list<RenamePlanEditEvidence> $edits
     */
    public function __construct(
        public string $type,
        public string $targetId,
        public string $sourceId,
        public string $destinationFqn,
        public string $newMethodName,
        public RenamePlanProvenanceEvidence $provenance,
        public array $edits,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['type'] ?? null) !== 'method_copy_plan' || ($data['contract_version'] ?? null) !== '1.0') {
            throw new RuntimeException('Unsupported agent-map method copy plan contract.');
        }
        if (($data['status'] ?? null) === 'review_required') {
            throw PlanRefusal::because('Method copy plan requires explicit review; no source was changed.', $data);
        }
        if (($data['status'] ?? null) !== 'safe') {
            throw PlanRefusal::because('Method copy plan is not safe; no source was changed.', $data);
        }

        foreach (['blind_spots', 'stale_evidence', 'blockers'] as $field) {
            $value = $data[$field] ?? null;
            if (!is_array($value)) {
                throw new RuntimeException('Method copy plan requires ' . $field . ' list evidence.');
            }
            if ($value !== []) {
                throw new RuntimeException('Safe method copy plan requires empty ' . $field . ' evidence.');
            }
        }

        $ownerDependencies = $data['owner_dependencies'] ?? null;
        if (!is_array($ownerDependencies)) {
            throw new RuntimeException('Method copy plan requires owner_dependencies list evidence.');
        }
        if ($ownerDependencies !== []) {
            throw new RuntimeException('Safe method copy plan requires empty owner_dependencies evidence.');
        }

        $notObservable = $data['not_observable'] ?? null;
        if (!is_array($notObservable) || !array_is_list($notObservable)) {
            throw new RuntimeException('Method copy plan requires not_observable list evidence.');
        }
        foreach ($notObservable as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                throw new RuntimeException('Method copy plan contains invalid not_observable evidence.');
            }
        }

        $targetId = self::string($data, 'target_id');
        $sourceId = self::string($data, 'source_id');
        if ($sourceId !== $targetId) {
            throw new RuntimeException('Method copy source identity must exactly match its target identity.');
        }
        if (!str_starts_with($targetId, 'method:')) {
            throw new RuntimeException('Method copy target identity must use the method: prefix.');
        }

        $methodIdentity = substr($targetId, strlen('method:'));
        $separator = strrpos($methodIdentity, '::');
        if ($separator === false || $separator === 0 || $separator === strlen($methodIdentity) - 2) {
            throw new RuntimeException('Method copy target identity is malformed.');
        }
        $sourceFqn = substr($methodIdentity, 0, $separator);
        $sourceMethodName = substr($methodIdentity, $separator + 2);

        $destinationFqn = self::string($data, 'destination_fqn');
        if ($destinationFqn !== ltrim($destinationFqn, '\\') || str_contains($destinationFqn, '::')) {
            throw new RuntimeException('Method copy destination must be an exact class identity.');
        }

        $newMethodName = self::string($data, 'new_method_name');
        if (strcasecmp($sourceFqn, $destinationFqn) === 0 && strcasecmp($sourceMethodName, $newMethodName) === 0) {
            throw new RuntimeException('Method copy within the same class requires a different method name.');
        }

        $rawProvenance = $data['provenance'] ?? null;
        if (!is_array($rawProvenance)) {
            throw new RuntimeException('Method copy plan requires typed provenance evidence.');
        }

        $rawEdits = $data['edits'] ?? null;
        if (!is_array($rawEdits) || !array_is_list($rawEdits) || count($rawEdits) < 1) {
            throw new RuntimeException('Safe method copy plan requires at least one insertion edit.');
        }

        if (isset($data['moves']) && (!is_array($data['moves']) || $data['moves'] !== [])) {
            throw new RuntimeException('method_copy_plan@1.0 may not publish file moves.');
        }

        $edits = [];
        $insertions = 0;
        $destinationMethodId = $destinationFqn . '::' . $newMethodName;

        foreach ($rawEdits as $rawEdit) {
            if (!is_array($rawEdit)) {
                throw new RuntimeException('Method copy plan contains an invalid edit.');
            }

            $allowEmptyExpected = ($rawEdit['role'] ?? null) === 'use_statement_insertion';
            $edit = RenamePlanEditEvidence::fromArray($rawEdit, false, $allowEmptyExpected);

            switch ($edit->role) {
                case 'method_declaration_insertion':
                    ++$insertions;
                    if ($edit->symbolId !== $destinationMethodId || $edit->replacement === '') {
                        throw new RuntimeException('Method copy insertion edit is not bound to the exact destination method identity.');
                    }
                    break;

                case 'use_statement_insertion':
                    if (!str_starts_with($edit->symbolId, 'use:') || $edit->replacement === '') {
                        throw new RuntimeException('Method copy use-statement edit is not bound to an import identity.');
                    }
                    break;

                default:
                    throw new RuntimeException('Unsupported method copy edit role: ' . $edit->role . '.');
            }

            $edits[] = $edit;
        }

        if ($insertions !== 1) {
            throw new RuntimeException('Safe method copy plan requires exactly one declaration insertion.');
        }

        return new self(
            type: 'method_copy_plan',
            targetId: $targetId,
            sourceId: $sourceId,
            destinationFqn: $destinationFqn,
            newMethodName: $newMethodName,
            provenance: RenamePlanProvenanceEvidence::fromArray($rawProvenance),
            edits: $edits,
        );
    }

    public function planType(): string
    {
        return $this->type;
    }

    public function targetId(): string
    {
        return $this->targetId;
    }

    public function requiresPhpStan(): bool
    {
        return true;
    }

    /** @return list<RenamePlanEditEvidence> */
    public function edits(): array
    {
        return $this->edits;
    }

    /** @return list<RenamePlanMoveEvidence> */
    public function moves(): array
    {
        return [];
    }

    public function assertMatches(AgentMapIndex $map): void
    {
        $this->provenance->assertMatches($map, true);
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Method copy plan requires non-empty string ' . $key . '.');
        }

        return $value;
    }
}
