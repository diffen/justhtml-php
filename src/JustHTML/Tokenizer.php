<?php

declare(strict_types=1);

namespace JustHTML;

final class TokenizerOpts
{
    public bool $discardBom;
    public bool $exactErrors;
    public ?string $initialRawtextTag;
    public ?int $initialState;
    public bool $xmlCoercion;

    public function __construct(
        bool $exactErrors = false,
        bool $discardBom = true,
        ?int $initialState = null,
        ?string $initialRawtextTag = null,
        bool $xmlCoercion = false
    ) {
        $this->exactErrors = $exactErrors;
        $this->discardBom = $discardBom;
        $this->initialState = $initialState;
        $this->initialRawtextTag = $initialRawtextTag;
        $this->xmlCoercion = $xmlCoercion;
    }
}

final class Tokenizer
{
    use TokenizerStates;
    public const DATA = 0;
    public const TAG_OPEN = 1;
    public const END_TAG_OPEN = 2;
    public const TAG_NAME = 3;
    public const BEFORE_ATTRIBUTE_NAME = 4;
    public const ATTRIBUTE_NAME = 5;
    public const AFTER_ATTRIBUTE_NAME = 6;
    public const BEFORE_ATTRIBUTE_VALUE = 7;
    public const ATTRIBUTE_VALUE_DOUBLE = 8;
    public const ATTRIBUTE_VALUE_SINGLE = 9;
    public const ATTRIBUTE_VALUE_UNQUOTED = 10;
    public const AFTER_ATTRIBUTE_VALUE_QUOTED = 11;
    public const SELF_CLOSING_START_TAG = 12;
    public const MARKUP_DECLARATION_OPEN = 13;
    public const COMMENT_START = 14;
    public const COMMENT_START_DASH = 15;
    public const COMMENT = 16;
    public const COMMENT_END_DASH = 17;
    public const COMMENT_END = 18;
    public const COMMENT_END_BANG = 19;
    public const BOGUS_COMMENT = 20;
    public const DOCTYPE = 21;
    public const BEFORE_DOCTYPE_NAME = 22;
    public const DOCTYPE_NAME = 23;
    public const AFTER_DOCTYPE_NAME = 24;
    public const BOGUS_DOCTYPE = 25;
    public const AFTER_DOCTYPE_PUBLIC_KEYWORD = 26;
    public const AFTER_DOCTYPE_SYSTEM_KEYWORD = 27;
    public const BEFORE_DOCTYPE_PUBLIC_IDENTIFIER = 28;
    public const DOCTYPE_PUBLIC_IDENTIFIER_DOUBLE_QUOTED = 29;
    public const DOCTYPE_PUBLIC_IDENTIFIER_SINGLE_QUOTED = 30;
    public const AFTER_DOCTYPE_PUBLIC_IDENTIFIER = 31;
    public const BETWEEN_DOCTYPE_PUBLIC_AND_SYSTEM_IDENTIFIERS = 32;
    public const BEFORE_DOCTYPE_SYSTEM_IDENTIFIER = 33;
    public const DOCTYPE_SYSTEM_IDENTIFIER_DOUBLE_QUOTED = 34;
    public const DOCTYPE_SYSTEM_IDENTIFIER_SINGLE_QUOTED = 35;
    public const AFTER_DOCTYPE_SYSTEM_IDENTIFIER = 36;
    public const CDATA_SECTION = 37;
    public const CDATA_SECTION_BRACKET = 38;
    public const CDATA_SECTION_END = 39;
    public const RCDATA = 40;
    public const RCDATA_LESS_THAN_SIGN = 41;
    public const RCDATA_END_TAG_OPEN = 42;
    public const RCDATA_END_TAG_NAME = 43;
    public const RAWTEXT = 44;
    public const RAWTEXT_LESS_THAN_SIGN = 45;
    public const RAWTEXT_END_TAG_OPEN = 46;
    public const RAWTEXT_END_TAG_NAME = 47;
    public const PLAINTEXT = 48;
    public const SCRIPT_DATA_ESCAPED = 49;
    public const SCRIPT_DATA_ESCAPED_DASH = 50;
    public const SCRIPT_DATA_ESCAPED_DASH_DASH = 51;
    public const SCRIPT_DATA_ESCAPED_LESS_THAN_SIGN = 52;
    public const SCRIPT_DATA_ESCAPED_END_TAG_OPEN = 53;
    public const SCRIPT_DATA_ESCAPED_END_TAG_NAME = 54;
    public const SCRIPT_DATA_DOUBLE_ESCAPE_START = 55;
    public const SCRIPT_DATA_DOUBLE_ESCAPED = 56;
    public const SCRIPT_DATA_DOUBLE_ESCAPED_DASH = 57;
    public const SCRIPT_DATA_DOUBLE_ESCAPED_DASH_DASH = 58;
    public const SCRIPT_DATA_DOUBLE_ESCAPED_LESS_THAN_SIGN = 59;
    public const SCRIPT_DATA_DOUBLE_ESCAPE_END = 60;

