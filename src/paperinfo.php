<?php
// paperinfo.php -- HotCRP paper objects
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

final class PaperReviewPreference {
    /** @var int
     * @readonly */
    public $preference;
    /** @var ?int
     * @readonly */
    public $expertise;

    /** @param int $preference
     * @param ?int $expertise */
    function __construct($preference = 0, $expertise = null) {
        $this->preference = $preference ?? 0;
        $this->expertise = $expertise;
    }

    /** @return PaperReviewPreference */
    static function make_sentinel() {
        return new PaperReviewPreference(-PHP_INT_MAX, null);
    }

    /** @return bool */
    function exists() {
        return $this->preference !== 0 || $this->expertise !== null;
    }

    /** @return string */
    function unparse() {
        return $this->preference . unparse_expertise($this->expertise);
    }

    /** @param PaperReviewPreference $a
     * @param PaperReviewPreference $b
     * @return -1|0|1 */
    static function compare($a, $b) {
        if ($a->preference !== $b->preference) {
            return $a->preference <=> $b->preference;
        } else if ($a->expertise !== $b->expertise) {
            $anull = $a->expertise === null;
            if ($anull || $b->expertise === null) {
                return $anull ? 1 : -1;
            }
            return $a->expertise <=> $b->expertise;
        }
        return 0;
    }

    /** @param ?int $tv
     * @return string */
    function unparse_span($tv = null) {
        $t = "P" . unparse_number_pm_html($this->preference) . unparse_expertise($this->expertise);
        if ($tv !== null) {
            $t .= " T" . unparse_number_pm_html($tv);
        }
        if ($this->preference === 0 && ($tv ?? 0) === 0) {
            $sign = 0;
        } else {
            $sign = ($this->preference ? : $tv ?? 0) > 0 ? 1 : -1;
        }
        return "<span class=\"asspref{$sign}\">{$t}</span>";
    }

    /** @return array{int,?int} */
    function as_list() {
        return [$this->preference, $this->expertise];
    }

    /** @param int $pid
     * @param int $cid
     * @param callable(string,...) $stagef */
    function save($pid, $cid, $stagef) {
        if ($this->exists()) {
            $stagef("insert into PaperReviewPreference set paperId=?, contactId=?, preference=?, expertise=? on duplicate key update preference=?, expertise=?", $pid, $cid, $this->preference, $this->expertise, $this->preference, $this->expertise);
        } else {
            $stagef("delete from PaperReviewPreference where paperId=? and contactId=?", $pid, $cid);
        }
    }
}

final class PaperContactInfo {
    /** @var int
     * @readonly */
    public $paperId;
    /** @var int
     * @readonly */
    public $contactId;
    /** @var int */
    public $conflictType = 0;
    /** @var int */
    public $rflags = 0;
    /** @var int */
    public $reviewType = 0;
    /** @var int */
    public $reviewRound = 0;
    /** @var int */
    public $review_status = 0;    // 0 means no review
    const CIRS_NONE = 0;
    const CIRS_DECLINED = 1;      // declined assigned review
    const CIRS_UNSUBMITTED = 2;   // review not submitted, needs submit
    const CIRS_PROXIED = 3;       // review proxied (e.g., lead)
    const CIRS_SUBMITTED = 4;     // review submitted

    /** @var ?PaperContactInfo */
    private $forced_rights_link = null;

    // set by Contact::rights()
    /** @var int */
    public $ciflags = 0;
    const CIF_SET0 = 0x1;
    const CIF_ALLOW_ADMINISTER = 0x2;
    const CIF_ALLOW_ADMINISTER_FORCED = 0x4;
    const CIFM_SET0 = 0x7;
    const CIF_RECURSION = 0x8;
    const CIF_SET1 = 0x10;
    const CIF_CAN_ADMINISTER = 0x20;
    const CIF_ALLOW_PC_BROAD = 0x40;
    const CIF_ALLOW_PC = 0x80;
    const CIF_ALLOW_AUTHOR_EDIT = 0x100;
    const CIF_ACT_AUTHOR_VIEW = 0x200;
    const CIF_ALLOW_AUTHOR_VIEW = 0x400;
    const CIF_CAN_VIEW_DECISION = 0x800;
    const CIF_SET2 = 0x1000;
    const CIF_ALLOW_VIEW_AUTHORS = 0x2000;
    const CIF_PREFER_VIEW_AUTHORS = 0x4000;
    const CIFSHIFT_VIEW_AUTHORS_STATE = 13;
    const CIF_SET3 = 0x8000;
    const CIF_CAN_VIEW_SUBMITTED_REVIEW = 0x10000;
    /** @var bool */
    public $primary_administrator;
    /** @var int */
    public $view_conflict_type;

    // cached by PaperInfo methods
    /** @var ?list<ReviewInfo> */
    public $vreviews_array;
    /** @var ?int */
    public $vreviews_version;
    /** @var ?string */
    public $viewable_tags;
    /** @var ?string */
    public $searchable_tags;

    /** @param int $paperId
     * @param int $contactId */
    function __construct($paperId, $contactId) {
        $this->paperId = $paperId;
        $this->contactId = $contactId;
    }

    /** @param PaperInfo $prow
     * @param Contact $user
     * @return bool */
    static function is_nonempty($prow, $user) {
        return $user->isPC
            && isset($prow->leadContactId)
            && $prow->leadContactId == $user->contactXid
            && !$prow->conf->setting("lead_noseerev");
    }

    /** @param PaperInfo $prow
     * @param Contact $user
     * @suppress PhanAccessReadOnlyProperty */
    static function make_user($prow, $user) {
        $ci = new PaperContactInfo($prow->paperId, $user->contactXid);
        if (self::is_nonempty($prow, $user)) {
            $ci->review_status = self::CIRS_PROXIED;
        }
        return $ci;
    }

    /** @param PaperInfo $prow
     * @param Contact $user
     * @suppress PhanAccessReadOnlyProperty */
    static function make_my($prow, $user) {
        $ci = PaperContactInfo::make_user($prow, $user);
        $ci->conflictType = (int) $prow->conflictType;
        if (isset($prow->myReviewPermissions)) {
            $ci->mark_my_review_permissions($prow->conf, $prow->myReviewPermissions);
        } else if ($prow->reviewSignatures !== null) {
            foreach ($prow->reviews_by_user($user->contactId, $user->review_tokens()) as $rrow) {
                $ci->mark_review($rrow);
            }
        }
        return $ci;
    }

    /** @return bool */
    function is_author() {
        return $this->conflictType >= CONFLICT_AUTHOR;
    }

    /** @return bool */
    function is_reviewer() {
        return $this->reviewType > 0;
    }

    /** @return bool */
    function conflicted() {
        return $this->conflictType > CONFLICT_MAXUNCONFLICTED;
    }

    /** @return bool */
    function unconflicted() {
        return $this->conflictType <= CONFLICT_MAXUNCONFLICTED;
    }

    /** @return bool */
    function self_assigned() {
        return ($this->rflags & ReviewInfo::RF_SELF_ASSIGNED) !== 0;
    }

    /** @return bool */
    function review_submitted() {
        return ($this->rflags & ReviewInfo::RF_SUBMITTED) !== 0;
    }

    /** @return bool */
    function allow_administer() {
        return ($this->ciflags & self::CIF_ALLOW_ADMINISTER) !== 0;
    }

    /** @return bool */
    function allow_administer_forced() {
        return ($this->ciflags & self::CIF_ALLOW_ADMINISTER_FORCED) !== 0;
    }

    /** @return bool */
    function can_administer() {
        return ($this->ciflags & self::CIF_CAN_ADMINISTER) !== 0;
    }

    /** @return bool */
    function allow_pc_broad() {
        return ($this->ciflags & self::CIF_ALLOW_PC_BROAD) !== 0;
    }

    /** @return bool */
    function allow_pc() {
        return ($this->ciflags & self::CIF_ALLOW_PC) !== 0;
    }

    /** @return bool */
    function allow_author_edit() {
        return ($this->ciflags & self::CIF_ALLOW_AUTHOR_EDIT) !== 0;
    }

    /** @return bool */
    function allow_author_view() {
        return ($this->ciflags & self::CIF_ALLOW_AUTHOR_VIEW) !== 0;
    }

    /** @return bool */
    function act_author_view() {
        return ($this->ciflags & self::CIF_ACT_AUTHOR_VIEW) !== 0;
    }

    /** @return bool */
    function can_view_decision() {
        return ($this->ciflags & self::CIF_CAN_VIEW_DECISION) !== 0;
    }

    /** @param int $ct */
    function mark_conflict($ct) {
        $this->conflictType = max($ct, $this->conflictType);
    }

    /** @param int $cif
     * @suppress PhanDeprecatedProperty */
    function __set_ciflags($cif) {
        $this->ciflags = $cif;
        assert(($cif & self::CIF_SET0) !== 0);
    }

    /** @param Conf $conf
     * @param int $rflags
     * @param int $reviewNeedsSubmit
     * @param int $reviewRound */
    private function mark_review_type($conf, $rflags,
            $reviewNeedsSubmit, $reviewRound) {
        $this->rflags |= $rflags;

        if (($rflags & ReviewInfo::RFM_TYPES) !== 0) {
            $this->reviewType = max(ReviewInfo::rflags_type($rflags), $this->reviewType);
            $this->reviewRound = $reviewRound;

            if (($rflags & ReviewInfo::RF_SUBMITTED) !== 0
                || $reviewNeedsSubmit === 0) {
                $this->review_status = self::CIRS_SUBMITTED;
            } else if ($this->review_status === 0) {
                $m = $conf->time_review_open() ? ReviewInfo::RF_LIVE : ReviewInfo::RFM_NONDRAFT;
                if (($rflags & $m) !== 0) {
                    $this->review_status = self::CIRS_UNSUBMITTED;
                }
            }
        }
    }

    function mark_review(ReviewInfo $rrow) {
        $this->mark_review_type($rrow->conf, $rrow->rflags,
            $rrow->reviewNeedsSubmit, $rrow->reviewRound);
    }

    /** @param ?string $sig */
    private function mark_my_review_permissions($conf, $sig) {
        if ((string) $sig !== "") {
            foreach (explode(",", $sig) as $r) {
                $a = explode(" ", $r);
                $this->mark_review_type($conf, (int) $a[0], (int) $a[1], (int) $a[2]);
            }
        }
    }

    /** @param Contact $user */
    static function load_into(PaperInfo $prow, $user) {
        // fake user with no DB capabilities (e.g., author_user()) requires no load
        $rev_tokens = $user->review_tokens();
        if ($user->contactXid <= 0
            && !$rev_tokens) {
            $prow->_set_empty_contact_info($user);
            return;
        }

        // choose user set:
        // normally just this user; might be whole PC
        $user_set = [$user->contactXid => $user];
        if ($user->contactXid > 0
            && !$rev_tokens
            && ($user->roles & Contact::ROLE_PC) !== 0) {
            $viewer = Contact::$main_user;
            if ($viewer !== $user
                && (!$viewer || $viewer->privChair || $viewer->contactXid === $prow->managerContactId)
                && $user === $prow->conf->pc_member_by_id($user->contactId)) {
                $user_set = $prow->conf->pc_members();
            }
        }

        // clear contact info
        $row_set = $prow->_row_set;
        foreach ($row_set as $pr) {
            foreach ($user_set as $u) {
                $pr->_set_empty_contact_info($u);
            }
        }

        // contact database
        $prwhere = "contactId?a";
        $qv = [];
        if ($rev_tokens) {
            $prwhere = "({$prwhere} or reviewToken?a)";
            $qv[] = $rev_tokens;
        }
        // two queries is marginally faster than one union query
        $mresult = Dbl::multi_qe($prow->conf->dblink, "select paperId, contactId, conflictType from PaperConflict where paperId?a and contactId?a;
            select paperId, contactId, null, rflags, reviewNeedsSubmit, reviewRound from PaperReview where paperId?a and {$prwhere}",
            $row_set->paper_ids(), array_keys($user_set),
            $row_set->paper_ids(), array_keys($user_set), ...$qv);
        while (($result = $mresult->next())) {
            while (($x = $result->fetch_row())) {
                $pr = $row_set->get((int) $x[0]);
                $ci = $pr->_get_contact_info((int) $x[1]);
                if ($x[2] !== null) {
                    $ci->mark_conflict((int) $x[2]);
                } else {
                    $ci->mark_review_type($pr->conf, (int) $x[3], (int) $x[4], (int) $x[5]);
                }
            }
            $result->close();
        }
    }

    /** @return PaperContactInfo */
    function get_forced_rights() {
        assert(($this->ciflags & self::CIF_ALLOW_ADMINISTER) !== 0);
        if (!$this->forced_rights_link) {
            $ci = $this->forced_rights_link = clone $this;
            $ci->vreviews_array = $ci->viewable_tags = $ci->searchable_tags = null;
            $ci->ciflags = ($ci->ciflags & self::CIFM_SET0)
                | self::CIF_ALLOW_ADMINISTER_FORCED;
        }
        return $this->forced_rights_link;
    }
}

final class PaperConflictInfo {
    /** @var int
     * @readonly */
    public $contactId;
    /** @var int
     * @readonly */
    public $conflictType;
    /** @var ?Contact
     * @readonly */
    public $user;
    /** @var ?int
     * @readonly */
    public $author_index;

    const UNINITIALIZED_INDEX = -400; // see also Author

    /** @param int $uid
     * @param int $ctype */
    function __construct($uid, $ctype) {
        $this->contactId = $uid;
        $this->conflictType = $ctype;
        $this->author_index = self::UNINITIALIZED_INDEX;
    }
}

final class PaperDocumentLink {
    /** @var int
     * @readonly */
    public $linkId;
    /** @var int
     * @readonly */
    public $linkType;
    /** @var int
     * @readonly */
    public $linkIndex;
    /** @var int
     * @readonly */
    public $documentId;

    /** @param int $linkId
     * @param int $linkType
     * @param int $linkIndex
     * @param int $documentId */
    function __construct($linkId, $linkType, $linkIndex, $documentId) {
        $this->linkId = $linkId;
        $this->linkType = $linkType;
        $this->linkIndex = $linkIndex;
        $this->documentId = $documentId;
    }
}

class PaperInfoSet implements IteratorAggregate, Countable {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var list<PaperInfo> */
    private $prows = [];
    /** @var array<int,PaperInfo> */
    private $by_pid = [];
    /** @var int */
    public $loaded_allprefs = 0;
    /** @var bool */
    public $prefetched_conflict_users = false;

