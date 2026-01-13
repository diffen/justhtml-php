<?php

declare(strict_types=1);

namespace JustHTML;

final class MarkdownBuilder
{
    /** @var array<int, string> */
    private array $buf = [];
    private int $newlineCount = 0;
    private bool $pendingSpace = false;

    public function hasOutput(): bool
    {
        return !empty($this->buf);
    }

    private function rstripLastSegment(): void
    {
        if (!$this->buf) {
            return;
        }
        $last = $this->buf[count($this->buf) - 1];
        $stripped = rtrim($last, " \t");
        if ($stripped !== $last) {
            $this->buf[count($this->buf) - 1] = $stripped;
        }
    }

    public function newline(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->pendingSpace = false;
            $this->rstripLastSegment();
            $this->buf[] = "\n";
            if ($this->newlineCount < 2) {
                $this->newlineCount += 1;
            }
        }
    }

    public function ensureNewlines(int $count): void
    {
        while ($this->newlineCount < $count) {
            $this->newline(1);
        }
    }

    public function raw(string $s): void
    {
        if ($s === '') {
            return;
        }

        if ($this->pendingSpace) {
            $first = $s[0];
            if ($first !== ' ' && $first !== "\t" && $first !== "\n" && $first !== "\r" && $first !== "\f") {
                if ($this->buf && $this->newlineCount === 0) {
                    $this->buf[] = ' ';
                }
            }
            $this->pendingSpace = false;
        }

        $this->buf[] = $s;
        if (strpos($s, "\n") !== false) {
            $trailing = 0;
            for ($i = strlen($s) - 1; $i >= 0; $i--) {
                if ($s[$i] === "\n") {
                    $trailing += 1;
                } else {
                    break;
                }
            }
            $this->newlineCount = $trailing > 2 ? 2 : $trailing;
            if ($trailing > 0) {
                $this->pendingSpace = false;
            }
        } else {
            $this->newlineCount = 0;
        }
    }

    public function text(string $s, bool $preserveWhitespace = false): void
    {
        if ($s === '') {
            return;
        }

        if ($preserveWhitespace) {
            $this->raw($s);
            return;
        }

        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r" || $ch === "\f") {
                $this->pendingSpace = true;
                continue;
            }

            if ($this->pendingSpace) {
                if ($this->buf && $this->newlineCount === 0) {
                    $this->buf[] = ' ';
                }
                $this->pendingSpace = false;
            }

            $this->buf[] = $ch;
            $this->newlineCount = 0;
        }
    }

    public function finish(): string
    {
        $out = implode('', $this->buf);
        return trim($out, " \t\n");
    }
}

final class Markdown
{
    private const BLOCK_ELEMENTS = [
        'p' => true,
        'div' => true,
        'section' => true,
        'article' => true,
        'header' => true,
        'footer' => true,
        'main' => true,
        'nav' => true,
        'aside' => true,
        'blockquote' => true,
        'pre' => true,
        'ul' => true,
        'ol' => true,
        'li' => true,
        'hr' => true,
        'h1' => true,
        'h2' => true,
        'h3' => true,
        'h4' => true,
        'h5' => true,
        'h6' => true,
        'table' => true,
    ];

    public static function toMarkdown($node): string
    {
        $builder = new MarkdownBuilder();
        self::toMarkdownWalk($node, $builder, false, 0);
        return $builder->finish();
    }

