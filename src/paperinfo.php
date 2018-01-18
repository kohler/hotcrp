<?php
// paperinfo.php -- HotCRP paper objects
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class PaperContactInfo {
    public $paperId;
    public $contactId;
    public $conflictType = 0;
    public $reviewType = 0;
    public $reviewSubmitted = 0;
    public $reviewNeedsSubmit = 1;
    public $review_status = 0;
    public $review_token_cid = 0;

    //public $reviewId;
    //public $reviewToken;
    //public $reviewModified;
    //public $reviewOrdinal;
    //public $reviewBlind;
    //public $requestedBy;
    //public $timeApprovalRequested;
    //public $reviewRound;

    public $rights_forced = null;
    public $forced_rights_link = null;

    public $vsreviews_array = null;
    public $vsreviews_cid_array = null;
    public $vsreviews_version = null;

    static function make_empty(PaperInfo $prow, $cid) {
        $ci = new PaperContactInfo;
        $ci->paperId = $prow->paperId;
        $ci->contactId = $cid;
        if ($cid > 0 && isset($prow->leadContactId) && $prow->leadContactId == $cid)
            $ci->review_status = 1;
        return $ci;
    }

    static function make_my(PaperInfo $prow, $cid, $object) {
        $ci = PaperContactInfo::make_empty($prow, $cid);
        if (property_exists($object, "conflictType"))
            $ci->conflictType = (int) $object->conflictType;
        if (property_exists($object, "myReviewType"))
            $ci->reviewType = (int) $object->myReviewType;
        if (property_exists($object, "myReviewSubmitted"))
            $ci->reviewSubmitted = (int) $object->myReviewSubmitted;
        if (property_exists($object, "myReviewNeedsSubmit"))
            $ci->reviewNeedsSubmit = (int) $object->myReviewNeedsSubmit;
        if (property_exists($object, "myReviewContactId")
            && $object->myReviewContactId != $cid)
            $ci->review_token_cid = (int) $object->myReviewContactId;
        $ci->update_review_status();
        return $ci;
    }

    function update_review_status() {
        if ($this->reviewType > 0) {
            if ($this->reviewSubmitted <= 0 && $this->reviewNeedsSubmit != 0)
                $this->review_status = -1;
            else
                $this->review_status = 1;
        }
    }

    function merge_review(ReviewInfo $rrow) {
        foreach (["reviewType", "reviewSubmitted", "reviewNeedsSubmit"] as $k)
            $this->$k = $rrow->$k;
        /*foreach (["reviewId", "reviewToken", "reviewType", "reviewRound",
                  "requestedBy", "reviewBlind", "reviewModified",
                  "reviewSubmitted", "reviewOrdinal", "timeApprovalRequested",
                  "reviewNeedsSubmit"] as $k)
            $this->$k = $rrow->$k;*/
        if ($rrow->contactId == $this->contactId)
            $this->review_token_cid = 0;
        else
            $this->review_token_cid = $rrow->contactId;
        $this->update_review_status();
    }

    private function merge() {
        if (isset($this->paperId))
            $this->paperId = (int) $this->paperId;
        $this->contactId = (int) $this->contactId;
        $this->conflictType = (int) $this->conflictType;
        $this->reviewType = (int) $this->reviewType;
        $this->reviewSubmitted = (int) $this->reviewSubmitted;
        if ($this->reviewNeedsSubmit !== null)
            $this->reviewNeedsSubmit = (int) $this->reviewNeedsSubmit;
        else
            $this->reviewNeedsSubmit = 1;
        $this->review_token_cid = (int) $this->review_token_cid;
        if ($this->review_token_cid == $this->contactId)
            $this->review_token_cid = 0;
        $this->update_review_status();
    }

    static function load_into(PaperInfo $prow, $cid, $rev_tokens) {
        global $Me;
        $conf = $prow->conf;
        $pid = $prow->paperId;
        $q = "select conflictType, reviewType, reviewSubmitted, reviewNeedsSubmit,
                PaperReview.contactId as review_token_cid";
        if ($cid && !$rev_tokens
            && $prow->_row_set && $prow->_row_set->size() > 1) {
            $result = $conf->qe("$q, Paper.paperId paperId, $cid contactId
                from Paper
                left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$cid)
                left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$cid)
                where Paper.paperId?a", $prow->_row_set->paper_ids());
            $found = false;
            $map = [];
            while ($result && ($ci = $result->fetch_object("PaperContactInfo"))) {
                $ci->merge();
                $map[$ci->paperId] = $ci;
            }
            Dbl::free($result);
            foreach ($prow->_row_set->all() as $row)
                $row->_add_contact_info($map[$row->paperId]);
            if ($prow->_get_contact_info($cid))
                return;
            $result = null;
        }
        if ($cid && !$rev_tokens
            && (!$Me || ($Me->contactId != $cid
                         && ($Me->privChair || $Me->contactId == $prow->managerContactId)))
            && ($pcm = $conf->pc_members()) && isset($pcm[$cid])) {
            $cids = array_keys($pcm);
            $result = $conf->qe_raw("$q, $pid paperId, ContactInfo.contactId
                from (select $pid paperId) P
                join ContactInfo
                left join PaperReview on (PaperReview.paperId=$pid and PaperReview.contactId=ContactInfo.contactId)
                left join PaperConflict on (PaperConflict.paperId=$pid and PaperConflict.contactId=ContactInfo.contactId)
                where roles!=0 and (roles&" . Contact::ROLE_PC . ")!=0");
        } else {
            $cids = [$cid];
            if ($cid) {
                $q = "$q, $pid paperId, $cid contactId
                from (select $pid paperId) P
                left join PaperReview on (PaperReview.paperId=P.paperId and (PaperReview.contactId=$cid";
                if ($rev_tokens)
                    $q .= " or PaperReview.reviewToken in (" . join(",", $rev_tokens) . ")";
                $result = $conf->qe_raw("$q))
                    left join PaperConflict on (PaperConflict.paperId=$pid and PaperConflict.contactId=$cid)");
            } else
                $result = null;
        }
        while ($result && ($ci = $result->fetch_object("PaperContactInfo"))) {
            $ci->merge();
            $prow->_add_contact_info($ci);
        }
        Dbl::free($result);
        foreach ($cids as $cid)
            if (!$prow->_get_contact_info($cid))
                $prow->_add_contact_info(PaperContactInfo::make_empty($prow, $cid));
    }

    function get_forced_rights() {
        if (!$this->forced_rights_link) {
            $ci = $this->forced_rights_link = clone $this;
            $ci->vsreviews_array = $ci->vsreviews_cid_array = null;
        }
        return $this->forced_rights_link;
    }
}

class PaperInfo_Author {
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
        } else {
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
                if (preg_match('/\A\s*(\S.*?)\s*\((.*)\)(?:[\s,;.]*|\s*(?:-+|–|—|:)\s+.*)\z/', $x, $m)) {
                    $this->affiliation = trim($m[2]);
                    $x = $m[1];
                }
                $this->_name = trim($x);
                list($this->firstName, $this->lastName, $this->email) = Text::split_name($x, true);
            }
        }
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

class PaperInfo_AuthorMatcher extends PaperInfo_Author {
    public $firstName_matcher;
    public $lastName_matcher;
    public $affiliation_matcher;
    public $general_pregexes;
    public $is_author = false;

    private static $wordinfo;

    function __construct($x) {
        if (is_string($x) && ($hash = strpos($x, "#")) !== false) {
            $x = substr($x, 0, $hash);
        }
        parent::__construct($x);

        $any = [];
        if ($this->firstName !== "") {
            preg_match_all('/[a-z0-9]+/', strtolower(UnicodeHelper::deaccent($this->firstName)), $m);
            $rr = [];
            foreach ($m[0] as $w) {
                $any[] = $rr[] = $w;
                if (ctype_alpha($w[0])) {
                    if (strlen($w) === 1)
                        $any[] = $rr[] = $w . "[a-z]*";
                    else
                        $any[] = $rr[] = $w[0] . "(?=\\.)";
                }
            }
            if (!empty($rr))
                $this->firstName_matcher = (object) [
                    "preg_raw" => '\b(?:' . join("|", $rr) . ')\b',
                    "preg_utf8" => Text::UTF8_INITIAL_NONLETTERDIGIT . '(?:' . join("|", $rr) . ')' . Text::UTF8_FINAL_NONLETTERDIGIT
                ];
        }
        if ($this->lastName !== "") {
            preg_match_all('/[a-z0-9]+/', strtolower(UnicodeHelper::deaccent($this->lastName)), $m);
            $rr = $ur = [];
            foreach ($m[0] as $w) {
                $any[] = $w;
                $rr[] = '(?=.*\b' . $w . '\b)';
                $ur[] = '(?=.*' . Text::UTF8_INITIAL_NONLETTERDIGIT . $w . Text::UTF8_FINAL_NONLETTERDIGIT . ')';
            }
            if (!empty($rr))
                $this->lastName_matcher = (object) [
                    "preg_raw" => '\A' . join("", $rr),
                    "preg_utf8" => '\A' . join("", $ur)
                ];
        }

        $aff = "";
        if ($this->affiliation !== ""
            && $this->firstName === ""
            && $this->lastName === ""
            && $this->email === "") {
            $aff = $this->affiliation;
        } else if ($this->affiliation === "" && is_string($x)) {
            $aff = $x;
        }
        if ($aff !== "") {
            self::wordinfo();
            preg_match_all('/[a-z0-9&]+/', strtolower(UnicodeHelper::deaccent($aff)), $m);

            $directs = $alts = [];
            $any_weak = false;
            foreach ($m[0] as $w) {
                $aw = get(self::$wordinfo, $w);
                if ($aw && isset($aw->stop) && $aw->stop)
                    continue;
                $any[] = preg_quote($w);
                $directs[] = $w;
                if ($aw && isset($aw->weak) && $aw->weak)
                    $any_weak = true;
                if ($aw && isset($aw->alternate)) {
                    if (is_array($aw->alternate))
                        $alts = array_merge($alts, $aw->alternate);
                    else
                        $alts[] = $aw->alternate;
                }
                if ($aw && isset($aw->sync))
                    $alts[] = $aw->sync;
            }

            $rs = $directs;
            foreach ($alts as $alt) {
                if (is_object($alt)) {
                    if ((isset($alt->if) && !self::match_if($alt->if, $rs))
                        || (isset($alt->if_not) && self::match_if($alt->if_not, $rs)))
                        continue;
                    $alt = $alt->word;
                }
                foreach (explode(" ", $alt) as $altw)
                    if ($altw !== "") {
                        $any[] = preg_quote($altw);
                        $rs[] = $altw;
                        $any_weak = true;
                    }
            }

            $rex = '{\b(?:' . str_replace('&', '\\&', join("|", $rs)) . ')\b}';
            $this->affiliation_matcher = [$directs, $any_weak, $rex];
        }

        $content = join("|", $any);
        if ($content !== "" && $content !== "none") {
            $this->general_pregexes = (object) [
                "preg_raw" => '\b(?:' . $content . ')\b',
                "preg_utf8" => Text::UTF8_INITIAL_NONLETTER . '(?:' . $content . ')' . Text::UTF8_FINAL_NONLETTER
            ];
        }
    }
    function is_empty() {
        return !$this->general_pregexes;
    }
    static function make($x, $nonauthor) {
        if ($x !== "") {
            $m = new PaperInfo_AuthorMatcher($x);
            if (!$m->is_empty()) {
                $m->nonauthor = $nonauthor;
                return $m;
            }
        }
        return null;
    }
    static function make_affiliation($x, $nonauthor) {
        return self::make((object) ["firstName" => "", "lastName" => "", "email" => "", "affiliation" => $x], $nonauthor);
    }

    const MATCH_NAME = 1;
    const MATCH_AFFILIATION = 2;
    function test($au) {
        if (!$this->general_pregexes) {
            return false;
        }
        if (is_string($au)) {
            $au = new PaperInfo_Author($au);
        }
        if ($au->firstName_deaccent === null) {
            $au->firstName_deaccent = $au->lastName_deaccent = false;
            $au->firstName_deaccent = UnicodeHelper::deaccent($au->firstName);
            $au->lastName_deaccent = UnicodeHelper::deaccent($au->lastName);
            $au->affiliation_deaccent = strtolower(UnicodeHelper::deaccent($au->affiliation));
        }
        if ($this->lastName_matcher
            && $au->lastName !== ""
            && Text::match_pregexes($this->lastName_matcher, $au->lastName, $au->lastName_deaccent)
            && ($au->firstName === ""
                || !$this->firstName_matcher
                || Text::match_pregexes($this->firstName_matcher, $au->firstName, $au->firstName_deaccent))) {
            return self::MATCH_NAME;
        }
        if ($this->affiliation_matcher
            && $au->affiliation !== ""
            && $this->test_affiliation($au->affiliation_deaccent)) {
            return self::MATCH_AFFILIATION;
        }
        return false;
    }
    static function highlight_all($au, $matchers) {
        $aff_suffix = null;
        if (is_object($au)) {
            if ($au->affiliation)
                $aff_suffix = "(" . htmlspecialchars($au->affiliation) . ")";
            $au = $au->nameaff_text();
        }
        $pregexes = [];
        foreach ($matchers as $matcher)
            $pregexes[] = $matcher->general_pregexes;
        if (count($pregexes) > 1)
            $pregexes = [Text::merge_pregexes($pregexes)];
        if (!empty($pregexes))
            $au = Text::highlight($au, $pregexes[0]);
        if ($aff_suffix && str_ends_with($au, $aff_suffix))
            $au = substr($au, 0, -strlen($aff_suffix))
                . ' <span class="auaff">' . $aff_suffix . '</span>';
        return $au;
    }
    function highlight($au) {
        return self::highlight_all($au, [$this]);
    }

    static function wordinfo() {
        global $ConfSitePATH;
        // XXX validate input JSON
        if (self::$wordinfo === null)
            self::$wordinfo = (array) json_decode(file_get_contents("$ConfSitePATH/etc/affiliationmatching.json"));
        return self::$wordinfo;
    }
    private function test_affiliation($mtext) {
        list($am_words, $am_any_weak, $am_regex) = $this->affiliation_matcher;
        if (!$am_any_weak)
            return preg_match($am_regex, $mtext) === 1;
        else if (!preg_match_all($am_regex, $mtext, $m))
            return false;
        $result = true;
        foreach ($am_words as $w) { // $am_words contains no alternates
            $aw = get(self::$wordinfo, $w);
            $weak = $aw && isset($aw->weak) && $aw->weak;
            $saw_w = in_array($w, $m[0]);
            if (!$saw_w && $aw && isset($aw->alternate)) {
                // We didn't see a requested word; did we see one of its alternates?
                foreach ($aw->alternate as $alt) {
                    if (is_object($alt)) {
                        if ((isset($alt->if) && !self::match_if($alt->if, $am_words))
                            || (isset($alt->if_not) && self::match_if($alt->if_not, $am_words)))
                            continue;
                        $alt = $alt->word;
                    }
                    // Check for every word in the alternate list
                    $saw_w = true;
                    $altws = explode(" ", $alt);
                    foreach ($altws as $altw)
                        if ($altw !== "" && !in_array($altw, $m[0])) {
                            $saw_w = false;
                            break;
                        }
                    // If all are found, exit; check if the found alternate is strong
                    if ($saw_w) {
                        if ($weak && count($altws) == 1) {
                            $aw2 = get(self::$wordinfo, $alt);
                            if (!$aw2 || !isset($aw2->weak) || !$aw2->weak)
                                $weak = false;
                        }
                        break;
                    }
                }
            }
            // Check for sync words: e.g., "penn state university" ≠ "university penn".
            // If *any* sync word is in matcher, then *some* sync word must be in subject.
            // If *no* sync word is in matcher, then *no* sync word allowed in subject.
            if ($saw_w && $aw && isset($aw->sync) && $aw->sync !== "") {
                $syncws = explode(" ", $aw->sync);
                $has_any_syncs = false;
                foreach ($syncws as $syncw)
                    $has_any_syncs = $has_any_syncs || in_array($syncw, $am_words);
                if ($has_any_syncs) {
                    $saw_w = false;
                    foreach ($syncws as $syncw)
                        $saw_w = $saw_w || in_array($syncw, $m[0]);
                } else {
                    $saw_w = true;
                    foreach ($syncws as $syncw)
                        $saw_w = $saw_w && !in_array($syncw, $m[0]);
                }
            }
            if ($saw_w) {
                if (!$weak)
                    return true;
            } else
                $result = false;
        }
        return $result;
    }
    private static function match_if($iftext, $ws) {
        foreach (explode(" ", $iftext) as $w)
            if ($w !== "" && !in_array($w, $ws))
                return false;
        return true;
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
            if ($prow->_row_set) error_log(json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
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
    // $paperTags: DO NOT LIST (property_exists() is meaningful)
    // $optionIds: DO NOT LIST (property_exists() is meaningful)
    // $allConflictTypes: DO NOT LIST (property_exists() is meaningful)
    // $reviewSignatures: DO NOT LIST (property_exists() is meaningful)

    private $_unaccented_title = null;
    private $_contact_info = array();
    private $_rights_version = 0;
    private $_author_array = null;
    private $_collaborator_array = null;
    private $_collaborator_general_pregexes = null;
    private $_prefs_array = null;
    private $_prefs_cid = null;
    private $_topics_array = null;
    private $_topic_interest_score_array = null;
    private $_option_values = null;
    private $_option_data = null;
    private $_option_array = null;
    private $_all_option_array = null;
    private $_document_array = null;
    private $_conflict_array = null;
    private $_conflict_array_email;
    private $_review_array = null;
    private $_review_array_version = 0;
    private $_reviews_have = [];
    private $_full_review = null;
    private $_full_review_id = null;
    private $_comment_array = null;
    private $_comment_skeleton_array = null;
    public $_row_set;

    function __construct($p = null, $contact = null, Conf $conf = null) {
        $this->merge($p, $contact, $conf);
    }

    private function merge($p, $contact, $conf) {
        global $Conf;
        assert($contact === null ? $conf !== null : $contact instanceof Contact);
        if ($contact)
            $conf = $contact->conf;
        $this->conf = $conf ? : $Conf;
        if ($p)
            foreach ($p as $k => $v)
                $this->$k = $v;
        $this->paperId = (int) $this->paperId;
        $this->managerContactId = (int) $this->managerContactId;
        if ($contact && (property_exists($this, "conflictType")
                         || property_exists($this, "myReviewType"))) {
            if ($contact === true)
                $cid = property_exists($this, "contactId") ? $this->contactId : null;
            else
                $cid = is_object($contact) ? $contact->contactId : $contact;
            $this->_rights_version = Contact::$rights_version;
            $this->load_my_contact_info($cid, $this);
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

    function initial_whynot() {
        return ["fail" => true, "paperId" => $this->paperId, "conf" => $this->conf];
    }


    static private function contact_to_cid($contact) {
        global $Me;
        if ($contact && is_object($contact))
            return $contact->contactId;
        else
            return $contact ? : $Me->contactId;
    }

    function _get_contact_info($cid) {
        return get($this->_contact_info, $cid);
    }

    function _add_contact_info(PaperContactInfo $ci) {
        $this->_contact_info[$ci->contactId] = $ci;
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

    function contact_info($contact = null) {
        global $Me;
        $this->update_rights_version();
        $rev_tokens = null;
        if (!$contact || is_object($contact)) {
            $contact = $contact ? : $Me;
            $rev_tokens = $contact->review_tokens();
        }
        $cid = self::contact_to_cid($contact);
        if (!array_key_exists($cid, $this->_contact_info)) {
            if ($this->_review_array || property_exists($this, "reviewSignatures")) {
                $ci = PaperContactInfo::make_empty($this, $cid);
                if (($c = get($this->conflicts(), $cid)))
                    $ci->conflictType = $c->conflictType;
                $have_rrow = null;
                foreach ($this->reviews_by_id() as $rrow)
                    if ($rrow->contactId == $cid
                        || ($rev_tokens && !$have_rrow && $rrow->reviewToken
                            && in_array($rrow->reviewToken, $rev_tokens)))
                        $have_rrow = $rrow;
                if ($have_rrow)
                    $ci->merge_review($have_rrow);
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

    function load_my_contact_info($cid, $object) {
        $this->_add_contact_info(PaperContactInfo::make_my($this, $cid, $object));
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
                    $this->_author_array[] = new PaperInfo_Author($line);
        }
        return $this->_author_array;
    }

    function author_by_email($email) {
        foreach ($this->author_list() as $a)
            if (strcasecmp($a, $email) == 0)
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

    function conflict_type($contact = null) {
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

    function collaborator_list($matcher = false) {
        if ($this->_collaborator_array === null
            || ($matcher
                && !empty($this->_collaborator_array)
                && !($this->_collaborator_array[0] instanceof PaperInfo_AuthorMatcher))) {
            $klass = $matcher ? "PaperInfo_AuthorMatcher" : "PaperInfo_Author";
            $this->_collaborator_array = [];
            foreach (explode("\n", $this->collaborators) as $co)
                if ($co !== "") {
                    $m = new $klass($co);
                    if (!$matcher || !$m->is_empty())
                        $this->_collaborator_array[] = $m;
                }
        }
        return $this->_collaborator_array;
    }

    function collaborator_general_pregexes() {
        if ($this->_collaborator_general_pregexes === null) {
            $m = array_map(function ($m) { return $m->general_pregexes; }, $this->collaborator_list(true));
            $this->_collaborator_general_pregexes = Text::merge_pregexes($m);
        }
        return $this->_collaborator_general_pregexes;
    }

    function potential_conflict(Contact $user, $full_info = false) {
        $details = [];
        if ($this->field_match_pregexes($user->aucollab_general_pregexes(), "authorInformation")) {
            foreach ($this->author_list() as $n => $au)
                foreach ($user->aucollab_matchers() as $matcher) {
                    if (($why = $matcher->test($au))) {
                        if (!$full_info)
                            return true;
                        if ($matcher->nonauthor)
                            $what = "PC’s collaborator ";
                        else if ($why == PaperInfo_AuthorMatcher::MATCH_AFFILIATION)
                            $what = "PC affiliation ";
                        else
                            $what = "PC member ";
                        $details[] = ["#" . ($n + 1), '<div class="mmm">Author ' . $matcher->highlight($au) . '<br />matches ' . $what . $matcher->nameaff_html() . '</div>'];
                    }
                }
        }
        if ((string) $this->collaborators !== "") {
            $au = $user->aucollab_matchers()[0];
            $autext = $au->nameaff_text();
            $autext_deaccent = false;
            if (preg_match('/[\x80-\xFF]/', $autext))
                $autext_deaccent = UnicodeHelper::deaccent($autext);
            if (Text::match_pregexes($this->collaborator_general_pregexes(), $autext, $autext_deaccent)) {
                foreach ($this->collaborator_list(true) as $matcher)
                    if ($matcher->test($au)) {
                        if (!$full_info)
                            return true;
                        $details[] = ["other conflicts", '<div class="mmm">PC member ' . $matcher->highlight($au) . '<br />matches other conflict ' . $matcher->nameaff_html() . '</div>'];
                    }
            }
        }
        return $details;
    }

    function potential_conflict_html(Contact $user, $highlight = false) {
        if (!($details = $this->potential_conflict($user, true)))
            return false;
        usort($details, function ($a, $b) { return strnatcmp($a[0], $b[0]); });
        $authors = array_unique(array_map(function ($x) { return $x[0]; }, $details));
        $authors = array_filter($authors, function ($f) { return $f !== "other conflicts"; });
        $messages = join("", array_map(function ($x) { return $x[1]; }, $details));
        return '<div class="pcconfmatch'
            . ($highlight ? " pcconfmatch-highlight" : "")
            . ' need-tooltip" data-tooltip-class="gray"'
            . ' data-tooltip="' . str_replace('"', '&quot;', $messages)
            . '">Possible conflict'
            . (empty($authors) ? "" : " with " . pluralx($authors, "author") . " " . numrangejoin($authors))
            . '…</div>';
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

    function review_not_incomplete($contact = null) {
        $ci = $this->contact_info($contact);
        return $ci && $ci->review_status > 0;
    }

    function review_submitted($contact = null) {
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

    function has_viewable_tag($tag, Contact $user, $forceShow = null) {
        $tags = $this->viewable_tags($user, $forceShow);
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

    function viewable_tags(Contact $user, $forceShow = null) {
        // see also Contact::can_view_tag()
        if ($user->can_view_most_tags($this, $forceShow))
            return Tagger::strip_nonviewable($this->all_tags_text(), $user);
        else if ($user->privChair && $user->can_view_tags($this, $forceShow))
            return Tagger::strip_nonsitewide($this->all_tags_text(), $user);
        else
            return "";
    }

    function editable_tags(Contact $user) {
        $tags = $this->viewable_tags($user);
        if ($tags !== "") {
            $privChair = $user->allow_administer($this);
            $etags = array();
            foreach (explode(" ", $tags) as $tag)
                if ($tag !== "" && $user->can_change_tag($this, $tag, 0, 1, true))
                    $etags[] = $tag;
            $tags = join(" ", $etags);
        }
        return $tags;
    }

    function add_tag_info_json($pj, Contact $user) {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        $tagger = new Tagger($user);
        $editable = $this->editable_tags($user);
        $viewable = $this->viewable_tags($user);
        $tags_view_html = $tagger->unparse_and_link($viewable);
        $pj->tags = TagInfo::split($viewable);
        $pj->tags_edit_text = $tagger->unparse($editable);
        $pj->tags_view_html = $tags_view_html;
        if (($td = $tagger->unparse_decoration_html($viewable)))
            $pj->tag_decoration_html = $td;
        $pj->color_classes = $this->conf->tags()->color_classes($viewable);
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
            $prow->_prefs_array = $prow->_prefs_cid = null;
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

    function _assign_option_value(PaperOptionValue $ov) {
        if ($this->_option_data === null)
            $this->load_options(false, true);
        $ov->assign(get($this->_option_values, $ov->id, []),
                    get($this->_option_data, $ov->id, []));
    }

    function _reload_option_value(PaperOptionValue $ov) {
        unset($this->optionIds);
        $this->_option_values = $this->_option_data = null;
        $this->load_options(true, true);
        $ov->assign(get($this->_option_values, $ov->id, []),
                    get($this->_option_data, $ov->id, []));
    }

    function options() {
        if ($this->_option_array === null)
            $this->_option_array = $this->_make_option_array(false);
        return $this->_option_array;
    }

    function option($id) {
        return get($this->options(), $id);
    }

    function all_options() {
        if ($this->_all_option_array === null)
            $this->_all_option_array = $this->_make_option_array(true);
        return $this->_all_option_array;
    }

    function all_option($id) {
        return get($this->all_options(), $id);
    }

    function invalidate_options() {
        unset($this->optionIds);
        $this->_option_array = $this->_all_option_array =
            $this->_option_values = $this->_option_data = null;
    }

    private function _add_documents($dids) {
        if ($this->_document_array === null)
            $this->_document_array = [];
        $result = $this->conf->qe("select paperStorageId, paperId, timestamp, mimetype, sha1, documentType, filename, infoJson, size, filterType, originalStorageId from PaperStorage where paperId=? and paperStorageId?a", $this->paperId, $dids);
        $loaded_dids = [];
        while (($di = DocumentInfo::fetch($result, $this->conf, $this))) {
            $this->_document_array[$di->paperStorageId] = $di;
            $loaded_dids[] = $di->paperStorageId;
        }
        Dbl::free($result);
        // rarely might refer to a doc owned by a different paper
        if (count($loaded_dids) != count($dids)
            && ($dids = array_diff($dids, $loaded_dids))) {
            $result = $this->conf->qe("select paperStorageId, paperId, timestamp, mimetype, sha1, documentType, filename, infoJson, size, filterType, originalStorageId from PaperStorage where paperStorageId?a", $dids);
            while (($di = DocumentInfo::fetch($result, $this->conf, $this)))
                $this->_document_array[$di->paperStorageId] = $di;
            Dbl::free($result);
        }
    }

    function document($dtype, $did = 0, $full = false) {
        if ($did <= 0) {
            if ($dtype == DTYPE_SUBMISSION)
                $did = $this->paperStorageId;
            else if ($dtype == DTYPE_FINAL)
                $did = $this->finalPaperStorageId;
            else if (($oa = $this->option($dtype)) && $oa->option->is_document())
                return $oa->document(0);
        }
        if ($did <= 1)
            return null;

        if ($this->_document_array !== null && isset($this->_document_array[$did]))
            return $this->_document_array[$did];

        if ((($dtype == DTYPE_SUBMISSION && $did == $this->paperStorageId && $this->finalPaperStorageId <= 0)
             || ($dtype == DTYPE_FINAL && $did == $this->finalPaperStorageId))
            && !$full) {
            $infoJson = get($this, $dtype == DTYPE_SUBMISSION ? "paper_infoJson" : "final_infoJson");
            return new DocumentInfo(["paperStorageId" => $did, "paperId" => $this->paperId, "documentType" => $dtype, "timestamp" => get($this, "timestamp"), "mimetype" => $this->mimetype, "sha1" => $this->sha1, "size" => get($this, "size"), "infoJson" => $infoJson, "is_partial" => true], $this->conf, $this);
        }

        if ($this->_document_array === null) {
            $x = [];
            if ($this->paperStorageId > 0)
                $x[] = $this->paperStorageId;
            if ($this->finalPaperStorageId > 0)
                $x[] = $this->finalPaperStorageId;
            foreach ($this->options() as $oa)
                if ($oa->option->has_document())
                    $x = array_merge($x, $oa->unsorted_values());
            if ($did > 0)
                $x[] = $did;
            $this->_add_documents($x);
        }
        if ($did > 0 && !isset($this->_document_array[$did]))
            $this->_add_documents([$did]);
        return $did > 0 ? get($this->_document_array, $did) : null;
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

        $this->ensure_reviewer_names();
        if (get($had, "lastLogin"))
            $this->ensure_reviewer_last_login();
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
        if ($this->_full_review
            && ($u = $this->conf->cached_user_by_id($this->_full_review->contactId)))
            $this->_full_review->assign_name($u);
    }

    function full_review_of_id($id) {
        if ($this->_full_review_id === null && !isset($this->_reviews_have["full"])) {
            $this->_full_review_id = "r$id";
            $result = $this->conf->qe("select PaperReview.*, " . $this->ratings_query() . " allRatings from PaperReview where paperId=? and reviewId=?", $this->paperId, $id);
            $this->_full_review = ReviewInfo::fetch($result, $this->conf);
            Dbl::free($result);
            $this->ensure_full_review_name();
        }
        if ($this->_full_review_id === "r$id")
            return $this->_full_review;
        $this->ensure_full_reviews();
        return $this->review_of_id($id);
    }

    function full_review_of_user($contact) {
        $cid = self::contact_to_cid($contact);
        if ($this->_full_review_id === null && !isset($this->_reviews_have["full"])) {
            $row_set = $this->_row_set ? : new PaperInfoSet($this);
            foreach ($row_set as $prow) {
                $prow->_full_review = null;
                $prow->_full_review_id = "u$cid";
            }
            $result = $this->conf->qe("select PaperReview.*, " . $this->ratings_query() . " allRatings from PaperReview where paperId?a and contactId=? order by paperId, reviewId", $row_set->paper_ids(), $cid);
            while (($rrow = ReviewInfo::fetch($result, $this->conf))) {
                $prow = $row_set->get($rrow->paperId);
                $prow->_full_review = $rrow;
            }
            Dbl::free($result);
            $this->ensure_full_review_name();
        }
        if ($this->_full_review_id === "u$cid")
            return $this->_full_review;
        $this->ensure_full_reviews();
        return $this->review_of_user($contact);
    }

    function full_review_of_ordinal($ordinal) {
        if ($this->_full_review_id === null && !isset($this->_reviews_have["full"])) {
            $this->_full_review_id = "o$ordinal";
            $result = $this->conf->qe("select PaperReview.*, " . $this->ratings_query() . " allRatings from PaperReview where paperId=? and reviewOrdinal=?", $this->paperId, $ordinal);
            $this->_full_review = ReviewInfo::fetch($result, $this->conf);
            Dbl::free($result);
            $this->ensure_full_review_name();
        }
        if ($this->_full_review_id === "o$ordinal")
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
            $cinfo->vsreviews_cid_array = null;
            $cinfo->vsreviews_version = $this->_review_array_version;
        }
        return $cinfo->vsreviews_array;
    }

    function viewable_submitted_reviews_by_user(Contact $contact) {
        $cinfo = $contact->__rights($this, null);
        if ($cinfo->vsreviews_cid_array === null
            || $cinfo->vsreviews_version !== $this->_review_array_version) {
            $rrows = $this->viewable_submitted_reviews_by_display($contact);
            $cinfo->vsreviews_cid_array = [];
            foreach ($rrows as $rrow)
                $cinfo->vsreviews_cid_array[$rrow->contactId] = $rrow;
        }
        return $cinfo->vsreviews_cid_array;
    }

    function can_view_review_identity_of($cid, Contact $contact) {
        if ($contact->can_administer($this)
            || $cid == $contact->contactId)
            return true;
        else {
            $rrow = $this->review_of_user($cid);
            return $rrow
                && $contact->can_view_review_identity($this, $rrow);
        }
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

    function ensure_reviewer_names() {
        $this->ensure_reviews();
        if (!empty($this->_review_array)
            && !isset($this->_reviews_have["names"])) {
            $row_set = $this->_row_set ? : new PaperInfoSet($this);
            $pcm = $this->conf->pc_members();
            $missing = [];
            foreach ($row_set as $prow) {
                $prow->_reviews_have["names"] = true;
                foreach ($prow->reviews_by_id() as $rrow)
                    if (($c = get($pcm, $rrow->contactId)))
                        $rrow->assign_name($c);
                    else
                        $missing[$rrow->contactId][] = $rrow;
            }
            if (!empty($missing)) {
                $result = $this->conf->qe("select contactId, firstName, lastName, email from ContactInfo where contactId?a", array_keys($missing));
                while ($result && ($c = $result->fetch_object()))
                    foreach (get($missing, $c->contactId, []) as $rrow)
                        $rrow->assign_name($c);
                Dbl::free($result);
            }
        }
    }

    function ensure_reviewer_last_login() {
        $this->ensure_reviews();
        if (!empty($this->_review_array)
            && !isset($this->_reviews_have["lastLogin"])) {
            $row_set = $this->_row_set ? : new PaperInfoSet($this);
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

    function viewable_comment_skeletons(Contact $user, $forceShow) {
        $crows = [];
        foreach ($this->all_comment_skeletons() as $cid => $crow)
            if ($user->can_view_comment($this, $crow, $forceShow))
                $crows[$cid] = $crow;
        return $crows;
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


    function watching($notifytype, Contact $user) {
        if ($this->watch & ($notifytype << WATCHSHIFT_ISSET))
            return ($this->watch & ($notifytype << WATCHSHIFT_ON)) != 0;
        else
            return ($user->defaultWatch & ($notifytype << WATCHSHIFT_ALLON)) != 0;
    }

    function notify($notifytype, $callback, $contact) {
        $wonflag = ($notifytype << WATCHSHIFT_ON) | ($notifytype << WATCHSHIFT_ALLON);
        $wsetflag = $wonflag | ($notifytype << WATCHSHIFT_ISSET);

        $q = "select ContactInfo.contactId, firstName, lastName, email,
                password, contactTags, roles, defaultWatch,
                PaperReview.reviewType myReviewType,
                PaperReview.reviewSubmitted myReviewSubmitted,
                PaperReview.reviewNeedsSubmit myReviewNeedsSubmit,
                conflictType, watch, preferredEmail, disabled
        from ContactInfo
        left join PaperConflict on (PaperConflict.paperId=$this->paperId and PaperConflict.contactId=ContactInfo.contactId)
        left join PaperWatch on (PaperWatch.paperId=$this->paperId and PaperWatch.contactId=ContactInfo.contactId)
        left join PaperReview on (PaperReview.paperId=$this->paperId and PaperReview.contactId=ContactInfo.contactId)
        where watch is not null
        or conflictType>=" . CONFLICT_AUTHOR . "
        or reviewType is not null
        or (select commentId from PaperComment where paperId=$this->paperId and contactId=ContactInfo.contactId limit 1) is not null
        or (defaultWatch & " . ($notifytype << WATCHSHIFT_ALLON) . ")!=0";
        if ($this->managerContactId > 0)
            $q .= " or ContactInfo.contactId=" . $this->managerContactId;
        $q .= " order by conflictType"; // group authors together

        $result = $this->conf->qe_raw($q);
        $watchers = array();
        $lastContactId = 0;
        while ($result && ($row = Contact::fetch($result))) {
            if ($row->contactId == $lastContactId
                || ($contact && $row->contactId == $contact->contactId)
                || Contact::is_anonymous_email($row->email))
                continue;
            $lastContactId = $row->contactId;

            $w = $row->defaultWatch;
            if ($row->watch & $wsetflag)
                $w = $row->watch;
            if ($w & $wonflag)
                $watchers[$row->contactId] = $row;
        }
        Dbl::free($result);

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
