<?php
// contactsearch.php -- HotCRP helper class for searching for users
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class ContactSearch {
    const F_QUOTED = 1;
    const F_PC = 2;
    const F_USER = 4;
    const F_TAG = 8;
    const F_ALLOW_DELETED = 16;
    const F_USERID = 32;
    const F_REQUIRED = 64;

    /** @var Conf */
    public $conf;
    /** @var int */
    public $type;
    /** @var string */
    public $text;
    /** @var Contact */
    private $viewer;
    /** @var ?array<int,Contact> */
    private $cset;
    /** @var list<int> */
    private $ids;
    /** @var bool */
    private $ok;
    /** @var bool */
    private $is_roles = false;
    /** @var bool */
    private $only_pc = false;
    /** @var ?list<Contact> */
    private $contacts = null;
    /** @var int */
    private $viewable_roles;
    /** @var ?list<MessageItem> */
    private $ml;

    /** @param int $type
     * @param string $text
     * @param ?array<int,Contact> $cset */
    function __construct($type, $text, Contact $viewer, $cset = null) {
        $this->conf = $viewer->conf;
        $this->type = $type;
        $this->text = $text;
        $this->viewer = $viewer;
        $this->viewable_roles = $viewer->viewable_roles_mask();
        $this->cset = $cset;
        $ids = null;
        if (($this->type & self::F_QUOTED) === 0
            || $this->text === "") {
            $ids = $this->check_simple();
        }
        if ($ids === null
            && ($this->type & self::F_TAG) !== 0
            && ($this->type & self::F_QUOTED) === 0
            && $this->viewer->can_view_user_tags()) {
            $ids = $this->check_pc_tag();
        }
        if ($ids === null
            && ($this->type & self::F_USER) !== 0) {
            $ids = $this->check_user();
        }
        $this->ids = $ids ?? [];
        $this->ok = $ids !== null;
        if ($this->ids === []
            && ($this->type & self::F_REQUIRED) !== 0
            && empty($this->ml)) {
            if (($this->type & self::F_PC) !== 0) {
                if (!$this->viewer->can_view_pc()) {
                    $this->ml[] = MessageItem::warning("<0>You don’t have permission to search the PC");
                } else {
                    $this->ml[] = MessageItem::warning("<0>PC user ‘{$text}’ not found");
                }
            } else if ($this->viewer->is_manager()) {
                $this->ml[] = MessageItem::warning("<0>User ‘{$text}’ not found");
            }
        }
    }

    /** @param string $text
     * @return ContactSearch */
    static function make_pc($text, Contact $viewer) {
        return new ContactSearch(self::F_PC | self::F_TAG | self::F_USER, $text, $viewer);
    }

    /** @param string $text
     * @return ContactSearch */
    static function make_special($text, Contact $viewer) {
        return new ContactSearch(self::F_PC | self::F_TAG, $text, $viewer);
    }

    /** @param string $text
     * @param array<int,Contact> $cset
     * @return ContactSearch */
    static function make_cset($text, Contact $viewer, $cset) {
        return new ContactSearch(self::F_USER, $text, $viewer, $cset);
    }

    /** @return ?list<int> */
    private function check_simple() {
        if (strcasecmp($this->text, "me") == 0
            && (($this->type & self::F_PC) === 0
                || ($this->viewer->roles & Contact::ROLE_PCLIKE) !== 0)) {
            return [$this->viewer->contactId];
        }
        if (($this->type & (self::F_USERID | self::F_QUOTED)) === self::F_USERID
            && strspn($this->text, "0123456789 ,") === strlen($this->text)
            && ($this->cset !== null || $this->viewable_roles !== 0)) {
            preg_match_all('/\d++/', $this->text, $m);
            $uids1 = $uids2 = [];
            foreach ($m[0] as $s) {
                if (($uid = stoi($s) ?? 0) > 0) {
                    $this->cset !== null || $this->conf->prefetch_user_by_id($uid);
                    $uids1[] = $uid;
                }
            }
            foreach ($uids1 as $uid) {
                if ($this->cset !== null) {
                    $u = $this->cset[$uid] ?? null;
                } else if (($u = $this->conf->user_by_id($uid))
                           && ($this->viewer->privChair
                               ? ($this->type & self::F_PC) !== 0
                                 && ($u->roles & Contact::ROLE_PCLIKE) === 0
                               : ($u->roles & $this->viewable_roles) === 0)) {
                    $u = null;
                }
                if ($u) {
                    $uids2[] = $uid;
                }
            }
            return $uids2;
        }
        if ($this->viewable_roles !== 0) {
            $allow_dormant = true;
            if ($this->text === ""
                || strcasecmp($this->text, "pc") === 0) {
                $roles = Contact::ROLE_ANYPC;
            } else if (($this->type & self::F_PC) !== 0
                       && strcasecmp($this->text, "enabled") === 0) {
                $roles = Contact::ROLE_ANYPC;
                $allow_dormant = false;
            } else if (($this->type & self::F_PC) !== 0
                       && (strcasecmp($this->text, "any") === 0
                           || strcasecmp($this->text, "all") === 0
                           || $this->text === "*")) {
                $roles = Contact::ROLE_PCLIKE;
            } else if (strcasecmp($this->text, "chair") === 0) {
                $roles = Contact::ROLE_CHAIR;
            } else if (strcasecmp($this->text, "admin") === 0) {
                $roles = Contact::ROLE_CHAIR | Contact::ROLE_ADMIN;
            } else {
                $roles = 0;
            }
            $roles &= $this->viewable_roles;
            if ($roles !== 0) {
                $cids = [];
                foreach ($this->conf->pc_users() as $p) {
                    if (($p->roles & $roles) !== 0
                        && ($allow_dormant || !$p->is_dormant()))
                        $cids[] = $p->contactId;
                }
                $this->is_roles = true;
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
            && $this->viewer->can_view_user_tag($x)) {
            $a = [];
            $want_tag = !$neg || !($this->type & self::F_PC);
            foreach ($this->conf->viewable_pc_members($this->viewer) as $id => $pc) {
                if ($pc->has_tag($x) === $want_tag)
                    $a[] = $id;
            }
            if (!$neg || !$want_tag) {
                return $a;
            }
            return $this->select_ids("select contactId from ContactInfo where contactId?A", [$a]);
        } else if ($need) {
            if ($this->viewer->can_view_user_tags()) {
                $this->ml[] = MessageItem::warning("<0>User tag ‘{$this->text}’ not found");
            } else {
                $this->ml[] = MessageItem::warning("<0>You don’t have permission to search user tags");
            }
            return [];
        }
        return null;
    }

    /** @return list<int> */
    private function check_user() {
        if (strcasecmp($this->text, "anonymous") === 0
            && $this->cset === null
            && ($this->type & self::F_PC) === 0) {
            $regex = Dbl::utf8ci($this->conf->dblink, "'^anonymous[0-9]*\$'");
            return $this->select_ids("select contactId from ContactInfo where email regexp {$regex}", []);
        }

        // split name components
        [$f, $l, $e] = Text::split_name($this->text, true);
        if ($f !== "" && $l !== "") {
            $n = "{$f} {$l}";
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
        if ($this->cset !== null) {
            $cs = $this->cset;
        } else if (($this->type & self::F_PC) !== 0) {
            $cs = $this->conf->viewable_pc_members($this->viewer);
        } else if (ctype_digit($this->text)
                   && ($this->type & self::F_QUOTED) === 0) {
            // unquoted numeral must be a user ID
            $cs = [];
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
            $allow_deleted = ($this->type & self::F_ALLOW_DELETED) !== 0;
            if ($allow_deleted) {
                $q .= " union select " . $this->conf->deleted_user_query_fields() . " from DeletedContactInfo where " . join(" or ", $where);
            }
            $result = $this->conf->qe_raw($q);
            $cs = [];
            while (($row = Contact::fetch($result, $this->conf))) {
                if ($allow_deleted || !$row->is_deleted())
                    $cs[$row->contactId] = $row;
            }
            Dbl::free($result);
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
            } else if ($nreg && $nreg->match($acct->searchable_name())) {
                $ids[] = $id;
            }
        }

        if (count($ids) > 1) {
            $cf = $this->conf->user_comparator();
            usort($ids, function ($a, $b) use ($cs, $cf) {
                return call_user_func($cf, $cs[$a], $cs[$b]);
            });
        }

        return $ids;
    }

    /** @return bool */
    function is_roles() {
        return $this->is_roles;
    }

    /** @return bool */
    function resolved() {
        return $this->ok;
    }

    /** @return bool
     * @deprecated */
    function has_error() {
        return !$this->ok;
    }

    /** @return bool */
    function is_empty() {
        return empty($this->ids);
    }

    /** @return bool */
    function resolved_unique() {
        return count($this->ids) === 1;
    }

    /** @return list<MessageItem> */
    function message_list() {
        return $this->ml ?? [];
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

    /** @return ?Contact */
    function user1() {
        $us = $this->users();
        return count($us) === 1 ? $us[0] : null;
    }
}
