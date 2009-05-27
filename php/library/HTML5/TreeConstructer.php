<?php

/*

Copyright 2007 Jeroen van der Meer <http://jero.net/>

Permission is hereby granted, free of charge, to any person obtaining a
copy of this software and associated documentation files (the
"Software"), to deal in the Software without restriction, including
without limitation the rights to use, copy, modify, merge, publish,
distribute, sublicense, and/or sell copies of the Software, and to
permit persons to whom the Software is furnished to do so, subject to
the following conditions:

The above copyright notice and this permission notice shall be included
in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

*/


// a lot of the attribute handling (specifically, when we look up
// an attribute), is wrong, because I incorrectly assumed that
// we were being passed a hash of attribute names to values. These
// need to be fixed XXX
//
//
// "wasn't ignored" is a pretty big bug that results in infinite
// loops if not checked

class HTML5_TreeConstructer {
    public $stack = array();

    private $mode;
    private $original_mode;
    private $dom;
    private $foster_parent = null;
    private $a_formatting  = array();

    private $head_pointer = null;
    private $form_pointer = null;

    private $flag_frameset_ok = true;

    private $scoping = array('button','caption','html','marquee','object','table','td','th');
    private $formatting = array('a','b','big','em','font','i','nobr','s','small','strike','strong','tt','u');
    private $special = array('address','area','base','basefont','bgsound',
    'blockquote','body','br','center','col','colgroup','dd','dir','div','dl',
    'dt','embed','fieldset','form','frame','frameset','h1','h2','h3','h4','h5',
    'h6','head','hr','iframe','image','img','input','isindex','li','link',
    'listing','menu','meta','noembed','noframes','noscript','ol','optgroup',
    'option','p','param','plaintext','pre','script','select','spacer','style',
    'tbody','textarea','tfoot','thead','title','tr','ul','wbr');

    // Tree construction modes
    const INITIAL           = 0;
    const BEFORE_HTML       = 1;
    const BEFORE_HEAD       = 2;
    const IN_HEAD           = 3;
    const IN_HEAD_NOSCRIPT  = 4;
    const AFTER_HEAD        = 5;
    const IN_BODY           = 6;
    const IN_CDATA_RCDATA   = 7; // XXX: renumber
    const IN_TABLE          = 9;
    const IN_CAPTION        = 10;
    const IN_COLUMN_GROUP   = 11;
    const IN_TABLE_BODY     = 12;
    const IN_ROW            = 13;
    const IN_CELL           = 14;
    const IN_SELECT         = 15;
    const IN_SELECT_IN_TABLE= 16;
    const IN_FOREIGN_CONTENT= 17;
    const AFTER_BODY        = 18;
    const IN_FRAMESET       = 19;
    const AFTER_FRAMESET    = 20;
    const AFTER_AFTER_BODY  = 21;
    const AFTER_AFTER_FRAMESET = 22;

    // The different types of elements.
    const SPECIAL    = 0;
    const SCOPING    = 1;
    const FORMATTING = 2;
    const PHRASING   = 3;

    const MARKER     = 0;

    public function __construct() {
        $this->mode = self::INITIAL;
        $this->dom = new DOMDocument;

        $this->dom->encoding = 'UTF-8';
        $this->dom->preserveWhiteSpace = true;
        $this->dom->substituteEntities = true;
        $this->dom->strictErrorChecking = false;
    }

