<?php

declare(strict_types=1);

namespace JustHTML;

trait TreeBuilderModes
{
    private function _handle_doctype($token)
    {
        if ($this->mode !== InsertionMode::INITIAL) {
            $this->_parse_error('unexpected-doctype');
            return TokenSinkResult::Continue;
        }

        $doctype = $token->doctype;
        [$parseError, $quirksMode] = TreeBuilderUtils::doctypeErrorAndQuirks($doctype, $this->iframe_srcdoc);

        $node = new SimpleDomNode('!doctype', null, $doctype);
        $this->document->appendChild($node);

        if ($parseError) {
            $this->_parse_error('unknown-doctype');
        }

        $this->_set_quirks_mode($quirksMode);
        $this->mode = InsertionMode::BEFORE_HTML;
        return TokenSinkResult::Continue;
    }

    private function _mode_initial($token)
    {
        if ($token instanceof CharacterTokens) {
            if (TreeBuilderUtils::isAllWhitespace($token->data)) {
                return null;
            }
            $this->_parse_error('expected-doctype-but-got-chars');
            $this->_set_quirks_mode('quirks');
            return ['reprocess', InsertionMode::BEFORE_HTML, $token];
        }
        if ($token instanceof CommentToken) {
            $this->_append_comment_to_document($token->data);
            return null;
        }
        if ($token instanceof EOFToken) {
            $this->_parse_error('expected-doctype-but-got-eof');
            $this->_set_quirks_mode('quirks');
            $this->mode = InsertionMode::BEFORE_HTML;
            return ['reprocess', InsertionMode::BEFORE_HTML, $token];
        }
        if ($token instanceof Tag) {
            if ($token->kind === Tag::START) {
                $this->_parse_error('expected-doctype-but-got-start-tag', $token->name, $token);
            } else {
                $this->_parse_error('expected-doctype-but-got-end-tag', $token->name, $token);
            }
            $this->_set_quirks_mode('quirks');
            return ['reprocess', InsertionMode::BEFORE_HTML, $token];
        }
        return null;
    }

    private function _mode_before_html($token)
    {
        if ($token instanceof CharacterTokens && TreeBuilderUtils::isAllWhitespace($token->data)) {
            return null;
        }
        if ($token instanceof CommentToken) {
            $this->_append_comment_to_document($token->data);
            return null;
        }
        if ($token instanceof Tag) {
            if ($token->kind === Tag::START && $token->name === 'html') {
                $this->_create_root($token->attrs);
                $this->mode = InsertionMode::BEFORE_HEAD;
                return null;
            }
            if ($token->kind === Tag::END && in_array($token->name, ['head', 'body', 'html', 'br'], true)) {
                $this->_create_root([]);
                $this->mode = InsertionMode::BEFORE_HEAD;
                return ['reprocess', InsertionMode::BEFORE_HEAD, $token];
            }
            if ($token->kind === Tag::END) {
                $this->_parse_error('unexpected-end-tag-before-html', $token->name);
                return null;
            }
        }
        if ($token instanceof EOFToken) {
            $this->_create_root([]);
            $this->mode = InsertionMode::BEFORE_HEAD;
            return ['reprocess', InsertionMode::BEFORE_HEAD, $token];
        }

        if ($token instanceof CharacterTokens) {
            $stripped = ltrim($token->data, "\t\n\f\r ");
            if (strlen($stripped) !== strlen($token->data)) {
                $token = new CharacterTokens($stripped);
            }
        }

        $this->_create_root([]);
        $this->mode = InsertionMode::BEFORE_HEAD;
        return ['reprocess', InsertionMode::BEFORE_HEAD, $token];
    }

    private function _mode_before_head($token)
    {
        if ($token instanceof CharacterTokens) {
            $data = $token->data ?? '';
            if (strpos($data, "\x00") !== false) {
                $this->_parse_error('invalid-codepoint-before-head');
                $data = str_replace("\x00", '', $data);
                if ($data === '') {
                    return null;
                }
            }
            if (TreeBuilderUtils::isAllWhitespace($data)) {
                return null;
            }
            $token = new CharacterTokens($data);
        }
        if ($token instanceof CommentToken) {
            $this->_append_comment($token->data);
            return null;
        }
        if ($token instanceof Tag) {
            if ($token->kind === Tag::START && $token->name === 'html') {
                $html = $this->open_elements[0] ?? null;
                if ($html !== null) {
                    $this->_add_missing_attributes($html, $token->attrs);
                }
                return null;
            }
            if ($token->kind === Tag::START && $token->name === 'head') {
                $head = $this->_insert_element($token, true);
                $this->head_element = $head;
                $this->mode = InsertionMode::IN_HEAD;
                return null;
            }
            if ($token->kind === Tag::END && in_array($token->name, ['head', 'body', 'html', 'br'], true)) {
                $this->head_element = $this->_insert_phantom('head');
                $this->mode = InsertionMode::IN_HEAD;
                return ['reprocess', InsertionMode::IN_HEAD, $token];
            }
            if ($token->kind === Tag::END) {
                $this->_parse_error('unexpected-end-tag-before-head', $token->name);
                return null;
            }
        }
        if ($token instanceof EOFToken) {
            $this->head_element = $this->_insert_phantom('head');
            $this->mode = InsertionMode::IN_HEAD;
            return ['reprocess', InsertionMode::IN_HEAD, $token];
        }

        $this->head_element = $this->_insert_phantom('head');
        $this->mode = InsertionMode::IN_HEAD;
        return ['reprocess', InsertionMode::IN_HEAD, $token];
    }

    private function _mode_in_head($token)
    {
        if ($token instanceof CharacterTokens) {
            if (TreeBuilderUtils::isAllWhitespace($token->data)) {
                $this->_append_text($token->data);
                return null;
            }
            $data = $token->data ?? '';
            $len = strlen($data);
            $leadingLen = strspn($data, "\t\n\f\r ");
            $leading = $leadingLen > 0 ? substr($data, 0, $leadingLen) : '';
            $remaining = $leadingLen < $len ? substr($data, $leadingLen) : '';
            if ($leading !== '') {
                $current = $this->open_elements ? $this->open_elements[count($this->open_elements) - 1] : null;
                if ($current !== null && $current->hasChildNodes()) {
                    $this->_append_text($leading);
                }
            }
            $this->_pop_current();
            $this->mode = InsertionMode::AFTER_HEAD;
            return ['reprocess', InsertionMode::AFTER_HEAD, new CharacterTokens($remaining)];
        }
        if ($token instanceof CommentToken) {
            $this->_append_comment($token->data);
            return null;
        }
        if ($token instanceof Tag) {
            if ($token->kind === Tag::START && $token->name === 'html') {
                $this->_pop_current();
                $this->mode = InsertionMode::AFTER_HEAD;
                return ['reprocess', InsertionMode::AFTER_HEAD, $token];
            }
            if ($token->kind === Tag::START && in_array($token->name, ['base', 'basefont', 'bgsound', 'link', 'meta'], true)) {
                $this->_insert_element($token, false);
                return null;
            }
            if ($token->kind === Tag::START && $token->name === 'template') {
                $this->_insert_element($token, true);
                $this->_push_formatting_marker();
                $this->frameset_ok = false;
                $this->mode = InsertionMode::IN_TEMPLATE;
                $this->template_modes[] = InsertionMode::IN_TEMPLATE;
                return null;
            }
            if ($token->kind === Tag::END && $token->name === 'template') {
                $hasTemplate = false;
                foreach ($this->open_elements as $node) {
                    if ($node->name === 'template') {
                        $hasTemplate = true;
                        break;
                    }
                }
                if (!$hasTemplate) {
                    return null;
                }
                $this->_generate_implied_end_tags();
                $this->_pop_until_inclusive('template');
                $this->_clear_active_formatting_up_to_marker();
                array_pop($this->template_modes);
                $this->_reset_insertion_mode();
                return null;
            }
            if ($token->kind === Tag::START && in_array($token->name, ['title', 'style', 'script', 'noframes'], true)) {
                $this->_insert_element($token, true);
                $this->original_mode = $this->mode;
                $this->mode = InsertionMode::TEXT;
                return null;
            }
            if ($token->kind === Tag::START && $token->name === 'noscript') {
                $this->_insert_element($token, true);
                $this->mode = InsertionMode::IN_HEAD_NOSCRIPT;
                return null;
            }
            if ($token->kind === Tag::END && $token->name === 'head') {
                $this->_pop_current();
                $this->mode = InsertionMode::AFTER_HEAD;
                return null;
            }
            if ($token->kind === Tag::END && in_array($token->name, ['body', 'html', 'br'], true)) {
                $this->_pop_current();
                $this->mode = InsertionMode::AFTER_HEAD;
                return ['reprocess', InsertionMode::AFTER_HEAD, $token];
            }
        }
        if ($token instanceof EOFToken) {
            $this->_pop_current();
            $this->mode = InsertionMode::AFTER_HEAD;
            return ['reprocess', InsertionMode::AFTER_HEAD, $token];
        }

        $this->_pop_current();
        $this->mode = InsertionMode::AFTER_HEAD;
        return ['reprocess', InsertionMode::AFTER_HEAD, $token];
    }

    private function _mode_in_head_noscript($token)
    {
        if ($token instanceof CharacterTokens) {
            $data = $token->data ?? '';
            if (TreeBuilderUtils::isAllWhitespace($data)) {
                return $this->_mode_in_head($token);
            }
            $this->_parse_error('unexpected-start-tag', 'text');
            $this->_pop_current();
            $this->mode = InsertionMode::IN_HEAD;
            return ['reprocess', InsertionMode::IN_HEAD, $token];
        }
        if ($token instanceof CommentToken) {
            return $this->_mode_in_head($token);
        }
        if ($token instanceof Tag) {
            if ($token->kind === Tag::START) {
                if ($token->name === 'html') {
                    return $this->_mode_in_body($token);
                }
                if (in_array($token->name, ['basefont', 'bgsound', 'link', 'meta', 'noframes', 'style'], true)) {
                    return $this->_mode_in_head($token);
                }
                if (in_array($token->name, ['head', 'noscript'], true)) {
                    $this->_parse_error('unexpected-start-tag', $token->name);
                    return null;
                }
                $this->_parse_error('unexpected-start-tag', $token->name);
                $this->_pop_current();
                $this->mode = InsertionMode::IN_HEAD;
                return ['reprocess', InsertionMode::IN_HEAD, $token];
            }
            if ($token->name === 'noscript') {
                $this->_pop_current();
                $this->mode = InsertionMode::IN_HEAD;
                return null;
            }
            if ($token->name === 'br') {
                $this->_parse_error('unexpected-end-tag', $token->name);
                $this->_pop_current();
                $this->mode = InsertionMode::IN_HEAD;
                return ['reprocess', InsertionMode::IN_HEAD, $token];
            }
            $this->_parse_error('unexpected-end-tag', $token->name);
            return null;
        }
        if ($token instanceof EOFToken) {
            $this->_parse_error('expected-closing-tag-but-got-eof', 'noscript');
            $this->_pop_current();
            $this->mode = InsertionMode::IN_HEAD;
            return ['reprocess', InsertionMode::IN_HEAD, $token];
        }
        return null;
    }