    private static function markdownEscapeText(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $out = [];
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($ch === '\\' || $ch === '`' || $ch === '*' || $ch === '_' || $ch === '[' || $ch === ']') {
                $out[] = '\\';
            }
            $out[] = $ch;
        }
        return implode('', $out);
    }

    private static function markdownCodeSpan(?string $s): string
    {
        $s = $s ?? '';
        $longest = 0;
        $run = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === '`') {
                $run += 1;
                if ($run > $longest) {
                    $longest = $run;
                }
            } else {
                $run = 0;
            }
        }
        $fence = str_repeat('`', $longest + 1);
        $needsSpace = $s !== '' && ($s[0] === '`' || $s[$len - 1] === '`');
        if ($needsSpace) {
            return $fence . ' ' . $s . ' ' . $fence;
        }
        return $fence . $s . $fence;
    }

    private static function toMarkdownWalk($node, MarkdownBuilder $builder, bool $preserveWhitespace, int $listDepth): void
    {
        $name = $node->name;

        if ($name === '#text') {
            if ($preserveWhitespace) {
                $builder->raw($node->data ?? '');
            } else {
                $builder->text(self::markdownEscapeText((string)($node->data ?? '')), false);
            }
            return;
        }

        if ($name === 'br') {
            $builder->newline(1);
            return;
        }

        if ($name === '#comment' || $name === '!doctype') {
            return;
        }

        if (Str::startsWith($name, '#')) {
            if (!empty($node->children)) {
                foreach ($node->children as $child) {
                    self::toMarkdownWalk($child, $builder, $preserveWhitespace, $listDepth);
                }
            }
            return;
        }

        $tag = strtolower($name);

        if ($tag === 'img') {
            $builder->raw($node->toHtml(0, 2, false));
            return;
        }

        if ($tag === 'table') {
            if ($builder->hasOutput()) {
                $builder->ensureNewlines(2);
            }
            $builder->raw($node->toHtml(0, 2, false));
            $builder->ensureNewlines(2);
            return;
        }

        if ($tag === 'h1' || $tag === 'h2' || $tag === 'h3' || $tag === 'h4' || $tag === 'h5' || $tag === 'h6') {
            if ($builder->hasOutput()) {
                $builder->ensureNewlines(2);
            }
            $level = (int)substr($tag, 1);
            $builder->raw(str_repeat('#', $level));
            $builder->raw(' ');
            if (!empty($node->children)) {
                foreach ($node->children as $child) {
                    self::toMarkdownWalk($child, $builder, false, $listDepth);
                }
            }
            $builder->ensureNewlines(2);
            return;
        }

        if ($tag === 'hr') {
            if ($builder->hasOutput()) {
                $builder->ensureNewlines(2);
            }
            $builder->raw('---');
            $builder->ensureNewlines(2);
            return;
        }

        if ($tag === 'pre') {
            if ($builder->hasOutput()) {
                $builder->ensureNewlines(2);
            }
            $code = $node->toText('', false);
            $builder->raw('```');
            $builder->newline(1);
            if ($code !== '') {
                $builder->raw(rtrim($code, "\n"));
                $builder->newline(1);
            }
            $builder->raw('```');
            $builder->ensureNewlines(2);
            return;
        }

        if ($tag === 'code' && !$preserveWhitespace) {
            $code = $node->toText('', false);
            $builder->raw(self::markdownCodeSpan($code));
            return;
        }

        if ($tag === 'p') {
            if ($builder->hasOutput()) {
                $builder->ensureNewlines(2);
            }
            if (!empty($node->children)) {
                foreach ($node->children as $child) {
                    self::toMarkdownWalk($child, $builder, false, $listDepth);
                }
            }
            $builder->ensureNewlines(2);
            return;
        }

        if ($tag === 'blockquote') {
            if ($builder->hasOutput()) {
                $builder->ensureNewlines(2);
            }
            $inner = new MarkdownBuilder();
            if (!empty($node->children)) {
                foreach ($node->children as $child) {
                    self::toMarkdownWalk($child, $inner, false, $listDepth);
                }
            }
            $text = $inner->finish();
            if ($text !== '') {
                $lines = explode("\n", $text);
                foreach ($lines as $i => $line) {
                    if ($i > 0) {
                        $builder->newline(1);
                    }
                    $builder->raw('> ');
                    $builder->raw($line);
                }
            }
            $builder->ensureNewlines(2);
            return;
        }

        if ($tag === 'ul' || $tag === 'ol') {
            if ($builder->hasOutput()) {
                $builder->ensureNewlines(2);
            }
            $ordered = $tag === 'ol';
            $idx = 1;
            foreach ($node->children ?? [] as $child) {
                if (strtolower((string)$child->name) !== 'li') {
                    continue;
                }
                if ($idx > 1) {
                    $builder->newline(1);
                }
                $indent = str_repeat('  ', $listDepth);
                $marker = $ordered ? ($idx . '. ') : '- ';
                $builder->raw($indent);
                $builder->raw($marker);
                foreach ($child->children ?? [] as $liChild) {
                    self::toMarkdownWalk($liChild, $builder, false, $listDepth + 1);
                }
                $idx += 1;
            }
            $builder->ensureNewlines(2);
            return;
        }

        if ($tag === 'em' || $tag === 'i') {
            $builder->raw('*');
            foreach ($node->children ?? [] as $child) {
                self::toMarkdownWalk($child, $builder, false, $listDepth);
            }
            $builder->raw('*');
            return;
        }

        if ($tag === 'strong' || $tag === 'b') {
            $builder->raw('**');
            foreach ($node->children ?? [] as $child) {
                self::toMarkdownWalk($child, $builder, false, $listDepth);
            }
            $builder->raw('**');
            return;
        }

        if ($tag === 'a') {
            $href = '';
            if ($node->attrs && array_key_exists('href', $node->attrs) && $node->attrs['href'] !== null) {
                $href = (string)$node->attrs['href'];
            }
            $builder->raw('[');
            foreach ($node->children ?? [] as $child) {
                self::toMarkdownWalk($child, $builder, false, $listDepth);
            }
            $builder->raw(']');
            if ($href !== '') {
                $builder->raw('(');
                $builder->raw($href);
                $builder->raw(')');
            }
            return;
        }

        $nextPreserve = $preserveWhitespace || ($tag === 'textarea' || $tag === 'script' || $tag === 'style');
        if (!empty($node->children)) {
            foreach ($node->children as $child) {
                self::toMarkdownWalk($child, $builder, $nextPreserve, $listDepth);
            }
        }

        if ($node instanceof ElementNode && $node->templateContent !== null) {
            self::toMarkdownWalk($node->templateContent, $builder, $nextPreserve, $listDepth);
        }

        if (isset(self::BLOCK_ELEMENTS[$tag])) {
            $builder->ensureNewlines(2);
        }
    }
}
