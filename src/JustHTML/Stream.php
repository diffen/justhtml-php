<?php

declare(strict_types=1);

namespace JustHTML;

final class StreamDummyNode
{
    public string $namespace = 'html';
}

final class StreamSink
{
    /** @var array<int, array{0:string,1:mixed}> */
    public array $tokens = [];
    /** @var array<int, StreamDummyNode> */
    public array $openElements = [];

    public function processToken($token): int
    {
        if ($token instanceof Tag) {
            if ($token->kind === Tag::START) {
                $attrs = $token->attrs ? $token->attrs : [];
                $this->tokens[] = ['start', [$token->name, $attrs]];
                $this->openElements[] = new StreamDummyNode();
            } else {
                $this->tokens[] = ['end', $token->name];
                if ($this->openElements) {
                    array_pop($this->openElements);
                }
            }
            return TokenSinkResult::Continue;
        }

        if ($token instanceof CommentToken) {
            $this->tokens[] = ['comment', $token->data];
            return TokenSinkResult::Continue;
        }

        if ($token instanceof DoctypeToken) {
            $dt = $token->doctype;
            $this->tokens[] = ['doctype', [$dt->name, $dt->publicId, $dt->systemId]];
            return TokenSinkResult::Continue;
        }

        return TokenSinkResult::Continue;
    }

    public function processCharacters(string $data): void
    {
        $this->tokens[] = ['text', $data];
    }
}

final class Stream
{
    public static function stream($html, ?string $encoding = null, bool $bytes = false): \Generator
    {
        if ($html === null) {
            $htmlStr = '';
        } elseif ($bytes) {
            [$htmlStr, $_] = Encoding::decodeHtml((string)$html, $encoding);
        } else {
            $htmlStr = (string)$html;
        }

        $sink = new StreamSink();
        $tokenizer = new Tokenizer($sink);
        $tokenizer->initialize($htmlStr);

        while (true) {
            $isEof = $tokenizer->step();

            if ($sink->tokens) {
                $textBuffer = [];
                foreach ($sink->tokens as $entry) {
                    $event = $entry[0];
                    $data = $entry[1];
                    if ($event === 'text') {
                        $textBuffer[] = $data;
                        continue;
                    }
                    if ($textBuffer) {
                        yield ['text', implode('', $textBuffer)];
                        $textBuffer = [];
                    }
                    yield [$event, $data];
                }
                if ($textBuffer) {
                    yield ['text', implode('', $textBuffer)];
                }
                $sink->tokens = [];
            }

            if ($isEof) {
                break;
            }
        }
    }
}