    /** @param Conf $conf */
    function __construct($conf) {
        $this->conf = $conf;
    }
    /** @param PaperInfo $row
     * @return PaperInfoSet */
    static function make_singleton($row) {
        $set = new PaperInfoSet($row->conf);
        $set->add_paper($row);
        return $set;
    }
    /** @param Dbl_Result $result
     * @param ?Contact $user
     * @param ?Conf $conf
     * @return PaperInfoSet */
    static function make_result($result, $user, $conf = null) {
        $set = new PaperInfoSet($conf);
        $set->add_result($result, $user);
        return $set;
    }
    function add_paper(PaperInfo $prow) {
        $this->prows[] = $this->by_pid[$prow->paperId] = $prow;
    }
    /** @param Dbl_Result $result
     * @param ?Contact $user */
    function add_result($result, $user) {
        while (PaperInfo::fetch($result, $user, $this->conf, $this)) {
        }
        Dbl::free($result);
    }
    /** @return list<PaperInfo> */
    function as_list() {
        return $this->prows;
    }
    /** @return list<PaperInfo> */
    function all() {
        return $this->prows;
    }
    /** @return int */
    function size() {
        return count($this->prows);
    }
    #[\ReturnTypeWillChange]
    /** @return int */
    function count() {
        return count($this->prows);
    }
    /** @return bool */
    function is_empty() {
        return empty($this->prows);
    }
    /** @param callable(PaperInfo,PaperInfo):int $compare */
    function sort_by($compare) {
        usort($this->prows, $compare);
        $this->by_pid = [];
        foreach ($this->prows as $prow) {
            $this->by_pid[$prow->paperId] = $prow;
        }
    }
    function sort_by_search(PaperSearch $srch) {
        if ($srch->nontrivial_sort()) {
            $pidmap = array_flip($srch->sorted_paper_ids());
            $this->sort_by(function ($a, $b) use ($pidmap) {
                $ai = $pidmap[$a->paperId] ?? PHP_INT_MAX;
                $bi = $pidmap[$b->paperId] ?? PHP_INT_MAX;
                return $ai <=> $bi;
            });
        }
    }
    /** @return list<int> */
    function paper_ids() {
        return array_keys($this->by_pid);
    }
    /** @param int $pid
     * @return ?PaperInfo */
    function paper_by_id($pid) {
        return $this->by_pid[$pid] ?? null;
    }
    /** @param int $pid
     * @return PaperInfo */
    function checked_paper_by_id($pid) {
        $prow = $this->by_pid[$pid] ?? null;
        if (!$prow) {
            throw new Exception("PaperInfoSet::checked_paper_by_id({$pid}) failure");
        }
        return $prow;
    }
    /** @param int $pid
     * @return ?PaperInfo */
    function get($pid) {
        return $this->by_pid[$pid] ?? null;
    }
    /** @param int $pid
     * @return PaperInfo */
    function cget($pid) {
        return $this->checked_paper_by_id($pid);
    }
    /** @param int $pid
     * @return bool */
    function contains($pid) {
        return isset($this->by_pid[$pid]);
    }
    /** @param callable(PaperInfo):bool $func
     * @return PaperInfoSet|Iterable<PaperInfo> */
    function filter($func) {
        $next_set = new PaperInfoSet($this->conf);
        foreach ($this->prows as $prow) {
            if (call_user_func($func, $prow))
                $next_set->add_paper($prow);
        }
        return $next_set;
    }
    /** @param callable(PaperInfo):bool $func */
    function apply_filter($func) {
        $prows = $by_pid = [];
        foreach ($this->prows as $prow) {
            if (call_user_func($func, $prow)) {
                $prows[] = $by_pid[$prow->paperId] = $prow;
            } else {
                $prow->_row_set = PaperInfoSet::make_singleton($prow);
            }
        }
        $this->prows = $prows;
        $this->by_pid = $by_pid;
    }
    /** @param callable(PaperInfo):bool $func
     * @return bool */
    function any($func) {
        foreach ($this->prows as $prow) {
            if (($x = call_user_func($func, $prow)))
                return $x;
        }
        return false;
    }
    #[\ReturnTypeWillChange]
    /** @return Iterator<PaperInfo> */
    function getIterator() {
        return new ArrayIterator($this->prows);
    }
    function ensure_full_reviews() {
        if (!empty($this->prows)) {
            $this->prows[0]->ensure_full_reviews();
        }
    }
    function ensure_primary_documents() {
        if (!empty($this->prows)) {
            $this->prows[0]->ensure_primary_documents();
        }
    }

    function prefetch_conflict_users() {
        if (!$this->prefetched_conflict_users) {
            $this->prefetched_conflict_users = true;
            foreach ($this->prows as $prow) {
                foreach ($prow->conflict_type_list() as $cu) {
                    if ($cu->contactId > 0)
                        $prow->conf->prefetch_user_by_id($cu->contactId);
                }
            }
        }
    }
}

class PaperInfo {
    /** @var Conf
     * @readonly */
    public $conf;

    // Always available, even in "minimal" paper skeletons
    /** @var int
     * @readonly */
    public $paperId;
    /** @var int
     * @readonly */
    public $paperXid;      // unique among all PaperInfos
    /** @var int */
    public $timeSubmitted = 0;
    /** @var int */
    public $timeWithdrawn = 0;
    /** @var int */
    public $outcome = 0;
    /** @var -2|-1|0|1 */
    public $outcome_sign = 0;
    /** @var int */
    public $leadContactId = 0;
    /** @var int */
    public $managerContactId = 0;
    /** @var ?bool */
    public $blind;         // always available if submission blindness is optional

    // Often available
    /** @var string */
    public $title;
    /** @var ?string */
    public $authorInformation;
    /** @var ?string */
    public $abstract;
    /** @var ?string */
    public $collaborators;
    /** @var ?int */
    public $timeModified;
    /** @var ?int */
    public $timeFinalSubmitted;
    /** @var ?string */
    public $withdrawReason;
    /** @var ?int */
    public $shepherdContactId;
    /** @var ?int */
    public $paperFormat;
    /** @var ?string */
    public $capVersion;
    /** @var ?string */
    public $dataOverflow;
    /** @var ?array<string,mixed> */
    public $_dataOverflow;

    /** @var ?int */
    public $paperStorageId;
    /** @var ?int */
    public $finalPaperStorageId;
    /** @var ?string */
    public $pdfFormatStatus;
    /** @var ?int */
    private $size;
    /** @var ?string */
    public $mimetype;
    /** @var ?string */
    public $timestamp;
    /** @var ?string */
    public $sha1;

    // Obtained by joins from other tables
    /** @var ?string */
    public $paperTags;
    /** @var ?string */
    public $optionIds;
    /** @var ?string */
    public $topicIds;
    /** @var ?string */
    public $allConflictType;
    /** @var ?string */
    public $allReviewerPreference;

    /** @var ?string */
    public $myReviewPermissions;
    /** @var ?string */
    public $myReviewerPreference;
    /** @var ?string */
    public $myReviewerExpertise;
    /** @var ?string */
    public $conflictType;
    /** @var ?int */
    public $watch;
    /** @var ?int */
    private $myContactXid;

    /** @var ?string */
    public $reviewSignatures;
    /** @var ?string */
    public $reviewWordCountSignature;
    /** @var ?string */
    public $s01Signature;
    /** @var ?string */
    public $s02Signature;
    /** @var ?string */
    public $s03Signature;
    /** @var ?string */
    public $s04Signature;
    /** @var ?string */
    public $s05Signature;
    /** @var ?string */
    public $s06Signature;
    /** @var ?string */
    public $s07Signature;
    /** @var ?string */
    public $s08Signature;
    /** @var ?string */
    public $s09Signature;
    /** @var ?string */
    public $s10Signature;
    /** @var ?string */
    public $s11Signature;

    /** @var ?string */
    public $commentSkeletonInfo;

    // Not in database
    /** @var PaperInfoSet */
    public $_row_set;
    /** @var array<int,?PaperContactInfo> */
    private $_contact_info = [];
    /** @var int */
    private $_rights_version = 0;
    /** @var ?Contact */
    private $_author_user;
    /** @var int */
    private $_flags = 0;
    /** @var ?SubmissionRound */
    private $_submission_round;
    /** @var ?array<string,string> */
    private $_deaccents;
    /** @var ?list<Author> */
    private $_author_array;
    /** @var ?list<PaperConflictInfo> */
    private $_ctype_list;
    /** @var ?list<AuthorMatcher> */
    private $_collaborator_array;
    /** @var ?PaperInfoPotentialConflictList */
    private $_potconf;
    /** @var ?array<int,PaperReviewPreference> */
    private $_prefs_array;
    /** @var ?int */
    private $_pref1_cid;
    /** @var ?PaperReviewPreference */
    private $_pref1;
    /** @var ?int */
    private $_desirability;
    /** @var ?list<int> */
    private $_topic_array;
    /** @var ?array<int,int> */
    private $_topic_interest_score_array;
    /** @var ?array<int,list<int>> */
    private $_option_values;
    /** @var ?array<int,list<?string>> */
    private $_option_data;
    /** @var array<int,?PaperValue> */
    private $_option_array = [];
    /** @var ?array<int,PaperValue> */
    private $_base_option_array;
    /** @var ?DocumentInfo */
    private $_primary_document;
    /** @var array<int,DocumentInfo> */
    private $_document_array;
    /** @var ?list<PaperDocumentLink> */
    private $_doclinks;
    /** @var ?DecisionInfo */
    private $_decision;
    /** @var ?array<int,ReviewInfo> */
    private $_review_array;
    /** @var int */
    private $_review_array_version = 0;
    /** @var ?list<?bool> */
    private $_reviews_have;
    /** @var ?list<ReviewInfo> */
    private $_full_review;
    /** @var ?string */
    private $_full_review_key;
    /** @var ?array<int,CommentInfo> */
    private $_comment_array;
    /** @var ?list<CommentInfo> */
    private $_comment_skeleton_array;
    /** @var ?list<ReviewRequestInfo> */
    private $_request_array;
    /** @var ?list<ReviewRefusalInfo> */
    private $_refusal_array;
    /** @var ?array<int,int> */
    private $_watch_array;
    /** @var null|false|TokenInfo */
    public $_author_view_token;
    /** @var ?int */
    public $_search_group;
    /** @var ?array<string,mixed> */
    public $_old_prop;

    const PID_MAX = 2000000000;

    const REVIEW_HAS_FULL = 0x01;
    const REVIEW_HAS_NAMES = 0x02;
    const REVIEW_HAS_LASTLOGIN = 0x04;
    const REVIEW_HAS_WORDCOUNT = 0x08;
    const REVIEW_FLAGS = 0xFF;
    const MARK_INACTIVE_PAUSE_1 = 0x100;
    const MARK_INACTIVE_PAUSE_2 = 0x200;
    const MARK_INACTIVE_PAUSE = 0x300;
    const ALLOW_ABSENT = 0x400;
    const IS_NEW = 0x800;
    const IS_UPDATING = 0x1000;
    const UPDATING_WANT_SUBMITTED = 0x2000;
    const HAS_PHASE = 0x8000;
    const PHASE_MASK = 0xF0000;
    const PHASE_SHIFT = 16;
    const PHASE_REVIEW = 0; // default
    const PHASE_FINAL = 1;  // accepted, collecting final versions, author can see decision
                            // (NB final versions might not be open at the moment)

    const SUBMITTED_AT_FOR_WITHDRAWN = 1000000000;
    static private $next_uid = 0;

    /** @param Conf $conf
     * @param ?PaperInfoSet $paperset */
    private function __construct(Conf $conf, $paperset = null) {
        $this->conf = $conf;
        $this->paperXid = ++self::$next_uid;
        $this->_row_set = $paperset ?? new PaperInfoSet($conf);
    }

    /** @suppress PhanAccessReadOnlyProperty */
    private function incorporate() {
        $this->paperId = (int) $this->paperId;
        $this->timeSubmitted = (int) $this->timeSubmitted;
        $this->timeWithdrawn = (int) $this->timeWithdrawn;
        $this->outcome = (int) $this->outcome;
        $this->leadContactId = (int) $this->leadContactId;
        $this->managerContactId = (int) $this->managerContactId;
        if (isset($this->blind)) {
            $this->blind = (bool) $this->blind;
        }
        if (isset($this->timeModified)) {
            $this->timeModified = (int) $this->timeModified;
        }
        if (isset($this->timeFinalSubmitted)) {
            $this->timeFinalSubmitted = (int) $this->timeFinalSubmitted;
        }
        if (isset($this->shepherdContactId)) {
            $this->shepherdContactId = (int) $this->shepherdContactId;
        }
        if (isset($this->paperFormat)) {
            $this->paperFormat = (int) $this->paperFormat;
        }
        if (isset($this->paperStorageId)) {
            $this->paperStorageId = (int) $this->paperStorageId;
        }
        if (isset($this->finalPaperStorageId)) {
            $this->finalPaperStorageId = (int) $this->finalPaperStorageId;
        }
        if (isset($this->size)) {
            $this->size = (int) $this->size;
        }
        if (isset($this->dataOverflow) && is_string($this->dataOverflow)) {
            $this->_dataOverflow = json_decode($this->dataOverflow, true);
            if ($this->_dataOverflow === null) {
                error_log("{$this->conf->dbname}: #{$this->paperId}: bad dataOverflow");
            }
        }
    }

    /** @suppress PhanAccessReadOnlyProperty */
    private function set_outcome_sign() {
        if ($this->outcome === 0) {
            $this->outcome_sign = 0;
        } else if ($this->outcome > 0) {
            $this->outcome_sign = 1;
        } else if ($this->conf->has_complex_decision()) {
            $this->outcome_sign = $this->decision()->sign;
        } else {
            $this->outcome_sign = -1;
        }
    }

    /** @param Dbl_Result $result
     * @param ?Contact $user
     * @param ?PaperInfoSet $paperset
     * @return ?PaperInfo */
    static function fetch($result, $user, $conf = null, $paperset = null) {
        if (($prow = $result->fetch_object("PaperInfo", [$conf ?? $user->conf, $paperset]))) {
            $prow->incorporate();
            $prow->set_outcome_sign();
            $prow->_row_set->add_paper($prow);
            if ($user !== null) {
                $prow->myContactXid = $user->contactXid;
                $prow->_rights_version = Contact::$rights_version;
            }
        }
        return $prow;
    }

    /** @param int $paperId
     * @return PaperInfo
     * @suppress PhanAccessReadOnlyProperty */
    static function make_placeholder(Conf $conf, $paperId) {
        $prow = new PaperInfo($conf);
        $prow->paperId = $paperId;
        $prow->title = "";
        $prow->authorInformation = "";
        $prow->_row_set->add_paper($prow);
        return $prow;
    }

    /** @param ?string $stag
     * @return PaperInfo
     * @suppress PhanAccessReadOnlyProperty */
    static function make_new(Contact $user, $stag) {
        $prow = new PaperInfo($user->conf);
        $prow->paperId = 0;
        $prow->timeModified = 0;
        $prow->abstract = $prow->title = $prow->collaborators =
            $prow->authorInformation = $prow->paperTags = $prow->optionIds =
            $prow->topicIds = "";
        $prow->leadContactId = $prow->shepherdContactId = 0;
        $prow->blind = true;
        if ($user->contactId > 0) {
            $prow->allConflictType = $user->contactId . " " . CONFLICT_CONTACTAUTHOR;
        } else {
            $prow->allConflictType = "";
        }
        $prow->_author_user = $user;
        $prow->_review_array = [];
        $prow->_comment_skeleton_array = $prow->_comment_array = [];
        $prow->_row_set->add_paper($prow);
        if ($stag
            && ($sr = $user->conf->submission_round_by_tag($stag))
            && !$sr->unnamed) {
            $prow->paperTags = " {$sr->tag}#0";
            $prow->_submission_round = $sr;
        }
        $prow->set_is_new(true);
        $prow->check_rights_version(); // ensure _contact_info[author_user] exists
        return $prow;
    }

    /** @param int $rtype
     * @param string $tags
     * @return PaperInfo
     * @suppress PhanAccessReadOnlyProperty */
    static function make_permissive_reviewer(Contact $user, $rtype, $tags) {
        $prow = new PaperInfo($user->conf);
        $prow->paperId = 1;
        $prow->blind = false;
        $prow->timeSubmitted = 1;
        $prow->managerContactId = 0;
        $prow->paperTags = $tags;
        $prow->outcome = 1;
        $prow->outcome_sign = 1;
        $prow->conflictType = "0";
        $rf = ReviewInfo::RF_LIVE | (1 << $rtype) | ReviewInfo::RF_ACKNOWLEDGED | ReviewInfo::RF_DRAFTED | ReviewInfo::RF_SUBMITTED;
        $prow->myReviewPermissions = "{$rf} 0 0";
        $prow->_row_set->add_paper($prow);
        $prow->myContactXid = $user->contactXid;
        $prow->_rights_version = Contact::$rights_version;
        return $prow;
    }

    /** @return ?PaperInfo */
    function reload() {
        return $this->conf->paper_by_id($this->paperId);
    }


    /** @param string $prefix
     * @return string */
    static function my_review_permissions_sql($prefix = "") {
        return "group_concat({$prefix}rflags, ' ', {$prefix}reviewNeedsSubmit, ' ', {$prefix}reviewRound)";
    }

    /** @return FailureReason */
    function failure_reason($rest = null) {
        return (new FailureReason($this->conf, $rest))->set_prow($this);
    }

    /** @param array $param
     * @param int $flags
     * @return string */
    function hoturl($param = [], $flags = 0) {
        $param["p"] = $this->paperId;
        return $this->conf->hoturl("paper", $param, $flags);
    }

    /** @param array $param
     * @param int $flags
     * @return string */
    function reviewurl($param = [], $flags = 0) {
        $param["p"] = $this->paperId;
        return $this->conf->hoturl("review", $param, $flags);
    }


    /** @param int|Contact $contact
     * @return int */
    static private function contact_to_cid($contact) {
        assert($contact !== null);
        return is_object($contact) ? $contact->contactId : $contact;
    }

