<?php
// search/st_admin.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Admin_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var bool|list<Contact> */
    private $match;
    /** @var int */
    private $flags;
    const F_ALLOW = 1;
    const F_TRACKMGR = 2;

    /** @param bool|list<Contact> $match
     * @param int $flags */
    function __construct(Contact $user, $match, $flags) {
        parent::__construct("admin");
        $this->user = $user;
        $this->match = $match;
        $this->flags = $flags;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $flags = ($sword->kwdef->is_allow ?? false) ? self::F_ALLOW : 0;
        if (!$sword->quoted && $flags === 0) {
            $lword = strtolower($word);
            if ($lword === "" || $lword === "any" || $lword === "yes") {
                return new Admin_SearchTerm($srch->user, true, $flags);
            } else if ($lword === "none" || $lword === "no") {
                return new Admin_SearchTerm($srch->user, false, $flags);
            }
        }
        if ($word === "") {
            return new False_SearchTerm;
        }
        // searches only PC members; needs update if non-PC can be admins
        $match = $srch->user_search(ContactSearch::F_PC | ContactSearch::F_USER, $sword);
        if ($match->is_empty()) {
            return new False_SearchTerm;
        }
        foreach ($match->users() as $u) {
            if ($u->is_track_manager())
                $flags |= self::F_TRACKMGR;
        }
        return new Admin_SearchTerm($srch->user, $match->users(), $flags);
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->match === true) {
            return "Paper.managerContactId!=0";
        } else if ($this->match === false) {
            // Non-viewable manager looks like no manager
            return $this->user->privChair ? "Paper.managerContactId=0" : "true";
        }
        $where = [];
        if ($this->flags & self::F_TRACKMGR) {
            $tsm = new TagSearchMatcher($this->user);
            foreach ($this->match as $u) {
                if (($mttl = $u->managed_track_tags()) === null) {
                    return "true";
                }
                $tsm->add_tag_list($mttl);
            }
            $where[] = $tsm->exists_sqlexpr("Paper");
        }
        $uids = array_map(function ($p) { return $p->contactId; }, $this->match);
        $where[] = "Paper.managerContactId" . CountMatcher::sqlexpr_using($uids);
        return "(" . join(" or ", $where) . ")";
    }
    function test(PaperInfo $row, $xinfo) {
        if (!$this->user->can_view_manager($row)) {
            return $this->match === false;
        } else if (is_bool($this->match)) {
            return $this->match === ($row->managerContactId != 0);
        }
        foreach ($this->match as $u) {
            if ($this->flags & self::F_ALLOW
                ? $u->can_manage($row)
                : $u->is_primary_administrator($row))
                return true;
        }
        return false;
    }
}
