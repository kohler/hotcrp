<?php
// contactsearch.php -- HotCRP helper class for searching for users
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ContactSearch {
    const F_QUOTED = 1;
    const F_PC = 2;
    const F_USER = 4;
    const F_TAG = 8;
    const F_ALLOW_DELETED = 16;
    const F_USERID = 32;

    /** @var Conf */
    public $conf;
    /** @var int */
    public $type;
    /** @var string */
    public $text;
    /** @var Contact */
    private $user;
    /** @var ?array<int,Contact> */
    private $cset;
    /** @var list<int> */
    private $ids;
    /** @var bool */
    private $ok;
    /** @var bool */
    private $only_pc = false;
    /** @var ?list<Contact> */
    private $contacts = null;
    /** @var false|string */
    public $warn_html = false;

    /** @param int $type
     * @param string $text
     * @param ?array<int,Contact> $cset */
    function __construct($type, $text, Contact $user, $cset = null) {
        $this->conf = $user->conf;
        $this->type = $type;
        $this->text = $text;
        $this->user = $user;
        $this->cset = $cset;
        $ids = null;
        if (($this->type & self::F_QUOTED) === 0
            || $this->text === "") {
            $ids = $this->check_simple();
        }
        if ($ids === null
            && ($this->type & self::F_TAG) !== 0
            && ($this->type & self::F_QUOTED) === 0
            && $this->user->can_view_user_tags()) {
            $ids = $this->check_pc_tag();
        }
        if ($ids === null
            && ($this->type & self::F_USER) !== 0) {
            $ids = $this->check_user();
        }
        $this->ids = $ids ?? [];
        $this->ok = $ids !== null;
    }

    /** @param string $text
     * @return ContactSearch */
    static function make_pc($text, Contact $user) {
        return new ContactSearch(self::F_PC | self::F_TAG | self::F_USER, $text, $user);
    }

    /** @param string $text
     * @return ContactSearch */
    static function make_special($text, Contact $user) {
        return new ContactSearch(self::F_PC | self::F_TAG, $text, $user);
    }

    /** @param string $text
     * @param array<int,Contact> $cset
     * @return ContactSearch */
    static function make_cset($text, Contact $user, $cset) {
        return new ContactSearch(self::F_USER, $text, $user, $cset);
    }

    /** @return ?list<int> */
    private function check_simple() {
        if (strcasecmp($this->text, "me") == 0
            && (($this->type & self::F_PC) === 0
                || ($this->user->roles & Contact::ROLE_PCLIKE) !== 0)) {
            return [$this->user->contactId];
        }
        if (($this->type & self::F_USERID) !== 0
            && strspn($this->text, "0123456789 ,") === strlen($this->text)) {
            preg_match_all('/\d+/', $this->text, $m);
            $ids = [];
            foreach ($m[0] as $d) {
                $d = intval($d);
                if ($d > 0
                    && (($this->type & self::F_PC) !== 0 || $this->conf->pc_user_by_id($d))) {
                    $ids[] = $d;
                }
            }
            return $ids;
        }
        if ($this->user->can_view_pc()) {
            if ($this->text === ""
                || strcasecmp($this->text, "pc") === 0) {
                return array_keys($this->conf->pc_members());
            } else if (($this->type & self::F_PC) !== 0
                       && strcasecmp($this->text, "enabled") === 0) {
                return array_keys($this->conf->enabled_pc_members());
            } else if (($this->type & self::F_PC) !== 0
                       && (strcasecmp($this->text, "any") === 0
                           || strcasecmp($this->text, "all") === 0
                           || $this->text === "*")) {
                return array_keys($this->conf->pc_users());
            } else if (strcasecmp($this->text, "chair") === 0
                       || strcasecmp($this->text, "admin") === 0) {
                $flags = Contact::ROLE_CHAIR;
                if (strcasecmp($this->text, "admin") === 0) {
                    $flags |= Contact::ROLE_ADMIN;
                }
                $cids = [];
                foreach ($this->conf->pc_members() as $p) {
                    if ($p->roles & $flags)
                        $cids[] = $p->contactId;
                }
                return $cids;
            }
        }
        return null;
    }

    /** @param string $q
     * @param list $args
     * @return ?list<int> */
    private function select_ids($q, $args) {
        $result = $this->conf->qe_apply($q, $args);
        $a = [];
        while (($row = $result->fetch_row())) {
            $a[] = (int) $row[0];
        }
        Dbl::free($result);
        return $a;
    }

    /** @return ?list<int> */
    private function check_pc_tag() {
        $need = $neg = false;
        $x = strtolower($this->text);
        if (substr($x, 0, 1) === "-") {
            $need = $neg = true;
            $x = substr($x, 1);
        }
        if (substr($x, 0, 1) === "#") {
            $need = true;
            $x = substr($x, 1);
        }

        if ($this->conf->pc_tag_exists($x)
            && $this->user->can_view_user_tag($x)) {
            $a = [];
            $want_tag = !$neg || !($this->type & self::F_PC);
            foreach ($this->conf->pc_members() as $cid => $pc) {
                if ($pc->has_tag($x) === $want_tag)
                    $a[] = $cid;
            }
            if (!$neg || !$want_tag) {
                return $a;
            } else {
                return $this->select_ids("select contactId from ContactInfo where contactId?A", [$a]);
            }
        } else if ($need) {
            $this->warn_html = "No users are tagged “" . htmlspecialchars($this->text) . "”.";
            return [];
        } else {
            return null;
        }
    }

    /** @return list<int> */
    private function check_user() {
        if (strcasecmp($this->text, "anonymous") === 0
            && !$this->cset
            && !($this->type & self::F_PC)) {
            $regex = Dbl::utf8ci($this->conf->dblink, "'^anonymous[0-9]*\$'");
            return $this->select_ids("select contactId from ContactInfo where email regexp $regex", []);
        }

        // split name components
        list($f, $l, $e) = Text::split_name($this->text, true);
        if ($f !== "" && $l !== "") {
            $n = "$f $l";
        } else {
            $n = $f . $l;
        }
        if ($e === "" && strpos($n, " ") === false) {
            $e = $n;
        }

        // generalize email
        $estar = $e && strpos($e, "*") !== false;
        if ($e && !$estar) {
            if (preg_match('/\A(.*)@(.*?)((?:[.](?:com|net|edu|org|us|uk|fr|be|jp|cn))?)\z/', $e, $m)) {
                $e = ($m[1] === "" ? "*" : $m[1]) . "@*" . $m[2] . ($m[3] ? : "*");
            } else {
                $e = "*{$e}*";
            }
        }

        // contact database if not restricted to PC or cset
        $result = null;
        if ($this->cset) {
            $cs = $this->cset;
        } else if ($this->type & self::F_PC) {
            $cs = $this->conf->pc_members();
        } else {
            $where = [];
            if ($n !== "") {
                $x = sqlq(Dbl::escape_like(strtolower(UnicodeHelper::deaccent($n))));
                $where[] = "unaccentedName like cast('%" . preg_replace('/[\s*]+/', "%", $x) . "%' as binary)";
            }
            if ($e !== "") {
                $x = sqlq(Dbl::escape_like($e));
                $where[] = "email like " . Dbl::utf8ci("'" . preg_replace('/[\s*]+/', "%", $x) . "'");
            }
            $q = "select " . $this->conf->user_query_fields() . " from ContactInfo where " . join(" or ", $where);
            if ($this->type & self::F_ALLOW_DELETED) {
                $q .= " union select " . $this->conf->deleted_user_query_fields() . " from DeletedContactInfo where " . join(" or ", $where);
            }
            $result = $this->conf->qe_raw($q);
            $cs = [];
            while (($row = Contact::fetch($result, $this->conf))) {
                $cs[$row->contactId] = $row;
            }
        }

        // filter results
        $nreg = $ereg = null;
        if ($n !== "") {
            $nreg = Text::star_text_pregexes($n);
        }
        if ($e !== "" && $estar) {
            $ereg = '{\A' . str_replace('\*', '.*', preg_quote($e)) . '\z}i';
        } else if ($e !== "") {
            $ereg = str_replace('@\*', '@(?:|.*[.])', preg_quote($e));
            $ereg = preg_replace('/\A\\\\\*/', '(?:.*[@.]|)', $ereg);
            $ereg = '{\A' . preg_replace('/\\\\\*$/', '(?:[@.].*|)', $ereg) . '\z}i';
        }

        $ids = [];
        foreach ($cs as $id => $acct) {
            if ($ereg && preg_match($ereg, $acct->email)) {
                // exact email match trumps all else
                if (strcasecmp($e, $acct->email) == 0) {
                    $ids = [$id];
                    break;
                }
                $ids[] = $id;
            } else if ($nreg) {
                $n = $acct->searchable_name();
                if (Text::match_pregexes($nreg, $n, UnicodeHelper::deaccent($n))) {
                    $ids[] = $id;
                }
            }
        }

        if (count($ids) > 1) {
            $cf = $this->conf->user_comparator();
            usort($ids, function ($a, $b) use ($cs, $cf) {
                return call_user_func($cf, $cs[$a], $cs[$b]);
            });
        }

        Dbl::free($result);
        return $ids;
    }

    /** @return bool */
    function has_error() {
        return !$this->ok;
    }

    /** @return bool */
    function is_empty() {
        return empty($this->ids);
    }

    /** @return list<int> */
    function user_ids() {
        return $this->ids;
    }

    /** @return list<Contact> */
    function users() {
        if ($this->contacts === null) {
            foreach ($this->ids as $cid) {
                if ($this->cset === null || !isset($this->cset[$cid]))
                    $this->conf->prefetch_user_by_id($cid);
            }
            $this->contacts = [];
            foreach ($this->ids as $cid) {
                if (($p = $this->cset[$cid] ?? $this->conf->user_by_id($cid, USER_SLICE)))
                    $this->contacts[] = $p;
            }
        }
        return $this->contacts;
    }
}