    private function check_rights_version() {
        if ($this->_rights_version === Contact::$rights_version) {
            return;
        }
        if ($this->_rights_version) {
            $this->_flags &= ~(self::REVIEW_FLAGS | self::HAS_PHASE);
            $this->_contact_info = [];
            $this->reviewSignatures = $this->_review_array = $this->_reviews_have = null;
            $this->allConflictType = $this->_ctype_list = null;
            $this->_potconf = null;
            $this->myContactXid = null;
            ++$this->_review_array_version;
        }
        $this->_rights_version = Contact::$rights_version;
        if ($this->_author_user) {
            // author_user should always be in _contact_info
            $this->contact_info($this->_author_user);
        }
    }

    function update_rights() {
        $this->_rights_version = -1;
        Contact::update_rights();
    }

    /** @param int $cid
     * @return PaperContactInfo */
    function _get_contact_info($cid) {
        $ci =& $this->_contact_info[$cid];
        if ($ci === null) {
            $ci = new PaperContactInfo($this->paperId, $cid);
        }
        return $ci;
    }

    /** @param Contact $user */
    function _set_empty_contact_info($user) {
        if (PaperContactInfo::is_nonempty($this, $user)) {
            $ci = PaperContactInfo::make_user($this, $user);
        } else {
            $ci = null;
        }
        $this->_contact_info[$user->contactXid] = $ci;
    }

    /** @return ?PaperContactInfo */
    function optional_contact_info(Contact $user) {
        $this->check_rights_version();
        $cid = $user->contactXid;
        if (array_key_exists($cid, $this->_contact_info)) {
            return $this->_contact_info[$cid];
        }
        if ($this->myContactXid === $cid) {
            $ci = PaperContactInfo::make_my($this, $user);
            $this->_contact_info[$cid] = $ci;
        } else if ($this->_review_array
                   || $this->reviewSignatures !== null) {
            $ci = PaperContactInfo::make_user($this, $user);
            if ($cid > 0) {
                $ci->mark_conflict($this->conflict_type($cid));
            }
            foreach ($this->reviews_by_user($cid, $user->review_tokens()) as $rrow) {
                $ci->mark_review($rrow);
            }
            $this->_contact_info[$cid] = $ci;
        } else {
            PaperContactInfo::load_into($this, $user);
            $ci = $this->_contact_info[$cid];
        }
        if ($user === $this->_author_user) {
            $ci = $ci ?? $this->_get_contact_info($cid);
            $ci->mark_conflict(CONFLICT_CONTACTAUTHOR);
        }
        return $ci;
    }

    /** @return PaperContactInfo */
    function contact_info(Contact $user) {
        $ci = $this->optional_contact_info($user);
        if ($ci === null) {
            $this->_contact_info[$user->contactXid] = $ci = new PaperContactInfo($this->paperId, $user->contactXid);
        }
        return $ci;
    }

    /** @return Contact */
    function author_user() {
        // Return a fake user that looks like a contact for this paper, but
        // has no special privileges
        if (!$this->_author_user) {
            $this->_author_user = Contact::make($this->conf);
            $this->contact_info($this->_author_user); // contact_info must exist
        }
        return $this->_author_user;
    }


    /** @return bool */
    function allow_absent() {
        return ($this->_flags & self::ALLOW_ABSENT) !== 0;
    }

    /** @param bool $allow_absent */
    function set_allow_absent($allow_absent) {
        assert(!$allow_absent || $this->paperId === 0);
        $this->_flags &= ~self::ALLOW_ABSENT;
        if ($allow_absent) {
            $this->_flags |= self::ALLOW_ABSENT;
        }
    }

    /** @return bool */
    function is_new() {
        return ($this->_flags & self::IS_NEW) !== 0;
    }

    /** @return bool */
    function is_updating() {
        return ($this->_flags & self::IS_UPDATING) !== 0;
    }

    /** @param bool $want_submitted
     * @return $this */
    function set_updating($want_submitted) {
        assert(($this->_flags & self::IS_UPDATING) === 0);
        $this->_flags |= self::IS_UPDATING
            | ($want_submitted ? self::UPDATING_WANT_SUBMITTED : 0);
        return $this;
    }

    /** @return $this */
    function clear_updating() {
        $this->_flags &= ~(self::IS_UPDATING | self::UPDATING_WANT_SUBMITTED);
        return $this;
    }

    /** @param bool $is_new
     * @return $this */
    function set_is_new($is_new) {
        $this->_flags &= ~self::IS_NEW;
        if ($is_new) {
            $this->_flags |= self::IS_NEW;
        }
        return $this;
    }

    /** @return bool */
    function want_submitted() {
        if (($this->_flags & self::IS_UPDATING) !== 0) {
            return ($this->_flags & self::UPDATING_WANT_SUBMITTED) !== 0;
        }
        return $this->timeSubmitted > 0;
    }

    /** @return 0|1 */
    function phase() {
        if ($this->outcome_sign <= 0) {
            return self::PHASE_REVIEW;
        }
        $this->check_rights_version();
        if (($this->_flags & self::HAS_PHASE) === 0) {
            if ($this->timeSubmitted > 0
                && $this->conf->allow_final_versions()
                && $this->can_author_view_decision()) {
                $phase = self::PHASE_FINAL;
            } else {
                $phase = self::PHASE_REVIEW;
            }
            $this->_flags = ($this->_flags & ~self::PHASE_MASK) | self::HAS_PHASE | ($phase << self::PHASE_SHIFT);
        }
        return ($this->_flags & self::PHASE_MASK) >> self::PHASE_SHIFT;
    }

    /** @return 0|1 */
    function visible_phase(?Contact $user = null) {
        $p = $this->phase();
        if ($p === self::PHASE_FINAL
            && $user
            && !$user->can_view_decision($this)) {
            $p = self::PHASE_REVIEW;
        }
        return $p;
    }


    /** @param string $prop
     * @return mixed */
    function prop($prop) {
        return $this->$prop;
    }

    /** @param string $prop
     * @return mixed */
    function prop_with_overflow($prop) {
        return $this->_dataOverflow[$prop] ?? $this->$prop;
    }

    /** @param string $prop
     * @param mixed $v */
    function set_prop($prop, $v) {
        $this->_old_prop = $this->_old_prop ?? [];
        if (!array_key_exists($prop, $this->_old_prop)) {
            $this->_old_prop[$prop] = $this->$prop;
        }
        $this->$prop = $v;
        // clear caches, sometimes conservatively
        $this->_deaccents = null;
        if ($prop === "authorInformation") {
            $this->_author_array = $this->_ctype_list = null;
        } else if ($prop === "collaborators") {
            $this->_collaborator_array = null;
        } else if ($prop === "topicIds") {
            $this->_topic_array = $this->_topic_interest_score_array = null;
        } else if ($prop === "allConflictType") {
            $this->_ctype_list = null;
        }
    }

    /** @param string $prop
     * @param mixed $v */
    function set_overflow_prop($prop, $v) {
        if ($v === null && $this->dataOverflow === null) {
            return;
        }
        $this->_old_prop = $this->_old_prop ?? [];
        if (!array_key_exists("dataOverflow", $this->_old_prop)) {
            $this->_old_prop["dataOverflow"] = $this->dataOverflow;
        }
        if ($v === null) {
            unset($this->_dataOverflow[$prop]);
        } else {
            $this->_dataOverflow[$prop] = $v;
        }
        if (empty($this->_dataOverflow)) {
            $this->dataOverflow = null;
        } else {
            $this->dataOverflow = json_encode_db($this->_dataOverflow);
        }
    }

    /** @param string $prop
     * @return mixed */
    function base_prop($prop) {
        if ($this->_old_prop !== null
            && array_key_exists($prop, $this->_old_prop)) {
            return $this->_old_prop[$prop];
        }
        return $this->$prop;
    }

    /** @param string $prop
     * @return mixed */
    function base_prop_with_overflow($prop) {
        if ($this->_old_prop !== null
            && array_key_exists("dataOverflow", $this->_old_prop)) {
            $old_dataOverflow = json_decode($this->_old_prop["dataOverflow"] ?? "null", true);
            if (isset($old_dataOverflow[$prop])) {
                return $old_dataOverflow[$prop];
            }
        } else if (isset($this->_dataOverflow[$prop])) {
            return $this->_dataOverflow[$prop];
        }
        return $this->base_prop($prop);
    }

    /** @param ?string $prop
     * @return bool */
    function prop_changed($prop = null) {
        return !empty($this->_old_prop)
            && (!$prop || array_key_exists($prop, $this->_old_prop));
    }

    /** @return bool */
    function user_prop_changed() {
        foreach ($this->_old_prop ?? [] as $prop => $x) {
            if (!in_array($prop, ["outcome", "leadContactId", "shepherdContactId", "managerContactId", "pdfFormatStatus"])) {
                return true;
            }
        }
        return false;
    }

    function commit_prop() {
        $this->_old_prop = null;
        $this->_flags &= ~self::HAS_PHASE;
    }

    function abort_prop() {
        foreach ($this->_old_prop ?? [] as $prop => $v) {
            $this->set_prop($prop, $v);
        }
        if (array_key_exists("dataOverflow", $this->_old_prop ?? [])) {
            $this->_dataOverflow = json_decode($this->_old_prop["dataOverflow"] ?? "null", true);
        }
        $this->_old_prop = null;
    }


    /** @return SubmissionRound */
    function submission_round() {
        if (!$this->_submission_round) {
            foreach ($this->conf->submission_round_list() as $sr) {
                if ($sr->unnamed || $this->has_tag($sr->tag)) {
                    $this->_submission_round = $sr;
                    break;
                }
            }
        }
        return $this->_submission_round;
    }


    /** @return int */
    function format_of($text, $check_simple = false) {
        return $this->conf->check_format($this->paperFormat, $check_simple ? $text : null);
    }

    /** @return string */
    function title() {
        return $this->title;
    }

    /** @return int */
    function title_format() {
        return $this->format_of($this->title, true);
    }

    /** @return string */
    function abstract() {
        return $this->_dataOverflow["abstract"] ?? $this->abstract ?? "";
    }

    /** @return int */
    function abstract_format() {
        return $this->format_of($this->abstract());
    }

    function edit_format() {
        return $this->conf->format_info($this->paperFormat);
    }


    /** @return string */
    function authorInformation() {
        // convenience method because we need abstract(), collaborators()
        // for overflow
        return $this->authorInformation;
    }

    /** @param string $authorInformation
     * @return list<Author> */
    static function parse_author_list($authorInformation) {
        $au = [];
        $n = 1;
        foreach (explode("\n", $authorInformation) as $line) {
            if ($line !== "") {
                $au[] = Author::make_tabbed($line, $n);
                ++$n;
            }
        }
        return $au;
    }

    /** @return list<Author> */
    function author_list() {
        if (!isset($this->_author_array)) {
            $this->_author_array = self::parse_author_list($this->authorInformation);
        }
        return $this->_author_array;
    }

    /** @param string $email
     * @return ?Author */
    function author_by_email($email) {
        return Author::find_by_email($email, $this->author_list());
    }

    /** @param int $index
     * @return ?Author */
    function author_by_index($index) {
        return ($this->author_list())[$index - 1] ?? null;
    }


    /** @param bool $review_complete
     * @return -1|0|1 */
    function blindness_state($review_complete) {
        $bs = $this->conf->submission_blindness();
        if ($bs === Conf::BLIND_NEVER
            || ($bs === Conf::BLIND_OPTIONAL && !$this->blind)
            || ($bs === Conf::BLIND_UNTILREVIEW && $review_complete)) {
            return -1; /* not blind to any reviewer */
        } else if ($this->outcome_sign > 0
                   && !$this->conf->setting("seedec_hideau")
                   && $this->can_author_view_decision()) {
            return 0;  /* not blind to reviewers who can see decision */
        } else {
            return 1;  /* blind to any reviewer */
        }
    }


    private function load_conflict_types() {
        // load conflicts from database
        if ($this->allConflictType === null) {
            foreach ($this->_row_set as $prow) {
                $prow->allConflictType = $prow->allConflictType ?? "";
            }
            $result = $this->conf->qe("select paperId, group_concat(contactId, ' ', conflictType) from PaperConflict force index (paperId) where paperId?a group by paperId", $this->_row_set->paper_ids());
            while (($row = $result->fetch_row())) {
                $this->_row_set->get((int) $row[0])->allConflictType = $row[1];
            }
            Dbl::free($result);
        }
        // parse conflicts into arrays
        $this->_ctype_list = [];
        if ($this->allConflictType !== "") {
            foreach (explode(",", $this->allConflictType) as $x) {
                list($cid, $ctype) = explode(" ", $x);
                $this->_ctype_list[] = new PaperConflictInfo((int) $cid, (int) $ctype);
            }
        }
    }

    /** @return list<PaperConflictInfo> */
    function conflict_type_list() {
        if ($this->_ctype_list === null) {
            $this->load_conflict_types();
        }
        return $this->_ctype_list;
    }

    /** @return list<PaperConflictInfo>
     * @suppress PhanAccessReadOnlyProperty */
    function conflict_list() {
        if ($this->_ctype_list === null) {
            $this->load_conflict_types();
        }
        if (!empty($this->_ctype_list)
            && $this->_ctype_list[0]->author_index === PaperConflictInfo::UNINITIALIZED_INDEX) {
            $this->_row_set->prefetch_conflict_users();
            foreach ($this->_ctype_list as $cu) {
                $u = null;
                if ($cu->contactId > 0) {
                    $cu->user = $this->conf->user_by_id($cu->contactId, USER_SLICE);
                } else if ($this->_author_user !== null
                           && $this->_author_user->contactId === $cu->contactId) {
                    $cu->user = $this->_author_user;
                }
                $cu->author_index = null;
                if ($cu->user
                    && $cu->user->has_email()
                    && ($au = $this->author_by_email($cu->user->email)) !== null) {
                    $cu->author_index = $au->author_index;
                }
            }
        }
        return $this->_ctype_list;
    }


    /** @return associative-array<int,int> */
    function conflict_types() {
        $ct = [];
        foreach ($this->conflict_type_list() as $cu) {
            $ct[$cu->contactId] = $cu->conflictType;
        }
        return $ct;
    }

    /** @param Contact|int $c
     * @return int */
    function conflict_type($c) {
        $this->check_rights_version();
        $cid = is_object($c) ? $c->contactXid : $c;
        if (array_key_exists($cid, $this->_contact_info)) {
            $ci = $this->_contact_info[$cid];
            return $ci ? $ci->conflictType : null;
        }
        foreach ($this->conflict_type_list() as $cu) {
            if ($cu->contactId === $cid)
                return $cu->conflictType;
        }
        return 0;
    }

    /** @param string $email
     * @return int */
    function conflict_type_by_email($email) {
        foreach ($this->conflict_list() as $cu) {
            if ($cu->user
                && strcasecmp($cu->user->email, $email) === 0)
                return $cu->conflictType;
        }
        return 0;
    }

    function invalidate_conflicts() {
        // XXX this does not invalidate conflict types that are loaded
        // through the _contact_info subsystem
        $this->allConflictType = $this->_ctype_list = null;
    }


    /** @param Contact|int $contact
     * @return bool */
    function has_conflict($contact) {
        return $this->conflict_type($contact) > CONFLICT_MAXUNCONFLICTED;
    }

    /** @param Contact|int $contact
     * @return bool */
    function has_author($contact) {
        return $this->conflict_type($contact) >= CONFLICT_AUTHOR;
    }

    /** @return bool */
    function has_author_view(Contact $user) {
        return $user->view_conflict_type($this) >= CONFLICT_AUTHOR;
    }


    /** @return string */
    function collaborators() {
        return $this->_dataOverflow["collaborators"] ?? $this->collaborators ?? "";
    }

    /** @return bool */
    function has_nonempty_collaborators() {
        $collab = $this->collaborators();
        return $collab !== "" && strcasecmp($collab, "none") !== 0;
    }

    /** @return string */
    function full_collaborators() {
        $a = [];
        if (($s = $this->collaborators()) !== "") {
            $a[] = $s;
        }
        foreach ($this->conflict_list() as $cu) {
            if ($cu->conflictType >= CONFLICT_AUTHOR
                && $cu->user
                && ($s = $cu->user->collaborators()) !== "") {
                $a[] = $s;
            }
        }
        return join("\n", $a);
    }

