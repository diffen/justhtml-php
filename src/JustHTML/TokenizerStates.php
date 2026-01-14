<?php

declare(strict_types=1);

namespace JustHTML;

trait TokenizerStates
{
    private static function xmlCoercionPattern(): string
    {
        if (self::$xmlCoercionPattern !== null) {
            return self::$xmlCoercionPattern;
        }
        $parts = [];
        for ($plane = 0; $plane < 17; $plane++) {
            $base = $plane * 0x10000;
            $parts[] = '\\x{' . strtoupper(dechex($base + 0xFFFE)) . '}';
            $parts[] = '\\x{' . strtoupper(dechex($base + 0xFFFF)) . '}';
        }
        $pattern = '/[\x0C\x{FDD0}-\x{FDEF}' . implode('', $parts) . ']/u';
        self::$xmlCoercionPattern = $pattern;
        return $pattern;
    }

    private static function coerceTextForXml(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        if (preg_match('/^[\x00-\x7F]*$/', $text) === 1) {
            if (strpos($text, "\f") !== false) {
                return str_replace("\f", ' ', $text);
            }
            return $text;
        }
        $pattern = self::xmlCoercionPattern();
        if (preg_match($pattern, $text) !== 1) {
            return $text;
        }
        return preg_replace_callback($pattern, static function ($match) {
            return $match[0] === "\f" ? ' ' : "\u{FFFD}";
        }, $text) ?? $text;
    }

    private static function coerceCommentForXml(string $text): string
    {
        if (strpos($text, '--') !== false) {
            return str_replace('--', '- -', $text);
        }
        return $text;
    }

    private function stateData(): bool
    {
        $buffer = $this->buffer;
        $length = $this->length;
        while (true) {
            if ($this->reconsume) {
                $this->reconsume = false;
                $this->pos -= 1;
            }

            $pos = $this->pos;
            if ($pos >= $length) {
                $this->pos = $pos;
                $this->currentChar = null;
                $this->flushText();
                $this->emitToken(new EOFToken());
                return true;
            }

            $runLen = strcspn($buffer, "<\r", $pos);
            if ($runLen > 0) {
                $this->appendText(substr($buffer, $pos, $runLen));
                $pos += $runLen;
                $this->pos = $pos;
                if ($pos >= $length) {
                    continue;
                }
            }

            $c = $buffer[$pos];
            if ($c === "\r") {
                $this->appendText("\n");
                $pos += 1;
                if ($pos < $length && $buffer[$pos] === "\n") {
                    $pos += 1;
                }
                $this->pos = $pos;
                continue;
            }

            $pos += 1;
            $this->pos = $pos;
            $this->currentChar = $c;
            $this->ignoreLf = false;

            if ($pos < $length) {
                $nc = $buffer[$pos];
                $ord = ord($nc);
                if (($ord >= 0x61 && $ord <= 0x7A) || ($ord >= 0x41 && $ord <= 0x5A)) {
                    $this->flushText();
                    $this->currentTagKind = Tag::START;
                    $this->currentTagName = '';
                    $this->currentAttrName = '';
                    $this->currentAttrValue = '';
                    $this->currentAttrValueHasAmp = false;
                    $this->currentTagSelfClosing = false;

                    if ($ord >= 0x41 && $ord <= 0x5A) {
                        $nc = chr($ord + 32);
                    }
                    $this->currentTagName .= $nc;
                    $this->pos += 1;
                    $this->state = self::TAG_NAME;
                    return $this->stateTagName();
                }

                if ($nc === '!') {
                    if ($pos + 2 < $length && $buffer[$pos + 1] === '-' && $buffer[$pos + 2] === '-') {
                        $this->flushText();
                        $this->pos += 3;
                        $this->currentComment = '';
                        $this->state = self::COMMENT_START;
                        return $this->stateCommentStart();
                    }
                }

                if ($nc === '/') {
                    if ($pos + 1 < $length) {
                        $nnc = $buffer[$pos + 1];
                        $ordNnc = ord($nnc);
                        if (($ordNnc >= 0x61 && $ordNnc <= 0x7A) || ($ordNnc >= 0x41 && $ordNnc <= 0x5A)) {
                            $this->flushText();
                            $this->currentTagKind = Tag::END;
                            $this->currentTagName = '';
                            $this->currentAttrName = '';
                            $this->currentAttrValue = '';
                            $this->currentAttrValueHasAmp = false;
                            $this->currentTagSelfClosing = false;

                            if ($ordNnc >= 0x41 && $ordNnc <= 0x5A) {
                                $nnc = chr($ordNnc + 32);
                            }
                            $this->currentTagName .= $nnc;
                            $this->pos += 2;
                            $this->state = self::TAG_NAME;
                            return $this->stateTagName();
                        }
                    }
                }
            }

            $this->flushText();
            $this->state = self::TAG_OPEN;
            return $this->stateTagOpen();
        }
    }

    private function stateTagOpen(): bool
    {
        $c = $this->getChar();
        if ($c === null) {
            $this->emitError('eof-before-tag-name');
            $this->appendText('<');
            $this->flushText();
            $this->emitToken(new EOFToken());
            return true;
        }
        if ($c === '!') {
            $this->state = self::MARKUP_DECLARATION_OPEN;
            return false;
        }
        if ($c === '/') {
            $this->state = self::END_TAG_OPEN;
            return false;
        }
        if ($c === '?') {
            $this->emitError('unexpected-question-mark-instead-of-tag-name');
            $this->currentComment = '';
            $this->reconsumeCurrent();
            $this->state = self::BOGUS_COMMENT;
            return false;
        }

        $this->emitError('invalid-first-character-of-tag-name');
        $this->appendText('<');
        $this->reconsumeCurrent();
        $this->state = self::DATA;
        return false;
    }

    private function stateEndTagOpen(): bool
    {
        $c = $this->getChar();
        if ($c === null) {
            $this->emitError('eof-before-tag-name');
            $this->appendText('<');
            $this->appendText('/');
            $this->flushText();
            $this->emitToken(new EOFToken());
            return true;
        }
        if ($c === '>') {
            $this->emitError('empty-end-tag');
            $this->state = self::DATA;
            return false;
        }

        $this->emitError('invalid-first-character-of-tag-name');
        $this->currentComment = '';
        $this->reconsumeCurrent();
        $this->state = self::BOGUS_COMMENT;
        return false;
    }