    // Process tag tokens
    public function emitToken($token, $mode = null) {
        // indenting is a little wonky, this can be changed later on
        if ($mode === null) $mode = $this->mode;
        switch ($mode) {

    case self::INITIAL:

        /* A character token that is one of U+0009 CHARACTER TABULATION,
         * U+000A LINE FEED (LF), U+000C FORM FEED (FF),  or U+0020 SPACE */
        if ($token['type'] === HTML5_Tokenizer::CHARACTER &&
        preg_match('/^[\t\n\x0b\x0c ]+$/', $token['data'])) {
            /* Ignore the token. */
        } elseif ($token['type'] === HTML5_Tokenizer::DOCTYPE) {
            if (
                $token['name'] !== 'html' || !empty($token['public']) ||
                !empty($token['system']) || $token !== 'about:legacy-compat'
            ) {
                /* If the DOCTYPE token's name is not a case-sensitive match
                 * for the string "html", or if the token's public identifier
                 * is not missing, or if the token's system identifier is
                 * neither missing nor a case-sensitive match for the string
                 * "about:legacy-compat", then there is a parse error (this
                 * is the DOCTYPE parse error). */
                // DOCTYPE parse error
            }
            /* Append a DocumentType node to the Document node, with the name
             * attribute set to the name given in the DOCTYPE token, or the
             * empty string if the name was missing; the publicId attribute
             * set to the public identifier given in the DOCTYPE token, or
             * the empty string if the public identifier was missing; the
             * systemId attribute set to the system identifier given in the
             * DOCTYPE token, or the empty string if the system identifier
             * was missing; and the other attributes specific to
             * DocumentType objects set to null and empty lists as
             * appropriate. Associate the DocumentType node with the
             * Document object so that it is returned as the value of the
             * doctype attribute of the Document object. */
            if (!isset($token['public'])) $token['public'] = null;
            if (!isset($token['system'])) $token['system'] = null;
            $impl = new DOMImplementation();
            $doctype = $impl->createDocumentType($token['name'], $token['public'], $token['system']);
            $this->dom = $impl->createDocument(null, 'html', $doctype);
            // XXX: Implement quirks mode
            $this->mode = self::BEFORE_HTML;
        } else {
            // parse error
            // XXX: Implement quirks mode
            /* Switch the insertion mode to "before html", then reprocess the
             * current token. */
            $this->mode = self::BEFORE_HTML;
            return $this->emitToken($token);
        }
        break;

    case self::BEFORE_HTML:

        /* A DOCTYPE token */
        if($token['type'] === HTML5_Tokenizer::DOCTYPE) {
            // Parse error. Ignore the token.

        /* A comment token */
        } elseif($token['type'] === HTML5_Tokenizer::COMMENT) {
            /* Append a Comment node to the Document object with the data
            attribute set to the data given in the comment token. */
            $comment = $this->dom->createComment($token['data']);
            $this->dom->appendChild($comment);

        /* A character token that is one of one of U+0009 CHARACTER TABULATION,
        U+000A LINE FEED (LF), U+000B LINE TABULATION, U+000C FORM FEED (FF),
        or U+0020 SPACE */
        } elseif($token['type'] === HTML5_Tokenizer::CHARACTER &&
        preg_match('/^[\t\n\x0b\x0c ]+$/', $token['data'])) {
            /* Ignore the token. */

        /* A start tag whose tag name is "html" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] == 'html') {
            /* Create an HTMLElement node with the tag name html, in the HTML
            namespace. Append it to the Document object. Put this element in
            the stack of open elements.*/
            $html = $this->dom->createElement('html');
            $this->dom->appendChild($html);
            $this->stack[] = $html;

            $this->mode = self::BEFORE_HEAD;

        } else {
            /* Create an html element. Append it to the Document object. Put
             * this element in the stack of open elements. */
            $html = $this->dom->createElement('html');
            $this->dom->appendChild($html);
            $this->stack[] = $html;

            /* Switch the insertion mode to "before head", then reprocess the
             * current token. */
            $this->mode = self::BEFORE_HEAD;
            return $this->emitToken($token);
        }
        break;

    case self::BEFORE_HEAD:

        /* A character token that is one of one of U+0009 CHARACTER TABULATION,
        U+000A LINE FEED (LF), U+000B LINE TABULATION, U+000C FORM FEED (FF),
        or U+0020 SPACE */
        if($token['type'] === HTML5_Tokenizer::CHARACTER &&
        preg_match('/^[\t\n\x0b\x0c ]+$/', $token['data'])) {
            /* Ignore the token. */

        /* A comment token */
        } elseif($token['type'] === HTML5_Tokenizer::COMMENT) {
            /* Append a Comment node to the current node with the data attribute
            set to the data given in the comment token. */
            $this->insertComment($token['data']);

        /* A DOCTYPE token */
        } elseif($token['type'] === HTML5_Tokenizer::DOCTYPE) {
            /* Parse error. Ignore the token */
            // parse error

        /* A start tag token with the tag name "html" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'html') {
            /* Process the token using the rules for the "in body"
             * insertion mode. */
            return $this->processWithRulesFor($token, self::IN_BODY);

        /* A start tag token with the tag name "head" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'head') {
            /* Insert an HTML element for the token. */
            $element = $this->insertElement($token);

            /* Set the head element pointer to this new element node. */
            $this->head_pointer = $element;

            /* Change the insertion mode to "in head". */
            $this->mode = self::IN_HEAD;

        /* An end tag whose tag name is one of: "head", "body", "html", "br" */
        } elseif(
            $token['type'] === HTML5_Tokenizer::ENDTAG && (
                $token['name'] === 'head' || $token['name'] === 'body' ||
                $token['name'] === 'html' || $token['name'] === 'br'
        )) {
            /* Act as if a start tag token with the tag name "head" and no
             * attributes had been seen, then reprocess the current token. */
            $this->emitToken(array(
                'name' => 'head',
                'type' => HTML5_Tokenizer::STARTTAG,
                'attr' => array()
            ));
            return $this->emitToken($token);

        /* Any other end tag */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG) {
            /* Parse error. Ignore the token. */

        } else {
            /* Act as if a start tag token with the tag name "head" and no
             * attributes had been seen, then reprocess the current token.
             * Note: This will result in an empty head element being
             * generated, with the current token being reprocessed in the
             * "after head" insertion mode. */
            $this->emitToken(array(
                'name' => 'head',
                'type' => HTML5_Tokenizer::STARTTAG,
                'attr' => array()
            ));
            return $this->emitToken($token);
        }
        break;

    case self::IN_HEAD:

        /* A character token that is one of one of U+0009 CHARACTER TABULATION,
        U+000A LINE FEED (LF), U+000B LINE TABULATION, U+000C FORM FEED (FF),
        or U+0020 SPACE. */
        if($token['type'] === HTML5_Tokenizer::CHARACTER &&
        preg_match('/^[\t\n\x0b\x0c ]+$/', $token['data'])) {
            /* Insert the character into the current node. */
            $this->insertText($token['data']);

        /* A comment token */
        } elseif($token['type'] === HTML5_Tokenizer::COMMENT) {
            /* Append a Comment node to the current node with the data attribute
            set to the data given in the comment token. */
            $this->insertComment($token['data']);

        /* A DOCTYPE token */
        } elseif($token['type'] === HTML5_Tokenizer::DOCTYPE) {
            /* Parse error. Ignore the token. */
            // parse error

        /* A start tag whose tag name is "html" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG &&
        $token['name'] === 'html') {
            return $this->processWithRulesFor($token, self::IN_BODY);

        /* A start tag whose tag name is one of: "base", "command", "link" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG &&
        ($token['name'] === 'base' || $token['name'] === 'command' ||
        $token['name'] === 'link')) {
            /* Insert an HTML element for the token. Immediately pop the
             * current node off the stack of open elements. */
            $this->insertElement($token);
            array_pop($this->stack);

            // XXX: Acknowledge the token's self-closing flag, if it is set.

        /* A start tag whose tag name is "meta" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'meta') {
            /* Insert an HTML element for the token. Immediately pop the
             * current node off the stack of open elements. */
            $this->insertElement($token);
            array_pop($this->stack);

            // XXX: Acknowledge the token's self-closing flag, if it is set.
            //
            // XXX: If the element has a charset attribute, and its value is a
            // supported encoding, and the confidence is currently tentative,
            // then change the encoding to the encoding given by the value of
            // the charset attribute.
            //
            // Otherwise, if the element has a content attribute, and applying
            // the algorithm for extracting an encoding from a Content-Type to
            // its value returns a supported encoding encoding, and the
            // confidence is currently tentative, then change the encoding to
            // the encoding encoding.

        /* A start tag with the tag name "title" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'title') {
            return $this->insertRCDATAElement($token);

        /* A start tag whose tag name is "noscript", if the scripting flag is enabled, or
         * A start tag whose tag name is one of: "noframes", "style" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG &&
        ($token['name'] === 'noscript' || $token['name'] === 'noframes' || $token['name'] === 'style')) {
            // XXX: Scripting flag not respected
            return $this->insertCDATAElement($token);

        // XXX: Scripting flag disable not implemented

        /* A start tag with the tag name "script" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'script') {
            // XXX: This is wrong, not sure how much of spec we want
            /* Create an element for the token. */
            $element = $this->insertElement($token, false);
            $this->head_pointer->appendChild($element);

            $this->mode = self::IN_CDATA_RCDATA;
            /* Switch the tokeniser's content model flag to the CDATA state. */
            return HTML5_Tokenizer::CDATA;

        /* An end tag with the tag name "head" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG && $token['name'] === 'head') {
            /* Pop the current node (which will be the head element) off the stack of open elements. */
            array_pop($this->stack);

            /* Change the insertion mode to "after head". */
            $this->mode = self::AFTER_HEAD;

        // Slight logic inversion here to minimize duplicatoin
        /* A start tag with the tag name "head" or an end tag except "html". */
        } elseif(($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'head') ||
        ($token['type'] === HTML5_Tokenizer::ENDTAG && $token['name'] !== 'html')) {
            // Parse error. Ignore the token.

        /* Anything else */
        } else {
            /* Act as if an end tag token with the tag name "head" had been
             * seen, and reprocess the current token. */
            $this->emitToken(array(
                'name' => 'head',
                'type' => HTML5_Tokenizer::ENDTAG
            ));

            /* Then, reprocess the current token. */
            return $this->emitToken($token);
        }
        break;

    case self::IN_HEAD_NOSCRIPT:
        if ($token['type'] === HTML5_Tokenizer::DOCTYPE) {
            // parse error
        } elseif ($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'html') {
            return $this->processWithRulesFor($token, self::IN_BODY);
        } elseif ($token['type'] === HTML5_Tokenizer::ENDTAG && $token['name'] === 'noscript') {
            /* Pop the current node (which will be a noscript element) from the
             * stack of open elements; the new current node will be a head
             * element. */
            array_pop($this->stack);
            $this->mode = self::IN_HEAD;
        } elseif (
            ($token['type'] === HTML5_Tokenizer::CHARACTER &&
                preg_match('/^[\t\n\x0b\x0c ]+$/', $token['data'])) ||
            ($token['type'] === HTML5_Tokenizer::COMMENT) ||
            ($token['type'] === HTML5_Tokenizer::STARTTAG && (
                $token['name'] === 'link' || $token['name'] === 'meta' ||
                $token['name'] === 'noframes' || $token['name'] === 'style'))) {
            return $this->processWithRulesFor($token, self::IN_HEAD);
        // inverted logic
        } elseif (
            ($token['type'] === HTML5_Tokenizer::STARTTAG && (
                $token['name'] === 'head' || $token['name'] === 'noscript')) ||
            ($token['type'] === HTML5_Tokenizer::ENDTAG &&
                $token['name'] !== 'br')) {
            // parse error
        } else {
            // parse error
            $this->emitToken(array(
                'type' => HTML5_Tokenizer::ENDTAG,
                'name' => 'noscript',
            ));
            return $this->emitToken($token);
        }
        break;

    case self::AFTER_HEAD:
        /* Handle the token as follows: */

        /* A character token that is one of one of U+0009 CHARACTER TABULATION,
        U+000A LINE FEED (LF), U+000B LINE TABULATION, U+000C FORM FEED (FF),
        or U+0020 SPACE */
        if($token['type'] === HTML5_Tokenizer::CHARACTER &&
        preg_match('/^[\t\n\x0b\x0c ]+$/', $token['data'])) {
            /* Append the character to the current node. */
            $this->insertText($token['data']);

        /* A comment token */
        } elseif($token['type'] === HTML5_Tokenizer::COMMENT) {
            /* Append a Comment node to the current node with the data attribute
            set to the data given in the comment token. */
            $this->insertComment($token['data']);

        } elseif ($token['type'] === HTML5_Tokenizer::DOCTYPE) {
            // parse error

        } elseif ($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'html') {
            return $this->processWithRulesFor($token, self::IN_BODY);

        /* A start tag token with the tag name "body" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'body') {
            $this->insertElement($token);

            /* Set the frameset-ok flag to "not ok". */
            $this->flag_frameset_ok = false;

            /* Change the insertion mode to "in body". */
            $this->mode = self::IN_BODY;

        /* A start tag token with the tag name "frameset" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'frameset') {
            /* Insert a frameset element for the token. */
            $this->insertElement($token);

            /* Change the insertion mode to "in frameset". */
            $this->mode = self::IN_FRAMESET;

        /* A start tag token whose tag name is one of: "base", "link", "meta",
        "script", "style", "title" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && in_array($token['name'],
        array('base', 'link', 'meta', 'noframes', 'script', 'style', 'title'))) {
            // parse error
            /* Push the node pointed to by the head element pointer onto the
             * stack of open elements. */
            $this->stack[] = $this->head_pointer;
            $out = $this->processWithRulesFor($token, self::IN_HEAD);
            array_pop($this->stack);
            return $out;

        // inversion of specification
        } elseif(
        ($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'head') ||
        ($token['type'] === HTML5_Tokenizer::ENDTAG &&
            $token['name'] !== 'body' && $token['name'] !== 'html' &&
            $token['name'] !== 'br')) {
            // parse error

        /* Anything else */
        } else {
            $this->emitToken(array(
                'name' => 'body',
                'type' => HTML5_Tokenizer::STARTTAG,
                'attr' => array()
            ));
            $this->flag_frameset_ok = true;
            return $this->emitToken($token);
        }
        break;

    case self::IN_BODY:
        /* Handle the token as follows: */

        switch($token['type']) {
            /* A character token */
            case HTML5_Tokenizer::CHARACTER:
                /* Reconstruct the active formatting elements, if any. */
                $this->reconstructActiveFormattingElements();

                /* Append the token's character to the current node. */
                $this->insertText($token['data']);

                /* If the token is not one of U+0009 CHARACTER TABULATION,
                 * U+000A LINE FEED (LF), U+000C FORM FEED (FF),  or U+0020
                 * SPACE, then set the frameset-ok flag to "not ok". */
                // XXX: not implemented
            break;

            /* A comment token */
            case HTML5_Tokenizer::COMMENT:
                /* Append a Comment node to the current node with the data
                attribute set to the data given in the comment token. */
                $this->insertComment($token['data']);
            break;

            case HTML5_Tokenizer::DOCTYPE:
                // parse error
            break;

            case HTML5_Tokenizer::STARTTAG:
            switch($token['name']) {
                case 'html':
                    // parse error
                    /* For each attribute on the token, check to see if the
                     * attribute is already present on the top element of the
                     * stack of open elements. If it is not, add the attribute
                     * and its corresponding value to that element. */
                    foreach($token['attr'] as $attr) {
                        if(!$this->stack[0]->hasAttribute($attr['name'])) {
                            $this->stack[0]->setAttribute($attr['name'], $attr['value']);
                        }
                    }
                break;

                case 'base': case 'command': case 'link': case 'meta': case 'noframes':
                case 'script': case 'style': case 'title':
                    /* Process the token as if the insertion mode had been "in
                    head". */
                    return $this->processWithRulesFor($token, self::IN_HEAD);
                break;

                /* A start tag token with the tag name "body" */
                case 'body':
                    /* Parse error. If the second element on the stack of open
                    elements is not a body element, or, if the stack of open
                    elements has only one node on it, then ignore the token.
                    (fragment case) */
                    if(count($this->stack) === 1 || $this->stack[1]->nodeName !== 'body') {
                        // Ignore

                    /* Otherwise, for each attribute on the token, check to see
                    if the attribute is already present on the body element (the
                    second element)    on the stack of open elements. If it is not,
                    add the attribute and its corresponding value to that
                    element. */
                    } else {
                        foreach($token['attr'] as $attr) {
                            if(!$this->stack[1]->hasAttribute($attr['name'])) {
                                $this->stack[1]->setAttribute($attr['name'], $attr['value']);
                            }
                        }
                    }
                break;

                case 'frameset':
                    // parse error
                    /* If the second element on the stack of open elements is
                     * not a body element, or, if the stack of open elements
                     * has only one node on it, then ignore the token.
                     * (fragment case) */
                    if(count($this->stack) === 1 || $this->stack[1]->nodeName
                        !== 'body') {
                        // Ignore
                    } elseif (!$this->flag_frameset_ok) {
                        // Ignore
                    } else {
                        // XXX: Not implemented
                    }
                break;

                // in spec, there is a diversion here

                case 'address': case 'article': case 'aside': case 'blockquote':
                case 'center': case 'datagrid': case 'details': case 'dialog': case 'dir':
                case 'div': case 'dl': case 'fieldset': case 'figure': case 'footer':
                case 'header': case 'hgroup': case 'menu': case 'nav':
                case 'ol': case 'p': case 'section': case 'ul':
                    /* If the stack of open elements has a p element in scope,
                    then act as if an end tag with the tag name p had been
                    seen. */
                    if($this->elementInScope('p')) {
                        $this->emitToken(array(
                            'name' => 'p',
                            'type' => HTML5_Tokenizer::ENDTAG
                        ));
                    }

                    /* Insert an HTML element for the token. */
                    $this->insertElement($token);
                break;

                /* A start tag whose tag name is one of: "h1", "h2", "h3", "h4",
                "h5", "h6" */
                case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                    /* If the stack of open elements has a p  element in scope,
                    then act as if an end tag with the tag name p had been seen. */
                    if($this->elementInScope('p')) {
                        $this->emitToken(array(
                            'name' => 'p',
                            'type' => HTML5_Tokenizer::ENDTAG
                        ));
                    }

                    /* If the current node is an element whose tag name is one
                     * of "h1", "h2", "h3", "h4", "h5", or "h6", then this is a
                     * parse error; pop the current node off the stack of open
                     * elements. */
                    $peek = array_pop($this->stack);
                    if (in_array($peek->tagName, array("h1", "h2", "h3", "h4", "h5", "h6"))) {
                        // parse error
                    } else {
                        $this->stack[] = $peek;
                    }

                    /* Insert an HTML element for the token. */
                    $this->insertElement($token);
                break;

                case 'pre': case 'listing':
                    /* If the stack of open elements has a p  element in scope,
                    then act as if an end tag with the tag name p had been seen. */
                    if($this->elementInScope('p')) {
                        $this->emitToken(array(
                            'name' => 'p',
                            'type' => HTML5_Tokenizer::ENDTAG
                        ));
                    }
                    $this->insertElement($token);
                    // XXX: We probably need some flag, then
                    /* If the next token is a U+000A LINE FEED (LF) character
                     * token, then ignore that token and move on to the next
                     * one. (Newlines at the start of pre blocks are ignored as
                     * an authoring convenience.) */
                    $this->flag_frameset_ok = false;

                /* A start tag whose tag name is "form" */
                case 'form':
                    /* If the form element pointer is not null, ignore the
                    token with a parse error. */
                    if($this->form_pointer !== null) {
                        // Ignore.

                    /* Otherwise: */
                    } else {
                        /* If the stack of open elements has a p element in
                        scope, then act as if an end tag with the tag name p
                        had been seen. */
                        if($this->elementInScope('p')) {
                            $this->emitToken(array(
                                'name' => 'p',
                                'type' => HTML5_Tokenizer::ENDTAG
                            ));
                        }

                        /* Insert an HTML element for the token, and set the
                        form element pointer to point to the element created. */
                        $element = $this->insertElement($token);
                        $this->form_pointer = $element;
                    }
                break;

                // condensed specification
                case 'li': case 'dd': case 'dt':
                    /* 1. Set the frameset-ok flag to "not ok". */
                    $this->flag_frameset_ok = false;

                    $stack_length = count($this->stack) - 1;
                    for($n = $stack_length; 0 <= $n; $n--) {
                        /* 2. Initialise node to be the current node (the
                        bottommost node of the stack). */
                        $stop = false;
                        $node = $this->stack[$n];
                        $cat  = $this->getElementCategory($node->tagName);

                        // for case 'li':
                        /* 3. If node is an li element, then act as if an end
                         * tag with the tag name "li" had been seen, then jump
                         * to the last step.  */
                        // for case 'dd': case 'dt':
                        /* If node is a dd or dt element, then act as if an end
                         * tag with the same tag name as node had been seen, then
                         * jump to the last step. */
                        if(($token['name'] === 'li' && $node->tagName === 'li') ||
                        ($node->tagName === 'dd' || $node->tagName === 'dt')) { // limited conditional
                            $this->emitToken(array(
                                'type' => HTML5_Tokenizer::ENDTAG,
                                'name' => $token['name'],
                            ));
                            break;
                        }

                        /* 4. If node is not in the formatting category, and is
                        not    in the phrasing category, and is not an address,
                        div or p element, then stop this algorithm. */
                        if($cat !== self::FORMATTING && $cat !== self::PHRASING &&
                        $node->tagName !== 'address' && $node->tagName !== 'div' &&
                        $node->tagName !== 'p') {
                            break;
                        }

                        /* 5. Otherwise, set node to the previous entry in the
                         * stack of open elements and return to step 2. */
                    }

                    /* 6. This is the last step. */

                    /* If the stack of open elements has a p  element in scope,
                    then act as if an end tag with the tag name p had been
                    seen. */
                    if($this->elementInScope('p')) {
                        $this->emitToken(array(
                            'name' => 'p',
                            'type' => HTML5_Tokenizer::ENDTAG
                        ));
                    }

                    /* Finally, insert an HTML element with the same tag
                    name as the    token's. */
                    $this->insertElement($token);
                break;

                /* A start tag token whose tag name is "plaintext" */
                case 'plaintext':
                    /* If the stack of open elements has a p  element in scope,
                    then act as if an end tag with the tag name p had been
                    seen. */
                    if($this->elementInScope('p')) {
                        $this->emitToken(array(
                            'name' => 'p',
                            'type' => HTML5_Tokenizer::ENDTAG
                        ));
                    }

                    /* Insert an HTML element for the token. */
                    $this->insertElement($token);

                    return HTML5_Tokenizer::PLAINTEXT;
                break;

                // more diversions

                /* A start tag whose tag name is "a" */
                case 'a':
                    /* If the list of active formatting elements contains
                    an element whose tag name is "a" between the end of the
                    list and the last marker on the list (or the start of
                    the list if there is no marker on the list), then this
                    is a parse error; act as if an end tag with the tag name
                    "a" had been seen, then remove that element from the list
                    of active formatting elements and the stack of open
                    elements if the end tag didn't already remove it (it
                    might not have if the element is not in table scope). */
                    $leng = count($this->a_formatting);

                    for($n = $leng - 1; $n >= 0; $n--) {
                        if($this->a_formatting[$n] === self::MARKER) {
                            break;

                        } elseif($this->a_formatting[$n]->nodeName === 'a') {
                            $this->emitToken(array(
                                'name' => 'a',
                                'type' => HTML5_Tokenizer::ENDTAG
                            ));
                            break;
                        }
                    }

                    /* Reconstruct the active formatting elements, if any. */
                    $this->reconstructActiveFormattingElements();

                    /* Insert an HTML element for the token. */
                    $el = $this->insertElement($token);

                    /* Add that element to the list of active formatting
                    elements. */
                    $this->a_formatting[] = $el;
                break;

                case 'b': case 'big': case 'code': case 'em': case 'font': case 'i':
                case 's': case 'small': case 'strike':
                case 'strong': case 'tt': case 'u':
                    /* Reconstruct the active formatting elements, if any. */
                    $this->reconstructActiveFormattingElements();

                    /* Insert an HTML element for the token. */
                    $el = $this->insertElement($token);

                    /* Add that element to the list of active formatting
                    elements. */
                    $this->a_formatting[] = $el;
                break;

                case 'nobr':
                    /* Reconstruct the active formatting elements, if any. */
                    $this->reconstructActiveFormattingElements();

                    /* If the stack of open elements has a nobr element in
                     * scope, then this is a parse error; act as if an end tag
                     * with the tag name "nobr" had been seen, then once again
                     * reconstruct the active formatting elements, if any. */
                    if ($this->elementInScope('nobr')) {
                        $this->emitToken(array(
                            'name' => 'nobr',
                            'type' => HTML5_Tokenizer::ENDTAG,
                        ));
                        $this->reconstructActiveFormattingElements();
                    }

                    /* Insert an HTML element for the token. */
                    $el = $this->insertElement($token);

                    /* Add that element to the list of active formatting
                    elements. */
                    $this->a_formatting[] = $el;
                break;

                // another diversion

                /* A start tag token whose tag name is "button" */
                case 'button':
                    /* If the stack of open elements has a button element in scope,
                    then this is a parse error; act as if an end tag with the tag
                    name "button" had been seen, then reprocess the token. (We don't
                    do that. Unnecessary.) (I hope you're right! -- ezyang) */
                    if($this->elementInScope('button')) {
                        $this->emitToken(array(
                            'name' => 'button',
                            'type' => HTML5_Tokenizer::ENDTAG
                        ));
                    }

                    /* Reconstruct the active formatting elements, if any. */
                    $this->reconstructActiveFormattingElements();

                    /* Insert an HTML element for the token. */
                    $this->insertElement($token);

                    /* Insert a marker at the end of the list of active
                    formatting elements. */
                    $this->a_formatting[] = self::MARKER;

                    $this->flag_frameset_ok = false;
                break;

                case 'applet': case 'marquee': case 'object':
                    /* Reconstruct the active formatting elements, if any. */
                    $this->reconstructActiveFormattingElements();

                    /* Insert an HTML element for the token. */
                    $this->insertElement($token);

                    /* Insert a marker at the end of the list of active
                    formatting elements. */
                    $this->a_formatting[] = self::MARKER;

                    $this->flag_frameset_ok = false;
                break;

                // spec diversion

                /* A start tag whose tag name is "table" */
                case 'table':
                    // XXX: If NOT in quirks mode
                    /* If the stack of open elements has a p element in scope,
                    then act as if an end tag with the tag name p had been seen. */
                    if($this->elementInScope('p')) {
                        $this->emitToken(array(
                            'name' => 'p',
                            'type' => HTML5_Tokenizer::ENDTAG
                        ));
                    }

                    /* Insert an HTML element for the token. */
                    $this->insertElement($token);

                    $this->flag_frameset_ok = false;

                    /* Change the insertion mode to "in table". */
                    $this->mode = self::IN_TABLE;
                break;

                /* A start tag whose tag name is one of: "area", "basefont",
                "bgsound", "br", "embed", "img", "param", "spacer", "wbr" */
                case 'area': case 'basefont': case 'bgsound': case 'br':
                case 'embed': case 'img': case 'input': case 'keygen': case 'spacer':
                case 'wbr':
                    /* Reconstruct the active formatting elements, if any. */
                    $this->reconstructActiveFormattingElements();

                    /* Insert an HTML element for the token. */
                    $this->insertElement($token);

                    /* Immediately pop the current node off the stack of open elements. */
                    array_pop($this->stack);

                    // XXX: Acknowledge the token's self-closing flag, if it is set.

                    $this->flag_frameset_ok = false;
                break;

                case 'param': case 'source':
                    /* Insert an HTML element for the token. */
                    $this->insertElement($token);

                    /* Immediately pop the current node off the stack of open elements. */
                    array_pop($this->stack);

                    // XXX: Acknowledge the token's self-closing flag, if it is set.
                break;

                /* A start tag whose tag name is "hr" */
                case 'hr':
                    /* If the stack of open elements has a p element in scope,
                    then act as if an end tag with the tag name p had been seen. */
                    if($this->elementInScope('p')) {
                        $this->emitToken(array(
                            'name' => 'p',
                            'type' => HTML5_Tokenizer::ENDTAG
                        ));
                    }

                    /* Insert an HTML element for the token. */
                    $this->insertElement($token);

                    /* Immediately pop the current node off the stack of open elements. */
                    array_pop($this->stack);

                    // XXX: Acknowledge the token's self-closing flag, if it is set.

                    $this->flag_frameset_ok = false;
                break;

                /* A start tag whose tag name is "image" */
                case 'image':
                    /* Parse error. Change the token's tag name to "img" and
                    reprocess it. (Don't ask.) */
                    $token['name'] = 'img';
                    return $this->emitToken($token);
                break;

                /* A start tag whose tag name is "isindex" */
                case 'isindex':
                    /* Parse error. */

                    /* If the form element pointer is not null,
                    then ignore the token. */
                    if($this->form_pointer === null) {
                        /* Act as if a start tag token with the tag name "form" had
                        been seen. */
                        /* If the token has an attribute called "action", set
                         * the action attribute on the resulting form
                         * element to the value of the "action" attribute of
                         * the token. */
                        $attr = array();
                        // XXX: bug
                        if (isset($token['attr']['action'])) {
                            $attr['action'] = $token['attr']['action'];
                        }
                        $this->emitToken(array(
                            'name' => 'form',
                            'type' => HTML5_Tokenizer::STARTTAG,
                            'attr' => $attr
                        ));

                        /* Act as if a start tag token with the tag name "hr" had
                        been seen. */
                        $this->emitToken(array(
                            'name' => 'hr',
                            'type' => HTML5_Tokenizer::STARTTAG,
                            'attr' => array()
                        ));

                        /* Act as if a start tag token with the tag name "p" had
                        been seen. */
                        $this->emitToken(array(
                            'name' => 'p',
                            'type' => HTML5_Tokenizer::STARTTAG,
                            'attr' => array()
                        ));

                        /* Act as if a start tag token with the tag name "label"
                        had been seen. */
                        $this->emitToken(array(
                            'name' => 'label',
                            'type' => HTML5_Tokenizer::STARTTAG,
                            'attr' => array()
                        ));

                        // XXX: bug
                        // XXX: not strictly in spec; is this ok?
                        /* Act as if a stream of character tokens had been seen. */
                        if (isset($token['attr']['prompt'])) {
                            $this->insertText($token['attr']['prompt']);
                        } else {
                            $this->insertText('This is a searchable index. '.
                            'Insert your search keywords here: ');
                        }

                        /* Act as if a start tag token with the tag name "input"
                        had been seen, with all the attributes from the "isindex"
                        token, except with the "name" attribute set to the value
                        "isindex" (ignoring any explicit "name" attribute). */
                        $attr = $token['attr'];
                        // XXX: remove attributes
                        $attr[] = array('name' => 'name', 'value' => 'isindex');

                        $this->emitToken(array(
                            'name' => 'input',
                            'type' => HTML5_Tokenizer::STARTTAG,
                            'attr' => $attr
                        ));

                        /* Act as if a stream of character tokens had been seen
                        (see below for what they should say). */
                        $this->insertText('This is a searchable index. '.
                        'Insert your search keywords here: ');

                        /* Act as if an end tag token with the tag name "label"
                        had been seen. */
                        $this->emitToken(array(
                            'name' => 'label',
                            'type' => HTML5_Tokenizer::ENDTAG
                        ));

                        /* Act as if an end tag token with the tag name "p" had
                        been seen. */
                        $this->emitToken(array(
                            'name' => 'p',
                            'type' => HTML5_Tokenizer::ENDTAG
                        ));

                        /* Act as if a start tag token with the tag name "hr" had
                        been seen. */
                        $this->emitToken(array(
                            'name' => 'hr',
                            'type' => HTML5_Tokenizer::ENDTAG
                        ));

                        /* Act as if an end tag token with the tag name "form" had
                        been seen. */
                        $this->emitToken(array(
                            'name' => 'form',
                            'type' => HTML5_Tokenizer::ENDTAG
                        ));
                    }
                break;

                /* A start tag whose tag name is "textarea" */
                case 'textarea':
                    $this->insertElement($token);

                    // XXX: If the next token is a U+000A LINE FEED (LF)
                    // character token, then ignore that token and move on to
                    // the next one. (Newlines at the start of textarea
                    // elements are ignored as an authoring convenience.)
                    // need flag, see also <pre>

                    $this->original_mode = $this->mode;
                    $this->flag_frameset_ok = false;
                    $this->mode = self::IN_CDATA_RCDATA;

                    /* Switch the tokeniser's content model flag to the
                    RCDATA state. */
                    return HTML5_Tokenizer::RCDATA;
                break;

                /* A start tag token whose tag name is "xmp" */
                case 'xmp':
                    /* Reconstruct the active formatting elements, if any. */
                    $this->reconstructActiveFormattingElements();

                    $this->flag_frameset_ok = false;

                    return $this->insertCDATAElement($token);
                break;

                case 'iframe':
                    $this->flag_frameset_ok = false;
                    return $this->insertCDATAElement($token);

                case 'noembed': case 'noscript':
                    // XXX: should check scripting flag
                    return $this->insertCDATAElement($token);
                break;

                /* A start tag whose tag name is "select" */
                case 'select':
                    /* Reconstruct the active formatting elements, if any. */
                    $this->reconstructActiveFormattingElements();

                    /* Insert an HTML element for the token. */
                    $this->insertElement($token);

                    $this->flag_frameset_ok = false;

                    /* If the insertion mode is one of in table", "in caption",
                     * "in column group", "in table body", "in row", or "in
                     * cell", then switch the insertion mode to "in select in
                     * table". Otherwise, switch the insertion mode  to "in
                     * select". */
                    if (
                        $this->mode === self::IN_TABLE || $this->mode === self::IN_CAPTION ||
                        $this->mode === self::IN_COLUMN_GROUP || $this->mode ==+self::IN_TABLE_BODY ||
                        $this->mode === self::IN_ROW || $this->mode === self::IN_CELL
                    ) {
                        $this->mode = self::IN_SELECT_IN_TABLE;
                    } else {
                        $this->mode = self::IN_SELECT;
                    }
                break;

                case 'option': case 'optgroup':
                    if ($this->elementInScope('option')) {
                        $this->emitToken(array(
                            'name' => 'option',
                            'type' => HTML5_Tokenizer::ENDTAG,
                        ));
                    }
                    $this->reconstructActiveFormattingElements();
                    $this->insertElement($token);
                break;

                case 'rp': case 'rt':
                    /* If the stack of open elements has a ruby element in scope, then generate
                     * implied end tags. If the current node is not then a ruby element, this is
                     * a parse error; pop all the nodes from the current node up to the node
                     * immediately before the bottommost ruby element on the stack of open elements.
                     */
                    if ($this->elementInScope('ruby')) {
                        $this->generateImpliedEndTags();
                    }
                    $peek = false;
                    do {
                        if ($peek) {
                            // parse error
                        }
                        $peek = array_pop($this->stack);
                    } while ($peek->tagName !== 'ruby');
                    $this->stack[] = $peek; // we popped one too many
                    $this->insertElement($token);
                break;

                // spec diversion

                case 'math':
                    // XXX: not implemented
                break;

                case 'svg':
                    // XXX: not implemented
                break;

                case 'caption': case 'col': case 'colgroup': case 'frame': case 'head':
                case 'tbody': case 'td': case 'tfoot': case 'th': case 'thead': case 'tr':
                    // parse error
                break;

                /* A start tag token not covered by the previous entries */
                default:
                    /* Reconstruct the active formatting elements, if any. */
                    $this->reconstructActiveFormattingElements();

                    $this->insertElement($token);
                    /* This element will be a phrasing  element. */
                break;
            }
            break;

            case HTML5_Tokenizer::ENDTAG:
            switch($token['name']) {
                /* An end tag with the tag name "body" */
                case 'body':
                    /* If the second element in the stack of open elements is
                    not a body element, this is a parse error. Ignore the token.
                    (innerHTML case) */
                    if(count($this->stack) < 2 || $this->stack[1]->nodeName !== 'body') {
                        // Ignore.

                    /* Otherwise, if there is a node in the stack of open
                     * elements that is not either a dd element, a dt
                     * element, an li element, an optgroup element, an
                     * option element, a p element, an rp element, an rt
                     * element, a tbody element, a td element, a tfoot
                     * element, a th element, a thead element, a tr element,
                     * the body element, or the html element, then this is a
                     * parse error. */
                    } else {
                        // XXX: implement this check for parse error
                    }

                    /* Change the insertion mode to "after body". */
                    $this->mode = self::AFTER_BODY;
                break;

                /* An end tag with the tag name "html" */
                case 'html':
                    /* Act as if an end tag with tag name "body" had been seen,
                    then, if that token wasn't ignored, reprocess the current
                    token. */
                    $this->emitToken(array(
                        'name' => 'body',
                        'type' => HTML5_Tokenizer::ENDTAG
                    ));
                    // XXX: Unclear how to check if a token is ignored or not

                    //return $this->emitToken($token);
                break;

                case 'address': case 'article': case 'aside': case 'blockquote':
                case 'center': case 'datagrid': case 'details': case 'dir':
                case 'div': case 'dl': case 'fieldset': case 'figure': case 'footer':
                case 'header': case 'hgroup': case 'listing': case 'menu':
                case 'nav': case 'ol': case 'pre': case 'section': case 'ul':
                    /* If the stack of open elements has an element in scope
                    with the same tag name as that of the token, then generate
                    implied end tags. */
                    if($this->elementInScope($token['name'])) {
                        $this->generateImpliedEndTags();

                        /* Now, if the current node is not an element with
                        the same tag name as that of the token, then this
                        is a parse error. */
                        // w/e
                        // XXX: implement parse error logic

                        /* If the stack of open elements has an element in
                        scope with the same tag name as that of the token,
                        then pop elements from this stack until an element
                        with that tag name has been popped from the stack. */
                        for($n = count($this->stack) - 1; $n >= 0; $n--) {
                            if($this->stack[$n]->nodeName === $token['name']) {
                                $n = -1;
                            }

                            array_pop($this->stack);
                        }
                    } else {
                        // parse error
                    }
                break;

                /* An end tag whose tag name is "form" */
                case 'form':
                    // XXX: This is wrong
                    /* If the stack of open elements has an element in scope
                    with the same tag name as that of the token, then generate
                    implied    end tags. */
                    if($this->elementInScope($token['name'])) {
                        $this->generateImpliedEndTags();

                    }

                    if(end($this->stack)->nodeName !== $token['name']) {
                        /* Now, if the current node is not an element with the
                        same tag name as that of the token, then this is a parse
                        error. */
                        // w/e

                    } else {
                        /* Otherwise, if the current node is an element with
                        the same tag name as that of the token pop that element
                        from the stack. */
                        array_pop($this->stack);
                    }

                    /* In any case, set the form element pointer to null. */
                    $this->form_pointer = null;
                break;

                /* An end tag whose tag name is "p" */
                case 'p':
                    /* If the stack of open elements has a p element in scope,
                    then generate implied end tags, except for p elements. */
                    if($this->elementInScope('p')) {
                        /* Generate implied end tags, except for elements with
                         * the same tag name as the token. */
                        $this->generateImpliedEndTags(array('p'));

                        /* If the current node is not a p element, then this is
                        a parse error. */
                        // XXX: implement

                        /* Pop elements from the stack of open elements  until
                         * an element with the same tag name as the token has
                         * been popped from the stack. */
                        do {
                            $node = array_pop($this->stack);
                        } while ($node->tagName !== 'p');

                    } else {
                        // parse error
                        $this->emitToken(array(
                            'name' => 'p',
                            'type' => HTML5_Tokenizer::STARTTAG,
                        ));
                        $this->emitToken($token);
                    }
                break;

                /* An end tag whose tag name is "dd", "dt", or "li" */
                case 'dd': case 'dt': case 'li':
                    if($this->elementInScope($token['name'])) {
                        $this->generateImpliedEndTags(array($token['name']));

                        /* If the current node is not an element with the same
                        tag name as the token, then this is a parse error. */
                        // XXX: implement parse error

                        /* Pop elements from the stack of open elements  until
                         * an element with the same tag name as the token has
                         * been popped from the stack. */
                        do {
                            $node = array_pop($this->stack);
                        } while ($node->tagName !== $token['name']);

                    } else {
                        // parse error
                    }
                break;

                /* An end tag whose tag name is one of: "h1", "h2", "h3", "h4",
                "h5", "h6" */
                case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                    $elements = array('h1', 'h2', 'h3', 'h4', 'h5', 'h6');

                    /* If the stack of open elements has in scope an element whose
                    tag name is one of "h1", "h2", "h3", "h4", "h5", or "h6", then
                    generate implied end tags. */
                    if($this->elementInScope($elements)) {
                        $this->generateImpliedEndTags();

                        /* Now, if the current node is not an element with the same
                        tag name as that of the token, then this is a parse error. */
                        // XXX: implement parse error

                        /* If the stack of open elements has in scope an element
                        whose tag name is one of "h1", "h2", "h3", "h4", "h5", or
                        "h6", then pop elements from the stack until an element
                        with one of those tag names has been popped from the stack. */
                        do {
                            $node = array_pop($this->stack);
                        } while (!in_array($node->tagName, $elements));
                    } else {
                        // parse error
                    }
                break;

                /* An end tag whose tag name is one of: "a", "b", "big", "em",
                "font", "i", "nobr", "s", "small", "strike", "strong", "tt", "u" */
                case 'a': case 'b': case 'big': case 'em': case 'font':
                case 'i': case 'nobr': case 's': case 'small': case 'strike':
                case 'strong': case 'tt': case 'u':
                    // XXX: generally speaking this needs parse error logic
                    /* 1. Let the formatting element be the last element in
                    the list of active formatting elements that:
                        * is between the end of the list and the last scope
                        marker in the list, if any, or the start of the list
                        otherwise, and
                        * has the same tag name as the token.
                    */
                    while(true) {
                        for($a = count($this->a_formatting) - 1; $a >= 0; $a--) {
                            if($this->a_formatting[$a] === self::MARKER) {
                                break;

                            } elseif($this->a_formatting[$a]->tagName === $token['name']) {
                                $formatting_element = $this->a_formatting[$a];
                                $in_stack = in_array($formatting_element, $this->stack, true);
                                $fe_af_pos = $a;
                                break;
                            }
                        }

                        /* If there is no such node, or, if that node is
                        also in the stack of open elements but the element
                        is not in scope, then this is a parse error. Abort
                        these steps. The token is ignored. */
                        if(!isset($formatting_element) || ($in_stack &&
                        !$this->elementInScope($token['name']))) {
                            break;

                        /* Otherwise, if there is such a node, but that node
                        is not in the stack of open elements, then this is a
                        parse error; remove the element from the list, and
                        abort these steps. */
                        } elseif(isset($formatting_element) && !$in_stack) {
                            unset($this->a_formatting[$fe_af_pos]);
                            $this->a_formatting = array_merge($this->a_formatting);
                            break;
                        }

                        /* Otherwise, there is a formatting element and that
                         * element is in the stack and is in scope. If the
                         * element is not the current node, this is a parse
                         * error. In any case, proceed with the algorithm as
                         * written in the following steps. */
                        // XXX: implement me

                        /* 2. Let the furthest block be the topmost node in the
                        stack of open elements that is lower in the stack
                        than the formatting element, and is not an element in
                        the phrasing or formatting categories. There might
                        not be one. */
                        $fe_s_pos = array_search($formatting_element, $this->stack, true);
                        $length = count($this->stack);

                        for($s = $fe_s_pos + 1; $s < $length; $s++) {
                            $category = $this->getElementCategory($this->stack[$s]->nodeName);

                            if($category !== self::PHRASING && $category !== self::FORMATTING) {
                                $furthest_block = $this->stack[$s];
                            }
                        }

                        /* 3. If there is no furthest block, then the UA must
                        skip the subsequent steps and instead just pop all
                        the nodes from the bottom of the stack of open
                        elements, from the current node up to the formatting
                        element, and remove the formatting element from the
                        list of active formatting elements. */
                        if(!isset($furthest_block)) {
                            for($n = $length - 1; $n >= $fe_s_pos; $n--) {
                                array_pop($this->stack);
                            }

                            unset($this->a_formatting[$fe_af_pos]);
                            $this->a_formatting = array_merge($this->a_formatting);
                            break;
                        }

                        /* 4. Let the common ancestor be the element
                        immediately above the formatting element in the stack
                        of open elements. */
                        $common_ancestor = $this->stack[$fe_s_pos - 1];

                        /* 5. Let a bookmark note the position of the
                        formatting element in the list of active formatting
                        elements relative to the elements on either side
                        of it in the list. */
                        $bookmark = $fe_af_pos;

                        /* 6. Let node and last node  be the furthest block.
                        Follow these steps: */
                        $node = $furthest_block;
                        $last_node = $furthest_block;

                        while(true) {
                            for($n = array_search($node, $this->stack, true) - 1; $n >= 0; $n--) {
                                /* 6.1 Let node be the element immediately
                                prior to node in the stack of open elements. */
                                $node = $this->stack[$n];

                                /* 6.2 If node is not in the list of active
                                formatting elements, then remove node from
                                the stack of open elements and then go back
                                to step 1. */
                                if(!in_array($node, $this->a_formatting, true)) {
                                    unset($this->stack[$n]);
                                    $this->stack = array_merge($this->stack);

                                } else {
                                    break;
                                }
                            }

                            /* 6.3 Otherwise, if node is the formatting
                            element, then go to the next step in the overall
                            algorithm. */
                            if($node === $formatting_element) {
                                break;

                            /* 6.4 Otherwise, if last node is the furthest
                            block, then move the aforementioned bookmark to
                            be immediately after the node in the list of
                            active formatting elements. */
                            } elseif($last_node === $furthest_block) {
                                $bookmark = array_search($node, $this->a_formatting, true) + 1;
                            }

                            /* 6.5 Create an element for the token for which
                             * the element node was created, replace the entry
                             * for node in the list of active formatting
                             * elements with an entry for the new element,
                             * replace the entry for node in the stack of open
                             * elements with an entry for the new element, and
                             * let node be the new element. */
                            // we don't know what the token is anymore
                            $clone = $node->cloneNode(); // XXX: make sure it has no children!
                            $a_pos = array_search($node, $this->a_formatting, true);
                            $s_pos = array_search($node, $this->stack, true);
                            $this->a_formatting[$a_pos] = $clone;
                            $this->stack[$s_pos] = $clone;
                            $node = $clone;

                            /* 6.6 Insert last node into node, first removing
                            it from its previous parent node if any. */
                            if($last_node->parentNode !== null) {
                                $last_node->parentNode->removeChild($last_node);
                            }

                            $node->appendChild($last_node);

                            /* 6.7 Let last node be node. */
                            $last_node = $node;

                            /* 6.8 Return to step 1 of this inner set of steps. */
                        }

                        /* 7. If the common ancestor node is a table, tbody,
                         * tfoot, thead, or tr element, then, foster parent
                         * whatever last node ended up being in the previous
                         * step, first removing it from its previous parent
                         * node if any. */
                        if ($last_node->parentNode) { // common step
                            $last_node->parentNode->removeChild($last_node);
                        }
                        if (in_array($common_ancestor->tagName, array('table', 'tbody', 'tfoot', 'thead', 'tr'))) {
                            $this->foster_parent->appendChild($last_node);
                            // XXX: mark table tainted
                        /* Otherwise, append whatever last node  ended up being
                         * in the previous step to the common ancestor node,
                         * first removing it from its previous parent node if
                         * any. */
                        } else {
                            $common_ancestor->appendChild($last_node);
                        }

                        /* 8. Create an element for the token for which the
                         * formatting element was created. */
                        $clone = $formatting_element->cloneNode();

                        /* 9. Take all of the child nodes of the furthest
                        block and append them to the element created in the
                        last step. */
                        while($furthest_block->hasChildNodes()) {
                            $child = $furthest_block->firstChild;
                            $furthest_block->removeChild($child);
                            $clone->appendChild($child);
                        }

                        /* 10. Append that clone to the furthest block. */
                        $furthest_block->appendChild($clone);

                        /* 11. Remove the formatting element from the list
                        of active formatting elements, and insert the new element
                        into the list of active formatting elements at the
                        position of the aforementioned bookmark. */
                        $fe_af_pos = array_search($formatting_element, $this->a_formatting, true);
                        unset($this->a_formatting[$fe_af_pos]);
                        $this->a_formatting = array_merge($this->a_formatting);

                        $af_part1 = array_slice($this->a_formatting, 0, $bookmark - 1);
                        $af_part2 = array_slice($this->a_formatting, $bookmark, count($this->a_formatting));
                        $this->a_formatting = array_merge($af_part1, array($clone), $af_part2);

                        /* 12. Remove the formatting element from the stack
                        of open elements, and insert the new element into the stack
                        of open elements immediately below the position of the
                        furthest block in that stack. */
                        $fe_s_pos = array_search($formatting_element, $this->stack, true);
                        $fb_s_pos = array_search($furthest_block, $this->stack, true);
                        unset($this->stack[$fe_s_pos]);

                        $s_part1 = array_slice($this->stack, 0, $fb_s_pos);
                        $s_part2 = array_slice($this->stack, $fb_s_pos + 1, count($this->stack));
                        $this->stack = array_merge($s_part1, array($clone), $s_part2);

                        /* 13. Jump back to step 1 in this series of steps. */
                        unset($formatting_element, $fe_af_pos, $fe_s_pos, $furthest_block);
                    }
                break;

                case 'applet': case 'button': case 'marquee': case 'object':
                    /* If the stack of open elements has an element in scope whose
                    tag name matches the tag name of the token, then generate implied
                    tags. */
                    if($this->elementInScope($token['name'])) {
                        $this->generateImpliedEndTags();

                        /* Now, if the current node is not an element with the same
                        tag name as the token, then this is a parse error. */
                        // XXX: implement logic

                        /* Pop elements from the stack of open elements  until
                         * an element with the same tag name as the token has
                         * been popped from the stack. */
                        do {
                            $node = array_pop($this->stack);
                        } while ($node->tagName !== $token['name']);

                        /* Clear the list of active formatting elements up to the
                         * last marker. */
                        $marker = end(array_keys($this->a_formatting, self::MARKER, true));

                        for($n = count($this->a_formatting) - 1; $n > $marker; $n--) {
                            array_pop($this->a_formatting);
                        }
                    } else {
                        // parse error
                    }
                break;

                case 'br':
                    // Parse error
                    $this->emitToken(array(
                        'name' => 'br',
                        'type' => HTML5_Tokenizer::STARTTAG,
                    ));
                break;

                /* An end tag token not covered by the previous entries */
                default:
                    for($n = count($this->stack) - 1; $n >= 0; $n--) {
                        /* Initialise node to be the current node (the bottommost
                        node of the stack). */
                        $node = end($this->stack);

                        /* If node has the same tag name as the end tag token,
                        then: */
                        if($token['name'] === $node->nodeName) {
                            /* Generate implied end tags. */
                            $this->generateImpliedEndTags();

                            /* If the tag name of the end tag token does not
                            match the tag name of the current node, this is a
                            parse error. */
                            // XXX: implement this

                            /* Pop all the nodes from the current node up to
                            node, including node, then stop these steps. */
                            for($x = count($this->stack) - $n; $x >= $n; $x--) {
                                array_pop($this->stack);
                            }

                        } else {
                            $category = $this->getElementCategory($node);

                            if($category !== self::SPECIAL && $category !== self::SCOPING) {
                                /* Otherwise, if node is in neither the formatting
                                category nor the phrasing category, then this is a
                                parse error. Stop this algorithm. The end tag token
                                is ignored. */
                                // parse error
                            }
                        }
                        /* Set node to the previous entry in the stack of open elements. Loop. */
                    }
                break;
            }
            break;
        }
        break;

    case self::IN_CDATA_RCDATA:
        if ($token['type'] === HTML5_Tokenizer::CHARACTER) {
            $this->insertText($token['data']);
        } elseif ($token['type'] === HTML5_Tokenizer::EOF) {
            // parse error
            /* If the current node is a script  element, mark the script
             * element as "already executed". */
            // probably not necessary
            array_pop($this->stack);
            $this->mode = $this->original_mode;
            $this->emitToken($token);
        } elseif ($token['type'] === HTML5_Tokenizer::ENDTAG && $token['name'] === 'script') {
            array_pop($this->stack);
            $this->mode = $this->original_mode;
            // we're ignoring all of the execution stuff
        } elseif ($token['type'] === HTML5_Tokenizer::ENDTAG) {
            array_pop($this->stack);
            $this->mode = $this->original_mode;
        }
    break;

    case self::IN_TABLE:
        $clear = array('html', 'table');

        /* A character token that is one of one of U+0009 CHARACTER TABULATION,
        U+000A LINE FEED (LF), U+000B LINE TABULATION, U+000C FORM FEED (FF),
        or U+0020 SPACE */
        if($token['type'] === HTML5_Tokenizer::CHARACTER &&
        preg_match('/^[\t\n\x0b\x0c ]+$/', $token['data'])) {
            /* If the current table is tainted, then act as described in
             * the "anything else" entry below. */
            // XXX: Not clear how to transfer this information
            /* Append the character to the current node. */
            $this->insertText($token['data']);

        /* A comment token */
        } elseif($token['type'] === HTML5_Tokenizer::COMMENT) {
            /* Append a Comment node to the current node with the data
            attribute set to the data given in the comment token. */
            $this->insertComment($token['data']);

        } elseif($token['type'] === HTML5_Tokenizer::DOCTYPE) {
            // parse error

        /* A start tag whose tag name is "caption" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG &&
        $token['name'] === 'caption') {
            /* Clear the stack back to a table context. */
            $this->clearStackToTableContext($clear);

            /* Insert a marker at the end of the list of active
            formatting elements. */
            $this->a_formatting[] = self::MARKER;

            /* Insert an HTML element for the token, then switch the
            insertion mode to "in caption". */
            $this->insertElement($token);
            $this->mode = self::IN_CAPTION;

        /* A start tag whose tag name is "colgroup" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG &&
        $token['name'] === 'colgroup') {
            /* Clear the stack back to a table context. */
            $this->clearStackToTableContext($clear);

            /* Insert an HTML element for the token, then switch the
            insertion mode to "in column group". */
            $this->insertElement($token);
            $this->mode = self::IN_COLUMN_GROUP;

        /* A start tag whose tag name is "col" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG &&
        $token['name'] === 'col') {
            $this->emitToken(array(
                'name' => 'colgroup',
                'type' => HTML5_Tokenizer::STARTTAG,
                'attr' => array()
            ));

            return $this->emitToken($token);

        /* A start tag whose tag name is one of: "tbody", "tfoot", "thead" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && in_array($token['name'],
        array('tbody', 'tfoot', 'thead'))) {
            /* Clear the stack back to a table context. */
            $this->clearStackToTableContext($clear);

            /* Insert an HTML element for the token, then switch the insertion
            mode to "in table body". */
            $this->insertElement($token);
            $this->mode = self::IN_TABLE_BODY;

        /* A start tag whose tag name is one of: "td", "th", "tr" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG &&
        in_array($token['name'], array('td', 'th', 'tr'))) {
            /* Act as if a start tag token with the tag name "tbody" had been
            seen, then reprocess the current token. */
            $this->emitToken(array(
                'name' => 'tbody',
                'type' => HTML5_Tokenizer::STARTTAG,
                'attr' => array()
            ));

            return $this->emitToken($token);

        /* A start tag whose tag name is "table" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG &&
        $token['name'] === 'table') {
            /* Parse error. Act as if an end tag token with the tag name "table"
            had been seen, then, if that token wasn't ignored, reprocess the
            current token. */
            $this->emitToken(array(
                'name' => 'table',
                'type' => HTML5_Tokenizer::ENDTAG
            ));

            //return $this->emitToken($token);

        /* An end tag whose tag name is "table" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG &&
        $token['name'] === 'table') {
            /* If the stack of open elements does not have an element in table
            scope with the same tag name as the token, this is a parse error.
            Ignore the token. (fragment case) */
            if(!$this->elementInScope($token['name'], true)) {
                return false;

            /* Otherwise: */
            } else {
                do {
                    $node = array_pop($this->stack);
                } while ($node->tagName !== 'table');

                /* Reset the insertion mode appropriately. */
                $this->resetInsertionMode();
            }

        /* An end tag whose tag name is one of: "body", "caption", "col",
        "colgroup", "html", "tbody", "td", "tfoot", "th", "thead", "tr" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG && in_array($token['name'],
        array('body', 'caption', 'col', 'colgroup', 'html', 'tbody', 'td',
        'tfoot', 'th', 'thead', 'tr'))) {
            // Parse error. Ignore the token.

        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG &&
        ($token['name'] === 'style' || $token['name'] === 'script')) {
            $this->processWithRulesFor($token, self::IN_HEAD);

        } elseif ($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'input') {
            /* If the token does not have an attribute with the name "type", or
             * if it does, but that attribute's value is not an ASCII
             * case-insensitive match for the string "hidden", then: act as
             * described in the "anything else" entry below. */
            // XXX: implement this
            /* Otherwise */
            // parse error
            $this->insertElement($token);
            array_pop($this->stack);
        } elseif ($token['type'] === HTML5_Tokenizer::EOF) {
            /* If the current node is not the root html element, then this is a parse error. */
            if (end($this->stack)->tagName !== 'html') {
                // Note: It can only be the current node in the fragment case.
                // parse error
            }
            /* Stop parsing. */
        /* Anything else */
        } else {
            /* Parse error. Process the token as if the insertion mode was "in
            body", with the following exception: */

            // XXX: This is probably wrong
            /* If the current node is a table, tbody, tfoot, thead, or tr
            element, then, whenever a node would be inserted into the current
            node, it must instead be inserted into the foster parent element. */
            if(in_array(end($this->stack)->nodeName,
            array('table', 'tbody', 'tfoot', 'thead', 'tr'))) {
                /* The foster parent element is the parent element of the last
                table element in the stack of open elements, if there is a
                table element and it has such a parent element. If there is no
                table element in the stack of open elements (innerHTML case),
                then the foster parent element is the first element in the
                stack of open elements (the html  element). Otherwise, if there
                is a table element in the stack of open elements, but the last
                table element in the stack of open elements has no parent, or
                its parent node is not an element, then the foster parent
                element is the element before the last table element in the
                stack of open elements. */
                for($n = count($this->stack) - 1; $n >= 0; $n--) {
                    if($this->stack[$n]->nodeName === 'table') {
                        $table = $this->stack[$n];
                        break;
                    }
                }

                if(isset($table) && $table->parentNode !== null) {
                    $this->foster_parent = $table->parentNode;

                } elseif(!isset($table)) {
                    $this->foster_parent = $this->stack[0];

                } elseif(isset($table) && ($table->parentNode === null ||
                $table->parentNode->nodeType !== XML_ELEMENT_NODE)) {
                    $this->foster_parent = $this->stack[$n - 1];
                }
            }

            $this->processWithRulesFor($token, self::IN_BODY);
        }
    break;

    case self::IN_CAPTION:
        /* An end tag whose tag name is "caption" */
        if($token['type'] === HTML5_Tokenizer::ENDTAG && $token['name'] === 'caption') {
            /* If the stack of open elements does not have an element in table
            scope with the same tag name as the token, this is a parse error.
            Ignore the token. (fragment case) */
            if(!$this->elementInScope($token['name'], true)) {
                // Ignore

            /* Otherwise: */
            } else {
                /* Generate implied end tags. */
                $this->generateImpliedEndTags();

                /* Now, if the current node is not a caption element, then this
                is a parse error. */
                // XXX: implement

                /* Pop elements from this stack until a caption element has
                been popped from the stack. */
                do {
                    $node = array_pop($this->stack);
                } while ($node->tagName !== 'caption');

                /* Clear the list of active formatting elements up to the last
                marker. */
                $this->clearTheActiveFormattingElementsUpToTheLastMarker();

                /* Switch the insertion mode to "in table". */
                $this->mode = self::IN_TABLE;
            }

        /* A start tag whose tag name is one of: "caption", "col", "colgroup",
        "tbody", "td", "tfoot", "th", "thead", "tr", or an end tag whose tag
        name is "table" */
        } elseif(($token['type'] === HTML5_Tokenizer::STARTTAG && in_array($token['name'],
        array('caption', 'col', 'colgroup', 'tbody', 'td', 'tfoot', 'th',
        'thead', 'tr'))) || ($token['type'] === HTML5_Tokenizer::ENDTAG &&
        $token['name'] === 'table')) {
            /* Parse error. Act as if an end tag with the tag name "caption"
            had been seen, then, if that token wasn't ignored, reprocess the
            current token. */
            $this->emitToken(array(
                'name' => 'caption',
                'type' => HTML5_Tokenizer::ENDTAG
            ));

            // XXX: Not sure how to check for this
            //return $this->emitToken($token);

        /* An end tag whose tag name is one of: "body", "col", "colgroup",
        "html", "tbody", "td", "tfoot", "th", "thead", "tr" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG && in_array($token['name'],
        array('body', 'col', 'colgroup', 'html', 'tbody', 'tfoot', 'th',
        'thead', 'tr'))) {
            // Parse error. Ignore the token.

        /* Anything else */
        } else {
            /* Process the token as if the insertion mode was "in body". */
            $this->processWithRulesFor($token, self::IN_BODY);
        }
    break;

    case self::IN_COLUMN_GROUP:
        /* A character token that is one of one of U+0009 CHARACTER TABULATION,
        U+000A LINE FEED (LF), U+000B LINE TABULATION, U+000C FORM FEED (FF),
        or U+0020 SPACE */
        if($token['type'] === HTML5_Tokenizer::CHARACTER &&
        preg_match('/^[\t\n\x0b\x0c ]+$/', $token['data'])) {
            /* Append the character to the current node. */
            $this->insertText($token['data']);

        /* A comment token */
        } elseif($token['type'] === HTML5_Tokenizer::COMMENT) {
            /* Append a Comment node to the current node with the data
            attribute set to the data given in the comment token. */
            $this->insertToken($token['data']);

        } elseif($token['type'] === HTML5_Tokenizer::DOCTYPE) {
            // parse error

        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'html') {
            $this->processWithRulesFor($token, self::IN_BODY);

        /* A start tag whose tag name is "col" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'col') {
            /* Insert a col element for the token. Immediately pop the current
            node off the stack of open elements. */
            $this->insertElement($token);
            array_pop($this->stack);
            // XXX: Acknowledge the token's self-closing flag, if it is set.

        /* An end tag whose tag name is "colgroup" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG &&
        $token['name'] === 'colgroup') {
            /* If the current node is the root html element, then this is a
            parse error, ignore the token. (fragment case) */
            if(end($this->stack)->nodeName === 'html') {
                // Ignore

            /* Otherwise, pop the current node (which will be a colgroup
            element) from the stack of open elements. Switch the insertion
            mode to "in table". */
            } else {
                array_pop($this->stack);
                $this->mode = self::IN_TABLE;
            }

        /* An end tag whose tag name is "col" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG && $token['name'] === 'col') {
            /* Parse error. Ignore the token. */

        /* An end-of-file token */
        /* If the current node is the root html  element */
        } elseif($token['type'] === HTML5_Tokenizer::EOF && end($this->stack)->tagName === 'html') {
            /* Stop parsing */

        /* Anything else */
        } else {
            /* Act as if an end tag with the tag name "colgroup" had been seen,
            and then, if that token wasn't ignored, reprocess the current token. */
            $this->emitToken(array(
                'name' => 'colgroup',
                'type' => HTML5_Tokenizer::ENDTAG
            ));

            //return $this->emitToken($token);
        }
    break;

    case self::IN_TABLE_BODY:
        $clear = array('tbody', 'tfoot', 'thead', 'html');

        /* A start tag whose tag name is "tr" */
        if($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'tr') {
            /* Clear the stack back to a table body context. */
            $this->clearStackToTableContext($clear);

            /* Insert a tr element for the token, then switch the insertion
            mode to "in row". */
            $this->insertElement($token);
            $this->mode = self::IN_ROW;

        /* A start tag whose tag name is one of: "th", "td" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG &&
        ($token['name'] === 'th' ||    $token['name'] === 'td')) {
            /* Parse error. Act as if a start tag with the tag name "tr" had
            been seen, then reprocess the current token. */
            $this->emitToken(array(
                'name' => 'tr',
                'type' => HTML5_Tokenizer::STARTTAG,
                'attr' => array()
            ));

            return $this->emitToken($token);

        /* An end tag whose tag name is one of: "tbody", "tfoot", "thead" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG &&
        in_array($token['name'], array('tbody', 'tfoot', 'thead'))) {
            /* If the stack of open elements does not have an element in table
            scope with the same tag name as the token, this is a parse error.
            Ignore the token. */
            if(!$this->elementInScope($token['name'], true)) {
                // Parse error

            /* Otherwise: */
            } else {
                /* Clear the stack back to a table body context. */
                $this->clearStackToTableContext($clear);

                /* Pop the current node from the stack of open elements. Switch
                the insertion mode to "in table". */
                array_pop($this->stack);
                $this->mode = self::IN_TABLE;
            }

        /* A start tag whose tag name is one of: "caption", "col", "colgroup",
        "tbody", "tfoot", "thead", or an end tag whose tag name is "table" */
        } elseif(($token['type'] === HTML5_Tokenizer::STARTTAG && in_array($token['name'],
        array('caption', 'col', 'colgroup', 'tbody', 'tfoot', 'thead'))) ||
        ($token['type'] === HTML5_Tokenizer::ENDTAG && $token['name'] === 'table')) {
            /* If the stack of open elements does not have a tbody, thead, or
            tfoot element in table scope, this is a parse error. Ignore the
            token. (fragkment case) */
            if(!$this->elementInScope(array('tbody', 'thead', 'tfoot'), true)) {
                // parse error

            /* Otherwise: */
            } else {
                /* Clear the stack back to a table body context. */
                $this->clearStackToTableContext($clear);

                /* Act as if an end tag with the same tag name as the current
                node ("tbody", "tfoot", or "thead") had been seen, then
                reprocess the current token. */
                $this->emitToken(array(
                    'name' => end($this->stack)->nodeName,
                    'type' => HTML5_Tokenizer::ENDTAG
                ));

                return $this->emitToken($token);
            }

        /* An end tag whose tag name is one of: "body", "caption", "col",
        "colgroup", "html", "td", "th", "tr" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG && in_array($token['name'],
        array('body', 'caption', 'col', 'colgroup', 'html', 'td', 'th', 'tr'))) {
            /* Parse error. Ignore the token. */

        /* Anything else */
        } else {
            /* Process the token as if the insertion mode was "in table". */
            $this->processWithRulesFor($token, self::IN_TABLE);
        }
    break;

    case self::IN_ROW:
        $clear = array('tr', 'html');

        /* A start tag whose tag name is one of: "th", "td" */
        if($token['type'] === HTML5_Tokenizer::STARTTAG &&
        ($token['name'] === 'th' || $token['name'] === 'td')) {
            /* Clear the stack back to a table row context. */
            $this->clearStackToTableContext($clear);

            /* Insert an HTML element for the token, then switch the insertion
            mode to "in cell". */
            $this->insertElement($token);
            $this->mode = self::IN_CELL;

            /* Insert a marker at the end of the list of active formatting
            elements. */
            $this->a_formatting[] = self::MARKER;

        /* An end tag whose tag name is "tr" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG && $token['name'] === 'tr') {
            /* If the stack of open elements does not have an element in table
            scope with the same tag name as the token, this is a parse error.
            Ignore the token. (fragment case) */
            if(!$this->elementInScope($token['name'], true)) {
                // Ignore.

            /* Otherwise: */
            } else {
                /* Clear the stack back to a table row context. */
                $this->clearStackToTableContext($clear);

                /* Pop the current node (which will be a tr element) from the
                stack of open elements. Switch the insertion mode to "in table
                body". */
                array_pop($this->stack);
                $this->mode = self::IN_TABLE_BODY;
            }

        /* A start tag whose tag name is one of: "caption", "col", "colgroup",
        "tbody", "tfoot", "thead", "tr" or an end tag whose tag name is "table" */
        } elseif(($token['type'] === HTML5_Tokenizer::STARTTAG && in_array($token['name'],
        array('caption', 'col', 'colgroup', 'tbody', 'tfoot', 'thead', 'tr'))) ||
        ($token['type'] === HTML5_Tokenizer::ENDTAG && $token['name'] === 'table')) {
            /* Act as if an end tag with the tag name "tr" had been seen, then,
            if that token wasn't ignored, reprocess the current token. */
            $this->emitToken(array(
                'name' => 'tr',
                'type' => HTML5_Tokenizer::ENDTAG
            ));
            // XXX: if that token wasn't ignored

            //return $this->emitToken($token);

        /* An end tag whose tag name is one of: "tbody", "tfoot", "thead" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG &&
        in_array($token['name'], array('tbody', 'tfoot', 'thead'))) {
            /* If the stack of open elements does not have an element in table
            scope with the same tag name as the token, this is a parse error.
            Ignore the token. */
            if(!$this->elementInScope($token['name'], true)) {
                // Ignore.

            /* Otherwise: */
            } else {
                /* Otherwise, act as if an end tag with the tag name "tr" had
                been seen, then reprocess the current token. */
                $this->emitToken(array(
                    'name' => 'tr',
                    'type' => HTML5_Tokenizer::ENDTAG
                ));

                return $this->emitToken($token);
            }

        /* An end tag whose tag name is one of: "body", "caption", "col",
        "colgroup", "html", "td", "th" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG && in_array($token['name'],
        array('body', 'caption', 'col', 'colgroup', 'html', 'td', 'th'))) {
            /* Parse error. Ignore the token. */

        /* Anything else */
        } else {
            /* Process the token as if the insertion mode was "in table". */
            $this->processWithRulesFor($token, self::IN_TABLE);
        }
    break;

    case self::IN_CELL:
        /* An end tag whose tag name is one of: "td", "th" */
        if($token['type'] === HTML5_Tokenizer::ENDTAG &&
        ($token['name'] === 'td' || $token['name'] === 'th')) {
            /* If the stack of open elements does not have an element in table
            scope with the same tag name as that of the token, then this is a
            parse error and the token must be ignored. */
            if(!$this->elementInScope($token['name'], true)) {
                // Ignore.

            /* Otherwise: */
            } else {
                /* Generate implied end tags, except for elements with the same
                tag name as the token. */
                $this->generateImpliedEndTags(array($token['name']));

                /* Now, if the current node is not an element with the same tag
                name as the token, then this is a parse error. */
                // XXX: Implement parse error code

                /* Pop elements from this stack until an element with the same
                tag name as the token has been popped from the stack. */
                do {
                    $node = array_pop($this->stack);
                } while ($node->tagName !== $token['name']);

                /* Clear the list of active formatting elements up to the last
                marker. */
                $this->clearTheActiveFormattingElementsUpToTheLastMarker();

                /* Switch the insertion mode to "in row". (The current node
                will be a tr element at this point.) */
                $this->mode = self::IN_ROW;
            }

        /* A start tag whose tag name is one of: "caption", "col", "colgroup",
        "tbody", "td", "tfoot", "th", "thead", "tr" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && in_array($token['name'],
        array('caption', 'col', 'colgroup', 'tbody', 'td', 'tfoot', 'th',
        'thead', 'tr'))) {
            /* If the stack of open elements does not have a td or th element
            in table scope, then this is a parse error; ignore the token.
            (fragment case) */
            if(!$this->elementInScope(array('td', 'th'), true)) {
                // parse error

            /* Otherwise, close the cell (see below) and reprocess the current
            token. */
            } else {
                $this->closeCell();
                return $this->emitToken($token);
            }

        /* An end tag whose tag name is one of: "body", "caption", "col",
        "colgroup", "html" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG && in_array($token['name'],
        array('body', 'caption', 'col', 'colgroup', 'html'))) {
            /* Parse error. Ignore the token. */

        /* An end tag whose tag name is one of: "table", "tbody", "tfoot",
        "thead", "tr" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG && in_array($token['name'],
        array('table', 'tbody', 'tfoot', 'thead', 'tr'))) {
            /* If the stack of open elements does not have a td or th element
            in table scope, then this is a parse error; ignore the token.
            (innerHTML case) */
            if(!$this->elementInScope(array('td', 'th'), true)) {
                // Parse error

            /* Otherwise, close the cell (see below) and reprocess the current
            token. */
            } else {
                $this->closeCell();
                return $this->emitToken($token);
            }

        /* Anything else */
        } else {
            /* Process the token as if the insertion mode was "in body". */
            $this->processWithRulesFor($token, self::IN_BODY);
        }
    break;

    case self::IN_SELECT:
        /* Handle the token as follows: */

        /* A character token */
        if($token['type'] === HTML5_Tokenizer::CHARACTER) {
            /* Append the token's character to the current node. */
            $this->insertText($token['data']);

        /* A comment token */
        } elseif($token['type'] === HTML5_Tokenizer::COMMENT) {
            /* Append a Comment node to the current node with the data
            attribute set to the data given in the comment token. */
            $this->insertComment($token['data']);

        } elseif($token['type'] === HTML5_Tokenizer::DOCTYPE) {
            // parse error

        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'html') {
            $this->processWithRulesFor($token, self::INBODY);

        /* A start tag token whose tag name is "option" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG &&
        $token['name'] === 'option') {
            /* If the current node is an option element, act as if an end tag
            with the tag name "option" had been seen. */
            if(end($this->stack)->nodeName === 'option') {
                $this->emitToken(array(
                    'name' => 'option',
                    'type' => HTML5_Tokenizer::ENDTAG
                ));
            }

            /* Insert an HTML element for the token. */
            $this->insertElement($token);

        /* A start tag token whose tag name is "optgroup" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG &&
        $token['name'] === 'optgroup') {
            /* If the current node is an option element, act as if an end tag
            with the tag name "option" had been seen. */
            if(end($this->stack)->nodeName === 'option') {
                $this->emitToken(array(
                    'name' => 'option',
                    'type' => HTML5_Tokenizer::ENDTAG
                ));
            }

            /* If the current node is an optgroup element, act as if an end tag
            with the tag name "optgroup" had been seen. */
            if(end($this->stack)->nodeName === 'optgroup') {
                $this->emitToken(array(
                    'name' => 'optgroup',
                    'type' => HTML5_Tokenizer::ENDTAG
                ));
            }

            /* Insert an HTML element for the token. */
            $this->insertElement($token);

        /* An end tag token whose tag name is "optgroup" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG &&
        $token['name'] === 'optgroup') {
            /* First, if the current node is an option element, and the node
            immediately before it in the stack of open elements is an optgroup
            element, then act as if an end tag with the tag name "option" had
            been seen. */
            $elements_in_stack = count($this->stack);

            if($this->stack[$elements_in_stack - 1]->nodeName === 'option' &&
            $this->stack[$elements_in_stack - 2]->nodeName === 'optgroup') {
                $this->emitToken(array(
                    'name' => 'option',
                    'type' => HTML5_Tokenizer::ENDTAG
                ));
            }

            /* If the current node is an optgroup element, then pop that node
            from the stack of open elements. Otherwise, this is a parse error,
            ignore the token. */
            if($this->stack[$elements_in_stack - 1] === 'optgroup') {
                array_pop($this->stack);
            } else {
                // parse error
            }

        /* An end tag token whose tag name is "option" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG &&
        $token['name'] === 'option') {
            /* If the current node is an option element, then pop that node
            from the stack of open elements. Otherwise, this is a parse error,
            ignore the token. */
            if(end($this->stack)->nodeName === 'option') {
                array_pop($this->stack);
            } else {
                // parse error
            }

        /* An end tag whose tag name is "select" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG &&
        $token['name'] === 'select') {
            /* If the stack of open elements does not have an element in table
            scope with the same tag name as the token, this is a parse error.
            Ignore the token. (fragment case) */
            if(!$this->elementInScope($token['name'], true)) {
                // parse error

            /* Otherwise: */
            } else {
                /* Pop elements from the stack of open elements until a select
                element has been popped from the stack. */
                do {
                    $node = array_pop($this->stack);
                } while ($node->tagName !== 'select');

                /* Reset the insertion mode appropriately. */
                $this->resetInsertionMode();
            }

        /* A start tag whose tag name is "select" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'select') {
            /* Parse error. Act as if the token had been an end tag with the
            tag name "select" instead. */
            $this->emitToken(array(
                'name' => 'select',
                'type' => HTML5_Tokenizer::ENDTAG
            ));

        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG &&
        ($token['name'] === 'input' || $token['name'] === 'textarea')) {
            // parse error
            $this->emitToken(array(
                'name' => 'select',
                'type' => HTML5_Tokenizer::ENDTAG
            ));
            $this->emitToken($token);

        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'script') {
            $this->processWithRulesFor($token, self::IN_HEAD);

        } elseif($token['type'] === HTML5_Tokenizer::EOF) {
            // XXX: If the current node is not the root html element, then this is a parse error.
            /* Stop parsing */

        /* Anything else */
        } else {
            /* Parse error. Ignore the token. */
        }
    break;

    case self::IN_SELECT_IN_TABLE:

        if(in_array($token['name'], array('caption', 'table', 'tbody',
        'tfoot', 'thead', 'tr', 'td', 'th')) && $token['type'] === HTML5_Tokenizer::STARTTAG) {
            // parse error
            $this->emitToken(array(
                'name' => 'select',
                'type' => HTML5_Tokenizer::ENDTAG,
            ));
            $this->emitToken($token);

        /* An end tag whose tag name is one of: "caption", "table", "tbody",
        "tfoot", "thead", "tr", "td", "th" */
        } elseif(in_array($token['name'], array('caption', 'table', 'tbody',
        'tfoot', 'thead', 'tr', 'td', 'th')) && $token['type'] === HTML5_Tokenizer::ENDTAG) {
            /* Parse error. */
            // parse error

            /* If the stack of open elements has an element in table scope with
            the same tag name as that of the token, then act as if an end tag
            with the tag name "select" had been seen, and reprocess the token.
            Otherwise, ignore the token. */
            if($this->elementInScope($token['name'], true)) {
                $this->emitToken(array(
                    'name' => 'select',
                    'type' => HTML5_Tokenizer::ENDTAG
                ));

                // XXX
                //$this->emitToken($token);
            }
        } else {
            $this->processWithRulesFor($token, self::IN_SELECT);
        }
    break;

    case self::IN_FOREIGN_CONTENT:
        // XXX: not implemented
    break;

    case self::AFTER_BODY:
        /* Handle the token as follows: */

        /* A character token that is one of one of U+0009 CHARACTER TABULATION,
        U+000A LINE FEED (LF), U+000B LINE TABULATION, U+000C FORM FEED (FF),
        or U+0020 SPACE */
        if($token['type'] === HTML5_Tokenizer::CHARACTER &&
        preg_match('/^[\t\n\x0b\x0c ]+$/', $token['data'])) {
            /* Process the token as it would be processed if the insertion mode
            was "in body". */
            $this->processWithRulesFor($token, self::IN_BODY);

        /* A comment token */
        } elseif($token['type'] === HTML5_Tokenizer::COMMENT) {
            /* Append a Comment node to the first element in the stack of open
            elements (the html element), with the data attribute set to the
            data given in the comment token. */
            $comment = $this->dom->createComment($token['data']);
            $this->stack[0]->appendChild($comment);

        } elseif($token['type'] === HTML5_Tokenizer::DOCTYPE) {
            // parse error

        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'html') {
            $this->processWithRulesFor($token, self::IN_BODY);

        /* An end tag with the tag name "html" */
        } elseif($token['type'] === HTML5_Tokenizer::ENDTAG && $token['name'] === 'html') {
            /*     If the parser was originally created as part of the HTML
             *     fragment parsing algorithm, this is a parse error; ignore
             *     the token. (fragment case) */
            // XXX: implement this

            $this->mode = self::AFTER_AFTER_BODY;

        } elseif($token['type'] === HTML5_Tokenizer::EOF) {
            /* Stop parsing */

        /* Anything else */
        } else {
            /* Parse error. Set the insertion mode to "in body" and reprocess
            the token. */
            $this->mode = self::IN_BODY;
            return $this->emitToken($token);
        }
    break;

    case self::IN_FRAMESET:
        /* Handle the token as follows: */

        /* A character token that is one of one of U+0009 CHARACTER TABULATION,
        U+000A LINE FEED (LF), U+000B LINE TABULATION, U+000C FORM FEED (FF),
        U+000D CARRIAGE RETURN (CR), or U+0020 SPACE */
        if($token['type'] === HTML5_Tokenizer::CHARACTER &&
        preg_match('/^[\t\n\x0b\x0c ]+$/', $token['data'])) {
            /* Append the character to the current node. */
            $this->insertText($token['data']);

        /* A comment token */
        } elseif($token['type'] === HTML5_Tokenizer::COMMENT) {
            /* Append a Comment node to the current node with the data
            attribute set to the data given in the comment token. */
            $this->insertComment($token['data']);

        } elseif($token['type'] === HTML5_Tokenizer::DOCTYPE) {
            // parse error

        /* A start tag with the tag name "frameset" */
        } elseif($token['name'] === 'frameset' &&
        $token['type'] === HTML5_Tokenizer::STARTTAG) {
            $this->insertElement($token);

        /* An end tag with the tag name "frameset" */
        } elseif($token['name'] === 'frameset' &&
        $token['type'] === HTML5_Tokenizer::ENDTAG) {
            /* If the current node is the root html element, then this is a
            parse error; ignore the token. (fragment case) */
            if(end($this->stack)->nodeName === 'html') {
                // Parse error

            } else {
                /* Otherwise, pop the current node from the stack of open
                elements. */
                array_pop($this->stack);

                /* If the parser was not originally created as part of the HTML 
                 * fragment parsing algorithm  (fragment case), and the current 
                 * node is no longer a frameset element, then switch the 
                 * insertion mode to "after frameset". */
                $this->mode = self::AFTER_FRAMESET;
            }

        /* A start tag with the tag name "frame" */
        } elseif($token['name'] === 'frame' &&
        $token['type'] === HTML5_Tokenizer::STARTTAG) {
            /* Insert an HTML element for the token. */
            $this->insertElement($token);

            /* Immediately pop the current node off the stack of open elements. */
            array_pop($this->stack);

            // XXX: Acknowledge the token's self-closing flag, if it is set.

        /* A start tag with the tag name "noframes" */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG &&
        $token['name'] === 'noframes') {
            /* Process the token using the rules for the "in head" insertion mode. */
            $this->processwithRulesFor($token, self::IN_HEAD);

        } elseif($token['type'] === HTML5_Tokenizer::EOF) {
            // XXX: If the current node is not the root html element, then this is a parse error.
            /* Stop parsing */
        /* Anything else */
        } else {
            /* Parse error. Ignore the token. */
        }
    break;

    case self::AFTER_FRAMESET:
        /* Handle the token as follows: */

        /* A character token that is one of one of U+0009 CHARACTER TABULATION,
        U+000A LINE FEED (LF), U+000B LINE TABULATION, U+000C FORM FEED (FF),
        U+000D CARRIAGE RETURN (CR), or U+0020 SPACE */
        if($token['type'] === HTML5_Tokenizer::CHARACTER &&
        preg_match('/^[\t\n\x0b\x0c ]+$/', $token['data'])) {
            /* Append the character to the current node. */
            $this->insertText($token['data']);

        /* A comment token */
        } elseif($token['type'] === HTML5_Tokenizer::COMMENT) {
            /* Append a Comment node to the current node with the data
            attribute set to the data given in the comment token. */
            $this->insertComment($token['data']);

        } elseif($token['type'] === HTML5_Tokenizer::DOCTYPE) {
            // parse error

        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'html') {
            $this->processWithRulesFor($token, self::IN_BODY);

        /* An end tag with the tag name "html" */
        } elseif($token['name'] === 'html' &&
        $token['type'] === HTML5_Tokenizer::ENDTAG) {
            $this->mode = self::AFTER_AFTER_FRAMESET;

        /* A start tag with the tag name "noframes" */
        } elseif($token['name'] === 'noframes' &&
        $token['type'] === HTML5_Tokenizer::STARTTAG) {
            $this->processWithRulesFor($token, self::IN_HEAD);

        } elseif($token['type'] === HTML5_Tokenizer::EOF) {
            /* Stop parsing */

        /* Anything else */
        } else {
            /* Parse error. Ignore the token. */
        }
    break;

    case self::AFTER_AFTER_BODY:
        /* A comment token */
        if($token['type'] === HTML5_Tokenizer::COMMENT) {
            /* Append a Comment node to the Document object with the data
            attribute set to the data given in the comment token. */
            $comment = $this->dom->createComment($token['data']);
            $this->dom->appendChild($comment);

        } elseif($token['type'] === HTML5_Tokenizer::DOCTYPE ||
        ($token['type'] === HTML5_Tokenizer::CHARACTER &&
        preg_match('/^[\t\n\x0b\x0c ]+$/', $token['data']) ||
        ($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'html'))) {
            $this->processWithRulesFor($token, self::IN_BODY);

        /* An end-of-file token */
        } elseif($token['type'] === HTML5_Tokenizer::EOF) {
            /* OMG DONE!! */
        } else {
            // parse error
            $this->mode = self::IN_BODY;
            $this->emitToken($token);
        }
    break;

    case self::AFTER_AFTER_FRAMESET:
        /* A comment token */
        if($token['type'] === HTML5_Tokenizer::COMMENT) {
            /* Append a Comment node to the Document object with the data
            attribute set to the data given in the comment token. */
            $comment = $this->dom->createComment($token['data']);
            $this->dom->appendChild($comment);

        } elseif($token['type'] === HTML5_Tokenizer::DOCTYPE ||
        ($token['type'] === HTML5_Tokenizer::CHARACTER &&
        preg_match('/^[\t\n\x0b\x0c ]+$/', $token['data']) ||
        ($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'html'))) {
            $this->processWithRulesFor($token, self::IN_BODY);

        /* An end-of-file token */
        } elseif($token['type'] === HTML5_Tokenizer::EOF) {
            /* OMG DONE!! */
        } elseif($token['type'] === HTML5_Tokenizer::STARTTAG && $token['name'] === 'nofrmaes') {
            $this->processWithRulesFor($token, self::IN_HEAD);
        } else {
            // parse error
        }
    break;
    }
        // end funky indenting
        }

