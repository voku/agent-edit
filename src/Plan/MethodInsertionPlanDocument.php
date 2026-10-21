<?php

declare(strict_types=1);

namespace voku\AgentEdit\Plan;

use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;

/** Fail-closed decoder for Map's safe method-copy and method-scaffold insertion contracts. */
final readonly class MethodInsertionPlanDocument implements EditMovePlanEvidence
{
    /**
     * @param list<RenamePlanEditEvidence> $edits
     */
    private function __construct(
        private string $type,
        private string $targetId,
        private RenamePlanProvenanceEvidence $provenance,
        private array $edits,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        if (!is_string($type) || !in_array($type, ['method_copy_plan', 'method_scaffold_plan'], true)
            || ($data['contract_version'] ?? null) !== '1.0') {
            throw new RuntimeException('Unsupported agent-map method insertion plan contract.');
        }
        self::safeEvidence($data, $type);

        $targetId = self::string($data, 'target_id', $type);
        if (!str_starts_with($targetId, 'method:')) {
            throw new RuntimeException(ucfirst(str_replace('_', ' ', $type)) . ' target identity must use the method: prefix.');
        }
        $provenance = $data['provenance'] ?? null;
        if (!is_array($provenance)) {
            throw new RuntimeException(ucfirst(str_replace('_', ' ', $type)) . ' requires typed provenance evidence.');
        }
        $rawEdits = $data['edits'] ?? null;
        if (!is_array($rawEdits) || !array_is_list($rawEdits) || $rawEdits === []) {
            throw new RuntimeException('Safe ' . $type . ' requires exact insertion edits.');
        }
        if (isset($data['moves']) && (!is_array($data['moves']) || $data['moves'] !== [])) {
            throw new RuntimeException($type . '@1.0 may not publish file moves.');
        }

        $expectedMethodId = $type === 'method_copy_plan'
            ? self::copyMethodId($data, $targetId)
            : self::scaffoldMethodId($data, $targetId);
        $dependencyResolution = $type === 'method_copy_plan' ? 'copy_dependency' : 'scaffold_dependency';
        $declarationResolution = $type === 'method_copy_plan' ? 'copy_declaration' : 'scaffold_declaration';

        $edits = [];
        $insertions = 0;
        foreach ($rawEdits as $rawEdit) {
            if (!is_array($rawEdit)) {
                throw new RuntimeException($type . ' contains an invalid edit.');
            }
            $edit = RenamePlanEditEvidence::fromArray($rawEdit);
            if ($edit->role === 'method_declaration_insertion') {
                ++$insertions;
                if ($edit->symbolId !== $expectedMethodId || $edit->resolution !== $declarationResolution || $edit->replacement === '') {
                    throw new RuntimeException($type . ' declaration insertion is not bound to its exact destination method.');
                }
            } elseif ($edit->role === 'use_statement_insertion') {
                if (!str_starts_with($edit->symbolId, 'use:') || $edit->resolution !== $dependencyResolution) {
                    throw new RuntimeException($type . ' dependency insertion has unexpected evidence.');
                }
            } else {
                throw new RuntimeException('Unsupported ' . $type . ' edit role: ' . $edit->role . '.');
            }
            $edits[] = $edit;
        }
        if ($insertions !== 1) {
            throw new RuntimeException('Safe ' . $type . ' requires exactly one method declaration insertion.');
        }

        return new self($type, $targetId, RenamePlanProvenanceEvidence::fromArray($provenance), $edits);
    }

    public function planType(): string { return $this->type; }
    public function targetId(): string { return $this->targetId; }
    public function requiresPhpStan(): bool { return true; }
    /** @return list<RenamePlanEditEvidence> */
    public function edits(): array { return $this->edits; }
    /** @return list<RenamePlanMoveEvidence> */
    public function moves(): array { return []; }
    public function assertMatches(AgentMapIndex $map): void { $this->provenance->assertMatches($map, true); }

    /** @param array<string, mixed> $data */
    private static function copyMethodId(array $data, string $targetId): string
    {
        $sourceId = self::string($data, 'source_id', 'method copy plan');
        if ($sourceId !== $targetId) {
            throw new RuntimeException('Method copy source identity must exactly match its target identity.');
        }
        $destination = self::string($data, 'destination_fqn', 'method copy plan');
        $name = self::string($data, 'new_method_name', 'method copy plan');
        if ($destination !== ltrim($destination, '\\') || str_contains($destination, '::') || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new RuntimeException('Method copy destination identity is malformed.');
        }
        $dependencies = $data['owner_dependencies'] ?? null;
        if (!is_array($dependencies) || $dependencies !== []) {
            throw new RuntimeException('Safe method copy plan requires empty owner_dependencies evidence.');
        }

        return $destination . '::' . $name;
    }

    /** @param array<string, mixed> $data */
    private static function scaffoldMethodId(array $data, string $targetId): string
    {
        $class = self::string($data, 'target_class', 'method scaffold plan');
        $name = self::string($data, 'method_name', 'method scaffold plan');
        if ($class !== ltrim($class, '\\') || str_contains($class, '::') || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)
            || $targetId !== 'method:' . $class . '::' . $name) {
            throw new RuntimeException('Method scaffold target identity is malformed.');
        }

        return $class . '::' . $name;
    }

    /** @param array<string, mixed> $data */
    private static function safeEvidence(array $data, string $type): void
    {
        if (($data['status'] ?? null) === 'review_required') {
            throw PlanRefusal::because(ucfirst(str_replace('_', ' ', $type)) . ' requires explicit review; no source was changed.', $data);
        }
        if (($data['status'] ?? null) !== 'safe') {
            throw PlanRefusal::because(ucfirst(str_replace('_', ' ', $type)) . ' is not safe; no source was changed.', $data);
        }
        foreach (['blind_spots', 'stale_evidence', 'blockers'] as $field) {
            $value = $data[$field] ?? null;
            if (!is_array($value) || $value !== []) {
                throw new RuntimeException('Safe ' . $type . ' requires empty ' . $field . ' evidence.');
            }
        }
        $notObservable = $data['not_observable'] ?? null;
        if (!is_array($notObservable) || !array_is_list($notObservable)) {
            throw new RuntimeException($type . ' requires not_observable list evidence.');
        }
        foreach ($notObservable as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new RuntimeException($type . ' contains invalid not_observable evidence.');
            }
        }
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key, string $plan): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(ucfirst($plan) . ' requires non-empty string ' . $key . '.');
        }

        return $value;
    }
}
