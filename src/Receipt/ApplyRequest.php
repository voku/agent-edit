<?php

declare(strict_types=1);

namespace voku\AgentEdit\Receipt;

use Closure;

/** Everything `EditEngine::applyWithReceipt()` needs; all paths are absolute and already resolved by the caller. */
final readonly class ApplyRequest
{
    /**
     * @param string|null $mapIndexPath null is only valid for plan families that need no Map (`source_edit_plan`)
     * @param (Closure(string): void)|null $authorizeMutation host authorization hook called with the label before
     *                                                       any non-dry-run write; it must throw to refuse
     * @param bool $requireSourceHashes host policy for `source_edit_plan`: refuse any edit of an existing file that
     *                                  does not carry `expected_source_sha256`, so an edit can only land on the bytes
     *                                  the agent based it on (Map `context` slices carry that digest as `source_sha256`)
     */
    public function __construct(
        public string $repositoryRoot,
        public string $planPath,
        public ?string $mapIndexPath,
        public string $mapRoot,
        public string $outputDirectory,
        public string $label,
        public bool $dryRun = false,
        public ?Closure $authorizeMutation = null,
        public bool $requireSourceHashes = false,
    ) {
    }
}