    private function insertElement($token, $append = true) {
        $el = $this->dom->createElement($token['name']);

        foreach($token['attr'] as $attr) {
            if(!$el->hasAttribute($attr['name'])) {
                $el->setAttribute($attr['name'], $attr['value']);
            }
        }

        $this->appendToRealParent($el);
        $this->stack[] = $el;

        return $el;
    }

    private function insertText($data) {
        $text = $this->dom->createTextNode($data);
        $this->appendToRealParent($text);
    }

    private function insertComment($data) {
        $comment = $this->dom->createComment($data);
        $this->appendToRealParent($comment);
    }

    private function appendToRealParent($node) {
        if($this->foster_parent === null) {
            end($this->stack)->appendChild($node);

        } elseif($this->foster_parent !== null) {
            /* If the foster parent element is the parent element of the
            last table element in the stack of open elements, then the new
            node must be inserted immediately before the last table element
            in the stack of open elements in the foster parent element;
            otherwise, the new node must be appended to the foster parent
            element. */
            for($n = count($this->stack) - 1; $n >= 0; $n--) {
                if($this->stack[$n]->nodeName === 'table' &&
                $this->stack[$n]->parentNode !== null) {
                    $table = $this->stack[$n];
                    break;
                }
            }

            if(isset($table) && $this->foster_parent->isSameNode($table->parentNode))
                $this->foster_parent->insertBefore($node, $table);
            else
                $this->foster_parent->appendChild($node);

            $this->foster_parent = null;
        }
    }

