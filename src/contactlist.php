<?php
// contactlist.php -- HotCRP helper class for producing lists of contacts
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ContactList {
    const FIELD_SELECTOR = 1000;
    const FIELD_SELECTOR_ON = 1001;

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
    const FIELD_SCORE = 50;

    /** @var list<string> */
    public static $folds = ["topics", "aff", "tags", "collab"];

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
    /** @var object */
    public $any;
    /** @var Tagger */
    private $tagger;
    private $limit;
    public $have_folds = [];
    private $qopt = [];
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
    /** @var list<Score_ReviewField> */
    private $_rfields = [];
    /** @var list<array<int,list<int>>> */
    private $_score_data;
    /** @var array<int,array{int,int}> */
    private $_rating_data;
    /** @var array<int,true> */
    private $_limit_cids;
    /** @var array<int,array> */
    private $_sort_data;

    function __construct(Contact $user, $sortable = true, $qreq = null) {
        $this->conf = $user->conf;
        $this->user = $user;
        if (!$qreq || !($qreq instanceof Qrequest)) {
            $qreq = new Qrequest("GET", $qreq);
        }
        $this->qreq = $qreq;

        $this->tagger = new Tagger($this->user);
        foreach ($this->conf->review_form()->viewable_fields($this->user) as $f) {
            if ($f instanceof Score_ReviewField)
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
            if (($fs = $this->_fieldspec($s))) {
                $this->sortField = $fs[3];
            }
        }
    }

    /** @param int|string $fieldId
     * @return array{string,int,int,int,string} */
    private function _fieldspec($fieldId) {
        switch ($fieldId) {
        case self::FIELD_SELECTOR:
        case "sel":
            return ["sel", 1, 0, self::FIELD_SELECTOR, "sel"];
        case self::FIELD_SELECTOR_ON:
        case "selon":
            return ["sel", 1, 0, self::FIELD_SELECTOR, "selon"];
        case self::FIELD_NAME:
        case "name":
            return ['name', 1, 1, self::FIELD_NAME, "name"];
        case self::FIELD_EMAIL:
        case "email":
            return ['email', 1, 1, self::FIELD_EMAIL, "email"];
        case self::FIELD_AFFILIATION:
        case "aff":
            return ['affiliation', 1, 1, self::FIELD_AFFILIATION, "aff"];
        case self::FIELD_AFFILIATION_ROW:
        case "affrow":
            return ['affrow', 4, 0, self::FIELD_AFFILIATION_ROW, "affrow"];
        case self::FIELD_LASTVISIT:
        case "lastvisit":
            return ['lastvisit', 1, 1, self::FIELD_LASTVISIT, "lastvisit"];
        case self::FIELD_HIGHTOPICS:
        case "topicshi":
            return ['topics', 3, 0, self::FIELD_HIGHTOPICS, "topicshi"];
        case self::FIELD_LOWTOPICS:
        case "topicslo":
            return ['topics', 3, 0, self::FIELD_LOWTOPICS, "topicslo"];
        case self::FIELD_REVIEWS:
        case "reviews":
            return ['revstat', 1, 1, self::FIELD_REVIEWS, "reviews"];
        case self::FIELD_REVIEW_RATINGS:
        case "revratings":
            return ['revstat', 1, 1, self::FIELD_REVIEW_RATINGS, "revratings"];
        case self::FIELD_PAPERS:
        case "papers":
            return ['papers', 1, 1, self::FIELD_PAPERS, "papers"];
        case self::FIELD_REVIEW_PAPERS:
        case "repapers":
            return ['papers', 1, 1, self::FIELD_REVIEW_PAPERS, "repapers"];
        case self::FIELD_LEADS:
        case "lead":
            return ['revstat', 1, 1, self::FIELD_LEADS, "lead"];
        case self::FIELD_SHEPHERDS:
        case "shepherd":
            return ['revstat', 1, 1, self::FIELD_SHEPHERDS, "shepherd"];
        case self::FIELD_TAGS:
        case "tags":
            return ['tags', 5, 0, self::FIELD_TAGS, "tags"];
        case self::FIELD_COLLABORATORS:
        case "collab":
            return ['collab', 6, 0, self::FIELD_COLLABORATORS, "collab"];
        default:
            if (is_int($fieldId)
                && $fieldId >= self::FIELD_SCORE
                && $fieldId < self::FIELD_SCORE + count($this->_rfields)) {
                return ["uscores", 1, 1, $fieldId, $this->_rfields[$fieldId - self::FIELD_SCORE]->uid()];
            } else if (is_string($fieldId)
                       && ($f = $this->conf->review_field($fieldId) ?? $this->conf->find_review_field($fieldId))
                       && ($p = array_search($f, $this->_rfields)) !== false) {
                return ["uscores", 1, 1, self::FIELD_SCORE + $p, $f->uid()];
            } else {
                return null;
            }
        }
    }



    /** @param int $fieldId */
    function selector($fieldId) {
        if (!$this->user->isPC
            && $fieldId !== self::FIELD_NAME
            && $fieldId !== self::FIELD_AFFILIATION
            && $fieldId !== self::FIELD_AFFILIATION_ROW) {
            return false;
        } else if (in_array($fieldId, [self::FIELD_NAME, self::FIELD_SELECTOR, self::FIELD_SELECTOR_ON, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_LASTVISIT], true)) {
            return true;
        }
        switch ($fieldId) {
        case self::FIELD_HIGHTOPICS:
        case self::FIELD_LOWTOPICS:
            $this->have_folds["topics"] = $this->qopt["topics"] = true;
            break;
        case self::FIELD_REVIEWS:
            $this->qopt["reviews"] = true;
            break;
        case self::FIELD_LEADS:
            $this->qopt["papers"] = $this->qopt["leads"] = true;
            break;
        case self::FIELD_SHEPHERDS:
            $this->qopt["papers"] = $this->qopt["shepherds"] = true;
            break;
        case self::FIELD_REVIEW_RATINGS:
            if ($this->conf->setting("rev_ratings") == REV_RATINGS_NONE) {
                return false;
            }
            $this->qopt["revratings"] = $this->qopt["reviews"] = true;
            break;
        case self::FIELD_PAPERS:
            $this->qopt["papers"] = $this->limit;
            break;
        case self::FIELD_REVIEW_PAPERS:
            $this->qopt["repapers"] = $this->qopt["reviews"] = true;
            break;
        case self::FIELD_AFFILIATION_ROW:
            $this->have_folds["aff"] = true;
            break;
        case self::FIELD_TAGS:
            $this->have_folds["tags"] = true;
            break;
        case self::FIELD_COLLABORATORS:
            $this->have_folds["collab"] = true;
            break;
        default:
            $f = $this->_rfields[$fieldId - self::FIELD_SCORE];
            if (!$this->user->can_view_some_review_field($f)) {
                return false;
            }
            $this->qopt["reviews"] = true;
            $this->qopt["scores"][] = $f;
            break;
        }
        return true;
    }

    function _sortBase($a, $b) {
        return call_user_func($this->conf->user_comparator(), $a, $b);
    }

    function _sortEmail($a, $b) {
        return strnatcasecmp($a->email, $b->email);
    }

    function _sortAffiliation($a, $b) {
        $x = strnatcasecmp($a->affiliation, $b->affiliation);
        return $x ? : $this->_sortBase($a, $b);
    }

    function _sortLastVisit($a, $b) {
        return $b->activity_at <=> $a->activity_at ? : $this->_sortBase($a, $b);
    }

    function _sortReviews($a, $b) {
        $ac = $this->_rect_data[$a->contactId] ?? [0, 0];
        $bc = $this->_rect_data[$b->contactId] ?? [0, 0];
        return $bc[1] <=> $ac[1] ? : ($bc[0] <=> $ac[0] ? : $this->_sortBase($a, $b));
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
        case self::FIELD_EMAIL:
            usort($rows, [$this, "_sortEmail"]);
            break;
        case self::FIELD_AFFILIATION:
        case self::FIELD_AFFILIATION_ROW:
            usort($rows, [$this, "_sortAffiliation"]);
            break;
        case self::FIELD_LASTVISIT:
            usort($rows, [$this, "_sortLastVisit"]);
            break;
        case self::FIELD_REVIEWS:
            usort($rows, [$this, "_sortReviews"]);
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
            $f = $this->_rfields[$this->sortField - self::FIELD_SCORE];
            $scoresort = $this->user->session("ulscoresort") ?? "A";
            if (!in_array($scoresort, ["A", "V", "D"], true)) {
                $scoresort = "A";
            }
            foreach ($rows as $row) {
                $scores = $this->_score_data[$f->order][$row->contactId] ?? [];
                $scoreinfo = new ScoreInfo($scores, true);
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
        case self::FIELD_LASTVISIT:
            return '<span class="hastitle" title="Includes paper changes, review updates, and profile changes">Last update</span>';
        case self::FIELD_HIGHTOPICS:
            return "High-interest topics";
        case self::FIELD_LOWTOPICS:
            return "Low-interest topics";
        case self::FIELD_REVIEWS:
            return '<span class="hastitle" title="“1/2” means 1 complete review out of 2 assigned reviews">Reviews</span>';
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
     * @param ReviewInfo $rrow
     * @param list<int> $forders */
    private function collect_review_data($prow, $rrow, $repapers, $review_limit, $forders) {
        $cid = $rrow->contactId;
        if ($repapers) {
            $this->_re_data[$cid][] = $rrow->paperId;
            $this->_reord_data[$cid][] = [$rrow->paperId, $rrow->reviewId, $rrow->reviewOrdinal];
        }
        if ($review_limit
            && ($rrow->reviewStatus >= ReviewInfo::RS_ADOPTED || $prow->timeSubmitted > 0 || $review_limit === "all")) {
            if ($this->limit === "re"
                || ($this->limit === "req" && $rrow->reviewType == REVIEW_EXTERNAL && $rrow->requestedBy == $this->user->contactId)
                || ($this->limit === "ext" && $rrow->reviewType == REVIEW_EXTERNAL)
                || ($this->limit === "extsub" && $rrow->reviewType == REVIEW_EXTERNAL && $rrow->reviewStatus >= ReviewInfo::RS_ADOPTED)) {
                $this->_limit_cids[$cid] = true;
            }
        }
        if (!isset($this->_rect_data[$cid])) {
            $this->_rect_data[$cid] = [0, 0];
        }
        if ($rrow->reviewStatus >= ReviewInfo::RS_ADOPTED) {
            $this->_rect_data[$cid][0] += 1;
            $this->_rect_data[$cid][1] += 1;
            if ($this->user->can_view_review($prow, $rrow)) {
                foreach ($forders as $i) {
                    if ($rrow->fields[$i]) {
                        $this->_score_data[$i][$cid][] = $rrow->fields[$i];
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
                return $prow->outcome < 0 && $this->user->can_view_decision($prow);
            } else if ($this->limit === "auacc") {
                return $prow->outcome > 0 && $this->user->can_view_decision($prow);
            } else {
                return true;
            }
        } else {
            return false;
        }
    }

    private function collect_paper_data() {
        $limit = $this->limit;
        $review_limit = in_array($limit, ["re", "req", "ext", "extsub", "all"]) ? $limit : null;

        $args = [];
        if (isset($this->qopt["papers"])) {
            $args["allConflictType"] = true;
        }
        if (isset($this->qopt["reviews"]) || $review_limit) {
            $args["reviewSignatures"] = true;
            if (isset($this->qopt["scores"])) {
                $args["scores"] = $this->qopt["scores"];
            }
        }
        if ($limit === "req") {
            $args["myReviewRequests"] = $args["finalized"] = true;
        } else if ($limit === "au") {
            $args["finalized"] = true;
        } else if ($limit === "aurej") {
            $args["rejected"] = true;
        } else if ($limit === "auacc") {
            $args["accepted"] = true;
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
                    foreach ($prow->contacts() as $cid => $cflt) {
                        $this->_limit_cids[$cid] = true;
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
                    foreach ($prow->contacts() as $cflt) {
                        $this->_au_data[$cflt->contactId][] = $prow->paperId;
                        if ($prow->timeSubmitted <= 0) {
                            $this->_au_unsub[$cflt->contactId] = true;
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
            $forders = [];
            foreach ($this->qopt["scores"] ?? [] as $f) {
                $forders[] = $f->order;
            }
            $this->_score_data = $this->conf->review_form()->order_array([]);
            foreach ($prows as $prow) {
                if ($this->user->can_view_review_assignment($prow, null)
                    && $this->user->can_view_review_identity($prow, null)) {
                    foreach ($prow->all_reviews() as $rrow) {
                        if ($this->user->can_view_review_assignment($prow, $rrow)
                            && $this->user->can_view_review_identity($prow, $rrow)) {
                            $this->collect_review_data($prow, $rrow, $repapers, $review_limit, $forders);
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
            $result = $this->conf->qe("select paperId, reviewId, " . $this->conf->query_ratings() . " ratingSignature from PaperReview where paperId?a and reviewType>0 group by paperId, reviewId", $pids);
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
                && $this->conf->tags()->has_decoration) {
                $tagger = new Tagger($this->user);
                $t .= $tagger->unparse_decoration_html($viewable, Tagger::DECOR_USER);
            }
            $roles = $row->viewable_pc_roles($this->user);
            if ($roles === Contact::ROLE_PC && $this->limit === "pc") {
                $roles = 0;
            }
            if ($roles !== 0 && ($rolet = Contact::role_html_for($roles))) {
                $t .= " $rolet";
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
            if ($this->user->isPC) {
                $e = htmlspecialchars($row->email);
                if (strpos($row->email, "@") === false) {
                    return $e;
                } else {
                    return "<a href=\"mailto:$e\" class=\"q\">$e</a>";
                }
            } else {
                return "";
            }
        case self::FIELD_AFFILIATION:
        case self::FIELD_AFFILIATION_ROW:
            return htmlspecialchars($row->affiliation);
        case self::FIELD_LASTVISIT:
            if (!$row->activity_at) {
                return "Never";
            } else {
                return $this->conf->unparse_time_obscure($row->activity_at);
            }
        case self::FIELD_SELECTOR:
        case self::FIELD_SELECTOR_ON:
            $this->any->sel = true;
            $c = "";
            if ($fieldId == self::FIELD_SELECTOR_ON) {
                $c = ' checked="checked"';
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
                        $x[] = '<a class="q nw" href="' . $this->conf->hoturl("users", "t=%23" . Tagger::base($t)) . '">' . $this->tagger->unparse_hashed($t) . '</a>';
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
                if (($scores = $this->_score_data[$f->order][$row->contactId] ?? [])) {
                    return $f->unparse_graph(new ScoreInfo($scores, true), 2);
                }
            }
            return "";
        }
    }

    /** @return string */
    static function uldisplay(Contact $user, $no_session = false) {
        if ($no_session || ($uldisplay = $user->session("uldisplay")) === null) {
            $uldisplay = " tags ";
            foreach ($user->conf->review_form()->highlighted_main_scores() as $rf) {
                $uldisplay .= "{$rf->short_id} ";
            }
        }
        return $uldisplay;
    }

    /** @param list<int> $a
     * @return list<int> */
    private function addScores($a) {
        if ($this->user->isPC) {
            $uldisplay = self::uldisplay($this->user);
            foreach ($this->_rfields as $i => $f) {
                if (strpos($uldisplay, " {$f->short_id} ") !== false)
                    $a[] = self::FIELD_SCORE + $i;
            }
        }
        return $a;
    }

    /** @return list<int> */
    function listFields($listname) {
        $this->limit = $listname;
        switch ($listname) {
        case "pc":
        case "admin":
        case "pcadmin":
            return $this->addScores([self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_LASTVISIT, self::FIELD_TAGS, self::FIELD_COLLABORATORS, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS, self::FIELD_REVIEW_RATINGS, self::FIELD_LEADS, self::FIELD_SHEPHERDS]);
        case "pcadminx":
            return [self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_LASTVISIT, self::FIELD_TAGS, self::FIELD_COLLABORATORS, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS];
        case "re":
            return $this->addScores([self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_LASTVISIT, self::FIELD_TAGS, self::FIELD_COLLABORATORS, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS, self::FIELD_REVIEW_RATINGS]);
        case "ext":
        case "extsub":
            return $this->addScores([self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_LASTVISIT, self::FIELD_COLLABORATORS, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS, self::FIELD_REVIEW_RATINGS, self::FIELD_REVIEW_PAPERS]);
        case "req":
            return $this->addScores([self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_LASTVISIT, self::FIELD_TAGS, self::FIELD_COLLABORATORS, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS, self::FIELD_REVIEW_RATINGS, self::FIELD_REVIEW_PAPERS]);
        case "au":
        case "aurej":
        case "auuns":
        case "auacc":
            return [self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION_ROW, self::FIELD_LASTVISIT, self::FIELD_TAGS, self::FIELD_PAPERS, self::FIELD_COLLABORATORS];
        case "all":
            return [self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION_ROW, self::FIELD_LASTVISIT, self::FIELD_TAGS, self::FIELD_PAPERS, self::FIELD_REVIEWS, self::FIELD_COLLABORATORS];
        default:
            return null;
        }
    }

    function footer($ncol, $hascolors) {
        if ($this->count == 0)
            return "";
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

            $mods = ["disableaccount" => "Disable", "enableaccount" => "Enable"];
            if ($this->user->can_change_password(null)) {
                $mods["resetpassword"] = "Reset password";
            }
            $mods["sendaccount"] = "Send account information";
            $lllgroups[] = ["", "Modify",
                Ht::select("modifyfn", $mods, null, ["class" => "want-focus"])
                . Ht::submit("fn", "Go", ["value" => "modify", "class" => "uic js-submit-list ml-2"])];
        }

        return "  <tfoot class=\"pltable" . ($hascolors ? " pltable-colored" : "")
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

        // make query
        $result = $this->conf->qe_raw("select * from ContactInfo" . (empty($mainwhere) ? "" : " where " . join(" and ", $mainwhere)));
        $rows = [];
        while (($row = Contact::fetch($result, $this->conf))) {
            if (!$row->is_anonymous_user()) {
                $rows[] = $row;
            }
        }
        Dbl::free($result);
        if (isset($this->qopt["topics"])) {
            Contact::load_topic_interests($rows);
        }
        return $rows;
    }

    function table_html($listname, $url, $listtitle = "", $foldsession = null) {
        // PC tags
        $listquery = $listname;
        $this->qopt = [];
        if (str_starts_with($listname, "#")) {
            $x = sqlq(Dbl::escape_like(substr($listname, 1)));
            $this->qopt["where"] = "(contactTags like " . Dbl::utf8ci("'% {$x}#%'") . ")";
            $listquery = "pcadmin";
        }

        // get paper list
        $baseFieldId = $this->listFields($listquery);
        if (!$baseFieldId) {
            $this->conf->error_msg("<0>User set ‘{$listquery}’ not found");
            return null;
        }

        // get field array
        $fieldDef = [];
        $acceptable_fields = [];
        $this->any = (object) ["sel" => false];
        $ncol = 0;
        foreach ($baseFieldId as $fid) {
            if ($this->selector($fid) !== false) {
                $acceptable_fields[$fid] = true;
                $fieldDef[$fid] = $this->_fieldspec($fid);
                if ($fieldDef[$fid][1] === 1) {
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
            || !($acceptable_fields[$this->sortField] ?? false)) {
            $this->sortField = self::FIELD_NAME;
        }
        $srows = $this->_sort($rows);

        // count non-callout columns
        $firstcallout = $lastcallout = null;
        $n = 0;
        foreach ($fieldDef as $fieldId => $fdef) {
            if ($fdef[1] == 1) {
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
        $body = '';
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
                $trclass .= " graytext";
            }
            $this->count++;
            $ids[] = (int) $row->contactId;

            // First create the expanded callout row
            $tt = "";
            foreach ($fieldDef as $fieldId => $fdef) {
                if ($fdef[1] >= 2
                    && ($d = $this->content($fieldId, $row)) !== "") {
                    $tt .= "<div";
                    //$t .= "  <tr class=\"pl_$fdef[0] pl_callout $trclass";
                    if ($fdef[1] >= 3) {
                        $tt .= " class=\"fx" . ($fdef[1] - 2) . "\"";
                    }
                    $tt .= '><em class="plx">' . $this->header($fieldId, $row)
                        . ":</em> " . $d . "</div>";
                }
            }

            if ($tt !== "") {
                $x = "  <tr class=\"plx $trclass\">";
                if ($firstcallout > 0) {
                    $x .= "<td colspan=\"$firstcallout\"></td>";
                }
                $tt = $x . "<td class=\"plx\" colspan=\"" . ($lastcallout - $firstcallout)
                    . "\">" . $tt . "</td></tr>\n";
            }

            // Now the normal row
            $t = "  <tr class=\"pl $trclass" . ($tt !== "" ? "" : " plnx") . "\">\n";
            $n = 0;
            foreach ($fieldDef as $fieldId => $fdef) {
                if ($fdef[1] == 1) {
                    $c = $this->content($fieldId, $row);
                    $t .= "    <td class=\"pl pl_$fdef[0]\"";
                    if ($n >= $lastcallout && $tt != "") {
                        $t .= " rowspan=\"2\"";
                    }
                    $t .= ">" . $c . "</td>\n";
                    if ($c != "") {
                        $anyData[$fieldId] = 1;
                    }
                    ++$n;
                }
            }
            $t .= "  </tr>\n";

            $body .= $t . $tt;
        }

        $uldisplay = self::uldisplay($this->user);
        $foldclasses = [];
        foreach (self::$folds as $k => $fold) {
            if (($this->have_folds[$fold] ?? null) !== null) {
                $this->have_folds[$fold] = strpos($uldisplay, " $fold ") !== false;
                $foldclasses[] = "fold" . ($k + 1) . ($this->have_folds[$fold] ? "o" : "c");
            }
        }

        $x = "<table id=\"foldul\" class=\"pltable fullw";
        if ($foldclasses) {
            $x .= " " . join(" ", $foldclasses);
        }
        if ($foldclasses && $foldsession) {
            $fs = [];
            foreach (self::$folds as $k => $fold) {
                $fs[$k + 1] = $fold;
            }
            $x .= "\" data-fold-session=\"" . htmlspecialchars(json_encode_browser($fs)) . "\" data-fold-session-prefix=\"" . htmlspecialchars($foldsession);
        }
        $x .= "\">\n";

        $x .= "  <thead class=\"pltable\">\n  <tr class=\"pl_headrow\">\n";

        if ($this->sortable && $url) {
            $sortUrl = $url . (strpos($url, "?") ? "&amp;" : "?") . "sort=";
            $q = '<a class="pl_sort" rel="nofollow" href="' . $sortUrl;
            foreach ($fieldDef as $fieldId => $fdef) {
                if ($fdef[1] != 1) {
                    continue;
                } else if (!isset($anyData[$fieldId])) {
                    $x .= "    <th class=\"pl plh pl_$fdef[0]\"></th>\n";
                    continue;
                }
                $x .= "    <th class=\"pl plh pl_$fdef[0]\">";
                $ftext = $this->header($fieldId);
                if ($fieldId === $this->sortField) {
                    $rev = $this->reverseSort ? "" : "-";
                    $x .= '<a class="pl_sort pl_sorting' . ($this->reverseSort ? "_rev" : "_fwd")
                        . "\" rel=\"nofollow\" href=\"{$sortUrl}{$rev}{$fdef[4]}\">{$ftext}</a>";
                } else if ($fdef[2]) {
                    $x .= "{$q}{$fdef[4]}\">{$ftext}</a>";
                } else {
                    $x .= $ftext;
                }
                $x .= "</th>\n";
            }

        } else {
            foreach ($fieldDef as $fieldId => $fdef) {
                if ($fdef[1] == 1 && isset($anyData[$fieldId])) {
                    $x .= "    <th class=\"pl plh pl_$fdef[0]\">"
                        . $this->header($fieldId) . "</th>\n";
                } else if ($fdef[1] == 1) {
                    $x .= "    <th class=\"pl plh pl_$fdef[0]\"></th>\n";
                }
            }
        }

        $x .= "  </tr></thead>\n";

        reset($fieldDef);
        if (key($fieldDef) == self::FIELD_SELECTOR) {
            $x .= $this->footer($ncol, $hascolors);
        }

        $x .= "<tbody class=\"pltable" . ($hascolors ? " pltable-colored" : "");
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
            $x .= " has-hotlist\" data-hotlist=\"" . htmlspecialchars($l->info_string());
        }
        return $x . "\">" . $body . "</tbody></table>";
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
        if (!($baseFieldId = $this->listFields($listname))) {
            $this->conf->error_msg("<0>User list ‘{$listname}’ not found");
            return null;
        }
        $this->limit = array_shift($baseFieldId);

        // run query
        return $this->_rows();
    }

}
