<?php
// author.php -- HotCRP author objects
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Author {
    public $firstName = "";
    public $lastName = "";
    public $email = "";
    public $affiliation = "";
    private $_name;
    public $contactId = null;
    private $_deaccents;
    public $nonauthor;
    public $sorter;

    function __construct($x = null) {
        if (is_object($x)) {
            $this->firstName = $x->firstName;
            $this->lastName = $x->lastName;
            $this->email = $x->email;
            $this->affiliation = $x->affiliation;
        } else if ((string) $x !== "") {
            $this->assign_string($x);
        }
    }
    static function make_tabbed($s) {
        $au = new Author;
        $w = explode("\t", $s);
        $au->firstName = isset($w[0]) ? $w[0] : "";
        $au->lastName = isset($w[1]) ? $w[1] : "";
        $au->email = isset($w[2]) ? $w[2] : "";
        $au->affiliation = isset($w[3]) ? $w[3] : "";
        return $au;
    }
    static function make_string($s) {
        $au = new Author;
        $au->assign_string($s);
        return $au;
    }
    function assign_string($s) {
        if (($paren = strpos($s, "(")) !== false) {
            if (preg_match('{\G([^()]*)(?:\)|\z)(?:[\s,;.]*|\s*(?:-+|–|—|[#:%]).*)\z}', $s, $m, 0, $paren + 1)) {
                $this->affiliation = trim($m[1]);
                $s = rtrim(substr($s, 0, $paren));
            } else {
                $len = strlen($s);
                while ($paren !== false) {
                    $rparen = self::skip_balanced_parens($s, $paren);
                    if ($rparen === $len
                        || preg_match('{\A(?:[\s,;.]*|\s*(?:-+|–|—|[#:]).*)\z}', substr($s, $rparen + 1))) {
                        $this->affiliation = trim(substr($s, $paren + 1, $rparen - $paren - 1));
                        $s = rtrim(substr($s, 0, $paren));
                        break;
                    }
                    $paren = strpos($s, "(", $rparen + 1);
                }
            }
        }
        if (strlen($s) > 4
            || ($s !== "" && strcasecmp($s, "all") && strcasecmp($s, "none"))) {
            $this->_name = trim($s);
            list($this->firstName, $this->lastName, $this->email) = Text::split_name($s, true);
        }
    }
    static function make_string_guess($s) {
        $au = new Author;
        $au->assign_string_guess($s);
        return $au;
    }
    function assign_string_guess($s) {
        $hash = strpos($s, "#");
        $pct = strpos($s, "%");
        if ($hash !== false || $pct !== false)
            $s = substr($s, 0, $hash === false ? $pct : ($pct === false ? $hash : min($hash, $pct)));
        $this->assign_string($s);
        if ($this->firstName === ""
            && (strcasecmp($this->lastName, "all") === 0
                || strcasecmp($this->lastName, "none") === 0))
            $this->lastName = "";
        if ($this->affiliation === ""
            && $this->email === "") {
            if (strpos($s, ",") !== false
                && strpos($this->lastName, " ") !== false) {
                if (AuthorMatcher::is_likely_affiliation($this->firstName)) {
                    $this->affiliation = $this->firstName;
                    list($this->firstName, $this->lastName) = Text::split_name($this->lastName);
                }
            } else if (AuthorMatcher::is_likely_affiliation($s)) {
                $this->firstName = $this->lastName = "";
                $this->affiliation = $s;
            }
        }
    }
    static function skip_balanced_parens($s, $paren) {
        for ($len = strlen($s), $depth = 1, ++$paren; $paren < $len; ++$paren)
            if ($s[$paren] === "(")
                ++$depth;
            else if ($s[$paren] === ")") {
                if (--$depth === 0)
                    return $paren;
            }
        return $paren;
    }
    function name() {
        if ($this->_name !== null)
            return $this->_name;
        else if ($this->firstName !== "" && $this->lastName !== "")
            return $this->firstName . " " . $this->lastName;
        else if ($this->lastName !== "")
            return $this->lastName;
        else
            return $this->firstName;
    }
    function nameaff_html() {
        $n = htmlspecialchars($this->name());
        if ($n === "")
            $n = htmlspecialchars($this->email);
        if ($this->affiliation)
            $n .= ' <span class="auaff">(' . htmlspecialchars($this->affiliation) . ')</span>';
        return ltrim($n);
    }
    function nameaff_text() {
        $n = $this->name();
        if ($n === "")
            $n = $this->email;
        if ($this->affiliation)
            $n .= ' (' . $this->affiliation . ')';
        return ltrim($n);
    }
    function name_email_aff_text() {
        $n = $this->name();
        if ($n === "")
            $n = $this->email;
        else if ($this->email !== "")
            $n .= " <$this->email>";
        if ($this->affiliation)
            $n .= ' (' . $this->affiliation . ')';
        return ltrim($n);
    }
    function abbrevname_text() {
        if ($this->lastName !== "") {
            $u = "";
            if ($this->firstName !== "" && ($u = Text::initial($this->firstName)) != "")
                $u .= " "; // non-breaking space
            return $u . $this->lastName;
        } else if ($this->firstName !== "")
            return $this->firstName;
        else if ($this->email !== "")
            return $this->email;
        else
            return "???";
    }
    function abbrevname_html() {
        return htmlspecialchars($this->abbrevname_text());
    }
    function deaccent($component) {
        if ($this->_deaccents === null)
            $this->_deaccents = [
                strtolower(UnicodeHelper::deaccent($this->firstName)),
                strtolower(UnicodeHelper::deaccent($this->lastName)),
                strtolower(UnicodeHelper::deaccent($this->affiliation))
            ];
        return $this->_deaccents[$component];
    }
}
