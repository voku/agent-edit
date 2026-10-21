<?php

declare(strict_types=1);

namespace voku\AgentEdit\Cli;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Plan\SourceEditPlanDocument;

/**
 * `agent-edit edit FILE --find TEXT --replace TEXT`: the one-edit shorthand for a `source_edit_plan`.
 *
 * It only builds the plan document and hands it to `apply`, so a quick fix gets exactly the same anchor proof, API-change
 * guard, syntax check, transaction, receipt and undo as a hand-written plan.
 */
final readonly class EditCommand
{
    /** @param (Closure(string): void)|null $authorizeMutation */
    public function __construct(
        private string $projectRoot,
        private EditEngine $engine = new EditEngine(),
        private ?Closure $authorizeMutation = null,
    ) {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        if ($tokens === [] || in_array($tokens[0], ['help', '--help', '-h'], true)) {
            echo $this->help();

            return 0;
        }

        try {
            [$plan, $passThrough] = $this->plan($tokens);
        } catch (Throwable $exception) {
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 2;
        }

        $json = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        return (new ApplyCommand($this->projectRoot, $this->engine, $this->authorizeMutation, static fn (): string => $json))
            ->run(['-', ...$passThrough]);
    }

    /**
     * @param list<string> $tokens
     * @return array{array<string, mixed>, list<string>}
     */
    private function plan(array $tokens): array
    {
        $path = null;
        /** @var array<string, string> $values */
        $values = [];
        $flags = [];
        $passThrough = [];
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (in_array($token, ['--all', '--allow-signature-change', '--dry-run'], true)) {
                $flags[$token] = true;
                if ($token === '--dry-run') {
                    $passThrough[] = $token;
                }
                continue;
            }
            if (!str_starts_with($token, '--')) {
                if ($path !== null) {
                    throw new InvalidArgumentException('agent-edit edit takes exactly one FILE; use `agent-edit apply -` with a plan for several edits (got extra: ' . $token . ').');
                }
                $path = $token;
                continue;
            }

            $raw = substr($token, 2);
            if (str_contains($raw, '=')) {
                [$name, $value] = explode('=', $raw, 2);
            } else {
                $name = $raw;
                if (!array_key_exists($index + 1, $tokens)) {
                    throw new InvalidArgumentException('Missing value for option --' . $name);
                }
                $value = $tokens[++$index];
            }
            if (!in_array($name, ['find', 'find-file', 'replace', 'replace-file', 'occurrence', 'sha256', 'task', 'output-dir', 'target'], true)) {
                throw new InvalidArgumentException('Unknown option --' . $name . ' for `agent-edit edit`; see `agent-edit edit --help`.');
            }
            if (isset($values[$name])) {
                throw new InvalidArgumentException('Duplicate option --' . $name);
            }
            $values[$name] = $value;
        }

        if ($path === null || $path === '') {
            throw new InvalidArgumentException('agent-edit edit requires the FILE to change (project-relative).');
        }
        $find = $this->text($values, 'find', 'find-file');
        $replace = $this->text($values, 'replace', 'replace-file');

        $edit = ['path' => $this->relative($path), 'find' => $find, 'replace' => $replace];
        if (isset($values['occurrence'])) {
            if (preg_match('/\A[1-9][0-9]*\z/', $values['occurrence']) !== 1) {
                throw new InvalidArgumentException('--occurrence must be a positive integer (1-based).');
            }
            $edit['occurrence'] = (int) $values['occurrence'];
        }
        if (isset($flags['--all'])) {
            $edit['all'] = true;
        }
        if (isset($values['sha256'])) {
            $edit['expected_source_sha256'] = str_starts_with($values['sha256'], 'sha256:') ? $values['sha256'] : 'sha256:' . $values['sha256'];
        }

        $plan = [
            'type' => SourceEditPlanDocument::TYPE,
            'contract_version' => '1.0',
            'target_id' => $values['target'] ?? 'edit:' . $edit['path'],
            'edits' => [$edit],
        ];
        if (isset($flags['--allow-signature-change'])) {
            $plan['allow_signature_change'] = true;
        }
        foreach (['task' => '--task', 'output-dir' => '--output-dir'] as $name => $option) {
            if (isset($values[$name])) {
                $passThrough[] = $option . '=' . $values[$name];
            }
        }

        return [$plan, $passThrough];
    }

    /** @param array<string, string> $values */
    private function text(array $values, string $inline, string $file): string
    {
        if (isset($values[$inline]) === isset($values[$file])) {
            throw new InvalidArgumentException(sprintf('Give exactly one of --%s (inline text) or --%s (read the text from a file).', $inline, $file));
        }
        if (isset($values[$inline])) {
            return $values[$inline];
        }
        $source = $values[$file];
        $resolved = str_starts_with($source, '/') ? $source : rtrim($this->projectRoot, '/') . '/' . $source;
        $content = is_file($resolved) ? file_get_contents($resolved) : false;
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read --' . $file . ': ' . $source);
        }

        return $content;
    }

    /** Accepts a project-relative path, or an absolute path inside the project root (made relative). */
    private function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, '/')) {
            return $path;
        }
        $root = realpath($this->projectRoot);
        $root = is_string($root) ? rtrim(str_replace('\\', '/', $root), '/') . '/' : '';
        if ($root === '/' || !str_starts_with($path, $root)) {
            throw new InvalidArgumentException('FILE must be inside the project root: ' . $path);
        }

        return substr($path, strlen($root));
    }

    private function help(): string
    {
        return <<<'TXT'
Usage:
  agent-edit edit FILE (--find TEXT | --find-file PATH) (--replace TEXT | --replace-file PATH) [options]

Replaces exact text in one existing file through the same safety boundary as every plan: the anchor must occur
exactly once (or be picked with --occurrence / --all), PHP is syntax-checked before publication, the write is
transactional, and a receipt with pre-images is written so `agent-edit undo` can restore the file.

Options:
  --occurrence N            Replace the Nth (1-based) match when --find occurs more than once.
  --all                     Replace every match (at least one must exist).
  --sha256 DIGEST           Refuse unless the file still has this SHA-256.
  --allow-signature-change  Acknowledge that callers of a changed/removed class, function or non-private method
                            were checked. Without it such edits are refused: use an agent-map plan instead.
  --target LABEL            Plan target label. Default: edit:<FILE>.
  --task LABEL              Receipt label. Default: plan-<plan sha256 prefix>.
  --output-dir PATH         Receipt bundle. Default: .agent-edit/receipts/<label>
  --dry-run                 Resolve and validate everything, write nothing.

Text from --find-file / --replace-file is used byte for byte, including a trailing newline: a file written with a
trailing newline only matches text that is followed by one.
Use `--find=TEXT` / `--replace=TEXT` when a value starts with `--`; use `--replace=` to delete the match.
Several edits or new files at once: write a source_edit_plan (see `agent-edit schema`) and run `agent-edit apply PLAN`
or `agent-edit apply -` with the plan on stdin.

TXT;
    }
}
