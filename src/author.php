<?php
// author.php -- HotCRP author objects
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Author {
    /** @var string */
    public $firstName = "";
    /** @var string */
    public $lastName = "";
    /** @var string */
    public $email = "";
    /** @var string */
    public $affiliation = "";
    /** @var ?string */
    private $_name;
    /** @var null|Author|array{string,string,string} */
    private $_deaccents;
    /** @var ?int */
    public $contactId;
    /** @var int */
    public $roles = 0;
    /** @var int */
    private $disablement = 0;
    /** @var ?int */
    public $conflictType;
    /** @var ?int */
    public $status;
    /** @var ?int */
    public $author_index;

    const STATUS_AUTHOR = 1;
    const STATUS_REVIEWER = 2;
    const STATUS_ANONYMOUS_REVIEWER = 3;
    const STATUS_PC = 4;
    const STATUS_NONAUTHOR = 5;

    const COLLABORATORS_INDEX = -200;
    const UNINITIALIZED_INDEX = -400; // see also PaperConflictInfo

    /** @param null|string|object $x
     * @param null|1|2|3|4|5 $status */
    function __construct($x = null, $status = null) {
        if (is_object($x)) {
            $this->firstName = $x->firstName;
            $this->lastName = $x->lastName;
            $this->email = $x->email;
            $this->affiliation = $x->affiliation;
        } else if ($x !== null && $x !== "") {
            $this->assign_string($x);
        }
        $this->status = $status;
    }

    /** @param string $s
     * @param ?int $author_index
     * @return Author */
    static function make_tabbed($s, $author_index = null) {
        $au = new Author;
        $w = explode("\t", $s);
        $au->firstName = $w[0] ?? "";
        $au->lastName = $w[1] ?? "";
        $au->email = $w[2] ?? "";
        $au->affiliation = $w[3] ?? "";
        $au->author_index = $author_index;
        return $au;
    }

    /** @param string $s
     * @return Author */
    static function make_string($s) {
        $au = new Author;
        $au->assign_string($s);
        return $au;
    }

    /** @param string $s
     * @return Author */
    static function make_string_guess($s) {
        $au = new Author;
        $au->assign_string_guess($s);
        return $au;
    }

    /** @param object|array<string,mixed> $o
     * @return Author */
    static function make_keyed($o) {
        $au = new Author;
        $au->assign_keyed($o);
        return $au;
    }

    /** @param string $email
     * @return Author */
    static function make_email($email) {
        $au = new Author;
        $au->email = $email;
        return $au;
    }

    /** @param Contact $u
     * @return Author */
    static function make_user($u) {
        $au = new Author($u);
        $au->contactId = $u->contactId;
        $au->roles = $u->roles;
        $au->disablement = $u->disabled_flags();
        return $au;
    }

    /** @return $this */
    function copy() {
        $au = clone $this;
        if (!is_object($this->_deaccents)) {
            $au->_deaccents = $this;
        }
        return $au;
    }

    /** @param Author|Contact $o */
    function merge($o) {
        if ($this->email === "") {
            $this->email = $o->email;
        }
        if ($this->firstName === "" && $this->lastName === "") {
            $this->firstName = $o->firstName;
            $this->lastName = $o->lastName;
            $this->_name = null;
        }
        if ($this->affiliation === "") {
            $this->affiliation = $o->affiliation;
        }
        $this->_deaccents = null;
    }

    /** @param string $s */
    function assign_string($s) {
        if (($paren = strpos($s, "(")) !== false) {
            if (preg_match('/\G([^()]*)(?:\)|\z)(?:[\s,;.]*|\s*(?:-+|–|—|[#:%]).*)\z/', $s, $m, 0, $paren + 1)) {
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
            || ($s !== "" && strcasecmp($s, "all") !== 0 && strcasecmp($s, "none") !== 0)) {
            list($this->firstName, $this->lastName, $this->email) = Text::split_name($s, true);
        }
        $this->_name = $this->email === null ? trim($s) : null;
    }

    /** @param string $s */
    function assign_string_guess($s) {
        $hash = strpos($s, "#");
        $pct = strpos($s, "%");
        if ($hash !== false || $pct !== false) {
            $s = substr($s, 0, $hash === false ? $pct : ($pct === false ? $hash : min($hash, $pct)));
        }
        $this->assign_string($s);
        if ($this->firstName === ""
            && (strcasecmp($this->lastName, "all") === 0
                || strcasecmp($this->lastName, "none") === 0)) {
            $this->lastName = "";
        }
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

    /** @param object|array<string,mixed> $x */
    function assign_keyed($x) {
        if (!is_object($x) && !is_array($x)) {
            throw new Exception("invalid Author::make_keyed");
        }
        $arr = is_object($x) ? get_object_vars($x) : $x;
        $f = $arr["firstName"] ?? $arr["first"] ?? $arr["givenName"] ?? $arr["given"] ?? null;
        $l = $arr["lastName"] ?? $arr["last"] ?? $arr["familyName"] ?? $arr["family"] ?? null;
        $e = $arr["email"] ?? null;
        $a = $arr["affiliation"] ?? null;
        if (($n = $arr["name"] ?? $arr["fullName"] ?? null) !== null) {
            $this->_name = $n;
            list($ff, $ll, $ee) = Text::split_name($n, true);
            if ($f === null && $l === null) {
                $f = $ff;
                $l = $ll;
            }
            $e = $e ?? $ee;
        }
        $this->firstName = $f ?? "";
        $this->lastName = $l ?? "";
        $this->email = $e ?? "";
        $this->affiliation = $a ?? "";
    }

    /** @param string $s
     * @param int $paren
     * @return int */
    static function skip_balanced_parens($s, $paren) {
        // assert($s[$paren] === "("); -- precondition
        for ($len = strlen($s), $depth = 1, ++$paren; $paren < $len; ++$paren) {
            if ($s[$paren] === "(") {
                ++$depth;
            } else if ($s[$paren] === ")") {
                --$depth;
                if ($depth === 0) {
                    break;
                }
            }
        }
        return $paren;
    }

    /** @return string */
    function name($flags = 0) {
        if (($flags & (NAME_L | NAME_I | NAME_U)) === 0 && $this->_name !== null) {
            $name = $this->_name;
        } else {
            $name = Text::name($this->firstName, $this->lastName, $this->email, $flags);
        }
        if (($flags & NAME_A) !== 0 && $this->affiliation !== "") {
            $name = Text::add_affiliation($name, $this->affiliation, $flags);
        }
        return $name;
    }

    /** @return string */
    function name_h($flags = 0) {
        $name = htmlspecialchars($this->name($flags & ~NAME_A));
        if (($flags & NAME_A) !== 0 && $this->affiliation !== "") {
            $name = Text::add_affiliation_h($name, $this->affiliation, $flags);
        }
        return $name;
    }

    /** @param 0|1|2 $component
     * @return string */
    function deaccent($component) {
        if (is_object($this->_deaccents)) {
            return $this->_deaccents->deaccent($component);
        }
        if ($this->_deaccents === null) {
            $this->_deaccents = [
                strtolower(UnicodeHelper::deaccent($this->firstName)),
                strtolower(UnicodeHelper::deaccent($this->lastName)),
                strtolower(UnicodeHelper::deaccent($this->affiliation))
            ];
        }
        return $this->_deaccents[$component];
    }

    /** @return bool */
    function is_empty() {
        return $this->email === "" && $this->firstName === "" && $this->lastName === "" && $this->affiliation === "";
    }

    /** @return bool */
    function is_conflicted() {
        assert($this->conflictType !== null);
        return $this->conflictType > CONFLICT_MAXUNCONFLICTED;
    }

    /** @return bool */
    function is_nonauthor() {
        return $this->status === self::STATUS_NONAUTHOR;
    }

    /** @return bool */
    function is_placeholder() {
        return ($this->disablement & Contact::CF_PLACEHOLDER) !== 0;
    }

    /** @return int */
    function disabled_flags() {
        return $this->disablement;
    }

    /** @param Author|Contact $x
     * @return bool */
    function nea_equals($x) {
        return $this->email === $x->email
            && $this->firstName === $x->firstName
            && $this->lastName === $x->lastName
            && $this->affiliation === $x->affiliation;
    }

    /** @return string */
    function unparse_tabbed() {
        return "{$this->firstName}\t{$this->lastName}\t{$this->email}\t{$this->affiliation}";
    }

    /** @return array{email?:string,first?:string,last?:string,affiliation?:string} */
    function unparse_nea_json() {
        return self::unparse_nea_json_for($this);
    }

    /** @param Author|Contact $x
     * @return array{email?:string,first?:string,last?:string,affiliation?:string} */
    static function unparse_nea_json_for($x) {
        $j = [];
        if ($x->email !== "") {
            $j["email"] = $x->email;
        }
        if ($x->firstName !== "") {
            $j["first"] = $x->firstName;
        }
        if ($x->lastName !== "") {
            $j["last"] = $x->lastName;
        }
        if ($x->affiliation !== "") {
            $j["affiliation"] = $x->affiliation;
        }
        return $j;
    }

    /** @return array<string,string> */
    function unparse_debug_json() {
        $j = [];
        foreach (get_object_vars($this) as $k => $v) {
            if (!str_starts_with($k, "_") && $v !== null && $v !== "")
                $j[$k] = $v;
        }
        return $j;
    }
}