    /** @return Generator<AuthorMatcher> */
    function collaborator_list() {
        if ($this->_collaborator_array === null) {
            $this->_collaborator_array = [];
            foreach (AuthorMatcher::make_collaborator_generator($this->collaborators()) as $m) {
                $m->contactId = 0;
                $m->author_index = Author::COLLABORATORS_INDEX;
                $this->_collaborator_array[] = $m;
            }
        }
        yield from $this->_collaborator_array;
        foreach ($this->conflict_list() as $cu) {
            if ($cu->conflictType < CONFLICT_AUTHOR
                || !$cu->user) {
                continue;
            }
            foreach ($cu->user->aucollab_matchers() as $m) {
                if ($m->is_nonauthor()) {
                    $m = $m->copy();
                    $m->contactId = $cu->contactId;
                    $m->author_index = $cu->author_index;
                    yield $m;
                }
            }
        }
    }

    /** @return PaperInfoPotentialConflictList */
    private function _potential_conflict(Contact $user) {
        $this->check_rights_version();
        if ($this->_potconf && $this->_potconf->user() === $user) {
            return $this->_potconf;
        }
        $auproblems = 0;
        $pcs = [];
        if ($this->field_match_pregexes($user->aucollab_general_pregexes(), "authorInformation")) {
            foreach ($this->author_list() as $au) {
                foreach ($user->aucollab_matchers() as $userm) {
                    if (($why = $userm->test($au, $userm->is_nonauthor()))) {
                        $auproblems |= $why;
                        $pcs[] = new PaperInfoPotentialConflict($userm, $au, $why);
                    }
                }
            }
        }
        $userm = $user->full_matcher();
        $collab = $this->full_collaborators();
        if (Text::match_pregexes($userm->general_pregexes(), $collab, UnicodeHelper::deaccent($collab))) {
            foreach ($this->collaborator_list() as $co) {
                if (($co->lastName !== ""
                     || ($auproblems & AuthorMatcher::MATCH_AFFILIATION) === 0)
                    && ($why = $userm->test($co, true))) {
                    $pcs[] = new PaperInfoPotentialConflict($userm, $co, $why);
                }
            }
        }
        $this->_potconf = new PaperInfoPotentialConflictList($user, $pcs);
        return $this->_potconf;
    }

    /** @return bool */
    function potential_conflict(Contact $user) {
        return !$this->_potential_conflict($user)->is_empty();
    }

    /** @return ?PaperInfoPotentialConflictList */
    function potential_conflict_list(Contact $user) {
        $pcl = $this->_potential_conflict($user);
        return $pcl->is_empty() ? null : $pcl;
    }


    /** @return int */
    function submitted_at() {
        if ($this->timeSubmitted > 0) {
            return $this->timeSubmitted;
        } else if ($this->timeWithdrawn > 0) {
            if ($this->timeSubmitted === -100) {
                return self::SUBMITTED_AT_FOR_WITHDRAWN;
            } else if ($this->timeSubmitted < -100) {
                return -$this->timeSubmitted;
            }
        }
        return 0;
    }

    /** @return list<Contact> */
    function administrators() {
        if ($this->managerContactId > 0) {
            $u = $this->conf->user_by_id($this->managerContactId, USER_SLICE);
            return $u ? [$u] : [];
        }

        $has_track_admin = false;
        if ($this->conf->check_track_admin_sensitivity()) {
            foreach ($this->conf->track_tags() as $ttag) {
                if ($this->conf->track_permission($ttag, Track::ADMIN)
                    && $this->has_tag($ttag)) {
                    $has_track_admin = true;
                    break;
                }
            }
        }

        $as = $cas = [];
        foreach ($this->conf->pc_members() as $u) {
            if (($has_track_admin || $u->privChair)
                && $u->is_primary_administrator($this)) {
                if ($u->can_administer($this)) {
                    $as[] = $u;
                } else {
                    $cas[] = $u;
                }
            }
        }
        return empty($as) ? $cas : $as;
    }

    /** @param ?Contact $viewer
     * @param int $cid
     * @return ?string */
    function unparse_pseudonym($viewer, $cid) {
        if ($this->has_author($cid)) {
            return "Author";
        } else if ($cid > 0 && $this->managerContactId === $cid) {
            return "Administrator";
        } else if ($cid > 0 && $this->shepherdContactId === $cid) {
            return "Shepherd";
        }
        $rrow = $this->review_by_user($cid);
        if ($rrow
            && $rrow->reviewOrdinal
            && (!$viewer || $viewer->can_view_review_assignment($this, $rrow))) {
            return "Reviewer " . unparse_latin_ordinal($rrow->reviewOrdinal);
        } else if (($p = $this->conf->pc_member_by_id($cid))
                   && $p->allow_administer($this)) {
            return "Administrator";
        } else if ($rrow) {
            return "Reviewer";
        } else {
            return null;
        }
    }


    /** @param 'title'|'abstract'|'authorInformation'|'collaborators' $field
     * @return ?string */
    private function deaccented_field($field) {
        $this->_deaccents = $this->_deaccents ?? [];
        if (!array_key_exists($field, $this->_deaccents)) {
            $str = $this->{$field}();
            if ($str !== "" && !is_usascii($str)) {
                $this->_deaccents[$field] = UnicodeHelper::deaccent($str);
            } else {
                $this->_deaccents[$field] = null;
            }
        }
        return $this->_deaccents[$field];
    }

    /** @param TextPregexes $reg
     * @param 'title'|'abstract'|'authorInformation'|'collaborators' $field
     * @return bool */
    function field_match_pregexes($reg, $field) {
        return Text::match_pregexes($reg, $this->{$field}(), $this->deaccented_field($field));
    }


    /** @return bool */
    function can_author_view_submitted_review() {
        $ausr = $this->conf->_au_seerev;
        return ($ausr && $ausr->test($this, null))
            || $this->can_author_respond();
    }

    /** @return bool */
    function can_author_respond() {
        if ($this->conf->any_response_open === 2) {
            return true;
        } else if ($this->conf->any_response_open) {
            foreach ($this->conf->response_round_list() as $rrd) {
                if ($rrd->can_author_respond($this, true))
                    return true;
            }
        }
        return false;
    }

    /** @return bool */
    function can_author_view_decision() {
        return $this->outcome !== 0
            && ($this->outcome_sign === -2
                || ($this->conf->_au_seedec
                    && $this->conf->_au_seedec->test($this, null)));
    }

    /** @return 0|1|2 */
    function author_edit_state() {
        if ($this->timeWithdrawn > 0
            || $this->outcome_sign < 0) {
            return 0;
        }
        if ($this->phase() === self::PHASE_FINAL
            && $this->conf->time_edit_final_paper()) {
            return 2;
        }
        $sr = $this->submission_round();
        if (($this->timeSubmitted <= 0 || !$sr->freeze)
            && $sr->time_update(true)) {
            return 1;
        } else {
            return 0;
        }
    }


    /** @param int|Contact $contact
     * @return int */
    function review_type($contact) {
        $this->check_rights_version();
        if (is_object($contact) && $contact->has_capability()) {
            $ci = $this->optional_contact_info($contact);
            return $ci ? $ci->reviewType : 0;
        }
        $cid = self::contact_to_cid($contact);
        if (array_key_exists($cid, $this->_contact_info)) {
            $rrow = $this->_contact_info[$cid];
        } else {
            $rrow = $this->review_by_user($cid);
        }
        return $rrow ? $rrow->reviewType : 0;
    }

    /** @param int|Contact $contact
     * @return bool */
    function has_reviewer($contact) {
        return $this->review_type($contact) > 0;
    }

    /** @param int|Contact $contact
     * @return bool */
    function has_active_reviewer($contact) {
        $ci = $this->optional_contact_info($contact);
        return $ci
            && $ci->reviewType > 0
            && $ci->review_status >= PaperContactInfo::CIRS_UNSUBMITTED;
    }


    function load_tags() {
        $result = $this->conf->qe("select group_concat(' ', tag, '#', tagIndex order by tag separator '') from PaperTag where paperId=? group by paperId", $this->paperId);
        $this->paperTags = "";
        if (($row = $result->fetch_row()) && $row[0] !== null) {
            $this->paperTags = $row[0];
        }
        Dbl::free($result);
    }

    /** @return bool */
    function has_tag($tag) {
        if ($this->paperTags === null) {
            $this->load_tags();
        }
        return $this->paperTags !== ""
            && stripos($this->paperTags, " {$tag}#") !== false;
    }

    /** @return bool */
    function has_any_tag($tags) {
        if ($this->paperTags === null) {
            $this->load_tags();
        }
        if ($this->paperTags !== "") {
            foreach ($tags as $tag) {
                if (stripos($this->paperTags, " {$tag}#") !== false)
                    return true;
            }
        }
        return false;
    }

    /** @return bool */
    function has_viewable_tag($tag, Contact $user) {
        $tags = $this->viewable_tags($user);
        return $tags !== "" && stripos(" " . $tags, " {$tag}#") !== false;
    }

    /** @param string $tag
     * @return ?float */
    function tag_value($tag) {
        if ($this->paperTags === null) {
            $this->load_tags();
        }
        if ($this->paperTags !== ""
            && ($pos = stripos($this->paperTags, " {$tag}#")) !== false) {
            return (float) substr($this->paperTags, $pos + strlen($tag) + 2);
        }
        return null;
    }

    /** @return string */
    function all_tags_text() {
        if ($this->paperTags === null) {
            $this->load_tags();
        }
        return $this->paperTags;
    }

    /** @return string */
    function searchable_tags(Contact $user) {
        if (!$user->isPC || $this->all_tags_text() === "") {
            return "";
        }
        $rights = $user->__rights($this);
        if ($rights->searchable_tags === null) {
            $dt = $this->conf->tags();
            $rights->searchable_tags = $dt->censor(TagMap::CENSOR_SEARCH, $this->paperTags, $user, $this);
        }
        return $rights->searchable_tags;
    }

    /** @return string */
    function sorted_searchable_tags(Contact $user) {
        $tags = $this->searchable_tags($user);
        return $tags === "" ? "" : $this->conf->tags()->sort_string($tags);
    }

    /** @return string */
    function viewable_tags(Contact $user) {
        // see also Contact::can_view_tag()
        if (!$user->isPC || $this->all_tags_text() === "") {
            return "";
        }
        $rights = $user->__rights($this);
        if ($rights->viewable_tags === null) {
            $dt = $this->conf->tags();
            $tags = $dt->censor(TagMap::CENSOR_VIEW, $this->paperTags, $user, $this);
            $rights->viewable_tags = $dt->sort_string($tags);
        }
        return $rights->viewable_tags;
    }

    /** @return string */
    function sorted_viewable_tags(Contact $user) {
        // XXX currently always sorted, shouldn't sort until required
        return $this->viewable_tags($user);
    }

    /** @return string */
    function sorted_editable_tags(Contact $user) {
        $tags = $this->sorted_viewable_tags($user);
        if ($tags !== "") {
            $etags = [];
            foreach (explode(" ", $tags) as $tag) {
                if ($tag !== "" && $user->can_edit_tag($this, Tagger::tv_tag($tag), 0, 1))
                    $etags[] = $tag;
            }
            $tags = join(" ", $etags);
        }
        return $tags;
    }

    /** @param ?string $viewable
     * @return string */
    function decoration_html(Contact $user, $viewable = null, $viewable_override = null) {
        if ($this->all_tags_text() === ""
            || !$this->conf->tags()->has(TagInfo::TFM_DECORATION)) {
            return "";
        }
        $viewable = $viewable ?? $this->sorted_viewable_tags($user);
        if ($viewable_override === null) {
            if ($user->has_overridable_conflict($this)) {
                $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
                $viewable_override = $this->sorted_viewable_tags($user);
                $user->set_overrides($old_overrides);
            } else {
                $viewable_override = "";
            }
        }
        $tagger = new Tagger($user);
        $decor = $tagger->unparse_decoration_html($viewable);
        if ($viewable_override !== "" && $viewable_override !== $viewable) {
            $decor_override = $tagger->unparse_decoration_html($viewable_override);
            if ($decor !== $decor_override) {
                return str_replace('class="tagdecoration"', 'class="tagdecoration fn5"', $decor)
                    . str_replace('class="tagdecoration"', 'class="tagdecoration fx5"', $decor_override);
            }
        }
        return $decor;
    }

    /** @param TagMessageReport $pj */
    private function _add_override_tag_info_json($pj, $viewable, $viewable_override, Contact $user) {
        $pj->tags = Tagger::split($viewable_override);
        $pj->tags_conflicted = Tagger::split($viewable);
        if (($decor = $this->decoration_html($user, $viewable, $viewable_override)) !== "") {
            $pj->tag_decoration_html = $decor;
        }
        $tagmap = $this->conf->tags();
        $pj->color_classes = $tagmap->color_classes($viewable_override);
        if ($pj->color_classes
            && ($color_classes_c = $tagmap->color_classes($viewable)) !== $pj->color_classes) {
            $pj->color_classes_conflicted = $color_classes_c;
        }
    }

    /** @param TagMessageReport $pj */
    function add_tag_info_json($pj, Contact $user) {
        $viewable = $this->sorted_viewable_tags($user);
        $tagger = new Tagger($user);
        $pj->tags_edit_text = $tagger->unparse($this->sorted_editable_tags($user));
        $pj->tags_view_html = $tagger->unparse_link($viewable);
        if ($user->has_overridable_conflict($this) && $this->paperTags !== "") {
            $old_overrides = $user->set_overrides($user->overrides() ^ Contact::OVERRIDE_CONFLICT);
            $viewable2 = $this->sorted_viewable_tags($user);
            $user->set_overrides($old_overrides);
            if ($viewable !== $viewable2) {
                if ($old_overrides & Contact::OVERRIDE_CONFLICT) {
                    $this->_add_override_tag_info_json($pj, $viewable2, $viewable, $user);
                } else {
                    $this->_add_override_tag_info_json($pj, $viewable, $viewable2, $user);
                }
                return;
            }
        }
        $pj->tags = Tagger::split($viewable);
        if (($decor = $this->decoration_html($user, $viewable, "")) !== "") {
            $pj->tag_decoration_html = $decor;
        }
        $pj->color_classes = $this->conf->tags()->color_classes($viewable);
    }


    private function load_topics() {
        foreach ($this->_row_set as $prow) {
            $prow->topicIds = "";
        }
        if ($this->conf->has_topics()) {
            $result = $this->conf->qe("select paperId, group_concat(topicId) from PaperTopic where paperId?a group by paperId", $this->_row_set->paper_ids());
            while ($result && ($row = $result->fetch_row())) {
                $prow = $this->_row_set->get((int) $row[0]);
                $prow->topicIds = $row[1] ?? "";
            }
            Dbl::free($result);
        }
    }

    /** @return bool */
    function has_topics() {
        if ($this->topicIds === null) {
            $this->load_topics();
        }
        return $this->topicIds !== "";
    }

    /** @return list<int> */
    function topic_list() {
        if ($this->_topic_array === null) {
            if ($this->topicIds === null) {
                $this->load_topics();
            }
            $this->_topic_array = [];
            if ($this->topicIds !== "") {
                foreach (explode(",", $this->topicIds) as $t) {
                    $this->_topic_array[] = (int) $t;
                }
                $this->conf->topic_set()->sort($this->_topic_array);
            }
        }
        return $this->_topic_array;
    }

    /** @return array<int,string> */
    function topic_map() {
        $t = [];
        foreach ($this->topic_list() as $tid) {
            if (empty($t)) {
                $tset = $this->conf->topic_set();
            }
            $t[$tid] = $tset[$tid];
        }
        return $t;
    }

    /** @return string */
    function unparse_topics_text() {
        return join("; ", $this->topic_map());
    }

    private static $topic_interest_values = [-0.7071, -0.5, 0, 0.7071, 1];

    /** @param int|Contact $contact
     * @return int */
    function topic_interest_score($contact) {
        if (is_int($contact)) {
            $contact = ($this->conf->pc_members())[$contact] ?? null;
        }
        if ($this->topicIds === "" || !$contact) {
            return 0;
        }
        $this->_topic_interest_score_array = $this->_topic_interest_score_array ?? [];
        if (array_key_exists($contact->contactId, $this->_topic_interest_score_array)) {
            return $this->_topic_interest_score_array[$contact->contactId];
        }
        $score = 0;
        $interests = $contact->topic_interest_map();
        $topics = $this->topic_list();
        foreach ($topics as $t) {
            if (($j = $interests[$t] ?? 0)) {
                if ($j >= -2 && $j <= 2) {
                    $score += self::$topic_interest_values[$j + 2];
                } else if ($j > 2) {
                    $score += sqrt($j / 2);
                } else {
                    $score += -sqrt(-$j / 4);
                }
            }
        }
        // Scale so strong interest in the paper's single topic gets score 10
        $iscore = $score ? (int) ($score / sqrt(count($topics)) * 10 + 0.5) : 0;
        $this->_topic_interest_score_array[$contact->contactId] = $iscore;
        return $iscore;
    }

