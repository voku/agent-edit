<?php

declare(strict_types=1);

namespace voku\AgentEdit\Verify;

/** Verifies one applied receipt bundle against current source and a refreshed Map. */
interface BundleVerifier
{
    /**
     * @return array<string, mixed> verification result document (`verification-result.json` payload)
     */
    public function verify(string $bundle, string $mapIndex, string $mapRoot): array;
}