    private function stateTagName(): bool
    {
        $replacement = "\u{FFFD}";
        $buffer = $this->buffer;
        $length = $this->length;
        while (true) {
            if ($this->reconsume) {
                $this->reconsume = false;
                $c = $this->currentChar;
                if ($c === null) {
                    $this->emitError('eof-in-tag');
                    $this->emitToken(new EOFToken());
                    return true;
                }
                if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                    $this->state = self::BEFORE_ATTRIBUTE_NAME;
                    return false;
                }
                if ($c === '/') {
                    $this->state = self::SELF_CLOSING_START_TAG;
                    return false;
                }
                if ($c === '>') {
                    $switched = $this->emitCurrentTag();
                    if (!$switched) {
                        $this->state = self::DATA;
                    }
                    return false;
                }
                if ($c === "\0") {
                    $this->emitError('unexpected-null-character');
                    $this->currentTagName .= $replacement;
                    continue;
                }
                $ord = ord($c);
                if ($ord >= 0x41 && $ord <= 0x5A) {
                    $c = chr($ord + 32);
                }
                $this->currentTagName .= $c;
                continue;
            }

            $pos = $this->pos;
            if ($pos >= $length) {
                $this->emitError('eof-in-tag');
                $this->emitToken(new EOFToken());
                return true;
            }

            if ($this->ignoreLf && $buffer[$pos] === "\n") {
                $this->ignoreLf = false;
                $this->pos = $pos + 1;
                continue;
            }

            $runLen = strcspn($buffer, "\t\n\f />\0\r", $pos);
            if ($runLen > 0) {
                $chunk = substr($buffer, $pos, $runLen);
                $chunk = strtr($chunk, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
                $this->currentTagName .= $chunk;
                $this->pos = $pos + $runLen;
                $pos = $this->pos;
                if ($pos >= $length) {
                    $this->emitError('eof-in-tag');
                    $this->emitToken(new EOFToken());
                    return true;
                }
            }

            $c = $buffer[$pos];
            $this->pos = $pos + 1;
            if ($c === "\r") {
                $this->ignoreLf = true;
                $c = "\n";
            } else {
                $this->ignoreLf = false;
            }

            if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                $this->state = self::BEFORE_ATTRIBUTE_NAME;
                return false;
            }
            if ($c === '/') {
                $this->state = self::SELF_CLOSING_START_TAG;
                return false;
            }
            if ($c === '>') {
                $switched = $this->emitCurrentTag();
                if (!$switched) {
                    $this->state = self::DATA;
                }
                return false;
            }
            if ($c === "\0") {
                $this->emitError('unexpected-null-character');
                $this->currentTagName .= $replacement;
                continue;
            }
            $ord = ord($c);
            if ($ord >= 0x41 && $ord <= 0x5A) {
                $c = chr($ord + 32);
            }
            $this->currentTagName .= $c;
        }
    }

    private function stateBeforeAttributeName(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('eof-in-tag');
                $this->flushText();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                continue;
            }
            if ($c === '/') {
                $this->state = self::SELF_CLOSING_START_TAG;
                return false;
            }
            if ($c === '>') {
                $this->finishAttribute();
                $switched = $this->emitCurrentTag();
                if (!$switched) {
                    $this->state = self::DATA;
                }
                return false;
            }
            if ($c === '=') {
                $this->emitError('unexpected-equals-sign-before-attribute-name');
                $this->currentAttrName = '=';
                $this->currentAttrValue = '';
                $this->currentAttrValueHasAmp = false;
                $this->state = self::ATTRIBUTE_NAME;
                return false;
            }

            $this->currentAttrName = '';
            $this->currentAttrValue = '';
            $this->currentAttrValueHasAmp = false;
            if ($c === "\0") {
                $this->emitError('unexpected-null-character');
                $c = "\u{FFFD}";
            } else {
                $ord = ord($c);
                if ($ord >= 0x41 && $ord <= 0x5A) {
                    $c = chr($ord + 32);
                }
            }
            $this->currentAttrName .= $c;
            $this->state = self::ATTRIBUTE_NAME;
            return false;
        }
    }

    private function stateAttributeName(): bool
    {
        $replacement = "\u{FFFD}";
        $buffer = $this->buffer;
        $length = $this->length;
        while (true) {
            if ($this->reconsume) {
                $this->reconsume = false;
                $c = $this->currentChar;
                if ($c === null) {
                    $this->emitError('eof-in-tag');
                    $this->flushText();
                    $this->emitToken(new EOFToken());
                    return true;
                }
                if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                    $this->finishAttribute();
                    $this->state = self::AFTER_ATTRIBUTE_NAME;
                    return false;
                }
                if ($c === '/') {
                    $this->finishAttribute();
                    $this->state = self::SELF_CLOSING_START_TAG;
                    return false;
                }
                if ($c === '=') {
                    $this->state = self::BEFORE_ATTRIBUTE_VALUE;
                    return false;
                }
                if ($c === '>') {
                    $this->finishAttribute();
                    $switched = $this->emitCurrentTag();
                    if (!$switched) {
                        $this->state = self::DATA;
                    }
                    return false;
                }
                if ($c === "\0") {
                    $this->emitError('unexpected-null-character');
                    $this->currentAttrName .= $replacement;
                    continue;
                }
                if ($c === '"' || $c === "'" || $c === '<') {
                    $this->emitError('unexpected-character-in-attribute-name');
                }
                $ord = ord($c);
                if ($ord >= 0x41 && $ord <= 0x5A) {
                    $c = chr($ord + 32);
                }
                $this->currentAttrName .= $c;
                continue;
            }

            $pos = $this->pos;
            if ($pos >= $length) {
                $this->emitError('eof-in-tag');
                $this->flushText();
                $this->emitToken(new EOFToken());
                return true;
            }

            if ($this->ignoreLf && $buffer[$pos] === "\n") {
                $this->ignoreLf = false;
                $this->pos = $pos + 1;
                continue;
            }

            $runLen = strcspn($buffer, "\t\n\f />=\0\"'<\r", $pos);
            if ($runLen > 0) {
                $chunk = substr($buffer, $pos, $runLen);
                $chunk = strtr($chunk, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
                $this->currentAttrName .= $chunk;
                $this->pos = $pos + $runLen;
                $pos = $this->pos;
                if ($pos >= $length) {
                    $this->emitError('eof-in-tag');
                    $this->flushText();
                    $this->emitToken(new EOFToken());
                    return true;
                }
            }

            $c = $buffer[$pos];
            $this->pos = $pos + 1;
            if ($c === "\r") {
                $this->ignoreLf = true;
                $c = "\n";
            } else {
                $this->ignoreLf = false;
            }

            if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                $this->finishAttribute();
                $this->state = self::AFTER_ATTRIBUTE_NAME;
                return false;
            }
            if ($c === '/') {
                $this->finishAttribute();
                $this->state = self::SELF_CLOSING_START_TAG;
                return false;
            }
            if ($c === '=') {
                $this->state = self::BEFORE_ATTRIBUTE_VALUE;
                return false;
            }
            if ($c === '>') {
                $this->finishAttribute();
                $switched = $this->emitCurrentTag();
                if (!$switched) {
                    $this->state = self::DATA;
                }
                return false;
            }
            if ($c === "\0") {
                $this->emitError('unexpected-null-character');
                $this->currentAttrName .= $replacement;
                continue;
            }
            if ($c === '"' || $c === "'" || $c === '<') {
                $this->emitError('unexpected-character-in-attribute-name');
            }
            $ord = ord($c);
            if ($ord >= 0x41 && $ord <= 0x5A) {
                $c = chr($ord + 32);
            }
            $this->currentAttrName .= $c;
        }
    }

    private function stateAfterAttributeName(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('eof-in-tag');
                $this->flushText();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                continue;
            }
            if ($c === '/') {
                $this->finishAttribute();
                $this->state = self::SELF_CLOSING_START_TAG;
                return false;
            }
            if ($c === '=') {
                $this->state = self::BEFORE_ATTRIBUTE_VALUE;
                return false;
            }
            if ($c === '>') {
                $this->finishAttribute();
                $switched = $this->emitCurrentTag();
                if (!$switched) {
                    $this->state = self::DATA;
                }
                return false;
            }

            $this->finishAttribute();
            $this->currentAttrName = '';
            $this->currentAttrValue = '';
            $this->currentAttrValueHasAmp = false;
            if ($c === "\0") {
                $this->emitError('unexpected-null-character');
                $c = "\u{FFFD}";
            } else {
                $ord = ord($c);
                if ($ord >= 0x41 && $ord <= 0x5A) {
                    $c = chr($ord + 32);
                }
            }
            $this->currentAttrName .= $c;
            $this->state = self::ATTRIBUTE_NAME;
            return false;
        }
    }

    private function stateBeforeAttributeValue(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('eof-in-tag');
                $this->flushText();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                continue;
            }
            if ($c === '"') {
                $this->state = self::ATTRIBUTE_VALUE_DOUBLE;
                return false;
            }
            if ($c === "'") {
                $this->state = self::ATTRIBUTE_VALUE_SINGLE;
                return false;
            }
            if ($c === '>') {
                $this->emitError('missing-attribute-value');
                $this->finishAttribute();
                $switched = $this->emitCurrentTag();
                if (!$switched) {
                    $this->state = self::DATA;
                }
                return false;
            }
            $this->reconsumeCurrent();
            $this->state = self::ATTRIBUTE_VALUE_UNQUOTED;
            return false;
        }
    }

    private function stateAttributeValueDouble(): bool
    {
        $replacement = "\u{FFFD}";
        $buffer = $this->buffer;
        $length = $this->length;
        while (true) {
            if ($this->reconsume) {
                $this->reconsume = false;
                $c = $this->currentChar;
                if ($c === null) {
                    $this->emitError('eof-in-tag');
                    $this->emitToken(new EOFToken());
                    return true;
                }
                if ($c === '"') {
                    $this->state = self::AFTER_ATTRIBUTE_VALUE_QUOTED;
                    return false;
                }
                if ($c === '&') {
                    $this->appendAttrValueChar('&');
                    $this->currentAttrValueHasAmp = true;
                    continue;
                }
                if ($c === "\0") {
                    $this->emitError('unexpected-null-character');
                    $this->appendAttrValueChar($replacement);
                    continue;
                }
                $this->appendAttrValueChar($c);
                continue;
            }
            $pos = $this->pos;
            if ($pos >= $length) {
                $this->emitError('eof-in-tag');
                $this->emitToken(new EOFToken());
                return true;
            }

            if ($this->ignoreLf && $buffer[$pos] === "\n") {
                $this->ignoreLf = false;
                $this->pos = $pos + 1;
                continue;
            }

            $runLen = strcspn($buffer, "\"&\0\r\n", $pos);
            if ($runLen > 0) {
                $this->currentAttrValue .= substr($buffer, $pos, $runLen);
                $this->pos = $pos + $runLen;
                $pos = $this->pos;
                if ($pos >= $length) {
                    $this->emitError('eof-in-tag');
                    $this->emitToken(new EOFToken());
                    return true;
                }
            }

            $c = $buffer[$pos];
            $this->pos = $pos + 1;
            if ($c === "\r") {
                $this->ignoreLf = true;
                $c = "\n";
            } else {
                $this->ignoreLf = false;
            }

            if ($c === '"') {
                $this->state = self::AFTER_ATTRIBUTE_VALUE_QUOTED;
                return false;
            }
            if ($c === '&') {
                $this->appendAttrValueChar('&');
                $this->currentAttrValueHasAmp = true;
                continue;
            }
            if ($c === "\0") {
                $this->emitError('unexpected-null-character');
                $this->appendAttrValueChar($replacement);
                continue;
            }
            $this->appendAttrValueChar($c);
        }
    }

    private function stateAttributeValueSingle(): bool
    {
        $replacement = "\u{FFFD}";
        $buffer = $this->buffer;
        $length = $this->length;
        while (true) {
            if ($this->reconsume) {
                $this->reconsume = false;
                $c = $this->currentChar;
                if ($c === null) {
                    $this->emitError('eof-in-tag');
                    $this->emitToken(new EOFToken());
                    return true;
                }
                if ($c === "'") {
                    $this->state = self::AFTER_ATTRIBUTE_VALUE_QUOTED;
                    return false;
                }
                if ($c === '&') {
                    $this->appendAttrValueChar('&');
                    $this->currentAttrValueHasAmp = true;
                    continue;
                }
                if ($c === "\0") {
                    $this->emitError('unexpected-null-character');
                    $this->appendAttrValueChar($replacement);
                    continue;
                }
                $this->appendAttrValueChar($c);
                continue;
            }
            $pos = $this->pos;
            if ($pos >= $length) {
                $this->emitError('eof-in-tag');
                $this->emitToken(new EOFToken());
                return true;
            }

            if ($this->ignoreLf && $buffer[$pos] === "\n") {
                $this->ignoreLf = false;
                $this->pos = $pos + 1;
                continue;
            }

            $runLen = strcspn($buffer, "'&\0\r\n", $pos);
            if ($runLen > 0) {
                $this->currentAttrValue .= substr($buffer, $pos, $runLen);
                $this->pos = $pos + $runLen;
                $pos = $this->pos;
                if ($pos >= $length) {
                    $this->emitError('eof-in-tag');
                    $this->emitToken(new EOFToken());
                    return true;
                }
            }

            $c = $buffer[$pos];
            $this->pos = $pos + 1;
            if ($c === "\r") {
                $this->ignoreLf = true;
                $c = "\n";
            } else {
                $this->ignoreLf = false;
            }

            if ($c === "'") {
                $this->state = self::AFTER_ATTRIBUTE_VALUE_QUOTED;
                return false;
            }
            if ($c === '&') {
                $this->appendAttrValueChar('&');
                $this->currentAttrValueHasAmp = true;
                continue;
            }
            if ($c === "\0") {
                $this->emitError('unexpected-null-character');
                $this->appendAttrValueChar($replacement);
                continue;
            }
            $this->appendAttrValueChar($c);
        }
    }

    private function stateAttributeValueUnquoted(): bool
    {
        $replacement = "\u{FFFD}";
        $buffer = $this->buffer;
        $length = $this->length;
        while (true) {
            if ($this->reconsume) {
                $this->reconsume = false;
                $c = $this->currentChar;
                if ($c === null) {
                    $this->emitError('eof-in-tag');
                    $this->emitToken(new EOFToken());
                    return true;
                }
                if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                    $this->finishAttribute();
                    $this->state = self::BEFORE_ATTRIBUTE_NAME;
                    return false;
                }
                if ($c === '>') {
                    $this->finishAttribute();
                    $switched = $this->emitCurrentTag();
                    if (!$switched) {
                        $this->state = self::DATA;
                    }
                    return false;
                }
                if ($c === '&') {
                    $this->appendAttrValueChar('&');
                    $this->currentAttrValueHasAmp = true;
                    continue;
                }
                if ($c === '"' || $c === "'" || $c === '<' || $c === '=' || $c === '`') {
                    $this->emitError('unexpected-character-in-unquoted-attribute-value');
                }
                if ($c === "\0") {
                    $this->emitError('unexpected-null-character');
                    $this->appendAttrValueChar($replacement);
                    continue;
                }
                $this->appendAttrValueChar($c);
                continue;
            }
            $pos = $this->pos;
            if ($pos >= $length) {
                $this->emitError('eof-in-tag');
                $this->emitToken(new EOFToken());
                return true;
            }

            if ($this->ignoreLf && $buffer[$pos] === "\n") {
                $this->ignoreLf = false;
                $this->pos = $pos + 1;
                continue;
            }

            $runLen = strcspn($buffer, "\t\n\f >\"'<=`\0&\r", $pos);
            if ($runLen > 0) {
                $this->currentAttrValue .= substr($buffer, $pos, $runLen);
                $this->pos = $pos + $runLen;
                $pos = $this->pos;
                if ($pos >= $length) {
                    $this->emitError('eof-in-tag');
                    $this->emitToken(new EOFToken());
                    return true;
                }
            }

            $c = $buffer[$pos];
            $this->pos = $pos + 1;
            if ($c === "\r") {
                $this->ignoreLf = true;
                $c = "\n";
            } else {
                $this->ignoreLf = false;
            }

            if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                $this->finishAttribute();
                $this->state = self::BEFORE_ATTRIBUTE_NAME;
                return false;
            }
            if ($c === '>') {
                $this->finishAttribute();
                $switched = $this->emitCurrentTag();
                if (!$switched) {
                    $this->state = self::DATA;
                }
                return false;
            }
            if ($c === '&') {
                $this->appendAttrValueChar('&');
                $this->currentAttrValueHasAmp = true;
                continue;
            }
            if ($c === '"' || $c === "'" || $c === '<' || $c === '=' || $c === '`') {
                $this->emitError('unexpected-character-in-unquoted-attribute-value');
            }
            if ($c === "\0") {
                $this->emitError('unexpected-null-character');
                $this->appendAttrValueChar($replacement);
                continue;
            }
            $this->appendAttrValueChar($c);
        }
    }

    private function stateAfterAttributeValueQuoted(): bool
    {
        $c = $this->getChar();
        if ($c === null) {
            $this->emitError('eof-in-tag');
            $this->flushText();
            $this->emitToken(new EOFToken());
            return true;
        }
        if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
            $this->finishAttribute();
            $this->state = self::BEFORE_ATTRIBUTE_NAME;
            return false;
        }
        if ($c === '/') {
            $this->finishAttribute();
            $this->state = self::SELF_CLOSING_START_TAG;
            return false;
        }
        if ($c === '>') {
            $this->finishAttribute();
            $switched = $this->emitCurrentTag();
            if (!$switched) {
                $this->state = self::DATA;
            }
            return false;
        }
        $this->emitError('missing-whitespace-between-attributes');
        $this->finishAttribute();
        $this->reconsumeCurrent();
        $this->state = self::BEFORE_ATTRIBUTE_NAME;
        return false;
    }

    private function stateSelfClosingStartTag(): bool
    {
        $c = $this->getChar();
        if ($c === null) {
            $this->emitError('eof-in-tag');
            $this->flushText();
            $this->emitToken(new EOFToken());
            return true;
        }
        if ($c === '>') {
            $this->currentTagSelfClosing = true;
            $this->emitCurrentTag();
            $this->state = self::DATA;
            return false;
        }
        $this->emitError('unexpected-character-after-solidus-in-tag');
        $this->reconsumeCurrent();
        $this->state = self::BEFORE_ATTRIBUTE_NAME;
        return false;
    }

    private function stateMarkupDeclarationOpen(): bool
    {
        if ($this->consumeIf('--')) {
            $this->currentComment = '';
            $this->state = self::COMMENT_START;
            return false;
        }
        if ($this->consumeCaseInsensitive('DOCTYPE')) {
            $this->currentDoctypeName = '';
            $this->currentDoctypePublic = null;
            $this->currentDoctypeSystem = null;
            $this->currentDoctypeForceQuirks = false;
            $this->state = self::DOCTYPE;
            return false;
        }
        if ($this->consumeIf('[CDATA[')) {
            $stack = $this->sink->openElements ?? [];
            $current = $stack ? $stack[count($stack) - 1] : null;
            $namespace = $current ? $current->namespace : null;
            if ($namespace !== null && $namespace !== 'html') {
                $this->state = self::CDATA_SECTION;
                return false;
            }
            $this->emitError('cdata-in-html-content');
            $this->currentComment = '[CDATA[';
            $this->state = self::BOGUS_COMMENT;
            return false;
        }
        $this->emitError('incorrectly-opened-comment');
        $this->currentComment = '';
        $this->state = self::BOGUS_COMMENT;
        return false;
    }

    private function stateCommentStart(): bool
    {
        $c = $this->getChar();
        if ($c === '-') {
            $this->state = self::COMMENT_START_DASH;
            return false;
        }
        if ($c === '>') {
            $this->emitError('abrupt-closing-of-empty-comment');
            $this->emitComment();
            $this->state = self::DATA;
            return false;
        }
        if ($c === null) {
            $this->emitError('eof-in-comment');
            $this->emitComment();
            $this->emitToken(new EOFToken());
            return true;
        }
        $this->reconsumeCurrent();
        $this->state = self::COMMENT;
        return false;
    }

    private function stateCommentStartDash(): bool
    {
        $c = $this->getChar();
        if ($c === '-') {
            $this->state = self::COMMENT_END;
            return false;
        }
        if ($c === '>') {
            $this->emitError('abrupt-closing-of-empty-comment');
            $this->emitComment();
            $this->state = self::DATA;
            return false;
        }
        if ($c === null) {
            $this->emitError('eof-in-comment');
            $this->emitComment();
            $this->emitToken(new EOFToken());
            return true;
        }
        $this->currentComment .= '-';
        $this->reconsumeCurrent();
        $this->state = self::COMMENT;
        return false;
    }

    private function stateComment(): bool
    {
        while (true) {
            if ($this->consumeCommentRun()) {
                return false;
            }
            $c = $this->getChar();
            if ($c === '-') {
                $this->state = self::COMMENT_END_DASH;
                return false;
            }
            if ($c === null) {
                $this->emitError('eof-in-comment');
                $this->emitComment();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "\0") {
                $this->emitError('unexpected-null-character');
                $this->currentComment .= "\u{FFFD}";
                continue;
            }
            $this->currentComment .= $c;
        }
    }

    private function stateCommentEndDash(): bool
    {
        $c = $this->getChar();
        if ($c === '-') {
            $this->state = self::COMMENT_END;
            return false;
        }
        if ($c === null) {
            $this->emitError('eof-in-comment');
            $this->emitComment();
            $this->emitToken(new EOFToken());
            return true;
        }
        $this->currentComment .= '-';
        $this->reconsumeCurrent();
        $this->state = self::COMMENT;
        return false;
    }

    private function stateCommentEnd(): bool
    {
        $c = $this->getChar();
        if ($c === '>') {
            $this->emitComment();
            $this->state = self::DATA;
            return false;
        }
        if ($c === '!') {
            $this->state = self::COMMENT_END_BANG;
            return false;
        }
        if ($c === '-') {
            $this->currentComment .= '-';
            return false;
        }
        if ($c === null) {
            $this->emitError('eof-in-comment');
            $this->emitComment();
            $this->emitToken(new EOFToken());
            return true;
        }
        $this->currentComment .= '--';
        $this->reconsumeCurrent();
        $this->state = self::COMMENT;
        return false;
    }

    private function stateCommentEndBang(): bool
    {
        $c = $this->getChar();
        if ($c === '-') {
            $this->currentComment .= '--!';
            $this->state = self::COMMENT_END_DASH;
            return false;
        }
        if ($c === '>') {
            $this->emitError('incorrectly-closed-comment');
            $this->emitComment();
            $this->state = self::DATA;
            return false;
        }
        if ($c === null) {
            $this->emitError('eof-in-comment');
            $this->emitComment();
            $this->emitToken(new EOFToken());
            return true;
        }
        $this->currentComment .= '--!';
        $this->reconsumeCurrent();
        $this->state = self::COMMENT;
        return false;
    }

    private function stateBogusComment(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === '>') {
                $this->emitComment();
                $this->state = self::DATA;
                return false;
            }
            if ($c === null) {
                $this->emitComment();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "\0") {
                $this->emitError('unexpected-null-character');
                $this->currentComment .= "\u{FFFD}";
                continue;
            }
            $this->currentComment .= $c;
        }
    }

    private function stateDoctype(): bool
    {
        $c = $this->getChar();
        if ($c === null) {
            $this->emitError('eof-in-doctype');
            $this->currentDoctypeForceQuirks = true;
            $this->emitDoctype();
            $this->emitToken(new EOFToken());
            return true;
        }
        if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
            $this->state = self::BEFORE_DOCTYPE_NAME;
            return false;
        }
        if ($c === '>') {
            $this->emitError('expected-doctype-name-but-got-right-bracket');
            $this->currentDoctypeForceQuirks = true;
            $this->emitDoctype();
            $this->state = self::DATA;
            return false;
        }
        $this->emitError('missing-whitespace-before-doctype-name');
        $this->reconsumeCurrent();
        $this->state = self::BEFORE_DOCTYPE_NAME;
        return false;
    }

    private function stateBeforeDoctypeName(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('eof-in-doctype');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                continue;
            }
            if ($c === '>') {
                $this->emitError('expected-doctype-name-but-got-right-bracket');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->state = self::DATA;
                return false;
            }
            if ($c === "\0") {
                $this->emitError('unexpected-null-character');
                $this->currentDoctypeName .= "\u{FFFD}";
                $this->state = self::DOCTYPE_NAME;
                return false;
            }
            $ord = ord($c);
            if ($ord >= 0x41 && $ord <= 0x5A) {
                $c = chr($ord + 32);
            }
            $this->currentDoctypeName .= $c;
            $this->state = self::DOCTYPE_NAME;
            return false;
        }
    }

    private function stateDoctypeName(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('eof-in-doctype-name');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                $this->state = self::AFTER_DOCTYPE_NAME;
                return false;
            }
            if ($c === '>') {
                $this->emitDoctype();
                $this->state = self::DATA;
                return false;
            }
            if ($c === "\0") {
                $this->emitError('unexpected-null-character');
                $this->currentDoctypeName .= "\u{FFFD}";
                continue;
            }
            $ord = ord($c);
            if ($ord >= 0x41 && $ord <= 0x5A) {
                $c = chr($ord + 32);
            }
            $this->currentDoctypeName .= $c;
        }
    }

    private function stateAfterDoctypeName(): bool
    {
        if ($this->consumeCaseInsensitive('PUBLIC')) {
            $this->state = self::AFTER_DOCTYPE_PUBLIC_KEYWORD;
            return false;
        }
        if ($this->consumeCaseInsensitive('SYSTEM')) {
            $this->state = self::AFTER_DOCTYPE_SYSTEM_KEYWORD;
            return false;
        }
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('eof-in-doctype');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                continue;
            }
            if ($c === '>') {
                $this->emitDoctype();
                $this->state = self::DATA;
                return false;
            }
            $this->emitError('missing-whitespace-after-doctype-name');
            $this->currentDoctypeForceQuirks = true;
            $this->reconsumeCurrent();
            $this->state = self::BOGUS_DOCTYPE;
            return false;
        }
    }

    private function stateAfterDoctypePublicKeyword(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('missing-quote-before-doctype-public-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                $this->state = self::BEFORE_DOCTYPE_PUBLIC_IDENTIFIER;
                return false;
            }
            if ($c === '"') {
                $this->emitError('missing-whitespace-before-doctype-public-identifier');
                $this->currentDoctypePublic = '';
                $this->state = self::DOCTYPE_PUBLIC_IDENTIFIER_DOUBLE_QUOTED;
                return false;
            }
            if ($c === "'") {
                $this->emitError('missing-whitespace-before-doctype-public-identifier');
                $this->currentDoctypePublic = '';
                $this->state = self::DOCTYPE_PUBLIC_IDENTIFIER_SINGLE_QUOTED;
                return false;
            }
            if ($c === '>') {
                $this->emitError('missing-doctype-public-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->state = self::DATA;
                return false;
            }
            $this->emitError('unexpected-character-after-doctype-public-keyword');
            $this->currentDoctypeForceQuirks = true;
            $this->reconsumeCurrent();
            $this->state = self::BOGUS_DOCTYPE;
            return false;
        }
    }

    private function stateAfterDoctypeSystemKeyword(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('missing-quote-before-doctype-system-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                $this->state = self::BEFORE_DOCTYPE_SYSTEM_IDENTIFIER;
                return false;
            }
            if ($c === '"') {
                $this->emitError('missing-whitespace-after-doctype-public-identifier');
                $this->currentDoctypeSystem = '';
                $this->state = self::DOCTYPE_SYSTEM_IDENTIFIER_DOUBLE_QUOTED;
                return false;
            }
            if ($c === "'") {
                $this->emitError('missing-whitespace-after-doctype-public-identifier');
                $this->currentDoctypeSystem = '';
                $this->state = self::DOCTYPE_SYSTEM_IDENTIFIER_SINGLE_QUOTED;
                return false;
            }
            if ($c === '>') {
                $this->emitError('missing-doctype-system-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->state = self::DATA;
                return false;
            }
            $this->emitError('unexpected-character-after-doctype-system-keyword');
            $this->currentDoctypeForceQuirks = true;
            $this->reconsumeCurrent();
            $this->state = self::BOGUS_DOCTYPE;
            return false;
        }
    }

    private function stateBeforeDoctypePublicIdentifier(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('missing-doctype-public-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                continue;
            }
            if ($c === '"') {
                $this->currentDoctypePublic = '';
                $this->state = self::DOCTYPE_PUBLIC_IDENTIFIER_DOUBLE_QUOTED;
                return false;
            }
            if ($c === "'") {
                $this->currentDoctypePublic = '';
                $this->state = self::DOCTYPE_PUBLIC_IDENTIFIER_SINGLE_QUOTED;
                return false;
            }
            if ($c === '>') {
                $this->emitError('missing-doctype-public-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->state = self::DATA;
                return false;
            }
            $this->emitError('missing-quote-before-doctype-public-identifier');
            $this->currentDoctypeForceQuirks = true;
            $this->reconsumeCurrent();
            $this->state = self::BOGUS_DOCTYPE;
            return false;
        }
    }

    private function stateDoctypePublicIdentifierDoubleQuoted(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('eof-in-doctype-public-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === '"') {
                $this->state = self::AFTER_DOCTYPE_PUBLIC_IDENTIFIER;
                return false;
            }
            if ($c === "\0") {
                $this->emitError('unexpected-null-character');
                $this->currentDoctypePublic .= "\u{FFFD}";
                continue;
            }
            if ($c === '>') {
                $this->emitError('abrupt-doctype-public-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->state = self::DATA;
                return false;
            }
            $this->currentDoctypePublic .= $c;
        }
    }

    private function stateDoctypePublicIdentifierSingleQuoted(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('eof-in-doctype-public-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "'") {
                $this->state = self::AFTER_DOCTYPE_PUBLIC_IDENTIFIER;
                return false;
            }
            if ($c === "\0") {
                $this->emitError('unexpected-null-character');
                $this->currentDoctypePublic .= "\u{FFFD}";
                continue;
            }
            if ($c === '>') {
                $this->emitError('abrupt-doctype-public-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->state = self::DATA;
                return false;
            }
            $this->currentDoctypePublic .= $c;
        }
    }

    private function stateAfterDoctypePublicIdentifier(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('eof-in-doctype-public-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                $this->state = self::BETWEEN_DOCTYPE_PUBLIC_AND_SYSTEM_IDENTIFIERS;
                return false;
            }
            if ($c === '>') {
                $this->emitDoctype();
                $this->state = self::DATA;
                return false;
            }
            if ($c === '"') {
                $this->emitError('missing-whitespace-between-doctype-public-and-system-identifiers');
                $this->currentDoctypeSystem = '';
                $this->state = self::DOCTYPE_SYSTEM_IDENTIFIER_DOUBLE_QUOTED;
                return false;
            }
            if ($c === "'") {
                $this->emitError('missing-whitespace-between-doctype-public-and-system-identifiers');
                $this->currentDoctypeSystem = '';
                $this->state = self::DOCTYPE_SYSTEM_IDENTIFIER_SINGLE_QUOTED;
                return false;
            }
            $this->emitError('unexpected-character-after-doctype-public-identifier');
            $this->currentDoctypeForceQuirks = true;
            $this->reconsumeCurrent();
            $this->state = self::BOGUS_DOCTYPE;
            return false;
        }
    }

    private function stateBetweenDoctypePublicAndSystemIdentifiers(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('eof-in-doctype');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                continue;
            }
            if ($c === '>') {
                $this->emitDoctype();
                $this->state = self::DATA;
                return false;
            }
            if ($c === '"') {
                $this->currentDoctypeSystem = '';
                $this->state = self::DOCTYPE_SYSTEM_IDENTIFIER_DOUBLE_QUOTED;
                return false;
            }
            if ($c === "'") {
                $this->currentDoctypeSystem = '';
                $this->state = self::DOCTYPE_SYSTEM_IDENTIFIER_SINGLE_QUOTED;
                return false;
            }
            $this->emitError('missing-quote-before-doctype-system-identifier');
            $this->currentDoctypeForceQuirks = true;
            $this->reconsumeCurrent();
            $this->state = self::BOGUS_DOCTYPE;
            return false;
        }
    }

    private function stateBeforeDoctypeSystemIdentifier(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('missing-doctype-system-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                continue;
            }
            if ($c === '"') {
                $this->currentDoctypeSystem = '';
                $this->state = self::DOCTYPE_SYSTEM_IDENTIFIER_DOUBLE_QUOTED;
                return false;
            }
            if ($c === "'") {
                $this->currentDoctypeSystem = '';
                $this->state = self::DOCTYPE_SYSTEM_IDENTIFIER_SINGLE_QUOTED;
                return false;
            }
            if ($c === '>') {
                $this->emitError('missing-doctype-system-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->state = self::DATA;
                return false;
            }
            $this->emitError('missing-quote-before-doctype-system-identifier');
            $this->currentDoctypeForceQuirks = true;
            $this->reconsumeCurrent();
            $this->state = self::BOGUS_DOCTYPE;
            return false;
        }
    }

    private function stateDoctypeSystemIdentifierDoubleQuoted(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('eof-in-doctype-system-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === '"') {
                $this->state = self::AFTER_DOCTYPE_SYSTEM_IDENTIFIER;
                return false;
            }
            if ($c === "\0") {
                $this->emitError('unexpected-null-character');
                $this->currentDoctypeSystem .= "\u{FFFD}";
                continue;
            }
            if ($c === '>') {
                $this->emitError('abrupt-doctype-system-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->state = self::DATA;
                return false;
            }
            $this->currentDoctypeSystem .= $c;
        }
    }

    private function stateDoctypeSystemIdentifierSingleQuoted(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('eof-in-doctype-system-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "'") {
                $this->state = self::AFTER_DOCTYPE_SYSTEM_IDENTIFIER;
                return false;
            }
            if ($c === "\0") {
                $this->emitError('unexpected-null-character');
                $this->currentDoctypeSystem .= "\u{FFFD}";
                continue;
            }
            if ($c === '>') {
                $this->emitError('abrupt-doctype-system-identifier');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->state = self::DATA;
                return false;
            }
            $this->currentDoctypeSystem .= $c;
        }
    }

    private function stateAfterDoctypeSystemIdentifier(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('eof-in-doctype');
                $this->currentDoctypeForceQuirks = true;
                $this->emitDoctype();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === "\t" || $c === "\n" || $c === "\f" || $c === ' ') {
                continue;
            }
            if ($c === '>') {
                $this->emitDoctype();
                $this->state = self::DATA;
                return false;
            }
            $this->emitError('unexpected-character-after-doctype-system-identifier');
            $this->reconsumeCurrent();
            $this->state = self::BOGUS_DOCTYPE;
            return false;
        }
    }

    private function stateBogusDoctype(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === '>') {
                $this->emitDoctype();
                $this->state = self::DATA;
                return false;
            }
            if ($c === null) {
                $this->emitDoctype();
                $this->emitToken(new EOFToken());
                return true;
            }
        }
    }

    private function stateCdataSection(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c === null) {
                $this->emitError('eof-in-cdata');
                $this->flushText();
                $this->emitToken(new EOFToken());
                return true;
            }
            if ($c === ']') {
                $this->state = self::CDATA_SECTION_BRACKET;
                return false;
            }
            $this->appendText($c);
        }
    }

    private function stateCdataSectionBracket(): bool
    {
        $c = $this->getChar();
        if ($c === ']') {
            $this->state = self::CDATA_SECTION_END;
            return false;
        }
        $this->appendText(']');
        if ($c === null) {
            $this->emitError('eof-in-cdata');
            $this->flushText();
            $this->emitToken(new EOFToken());
            return true;
        }
        $this->reconsumeCurrent();
        $this->state = self::CDATA_SECTION;
        return false;
    }

    private function stateCdataSectionEnd(): bool
    {
        $c = $this->getChar();
        if ($c === '>') {
            $this->flushText();
            $this->state = self::DATA;
            return false;
        }
        $this->appendText(']');
        if ($c === null) {
            $this->appendText(']');
            $this->emitError('eof-in-cdata');
            $this->flushText();
            $this->emitToken(new EOFToken());
            return true;
        }
        if ($c === ']') {
            return false;
        }
        $this->appendText(']');
        $this->reconsumeCurrent();
        $this->state = self::CDATA_SECTION;
        return false;
    }

    private function stateRcdata(): bool
    {
        $buffer = $this->buffer;
        $length = $this->length;
        $pos = $this->pos;
        while (true) {
            if ($this->reconsume) {
                $this->reconsume = false;
                if ($this->currentChar === null) {
                    $this->flushText();
                    $this->emitToken(new EOFToken());
                    return true;
                }
                $this->pos -= 1;
                $pos = $this->pos;
            }

            if ($pos >= $length) {
                $this->flushText();
                $this->emitToken(new EOFToken());
                return true;
            }

            $runLen = strcspn($buffer, "<&\0", $pos);
            if ($runLen > 0) {
                $chunk = substr($buffer, $pos, $runLen);
                $chunkLen = strlen($chunk);
                $this->appendTextChunk($chunk, $chunkLen > 0 && $chunk[$chunkLen - 1] === "\r");
                $pos += $runLen;
                $this->pos = $pos;
                if ($pos >= $length) {
                    $this->flushText();
                    $this->emitToken(new EOFToken());
                    return true;
                }
            }

            $c = $buffer[$pos];
            if ($c === "\0") {
                $this->ignoreLf = false;
                $this->emitError('unexpected-null-character');
                $this->appendText("\u{FFFD}");
                $pos += 1;
                $this->pos = $pos;
                continue;
            }
            if ($c === '&') {
                $this->appendText('&');
                $pos += 1;
                $this->pos = $pos;
                continue;
            }

            $pos += 1;
            $this->pos = $pos;
            $this->state = self::RCDATA_LESS_THAN_SIGN;
            return false;
        }
    }

    private function stateRcdataLessThanSign(): bool
    {
        $c = $this->getChar();
        if ($c === '/') {
            $this->currentTagName = '';
            $this->originalTagName = '';
            $this->state = self::RCDATA_END_TAG_OPEN;
            return false;
        }
        $this->appendText('<');
        $this->reconsumeCurrent();
        $this->state = self::RCDATA;
        return false;
    }

    private function stateRcdataEndTagOpen(): bool
    {
        $c = $this->getChar();
        if ($c !== null) {
            $ord = ord($c);
            if (($ord >= 0x41 && $ord <= 0x5A) || ($ord >= 0x61 && $ord <= 0x7A)) {
                $lower = $c;
                if ($ord >= 0x41 && $ord <= 0x5A) {
                    $lower = chr($ord + 32);
                }
                $this->currentTagName .= $lower;
                $this->originalTagName .= $c;
                $this->state = self::RCDATA_END_TAG_NAME;
                return false;
            }
        }
        $this->appendText('<');
        $this->appendText('/');
        $this->reconsumeCurrent();
        $this->state = self::RCDATA;
        return false;
    }

    private function stateRcdataEndTagName(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c !== null) {
                $ord = ord($c);
                if (($ord >= 0x41 && $ord <= 0x5A) || ($ord >= 0x61 && $ord <= 0x7A)) {
                    $lower = $c;
                    if ($ord >= 0x41 && $ord <= 0x5A) {
                        $lower = chr($ord + 32);
                    }
                    $this->currentTagName .= $lower;
                    $this->originalTagName .= $c;
                    continue;
                }
            }
            $tagName = $this->currentTagName;
            if ($tagName === $this->rawtextTagName) {
                if ($c === '>') {
                    $tag = new Tag(Tag::END, $tagName, [], false);
                    $this->flushText();
                    $this->emitToken($tag);
                    $this->state = self::DATA;
                    $this->rawtextTagName = null;
                    $this->originalTagName = '';
                    return false;
                }
                if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f") {
                    $this->currentTagKind = Tag::END;
                    $this->currentTagAttrs = [];
                    $this->state = self::BEFORE_ATTRIBUTE_NAME;
                    return false;
                }
                if ($c === '/') {
                    $this->flushText();
                    $this->currentTagKind = Tag::END;
                    $this->currentTagAttrs = [];
                    $this->state = self::SELF_CLOSING_START_TAG;
                    return false;
                }
            }
            if ($c === null) {
                $this->appendText('<');
                $this->appendText('/');
                if ($this->originalTagName !== '') {
                    $this->appendText($this->originalTagName);
                }
                $this->currentTagName = '';
                $this->originalTagName = '';
                $this->flushText();
                $this->emitToken(new EOFToken());
                return true;
            }
            $this->appendText('<');
            $this->appendText('/');
            if ($this->originalTagName !== '') {
                $this->appendText($this->originalTagName);
            }
            $this->currentTagName = '';
            $this->originalTagName = '';
            $this->reconsumeCurrent();
            $this->state = self::RCDATA;
            return false;
        }
    }

    private function stateRawtext(): bool
    {
        $buffer = $this->buffer;
        $length = $this->length;
        $pos = $this->pos;
        while (true) {
            if ($this->reconsume) {
                $this->reconsume = false;
                if ($this->currentChar === null) {
                    $this->flushText();
                    $this->emitToken(new EOFToken());
                    return true;
                }
                $this->pos -= 1;
                $pos = $this->pos;
            }
            if ($pos >= $length) {
                $this->flushText();
                $this->emitToken(new EOFToken());
                return true;
            }

            $runLen = strcspn($buffer, "<\0", $pos);
            if ($runLen > 0) {
                $chunk = substr($buffer, $pos, $runLen);
                $chunkLen = strlen($chunk);
                $this->appendTextChunk($chunk, $chunkLen > 0 && $chunk[$chunkLen - 1] === "\r");
                $pos += $runLen;
                $this->pos = $pos;
                if ($pos >= $length) {
                    $this->flushText();
                    $this->emitToken(new EOFToken());
                    return true;
                }
            }

            $c = $buffer[$pos];
            if ($c === "\0") {
                $this->ignoreLf = false;
                $this->emitError('unexpected-null-character');
                $this->appendText("\u{FFFD}");
                $pos += 1;
                $this->pos = $pos;
                continue;
            }

            $pos += 1;
            $this->pos = $pos;

            if ($this->rawtextTagName === 'script') {
                $next1 = $this->peekChar(0);
                $next2 = $this->peekChar(1);
                $next3 = $this->peekChar(2);
                if ($next1 === '!' && $next2 === '-' && $next3 === '-') {
                    $this->appendText('<');
                    $this->appendText('!');
                    $this->appendText('-');
                    $this->appendText('-');
                    $this->getChar();
                    $this->getChar();
                    $this->getChar();
                    $this->state = self::SCRIPT_DATA_ESCAPED;
                    return false;
                }
            }

            $this->state = self::RAWTEXT_LESS_THAN_SIGN;
            return false;
        }
    }

    private function stateRawtextLessThanSign(): bool
    {
        $c = $this->getChar();
        if ($c === '/') {
            $this->currentTagName = '';
            $this->originalTagName = '';
            $this->state = self::RAWTEXT_END_TAG_OPEN;
            return false;
        }
        $this->appendText('<');
        $this->reconsumeCurrent();
        $this->state = self::RAWTEXT;
        return false;
    }

    private function stateRawtextEndTagOpen(): bool
    {
        $c = $this->getChar();
        if ($c !== null) {
            $ord = ord($c);
            if (($ord >= 0x41 && $ord <= 0x5A) || ($ord >= 0x61 && $ord <= 0x7A)) {
                $lower = $c;
                if ($ord >= 0x41 && $ord <= 0x5A) {
                    $lower = chr($ord + 32);
                }
                $this->currentTagName .= $lower;
                $this->originalTagName .= $c;
                $this->state = self::RAWTEXT_END_TAG_NAME;
                return false;
            }
        }
        $this->appendText('<');
        $this->appendText('/');
        $this->reconsumeCurrent();
        $this->state = self::RAWTEXT;
        return false;
    }

    private function stateRawtextEndTagName(): bool
    {
        while (true) {
            $c = $this->getChar();
            if ($c !== null) {
                $ord = ord($c);
                if (($ord >= 0x41 && $ord <= 0x5A) || ($ord >= 0x61 && $ord <= 0x7A)) {
                    $lower = $c;
                    if ($ord >= 0x41 && $ord <= 0x5A) {
                        $lower = chr($ord + 32);
                    }
                    $this->currentTagName .= $lower;
                    $this->originalTagName .= $c;
                    continue;
                }
            }
            $tagName = $this->currentTagName;
            if ($tagName === $this->rawtextTagName) {
                if ($c === '>') {
                    $tag = new Tag(Tag::END, $tagName, [], false);
                    $this->flushText();
                    $this->emitToken($tag);
                    $this->state = self::DATA;
                    $this->rawtextTagName = null;
                    $this->originalTagName = '';
                    return false;
                }
                if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f") {
                    $this->currentTagKind = Tag::END;
                    $this->currentTagAttrs = [];
                    $this->state = self::BEFORE_ATTRIBUTE_NAME;
                    return false;
                }
                if ($c === '/') {
                    $this->flushText();
                    $this->currentTagKind = Tag::END;
                    $this->currentTagAttrs = [];
                    $this->state = self::SELF_CLOSING_START_TAG;
                    return false;
                }
            }
            if ($c === null) {
                $this->appendText('<');
                $this->appendText('/');
                if ($this->originalTagName !== '') {
                    $this->appendText($this->originalTagName);
                }
                $this->currentTagName = '';
                $this->originalTagName = '';
                $this->flushText();
                $this->emitToken(new EOFToken());
                return true;
            }
            $this->appendText('<');
            $this->appendText('/');
            if ($this->originalTagName !== '') {
                $this->appendText($this->originalTagName);
            }
            $this->currentTagName = '';
            $this->originalTagName = '';
            $this->reconsumeCurrent();
            $this->state = self::RAWTEXT;
            return false;
        }
    }

    private function statePlaintext(): bool
    {
        if ($this->pos < $this->length) {
            $remaining = substr($this->buffer, $this->pos);
            if (strpos($remaining, "\0") !== false) {
                $remaining = str_replace("\0", "\u{FFFD}", $remaining);
                $this->emitError('unexpected-null-character');
            }
            $this->appendText($remaining);
            $this->pos = $this->length;
        }
        $this->flushText();
        $this->emitToken(new EOFToken());
        return true;
    }

    private function stateScriptDataEscaped(): bool
    {
        $c = $this->getChar();
        if ($c === null) {
            $this->flushText();
            $this->emitToken(new EOFToken());
            return true;
        }
        if ($c === '-') {
            $this->appendText('-');
            $this->state = self::SCRIPT_DATA_ESCAPED_DASH;
            return false;
        }
        if ($c === '<') {
            $this->state = self::SCRIPT_DATA_ESCAPED_LESS_THAN_SIGN;
            return false;
        }
        if ($c === "\0") {
            $this->emitError('unexpected-null-character');
            $this->appendText("\u{FFFD}");
            return false;
        }
        $this->appendText($c);
        return false;
    }

    private function stateScriptDataEscapedDash(): bool
    {
        $c = $this->getChar();
        if ($c === null) {
            $this->flushText();
            $this->emitToken(new EOFToken());
            return true;
        }
        if ($c === '-') {
            $this->appendText('-');
            $this->state = self::SCRIPT_DATA_ESCAPED_DASH_DASH;
            return false;
        }
        if ($c === '<') {
            $this->state = self::SCRIPT_DATA_ESCAPED_LESS_THAN_SIGN;
            return false;
        }
        if ($c === "\0") {
            $this->emitError('unexpected-null-character');
            $this->appendText("\u{FFFD}");
            $this->state = self::SCRIPT_DATA_ESCAPED;
            return false;
        }
        $this->appendText($c);
        $this->state = self::SCRIPT_DATA_ESCAPED;
        return false;
    }

    private function stateScriptDataEscapedDashDash(): bool
    {
        $c = $this->getChar();
        if ($c === null) {
            $this->flushText();
            $this->emitToken(new EOFToken());
            return true;
        }
        if ($c === '-') {
            $this->appendText('-');
            return false;
        }
        if ($c === '<') {
            $this->appendText('<');
            $this->state = self::SCRIPT_DATA_ESCAPED_LESS_THAN_SIGN;
            return false;
        }
        if ($c === '>') {
            $this->appendText('>');
            $this->state = self::RAWTEXT;
            return false;
        }
        if ($c === "\0") {
            $this->emitError('unexpected-null-character');
            $this->appendText("\u{FFFD}");
            $this->state = self::SCRIPT_DATA_ESCAPED;
            return false;
        }
        $this->appendText($c);
        $this->state = self::SCRIPT_DATA_ESCAPED;
        return false;
    }

    private function stateScriptDataEscapedLessThanSign(): bool
    {
        $c = $this->getChar();
        if ($c === '/') {
            $this->tempBuffer = '';
            $this->state = self::SCRIPT_DATA_ESCAPED_END_TAG_OPEN;
            return false;
        }
        if ($c !== null) {
            $ord = ord($c);
            if (($ord >= 0x41 && $ord <= 0x5A) || ($ord >= 0x61 && $ord <= 0x7A)) {
                $this->tempBuffer = '';
                $this->appendText('<');
                $this->reconsumeCurrent();
                $this->state = self::SCRIPT_DATA_DOUBLE_ESCAPE_START;
                return false;
            }
        }
        $this->appendText('<');
        $this->reconsumeCurrent();
        $this->state = self::SCRIPT_DATA_ESCAPED;
        return false;
    }

    private function stateScriptDataEscapedEndTagOpen(): bool
    {
        $c = $this->getChar();
        if ($c !== null) {
            $ord = ord($c);
            if (($ord >= 0x41 && $ord <= 0x5A) || ($ord >= 0x61 && $ord <= 0x7A)) {
                $this->currentTagName = '';
                $this->originalTagName = '';
                $this->reconsumeCurrent();
                $this->state = self::SCRIPT_DATA_ESCAPED_END_TAG_NAME;
                return false;
            }
        }
        $this->appendText('<');
        $this->appendText('/');
        $this->reconsumeCurrent();
        $this->state = self::SCRIPT_DATA_ESCAPED;
        return false;
    }

    private function stateScriptDataEscapedEndTagName(): bool
    {
        $c = $this->getChar();
        if ($c !== null) {
            $ord = ord($c);
            if (($ord >= 0x41 && $ord <= 0x5A) || ($ord >= 0x61 && $ord <= 0x7A)) {
                $lower = $c;
                if ($ord >= 0x41 && $ord <= 0x5A) {
                    $lower = chr($ord + 32);
                }
                $this->currentTagName .= $lower;
                $this->originalTagName .= $c;
                $this->tempBuffer .= $c;
                return false;
            }
        }
        $tagName = $this->currentTagName;
        $isAppropriate = $tagName === $this->rawtextTagName;

        if ($isAppropriate) {
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f") {
                $this->currentTagKind = Tag::END;
                $this->currentTagAttrs = [];
                $this->state = self::BEFORE_ATTRIBUTE_NAME;
                return false;
            }
            if ($c === '/') {
                $this->flushText();
                $this->currentTagKind = Tag::END;
                $this->currentTagAttrs = [];
                $this->state = self::SELF_CLOSING_START_TAG;
                return false;
            }
            if ($c === '>') {
                $this->flushText();
                $tag = new Tag(Tag::END, $tagName, [], false);
                $this->emitToken($tag);
                $this->state = self::DATA;
                $this->rawtextTagName = null;
                $this->currentTagName = '';
                $this->originalTagName = '';
                return false;
            }
        }

        $this->appendText('<');
        $this->appendText('/');
        if ($this->tempBuffer !== '') {
            $this->appendText($this->tempBuffer);
        }
        $this->reconsumeCurrent();
        $this->state = self::SCRIPT_DATA_ESCAPED;
        return false;
    }

    private function stateScriptDataDoubleEscapeStart(): bool
    {
        $c = $this->getChar();
        if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f" || $c === '/' || $c === '>') {
            $temp = strtolower($this->tempBuffer);
            if ($temp === 'script') {
                $this->state = self::SCRIPT_DATA_DOUBLE_ESCAPED;
            } else {
                $this->state = self::SCRIPT_DATA_ESCAPED;
            }
            $this->appendText($c);
            return false;
        }
        if ($c !== null) {
            $ord = ord($c);
            if (($ord >= 0x41 && $ord <= 0x5A) || ($ord >= 0x61 && $ord <= 0x7A)) {
                $this->tempBuffer .= $c;
                $this->appendText($c);
                return false;
            }
        }
        $this->reconsumeCurrent();
        $this->state = self::SCRIPT_DATA_ESCAPED;
        return false;
    }

    private function stateScriptDataDoubleEscaped(): bool
    {
        $c = $this->getChar();
        if ($c === null) {
            $this->flushText();
            $this->emitToken(new EOFToken());
            return true;
        }
        if ($c === '-') {
            $this->appendText('-');
            $this->state = self::SCRIPT_DATA_DOUBLE_ESCAPED_DASH;
            return false;
        }
        if ($c === '<') {
            $this->appendText('<');
            $this->state = self::SCRIPT_DATA_DOUBLE_ESCAPED_LESS_THAN_SIGN;
            return false;
        }
        if ($c === "\0") {
            $this->emitError('unexpected-null-character');
            $this->appendText("\u{FFFD}");
            return false;
        }
        $this->appendText($c);
        return false;
    }

    private function stateScriptDataDoubleEscapedDash(): bool
    {
        $c = $this->getChar();
        if ($c === null) {
            $this->flushText();
            $this->emitToken(new EOFToken());
            return true;
        }
        if ($c === '-') {
            $this->appendText('-');
            $this->state = self::SCRIPT_DATA_DOUBLE_ESCAPED_DASH_DASH;
            return false;
        }
        if ($c === '<') {
            $this->appendText('<');
            $this->state = self::SCRIPT_DATA_DOUBLE_ESCAPED_LESS_THAN_SIGN;
            return false;
        }
        if ($c === "\0") {
            $this->emitError('unexpected-null-character');
            $this->appendText("\u{FFFD}");
            $this->state = self::SCRIPT_DATA_DOUBLE_ESCAPED;
            return false;
        }
        $this->appendText($c);
        $this->state = self::SCRIPT_DATA_DOUBLE_ESCAPED;
        return false;
    }

    private function stateScriptDataDoubleEscapedDashDash(): bool
    {
        $c = $this->getChar();
        if ($c === null) {
            $this->flushText();
            $this->emitToken(new EOFToken());
            return true;
        }
        if ($c === '-') {
            $this->appendText('-');
            return false;
        }
        if ($c === '<') {
            $this->appendText('<');
            $this->state = self::SCRIPT_DATA_DOUBLE_ESCAPED_LESS_THAN_SIGN;
            return false;
        }
        if ($c === '>') {
            $this->appendText('>');
            $this->state = self::RAWTEXT;
            return false;
        }
        if ($c === "\0") {
            $this->emitError('unexpected-null-character');
            $this->appendText("\u{FFFD}");
            $this->state = self::SCRIPT_DATA_DOUBLE_ESCAPED;
            return false;
        }
        $this->appendText($c);
        $this->state = self::SCRIPT_DATA_DOUBLE_ESCAPED;
        return false;
    }

    private function stateScriptDataDoubleEscapedLessThanSign(): bool
    {
        $c = $this->getChar();
        if ($c === '/') {
            $this->tempBuffer = '';
            $this->appendText('/');
            $this->state = self::SCRIPT_DATA_DOUBLE_ESCAPE_END;
            return false;
        }
        if ($c !== null) {
            $ord = ord($c);
            if (($ord >= 0x41 && $ord <= 0x5A) || ($ord >= 0x61 && $ord <= 0x7A)) {
                $this->tempBuffer = '';
                $this->reconsumeCurrent();
                $this->state = self::SCRIPT_DATA_DOUBLE_ESCAPE_START;
                return false;
            }
        }
        $this->reconsumeCurrent();
        $this->state = self::SCRIPT_DATA_DOUBLE_ESCAPED;
        return false;
    }

    private function stateScriptDataDoubleEscapeEnd(): bool
    {
        $c = $this->getChar();
        if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f" || $c === '/' || $c === '>') {
            $temp = strtolower($this->tempBuffer);
            if ($temp === 'script') {
                $this->state = self::SCRIPT_DATA_ESCAPED;
            } else {
                $this->state = self::SCRIPT_DATA_DOUBLE_ESCAPED;
            }
            $this->appendText($c);
            return false;
        }
        if ($c !== null) {
            $ord = ord($c);
            if (($ord >= 0x41 && $ord <= 0x5A) || ($ord >= 0x61 && $ord <= 0x7A)) {
                $this->tempBuffer .= $c;
                $this->appendText($c);
                return false;
            }
        }
        $this->reconsumeCurrent();
        $this->state = self::SCRIPT_DATA_DOUBLE_ESCAPED;
        return false;
    }

    private function getChar(): ?string
    {
        if ($this->reconsume) {
            $this->reconsume = false;
            return $this->currentChar;
        }

        $buffer = $this->buffer;
        $pos = $this->pos;
        $length = $this->length;

        while (true) {
            if ($pos >= $length) {
                $this->pos = $pos;
                $this->currentChar = null;
                return null;
            }

            $c = $buffer[$pos];
            $pos += 1;

            if ($c === "\r") {
                $this->ignoreLf = true;
                $this->currentChar = "\n";
                $this->pos = $pos;
                return "\n";
            }

            if ($c === "\n") {
                if ($this->ignoreLf) {
                    $this->ignoreLf = false;
                    continue;
                }
            } else {
                $this->ignoreLf = false;
            }

            $this->currentChar = $c;
            $this->pos = $pos;
            return $c;
        }
    }

    private function reconsumeCurrent(): void
    {
        $this->reconsume = true;
    }

    private function appendText(string $text): void
    {
        if ($this->textBuffer === '') {
            $this->textStartPos = $this->pos;
        }
        $this->textBuffer .= $text;
    }

    private function flushText(): void
    {
        if ($this->textBuffer === '') {
            return;
        }

        $data = $this->textBuffer;
        $rawLen = strlen($data);

        $this->textBuffer = '';

        if ($this->state === self::DATA && strpos($data, "\0") !== false) {
            $count = substr_count($data, "\0");
            for ($i = 0; $i < $count; $i++) {
                $this->emitError('unexpected-null-character');
            }
        }

        if ($this->state >= self::PLAINTEXT || ($this->state >= self::CDATA_SECTION && $this->state <= self::CDATA_SECTION_END)) {
            // No entity decoding.
        } elseif ($this->state >= self::RAWTEXT) {
            // No entity decoding.
        } else {
            if (strpos($data, '&') !== false) {
                $data = Entities::decodeEntitiesInText($data);
            }
        }

        if ($this->opts->xmlCoercion) {
            $data = self::coerceTextForXml($data);
        }

        $this->recordTextEndPosition($rawLen);
        $this->sink->processCharacters($data);
    }

    private function appendAttrValueChar(string $c): void
    {
        $this->currentAttrValue .= $c;
    }

    private function finishAttribute(): void
    {
        if ($this->currentAttrName === '') {
            return;
        }
        $name = $this->currentAttrName;
        $attrs = $this->currentTagAttrs;
        $isDuplicate = array_key_exists($name, $attrs);
        $this->currentAttrName = '';
        if ($isDuplicate) {
            $this->emitError('duplicate-attribute');
            $this->currentAttrValue = '';
            $this->currentAttrValueHasAmp = false;
            return;
        }
        $value = '';
        $value = $this->currentAttrValue;
        if ($this->currentAttrValueHasAmp) {
            $value = Entities::decodeEntitiesInText($value, true);
        }
        $attrs[$name] = $value;
        $this->currentTagAttrs = $attrs;
        $this->currentAttrValue = '';
        $this->currentAttrValueHasAmp = false;
    }

    private function emitCurrentTag(): bool
    {
        $name = $this->currentTagName;
        $attrs = $this->currentTagAttrs;
        $this->currentTagAttrs = [];

        $tag = $this->tagToken;
        $tag->kind = $this->currentTagKind;
        $tag->name = $name;
        $tag->attrs = $attrs;
        $tag->selfClosing = $this->currentTagSelfClosing;

        $switchedToRawtext = false;
        if ($this->currentTagKind === Tag::START) {
            $this->lastStartTagName = $name;
            $needsRawtextCheck = isset(self::RAWTEXT_SWITCH_TAGS[$name]) || $name === 'plaintext';
            if ($needsRawtextCheck) {
                $stack = $this->sink->openElements ?? [];
                $current = $stack ? $stack[count($stack) - 1] : null;
                $namespace = $current ? $current->namespace : null;
                if ($namespace === null || $namespace === 'html') {
                    if (isset(self::RCDATA_ELEMENTS[$name])) {
                        $this->state = self::RCDATA;
                        $this->rawtextTagName = $name;
                        $switchedToRawtext = true;
                    } elseif (isset(self::RAWTEXT_SWITCH_TAGS[$name])) {
                        $this->state = self::RAWTEXT;
                        $this->rawtextTagName = $name;
                        $switchedToRawtext = true;
                    } else {
                        $this->state = self::PLAINTEXT;
                        $switchedToRawtext = true;
                    }
                }
            }
        }

        $this->recordTokenPosition();
        $result = $this->sink->processToken($tag);
        if ($result === TokenSinkResult::Plaintext) {
            $this->state = self::PLAINTEXT;
            $switchedToRawtext = true;
        }

        $this->currentTagName = '';
        $this->currentAttrName = '';
        $this->currentAttrValue = '';
        $this->currentTagSelfClosing = false;
        $this->currentTagKind = Tag::START;
        return $switchedToRawtext;
    }

    private function emitComment(): void
    {
        $data = $this->currentComment;
        $this->currentComment = '';
        if ($this->opts->xmlCoercion) {
            $data = self::coerceCommentForXml($data);
        }
        $this->commentToken->data = $data;
        $this->emitToken($this->commentToken);
    }

    private function emitDoctype(): void
    {
        $name = $this->currentDoctypeName !== '' ? $this->currentDoctypeName : null;
        $publicId = $this->currentDoctypePublic !== null ? $this->currentDoctypePublic : null;
        $systemId = $this->currentDoctypeSystem !== null ? $this->currentDoctypeSystem : null;
        $doctype = new Doctype($name, $publicId, $systemId, $this->currentDoctypeForceQuirks);
        $this->currentDoctypeName = '';
        $this->currentDoctypePublic = null;
        $this->currentDoctypeSystem = null;
        $this->currentDoctypeForceQuirks = false;
        $this->emitToken(new DoctypeToken($doctype));
    }

    private function emitToken($token): void
    {
        $this->recordTokenPosition();
        $this->sink->processToken($token);
    }

    private function recordTokenPosition(): void
    {
        if (!$this->collectErrors) {
            return;
        }
        $pos = $this->pos;
        $lastNewline = strrpos(substr($this->buffer, 0, $pos), "\n");
        if ($lastNewline === false) {
            $column = $pos;
        } else {
            $column = $pos - $lastNewline - 1;
        }
        $this->lastTokenLine = $this->getLineAtPos($pos);
        $this->lastTokenColumn = $column;
    }

    private function recordTextEndPosition(int $rawLen): void
    {
        if (!$this->collectErrors) {
            return;
        }
        $endPos = $this->textStartPos + $rawLen;
        $lastNewline = strrpos(substr($this->buffer, 0, $endPos), "\n");
        if ($lastNewline === false) {
            $column = $endPos;
        } else {
            $column = $endPos - $lastNewline - 1;
        }
        $this->lastTokenLine = $this->getLineAtPos($endPos);
        $this->lastTokenColumn = $column;
    }

    private function emitError(string $code): void
    {
        if (!$this->collectErrors) {
            return;
        }
        $pos = max(0, $this->pos - 1);
        $lastNewline = strrpos(substr($this->buffer, 0, $pos + 1), "\n");
        if ($lastNewline === false) {
            $column = $pos + 1;
        } else {
            $column = $pos - $lastNewline;
        }
        $message = Errors::generateErrorMessage($code);
        $line = $this->getLineAtPos($this->pos);
        $this->errors[] = new ParseError($code, $line, $column, $message, $this->buffer);
    }

    private function consumeIf(string $literal): bool
    {
        $len = strlen($literal);
        $end = $this->pos + $len;
        if ($end > $this->length) {
            return false;
        }
        if (substr_compare($this->buffer, $literal, $this->pos, $len, false) !== 0) {
            return false;
        }
        $this->pos = $end;
        return true;
    }

    private function consumeCaseInsensitive(string $literal): bool
    {
        $len = strlen($literal);
        $end = $this->pos + $len;
        if ($end > $this->length) {
            return false;
        }
        if (substr_compare($this->buffer, $literal, $this->pos, $len, true) !== 0) {
            return false;
        }
        $this->pos = $end;
        return true;
    }

    private function consumeCommentRun(): bool
    {
        if ($this->reconsume) {
            $this->reconsume = false;
            $this->pos = max(0, $this->pos - 1);
        }
        $pos = $this->pos;
        $length = $this->length;
        if ($pos >= $length) {
            return false;
        }
        if ($this->ignoreLf && $pos < $length && $this->buffer[$pos] === "\n") {
            $this->ignoreLf = false;
            $pos += 1;
            $this->pos = $pos;
            return true;
        }
        $runLen = strcspn($this->buffer, "-\0\r", $pos);
        if ($runLen > 0) {
            $chunk = substr($this->buffer, $pos, $runLen);
            $this->currentComment .= $chunk;
            $this->pos = $pos + $runLen;
            return true;
        }
        if ($this->buffer[$pos] === "\r") {
            $this->currentComment .= "\n";
            $pos += 1;
            if ($pos < $length && $this->buffer[$pos] === "\n") {
                $pos += 1;
            }
            $this->pos = $pos;
            return true;
        }
        return false;
    }
}
