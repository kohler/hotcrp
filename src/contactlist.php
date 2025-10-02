<?php
// contactlist.php -- HotCRP helper class for producing lists of contacts
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ContactList {
    const FIELD_SELECTOR = 1000;

    const FIELD_NAME = 1;
    const FIELD_EMAIL = 2;
    const FIELD_AFFILIATION = 3;
    const FIELD_LASTVISIT = 5;
    const FIELD_HIGHTOPICS = 6;
    const FIELD_LOWTOPICS = 7;
    const FIELD_REVIEWS = 8;
    const FIELD_PAPERS = 9;
    const FIELD_REVIEW_PAPERS = 10;
    const FIELD_AFFILIATION_ROW = 11;
    const FIELD_REVIEW_RATINGS = 12;
    const FIELD_LEADS = 13;
    const FIELD_SHEPHERDS = 14;
    const FIELD_TAGS = 15;
    const FIELD_COLLABORATORS = 16;
    const FIELD_INCOMPLETE_REVIEWS = 17;
    const FIELD_ORCID = 18;
    const FIELD_COUNTRY = 19;
    const FIELD_FIRST = 40;
    const FIELD_LAST = 41;
    const FIELD_SCORE = 50;

    /** @var list<string> */
    public static $folds = ["topics", "aff", "tags", "collab", "orcid", "country"];

    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Qrequest */
    public $qreq;
    /** @var ?int */
    private $sortField;
    /** @var bool */
    private $reverseSort = false;
    /** @var bool */
    private $sortable;
    /** @var int */
    private $count;
    /** @var int */
    private $has_flags = 0;
    /** @var Tagger */
    private $tagger;
    private $limit;
    public $have_folds = [];
    private $qopt = [];
    /** @var array<int,Column> */
    private $_columns = [];
    /** @var array<int,string> */
    private $_fold_names = [];
    /** @var PaperInfoSet */
    private $_rowset;
    /** @var array<int,list<int>> */
    private $_au_data;
    /** @var array<int,bool> */
    private $_au_unsub;
    /** @var array<int,list<int>> */
    private $_re_data;
    /** @var array<int,list<array{int,int,int,int}>> */
    private $_reord_data;
    /** @var array<int,array{int,int}> */
    private $_rect_data;
    /** @var array<int,int> */
    private $_lead_data;
    /** @var array<int,int> */
    private $_shepherd_data;
    /** @var list<Discrete_ReviewField> */
    private $_rfields = [];
    /** @var list<Discrete_ReviewField> */
    private $_wfields = [];
    /** @var array<int,list<int>> */
    private $_score1_data = [];
    /** @var array<int,list<int>> */
    private $_scorex_data = [];
    /** @var array<int,array{int,int}> */
    private $_rating_data;
    /** @var array<int,true> */
    private $_limit_cids;
    /** @var array<int,array> */
    private $_sort_data;
    /** @var ?SearchSelection */
    private $_selection;
    /** @var bool */
    private $_select_all = false;

    /** @var ?array<string,int> */
    static private $field_name_map;

    const HAS_SELECTOR = 1;
    const HAS_PC = 2;
    const HAS_NONPC = 4;

    function __construct(Contact $user, $sortable = true, $qreq = null) {
        $this->conf = $user->conf;
        $this->user = $user;
        if (!$qreq || !($qreq instanceof Qrequest)) {
            $qreq = new Qrequest("GET", $qreq);
            $qreq->set_user($user);
        }
        $this->qreq = $qreq;

        $this->tagger = new Tagger($this->user);
        foreach ($this->conf->review_form()->viewable_fields($this->user) as $f) {
            if ($f instanceof Discrete_ReviewField)
                $this->_rfields[] = $f;
        }

        if (($this->sortable = $sortable)
            && ($s = $this->qreq->sort ?? "") !== "") {
            if (str_ends_with($s, "R")) {
                $this->reverseSort = true;
                $s = substr($s, 0, strlen($s) - 1);
            } else if (str_ends_with($s, "N")) {
                $s = substr($s, 0, strlen($s) - 1);
            } else if (str_starts_with($s, "-")) {
                $this->reverseSort = true;
                $s = substr($s, 1);
            }
            if (($col = $this->_column($s))
                && $this->column_visible($col)) {
                $this->sortField = $col->order;
            }
        }

        if ($this->qreq->has_a("pap")) {
            $this->_selection = SearchSelection::make($this->qreq);
        }
        if ($this->qreq->selectall) {
            $this->_select_all = true;
        }
    }

    /** @param string $name
     * @return int */
    private function _resolve_field_name($name) {
        if (self::$field_name_map === null) {
            self::$field_name_map = [
                "sel" => self::FIELD_SELECTOR,
                "name" => self::FIELD_NAME,
                "first" => self::FIELD_FIRST,
                "given_name" => self::FIELD_FIRST,
                "last" => self::FIELD_LAST,
                "family_name" => self::FIELD_LAST,
                "email" => self::FIELD_EMAIL,
                "aff" => self::FIELD_AFFILIATION,
                "affrow" => self::FIELD_AFFILIATION_ROW,
                "orcid" => self::FIELD_ORCID,
                "country" => self::FIELD_COUNTRY,
                "lastvisit" => self::FIELD_LASTVISIT,
                "topicshi" => self::FIELD_HIGHTOPICS,
                "topicslo" => self::FIELD_LOWTOPICS,
                "reviews" => self::FIELD_REVIEWS,
                "ire" => self::FIELD_INCOMPLETE_REVIEWS,
                "revratings" => self::FIELD_REVIEW_RATINGS,
                "papers" => self::FIELD_PAPERS,
                "repapers" => self::FIELD_REVIEW_PAPERS,
                "lead" => self::FIELD_LEADS,
                "shepherd" => self::FIELD_SHEPHERDS,
                "tags" => self::FIELD_TAGS,
                "collab" => self::FIELD_COLLABORATORS,
                "scores" => 0
            ];
        }
        if (($fid = self::$field_name_map[$name] ?? null) !== null) {
            return $fid;
        }
        $f = $this->conf->review_field($name) ?? $this->conf->find_review_field($name);
        if ($f && ($p = array_search($f, $this->_rfields)) !== false) {
            return self::FIELD_SCORE + $p;
        }
        return 0;
    }

    /** @param int $fid
     * @return ?Column */
    private function _make_column($fid) {
        switch ($fid) {
        case self::FIELD_SELECTOR:
            return new Column(["name" => "sel"]);
        case self::FIELD_NAME:
            return new Column(["name" => "name", "sort" => true]);
        case self::FIELD_FIRST:
            return new Column(["name" => "given_name", "sort" => true]);
        case self::FIELD_LAST:
            return new Column(["name" => "family_name", "sort" => true]);
        case self::FIELD_EMAIL:
            return new Column(["name" => "email", "sort" => true]);
        case self::FIELD_AFFILIATION:
            return new Column(["name" => "aff", "className" => "pl_affiliation", "sort" => true]);
        case self::FIELD_AFFILIATION_ROW:
            return new Column(["name" => "affrow", "fold" => 2, "sort" => true, "prefer_row" => true]);
        case self::FIELD_LASTVISIT:
            return new Column(["name" => "lastvisit", "sort" => true]);
        case self::FIELD_HIGHTOPICS:
            $this->_fold_names[$fid] = "topics";
            return new Column(["name" => "topicshi", "className" => "pl_topics", "fold" => 1, "prefer_row" => true]);
        case self::FIELD_LOWTOPICS:
            $this->_fold_names[$fid] = "topics";
            return new Column(["name" => "topicslo", "className" => "pl_topics", "fold" => 1, "prefer_row" => true]);
        case self::FIELD_REVIEWS:
            return new Column(["name" => "reviews", "className" => "pl_revstat", "sort" => true]);
        case self::FIELD_INCOMPLETE_REVIEWS:
            return new Column(["name" => "ire", "className" => "pl_revstat", "sort" => true]);
        case self::FIELD_REVIEW_RATINGS:
            return new Column(["name" => "revratings", "className" => "pl_revstat", "sort" => true]);
        case self::FIELD_PAPERS:
            return new Column(["name" => "papers", "sort" => true]);
        case self::FIELD_REVIEW_PAPERS:
            return new Column(["name" => "repapers", "className" => "pl_papers", "sort" => true]);
        case self::FIELD_LEADS:
            return new Column(["name" => "lead", "className" => "pl_revstat", "sort" => true]);
        case self::FIELD_SHEPHERDS:
            return new Column(["name" => "shepherd", "className" => "pl_revstat", "sort" => true]);
        case self::FIELD_TAGS:
            return new Column(["name" => "tags", "fold" => 3, "prefer_row" => true]);
        case self::FIELD_ORCID:
            return new Column(["name" => "orcid", "fold" => 5, "sort" => true]);
        case self::FIELD_COUNTRY:
            return new Column(["name" => "country", "fold" => 6, "sort" => true]);
        case self::FIELD_COLLABORATORS:
            return new Column(["name" => "collab", "fold" => 4, "prefer_row" => true]);
        }
        if ($fid >= self::FIELD_SCORE && $fid < self::FIELD_SCORE + count($this->_rfields)) {
            $f = $this->_rfields[$fid - self::FIELD_SCORE];
            $this->_fold_names[$fid] = $f->short_id;
            return new Column(["name" => $f->uid(), "sort" => true, "className" => "pl_uscores"]);
        }
        return null;
    }

    /** @param int|string $fid
     * @return ?Column */
    private function _column($fid) {
        // [classname, position, sortable, fieldid, sortname]
        if (is_string($fid)) {
            $fid = $this->_resolve_field_name($fid);
        }
        if ($fid <= 0) {
            return null;
        }
        if (array_key_exists($fid, $this->_columns)) {
            return $this->_columns[$fid];
        }
        if (($f = $this->_columns[$fid] = $this->_make_column($fid))) {
            $f->order = $fid;
        }
        return $f;
    }


    /** @param Column $col
     * @return bool */
    private function column_visible($col) {
        switch ($col->order) {
        case self::FIELD_NAME:
        case self::FIELD_AFFILIATION:
        case self::FIELD_AFFILIATION_ROW:
        case self::FIELD_ORCID:
        case self::FIELD_COUNTRY:
            return true;
        case self::FIELD_SELECTOR:
        case self::FIELD_EMAIL:
        case self::FIELD_HIGHTOPICS:
        case self::FIELD_LOWTOPICS:
        case self::FIELD_REVIEWS:
        case self::FIELD_INCOMPLETE_REVIEWS:
        case self::FIELD_LEADS:
        case self::FIELD_SHEPHERDS:
        case self::FIELD_PAPERS:
        case self::FIELD_REVIEW_PAPERS:
        case self::FIELD_COLLABORATORS:
            return $this->user->isPC;
        case self::FIELD_LASTVISIT:
            return $this->user->privChair;
        case self::FIELD_REVIEW_RATINGS:
            return $this->user->isPC && $this->conf->review_ratings() >= 0;
        case self::FIELD_TAGS:
            return $this->user->can_view_user_tags();
        }
        if ($col->order >= self::FIELD_SCORE
            && ($f = $this->_rfields[$col->order - self::FIELD_SCORE] ?? null)) {
            return $this->user->isPC
                && $this->user->can_view_some_review_field($f);
        }
        return false;
    }

    /** @param Column $col
     * @param string $uldisplay
     * @return bool */
    private function column_unfolded($col, $uldisplay) {
        if (!$col->fold) {
            return true;
        }
        $foldname = $this->_fold_names[$col->order] ?? $col->name;
        $this->have_folds[$foldname] = true;
        return strpos($uldisplay, " {$foldname} ") !== false;
    }

    /** @param Column $col */
    private function column_query_options($col) {
        switch ($col->order) {
        case self::FIELD_HIGHTOPICS:
        case self::FIELD_LOWTOPICS:
            $this->qopt["topics"] = true;
            return;
        case self::FIELD_REVIEWS:
        case self::FIELD_INCOMPLETE_REVIEWS:
            $this->qopt["reviews"] = true;
            return;
        case self::FIELD_LEADS:
            $this->qopt["papers"] = $this->qopt["leads"] = true;
            return;
        case self::FIELD_SHEPHERDS:
            $this->qopt["papers"] = $this->qopt["shepherds"] = true;
            return;
        case self::FIELD_REVIEW_RATINGS:
            $this->qopt["revratings"] = $this->qopt["reviews"] = true;
            return;
        case self::FIELD_PAPERS:
            $this->qopt["papers"] = $this->limit;
            return;
        case self::FIELD_REVIEW_PAPERS:
            $this->qopt["repapers"] = $this->qopt["reviews"] = true;
            return;
        }
        if ($col->order >= self::FIELD_SCORE
            && ($f = $this->_rfields[$col->order - self::FIELD_SCORE] ?? null)) {
            $this->qopt["reviews"] = true;
            if (!in_array($f, $this->_wfields, true)) {
                $this->_wfields[] = $f;
            }
        }
    }

    /** @param int $uid
     * @param ReviewField $f
     * @return list<int> */
    private function _extract_scores($uid, $f) {
        if (count($this->_wfields) === 1 && $f === $this->_wfields[0]) {
            return $this->_score1_data[$uid] ?? [];
        }
        $s = [];
        $sx = $this->_scorex_data[$uid] ?? [];
        for ($i = 0; $i !== count($sx); $i += 2) {
            if ($sx[$i] === $f->order)
                $s[] = $sx[$i + 1];
        }
        return $s;
    }

    function _sortBase($a, $b) {
        return call_user_func($this->conf->user_comparator(), $a, $b);
    }

    function _sortEmail($a, $b) {
        return strnatcasecmp($a->email, $b->email);
    }

    function _sort_string($a, $b, $as, $bs, $nat) {
        $cmp = $nat ? strnatcasecmp($as, $bs) : strcmp($as, $bs);
        if ($cmp === 0) {
            return $this->_sortBase($a, $b);
        } else if ($as === "" || $bs === "") {
            return $as === "" ? 1 : -1;
        } else {
            return $cmp;
        }
    }

    function _sortAffiliation($a, $b) {
        return $this->_sort_string($a, $b, $a->affiliation, $b->affiliation, true);
    }

    function _sortCountry($a, $b) {
        return $this->_sort_string($a, $b, $a->country_name(), $b->country_name(), true);
    }

    function _sortOrcid($a, $b) {
        return $this->_sort_string($a, $b, $a->decorated_orcid(), $b->decorated_orcid(), false);
    }

    function _sortLastVisit($a, $b) {
        return $b->activity_at <=> $a->activity_at ? : $this->_sortBase($a, $b);
    }

    function _sortReviews($a, $b) {
        $ac = $this->_rect_data[$a->contactId] ?? [0, 0];
        $bc = $this->_rect_data[$b->contactId] ?? [0, 0];
        return $bc[1] <=> $ac[1] ? : ($bc[0] <=> $ac[0] ? : $this->_sortBase($a, $b));
    }

    function _sortIncompleteReviews($a, $b) {
        $ac = $this->_rect_data[$a->contactId] ?? [0, 0];
        $bc = $this->_rect_data[$b->contactId] ?? [0, 0];
        return $bc[0] <=> $ac[0] ? : $this->_sortBase($a, $b);
    }

    function _sortLeads($a, $b) {
        return ($this->_lead_data[$b->contactId] ?? 0) <=> ($this->_lead_data[$a->contactId] ?? 0) ? : $this->_sortBase($a, $b);
    }

    function _sortShepherds($a, $b) {
        return ($this->_shepherd_data[$b->contactId] ?? 0) <=> ($this->_shepherd_data[$a->contactId] ?? 0) ? : $this->_sortBase($a, $b);
    }

    function _sortReviewRatings($a, $b) {
        list($ag, $ab) = $this->_rating_data[$a->contactId] ?? [0, 0];
        list($bg, $bb) = $this->_rating_data[$b->contactId] ?? [0, 0];
        if ($ag + $ab === 0) {
            if ($bg + $bb !== 0) {
                return 1;
            }
        } else if ($bg + $bb === 0) {
            return -1;
        } else if ($ag - $ab !== $bg - $bb) {
            return $bg - $bb <=> $ag - $ab;
        } else if ($ag + $ab !== $bg + $bb) {
            return $bg + $bb <=> $ag + $ab;
        }
        return $this->_sortBase($a, $b);
    }

    /** @param Contact $a
     * @param Contact $b
     * @param array<int,list<int>> $map */
    private function _sort_paper_list($a, $b, $map) {
        $ap = $map[$a->contactId] ?? [];
        $bp = $map[$b->contactId] ?? [];
        if (count($ap) !== count($bp)) {
            return count($ap) > count($bp) ? -1 : 1;
        }
        for ($i = 0; $i !== count($ap); ++$i) {
            if ($ap[$i] !== $bp[$i])
                return $ap[$i] < $bp[$i] ? -1 : 1;
        }
        return $this->_sortBase($a, $b);
    }

    function _sort_papers($a, $b) {
        return $this->_sort_paper_list($a, $b, $this->_au_data);
    }

    function _sort_reviewed_papers($a, $b) {
        return $this->_sort_paper_list($a, $b, $this->_re_data);
    }

    function _sort_scores($a, $b) {
        $ai = $this->_sort_data[$a->contactId];
        $bi = $this->_sort_data[$b->contactId];
        if (!($x = ScoreInfo::compare($bi[1], $ai[1], -1))) {
            $x = ScoreInfo::compare($bi[0], $ai[0]);
        }
        return $x ? ($x < 0 ? -1 : 1) : $this->_sortBase($a, $b);
    }

    function _sort($rows) {
        switch ($this->sortField) {
        case self::FIELD_NAME:
            usort($rows, [$this, "_sortBase"]);
            break;
        case self::FIELD_FIRST:
        case self::FIELD_LAST:
            $compar = $this->conf->user_comparator($this->sortField === self::FIELD_LAST);
            usort($rows, $compar);
            break;
        case self::FIELD_EMAIL:
            usort($rows, [$this, "_sortEmail"]);
            break;
        case self::FIELD_AFFILIATION:
        case self::FIELD_AFFILIATION_ROW:
            usort($rows, [$this, "_sortAffiliation"]);
            break;
        case self::FIELD_ORCID:
            usort($rows, [$this, "_sortOrcid"]);
            break;
        case self::FIELD_COUNTRY:
            usort($rows, [$this, "_sortCountry"]);
            break;
        case self::FIELD_LASTVISIT:
            usort($rows, [$this, "_sortLastVisit"]);
            break;
        case self::FIELD_REVIEWS:
            usort($rows, [$this, "_sortReviews"]);
            break;
        case self::FIELD_INCOMPLETE_REVIEWS:
            usort($rows, [$this, "_sortIncompleteReviews"]);
            break;
        case self::FIELD_LEADS:
            usort($rows, [$this, "_sortLeads"]);
            break;
        case self::FIELD_SHEPHERDS:
            usort($rows, [$this, "_sortShepherds"]);
            break;
        case self::FIELD_REVIEW_RATINGS:
            usort($rows, [$this, "_sortReviewRatings"]);
            break;
        case self::FIELD_PAPERS:
            usort($rows, [$this, "_sort_papers"]);
            break;
        case self::FIELD_REVIEW_PAPERS:
            usort($rows, [$this, "_sort_reviewed_papers"]);
            break;
        default:
            $f = $this->_rfields[$this->sortField - self::FIELD_SCORE] ?? null;
            $scoresort = $this->qreq->csession("ulscoresort") ?? "average";
            if (!in_array($scoresort, ["average", "variance", "maxmin"], true)) {
                $scoresort = "average";
            }
            foreach ($rows as $row) {
                $scoreinfo = new ScoreInfo($this->_extract_scores($row->contactId, $f));
                $this->_sort_data[$row->contactId] =
                    [$scoreinfo->sort_data($scoresort), $scoreinfo->mean()];
            }
            usort($rows, [$this, "_sort_scores"]);
            break;
        }
        if ($this->reverseSort) {
            return array_reverse($rows);
        } else {
            return $rows;
        }
    }

    /** @param int $fieldId
     * @return string */
    function header($fieldId, $row = null) {
        switch ($fieldId) {
        case self::FIELD_NAME:
            return "Name";
        case self::FIELD_EMAIL:
            return "Email";
        case self::FIELD_AFFILIATION:
        case self::FIELD_AFFILIATION_ROW:
            return "Affiliation";
        case self::FIELD_ORCID:
            return "ORCID iD";
        case self::FIELD_COUNTRY:
            return "Country";
        case self::FIELD_LASTVISIT:
            return '<span class="hastitle" title="Includes paper changes, review updates, and profile changes">Last update</span>';
        case self::FIELD_HIGHTOPICS:
            return "High-interest topics";
        case self::FIELD_LOWTOPICS:
            return "Low-interest topics";
        case self::FIELD_REVIEWS:
            if ($this->limit === "extsub") {
                return "Completed reviews";
            } else {
                return '<span class="hastitle" title="“1/2” means 1 complete review out of 2 assigned reviews">Reviews</span>';
            }
        case self::FIELD_INCOMPLETE_REVIEWS:
            if ($this->limit === "extrev-not-accepted") {
                return "Outstanding review requests";
            } else {
                return "Incomplete reviews";
            }
        case self::FIELD_LEADS:
            return "Leads";
        case self::FIELD_SHEPHERDS:
            return "Shepherds";
        case self::FIELD_REVIEW_RATINGS:
            return '<span class="hastitle" title="Ratings of reviews">Rating</a>';
        case self::FIELD_SELECTOR:
            return "";
        case self::FIELD_PAPERS:
            if ($this->limit === "auacc") {
                return "Accepted submissions";
            } else if ($this->limit === "aurej") {
                return "Rejected submissions";
            } else if ($this->limit === "auuns") {
                return "Incomplete submissions";
            } else {
                assert($this->limit === "au" || $this->limit === "all");
                return "Submissions";
            }
        case self::FIELD_REVIEW_PAPERS:
            return "Assigned submissions";
        case self::FIELD_TAGS:
            return "Tags";
        case self::FIELD_COLLABORATORS:
            return "Collaborators";
        default:
            $f = $this->_rfields[$fieldId - self::FIELD_SCORE];
            return $f->web_abbreviation();
        }
    }

    /** @param PaperInfo $prow
     * @param ReviewInfo $rrow */
    private function collect_review_data($prow, $rrow, $repapers, $review_limit) {
        $cid = $rrow->contactId;
        if ($repapers) {
            $this->_re_data[$cid][] = $rrow->paperId;
            $this->_reord_data[$cid][] = [$rrow->paperId, $rrow->reviewId, $rrow->reviewOrdinal];
        }
        if ($review_limit
            && ($rrow->reviewStatus >= ReviewInfo::RS_APPROVED || $prow->timeSubmitted > 0 || $review_limit === "all")) {
            if ($this->limit === "re"
                || ($this->limit === "req" && $rrow->reviewType === REVIEW_EXTERNAL && $rrow->requestedBy == $this->user->contactId)
                || ($this->limit === "ext" && $rrow->reviewType === REVIEW_EXTERNAL)
                || ($this->limit === "extsub" && $rrow->reviewType === REVIEW_EXTERNAL && $rrow->reviewStatus >= ReviewInfo::RS_APPROVED)
                || ($this->limit === "extrev-not-accepted" && $rrow->reviewType === REVIEW_EXTERNAL && $rrow->reviewStatus === ReviewInfo::RS_EMPTY)) {
                $this->_limit_cids[$cid] = true;
            }
        }
        if (!isset($this->_rect_data[$cid])) {
            $this->_rect_data[$cid] = [0, 0];
        }
        if (($this->limit === "extsub" && $rrow->reviewStatus < ReviewInfo::RS_APPROVED)
            || ($this->limit === "extrev-not-accepted" && $rrow->reviewStatus !== ReviewInfo::RS_EMPTY)) {
            return;
        }
        if ($rrow->reviewStatus >= ReviewInfo::RS_APPROVED) {
            $this->_rect_data[$cid][0] += 1;
            $this->_rect_data[$cid][1] += 1;
            if ($this->user->can_view_review($prow, $rrow)) {
                $bound = $this->user->view_score_bound($prow, $rrow);
                if (count($this->_wfields) === 1) {
                    $f = $this->_wfields[0];
                    if ($f->view_score > $bound
                        && ($fv = $rrow->fval($f)) !== null) {
                        $this->_score1_data[$cid][] = $fv;
                    }
                } else {
                    foreach ($this->_wfields as $f) {
                        if ($f->view_score > $bound
                            && ($fv = $rrow->fval($f)) !== null) {
                            $this->_scorex_data[$cid][] = $f->order;
                            $this->_scorex_data[$cid][] = $fv;
                        }
                    }
                }
            }
        } else if ($rrow->reviewNeedsSubmit && $prow->timeSubmitted > 0) {
            $this->_rect_data[$cid][0] += 1;
        }
    }

    private function test_paper_authors(PaperInfo $prow) {
        if ($this->user->can_view_authors($prow)) {
            if ($this->limit === "au") {
                return $prow->timeSubmitted > 0;
            } else if ($this->limit === "auuns") {
                return $prow->timeSubmitted <= 0;
            } else if ($this->limit === "aurej") {
                return $prow->outcome_sign < 0 && $this->user->can_view_decision($prow);
            } else if ($this->limit === "auacc") {
                return $prow->outcome_sign > 0 && $this->user->can_view_decision($prow);
            } else {
                return true;
            }
        } else {
            return false;
        }
    }

    private function collect_paper_data() {
        $limit = $this->limit;
        $review_limit = in_array($limit, ["re", "req", "ext", "extsub", "extrev-not-accepted", "all"], true) ? $limit : null;

        $args = [];
        if (isset($this->qopt["papers"])) {
            $args["allConflictType"] = true;
        }
        if (isset($this->qopt["reviews"]) || $review_limit) {
            $args["reviewSignatures"] = true;
            if ($this->_wfields) {
                $args["scores"] = $this->_wfields;
            }
        }
        if ($limit === "req") {
            $args["myReviewRequests"] = $args["finalized"] = true;
        } else if ($limit === "au") {
            $args["finalized"] = true;
        } else if ($limit === "aurej") {
            $args["dec:no"] = true;
        } else if ($limit === "auacc") {
            $args["dec:yes"] = true;
        } else if ($limit === "auuns") {
            $args["unsub"] = true;
        }
        if (empty($args)
            && !isset($this->qopt["leads"])
            && !isset($this->qopt["shepherds"])
            && !str_starts_with($limit, "au")) {
            return;
        }

        $overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);

        $prows = $this->user->paper_set($args);
        $prows->apply_filter(function ($prow) {
            return $this->user->can_view_paper($prow);
        });

        if (str_starts_with($limit, "au") || $limit === "all") {
            $this->_limit_cids = [];
            foreach ($prows as $prow) {
                if ($this->test_paper_authors($prow)) {
                    foreach ($prow->contact_list() as $u) {
                        $this->_limit_cids[$u->contactId] = true;
                    }
                }
            }
        } else if ($review_limit) {
            $this->_limit_cids = [];
        }

        if (isset($this->qopt["papers"])) {
            $this->_au_data = [];
            $this->_au_unsub = [];
            foreach ($prows as $prow) {
                if ($this->test_paper_authors($prow)) {
                    foreach ($prow->contact_list() as $u) {
                        $this->_au_data[$u->contactId][] = $prow->paperId;
                        if ($prow->timeSubmitted <= 0) {
                            $this->_au_unsub[$u->contactId] = true;
                        }
                    }
                }
            }
        }

        if (isset($this->qopt["reviews"]) || $review_limit) {
            $repapers = $this->qopt["repapers"] ?? false;
            $this->_rect_data = [];
            if ($repapers) {
                $this->_re_data = $this->_reord_data = [];
            }
            foreach ($prows as $prow) {
                if ($this->user->can_view_review_assignment($prow, null)
                    && $this->user->can_view_review_identity($prow, null)) {
                    foreach ($prow->all_reviews() as $rrow) {
                        if ($this->user->can_view_review_assignment($prow, $rrow)
                            && $this->user->can_view_review_identity($prow, $rrow)) {
                            $this->collect_review_data($prow, $rrow, $repapers, $review_limit);
                        }
                    }
                }
            }
        }

        if (isset($this->qopt["revratings"])) {
            $pids = $ratings = [];
            foreach ($prows as $prow) {
                if ($this->user->can_view_review_ratings($prow)) {
                    $pids[] = $prow->paperId;
                }
            }
            $result = $this->conf->qe("select paperId, reviewId, " . $this->conf->rating_signature_query() . " ratingSignature from PaperReview where paperId?a and reviewType>0 group by paperId, reviewId", $pids);
            while (($row = $result->fetch_row())) {
                $ratings[$row[0]][$row[1]] = $row[2];
            }
            Dbl::free($result);
            $this->_rating_data = [];
            foreach ($prows as $prow) {
                if ($this->user->can_view_review_ratings($prow)) {
                    $allow_admin = $this->user->allow_administer($prow);
                    foreach ($prow->all_reviews() as $rrow) {
                        if (isset($ratings[$prow->paperId][$rrow->reviewId])
                            && ($allow_admin
                                || ($this->user->can_view_review_ratings($prow, $rrow)
                                    && $this->user->can_view_review_identity($prow, $rrow)))) {
                            $rrow->ratingSignature = $ratings[$prow->paperId][$rrow->reviewId];
                            $cid = $rrow->contactId;
                            $this->_rating_data[$cid] = $this->_rating_data[$cid] ?? [0, 0];
                            foreach ($rrow->ratings() as $rate) {
                                $good = $rate & ReviewInfo::RATING_GOODMASK ? 0 : 1;
                                $this->_rating_data[$cid][$good] += 1;
                            }
                        }
                    }
                }
            }
        }

        if (isset($this->qopt["leads"])) {
            $this->_lead_data = [];
            foreach ($prows as $prow) {
                if ($prow->leadContactId > 0
                    && $this->user->can_view_lead($prow)) {
                    $c = $prow->leadContactId;
                    $this->_lead_data[$c] = ($this->_lead_data[$c] ?? 0) + 1;
                }
            }
        }

        if (isset($this->qopt["shepherds"])) {
            $this->_shepherd_data = [];
            foreach ($prows as $prow) {
                if ($prow->shepherdContactId > 0
                    && $this->user->can_view_shepherd($prow)) {
                    $c = $prow->shepherdContactId;
                    $this->_shepherd_data[$c] = ($this->_shepherd_data[$c] ?? 0) + 1;
                }
            }
        }

        $this->user->set_overrides($overrides);
    }

    /** @param int $fieldId
     * @param Contact $row
     * @return string */
    function content($fieldId, $row) {
        switch ($fieldId) {
        case self::FIELD_NAME:
            $t = $row->name_h($this->sortField == $fieldId ? NAME_S : 0);
            if (trim($t) === "") {
                $t = "[No name]";
            }
            $t = '<span class="taghl">' . $t . '</span>';
            if ($this->user->privChair) {
                $t = "<a href=\"" . $this->conf->hoturl("profile", "u=" . urlencode($row->email)) . "\"" . ($row->is_disabled() ? ' class="qh"' : "") . ">$t</a>";
            }
            if (($viewable = $row->viewable_tags($this->user))
                && $this->conf->tags()->has(TagInfo::TFM_DECORATION)) {
                $tagger = new Tagger($this->user);
                $t .= $tagger->unparse_decoration_html($viewable, Tagger::DECOR_USER);
            }
            $roles = $row->viewable_pc_roles($this->user);
            if ($roles === Contact::ROLE_PC && $this->limit === "pc") {
                $roles = 0;
            }
            if ($roles !== 0 && ($rolet = Contact::role_html_for($roles))) {
                $t .= " {$rolet}";
            }
            if ($this->user->privChair && $row->email != $this->user->email) {
                $t .= " <a href=\"" . $this->conf->hoturl("index", "actas=" . urlencode($row->email)) . "\">"
                    . Ht::img("viewas.png", "[Act as]", ["title" => "Act as " . $row->name(NAME_P)])
                    . "</a>";
            }
            if ($row->is_disabled() && $this->user->isPC) {
                $t .= ' <span class="hint">(disabled)</span>';
            }
            return $t;
        case self::FIELD_EMAIL:
            if (!$this->user->isPC) {
                return "";
            }
            $e = htmlspecialchars($row->email);
            if (strpos($row->email, "@") === false) {
                return $e;
            } else {
                return "<a href=\"mailto:{$e}\" class=\"q\">{$e}</a>";
            }
        case self::FIELD_AFFILIATION:
        case self::FIELD_AFFILIATION_ROW:
            return htmlspecialchars($row->affiliation);
        case self::FIELD_ORCID:
            return htmlspecialchars($row->orcid());
        case self::FIELD_COUNTRY:
            return htmlspecialchars($row->country_name());
        case self::FIELD_LASTVISIT:
            if (!$row->activity_at) {
                return "Never";
            } else {
                return $this->conf->unparse_time_obscure($row->activity_at);
            }
        case self::FIELD_SELECTOR:
            $this->has_flags |= self::HAS_SELECTOR;
            $c = "";
            if ($this->_select_all
                || ($this->_selection && $this->_selection->is_selected($row->contactId))) {
                $c = ' checked';
            }
            return '<input type="checkbox" class="uic js-range-click js-selector" name="pap[]" value="' . $row->contactId . '" tabindex="1"' . $c . ' />';
        case self::FIELD_HIGHTOPICS:
        case self::FIELD_LOWTOPICS:
            if (!($topics = $row->topic_interest_map())) {
                return "";
            }
            if ($fieldId == self::FIELD_HIGHTOPICS) {
                $nt = array_filter($topics, function ($i) { return $i > 0; });
            } else {
                $nt = array_filter($topics, function ($i) { return $i < 0; });
            }
            return $this->conf->topic_set()->unparse_list_html(array_keys($nt), $nt);
        case self::FIELD_REVIEWS:
            if (($ct = $this->_rect_data[$row->contactId] ?? null)) {
                $a1 = "<a href=\"" . $this->conf->hoturl("search", "t=s&amp;q=re:" . urlencode($row->email)) . "\">";
                if ($ct[0] === $ct[1]) {
                    return $a1 . "<b>{$ct[1]}</b></a>";
                } else {
                    return $a1 . "<b>{$ct[1]}</b>/{$ct[0]}</a>";
                }
            } else {
                return "";
            }
        case self::FIELD_INCOMPLETE_REVIEWS:
            if (($ct = $this->_rect_data[$row->contactId] ?? null)) {
                return "<a href=\"" . $this->conf->hoturl("search", "t=s&amp;q=ire:" . urlencode($row->email)) . "\">{$ct[0]}</a>";
            } else {
                return "";
            }
        case self::FIELD_LEADS:
            if (($c = $this->_lead_data[$row->contactId] ?? null)) {
                return "<a href=\"" . $this->conf->hoturl("search", "t=s&amp;q=lead:" . urlencode($row->email)) . "\">$c</a>";
            } else {
                return "";
            }
        case self::FIELD_SHEPHERDS:
            if (($c = $this->_shepherd_data[$row->contactId] ?? null)) {
                return "<a href=\"" . $this->conf->hoturl("search", "t=s&amp;q=shepherd:" . urlencode($row->email)) . "\">$c</a>";
            } else {
                return "";
            }
        case self::FIELD_REVIEW_RATINGS:
            if (($c = $this->_rating_data[$row->contactId] ?? null)
                && ($c[0] || $c[1])) {
                $a = $b = [];
                if ($c[0]) {
                    $a[] = "{$c[0]} positive";
                    $b[] = "<a href=\"" . $this->conf->hoturl("search", "q=rate:good:" . urlencode($row->email)) . "\">+{$c[0]}</a>";
                }
                if ($c[1]) {
                    $a[] = "{$c[1]} negative";
                    $b[] = "<a href=\"" . $this->conf->hoturl("search", "q=rate:bad:" . urlencode($row->email)) . "\">&minus;{$c[1]}</a>";
                }
                return '<span class="hastitle" title="' . join(", ", $a) . '">' . join(" ", $b) . '</span>';
            } else {
                return "";
            }
        case self::FIELD_PAPERS:
            if (($pids = $this->_au_data[$row->contactId] ?? null)) {
                $t = [];
                foreach ($pids as $p) {
                    $t[] = '<a href="' . $this->conf->hoturl("paper", "p=$p") . '">' . $p . '</a>';
                }
                $lsx = "au:{$row->email}";
                if ($this->limit === "auuns") {
                    $lsx .= " -is:submitted";
                } else if ($this->limit === "auacc") {
                    $lsx .= " dec:yes";
                } else if ($this->limit === "aurej") {
                    $lsx .= " dec:no";
                }
                $lst = $this->_au_unsub[$row->contactId] ?? false ? "all" : "s";
                return '<div class="has-hotlist" data-hotlist="'
                    . htmlspecialchars("p/$lst/" . urlencode($lsx)) . '">' . join(", ", $t) . '</div>';
            } else {
                return "";
            }
        case self::FIELD_REVIEW_PAPERS:
            $t = [];
            if (($reords = $this->_reord_data[$row->contactId] ?? null)) {
                $last = null;
                foreach ($reords as $reord) {
                    if ($last !== $reord[0])  {
                        if ($reord[2]) {
                            $url = $this->conf->hoturl("paper", "p={$reord[0]}#r{$reord[0]}" . unparse_latin_ordinal($reord[2]));
                        } else {
                            $url = $this->conf->hoturl("review", "p={$reord[0]}&amp;r={$reord[1]}");
                        }
                        $t[] = "<a href=\"{$url}\">{$reord[0]}</a>";
                    }
                    $last = $reord[0];
                }
            }
            if (!empty($t)) {
                $ls = htmlspecialchars("p/s/" . urlencode("re:" . $row->email));
                return '<div class="has-hotlist" data-hotlist="' . $ls . '">'
                    . join(", ", $t) . '</div>';
            } else {
                return "";
            }
        case self::FIELD_TAGS:
            if ($this->user->isPC
                && ($tags = $row->viewable_tags($this->user))) {
                $x = [];
                foreach (Tagger::split($tags) as $t) {
                    if ($t !== "pc#0")
                        $x[] = '<a class="q nw" href="' . $this->conf->hoturl("users", "t=%23" . Tagger::tv_tag($t)) . '">' . $this->tagger->unparse_hashed($t) . '</a>';
                }
                return join(" ", $x);
            } else {
                return "";
            }
        case self::FIELD_COLLABORATORS:
            if ($this->user->isPC && ($row->roles & Contact::ROLE_PC)) {
                $t = [];
                foreach ($row->collaborator_generator() as $co) {
                    $t[] = (empty($t) ? '' : ';</span> ') . '<span class="nw">' . $co->name_h(NAME_A);
                }
                return empty($t) ? "" : join("", $t) . '</span>';
            } else {
                return "";
            }
        default:
            if (($row->roles & Contact::ROLE_PC)
                || $this->user->privChair
                || $this->limit === "req") {
                $f = $this->_rfields[$fieldId - self::FIELD_SCORE];
                if (($scores = $this->_extract_scores($row->contactId, $f))) {
                    return $f->unparse_graph(new ScoreInfo($scores), Discrete_ReviewField::GRAPH_PROPORTIONS);
                }
            }
            return "";
        }
    }

    /** @return string */
    static function uldisplay(Qrequest $qreq, $no_session = false) {
        if ($no_session || ($uldisplay = $qreq->csession("uldisplay")) === null) {
            $uldisplay = " tags ";
            foreach ($qreq->conf()->review_form()->highlighted_main_scores() as $rf) {
                $uldisplay .= "{$rf->short_id} ";
            }
        }
        return $uldisplay;
    }

    /** @param string $columnlist
     * @return list<Column> */
    function _resolve_columns($columnlist) {
        $uldisplay = self::uldisplay($this->qreq);
        $cols = [];
        foreach (explode(" ", $columnlist) as $colname) {
            if ($colname === "") {
                continue;
            } else if (($col = $this->_column($colname))) {
                $cols[] = $col;
            } else if ($colname === "scores" && $this->user->isPC) {
                foreach ($this->_rfields as $i => $f) {
                    if (strpos($uldisplay, " {$f->short_id} ") !== false)
                        $cols[] = $this->_column(self::FIELD_SCORE + $i);
                }
            }
        }
        return $cols;
    }

    /** @return list<Column> */
    private function list_columns($listname) {
        $this->limit = $listname;
        switch ($listname) {
        case "pc":
        case "admin":
        case "pcadmin":
            return $this->_resolve_columns("sel name email aff orcid country lastvisit tags collab topicshi topicslo reviews revratings lead shepherd scores");
        case "pcadminx":
            return $this->_resolve_columns("name email aff orcid country lastvisit tags collab topicshi topicslo");
        case "re":
            return $this->_resolve_columns("sel name email aff orcid country lastvisit tags collab topicshi topicslo reviews revratings scores");
        case "ext":
        case "extsub":
            return $this->_resolve_columns("sel name email aff orcid country lastvisit collab topicshi topicslo reviews revratings repapers scores");
        case "extrev-not-accepted":
            return $this->_resolve_columns("sel name email aff orcid country lastvisit collab topicshi topicslo ire repapers scores");
        case "req":
            return $this->_resolve_columns("sel name email aff orcid country lastvisit tags collab topicshi topicslo reviews revratings repapers scores");
        case "au":
        case "aurej":
        case "auuns":
        case "auacc":
            return $this->_resolve_columns("sel name email affrow orcid country lastvisit tags papers collab");
        case "all":
            return $this->_resolve_columns("sel name email affrow orcid country lastvisit tags papers reviews collab");
        default:
            return [];
        }
    }

    function footer($ncol, $hascolors) {
        if ($this->count === 0) {
            return "";
        }
        $lllgroups = [];

        // Begin linelinks
        $types = ["nameemail" => "Names and emails"];
        if ($this->user->privChair) {
            $types["pcinfo"] = "PC info";
        }
        $lllgroups[] = ["", "Download",
            Ht::select("getfn", $types, null, ["class" => "want-focus"])
            . Ht::submit("fn", "Go", ["value" => "get", "class" => "uic js-submit-list ml-2 can-submit-all"])];

        if ($this->user->privChair) {
            $lllgroups[] = ["", "Tag",
                Ht::select("tagfn", ["a" => "Add", "d" => "Remove", "s" => "Define"], $this->qreq->tagfn)
                . ' &nbsp;tag(s) &nbsp;'
                . Ht::entry("tag", $this->qreq->tag, ["size" => 15, "class" => "want-focus js-autosubmit", "data-submit-fn" => "tag"])
                . Ht::submit("fn", "Go", ["value" => "tag", "class" => "uic js-submit-list ml-2"])];

            $mods = [
                "disableaccount" => "Disable",
                "enableaccount" => "Enable"
            ];
            if ($this->user->can_edit_any_password()) {
                $mods["resetpassword"] = "Reset password";
            }
            $mods["sendaccount"] = "Send account information";
            $mods[] = null;
            if ($this->has("nonpc")) {
                $mods["add_pc"] = "Add to PC";
            }
            if ($this->has("pc")) {
                $mods["remove_pc"] = "Remove from PC";
            }
            $lllgroups[] = ["", "Modify",
                Ht::select("modifyfn", $mods, null, ["class" => "want-focus"])
                . Ht::submit("fn", "Go", ["value" => "modify", "class" => "uic js-submit-list ml-2"])];
        }

        return "  <tfoot class=\"pltable-tfoot" . ($hascolors ? " pltable-colored" : "")
            . "\">" . PaperList::render_footer_row(1, $ncol - 1,
                "<b>Select people</b> (or <a class=\"ui js-select-all\" href=\"\">select all {$this->count}</a>), then&nbsp; ",
                $lllgroups)
            . "</tfoot>\n";
    }

    function _rows() {
        // Collect paper data first
        $this->collect_paper_data();

        $mainwhere = [];
        if (isset($this->qopt["where"])) {
            $mainwhere[] = $this->qopt["where"];
        }
        if ($this->limit == "pc") {
            $mainwhere[] = "roles!=0 and (roles&" . Contact::ROLE_PC . ")!=0";
        } else if ($this->limit == "admin") {
            $mainwhere[] = "roles!=0 and (roles&" . (Contact::ROLE_ADMIN | Contact::ROLE_CHAIR) . ")!=0";
        } else if ($this->limit == "pcadmin" || $this->limit == "pcadminx") {
            $mainwhere[] = "roles!=0 and (roles&" . Contact::ROLE_PCLIKE . ")!=0";
        }
        if ($this->limit === "all") {
            $mainwhere[] = "(roles!=0 or lastLogin>0 or contactId" . sql_in_int_list(array_keys($this->_limit_cids)) . ")";
        } else if ($this->_limit_cids !== null) {
            $mainwhere[] = "contactId" . sql_in_int_list(array_keys($this->_limit_cids));
        }
        $mainwhere[] = "(cflags&" . Contact::CF_PLACEHOLDER . ")=0";

        // make query
        $result = $this->conf->qe_raw("select * from ContactInfo" . (empty($mainwhere) ? "" : " where " . join(" and ", $mainwhere)));
        $rows = [];
        while (($row = Contact::fetch($result, $this->conf))) {
            if (!$row->is_anonymous_user() && !$row->is_placeholder()) {
                $rows[] = $row;
            }
        }
        Dbl::free($result);
        if (isset($this->qopt["topics"])) {
            Contact::load_topic_interests($rows);
        }
        return $rows;
    }

    private function _next_sort_link($sortUrl) {
        $fld = $this->sortField;
        if ($fld === self::FIELD_NAME) {
            $fld = $this->conf->sort_by_last ? self::FIELD_LAST : self::FIELD_FIRST;
        }
        if (($fld === self::FIELD_LAST || $fld === self::FIELD_FIRST)
            && $this->reverseSort) {
            $fld = $fld === self::FIELD_LAST ? self::FIELD_FIRST : self::FIELD_LAST;
        }
        $column = $this->_column($fld);
        return $sortUrl . ($this->reverseSort ? "" : "-") . $column->name;
    }

    function print_table_html($listname, $url, $listtitle = "", $foldsession = null) {
        // PC tags
        $listquery = $listname;
        $this->qopt = [];
        if (str_starts_with($listname, "#")) {
            $x = sqlq(Dbl::escape_like(substr($listname, 1)));
            $this->qopt["where"] = "(contactTags like " . Dbl::utf8ci("'% {$x}#%'") . ")";
            $listquery = "pcadmin";
        }

        // get paper list
        $columns = $this->list_columns($listquery);
        if (empty($columns)) {
            echo Ht::feedback_msg(MessageItem::error("<0>User set ‘{$listquery}’ not found"));
            return;
        }

        // get field array
        $fieldDef = [];
        $acceptable_fields = [];
        $uldisplay = self::uldisplay($this->qreq);
        $this->has_flags = 0;
        $ncol = 0;
        foreach ($columns as $col) {
            if ($this->column_visible($col)
                && $this->column_unfolded($col, $uldisplay)) {
                $this->column_query_options($col);
                $acceptable_fields[$col->order] = true;
                $fieldDef[$col->order] = $col;
                if (!$col->as_row) {
                    ++$ncol;
                }
            }
        }

        // run query
        $rows = $this->_rows();
        if (empty($rows)) {
            return "No matching people";
        }

        // sort rows
        if (!$this->sortField
            || (!($acceptable_fields[$this->sortField] ?? false)
                && $this->sortField !== self::FIELD_FIRST
                && $this->sortField !== self::FIELD_LAST)) {
            $this->sortField = self::FIELD_NAME;
        }
        $srows = $this->_sort($rows);

        // count non-callout columns
        $firstcallout = $lastcallout = null;
        $n = 0;
        foreach ($fieldDef as $fieldId => $fdef) {
            if (!$fdef->as_row) {
                if ($firstcallout === null && $fieldId < self::FIELD_SELECTOR) {
                    $firstcallout = $n;
                }
                if ($fieldId < self::FIELD_SCORE) {
                    $lastcallout = $n + 1;
                }
                ++$n;
            }
        }
        $firstcallout = $firstcallout ? $firstcallout : 0;
        $lastcallout = ($lastcallout ? $lastcallout : $ncol) - $firstcallout;

        // collect row data
        $this->count = 0;
        $show_colors = $this->user->isPC;

        $anyData = [];
        $bodyrows = [];
        $extrainfo = $hascolors = false;
        $ids = [];
        foreach ($srows as $row) {
            if (($this->limit == "resub" || $this->limit == "extsub")
                && (!isset($this->_rect_data[$row->contactId])
                    || $this->_rect_data[$row->contactId][1] === 0)) {
                continue;
            }

            $trclass = "k" . ($this->count % 2);
            if ($show_colors && ($k = $row->viewable_color_classes($this->user))) {
                if (str_ends_with($k, " tagbg")) {
                    $trclass = $k;
                    $hascolors = true;
                } else {
                    $trclass .= " " . $k;
                }
            }
            if ($row->is_disabled() && $this->user->isPC) {
                $trclass .= " dim";
            }
            $this->count++;
            $ids[] = (int) $row->contactId;
            if ($row->roles & Contact::ROLE_PC) {
                $this->has_flags |= self::HAS_PC;
            } else {
                $this->has_flags |= self::HAS_NONPC;
            }

            // First create the expanded callout row
            $tt = "";
            foreach ($fieldDef as $fieldId => $fdef) {
                if ($fdef->as_row
                    && ($d = $this->content($fieldId, $row)) !== "") {
                    $tt .= "<div";
                    if ($fdef->fold) {
                        $tt .= " class=\"fx{$fdef->fold}\"";
                    }
                    $tt .= '><em class="plx">' . $this->header($fieldId, $row)
                        . ":</em> " . $d . "</div>";
                }
            }

            if ($tt !== "") {
                $x = "  <tr class=\"plx {$trclass}\">";
                if ($firstcallout > 0) {
                    $x .= "<td colspan=\"{$firstcallout}\"></td>";
                }
                $tt = $x . "<td class=\"plx\" colspan=\"" . ($lastcallout - $firstcallout)
                    . "\">" . $tt . "</td></tr>\n";
            }

            // Now the normal row
            $t = "  <tr class=\"pl {$trclass}" . ($tt !== "" ? "" : " plnx") . "\">";
            $n = 0;
            foreach ($fieldDef as $fieldId => $fdef) {
                if (!$fdef->as_row) {
                    $c = $this->content($fieldId, $row);
                    $t .= "<td class=\"pl {$fdef->className}\"";
                    if ($n >= $lastcallout && $tt != "") {
                        $t .= " rowspan=\"2\"";
                    }
                    $t .= ">" . $c . "</td>";
                    if ($c != "") {
                        $anyData[$fieldId] = true;
                    }
                    ++$n;
                }
            }
            $t .= "</tr>\n";

            $bodyrows[] = $t . $tt;
        }

        $uldisplay = self::uldisplay($this->qreq);
        $foldclasses = [];
        foreach (self::$folds as $k => $fold) {
            if (($this->have_folds[$fold] ?? null) !== null) {
                $this->have_folds[$fold] = strpos($uldisplay, " {$fold} ") !== false;
                $foldclasses[] = "fold" . ($k + 1) . ($this->have_folds[$fold] ? "o" : "c");
            }
        }

        echo "<table id=\"foldul\" class=\"pltable fullw";
        if ($foldclasses) {
            echo " ", join(" ", $foldclasses);
        }
        if ($foldclasses && $foldsession) {
            $fs = [];
            foreach (self::$folds as $k => $fold) {
                $fs[$k + 1] = $fold;
            }
            echo "\" data-fold-session=\"", htmlspecialchars(json_encode_browser($fs)), "\" data-fold-session-prefix=\"", htmlspecialchars($foldsession);
        }
        echo "\">\n";

        echo "  <thead class=\"pltable-thead\">\n  <tr class=\"pl_headrow\">";

        if ($this->sortable && $url) {
            $sortUrl = $url . (strpos($url, "?") ? "&amp;" : "?") . "sort=";
            $sortField = $this->sortField;
            if ($sortField === self::FIELD_FIRST || $sortField === self::FIELD_LAST) {
                $sortField = self::FIELD_NAME;
            }
            $q = '<a class="pl_sort" rel="nofollow" href="' . $sortUrl;
            foreach ($fieldDef as $fieldId => $fdef) {
                if ($fdef->as_row) {
                    continue;
                }
                if (!isset($anyData[$fieldId])) {
                    echo "<th class=\"pl plh {$fdef->className}\"></th>";
                    continue;
                }
                echo "<th class=\"pl plh {$fdef->className}\">";
                $ftext = $this->header($fieldId);
                if ($fieldId === $sortField) {
                    $klass = $this->reverseSort ? "sort-descending" : "sort-ascending";
                    $qx = $this->_next_sort_link($sortUrl);
                    echo "<a class=\"pl_sort {$klass}\" rel=\"nofollow\" href=\"{$qx}\">{$ftext}</a>";
                } else if ($fdef->sort) {
                    echo "{$q}{$fdef->name}\">{$ftext}</a>";
                } else {
                    echo $ftext;
                }
                echo "</th>";
            }

        } else {
            foreach ($fieldDef as $fieldId => $fdef) {
                if (!$fdef->as_row && isset($anyData[$fieldId])) {
                    echo "<th class=\"pl plh {$fdef->className}\">",
                        $this->header($fieldId), "</th>";
                } else if (!$fdef->as_row) {
                    echo "<th class=\"pl plh {$fdef->className}\"></th>";
                }
            }
        }

        echo "</tr></thead>\n";

        echo "<tbody class=\"pltable-tbody" . ($hascolors ? " pltable-colored" : "");
        if ($this->user->privChair) {
            $listlink = $listname;
            if ($listlink === "pcadminx") {
                $listlink = "pcadmin";
            } else if ($listtitle === "") {
                if ($listlink === "pcadmin") {
                    $listtitle = "PC and admins";
                } else {
                    $listtitle = "Users";
                }
            }
            $l = (new SessionList("u/{$listlink}", $ids, $listtitle))
                ->set_urlbase($this->conf->hoturl_raw("users", ["t" => $listlink], Conf::HOTURL_SITEREL));
            echo " has-hotlist\" data-hotlist=\"", htmlspecialchars($l->info_string());
        }
        echo "\">";
        foreach ($bodyrows as $t) {
            echo $t;
        }
        echo "</tbody>";

        reset($fieldDef);
        if (key($fieldDef) == self::FIELD_SELECTOR) {
            echo $this->footer($ncol, $hascolors);
        }

        echo "</table>";
    }

    /** @return string */
    function table_html($listname, $url, $listtitle = "", $foldsession = null) {
        ob_start();
        $this->print_table_html($listname, $url, $listtitle, $foldsession);
        return ob_get_clean();
    }

    function rows($listname) {
        // PC tags
        $this->qopt = [];
        if (str_starts_with($listname, "#")) {
            $x = sqlq(Dbl::escape_like(substr($listname, 1)));
            $this->qopt["where"] = "(contactTags like " . Dbl::utf8ci("'% {$x}#%'") . ")";
            $listname = "pc";
        }

        // get paper list
        if (!$this->list_columns($listname)) {
            $this->conf->error_msg("<0>User list ‘{$listname}’ not found");
            return null;
        }

        // run query
        return $this->_rows();
    }

    /** @param string $field
     * @return bool */
    function has($field) {
        if ($field === "sel") {
            return ($this->has_flags & self::HAS_SELECTOR) !== 0;
        } else if ($field === "pc") {
            return ($this->has_flags & self::HAS_PC) !== 0;
        } else if ($field === "nonpc") {
            return ($this->has_flags & self::HAS_NONPC) !== 0;
        }
        return false;
    }
}
