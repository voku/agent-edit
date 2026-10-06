<?php

declare(strict_types=1);

namespace voku\AgentEdit\Verify;

use voku\AgentMap\Reference\NonPhpReference;
use voku\AgentMap\Reference\NonPhpReferenceScanner;
use voku\AgentMap\Reference\ReferenceTarget;

/**
 * Re-scans Markdown and template files for the old symbol after a rename or removal.
 *
 * The PHP edit is proven by the plan; text mentions are not provable. They are therefore residue: a
 * verification that otherwise passed is `incomplete` while non-historical mentions remain, until the
 * mentions are fixed (by a separate cleanup) or accepted with a recorded reason.
 */
final readonly class ResidueCheck
{
    private const SUPPORTED_PLANS = ['method_rename_plan', 'class_rename_plan', 'method_removal_plan'];
    private const MAX_LISTED = 50;

    public function __construct(private NonPhpReferenceScanner $scanner = new NonPhpReferenceScanner())
    {
    }

    /**
     * @param array<string, mixed> $result verification result of the plan-type verifier
     * @param array<string, mixed> $plan the immutable plan bound by the receipt
     * @return array<string, mixed> the result, with a `residue` block for supported plan types
     */
    public function apply(array $result, array $plan, string $projectRoot, ?string $disposition): array
    {
        $targets = $this->targets($plan);
        if ($targets === []) {
            return $result;
        }

        $report = $this->scanner->scan($projectRoot, ...$targets);
        $open = array_values(array_filter($report->references, static fn (NonPhpReference $reference): bool => !$reference->historical));
        $historical = count($report->references) - count($open);
        $truncated = $report->total > count($report->references);

        $status = $open === [] && !$truncated ? 'clear' : ($disposition !== null ? 'accepted' : 'open');
        $result['residue'] = [
            'status' => $status,
            'open' => count($open),
            'historical' => $historical,
            'truncated' => $truncated,
            'scanned_files' => $report->scannedFiles,
            'references' => array_map(
                static fn (NonPhpReference $reference): array => $reference->toArray(),
                array_slice($open, 0, self::MAX_LISTED),
            ),
        ] + ($status === 'accepted' ? ['disposition' => $disposition] : []);

        if ($status === 'open' && ($result['status'] ?? null) === 'passed') {
            $result['status'] = 'incomplete';
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $plan
     * @return list<ReferenceTarget>
     */
    private function targets(array $plan): array
    {
        $type = $plan['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::SUPPORTED_PLANS, true)) {
            return [];
        }

        if ($type === 'class_rename_plan') {
            $fqn = $plan['original_fqn'] ?? null;

            return is_string($fqn) && $fqn !== '' ? [ReferenceTarget::classLike($fqn)] : [];
        }

        $name = $type === 'method_rename_plan' ? ($plan['original_name'] ?? null) : null;
        $ids = $type === 'method_rename_plan' ? ($plan['family'] ?? []) : [$plan['target_id'] ?? null];
        $targets = [];
        foreach (is_array($ids) ? $ids : [] as $id) {
            if (is_string($id) && preg_match('/^method:(.+)::([^:]+)$/', $id, $match) === 1) {
                $targets[$match[1]] = ReferenceTarget::method($match[1], is_string($name) ? $name : $match[2]);
            }
        }

        return array_values($targets);
    }
}