    private function _mode_after_head($token)
    {
        if ($token instanceof CharacterTokens) {
            $data = $token->data ?? '';
            if (strpos($data, "\x00") !== false) {
                $this->_parse_error('invalid-codepoint-in-body');
                $data = str_replace("\x00", '', $data);
            }
            if (strpos($data, "\x0c") !== false) {
                $this->_parse_error('invalid-codepoint-in-body');
                $data = str_replace("\x0c", '', $data);
            }
            if ($data === '' || TreeBuilderUtils::isAllWhitespace($data)) {
                if ($data !== '') {
                    $this->_append_text($data);
                }
                return null;
            }
            $this->_insert_body_if_missing();
            return ['reprocess', InsertionMode::IN_BODY, new CharacterTokens($data)];
        }
        if ($token instanceof CommentToken) {
            $this->_append_comment($token->data);
            return null;
        }
        if ($token instanceof Tag) {
            if ($token->kind === Tag::START && $token->name === 'html') {
                $this->_insert_body_if_missing();
                return ['reprocess', InsertionMode::IN_BODY, $token];
            }
            if ($token->kind === Tag::START && $token->name === 'body') {
                $this->_insert_element($token, true);
                $this->mode = InsertionMode::IN_BODY;
                $this->frameset_ok = false;
                return null;
            }
            if ($token->kind === Tag::START && $token->name === 'frameset') {
                $this->_insert_element($token, true);
                $this->mode = InsertionMode::IN_FRAMESET;
                return null;
            }
            if ($token->kind === Tag::START && $token->name === 'input') {
                $inputType = null;
                foreach ($token->attrs as $name => $value) {
                    if ($name === 'type') {
                        $inputType = strtolower($value ?? '');
                        break;
                    }
                }
                if ($inputType === 'hidden') {
                    $this->_parse_error('unexpected-hidden-input-after-head');
                    return null;
                }
                $this->_insert_body_if_missing();
                return ['reprocess', InsertionMode::IN_BODY, $token];
            }
            if ($token->kind === Tag::START && in_array($token->name, ['base', 'basefont', 'bgsound', 'link', 'meta', 'title', 'style', 'script', 'noscript'], true)) {
                $this->open_elements[] = $this->head_element;
                $result = $this->_mode_in_head($token);
                $idx = array_search($this->head_element, $this->open_elements, true);
                if ($idx !== false) {
                    array_splice($this->open_elements, (int)$idx, 1);
                }
                return $result;
            }
            if ($token->kind === Tag::START && $token->name === 'template') {
                $this->open_elements[] = $this->head_element;
                $this->mode = InsertionMode::IN_HEAD;
                return ['reprocess', InsertionMode::IN_HEAD, $token];
            }
            if ($token->kind === Tag::END && $token->name === 'template') {
                return $this->_mode_in_head($token);
            }
            if ($token->kind === Tag::END && $token->name === 'body') {
                $this->_insert_body_if_missing();
                return ['reprocess', InsertionMode::IN_BODY, $token];
            }
            if ($token->kind === Tag::END && in_array($token->name, ['html', 'br'], true)) {
                $this->_insert_body_if_missing();
                return ['reprocess', InsertionMode::IN_BODY, $token];
            }
            if ($token->kind === Tag::END) {
                $this->_parse_error('unexpected-end-tag-after-head', $token->name);
                return null;
            }
        }
        if ($token instanceof EOFToken) {
            $this->_insert_body_if_missing();
            $this->mode = InsertionMode::IN_BODY;
            return ['reprocess', InsertionMode::IN_BODY, $token];
        }

        $this->_insert_body_if_missing();
        return ['reprocess', InsertionMode::IN_BODY, $token];
    }

    private function _mode_text($token)
    {
        if ($token instanceof CharacterTokens) {
            $this->_append_text($token->data);
            return null;
        }
        if ($token instanceof EOFToken) {
            $tagName = $this->open_elements ? $this->open_elements[count($this->open_elements) - 1]->name : null;
            $this->_parse_error('expected-named-closing-tag-but-got-eof', $tagName);
            $this->_pop_current();
            $this->mode = $this->original_mode ?? InsertionMode::IN_BODY;
            return ['reprocess', $this->mode, $token];
        }
        $this->_pop_current();
        $this->mode = $this->original_mode ?? InsertionMode::IN_BODY;
        return null;
    }

    private function _mode_in_body($token)
    {
        $type = get_class($token);
        $handler = $this->_BODY_TOKEN_HANDLERS[$type] ?? null;
        return $handler ? $this->{$handler}($token) : null;
    }

    private function _handle_characters_in_body($token)
    {
        $data = $token->data ?? '';
        if (strpos($data, "\x00") !== false) {
            $this->_parse_error('invalid-codepoint');
            $data = str_replace("\x00", '', $data);
        }
        if (TreeBuilderUtils::isAllWhitespace($data)) {
            $this->_reconstruct_active_formatting_elements();
            $this->_append_text($data);
            return;
        }
        $this->_reconstruct_active_formatting_elements();
        $this->frameset_ok = false;
        $this->_append_text($data);
        return;
    }

    private function _handle_comment_in_body($token)
    {
        $this->_append_comment($token->data);
        return;
    }

    private function _handle_tag_in_body($token)
    {
        if ($token->kind === Tag::START) {
            $handler = $this->_BODY_START_HANDLERS[$token->name] ?? null;
            if ($handler) {
                return $this->{$handler}($token);
            }
            return $this->_handle_body_start_default($token);
        }

        $name = $token->name;
        if ($name === 'br') {
            $this->_parse_error('unexpected-end-tag', $name, $token);
            $brTag = new Tag(Tag::START, 'br', [], false);
            return $this->_mode_in_body($brTag);
        }
        if (isset(Constants::FORMATTING_ELEMENTS[$name])) {
            $this->_adoption_agency($name);
            return null;
        }
        $handler = $this->_BODY_END_HANDLERS[$name] ?? null;
        if ($handler) {
            return $this->{$handler}($token);
        }
        $this->_any_other_end_tag($token->name);
        return null;
    }

    private function _handle_eof_in_body($token)
    {
        if ($this->template_modes) {
            return $this->_mode_in_template($token);
        }
        foreach ($this->open_elements as $node) {
            if (!isset([
                'dd' => true,
                'dt' => true,
                'li' => true,
                'optgroup' => true,
                'option' => true,
                'p' => true,
                'rb' => true,
                'rp' => true,
                'rt' => true,
                'rtc' => true,
                'tbody' => true,
                'td' => true,
                'tfoot' => true,
                'th' => true,
                'thead' => true,
                'tr' => true,
                'body' => true,
                'html' => true,
            ][$node->name])) {
                $this->_parse_error('expected-closing-tag-but-got-eof', $node->name);
                break;
            }
        }
        $this->mode = InsertionMode::AFTER_BODY;
        return ['reprocess', InsertionMode::AFTER_BODY, $token];
    }

    private function _handle_body_start_html($token)
    {
        if ($this->template_modes) {
            $this->_parse_error('unexpected-start-tag', $token->name);
            return;
        }
        if ($this->open_elements) {
            $html = $this->open_elements[0];
            $this->_add_missing_attributes($html, $token->attrs);
        }
        return;
    }

    private function _handle_body_start_body($token)
    {
        if ($this->template_modes) {
            $this->_parse_error('unexpected-start-tag', $token->name);
            return;
        }
        if (count($this->open_elements) > 1) {
            $this->_parse_error('unexpected-start-tag', $token->name);
            $body = $this->open_elements[1] ?? null;
            if ($body && $body->name === 'body') {
                $this->_add_missing_attributes($body, $token->attrs);
            }
            $this->frameset_ok = false;
            return;
        }
        $this->frameset_ok = false;
        return;
    }

    private function _handle_body_start_head($token)
    {
        $this->_parse_error('unexpected-start-tag', $token->name);
        return;
    }

    private function _handle_body_start_in_head($token)
    {
        return $this->_mode_in_head($token);
    }

    private function _handle_body_start_block_with_p($token)
    {
        $this->_close_p_element();
        $this->_insert_element($token, true);
        return;
    }

    private function _handle_body_start_heading($token)
    {
        $this->_close_p_element();
        if ($this->open_elements && isset(Constants::HEADING_ELEMENTS[$this->open_elements[count($this->open_elements) - 1]->name])) {
            $this->_parse_error('unexpected-start-tag', $token->name);
            $this->_pop_current();
        }
        $this->_insert_element($token, true);
        $this->frameset_ok = false;
        return;
    }

    private function _handle_body_start_pre_listing($token)
    {
        $this->_close_p_element();
        $this->_insert_element($token, true);
        $this->ignore_lf = true;
        $this->frameset_ok = false;
        return;
    }

    private function _handle_body_start_form($token)
    {
        if ($this->form_element !== null) {
            $this->_parse_error('unexpected-start-tag', $token->name);
            return;
        }
        $this->_close_p_element();
        $node = $this->_insert_element($token, true);
        $this->form_element = $node;
        $this->frameset_ok = false;
        return;
    }

    private function _handle_body_start_button($token)
    {
        if ($this->_has_in_scope('button')) {
            $this->_parse_error('unexpected-start-tag-implies-end-tag', $token->name);
            $this->_close_element_by_name('button');
        }
        $this->_insert_element($token, true);
        $this->frameset_ok = false;
        return;
    }

