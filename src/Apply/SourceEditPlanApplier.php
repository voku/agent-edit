<?php

declare(strict_types=1);

namespace voku\AgentEdit\Apply;

use Closure;
use RuntimeException;
use voku\AgentEdit\Failure\EditFailure;
use voku\AgentEdit\Failure\FailureCode;
use voku\AgentEdit\Plan\SourceEdit;
use voku\AgentEdit\Plan\SourceEditPlanDocument;
use voku\AgentEdit\Symbol\PhpSourceEditor;
use voku\AgentEdit\Symbol\SymbolEditException;
use PhpParser\Error;
use PhpParser\ParserFactory;

/**
 * Applies a caller-authored `source_edit_plan` without a Map: anchors are resolved against the current bytes, the whole
 * result is built in memory, API-changing edits are refused unless acknowledged, and publication goes through the same
 * stage / lint / back up / publish / roll back transaction every other capability uses.
 */
final readonly class SourceEditPlanApplier implements MaplessPlanApplier
{
    private FileTransaction $transaction;

    /**
     * @param (Closure(string, string): bool)|null $renameOperation
     * @param (Closure(string): array{exit_code: int, stdout: string, stderr: string})|null $lintOperation
     */
    public function __construct(?Closure $renameOperation = null, ?Closure $lintOperation = null)
    {
        $this->transaction = new FileTransaction($renameOperation, $lintOperation);
    }

    /** @param array<string, mixed> $plan */
    public function preflight(array $plan, string $root): array
    {
        $prepared = $this->prepare(SourceEditPlanDocument::fromArray($plan), $root);
        $files = [];
        $hashes = [];
        foreach ($prepared->files as $file) {
            $absolute = $this->absolute($root, $file['path']);
            $files[$absolute] = $file['after'];
            if ($file['before_sha256'] !== null) {
                $hashes[$absolute] = 'sha256:' . $file['before_sha256'];
            }
        }
        ksort($files, SORT_STRING);
        ksort($hashes, SORT_STRING);

        return [
            'files' => $files,
            'final_paths' => array_combine(array_keys($files), array_keys($files)),
            'source_hashes' => $hashes,
            'plan_type' => SourceEditPlanDocument::TYPE,
            'edit_count' => $prepared->editCount,
            'move_count' => 0,
            'creation_count' => $prepared->creationCount,
        ];
    }

    /** @param array<string, mixed> $plan */
    public function apply(array $plan, string $root): EditResult
    {
        $prepared = $this->prepare(SourceEditPlanDocument::fromArray($plan), $root);
        $warnings = $this->transaction->run($prepared->operations, $root);

        $evidence = [];
        $preImages = [];
        foreach ($prepared->files as $file) {
            if ($file['before'] !== null) {
                $preImages[$file['path']] = $file['before'];
            }
            $evidence[] = [
                'path' => $file['path'],
                'before_sha256' => $file['before_sha256'],
                'after_sha256' => $file['after_sha256'],
                'edits' => $file['edits'],
            ];
        }

        return new EditResult(
            status: 'runner_succeeded',
            exitCode: 0,
            stdout: sprintf(
                "%s applied %d edit(s) and %d creation(s) across %d file(s).\n",
                SourceEditPlanDocument::TYPE,
                $prepared->editCount,
                $prepared->creationCount,
                count($prepared->files),
            ),
            stderr: $warnings === [] ? '' : "source_edit_plan completed with cleanup warning(s):\n- " . implode("\n- ", $warnings),
            evidence: [
                'files' => $evidence,
                'acknowledged_api_changes' => $prepared->acknowledgedApiChanges,
                'pre_images' => $preImages,
            ],
        );
    }

    /**
     * Resolves every anchor and builds every final content. Pure: nothing is written.
     *
     * @throws RuntimeException with an actionable message when any edit cannot be proven
     */
    public function prepare(SourceEditPlanDocument $plan, string $root): SourceEditPreparation
    {
        $realRoot = realpath($root);
        if (!is_string($realRoot)) {
            throw new RuntimeException('Unable to resolve the project root.');
        }
        $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');

        /** @var array<string, string> $original */
        $original = [];
        /** @var array<string, list<array{int, int, string}>> $byFile */
        $byFile = [];
        /** @var array<string, list<array{range: array{int, int}, operation: array<string, mixed>}>> $removals the remove_symbol edits of each file */
        $removals = [];
        foreach ($plan->edits as $position => $edit) {
            $path = $this->existingFile($realRoot, $edit->path, $position);
            $content = $original[$path] ??= $this->read($path, $edit->path);
            if ($edit->expectedSourceSha256 !== null && !hash_equals($edit->expectedSourceSha256, 'sha256:' . hash('sha256', $content))) {
                throw new EditFailure(sprintf('edits[%d]: %s changed since you read it (expected_source_sha256 no longer matches). Re-read the file and rebuild the edit.', $position, $edit->path), FailureCode::AnchorMissing, ['path' => $edit->path, 'edit' => $position]);
            }
            foreach ($this->resolve($edit, $content, $position) as $range) {
                $byFile[$path][] = $range;
                if (($edit->symbolOperation['op'] ?? null) === 'remove_symbol') {
                    $removals[$path][] = ['range' => [$range[0], $range[1]], 'operation' => $edit->symbolOperation];
                }
            }
        }

        $operations = [];
        $files = [];
        $acknowledged = [];
        $guard = new SignatureGuard();
        ksort($byFile, SORT_STRING);
        foreach ($byFile as $path => $ranges) {
            $before = $original[$path];
            $edits = count($ranges);
            $ranges = $this->mergeRemovals($before, $this->relative($realRoot, $path), $ranges, $removals[$path] ?? []);
            // A pure insertion at the offset where another edit starts goes first, so both can apply.
            usort($ranges, static fn (array $left, array $right): int => [$left[0], $left[1] - $left[0]] <=> [$right[0], $right[1] - $right[0]]);
            $after = '';
            $cursor = 0;
            foreach ($ranges as [$start, $end, $replacement]) {
                if ($start < $cursor) {
                    throw new EditFailure(sprintf(
                        'Two edits overlap in %s (around line %d). Merge them into one edit or anchor them on disjoint text.',
                        $this->relative($realRoot, $path),
                        AnchorHint::line($before, $start),
                    ), FailureCode::EditsOverlap, ['path' => $this->relative($realRoot, $path), 'line' => AnchorHint::line($before, $start)]);
                }
                $after .= substr($before, $cursor, $start - $cursor) . $replacement;
                $cursor = $end;
            }
            $after .= substr($before, $cursor);

            if (str_ends_with(strtolower($path), '.php')) {
                // Before the API guard (which cannot judge unparsable code) and before any staging, so a dry run
                // reports a broken result with its line instead of `prepared`.
                $this->assertParses($after, $this->relative($realRoot, $path));
                $changes = $guard->changes($before, $after);
                if ($changes !== []) {
                    if (!$plan->allowSignatureChange) {
                        throw new EditFailure($this->apiChangeRefusal($this->relative($realRoot, $path), $changes), FailureCode::ApiChangeRefused, ['path' => $this->relative($realRoot, $path), 'changes' => $changes]);
                    }
                    foreach ($changes as $change) {
                        $acknowledged[] = $this->relative($realRoot, $path) . ': ' . $change;
                    }
                }
            }

            $beforeHash = hash('sha256', $before);
            $operations[] = FileOperation::write($path, $after, $beforeHash);
            $files[] = [
                'path' => $this->relative($realRoot, $path),
                'before_sha256' => $beforeHash,
                'after_sha256' => hash('sha256', $after),
                'before' => $before,
                'after' => $after,
                'edits' => $edits,
            ];
        }

        foreach ($plan->creates as $create) {
            $absolute = $realRoot . '/' . $create->path;
            if (file_exists($absolute) || is_link($absolute)) {
                throw new EditFailure('creates: ' . $create->path . ' already exists. Use an edit to change it, or pick another path.', FailureCode::TargetExists, ['path' => $create->path]);
            }
            $operations[] = FileOperation::write($absolute, $create->content, null);
            $files[] = [
                'path' => $create->path,
                'before_sha256' => null,
                'after_sha256' => hash('sha256', $create->content),
                'before' => null,
                'after' => $create->content,
                'edits' => 0,
            ];
        }

        usort($files, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
        if ($operations === []) {
            throw new RuntimeException('The plan changes nothing (every edit was already in place, for example an existing import); no file was touched.');
        }

        return new SourceEditPreparation($operations, $files, count($plan->edits), count($plan->creates), $acknowledged);
    }

    /**
     * @param list<string> $changes
     */
    private function apiChangeRefusal(string $path, array $changes): string
    {
        $listed = array_slice($changes, 0, 3);
        $more = count($changes) > 3 ? sprintf(' (+%d more)', count($changes) - 3) : '';

        return sprintf(
            'The edit changes an API other files may call in %s: %s%s. A direct edit has no caller evidence. Use an agent-map plan (rename/move/removal) so callers are proven, or, after checking the callers yourself, set "allow_signature_change": true.',
            $path,
            implode('; ', $listed),
            $more,
        );
    }

    /** @return list<array{int, int, string}> start offset, end offset (exclusive), replacement */
    private function resolve(SourceEdit $edit, string $content, int $position): array
    {
        if ($edit->symbolOperation !== null) {
            return $this->resolveSymbol($edit->symbolOperation, $edit->path, $content, $position);
        }

        $find = $edit->find;
        $replace = $edit->replace;
        $offsets = AnchorHint::offsets($content, $find);
        if ($offsets === [] && ($crlf = AnchorHint::crlfVariant($content, $find)) !== null) {
            // The file is CRLF throughout and the multi-line text was written with LF: match and write it in the file's own line ending.
            $offsets = AnchorHint::offsets($content, $crlf);
            if ($offsets !== []) {
                $find = $crlf;
                $replace = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $replace));
            }
        }
        if ($offsets === []) {
            throw new EditFailure(sprintf('edits[%d]: %s', $position, AnchorHint::missing($edit->path, $content, $edit->find)), FailureCode::AnchorMissing, ['path' => $edit->path, 'edit' => $position]);
        }

        if ($edit->all) {
            $selected = $offsets;
        } elseif ($edit->occurrence !== null) {
            if ($edit->occurrence > count($offsets)) {
                throw new EditFailure(sprintf('edits[%d]: occurrence %d requested but `find` occurs only %d time(s) in %s.', $position, $edit->occurrence, count($offsets), $edit->path), FailureCode::AnchorMissing, ['path' => $edit->path, 'edit' => $position]);
            }
            $selected = [$offsets[$edit->occurrence - 1]];
        } elseif (count($offsets) > 1) {
            throw new EditFailure(sprintf('edits[%d]: %s', $position, AnchorHint::ambiguous($edit->path, $content, $edit->find, $offsets)), FailureCode::AnchorAmbiguous, ['path' => $edit->path, 'edit' => $position, 'matches' => count($offsets)]);
        } else {
            $selected = $offsets;
        }

        return array_map(
            static fn (int $offset): array => [$offset, $offset + strlen($find), $replace],
            $selected,
        );
    }

    /**
     * Removing neighbouring declarations in one plan. Each `remove_symbol` range also takes the blank line it makes
     * redundant, and for neighbours that is the same blank line (or the removals together leave one behind), so the
     * ranges overlap or the result is not what removing the declarations one after the other gives. Removals that
     * overlap or are separated by whitespace only are therefore applied one after the other and replaced by the one
     * range that turns the file as read into that result. Other edits keep their own ranges.
     *
     * @param list<array{int, int, string}> $ranges
     * @param list<array{range: array{int, int}, operation: array<string, mixed>}> $removals
     * @return list<array{int, int, string}>
     */
    private function mergeRemovals(string $before, string $path, array $ranges, array $removals): array
    {
        if (count($removals) < 2) {
            return $ranges;
        }

        usort($removals, static fn (array $left, array $right): int => $left['range'] <=> $right['range']);
        /** @var list<list<array{range: array{int, int}, operation: array<string, mixed>}>> $clusters */
        $clusters = [];
        $clusterEnd = -1;
        foreach ($removals as $removal) {
            [$start, $end] = $removal['range'];
            $last = count($clusters) - 1;
            if ($last >= 0 && ($start < $clusterEnd || trim(substr($before, $clusterEnd, $start - $clusterEnd)) === '')) {
                $clusters[$last][] = $removal;
                $clusterEnd = max($clusterEnd, $end);
                continue;
            }
            $clusters[] = [$removal];
            $clusterEnd = $end;
        }

        $editor = new PhpSourceEditor();
        foreach ($clusters as $cluster) {
            if (count($cluster) < 2) {
                continue;
            }
            $after = $before;
            foreach (array_reverse($cluster) as $removal) {
                try {
                    $after = $editor->apply($after, $removal['operation'], $path)['content'];
                } catch (SymbolEditException $exception) {
                    throw new RuntimeException(sprintf('%s (%s): %s', $path, (string) $removal['operation']['op'], $exception->getMessage()), 0, $exception);
                }
            }

            $keys = [];
            foreach ($cluster as $removal) {
                $keys[$removal['range'][0] . ':' . $removal['range'][1]] = true;
            }
            $ranges = array_values(array_filter(
                $ranges,
                static fn (array $range): bool => $range[2] !== '' || !isset($keys[$range[0] . ':' . $range[1]]),
            ));

            $limit = min(strlen($before), strlen($after));
            $prefix = 0;
            while ($prefix < $limit && $before[$prefix] === $after[$prefix]) {
                ++$prefix;
            }
            $suffix = 0;
            while ($suffix < $limit - $prefix && $before[strlen($before) - 1 - $suffix] === $after[strlen($after) - 1 - $suffix]) {
                ++$suffix;
            }
            $ranges[] = [$prefix, strlen($before) - $suffix, substr($after, $prefix, strlen($after) - $prefix - $suffix)];
        }

        return $ranges;
    }

    /**
     * Runs one symbol-addressed operation on the file as read and takes the one byte range it replaces (a pure
     * insertion is a zero-length range), so it is checked for overlaps, guarded and published exactly like a
     * `find` / `replace` edit.
     *
     * @param array<string, mixed> $operation
     * @return list<array{int, int, string}>
     */
    private function resolveSymbol(array $operation, string $path, string $content, int $position): array
    {
        if (!str_ends_with(strtolower($path), '.php')) {
            throw new RuntimeException(sprintf('edits[%d]: %s is not a .php file; symbol operations only edit PHP. Use find/replace.', $position, $path));
        }
        try {
            $range = (new PhpSourceEditor())->apply($content, $operation, $path)['range'];
        } catch (SymbolEditException $exception) {
            throw new RuntimeException(sprintf('edits[%d] (%s): %s', $position, (string) $operation['op'], $exception->getMessage()), 0, $exception);
        }

        return $range === null ? [] : [$range];
    }

    private function assertParses(string $content, string $path): void
    {
        try {
            (new ParserFactory())->createForHostVersion()->parse($content);
        } catch (Error $error) {
            $line = $error->getStartLine();
            $lines = explode("\n", $content);
            $text = $line > 0 && isset($lines[$line - 1]) ? trim($lines[$line - 1]) : '';
            throw new RuntimeException(sprintf(
                'Edited PHP failed syntax validation before publication in %s: %s%s. No file was changed.',
                $path,
                $error->getRawMessage(),
                $line > 0 ? sprintf(' on line %d%s', $line, $text === '' ? '' : ' (`' . $text . '`)') : '',
            ));
        }
    }

    private function existingFile(string $realRoot, string $relative, int $position): string
    {
        $path = $realRoot . '/' . $relative;
        if (is_link($path) || !is_file($path)) {
            throw new EditFailure(sprintf('edits[%d]: %s does not exist as a regular file. To add a new file use "creates".', $position, $relative), FailureCode::InputNotFound, ['path' => $relative]);
        }
        $real = realpath($path);
        if (!is_string($real) || !str_starts_with(str_replace('\\', '/', $real), $realRoot . '/')) {
            throw new RuntimeException(sprintf('edits[%d]: %s resolves outside the project root.', $position, $relative));
        }

        return str_replace('\\', '/', $real);
    }

    private function read(string $path, string $display): string
    {
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read ' . $display . '.');
        }
        if (str_contains($content, "\0")) {
            throw new RuntimeException($display . ' looks binary (contains a NUL byte); direct edits only change text files.');
        }

        return $content;
    }

    private function relative(string $realRoot, string $path): string
    {
        return substr($path, strlen($realRoot) + 1);
    }

    private function absolute(string $root, string $relative): string
    {
        $realRoot = realpath($root);

        return rtrim(str_replace('\\', '/', is_string($realRoot) ? $realRoot : $root), '/') . '/' . $relative;
    }
}