    private function elementInScope($el, $table = false) {
        if(is_array($el)) {
            foreach($el as $element) {
                if($this->elementInScope($element, $table)) {
                    return true;
                }
            }

            return false;
        }

        $leng = count($this->stack);

        for($n = 0; $n < $leng; $n++) {
            /* 1. Initialise node to be the current node (the bottommost node of
            the stack). */
            $node = $this->stack[$leng - 1 - $n];

            if($node->tagName === $el) {
                /* 2. If node is the target node, terminate in a match state. */
                return true;

            } elseif($node->tagName === 'table') {
                /* 3. Otherwise, if node is a table element, terminate in a failure
                state. */
                return false;

            } elseif($table === true && in_array($node->tagName, array('caption', 'td',
            'th', 'button', 'marquee', 'object'))) {
                /* 4. Otherwise, if the algorithm is the "has an element in scope"
                variant (rather than the "has an element in table scope" variant),
                and node is one of the following, terminate in a failure state. */
                return false;

            } elseif($node === $node->ownerDocument->documentElement) {
                /* 5. Otherwise, if node is an html element (root element), terminate
                in a failure state. (This can only happen if the node is the topmost
                node of the    stack of open elements, and prevents the next step from
                being invoked if there are no more elements in the stack.) */
                return false;
            }

            /* Otherwise, set node to the previous entry in the stack of open
            elements and return to step 2. (This will never fail, since the loop
            will always terminate in the previous step if the top of the stack
            is reached.) */
        }
    }

