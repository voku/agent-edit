<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests\Support;

use PHPUnit\Framework\AssertionFailedError;
use Throwable;

/**
 * Capture what an operation throws without the classic trap: PHPUnit's own assertion failures extend RuntimeException, so
 * `try { op(); self::fail(); } catch (RuntimeException $e) { assert on $e }` swallows its own `fail()` and then "passes"
 * on the failure message. Assertion failures raised inside the operation are rethrown; anything else is returned.
 */
trait CapturesFailures
{
    private function captured(callable $operation): ?Throwable
    {
        try {
            $operation();
        } catch (AssertionFailedError $assertion) {
            throw $assertion;
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }

    private function capturedOrFail(callable $operation, string $context): Throwable
    {
        $failure = $this->captured($operation);
        if ($failure === null) {
            self::fail('Expected the operation to fail: ' . $context);
        }

        return $failure;
    }
}
