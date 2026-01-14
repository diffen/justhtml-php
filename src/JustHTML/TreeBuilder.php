<?php

declare(strict_types=1);

namespace JustHTML;

class TreeBuilder
{
    use TreeBuilderModes;

    private const AFTER_HEAD_HEAD_TAGS = [
        'base' => true,
        'basefont' => true,
        'bgsound' => true,
        'link' => true,
        'meta' => true,
        'title' => true,
        'style' => true,
        'script' => true,
        'noscript' => true,
    ];
    private const AFTER_HEAD_END_TAGS = [
        'html' => true,
        'br' => true,
    ];
    private const TABLE_TEXT_START_TAGS = [
        'style' => true,
        'script' => true,
    ];

    public bool $collect_errors;
    /** @var array<int, ParseError> */
    public array $errors;
    public ?Tokenizer $tokenizer = null;
    public ?FragmentContext $fragment_context;
    public ?SimpleDomNode $fragment_context_element;
    public bool $iframe_srcdoc;
    public SimpleDomNode $document;
    public int $mode;
    public ?int $original_mode;
    public ?int $table_text_original_mode;
    /** @var array<int, SimpleDomNode> */
    public array $open_elements;
    /** @var array<int, SimpleDomNode> */
    public array $openElements;
    public ?SimpleDomNode $head_element;
    public ?SimpleDomNode $form_element;
    public bool $frameset_ok;
    public string $quirks_mode;
    public bool $ignore_lf;
    /** @var array<int, mixed> */
    public array $active_formatting;
    public bool $insert_from_table;
    public string $pending_table_text;
    /** @var array<int, int> */
    public array $template_modes;
    public ?int $tokenizer_state_override;

    public function __construct(?FragmentContext $fragment_context = null, bool $iframe_srcdoc = false, bool $collect_errors = false)
    {
        $this->fragment_context = $fragment_context;
        $this->iframe_srcdoc = $iframe_srcdoc;
        $this->collect_errors = $collect_errors;
        $this->errors = [];
        $this->tokenizer = null;
        $this->fragment_context_element = null;
        $this->document = $fragment_context ? new SimpleDomNode('#document-fragment') : new SimpleDomNode('#document');
        $this->mode = InsertionMode::INITIAL;
        $this->original_mode = null;
        $this->table_text_original_mode = null;
        $this->open_elements = [];
        $this->openElements = &$this->open_elements;
        $this->head_element = null;
        $this->form_element = null;
        $this->frameset_ok = true;
        $this->quirks_mode = 'no-quirks';
        $this->ignore_lf = false;
        $this->active_formatting = [];
        $this->insert_from_table = false;
        $this->pending_table_text = '';
        $this->template_modes = [];
        $this->tokenizer_state_override = null;

        if ($fragment_context !== null) {
            $root = $this->_create_element('html', null, []);
            $this->document->appendChild($root);
            $this->open_elements[] = $root;

            $namespace = $fragment_context->namespace;
            $context_name = $fragment_context->tagName ?? '';
            $name = strtolower($context_name);

            if ($namespace && $namespace !== 'html') {
                $adjusted = $context_name;
                if ($namespace === 'svg') {
                    $adjusted = $this->_adjust_svg_tag_name($context_name);
                }
                $context_element = $this->_create_element($adjusted, $namespace, []);
                $root->appendChild($context_element);
                $this->open_elements[] = $context_element;
                $this->fragment_context_element = $context_element;
            }

            if ($name === 'html') {
                $this->mode = InsertionMode::BEFORE_HEAD;
            } elseif (($namespace === null || $namespace === 'html') && in_array($name, ['tbody', 'thead', 'tfoot'], true)) {
                $this->mode = InsertionMode::IN_TABLE_BODY;
            } elseif (($namespace === null || $namespace === 'html') && $name === 'tr') {
                $this->mode = InsertionMode::IN_ROW;
            } elseif (($namespace === null || $namespace === 'html') && in_array($name, ['td', 'th'], true)) {
                $this->mode = InsertionMode::IN_CELL;
            } elseif (($namespace === null || $namespace === 'html') && $name === 'caption') {
                $this->mode = InsertionMode::IN_CAPTION;
            } elseif (($namespace === null || $namespace === 'html') && $name === 'colgroup') {
                $this->mode = InsertionMode::IN_COLUMN_GROUP;
            } elseif (($namespace === null || $namespace === 'html') && $name === 'table') {
                $this->mode = InsertionMode::IN_TABLE;
            } else {
                $this->mode = InsertionMode::IN_BODY;
            }
            $this->frameset_ok = false;
        }
    }

    private function _set_quirks_mode(string $mode): void
    {
        $this->quirks_mode = $mode;
    }

