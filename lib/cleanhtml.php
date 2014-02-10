<?php
// cleanhtml.php -- HTML cleaner for CSS prevention
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CleanHTML {

    private static $goodtags = null;
    private static $emptytags = null;

    private static function _cleanHTMLError(&$err, $etype) {
        $err = "Your HTML code contains $etype. Only HTML content tags are accepted, such as <tt>&lt;p&gt;</tt>, <tt>&lt;strong&gt;</tt>, and <tt>&lt;h1&gt;</tt>, and attributes are restricted.";
        return false;
    }

    private static function _set_statics() {
        self::$goodtags = array_flip(array("a", "abbr", "acronym", "address", "area", "b", "bdo", "big", "blockquote", "br", "button", "caption", "center", "cite", "code", "col", "colgroup", "dd", "del", "dir", "div", "dfn", "dl", "dt", "em", "font", "h1", "h2", "h3", "h4", "h5", "h6", "hr", "i", "img", "ins", "kbd", "label", "legend", "li", "link", "map", "menu", "noscript", "ol", "optgroup", "option", "p", "pre", "q", "s", "samp", "select", "small", "span", "strike", "strong", "sub", "sup", "table", "tbody", "td", "textarea", "tfoot", "th", "thead", "title", "tr", "tt", "u", "ul", "var"));
        self::$emptytags = array_flip(array("base", "meta", "link", "hr", "br", "param", "img", "area", "input", "col"));
    }

    static function clean($t, &$err) {
        if (!self::$goodtags)
            self::_set_statics();

        $tagstack = array();

        $x = "";
        while ($t != "") {
            if (($p = strpos($t, "<")) === false) {
                $x .= $t;
                break;
            }
            $x .= substr($t, 0, $p);
            $t = substr($t, $p);

            if (preg_match('/\A<!\[[ie]/', $t))
                return self::_cleanHTMLError($err, "an Internet Explorer conditional comment");
            else if (preg_match('/\A(<!\[CDATA\[.*?)(\]\]>|\z)(.*)\z/s', $t, $m)) {
                $x .= $m[1] . "]]>";
                $t = $m[3];
            } else if (preg_match('/\A<!--.*?(-->|\z)(.*)\z/s', $t, $m))
                $t = $m[2];
            else if (preg_match('/\A<!(\S+)/s', $t, $m))
                return self::_cleanHTMLError($err, "<code>$m[1]</code> declarations");
            else if (preg_match('/\A<\s*([A-Za-z]+)\s*(.*)\z/s', $t, $m)) {
                $tag = strtolower($m[1]);
                $t = $m[2];
                $x .= "<" . $tag;
                if (!isset(self::$goodtags[$tag]))
                    return self::_cleanHTMLError($err, "some <code>&lt;$tag&gt;</code> tag");
                while ($t != "" && $t[0] != "/" && $t[0] != ">") {
                    if (!preg_match(',\A([^\s/<>=\'"]+)\s*(.*)\z,s', $t, $m))
                        return self::_cleanHTMLError($err, "garbage <code>" . htmlspecialchars($t) . "</code> within some <code>&lt;$tag&gt;</code> tag");
                    $attr = strtolower($m[1]);
                    if (strlen($attr) > 2 && $attr[0] == "o" && $attr[1] == "n")
                        return self::_cleanHTMLError($err, "an event handler attribute in some <code>&lt;$tag&gt;</code> tag");
                    else if ($attr == "style" || $attr == "script" || $attr == "id")
                        return self::_cleanHTMLError($err, "a <code>$attr</code> attribute in some <code>&lt;$tag&gt;</code> tag");
                    $x .= " " . $attr . "=";
                    $t = $m[2];
                    if (preg_match(',\A=\s*(\'.*?\'|".*?"|\w+)\s*(.*)\z,s', $t, $m)) {
                        if ($m[1][0] != "'" && $m[1][0] != "\"")
                            $m[1] = "\"$m[1]\"";
                        $x .= $m[1];
                        $t = $m[2];
                    } else
                        $x .= "\"$attr\" ";
                }
                if ($t == "")
                    return self::_cleanHTMLError($err, "an unclosed <code>&lt;$tag&gt;</code> tag");
                else if ($t[0] == ">") {
                    $t = substr($t, 1);
                    if (isset(self::$emptytags[$tag])
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
                } else
                    return self::_cleanHTMLError($err, "garbage in some <code>&lt;$tag&gt;</code> tag");
            } else if (preg_match(',\A<\s*/\s*([A-Za-z]+)\s*>(.*)\z,s', $t, $m)) {
                $tag = strtolower($m[1]);
                if (!isset(self::$goodtags[$tag]))
                    return self::_cleanHTMLError($err, "some <code>&lt;/$tag&gt;</code> tag");
                else if (count($tagstack) == 0)
                    return self::_cleanHTMLError($err, "a extra close tag <code>&lt;/$tag&gt;</code>");
                else if (($last = array_pop($tagstack)) != $tag)
                    return self::_cleanHTMLError($err, "a close tag <code>&lt;/$tag</code> that doesn’t match the open tag <code>&lt;$last</code>");
                $x .= "</$tag>";
                $t = $m[2];
            } else {
                $x .= "&lt;";
                $t = substr($t, 1);
            }
        }

        if (count($tagstack) > 0)
            return self::_cleanHTMLError($err, "unclosed tags, including <code>&lt;$tagstack[0]&gt;</code>");

        return $x;
    }

}
