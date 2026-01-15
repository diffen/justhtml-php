<?php

declare(strict_types=1);

namespace JustHTML;

final class StrictModeError extends \RuntimeException
{
    public ParseError $error;

    public function __construct(ParseError $error)
    {
        $this->error = $error;
        parent::__construct((string)$error);
    }
}

final class JustHTML
{
    public bool $debug;
    public ?string $encoding;
    /** @var array<int, ParseError> */
    public array $errors;
    public ?FragmentContext $fragment_context;
    public SimpleDomNode $root;
    public Tokenizer $tokenizer;
    public TreeBuilder $tree_builder;

    /** @param array<string, mixed> $options */
    public function __construct($html, array $options = [])
    {
        $this->debug = (bool)($options['debug'] ?? false);
        $this->fragment_context = $options['fragment_context'] ?? null;
        $this->encoding = null;

        $collect_errors = (bool)($options['collect_errors'] ?? false);
        $strict = (bool)($options['strict'] ?? false);
        $should_collect = $collect_errors || $strict;
        $transport_encoding = $options['encoding'] ?? null;
        $iframe_srcdoc = (bool)($options['iframe_srcdoc'] ?? false);
        $tokenizer_opts = $options['tokenizer_opts'] ?? null;
        $tree_builder = $options['tree_builder'] ?? null;
        $is_bytes = (bool)($options['bytes'] ?? false);

        if ($html === null) {
            $html_str = '';
        } elseif ($is_bytes) {
            [$html_str, $chosen] = Encoding::decodeHtml((string)$html, $transport_encoding);
            $this->encoding = $chosen;
        } else {
            $html_str = (string)$html;
        }

        $this->tree_builder = $tree_builder ?? new TreeBuilder(
            $this->fragment_context,
            $iframe_srcdoc,
            $should_collect
        );
        $opts = $tokenizer_opts instanceof TokenizerOpts ? $tokenizer_opts : new TokenizerOpts();

        if ($this->fragment_context !== null && $this->fragment_context->namespace === null) {
            $tag_name = strtolower($this->fragment_context->tagName);
            if (in_array($tag_name, ['textarea', 'title', 'style'], true)) {
                $opts->initialState = Tokenizer::RAWTEXT;
                $opts->initialRawtextTag = $tag_name;
            } elseif (in_array($tag_name, ['plaintext', 'script'], true)) {
                $opts->initialState = Tokenizer::PLAINTEXT;
            }
        }

        $this->tokenizer = new Tokenizer($this->tree_builder, $opts, $should_collect);
        $this->tree_builder->tokenizer = $this->tokenizer;

        $this->tokenizer->run($html_str);
        $this->root = $this->tree_builder->finish();

        $this->errors = array_merge($this->tokenizer->errors, $this->tree_builder->errors);

        if ($strict && $this->errors) {
            throw new StrictModeError($this->errors[0]);
        }
    }

    public function toHtml(bool $pretty = true, int $indent_size = 2): string
    {
        return $this->root->toHtml(0, $indent_size, $pretty);
    }

    public function toText(string $separator = ' ', bool $strip = true): string
    {
        return $this->root->toText($separator, $strip);
    }

    public function toMarkdown(): string
    {
        return $this->root->toMarkdown();
    }

    /** @return array<int, mixed> */
    public function query(string $selector): array
    {
        return $this->root->query($selector);
    }

    public function queryFirst(string $selector)
    {
        return $this->root->queryFirst($selector);
    }

    public function toTestFormat(): string
    {
        return $this->root->toTestFormat(0);
    }
}
