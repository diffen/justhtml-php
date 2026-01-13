<?php

declare(strict_types=1);

namespace JustHTML;

final class Constants
{
    private static ?object $formatMarker = null;

    public static function formatMarker(): object
    {
        if (self::$formatMarker === null) {
            self::$formatMarker = new \stdClass();
        }
        return self::$formatMarker;
    }

    public const FOREIGN_ATTRIBUTE_ADJUSTMENTS = [
        "xlink:actuate" => [
            "xlink",
            "actuate",
            "http://www.w3.org/1999/xlink"
        ],
        "xlink:arcrole" => [
            "xlink",
            "arcrole",
            "http://www.w3.org/1999/xlink"
        ],
        "xlink:href" => [
            "xlink",
            "href",
            "http://www.w3.org/1999/xlink"
        ],
        "xlink:role" => [
            "xlink",
            "role",
            "http://www.w3.org/1999/xlink"
        ],
        "xlink:show" => [
            "xlink",
            "show",
            "http://www.w3.org/1999/xlink"
        ],
        "xlink:title" => [
            "xlink",
            "title",
            "http://www.w3.org/1999/xlink"
        ],
        "xlink:type" => [
            "xlink",
            "type",
            "http://www.w3.org/1999/xlink"
        ],
        "xml:lang" => [
            "xml",
            "lang",
            "http://www.w3.org/XML/1998/namespace"
        ],
        "xml:space" => [
            "xml",
            "space",
            "http://www.w3.org/XML/1998/namespace"
        ],
        "xmlns" => [
            null,
            "xmlns",
            "http://www.w3.org/2000/xmlns/"
        ],
        "xmlns:xlink" => [
            "xmlns",
            "xlink",
            "http://www.w3.org/2000/xmlns/"
        ]
    ];

    public const MATHML_ATTRIBUTE_ADJUSTMENTS = [
        "definitionurl" => "definitionURL"
    ];

    public const SVG_ATTRIBUTE_ADJUSTMENTS = [
        "attributename" => "attributeName",
        "attributetype" => "attributeType",
        "basefrequency" => "baseFrequency",
        "baseprofile" => "baseProfile",
        "calcmode" => "calcMode",
        "clippathunits" => "clipPathUnits",
        "diffuseconstant" => "diffuseConstant",
        "edgemode" => "edgeMode",
        "filterunits" => "filterUnits",
        "glyphref" => "glyphRef",
        "gradienttransform" => "gradientTransform",
        "gradientunits" => "gradientUnits",
        "kernelmatrix" => "kernelMatrix",
        "kernelunitlength" => "kernelUnitLength",
        "keypoints" => "keyPoints",
        "keysplines" => "keySplines",
        "keytimes" => "keyTimes",
        "lengthadjust" => "lengthAdjust",
        "limitingconeangle" => "limitingConeAngle",
        "markerheight" => "markerHeight",
        "markerunits" => "markerUnits",
        "markerwidth" => "markerWidth",
        "maskcontentunits" => "maskContentUnits",
        "maskunits" => "maskUnits",
        "numoctaves" => "numOctaves",
        "pathlength" => "pathLength",
        "patterncontentunits" => "patternContentUnits",
        "patterntransform" => "patternTransform",
        "patternunits" => "patternUnits",
        "pointsatx" => "pointsAtX",
        "pointsaty" => "pointsAtY",
        "pointsatz" => "pointsAtZ",
        "preservealpha" => "preserveAlpha",
        "preserveaspectratio" => "preserveAspectRatio",
        "primitiveunits" => "primitiveUnits",
        "refx" => "refX",
        "refy" => "refY",
        "repeatcount" => "repeatCount",
        "repeatdur" => "repeatDur",
        "requiredextensions" => "requiredExtensions",
        "requiredfeatures" => "requiredFeatures",
        "specularconstant" => "specularConstant",
        "specularexponent" => "specularExponent",
        "spreadmethod" => "spreadMethod",
        "startoffset" => "startOffset",
        "stddeviation" => "stdDeviation",
        "stitchtiles" => "stitchTiles",
        "surfacescale" => "surfaceScale",
        "systemlanguage" => "systemLanguage",
        "tablevalues" => "tableValues",
        "targetx" => "targetX",
        "targety" => "targetY",
        "textlength" => "textLength",
        "viewbox" => "viewBox",
        "viewtarget" => "viewTarget",
        "xchannelselector" => "xChannelSelector",
        "ychannelselector" => "yChannelSelector",
        "zoomandpan" => "zoomAndPan"
    ];