    private function _parse_error(string $code, ?string $tag_name = null, $token = null): void
    {
        if (!$this->collect_errors) {
            return;
        }
        $line = null;
        $column = null;
        $end_column = null;
        if ($this->tokenizer !== null) {
            $line = $this->tokenizer->lastTokenLine;
            $column = $this->tokenizer->lastTokenColumn;

            if ($token instanceof Tag) {
                $tag_len = strlen($token->name) + 2;
                if ($token->kind === Tag::END) {
                    $tag_len += 1;
                }
                foreach ($token->attrs as $attr_name => $attr_value) {
                    $attr_name_str = (string)$attr_name;
                    $tag_len += 1 + strlen($attr_name_str);
                    if ($attr_value !== null && $attr_value !== '') {
                        $tag_len += 1 + 2 + strlen((string)$attr_value);
                    }
                }
                if ($token->selfClosing) {
                    $tag_len += 1;
                }
                $start_column = $column - $tag_len + 1;
                $column = $start_column;
                $end_column = $column + $tag_len;
            }
        }
        $message = Errors::generateErrorMessage($code, $tag_name);
        $source_html = $this->tokenizer ? $this->tokenizer->buffer : null;
        $this->errors[] = new ParseError($code, $line, $column, $message, $source_html, $end_column);
    }

    private function _has_element_in_scope(string $target, ?array $terminators = null, bool $check_integration_points = true): bool
    {
        $terminators = $terminators ?? Constants::DEFAULT_SCOPE_TERMINATORS;
        for ($i = count($this->open_elements) - 1; $i >= 0; $i--) {
            $node = $this->open_elements[$i];
            if ($node->name === $target) {
                return true;
            }
            $ns = $node->namespace;
            if ($ns === null || $ns === 'html') {
                if (isset($terminators[$node->name])) {
                    return false;
                }
            } elseif ($check_integration_points && ($this->_is_html_integration_point($node) || $this->_is_mathml_text_integration_point($node))) {
                return false;
            }
        }
        return false;
    }

    private function _has_element_in_button_scope(string $target): bool
    {
        return $this->_has_element_in_scope($target, Constants::BUTTON_SCOPE_TERMINATORS);
    }

    private function _pop_until_inclusive(string $name): void
    {
        while ($this->open_elements) {
            $node = array_pop($this->open_elements);
            if ($node->name === $name) {
                break;
            }
        }
    }

    private function _pop_until_any_inclusive(array $names): void
    {
        while ($this->open_elements) {
            $node = array_pop($this->open_elements);
            if (isset($names[$node->name])) {
                return;
            }
        }
    }

    private function _close_p_element(): bool
    {
        if ($this->_has_element_in_button_scope('p')) {
            $this->_generate_implied_end_tags('p');
            if ($this->open_elements && $this->open_elements[count($this->open_elements) - 1]->name !== 'p') {
                $this->_parse_error('end-tag-too-early', 'p');
            }
            $this->_pop_until_inclusive('p');
            return true;
        }
        return false;
    }

    public function processToken($token): int
    {
        if ($token instanceof DoctypeToken) {
            if ($this->open_elements) {
                $current = $this->open_elements[count($this->open_elements) - 1];
                if ($current->namespace !== null && $current->namespace !== 'html') {
                    $this->_parse_error('unexpected-doctype');
                    return TokenSinkResult::Continue;
                }
            }
            return $this->_handle_doctype($token);
        }

        $current_token = $token;
        $force_html_mode = false;
        $mode_handlers = $this->_MODE_HANDLERS;

        while (true) {
            $current_node = $this->open_elements ? $this->open_elements[count($this->open_elements) - 1] : null;
            $is_html_namespace = $current_node === null || $current_node->namespace === null || $current_node->namespace === 'html';

            if ($force_html_mode || $is_html_namespace) {
                $force_html_mode = false;
                $handler = $mode_handlers[$this->mode] ?? null;
                $result = $handler ? $this->{$handler}($current_token) : null;
            } elseif ($this->_should_use_foreign_content($current_token)) {
                $result = $this->_process_foreign_content($current_token);
            } else {
                $current = $current_node;
                if ($current_token instanceof CharacterTokens) {
                    if ($this->_is_mathml_text_integration_point($current)) {
                        $data = $current_token->data;
                        if (Str::contains($data, "\x00")) {
                            $this->_parse_error('invalid-codepoint');
                            $data = str_replace("\x00", '', $data);
                        }
                        if (Str::contains($data, "\x0c")) {
                            $this->_parse_error('invalid-codepoint');
                            $data = str_replace("\x0c", '', $data);
                        }
                        if ($data !== '') {
                            if (!TreeBuilderUtils::isAllWhitespace($data)) {
                                $this->_reconstruct_active_formatting_elements();
                                $this->frameset_ok = false;
                            }
                            $this->_append_text($data);
                        }
                        $result = null;
                    } else {
                        $handler = $mode_handlers[$this->mode] ?? null;
                        $result = $handler ? $this->{$handler}($current_token) : null;
                    }
                } else {
                    if (
                        ($this->_is_mathml_text_integration_point($current) || $this->_is_html_integration_point($current))
                        && $current_token instanceof Tag
                        && $current_token->kind === Tag::START
                        && $this->mode !== InsertionMode::IN_BODY
                    ) {
                        $is_table_mode = in_array($this->mode, [
                            InsertionMode::IN_TABLE,
                            InsertionMode::IN_TABLE_BODY,
                            InsertionMode::IN_ROW,
                            InsertionMode::IN_CELL,
                            InsertionMode::IN_CAPTION,
                            InsertionMode::IN_COLUMN_GROUP,
                        ], true);
                        $has_table_in_scope = $this->_has_in_table_scope('table');
                        if ($is_table_mode && !$has_table_in_scope) {
                            $saved_mode = $this->mode;
                            $this->mode = InsertionMode::IN_BODY;
                            $handler = $mode_handlers[$this->mode] ?? null;
                            $result = $handler ? $this->{$handler}($current_token) : null;
                            if ($this->mode === InsertionMode::IN_BODY) {
                                $this->mode = $saved_mode;
                            }
                        } else {
                            $handler = $mode_handlers[$this->mode] ?? null;
                            $result = $handler ? $this->{$handler}($current_token) : null;
                        }
                    } else {
                        $handler = $mode_handlers[$this->mode] ?? null;
                        $result = $handler ? $this->{$handler}($current_token) : null;
                    }
                }
            }

            if ($result === null) {
                $result_to_return = $this->tokenizer_state_override ?? TokenSinkResult::Continue;
                $this->tokenizer_state_override = null;
                return $result_to_return;
            }

            $this->mode = $result[1];
            $current_token = $result[2];
            if (isset($result[3])) {
                $force_html_mode = (bool)$result[3];
            }
        }
    }