    /** @var array<int, callable> */
    private static array $stateHandlers = [];

    private const ATTR_VALUE_UNQUOTED_TERMINATORS = "\t\n\f >&\"'<=`\r\0";
    private const RCDATA_ELEMENTS = ['title' => true, 'textarea' => true];
    private const RAWTEXT_SWITCH_TAGS = [
        'script' => true,
        'style' => true,
        'xmp' => true,
        'iframe' => true,
        'noembed' => true,
        'noframes' => true,
        'textarea' => true,
        'title' => true,
    ];

    private static ?string $xmlCoercionPattern = null;

    public $sink;
    public TokenizerOpts $opts;
    public bool $collectErrors;
    /** @var array<int, ParseError> */
    public array $errors = [];

    public int $state = self::DATA;
    public string $buffer = '';
    public int $length = 0;
    public int $pos = 0;
    public bool $reconsume = false;
    public ?string $currentChar = '';
    public bool $ignoreLf = false;
    public int $lastTokenLine = 1;
    public int $lastTokenColumn = 0;

    /** @var array<int, string> */
    private array $textBuffer = [];
    private int $textStartPos = 0;
    /** @var array<int, string> */
    private array $currentTagName = [];
    /** @var array<string, string|null> */
    private array $currentTagAttrs = [];
    /** @var array<int, string> */
    private array $currentAttrName = [];
    /** @var array<int, string> */
    private array $currentAttrValue = [];
    private bool $currentAttrValueHasAmp = false;
    private bool $currentTagSelfClosing = false;
    private int $currentTagKind = Tag::START;
    /** @var array<int, string> */
    private array $currentComment = [];
    /** @var array<int, string> */
    private array $currentDoctypeName = [];
    /** @var array<int, string>|null */
    private ?array $currentDoctypePublic = null;
    /** @var array<int, string>|null */
    private ?array $currentDoctypeSystem = null;
    private bool $currentDoctypeForceQuirks = false;
    private ?string $lastStartTagName = null;
    private ?string $rawtextTagName = null;
    /** @var array<int, string> */
    private array $originalTagName = [];
    /** @var array<int, string> */
    private array $tempBuffer = [];
    private Tag $tagToken;
    private CommentToken $commentToken;

    /** @var array<int, int>|null */
    private ?array $newlinePositions = null;