    private function reconstructActiveFormattingElements() {
        /* 1. If there are no entries in the list of active formatting elements,
        then there is nothing to reconstruct; stop this algorithm. */
        $formatting_elements = count($this->a_formatting);

        if($formatting_elements === 0) {
            return false;
        }

        /* 3. Let entry be the last (most recently added) element in the list
        of active formatting elements. */
        $entry = end($this->a_formatting);

        /* 2. If the last (most recently added) entry in the list of active
        formatting elements is a marker, or if it is an element that is in the
        stack of open elements, then there is nothing to reconstruct; stop this
        algorithm. */
        if($entry === self::MARKER || in_array($entry, $this->stack, true)) {
            return false;
        }

        for($a = $formatting_elements - 1; $a >= 0; true) {
            /* 4. If there are no entries before entry in the list of active
            formatting elements, then jump to step 8. */
            if($a === 0) {
                $step_seven = false;
                break;
            }

            /* 5. Let entry be the entry one earlier than entry in the list of
            active formatting elements. */
            $a--;
            $entry = $this->a_formatting[$a];

            /* 6. If entry is neither a marker nor an element that is also in
            thetack of open elements, go to step 4. */
            if($entry === self::MARKER || in_array($entry, $this->stack, true)) {
                break;
            }
        }

        while(true) {
            /* 7. Let entry be the element one later than entry in the list of
            active formatting elements. */
            if(isset($step_seven) && $step_seven === true) {
                $a++;
                $entry = $this->a_formatting[$a];
            }

            /* 8. Perform a shallow clone of the element entry to obtain clone. */
            $clone = $entry->cloneNode();

            /* 9. Append clone to the current node and push it onto the stack
            of open elements  so that it is the new current node. */
            end($this->stack)->appendChild($clone);
            $this->stack[] = $clone;

            /* 10. Replace the entry for entry in the list with an entry for
            clone. */
            $this->a_formatting[$a] = $clone;

            /* 11. If the entry for clone in the list of active formatting
            elements is not the last entry in the list, return to step 7. */
            if(end($this->a_formatting) !== $clone) {
                $step_seven = true;
            } else {
                break;
            }
        }
    }