    public function finish(): SimpleDomNode
    {
        if ($this->fragment_context !== null) {
            $root = $this->document->children[0] ?? null;
            if ($root !== null) {
                $context_elem = $this->fragment_context_element;
                if ($context_elem !== null && $context_elem->parent === $root) {
                    foreach (array_values($context_elem->children) as $child) {
                        $context_elem->removeChild($child);
                        $root->appendChild($child);
                    }
                    $root->removeChild($context_elem);
                }
                foreach (array_values($root->children) as $child) {
                    $root->removeChild($child);
                    $this->document->appendChild($child);
                }
                $this->document->removeChild($root);
            }
        }

        $this->_populate_selectedcontent($this->document);

        return $this->document;
    }

    private function _append_comment_to_document(string $text): void
    {
        $node = new SimpleDomNode('#comment', null, $text);
        $this->document->appendChild($node);
    }

    private function _append_comment(string $text, $parent = null): void
    {
        if ($parent === null) {
            $parent = $this->_current_node_or_html();
        }
        if ($parent instanceof TemplateNode && $parent->templateContent) {
            $parent = $parent->templateContent;
        }
        if ($parent === null) {
            return;
        }
        $node = new SimpleDomNode('#comment', null, $text);
        $parent->appendChild($node);
    }

    private function _append_text(string $text): void
    {
        if ($this->ignore_lf) {
            $this->ignore_lf = false;
            if ($text !== '' && $text[0] === "\n") {
                $text = substr($text, 1);
                if ($text === '') {
                    return;
                }
            }
        }

        if (!$this->open_elements) {
            return;
        }

        $target = $this->open_elements[count($this->open_elements) - 1];

        if (!isset(Constants::TABLE_FOSTER_TARGETS[$target->name]) && !($target instanceof TemplateNode)) {
            $children = $target->children;
            if ($children) {
                $last_child = $children[count($children) - 1];
                if ($last_child instanceof TextNode) {
                    $last_child->data = ($last_child->data ?? '') . $text;
                    return;
                }
            }
            $node = new TextNode($text);
            $target->children[] = $node;
            $node->parent = $target;
            return;
        }

        $target = $this->_current_node_or_html();
        if ($target === null) {
            return;
        }
        $foster_parenting = $this->_should_foster_parenting($target, null, true);

        if ($foster_parenting) {
            $this->_reconstruct_active_formatting_elements();
        }

        [$parent, $position] = $this->_appropriate_insertion_location(null, $foster_parenting);

        if ($position > 0 && $parent->children[$position - 1]->name === '#text') {
            $parent->children[$position - 1]->data = ($parent->children[$position - 1]->data ?? '') . $text;
            return;
        }

        $node = new TextNode($text);
        $reference = $position < count($parent->children) ? $parent->children[$position] : null;
        $parent->insertBefore($node, $reference);
    }

    private function _current_node_or_html()
    {
        if ($this->open_elements) {
            return $this->open_elements[count($this->open_elements) - 1];
        }
        $children = $this->document->children;
        if ($children !== null) {
            foreach ($children as $child) {
                if ($child->name === 'html') {
                    return $child;
                }
            }
            return $children ? $children[0] : null;
        }
        return null;
    }

    private function _create_root(array $attrs)
    {
        $node = new SimpleDomNode('html', $attrs, null, 'html');
        $this->document->appendChild($node);
        $this->open_elements[] = $node;
        return $node;
    }

    private function _insert_element(Tag $tag, bool $push, string $namespace = 'html')
    {
        if ($tag->name === 'template' && $namespace === 'html') {
            $node = new TemplateNode($tag->name, $tag->attrs, $namespace);
        } else {
            $node = new ElementNode($tag->name, $tag->attrs, $namespace);
        }

        if (!$this->insert_from_table) {
            $target = $this->_current_node_or_html();
            if ($target instanceof TemplateNode && $target->templateContent) {
                $parent = $target->templateContent;
            } else {
                $parent = $target;
            }
            if ($parent !== null) {
                $parent->appendChild($node);
            }
            if ($push) {
                $this->open_elements[] = $node;
            }
            return $node;
        }

        $target = $this->_current_node_or_html();
        if ($target === null) {
            return $node;
        }
        $foster_parenting = $this->_should_foster_parenting($target, $tag->name, false);
        [$parent, $position] = $this->_appropriate_insertion_location(null, $foster_parenting);
        $this->_insert_node_at($parent, $position, $node);
        if ($push) {
            $this->open_elements[] = $node;
        }
        return $node;
    }