    public function __construct($sink, ?TokenizerOpts $opts = null, bool $collectErrors = false)
    {
        $this->sink = $sink;
        $this->opts = $opts ?? new TokenizerOpts();
        $this->collectErrors = $collectErrors;
        $this->errors = [];

        $this->tagToken = new Tag(Tag::START, '', [], false);
        $this->commentToken = new CommentToken('');

        if (!self::$stateHandlers) {
            self::$stateHandlers = [
                self::DATA => 'stateData',
                self::TAG_OPEN => 'stateTagOpen',
                self::END_TAG_OPEN => 'stateEndTagOpen',
                self::TAG_NAME => 'stateTagName',
                self::BEFORE_ATTRIBUTE_NAME => 'stateBeforeAttributeName',
                self::ATTRIBUTE_NAME => 'stateAttributeName',
                self::AFTER_ATTRIBUTE_NAME => 'stateAfterAttributeName',
                self::BEFORE_ATTRIBUTE_VALUE => 'stateBeforeAttributeValue',
                self::ATTRIBUTE_VALUE_DOUBLE => 'stateAttributeValueDouble',
                self::ATTRIBUTE_VALUE_SINGLE => 'stateAttributeValueSingle',
                self::ATTRIBUTE_VALUE_UNQUOTED => 'stateAttributeValueUnquoted',
                self::AFTER_ATTRIBUTE_VALUE_QUOTED => 'stateAfterAttributeValueQuoted',
                self::SELF_CLOSING_START_TAG => 'stateSelfClosingStartTag',
                self::MARKUP_DECLARATION_OPEN => 'stateMarkupDeclarationOpen',
                self::COMMENT_START => 'stateCommentStart',
                self::COMMENT_START_DASH => 'stateCommentStartDash',
                self::COMMENT => 'stateComment',
                self::COMMENT_END_DASH => 'stateCommentEndDash',
                self::COMMENT_END => 'stateCommentEnd',
                self::COMMENT_END_BANG => 'stateCommentEndBang',
                self::BOGUS_COMMENT => 'stateBogusComment',
                self::DOCTYPE => 'stateDoctype',
                self::BEFORE_DOCTYPE_NAME => 'stateBeforeDoctypeName',
                self::DOCTYPE_NAME => 'stateDoctypeName',
                self::AFTER_DOCTYPE_NAME => 'stateAfterDoctypeName',
                self::BOGUS_DOCTYPE => 'stateBogusDoctype',
                self::AFTER_DOCTYPE_PUBLIC_KEYWORD => 'stateAfterDoctypePublicKeyword',
                self::AFTER_DOCTYPE_SYSTEM_KEYWORD => 'stateAfterDoctypeSystemKeyword',
                self::BEFORE_DOCTYPE_PUBLIC_IDENTIFIER => 'stateBeforeDoctypePublicIdentifier',
                self::DOCTYPE_PUBLIC_IDENTIFIER_DOUBLE_QUOTED => 'stateDoctypePublicIdentifierDoubleQuoted',
                self::DOCTYPE_PUBLIC_IDENTIFIER_SINGLE_QUOTED => 'stateDoctypePublicIdentifierSingleQuoted',
                self::AFTER_DOCTYPE_PUBLIC_IDENTIFIER => 'stateAfterDoctypePublicIdentifier',
                self::BETWEEN_DOCTYPE_PUBLIC_AND_SYSTEM_IDENTIFIERS => 'stateBetweenDoctypePublicAndSystemIdentifiers',
                self::BEFORE_DOCTYPE_SYSTEM_IDENTIFIER => 'stateBeforeDoctypeSystemIdentifier',
                self::DOCTYPE_SYSTEM_IDENTIFIER_DOUBLE_QUOTED => 'stateDoctypeSystemIdentifierDoubleQuoted',
                self::DOCTYPE_SYSTEM_IDENTIFIER_SINGLE_QUOTED => 'stateDoctypeSystemIdentifierSingleQuoted',
                self::AFTER_DOCTYPE_SYSTEM_IDENTIFIER => 'stateAfterDoctypeSystemIdentifier',
                self::CDATA_SECTION => 'stateCdataSection',
                self::CDATA_SECTION_BRACKET => 'stateCdataSectionBracket',
                self::CDATA_SECTION_END => 'stateCdataSectionEnd',
                self::RCDATA => 'stateRcdata',
                self::RCDATA_LESS_THAN_SIGN => 'stateRcdataLessThanSign',
                self::RCDATA_END_TAG_OPEN => 'stateRcdataEndTagOpen',
                self::RCDATA_END_TAG_NAME => 'stateRcdataEndTagName',
                self::RAWTEXT => 'stateRawtext',
                self::RAWTEXT_LESS_THAN_SIGN => 'stateRawtextLessThanSign',
                self::RAWTEXT_END_TAG_OPEN => 'stateRawtextEndTagOpen',
                self::RAWTEXT_END_TAG_NAME => 'stateRawtextEndTagName',
                self::PLAINTEXT => 'statePlaintext',
                self::SCRIPT_DATA_ESCAPED => 'stateScriptDataEscaped',
                self::SCRIPT_DATA_ESCAPED_DASH => 'stateScriptDataEscapedDash',
                self::SCRIPT_DATA_ESCAPED_DASH_DASH => 'stateScriptDataEscapedDashDash',
                self::SCRIPT_DATA_ESCAPED_LESS_THAN_SIGN => 'stateScriptDataEscapedLessThanSign',
                self::SCRIPT_DATA_ESCAPED_END_TAG_OPEN => 'stateScriptDataEscapedEndTagOpen',
                self::SCRIPT_DATA_ESCAPED_END_TAG_NAME => 'stateScriptDataEscapedEndTagName',
                self::SCRIPT_DATA_DOUBLE_ESCAPE_START => 'stateScriptDataDoubleEscapeStart',
                self::SCRIPT_DATA_DOUBLE_ESCAPED => 'stateScriptDataDoubleEscaped',
                self::SCRIPT_DATA_DOUBLE_ESCAPED_DASH => 'stateScriptDataDoubleEscapedDash',
                self::SCRIPT_DATA_DOUBLE_ESCAPED_DASH_DASH => 'stateScriptDataDoubleEscapedDashDash',
                self::SCRIPT_DATA_DOUBLE_ESCAPED_LESS_THAN_SIGN => 'stateScriptDataDoubleEscapedLessThanSign',
                self::SCRIPT_DATA_DOUBLE_ESCAPE_END => 'stateScriptDataDoubleEscapeEnd',
            ];
        }
    }

