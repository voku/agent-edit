<?php

declare(strict_types=1);

namespace voku\AgentEdit\Receipt;

use RuntimeException;
use Throwable;

/**
 * The source was published but its receipt could not be written. The mutation lock is already released and the
 * transaction committed, so there is no snapshot to restore: hosts must treat the working tree as changed without
 * evidence. `verify` refuses a bundle without a receipt, so a governed close cannot pass on this state.
 */
final class ReceiptNotPersistedException extends RuntimeException
{
    /** @param list<string> $changedFiles project-relative files observed as changed by the published edit */
    public function __construct(
        public readonly array $changedFiles,
        public readonly string $outputDirectory,
        Throwable $previous,
    ) {
        parent::__construct(sprintf(
            'Source files were published but the agent-edit receipt could not be persisted (%s); no receipt exists, so verify refuses this bundle. Changed files: %s. Restore them from version control and re-plan, or verify and keep the changes through a fresh governed run.',
            $previous->getMessage(),
            $changedFiles === [] ? '(none observed)' : implode(', ', $changedFiles),
        ), 0, $previous);
    }
}
