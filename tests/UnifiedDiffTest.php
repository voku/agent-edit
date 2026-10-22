<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentEdit\Preview\UnifiedDiff;
use voku\AgentEdit\Tests\Support\UnifiedPatch;

final class UnifiedDiffTest extends TestCase
{
    public function testIdenticalTextProducesNoDiff(): void
    {
        self::assertSame(['added' => 0, 'removed' => 0, 'diff' => ''], UnifiedDiff::between("a\nb\n", "a\nb\n", 'a/f', 'b/f'));
    }

    public function testSingleLineChangeHasHeadersContextAndCounts(): void
    {
        $old = "one\ntwo\nthree\nfour\nfive\nsix\nseven\n";
        $new = "one\ntwo\nthree\nFOUR\nfive\nsix\nseven\n";

        $result = UnifiedDiff::between($old, $new, 'a/f.php', 'b/f.php');

        self::assertSame(1, $result['added']);
        self::assertSame(1, $result['removed']);
        self::assertSame("--- a/f.php\n+++ b/f.php\n@@ -1,7 +1,7 @@\n one\n two\n three\n-four\n+FOUR\n five\n six\n seven\n", $result['diff']);
        self::assertSame($new, UnifiedPatch::apply($old, $result['diff']));
    }

    public function testDistantChangesBecomeSeparateHunksAndNearbyOnesMerge(): void
    {
        $old = implode("\n", range(1, 40)) . "\n";
        $far = str_replace("\n3\n", "\nX3\n", $old);
        $far = str_replace("\n38\n", "\nX38\n", $far);
        $near = str_replace("\n10\n", "\nX10\n", $old);
        $near = str_replace("\n14\n", "\nX14\n", $near);

        self::assertSame(2, substr_count(UnifiedDiff::between($old, $far, 'a', 'b')['diff'], "\n@@ "), 'far apart: two hunks');
        self::assertSame(1, substr_count(UnifiedDiff::between($old, $near, 'a', 'b')['diff'], "\n@@ "), 'within twice the context: one hunk');
        self::assertSame($far, UnifiedPatch::apply($old, UnifiedDiff::between($old, $far, 'a', 'b')['diff']));
        self::assertSame($near, UnifiedPatch::apply($old, UnifiedDiff::between($old, $near, 'a', 'b')['diff']));
    }

    public function testHunksMergeExactlyWhenTheirContextTouches(): void
    {
        $old = implode("\n", range(1, 40)) . "\n";
        $touching = str_replace(["\n10\n", "\n17\n"], ["\nX10\n", "\nX17\n"], $old);
        $apart = str_replace(["\n10\n", "\n18\n"], ["\nX10\n", "\nX18\n"], $old);

        // With three lines of context, changes at 10 and 17 cover 7-13 and 14-20: adjacent, so one hunk.
        self::assertSame(1, substr_count(UnifiedDiff::between($old, $touching, 'a', 'b')['diff'], "\n@@ "));
        self::assertSame(2, substr_count(UnifiedDiff::between($old, $apart, 'a', 'b')['diff'], "\n@@ "));
        self::assertSame($touching, UnifiedPatch::apply($old, UnifiedDiff::between($old, $touching, 'a', 'b')['diff']));
        self::assertSame($apart, UnifiedPatch::apply($old, UnifiedDiff::between($old, $apart, 'a', 'b')['diff']));
    }

    public function testWholeFileRemovalAndCreationUseEmptyRanges(): void
    {
        $removed = UnifiedDiff::between("a\nb\n", '', 'a/f', '/dev/null');
        self::assertSame(['added' => 0, 'removed' => 2], ['added' => $removed['added'], 'removed' => $removed['removed']]);
        self::assertStringContainsString('@@ -1,2 +0,0 @@', $removed['diff']);
        self::assertSame('', UnifiedPatch::apply("a\nb\n", $removed['diff']));

        $created = UnifiedDiff::between('', "a\nb\n", '/dev/null', 'b/f');
        self::assertStringContainsString('@@ -0,0 +1,2 @@', $created['diff']);
        self::assertSame("a\nb\n", UnifiedPatch::apply('', $created['diff']));
    }