    public const HTML_INTEGRATION_POINT_SET = [
        "math|annotation-xml" => true,
        "svg|desc" => true,
        "svg|foreignObject" => true,
        "svg|title" => true
    ];

    public const MATHML_TEXT_INTEGRATION_POINT_SET = [
        "math|mi" => true,
        "math|mn" => true,
        "math|mo" => true,
        "math|ms" => true,
        "math|mtext" => true
    ];

    public const QUIRKY_PUBLIC_PREFIXES = [
        "-//advasoft ltd//dtd html 3.0 aswedit + extensions//",
        "-//as//dtd html 3.0 aswedit + extensions//",
        "-//ietf//dtd html 2.0 level 1//",
        "-//ietf//dtd html 2.0 level 2//",
        "-//ietf//dtd html 2.0 strict level 1//",
        "-//ietf//dtd html 2.0 strict level 2//",
        "-//ietf//dtd html 2.0 strict//",
        "-//ietf//dtd html 2.0//",
        "-//ietf//dtd html 2.1e//",
        "-//ietf//dtd html 3.0//",
        "-//ietf//dtd html 3.2 final//",
        "-//ietf//dtd html 3.2//",
        "-//ietf//dtd html 3//",
        "-//ietf//dtd html level 0//",
        "-//ietf//dtd html level 1//",
        "-//ietf//dtd html level 2//",
        "-//ietf//dtd html level 3//",
        "-//ietf//dtd html strict level 0//",
        "-//ietf//dtd html strict level 1//",
        "-//ietf//dtd html strict level 2//",
        "-//ietf//dtd html strict level 3//",
        "-//ietf//dtd html strict//",
        "-//ietf//dtd html//",
        "-//metrius//dtd metrius presentational//",
        "-//microsoft//dtd internet explorer 2.0 html strict//",
        "-//microsoft//dtd internet explorer 2.0 html//",
        "-//microsoft//dtd internet explorer 2.0 tables//",
        "-//microsoft//dtd internet explorer 3.0 html strict//",
        "-//microsoft//dtd internet explorer 3.0 html//",
        "-//microsoft//dtd internet explorer 3.0 tables//",
        "-//netscape comm. corp.//dtd html//",
        "-//netscape comm. corp.//dtd strict html//",
        "-//o'reilly and associates//dtd html 2.0//",
        "-//o'reilly and associates//dtd html extended 1.0//",
        "-//o'reilly and associates//dtd html extended relaxed 1.0//",
        "-//softquad software//dtd hotmetal pro 6.0::19990601::extensions to html 4.0//",
        "-//softquad//dtd hotmetal pro 4.0::19971010::extensions to html 4.0//",
        "-//spyglass//dtd html 2.0 extended//",
        "-//sq//dtd html 2.0 hotmetal + extensions//",
        "-//sun microsystems corp.//dtd hotjava html//",
        "-//sun microsystems corp.//dtd hotjava strict html//",
        "-//w3c//dtd html 3 1995-03-24//",
        "-//w3c//dtd html 3.2 draft//",
        "-//w3c//dtd html 3.2 final//",
        "-//w3c//dtd html 3.2//",
        "-//w3c//dtd html 3.2s draft//",
        "-//w3c//dtd html 4.0 frameset//",
        "-//w3c//dtd html 4.0 transitional//",
        "-//w3c//dtd html experimental 19960712//",
        "-//w3c//dtd html experimental 970421//",
        "-//w3c//dtd html experimental 970421//",
        "-//w3c//dtd w3 html//",
        "-//w3o//dtd w3 html 3.0//",
        "-//webtechs//dtd mozilla html 2.0//",
        "-//webtechs//dtd mozilla html//"
    ];

