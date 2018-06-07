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
    public $firstName_deaccent;
    public $lastName_deaccent;
    public $affiliation_deaccent;
    public $nonauthor;
    public $sorter;

    function __construct($x) {
        if (is_object($x)) {
            $this->firstName = $x->firstName;
            $this->lastName = $x->lastName;
            $this->email = $x->email;
            $this->affiliation = $x->affiliation;
        } else if ((string) $x !== "") {
            $a = explode("\t", $x);
            if (isset($a[1])) {
                $this->firstName = $a[0];
                $this->lastName = $a[1];
                if (isset($a[3]) && $a[3] !== "") {
                    $this->email = $a[2];
                    $this->affiliation = $a[3];
                } else if (isset($a[2]) && $a[2] !== "") {
                    if (strpos($a[2], "@") === false) {
                        $this->affiliation = $a[2];
                    } else {
                        $this->email = $a[2];
                    }
                }
            } else {
                if (($paren = strpos($x, "(")) !== false) {
                    if (preg_match('{\G([^()]*)(?:\)|\z)(?:[\s,;.]*|\s*(?:-+|–|—|[#:]).*)\z}', $x, $m, 0, $paren + 1)) {
                        $this->affiliation = trim($m[1]);
                        $x = substr($x, 0, $paren);
                    } else {
                        $len = strlen($x);
                        while ($paren !== false) {
                            $rparen = self::skip_balanced_parens($x, $paren);
                            if ($rparen === $len
                                || preg_match('{\A(?:[\s,;.]*|\s*(?:-+|–|—|[#:]).*)\z}', substr($x, $rparen + 1))) {
                                $this->affiliation = trim(substr($x, $paren + 1, $rparen - $paren - 1));
                                $x = substr($x, 0, $paren);
                                break;
                            }
                            $paren = strpos($x, "(", $rparen + 1);
                        }
                    }
                }
                $this->_name = trim($x);
                list($this->firstName, $this->lastName, $this->email) = Text::split_name($x, true);
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
}
