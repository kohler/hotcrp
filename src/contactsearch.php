<?php
// papersearch.php -- HotCRP helper class for searching for users
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class ContactSearch {
    const F_QUOTED = 1;
    const F_PC = 2;
    const F_USER = 4;
    const F_TAG = 8;
    const F_ALLOW_DELETED = 16;

    /** @var Conf */
    public $conf;
    /** @var int */
    public $type;
    public $text;
    /** @var Contact */
    private $user;
    private $cset = null;
    /** @var list<int> */
    private $ids;
    /** @var bool */
    private $ok;
    private $only_pc = false;
    /** @var ?list<Contact> */
    private $contacts = null;
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
        if (!($this->type & self::F_QUOTED) || $this->text === "") {
            $ids = $this->check_simple();
        }
        if ($ids === null
            && ($this->type & self::F_TAG)
            && !($this->type & self::F_QUOTED)
            && $this->user->can_view_user_tags()) {
            $ids = $this->check_pc_tag();
        }
        if ($ids === null
            && ($this->type & self::F_USER)) {
            $ids = $this->check_user();
        }
        $this->ids = $ids ?? [];
        $this->ok = $ids !== null;
    }
    static function make_pc($text, Contact $user) {
        return new ContactSearch(self::F_PC | self::F_TAG | self::F_USER, $text, $user);
    }
    static function make_special($text, Contact $user) {
        return new ContactSearch(self::F_PC | self::F_TAG, $text, $user);
    }
    static function make_cset($text, Contact $user, $cset) {
        return new ContactSearch(self::F_USER, $text, $user, $cset);
    }
    private function check_simple() {
        if (strcasecmp($this->text, "me") == 0
            && (!($this->type & self::F_PC)
                || ($this->user->roles & Contact::ROLE_PCLIKE))) {
            return [$this->user->contactId];
        }
        if ($this->user->can_view_pc()) {
            if ($this->text === ""
                || strcasecmp($this->text, "pc") === 0) {
                return array_keys($this->conf->pc_members());
            } else if (($this->type & self::F_PC)
                       && (strcasecmp($this->text, "any") === 0
                           || strcasecmp($this->text, "all") === 0)
                           || $this->text === "*") {
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
    private function select_ids($q, $args) {
        $result = $this->conf->qe_apply($q, $args);
        $a = [];
        while (($row = $result->fetch_row())) {
            $a[] = (int) $row[0];
        }
        Dbl::free($result);
        return $a;
    }
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
    private function check_user() {
        if (strcasecmp($this->text, "anonymous") == 0
            && !$this->cset
            && !($this->type & self::F_PC)) {
            return $this->select_ids("select contactId from ContactInfo where email regexp '^anonymous[0-9]*\$'", []);
        }

        // split name components
        list($f, $l, $e) = Text::split_name($this->text, true);
        $n = trim($f . " " . $l);
        if ($e === "" && strpos($n, " ") === false) {
            $e = $n;
        }

        // generalize email
        $estar = $e && strpos($e, "*") !== false;
        if ($e && !$estar) {
            if (preg_match('/\A(.*)@(.*?)((?:[.](?:com|net|edu|org|us|uk|fr|be|jp|cn))?)\z/', $e, $m)) {
                $e = ($m[1] === "" ? "*" : $m[1]) . "@*" . $m[2] . ($m[3] ? : "*");
            } else {
                $e = "*$e*";
            }
        }

        // contact database if not restricted to PC or cset
        $result = null;
        if ($this->cset) {
            $cs = $this->cset;
        } else if ($this->type & self::F_PC) {
            $cs = $this->conf->pc_members();
        } else {
            $where = array();
            if ($n !== "") {
                $x = sqlq_for_like(UnicodeHelper::deaccent($n));
                $where[] = "unaccentedName like " . Dbl::utf8ci("'%" . preg_replace('/[\s*]+/', "%", $x) . "%'");
            }
            if ($e !== "") {
                $x = sqlq_for_like($e);
                $where[] = "email like " . Dbl::utf8ci("'" . preg_replace('/[\s*]+/', "%", $x) . "'");
            }
            $q = "select contactId, firstName, lastName, unaccentedName, email, roles from ContactInfo where " . join(" or ", $where);
            if ($this->type & self::F_ALLOW_DELETED)
                $q .= " union select contactId, firstName, lastName, unaccentedName, email, 0 roles from DeletedContactInfo where " . join(" or ", $where);
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
                $n = $acct->firstName === "" || $acct->lastName === "" ? "" : " ";
                $n = $acct->firstName . $n . $acct->lastName;
                if (Text::match_pregexes($nreg, $n, $acct->unaccentedName)) {
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
        global $Me;
        if ($this->contacts === null) {
            $this->contacts = [];
            $pcm = $this->conf->pc_users();
            foreach ($this->ids as $cid) {
                if ($this->cset && ($p = $this->cset[$cid] ?? null)) {
                    $this->contacts[] = $p;
                } else if (($p = $pcm[$cid] ?? null)) {
                    $this->contacts[] = $p;
                } else if ($Me->contactId == $cid && $Me->conf === $this->conf) {
                    $this->contacts[] = $Me;
                } else {
                    $this->contacts[] = $this->conf->cached_user_by_id($cid);
                }
            }
        }
        return $this->contacts;
    }
    /** @param int $i
     * @return ?Contact */
    function user_by_index($i) {
        return ($this->users())[$i] ?? null;
    }
}
