<?php
// paperinfo.php -- HotCRP paper objects
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class PaperContactInfo {
    public $paperId;
    public $contactId;
    public $conflictType = 0;
    public $reviewType = 0;
    public $reviewSubmitted = 0;
    public $review_status = 0;

    public $rights_forced = null;
    public $forced_rights_link = null;

    // set by Contact::rights()
    public $allow_administer;
    public $can_administer;
    public $allow_pc_broad;
    public $allow_pc;
    public $potential_reviewer;
    public $allow_review;
    public $act_author;
    public $allow_author;
    public $view_conflict_type;
    public $act_author_view;
    public $allow_author_view;
    public $nonblind;

    public $vsreviews_array;
    public $vsreviews_version;

    static function make_empty(PaperInfo $prow, $cid) {
        $ci = new PaperContactInfo;
        $ci->paperId = $prow->paperId;
        $ci->contactId = $cid;
        if ($cid > 0
            && isset($prow->leadContactId)
            && $prow->leadContactId == $cid)
            $ci->review_status = 1;
        return $ci;
    }

    static function make_my(PaperInfo $prow, $contact, $object) {
        $cid = is_object($contact) ? $contact->contactId : $contact;
        $ci = PaperContactInfo::make_empty($prow, $cid);
        $ci->conflictType = (int) $object->conflictType;
        if (property_exists($object, "myReviewPermissions")) {
            $ci->mark_my_review_permissions($object->myReviewPermissions);
        } else if ($object instanceof PaperInfo
                   && property_exists($object, "reviewSignatures")) {
            $rev_tokens = is_object($contact) ? $contact->review_tokens() : null;
            foreach ($object->reviews_of_user($cid, $rev_tokens) as $rrow)
                $ci->mark_review($rrow);
        }
        return $ci;
    }

    private function mark_conflict($ct) {
        $this->conflictType = max($ct, $this->conflictType);
    }

    private function mark_review_type($rt, $rs, $rns) {
        $this->reviewType = max($rt, $this->reviewType);
        $this->reviewSubmitted = max($rs, $this->reviewSubmitted);
        if ($rt > 0) {
            if ($rs > 0 || $rns == 0)
                $this->review_status = 1;
            else if ($this->review_status == 0)
                $this->review_status = -1;
        }
    }

    function mark_review(ReviewInfo $rrow) {
        $this->mark_review_type($rrow->reviewType, (int) $rrow->reviewSubmitted, $rrow->reviewNeedsSubmit);
    }

    private function mark_my_review_permissions($sig) {
        if ((string) $sig !== "") {
            foreach (explode(",", $sig) as $r) {
                list($rt, $rs, $rns) = explode(" ", $r);
                $this->mark_review_type((int) $rt, (int) $rs, (int) $rns);
            }
        }
    }

    static function load_into(PaperInfo $prow, $cid, $rev_tokens) {
        global $Me;
        $conf = $prow->conf;
        $pid = $prow->paperId;
        $q = "select conflictType, reviewType, reviewSubmitted, reviewNeedsSubmit";
        if ($cid
            && !$rev_tokens
            && ($row_set = $prow->_row_set)
            && $row_set->size() > 1) {
            $result = $conf->qe("$q, Paper.paperId paperId, ? contactId
                from Paper
                left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=?)
                left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=?)
                where Paper.paperId?a",
                $cid, $cid, $cid, $row_set->paper_ids());
            foreach ($row_set->all() as $row)
                $row->_clear_contact_info($cid);
            while ($result && ($local = $result->fetch_row())) {
                $row = $row_set->get($local[4]);
                $ci = $row->_get_contact_info($local[5]);
                $ci->mark_conflict((int) $local[0]);
                $ci->mark_review_type((int) $local[1], (int) $local[2], (int) $local[3]);
            }
            Dbl::free($result);
            return;
        }
        if ($cid
            && !$rev_tokens
            && (!$Me || ($Me->contactId != $cid
                         && ($Me->privChair || $Me->contactId == $prow->managerContactId)))
            && ($pcm = $conf->pc_members())
            && isset($pcm[$cid])) {
            $cids = array_keys($pcm);
            $result = $conf->qe("$q, ContactInfo.contactId
                from ContactInfo
                left join PaperConflict on (PaperConflict.paperId=? and PaperConflict.contactId=ContactInfo.contactId)
                left join PaperReview on (PaperReview.paperId=? and PaperReview.contactId=ContactInfo.contactId)
                where roles!=0 and (roles&" . Contact::ROLE_PC . ")!=0",
                $pid, $pid);
        } else {
            $cids = [$cid];
            if ($cid) {
                $q = "$q, ? contactId
                from (select ? paperId) P
                left join PaperConflict on (PaperConflict.paperId=? and PaperConflict.contactId=?)
                left join PaperReview on (PaperReview.paperId=? and (PaperReview.contactId=?";
                $qv = [$cid, $pid, $pid, $cid, $pid, $cid];
                if ($rev_tokens) {
                    $q .= " or PaperReview.reviewToken?a";
                    $qv[] = $rev_tokens;
                }
                $result = $conf->qe_apply("$q))", $qv);
            } else
                $result = null;
        }
        foreach ($cids as $cid)
            $prow->_clear_contact_info($cid);
        while ($result && ($local = $result->fetch_row())) {
            $ci = $prow->_get_contact_info($local[4]);
            $ci->mark_conflict((int) $local[0]);
            $ci->mark_review_type((int) $local[1], (int) $local[2], (int) $local[3]);
        }
        Dbl::free($result);
    }

    function get_forced_rights() {
        if (!$this->forced_rights_link) {
            $ci = $this->forced_rights_link = clone $this;
            $ci->vsreviews_array = null;
        }
        return $this->forced_rights_link;
    }
}

class PaperInfo_Conflict {
    public $contactId;
    public $conflictType;
    public $email;

    function __construct($cid, $ctype, $email = null) {
        $this->contactId = (int) $cid;
        $this->conflictType = (int) $ctype;
        $this->email = $email;
    }
}

class PaperInfoSet implements IteratorAggregate {
    private $prows = [];
    private $by_pid = [];
    public $loaded_allprefs = 0;
    function __construct(PaperInfo $prow = null) {
        if ($prow)
            $this->add($prow, true);
    }
    function add(PaperInfo $prow, $copy = false) {
        $this->prows[] = $prow;
        if (!isset($this->by_pid[$prow->paperId]))
            $this->by_pid[$prow->paperId] = $prow;
        if (!$copy) {
            assert(!$prow->_row_set);
            $prow->_row_set = $this;
        }
    }
    function take_all(PaperInfoSet $set) {
        foreach ($set->prows as $prow) {
            $prow->_row_set = null;
            $this->add($prow);
        }
        $set->prows = $set->by_pid = [];
    }
    function all() {
        return $this->prows;
    }
    function size() {
        return count($this->prows);
    }
    function is_empty() {
        return empty($this->prows);
    }
    function paper_ids() {
        return array_keys($this->by_pid);
    }
    function get($pid) {
        return get($this->by_pid, $pid);
    }
    function filter($func) {
        $next_set = new PaperInfoSet;
        foreach ($this as $prow)
            if (call_user_func($func, $prow))
                $next_set->add($prow, true);
        return $next_set;
    }
    function any($func) {
        foreach ($this as $prow)
            if (($x = call_user_func($func, $prow)))
                return $x;
        return false;
    }
    function getIterator() {
        return new ArrayIterator($this->prows);
    }
}

class PaperInfo {
    public $paperId;
    public $conf;
    public $title;
    public $authorInformation;
    public $abstract;
    public $collaborators;
    public $timeSubmitted;
    public $timeWithdrawn;
    public $paperStorageId;
    public $finalPaperStorageId;
    public $managerContactId;
    public $paperFormat;
    public $outcome;
    // $paperTags: DO NOT LIST (property_exists() is meaningful)
    // $optionIds: DO NOT LIST (property_exists() is meaningful)
    // $allConflictTypes: DO NOT LIST (property_exists() is meaningful)
    // $reviewSignatures: DO NOT LIST (property_exists() is meaningful)