    function invalidate_topics() {
        $this->topicIds = $this->_topic_array = $this->_topic_interest_score_array = null;
    }


    /** @return list<Contact> */
    function contact_list() {
        $us = [];
        foreach ($this->conflict_list() as $cu) {
            if ($cu->conflictType >= CONFLICT_AUTHOR
                && $cu->user)
                $us[] = $cu->user;
        }
        return $us;
    }

    /** @return PaperInfoLikelyContacts */
    function likely_contacts() {
        $lc = new PaperInfoLikelyContacts;
        $uanames = [];
        foreach ($this->author_list() as $au) {
            $lc->author_list[] = $au;
            $lc->author_cids[] = [];
            $uanames[] = trim(preg_replace('/[-\s.,;:]+/', ' ', UnicodeHelper::deaccent($au->name())));
        }
        foreach ($this->contact_list() as $u) {
            $nm_full = $nm_uaname = $nm_last = $nm_email = $fulli = $uanamei = $lasti = $emaili = 0;
            if ($u->email !== "") {
                foreach ($lc->author_list as $i => $au) {
                    if (strcasecmp($u->email, $au->email) === 0) {
                        ++$nm_email;
                        $emaili = $i;
                    }
                }
            }
            if ($nm_email !== 1 && ($cflt_name = $u->name()) !== "") {
                $cflt_uaname = trim(preg_replace('/[-\s.,;:]+/', ' ', UnicodeHelper::deaccent($cflt_name)));
                foreach ($lc->author_list as $i => $au) {
                    if (strcasecmp($cflt_name, $au->name()) === 0) {
                        ++$nm_full;
                        $fulli = $i;
                    } else if ($cflt_uaname !== ""
                               && strcasecmp($cflt_uaname, $uanames[$i]) === 0) {
                        ++$nm_uaname;
                        $uanamei = $i;
                    } else if ($u->lastName !== ""
                               && strcasecmp($u->lastName, $au->lastName) === 0) {
                        ++$nm_last;
                        $lasti = $i;
                    }
                }
            }
            if ($nm_email === 1) {
                $lc->author_list[$emaili]->contactId = $u->contactId;
                array_unshift($lc->author_cids[$emaili], $u->contactId);
            } else if ($nm_full === 1) {
                $lc->author_cids[$fulli][] = $u->contactId;
            } else if ($nm_uaname === 1) {
                $lc->author_cids[$uanamei][] = $u->contactId;
            } else if ($nm_last === 1) {
                $lc->author_cids[$lasti][] = $u->contactId;
            } else {
                $lc->nonauthor_contacts[] = $u;
            }
        }
        return $lc;
    }


    function load_decision() {
        $this->outcome = $this->conf->fetch_ivalue("select outcome from Paper where paperId=?", $this->paperId);
        $this->_decision = null;
        $this->_flags &= ~self::HAS_PHASE;
        $this->set_outcome_sign();
    }

    /** @return DecisionInfo */
    function decision() {
        if ($this->_decision === null) {
            if ($this->outcome === 0) {
                $this->_decision = $this->conf->unspecified_decision;
            } else {
                $this->_decision = $this->conf->decision_set()->get($this->outcome);
            }
        }
        return $this->_decision;
    }

    /** @return DecisionInfo */
    function viewable_decision(Contact $user) {
        if ($this->outcome === 0 || !$user->can_view_decision($this)) {
            return $this->conf->unspecified_decision;
        } else {
            return $this->decision();
        }
    }

    /** @return array{string,string} */
    function status_class_and_name(Contact $user) {
        if ($this->timeWithdrawn > 0) {
            return ["ps-withdrawn", "Withdrawn"];
        }
        $dec = $this->viewable_decision($user);
        if ($dec->id !== 0) {
            return [$dec->status_class(), $dec->name];
        } else if ($this->timeSubmitted > 0) {
            return ["ps-submitted", "Submitted"];
        }
        if ($this->paperStorageId <= 1) {
            $subopt = $this->conf->option_by_id(DTYPE_SUBMISSION);
            if ($subopt->test_exists($this) && $subopt->test_required($this)) {
                return ["ps-draft", "No submission"];
            }
        }
        return ["ps-draft", "Draft"];
    }


    function load_preferences() {
        $this->allReviewerPreference = null;
        if (count($this->_row_set) <= 10 || ++$this->_row_set->loaded_allprefs >= 10) {
            $row_set = $this->_row_set->filter(function ($prow) {
                return $prow->allReviewerPreference === null;
            });
        } else {
            $row_set = PaperInfoSet::make_singleton($this);
        }
        foreach ($row_set as $prow) {
            $prow->allReviewerPreference = "";
            $prow->_prefs_array = null;
            $prow->myReviewerPreference = $prow->myReviewerExpertise = null;
            $prow->_pref1_cid = $prow->_pref1 = null;
            $prow->_desirability = null;
        }
        $result = $this->conf->qe("select paperId, " . $this->conf->all_reviewer_preference_query() . " from PaperReviewPreference where paperId?a group by paperId", $row_set->paper_ids());
        while ($result && ($row = $result->fetch_row())) {
            $prow = $row_set->get((int) $row[0]);
            $prow->allReviewerPreference = $row[1];
        }
        Dbl::free($result);
    }

    /** @return array<int,PaperReviewPreference> */
    function preferences() {
        if ($this->allReviewerPreference === null) {
            $this->load_preferences();
        }
        if ($this->_prefs_array === null) {
            $x = [];
            if ($this->allReviewerPreference !== "") {
                $p = preg_split('/[ ,]/', $this->allReviewerPreference);
                for ($i = 0; $i + 2 < count($p); $i += 3) {
                    if ($p[$i+1] !== "0" || $p[$i+2] !== ".")
                        $x[(int) $p[$i]] = new PaperReviewPreference((int) $p[$i+1], $p[$i+2] === "." ? null : (int) $p[$i+2]);
                }
            }
            $this->_prefs_array = $x;
        }
        return $this->_prefs_array;
    }

    /** @param int|Contact $contact
     * @return PaperReviewPreference */
    function preference($contact) {
        $cid = is_int($contact) ? $contact : $contact->contactId;
        if ($this->myReviewerPreference !== null
            && $this->myContactXid === $cid) {
            $pf = (int) $this->myReviewerPreference;
            $ex = $this->myReviewerExpertise;
            return new PaperReviewPreference($pf, $ex !== null ? (int) $ex : null);
        }
        if ($this->_pref1_cid === null
            && $this->_prefs_array === null
            && $this->allReviewerPreference === null) {
            foreach ($this->_row_set as $prow) {
                $prow->_pref1_cid = $cid;
                $prow->_pref1 = null;
            }
            $result = $this->conf->qe("select paperId, preference, expertise from PaperReviewPreference where paperId?a and contactId=?", $this->_row_set->paper_ids(), $cid);
            while ($result && ($row = $result->fetch_row())) {
                $prow = $this->_row_set->get((int) $row[0]);
                $prow->_pref1 = new PaperReviewPreference((int) $row[1], $row[2] !== null ? (int) $row[2] : null);
            }
            Dbl::free($result);
        }
        if ($this->_pref1_cid === $cid) {
            $pref = $this->_pref1;
        } else {
            $pref = ($this->preferences())[$cid] ?? null;
        }
        return $pref ?? new PaperReviewPreference(0, null);
    }

    /** @return array<int,PaperReviewPreference> */
    function viewable_preferences(Contact $viewer, $aggregate = false) {
        if ($viewer->can_view_preference($this, $aggregate)) {
            return $this->preferences();
        } else if ($viewer->isPC) {
            $pref = $this->preference($viewer);
            if ($pref->preference !== 0 || $pref->expertise !== null) {
                return [$viewer->contactId => $pref];
            }
        }
        return [];
    }

    /** @return int */
    function desirability() {
        if ($this->_desirability === null) {
            $this->_desirability = 0;
            foreach ($this->preferences() as $pf) {
                if ($pf->preference > 0) {
                    $this->_desirability += 1;
                } else if ($pf->preference > -100 && $pf->preference < 0) {
                    $this->_desirability -= 1;
                }
            }
        }
        return $this->_desirability;
    }


    /** @param bool $only_me
     * @param bool $need_data */
    private function load_options($only_me, $need_data) {
        if ($this->_option_values === null
            && ($this->paperId === 0 || $this->optionIds === "")) {
            $this->_option_values = $this->_option_data = [];
        } else if ($this->_option_values === null
                   && $this->optionIds !== null
                   && !$need_data) {
            $this->_option_values = [];
            preg_match_all('/(\d+)\#(-?\d+)/', $this->optionIds, $m);
            for ($i = 0; $i < count($m[1]); ++$i) {
                $this->_option_values[(int) $m[1][$i]][] = (int) $m[2][$i];
            }
        } else if ($this->_option_values === null
                   || ($need_data && $this->_option_data === null)) {
            if (!$only_me || count($this->_row_set) === 1) {
                $row_set = $this->_row_set;
            } else {
                $row_set = PaperInfoSet::make_singleton($this);
            }
            assert(!!$row_set->get($this->paperId));
            foreach ($row_set as $prow) {
                $prow->_option_values = $prow->_option_data = [];
            }
            $result = $this->conf->qe("select paperId, optionId, value, data, dataOverflow from PaperOption where paperId?a order by paperId", $row_set->paper_ids());
            while ($result && ($row = $result->fetch_row())) {
                $prow = $row_set->get((int) $row[0]);
                $prow->_option_values[(int) $row[1]][] = (int) $row[2];
                $prow->_option_data[(int) $row[1]][] = $row[3] !== null ? $row[3] : $row[4];
            }
            Dbl::free($result);
        }
    }

    /** @return list<int> */
    private function stored_option_ids() {
        if ($this->_option_values === null) {
            $this->load_options(false, false);
        }
        return array_keys($this->_option_values);
    }

    /** @param int $id
     * @return array{list<int>,list<?string>} */
    function option_value_data($id) {
        if ($this->_option_data === null) {
            $this->load_options(false, true);
        }
        return [$this->_option_values[$id] ?? [],
                $this->_option_data[$id] ?? []];
    }

    /** @param int|PaperOption $o
     * @return ?PaperValue */
    function option($o) {
        $id = is_int($o) ? $o : $o->id;
        if (!array_key_exists($id, $this->_option_array)
            && ($opt = is_int($o) ? $this->conf->option_by_id($o) : $o)) {
            if ($this->_option_values === null) {
                $this->load_options(false, false);
            }
            if (isset($this->_option_values[$id])) {
                $this->_option_array[$id] = PaperValue::make_multi($this, $opt, $this->_option_values[$id], $this->_option_data[$id] ?? null);
            } else if ($opt->include_empty) {
                $this->_option_array[$id] = PaperValue::make_force($this, $opt);
            } else {
                $this->_option_array[$id] = null;
            }
        }
        return $this->_option_array[$id] ?? null;
    }

    /** @param int|PaperOption $o
     * @return PaperValue */
    function force_option($o) {
        if (($ov = $this->option($o))) {
            return $ov;
        } else if (($opt = is_int($o) ? $this->conf->option_by_id($o) : $o)) {
            return PaperValue::make_force($this, $opt);
        }
        return null;
    }

    /** @param int|PaperOption $o
     * @return PaperValue */
    function base_option($o) {
        $id = is_int($o) ? $o : $o->id;
        return $this->_base_option_array[$id] ?? $this->force_option($o);
    }

    function override_option(PaperValue $ov) {
        $id = $ov->option_id();
        if (!isset($this->_base_option_array[$id])) {
            $this->_base_option_array[$id] = $this->force_option($ov->option);
        }
        $this->_option_array[$id] = $ov;
    }

    /** @return list<int> */
    function overridden_option_ids() {
        return array_keys($this->_base_option_array ?? []);
    }

    function remove_option_overrides() {
        if ($this->_base_option_array !== null) {
            foreach ($this->_base_option_array as $id => $ov) {
                $this->_option_array[$id] = $ov;
            }
            $this->_base_option_array = null;
        }
    }

    function invalidate_options($reload = false) {
        assert($this->_base_option_array === null);
        $this->optionIds = $this->_option_values = $this->_option_data = null;
        $this->_option_array = [];
        if ($reload) {
            $this->load_options(true, true);
        }
    }

    /** @return array<int,PaperOption> */
    function form_fields() {
        return $this->conf->options()->form_fields($this);
    }

    /** @return array<int,PaperOption> */
    function page_fields() {
        return $this->conf->options()->page_fields($this);
    }

    /** @param int $dtype
     * @param int $did
     * @return ?DocumentInfo */
    function document($dtype, $did = 0, $full = false) {
        if ($did <= 0) {
            if ($dtype === DTYPE_SUBMISSION) {
                $did = $this->paperStorageId;
            } else if ($dtype === DTYPE_FINAL) {
                $did = $this->finalPaperStorageId;
            } else if (($oa = $this->force_option($dtype))
                       && $oa->option->is_document()) {
                return $oa->document(0);
            }
        }

        if ($did <= 1) {
            return null;
        }

        if ($this->_document_array !== null
            && array_key_exists($did, $this->_document_array)) {
            return $this->_document_array[$did];
        }

        if ((($dtype === DTYPE_SUBMISSION
              && $did == $this->paperStorageId
              && $this->finalPaperStorageId <= 0)
             || ($dtype === DTYPE_FINAL
                 && $did == $this->finalPaperStorageId))
            && !$full) {
            $this->_primary_document = $this->_primary_document
                ?? DocumentInfo::make_primary_document($this, $dtype, $this->size);
            return $this->_primary_document;
        }

        if ($this->_document_array === null) {
            $result = $this->conf->qe("select " . $this->conf->document_query_fields() . " from PaperStorage where paperId=? and inactive=0", $this->paperId);
            $this->_document_array = [];
            while (($di = DocumentInfo::fetch($result, $this->conf, $this))) {
                $this->_document_array[$di->paperStorageId] = $di;
            }
            Dbl::free($result);
        }
        if (!array_key_exists($did, $this->_document_array)) {
            $result = $this->conf->qe("select " . $this->conf->document_query_fields() . " from PaperStorage where paperStorageId=?", $did);
            $this->_document_array[$did] = DocumentInfo::fetch($result, $this->conf, $this);
            Dbl::free($result);
        }
        return $this->_document_array[$did];
    }

    function ensure_primary_documents() {
        if ($this->_primary_document || count($this->_row_set) <= 1) {
            return;
        }
        $psids = [];
        foreach ($this->_row_set as $prow) {
            $psids[] = $prow->finalPaperStorageId <= 0 ? $prow->paperStorageId : $prow->finalPaperStorageId;
        }
        $result = $this->conf->qe("select " . $this->conf->document_query_fields() . " from PaperStorage where paperStorageId?a", $psids);
        while (($di = DocumentInfo::fetch($result, $this->conf))) {
            if (($prow = $this->_row_set->get($di->paperId))) {
                $di->prow = $prow;
                $prow->_primary_document = $di;
            }
        }
        Dbl::free($result);
    }

    /** @return ?DocumentInfo */
    function primary_document() {
        return $this->document($this->finalPaperStorageId > 0 ? DTYPE_FINAL : DTYPE_SUBMISSION);
    }

    /** @return bool */
    function is_primary_document(DocumentInfo $doc) {
        return $doc->paperStorageId > 1
            && (($doc->paperStorageId === $this->paperStorageId
                 && $this->finalPaperStorageId <= 0
                 && $doc->documentType === DTYPE_SUBMISSION)
                || ($doc->paperStorageId === $this->finalPaperStorageId
                    && $doc->documentType === DTYPE_FINAL));
    }

    /** @return int */
    function primary_document_size() {
        // ensure `Paper.size` exists (might not due to import bugs)
        if (($this->size ?? -1) < 0 && ($doc = $this->primary_document())) {
            $this->size = $doc->size();
            if ($this->size >= 0) {
                $key = $doc->documentType === DTYPE_SUBMISSION ? "paperStorageId" : "finalPaperStorageId";
                $this->conf->qe("update Paper set size=? where paperId=? and {$key}=? and size<=0", $this->size, $this->paperId, $doc->paperStorageId);
            }
        }
        return $this->size ?? -1;
    }

