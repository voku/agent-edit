<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentEdit\Verify\ImportResidueCheck;

/** A pure-deletion plan edit can orphan the `use` import only the deleted text needed; verify reports it. */
final class ImportResidueCheckTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-edit-import-residue-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testImportUsedOnlyByTheDeletedMethodKeepsVerificationIncomplete(): void
    {
        $this->source("use Demo\\Util\\Gone;\nuse Demo\\Util\\Kept;\n", "public function a(): Kept\n    {\n        return new Kept();\n    }\n");
        $result = $this->check('method_removal_plan', 'new Gone();');

        self::assertSame('incomplete', $result['status']);
        self::assertSame('open', $result['import_residue']['status']);
        self::assertSame([['path' => 'src/A.php', 'import' => 'Demo\\Util\\Gone', 'alias' => 'Gone', 'line' => 7]], $result['import_residue']['imports']);
    }

    public function testImportStillUsedElsewhereIsNotResidue(): void
    {
        $this->source("use Demo\\Util\\Kept;\n", "public function a(): Kept\n    {\n        return new Kept();\n    }\n");
        $result = $this->check('method_move_plan', 'return Kept::make();');

        self::assertSame('passed', $result['status']);
        self::assertSame('clear', $result['import_residue']['status']);
    }

    public function testAliasedImportAndDocblockMentionAreHonoured(): void
    {
        $this->source("use Demo\\Util\\Gone as Other;\nuse Demo\\Util\\Doc;\n", "/** @return Doc */\n    public function a()\n    {\n    }\n");
        $result = $this->check('method_removal_plan', 'new Other(); new Doc();');

        self::assertSame(['Other'], array_column($result['import_residue']['imports'], 'alias'));
    }

    public function testAcceptedDispositionDoesNotBlock(): void
    {
        $this->source("use Demo\\Util\\Gone;\n", "public function a()\n    {\n    }\n");
        $result = (new ImportResidueCheck())->apply(['status' => 'passed'], $this->plan('method_removal_plan', 'new Gone();'), $this->root, 'cleanup follows');

        self::assertSame('passed', $result['status']);
        self::assertSame('accepted', $result['import_residue']['status']);
        self::assertSame('cleanup follows', $result['import_residue']['disposition']);
    }

    public function testOtherPlanTypesPassThroughUnchanged(): void
    {
        $result = ['status' => 'passed'];

        self::assertSame($result, (new ImportResidueCheck())->apply($result, $this->plan('method_rename_plan', 'new Gone();'), $this->root, null));
    }

    private function source(string $uses, string $members): void
    {
        file_put_contents($this->root . '/src/A.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\n" . $uses . "\nfinal class A\n{\n    " . $members . "}\n");
    }

    /** @return array<string, mixed> */
    private function check(string $type, string $deleted): array
    {
        return (new ImportResidueCheck())->apply(['status' => 'passed'], $this->plan($type, $deleted), $this->root, null);
    }

    /** @return array<string, mixed> */
    private function plan(string $type, string $deleted): array
    {
        return ['type' => $type, 'edits' => [['path' => 'src/A.php', 'expected' => $deleted, 'replacement' => '']]];
    }
}