    private function _insert_phantom(string $name)
    {
        $tag = new Tag(Tag::START, $name, [], false);
        return $this->_insert_element($tag, true);
    }

    private function _insert_body_if_missing(): void
    {
        $html_node = $this->_find_last_on_stack('html');
        $node = new SimpleDomNode('body', null, null, 'html');
        if ($html_node !== null) {
            $html_node->appendChild($node);
            $node->parent = $html_node;
        }
        $this->open_elements[] = $node;
    }

    private function _create_element(string $name, ?string $namespace, array $attrs)
    {
        $ns = $namespace ?? 'html';
        return new ElementNode($name, $attrs, $ns);
    }

    private function _pop_current()
    {
        return array_pop($this->open_elements);
    }

    private function _in_scope(string $name): bool
    {
        return $this->_has_element_in_scope($name, Constants::DEFAULT_SCOPE_TERMINATORS);
    }

    private function _close_element_by_name(string $name): void
    {
        $index = count($this->open_elements) - 1;
        while ($index >= 0) {
            if ($this->open_elements[$index]->name === $name) {
                if ($index === count($this->open_elements) - 1) {
                    array_pop($this->open_elements);
                } else {
                    array_splice($this->open_elements, $index);
                }
                return;
            }
            $index -= 1;
        }
    }

    private function _any_other_end_tag(string $name): void
    {
        $index = count($this->open_elements) - 1;
        while ($index >= 0) {
            $node = $this->open_elements[$index];
            if ($node->name === $name) {
                if ($index !== count($this->open_elements) - 1) {
                    $this->_parse_error('end-tag-too-early');
                }
                if ($index === count($this->open_elements) - 1) {
                    array_pop($this->open_elements);
                } else {
                    array_splice($this->open_elements, $index);
                }
                return;
            }
            if ($this->_is_special_element($node)) {
                $this->_parse_error('unexpected-end-tag', $name);
                return;
            }
            $index -= 1;
        }
    }

    private function _add_missing_attributes($node, array $attrs): void
    {
        if (!$attrs) {
            return;
        }
        $existing = $node->attrs ?? [];
        foreach ($attrs as $name => $value) {
            if (!array_key_exists($name, $existing)) {
                $existing[$name] = $value;
            }
        }
        $node->attrs = $existing;
    }

    private function _remove_from_open_elements($node): bool
    {
        foreach ($this->open_elements as $index => $current) {
            if ($current === $node) {
                if ($index === count($this->open_elements) - 1) {
                    array_pop($this->open_elements);
                } else {
                    array_splice($this->open_elements, (int)$index, 1);
                }
                return true;
            }
        }
        return false;
    }

    private function _is_special_element($node): bool
    {
        if ($node->namespace !== null && $node->namespace !== 'html') {
            return false;
        }
        return isset(Constants::SPECIAL_ELEMENTS[$node->name]);
    }

    private function _find_active_formatting_index(string $name): ?int
    {
        $marker = Constants::formatMarker();
        for ($i = count($this->active_formatting) - 1; $i >= 0; $i--) {
            $entry = $this->active_formatting[$i];
            if ($entry === $marker) {
                break;
            }
            if ($entry['name'] === $name) {
                return $i;
            }
        }
        return null;
    }

    private function _find_active_formatting_index_by_node($node): ?int
    {
        $marker = Constants::formatMarker();
        for ($i = count($this->active_formatting) - 1; $i >= 0; $i--) {
            $entry = $this->active_formatting[$i];
            if ($entry !== $marker && $entry['node'] === $node) {
                return $i;
            }
        }
        return null;
    }

    private function _clone_attributes(array $attrs): array
    {
        return $attrs ? $attrs : [];
    }

    private function _attrs_signature(array $attrs): array
    {
        if (!$attrs) {
            return [];
        }
        $items = [];
        foreach ($attrs as $name => $value) {
            $items[] = [$name, $value ?? ''];
        }
        usort($items, static function ($a, $b) {
            if ($a[0] === $b[0]) {
                return $a[1] <=> $b[1];
            }
            return $a[0] <=> $b[0];
        });
        return $items;
    }

    private function _find_active_formatting_duplicate(string $name, array $attrs): ?int
    {
        $signature = $this->_attrs_signature($attrs);
        $matches = [];
        foreach ($this->active_formatting as $index => $entry) {
            if ($entry === Constants::formatMarker()) {
                $matches = [];
                continue;
            }
            if ($entry['name'] === $name && $entry['signature'] == $signature) {
                $matches[] = $index;
            }
        }
        if (count($matches) >= 3) {
            return $matches[0];
        }
        return null;
    }

    private function _has_active_formatting_entry(string $name): bool
    {
        $marker = Constants::formatMarker();
        for ($i = count($this->active_formatting) - 1; $i >= 0; $i--) {
            $entry = $this->active_formatting[$i];
            if ($entry === $marker) {
                break;
            }
            if ($entry['name'] === $name) {
                return true;
            }
        }
        return false;
    }

