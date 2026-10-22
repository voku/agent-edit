<?php

declare(strict_types=1);

namespace voku\AgentEdit\Symbol;

use PhpParser\Node;

/** One declaration found in a PHP file, addressable from an edit request. */
final readonly class SourceSymbol
{
    /**
     * @param string $kind class, interface, trait, enum, function, method, property, constant or case
     * @param string $owner fully qualified class-like name for members, empty otherwise
     * @param int $declarationCount number of names the statement declares (`public $a, $b;` declares 2)
     */
    public function __construct(
        public string $kind,
        public string $name,
        public string $fqn,
        public string $owner,
        public Node $node,
        public ?Node $ownerNode,
        public int $declarationCount = 1,
    ) {
    }

    public function isClassLike(): bool
    {
        return in_array($this->kind, ['class', 'interface', 'trait', 'enum'], true);
    }

    public function isMember(): bool
    {
        return $this->owner !== '';
    }

    /** The form an edit request uses to address this symbol, for example `App\Foo::bar()` or `App\Foo::$baz`. */
    public function display(): string
    {
        return match ($this->kind) {
            'method' => $this->owner . '::' . $this->name . '()',
            'property' => $this->owner . '::$' . $this->name,
            'constant', 'case' => $this->owner . '::' . $this->name,
            'function' => $this->fqn . '()',
            default => $this->fqn,
        };
    }

    /** First byte of the declaration including its attributes, without the doc comment. */
    public function startPos(): int
    {
        return $this->node->getStartFilePos();
    }

    /** First byte of the declaration's doc comment, or of the declaration itself when it has none. */
    public function docStartPos(): int
    {
        $doc = $this->node->getDocComment();

        return $doc !== null ? $doc->getStartFilePos() : $this->node->getStartFilePos();
    }

    public function endPos(): int
    {
        return $this->node->getEndFilePos();
    }
}
