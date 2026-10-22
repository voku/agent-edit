<?php

declare(strict_types=1);

namespace voku\AgentEdit\Failure;

use RuntimeException;
use Throwable;

/**
 * A refusal or failure with a stable {@see FailureCode}. It is still a `RuntimeException` with the same human message the
 * code used to throw, so a caller that only prints `getMessage()` or catches `RuntimeException` keeps working; one that
 * wants to branch reads `failureCode()`.
 */
final class EditFailure extends RuntimeException implements CodedFailure
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        private readonly FailureCode $failureCode,
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function failureCode(): FailureCode
    {
        return $this->failureCode;
    }

    public function details(): array
    {
        return $this->details;
    }
}