    private $_unaccented_title;
    private $_contact_info = [];
    private $_rights_version = 0;
    private $_author_array;
    private $_collaborator_array;
    private $_prefs_array;
    private $_prefs_cid;
    private $_desirability;
    private $_topics_array;
    private $_topic_interest_score_array;
    private $_option_values;
    private $_option_data;
    private $_option_array;
    private $_all_option_array;
    private $_document_array;
    private $_conflict_array;
    private $_conflict_array_email;
    private $_review_array;
    private $_review_array_version = 0;
    private $_reviews_have = [];
    private $_full_review;
    private $_full_review_key;
    private $_comment_array;
    private $_comment_skeleton_array;
    private $_potential_conflicts;
    public $_row_set;

    const SUBMITTED_AT_FOR_WITHDRAWN = 1000000000;

    function __construct($p = null, $contact = null, Conf $conf = null) {
        $this->merge($p, $contact, $conf);
    }

    private function merge($p, $contact, $conf) {
        assert($contact === null ? $conf !== null : $contact instanceof Contact);
        $this->conf = $contact ? $contact->conf : $conf;
        if ($p)
            foreach ($p as $k => $v)
                $this->$k = $v;
        $this->paperId = (int) $this->paperId;
        $this->managerContactId = (int) $this->managerContactId;
        if ($contact && (property_exists($this, "myReviewPermissions")
                         || property_exists($this, "reviewSignatures"))) {
            $this->_rights_version = Contact::$rights_version;
            $this->load_my_contact_info($contact, $this);
        } else if ($contact && property_exists($this, "conflictType")) {
            error_log("conflictType exists but myReviewPermissions does not " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        }
        foreach (["paperTags", "optionIds"] as $k)
            if (property_exists($this, $k) && $this->$k === null)
                $this->$k = "";
    }

    static function fetch($result, $contact, Conf $conf = null) {
        $prow = $result ? $result->fetch_object("PaperInfo", [null, $contact, $conf]) : null;
        if ($prow && !is_int($prow->paperId))
            $prow->merge(null, $contact, $conf);
        return $prow;
    }

    static function table_name() {
        return "Paper";
    }

    static function id_column() {
        return "paperId";
    }

    static function comment_table_name() {
        return "PaperComment";
    }

    static function my_review_permissions_sql($prefix = "") {
        return "group_concat({$prefix}reviewType, ' ', coalesce({$prefix}reviewSubmitted,0), ' ', reviewNeedsSubmit)";
    }

    function make_whynot($rest = []) {
        return ["fail" => true, "paperId" => $this->paperId, "conf" => $this->conf] + $rest;
    }


    static private function contact_to_cid($contact) {
        global $Me;
        assert($contact !== null);
        if ($contact && is_object($contact))
            return $contact->contactId;
        else
            return $contact !== null ? $contact : $Me->contactId;
    }

    function _get_contact_info($cid) {
        return get($this->_contact_info, $cid);
    }

    function _clear_contact_info($cid) {
        $this->_contact_info[$cid] = PaperContactInfo::make_empty($this, $cid);
    }

    private function update_rights_version() {
        if ($this->_rights_version !== Contact::$rights_version) {
            if ($this->_rights_version) {
                $this->_contact_info = $this->_reviews_have = [];
                $this->_review_array = $this->_conflict_array = null;
                ++$this->_review_array_version;
                unset($this->reviewSignatures, $this->allConflictType);
            }
            $this->_rights_version = Contact::$rights_version;
        }
    }

    function contact_info($contact) {
        global $Me;
        $this->update_rights_version();
        $rev_tokens = null;
        if (!$contact || !is_object($contact))
            error_log("PaperInfo::contact_info bad argument: " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        if (!$contact || is_object($contact)) {
            $contact = $contact ? : $Me;
            $rev_tokens = $contact->review_tokens();
        }
        $cid = self::contact_to_cid($contact);
        if (!array_key_exists($cid, $this->_contact_info)) {
            if ($this->_review_array
                || property_exists($this, "reviewSignatures")) {
                $ci = PaperContactInfo::make_empty($this, $cid);
                if (($c = get($this->conflicts(), $cid)))
                    $ci->conflictType = $c->conflictType;
                foreach ($this->reviews_of_user($cid, $rev_tokens) as $rrow)
                    $ci->mark_review($rrow);
                $this->_contact_info[$cid] = $ci;
            } else
                PaperContactInfo::load_into($this, $cid, $rev_tokens);
        }
        return $this->_contact_info[$cid];
    }

    function replace_contact_info_map($cimap) {
        $old_cimap = $this->_contact_info;
        $this->_contact_info = $cimap;
        $this->_rights_version = Contact::$rights_version;
        return $old_cimap;
    }

    function load_my_contact_info($contact, $object) {
        $ci = PaperContactInfo::make_my($this, $contact, $object);
        $this->_contact_info[$ci->contactId] = $ci;
    }


    function unaccented_title() {
        if ($this->_unaccented_title === null)
            $this->_unaccented_title = UnicodeHelper::deaccent($this->title);
        return $this->_unaccented_title;
    }

    function pretty_text_title_indent($width = 75) {
        $n = "Paper #{$this->paperId}: ";
        $vistitle = $this->unaccented_title();
        $l = (int) (($width + 0.5 - strlen($vistitle) - strlen($n)) / 2);
        return strlen($n) + max(0, $l);
    }

    function pretty_text_title($width = 75) {
        $l = $this->pretty_text_title_indent($width);
        return prefix_word_wrap("Paper #{$this->paperId}: ", $this->title, $l);
    }

    function format_of($text, $check_simple = false) {
        return $this->conf->check_format($this->paperFormat, $check_simple ? $text : null);
    }

    function title_format() {
        return $this->format_of($this->title, true);
    }

    function abstract_format() {
        return $this->format_of($this->abstract, true);
    }

    function edit_format() {
        return $this->conf->format_info($this->paperFormat);
    }

    function author_list() {
        if (!isset($this->_author_array)) {
            $this->_author_array = array();
            foreach (explode("\n", $this->authorInformation) as $line)
                if ($line != "")
                    $this->_author_array[] = Author::make_tabbed($line);
        }
        return $this->_author_array;
    }

    function author_by_email($email) {
        foreach ($this->author_list() as $a)
            if (strcasecmp($a->email, $email) == 0 && (string) $email !== "")
                return $a;
        return null;
    }

    function parse_author_list() {
        $ai = "";
        foreach ($this->_author_array as $au)
            $ai .= $au->firstName . "\t" . $au->lastName . "\t" . $au->email . "\t" . $au->affiliation . "\n";
        return ($this->authorInformation = $ai);
    }

    function pretty_text_author_list() {
        $info = "";
        foreach ($this->author_list() as $au) {
            $info .= $au->name() ? : $au->email;
            if ($au->affiliation)
                $info .= " (" . $au->affiliation . ")";
            $info .= "\n";
        }
        return $info;
    }

    function conflict_type($contact) {
        $cid = self::contact_to_cid($contact);
        if (array_key_exists($cid, $this->_contact_info))
            return $this->_contact_info[$cid]->conflictType;
        else if (($ci = get($this->conflicts(), $cid)))
            return $ci->conflictType;
        else
            return 0;
    }

    function has_conflict($contact) {
        return $this->conflict_type($contact) > 0;
    }

    function has_author($contact) {
        return $this->conflict_type($contact) >= CONFLICT_AUTHOR;
    }

    function collaborator_list() {
        if ($this->_collaborator_array === null) {
            $this->_collaborator_array = [];
            foreach (explode("\n", (string) $this->collaborators) as $co)
                if (($m = AuthorMatcher::make_collaborator_line($co)))
                    $this->_collaborator_array[] = $m;
        }
        return $this->_collaborator_array;
    }

    function potential_conflict_callback(Contact $user, $callback) {
        $nproblems = $auproblems = 0;
        if ($this->field_match_pregexes($user->aucollab_general_pregexes(), "authorInformation")) {
            foreach ($this->author_list() as $n => $au)
                foreach ($user->aucollab_matchers() as $matcher) {
                    if (($why = $matcher->test($au, $matcher->nonauthor))) {
                        if (!$callback)
                            return true;
                        $auproblems |= $why;
                        ++$nproblems;
                        call_user_func($callback, $user, $matcher, $au, $n + 1, $why);
                    }
                }
        }
        if ((string) $this->collaborators !== "") {
            $aum = $user->full_matcher();
            if (Text::match_pregexes($aum->general_pregexes(), $this->collaborators, UnicodeHelper::deaccent($this->collaborators))) {
                foreach ($this->collaborator_list() as $co)
                    if (($co->lastName !== ""
                         || !($auproblems & AuthorMatcher::MATCH_AFFILIATION))
                        && ($why = $aum->test($co, true))) {
                        if (!$callback)
                            return true;
                        ++$nproblems;
                        call_user_func($callback, $user, $aum, $co, 0, $why);
                    }
            }
        }
        return $nproblems > 0;
    }

    function potential_conflict(Contact $user) {
        return $this->potential_conflict_callback($user, null);
    }

    function _potential_conflict_html_callback($user, $matcher, $conflict, $aunum, $why) {
        if ($aunum) {
            if ($matcher->nonauthor) {
                $aumatcher = new AuthorMatcher($conflict);
                $what = "PC collaborator " . $aumatcher->highlight($matcher) . "<br>matches author #$aunum " . $matcher->highlight($conflict);
            } else if ($why == AuthorMatcher::MATCH_AFFILIATION)
                $what = "PC affiliation matches author #$aunum affiliation " . $matcher->highlight($conflict->affiliation);
            else
                $what = "PC name matches author #$aunum name " . $matcher->highlight($conflict->name());
            $this->_potential_conflicts[] = ["#$aunum", '<div class="mmm">' . $what . '</div>'];
        } else {
            if ($why == AuthorMatcher::MATCH_AFFILIATION)
                $what = "PC affiliation matches paper collaborator ";
            else
                $what = "PC name matches paper collaborator ";
            $this->_potential_conflicts[] = ["other conflicts", '<div class="mmm">' . $what . $matcher->highlight($conflict) . '</div>'];
        }
    }

    function potential_conflict_html(Contact $user, $highlight = false) {
        $this->_potential_conflicts = [];
        if (!$this->potential_conflict_callback($user, [$this, "_potential_conflict_html_callback"]))
            return false;
        usort($this->_potential_conflicts, function ($a, $b) { return strnatcmp($a[0], $b[0]); });
        $authors = array_unique(array_map(function ($x) { return $x[0]; }, $this->_potential_conflicts));
        $authors = array_filter($authors, function ($f) { return $f !== "other conflicts"; });
        $messages = join("", array_map(function ($x) { return $x[1]; }, $this->_potential_conflicts));
        $this->_potential_conflicts = null;
        return ['<div class="pcconfmatch'
            . ($highlight ? " pcconfmatch-highlight" : "")
            . '">Possible conflict'
            . (empty($authors) ? "" : " with " . pluralx($authors, "author") . " " . numrangejoin($authors))
            . '…</div>', $messages];
    }

    function field_match_pregexes($reg, $field) {
        $data = $this->$field;
        $field_deaccent = $field . "_deaccent";
        if (!isset($this->$field_deaccent)) {
            if (preg_match('/[\x80-\xFF]/', $data))
                $this->$field_deaccent = UnicodeHelper::deaccent($data);
            else
                $this->$field_deaccent = false;
        }
        return Text::match_pregexes($reg, $data, $this->$field_deaccent);
    }

    function submitted_at() {
        if ($this->timeSubmitted > 0)
            return (int) $this->timeSubmitted;
        if ($this->timeWithdrawn > 0) {
            if ($this->timeSubmitted == -100)
                return self::SUBMITTED_AT_FOR_WITHDRAWN;
            if ($this->timeSubmitted < -100)
                return -(int) $this->timeSubmitted;
        }
        return 0;
    }

    function can_author_view_decision() {
        return $this->conf->can_all_author_view_decision();
    }

    function review_type($contact) {
        $this->update_rights_version();
        $cid = self::contact_to_cid($contact);
        if (array_key_exists($cid, $this->_contact_info))
            $rrow = $this->_contact_info[$cid];
        else
            $rrow = $this->review_of_user($cid);
        return $rrow ? $rrow->reviewType : 0;
    }

    function has_reviewer($contact) {
        return $this->review_type($contact) > 0;
    }

    function review_not_incomplete($contact) {
        $ci = $this->contact_info($contact);
        return $ci && $ci->review_status > 0;
    }

    function review_submitted($contact) {
        $ci = $this->contact_info($contact);
        return $ci && $ci->reviewType > 0 && $ci->reviewSubmitted > 0;
    }

    function pc_can_become_reviewer() {
        if (!$this->conf->check_track_review_sensitivity())
            return $this->conf->pc_members();
        else {
            $pcm = array();
            foreach ($this->conf->pc_members() as $cid => $pc)
                if ($pc->can_become_reviewer_ignore_conflict($this))
                    $pcm[$cid] = $pc;
            return $pcm;
        }
    }

    function load_tags() {
        $result = $this->conf->qe("select group_concat(' ', tag, '#', tagIndex order by tag separator '') from PaperTag where paperId=? group by paperId", $this->paperId);
        $this->paperTags = "";
        if (($row = edb_row($result)) && $row[0] !== null)
            $this->paperTags = $row[0];
        Dbl::free($result);
    }

    function has_tag($tag) {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        return $this->paperTags !== ""
            && stripos($this->paperTags, " $tag#") !== false;
    }

    function has_any_tag($tags) {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        foreach ($tags as $tag)
            if (stripos($this->paperTags, " $tag#") !== false)
                return true;
        return false;
    }

    function has_viewable_tag($tag, Contact $user) {
        $tags = $this->viewable_tags($user);
        return $tags !== "" && stripos(" " . $tags, " $tag#") !== false;
    }

    function tag_value($tag) {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        if ($this->paperTags !== ""
            && ($pos = stripos($this->paperTags, " $tag#")) !== false)
            return (float) substr($this->paperTags, $pos + strlen($tag) + 2);
        else
            return false;
    }

    function all_tags_text() {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        return $this->paperTags;
    }

    function searchable_tags(Contact $user) {
        if ($user->allow_administer($this))
            return $this->all_tags_text();
        else
            return $this->viewable_tags($user);
    }

    function viewable_tags(Contact $user) {
        // see also Contact::can_view_tag()
        $tags = "";
        if ($user->isPC)
            $tags = (string) $this->all_tags_text();
        if ($tags !== "") {
            $dt = $this->conf->tags();
            if ($user->can_view_most_tags($this))
                $tags = $dt->strip_nonviewable($tags, $user, $this);
            else if ($dt->has_sitewide && $user->can_view_tags($this))
                $tags = Tagger::strip_nonsitewide($tags, $user);
            else
                $tags = "";
        }
        return $tags;
    }

    function editable_tags(Contact $user) {
        $tags = $this->all_tags_text();
        if ($tags !== "") {
            $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
            $tags = $this->viewable_tags($user);
            if ($tags !== "") {
                $etags = [];
                foreach (explode(" ", $tags) as $tag)
                    if ($tag !== "" && $user->can_change_tag($this, $tag, 0, 1))
                        $etags[] = $tag;
                $tags = join(" ", $etags);
            }
            $user->set_overrides($old_overrides);
        }
        return $tags;
    }

    function add_tag_info_json($pj, Contact $user) {
        $tagger = new Tagger($user);
        if (($can_override = $user->can_meaningfully_override($this)))
            $overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $editable = $this->editable_tags($user);
        $viewable = $this->viewable_tags($user);
        $pj->tags = TagInfo::split($viewable);
        $pj->tags_edit_text = $tagger->unparse($editable);
        $pj->tags_view_html = $tagger->unparse_and_link($viewable);
        if (($decor = $tagger->unparse_decoration_html($viewable)))
            $pj->tag_decoration_html = $decor;
        $tagmap = $this->conf->tags();
        $pj->color_classes = $tagmap->color_classes($viewable);
        if ($can_override && $viewable) {
            $user->remove_overrides(Contact::OVERRIDE_CONFLICT);
            $viewable_c = $this->viewable_tags($user);
            if ($viewable_c !== $viewable) {
                $pj->tags_conflicted = TagInfo::split($viewable_c);
                if ($decor
                    && ($decor_c = $tagger->unparse_decoration_html($viewable_c)) !== $decor)
                    $pj->tag_decoration_html_conflicted = $decor_c;
                if ($pj->color_classes
                    && ($cc_c = $tagmap->color_classes($viewable_c)) !== $pj->color_classes)
                    $pj->color_classes_conflicted = $cc_c;
            }
        }
        if ($can_override)
            $user->set_overrides($overrides);
    }

    private function load_topics() {
        $row_set = $this->_row_set ? : new PaperInfoSet($this);
        foreach ($row_set as $prow)
            $prow->topicIds = null;
        if ($this->conf->has_topics()) {
            $result = $this->conf->qe("select paperId, group_concat(topicId) from PaperTopic where paperId?a group by paperId", $row_set->paper_ids());
            while ($result && ($row = $result->fetch_row())) {
                $prow = $row_set->get($row[0]);
                $prow->topicIds = (string) $row[1];
            }
            Dbl::free($result);
        }
    }

    function has_topics() {
        if (!property_exists($this, "topicIds"))
            $this->load_topics();
        return $this->topicIds !== null && $this->topicIds !== "";
    }

    function topic_list() {
        if ($this->_topics_array === null) {
            if (!property_exists($this, "topicIds"))
                $this->load_topics();
            $this->_topics_array = [];
            if ($this->topicIds !== null && $this->topicIds !== "") {
                foreach (explode(",", $this->topicIds) as $t)
                    $this->_topics_array[] = (int) $t;
                $tomap = $this->conf->topic_order_map();
                usort($this->_topics_array, function ($a, $b) use ($tomap) {
                    return $tomap[$a] - $tomap[$b];
                });
            }
        }
        return $this->_topics_array;
    }

    function topic_map() {
        return array_fill_keys($this->topic_list(), true);
    }

    function named_topic_map() {
        $t = [];
        foreach ($this->topic_list() as $tid) {
            if (empty($t))
                $tmap = $this->conf->topic_map();
            $t[$tid] = $tmap[$tid];
        }
        return $t;
    }

    function unparse_topics_text() {
        return join("; ", $this->named_topic_map());
    }

    private static function render_topic($tname, $i, &$long) {
        $s = '<span class="topicsp topic' . ($i ? : 0);
        if (strlen($tname) <= 50)
            $s .= ' nw';
        else
            $long = true;
        return $s . '">' . htmlspecialchars($tname) . '</span>';
    }

    static function unparse_topic_list_html(Conf $conf, $ti) {
        if (!$ti)
            return "";
        $out = array();
        $tmap = $conf->topic_map();
        $tomap = $conf->topic_order_map();
        $long = false;
        foreach ($ti as $t => $i)
            $out[$tomap[$t]] = self::render_topic($tmap[$t], $i, $long);
        ksort($out);
        return join($conf->topic_separator(), $out);
    }

    private static $topic_interest_values = [-0.7071, -0.5, 0, 0.7071, 1];

    function topic_interest_score($contact) {
        $score = 0;
        if (is_int($contact))
            $contact = get($this->conf->pc_members(), $contact);
        if ($contact) {
            if ($this->_topic_interest_score_array === null)
                $this->_topic_interest_score_array = array();
            if (isset($this->_topic_interest_score_array[$contact->contactId]))
                $score = $this->_topic_interest_score_array[$contact->contactId];
            else {
                $interests = $contact->topic_interest_map();
                $topics = $this->topic_list();
                foreach ($topics as $t)
                    if (($j = get($interests, $t, 0))) {
                        if ($j >= -2 && $j <= 2)
                            $score += self::$topic_interest_values[$j + 2];
                        else if ($j > 2)
                            $score += sqrt($j / 2);
                        else
                            $score += -sqrt(-$j / 4);
                    }
                if ($score)
                    // * Strong interest in the paper's single topic gets
                    //   score 10.
                    $score = (int) ($score / sqrt(count($topics)) * 10 + 0.5);
                $this->_topic_interest_score_array[$contact->contactId] = $score;
            }
        }
        return $score;
    }


    function load_conflicts($email) {
        if (!$email && isset($this->allConflictType)) {
            $this->_conflict_array = [];
            $this->_conflict_array_email = $email;
            foreach (explode(",", $this->allConflictType) as $x) {
                list($cid, $ctype) = explode(" ", $x);
                $cflt = new PaperInfo_Conflict($cid, $ctype);
                $this->_conflict_array[$cflt->contactId] = $cflt;
            }
        } else {
            $row_set = $this->_row_set ? : new PaperInfoSet($this);
            foreach ($row_set->all() as $prow) {
                $prow->_conflict_array = [];
                $prow->_conflict_array_email = $email;
            }
            if ($email)
                $result = $this->conf->qe("select paperId, PaperConflict.contactId, conflictType, email from PaperConflict join ContactInfo using (contactId) where paperId?a", $row_set->paper_ids());
            else
                $result = $this->conf->qe("select paperId, contactId, conflictType, null from PaperConflict where paperId?a", $row_set->paper_ids());
            while ($result && ($row = $result->fetch_row())) {
                $prow = $row_set->get($row[0]);
                $cflt = new PaperInfo_Conflict($row[1], $row[2], $row[3]);
                $prow->_conflict_array[$cflt->contactId] = $cflt;
            }
            Dbl::free($result);
        }
    }

    function conflicts($email = false) {
        if ($this->_conflict_array === null
            || ($email && !$this->_conflict_array_email))
            $this->load_conflicts($email);
        return $this->_conflict_array;
    }

    function pc_conflicts($email = false) {
        return array_intersect_key($this->conflicts($email), $this->conf->pc_members());
    }

    function contacts($email = false) {
        $c = array();
        foreach ($this->conflicts($email) as $id => $cflt)
            if ($cflt->conflictType >= CONFLICT_AUTHOR)
                $c[$id] = $cflt;
        return $c;
    }

    function named_contacts() {
        $vals = Dbl::fetch_objects($this->conf->qe("select ContactInfo.contactId, conflictType, email, firstName, lastName, affiliation from PaperConflict join ContactInfo using (contactId) where paperId=$this->paperId and conflictType>=" . CONFLICT_AUTHOR));
        foreach ($vals as $v) {
            $v->contactId = (int) $v->contactId;
            $v->conflictType = (int) $v->conflictType;
        }
        return $vals;
    }

    function load_reviewer_preferences() {
        if ($this->_row_set && ++$this->_row_set->loaded_allprefs >= 10)
            $row_set = $this->_row_set->filter(function ($prow) {
                return !property_exists($prow, "allReviewerPreference");
            });
        else
            $row_set = new PaperInfoSet($this);
        foreach ($row_set as $prow) {
            $prow->allReviewerPreference = null;
            $prow->_prefs_array = $prow->_prefs_cid = $prow->_desirability = null;
        }
        $result = $this->conf->qe("select paperId, " . $this->conf->query_all_reviewer_preference() . " from PaperReviewPreference where paperId?a group by paperId", $row_set->paper_ids());
        while ($result && ($row = $result->fetch_row())) {
            $prow = $row_set->get($row[0]);
            $prow->allReviewerPreference = $row[1];
        }
        Dbl::free($result);
    }

    function reviewer_preferences() {
        if (!property_exists($this, "allReviewerPreference"))
            $this->load_reviewer_preferences();
        if ($this->_prefs_array === null) {
            $x = array();
            if ($this->allReviewerPreference !== null && $this->allReviewerPreference !== "") {
                $p = preg_split('/[ ,]/', $this->allReviewerPreference);
                for ($i = 0; $i + 2 < count($p); $i += 3) {
                    if ($p[$i+1] != "0" || $p[$i+2] != ".")
                        $x[(int) $p[$i]] = array((int) $p[$i+1], $p[$i+2] == "." ? null : (int) $p[$i+2]);
                }
            }
            $this->_prefs_array = $x;
        }
        return $this->_prefs_array;
    }

    function reviewer_preference($contact, $include_topic_score = false) {
        $cid = is_int($contact) ? $contact : $contact->contactId;
        if ($this->_prefs_cid === null && $this->_prefs_array === null) {
            $row_set = $this->_row_set ? : new PaperInfoSet($this);
            foreach ($row_set as $prow)
                $prow->_prefs_cid = [$cid, null];
            $result = $this->conf->qe("select paperId, preference, expertise from PaperReviewPreference where paperId?a and contactId=?", $row_set->paper_ids(), $cid);
            while ($result && ($row = $result->fetch_row())) {
                $prow = $row_set->get($row[0]);
                $prow->_prefs_cid[1] = [(int) $row[1], $row[2] === null ? null : (int) $row[2]];
            }
            Dbl::free($result);
        }
        if ($this->_prefs_cid !== null && $this->_prefs_cid[0] == $cid)
            $pref = $this->_prefs_cid[1];
        else
            $pref = get($this->reviewer_preferences(), $cid);
        $pref = $pref ? : [0, null];
        if ($include_topic_score)
            $pref[] = $this->topic_interest_score($contact);
        return $pref;
    }

    function desirability() {
        if ($this->_desirability === null) {
            $prefs = $this->reviewer_preferences();
            $this->_desirability = 0;
            foreach ($prefs as $pf) {
                if ($pf[0] > 0)
                    $this->_desirability += 1;
                else if ($pf[0] > -100 && $pf[0] < 0)
                    $this->_desirability -= 1;
            }
        }
        return $this->_desirability;
    }

    private function load_options($only_me, $need_data) {
        if ($this->_option_values === null
            && isset($this->optionIds)
            && (!$need_data || $this->optionIds === "")) {
            if ($this->optionIds === "")
                $this->_option_values = $this->_option_data = [];
            else {
                $this->_option_values = [];
                preg_match_all('/(\d+)#(-?\d+)/', $this->optionIds, $m);
                for ($i = 0; $i < count($m[1]); ++$i)
                    $this->_option_values[(int) $m[1][$i]][] = (int) $m[2][$i];
            }
        } else if ($this->_option_values === null
                   || ($need_data && $this->_option_data === null)) {
            $old_row_set = $this->_row_set;
            if ($only_me)
                $this->_row_set = null;
            $row_set = $this->_row_set ? : new PaperInfoSet($this);
            foreach ($row_set->all() as $prow)
                $prow->_option_values = $prow->_option_data = [];
            $result = $this->conf->qe("select paperId, optionId, value, data, dataOverflow from PaperOption where paperId?a order by paperId", $row_set->paper_ids());
            while ($result && ($row = $result->fetch_row())) {
                $prow = $row_set->get((int) $row[0]);
                $prow->_option_values[(int) $row[1]][] = (int) $row[2];
                $prow->_option_data[(int) $row[1]][] = $row[3] !== null ? $row[3] : $row[4];
            }
            Dbl::free($result);
            if ($only_me)
                $this->_row_set = $old_row_set;
        }
    }

    private function _make_option_array($all) {
        $this->load_options(false, false);
        $paper_opts = $this->conf->paper_opts;
        $option_array = [];
        foreach ($this->_option_values as $oid => $ovalues)
            if (($o = $paper_opts->get($oid, $all)))
                $option_array[$oid] = new PaperOptionValue($this, $o, $ovalues, get($this->_option_data, $oid));
        uasort($option_array, function ($a, $b) {
            if ($a->option && $b->option)
                return PaperOption::compare($a->option, $b->option);
            else if ($a->option || $b->option)
                return $a->option ? -1 : 1;
            else
                return $a->id - $b->id;
        });
        return $option_array;
    }

    function option_value_data($id) {
        if ($this->_option_data === null)
            $this->load_options(false, true);
        return [get($this->_option_values, $id, []),
                get($this->_option_data, $id, [])];
    }

    function options() {
        if ($this->_option_array === null)
            $this->_option_array = $this->_make_option_array(false);
        return $this->_option_array;
    }

    function option($id) {
        return get($this->options(), $id);
    }

    function force_option($id) {
        if (($oa = get($this->options(), $id)))
            return $oa;
        else if (($opt = $this->conf->paper_opts->get($id)))
            return new PaperOptionValue($this, $opt);
        else
            return null;
    }

    function all_options() {
        if ($this->_all_option_array === null)
            $this->_all_option_array = $this->_make_option_array(true);
        return $this->_all_option_array;
    }

    function all_option($id) {
        return get($this->all_options(), $id);
    }

    function invalidate_options($reload = false) {
        unset($this->optionIds);
        $this->_option_array = $this->_all_option_array =
            $this->_option_values = $this->_option_data = null;
        if ($reload)
            $this->load_options(true, true);
    }

    private function _document_sql() {
        return "paperId, paperStorageId, timestamp, mimetype, sha1, documentType, filename, infoJson, size, filterType, originalStorageId, inactive";
    }

    function document($dtype, $did = 0, $full = false) {
        if ($did <= 0) {
            if ($dtype == DTYPE_SUBMISSION)
                $did = $this->paperStorageId;
            else if ($dtype == DTYPE_FINAL)
                $did = $this->finalPaperStorageId;
            else if (($oa = $this->force_option($dtype))
                     && $oa->option->is_document())
                return $oa->document(0);
        }

        if ($did <= 1)
            return null;

        if ($this->_document_array !== null
            && array_key_exists($did, $this->_document_array))
            return $this->_document_array[$did];

        if ((($dtype == DTYPE_SUBMISSION
              && $did == $this->paperStorageId
              && $this->finalPaperStorageId <= 0)
             || ($dtype == DTYPE_FINAL
                 && $did == $this->finalPaperStorageId))
            && !$full) {
            $infoJson = get($this, $dtype == DTYPE_SUBMISSION ? "paper_infoJson" : "final_infoJson");
            return new DocumentInfo(["paperStorageId" => $did, "paperId" => $this->paperId, "documentType" => $dtype, "timestamp" => get($this, "timestamp"), "mimetype" => $this->mimetype, "sha1" => $this->sha1, "size" => get($this, "size"), "infoJson" => $infoJson, "is_partial" => true], $this->conf, $this);
        }

        if ($this->_document_array === null) {
            $result = $this->conf->qe("select " . $this->_document_sql() . " from PaperStorage where paperId=? and inactive=0", $this->paperId);
            $this->_document_array = [];
            while (($di = DocumentInfo::fetch($result, $this->conf, $this)))
                $this->_document_array[$di->paperStorageId] = $di;
            Dbl::free($result);
        }
        if (!array_key_exists($did, $this->_document_array)) {
            $result = $this->conf->qe("select " . $this->_document_sql() . " from PaperStorage where paperStorageId=?", $did);
            $this->_document_array[$did] = DocumentInfo::fetch($result, $this->conf, $this);
            Dbl::free($result);
        }
        return $this->_document_array[$did];
    }
    function joindoc() {
        return $this->document($this->finalPaperStorageId > 0 ? DTYPE_FINAL : DTYPE_SUBMISSION);
    }
    function is_joindoc(DocumentInfo $doc) {
        return $doc->paperStorageId > 1
            && (($doc->paperStorageId == $this->paperStorageId
                 && $this->finalPaperStorageId <= 0
                 && $doc->documentType == DTYPE_SUBMISSION)
                || ($doc->paperStorageId == $this->finalPaperStorageId
                    && $doc->documentType == DTYPE_FINAL));
    }
    function documents($dtype) {
        if ($dtype <= 0) {
            $doc = $this->document($dtype, 0, true);
            return $doc ? [$doc] : [];
        } else if (($oa = $this->option($dtype)) && $oa->has_document())
            return $oa->documents();
        else
            return [];
    }
    function mark_inactive_documents() {
        $dids = [];
        if ($this->paperStorageId > 1)
            $dids[] = $this->paperStorageId;
        if ($this->finalPaperStorageId > 1)
            $dids[] = $this->finalPaperStorageId;
        foreach ($this->options() as $oa)
            if ($oa->option->has_document())
                $dids = array_merge($dids, $oa->unsorted_values());
        $this->conf->qe("update PaperStorage set inactive=1 where paperId=? and documentType>=? and paperStorageId?A", $this->paperId, DTYPE_FINAL, $dids);
    }

    function attachment($dtype, $name) {
        $oa = $this->option($dtype);
        return $oa ? $oa->attachment($name) : null;
    }

    function npages() {
        $doc = $this->document($this->finalPaperStorageId <= 0 ? DTYPE_SUBMISSION : DTYPE_FINAL);
        return $doc ? $doc->npages() : 0;
    }

    private function ratings_query() {
        if ($this->conf->setting("rev_ratings") != REV_RATINGS_NONE)
            return "(select group_concat(contactId, ' ', rating) from ReviewRating where paperId=PaperReview.paperId and reviewId=PaperReview.reviewId)";
        else
            return "''";
    }

    function load_reviews($always = false) {
        ++$this->_review_array_version;

        if (property_exists($this, "reviewSignatures")
            && $this->_review_array === null
            && !$always) {
            $this->_review_array = $this->_reviews_have = [];
            if ((string) $this->reviewSignatures !== "")
                foreach (explode(",", $this->reviewSignatures) as $rs) {
                    $rrow = ReviewInfo::make_signature($this, $rs);
                    $this->_review_array[$rrow->reviewId] = $rrow;
                }
            return;
        }

        if ($this->_row_set && ($this->_review_array === null || $always))
            $row_set = $this->_row_set;
        else
            $row_set = new PaperInfoSet($this);
        $had = [];
        foreach ($row_set as $prow) {
            $prow->_review_array = [];
            $had += $prow->_reviews_have;
            $prow->_reviews_have = ["full" => true];
        }

        $result = $this->conf->qe("select PaperReview.*, " . $this->ratings_query() . " allRatings from PaperReview where paperId?a order by paperId, reviewId", $row_set->paper_ids());
        while (($rrow = ReviewInfo::fetch($result, $this->conf))) {
            $prow = $row_set->get($rrow->paperId);
            $prow->_review_array[$rrow->reviewId] = $rrow;
        }
        Dbl::free($result);

        $this->ensure_reviewer_names_set($row_set);
        if (get($had, "lastLogin"))
            $this->ensure_reviewer_last_login_set($row_set);
    }

    private function parse_textual_id($textid) {
        if (ctype_digit($textid))
            return intval($textid);
        if (str_starts_with($textid, (string) $this->paperId))
            $textid = (string) substr($textid, strlen($this->paperId));
        if ($textid !== "" && ctype_upper($textid)
            && ($n = parseReviewOrdinal($textid)) > 0)
            return -$n;
        return false;
    }

    function reviews_by_id() {
        if ($this->_review_array === null)
            $this->load_reviews();
        return $this->_review_array;
    }

    function reviews_by_id_order() {
        return array_values($this->reviews_by_id());
    }

    function reviews_by_display() {
        $rrows = $this->reviews_by_id();
        uasort($rrows, "ReviewInfo::compare");
        return $rrows;
    }

    function review_of_id($id) {
        return get($this->reviews_by_id(), $id);
    }

    function review_of_user($contact) {
        $cid = self::contact_to_cid($contact);
        foreach ($this->reviews_by_id() as $rrow)
            if ($rrow->contactId == $cid)
                return $rrow;
        return null;
    }

    function reviews_of_user($contact, $rev_tokens = null) {
        $cid = self::contact_to_cid($contact);
        $rrows = [];
        foreach ($this->reviews_by_id() as $rrow)
            if ($rrow->contactId == $cid
                || ($rev_tokens
                    && $rrow->reviewToken
                    && in_array($rrow->reviewToken, $rev_tokens)))
                $rrows[] = $rrow;
        return $rrows;
    }

    function review_of_ordinal($ordinal) {
        foreach ($this->reviews_by_id() as $rrow)
            if ($rrow->reviewOrdinal == $ordinal)
                return $rrow;
        return null;
    }

    function review_of_token($token) {
        if (!is_int($token))
            $token = decode_token($token, "V");
        foreach ($this->reviews_by_id() as $rrow)
            if ($rrow->reviewToken == $token)
                return $rrow;
        return null;
    }

    function review_of_textual_id($textid) {
        if (($n = $this->parse_textual_id($textid)) === false)
            return false;
        else if ($n < 0)
            return $this->review_of_ordinal(-$n);
        else
            return $this->review_of_id($n);
    }

    private function ensure_full_review_name() {
        if (($rrows = $this->_full_review)) {
            foreach (is_array($rrows) ? $rrows : [$rrows] as $rrow)
                if (($u = $this->conf->cached_user_by_id($rrow->contactId)))
                    $rrow->assign_name($u);
        }
    }

    function full_review_of_id($id) {
        if ($this->_full_review_key === null
            && !isset($this->_reviews_have["full"])) {
            $this->_full_review_key = "r$id";
            $result = $this->conf->qe("select PaperReview.*, " . $this->ratings_query() . " allRatings from PaperReview where paperId=? and reviewId=?", $this->paperId, $id);
            $this->_full_review = ReviewInfo::fetch($result, $this->conf);
            Dbl::free($result);
            $this->ensure_full_review_name();
        }
        if ($this->_full_review_key === "r$id")
            return $this->_full_review;
        $this->ensure_full_reviews();
        return $this->review_of_id($id);
    }

    function full_reviews_of_user($contact) {
        $cid = self::contact_to_cid($contact);
        if ($this->_full_review_key === null
            && !isset($this->_reviews_have["full"])) {
            $row_set = $this->_row_set ? : new PaperInfoSet($this);
            foreach ($row_set as $prow) {
                $prow->_full_review = [];
                $prow->_full_review_key = "u$cid";
            }
            $result = $this->conf->qe("select PaperReview.*, " . $this->ratings_query() . " allRatings from PaperReview where paperId?a and contactId=? order by paperId, reviewId", $row_set->paper_ids(), $cid);
            while (($rrow = ReviewInfo::fetch($result, $this->conf))) {
                $prow = $row_set->get($rrow->paperId);
                $prow->_full_review[] = $rrow;
            }
            Dbl::free($result);
            $this->ensure_full_review_name();
        }
        if ($this->_full_review_key === "u$cid")
            return $this->_full_review;
        $this->ensure_full_reviews();
        return $this->reviews_of_user($contact);
    }

    function full_review_of_ordinal($ordinal) {
        if ($this->_full_review_key === null
            && !isset($this->_reviews_have["full"])) {
            $this->_full_review_key = "o$ordinal";
            $result = $this->conf->qe("select PaperReview.*, " . $this->ratings_query() . " allRatings from PaperReview where paperId=? and reviewOrdinal=?", $this->paperId, $ordinal);
            $this->_full_review = ReviewInfo::fetch($result, $this->conf);
            Dbl::free($result);
            $this->ensure_full_review_name();
        }
        if ($this->_full_review_key === "o$ordinal")
            return $this->_full_review;
        $this->ensure_full_reviews();
        return $this->review_of_ordinal($ordinal);
    }

    function full_review_of_textual_id($textid) {
        if (($n = $this->parse_textual_id($textid)) === false)
            return false;
        else if ($n < 0)
            return $this->full_review_of_ordinal(-$n);
        else
            return $this->full_review_of_id($n);
    }

    private function fresh_review_of($key, $value) {
        $result = $this->conf->qe("select PaperReview.*, " . $this->ratings_query() . " allRatings, ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email from PaperReview join ContactInfo using (contactId) where paperId=? and $key=? order by paperId, reviewId", $this->paperId, $value);
        $rrow = ReviewInfo::fetch($result, $this->conf);
        Dbl::free($result);
        return $rrow;
    }

    function fresh_review_of_id($id) {
        return $this->fresh_review_of("reviewId", $id);
    }

    function fresh_review_of_user($contact) {
        return $this->fresh_review_of("contactId", self::contact_to_cid($contact));
    }

    function viewable_submitted_reviews_by_display(Contact $contact) {
        $cinfo = $contact->__rights($this, null);
        if ($cinfo->vsreviews_array === null
            || $cinfo->vsreviews_version !== $this->_review_array_version) {
            $cinfo->vsreviews_array = [];
            foreach ($this->reviews_by_display() as $id => $rrow) {
                if ($rrow->reviewSubmitted > 0
                    && $contact->can_view_review($this, $rrow))
                    $cinfo->vsreviews_array[$id] = $rrow;
            }
            $cinfo->vsreviews_version = $this->_review_array_version;
        }
        return $cinfo->vsreviews_array;
    }

    function viewable_submitted_reviews_by_user(Contact $contact) {
        $rrows = [];
        foreach ($this->viewable_submitted_reviews_by_display($contact) as $rrow)
            $rrows[$rrow->contactId] = $rrow;
        return $rrows;
    }

    function can_view_review_identity_of($cid, Contact $contact) {
        if ($contact->can_administer_for_track($this, Track::VIEWREVID)
            || $cid == $contact->contactId)
            return true;
        foreach ($this->reviews_of_user($cid) as $rrow)
            if ($contact->can_view_review_identity($this, $rrow))
                return true;
        return false;
    }

    function may_have_viewable_scores($field, Contact $contact) {
        $field = is_object($field) ? $field : $this->conf->review_field($field);
        return $contact->can_view_review($this, $field->view_score)
            || $this->review_type($contact);
    }

    function ensure_reviews() {
        if ($this->_review_array === null)
            $this->load_reviews();
    }

    function ensure_full_reviews() {
        if (!isset($this->_reviews_have["full"]))
            $this->load_reviews(true);
    }

    private function ensure_reviewer_names_set($row_set) {
        $missing = [];
        foreach ($row_set as $prow) {
            $prow->_reviews_have["names"] = true;
            foreach ($prow->reviews_by_id() as $rrow)
                if (($u = $this->conf->cached_user_by_id($rrow->contactId, true)))
                    $rrow->assign_name($u);
                else
                    $missing[] = $rrow;
        }
        if ($this->conf->load_missing_cached_users()) {
            foreach ($missing as $rrow)
                if (($u = $this->conf->cached_user_by_id($rrow->contactId, true)))
                    $rrow->assign_name($u);
        }
    }

    function ensure_reviewer_names() {
        $this->ensure_reviews();
        if (!empty($this->_review_array)
            && !isset($this->_reviews_have["names"]))
            $this->ensure_reviewer_names_set($this->_row_set ? : new PaperInfoSet($this));
    }

    private function ensure_reviewer_last_login_set($row_set) {
        $users = [];
        foreach ($row_set as $prow) {
            $prow->_reviews_have["lastLogin"] = true;
            foreach ($prow->reviews_by_id() as $rrow)
                $users[$rrow->contactId] = true;
        }
        if (!empty($users)) {
            $result = $this->conf->qe("select contactId, lastLogin from ContactInfo where contactId?a", array_keys($users));
            $users = Dbl::fetch_iimap($result);
            foreach ($row_set as $prow) {
                foreach ($prow->reviews_by_id() as $rrow)
                    $rrow->reviewLastLogin = $users[$rrow->contactId];
            }
        }
    }

    function ensure_reviewer_last_login() {
        $this->ensure_reviews();
        if (!empty($this->_review_array)
            && !isset($this->_reviews_have["lastLogin"]))
            $this->ensure_reviewer_last_login_set($this->_row_set ? : new PaperInfoSet($this));
    }

    private function load_review_fields($fid, $maybe_null = false) {
        $k = $fid . "Signature";
        $row_set = $this->_row_set ? : new PaperInfoSet($this);
        foreach ($row_set as $prow)
            $prow->$k = "";
        $select = $maybe_null ? "coalesce($fid,'.')" : $fid;
        $result = $this->conf->qe("select paperId, group_concat($select order by reviewId) from PaperReview where paperId?a group by paperId", $row_set->paper_ids());
        while ($result && ($row = $result->fetch_row())) {
            $prow = $row_set->get($row[0]);
            $prow->$k = $row[1];
        }
        Dbl::free($result);
    }

    function ensure_review_score($field) {
        $fid = is_object($field) ? $field->id : $field;
        if (!isset($this->_reviews_have[$fid])
            && !isset($this->_reviews_have["full"])) {
            $rfi = is_object($field) ? $field : ReviewInfo::field_info($fid, $this->conf);
            if (!$rfi)
                $this->_reviews_have[$fid] = false;
            else if (!$rfi->main_storage)
                $this->ensure_full_reviews();
            else {
                $this->_reviews_have[$fid] = true;
                $k = $rfi->main_storage . "Signature";
                if (!property_exists($this, $k))
                    $this->load_review_fields($rfi->main_storage);
                $x = explode(",", $this->$k);
                foreach ($this->reviews_by_id_order() as $i => $rrow)
                    $rrow->$fid = (int) $x[$i];
            }
        }
    }

    private function _update_review_word_counts($rids) {
        $rf = $this->conf->review_form();
        $result = $this->conf->qe("select * from PaperReview where paperId=$this->paperId and reviewId?a", $rids);
        $qs = [];
        while (($rrow = ReviewInfo::fetch($result, $this->conf))) {
            if ($rrow->reviewWordCount === null) {
                $rrow->reviewWordCount = $rf->word_count($rrow);
                $qs[] = "update PaperReview set reviewWordCount={$rrow->reviewWordCount} where paperId={$this->paperId} and reviewId={$rrow->reviewId}";
            }
            $my_rrow = get($this->_review_array, $rrow->reviewId);
            $my_rrow->reviewWordCount = (int) $rrow->reviewWordCount;
        }
        Dbl::free($result);
        if (!empty($qs)) {
            $mresult = Dbl::multi_qe($this->conf->dblink, join(";", $qs));
            $mresult->free_all();
        }
    }

    function ensure_review_word_counts() {
        if (!isset($this->_reviews_have["reviewWordCount"])) {
            $this->_reviews_have["reviewWordCount"] = true;
            if (!property_exists($this, "reviewWordCountSignature"))
                $this->load_review_fields("reviewWordCount", true);
            $x = explode(",", $this->reviewWordCountSignature);
            $bad_ids = [];

            foreach ($this->reviews_by_id_order() as $i => $rrow)
                if ($x[$i] !== ".")
                    $rrow->reviewWordCount = (int) $x[$i];
                else
                    $bad_ids[] = $rrow->reviewId;
            if (!empty($bad_ids))
                $this->_update_review_word_counts($bad_ids);
        }
    }

    static function fetch_comment_query() {
        return "select PaperComment.*,
            firstName reviewFirstName, lastName reviewLastName, email reviewEmail
            from PaperComment
            join ContactInfo on (ContactInfo.contactId=PaperComment.contactId)";
    }

    function fetch_comments($extra_where = null) {
        $result = $this->conf->qe(self::fetch_comment_query()
            . " where paperId={$this->paperId}" . ($extra_where ? " and $extra_where" : "")
            . " order by paperId, commentId");
        $comments = array();
        while (($c = CommentInfo::fetch($result, $this, $this->conf)))
            $comments[$c->commentId] = $c;
        Dbl::free($result);
        return $comments;
    }

    function load_comments() {
        $row_set = $this->_row_set ? : new PaperInfoSet($this);
        foreach ($row_set as $prow)
            $prow->_comment_array = [];
        $result = $this->conf->qe(self::fetch_comment_query()
            . " where paperId?a order by paperId, commentId", $row_set->paper_ids());
        $comments = [];
        while (($c = CommentInfo::fetch($result, null, $this->conf))) {
            $prow = $row_set->get($c->paperId);
            $c->set_prow($prow);
            $prow->_comment_array[$c->commentId] = $c;
        }
        Dbl::free($result);
    }

    function all_comments() {
        if ($this->_comment_array === null)
            $this->load_comments();
        return $this->_comment_array;
    }

    function viewable_comments(Contact $user) {
        $crows = [];
        foreach ($this->all_comments() as $cid => $crow)
            if ($user->can_view_comment($this, $crow))
                $crows[$cid] = $crow;
        return $crows;
    }

    function all_comment_skeletons() {
        if ($this->_comment_skeleton_array !== null)
            return $this->_comment_skeleton_array;
        if ($this->_comment_array !== null
            || !property_exists($this, "commentSkeletonInfo"))
            return $this->all_comments();
        $this->_comment_skeleton_array = [];
        preg_match_all('/(\d+);(\d+);(\d+);(\d+);([^|]*)/', $this->commentSkeletonInfo, $ms, PREG_SET_ORDER);
        foreach ($ms as $m) {
            $c = new CommentInfo((object) [
                    "commentId" => $m[1], "contactId" => $m[2],
                    "commentType" => $m[3], "commentRound" => $m[4],
                    "commentTags" => $m[5]
                ], $this, $this->conf);
            $this->_comment_skeleton_array[$c->commentId] = $c;
        }
        return $this->_comment_skeleton_array;
    }

    function viewable_comment_skeletons(Contact $user) {
        $crows = [];
        foreach ($this->all_comment_skeletons() as $cid => $crow)
            if ($user->can_view_comment($this, $crow))
                $crows[$cid] = $crow;
        return $crows;
    }

    function has_commenter($contact) {
        $cid = self::contact_to_cid($contact);
        foreach ($this->all_comment_skeletons() as $crow)
            if ($crow->contactId == $cid)
                return true;
        return false;
    }

    static function analyze_review_or_comment($x) {
        if (isset($x->commentId))
            return [!!($x->commentType & COMMENTTYPE_DRAFT),
                    (int) $x->timeDisplayed, true];
        else
            return [$x->reviewSubmitted && !$x->reviewOrdinal,
                    (int) $x->timeDisplayed, false];
    }
    static function review_or_comment_compare($a, $b) {
        list($a_draft, $a_displayed_at, $a_iscomment) = self::analyze_review_or_comment($a);
        list($b_draft, $b_displayed_at, $b_iscomment) = self::analyze_review_or_comment($b);
        // drafts come last
        if ($a_draft !== $b_draft
            && ($a_draft ? !$a_displayed_at : !$b_displayed_at))
            return $a_draft ? 1 : -1;
        // order by displayed_at
        if ($a_displayed_at !== $b_displayed_at)
            return $a_displayed_at < $b_displayed_at ? -1 : 1;
        // reviews before comments
        if ($a_iscomment !== $b_iscomment)
            return !$a_iscomment ? -1 : 1;
        if ($a_iscomment)
            // order by commentId (which generally agrees with ordinal)
            return $a->commentId < $b->commentId ? -1 : 1;
        else {
            // order by ordinal or reviewId
            if ($a->reviewOrdinal && $b->reviewOrdinal)
                return $a->reviewOrdinal < $b->reviewOrdinal ? -1 : 1;
            else
                return $a->reviewId < $b->reviewId ? -1 : 1;
        }
    }
    function viewable_submitted_reviews_and_comments(Contact $user) {
        $this->ensure_full_reviews();
        $rrows = $this->viewable_submitted_reviews_by_display($user);
        $crows = $this->viewable_comments($user);
        $rcs = array_merge(array_values($rrows), array_values($crows));
        usort($rcs, "PaperInfo::review_or_comment_compare");
        return $rcs;
    }
    static function review_or_comment_text_separator($a, $b) {
        if (!$a || !$b)
            return "";
        else if (isset($a->reviewId) || isset($b->reviewId)
                 || (($a->commentType | $b->commentType) & COMMENTTYPE_RESPONSE))
            return "\n\n\n";
        else
            return "\n\n";
    }


    static function notify_user_compare($a, $b) {
        // group authors together, then reviewers
        $act = (int) $a->conflictType;
        $bct = (int) $b->conflictType;
        if (($act >= CONFLICT_AUTHOR) !== ($bct >= CONFLICT_AUTHOR))
            return $act >= CONFLICT_AUTHOR ? -1 : 1;
        $arp = $a->myReviewPermissions;
        $brp = $b->myReviewPermissions;
        if ((bool) $arp !== (bool) $brp)
            return (bool) $arp ? -1 : 1;
        return Contact::compare($a, $b);
    }

    function notify_reviews($callback, $sending_user) {
        $result = $this->conf->qe_raw("select ContactInfo.contactId, firstName, lastName, email,
                password, contactTags, roles, defaultWatch,
                " . self::my_review_permissions_sql() . " myReviewPermissions,
                conflictType, watch, preferredEmail, disabled
        from ContactInfo
        left join PaperConflict on (PaperConflict.paperId=$this->paperId and PaperConflict.contactId=ContactInfo.contactId)
        left join PaperWatch on (PaperWatch.paperId=$this->paperId and PaperWatch.contactId=ContactInfo.contactId)
        left join PaperReview on (PaperReview.paperId=$this->paperId and PaperReview.contactId=ContactInfo.contactId)
        where (watch&" . Contact::WATCH_REVIEW . ")!=0
        or (defaultWatch&" . (Contact::WATCH_REVIEW_ALL | Contact::WATCH_REVIEW_MANAGED) . ")!=0
        or conflictType>=" . CONFLICT_AUTHOR . "
        or reviewType is not null
        or exists (select * from PaperComment where paperId=$this->paperId and contactId=ContactInfo.contactId)
        group by ContactInfo.contactId");

        $watchers = [];
        $lastContactId = 0;
        while (($minic = Contact::fetch($result, $this->conf))) {
            if ($minic->contactId == $lastContactId
                || ($sending_user && $minic->contactId == $sending_user->contactId)
                || Contact::is_anonymous_email($minic->email))
                continue;
            $lastContactId = $minic->contactId;
            if ($minic->following_reviews($this, $minic->watch))
                $watchers[$minic->contactId] = $minic;
        }
        Dbl::free($result);
        usort($watchers, "PaperInfo::notify_user_compare");

        // save my current contact info map -- we are replacing it with another
        // map that lacks review token information and so forth
        $cimap = $this->replace_contact_info_map(null);

        foreach ($watchers as $minic) {
            $this->load_my_contact_info($minic->contactId, $minic);
            call_user_func($callback, $this, $minic);
        }

        $this->replace_contact_info_map($cimap);
    }

    function notify_final_submit($callback, $sending_user) {
        $result = $this->conf->qe_raw("select ContactInfo.contactId, firstName, lastName, email,
                password, contactTags, roles, defaultWatch,
                " . self::my_review_permissions_sql() . " myReviewPermissions,
                conflictType, watch, preferredEmail, disabled
        from ContactInfo
        left join PaperConflict on (PaperConflict.paperId=$this->paperId and PaperConflict.contactId=ContactInfo.contactId)
        left join PaperWatch on (PaperWatch.paperId=$this->paperId and PaperWatch.contactId=ContactInfo.contactId)
        left join PaperReview on (PaperReview.paperId=$this->paperId and PaperReview.contactId=ContactInfo.contactId)
        where (defaultWatch&" . (Contact::WATCH_FINAL_SUBMIT_ALL) . ")!=0
        group by ContactInfo.contactId");

        $watchers = [];
        $lastContactId = 0;
        while (($minic = Contact::fetch($result, $this->conf))) {
            if ($minic->contactId == $lastContactId
                || ($sending_user && $minic->contactId == $sending_user->contactId)
                || Contact::is_anonymous_email($minic->email))
                continue;
            $lastContactId = $minic->contactId;
            $watchers[$minic->contactId] = $minic;
        }
        Dbl::free($result);
        usort($watchers, "PaperInfo::notify_user_compare");

        // save my current contact info map -- we are replacing it with another
        // map that lacks review token information and so forth
        $cimap = $this->replace_contact_info_map(null);

        foreach ($watchers as $minic) {
            $this->load_my_contact_info($minic->contactId, $minic);
            call_user_func($callback, $this, $minic);
        }

        $this->replace_contact_info_map($cimap);
    }

    function delete_from_database(Contact $user = null) {
        // XXX email self?
        if ($this->paperId <= 0)
            return false;
        $rrows = $this->reviews_by_id();

        $qs = [];
        foreach (["PaperWatch", "PaperReviewPreference", "PaperReviewRefused", "ReviewRequest", "PaperTag", "PaperComment", "PaperReview", "PaperTopic", "PaperOption", "PaperConflict", "Paper", "PaperStorage", "Capability"] as $table) {
            $qs[] = "delete from $table where paperId={$this->paperId}";
        }
        $mresult = Dbl::multi_qe($this->conf->dblink, join(";", $qs));
        $mresult->free_all();

        if (!Dbl::$nerrors) {
            $this->conf->update_papersub_setting(-1);
            if ($this->outcome > 0)
                $this->conf->update_paperacc_setting(-1);
            if ($this->leadContactId > 0 || $this->shepherdContactId > 0)
                $this->conf->update_paperlead_setting(-1);
            if ($this->managerContactId > 0)
                $this->conf->update_papermanager_setting(-1);
            if ($rrows && array_filter($rrows, function ($rrow) { return $rrow->reviewToken > 0; }))
                $this->conf->update_rev_tokens_setting(-1);
            if ($rrows && array_filter($rrows, function ($rrow) { return $rrow->reviewType == REVIEW_META; }))
                $this->conf->update_metareviews_setting(-1);
            $this->conf->log_for($user, $user, "Deleted", $this->paperId);
            return true;
        } else {
            return false;
        }
    }
}