    private function clearTheActiveFormattingElementsUpToTheLastMarker() {
        /* When the steps below require the UA to clear the list of active
        formatting elements up to the last marker, the UA must perform the
        following steps: */

        while(true) {
            /* 1. Let entry be the last (most recently added) entry in the list
            of active formatting elements. */
            $entry = end($this->a_formatting);

            /* 2. Remove entry from the list of active formatting elements. */
            array_pop($this->a_formatting);

            /* 3. If entry was a marker, then stop the algorithm at this point.
            The list has been cleared up to the last marker. */
            if($entry === self::MARKER) {
                break;
            }
        }
    }

    private function generateImpliedEndTags($exclude = array()) {
        /* When the steps below require the UA to generate implied end tags,
        then, if the current node is a dd element, a dt element, an li element,
        a p element, a td element, a th  element, or a tr element, the UA must
        act as if an end tag with the respective tag name had been seen and
        then generate implied end tags again. */
        $node = end($this->stack);
        $elements = array_diff(array('dd', 'dt', 'li', 'p', 'td', 'th', 'tr'), $exclude);

        while(in_array(end($this->stack)->nodeName, $elements)) {
            array_pop($this->stack);
        }
    }

    private function getElementCategory($node) {
        $name = $node->tagName;
        if(in_array($name, $this->special))
            return self::SPECIAL;

        elseif(in_array($name, $this->scoping))
            return self::SCOPING;

        elseif(in_array($name, $this->formatting))
            return self::FORMATTING;

        else
            return self::PHRASING;
    }