    /** @param int $dtype
     * @return list<DocumentInfo> */
    function documents($dtype) {
        if ($dtype <= 0) {
            $doc = $this->document($dtype, 0, true);
            return $doc ? [$doc] : [];
        } else if (($ov = $this->option($dtype))
                   && $ov->option->has_document()) {
            return $ov->documents();
        }
        return [];
    }

    /** @param int $dtype
     * @return ?DocumentInfo */
    function attachment($dtype, $name) {
        $ov = $this->option($dtype);
        return $ov ? $ov->attachment($name) : null;
    }

    /** @return ?int */
    function npages() {
        $doc = $this->document($this->finalPaperStorageId <= 0 ? DTYPE_SUBMISSION : DTYPE_FINAL);
        return $doc ? $doc->npages() : 0;
    }

    function invalidate_documents() {
        $this->_primary_document = null;
        $this->_document_array = null;
    }

    function pause_mark_inactive_documents() {
        $this->_flags |= self::MARK_INACTIVE_PAUSE_1;
    }

    function resume_mark_inactive_documents() {
        $old_flags = $this->_flags;
        $this->_flags &= ~self::MARK_INACTIVE_PAUSE;
        if (($old_flags & self::MARK_INACTIVE_PAUSE_2) !== 0) {
            $this->mark_inactive_documents();
        }
    }

    function mark_inactive_documents() {
        // see also DocumentInfo::active_document_map
        if (($this->_flags & self::MARK_INACTIVE_PAUSE) === 0) {
            $this->_flags |= self::MARK_INACTIVE_PAUSE_2;
            $dids = [];
            if ($this->paperStorageId > 1) {
                $dids[] = $this->paperStorageId;
            }
            if ($this->finalPaperStorageId > 1) {
                $dids[] = $this->finalPaperStorageId;
            }
            foreach ($this->stored_option_ids() as $id) {
                if (($ov = $this->option($id)) && $ov->option->has_document()) {
                    $dids = array_merge($dids, $ov->option->value_dids($ov));
                }
            }
            $this->conf->qe("update PaperStorage set inactive=(paperStorageId?A) where paperId=? and documentType>=?", $dids, $this->paperId, DTYPE_FINAL);
            $this->_flags &= ~self::MARK_INACTIVE_PAUSE;
        } else {
            $this->_flags |= self::MARK_INACTIVE_PAUSE_2;
        }
    }


    /** @return list<PaperDocumentLink> */
    private function doclinks() {
        if ($this->_doclinks === null) {
            foreach ($this->_row_set as $prow) {
                $prow->_doclinks = [];
            }
            $result = $this->conf->qe("select paperId, linkId, linkType, linkIndex, documentId from DocumentLink where paperId?a order by paperId, linkId, linkType, linkIndex", $this->_row_set->paper_ids());
            while ($result && ($row = $result->fetch_row())) {
                $prow = $this->_row_set->get((int) $row[0]);
                $prow->_doclinks[] = new PaperDocumentLink(
                    (int) $row[1], (int) $row[2], (int) $row[3], (int) $row[4]
                );
            }
            Dbl::free($result);
        }
        return $this->_doclinks;
    }

    /** @param int $linkId
     * @param int $linkType
     * @param ?CommentInfo $owner
     * @return DocumentInfoSet
     * @suppress PhanTypeArraySuspiciousNullable */
    function linked_documents($linkId, $linkType, $owner = null) {
        if ($this->_doclinks === null) {
            $this->doclinks();
        }
        $l = 0;
        $n = $r = count($this->_doclinks);
        if ($n > 20) {
            while ($r > $l) {
                $m = $l + (($r - $l) >> 1);
                $dl = $this->_doclinks[$m];
                if ($dl->linkId < $linkId) {
                    $l = $m + 1;
                } else {
                    $r = $m;
                }
            }
        }
        $docs = new DocumentInfoSet;
        while ($l < $n) {
            $dl = $this->_doclinks[$l];
            if ($dl->linkId === $linkId
                && $dl->linkType === $linkType
                && ($d = $this->document($linkType, $dl->documentId))) {
                $docs->add($owner ? $d->with_owner($owner) : $d);
            } else if ($dl->linkId > $linkId) {
                break;
            }
            ++$l;
        }
        return $docs;
    }

    /** @param int $docid
     * @param int $linkType
     * @return ?int */
    function link_id_by_document_id($docid, $linkType) {
        foreach ($this->doclinks() as $dl) {
            if ($dl->linkType === $linkType
                && $dl->documentId === $docid) {
                return $dl->linkId;
            }
        }
        return null;
    }

    function invalidate_linked_documents() {
        $this->_doclinks = null;
    }

    function mark_inactive_linked_documents() {
        // see also DocumentInfo::active_document_map
        $this->conf->qe("update PaperStorage ps
            left join DocumentLink dl on (dl.paperId=? and dl.linkType=ps.documentType and dl.documentId=ps.paperStorageId)
            set inactive=(dl.documentId is null)
            where ps.paperId=? and ps.documentType<=?",
            $this->paperId, $this->paperId, DTYPE_COMMENT);
    }


    function invalidate_reviews() {
        $this->reviewSignatures = $this->_review_array = null;
        ++$this->_review_array_version;
    }

    /** @param bool $always */
    function load_reviews($always = false) {
        if ($this->reviewSignatures !== null
            && $this->_review_array === null
            && !$always) {
            $this->_review_array = [];
            ++$this->_review_array_version;
            $this->_flags &= ~self::REVIEW_FLAGS;
            $this->_reviews_have = null;
            if ($this->reviewSignatures !== "") {
                foreach (explode(",", $this->reviewSignatures) as $rs) {
                    $rrow = ReviewInfo::make_signature($this, $rs);
                    $this->_review_array[$rrow->reviewId] = $rrow;
                }
            }
            return;
        }

        if ($this->_review_array === null || count($this->_row_set) === 1 || $always) {
            $row_set = $this->_row_set;
        } else {
            $row_set = PaperInfoSet::make_singleton($this);
        }
        $rdiffs = [];
        $had = 0;
        foreach ($row_set as $prow) {
            foreach ($prow->_review_array ?? [] as $rrow) {
                if ($rrow->prop_changed())
                    $rdiffs[$rrow->reviewId] = $rrow->prop_diff();
            }
            $prow->_review_array = [];
            ++$prow->_review_array_version;
            $had |= $prow->_flags;
            $prow->_flags = ($prow->_flags & ~self::REVIEW_FLAGS) | self::REVIEW_HAS_FULL;
            $prow->_reviews_have = null;
        }

        $result = $this->conf->qe("select PaperReview.*, " . $this->conf->rating_signature_query() . " ratingSignature from PaperReview where paperId?a order by paperId, reviewId", $row_set->paper_ids());
        while (($rrow = ReviewInfo::fetch($result, $row_set, $this->conf))) {
            if (($diff = $rdiffs[$rrow->reviewId] ?? null)) {
                $diff->apply_prop_changes_to($rrow);
            }
            $rrow->prow->_review_array[$rrow->reviewId] = $rrow;
        }
        Dbl::free($result);

        $this->ensure_reviewer_names_set($row_set);
    }

    /** @return int|false */
    function parse_ordinal_id($oid) {
        if (is_int($oid)) {
            return $oid;
        } else if ($oid === "") {
            return 0;
        } else if (ctype_digit($oid)) {
            return intval($oid);
        }
        $pidstr = (string) $this->paperId;
        if (str_starts_with($oid, $pidstr)) {
            $oid = (string) substr($oid, strlen($pidstr));
            if (strlen($oid) > 1 && $oid[0] === "r" && ctype_digit(substr($oid, 1))) {
                return intval(substr($oid, 1));
            }
        }
        if (ctype_upper($oid) && ($n = parse_latin_ordinal($oid)) > 0) {
            return -$n;
        } else if ($oid === "rnew" || $oid === "new") {
            return 0;
        }
        return false;
    }

    /** @return array<int,ReviewInfo> */
    function all_reviews() {
        if ($this->_review_array === null) {
            $this->load_reviews(false);
        }
        return $this->_review_array;
    }

    /** @return array<int,ReviewInfo> */
    function all_full_reviews() {
        $this->ensure_full_reviews();
        return $this->all_reviews();
    }

    /** @return list<ReviewInfo> */
    function reviews_as_list() {
        return array_values($this->all_reviews());
    }

    /** @return list<ReviewInfo> */
    function reviews_as_display() {
        $srs = $urs = $ers = [];

        foreach ($this->all_reviews() as $rrow) {
            if ($rrow->reviewStatus >= ReviewInfo::RS_COMPLETED) {
                $srs[] = $rrow;
            } else if ($rrow->is_subreview()) {
                $ers[] = $rrow;
            } else if ($rrow->reviewOrdinal) {
                $srs[] = $rrow;
            } else {
                $urs[] = $rrow;
            }
        }

        usort($srs, "ReviewInfo::display_compare");

        foreach ($urs as $urow) {
            $srs[] = $urow;
        }

        foreach ($ers as $urow) {
            $p0 = count($srs);
            foreach ($srs as $i => $srow) {
                if ($urow->requestedBy === $srow->contactId
                    || ($urow->requestedBy === $srow->requestedBy
                        && $srow->is_subreview()
                        && ($urow->reviewStatus < ReviewInfo::RS_APPROVED
                            || ($srow->reviewStatus >= ReviewInfo::RS_APPROVED
                                && $urow->timeDisplayed >= $srow->timeDisplayed)))) {
                    $p0 = $i + 1;
                }
            }
            array_splice($srs, $p0, 0, [$urow]);
        }

        return $srs;
    }

    /** @param int $id
     * @return ?ReviewInfo */
    function review_by_id($id) {
        return ($this->all_reviews())[$id] ?? null;
    }

    /** @param int $ordinal
     * @return ?ReviewInfo */
    function review_by_ordinal($ordinal) {
        foreach ($this->all_reviews() as $rrow) {
            if ($rrow->reviewOrdinal == $ordinal) {
                return $rrow;
            }
        }
        return null;
    }

    /** @param string $oid
     * @return ?ReviewInfo */
    function review_by_ordinal_id($oid) {
        if (($n = $this->parse_ordinal_id($oid)) === false || $n === 0) {
            return null;
        } else if ($n < 0) {
            return $this->review_by_ordinal(-$n);
        } else {
            return $this->review_by_id($n);
        }
    }

    /** @param int|Contact $u
     * @return ?ReviewInfo */
    function review_by_user($u) {
        $cid = self::contact_to_cid($u);
        foreach ($this->all_reviews() as $rrow) {
            if ($rrow->contactId == $cid) {
                return $rrow;
            }
        }
        return null;
    }

    /** @param int|Contact $u
     * @return ReviewInfo */
    function checked_review_by_user($u) {
        if (($rrow = $this->review_by_user($u))) {
            return $rrow;
        }
        throw new Exception("PaperInfo::checked_review_by_user failure");
    }

    /** @param int|Contact $contact
     * @return list<ReviewInfo> */
    function reviews_by_user($contact, $rev_tokens = null) {
        $cid = self::contact_to_cid($contact);
        $rrows = [];
        foreach ($this->all_reviews() as $rrow) {
            if ($rrow->contactId == $cid
                || ($rev_tokens
                    && $rrow->reviewToken
                    && in_array($rrow->reviewToken, $rev_tokens, true))) {
                $rrows[] = $rrow;
            }
        }
        return $rrows;
    }

    /** @param int|Contact $contact
     * @return ?ReviewInfo */
    function viewable_review_by_user($contact, Contact $viewer) {
        $cid = self::contact_to_cid($contact);
        foreach ($this->viewable_reviews_as_display($viewer) as $rrow) {
            if ($rrow->contactId == $cid
                && $viewer->can_view_review_identity($this, $rrow)) {
                return $rrow;
            }
        }
        return null;
    }

    /** @param int|string $token
     * @return ?ReviewInfo */
    function review_by_token($token) {
        if (!is_int($token)) {
            $token = decode_token($token, "V");
        }
        foreach ($this->all_reviews() as $rrow) {
            if ($rrow->reviewToken == $token) {
                return $rrow;
            }
        }
        return null;
    }


    private function ensure_full_review_name() {
        ReviewInfo::check_ambiguous_names($this->_full_review ?? []);
    }

    /** @return ?ReviewInfo */
    function full_review_by_id($id) {
        if ($this->_full_review_key === null
            && ($this->_flags & self::REVIEW_HAS_FULL) === 0) {
            $this->_full_review_key = "r$id";
            $result = $this->conf->qe("select PaperReview.*, " . $this->conf->rating_signature_query() . " ratingSignature from PaperReview where paperId=? and reviewId=?", $this->paperId, $id);
            $rrow = ReviewInfo::fetch($result, $this, $this->conf);
            $this->_full_review = $rrow ? [$rrow] : [];
            Dbl::free($result);
            $this->ensure_full_review_name();
        }
        if ($this->_full_review_key === "r{$id}") {
            return $this->_full_review[0] ?? null;
        }
        $this->ensure_full_reviews();
        return $this->review_by_id($id);
    }

    /** @param int|Contact $contact
     * @return list<ReviewInfo> */
    function full_reviews_by_user($contact) {
        $cid = self::contact_to_cid($contact);
        if ($this->_full_review_key === null
            && ($this->_flags & self::REVIEW_HAS_FULL) === 0) {
            foreach ($this->_row_set as $prow) {
                $prow->_full_review = [];
                $prow->_full_review_key = "u{$cid}";
            }
            $result = $this->conf->qe("select PaperReview.*, " . $this->conf->rating_signature_query() . " ratingSignature from PaperReview where paperId?a and contactId=? order by paperId, reviewId", $this->_row_set->paper_ids(), $cid);
            while (($rrow = ReviewInfo::fetch($result, $this->_row_set, $this->conf))) {
                $rrow->prow->_full_review[] = $rrow;
            }
            Dbl::free($result);
            $this->ensure_full_review_name();
        }
        if ($this->_full_review_key === "u{$cid}") {
            return $this->_full_review;
        }
        $this->ensure_full_reviews();
        return $this->reviews_by_user($contact);
    }

    /** @return ?ReviewInfo */
    function full_review_by_ordinal($ordinal) {
        if ($this->_full_review_key === null
            && ($this->_flags & self::REVIEW_HAS_FULL) === 0) {
            $this->_full_review_key = "o{$ordinal}";
            $result = $this->conf->qe("select PaperReview.*, " . $this->conf->rating_signature_query() . " ratingSignature from PaperReview where paperId=? and reviewOrdinal=?", $this->paperId, $ordinal);
            $rrow = ReviewInfo::fetch($result, $this, $this->conf);
            $this->_full_review = $rrow ? [$rrow] : [];
            Dbl::free($result);
            $this->ensure_full_review_name();
        }
        if ($this->_full_review_key === "o{$ordinal}") {
            return $this->_full_review[0] ?? null;
        }
        $this->ensure_full_reviews();
        return $this->review_by_ordinal($ordinal);
    }

    /** @param string $oid
     * @return ?ReviewInfo */
    function full_review_by_ordinal_id($oid) {
        if (($n = $this->parse_ordinal_id($oid)) === false || $n === 0) {
            return null;
        } else if ($n < 0) {
            return $this->full_review_by_ordinal(-$n);
        } else {
            return $this->full_review_by_id($n);
        }
    }

    /** @return ?ReviewInfo */
    private function fresh_review_by($key, $value) {
        $result = $this->conf->qe("select PaperReview.*, " . $this->conf->rating_signature_query() . " ratingSignature from PaperReview where paperId=? and {$key}=? order by paperId, reviewId", $this->paperId, $value);
        $rrow = ReviewInfo::fetch($result, $this, $this->conf);
        Dbl::free($result);
        return $rrow;
    }

    /** @return ?ReviewInfo */
    function fresh_review_by_id($id) {
        return $this->fresh_review_by("reviewId", $id);
    }

    /** @param Contact|int $u
     * @return ?ReviewInfo */
    function fresh_review_by_user($u) {
        return $this->fresh_review_by("contactId", self::contact_to_cid($u));
    }

    /** @return list<ReviewInfo> */
    function viewable_reviews_as_display(Contact $viewer) {
        $cinfo = $viewer->__rights($this);
        if ($cinfo->vreviews_array === null
            || $cinfo->vreviews_version !== $this->_review_array_version) {
            $cinfo->vreviews_array = [];
            foreach ($this->reviews_as_display() as $rrow) {
                if ($viewer->can_view_review($this, $rrow)) {
                    $cinfo->vreviews_array[] = $rrow;
                }
            }
            $cinfo->vreviews_version = $this->_review_array_version;
        }
        return $cinfo->vreviews_array;
    }

