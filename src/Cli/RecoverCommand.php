<?php

declare(strict_types=1);

namespace voku\AgentEdit\Cli;

use Closure;
use JsonException;
use RuntimeException;
use Throwable;
use voku\AgentEdit\EditEngine;

/**
 * `agent-edit recover --bundle=PATH [--format=text|json]`: resolves an unfinished publication recorded in a receipt
 * bundle (lost receipt, incomplete rollback, killed process). Exit 0 when the bundle is consistent afterwards
 * (`published`, `not_published`, `rolled_back`, `nothing_to_recover`), 1 on `conflict` or error.
 */
final readonly class RecoverCommand
{
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
            echo <<<'TXT'
Usage:
  agent-edit recover --bundle=PATH [--format=text|json]

Resolves an unfinished publication recorded in a receipt bundle (publication-journal.json), for example after the
receipt could not be written or the process was killed. Every journaled file is compared with its recorded digests:
  published      all files have the published bytes: the receipt is written, changed files observed again
  not_published  all files have their original bytes: a failure receipt is written
  rolled_back    a mix: every file is restored from the bundle's pre-images and a failure receipt is written
  conflict       nothing is touched, the journal stays, exit 1. Either a file matches neither digest
                 (unexpected_file: changed by someone else), or the bundle already has a success receipt
                 while the files are not published (receipt_claims_publication: put the published bytes back)
Publisher temporary files this publication left next to its paths are removed. Run `verify` afterwards as usual.

TXT;

            return 0;
        }

        try {
            $bundle = '';
            $format = 'text';
            foreach ($tokens as $token) {
                if (str_starts_with($token, '--bundle=')) {
                    $bundle = substr($token, strlen('--bundle='));
                    continue;
                }
                if (str_starts_with($token, '--format=')) {
                    $format = substr($token, strlen('--format='));
                    if (!in_array($format, ['text', 'json'], true)) {
                        throw new RuntimeException('--format must be text or json.');
                    }
                    continue;
                }
                throw new RuntimeException('Unknown argument for `agent-edit recover`: ' . $token);
            }
            if ($bundle === '') {
                throw new RuntimeException('agent-edit recover requires --bundle=PATH.');
            }
            $result = $this->engine->recover($this->projectRoot, $bundle, $this->authorizeMutation);
        } catch (Throwable $exception) {
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 1;
        }

        $status = (string) ($result['status'] ?? '');
        if ($format === 'json') {
            try {
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            } catch (JsonException $exception) {
                fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

                return 1;
            }
        } else {
            echo 'Recovery: ' . $status . "\n";
            foreach (is_array($result['files'] ?? null) ? $result['files'] : [] as $file) {
                if (is_array($file)) {
                    echo '- ' . (string) ($file['path'] ?? '') . ': ' . (string) ($file['state'] ?? '') . "\n";
                }
            }
            foreach (is_array($result['removed_temporary_files'] ?? null) ? $result['removed_temporary_files'] : [] as $path) {
                echo '- removed temporary file ' . (string) $path . "\n";
            }
            if ($status === 'conflict') {
                fwrite(STDERR, ($result['conflict'] ?? null) === 'receipt_claims_publication'
                    ? "[ERROR] The bundle already records a successful publication, but the journaled files do not have their published bytes; nothing was changed. Put the published bytes back (or remove the receipt deliberately), then run recover again.\n"
                    : "[ERROR] A journaled file matches neither its original nor its published bytes; nothing was changed. Resolve those files by hand, then run recover again.\n");
            }
        }

        return $status === 'conflict' ? 1 : 0;
    }
}