    private function _remove_last_active_formatting_by_name(string $name): void
    {
        $marker = Constants::formatMarker();
        for ($i = count($this->active_formatting) - 1; $i >= 0; $i--) {
            $entry = $this->active_formatting[$i];
            if ($entry === $marker) {
                break;
            }
            if ($entry['name'] === $name) {
                if ($i === count($this->active_formatting) - 1) {
                    array_pop($this->active_formatting);
                } else {
                    array_splice($this->active_formatting, $i, 1);
                }
                return;
            }
        }
    }

    private function _remove_last_open_element_by_name(string $name): void
    {
        for ($i = count($this->open_elements) - 1; $i >= 0; $i--) {
            if ($this->open_elements[$i]->name === $name) {
                if ($i === count($this->open_elements) - 1) {
                    array_pop($this->open_elements);
                } else {
                    array_splice($this->open_elements, $i, 1);
                }
                return;
            }
        }
    }

    private function _append_active_formatting_entry(string $name, array $attrs, $node): void
    {
        $entry_attrs = $this->_clone_attributes($attrs);
        $signature = $this->_attrs_signature($entry_attrs);
        $this->active_formatting[] = [
            'name' => $name,
            'attrs' => $entry_attrs,
            'node' => $node,
            'signature' => $signature,
        ];
    }

    private function _clear_active_formatting_up_to_marker(): void
    {
        $marker = Constants::formatMarker();
        while ($this->active_formatting) {
            $entry = array_pop($this->active_formatting);
            if ($entry === $marker) {
                break;
            }
        }
    }

    private function _push_formatting_marker(): void
    {
        $this->active_formatting[] = Constants::formatMarker();
    }

    private function _remove_formatting_entry(int $index): void
    {
        if ($index === count($this->active_formatting) - 1) {
            array_pop($this->active_formatting);
        } else {
            array_splice($this->active_formatting, $index, 1);
        }
    }

    private function _reconstruct_active_formatting_elements(): void
    {
        if (!$this->active_formatting) {
            return;
        }
        $marker = Constants::formatMarker();
        $openSet = [];
        if ($this->open_elements) {
            foreach ($this->open_elements as $node) {
                $openSet[spl_object_id($node)] = true;
            }
        }
        $last_entry = $this->active_formatting[count($this->active_formatting) - 1];
        if ($last_entry === $marker || isset($openSet[spl_object_id($last_entry['node'])])) {
            return;
        }

        $index = count($this->active_formatting) - 1;
        while (true) {
            $index -= 1;
            if ($index < 0) {
                break;
            }
            $entry = $this->active_formatting[$index];
            if ($entry === $marker || isset($openSet[spl_object_id($entry['node'])])) {
                $index += 1;
                break;
            }
        }
        if ($index < 0) {
            $index = 0;
        }
        while ($index < count($this->active_formatting)) {
            $entry = $this->active_formatting[$index];
            $tag = new Tag(Tag::START, $entry['name'], $this->_clone_attributes($entry['attrs']), false);
            $new_node = $this->_insert_element($tag, true);
            $entry['node'] = $new_node;
            $this->active_formatting[$index] = $entry;
            $index += 1;
        }
    }

    private function _insert_node_at($parent, int $index, $node): void
    {
        $reference_node = null;
        if ($index < count($parent->children)) {
            $reference_node = $parent->children[$index];
        }
        $parent->insertBefore($node, $reference_node);
    }

    private function _find_last_on_stack(string $name)
    {
        for ($i = count($this->open_elements) - 1; $i >= 0; $i--) {
            if ($this->open_elements[$i]->name === $name) {
                return $this->open_elements[$i];
            }
        }
        return null;
    }

    private function _clear_stack_until(array $names): void
    {
        while ($this->open_elements) {
            $node = $this->open_elements[count($this->open_elements) - 1];
            if (isset($names[$node->name]) && ($node->namespace === null || $node->namespace === 'html')) {
                break;
            }
            array_pop($this->open_elements);
        }
    }

    private function _generate_implied_end_tags(?string $exclude = null): void
    {
        while ($this->open_elements) {
            $node = $this->open_elements[count($this->open_elements) - 1];
            if (isset(Constants::IMPLIED_END_TAGS[$node->name]) && $node->name !== $exclude) {
                array_pop($this->open_elements);
                continue;
            }
            break;
        }
    }

    private function _has_in_table_scope(string $name): bool
    {
        return $this->_has_element_in_scope($name, Constants::TABLE_SCOPE_TERMINATORS, false);
    }

    private function _close_table_cell(): bool
    {
        if ($this->_has_in_table_scope('td')) {
            $this->_end_table_cell('td');
            return true;
        }
        if ($this->_has_in_table_scope('th')) {
            $this->_end_table_cell('th');
            return true;
        }
        return false;
    }

    private function _end_table_cell(string $name): void
    {
        $this->_generate_implied_end_tags($name);
        while ($this->open_elements) {
            $node = array_pop($this->open_elements);
            if ($node->name === $name && ($node->namespace === null || $node->namespace === 'html')) {
                break;
            }
        }
        $this->_clear_active_formatting_up_to_marker();
        $this->mode = InsertionMode::IN_ROW;
    }

