<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** agent-map <- agent-edit <- agent-loop: agent-edit must not know its host. */
final class DependencyDirectionTest extends TestCase
{
    public function testComposerRequiresNoHostPackage(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $requires = array_keys(array_merge($composer['require'], $composer['require-dev'] ?? []));

        foreach ($requires as $package) {
            self::assertDoesNotMatchRegularExpression('~^voku/agent-(loop|recall-compiler|session|kanban|learning)$~', $package);
        }
    }

    public function testSourceHasNoHostNamespaceOrLoopPathDefaults(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src', FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $content = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression('~AgentLoop|\.agent-loop|agent-loop~i', $content, $file->getPathname());
        }
    }
}
