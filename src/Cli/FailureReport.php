<?php

declare(strict_types=1);

namespace voku\AgentEdit\Cli;

use Throwable;
use voku\AgentEdit\Failure\CodedFailure;
use voku\AgentEdit\Failure\FailureCode;

/** Turns any throwable into the `error` object of the JSON envelope; an uncoded one is reported as `failed`, never guessed. */
final class FailureReport
{
    /** @return array{code: string, message: string, next_action: string, hint: string, details: array<string, mixed>|\stdClass} */
    public static function describe(Throwable $failure): array
    {
        $code = $failure instanceof CodedFailure ? $failure->failureCode() : FailureCode::Failed;
        $details = $failure instanceof CodedFailure ? $failure->details() : [];

        return [
            'code' => $code->value,
            'message' => $failure->getMessage(),
            'next_action' => $code->nextAction(),
            'hint' => $code->hint(),
            // An empty PHP array would encode as a JSON list; `details` is always an object.
            'details' => $details === [] ? new \stdClass() : $details,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
    }
}