    private function _flush_pending_table_text(): void
    {
        $data = $this->pending_table_text;
        $this->pending_table_text = '';
        if ($data === '') {
            return;
        }
        if (TreeBuilderUtils::isAllWhitespace($data)) {
            $this->_append_text($data);
            return;
        }
        $this->_parse_error('foster-parenting-character');
        $previous = $this->insert_from_table;
        $this->insert_from_table = true;
        try {
            $this->_reconstruct_active_formatting_elements();
            $this->_append_text($data);
        } finally {
            $this->insert_from_table = $previous;
        }
    }

    private function _close_table_element(): bool
    {
        if (!$this->_has_in_table_scope('table')) {
            $this->_parse_error('unexpected-end-tag', 'table');
            return false;
        }
        $this->_generate_implied_end_tags();
        while ($this->open_elements) {
            $node = array_pop($this->open_elements);
            if ($node->name === 'table') {
                break;
            }
        }
        $this->_reset_insertion_mode();
        return true;
    }

    private function _reset_insertion_mode(): void
    {
        $idx = count($this->open_elements) - 1;
        while ($idx >= 0) {
            $node = $this->open_elements[$idx];
            $name = $node->name;
            if ($name === 'select') {
                $this->mode = InsertionMode::IN_SELECT;
                return;
            }
            if ($name === 'td' || $name === 'th') {
                $this->mode = InsertionMode::IN_CELL;
                return;
            }
            if ($name === 'tr') {
                $this->mode = InsertionMode::IN_ROW;
                return;
            }
            if (in_array($name, ['tbody', 'tfoot', 'thead'], true)) {
                $this->mode = InsertionMode::IN_TABLE_BODY;
                return;
            }
            if ($name === 'caption') {
                $this->mode = InsertionMode::IN_CAPTION;
                return;
            }
            if ($name === 'table') {
                $this->mode = InsertionMode::IN_TABLE;
                return;
            }
            if ($name === 'template') {
                if ($this->template_modes) {
                    $this->mode = $this->template_modes[count($this->template_modes) - 1];
                    return;
                }
            }
            if ($name === 'head') {
                $this->mode = InsertionMode::IN_HEAD;
                return;
            }
            if ($name === 'html') {
                $this->mode = InsertionMode::IN_BODY;
                return;
            }
            $idx -= 1;
        }
        $this->mode = InsertionMode::IN_BODY;
    }

    private function _should_foster_parenting($target, ?string $for_tag = null, bool $is_text = false): bool
    {
        if (!$this->insert_from_table) {
            return false;
        }
        if (!isset(Constants::TABLE_FOSTER_TARGETS[$target->name])) {
            return false;
        }
        if ($is_text) {
            return true;
        }
        if ($for_tag !== null && isset(Constants::TABLE_ALLOWED_CHILDREN[$for_tag])) {
            return false;
        }
        return true;
    }

    private function _lower_ascii(string $value): string
    {
        return $value ? strtolower($value) : '';
    }

    private function _adjust_svg_tag_name(string $name): string
    {
        $lowered = $this->_lower_ascii($name);
        return Constants::SVG_TAG_NAME_ADJUSTMENTS[$lowered] ?? $name;
    }

    private function _prepare_foreign_attributes(string $namespace, array $attrs): array
    {
        if (!$attrs) {
            return [];
        }
        $adjusted = [];
        foreach ($attrs as $name => $value) {
            $lower_name = $this->_lower_ascii($name);
            if ($namespace === 'math' && isset(Constants::MATHML_ATTRIBUTE_ADJUSTMENTS[$lower_name])) {
                $name = Constants::MATHML_ATTRIBUTE_ADJUSTMENTS[$lower_name];
                $lower_name = $this->_lower_ascii($name);
            } elseif ($namespace === 'svg' && isset(Constants::SVG_ATTRIBUTE_ADJUSTMENTS[$lower_name])) {
                $name = Constants::SVG_ATTRIBUTE_ADJUSTMENTS[$lower_name];
                $lower_name = $this->_lower_ascii($name);
            }

            $foreign_adjustment = Constants::FOREIGN_ATTRIBUTE_ADJUSTMENTS[$lower_name] ?? null;
            if ($foreign_adjustment !== null) {
                $prefix = $foreign_adjustment[0];
                $local = $foreign_adjustment[1];
                $name = $prefix !== null ? $prefix . ':' . $local : $local;
            }

            $adjusted[$name] = $value;
        }
        return $adjusted;
    }

    private function _node_attribute_value($node, string $name): ?string
    {
        $target = $this->_lower_ascii($name);
        foreach ($node->attrs as $attr_name => $attr_value) {
            if ($this->_lower_ascii($attr_name) === $target) {
                return $attr_value ?? '';
            }
        }
        return null;
    }

    private function _is_html_integration_point($node): bool
    {
        if ($node->namespace === 'math' && $node->name === 'annotation-xml') {
            $encoding = $this->_node_attribute_value($node, 'encoding');
            if ($encoding) {
                $enc_lower = strtolower($encoding);
                if (in_array($enc_lower, ['text/html', 'application/xhtml+xml'], true)) {
                    return true;
                }
            }
            return false;
        }
        $key = $node->namespace . '|' . $node->name;
        return isset(Constants::HTML_INTEGRATION_POINT_SET[$key]);
    }

