<?php
// paperinfo.php -- HotCRP paper objects
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperContactInfo {
    public $paperId;
    public $contactId;
    public $conflictType = 0;
    public $reviewType = 0;
    public $reviewSubmitted = 0;
    public $reviewNeedsSubmit = 1;
    public $review_token_cid = 0;

    public $is_full = false;
    public $reviewId;
    public $reviewModified;
    public $reviewOrdinal;
    public $reviewBlind;
    public $requestedBy;
    public $timeApprovalRequested;
    public $reviewRound;

    public $rights_forced = null;
    public $forced_rights_link = null;

    static function make(PaperInfo $prow, $cid, $full = false) {
        $ci = new PaperContactInfo;
        $ci->paperId = $prow->paperId;
        $ci->contactId = $cid;
        $ci->is_full = $full;
        return $ci;
    }

    static function make_my(PaperInfo $prow, $cid, $object) {
        $ci = PaperContactInfo::make($prow, $cid);
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
        return $ci;
    }

    private function merge($full) {
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
            $this->review_token_cid = null;
        $this->is_full = $full;
        if ($full && $this->reviewType)
            foreach (["reviewId", "reviewModified", "reviewOrdinal",
                      "reviewBlind", "requestedBy", "timeApprovalRequested",
                      "reviewRound"] as $k)
                $this->$k = (int) $this->$k;
    }

    static function load_into(PaperInfo $prow, $cid, $rev_tokens, $full) {
        global $Me;
        $conf = $prow->conf;
        $pid = $prow->paperId;
        $q = "select conflictType, reviewType, reviewSubmitted, reviewNeedsSubmit,
                PaperReview.contactId as review_token_cid";
        if ($full)
            $q .= ", reviewId, reviewModified, reviewOrdinal, reviewBlind, requestedBy, timeApprovalRequested, reviewRound";
        if ($cid && !$rev_tokens
            && $prow->_row_set && $prow->_row_set->size() > 1) {
            $result = $conf->qe("$q, Paper.paperId paperId, $cid contactId
                from Paper
                left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$cid)
                left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$cid)
                where Paper.paperId?a", $prow->_row_set->pids());
            $found = false;
            $map = [];
            while ($result && ($ci = $result->fetch_object("PaperContactInfo"))) {
                $ci->merge($full);
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
            $ci->merge($full);
            $prow->_add_contact_info($ci);
        }
        Dbl::free($result);
        foreach ($cids as $cid)
            if (!$prow->_get_contact_info($cid))
                $prow->_add_contact_info(PaperContactInfo::make($prow, $cid, $full));
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

    function __construct($x, $only_tabs = false) {
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
                    if (strpos($a[2], "@") === false)
                        $this->affiliation = $a[2];
                    else
                        $this->email = $a[2];
                }
            } else {
                if (preg_match('/\A\s*(\S.*?)\s*\((.*)\)(?:[\s,;.]*|\s*(?:-+|–|—|:)\s+.*)\z/', $x, $m)) {
                    $this->affiliation = trim($m[2]);
                    $x = $m[1];
                } else
                    $this->affiliation = "";
                $this->_name = $x;
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
        if (is_string($x) && ($hash = strpos($x, "#")) !== false)
            $x = substr($x, 0, $hash);
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
        if ($this->affiliation !== "" && $this->firstName === "" && $this->lastName === "" && $this->email === "")
            $aff = $this->affiliation;
        else if ($this->affiliation === "" && is_string($x))
            $aff = $x;
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
    function test($au) {
        if (!$this->general_pregexes)
            return false;
        if (is_string($au))
            $au = new PaperInfo_Author($au);
        if ($au->firstName_deaccent === null) {
            $au->firstName_deaccent = $au->lastName_deaccent = false;
            $au->firstName_deaccent = UnicodeHelper::deaccent($au->firstName);
            $au->lastName_deaccent = UnicodeHelper::deaccent($au->lastName);
            $au->affiliation_deaccent = strtolower(UnicodeHelper::deaccent($au->affiliation));
        }
        if ($this->lastName_matcher) {
            if ($au->lastName !== ""
                && Text::match_pregexes($this->lastName_matcher, $au->lastName, $au->lastName_deaccent)
                && ($au->firstName === ""
                    || !$this->firstName_matcher
                    || Text::match_pregexes($this->firstName_matcher, $au->firstName, $au->firstName_deaccent)))
                return true;
        }
        if ($this->affiliation_matcher && $au->affiliation !== "") {
            if (self::test_affiliation($au->affiliation_deaccent, $this->affiliation_matcher))
                return true;
        }
        return false;
    }
    function highlight($au) {
        $aff_suffix = null;
        if (is_object($au)) {
            if ($au->affiliation)
                $aff_suffix = "(" . htmlspecialchars($au->affiliation) . ")";
            $au = $au->nameaff_text();
        }
        $au = Text::highlight($au, $this->general_pregexes);
        if ($aff_suffix && str_ends_with($au, $aff_suffix))
            $au = substr($au, 0, -strlen($aff_suffix)) . ' <span class="auaff">' . $aff_suffix . '</span>';
        return $au;
    }

    static function wordinfo() {
        global $ConfSitePATH;
        // XXX validate input JSON
        if (self::$wordinfo === null)
            self::$wordinfo = (array) json_decode(file_get_contents("$ConfSitePATH/etc/affiliationmatching.json"));
        return self::$wordinfo;
    }
    private static function test_affiliation($mtext, $md) {
        if (!$md[1])
            return preg_match($md[2], $mtext) === 1;
        else if (!preg_match_all($md[2], $mtext, $m))
            return false;
        $result = true;
        foreach ($md[0] as $w) { // $md[0] contains the requested words (no alternates).
            $aw = get(self::$wordinfo, $w);
            $weak = $aw && isset($aw->weak) && $aw->weak;
            $saw_w = in_array($w, $m[0]);
            if (!$saw_w && $aw && isset($aw->alternate)) {
                // We didn't see a requested word; did we see one of its alternates?
                foreach ($aw->alternate as $alt) {
                    if (is_object($alt)) {
                        if ((isset($alt->if) && !self::match_if($alt->if, $md[0]))
                            || (isset($alt->if_not) && self::match_if($alt->if_not, $md[0])))
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
            // Check for sync words: e.g., "penn state university" ≠ "university penn"
            if ($saw_w && $aw && isset($aw->sync)) {
                foreach (explode(" ", $aw->sync) as $syncw)
                    if ($syncw !== ""
                        && (in_array($syncw, $md[0])
                            ? !in_array($syncw, $m[0])
                            : preg_match('/\b' . $syncw . '\b/', $mtext))) {
                        $saw_w = false;
                        break;
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

class PaperInfoSet implements IteratorAggregate {
    private $prows = [];
    private $by_pid = [];
    function __construct(PaperInfo $prow = null) {
        if ($prow)
            $this->add($prow);
    }
    function add(PaperInfo $prow) {
        assert(!$prow->_row_set);
        $this->prows[] = $prow;
        if (!isset($this->by_pid[$prow->paperId]))
            $this->by_pid[$prow->paperId] = $prow;
        $prow->_row_set = $this;
    }
    function all() {
        return $this->prows;
    }
    function size() {
        return count($this->prows);
    }
    function pids() {
        return array_keys($this->by_pid);
    }
    function get($pid) {
        return get($this->by_pid, $pid);
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

    private $_unaccented_title = null;
    private $_contact_info = array();
    private $_contact_info_rights_version = 0;
    private $_author_array = null;
    private $_collaborator_array = null;
    private $_collaborator_general_pregexes = null;
    private $_prefs_array = null;
    private $_prefs_cid = null;
    private $_review_id_array = null;
    private $_topics_array = null;
    private $_topic_interest_score_array = null;
    private $_option_values = null;
    private $_option_data = null;
    private $_option_array = null;
    private $_all_option_array = null;
    private $_document_array = null;
    private $_conflicts;
    private $_conflicts_email;
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
            $this->_contact_info_rights_version = Contact::$rights_version;
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

    static function fetch_all($result, $contact, Conf $conf = null) {
        $set = new PaperInfoSet;
        while (($prow = self::fetch($result, $contact, $conf)))
            $set->add($prow);
        return $set;
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

    function contact_info($contact = null, $full = false) {
        global $Me;
        $rev_tokens = null;
        if (!$contact || is_object($contact)) {
            $contact = $contact ? : $Me;
            $rev_tokens = $contact->review_tokens();
        }
        $cid = self::contact_to_cid($contact);
        if ($this->_contact_info_rights_version !== Contact::$rights_version) {
            $this->_contact_info = array();
            $this->_contact_info_rights_version = Contact::$rights_version;
        }
        if (!array_key_exists($cid, $this->_contact_info)
            || ($full && !$this->_contact_info[$cid]->is_full)) {
            if (!$rev_tokens && !$full
                && property_exists($this, "allReviewNeedsSubmit")) {
                $ci = PaperContactInfo::make($this, $cid);
                if (($c = get($this->conflicts(), $cid)))
                    $ci->conflictType = $c->conflictType;
                $ci->reviewType = $this->review_type($cid);
                $rs = $this->review_cid_int_array(false, "reviewSubmitted", "allReviewSubmitted");
                $ci->reviewSubmitted = get($rs, $cid, 0);
                $rs = $this->review_cid_int_array(false, "reviewNeedsSubmit", "allReviewNeedsSubmit");
                $ci->reviewNeedsSubmit = get($rs, $cid, 1);
                $this->_contact_info[$cid] = $ci;
            } else
                PaperContactInfo::load_into($this, $cid, $rev_tokens, $full);
        }
        return $this->_contact_info[$cid];
    }

    function replace_contact_info_map($cimap) {
        $old_cimap = $this->_contact_info;
        $this->_contact_info = $cimap;
        $this->_contact_info_rights_version = Contact::$rights_version;
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
        $ci = $this->contact_info($contact);
        return $ci ? $ci->conflictType : 0;
    }

    function has_conflict($contact) {
        return $this->conflict_type($contact) > 0;
    }

    function has_author($contact) {
        return $this->conflict_type($contact) >= CONFLICT_AUTHOR;
    }

    function collaborator_list($matcher = false) {
        if ($this->_collaborator_array === null
            || (!empty($this->_collaborator_array) && $matcher
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
                foreach ($user->aucollab_matchers() as $matcheridx => $matcher) {
                    if ($matcher->test($au)) {
                        if ($full_info)
                            $details[] = ["#" . ($n + 1), '<div class="mmm">Author ' . $matcher->highlight($au) . '<br />matches ' . ($matcheridx ? "PC collaborator " : "PC member ") . $matcher->nameaff_html() . '</div>'];
                        else
                            return true;
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
                        if ($full_info)
                            $details[] = ["other conflicts", '<div class="mmm">PC member ' . $matcher->highlight($au) . '<br />matches other conflict ' . $matcher->nameaff_html() . '</div>'];
                        else
                            return true;
                    }
            }
        }
        return $details;
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
        $cid = self::contact_to_cid($contact);
        if ($this->_contact_info_rights_version === Contact::$rights_version
            && array_key_exists($cid, $this->_contact_info)) {
            $ci = $this->_contact_info[$cid];
            return $ci ? $ci->reviewType : 0;
        }
        if (!isset($this->allReviewTypes) && isset($this->reviewTypes)
            && ($x = get($this->submitted_review_types(), $cid)) !== null)
            return $x;
        return get($this->all_review_types(), $cid);
    }

    function has_reviewer($contact) {
        return $this->review_type($contact) > 0;
    }

    function review_not_incomplete($contact = null) {
        $ci = $this->contact_info($contact);
        return $ci && $ci->reviewType > 0
            && ($ci->reviewSubmitted > 0 || $ci->reviewNeedsSubmit == 0);
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

    function searchable_tags(Contact $user, $forceShow = null) {
        if ($user->allow_administer($this))
            return $this->all_tags_text();
        else
            return $this->viewable_tags($user, $forceShow);
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
                if (!($tag === ""
                      || (($t = $this->conf->tags()->check_base($tag))
                          && ($t->vote
                              || $t->approval
                              || (!$privChair
                                  && (!$user->privChair || !$t->sitewide)
                                  && ($t->chair || $t->rank))))))
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
        $tags_view_html = $tagger->unparse_and_link($viewable, false);
        $pj->tags = TagInfo::split($viewable);
        $pj->tags_edit_text = $tagger->unparse($editable);
        $pj->tags_view_html = $tags_view_html;
        if (($td = $tagger->unparse_decoration_html($viewable)))
            $pj->tag_decoration_html = $td;
        $pj->color_classes = $this->conf->tags()->color_classes($viewable);
    }

    private function load_topics() {
        $result = $this->conf->qe_raw("select group_concat(topicId) from PaperTopic where paperId=$this->paperId");
        $row = edb_row($result);
        $this->topicIds = $row ? $row[0] : "";
        Dbl::free($result);
    }

    function has_topics() {
        return count($this->topics()) > 0;
    }

    function topics() {
        if ($this->_topics_array === null) {
            if (!property_exists($this, "topicIds"))
                $this->load_topics();
            if (is_array($this->topicIds))
                $this->_topics_array = $this->topicIds;
            else {
                $this->_topics_array = array();
                if ($this->topicIds !== "" && $this->topicIds !== null) {
                    foreach (explode(",", $this->topicIds) as $t)
                        $this->_topics_array[] = (int) $t;
                    $tomap = $this->conf->topic_order_map();
                    usort($this->_topics_array, function ($a, $b) use ($tomap) {
                        return $tomap[$a] - $tomap[$b];
                    });
                }
            }
        }
        return $this->_topics_array;
    }

    function unparse_topics_text() {
        $tarr = $this->topics();
        if (!$tarr)
            return "";
        $out = [];
        $tmap = $this->conf->topic_map();
        foreach ($tarr as $t)
            $out[] = $tmap[$t];
        return join("; ", $out);
    }

    private static function render_topic($t, $i, $tmap, &$long) {
        $s = '<span class="topicsp topic' . ($i ? : 0);
        $tname = $tmap[$t];
        if (strlen($tname) <= 50)
            $s .= ' nw';
        else
            $long = true;
        return $s . '">' . htmlspecialchars($tname) . '</span>';
    }

    private static function render_topic_list(Conf $conf, $out, $comma, $long) {
        if ($comma)
            return join($conf->topic_separator(), $out);
        else if ($long)
            return '<p class="od">' . join('</p><p class="od">', $out) . '</p>';
        else
            return '<p class="topicp">' . join(' ', $out) . '</p>';
    }

    function unparse_topics_html($comma, Contact $interests_user = null) {
        if (!($topics = $this->topics()))
            return "";
        $out = array();
        $tmap = $this->conf->topic_map();
        $interests = [];
        if ($interests_user)
            $interests = $interests_user->topic_interest_map();
        $long = false;
        foreach ($topics as $t)
            $out[] = self::render_topic($t, get($interests, $t), $tmap, $long);
        return self::render_topic_list($this->conf, $out, $comma, $long);
    }

    static function unparse_topic_list_html(Conf $conf, $ti, $comma) {
        if (!$ti)
            return "";
        $out = array();
        $tmap = $conf->topic_map();
        $tomap = $conf->topic_order_map();
        $long = false;
        foreach ($ti as $t => $i)
            $out[$tomap[$t]] = self::render_topic($t, $i, $tmap, $long);
        ksort($out);
        return self::render_topic_list($conf, $out, $comma, $long);
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
                $topics = $this->topics();
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

    function conflicts($email = false) {
        if ($email ? !$this->_conflicts_email : !isset($this->_conflicts)) {
            $this->_conflicts = array();
            if (!$email && isset($this->allConflictType)) {
                $vals = array();
                foreach (explode(",", $this->allConflictType) as $x)
                    $vals[] = explode(" ", $x);
            } else if (!$email)
                $vals = $this->conf->fetch_rows("select contactId, conflictType from PaperConflict where paperId=$this->paperId");
            else {
                $vals = $this->conf->fetch_rows("select ContactInfo.contactId, conflictType, email from PaperConflict join ContactInfo using (contactId) where paperId=$this->paperId");
                $this->_conflicts_email = true;
            }
            foreach ($vals as $v)
                if ($v[1] > 0) {
                    $row = (object) array("contactId" => (int) $v[0], "conflictType" => (int) $v[1]);
                    if (isset($v[2]) && $v[2])
                        $row->email = $v[2];
                    $this->_conflicts[$row->contactId] = $row;
                }
        }
        return $this->_conflicts;
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
        $this->allReviewerPreference = $this->conf->fetch_value("select " . $this->conf->query_all_reviewer_preference() . " from PaperReviewPreference where paperId=$this->paperId");
        $this->_prefs_array = $this->_prefs_cid = null;
    }

    function reviewer_preferences() {
        if (!property_exists($this, "allReviewerPreference"))
            $this->load_reviewer_preferences();
        if ($this->_prefs_array === null) {
            $x = array();
            if ($this->allReviewerPreference !== "" && $this->allReviewerPreference !== null) {
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

    function reviewer_preference($contact) {
        $cid = is_int($contact) ? $contact : $contact->contactId;
        if ($this->_prefs_cid === null && $this->_prefs_array === null) {
            $row_set = $this->_row_set ? : new PaperInfoSet($this);
            foreach ($row_set->all() as $prow)
                $prow->_prefs_cid = [$cid, null];
            $result = $this->conf->qe("select paperId, preference, expertise from PaperReviewPreference where paperId?a and contactId=?", $row_set->pids(), $cid);
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
        return $pref ? : [0, null];
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
            $result = $this->conf->qe("select paperId, optionId, value, data, dataOverflow from PaperOption where paperId?a order by paperId", $row_set->pids());
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
            if (($o = $paper_opts->find($oid, $all)))
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
        $ov->assign($this->_option_values[$ov->id], $this->_option_data[$ov->id]);
    }

    function _reload_option_value(PaperOptionValue $ov) {
        unset($this->optionIds);
        $this->_option_values = $this->_option_data = null;
        $this->load_options(true, true);
        $ov->assign($this->_option_values[$ov->id], $this->_option_data[$ov->id]);
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
                if ($oa->option->has_document_storage())
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

    function npages() {
        $doc = $this->document($this->finalPaperStorageId <= 0 ? DTYPE_SUBMISSION : DTYPE_FINAL);
        return $doc ? $doc->npages() : 0;
    }

    function num_reviews_submitted() {
        if (!property_exists($this, "reviewCount"))
            $this->reviewCount = $this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=$this->paperId and reviewSubmitted>0");
        return (int) $this->reviewCount;
    }

    function num_reviews_assigned() {
        if (!property_exists($this, "startedReviewCount"))
            $this->startedReviewCount = $this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=$this->paperId and (reviewSubmitted>0 or reviewNeedsSubmit>0)");
        return (int) $this->startedReviewCount;
    }

    function num_reviews_in_progress() {
        if (!property_exists($this, "inProgressReviewCount")) {
            if (isset($this->reviewCount) && isset($this->startedReviewCount) && $this->reviewCount === $this->startedReviewCount)
                $this->inProgressReviewCount = $this->reviewCount;
            else
                $this->inProgressReviewCount = $this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=$this->paperId and (reviewSubmitted>0 or reviewModified>0)");
        }
        return (int) $this->inProgressReviewCount;
    }

    function num_reviews_started($user) {
        if ($user->privChair || !$this->conflict_type($user))
            return $this->num_reviews_assigned();
        else
            return $this->num_reviews_in_progress();
    }

    private function load_score_array($restriction, $args) {
        $req = array();
        for ($i = 0; $i < count($args); $i += 2)
            $req[] = "group_concat(" . $args[$i] . " order by reviewId) " . $args[$i + 1];
        $result = $this->conf->qe("select " . join(", ", $req) . " from PaperReview where paperId=$this->paperId and " . ($restriction ? "reviewSubmitted>0" : "true"));
        $row = $result ? $result->fetch_assoc() : null;
        foreach ($row ? : array() as $k => $v)
            $this->$k = $v;
        Dbl::free($result);
    }

    private function load_scores(/* args */) {
        $args = func_get_args();
        $this->load_score_array(true, count($args) == 1 ? $args[0] : $args);
    }

    private function review_cid_int_array($restriction, $basek, $k) {
        $ck = $restriction ? "reviewContactIds" : "allReviewContactIds";
        if (!property_exists($this, $ck)
            || (!empty($this->$ck) && !property_exists($this, $k)))
            $this->load_score_array($restriction, [$basek, $k, "contactId", $ck]);
        if (empty($this->$ck))
            return array();
        $ka = explode(",", $this->$ck);
        $va = json_decode("[" . $this->$k . "]", true); // json_decode produces int values
        return count($ka) == count($va) ? array_combine($ka, $va) : false;
    }

    function all_reviewers() {
        if (!property_exists($this, "allReviewContactIds"))
            $this->load_score_array(false, ["contactId", "allReviewContactIds"]);
        return json_decode("[" . ($this->allReviewContactIds ? : "") . "]");
    }

    function submitted_reviewers() {
        if (!property_exists($this, "reviewContactIds"))
            $this->load_score_array(true, ["contactId", "reviewContactIds"]);
        return json_decode("[" . ($this->reviewContactIds ? : "") . "]");
    }

    function viewable_submitted_reviewers($contact, $forceShow) {
        if (!property_exists($this, "reviewContactIds"))
            $this->load_scores("contactId", "reviewContactIds");
        if ($this->reviewContactIds) {
            // XXX should include requestedBy checks maybe
            if ($contact->can_view_review($this, null, $forceShow))
                return json_decode("[" . $this->reviewContactIds . "]");
            else if ($this->review_type($contact))
                return array($contact->contactId);
        }
        return array();
    }

    function all_review_ids() {
        return $this->review_cid_int_array(false, "reviewId", "allReviewIds");
    }

    function review_ordinals() {
        return $this->review_cid_int_array(true, "reviewOrdinal", "reviewOrdinals");
    }

    function review_ordinal($cid) {
        return get($this->review_ordinals(), $cid);
    }

    function all_review_types() {
        return $this->review_cid_int_array(false, "reviewType", "allReviewTypes");
    }

    function submitted_review_types() {
        return $this->review_cid_int_array(true, "reviewType", "reviewTypes");
    }

    private function _review_word_counts($restriction, $basek, $k, $count) {
        $a = $this->review_cid_int_array($restriction, $basek, $k);
        if ($a !== false || $count)
            return $a;
        $result = $this->conf->qe("select * from PaperReview where reviewWordCount is null and paperId=$this->paperId");
        $rf = $this->conf->review_form();
        $qs = [];
        while (($rrow = edb_orow($result)))
            $qs[] = "update PaperReview set reviewWordCount=" . $rf->word_count($rrow) . " where reviewId=" . $rrow->reviewId;
        Dbl::free($result);
        if (count($qs)) {
            $mresult = Dbl::multi_qe($this->conf->dblink, join(";", $qs));
            $mresult->free_all();
            unset($this->reviewWordCounts, $this->allReviewWordCounts);
        }
        return $this->_review_word_counts($restriction, $basek, $k, $count + 1);
    }

    function submitted_review_word_counts() {
        return $this->_review_word_counts(true, "reviewWordCount", "reviewWordCounts", 0);
    }

    function all_review_word_counts() {
        return $this->_review_word_counts(false, "reviewWordCount", "allReviewWordCounts", 0);
    }

    function submitted_review_word_count($cid) {
        return get($this->submitted_review_word_counts(), $cid);
    }

    function all_review_rounds() {
        return $this->review_cid_int_array(false, "reviewRound", "allReviewRounds");
    }

    function submitted_review_rounds() {
        return $this->review_cid_int_array(true, "reviewRound", "reviewRounds");
    }

    function submitted_review_round($cid) {
        return get($this->submitted_review_rounds());
    }

    function review_round($cid) {
        if (!isset($this->allReviewRounds) && isset($this->reviewRounds)
            && ($x = get($this->submitted_review_rounds())) !== null)
            return $x;
        return get($this->all_review_rounds(), $cid);
    }

    function scores($fid) {
        $fid = is_object($fid) ? $fid->id : $fid;
        return $this->review_cid_int_array(true, $fid, "{$fid}Scores");
    }

    function score($fid, $cid) {
        return get($this->scores($fid), $cid);
    }

    function may_have_viewable_scores($field, Contact $contact, $forceShow) {
        $field = is_object($field) ? $field : $this->conf->review_field($field);
        return $contact->can_view_review($this, $field->view_score, $forceShow)
            || $this->review_type($contact);
    }

    function viewable_scores($field, Contact $contact, $forceShow) {
        $field = is_object($field) ? $field : $this->conf->review_field($field);
        $view = $contact->can_view_review($this, $field->view_score, $forceShow);
        if ($view || $this->review_type($contact)) {
            $s = $this->scores($field->id);
            if ($view)
                return $s;
            else if (($my_score = get($s, $contact->contactId)) !== null)
                return array($contact->contactId => $my_score);
        }
        return null;
    }

    function review_status($cid) {
        return $this->contact_info($cid, true);
    }

    function can_view_review_identity_of($cid, Contact $contact, $forceShow = null) {
        if ($contact->can_administer($this, $forceShow)
            || $cid == $contact->contactId)
            return true;
        // load information needed to make the call
        if ($this->_review_id_array === null
            || ($contact->review_tokens() && !property_exists($this, "reviewTokens"))) {
            $need = array("contactId", "reviewContactIds",
                          "requestedBy", "reviewRequestedBys",
                          "reviewType", "reviewTypes");
            if ($this->conf->review_blindness() == Conf::BLIND_OPTIONAL)
                array_push($need, "reviewBlind", "reviewBlinds");
            if ($contact->review_tokens())
                array_push($need, "reviewToken", "reviewTokens");
            for ($i = 1; $i < count($need); $i += 2)
                if (!property_exists($this, $need[$i])) {
                    $this->load_scores($need);
                    break;
                }
            for ($i = 0; $i < count($need); $i += 2) {
                $k = $need[$i + 1];
                $need[$i + 1] = explode(",", $this->$k);
            }
            $this->_review_id_array = array();
            for ($n = 0; $n < count($need[1]); ++$n) {
                $rrow = (object) array("reviewSubmitted" => 1);
                for ($i = 0; $i < count($need); $i += 2) {
                    $k = $need[$i];
                    $rrow->$k = $need[$i + 1][$n];
                }
                $this->_review_id_array[(int) $rrow->contactId] = $rrow;
            }
        }
        // call contact
        return ($rrow = get($this->_review_id_array, $cid))
            && $contact->can_view_review_identity($this, $rrow, $forceShow);
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
        foreach ($row_set->all() as $prow)
            $prow->_comment_array = [];
        $result = $this->conf->qe(self::fetch_comment_query()
            . " where paperId?a order by paperId, commentId", $row_set->pids());
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

    function viewable_comments(Contact $user, $forceShow) {
        $crows = [];
        foreach ($this->all_comments() as $cid => $crow)
            if ($user->can_view_comment($this, $crow, $forceShow))
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
}