    public const QUIRKY_PUBLIC_MATCHES = [
        "-//w3o//dtd w3 html strict 3.0//en//",
        "-/w3c/dtd html 4.0 transitional/en",
        "html"
    ];

    public const QUIRKY_SYSTEM_MATCHES = [
        "http://www.ibm.com/data/dtd/v11/ibmxhtml1-transitional.dtd"
    ];

    public const LIMITED_QUIRKY_PUBLIC_PREFIXES = [
        "-//w3c//dtd xhtml 1.0 frameset//",
        "-//w3c//dtd xhtml 1.0 transitional//"
    ];

    public const HTML4_PUBLIC_PREFIXES = [
        "-//w3c//dtd html 4.01 frameset//",
        "-//w3c//dtd html 4.01 transitional//"
    ];

    public const HEADING_ELEMENTS = [
        "h1" => true,
        "h2" => true,
        "h3" => true,
        "h4" => true,
        "h5" => true,
        "h6" => true
    ];

    public const FORMATTING_ELEMENTS = [
        "a" => true,
        "b" => true,
        "big" => true,
        "code" => true,
        "em" => true,
        "font" => true,
        "i" => true,
        "nobr" => true,
        "s" => true,
        "small" => true,
        "strike" => true,
        "strong" => true,
        "tt" => true,
        "u" => true
    ];

    public const SPECIAL_ELEMENTS = [
        "address" => true,
        "applet" => true,
        "area" => true,
        "article" => true,
        "aside" => true,
        "base" => true,
        "basefont" => true,
        "bgsound" => true,
        "blockquote" => true,
        "body" => true,
        "br" => true,
        "button" => true,
        "caption" => true,
        "center" => true,
        "col" => true,
        "colgroup" => true,
        "dd" => true,
        "details" => true,
        "dialog" => true,
        "dir" => true,
        "div" => true,
        "dl" => true,
        "dt" => true,
        "embed" => true,
        "fieldset" => true,
        "figcaption" => true,
        "figure" => true,
        "footer" => true,
        "form" => true,
        "frame" => true,
        "frameset" => true,
        "h1" => true,
        "h2" => true,
        "h3" => true,
        "h4" => true,
        "h5" => true,
        "h6" => true,
        "head" => true,
        "header" => true,
        "hgroup" => true,
        "hr" => true,
        "html" => true,
        "iframe" => true,
        "img" => true,
        "input" => true,
        "keygen" => true,
        "li" => true,
        "link" => true,
        "listing" => true,
        "main" => true,
        "marquee" => true,
        "menu" => true,
        "menuitem" => true,
        "meta" => true,
        "nav" => true,
        "noembed" => true,
        "noframes" => true,
        "noscript" => true,
        "object" => true,
        "ol" => true,
        "p" => true,
        "param" => true,
        "plaintext" => true,
        "pre" => true,
        "script" => true,
        "search" => true,
        "section" => true,
        "select" => true,
        "source" => true,
        "style" => true,
        "summary" => true,
        "table" => true,
        "tbody" => true,
        "td" => true,
        "template" => true,
        "textarea" => true,
        "tfoot" => true,
        "th" => true,
        "thead" => true,
        "title" => true,
        "tr" => true,
        "track" => true,
        "ul" => true,
        "wbr" => true
    ];

    public const DEFAULT_SCOPE_TERMINATORS = [
        "applet" => true,
        "caption" => true,
        "html" => true,
        "marquee" => true,
        "object" => true,
        "table" => true,
        "td" => true,
        "template" => true,
        "th" => true
    ];

    public const BUTTON_SCOPE_TERMINATORS = [
        "applet" => true,
        "button" => true,
        "caption" => true,
        "html" => true,
        "marquee" => true,
        "object" => true,
        "table" => true,
        "td" => true,
        "template" => true,
        "th" => true
    ];

    public const LIST_ITEM_SCOPE_TERMINATORS = [
        "applet" => true,
        "caption" => true,
        "html" => true,
        "marquee" => true,
        "object" => true,
        "ol" => true,
        "table" => true,
        "td" => true,
        "template" => true,
        "th" => true,
        "ul" => true
    ];