    private function _is_mathml_text_integration_point($node): bool
    {
        if ($node->namespace !== 'math') {
            return false;
        }
        $key = $node->namespace . '|' . $node->name;
        return isset(Constants::MATHML_TEXT_INTEGRATION_POINT_SET[$key]);
    }

    private function _adjusted_current_node()
    {
        return $this->open_elements[count($this->open_elements) - 1];
    }

    private function _should_use_foreign_content($token): bool
    {
        $current = $this->_adjusted_current_node();
        if ($current->namespace === null || $current->namespace === 'html') {
            return false;
        }

        if ($token instanceof EOFToken) {
            return false;
        }

        if ($this->_is_mathml_text_integration_point($current)) {
            if ($token instanceof CharacterTokens) {
                return false;
            }
            if ($token instanceof Tag && $token->kind === Tag::START) {
                $name_lower = $this->_lower_ascii($token->name);
                if (!in_array($name_lower, ['mglyph', 'malignmark'], true)) {
                    return false;
                }
            }
        }

        if ($current->namespace === 'math' && $current->name === 'annotation-xml') {
            if ($token instanceof Tag && $token->kind === Tag::START) {
                if ($this->_lower_ascii($token->name) === 'svg') {
                    return false;
                }
            }
        }

        if ($this->_is_html_integration_point($current)) {
            if ($token instanceof CharacterTokens) {
                return false;
            }
            if ($token instanceof Tag && $token->kind === Tag::START) {
                return false;
            }
        }

        return true;
    }

    private function _foreign_breakout_font(Tag $tag): bool
    {
        foreach ($tag->attrs as $name => $value) {
            $lower = $this->_lower_ascii($name);
            if (in_array($lower, ['color', 'face', 'size'], true)) {
                return true;
            }
        }
        return false;
    }

    private function _pop_until_html_or_integration_point(): void
    {
        while ($this->open_elements) {
            $node = $this->open_elements[count($this->open_elements) - 1];
            if ($node->namespace === null || $node->namespace === 'html') {
                return;
            }
            if ($this->_is_html_integration_point($node)) {
                return;
            }
            if ($this->fragment_context_element !== null && $node === $this->fragment_context_element) {
                return;
            }
            array_pop($this->open_elements);
        }
    }

    private function _process_foreign_content($token)
    {
        $current = $this->_adjusted_current_node();

        if ($token instanceof CharacterTokens) {
            $raw = $token->data ?? '';
            $cleaned = [];
            $has_non_null_non_ws = false;
            $len = strlen($raw);
            for ($i = 0; $i < $len; $i++) {
                $ch = $raw[$i];
                if ($ch === "\x00") {
                    $this->_parse_error('invalid-codepoint-in-foreign-content');
                    $cleaned[] = "\u{FFFD}";
                    continue;
                }
                $cleaned[] = $ch;
                if (!in_array($ch, ["\t", "\n", "\f", "\r", ' '], true)) {
                    $has_non_null_non_ws = true;
                }
            }
            $data = implode('', $cleaned);
            if ($has_non_null_non_ws) {
                $this->frameset_ok = false;
            }
            $this->_append_text($data);
            return null;
        }

        if ($token instanceof CommentToken) {
            $this->_append_comment($token->data);
            return null;
        }

        $name_lower = $this->_lower_ascii($token->name);
        if ($token->kind === Tag::START) {
            if (isset(Constants::FOREIGN_BREAKOUT_ELEMENTS[$name_lower]) || ($name_lower === 'font' && $this->_foreign_breakout_font($token))) {
                $this->_parse_error('unexpected-html-element-in-foreign-content');
                $this->_pop_until_html_or_integration_point();
                $this->_reset_insertion_mode();
                return ['reprocess', $this->mode, $token, true];
            }

            $namespace = $current->namespace;
            $adjusted_name = $token->name;
            if ($namespace === 'svg') {
                $adjusted_name = $this->_adjust_svg_tag_name($token->name);
            }
            $attrs = $this->_prepare_foreign_attributes($namespace, $token->attrs);
            $new_tag = new Tag(Tag::START, $adjusted_name, $attrs, $token->selfClosing);
            $this->_insert_element($new_tag, !$token->selfClosing, $namespace);
            return null;
        }

        if (in_array($name_lower, ['br', 'p'], true)) {
            $this->_parse_error('unexpected-html-element-in-foreign-content');
            $this->_pop_until_html_or_integration_point();
            $this->_reset_insertion_mode();
            return ['reprocess', $this->mode, $token, true];
        }

        $idx = count($this->open_elements) - 1;
        $first = true;
        while ($idx >= 0) {
            $node = $this->open_elements[$idx];
            $is_html = $node->namespace === null || $node->namespace === 'html';
            $name_eq = $this->_lower_ascii($node->name) === $name_lower;

            if ($name_eq) {
                if ($this->fragment_context_element !== null && $node === $this->fragment_context_element) {
                    $this->_parse_error('unexpected-end-tag-in-fragment-context');
                    return null;
                }
                if ($is_html) {
                    return ['reprocess', $this->mode, $token, true];
                }
                array_splice($this->open_elements, $idx);
                return null;
            }

            if ($first) {
                $this->_parse_error('unexpected-end-tag-in-foreign-content', $token->name);
                $first = false;
            }

            if ($is_html) {
                return ['reprocess', $this->mode, $token, true];
            }

            $idx -= 1;
        }

        return null;
    }

