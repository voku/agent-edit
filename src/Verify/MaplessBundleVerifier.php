<?php

declare(strict_types=1);

namespace voku\AgentEdit\Verify;

/** Verifies one applied receipt bundle of a plan family that needs no Map, against the current source alone. */
interface MaplessBundleVerifier
{
    /** @return array<string, mixed> verification result document (`verification-result.json` payload) */
    public function verify(string $bundle, string $root): array;
}
