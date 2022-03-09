<?php
// paperinfo.php -- HotCRP paper objects
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class PaperContactInfo {
    /** @var int
     * @readonly */
    public $paperId;
    /** @var int
     * @readonly */
    public $contactId;
    /** @var int */
    public $conflictType = 0;
    /** @var int */
    public $reviewType = 0;
    /** @var int */
    public $reviewSubmitted = 0;
    /** @var int */
    public $review_status = 0;    // 0 means no review
    const RS_DECLINED = 1;        // declined assigned review
    const RS_UNSUBMITTED = 2;     // review not submitted, needs submit
    const RS_PROXIED = 3;         // review proxied (e.g., lead)
    const RS_SUBMITTED = 4;       // review submitted

    /** @var ?bool */
    public $rights_forced = null;
    /** @var ?PaperContactInfo */
    private $forced_rights_link = null;

    // set by Contact::rights()
    /** @var bool */
    public $allow_administer;
    /** @var bool */
    public $can_administer;
    /** @var bool */
    public $primary_administrator;
    /** @var bool */
    public $allow_pc_broad;
    /** @var bool */
    public $allow_pc;
    /** @var bool */
    public $potential_reviewer;
    /** @var bool */
    public $allow_review;
    /** @var bool */
    public $allow_author_edit;
    /** @var int */
    public $view_conflict_type;
    /** @var bool */
    public $act_author_view;
    /** @var bool */
    public $allow_author_view;
    /** @var bool */
    public $can_view_decision;
    /** @var 0|1|2 */
    public $view_authors_state;
    /** @var ?string */
    public $perm_tags;

    // cached by PaperInfo methods
    /** @var ?list<ReviewInfo> */
    public $vreviews_array;
    /** @var ?int */
    public $vreviews_version;
    /** @var ?string */
    public $viewable_tags;
    /** @var ?string */
    public $searchable_tags;

    /** @param Contact $user
     * @suppress PhanAccessReadOnlyProperty */
    static function make_empty(PaperInfo $prow, $user) {
        $ci = new PaperContactInfo;
        $ci->paperId = $prow->paperId;
        $ci->contactId = $user->contactXid;
        if ($user->isPC
            && isset($prow->leadContactId)
            && $prow->leadContactId == $user->contactXid
            && !$prow->conf->setting("lead_noseerev")) {
            $ci->review_status = PaperContactInfo::RS_PROXIED;
        }
        return $ci;
    }

    /** @param Contact $user
     * @suppress PhanAccessReadOnlyProperty */
    static function make_my(PaperInfo $prow, $user, $object) {
        $ci = PaperContactInfo::make_empty($prow, $user);
        $ci->conflictType = (int) $object->conflictType;
        if (isset($object->myReviewPermissions)) {
            $ci->mark_my_review_permissions($object->myReviewPermissions);
        } else if ($object instanceof PaperInfo
                   && $object->reviewSignatures !== null) {
            foreach ($object->reviews_by_user($user->contactId, $user->review_tokens()) as $rrow) {
                $ci->mark_review($rrow);
            }
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
            if ($rs > 0 || $rns == 0) {
                $this->review_status = PaperContactInfo::RS_SUBMITTED;
            } else if ($this->review_status == 0) {
                $this->review_status = PaperContactInfo::RS_UNSUBMITTED;
            }
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

    /** @param Contact $user */
    static function load_into(PaperInfo $prow, $user) {
        $conf = $prow->conf;
        $pid = $prow->paperId;
        $q = "select conflictType, reviewType, reviewSubmitted, reviewNeedsSubmit";
        $cid = $user->contactXid;
        $rev_tokens = $user->review_tokens();
        if ($cid > 0
            && !$rev_tokens
            && ($row_set = $prow->_row_set)
            && $row_set->size() > 1) {
            $result = $conf->qe("$q, Paper.paperId paperId, ? contactId
                from Paper
                left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=?)
                left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=?)
                where Paper.paperId?a",
                $cid, $cid, $cid, $row_set->paper_ids());
            foreach ($row_set as $row) {
                $row->_clear_contact_info($user);
            }
            while (($local = $result->fetch_row())) {
                $row = $row_set->get((int) $local[4]);
                $ci = $row->_get_contact_info((int) $local[5]);
                $ci->mark_conflict((int) $local[0]);
                $ci->mark_review_type((int) $local[1], (int) $local[2], (int) $local[3]);
            }
            Dbl::free($result);
            return;
        }
        if ($cid > 0
            && !$rev_tokens
            && (!($viewer = Contact::$main_user)
                || ($viewer->contactId != $cid
                    && ($viewer->privChair || $viewer->contactXid === $prow->managerContactId)))
            && ($pcm = $conf->pc_members())
            && isset($pcm[$cid])) {
            foreach ($pcm as $u) {
                $prow->_clear_contact_info($u);
            }
            $result = $conf->qe("$q, ContactInfo.contactId
                from ContactInfo
                left join PaperConflict on (PaperConflict.paperId=? and PaperConflict.contactId=ContactInfo.contactId)
                left join PaperReview on (PaperReview.paperId=? and PaperReview.contactId=ContactInfo.contactId)
                where roles!=0 and (roles&" . Contact::ROLE_PC . ")!=0",
                $pid, $pid);
        } else {
            $prow->_clear_contact_info($user);
            if ($cid > 0 || $rev_tokens) {
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
            } else {
                $result = null;
            }
        }
        while ($result && ($local = $result->fetch_row())) {
            $ci = $prow->_get_contact_info((int) $local[4]);
            $ci->mark_conflict((int) $local[0]);
            $ci->mark_review_type((int) $local[1], (int) $local[2], (int) $local[3]);
        }
        Dbl::free($result);
    }

    /** @return PaperContactInfo */
    function get_forced_rights() {
        if (!$this->forced_rights_link) {
            $ci = $this->forced_rights_link = clone $this;
            $ci->vreviews_array = $ci->viewable_tags = $ci->searchable_tags = null;
        }
        return $this->forced_rights_link;
    }

    /** @param string $perm
     * @return ?bool */
    function perm_tag_allows($perm) {
        if ($this->perm_tags !== null
            && ($pos = stripos($this->perm_tags, " perm:$perm#")) !== false) {
            return $this->perm_tags[$pos + strlen($perm) + 7] !== "-";
        } else {
            return null;
        }
    }
}

class PaperInfoSet implements ArrayAccess, IteratorAggregate, Countable {
    /** @var list<PaperInfo> */
    private $prows = [];
    /** @var array<int,PaperInfo> */
    private $by_pid = [];
    /** @var bool */
    private $_need_pid_sort = false;
    /** @var int */
    public $loaded_allprefs = 0;