    private function _appropriate_insertion_location($override_target = null, bool $foster_parenting = false): array
    {
        $target = $override_target ?? $this->_current_node_or_html();
        if ($target === null) {
            return [$this->document, 0];
        }

        if ($foster_parenting && in_array($target->name, ['table', 'tbody', 'tfoot', 'thead', 'tr'], true)) {
            $last_template = $this->_find_last_on_stack('template');
            $last_table = $this->_find_last_on_stack('table');
            if ($last_template !== null && ($last_table === null || array_search($last_template, $this->open_elements, true) > array_search($last_table, $this->open_elements, true))) {
                return [$last_template->templateContent, count($last_template->templateContent->children)];
            }
            if ($last_table === null) {
                return [$target, count($target->children)];
            }
            $parent = $last_table->parent;
            if ($parent === null) {
                $children = $target->children;
                return [$target, $children ? count($children) : 0];
            }
            $position = array_search($last_table, $parent->children, true);
            return [$parent, $position];
        }

        if ($target instanceof TemplateNode && $target->templateContent) {
            $children = $target->templateContent->children;
            return [$target->templateContent, $children ? count($children) : 0];
        }

        $children = $target->children;
        return [$target, $children ? count($children) : 0];
    }

    private function _populate_selectedcontent($root): void
    {
        $selects = [];
        $this->_find_elements($root, 'select', $selects);

        foreach ($selects as $select) {
            $selectedcontent = $this->_find_element($select, 'selectedcontent');
            if (!$selectedcontent) {
                continue;
            }

            $options = [];
            $this->_find_elements($select, 'option', $options);
            if (!$options) {
                continue;
            }

            $selected_option = null;
            foreach ($options as $opt) {
                if ($opt->attrs) {
                    foreach ($opt->attrs as $attr_name => $attr_value) {
                        if ($attr_name === 'selected') {
                            $selected_option = $opt;
                            break;
                        }
                    }
                }
                if ($selected_option) {
                    break;
                }
            }

            if (!$selected_option) {
                $selected_option = $options[0];
            }

            $this->_clone_children($selected_option, $selectedcontent);
        }
    }

    private function _find_elements($node, string $name, array &$result): void
    {
        if ($node->name === $name) {
            $result[] = $node;
        }
        if ($node->hasChildNodes()) {
            foreach ($node->children as $child) {
                $this->_find_elements($child, $name, $result);
            }
        }
    }

    private function _find_element($node, string $name)
    {
        if ($node->name === $name) {
            return $node;
        }
        if ($node->hasChildNodes()) {
            foreach ($node->children as $child) {
                $result = $this->_find_element($child, $name);
                if ($result) {
                    return $result;
                }
            }
        }
        return null;
    }

    private function _clone_children($source, $target): void
    {
        if (empty($source->children)) {
            return;
        }
        foreach ($source->children as $child) {
            $target->appendChild($child->cloneNode(true));
        }
    }

    private function _has_in_scope(string $name): bool
    {
        return $this->_has_element_in_scope($name, Constants::DEFAULT_SCOPE_TERMINATORS);
    }

    private function _has_in_list_item_scope(string $name): bool
    {
        return $this->_has_element_in_scope($name, Constants::LIST_ITEM_SCOPE_TERMINATORS);
    }

    private function _has_in_definition_scope(string $name): bool
    {
        return $this->_has_element_in_scope($name, Constants::DEFINITION_SCOPE_TERMINATORS);
    }

    private function _has_any_in_scope(array $names): bool
    {
        $terminators = Constants::DEFAULT_SCOPE_TERMINATORS;
        $idx = count($this->open_elements) - 1;
        while ($idx >= 0) {
            $node = $this->open_elements[$idx];
            if (isset($names[$node->name])) {
                return true;
            }
            if (($node->namespace === null || $node->namespace === 'html') && isset($terminators[$node->name])) {
                return false;
            }
            $idx -= 1;
        }
        return false;
    }

    public function processCharacters(string $data): int
    {
        $current_node = $this->open_elements ? $this->open_elements[count($this->open_elements) - 1] : null;
        $is_html_namespace = $current_node === null || $current_node->namespace === null || $current_node->namespace === 'html';

        if (!$is_html_namespace) {
            return $this->processToken(new CharacterTokens($data));
        }

        if ($this->mode === InsertionMode::IN_BODY) {
            if (Str::contains($data, "\x00")) {
                $this->_parse_error('invalid-codepoint');
                $data = str_replace("\x00", '', $data);
            }

            if ($data === '') {
                return TokenSinkResult::Continue;
            }

            if (TreeBuilderUtils::isAllWhitespace($data)) {
                $this->_reconstruct_active_formatting_elements();
                $this->_append_text($data);
                return TokenSinkResult::Continue;
            }

            $this->_reconstruct_active_formatting_elements();
            $this->frameset_ok = false;
            $this->_append_text($data);
            return TokenSinkResult::Continue;
        }

        return $this->processToken(new CharacterTokens($data));
    }
}
