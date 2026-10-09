<?php

declare(strict_types=1);

namespace voku\AgentEdit\Verify;

use voku\SimplePhpParser\Parsers\Helper\UnusedImports;

/**
 * Finds `use` imports orphaned by a pure-deletion plan edit.
 *
 * A removal or move plan deletes exactly one declaration; the import that only that declaration needed stays
 * behind. agent-edit never invents an extra edit, so the leftover is reported as residue instead: an import is
 * orphaned when its alias occurs in the deleted text and no longer occurs anywhere else in the edited file.
 */
final readonly class ImportResidueCheck
{
    private const SUPPORTED_PLANS = ['method_removal_plan', 'method_move_plan', 'property_removal_plan', 'class_constant_removal_plan'];

    /**
     * @param array<string, mixed> $result verification result of the plan-type verifier
     * @param array<string, mixed> $plan the immutable plan bound by the receipt
     * @return array<string, mixed> the result, with an `import_residue` block for supported plan types
     */
    public function apply(array $result, array $plan, string $projectRoot, ?string $disposition): array
    {
        $type = $plan['type'] ?? null;
        $edits = $plan['edits'] ?? null;
        if (!is_string($type) || !in_array($type, self::SUPPORTED_PLANS, true) || !is_array($edits)) {
            return $result;
        }

        $orphaned = [];
        foreach ($this->deletions($edits) as $path => $deletedTexts) {
            $source = @file_get_contents($projectRoot . '/' . $path);
            if (!is_string($source)) {
                continue;
            }
            foreach ($this->orphanedImports($source, implode("\n", $deletedTexts)) as $import) {
                $orphaned[] = ['path' => $path] + $import;
            }
        }

        $status = $orphaned === [] ? 'clear' : ($disposition !== null ? 'accepted' : 'open');
        $result['import_residue'] = [
            'status' => $status,
            'open' => count($orphaned),
            'imports' => $orphaned,
        ] + ($status === 'accepted' ? ['disposition' => $disposition] : []);

        if ($status === 'open' && ($result['status'] ?? null) === 'passed') {
            $result['status'] = 'incomplete';
        }

        return $result;
    }

    /**
     * @param array<mixed> $edits
     * @return array<string, list<string>> deleted text per path
     */
    private function deletions(array $edits): array
    {
        $deleted = [];
        foreach ($edits as $edit) {
            if (is_array($edit) && is_string($edit['path'] ?? null) && ($edit['replacement'] ?? null) === '' && is_string($edit['expected'] ?? null)) {
                $deleted[$edit['path']][] = $edit['expected'];
            }
        }

        return $deleted;
    }

    /** @return list<array{import: string, alias: string, line: int}> */
    private function orphanedImports(string $source, string $deleted): array
    {
        return array_values(array_filter(
            UnusedImports::find($source),
            static fn (array $import): bool => preg_match('/(?<![\w$])' . preg_quote($import['alias'], '/') . '(?!\w)/i', $deleted) === 1,
        ));
    }
}