    public const DEFINITION_SCOPE_TERMINATORS = [
        "applet" => true,
        "caption" => true,
        "dl" => true,
        "html" => true,
        "marquee" => true,
        "object" => true,
        "table" => true,
        "td" => true,
        "template" => true,
        "th" => true
    ];

    public const TABLE_FOSTER_TARGETS = [
        "table" => true,
        "tbody" => true,
        "tfoot" => true,
        "thead" => true,
        "tr" => true
    ];

    public const SVG_TAG_NAME_ADJUSTMENTS = [
        "altglyph" => "altGlyph",
        "altglyphdef" => "altGlyphDef",
        "altglyphitem" => "altGlyphItem",
        "animatecolor" => "animateColor",
        "animatemotion" => "animateMotion",
        "animatetransform" => "animateTransform",
        "clippath" => "clipPath",
        "feblend" => "feBlend",
        "fecolormatrix" => "feColorMatrix",
        "fecomponenttransfer" => "feComponentTransfer",
        "fecomposite" => "feComposite",
        "feconvolvematrix" => "feConvolveMatrix",
        "fediffuselighting" => "feDiffuseLighting",
        "fedisplacementmap" => "feDisplacementMap",
        "fedistantlight" => "feDistantLight",
        "feflood" => "feFlood",
        "fefunca" => "feFuncA",
        "fefuncb" => "feFuncB",
        "fefuncg" => "feFuncG",
        "fefuncr" => "feFuncR",
        "fegaussianblur" => "feGaussianBlur",
        "feimage" => "feImage",
        "femerge" => "feMerge",
        "femergenode" => "feMergeNode",
        "femorphology" => "feMorphology",
        "feoffset" => "feOffset",
        "fepointlight" => "fePointLight",
        "fespecularlighting" => "feSpecularLighting",
        "fespotlight" => "feSpotLight",
        "fetile" => "feTile",
        "feturbulence" => "feTurbulence",
        "foreignobject" => "foreignObject",
        "glyphref" => "glyphRef",
        "lineargradient" => "linearGradient",
        "radialgradient" => "radialGradient",
        "textpath" => "textPath"
    ];

    public const FOREIGN_BREAKOUT_ELEMENTS = [
        "b" => true,
        "big" => true,
        "blockquote" => true,
        "body" => true,
        "br" => true,
        "center" => true,
        "code" => true,
        "dd" => true,
        "div" => true,
        "dl" => true,
        "dt" => true,
        "em" => true,
        "embed" => true,
        "h1" => true,
        "h2" => true,
        "h3" => true,
        "h4" => true,
        "h5" => true,
        "h6" => true,
        "head" => true,
        "hr" => true,
        "i" => true,
        "img" => true,
        "li" => true,
        "listing" => true,
        "menu" => true,
        "meta" => true,
        "nobr" => true,
        "ol" => true,
        "p" => true,
        "pre" => true,
        "ruby" => true,
        "s" => true,
        "small" => true,
        "span" => true,
        "strike" => true,
        "strong" => true,
        "sub" => true,
        "sup" => true,
        "table" => true,
        "tt" => true,
        "u" => true,
        "ul" => true,
        "var" => true
    ];

    public const TABLE_ALLOWED_CHILDREN = [
        "caption" => true,
        "colgroup" => true,
        "script" => true,
        "style" => true,
        "tbody" => true,
        "td" => true,
        "template" => true,
        "tfoot" => true,
        "th" => true,
        "thead" => true,
        "tr" => true
    ];

    public const TABLE_SCOPE_TERMINATORS = [
        "html" => true,
        "table" => true,
        "template" => true
    ];

    public const IMPLIED_END_TAGS = [
        "dd" => true,
        "dt" => true,
        "li" => true,
        "optgroup" => true,
        "option" => true,
        "p" => true,
        "rb" => true,
        "rp" => true,
        "rt" => true,
        "rtc" => true
    ];

    public const VOID_ELEMENTS = [
        "area" => true,
        "base" => true,
        "br" => true,
        "col" => true,
        "embed" => true,
        "hr" => true,
        "img" => true,
        "input" => true,
        "link" => true,
        "meta" => true,
        "param" => true,
        "source" => true,
        "track" => true,
        "wbr" => true
    ];

}