    private function _handle_body_start_paragraph($token)
    {
        $this->_close_p_element();
        $this->_insert_element($token, true);
        return;
    }

    private function _handle_body_start_math($token)
    {
        $this->_reconstruct_active_formatting_elements();
        $attrs = $this->_prepare_foreign_attributes('math', $token->attrs);
        $newTag = new Tag(Tag::START, $token->name, $attrs, $token->selfClosing);
        $this->_insert_element($newTag, !$token->selfClosing, 'math');
        return;
    }

    private function _handle_body_start_svg($token)
    {
        $this->_reconstruct_active_formatting_elements();
        $adjusted = $this->_adjust_svg_tag_name($token->name);
        $attrs = $this->_prepare_foreign_attributes('svg', $token->attrs);
        $newTag = new Tag(Tag::START, $adjusted, $attrs, $token->selfClosing);
        $this->_insert_element($newTag, !$token->selfClosing, 'svg');
        return;
    }

    private function _handle_body_start_li($token)
    {
        $this->frameset_ok = false;
        $this->_close_p_element();
        if ($this->_has_in_list_item_scope('li')) {
            $this->_pop_until_any_inclusive(['li' => true]);
        }
        $this->_insert_element($token, true);
        return;
    }

    private function _handle_body_start_dd_dt($token)
    {
        $this->frameset_ok = false;
        $this->_close_p_element();
        $name = $token->name;
        if ($name === 'dd') {
            if ($this->_has_in_definition_scope('dd')) {
                $this->_pop_until_any_inclusive(['dd' => true]);
            }
            if ($this->_has_in_definition_scope('dt')) {
                $this->_pop_until_any_inclusive(['dt' => true]);
            }
        } else {
            if ($this->_has_in_definition_scope('dt')) {
                $this->_pop_until_any_inclusive(['dt' => true]);
            }
            if ($this->_has_in_definition_scope('dd')) {
                $this->_pop_until_any_inclusive(['dd' => true]);
            }
        }
        $this->_insert_element($token, true);
        return;
    }

    private function _adoption_agency($subject): void
    {
        if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name === $subject) {
            if (!$this->_has_active_formatting_entry($subject)) {
                $this->_pop_until_inclusive($subject);
                return;
            }
        }

