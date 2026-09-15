<?php

/**
 * Ok, glad you are here
 * first we get a config instance, and set the settings
 * $config = HTMLPurifier_Config::createDefault();
 * $config->set('Core.Encoding', $this->config->get('purifier.encoding'));
 * $config->set('Cache.SerializerPath', $this->config->get('purifier.cachePath'));
 * if ( ! $this->config->get('purifier.finalize')) {
 *     $config->autoFinalize = false;
 * }
 * $config->loadArray($this->getConfig());
 *
 * You must NOT delete the default settings
 * anything in settings should be compacted with params that needed to instance HTMLPurifier_Config.
 *
 * @link http://htmlpurifier.org/live/configdoc/plain.html
 */

/*
 * Rich text — daily reports and notes. The allowlist is exactly what the
 * editor can emit and nothing else — no iframe, no style attributes, no inline
 * CSS — so a crafted paste or a hand-rolled PATCH cannot smuggle script into
 * something every member will read.
 */
$richText = [
    'HTML.Doctype' => 'HTML 4.01 Transitional',
    // `s` and `h2` are what the editor writes; `del` and `div` are kept
    // because everything written before it does still has them.
    'HTML.Allowed' => 'div,p,br,strong,em,s,del,a[href],h1,h2,blockquote,pre,ul,ol,li',
    'CSS.AllowedProperties' => '',
    // javascript: and data: URLs never survive this.
    'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true],
    'HTML.TargetBlank' => true,
    'HTML.Nofollow' => true,
    // The editor lays its own blocks out; auto-paragraphing rewrites them.
    'AutoFormat.AutoParagraph' => false,
    'AutoFormat.RemoveEmpty' => true,
];

/*
 * The same, plus pictures — notes only.
 *
 * Reports deliberately do not get this: nothing in the report editor can
 * produce an image, so an <img> in a report body did not come from a writer
 * and has no business being kept.
 */
$richTextWithImages = array_merge($richText, [
    'HTML.Allowed' => $richText['HTML.Allowed'].',img[src|alt|width|height]',
    /*
     * Images must be ours, and this is what enforces it.
     *
     * The rule rejects any *embedded* URL that carries a host, and URI.Host is
     * deliberately left unset so that means every one of them.
     * Attachment::inlineSrc() writes a root-relative `/attachments/…` path,
     * which has no host and passes; a pasted
     * `<img src="https://tracker.example/pixel.gif">` does not, so a note
     * cannot report back to whoever wrote it who opened it and when. Embedded
     * resources only — ordinary external links are untouched.
     */
    'URI.DisableExternalResources' => true,
    /*
     * An image with no alt gets an empty one, not an invented one.
     *
     * Left to itself HTMLPurifier fills a missing alt from the last segment of
     * the src — and ours is `/attachments/12`, so a screen reader would read
     * the picture out as "12". An empty alt at least says "this has no
     * description" rather than a lie.
     */
    'Attr.DefaultImageAlt' => '',
]);

return [
    'encoding' => 'UTF-8',
    'finalize' => true,
    'ignoreNonStrings' => false,
    'cachePath' => storage_path('app/purifier'),
    'cacheFileMode' => 0755,
    'settings' => [
        'default' => [
            'HTML.Doctype' => 'HTML 4.01 Transitional',
            'HTML.Allowed' => 'div,b,strong,i,em,u,a[href|title],ul,ol,li,p[style],br,span[style],img[width|height|alt|src]',
            'CSS.AllowedProperties' => 'font,font-size,font-weight,font-style,font-family,text-decoration,padding-left,color,background-color,text-align',
            'AutoFormat.AutoParagraph' => true,
            'AutoFormat.RemoveEmpty' => true,
        ],
        'rich_text' => $richText,
        'rich_text_images' => $richTextWithImages,

        'test' => [
            'Attr.EnableID' => 'true',
        ],
        'youtube' => [
            'HTML.SafeIframe' => 'true',
            'URI.SafeIframeRegexp' => '%^(http://|https://|//)(www.youtube.com/embed/|player.vimeo.com/video/)%',
        ],
        'custom_definition' => [
            'id' => 'html5-definitions',
            'rev' => 1,
            'debug' => false,
            'elements' => [
                // http://developers.whatwg.org/sections.html
                ['section', 'Block', 'Flow', 'Common'],
                ['nav',     'Block', 'Flow', 'Common'],
                ['article', 'Block', 'Flow', 'Common'],
                ['aside',   'Block', 'Flow', 'Common'],
                ['header',  'Block', 'Flow', 'Common'],
                ['footer',  'Block', 'Flow', 'Common'],

                // Content model actually excludes several tags, not modelled here
                ['address', 'Block', 'Flow', 'Common'],
                ['hgroup', 'Block', 'Required: h1 | h2 | h3 | h4 | h5 | h6', 'Common'],

                // http://developers.whatwg.org/grouping-content.html
                ['figure', 'Block', 'Optional: (figcaption, Flow) | (Flow, figcaption) | Flow', 'Common'],
                ['figcaption', 'Inline', 'Flow', 'Common'],

                // http://developers.whatwg.org/the-video-element.html#the-video-element
                ['video', 'Block', 'Optional: (source, Flow) | (Flow, source) | Flow', 'Common', [
                    'src' => 'URI',
                    'type' => 'Text',
                    'width' => 'Length',
                    'height' => 'Length',
                    'poster' => 'URI',
                    'preload' => 'Enum#auto,metadata,none',
                    'controls' => 'Bool',
                ]],
                ['source', 'Block', 'Flow', 'Common', [
                    'src' => 'URI',
                    'type' => 'Text',
                ]],

                // http://developers.whatwg.org/text-level-semantics.html
                ['s',    'Inline', 'Inline', 'Common'],
                ['var',  'Inline', 'Inline', 'Common'],
                ['sub',  'Inline', 'Inline', 'Common'],
                ['sup',  'Inline', 'Inline', 'Common'],
                ['mark', 'Inline', 'Inline', 'Common'],
                ['wbr',  'Inline', 'Empty', 'Core'],

                // http://developers.whatwg.org/edits.html
                ['ins', 'Block', 'Flow', 'Common', ['cite' => 'URI', 'datetime' => 'CDATA']],
                ['del', 'Block', 'Flow', 'Common', ['cite' => 'URI', 'datetime' => 'CDATA']],
            ],
            'attributes' => [
                ['iframe', 'allowfullscreen', 'Bool'],
                ['table', 'height', 'Text'],
                ['td', 'border', 'Text'],
                ['th', 'border', 'Text'],
                ['tr', 'width', 'Text'],
                ['tr', 'height', 'Text'],
                ['tr', 'border', 'Text'],
            ],
        ],
        'custom_attributes' => [
            ['a', 'target', 'Enum#_blank,_self,_target,_top'],
        ],
        'custom_elements' => [
            ['u', 'Inline', 'Inline', 'Common'],
        ],
    ],

];
