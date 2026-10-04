<?php

declare(strict_types=1);

namespace voku\AgentEdit\Plan;

use RuntimeException;

/**
 * Refusal of a plan the owner marked unsafe, stale or review-only. The fixed refusal sentence stays first; the plan's
 * own status and evidence are appended so a caller sees *why* (for example the call site that blocks a removal)
 * without opening the plan file.
 */
final class PlanRefusal
{
    private const MAX_ITEMS = 3;
    private const MAX_ITEM_LENGTH = 200;

    /** @param array<string, mixed> $plan decoded plan document */
    public static function because(string $refusal, array $plan): RuntimeException
    {
        $parts = [];
        $status = $plan['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $parts[] = 'status: ' . $status;
        }
        foreach (['blockers' => 'blockers', 'stale_evidence' => 'stale evidence', 'blind_spots' => 'blind spots'] as $key => $label) {
            $items = self::items($plan[$key] ?? null);
            if ($items !== []) {
                $parts[] = $label . ': ' . implode('; ', $items);
            }
        }

        return new RuntimeException($parts === [] ? $refusal : $refusal . ' Plan evidence — ' . implode(' | ', $parts) . '.');
    }

    /** @return list<string> */
    private static function items(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [];
        }

        $items = [];
        foreach (array_slice(array_values($value), 0, self::MAX_ITEMS) as $item) {
            $text = is_string($item) ? $item : (string) json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
            $items[] = mb_strlen($text) > self::MAX_ITEM_LENGTH ? mb_substr($text, 0, self::MAX_ITEM_LENGTH - 1) . '…' : $text;
        }
        if (count($value) > self::MAX_ITEMS) {
            $items[] = '(+' . (count($value) - self::MAX_ITEMS) . ' more)';
        }

        return $items;
    }
}