    /** @param int $cid
     * @return bool */
    function can_view_review_identity_of($cid, Contact $viewer) {
        if ($viewer->can_administer($this)
            || $cid === $viewer->contactId) {
            return true;
        }
        foreach ($this->reviews_by_user($cid) as $rrow) {
            if ($viewer->can_view_review_identity($this, $rrow)) {
                return true;
            }
        }
        return false;
    }

    /** @param ReviewField $field
     * @return bool */
    function may_have_viewable_scores($field, Contact $viewer) {
        return $viewer->can_view_review($this, null, $field->view_score)
            || $this->review_type($viewer);
    }

    function ensure_reviews() {
        if ($this->_review_array === null) {
            $this->load_reviews(false);
        }
    }

    function ensure_full_reviews() {
        if (($this->_flags & self::REVIEW_HAS_FULL) === 0) {
            $this->load_reviews(true);
        }
    }

    /** @param PaperInfoSet $row_set */
    private function ensure_reviewer_names_set($row_set) {
        foreach ($row_set as $prow) {
            foreach ($prow->all_reviews() as $rrow) {
                $this->conf->prefetch_user_by_id($rrow->contactId);
            }
            if (($cmts = $prow->_comment_array ?? $prow->_comment_skeleton_array)) {
                foreach ($cmts as $crow) {
                    $this->conf->prefetch_user_by_id($crow->contactId);
                }
            }
        }
        foreach ($row_set as $prow) {
            $prow->_flags |= self::REVIEW_HAS_NAMES;
            ReviewInfo::check_ambiguous_names(array_values($prow->all_reviews()));
        }
    }

    function ensure_reviewer_names() {
        $this->ensure_reviews();
        if (!empty($this->_review_array)
            && ($this->_flags & self::REVIEW_HAS_NAMES) === 0) {
            $this->ensure_reviewer_names_set($this->_row_set);
        }
    }

    /** @param string $fid
     * @param string $main_storage
     * @param bool $maybe_null */
    private function load_review_fields($fid, $main_storage, $maybe_null) {
        $k = $fid . "Signature";
        $this->ensure_reviews();
        if (empty($this->_review_array)) {
            $this->$k = "";
            return;
        }
        foreach ($this->_row_set as $prow) {
            $prow->$k = "";
        }
        $select = $maybe_null ? "coalesce({$main_storage},'.')" : $main_storage;
        $result = $this->conf->qe("select paperId, group_concat({$select} order by reviewId) from PaperReview where paperId?a group by paperId", $this->_row_set->paper_ids());
        while (($row = $result->fetch_row())) {
            $prow = $this->_row_set->get((int) $row[0]);
            $prow->$k = $row[1];
        }
        Dbl::free($result);
    }

    /** @param int $order */
    function ensure_review_field_order($order) {
        if (($this->_flags & self::REVIEW_HAS_FULL) !== 0
            || ($this->_reviews_have[$order] ?? null) !== null) {
            return;
        }
        $rform = $this->conf->review_form();
        $this->_reviews_have = $this->_reviews_have ?? $rform->order_array(null);
        $f = $rform->field_by_order($order);
        if (!$f) {
            $this->_reviews_have[$order] = false;
        } else if (!$f->main_storage) {
            $this->ensure_full_reviews();
        } else {
            $this->_reviews_have[$order] = true;
            $k = $f->short_id . "Signature";
            if ($this->$k === null) {
                $this->load_review_fields($f->short_id, $f->main_storage, false);
            }
            $x = explode(",", $this->$k);
            foreach ($this->reviews_as_list() as $i => $rrow) {
                $rrow->fields = $rrow->fields ?? $rform->order_array(null);
                $fv = (int) $x[$i];
                $rrow->fields[$order] = $fv > 0 ? $fv : ($fv < 0 ? 0 : null);
            }
        }
    }

    /** @param int $order */
    function _mark_has_review_field_order($order) {
        if ($this->_reviews_have === null) {
            $this->_reviews_have = $this->conf->review_form()->order_array(null);
        }
        $this->_reviews_have[$order] = true;
    }

    private function _update_review_word_counts($rids) {
        $rf = $this->conf->review_form();
        $result = $this->conf->qe("select * from PaperReview where paperId=$this->paperId and reviewId?a", $rids);
        $qs = [];
        while (($rrow = ReviewInfo::fetch($result, $this, $this->conf))) {
            if ($rrow->reviewWordCount === null) {
                $rrow->reviewWordCount = $rf->word_count($rrow);
                $qs[] = "update PaperReview set reviewWordCount={$rrow->reviewWordCount} where paperId={$this->paperId} and reviewId={$rrow->reviewId}";
            }
            /** @phan-suppress-next-line PhanTypeArraySuspiciousNullable */
            $my_rrow = $this->_review_array[$rrow->reviewId];
            $my_rrow->reviewWordCount = $rrow->reviewWordCount;
        }
        Dbl::free($result);
        if (!empty($qs)) {
            $mresult = Dbl::multi_qe($this->conf->dblink, join(";", $qs));
            $mresult->free_all();
        }
    }

    function ensure_review_word_counts() {
        if (($this->_flags & self::REVIEW_HAS_WORDCOUNT) === 0) {
            $this->_flags |= self::REVIEW_HAS_WORDCOUNT;
            if ($this->reviewWordCountSignature === null) {
                $this->load_review_fields("reviewWordCount", "reviewWordCount", true);
            }
            $x = explode(",", $this->reviewWordCountSignature);
            $bad_ids = [];

            foreach ($this->reviews_as_list() as $i => $rrow) {
                if ($x[$i] !== ".") {
                    $rrow->reviewWordCount = (int) $x[$i];
                } else {
                    $bad_ids[] = $rrow->reviewId;
                }
            }
            if (!empty($bad_ids)) {
                $this->_update_review_word_counts($bad_ids);
            }
        }
    }

    function ensure_review_ratings(?ReviewInfo $ensure_rrow = null) {
        $pids = [];
        foreach ($this->_row_set as $prow) {
            if ($prow === $this
                || !empty($prow->_review_array)
                || isset($prow->reviewSignatures)) {
                $pids[] = $prow->paperId;
                foreach ($prow->all_reviews() as $rrow) {
                    $rrow->ratingSignature = "";
                }
            }
        }
        if ($ensure_rrow) {
            $ensure_rrow->ratingSignature = "";
        }
        $result = $this->conf->qe("select paperId, reviewId, " . $this->conf->rating_signature_query() . " ratingSignature from PaperReview where paperId?a", $pids);
        while (($row = $result->fetch_row())) {
            $prow = $this->_row_set->get((int) $row[0]);
            if (($rrow = $prow->_review_array[(int) $row[1]] ?? null)) {
                $rrow->ratingSignature = $row[2];
            }
            if ($ensure_rrow && $ensure_rrow->reviewId === (int) $row[1]) {
                $ensure_rrow->ratingSignature = $row[2];
            }
        }
        Dbl::free($result);
    }

    /** @return bool */
    function has_author_seen_any_review() {
        foreach ($this->all_reviews() as $rrow) {
            if ($rrow->reviewAuthorSeen) {
                return true;
            }
        }
        return false;
    }


    /** @return list<Contact> */
    function reviewers_as_display() {
        $cids = [];
        foreach ($this->reviews_as_display() as $rrow) {
            $cids[$rrow->contactId] = true;
            $this->conf->prefetch_user_by_id($rrow->contactId);
        }
        $us = [];
        foreach (array_keys($cids) as $cid) {
            if (($u = $this->conf->user_by_id($cid, USER_SLICE)))
                $us[] = $u;
        }
        return $us;
    }


    function load_review_requests($always = false) {
        if ($this->_request_array === null || $always || count($this->_row_set) === 1) {
            $row_set = $this->_row_set;
        } else {
            $row_set = PaperInfoSet::make_singleton($this);
        }
        foreach ($row_set as $prow) {
            $prow->_request_array = [];
        }

        $result = $this->conf->qe("select * from ReviewRequest where paperId?a", $row_set->paper_ids());
        while (($ref = ReviewRequestInfo::fetch($result, $this->conf))) {
            $prow = $row_set->get($ref->paperId);
            $prow->_request_array[] = $ref;
        }
        Dbl::free($result);
    }

    /** @return list<ReviewRequestInfo> */
    function review_requests() {
        if ($this->_request_array === null) {
            $this->load_review_requests();
        }
        return $this->_request_array;
    }


    function load_review_refusals($always = false) {
        if ($this->_refusal_array === null || $always || count($this->_row_set) === 1) {
            $row_set = $this->_row_set;
        } else {
            $row_set = PaperInfoSet::make_singleton($this);
        }
        foreach ($row_set as $prow) {
            $prow->_refusal_array = [];
        }

        $result = $this->conf->qe("select * from PaperReviewRefused where paperId?a", $row_set->paper_ids());
        while (($ref = ReviewRefusalInfo::fetch($result, $this->conf))) {
            $prow = $row_set->get($ref->paperId);
            $prow->_refusal_array[] = $ref;
        }
        Dbl::free($result);
    }

    /** @return list<ReviewRefusalInfo> */
    function review_refusals() {
        if ($this->_refusal_array === null) {
            $this->load_review_refusals();
        }
        return $this->_refusal_array;
    }

    /** @param int $id
     * @return ?ReviewRefusalInfo */
    function review_refusal_by_id($id) {
        foreach ($this->review_refusals() as $ref) {
            if ($ref->refusedReviewId === $id)
                return $ref;
        }
        return null;
    }

    /** @return list<ReviewRefusalInfo> */
    function review_refusals_by_user_id($uxid) {
        $a = [];
        foreach ($this->review_refusals() as $ref) {
            if ($ref->contactId == $uxid) {
                $a[] = $ref;
            }
        }
        return $a;
    }

    /** @return list<ReviewRefusalInfo> */
    function review_refusals_by_user(Contact $user) {
        $a = [];
        foreach ($this->review_refusals() as $ref) {
            if ($ref->contactId == $user->contactId
                || strcasecmp($ref->email, $user->email) === 0) {
                $a[] = $ref;
            }
        }
        return $a;
    }

    /** @param string $email
     * @return list<ReviewRefusalInfo> */
    function review_refusals_by_email($email) {
        $a = [];
        foreach ($this->review_refusals() as $ref) {
            if (strcasecmp($ref->email, $email) === 0) {
                $a[] = $ref;
            }
        }
        return $a;
    }


    /** @return list<CommentInfo> */
    function fetch_comments($extra_where = null) {
        $result = $this->conf->qe("select * from PaperComment where paperId={$this->paperId}" . ($extra_where ? " and {$extra_where}" : "") . " order by paperId, commentId");
        $comments = [];
        while (($c = CommentInfo::fetch($result, $this, $this->conf))) {
            $comments[] = $c;
        }
        Dbl::free($result);
        return $comments;
    }

    function load_comments() {
        foreach ($this->_row_set as $prow) {
            $prow->_comment_skeleton_array = null;
            $prow->_comment_array = [];
        }
        $result = $this->conf->qe("select * from PaperComment where paperId?a order by paperId, commentId", $this->_row_set->paper_ids());
        while (($c = CommentInfo::fetch($result, null, $this->conf))) {
            $prow = $this->_row_set->checked_paper_by_id($c->paperId);
            $c->set_prow($prow);
            $prow->_comment_array[] = $c;
        }
        Dbl::free($result);
    }

    function ensure_comments() {
        if ($this->_comment_array === null) {
            $this->load_comments();
        }
    }

    /** @return list<CommentInfo> */
    function all_comments() {
        if ($this->_comment_array === null) {
            $this->load_comments();
        }
        return $this->_comment_array;
    }

    /** @param int $cid
     * @return ?CommentInfo */
    function comment_by_id($cid) {
        foreach ($this->all_comments() as $crow) {
            if ($crow->commentId === $cid)
                return $crow;
        }
        return null;
    }

    /** @return list<CommentInfo> */
    function viewable_comments(Contact $user, $textless = false) {
        $crows = [];
        foreach ($this->all_comments() as $crow) {
            if ($user->can_view_comment($this, $crow, $textless)) {
                $crows[] = $crow;
            }
        }
        return $crows;
    }

    /** @return list<CommentInfo> */
    function all_comment_skeletons() {
        if ($this->_comment_skeleton_array === null) {
            if ($this->_comment_array !== null
                || $this->commentSkeletonInfo === null) {
                return $this->all_comments();
            }
            $this->_comment_skeleton_array = [];
            preg_match_all('/(\d+);(\d+);(\d+);(\d+);(\d+);([^|]*)/',
                           $this->commentSkeletonInfo, $ms, PREG_SET_ORDER);
            foreach ($ms as $m) {
                $c = new CommentInfo($this);
                $c->commentId = (int) $m[1];
                $c->contactId = (int) $m[2];
                $c->commentType = (int) $m[3];
                $c->commentRound = (int) $m[4];
                $c->timeModified = (int) $m[5];
                $c->commentTags = $m[6];
                $this->_comment_skeleton_array[] = $c;
            }
        }
        return $this->_comment_skeleton_array;
    }

    /** @return list<CommentInfo> */
    function viewable_comment_skeletons(Contact $user, $textless = false) {
        $crows = [];
        foreach ($this->all_comment_skeletons() as $crow) {
            if ($user->can_view_comment($this, $crow, $textless))
                $crows[] = $crow;
        }
        return $crows;
    }

    /** @param int|Contact $contact
     * @return bool */
    function has_commenter($contact) {
        $cid = self::contact_to_cid($contact);
        foreach ($this->all_comment_skeletons() as $crow) {
            if ($crow->contactId == $cid) {
                return true;
            }
        }
        return false;
    }


    /** @param list<ReviewInfo> $rrows
     * @param list<CommentInfo> $crows
     * @return list<ReviewInfo|CommentInfo> */
    function merge_reviews_and_comments($rrows, $crows) {
        if (empty($crows)) {
            return $rrows;
        }

        usort($crows, function ($a, $b) {
            if ($a->timeDisplayed != $b->timeDisplayed) {
                if ($a->timeDisplayed == 0 || $b->timeDisplayed == 0) {
                    return $a->timeDisplayed == 0 ? 1 : -1;
                } else {
                    return $a->timeDisplayed < $b->timeDisplayed ? -1 : 1;
                }
            } else {
                return $a->commentId < $b->commentId ? -1 : 1;
            }
        });

        $xrows = [];
        $i = $j = 0;
        while ($i < count($rrows) && $j < count($crows)) {
            $rr = $rrows[$i];
            $cr = $crows[$j];
            if ($rr->timeDisplayed == 0 || $cr->timeDisplayed == 0) {
                break;
            } else if ($rr->timeDisplayed <= $cr->timeDisplayed) {
                $xrows[] = $rr;
                ++$i;
                while ($i < count($rrows) && $rrows[$i]->timeDisplayed == 0) {
                    $xrows[] = $rrows[$i];
                    ++$i;
                }
            } else {
                $xrows[] = $cr;
                ++$j;
            }
        }
        while ($i < count($rrows)) {
            $xrows[] = $rrows[$i];
            ++$i;
        }
        while ($j < count($crows)) {
            $xrows[] = $crows[$j];
            ++$j;
        }
        return $xrows;
    }

    /** @return list<ReviewInfo|CommentInfo> */
    function viewable_reviews_and_comments(Contact $user) {
        $this->ensure_full_reviews();
        return $this->merge_reviews_and_comments($this->viewable_reviews_as_display($user), $this->viewable_comments($user));
    }

    /** @return list<ReviewInfo|CommentInfo> */
    function viewable_submitted_reviews_and_comments(Contact $user) {
        $this->ensure_full_reviews();
        $rrows = [];
        foreach ($this->viewable_reviews_as_display($user) as $rrow) {
            if ($rrow->reviewSubmitted) {
                $rrows[] = $rrow;
            }
        }
        return $this->merge_reviews_and_comments($rrows, $this->viewable_comments($user));
    }

    static function review_or_comment_text_separator($a, $b) {
        if (!$a || !$b) {
            return "";
        } else if (isset($a->reviewId)
                   || isset($b->reviewId)
                   || (($a->commentType | $b->commentType) & CommentInfo::CT_RESPONSE)) {
            return "\n\n\n";
        } else {
            return "\n\n";
        }
    }


