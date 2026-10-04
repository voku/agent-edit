<?php

declare(strict_types=1);

namespace voku\AgentEdit\Receipt;

use Closure;

/** Everything `EditEngine::applyWithReceipt()` needs; all paths are absolute and already resolved by the caller. */
final readonly class ApplyRequest
{
    /**
     * @param (Closure(string): void)|null $authorizeMutation host authorization hook called with the label before
     *                                                       any non-dry-run write; it must throw to refuse
     */
    public function __construct(
        public string $repositoryRoot,
        public string $planPath,
        public string $mapIndexPath,
        public string $mapRoot,
        public string $outputDirectory,
        public string $label,
        public bool $dryRun = false,
        public ?Closure $authorizeMutation = null,
    ) {
    }
}