        for ($outer = 0; $outer < 8; $outer++) {
            $formattingIndex = $this->_find_active_formatting_index($subject);
            if ($formattingIndex === null) {
                return;
            }

            $formattingEntry = $this->active_formatting[$formattingIndex];
            $formattingElement = $formattingEntry['node'];

            if (!in_array($formattingElement, $this->open_elements, true)) {
                $this->_parse_error('adoption-agency-1.3');
                $this->_remove_formatting_entry($formattingIndex);
                return;
            }

            if (!$this->_has_element_in_scope($formattingElement->name)) {
                $this->_parse_error('adoption-agency-1.3');
                return;
            }

            if ($formattingElement !== $this->open_elements[count($this->open_elements) - 1]) {
                $this->_parse_error('adoption-agency-1.3');
            }

            $furthestBlock = null;
            $formattingInOpenIndex = array_search($formattingElement, $this->open_elements, true);
            if ($formattingInOpenIndex === false) {
                return;
            }

            $count = count($this->open_elements);
            for ($i = $formattingInOpenIndex + 1; $i < $count; $i++) {
                $node = $this->open_elements[$i];
                if ($this->_is_special_element($node)) {
                    $furthestBlock = $node;
                    break;
                }
            }

            if ($furthestBlock === null) {
                while ($this->open_elements) {
                    $popped = array_pop($this->open_elements);
                    if ($popped === $formattingElement) {
                        break;
                    }
                }
                $this->_remove_formatting_entry($formattingIndex);
                return;
            }

            $bookmark = $formattingIndex + 1;
            $node = $furthestBlock;
            $lastNode = $furthestBlock;

            $innerLoopCounter = 0;
            while (true) {
                $innerLoopCounter++;

                $nodeIndex = array_search($node, $this->open_elements, true);
                $node = $this->open_elements[$nodeIndex - 1];

                if ($node === $formattingElement) {
                    break;
                }

                $nodeFormattingIndex = $this->_find_active_formatting_index_by_node($node);

                if ($innerLoopCounter > 3 && $nodeFormattingIndex !== null) {
                    $this->_remove_formatting_entry($nodeFormattingIndex);
                    if ($nodeFormattingIndex < $bookmark) {
                        $bookmark -= 1;
                    }
                    $nodeFormattingIndex = null;
                }

                if ($nodeFormattingIndex === null) {
                    $nodeIndex = array_search($node, $this->open_elements, true);
                    array_splice($this->open_elements, $nodeIndex, 1);
                    $node = $this->open_elements[$nodeIndex];
                    continue;
                }

                $entry = $this->active_formatting[$nodeFormattingIndex];
                $newElement = $this->_create_element($entry['name'], $entry['node']->namespace, $entry['attrs']);
                $entry['node'] = $newElement;
                $this->active_formatting[$nodeFormattingIndex] = $entry;
                $this->open_elements[array_search($node, $this->open_elements, true)] = $newElement;
                $node = $newElement;

                if ($lastNode === $furthestBlock) {
                    $bookmark = $nodeFormattingIndex + 1;
                }

                if ($lastNode->parent) {
                    $lastNode->parent->removeChild($lastNode);
                }
                $node->appendChild($lastNode);

                $lastNode = $node;
            }

            $commonAncestor = $this->open_elements[$formattingInOpenIndex - 1];
            if ($lastNode->parent) {
                $lastNode->parent->removeChild($lastNode);
            }

            if ($this->_should_foster_parenting($commonAncestor, $lastNode->name)) {
                [$parent, $position] = $this->_appropriate_insertion_location($commonAncestor, true);
                $this->_insert_node_at($parent, $position, $lastNode);
            } else {
                if ($commonAncestor instanceof TemplateNode && $commonAncestor->templateContent) {
                    $commonAncestor->templateContent->appendChild($lastNode);
                } else {
                    $commonAncestor->appendChild($lastNode);
                }
            }

            $entry = $this->active_formatting[$formattingIndex];
            $newFormattingElement = $this->_create_element($entry['name'], $entry['node']->namespace, $entry['attrs']);
            $entry['node'] = $newFormattingElement;

            while ($furthestBlock->hasChildNodes()) {
                $child = $furthestBlock->children[0];
                $furthestBlock->removeChild($child);
                $newFormattingElement->appendChild($child);
            }

            $furthestBlock->appendChild($newFormattingElement);

            $this->_remove_formatting_entry($formattingIndex);
            $bookmark -= 1;
            array_splice($this->active_formatting, $bookmark, 0, [$entry]);

            $formattingIndexInOpen = array_search($formattingElement, $this->open_elements, true);
            array_splice($this->open_elements, $formattingIndexInOpen, 1);
            $furthestBlockIndex = array_search($furthestBlock, $this->open_elements, true);
            array_splice($this->open_elements, $furthestBlockIndex + 1, 0, [$newFormattingElement]);
        }
    }

    private function _handle_body_start_a($token)
    {
        if ($this->_has_active_formatting_entry('a')) {
            $this->_adoption_agency('a');
            $this->_remove_last_active_formatting_by_name('a');
            $this->_remove_last_open_element_by_name('a');
        }
        $this->_reconstruct_active_formatting_elements();
        $node = $this->_insert_element($token, true);
        $this->_append_active_formatting_entry('a', $token->attrs, $node);
        return;
    }

    private function _handle_body_start_formatting($token)
    {
        $name = $token->name;
        if ($name === 'nobr' && $this->_in_scope('nobr')) {
            $this->_adoption_agency('nobr');
            $this->_remove_last_active_formatting_by_name('nobr');
            $this->_remove_last_open_element_by_name('nobr');
        }
        $this->_reconstruct_active_formatting_elements();
        $duplicateIndex = $this->_find_active_formatting_duplicate($name, $token->attrs);
        if ($duplicateIndex !== null) {
            $this->_remove_formatting_entry($duplicateIndex);
        }
        $node = $this->_insert_element($token, true);
        $this->_append_active_formatting_entry($name, $token->attrs, $node);
        return;
    }

    private function _handle_body_start_applet_like($token)
    {
        $this->_reconstruct_active_formatting_elements();
        $this->_insert_element($token, true);
        $this->_push_formatting_marker();
        $this->frameset_ok = false;
        return;
    }

    private function _handle_body_start_br($token)
    {
        $this->_close_p_element();
        $this->_reconstruct_active_formatting_elements();
        $this->_insert_element($token, false);
        $this->frameset_ok = false;
        return;
    }

    private function _handle_body_start_hr($token)
    {
        $this->_close_p_element();
        $this->_insert_element($token, false);
        $this->frameset_ok = false;
        return;
    }

    private function _handle_body_start_frameset($token)
    {
        if (!$this->frameset_ok) {
            $this->_parse_error('unexpected-start-tag-ignored', $token->name);
            return;
        }
        $bodyIndex = null;
        foreach ($this->open_elements as $idx => $elem) {
            if ($elem->name === 'body') {
                $bodyIndex = $idx;
                break;
            }
        }
        if ($bodyIndex === null) {
            $this->_parse_error('unexpected-start-tag-ignored', $token->name);
            return;
        }
        $bodyElem = $this->open_elements[$bodyIndex];
        $bodyElem->parent->removeChild($bodyElem);
        $this->open_elements = array_slice($this->open_elements, 0, $bodyIndex);
        $this->_insert_element($token, true);
        $this->mode = InsertionMode::IN_FRAMESET;
        return;
    }

    private function _handle_body_end_body($token)
    {
        if ($this->_in_scope('body')) {
            $this->mode = InsertionMode::AFTER_BODY;
        }
        return;
    }

    private function _handle_body_end_html($token)
    {
        if ($this->_in_scope('body')) {
            return ['reprocess', InsertionMode::AFTER_BODY, $token];
        }
        return null;
    }

    private function _handle_body_end_p($token)
    {
        if (!$this->_close_p_element()) {
            $this->_parse_error('unexpected-end-tag', $token->name);
            $phantom = new Tag(Tag::START, 'p', [], false);
            $this->_insert_element($phantom, true);
            $this->_close_p_element();
        }
        return;
    }

    private function _handle_body_end_li($token)
    {
        if (!$this->_has_in_list_item_scope('li')) {
            $this->_parse_error('unexpected-end-tag', $token->name);
            return;
        }
        $this->_pop_until_any_inclusive(['li' => true]);
        return;
    }

    private function _handle_body_end_dd_dt($token)
    {
        $name = $token->name;
        if (!$this->_has_in_definition_scope($name)) {
            $this->_parse_error('unexpected-end-tag', $name);
            return;
        }
        $this->_pop_until_any_inclusive(['dd' => true, 'dt' => true]);
        return;
    }

    private function _handle_body_end_form($token)
    {
        if ($this->form_element === null) {
            $this->_parse_error('unexpected-end-tag', $token->name);
            return;
        }
        $removed = $this->_remove_from_open_elements($this->form_element);
        $this->form_element = null;
        if (!$removed) {
            $this->_parse_error('unexpected-end-tag', $token->name);
        }
        return;
    }

    private function _handle_body_end_applet_like($token)
    {
        $name = $token->name;
        if (!$this->_in_scope($name)) {
            $this->_parse_error('unexpected-end-tag', $name);
            return;
        }
        while ($this->open_elements) {
            $popped = array_pop($this->open_elements);
            if ($popped->name === $name) {
                break;
            }
        }
        $this->_clear_active_formatting_up_to_marker();
        return;
    }

    private function _handle_body_end_heading($token)
    {
        $name = $token->name;
        if (!$this->_has_any_in_scope(Constants::HEADING_ELEMENTS)) {
            $this->_parse_error('unexpected-end-tag', $name);
            return;
        }
        $this->_generate_implied_end_tags();
        if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name !== $name) {
            $this->_parse_error('end-tag-too-early', $name);
        }
        while ($this->open_elements) {
            $popped = array_pop($this->open_elements);
            if (isset(Constants::HEADING_ELEMENTS[$popped->name])) {
                break;
            }
        }
        return;
    }

    private function _handle_body_end_block($token)
    {
        $name = $token->name;
        if (!$this->_in_scope($name)) {
            $this->_parse_error('unexpected-end-tag', $name);
            return;
        }
        $this->_generate_implied_end_tags();
        if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name !== $name) {
            $this->_parse_error('end-tag-too-early', $name);
        }
        $this->_pop_until_any_inclusive([$name => true]);
        return;
    }

    private function _handle_body_end_template($token)
    {
        $hasTemplate = false;
        foreach ($this->open_elements as $node) {
            if ($node->name === 'template') {
                $hasTemplate = true;
                break;
            }
        }
        if (!$hasTemplate) {
            return;
        }
        $this->_generate_implied_end_tags();
        $this->_pop_until_inclusive('template');
        $this->_clear_active_formatting_up_to_marker();
        if ($this->template_modes) {
            array_pop($this->template_modes);
        }
        $this->_reset_insertion_mode();
        return;
    }

    private function _handle_body_start_structure_ignored($token)
    {
        $this->_parse_error('unexpected-start-tag-ignored', $token->name);
        return;
    }

    private function _handle_body_start_col_or_frame($token)
    {
        if ($this->fragment_context === null) {
            $this->_parse_error('unexpected-start-tag-ignored', $token->name);
            return;
        }
        $this->_insert_element($token, false);
        return;
    }

    private function _handle_body_start_image($token)
    {
        $this->_parse_error('image-start-tag', $token->name);
        $imgToken = new Tag(Tag::START, 'img', $token->attrs, $token->selfClosing);
        $this->_reconstruct_active_formatting_elements();
        $this->_insert_element($imgToken, false);
        $this->frameset_ok = false;
        return;
    }

    private function _handle_body_start_void_with_formatting($token)
    {
        $this->_reconstruct_active_formatting_elements();
        $this->_insert_element($token, false);
        $this->frameset_ok = false;
        return;
    }

    private function _handle_body_start_simple_void($token)
    {
        $this->_insert_element($token, false);
        return;
    }

    private function _handle_body_start_input($token)
    {
        $inputType = null;
        foreach ($token->attrs as $name => $value) {
            if ($name === 'type') {
                $inputType = strtolower($value ?? '');
                break;
            }
        }
        $this->_insert_element($token, false);
        if ($inputType !== 'hidden') {
            $this->frameset_ok = false;
        }
        return;
    }

    private function _handle_body_start_table($token)
    {
        if ($this->quirks_mode !== 'quirks') {
            $this->_close_p_element();
        }
        $this->_insert_element($token, true);
        $this->frameset_ok = false;
        $this->mode = InsertionMode::IN_TABLE;
        return;
    }

    private function _handle_body_start_plaintext_xmp($token)
    {
        $this->_close_p_element();
        $this->_insert_element($token, true);
        $this->frameset_ok = false;
        if ($token->name === 'plaintext') {
            $this->tokenizer_state_override = TokenSinkResult::Plaintext;
        } else {
            $this->original_mode = $this->mode;
            $this->mode = InsertionMode::TEXT;
        }
        return;
    }

    private function _handle_body_start_textarea($token)
    {
        $this->_insert_element($token, true);
        $this->ignore_lf = true;
        $this->frameset_ok = false;
        return;
    }

    private function _handle_body_start_select($token)
    {
        $this->_reconstruct_active_formatting_elements();
        $this->_insert_element($token, true);
        $this->frameset_ok = false;
        $this->_reset_insertion_mode();
        return;
    }

    private function _handle_body_start_option($token)
    {
        if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name === 'option') {
            array_pop($this->open_elements);
        }
        $this->_reconstruct_active_formatting_elements();
        $this->_insert_element($token, true);
        return;
    }

    private function _handle_body_start_optgroup($token)
    {
        if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name === 'option') {
            array_pop($this->open_elements);
        }
        $this->_reconstruct_active_formatting_elements();
        $this->_insert_element($token, true);
        return;
    }

    private function _handle_body_start_rp_rt($token)
    {
        $this->_generate_implied_end_tags('rtc');
        $this->_insert_element($token, true);
        return;
    }

    private function _handle_body_start_rb_rtc($token)
    {
        if ($this->open_elements) {
            $current = $this->open_elements[count($this->open_elements) - 1];
            if (isset(['rb' => true, 'rp' => true, 'rt' => true, 'rtc' => true][$current->name])) {
                $this->_generate_implied_end_tags();
            }
        }
        $this->_insert_element($token, true);
        return;
    }

    private function _handle_body_start_table_parse_error($token)
    {
        $this->_parse_error('unexpected-start-tag', $token->name);
        return;
    }

    private function _handle_body_start_default($token)
    {
        $this->_reconstruct_active_formatting_elements();
        $this->_insert_element($token, true);
        if ($token->selfClosing) {
            $this->_parse_error('non-void-html-element-start-tag-with-trailing-solidus', $token->name);
        }
        $this->frameset_ok = false;
        return;
    }

    private function _mode_in_table($token)
    {
        if ($token instanceof CharacterTokens) {
            $data = $token->data ?? '';
            if (strpos($data, "\x00") !== false) {
                $this->_parse_error('unexpected-null-character');
                $data = str_replace("\x00", '', $data);
                if ($data === '') {
                    return null;
                }
                $token = new CharacterTokens($data);
            }
            $this->pending_table_text = [];
            $this->table_text_original_mode = $this->mode;
            $this->mode = InsertionMode::IN_TABLE_TEXT;
            return ['reprocess', InsertionMode::IN_TABLE_TEXT, $token];
        }
        if ($token instanceof CommentToken) {
            $this->_append_comment($token->data);
            return null;
        }
        if ($token instanceof Tag) {
            $name = $token->name;
            if ($token->kind === Tag::START) {
                if ($name === 'caption') {
                    $this->_clear_stack_until(['table' => true, 'template' => true, 'html' => true]);
                    $this->_push_formatting_marker();
                    $this->_insert_element($token, true);
                    $this->mode = InsertionMode::IN_CAPTION;
                    return null;
                }
                if ($name === 'colgroup') {
                    $this->_clear_stack_until(['table' => true, 'template' => true, 'html' => true]);
                    $this->_insert_element($token, true);
                    $this->mode = InsertionMode::IN_COLUMN_GROUP;
                    return null;
                }
                if ($name === 'col') {
                    $this->_clear_stack_until(['table' => true, 'template' => true, 'html' => true]);
                    $implied = new Tag(Tag::START, 'colgroup', [], false);
                    $this->_insert_element($implied, true);
                    $this->mode = InsertionMode::IN_COLUMN_GROUP;
                    return ['reprocess', InsertionMode::IN_COLUMN_GROUP, $token];
                }
                if (in_array($name, ['tbody', 'tfoot', 'thead'], true)) {
                    $this->_clear_stack_until(['table' => true, 'template' => true, 'html' => true]);
                    $this->_insert_element($token, true);
                    $this->mode = InsertionMode::IN_TABLE_BODY;
                    return null;
                }
                if (in_array($name, ['td', 'th', 'tr'], true)) {
                    $this->_clear_stack_until(['table' => true, 'template' => true, 'html' => true]);
                    $implied = new Tag(Tag::START, 'tbody', [], false);
                    $this->_insert_element($implied, true);
                    $this->mode = InsertionMode::IN_TABLE_BODY;
                    return ['reprocess', InsertionMode::IN_TABLE_BODY, $token];
                }
                if ($name === 'table') {
                    $this->_parse_error('unexpected-start-tag-implies-end-tag', $name);
                    $closed = $this->_close_table_element();
                    if ($closed) {
                        return ['reprocess', $this->mode, $token];
                    }
                    return null;
                }
                if (in_array($name, ['style', 'script'], true)) {
                    $this->_insert_element($token, true);
                    $this->original_mode = $this->mode;
                    $this->mode = InsertionMode::TEXT;
                    return null;
                }
                if ($name === 'template') {
                    return $this->_mode_in_head($token);
                }
                if ($name === 'input') {
                    $inputType = null;
                    foreach ($token->attrs as $attrName => $attrValue) {
                        if ($attrName === 'type') {
                            $inputType = strtolower($attrValue ?? '');
                            break;
                        }
                    }
                    if ($inputType === 'hidden') {
                        $this->_parse_error('unexpected-hidden-input-in-table');
                        $this->_insert_element($token, true);
                        array_pop($this->open_elements);
                        return null;
                    }
                }
                if ($name === 'form') {
                    $this->_parse_error('unexpected-form-in-table');
                    if ($this->form_element === null) {
                        $node = $this->_insert_element($token, true);
                        $this->form_element = $node;
                        array_pop($this->open_elements);
                    }
                    return null;
                }
                $this->_parse_error('unexpected-start-tag-implies-table-voodoo', $name);
                $previous = $this->insert_from_table;
                $this->insert_from_table = true;
                try {
                    return $this->_mode_in_body($token);
                } finally {
                    $this->insert_from_table = $previous;
                }
            }
            if ($name === 'table') {
                $this->_close_table_element();
                return null;
            }
            if (in_array($name, ['body', 'caption', 'col', 'colgroup', 'html', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr'], true)) {
                $this->_parse_error('unexpected-end-tag', $name);
                return null;
            }
            $this->_parse_error('unexpected-end-tag-implies-table-voodoo', $name);
            $previous = $this->insert_from_table;
            $this->insert_from_table = true;
            try {
                return $this->_mode_in_body($token);
            } finally {
                $this->insert_from_table = $previous;
            }
        }

        if ($this->template_modes) {
            return $this->_mode_in_template($token);
        }
        if ($this->_has_in_table_scope('table')) {
            $this->_parse_error('expected-closing-tag-but-got-eof', 'table');
        }
        return null;
    }

    private function _mode_in_table_text($token)
    {
        if ($token instanceof CharacterTokens) {
            $data = $token->data;
            if (strpos($data, "\x0c") !== false) {
                $this->_parse_error('invalid-codepoint-in-table-text');
                $data = str_replace("\x0c", '', $data);
            }
            if ($data !== '') {
                $this->pending_table_text[] = $data;
            }
            return null;
        }
        $this->_flush_pending_table_text();
        $original = $this->table_text_original_mode ?? InsertionMode::IN_TABLE;
        $this->table_text_original_mode = null;
        $this->mode = $original;
        return ['reprocess', $original, $token];
    }

    private function _mode_in_caption($token)
    {
        if ($token instanceof CharacterTokens) {
            return $this->_mode_in_body($token);
        }
        if ($token instanceof CommentToken) {
            $this->_append_comment($token->data);
            return null;
        }
        if ($token instanceof Tag) {
            $name = $token->name;
            if ($token->kind === Tag::START) {
                if (in_array($name, ['caption', 'col', 'colgroup', 'tbody', 'tfoot', 'thead', 'tr', 'td', 'th'], true)) {
                    $this->_parse_error('unexpected-start-tag-implies-end-tag', $name);
                    if ($this->_close_caption_element()) {
                        return ['reprocess', InsertionMode::IN_TABLE, $token];
                    }
                    return null;
                }
                if ($name === 'table') {
                    $this->_parse_error('unexpected-start-tag-implies-end-tag', $name);
                    if ($this->_close_caption_element()) {
                        return ['reprocess', InsertionMode::IN_TABLE, $token];
                    }
                    return $this->_mode_in_body($token);
                }
                return $this->_mode_in_body($token);
            }
            if ($name === 'caption') {
                if (!$this->_close_caption_element()) {
                    return null;
                }
                return null;
            }
            if ($name === 'table') {
                if ($this->_close_caption_element()) {
                    return ['reprocess', InsertionMode::IN_TABLE, $token];
                }
                return null;
            }
            if (in_array($name, ['tbody', 'tfoot', 'thead'], true)) {
                $this->_parse_error('unexpected-end-tag', $name);
                return null;
            }
            return $this->_mode_in_body($token);
        }
        return $this->_mode_in_body($token);
    }

    private function _close_caption_element(): bool
    {
        if (!$this->_has_in_table_scope('caption')) {
            $this->_parse_error('unexpected-end-tag', 'caption');
            return false;
        }
        $this->_generate_implied_end_tags();
        while ($this->open_elements) {
            $node = array_pop($this->open_elements);
            if ($node->name === 'caption') {
                break;
            }
        }
        $this->_clear_active_formatting_up_to_marker();
        $this->mode = InsertionMode::IN_TABLE;
        return true;
    }

    private function _mode_in_column_group($token)
    {
        $current = $this->open_elements ? $this->open_elements[count($this->open_elements) - 1] : null;
        if ($token instanceof CharacterTokens) {
            $data = $token->data ?? '';
            $stripped = ltrim($data, " \t\n\r\f");

            if (strlen($stripped) < strlen($data)) {
                $ws = substr($data, 0, strlen($data) - strlen($stripped));
                $this->_append_text($ws);
            }

            $nonWsToken = new CharacterTokens($stripped);
            if ($current && $current->name === 'html') {
                $this->_parse_error('unexpected-characters-in-column-group');
                return null;
            }
            if ($current && $current->name === 'template') {
                $this->_parse_error('unexpected-characters-in-template-column-group');
                return null;
            }
            $this->_parse_error('unexpected-characters-in-column-group');
            $this->_pop_current();
            $this->mode = InsertionMode::IN_TABLE;
            return ['reprocess', InsertionMode::IN_TABLE, $nonWsToken];
        }
        if ($token instanceof CommentToken) {
            $this->_append_comment($token->data);
            return null;
        }
        if ($token instanceof Tag) {
            $name = $token->name;
            if ($token->kind === Tag::START) {
                if ($name === 'html') {
                    return $this->_mode_in_body($token);
                }
                if ($name === 'col') {
                    $this->_insert_element($token, true);
                    array_pop($this->open_elements);
                    return null;
                }
                if ($name === 'template') {
                    return $this->_mode_in_head($token);
                }
                if ($name === 'colgroup') {
                    $this->_parse_error('unexpected-start-tag-implies-end-tag', $name);
                    if ($current && $current->name === 'colgroup') {
                        $this->_pop_current();
                        $this->mode = InsertionMode::IN_TABLE;
                        return ['reprocess', InsertionMode::IN_TABLE, $token];
                    }
                    return null;
                }
                if (
                    $this->fragment_context
                    && $this->fragment_context->tagName
                    && strtolower($this->fragment_context->tagName) === 'colgroup'
                    && !$this->_has_in_table_scope('table')
                ) {
                    $this->_parse_error('unexpected-start-tag-in-column-group', $name);
                    return null;
                }
                if ($current && $current->name === 'colgroup') {
                    $this->_pop_current();
                    $this->mode = InsertionMode::IN_TABLE;
                    return ['reprocess', InsertionMode::IN_TABLE, $token];
                }
                $this->_parse_error('unexpected-start-tag-in-template-column-group', $name);
                return null;
            }
            if ($name === 'colgroup') {
                if ($current && $current->name === 'colgroup') {
                    $this->_pop_current();
                    $this->mode = InsertionMode::IN_TABLE;
                } else {
                    $this->_parse_error('unexpected-end-tag', $token->name);
                }
                return null;
            }
            if ($name === 'col') {
                $this->_parse_error('unexpected-end-tag', $name);
                return null;
            }
            if ($name === 'template') {
                return $this->_mode_in_head($token);
            }
            if ($current && $current->name !== 'html') {
                $this->_pop_current();
                $this->mode = InsertionMode::IN_TABLE;
            }
            return ['reprocess', InsertionMode::IN_TABLE, $token];
        }

        if ($current && $current->name === 'colgroup') {
            $this->_pop_current();
            $this->mode = InsertionMode::IN_TABLE;
            return ['reprocess', InsertionMode::IN_TABLE, $token];
        }
        if ($current && $current->name === 'template') {
            return $this->_mode_in_template($token);
        }
        return null;
    }

    private function _mode_in_table_body($token)
    {
        if ($token instanceof CharacterTokens || $token instanceof CommentToken) {
            return $this->_mode_in_table($token);
        }
        if ($token instanceof Tag) {
            $name = $token->name;
            if ($token->kind === Tag::START) {
                if ($name === 'tr') {
                    $this->_clear_stack_until(['tbody' => true, 'tfoot' => true, 'thead' => true, 'template' => true, 'html' => true]);
                    $this->_insert_element($token, true);
                    $this->mode = InsertionMode::IN_ROW;
                    return null;
                }
                if (in_array($name, ['td', 'th'], true)) {
                    $this->_parse_error('unexpected-cell-in-table-body');
                    $this->_clear_stack_until(['tbody' => true, 'tfoot' => true, 'thead' => true, 'template' => true, 'html' => true]);
                    $implied = new Tag(Tag::START, 'tr', [], false);
                    $this->_insert_element($implied, true);
                    $this->mode = InsertionMode::IN_ROW;
                    return ['reprocess', InsertionMode::IN_ROW, $token];
                }
                if (in_array($name, ['caption', 'col', 'colgroup', 'tbody', 'tfoot', 'thead', 'table'], true)) {
                    $current = $this->open_elements ? $this->open_elements[count($this->open_elements) - 1] : null;
                    if ($current && $current->name === 'template') {
                        $this->_parse_error('unexpected-start-tag-in-template-table-context', $name);
                        return null;
                    }
                    if (
                        $this->fragment_context
                        && $current
                        && $current->name === 'html'
                        && $this->fragment_context->tagName
                        && in_array(strtolower($this->fragment_context->tagName), ['tbody', 'tfoot', 'thead'], true)
                    ) {
                        $this->_parse_error('unexpected-start-tag');
                        return null;
                    }
                    if ($this->open_elements) {
                        array_pop($this->open_elements);
                        $this->mode = InsertionMode::IN_TABLE;
                        return ['reprocess', InsertionMode::IN_TABLE, $token];
                    }
                    $this->mode = InsertionMode::IN_TABLE;
                    return null;
                }
                return $this->_mode_in_table($token);
            }
            if (in_array($name, ['tbody', 'tfoot', 'thead'], true)) {
                if (!$this->_has_in_table_scope($name)) {
                    $this->_parse_error('unexpected-end-tag', $name);
                    return null;
                }
                $this->_clear_stack_until(['tbody' => true, 'tfoot' => true, 'thead' => true, 'template' => true, 'html' => true]);
                $this->_pop_current();
                $this->mode = InsertionMode::IN_TABLE;
                return null;
            }
            if ($name === 'table') {
                $current = $this->open_elements ? $this->open_elements[count($this->open_elements) - 1] : null;
                if ($current && $current->name === 'template') {
                    $this->_parse_error('unexpected-end-tag', $token->name);
                    return null;
                }
                if (
                    $this->fragment_context
                    && $current
                    && $current->name === 'html'
                    && $this->fragment_context->tagName
                    && in_array(strtolower($this->fragment_context->tagName), ['tbody', 'tfoot', 'thead'], true)
                ) {
                    $this->_parse_error('unexpected-end-tag', $token->name);
                    return null;
                }
                if ($current && in_array($current->name, ['tbody', 'tfoot', 'thead'], true)) {
                    array_pop($this->open_elements);
                }
                $this->mode = InsertionMode::IN_TABLE;
                return ['reprocess', InsertionMode::IN_TABLE, $token];
            }
            if (in_array($name, ['caption', 'col', 'colgroup', 'td', 'th', 'tr'], true)) {
                $this->_parse_error('unexpected-end-tag', $name);
                return null;
            }
            return $this->_mode_in_table($token);
        }
        return $this->_mode_in_table($token);
    }

    private function _mode_in_row($token)
    {
        if ($token instanceof CharacterTokens || $token instanceof CommentToken) {
            return $this->_mode_in_table($token);
        }
        if ($token instanceof Tag) {
            $name = $token->name;
            if ($token->kind === Tag::START) {
                if (in_array($name, ['td', 'th'], true)) {
                    $this->_clear_stack_until(['tr' => true, 'template' => true, 'html' => true]);
                    $this->_insert_element($token, true);
                    $this->_push_formatting_marker();
                    $this->mode = InsertionMode::IN_CELL;
                    return null;
                }
                if (in_array($name, ['caption', 'col', 'colgroup', 'tbody', 'tfoot', 'thead', 'tr', 'table'], true)) {
                    if (!$this->_has_in_table_scope('tr')) {
                        $this->_parse_error('unexpected-start-tag-implies-end-tag', $name);
                        return null;
                    }
                    $this->_end_tr_element();
                    return ['reprocess', $this->mode, $token];
                }
                $previous = $this->insert_from_table;
                $this->insert_from_table = true;
                try {
                    return $this->_mode_in_body($token);
                } finally {
                    $this->insert_from_table = $previous;
                }
            }
            if ($name === 'tr') {
                if (!$this->_has_in_table_scope('tr')) {
                    $this->_parse_error('unexpected-end-tag', $name);
                    return null;
                }
                $this->_end_tr_element();
                return null;
            }
            if (in_array($name, ['table', 'tbody', 'tfoot', 'thead'], true)) {
                if ($this->_has_in_table_scope($name)) {
                    $this->_end_tr_element();
                    return ['reprocess', $this->mode, $token];
                }
                $this->_parse_error('unexpected-end-tag', $name);
                return null;
            }
            if (in_array($name, ['caption', 'col', 'group', 'td', 'th'], true)) {
                $this->_parse_error('unexpected-end-tag', $name);
                return null;
            }
            $previous = $this->insert_from_table;
            $this->insert_from_table = true;
            try {
                return $this->_mode_in_body($token);
            } finally {
                $this->insert_from_table = $previous;
            }
        }
        return $this->_mode_in_table($token);
    }

    private function _end_tr_element(): void
    {
        $this->_clear_stack_until(['tr' => true, 'template' => true, 'html' => true]);
        if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name === 'tr') {
            array_pop($this->open_elements);
        }
        if ($this->template_modes) {
            $this->mode = $this->template_modes[count($this->template_modes) - 1];
        } else {
            $this->mode = InsertionMode::IN_TABLE_BODY;
        }
    }

    private function _mode_in_cell($token)
    {
        if ($token instanceof CharacterTokens) {
            $previous = $this->insert_from_table;
            $this->insert_from_table = false;
            try {
                return $this->_mode_in_body($token);
            } finally {
                $this->insert_from_table = $previous;
            }
        }
        if ($token instanceof CommentToken) {
            $this->_append_comment($token->data);
            return null;
        }
        if ($token instanceof Tag) {
            $name = $token->name;
            if ($token->kind === Tag::START) {
                if (in_array($name, ['caption', 'col', 'colgroup', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr'], true)) {
                    if ($this->_close_table_cell()) {
                        return ['reprocess', $this->mode, $token];
                    }
                    $this->_parse_error('unexpected-start-tag-in-cell-fragment', $name);
                    return null;
                }
                $previous = $this->insert_from_table;
                $this->insert_from_table = false;
                try {
                    return $this->_mode_in_body($token);
                } finally {
                    $this->insert_from_table = $previous;
                }
            }
            if (in_array($name, ['td', 'th'], true)) {
                if (!$this->_has_in_table_scope($name)) {
                    $this->_parse_error('unexpected-end-tag', $name);
                    return null;
                }
                $this->_end_table_cell($name);
                return null;
            }
            if (in_array($name, ['table', 'tbody', 'tfoot', 'thead', 'tr'], true)) {
                if (!$this->_has_in_table_scope($name)) {
                    $this->_parse_error('unexpected-end-tag', $name);
                    return null;
                }
                $this->_close_table_cell();
                return ['reprocess', $this->mode, $token];
            }
            $previous = $this->insert_from_table;
            $this->insert_from_table = false;
            try {
                return $this->_mode_in_body($token);
            } finally {
                $this->insert_from_table = $previous;
            }
        }
        if ($this->_close_table_cell()) {
            return ['reprocess', $this->mode, $token];
        }
        return $this->_mode_in_table($token);
    }

    private function _mode_in_select($token)
    {
        if ($token instanceof CharacterTokens) {
            $data = $token->data ?? '';
            if (strpos($data, "\x00") !== false) {
                $this->_parse_error('invalid-codepoint-in-select');
                $data = str_replace("\x00", '', $data);
            }
            if (strpos($data, "\x0c") !== false) {
                $this->_parse_error('invalid-codepoint-in-select');
                $data = str_replace("\x0c", '', $data);
            }
            if ($data !== '') {
                $this->_reconstruct_active_formatting_elements();
                $this->_append_text($data);
            }
            return null;
        }
        if ($token instanceof CommentToken) {
            $this->_append_comment($token->data);
            return null;
        }
        if ($token instanceof Tag) {
            $name = $token->name;
            if ($token->kind === Tag::START) {
                if ($name === 'html') {
                    return ['reprocess', InsertionMode::IN_BODY, $token];
                }
                if ($name === 'option') {
                    if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name === 'option') {
                        array_pop($this->open_elements);
                    }
                    $this->_reconstruct_active_formatting_elements();
                    $this->_insert_element($token, true);
                    return null;
                }
                if ($name === 'optgroup') {
                    if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name === 'option') {
                        array_pop($this->open_elements);
                    }
                    if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name === 'optgroup') {
                        array_pop($this->open_elements);
                    }
                    $this->_reconstruct_active_formatting_elements();
                    $this->_insert_element($token, true);
                    return null;
                }
                if ($name === 'select') {
                    $this->_parse_error('unexpected-start-tag-implies-end-tag', $name);
                    $this->_pop_until_any_inclusive(['select' => true]);
                    $this->_reset_insertion_mode();
                    return null;
                }
                if (in_array($name, ['input', 'textarea'], true)) {
                    $this->_parse_error('unexpected-start-tag-implies-end-tag', $name);
                    $this->_pop_until_any_inclusive(['select' => true]);
                    $this->_reset_insertion_mode();
                    return ['reprocess', $this->mode, $token];
                }
                if ($name === 'keygen') {
                    $this->_reconstruct_active_formatting_elements();
                    $this->_insert_element($token, false);
                    return null;
                }
                if (in_array($name, ['caption', 'col', 'colgroup', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'table'], true)) {
                    $this->_parse_error('unexpected-start-tag-implies-end-tag', $name);
                    $this->_pop_until_any_inclusive(['select' => true]);
                    $this->_reset_insertion_mode();
                    return ['reprocess', $this->mode, $token];
                }
                if (in_array($name, ['script', 'template'], true)) {
                    return $this->_mode_in_head($token);
                }
                if (in_array($name, ['svg', 'math'], true)) {
                    $this->_reconstruct_active_formatting_elements();
                    $this->_insert_element($token, !$token->selfClosing, $name);
                    return null;
                }
                if (isset(Constants::FORMATTING_ELEMENTS[$name])) {
                    $this->_reconstruct_active_formatting_elements();
                    $node = $this->_insert_element($token, true);
                    $this->_append_active_formatting_entry($name, $token->attrs, $node);
                    return null;
                }
                if ($name === 'hr') {
                    if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name === 'option') {
                        array_pop($this->open_elements);
                    }
                    if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name === 'optgroup') {
                        array_pop($this->open_elements);
                    }
                    $this->_reconstruct_active_formatting_elements();
                    $this->_insert_element($token, false);
                    return null;
                }
                if ($name === 'menuitem') {
                    $this->_reconstruct_active_formatting_elements();
                    $this->_insert_element($token, true);
                    return null;
                }

                if (in_array($name, ['p', 'div', 'span', 'button', 'datalist', 'selectedcontent'], true)) {
                    $this->_reconstruct_active_formatting_elements();
                    $this->_insert_element($token, !$token->selfClosing);
                    return null;
                }

                if (in_array($name, ['br', 'img'], true)) {
                    $this->_reconstruct_active_formatting_elements();
                    $this->_insert_element($token, false);
                    return null;
                }
                if ($name === 'plaintext') {
                    $this->_reconstruct_active_formatting_elements();
                    $this->_insert_element($token, true);
                }
                return null;
            }
            if ($name === 'optgroup') {
                if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name === 'option') {
                    array_pop($this->open_elements);
                }
                if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name === 'optgroup') {
                    array_pop($this->open_elements);
                } else {
                    $this->_parse_error('unexpected-end-tag', $token->name);
                }
                return null;
            }
            if ($name === 'option') {
                if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name === 'option') {
                    array_pop($this->open_elements);
                } else {
                    $this->_parse_error('unexpected-end-tag', $token->name);
                }
                return null;
            }
            if ($name === 'select') {
                $this->_pop_until_any_inclusive(['select' => true]);
                $this->_reset_insertion_mode();
                return null;
            }
            if ($name === 'a' || isset(Constants::FORMATTING_ELEMENTS[$name])) {
                $selectNode = $this->_find_last_on_stack('select');
                $fmtIndex = $this->_find_active_formatting_index($name);
                if ($fmtIndex !== null) {
                    $target = $this->active_formatting[$fmtIndex]['node'];
                    if (in_array($target, $this->open_elements, true)) {
                        $selectIndex = array_search($selectNode, $this->open_elements, true);
                        $targetIndex = array_search($target, $this->open_elements, true);
                        if ($targetIndex < $selectIndex) {
                            $this->_parse_error('unexpected-end-tag', $name);
                            return null;
                        }
                    }
                }
                $this->_adoption_agency($name);
                return null;
            }

            if (in_array($name, ['p', 'div', 'span', 'button', 'datalist', 'selectedcontent'], true)) {
                $select_idx = null;
                $target_idx = null;
                foreach ($this->open_elements as $i => $node) {
                    if ($node->name === 'select' && $select_idx === null) {
                        $select_idx = $i;
                    }
                    if ($node->name === $name) {
                        $target_idx = $i;
                    }
                }
                if ($target_idx !== null && ($select_idx === null || $target_idx > $select_idx)) {
                    while (true) {
                        $popped = array_pop($this->open_elements);
                        if ($popped->name === $name) {
                            break;
                        }
                    }
                } else {
                    $this->_parse_error('unexpected-end-tag', $name);
                }
                return null;
            }

            if (in_array($name, ['caption', 'col', 'colgroup', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'table'], true)) {
                $this->_parse_error('unexpected-end-tag', $name);
                $this->_pop_until_any_inclusive(['select' => true]);
                $this->_reset_insertion_mode();
                return ['reprocess', $this->mode, $token];
            }
            $this->_parse_error('unexpected-end-tag', $name);
            return null;
        }
        return $this->_mode_in_body($token);
    }

    private function _mode_in_template($token)
    {
        if ($token instanceof CharacterTokens) {
            return $this->_mode_in_body($token);
        }
        if ($token instanceof CommentToken) {
            return $this->_mode_in_body($token);
        }
        if ($token instanceof Tag) {
            if ($token->kind === Tag::START) {
                if (in_array($token->name, ['caption', 'colgroup', 'tbody', 'tfoot', 'thead'], true)) {
                    array_pop($this->template_modes);
                    $this->template_modes[] = InsertionMode::IN_TABLE;
                    $this->mode = InsertionMode::IN_TABLE;
                    return ['reprocess', InsertionMode::IN_TABLE, $token];
                }
                if ($token->name === 'col') {
                    array_pop($this->template_modes);
                    $this->template_modes[] = InsertionMode::IN_COLUMN_GROUP;
                    $this->mode = InsertionMode::IN_COLUMN_GROUP;
                    return ['reprocess', InsertionMode::IN_COLUMN_GROUP, $token];
                }
                if ($token->name === 'tr') {
                    array_pop($this->template_modes);
                    $this->template_modes[] = InsertionMode::IN_TABLE_BODY;
                    $this->mode = InsertionMode::IN_TABLE_BODY;
                    return ['reprocess', InsertionMode::IN_TABLE_BODY, $token];
                }
                if (in_array($token->name, ['td', 'th'], true)) {
                    array_pop($this->template_modes);
                    $this->template_modes[] = InsertionMode::IN_ROW;
                    $this->mode = InsertionMode::IN_ROW;
                    return ['reprocess', InsertionMode::IN_ROW, $token];
                }
                if (!in_array($token->name, ['base', 'basefont', 'bgsound', 'link', 'meta', 'noframes', 'script', 'style', 'template', 'title'], true)) {
                    array_pop($this->template_modes);
                    $this->template_modes[] = InsertionMode::IN_BODY;
                    $this->mode = InsertionMode::IN_BODY;
                    return ['reprocess', InsertionMode::IN_BODY, $token];
                }
            }
            if ($token->kind === Tag::END && $token->name === 'template') {
                return $this->_mode_in_head($token);
            }
            if (in_array($token->name, ['base', 'basefont', 'bgsound', 'link', 'meta', 'noframes', 'script', 'style', 'template', 'title'], true)) {
                return $this->_mode_in_head($token);
            }
        }
        if ($token instanceof EOFToken) {
            $hasTemplate = false;
            foreach ($this->open_elements as $node) {
                if ($node->name === 'template') {
                    $hasTemplate = true;
                    break;
                }
            }
            if (!$hasTemplate) {
                return null;
            }
            $this->_parse_error('expected-closing-tag-but-got-eof', 'template');
            $this->_pop_until_inclusive('template');
            $this->_clear_active_formatting_up_to_marker();
            array_pop($this->template_modes);
            $this->_reset_insertion_mode();
            return ['reprocess', $this->mode, $token];
        }
        return null;
    }

    private function _mode_after_body($token)
    {
        if ($token instanceof CharacterTokens) {
            if (TreeBuilderUtils::isAllWhitespace($token->data)) {
                $this->_mode_in_body($token);
                return null;
            }
            return ['reprocess', InsertionMode::IN_BODY, $token];
        }
        if ($token instanceof CommentToken) {
            $this->_append_comment($token->data, $this->open_elements[0]);
            return null;
        }
        if ($token instanceof Tag) {
            if ($token->kind === Tag::START && $token->name === 'html') {
                return ['reprocess', InsertionMode::IN_BODY, $token];
            }
            if ($token->kind === Tag::END && $token->name === 'html') {
                $this->mode = InsertionMode::AFTER_AFTER_BODY;
                return null;
            }
            return ['reprocess', InsertionMode::IN_BODY, $token];
        }
        return null;
    }

    private function _mode_after_after_body($token)
    {
        if ($token instanceof CharacterTokens) {
            if (TreeBuilderUtils::isAllWhitespace($token->data)) {
                $this->_mode_in_body($token);
                return null;
            }
            $this->_parse_error('unexpected-char-after-body');
            return ['reprocess', InsertionMode::IN_BODY, $token];
        }
        if ($token instanceof CommentToken) {
            if ($this->fragment_context !== null) {
                $htmlNode = $this->_find_last_on_stack('html');
                $htmlNode->appendChild(new SimpleDomNode('#comment', null, $token->data));
                return null;
            }
            $this->_append_comment_to_document($token->data);
            return null;
        }
        if ($token instanceof Tag) {
            if ($token->kind === Tag::START && $token->name === 'html') {
                return ['reprocess', InsertionMode::IN_BODY, $token];
            }
            $this->_parse_error('unexpected-token-after-body');
            return ['reprocess', InsertionMode::IN_BODY, $token];
        }
        return null;
    }

    private function _mode_in_frameset($token)
    {
        if ($token instanceof CharacterTokens) {
            $whitespace = '';
            $data = $token->data ?? '';
            $len = strlen($data);
            for ($i = 0; $i < $len; $i++) {
                $ch = $data[$i];
                if ($ch === "\t" || $ch === "\n" || $ch === "\f" || $ch === "\r" || $ch === ' ') {
                    $whitespace .= $ch;
                }
            }
            if ($whitespace !== '') {
                $this->_append_text($whitespace);
            }
            return null;
        }
        if ($token instanceof CommentToken) {
            $this->_append_comment($token->data);
            return null;
        }
        if ($token instanceof Tag) {
            if ($token->kind === Tag::START && $token->name === 'html') {
                return ['reprocess', InsertionMode::IN_BODY, $token];
            }
            if ($token->kind === Tag::START && $token->name === 'frameset') {
                $this->_insert_element($token, true);
                return null;
            }
            if ($token->kind === Tag::END && $token->name === 'frameset') {
                if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name === 'html') {
                    $this->_parse_error('unexpected-end-tag', $token->name);
                    return null;
                }
                array_pop($this->open_elements);
                if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name !== 'frameset') {
                    $this->mode = InsertionMode::AFTER_FRAMESET;
                }
                return null;
            }
            if ($token->kind === Tag::START && $token->name === 'frame') {
                $this->_insert_element($token, true);
                array_pop($this->open_elements);
                return null;
            }
            if ($token->kind === Tag::START && $token->name === 'noframes') {
                $this->_insert_element($token, true);
                $this->original_mode = $this->mode;
                $this->mode = InsertionMode::TEXT;
                return null;
            }
        }
        if ($token instanceof EOFToken) {
            if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name !== 'html') {
                $this->_parse_error('expected-closing-tag-but-got-eof', $this->open_elements[count($this->open_elements) - 1]->name);
            }
            return null;
        }
        $this->_parse_error('unexpected-token-in-frameset');
        return null;
    }

    private function _mode_after_frameset($token)
    {
        if ($token instanceof CharacterTokens) {
            $whitespace = '';
            $data = $token->data ?? '';
            $len = strlen($data);
            for ($i = 0; $i < $len; $i++) {
                $ch = $data[$i];
                if ($ch === "\t" || $ch === "\n" || $ch === "\f" || $ch === "\r" || $ch === ' ') {
                    $whitespace .= $ch;
                }
            }
            if ($whitespace !== '') {
                $this->_append_text($whitespace);
            }
            return null;
        }
        if ($token instanceof CommentToken) {
            $this->_append_comment($token->data);
            return null;
        }
        if ($token instanceof Tag) {
            if ($token->kind === Tag::START && $token->name === 'html') {
                return ['reprocess', InsertionMode::IN_BODY, $token];
            }
            if ($token->kind === Tag::END && $token->name === 'html') {
                $this->mode = InsertionMode::AFTER_AFTER_FRAMESET;
                return null;
            }
            if ($token->kind === Tag::START && $token->name === 'noframes') {
                $this->_insert_element($token, true);
                $this->original_mode = $this->mode;
                $this->mode = InsertionMode::TEXT;
                return null;
            }
        }
        if ($token instanceof EOFToken) {
            return null;
        }
        $this->_parse_error('unexpected-token-after-frameset');
        $this->mode = InsertionMode::IN_FRAMESET;
        return ['reprocess', InsertionMode::IN_FRAMESET, $token];
    }

    private function _mode_after_after_frameset($token)
    {
        if ($token instanceof CharacterTokens) {
            if (TreeBuilderUtils::isAllWhitespace($token->data)) {
                $this->_mode_in_body($token);
                return null;
            }
        }
        if ($token instanceof CommentToken) {
            $this->_append_comment_to_document($token->data);
            return null;
        }
        if ($token instanceof Tag) {
            if ($token->kind === Tag::START && $token->name === 'html') {
                return ['reprocess', InsertionMode::IN_BODY, $token];
            }
            if ($token->kind === Tag::START && $token->name === 'noframes') {
                $this->_insert_element($token, true);
                $this->original_mode = $this->mode;
                $this->mode = InsertionMode::TEXT;
                return null;
            }
        }
        if ($token instanceof EOFToken) {
            return null;
        }
        $this->_parse_error('unexpected-token-after-after-frameset');
        $this->mode = InsertionMode::IN_FRAMESET;
        return ['reprocess', InsertionMode::IN_FRAMESET, $token];
    }

    private array $_MODE_HANDLERS = [
        InsertionMode::INITIAL => '_mode_initial',
        InsertionMode::BEFORE_HTML => '_mode_before_html',
        InsertionMode::BEFORE_HEAD => '_mode_before_head',
        InsertionMode::IN_HEAD => '_mode_in_head',
        InsertionMode::IN_HEAD_NOSCRIPT => '_mode_in_head_noscript',
        InsertionMode::AFTER_HEAD => '_mode_after_head',
        InsertionMode::TEXT => '_mode_text',
        InsertionMode::IN_BODY => '_mode_in_body',
        InsertionMode::AFTER_BODY => '_mode_after_body',
        InsertionMode::AFTER_AFTER_BODY => '_mode_after_after_body',
        InsertionMode::IN_TABLE => '_mode_in_table',
        InsertionMode::IN_TABLE_TEXT => '_mode_in_table_text',
        InsertionMode::IN_CAPTION => '_mode_in_caption',
        InsertionMode::IN_COLUMN_GROUP => '_mode_in_column_group',
        InsertionMode::IN_TABLE_BODY => '_mode_in_table_body',
        InsertionMode::IN_ROW => '_mode_in_row',
        InsertionMode::IN_CELL => '_mode_in_cell',
        InsertionMode::IN_FRAMESET => '_mode_in_frameset',
        InsertionMode::AFTER_FRAMESET => '_mode_after_frameset',
        InsertionMode::AFTER_AFTER_FRAMESET => '_mode_after_after_frameset',
        InsertionMode::IN_SELECT => '_mode_in_select',
        InsertionMode::IN_TEMPLATE => '_mode_in_template',
    ];

    private array $_BODY_TOKEN_HANDLERS = [
        CharacterTokens::class => '_handle_characters_in_body',
        CommentToken::class => '_handle_comment_in_body',
        Tag::class => '_handle_tag_in_body',
        EOFToken::class => '_handle_eof_in_body',
    ];

    private array $_BODY_START_HANDLERS = [
        'a' => '_handle_body_start_a',
        'address' => '_handle_body_start_block_with_p',
        'applet' => '_handle_body_start_applet_like',
        'area' => '_handle_body_start_void_with_formatting',
        'article' => '_handle_body_start_block_with_p',
        'aside' => '_handle_body_start_block_with_p',
        'b' => '_handle_body_start_formatting',
        'base' => '_handle_body_start_in_head',
        'basefont' => '_handle_body_start_in_head',
        'bgsound' => '_handle_body_start_in_head',
        'big' => '_handle_body_start_formatting',
        'blockquote' => '_handle_body_start_block_with_p',
        'body' => '_handle_body_start_body',
        'br' => '_handle_body_start_br',
        'button' => '_handle_body_start_button',
        'caption' => '_handle_body_start_table_parse_error',
        'center' => '_handle_body_start_block_with_p',
        'code' => '_handle_body_start_formatting',
        'col' => '_handle_body_start_col_or_frame',
        'colgroup' => '_handle_body_start_structure_ignored',
        'dd' => '_handle_body_start_dd_dt',
        'details' => '_handle_body_start_block_with_p',
        'dialog' => '_handle_body_start_block_with_p',
        'dir' => '_handle_body_start_block_with_p',
        'div' => '_handle_body_start_block_with_p',
        'dl' => '_handle_body_start_block_with_p',
        'dt' => '_handle_body_start_dd_dt',
        'em' => '_handle_body_start_formatting',
        'embed' => '_handle_body_start_void_with_formatting',
        'fieldset' => '_handle_body_start_block_with_p',
        'figcaption' => '_handle_body_start_block_with_p',
        'figure' => '_handle_body_start_block_with_p',
        'font' => '_handle_body_start_formatting',
        'footer' => '_handle_body_start_block_with_p',
        'form' => '_handle_body_start_form',
        'frame' => '_handle_body_start_col_or_frame',
        'frameset' => '_handle_body_start_frameset',
        'h1' => '_handle_body_start_heading',
        'h2' => '_handle_body_start_heading',
        'h3' => '_handle_body_start_heading',
        'h4' => '_handle_body_start_heading',
        'h5' => '_handle_body_start_heading',
        'h6' => '_handle_body_start_heading',
        'head' => '_handle_body_start_head',
        'header' => '_handle_body_start_block_with_p',
        'hgroup' => '_handle_body_start_block_with_p',
        'hr' => '_handle_body_start_hr',
        'html' => '_handle_body_start_html',
        'i' => '_handle_body_start_formatting',
        'image' => '_handle_body_start_image',
        'img' => '_handle_body_start_void_with_formatting',
        'input' => '_handle_body_start_input',
        'keygen' => '_handle_body_start_void_with_formatting',
        'li' => '_handle_body_start_li',
        'link' => '_handle_body_start_in_head',
        'listing' => '_handle_body_start_pre_listing',
        'main' => '_handle_body_start_block_with_p',
        'marquee' => '_handle_body_start_applet_like',
        'math' => '_handle_body_start_math',
        'menu' => '_handle_body_start_block_with_p',
        'meta' => '_handle_body_start_in_head',
        'nav' => '_handle_body_start_block_with_p',
        'nobr' => '_handle_body_start_formatting',
        'noframes' => '_handle_body_start_in_head',
        'object' => '_handle_body_start_applet_like',
        'ol' => '_handle_body_start_block_with_p',
        'optgroup' => '_handle_body_start_optgroup',
        'option' => '_handle_body_start_option',
        'p' => '_handle_body_start_paragraph',
        'param' => '_handle_body_start_simple_void',
        'plaintext' => '_handle_body_start_plaintext_xmp',
        'pre' => '_handle_body_start_pre_listing',
        'rb' => '_handle_body_start_rb_rtc',
        'rp' => '_handle_body_start_rp_rt',
        'rt' => '_handle_body_start_rp_rt',
        'rtc' => '_handle_body_start_rb_rtc',
        's' => '_handle_body_start_formatting',
        'script' => '_handle_body_start_in_head',
        'search' => '_handle_body_start_block_with_p',
        'section' => '_handle_body_start_block_with_p',
        'select' => '_handle_body_start_select',
        'small' => '_handle_body_start_formatting',
        'source' => '_handle_body_start_simple_void',
        'strike' => '_handle_body_start_formatting',
        'strong' => '_handle_body_start_formatting',
        'style' => '_handle_body_start_in_head',
        'summary' => '_handle_body_start_block_with_p',
        'svg' => '_handle_body_start_svg',
        'table' => '_handle_body_start_table',
        'tbody' => '_handle_body_start_structure_ignored',
        'td' => '_handle_body_start_structure_ignored',
        'template' => '_handle_body_start_in_head',
        'textarea' => '_handle_body_start_textarea',
        'tfoot' => '_handle_body_start_structure_ignored',
        'th' => '_handle_body_start_structure_ignored',
        'thead' => '_handle_body_start_structure_ignored',
        'title' => '_handle_body_start_in_head',
        'tr' => '_handle_body_start_structure_ignored',
        'track' => '_handle_body_start_simple_void',
        'tt' => '_handle_body_start_formatting',
        'u' => '_handle_body_start_formatting',
        'ul' => '_handle_body_start_block_with_p',
        'wbr' => '_handle_body_start_void_with_formatting',
        'xmp' => '_handle_body_start_plaintext_xmp',
    ];

    private array $_BODY_END_HANDLERS = [
        'address' => '_handle_body_end_block',
        'applet' => '_handle_body_end_applet_like',
        'article' => '_handle_body_end_block',
        'aside' => '_handle_body_end_block',
        'blockquote' => '_handle_body_end_block',
        'body' => '_handle_body_end_body',
        'button' => '_handle_body_end_block',
        'center' => '_handle_body_end_block',
        'dd' => '_handle_body_end_dd_dt',
        'details' => '_handle_body_end_block',
        'dialog' => '_handle_body_end_block',
        'dir' => '_handle_body_end_block',
        'div' => '_handle_body_end_block',
        'dl' => '_handle_body_end_block',
        'dt' => '_handle_body_end_dd_dt',
        'fieldset' => '_handle_body_end_block',
        'figcaption' => '_handle_body_end_block',
        'figure' => '_handle_body_end_block',
        'footer' => '_handle_body_end_block',
        'form' => '_handle_body_end_form',
        'h1' => '_handle_body_end_heading',
        'h2' => '_handle_body_end_heading',
        'h3' => '_handle_body_end_heading',
        'h4' => '_handle_body_end_heading',
        'h5' => '_handle_body_end_heading',
        'h6' => '_handle_body_end_heading',
        'header' => '_handle_body_end_block',
        'hgroup' => '_handle_body_end_block',
        'html' => '_handle_body_end_html',
        'li' => '_handle_body_end_li',
        'listing' => '_handle_body_end_block',
        'main' => '_handle_body_end_block',
        'marquee' => '_handle_body_end_applet_like',
        'menu' => '_handle_body_end_block',
        'nav' => '_handle_body_end_block',
        'object' => '_handle_body_end_applet_like',
        'ol' => '_handle_body_end_block',
        'p' => '_handle_body_end_p',
        'pre' => '_handle_body_end_block',
        'search' => '_handle_body_end_block',
        'section' => '_handle_body_end_block',
        'summary' => '_handle_body_end_block',
        'table' => '_handle_body_end_block',
        'template' => '_handle_body_end_template',
        'ul' => '_handle_body_end_block',
    ];
}