    private function clearStackToTableContext($elements) {
        /* When the steps above require the UA to clear the stack back to a
        table context, it means that the UA must, while the current node is not
        a table element or an html element, pop elements from the stack of open
        elements. */
        while(true) {
            $node = end($this->stack)->nodeName;

            if(in_array($node, $elements)) {
                break;
            } else {
                array_pop($this->stack);
            }
        }
    }

    private function resetInsertionMode() {
        /* 1. Let last be false. */
        $last = false;
        $leng = count($this->stack);

        for($n = $leng - 1; $n >= 0; $n--) {
            /* 2. Let node be the last node in the stack of open elements. */
            $node = $this->stack[$n];

            /* 3. If node is the first node in the stack of open elements, then
            set last to true. If the element whose innerHTML  attribute is being
            set is neither a td  element nor a th element, then set node to the
            element whose innerHTML  attribute is being set. (innerHTML  case) */
            if($this->stack[0]->isSameNode($node)) {
                $last = true;
            }

            /* 4. If node is a select element, then switch the insertion mode to
            "in select" and abort these steps. (innerHTML case) */
            if($node->nodeName === 'select') {
                $this->mode = self::IN_SELECT;
                break;

            /* 5. If node is a td or th element, then switch the insertion mode
            to "in cell" and abort these steps. */
            } elseif($node->nodeName === 'td' || $node->nodeName === 'th') {
                $this->mode = self::IN_CELL;
                break;

            /* 6. If node is a tr element, then switch the insertion mode to
            "in    row" and abort these steps. */
            } elseif($node->nodeName === 'tr') {
                $this->mode = self::IN_ROW;
                break;

            /* 7. If node is a tbody, thead, or tfoot element, then switch the
            insertion mode to "in table body" and abort these steps. */
            } elseif(in_array($node->nodeName, array('tbody', 'thead', 'tfoot'))) {
                $this->mode = self::IN_TBODY;
                break;

            /* 8. If node is a caption element, then switch the insertion mode
            to "in caption" and abort these steps. */
            } elseif($node->nodeName === 'caption') {
                $this->mode = self::IN_CAPTION;
                break;

            /* 9. If node is a colgroup element, then switch the insertion mode
            to "in column group" and abort these steps. (innerHTML case) */
            } elseif($node->nodeName === 'colgroup') {
                $this->mode = self::IN_CGROUP;
                break;

            /* 10. If node is a table element, then switch the insertion mode
            to "in table" and abort these steps. */
            } elseif($node->nodeName === 'table') {
                $this->mode = self::IN_TABLE;
                break;

            /* 11. If node is a head element, then switch the insertion mode
            to "in body" ("in body"! not "in head"!) and abort these steps.
            (innerHTML case) */
            } elseif($node->nodeName === 'head') {
                $this->mode = self::IN_BODY;
                break;

            /* 12. If node is a body element, then switch the insertion mode to
            "in body" and abort these steps. */
            } elseif($node->nodeName === 'body') {
                $this->mode = self::IN_BODY;
                break;

            /* 13. If node is a frameset element, then switch the insertion
            mode to "in frameset" and abort these steps. (innerHTML case) */
            } elseif($node->nodeName === 'frameset') {
                $this->mode = self::IN_FRAME;
                break;

            /* 14. If node is an html element, then: if the head element
            pointer is null, switch the insertion mode to "before head",
            otherwise, switch the insertion mode to "after head". In either
            case, abort these steps. (innerHTML case) */
            } elseif($node->nodeName === 'html') {
                $this->mode = ($this->head_pointer === null)
                    ? self::BEFOR_HEAD
                    : self::AFTER_HEAD;

                break;

            /* 15. If last is true, then set the insertion mode to "in body"
            and    abort these steps. (innerHTML case) */
            } elseif($last) {
                $this->mode = self::IN_BODY;
                break;
            }
        }
    }

    private function closeCell() {
        /* If the stack of open elements has a td or th element in table scope,
        then act as if an end tag token with that tag name had been seen. */
        foreach(array('td', 'th') as $cell) {
            if($this->elementInScope($cell, true)) {
                $this->emitToken(array(
                    'name' => $cell,
                    'type' => HTML5_Tokenizer::ENDTAG
                ));

                break;
            }
        }
    }

    private function processWithRulesFor($token, $mode) {
        /* "using the rules for the m insertion mode", where m is one of these
         * modes, the user agent must use the rules described under the m
         * insertion mode's section, but must leave the insertion mode
         * unchanged unless the rules in m themselves switch the insertion mode
         * to a new value. */
        return $this->emitToken($token, $mode);
    }

    private function insertCDATAElement($token) {
        $this->insertElement($token);
        $this->originalMode = $this->mode;
        $this->mode = self::IN_CDATA_RCDATA;
        return HTML5_Tokenizer::CDATA;
    }

    private function insertRCDATAElement($token) {
        $this->insertElement($token);
        $this->originalMode = $this->mode;
        $this->mode = self::IN_CDATA_RCDATA;
        return HTML5_Tokenizer::RCDATA;
    }

    public function save() {
        return $this->dom;
    }
}

