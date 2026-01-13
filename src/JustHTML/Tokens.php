<?php

declare(strict_types=1);

namespace JustHTML;

final class Tag
{
    public const START = 0;
    public const END = 1;

    public int $kind;
    public string $name;
    /** @var array<string, string|null> */
    public array $attrs;
    public bool $selfClosing;

    /** @param array<string, string|null>|null $attrs */
    public function __construct(int $kind, string $name, ?array $attrs = null, bool $selfClosing = false)
    {
        $this->kind = $kind;
        $this->name = $name;
        $this->attrs = $attrs ?? [];
        $this->selfClosing = $selfClosing;
    }
}

final class CharacterTokens
{
    public string $data;

    public function __construct(string $data)
    {
        $this->data = $data;
    }
}

final class CommentToken
{
    public string $data;

    public function __construct(string $data)
    {
        $this->data = $data;
    }
}

final class Doctype
{
    public ?string $name;
    public ?string $publicId;
    public ?string $systemId;
    public bool $forceQuirks;

    public function __construct(
        ?string $name = null,
        ?string $publicId = null,
        ?string $systemId = null,
        bool $forceQuirks = false
    ) {
        $this->name = $name;
        $this->publicId = $publicId;
        $this->systemId = $systemId;
        $this->forceQuirks = $forceQuirks;
    }
}

final class DoctypeToken
{
    public Doctype $doctype;

    public function __construct(Doctype $doctype)
    {
        $this->doctype = $doctype;
    }
}

final class EOFToken
{
}

final class TokenSinkResult
{
    public const Continue = 0;
    public const Plaintext = 1;
}

final class ParseError
{
    public string $code;
    public ?int $line;
    public ?int $column;
    public string $message;
    public ?string $sourceHtml;
    public ?int $endColumn;

    public function __construct(
        string $code,
        ?int $line = null,
        ?int $column = null,
        ?string $message = null,
        ?string $sourceHtml = null,
        ?int $endColumn = null
    ) {
        $this->code = $code;
        $this->line = $line;
        $this->column = $column;
        $this->message = $message ?? $code;
        $this->sourceHtml = $sourceHtml;
        $this->endColumn = $endColumn;
    }

    public function __toString(): string
    {
        if ($this->line !== null && $this->column !== null) {
            if ($this->message !== $this->code) {
                return "({$this->line},{$this->column}): {$this->code} - {$this->message}";
            }
            return "({$this->line},{$this->column}): {$this->code}";
        }
        if ($this->message !== $this->code) {
            return "{$this->code} - {$this->message}";
        }
        return $this->code;
    }
}
