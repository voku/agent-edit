<?php

declare(strict_types=1);

namespace voku\AgentEdit\Failure;

use InvalidArgumentException;
use Throwable;

/** The {@see EditFailure} counterpart for what used to be an `InvalidArgumentException` (bad arguments, unsupported plan). */
final class InvalidInputFailure extends InvalidArgumentException implements CodedFailure
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
