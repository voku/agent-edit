<?php

declare(strict_types=1);

namespace voku\AgentEdit\Receipt;

use RuntimeException;
use voku\AgentEdit\Failure\CodedFailure;
use voku\AgentEdit\Failure\FailureCode;
use Throwable;

/**
 * The source was published but its receipt could not be written. The transaction committed, so this run cannot undo it,
 * and `verify` refuses a bundle without a receipt, so a governed close cannot pass on this state. The publication
 * journal written before publication stays in the bundle: `agent-edit recover` turns it into a receipt (or rolls an
 * interrupted publication back) once the bundle is writable again.
 */
final class ReceiptNotPersistedException extends RuntimeException implements CodedFailure
{
    /** @param list<string> $changedFiles project-relative files observed as changed by the published edit */
    public function __construct(
        public readonly array $changedFiles,
        public readonly string $outputDirectory,
        Throwable $previous,
    ) {
        parent::__construct(sprintf(
            'Source files were published but the agent-edit receipt could not be persisted (%s); no receipt exists, so verify refuses this bundle. Changed files: %s. The bundle keeps its publication journal: once the receipt location is writable again, run `agent-edit recover --bundle=%s` to record the published state (or roll an interrupted publication back).',
            $previous->getMessage(),
            $changedFiles === [] ? '(none observed)' : implode(', ', $changedFiles),
            $outputDirectory,
        ), 0, $previous);
    }

    public function failureCode(): FailureCode
    {
        return FailureCode::RecoveryRequired;
    }

    /** @return array{changed_files: list<string>, output_directory: string} */
    public function details(): array
    {
        return ['changed_files' => $this->changedFiles, 'output_directory' => $this->outputDirectory];
    }
}
