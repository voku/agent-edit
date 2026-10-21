<?php

declare(strict_types=1);

namespace voku\AgentEdit\Cli;

use RuntimeException;
use Throwable;
use voku\AgentEdit\EditEngine;

/** `agent-edit undo --bundle=PATH`: restores the pre-edit bytes recorded by a direct-edit receipt. */
final readonly class UndoCommand
{
    public function __construct(
        private string $projectRoot,
        private EditEngine $engine = new EditEngine(),
    ) {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        if ($tokens === [] || in_array($tokens[0], ['help', '--help', '-h'], true)) {
            echo "Usage:\n  agent-edit undo --bundle=PATH\n\nRestores the exact pre-edit bytes of a source_edit_plan receipt bundle and removes the files it created.\nRefuses when any touched file changed after the edit, so later work is never overwritten.\n";

            return 0;
        }

        try {
            $bundle = '';
            foreach ($tokens as $token) {
                if (str_starts_with($token, '--bundle=')) {
                    $bundle = substr($token, strlen('--bundle='));
                    continue;
                }
                throw new RuntimeException('Unknown argument for `agent-edit undo`: ' . $token);
            }
            if ($bundle === '') {
                throw new RuntimeException('agent-edit undo requires --bundle=PATH.');
            }
            $result = $this->engine->undo($this->projectRoot, $bundle);
        } catch (Throwable $exception) {
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 1;
        }

        echo 'Undone: ' . (string) ($result['task_id'] ?? '') . "\n";
        foreach (is_array($result['restored'] ?? null) ? $result['restored'] : [] as $path) {
            echo '- restored ' . (string) $path . "\n";
        }
        foreach (is_array($result['removed'] ?? null) ? $result['removed'] : [] as $path) {
            echo '- removed ' . (string) $path . "\n";
        }

        return 0;
    }
}
