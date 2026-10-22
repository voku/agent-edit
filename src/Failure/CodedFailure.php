<?php

declare(strict_types=1);

namespace voku\AgentEdit\Failure;

/** A throwable that carries a stable {@see FailureCode}; implemented by the two exception classes below. */
interface CodedFailure extends \Throwable
{
    public function failureCode(): FailureCode;

    /** @return array<string, mixed> structured facts a caller can act on without parsing the message */
    public function details(): array;
}