    function __construct(PaperInfo $prow = null) {
        if ($prow) {
            $this->add($prow, true);
        }
    }
    /** @param bool $copy */
    function add(PaperInfo $prow, $copy = false) {
        $this->prows[] = $prow;
        if (!isset($this->by_pid[$prow->paperId])) {
            $this->by_pid[$prow->paperId] = $prow;
        }
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
        $this->_need_pid_sort = true;
    }
    /** @return list<int> */
    function paper_ids() {
        if ($this->_need_pid_sort) {
            $by_pid = [];
            foreach ($this->prows as $prow) {
                if (!isset($by_pid[$prow->paperId]))
                    $by_pid[$prow->paperId] = $this->by_pid[$prow->paperId];
            }
            $this->by_pid = $by_pid;
            $this->_need_pid_sort = false;
        }
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
            throw new Exception("PaperInfoSet::checked_paper_by_id($pid) failure");
        }
        return $prow;
    }
    /** @param int $pid
     * @return ?PaperInfo */
    function get($pid) {
        return $this->by_pid[$pid] ?? null;
    }
    /** @param callable(PaperInfo):bool $func
     * @return PaperInfoSet|Iterable<PaperInfo> */
    function filter($func) {
        $next_set = new PaperInfoSet;
        foreach ($this->prows as $prow) {
            if (call_user_func($func, $prow))
                $next_set->add($prow, true);
        }
        return $next_set;
    }
    /** @param callable(PaperInfo):bool $func */
    function apply_filter($func) {
        $nprows = $by_pid = [];
        foreach ($this->prows as $prow) {
            if (call_user_func($func, $prow)) {
                $nprows[] = $prow;
                if (!isset($by_pid[$prow->paperId])) {
                    $by_pid[$prow->paperId] = $prow;
                }
            }
        }
        $this->prows = $nprows;
        $this->by_pid = $by_pid;
        $this->_need_pid_sort = false;
    }
    /** @param callable(PaperInfo):bool $func */
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
    #[\ReturnTypeWillChange]
    function offsetExists($offset) {
        return isset($this->by_pid[$offset]);
    }
    #[\ReturnTypeWillChange]
    /** @return ?PaperInfo */
    function offsetGet($offset) {
        return $this->by_pid[$offset] ?? null;
    }
    #[\ReturnTypeWillChange]
    function offsetSet($offset, $value) {
        throw new Exception("invalid PaperInfoSet::offsetSet");
    }
    #[\ReturnTypeWillChange]
    function offsetUnset($offset) {
        throw new Exception("invalid PaperInfoSet::offsetUnset");
    }
    function ensure_full_reviews() {
        if (!empty($this->prows)) {
            $this->prows[0]->ensure_full_reviews();
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
    public $paperId = 0;
    /** @var int
     * @readonly */
    public $paperXid;      // unique among all PaperInfos
    /** @var int */
    public $timeSubmitted = 0;
    /** @var int */
    public $timeWithdrawn = 0;
    /** @var int */
    public $outcome = 0;
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
    public $timeFinalSubmitted;
    /** @var ?string */
    public $withdrawReason;
    /** @var ?int */
    public $shepherdContactId;
    /** @var ?int */
    public $paperFormat;
    /** @var ?string */
    public $capVersion;
    /** @var ?array<string,mixed> */
    public $dataOverflow;

    /** @var ?int */
    public $paperStorageId;
    /** @var ?int */
    public $finalPaperStorageId;
    /** @var ?string */
    public $pdfFormatStatus;
    /** @var null|int|string */
    public $size;
    /** @var ?string */
    public $mimetype;
    /** @var ?string */
    public $timestamp;
    /** @var ?string */
    public $sha1;

    // Obtained by joins from other tables
    /** @var ?string */
    public $paper_infoJson;
    /** @var ?string */
    public $final_infoJson;

    /** @var ?string */
    public $paperTags;
    /** @var ?string */
    public $optionIds;
    /** @var ?string */
    public $topicIds;
    /** @var ?string */
    public $allConflictType;
    /** @var ?string */
    public $myReviewerPreference;
    /** @var ?string */
    public $myReviewerExpertise;
    /** @var ?string */
    public $allReviewerPreference;

    /** @var ?string */
    public $myReviewPermissions;
    /** @var ?string */
    public $conflictType;
    /** @var ?int */
    public $watch;
    /** @var ?int */
    private $_watch_cid;

    /** @var ?string */
    public $reviewSignatures;
    /** @var ?string */
    public $reviewWordCountSignature;
    /** @var ?string */
    public $overAllMeritSignature;
    /** @var ?string */
    public $reviewerQualificationSignature;
    /** @var ?string */
    public $noveltySignature;
    /** @var ?string */
    public $technicalMeritSignature;
    /** @var ?string */
    public $interestToCommunitySignature;
    /** @var ?string */
    public $longevitySignature;
    /** @var ?string */
    public $grammarSignature;
    /** @var ?string */
    public $likelyPresentationSignature;
    /** @var ?string */
    public $suitableForShortSignature;
    /** @var ?string */
    public $potentialSignature;
    /** @var ?string */
    public $fixabilitySignature;

    /** @var ?string */
    public $commentSkeletonInfo;

    // Not in database
    /** @var ?PaperInfoSet */
    public $_row_set;
    /** @var array<int,PaperContactInfo> */
    private $_contact_info = [];
    /** @var int */
    private $_rights_version = 0;
    /** @var ?list<Author> */
    private $_author_array;
    /** @var ?list<AuthorMatcher> */
    private $_collaborator_array;
    /** @var ?array<int,array{int,?int}> */
    private $_prefs_array;
    /** @var ?int */
    private $_pref1_cid;
    /** @var ?array{int,?int} */
    private $_pref1;
    /** @var ?int */
    private $_desirability;
    /** @var ?list<int> */
    private $_topics_array;
    /** @var ?array<int,float> */
    private $_topic_interest_score_array;
    /** @var ?array<int,list<int>> */
    private $_option_values;
    /** @var ?array<int,list<?string>> */
    private $_option_data;
    /** @var array<int,?PaperValue> */
    private $_option_array = [];
    /** @var ?array<int,PaperValue> */
    private $_base_option_array;
    /** @var array<int,DocumentInfo> */
    private $_document_array;
    /** @var ?array<int,array<int,int>> */
    private $_doclink_array;
    /** @var ?array<int,Author> */
    private $_conflict_array;
    /** @var bool */
    private $_conflict_array_email;
    /** @var ?Contact */
    private $_paper_creator;
    /** @var ?array<int,ReviewInfo> */
    private $_review_array;
    /** @var int */
    private $_review_array_version = 0;
    /** @var int */
    private $_reviews_flags = 0;
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
    /** @var ?list<array{string,string}> */
    private $_potential_conflicts;
    /** @var int */
    private $_potential_conflict_flags;
    /** @var ?list<ReviewRequestInfo> */
    private $_request_array;
    /** @var ?list<ReviewRefusalInfo> */
    private $_refusal_array;
    /** @var ?array<int,int> */
    private $_watch_array;
    /** @var ?TokenInfo */
    public $_author_view_token;
    /** @var ?Contact */
    private $_author_view_user;
    /** @var ?int */
    public $_sort_subset;
    /** @var ?bool */
    private $_allow_absent;
    /** @var ?int */
    private $_pause_mark_inactive_documents;

    /** @var ?array */
    public $anno;

    const REVIEW_HAS_FULL = 1;
    const REVIEW_HAS_NAMES = 2;
    const REVIEW_HAS_LASTLOGIN = 4;
    const REVIEW_HAS_WORDCOUNT = 8;

    const SUBMITTED_AT_FOR_WITHDRAWN = 1000000000;
    static private $next_uid = 0;

    /** @param Conf $conf */
    private function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->paperXid = ++self::$next_uid;
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
        if (isset($this->dataOverflow) && is_string($this->dataOverflow)) {
            $this->dataOverflow = json_decode($this->dataOverflow, true);
            if ($this->dataOverflow === null) {
                error_log("{$this->conf->dbname}: #{$this->paperId}: bad dataOverflow");
            }
        }
    }

    /** @param Contact $user */
    private function incorporate_user($user) {
        assert($this->conf === $user->conf);
        if ($this->myReviewPermissions !== null
            || $this->reviewSignatures !== null) {
            $this->_rights_version = Contact::$rights_version;
            $this->load_my_contact_info($user, $this);
        } else {
            assert($this->conflictType === null);
        }
        if ($this->myReviewerPreference !== null) {
            $re = $this->myReviewerExpertise;
            $this->_pref1 = [(int) $this->myReviewerPreference, $re === null ? $re : (int) $re];
            $this->_pref1_cid = $user->contactId;
        }
        if ($this->watch !== null) {
            $this->watch = (int) $this->watch;
            $this->_watch_cid = $user->contactId;
        }
    }

    /** @param Dbl_Result $result
     * @param ?Contact $user
     * @return ?PaperInfo */
    static function fetch($result, $user, Conf $conf = null) {
        if (($prow = $result->fetch_object("PaperInfo", [$conf ?? $user->conf]))) {
            $prow->incorporate();
            $user && $prow->incorporate_user($user);
        }
        return $prow;
    }

    /** @param int $paperId
     * @return PaperInfo
     * @suppress PhanAccessReadOnlyProperty */
    static function make_placeholder(Conf $conf, $paperId) {
        $prow = new PaperInfo($conf);
        $prow->paperId = $paperId;
        return $prow;
    }

    /** @return PaperInfo */
    static function make_new(Contact $user) {
        $prow = new PaperInfo($user->conf);
        $prow->abstract = $prow->title = $prow->collaborators =
            $prow->authorInformation = $prow->paperTags = $prow->optionIds =
            $prow->topicIds = "";
        $prow->shepherdContactId = 0;
        $prow->blind = true;
        $prow->_paper_creator = $user;
        $prow->check_rights_version();
        $ci = PaperContactInfo::make_empty($prow, $user);
        $ci->conflictType = CONFLICT_CONTACTAUTHOR;
        $prow->_contact_info[$user->contactXid] = $ci;
        $prow->_comment_skeleton_array = $prow->_comment_array = [];
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
        $prow->conflictType = "0";
        $prow->myReviewPermissions = "{$rtype} 1 0";
        $prow->incorporate_user($user);
        return $prow;
    }

    /** @param string $prefix
     * @return string */
    static function my_review_permissions_sql($prefix = "") {
        return "group_concat({$prefix}reviewType, ' ', coalesce({$prefix}reviewSubmitted,0), ' ', reviewNeedsSubmit)";
    }

    /** @return PermissionProblem */
    function make_whynot($rest = []) {
        $pp = new PermissionProblem($this->conf, ["paperId" => $this->paperId]);
        return $pp->merge($rest);
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
        if ($this->_rights_version !== Contact::$rights_version) {
            if ($this->_rights_version) {
                $this->_reviews_flags = 0;
                $this->_contact_info = [];
                $this->reviewSignatures = $this->_review_array = $this->allConflictType = $this->_conflict_array = $this->_reviews_have = null;
                ++$this->_review_array_version;
            }
            $this->_rights_version = Contact::$rights_version;
        }
    }

    function update_rights() {
        $this->_rights_version = -1;
        Contact::update_rights();
    }

    /** @param int $cid
     * @return PaperContactInfo */
    function _get_contact_info($cid) {
        return $this->_contact_info[$cid];
    }

    /** @param Contact $user */
    function _clear_contact_info($user) {
        $this->_contact_info[$user->contactXid] = PaperContactInfo::make_empty($this, $user);
    }

    /** @return PaperContactInfo */
    function contact_info(Contact $user) {
        $this->check_rights_version();
        $cid = $user->contactXid;
        if (!array_key_exists($cid, $this->_contact_info)) {
            if ($this->_review_array
                || $this->reviewSignatures !== null) {
                $ci = PaperContactInfo::make_empty($this, $user);
                if (($c = ($this->conflicts())[$cid] ?? null)) {
                    $ci->conflictType = $c->conflictType;
                }
                foreach ($this->reviews_by_user($cid, $user->review_tokens()) as $rrow) {
                    $ci->mark_review($rrow);
                }
                $this->_contact_info[$cid] = $ci;
            } else {
                PaperContactInfo::load_into($this, $user);
            }
        }
        return $this->_contact_info[$cid];
    }

    function load_my_contact_info($contact, $object) {
        $ci = PaperContactInfo::make_my($this, $contact, $object);
        $this->_contact_info[$ci->contactId] = $ci;
    }

    /** @return Contact */
    function author_view_user() {
        if (!$this->_author_view_user) {
            $this->_author_view_user = Contact::make($this->conf);
            $this->_author_view_user->set_capability("@av{$this->paperId}", true);
        }
        return $this->_author_view_user;
    }


    /** @return bool */
    function allow_absent() {
        return !!$this->_allow_absent;
    }

    /** @param bool $allow_absent */
    function set_allow_absent($allow_absent) {
        assert(!$allow_absent || $this->paperId === 0);
        $this->_allow_absent = $allow_absent;
    }


    /** @return int */
    function format_of($text, $check_simple = false) {
        return $this->conf->check_format($this->paperFormat, $check_simple ? $text : null);
    }

    /** @return int */
    function title_format() {
        return $this->format_of($this->title, true);
    }

    /** @return string */
    function abstract_text() {
        if ($this->dataOverflow && isset($this->dataOverflow["abstract"])) {
            return $this->dataOverflow["abstract"];
        } else {
            return $this->abstract ?? "";
        }
    }

    /** @return int */
    function abstract_format() {
        return $this->format_of($this->abstract_text(), true);
    }

    function edit_format() {
        return $this->conf->format_info($this->paperFormat);
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

    /** @param list<Author> $aulist
     * @param string $email
     * @return ?Author */
    static function search_author_list_by_email($aulist, $email) {
        foreach ($aulist as $au) {
            if ($au->email !== "" && strcasecmp($au->email, $email) === 0)
                return $au;
        }
        return null;
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
        return self::search_author_list_by_email($this->author_list(), $email);
    }

    /** @param Contact|int $contact
     * @return int */
    function conflict_type($contact) {
        $cid = self::contact_to_cid($contact);
        if (array_key_exists($cid, $this->_contact_info)) {
            return $this->_contact_info[$cid]->conflictType;
        } else if (($ci = (($this->conflicts())[$cid] ?? null))) {
            return $ci->conflictType;
        } else {
            return 0;
        }
    }

    /** @param string $email
     * @return int */
    function conflict_type_by_email($email) {
        foreach ($this->conflicts(true) as $cflt) {
            if (strcasecmp($cflt->email, $email) === 0)
                return $cflt->conflictType;
        }
        return 0;
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
        if ($this->dataOverflow && isset($this->dataOverflow["collaborators"])) {
            return $this->dataOverflow["collaborators"];
        } else {
            return $this->collaborators ?? "";
        }
    }

    /** @return list<AuthorMatcher> */
    function collaborator_list() {
        if ($this->_collaborator_array === null) {
            $this->_collaborator_array = [];
            foreach (Contact::make_collaborator_generator($this->collaborators()) as $m) {
                $this->_collaborator_array[] = $m;
            }
        }
        return $this->_collaborator_array;
    }

    /** @return bool */
    function has_nonempty_collaborators() {
        $collab = $this->collaborators();
        return $collab !== "" && strcasecmp($collab, "none") !== 0;
    }

    /** @param ?callable(Contact,AuthorMatcher,Author,int,string) $callback
     * @return bool */
    function potential_conflict_callback(Contact $user, $callback) {
        $nproblems = $auproblems = 0;
        if ($this->field_match_pregexes($user->aucollab_general_pregexes(), "authorInformation")) {
            foreach ($this->author_list() as $n => $au) {
                foreach ($user->aucollab_matchers() as $matcher) {
                    if (($why = $matcher->test($au, $matcher->nonauthor))) {
                        if (!$callback) {
                            return true;
                        }
                        $auproblems |= $why;
                        ++$nproblems;
                        call_user_func($callback, $user, $matcher, $au, $n + 1, $why);
                    }
                }
            }
        }
        if (($collab = $this->collaborators()) !== "") {
            $aum = $user->full_matcher();
            if (Text::match_pregexes($aum->general_pregexes(), $collab, UnicodeHelper::deaccent($collab))) {
                foreach ($this->collaborator_list() as $co) {
                    if (($co->lastName !== ""
                         || !($auproblems & AuthorMatcher::MATCH_AFFILIATION))
                        && ($why = $aum->test($co, true))) {
                        if (!$callback) {
                            return true;
                        }
                        ++$nproblems;
                        call_user_func($callback, $user, $aum, $co, 0, $why);
                    }
                }
            }
        }
        return $nproblems > 0;
    }

    /** @return bool */
    function potential_conflict(Contact $user) {
        return $this->potential_conflict_callback($user, null);
    }

    /** @param Contact $user
     * @param AuthorMatcher $matcher
     * @param Author $conflict
     * @param int $aunum
     * @param string $why */
    function _potential_conflict_html_callback($user, $matcher, $conflict, $aunum, $why) {
        if ($aunum && $matcher->nonauthor) {
            $matchdesc = "collaborator";
        } else if ($why === AuthorMatcher::MATCH_AFFILIATION) {
            $matchdesc = "affiliation";
            if (!($this->_potential_conflict_flags & 1)) {
                $matchdesc .= " (" . htmlspecialchars($user->affiliation) . ")";
                $this->_potential_conflict_flags |= 1;
            }
        } else {
            $matchdesc = "name";
        }
        if ($aunum) {
            if ($matcher->nonauthor) {
                $aumatcher = new AuthorMatcher($conflict);
                $what = "$matchdesc " . $aumatcher->highlight($matcher) . "<br>matches author #$aunum " . $matcher->highlight($conflict);
            } else if ($why == AuthorMatcher::MATCH_AFFILIATION) {
                $what = "$matchdesc matches author #$aunum affiliation " . $matcher->highlight($conflict->affiliation);
            } else {
                $what = "$matchdesc matches author #$aunum name " . $matcher->highlight($conflict->name());
            }
            $this->_potential_conflicts[] = ["#$aunum", $what];
        } else {
            $what = "$matchdesc matches paper collaborator ";
            $this->_potential_conflicts[] = ["other conflicts", $what . $matcher->highlight($conflict)];
        }
    }

    /** @return false|array{string,list<string>} */
    function potential_conflict_html(Contact $user, $highlight = false) {
        $this->_potential_conflicts = [];
        $this->_potential_conflict_flags = 0;
        if (!$this->potential_conflict_callback($user, [$this, "_potential_conflict_html_callback"])) {
            return false;
        }
        usort($this->_potential_conflicts, function ($a, $b) { return strnatcmp($a[0], $b[0]); });
        $authors = array_unique(array_map(function ($x) { return $x[0]; }, $this->_potential_conflicts));
        $authors = array_filter($authors, function ($f) { return $f !== "other conflicts"; });
        $messages = array_map(function ($x) { return $x[1]; }, $this->_potential_conflicts);
        $this->_potential_conflicts = null;
        return ['<div class="pcconfmatch'
            . ($highlight ? " pcconfmatch-highlight" : "")
            . '">Possible conflict'
            . (empty($authors) ? "" : " with " . pluralx($authors, "author") . " " . numrangejoin($authors))
            . '…</div>', $messages];
    }

    static function potential_conflict_tooltip_html($potconf) {
        return $potconf ? '<ul class="x"><li>' . join('</li><li>', $potconf[1]) . '</li></ul>' : '';
    }


    /** @return int */
    function submitted_at() {
        if ($this->timeSubmitted > 0) {
            return $this->timeSubmitted;
        } else if ($this->timeWithdrawn > 0) {
            if ($this->timeSubmitted == -100) {
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
            $u = $this->conf->cached_user_by_id($this->managerContactId);
            return $u ? [$u] : [];
        }

        $chairs = true;
        if ($this->conf->check_track_admin_sensitivity()) {
            foreach ($this->conf->track_tags() as $ttag) {
                if ($this->conf->track_permission($ttag, Track::ADMIN)
                    && $this->has_tag($ttag)) {
                    $chairs = false;
                    break;
                }
            }
        }

        $as = $cas = [];
        foreach ($chairs ? $this->conf->pc_chairs() : $this->conf->pc_members() as $u) {
            if ($u->is_primary_administrator($this)) {
                if ($u->can_administer($this)) {
                    $as[] = $u;
                } else {
                    $cas[] = $u;
                }
            }
        }
        return empty($as) ? $cas : $as;
    }


    /** @return string|false */
    private function deaccented_field($field) {
        $data = $this->$field;
        if ((string) $data !== "") {
            $field_deaccent = $field . "_deaccent";
            if (!isset($this->$field_deaccent)) {
                if (is_usascii($data)) {
                    $this->$field_deaccent = false;
                } else {
                    $this->$field_deaccent = UnicodeHelper::deaccent($data);
                }
            }
            return $this->$field_deaccent;
        } else {
            return false;
        }
    }

    /** @param TextPregexes $reg
     * @return bool */
    function field_match_pregexes($reg, $field) {
        return Text::match_pregexes($reg, $this->$field, $this->deaccented_field($field));
    }


    /** @return bool */
    function can_author_view_submitted_review() {
        if ($this->can_author_respond()) {
            return true;
        } else if ($this->conf->au_seerev == Conf::AUSEEREV_TAGS) {
            return $this->has_any_tag($this->conf->tag_au_seerev);
        } else {
            return $this->conf->au_seerev != 0;
        }
    }

    /** @return bool */
    function can_author_respond() {
        if ($this->conf->any_response_open === 2) {
            return true;
        } else if ($this->conf->any_response_open) {
            foreach ($this->conf->response_rounds() as $rrd) {
                if ($rrd->time_allowed(true)
                    && (!$rrd->search || $rrd->search->test($this))) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return bool */
    function can_author_view_decision() {
        return $this->outcome != 0
            && $this->conf->time_all_author_view_decision();
    }

    /** @return bool */
    function can_author_edit_paper() {
        return $this->timeWithdrawn <= 0
            && $this->outcome >= 0
            && ($this->conf->time_edit_paper($this)
                || $this->perm_tag_allows("author-write"));
    }

    /** @return bool */
    function can_author_edit_final_paper() {
        return $this->timeWithdrawn <= 0
            && $this->outcome > 0
            && $this->can_author_view_decision()
            && ($this->conf->time_edit_final_paper()
                || $this->perm_tag_allows("author-write"));
    }


    /** @return int */
    function review_type($contact) {
        $this->check_rights_version();
        if (is_object($contact) && $contact->has_capability()) {
            $ci = $this->contact_info($contact);
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

    /** @return bool */
    function has_reviewer($contact) {
        return $this->review_type($contact) > 0;
    }

    /** @return bool */
    function review_not_incomplete($contact) {
        $ci = $this->contact_info($contact);
        return $ci && $ci->review_status > PaperContactInfo::RS_UNSUBMITTED;
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
            && stripos($this->paperTags, " $tag#") !== false;
    }

    /** @return bool */
    function has_any_tag($tags) {
        if ($this->paperTags === null) {
            $this->load_tags();
        }
        if ($this->paperTags !== "") {
            foreach ($tags as $tag) {
                if (stripos($this->paperTags, " $tag#") !== false)
                    return true;
            }
        }
        return false;
    }

    /** @return bool */
    function has_viewable_tag($tag, Contact $user) {
        $tags = $this->viewable_tags($user);
        return $tags !== "" && stripos(" " . $tags, " $tag#") !== false;
    }

    /** @param string $tag
     * @return ?float */
    function tag_value($tag) {
        if ($this->paperTags === null) {
            $this->load_tags();
        }
        if ($this->paperTags !== ""
            && ($pos = stripos($this->paperTags, " $tag#")) !== false) {
            return (float) substr($this->paperTags, $pos + strlen($tag) + 2);
        } else {
            return null;
        }
    }

    /** @param string $perm
     * @return ?bool */
    function perm_tag_allows($perm) {
        if ($this->paperTags !== null
            && $this->paperTags !== ""
            && ($pos = stripos($this->paperTags, " perm:$perm#")) !== false) {
            return $this->paperTags[$pos + strlen($perm) + 7] !== "-";
        } else {
            return null;
        }
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
                if ($tag !== "" && $user->can_edit_tag($this, Tagger::base($tag), 0, 1))
                    $etags[] = $tag;
            }
            $tags = join(" ", $etags);
        }
        return $tags;
    }

    /** @param TagMessageReport $pj */
    private function _add_override_tag_info_json($pj, $viewable, $viewable_c, Contact $user) {
        $tagger = new Tagger($user);
        $pj->tags = Tagger::split($viewable);
        $pj->tags_conflicted = Tagger::split($viewable_c);
        if (($decor = $tagger->unparse_decoration_html($viewable))) {
            $decor_c = $tagger->unparse_decoration_html($viewable_c);
            if ($decor !== $decor_c) {
                $pj->tag_decoration_html = str_replace('class="tagdecoration"', 'class="tagdecoration fn5"', $decor_c)
                    .  str_replace('class="tagdecoration"', 'class="tagdecoration fx5"', $decor);
            } else {
                $pj->tag_decoration_html = $decor;
            }
        }
        $tagmap = $this->conf->tags();
        $pj->color_classes = $tagmap->color_classes($viewable);
        if ($pj->color_classes
            && ($color_classes_c = $tagmap->color_classes($viewable_c)) !== $pj->color_classes) {
            $pj->color_classes_conflicted = $color_classes_c;
        }
    }

    /** @param TagMessageReport $pj */
    function add_tag_info_json($pj, Contact $user) {
        $viewable = $this->sorted_viewable_tags($user);
        $tagger = new Tagger($user);
        $pj->tags_edit_text = $tagger->unparse($this->sorted_editable_tags($user));
        $pj->tags_view_html = $tagger->unparse_link($viewable);
        if ($user->has_overridable_conflict($this) && $this->all_tags_text() !== "") {
            $old_overrides = $user->set_overrides($user->overrides() ^ Contact::OVERRIDE_CONFLICT);
            $viewable2 = $this->sorted_viewable_tags($user);
            $user->set_overrides($old_overrides);
            if ($viewable !== $viewable2) {
                if ($old_overrides & Contact::OVERRIDE_CONFLICT) {
                    $this->_add_override_tag_info_json($pj, $viewable, $viewable2, $user);
                } else {
                    $this->_add_override_tag_info_json($pj, $viewable2, $viewable, $user);
                }
                return;
            }
        }
        $pj->tags = Tagger::split($viewable);
        if (($decor = $tagger->unparse_decoration_html($viewable))) {
            $pj->tag_decoration_html = $decor;
        }
        $pj->color_classes = $this->conf->tags()->color_classes($viewable);
    }


    private function load_topics() {
        $row_set = $this->_row_set ?? new PaperInfoSet($this);
        foreach ($row_set as $prow) {
            $prow->topicIds = "";
        }
        if ($this->conf->has_topics()) {
            $result = $this->conf->qe("select paperId, group_concat(topicId) from PaperTopic where paperId?a group by paperId", $row_set->paper_ids());
            while ($result && ($row = $result->fetch_row())) {
                $prow = $row_set->get((int) $row[0]);
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
        if ($this->_topics_array === null) {
            if ($this->topicIds === null) {
                $this->load_topics();
            }
            $this->_topics_array = [];
            if ($this->topicIds !== "") {
                foreach (explode(",", $this->topicIds) as $t) {
                    $this->_topics_array[] = (int) $t;
                }
                $this->conf->topic_set()->sort($this->_topics_array);
            }
        }
        return $this->_topics_array;
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
        $score = 0;
        if (is_int($contact)) {
            $contact = ($this->conf->pc_members())[$contact] ?? null;
        }
        if ($contact) {
            if ($this->_topic_interest_score_array === null) {
                $this->_topic_interest_score_array = [];
            }
            if (isset($this->_topic_interest_score_array[$contact->contactId])) {
                $score = $this->_topic_interest_score_array[$contact->contactId];
            } else {
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
                if ($score) {
                    // * Strong interest in the paper's single topic gets
                    //   score 10.
                    $score = (int) ($score / sqrt(count($topics)) * 10 + 0.5);
                }
                $this->_topic_interest_score_array[$contact->contactId] = $score;
            }
        }
        return $score;
    }

    function invalidate_topics() {
        $this->topicIds = $this->_topics_array = $this->_topic_interest_score_array = null;
    }


    /** @param bool $email */
    function load_conflicts($email) {
        if (!$email && $this->allConflictType !== null) {
            $this->_conflict_array = [];
            $this->_conflict_array_email = $email;
            if ($this->allConflictType !== "") {
                foreach (explode(",", $this->allConflictType) as $x) {
                    list($cid, $ctype) = explode(" ", $x);
                    $cflt = new Author;
                    $cflt->paperId = $this->paperId;
                    $cflt->contactId = (int) $cid;
                    $cflt->conflictType = (int) $ctype;
                    $this->_conflict_array[$cflt->contactId] = $cflt;
                }
            }
        } else if ($this->paperId === 0 && $this->_paper_creator) {
            $cflt = new Author($this->_paper_creator);
            $cflt->paperId = $this->paperId;
            $cflt->contactId = $this->_paper_creator->contactId;
            $cflt->conflictType = CONFLICT_CONTACTAUTHOR;
            $this->_conflict_array = [$cflt->contactId => $cflt];
            $this->_conflict_array_email = true;
        } else {
            $row_set = $this->_row_set ?? new PaperInfoSet($this);
            foreach ($row_set as $prow) {
                $prow->_conflict_array = [];
                $prow->_conflict_array_email = $email;
            }
            if ($email) {
                $result = $this->conf->qe("select paperId, PaperConflict.contactId, conflictType, firstName, lastName, affiliation, email from PaperConflict join ContactInfo using (contactId) where paperId?a", $row_set->paper_ids());
            } else {
                $result = $this->conf->qe("select paperId, contactId, conflictType, '' firstName, '' lastName, '' affiliation, '' email from PaperConflict where paperId?a", $row_set->paper_ids());
            }
            while ($result && ($row = $result->fetch_object("Author"))) {
                $row->paperId = (int) $row->paperId;
                $row->contactId = (int) $row->contactId;
                $row->conflictType = (int) $row->conflictType;
                $prow = $row_set->get($row->paperId);
                $prow->_conflict_array[$row->contactId] = $row;
            }
            Dbl::free($result);
        }
    }

    /** @param bool $email
     * @return associative-array<int,Author> */
    function conflicts($email = false) {
        if ($this->_conflict_array === null
            || ($email && !$this->_conflict_array_email)) {
            $this->load_conflicts($email);
        }
        return $this->_conflict_array;
    }

    /** @param bool $email
     * @return associative-array<int,Author> */
    function pc_conflicts($email = false) {
        return array_intersect_key($this->conflicts($email), $this->conf->pc_members());
    }

    /** @return associative-array<int,int> */
    function conflict_types() {
        $ct = [];
        foreach ($this->conflicts() as $cflt) {
            $ct[$cflt->contactId] = $cflt->conflictType;
        }
        return $ct;
    }

    function invalidate_conflicts() {
        $this->allConflictType = $this->_conflict_array = null;
    }


    /** @return associative-array<int,Author> */
    function contacts($email = false) {
        $c = [];
        foreach ($this->conflicts($email) as $id => $cflt) {
            if ($cflt->conflictType >= CONFLICT_AUTHOR)
                $c[$id] = $cflt;
        }
        return $c;
    }

    /** @return PaperInfoLikelyContacts */
    function likely_contacts() {
        $contacts = $this->contacts(true);
        $lc = new PaperInfoLikelyContacts;
        $uanames = [];
        foreach ($this->author_list() as $au) {
            $lc->author_list[] = $au;
            $lc->author_cids[] = [];
            $uanames[] = trim(preg_replace('/[-\s.,;:]+/', ' ', UnicodeHelper::deaccent($au->name())));
        }
        foreach ($contacts as $cflt) {
            $nm_full = $nm_uaname = $nm_last = $nm_email = $fulli = $uanamei = $lasti = $emaili = 0;
            if ($cflt->email !== "") {
                foreach ($lc->author_list as $i => $au) {
                    if (strcasecmp($cflt->email, $au->email) === 0) {
                        ++$nm_email;
                        $emaili = $i;
                    }
                }
            }
            if ($nm_email !== 1 && ($cflt_name = $cflt->name()) !== "") {
                $cflt_uaname = trim(preg_replace('/[-\s.,;:]+/', ' ', UnicodeHelper::deaccent($cflt_name)));
                foreach ($lc->author_list as $i => $au) {
                    if (strcasecmp($cflt_name, $au->name()) === 0) {
                        ++$nm_full;
                        $fulli = $i;
                    } else if ($cflt_uaname !== ""
                               && strcasecmp($cflt_uaname, $uanames[$i]) === 0) {
                        ++$nm_uaname;
                        $uanamei = $i;
                    } else if ($cflt->lastName !== ""
                               && strcasecmp($cflt->lastName, $au->lastName) === 0) {
                        ++$nm_last;
                        $lasti = $i;
                    }
                }
            }
            if ($nm_email === 1) {
                $lc->author_list[$emaili]->contactId = $cflt->contactId;
                array_unshift($lc->author_cids[$emaili], $cflt->contactId);
            } else if ($nm_full === 1) {
                $lc->author_cids[$fulli][] = $cflt->contactId;
            } else if ($nm_uaname === 1) {
                $lc->author_cids[$uanamei][] = $cflt->contactId;
            } else if ($nm_last === 1) {
                $lc->author_cids[$lasti][] = $cflt->contactId;
            } else {
                $lc->nonauthor_contacts[] = $cflt;
            }
        }
        return $lc;
    }


    function load_preferences() {
        if ($this->_row_set && ++$this->_row_set->loaded_allprefs >= 10) {
            $row_set = $this->_row_set->filter(function ($prow) {
                return $prow->allReviewerPreference === null;
            });
        } else {
            $row_set = new PaperInfoSet($this);
        }
        foreach ($row_set as $prow) {
            $prow->allReviewerPreference = "";
            $prow->_prefs_array = $prow->_pref1_cid = $prow->_pref1 = $prow->_desirability = null;
        }
        $result = $this->conf->qe("select paperId, " . $this->conf->query_all_reviewer_preference() . " from PaperReviewPreference where paperId?a group by paperId", $row_set->paper_ids());
        while ($result && ($row = $result->fetch_row())) {
            $prow = $row_set->get((int) $row[0]);
            $prow->allReviewerPreference = $row[1];
        }
        Dbl::free($result);
    }

    /** @return array<int,array{int,?int}> */
    function preferences() {
        if ($this->allReviewerPreference === null) {
            $this->load_preferences();
        }
        if ($this->_prefs_array === null) {
            $x = array();
            if ($this->allReviewerPreference !== "") {
                $p = preg_split('/[ ,]/', $this->allReviewerPreference);
                for ($i = 0; $i + 2 < count($p); $i += 3) {
                    if ($p[$i+1] !== "0" || $p[$i+2] !== ".")
                        $x[(int) $p[$i]] = [(int) $p[$i+1], $p[$i+2] === "." ? null : (int) $p[$i+2]];
                }
            }
            $this->_prefs_array = $x;
        }
        return $this->_prefs_array;
    }

    /** @param int|Contact $contact
     * @return array{int,?int,?int} */
    function preference($contact, $include_topic_score = false) {
        $cid = is_int($contact) ? $contact : $contact->contactId;
        if ($this->_pref1_cid === null
            && $this->_prefs_array === null
            && $this->allReviewerPreference === null) {
            $row_set = $this->_row_set ?? new PaperInfoSet($this);
            foreach ($row_set as $prow) {
                $prow->_pref1_cid = $cid;
                $prow->_pref1 = null;
            }
            $result = $this->conf->qe("select paperId, preference, expertise from PaperReviewPreference where paperId?a and contactId=?", $row_set->paper_ids(), $cid);
            while ($result && ($row = $result->fetch_row())) {
                $prow = $row_set->get((int) $row[0]);
                $prow->_pref1 = [(int) $row[1], $row[2] === null ? null : (int) $row[2]];
            }
            Dbl::free($result);
        }
        if ($this->_pref1_cid === $cid) {
            $pref = $this->_pref1 ?? [0, null];
        } else {
            $pref = ($this->preferences())[$cid] ?? [0, null];
        }
        if ($include_topic_score) {
            $pref[] = $this->topic_interest_score($contact);
        }
        return $pref;
    }

    /** @return array<int,array{int,?int}> */
    function viewable_preferences(Contact $viewer, $aggregate = false) {
        if ($viewer->can_view_preference($this, $aggregate)) {
            return $this->preferences();
        } else if ($viewer->isPC) {
            $pref = $this->preference($viewer);
            return $pref[0] || $pref[1] ? [$viewer->contactId => $pref] : [];
        } else {
            return [];
        }
    }

    /** @return int */
    function desirability() {
        if ($this->_desirability === null) {
            $this->_desirability = 0;
            foreach ($this->preferences() as $pf) {
                if ($pf[0] > 0) {
                    $this->_desirability += 1;
                } else if ($pf[0] > -100 && $pf[0] < 0) {
                    $this->_desirability -= 1;
                }
            }
        }
        return $this->_desirability;
    }


    private function load_options($only_me, $need_data) {
        if ($this->_option_values === null
            && ($this->paperId === 0 || $this->optionIds === "")) {
            $this->_option_values = $this->_option_data = [];
        } else if ($this->_option_values === null
                   && $this->optionIds !== null
                   && !$need_data) {
            $this->_option_values = [];
            preg_match_all('/(\d+)#(-?\d+)/', $this->optionIds, $m);
            for ($i = 0; $i < count($m[1]); ++$i) {
                $this->_option_values[(int) $m[1][$i]][] = (int) $m[2][$i];
            }
        } else if ($this->_option_values === null
                   || ($need_data && $this->_option_data === null)) {
            $old_row_set = $this->_row_set;
            if ($only_me) {
                $this->_row_set = null;
            }
            $row_set = $this->_row_set ?? new PaperInfoSet($this);
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
            if ($only_me) {
                $this->_row_set = $old_row_set;
            }
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
        } else {
            return null;
        }
    }

    /** @param int|PaperOption $o
     * @return PaperValue */
    function base_option($o) {
        $id = is_int($o) ? $o : $o->id;
        return $this->_base_option_array[$id] ?? $this->force_option($o);
    }

    function override_option(PaperValue $ov) {
        if (!isset($this->_base_option_array[$ov->id])) {
            $this->_base_option_array[$ov->id] = $this->force_option($ov->option);
        }
        $this->_option_array[$ov->id] = $ov;
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

    /** @return string */
    static function document_sql() {
        return "paperId, paperStorageId, timestamp, mimetype, sha1, crc32, documentType, filename, infoJson, size, filterType, originalStorageId, inactive";
    }

    /** @param int $dtype
     * @param int $did
     * @return ?DocumentInfo */
    function document($dtype, $did = 0, $full = false) {
        assert(is_int($dtype)); // XXX remove later
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
            $infokey = $dtype === DTYPE_SUBMISSION ? "paper_infoJson" : "final_infoJson";
            $infoJson = $this->$infokey ?? false;
            return new DocumentInfo(["paperStorageId" => $did, "paperId" => $this->paperId, "documentType" => $dtype, "timestamp" => $this->timestamp ?? null, "mimetype" => $this->mimetype, "sha1" => $this->sha1, "size" => $this->size, "infoJson" => $infoJson, "is_partial" => true], $this->conf, $this);
        }

        if ($this->_document_array === null) {
            $result = $this->conf->qe("select " . self::document_sql() . " from PaperStorage where paperId=? and inactive=0", $this->paperId);
            $this->_document_array = [];
            while (($di = DocumentInfo::fetch($result, $this->conf, $this))) {
                $this->_document_array[$di->paperStorageId] = $di;
            }
            Dbl::free($result);
        }
        if (!array_key_exists($did, $this->_document_array)) {
            $result = $this->conf->qe("select " . self::document_sql() . " from PaperStorage where paperStorageId=?", $did);
            $this->_document_array[$did] = DocumentInfo::fetch($result, $this->conf, $this);
            Dbl::free($result);
        }
        return $this->_document_array[$did];
    }

    /** @return ?DocumentInfo */
    function primary_document() {
        return $this->document($this->finalPaperStorageId > 0 ? DTYPE_FINAL : DTYPE_SUBMISSION);
    }

    /** @return bool */
    function is_primary_document(DocumentInfo $doc) {
        return $doc->paperStorageId > 1
            && (($doc->paperStorageId == $this->paperStorageId
                 && $this->finalPaperStorageId <= 0
                 && $doc->documentType === DTYPE_SUBMISSION)
                || ($doc->paperStorageId == $this->finalPaperStorageId
                    && $doc->documentType === DTYPE_FINAL));
    }

    /** @return int */
    function primary_document_size() {
        // ensure `Paper.size` exists (might not due to import bugs)
        if ($this->size == 0
            && $this->paperStorageId > 1
            && ($doc = $this->primary_document())
            && ($this->size = $doc->size()) > 0) {
            $this->conf->qe("update Paper set size=? where paperId=? and paperStorageId=? and size=0", $this->size, $this->paperId, $this->paperStorageId);
        }
        return (int) $this->size;
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
        } else {
            return [];
        }
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
        $this->_document_array = [];
    }

    function pause_mark_inactive_documents() {
        if ($this->_pause_mark_inactive_documents !== 2) {
            $this->_pause_mark_inactive_documents = 1;
        }
    }

    function resume_mark_inactive_documents() {
        $paused = $this->_pause_mark_inactive_documents;
        $this->_pause_mark_inactive_documents = null;
        if ($paused === 2) {
            $this->mark_inactive_documents();
        }
    }

    function mark_inactive_documents() {
        // see also DocumentInfo::active_document_map
        if (!$this->_pause_mark_inactive_documents) {
            $this->_pause_mark_inactive_documents = 2;
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
            $this->conf->qe("update PaperStorage set inactive=1 where paperId=? and documentType>=? and paperStorageId?A", $this->paperId, DTYPE_FINAL, $dids);
            $this->_pause_mark_inactive_documents = null;
        } else {
            $this->_pause_mark_inactive_documents = 2;
        }
    }


    /** @return array<int,array<int,int>> */
    private function doclink_array() {
        if ($this->_doclink_array === null) {
            $row_set = $this->_row_set ?? new PaperInfoSet($this);
            foreach ($row_set as $prow) {
                $prow->_doclink_array = [];
            }
            $result = $this->conf->qe("select paperId, linkId, linkType, documentId from DocumentLink where paperId?a order by paperId, linkId, linkType", $row_set->paper_ids());
            while ($result && ($row = $result->fetch_row())) {
                $prow = $row_set->get((int) $row[0]);
                $linkid = (int) $row[1];
                if (!isset($prow->_doclink_array[$linkid])) {
                    $prow->_doclink_array[$linkid] = [];
                }
                $prow->_doclink_array[$linkid][(int) $row[2]] = (int) $row[3];
            }
            Dbl::free($result);
        }
        return $this->_doclink_array;
    }

    /** @param int $linkid
     * @param int $min
     * @param int $max
     * @return DocumentInfoSet */
    function linked_documents($linkid, $min, $max, $owner = null) {
        $docs = new DocumentInfoSet;
        foreach (($this->doclink_array())[$linkid] ?? [] as $lt => $docid) {
            if ($lt >= $min
                && $lt < $max
                && ($d = $this->document(-2, $docid))) {
                $docs->add($owner ? $d->with_owner($owner) : $d);
            }
        }
        return $docs;
    }

    /** @param int $docid
     * @param int $min
     * @param int $max
     * @return ?int */
    function link_id_by_document_id($docid, $min, $max) {
        foreach ($this->doclink_array() as $linkid => $links) {
            foreach ($links as $lt => $did) {
                if ($lt >= $min
                    && $lt < $max
                    && $did === $docid) {
                    return $linkid;
                }
            }
        }
        return null;
    }

    function invalidate_linked_documents() {
        $this->_doclink_array = null;
    }

    function mark_inactive_linked_documents() {
        // see also DocumentInfo::active_document_map
        $this->conf->qe("update PaperStorage set inactive=1 where paperId=? and documentType<=? and paperStorageId not in (select documentId from DocumentLink where paperId=?)", $this->paperId, DTYPE_COMMENT, $this->paperId);
    }


    function load_reviews($always = false) {
        ++$this->_review_array_version;

        if ($this->reviewSignatures !== null
            && $this->_review_array === null
            && !$always) {
            $this->_review_array = [];
            $this->_reviews_flags = 0;
            $this->_reviews_have = null;
            if ($this->reviewSignatures !== "") {
                foreach (explode(",", $this->reviewSignatures) as $rs) {
                    $rrow = ReviewInfo::make_signature($this, $rs);
                    $this->_review_array[$rrow->reviewId] = $rrow;
                }
            }
            return;
        }

        if ($this->_row_set && ($this->_review_array === null || $always)) {
            $row_set = $this->_row_set;
        } else {
            $row_set = new PaperInfoSet($this);
        }
        $had = 0;
        foreach ($row_set as $prow) {
            $prow->_review_array = [];
            $prow->_reviews_have = null;
            $had |= $prow->_reviews_flags;
            $prow->_reviews_flags = self::REVIEW_HAS_FULL;
        }

        $result = $this->conf->qe("select PaperReview.*, " . $this->conf->query_ratings() . " ratingSignature from PaperReview where paperId?a order by paperId, reviewId", $row_set->paper_ids());
        while (($rrow = ReviewInfo::fetch($result, $row_set, $this->conf))) {
            $rrow->prow->_review_array[$rrow->reviewId] = $rrow;
        }
        Dbl::free($result);

        $this->ensure_reviewer_names_set($row_set);
        if ($had & self::REVIEW_HAS_LASTLOGIN) {
            $this->ensure_reviewer_last_login_set($row_set);
        }
    }

    /** @return int|false */
    function parse_ordinal_id($oid) {
        if ($oid === "") {
            return 0;
        } else if (ctype_digit($oid)) {
            return intval($oid);
        } else if (str_starts_with($oid, (string) $this->paperId)) {
            $oid = (string) substr($oid, strlen((string) $this->paperId));
            if (strlen($oid) > 1 && $oid[0] === "r" && ctype_digit(substr($oid, 1))) {
                return intval(substr($oid, 1));
            }
        }
        if (ctype_upper($oid) && ($n = parse_latin_ordinal($oid)) > 0) {
            return -$n;
        } else if ($oid === "rnew" || $oid === "new") {
            return 0;
        } else {
            return false;
        }
    }

    /** @return array<int,ReviewInfo> */
    function all_reviews() {
        if ($this->_review_array === null) {
            $this->load_reviews();
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

        usort($srs, function ($a, $b) {
            // NB: all submitted reviews have timeDisplayed
            if ($a->timeDisplayed != $b->timeDisplayed) {
                return $a->timeDisplayed < $b->timeDisplayed ? -1 : 1;
            } else if ($a->reviewOrdinal && $b->reviewOrdinal) {
                return $a->reviewOrdinal < $b->reviewOrdinal ? -1 : 1;
            } else {
                return $a->reviewId < $b->reviewId ? -1 : 1;
            }
        });

        foreach ($urs as $urow) {
            $srs[] = $urow;
        }

        foreach ($ers as $urow) {
            $p0 = count($srs);
            foreach ($srs as $i => $srow) {
                if ($urow->requestedBy === $srow->contactId
                    || ($urow->requestedBy === $srow->requestedBy
                        && $srow->is_subreview()
                        && ($urow->reviewStatus < ReviewInfo::RS_ADOPTED
                            || ($srow->reviewStatus >= ReviewInfo::RS_ADOPTED
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
        } else {
            throw new Exception("PaperInfo::checked_review_by_user failure");
        }
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
                    && in_array($rrow->reviewToken, $rev_tokens))) {
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
        $names = [];
        foreach ($this->_full_review ?? [] as $rrow) {
            if (($u = $this->conf->cached_user_by_id($rrow->contactId))) {
                $rrow->assign_name($u, $names);
            }
        }
    }

    /** @return ?ReviewInfo */
    function full_review_by_id($id) {
        if ($this->_full_review_key === null
            && !($this->_reviews_flags & self::REVIEW_HAS_FULL)) {
            $this->_full_review_key = "r$id";
            $result = $this->conf->qe("select PaperReview.*, " . $this->conf->query_ratings() . " ratingSignature from PaperReview where paperId=? and reviewId=?", $this->paperId, $id);
            $rrow = ReviewInfo::fetch($result, $this, $this->conf);
            $this->_full_review = $rrow ? [$rrow] : [];
            Dbl::free($result);
            $this->ensure_full_review_name();
        }
        if ($this->_full_review_key === "r$id") {
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
            && !($this->_reviews_flags & self::REVIEW_HAS_FULL)) {
            $row_set = $this->_row_set ?? new PaperInfoSet($this);
            foreach ($row_set as $prow) {
                $prow->_full_review = [];
                $prow->_full_review_key = "u$cid";
            }
            $result = $this->conf->qe("select PaperReview.*, " . $this->conf->query_ratings() . " ratingSignature from PaperReview where paperId?a and contactId=? order by paperId, reviewId", $row_set->paper_ids(), $cid);
            while (($rrow = ReviewInfo::fetch($result, $row_set, $this->conf))) {
                $rrow->prow->_full_review[] = $rrow;
            }
            Dbl::free($result);
            $this->ensure_full_review_name();
        }
        if ($this->_full_review_key === "u$cid") {
            return $this->_full_review;
        }
        $this->ensure_full_reviews();
        return $this->reviews_by_user($contact);
    }

    /** @return ?ReviewInfo */
    function full_review_by_ordinal($ordinal) {
        if ($this->_full_review_key === null
            && !($this->_reviews_flags & self::REVIEW_HAS_FULL)) {
            $this->_full_review_key = "o$ordinal";
            $result = $this->conf->qe("select PaperReview.*, " . $this->conf->query_ratings() . " ratingSignature from PaperReview where paperId=? and reviewOrdinal=?", $this->paperId, $ordinal);
            $rrow = ReviewInfo::fetch($result, $this, $this->conf);
            $this->_full_review = $rrow ? [$rrow] : [];
            Dbl::free($result);
            $this->ensure_full_review_name();
        }
        if ($this->_full_review_key === "o$ordinal") {
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
        $result = $this->conf->qe("select PaperReview.*, " . $this->conf->query_ratings() . " ratingSignature, ContactInfo.firstName, ContactInfo.lastName, ContactInfo.affiliation, ContactInfo.email, ContactInfo.roles, ContactInfo.contactTags from PaperReview join ContactInfo using (contactId) where paperId=? and $key=? order by paperId, reviewId", $this->paperId, $value);
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

    /** @return bool */
    function can_view_review_identity_of($cid, Contact $viewer) {
        if ($viewer->can_administer_for_track($this, Track::VIEWREVID)
            || $cid == $viewer->contactId) {
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
            $this->load_reviews();
        }
    }

    function ensure_full_reviews() {
        if (!($this->_reviews_flags & self::REVIEW_HAS_FULL)) {
            $this->load_reviews(true);
        }
    }

    private function ensure_reviewer_names_set($row_set) {
        foreach ($row_set as $prow) {
            foreach ($prow->all_reviews() as $rrow) {
                $this->conf->prefetch_user_by_id($rrow->contactId);
            }
        }
        foreach ($row_set as $prow) {
            $prow->_reviews_flags |= self::REVIEW_HAS_NAMES;
            $names = [];
            foreach ($prow->all_reviews() as $rrow) {
                if (($u = $this->conf->cached_user_by_id($rrow->contactId))) {
                    $rrow->assign_name($u, $names);
                }
            }
        }
    }

    function ensure_reviewer_names() {
        $this->ensure_reviews();
        if (!empty($this->_review_array)
            && !($this->_reviews_flags & self::REVIEW_HAS_NAMES)) {
            $this->ensure_reviewer_names_set($this->_row_set ?? new PaperInfoSet($this));
        }
    }

    private function ensure_reviewer_last_login_set($row_set) {
        $users = [];
        foreach ($row_set as $prow) {
            $prow->_reviews_flags |= self::REVIEW_HAS_LASTLOGIN;
            foreach ($prow->all_reviews() as $rrow) {
                $users[$rrow->contactId] = true;
            }
        }
        if (!empty($users)) {
            $result = $this->conf->qe("select contactId, lastLogin from ContactInfo where contactId?a", array_keys($users));
            $lastLogins = Dbl::fetch_iimap($result);
            foreach ($row_set as $prow) {
                foreach ($prow->all_reviews() as $rrow) {
                    $rrow->lastLogin = $lastLogins[$rrow->contactId];
                }
            }
        }
    }

    function ensure_reviewer_last_login() {
        $this->ensure_reviews();
        if (!empty($this->_review_array)
            && !($this->_reviews_flags & self::REVIEW_HAS_LASTLOGIN)) {
            $this->ensure_reviewer_last_login_set($this->_row_set ?? new PaperInfoSet($this));
        }
    }

    /** @param string $fid */
    private function load_review_fields($fid, $maybe_null = false) {
        $k = $fid . "Signature";
        $row_set = $this->_row_set ?? new PaperInfoSet($this);
        foreach ($row_set as $prow) {
            $prow->$k = "";
        }
        $select = $maybe_null ? "coalesce($fid,'.')" : $fid;
        $result = $this->conf->qe("select paperId, group_concat($select order by reviewId) from PaperReview where paperId?a group by paperId", $row_set->paper_ids());
        while ($result && ($row = $result->fetch_row())) {
            $prow = $row_set->get((int) $row[0]);
            $prow->$k = $row[1];
        }
        Dbl::free($result);
    }

    /** @param int $order */
    function ensure_review_field_order($order) {
        if (!($this->_reviews_flags & self::REVIEW_HAS_FULL)
            && ($this->_reviews_have[$order] ?? null) === null) {
            $rform = $this->conf->review_form();
            if ($this->_reviews_have === null) {
                $this->_reviews_have = $rform->order_array(null);
            }
            $f = $rform->field_by_order($order);
            if (!$f) {
                $this->_reviews_have[$order] = false;
            } else if (!$f->main_storage) {
                $this->ensure_full_reviews();
            } else {
                $this->_reviews_have[$order] = true;
                $k = $f->main_storage . "Signature";
                if ($this->$k === null) {
                    $this->load_review_fields($f->main_storage);
                }
                $x = explode(",", $this->$k);
                foreach ($this->reviews_as_list() as $i => $rrow) {
                    $rrow->fields[$order] = (int) $x[$i];
                }
            }
        }
    }

    /** @param string|ReviewField $field
     * @deprecated */
    function ensure_review_score($field) {
        if (!($this->_reviews_flags & self::REVIEW_HAS_FULL)) {
            $f = is_string($field) ? $this->conf->review_field($field) : $field;
            if ($f && $f->order) {
                $this->ensure_review_field_order($f->order);
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
        if (!($this->_reviews_flags & self::REVIEW_HAS_WORDCOUNT)) {
            $this->_reviews_flags |= self::REVIEW_HAS_WORDCOUNT;
            if ($this->reviewWordCountSignature === null) {
                $this->load_review_fields("reviewWordCount", true);
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

    function ensure_review_ratings(ReviewInfo $ensure_rrow = null) {
        $row_set = $this->_row_set ?? new PaperInfoSet($this);
        $pids = [];
        foreach ($row_set as $prow) {
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
        $result = $this->conf->qe("select paperId, reviewId, " . $this->conf->query_ratings() . " ratingSignature from PaperReview where paperId?a", $pids);
        while (($row = $result->fetch_row())) {
            $prow = $row_set->get((int) $row[0]);
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
            if (($u = $this->conf->cached_user_by_id($cid)))
                $us[] = $u;
        }
        return $us;
    }


    function load_review_requests($always = false) {
        if ($this->_row_set && ($this->_request_array === null || $always)) {
            $row_set = $this->_row_set;
        } else {
            $row_set = new PaperInfoSet($this);
        }
        foreach ($row_set as $prow) {
            $prow->_request_array = [];
        }

        $result = $this->conf->qe("select * from ReviewRequest where paperId?a", $row_set->paper_ids());
        while (($ref = ReviewRequestInfo::fetch($result))) {
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
        if ($this->_row_set && ($this->_refusal_array === null || $always)) {
            $row_set = $this->_row_set;
        } else {
            $row_set = new PaperInfoSet($this);
        }
        foreach ($row_set as $prow) {
            $prow->_refusal_array = [];
        }

        $result = $this->conf->qe("select * from PaperReviewRefused where paperId?a", $row_set->paper_ids());
        while (($ref = ReviewRefusalInfo::fetch($result))) {
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

    /** @return list<ReviewRefusalInfo> */
    function review_refusals_by_email($email) {
        $a = [];
        foreach ($this->review_refusals() as $ref) {
            if (strcasecmp($ref->email, $email) === 0) {
                $a[] = $ref;
            }
        }
        return $a;
    }


    static function fetch_comment_query() {
        return "select PaperComment.*, firstName, lastName, affiliation, email
            from PaperComment
            left join ContactInfo on (ContactInfo.contactId=PaperComment.contactId)";
    }

    /** @return array<int,CommentInfo> */
    function fetch_comments($extra_where = null) {
        $result = $this->conf->qe(self::fetch_comment_query()
            . " where paperId={$this->paperId}" . ($extra_where ? " and $extra_where" : "")
            . " order by paperId, commentId");
        $comments = [];
        while (($c = CommentInfo::fetch($result, $this, $this->conf))) {
            $comments[$c->commentId] = $c;
        }
        Dbl::free($result);
        return $comments;
    }

    function load_comments() {
        $row_set = $this->_row_set ?? new PaperInfoSet($this);
        foreach ($row_set as $prow) {
            $prow->_comment_array = [];
        }
        $result = $this->conf->qe(self::fetch_comment_query()
            . " where paperId?a order by paperId, commentId", $row_set->paper_ids());
        $comments = [];
        while (($c = CommentInfo::fetch($result, null, $this->conf))) {
            $prow = $row_set->checked_paper_by_id($c->paperId);
            $c->set_prow($prow);
            $prow->_comment_array[] = $c;
        }
        Dbl::free($result);
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
            if ($user->can_view_comment($this, $crow, $textless))
                $crows[] = $crow;
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
            preg_match_all('/(\d+);(\d+);(\d+);(\d+);([^|]*)/',
                           $this->commentSkeletonInfo, $ms, PREG_SET_ORDER);
            foreach ($ms as $m) {
                $c = new CommentInfo($this);
                $c->commentId = (int) $m[1];
                $c->contactId = (int) $m[2];
                $c->commentType = (int) $m[3];
                $c->commentRound = (int) $m[4];
                $c->commentTags = $m[5];
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

    /** @param int $checkflags
     * @param int $wantflags
     * @return bool */
    function has_viewable_comment_type(Contact $user, $checkflags, $wantflags) {
        foreach ($this->all_comment_skeletons() as $crow) {
            if (($crow->commentType & $checkflags) === $wantflags
                && $user->can_view_comment($this, $crow))
                return true;
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
    function all_watch() {
        if ($this->_watch_array === null) {
            $this->_watch_array = [];
            $result = $this->conf->qe("select contactId, watch from PaperWatch where paperId=?", $this->paperId);
            while (($row = $result->fetch_row())) {
                $this->_watch_array[(int) $row[0]] = (int) $row[1];
            }
            Dbl::free($result);
        }
        return $this->_watch_array;
    }

    /** @return int */
    function watch(Contact $user) {
        if ($user->contactId === $this->_watch_cid) {
            return $this->watch;
        } else {
            return ($this->all_watch())[$user->contactId] ?? 0;
        }
    }


    /** @param Contact $a
     * @param Contact $b */
    function notify_user_compare($a, $b) {
        // group authors together, then reviewers
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
        } else if (!$aa && !$ba) {
            $aa = $this->has_reviewer($a);
            $ba = $this->has_reviewer($b);
        }
        if ($aa !== $ba) {
            return $aa ? -1 : 1;
        } else {
            return call_user_func($a->conf->user_comparator(), $a, $b);
        }
    }

    /** @param list<int> $cids
     * @param string $clause
     * @param ?string $fn
     * @return list<Contact> */
    function generic_followers($cids, $clause, $fn) {
        $result = $this->conf->qe("select contactId, firstName, lastName, affiliation, email, preferredEmail, password, roles, contactTags, disabled, primaryContactId, defaultWatch from ContactInfo where (contactId?a or ($clause)) and not disabled", $cids);
        $watchers = [];
        while (($minic = Contact::fetch($result, $this->conf))) {
            if ($minic->can_view_paper($this)
                && (!$fn || $minic->$fn($this))) {
                $watchers[] = $minic;
            }
        }
        Dbl::free($result);
        usort($watchers, [$this, "notify_user_compare"]);
        return $watchers;
    }

    /** @return list<Contact> */
    function contact_followers() {
        $cids = [];
        foreach ($this->contacts() as $cflt) {
            $cids[] = $cflt->contactId;
        }
        return $this->generic_followers($cids, "false", null);
    }

    /** @return list<Contact> */
    function submission_followers() {
        $fl = ($this->anno["is_new"] ?? false ? Contact::WATCH_PAPER_REGISTER_ALL : 0)
            | ($this->timeSubmitted > 0 ? Contact::WATCH_PAPER_NEWSUBMIT_ALL : 0);
        return $this->generic_followers([], "(defaultWatch&{$fl})!=0 and roles!=0", "following_submission");
    }

    /** @return list<Contact> */
    function late_withdrawal_followers() {
        return $this->generic_followers([], "(defaultWatch&" . Contact::WATCH_LATE_WITHDRAWAL_ALL . ")!=0 and roles!=0", "following_late_withdrawal");
    }

    /** @return list<Contact> */
    function final_update_followers() {
        return $this->generic_followers([], "(defaultWatch&" . Contact::WATCH_FINAL_UPDATE_ALL . ")!=0 and roles!=0", "following_final_update");
    }

    /** @return list<Contact> */
    function review_followers() {
        $cids = [];
        foreach ($this->contacts() as $cflt) {
            $cids[] = $cflt->contactId;
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
        return $this->generic_followers($cids, "(defaultWatch&" . (Contact::WATCH_REVIEW_ALL | Contact::WATCH_REVIEW_MANAGED) . ")!=0 and roles!=0", "following_reviews");
    }

    function delete_from_database(Contact $user = null) {
        // XXX email self?
        if ($this->paperId <= 0) {
            return false;
        }
        $rrows = $this->all_reviews();

        $qs = [];
        foreach (["PaperWatch", "PaperReviewPreference", "PaperReviewRefused", "ReviewRequest", "PaperTag", "PaperComment", "PaperReview", "PaperTopic", "PaperOption", "PaperConflict", "Paper", "PaperStorage", "Capability"] as $table) {
            $qs[] = "delete from $table where paperId={$this->paperId}";
        }
        $mresult = Dbl::multi_qe($this->conf->dblink, join(";", $qs));
        $mresult->free_all();

        if (!Dbl::$nerrors) {
            $this->conf->update_papersub_setting(-1);
            if ($this->outcome > 0) {
                $this->conf->update_paperacc_setting(-1);
            }
            if ($this->leadContactId > 0 || $this->shepherdContactId > 0) {
                $this->conf->update_paperlead_setting(-1);
            }
            if ($this->managerContactId > 0) {
                $this->conf->update_papermanager_setting(-1);
            }
            if ($rrows && array_filter($rrows, function ($rrow) { return $rrow->reviewToken > 0; })) {
                $this->conf->update_rev_tokens_setting(-1);
            }
            if ($rrows && array_filter($rrows, function ($rrow) { return $rrow->reviewType == REVIEW_META; })) {
                $this->conf->update_metareviews_setting(-1);
            }
            $this->conf->log_for($user, $user, "Paper deleted", $this->paperId);
            return true;
        } else {
            return false;
        }
    }
}

class PaperInfoLikelyContacts implements JsonSerializable {
    /** @var list<Author> */
    public $author_list = [];
    /** @var list<list<int>> */
    public $author_cids = [];
    /** @var list<Author> */
    public $nonauthor_contacts = [];

    #[\ReturnTypeWillChange]
    /** @return array{author_list:list<object>,author_cids:list<list<int>>,nonauthor_contacts?:list<object>} */
    function jsonSerialize() {
        $x = ["author_list" => [], "author_cids" => $this->author_cids];
        foreach ($this->author_list as $au) {
            $j = $au->unparse_nae_json();
            if ($au->contactId > 0) {
                $j->contactId = $au->contactId;
            }
            $x["author_list"][] = $j;
        }
        foreach ($this->nonauthor_contacts as $au) {
            $j = $au->unparse_nae_json();
            $j->contactId = $au->contactId;
            $x["nonauthor_contacts"][] = $j;
        }
        return $x;
    }
}