    public function initialize(?string $html): void
    {
        if ($html !== null && $html !== '' && $html[0] === "\u{FEFF}" && $this->opts->discardBom) {
            $html = substr($html, 1);
        }

        $this->buffer = $html ?? '';
        $this->length = strlen($this->buffer);
        $this->pos = 0;
        $this->reconsume = false;
        $this->currentChar = '';
        $this->ignoreLf = false;
        $this->lastTokenLine = 1;
        $this->lastTokenColumn = 0;
        $this->errors = [];
        $this->textBuffer = [];
        $this->textStartPos = 0;
        $this->currentTagName = [];
        $this->currentTagAttrs = [];
        $this->currentAttrName = [];
        $this->currentAttrValue = [];
        $this->currentAttrValueHasAmp = false;
        $this->currentComment = [];
        $this->currentDoctypeName = [];
        $this->currentDoctypePublic = null;
        $this->currentDoctypeSystem = null;
        $this->currentDoctypeForceQuirks = false;
        $this->currentTagSelfClosing = false;
        $this->currentTagKind = Tag::START;
        $this->rawtextTagName = $this->opts->initialRawtextTag;
        $this->tempBuffer = [];
        $this->lastStartTagName = null;
        $this->tagToken->kind = Tag::START;
        $this->tagToken->name = '';
        $this->tagToken->attrs = [];
        $this->tagToken->selfClosing = false;

        if ($this->opts->initialState !== null) {
            $this->state = $this->opts->initialState;
        } else {
            $this->state = self::DATA;
        }

        if ($this->collectErrors) {
            $this->newlinePositions = [];
            $pos = -1;
            while (true) {
                $pos = strpos($this->buffer, "\n", $pos + 1);
                if ($pos === false) {
                    break;
                }
                $this->newlinePositions[] = $pos;
            }
        } else {
            $this->newlinePositions = null;
        }
    }

    private function getLineAtPos(int $pos): int
    {
        if ($this->newlinePositions === null) {
            return 1;
        }
        $count = 0;
        foreach ($this->newlinePositions as $nl) {
            if ($nl < $pos) {
                $count++;
            } else {
                break;
            }
        }
        return $count + 1;
    }

    public function step(): bool
    {
        $handler = self::$stateHandlers[$this->state] ?? null;
        if ($handler === null) {
            return true;
        }
        return $this->{$handler}();
    }

    public function run(?string $html): void
    {
        $this->initialize($html);
        while (true) {
            if ($this->step()) {
                break;
            }
        }
    }

    private function peekChar(int $offset): ?string
    {
        $pos = $this->pos + $offset;
        if ($pos < $this->length) {
            return $this->buffer[$pos];
        }
        return null;
    }

    private function appendTextChunk(string $chunk, bool $endsWithCr = false): void
    {
        $this->appendText($chunk);
        $this->ignoreLf = $endsWithCr;
    }

    // State handlers implemented in TokenizerStates trait.
}