    public function testAMissingFinalNewlineIsAChangeAndIsMarked(): void
    {
        $old = "a\nb";
        $new = "a\nb\n";

        $result = UnifiedDiff::between($old, $new, 'a/f', 'b/f');

        self::assertSame(1, $result['added']);
        self::assertSame(1, $result['removed']);
        self::assertStringContainsString("\\ No newline at end of file\n", $result['diff']);
        self::assertSame($new, UnifiedPatch::apply($old, $result['diff']));
        self::assertSame($old, UnifiedPatch::apply($new, UnifiedDiff::between($new, $old, 'a/f', 'b/f')['diff']));
    }

    public function testAnEditInAFileWithoutFinalNewlineKeepsItsMarker(): void
    {
        $old = "x\ny\nz";
        $new = "x\nY\nz";

        $diff = UnifiedDiff::between($old, $new, 'a', 'b')['diff'];

        self::assertSame($new, UnifiedPatch::apply($old, $diff));
        self::assertStringContainsString("\\ No newline at end of file\n", $diff);
    }

    public function testRepeatedLinesAreAlignedMinimally(): void
    {
        $old = str_repeat("x\n", 6);
        $new = str_repeat("x\n", 3) . "y\n" . str_repeat("x\n", 3);

        $result = UnifiedDiff::between($old, $new, 'a', 'b');

        self::assertSame(['added' => 1, 'removed' => 0], ['added' => $result['added'], 'removed' => $result['removed']]);
        self::assertSame($new, UnifiedPatch::apply($old, $result['diff']));
    }

    public function testAHugeChangedRegionDegradesToOneReplacedBlockInsteadOfExhaustingMemory(): void
    {
        $old = implode("\n", range(1, 2500)) . "\n";
        $new = implode("\n", range(10001, 12500)) . "\n";

        $result = UnifiedDiff::between($old, $new, 'a', 'b');

        self::assertSame(2500, $result['removed']);
        self::assertSame(2500, $result['added']);
        self::assertSame($new, UnifiedPatch::apply($old, $result['diff']));
    }

    public function testCarriageReturnsAndMultibyteTextRoundTrip(): void
    {
        $old = "a\r\nähnlich\r\nc\r\n";
        $new = "a\r\nähnlich!\r\nc\r\n";

        self::assertSame($new, UnifiedPatch::apply($old, UnifiedDiff::between($old, $new, 'a', 'b')['diff']));
    }

    /** The patch oracle is independent of the diff code: 1500 seeded random edits must all reproduce the new text exactly. */
    public function testRandomEditsRoundTripThroughTheIndependentPatchOracle(): void
    {
        mt_srand(20260421);
        for ($case = 0; $case < 1500; ++$case) {
            $before = [];
            for ($line = mt_rand(0, 30); $line > 0; --$line) {
                $before[] = 'l' . mt_rand(0, 9);
            }
            $after = $before;
            for ($edit = mt_rand(0, 5); $edit > 0; --$edit) {
                $position = mt_rand(0, count($after));
                match (mt_rand(0, 2)) {
                    0 => array_splice($after, $position, 0, ['n' . mt_rand(0, 99)]),
                    1 => $after === [] ? null : array_splice($after, min($position, count($after) - 1), 1),
                    default => $after === [] ? null : $after[min($position, count($after) - 1)] = 'c' . mt_rand(0, 99),
                };
            }
            $old = implode("\n", $before) . ($before !== [] && mt_rand(0, 3) > 0 ? "\n" : '');
            $new = implode("\n", $after) . ($after !== [] && mt_rand(0, 3) > 0 ? "\n" : '');

            $result = UnifiedDiff::between($old, $new, 'a/f', 'b/f', mt_rand(0, 4));

            if ($old === $new) {
                self::assertSame('', $result['diff'], 'case ' . $case);
                continue;
            }
            self::assertNotSame('', $result['diff'], 'case ' . $case . ' differs but produced no diff');
            self::assertSame($new, UnifiedPatch::apply($old, $result['diff']), 'case ' . $case . "\n" . $result['diff']);
        }
    }
}