    /** @return array<int,int> */
    function load_watch() {
        $this->watch = null;
        $this->_watch_array = [];
        $result = $this->conf->qe("select contactId, watch from PaperWatch where paperId=?", $this->paperId);
        while (($row = $result->fetch_row())) {
            $this->_watch_array[(int) $row[0]] = (int) $row[1];
        }
        Dbl::free($result);
        return $this->_watch_array;
    }

    /** @return array<int,int> */
    function all_watch() {
        return $this->_watch_array ?? $this->load_watch();
    }

    /** @return int */
    function watch(Contact $user) {
        if ($this->watch !== null
            && $this->myContactXid === $user->contactXid) {
            return (int) $this->watch;
        }
        return ($this->all_watch())[$user->contactId] ?? 0;
    }


    /** @param Contact $a
     * @param Contact $b */
    function notify_user_compare($a, $b) {
        // first, authors by position in author list;
        // then, reviewers by display order;
        // then others
        $aa = $this->has_author($a);
        $ba = $this->has_author($b);
        if ($aa && $ba) {
            $aua = $this->author_by_email($a->email);
            $aia = $aua ? $aua->author_index : PHP_INT_MAX;
            $aub = $this->author_by_email($b->email);
            $aib = $aub ? $aub->author_index : PHP_INT_MAX;
            if ($aia !== $aib) {
                return $aia < $aib ? -1 : 1;
            }
        } else if ($aa || $ba) {
            return $aa ? -1 : 1;
        } else {
            $ar = $this->review_by_user($a);
            $br = $this->review_by_user($b);
            if ($ar && $br) {
                $arc = ReviewInfo::display_compare($ar, $br);
                if ($arc !== 0) {
                    return $arc;
                }
            } else if ($ar || $br) {
                return $ar ? -1 : 1;
            }
        }
        return call_user_func($a->conf->user_comparator(), $a, $b);
    }

    /** @param list<int> $cids
     * @param string $clause
     * @return list<Contact> */
    function generic_followers($cids, $clause) {
        // collect followers
        $result = $this->conf->qe("select " . $this->conf->user_query_fields(Contact::SLICE_MINIMAL - Contact::SLICEBIT_PASSWORD - Contact::SLICEBIT_DEFAULTWATCH) . " from ContactInfo where (contactId?a or ({$clause})) and (cflags&?)=0",
            $cids, Contact::CFM_DISABLEMENT);
        $byuid = [];
        while (($u = Contact::fetch($result, $this->conf))) {
            if ($u->can_view_paper($this))
                $byuid[$u->contactId] = $u;
        }
        Dbl::free($result);
        // remove linked accounts with primaries in the list
        $us = [];
        foreach ($byuid as $u) {
            if ($u->primaryContactId <= 0
                || $u->primaryContactId === $u->contactId /* should not happen */
                || !isset($byuid[$u->primaryContactId])) {
                $us[] = $u;
            }
        }
        // sort and return
        usort($us, [$this, "notify_user_compare"]);
        return $us;
    }

    /** @return list<Contact> */
    function contact_followers() {
        $cids = [];
        foreach ($this->conflict_type_list() as $cu) {
            if ($cu->conflictType >= CONFLICT_AUTHOR)
                $cids[] = $cu->contactId;
        }
        return $this->generic_followers($cids, "false");
    }

    /** @return list<Contact> */
    function late_withdrawal_followers() {
        $us = [];
        foreach ($this->generic_followers([], "(defaultWatch&" . Contact::WATCH_LATE_WITHDRAWAL_ALL . ")!=0 and roles!=0") as $u) {
            if ($u->following_late_withdrawal($this))
                $us[] = $u;
        }
        return $us;
    }

    /** @param int $topic
     * @return list<Contact> */
    function review_followers($topic) {
        $cids = [];
        foreach ($this->conflict_type_list() as $cu) {
            if ($cu->conflictType >= CONFLICT_AUTHOR)
                $cids[] = $cu->contactId;
        }
        foreach ($this->all_reviews() as $rrow) {
            $cids[] = $rrow->contactId;
        }
        foreach ($this->all_comment_skeletons() as $crow) {
            $cids[] = $crow->contactId;
        }
        foreach ($this->all_watch() as $cid => $w) {
            if (($w & Contact::WATCH_REVIEW) !== 0)
                $cids[] = $cid;
        }
        $us = [];
        foreach ($this->generic_followers($cids, "(defaultWatch&" . (Contact::WATCH_REVIEW_ALL | Contact::WATCH_REVIEW_MANAGED) . ")!=0 and roles!=0") as $u) {
            if ($u->following_reviews($this, $topic))
                $us[] = $u;
        }
        return $us;
    }

    function delete_from_database(?Contact $user = null) {
        // XXX email self?
        if ($this->paperId <= 0) {
            return false;
        }
        $rrows = $this->all_reviews();

        // deleting a paper can change author/reviewer state & thus cdbRoles
        $uids = [];
        foreach ($this->conflict_types() as $uid => $ctype) {
            if ($ctype >= CONFLICT_AUTHOR)
                $uids[] = $ctype;
        }
        foreach ($rrows as $rrow) {
            if ($rrow->reviewType > 0)
                $uids[] = $rrow->contactId;
        }

        $qs = [];
        foreach (["PaperWatch", "PaperReviewPreference", "PaperReviewRefused", "ReviewRequest", "PaperTag", "PaperComment", "PaperReview", "PaperTopic", "PaperOption", "PaperConflict", "Paper", "PaperStorage", "DocumentLink", "Capability"] as $table) {
            $qs[] = "delete from {$table} where paperId={$this->paperId}";
        }
        $mresult = Dbl::multi_qe($this->conf->dblink, join(";", $qs));
        $mresult->free_all();

        if (Dbl::$nerrors) {
            return false;
        }

        $this->conf->log_for($user, $user, "Paper deleted", $this->paperId);

        // update settings
        $this->conf->update_papersub_setting(-1);
        if ($this->outcome_sign > 0) {
            $this->conf->update_paperacc_setting(-1);
        }
        if ($this->leadContactId > 0 || $this->shepherdContactId > 0) {
            $this->conf->update_paperlead_setting(-1);
        }
        if ($this->managerContactId > 0) {
            $this->conf->update_papermanager_setting(-1);
        }
        if (array_filter($rrows, function ($rrow) { return $rrow->reviewToken > 0; })) {
            $this->conf->update_rev_tokens_setting(-1);
        }
        if (array_filter($rrows, function ($rrow) { return $rrow->reviewType == REVIEW_META; })) {
            $this->conf->update_metareviews_setting(-1);
        }

        // update cdbRoles
        $this->conf->prefetch_users_by_id($uids);
        foreach ($uids as $uid) {
            if (($u = $this->conf->user_by_id($uid)))
                $u->update_cdb_roles();
        }

        return true;
    }
}

class PaperInfoLikelyContacts implements JsonSerializable {
    /** @var list<Author> */
    public $author_list = [];
    /** @var list<list<int>> */
    public $author_cids = [];
    /** @var list<Contact> */
    public $nonauthor_contacts = [];

    #[\ReturnTypeWillChange]
    /** @return array{author_list:list<object>,author_cids:list<list<int>>,nonauthor_contacts?:list<object>} */
    function jsonSerialize() {
        $x = ["author_list" => [], "author_cids" => $this->author_cids];
        foreach ($this->author_list as $au) {
            $j = (object) $au->unparse_nea_json();
            if ($au->contactId > 0) {
                $j->contactId = $au->contactId;
            }
            $x["author_list"][] = $j;
        }
        foreach ($this->nonauthor_contacts as $au) {
            $j = (object) Author::unparse_nea_json_for($au);
            $j->contactId = $au->contactId;
            $x["nonauthor_contacts"][] = $j;
        }
        return $x;
    }
}

class PaperInfoPotentialConflict {
    /** @var int */
    public $order;
    /** @var AuthorMatcher */
    public $user;
    /** @var Author */
    public $cflt;

    const OA_NAME = 1 << 28;
    const OA_AUCOLLABORATOR = 2 << 28;
    const OA_AFFILIATION = 3 << 28;
    const OA_COLLABORATOR = 4 << 28;
    const OA_MASK = 7 << 28;

    const OB_NAME = 0;
    const OB_AFFILIATION = 1 << 24;
    const OB_NONAUTHOR = 2 << 24;
    const OB_COLLABORATOR = 15 << 24;
    const OB_CONTACT = 14 << 24;
    const OB_MASK = 15 << 24;

    const AUIDX_MASK = 0xFFFFFF;

    function __construct(AuthorMatcher $au, Author $cflt, $why) {
        $this->user = $au;
        $this->cflt = $cflt;
        if ($au->is_nonauthor()) {
            $this->order = self::OA_COLLABORATOR;
        } else if ($cflt->is_nonauthor()) {
            $this->order = self::OA_AUCOLLABORATOR;
        } else if ($why === AuthorMatcher::MATCH_AFFILIATION) {
            $this->order = self::OA_AFFILIATION;
        } else {
            $this->order = self::OA_NAME;
        }
        if ($cflt->author_index === Author::COLLABORATORS_INDEX) {
            $this->order |= self::OB_COLLABORATOR;
        } else if (($cflt->author_index ?? 0) <= 0) {
            $this->order |= self::OB_CONTACT;
        } else if ($cflt->is_nonauthor()) {
            $this->order |= self::OB_NONAUTHOR | $cflt->author_index;
        } else if ($why === AuthorMatcher::MATCH_AFFILIATION) {
            $this->order |= self::OB_AFFILIATION | $cflt->author_index;
        } else {
            $this->order |= self::OB_NAME | $cflt->author_index;
        }
    }

    /** @param PaperInfoPotentialConflict $a
     * @param PaperInfoPotentialConflict $b
     * @return int */
    static function compare($a, $b) {
        return ($a->order <=> $b->order)
            ? : strnatcasecmp($a->cflt->name(), $b->cflt->name());
    }

    /** @return ?int */
    function author_index() {
        if (($this->order & self::OB_MASK) < self::OB_CONTACT) {
            return $this->order & self::AUIDX_MASK;
        }
        return null;
    }

    /** @param Contact $user
     * @param PaperInfo $prow
     * @return array{string,string} */
    function unparse_html($user, $prow) {
        $cfltm = AuthorMatcher::make($this->cflt);

        $oa = $this->order & self::OA_MASK;
        if ($oa === self::OA_NAME) {
            $userdesc = $cfltm->highlight($this->user->name());
        } else if ($oa === self::OA_AUCOLLABORATOR) {
            $userdesc = $cfltm->highlight($this->user);
        } else if ($oa === self::OA_AFFILIATION) {
            $userdesc = "<em>affiliation</em> " . $cfltm->highlight($this->user->affiliation);
        } else {
            $userdesc = "<em>collaborator</em> " . $cfltm->highlight($this->user);
        }

        $ob = $this->order & self::OB_MASK;
        if ($ob === self::OB_NAME) {
            $cfltdesc = "<em>author</em> " . $this->user->highlight($this->cflt);
        } else if ($ob === self::OB_COLLABORATOR) {
            $cfltdesc = "<em>submission-listed collaborator</em> " . $this->user->highlight($this->cflt);
        } else if ($ob === self::OB_CONTACT) {
            $cfltdesc = "<em>contact"
                . ($this->cflt->email ? " " . htmlspecialchars($this->cflt->email) : "")
                . ($this->cflt->is_nonauthor() ? " collaborator" : "")
                . "</em> " . $this->user->highlight($this->cflt);
        } else if ($ob === self::OB_AFFILIATION) {
            $aucflt = $prow->author_by_index($this->order & self::AUIDX_MASK) ?? $this->cflt;
            $cfltdesc = "<em>author</em> " . $aucflt->name_h() . " (" . $this->user->highlight($this->cflt->affiliation) . ")";
        } else {
            $aucflt = $prow->author_by_index($this->order & self::AUIDX_MASK) ?? $this->user;
            $cfltdesc = "<em>author " . $aucflt->name_h() . "’s collaborator</em> " . $this->user->highlight($this->cflt);
        }

        return [$userdesc, $cfltdesc];
    }

    /** @param Contact $user
     * @param PaperInfo $prow
     * @return array{string,string} */
    function unparse_text($user, $prow) {
        $cfltm = AuthorMatcher::make($this->cflt);

        $oa = $this->order & self::OA_MASK;
        $userdesc = $user->name(NAME_EB | NAME_A);
        if ($oa === self::OA_COLLABORATOR) {
            $userdesc .= " collaborator " . $this->user->name(NAME_A);
        }

        $ob = $this->order & self::OB_MASK;
        if ($ob === self::OB_NAME) {
            $cfltdesc = "author " . $this->cflt->name(NAME_A);
        } else if ($ob === self::OB_COLLABORATOR) {
            $cfltdesc = "submission-listed collaborator " . $this->cflt->name(NAME_A);
        } else if ($ob === self::OB_CONTACT) {
            $cfltdesc = ($this->cflt->is_nonauthor() ? "contact collaborator " : "contact ")
                . $this->cflt->name(NAME_EB | NAME_A);
        } else if ($ob === self::OB_AFFILIATION) {
            $aucflt = $prow->author_by_index($this->order & self::AUIDX_MASK) ?? $this->cflt;
            $cfltdesc = "author " . $aucflt->name(NAME_A);
        } else {
            $aucflt = $prow->author_by_index($this->order & self::AUIDX_MASK) ?? $this->user;
            $cfltdesc = "author " . $aucflt->name(NAME_A) . " collaborator " . $this->cflt->name(NAME_A);
        }

        return [$userdesc, $cfltdesc];
    }
}

class PaperInfoPotentialConflictList {
    /** @var Contact */
    private $_user;
    /** @var list<PaperInfoPotentialConflict> */
    private $_pcs;
    /** @var list<int> */
    private $_auindexes;

    /** @param list<PaperInfoPotentialConflict> $pcs */
    function __construct(Contact $user, $pcs) {
        $this->_user = $user;
        $this->_pcs = $pcs;
        if (empty($pcs)) {
            $this->_auindexes = [];
            return;
        }
        usort($this->_pcs, "PaperInfoPotentialConflict::compare");
        $aus = [];
        foreach ($this->_pcs as $pc) {
            if (($i = $pc->author_index()) > 0)
                $aus[$i] = true;
        }
        ksort($aus);
        $this->_auindexes = array_keys($aus);
    }

    /** @return Contact */
    function user() {
        return $this->_user;
    }

    /** @return bool */
    function is_empty() {
        return empty($this->_pcs);
    }

    /** @return list<PaperInfoPotentialConflict> */
    function list() {
        return $this->_pcs;
    }

    /** @return string */
    function description_text() {
        if (empty($this->_pcs)) {
            return "";
        }
        $x = "Possible conflict";
        if (!empty($this->_auindexes)) {
            $x .= " with " . plural_word($this->_auindexes, "author") . " "
                . numrangejoin(array_map(function ($i) { return "#{$i}"; },
                               $this->_auindexes));
        }
        return $x;
    }

    /** @return string */
    function description_html() {
        $x = $this->description_text();
        if ($x !== "") {
            $x = "<div class=\"pcconfmatch\">{$x}</div>";
        }
        return $x;
    }

    /** @return list<non-empty-list<string>> */
    function group_list_html(PaperInfo $prow) {
        $last_ut = null;
        $m = [];
        foreach ($this->_pcs as $pc) {
            list($ut, $ct) = $pc->unparse_html($this->_user, $prow);
            if ($ut === $last_ut) {
                $m[count($m) - 1][] = $ct;
            } else {
                $m[] = [$ut, $ct];
                $last_ut = $ut;
            }
        }
        return $m;
    }

    /** @param non-empty-list<string> $g
     * @param ?string $prefix
     * @param ?string $extraclass
     * @return string */
    static function group_html_ul($g, $prefix = null, $extraclass = null) {
        $class = Ht::add_tokens("potentialconflict", $extraclass);
        return "<ul class=\"{$class}\"><li>" . ($prefix ?? "")
            . join("</li><li>", $g) . "</li></ul>";
    }

    /** @return string */
    function tooltip_html(PaperInfo $prow) {
        if (empty($this->_pcs)) {
            return "";
        }
        $x = "<ul class=\"x\">";
        foreach ($this->group_list_html($prow) as $g) {
            $x .= "<li>" . self::group_html_ul($g) . "</li>";
        }
        return $x . "</ul>";
    }
}
