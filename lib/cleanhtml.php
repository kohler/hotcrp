<?php
// cleanhtml.php -- HTML cleaner for CSS prevention
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class CleanHTML {
    const BADTAGS_IGNORE = 1;
    /** @var int */
    private $flags;
    /** @var array<string,mixed> */
    private $goodtags;
    /** @var array<string,mixed> */
    private $emptytags;
    /** @var ?string */
    public $last_error;

    /** @var CleanHTML */
    static private $main;

    /** @param int $flags
     * @param ?list<string> $goodtags
     * @param ?list<string> $emptytags */
    function __construct($flags = 0, $goodtags = null, $emptytags = null) {
        if ($goodtags === null) {
            $goodtags = ["a", "abbr", "acronym", "address", "area", "b", "bdi", "bdo", "big", "blockquote", "br", "button", "caption", "center", "cite", "code", "col", "colgroup", "dd", "del", "details", "dir", "div", "dfn", "dl", "dt", "em", "figcaption", "figure", "font", "h1", "h2", "h3", "h4", "h5", "h6", "hr", "i", "img", "ins", "kbd", "label", "legend", "li", "link", "map", "mark", "menu", "menuitem", "meter", "noscript", "ol", "optgroup", "option", "p", "pre", "q", "rp", "rt", "ruby", "s", "samp", "section", "select", "small", "span", "strike", "strong", "sub", "summary", "sup", "table", "tbody", "td", "textarea", "tfoot", "th", "thead", "time", "title", "tr", "tt", "u", "ul", "var", "wbr"];
        }
        if ($emptytags === null) {
            $emptytags = ["area", "base", "br", "col", "hr", "img", "input", "link", "meta", "param", "wbr"];
        }
        $this->flags = 0;
        $this->goodtags = is_associative_array($goodtags) ? $goodtags : array_flip($goodtags);
        $this->emptytags = is_associative_array($emptytags) ? $emptytags : array_flip($emptytags);
    }

    private function _cleanHTMLError($etype) {
        $this->last_error = "Your HTML code contains $etype. Only HTML content tags are accepted, such as <code>&lt;p&gt;</code>, <code>&lt;strong&gt;</code>, and <code>&lt;h1&gt;</code>, and attributes are restricted.";
        return false;
    }

    /** @param string $t
     * @return string|false */
    function clean($t) {
        $tagstack = array();
        $this->last_error = null;

        $x = "";
        while ($t !== "") {
            if (($p = strpos($t, "<")) === false) {
                $x .= $t;
                break;
            }
            $x .= substr($t, 0, $p);
            $t = substr($t, $p);

            if (preg_match('/\A<!\[[ie]/', $t)) {
                return $this->_cleanHTMLError("an Internet Explorer conditional comment");
            } else if (preg_match('/\A(<!\[CDATA\[.*?)(\]\]>|\z)(.*)\z/s', $t, $m)) {
                $x .= $m[1] . "]]>";
                $t = $m[3];
            } else if (preg_match('/\A<!--.*?(-->|\z)(.*)\z/s', $t, $m)) {
                $t = $m[2];
            } else if (preg_match('/\A<!(\S+)/s', $t, $m)) {
                return $this->_cleanHTMLError("<code>$m[1]</code> declarations");
            } else if (preg_match('/\A<\s*([A-Za-z0-9]+)\s*(.*)\z/s', $t, $m)) {
                $tag = strtolower($m[1]);
                if (!isset($this->goodtags[$tag])) {
                    if (!($this->flags & self::BADTAGS_IGNORE)) {
                        return $this->_cleanHTMLError("an unacceptable <code>&lt;$tag&gt;</code> tag");
                    }
                    $x .= "&lt;";
                    $t = substr($t, 1);
                    continue;
                }
                $t = $m[2];
                $x .= "<" . $tag;
                // XXX should sanitize 'id', 'class', 'data-', etc.
                while ($t !== "" && $t[0] !== "/" && $t[0] !== ">") {
                    if (!preg_match(',\A([^\s/<>=\'"]+)\s*(.*)\z,s', $t, $m)) {
                        return $this->_cleanHTMLError("garbage <code>" . htmlspecialchars($t) . "</code> within some <code>&lt;$tag&gt;</code> tag");
                    }
                    $attr = strtolower($m[1]);
                    if (strlen($attr) > 2 && $attr[0] === "o" && $attr[1] === "n") {
                        return $this->_cleanHTMLError("an event handler attribute in some <code>&lt;$tag&gt;</code> tag");
                    } else if ($attr === "style" || $attr === "script" || $attr === "id") {
                        return $this->_cleanHTMLError("<code>$attr</code> attribute in some <code>&lt;$tag&gt;</code> tag");
                    }
                    $x .= " " . $attr;
                    $t = $m[2];
                    if (preg_match(',\A=\s*(\'.*?\'|".*?"|\w+)\s*(.*)\z,s', $t, $m)) {
                        if ($m[1][0] === "'" || $m[1][0] === "\"") {
                            $m[1] = substr($m[1], 1, -1);
                        }
                        $m[1] = html_entity_decode($m[1], ENT_HTML5);
                        if ($attr === "href" && preg_match(',\A\s*javascript\s*:,i', $m[1])) {
                            return $this->_cleanHTMLError("<code>href</code> attribute to JavaScript URL");
                        }
                        $x .= "=\"" . htmlspecialchars($m[1]) . "\"";
                        $t = $m[2];
                    }
                }
                if ($t === "") {
                    return $this->_cleanHTMLError("an unclosed <code>&lt;$tag&gt;</code> tag");
                } else if ($t[0] === ">") {
                    $t = substr($t, 1);
                    if (isset($this->emptytags[$tag])
                        && !preg_match(',\A\s*<\s*/' . $tag . '\s*>,si', $t))
                        // automagically close empty tags
                        $x .= " />";
                    else {
                        $x .= ">";
                        $tagstack[] = $tag;
                    }
                } else if (preg_match(',\A/\s*>(.*)\z,s', $t, $m)) {
                    $x .= " />";
                    $t = $m[1];
                } else {
                    return $this->_cleanHTMLError("garbage in some <code>&lt;$tag&gt;</code> tag");
                }
            } else if (preg_match(',\A<\s*/\s*([A-Za-z0-9]+)\s*>(.*)\z,s', $t, $m)) {
                $tag = strtolower($m[1]);
                if (!isset($this->goodtags[$tag])) {
                    if (!($this->flags & self::BADTAGS_IGNORE)) {
                        return $this->_cleanHTMLError("an unacceptable <code>&lt;/$tag&gt;</code> tag");
                    }
                    $x .= "&lt;";
                    $t = substr($t, 1);
                    continue;
                } else if (empty($tagstack)) {
                    return $this->_cleanHTMLError("a extra close tag <code>&lt;/$tag&gt;</code>");
                } else if (($last = array_pop($tagstack)) !== $tag) {
                    return $this->_cleanHTMLError("a close tag <code>&lt;/$tag</code> that doesn’t match the open tag <code>&lt;$last</code>");
                }
                $x .= "</$tag>";
                $t = $m[2];
            } else {
                $x .= "&lt;";
                $t = substr($t, 1);
            }
        }

        if (!empty($tagstack)) {
            return $this->_cleanHTMLError("unclosed tags, including <code>&lt;$tagstack[0]&gt;</code>");
        }

        return preg_replace('/\r\n?/', "\n", $x);
    }

    /** @param string|list<string> $t
     * @return list<string>|false */
    function clean_all($t) {
        $x = [];
        foreach (is_array($t) ? $t : [$t] as $s) {
            if (is_string($s)
                && ($s = $this->clean($s)) !== false) {
                $x[] = $s;
            } else {
                return false;
            }
        }
        return $x;
    }

    /** @return CleanHTML */
    static function basic() {
        if (!self::$main) {
            self::$main = new CleanHTML;
        }
        return self::$main;
    }

    /** @param string $t
     * @return string|false */
    static function basic_clean($t) {
        return self::basic()->clean($t);
    }

    /** @param string|list<string> $t
     * @return list<string>|false */
    static function basic_clean_all($t) {
        return self::basic()->clean_all($t);
    }
}
