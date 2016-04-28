<?php
// papercolumn.php -- HotCRP helper classes for paper list content
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperColumn extends Column {
    static public $by_name = [];
    static public $factories = [];
    static private $synonyms = [];

    const PREP_SORT = -1;
    const PREP_FOLDED = 0; // value matters
    const PREP_VISIBLE = 1; // value matters
    const PREP_COMPLETION = 2;

    public function __construct($name, $flags, $extra = array()) {
        parent::__construct($name, $flags, $extra);
    }

    public static function lookup_local($name) {
        $lname = strtolower($name);
        if (isset(self::$synonyms[$lname]))
            $lname = self::$synonyms[$lname];
        return get(self::$by_name, $lname, null);
    }

    public static function lookup($name, $errors = null) {
        $lname = strtolower($name);
        if (isset(self::$synonyms[$lname]))
            $lname = self::$synonyms[$lname];
        if (isset(self::$by_name[$lname]))
            return self::$by_name[$lname];
        foreach (self::$factories as $f)
            if (str_starts_with($lname, $f[0])
                && ($x = $f[1]->make_column($name, $errors)))
                return $x;
        if (($colon = strpos($lname, ":")) > 0
            && ($syn = get(self::$synonyms, substr($lname, 0, $colon))))
            return self::lookup($syn . substr($lname, $colon));
        return null;
    }

    public static function register($fdef) {
        $lname = strtolower($fdef->name);
        assert(!isset(self::$by_name[$lname]) && !isset(self::$synonyms[$lname]));
        self::$by_name[$lname] = $fdef;
        assert(func_num_args() == 1); // XXX backwards compat
        return $fdef;
    }
    public static function register_factory($prefix, $f) {
        self::$factories[] = array(strtolower($prefix), $f);
    }
    public static function register_synonym($new_name, $old_name) {
        $lold = strtolower($old_name);
        $lname = strtolower($new_name);
        assert(isset(self::$by_name[$lold]) && !isset(self::$by_name[$lname]) && !isset(self::$synonyms[$lname]));
        self::$synonyms[$lname] = $lold;
    }
    public static function make_column_error($errors, $ehtml, $eprio) {
        if ($errors)
            $errors->add($ehtml, $eprio);
    }
    public function make_editable() {
        return $this;
    }

    public function prepare(PaperList $pl, $visible) {
        return true;
    }
    public function annotate_field_js(PaperList $pl, &$fjs) {
    }

    public function analyze($pl, &$rows) {
    }

    public function sort_prepare($pl, &$rows, $sorter) {
    }
    public function id_compare($a, $b) {
        return $a->paperId - $b->paperId;
    }

    public function header($pl, $ordinal) {
        return "&lt;" . htmlspecialchars($this->name) . "&gt;";
    }
    public function completion_name() {
        if ($this->completable)
            return $this->name;
        else
            return false;
    }
    public function completion_instances() {
        return array($this);
    }

    public function content_empty($pl, $row) {
        return false;
    }

    public function content($pl, $row, $rowidx) {
        return "";
    }
    public function text($pl, $row) {
        return "";
    }

    public function has_statistics() {
        return false;
    }
}

class IdPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("id", Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("minimal" => true, "comparator" => "id_compare"));
    }
    public function header($pl, $ordinal) {
        return "ID";
    }
    public function content($pl, $row, $rowidx) {
        $href = $pl->_paperLink($row);
        return "<a href=\"$href\" class=\"pnum taghl\" tabindex=\"4\">#$row->paperId</a>";
    }
    public function text($pl, $row) {
        return $row->paperId;
    }
}

class SelectorPaperColumn extends PaperColumn {
    public $is_selector = true;
    public function __construct($name, $extra) {
        parent::__construct($name, Column::VIEW_COLUMN, $extra);
    }
    public function prepare(PaperList $pl, $visible) {
        if ($this->name == "selconf" && !$pl->contact->privChair)
            return false;
        if ($this->name == "selconf" || $this->name == "selunlessconf")
            $pl->qopts["reviewer"] = $pl->reviewer_cid();
        if ($this->name == "selconf" && ($tid = $pl->table_id()))
            $pl->add_header_script("add_conflict_ajax(" . json_encode("#$tid") . ")");
        return true;
    }
    public function header($pl, $ordinal) {
        if ($this->name == "selconf")
            return "Conflict?";
        else
            return ($ordinal ? "&nbsp;" : "");
    }
    private function checked($pl, $row) {
        $def = ($this->name == "selon"
                || ($this->name == "selconf" && $row->reviewerConflictType > 0));
        return $pl->papersel ? !!get($pl->papersel, $row->paperId, $def) : $def;
    }
    public function content($pl, $row, $rowidx) {
        if ($this->name == "selunlessconf" && $row->reviewerConflictType)
            return "";
        $pl->any->sel = true;
        $c = "";
        if ($this->checked($pl, $row)) {
            $c .= ' checked="checked"';
            unset($row->folded);
        }
        if ($this->name == "selconf" && $row->reviewerConflictType >= CONFLICT_AUTHOR)
            $c .= ' disabled="disabled"';
        if ($this->name != "selconf")
            $c .= ' onclick="rangeclick(event,this)"';
        return '<span class="pl_rownum fx6">' . $pl->count . '. </span>'
            . '<input type="checkbox" class="cb" name="pap[]" value="' . $row->paperId . '" tabindex="3" id="psel' . $pl->count . '"' . $c . ' />';
    }
    public function text($pl, $row) {
        return $this->checked($pl, $row) ? "X" : "";
    }
}

class TitlePaperColumn extends PaperColumn {
    private $has_badges = false;
    private $highlight = false;
    public function __construct() {
        parent::__construct("title", Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("minimal" => true, "comparator" => "title_compare"));
    }
    public function prepare(PaperList $pl, $visible) {
        $this->has_badges = $pl->contact->can_view_tags(null)
            && TagInfo::has_badges();
        if ($this->has_badges)
            $pl->qopts["tags"] = 1;
        $this->highlight = get($pl->search->matchPreg, "title");
        return true;
    }
    public function title_compare($a, $b) {
        return strcasecmp($a->title, $b->title);
    }
    public function header($pl, $ordinal) {
        return "Title";
    }
    public function content($pl, $row, $rowidx) {
        $t = '<a href="' . $pl->_paperLink($row) . '" class="ptitle taghl';

        $highlight_text = Text::highlight($row->title, $this->highlight, $highlight_count);

        if (!$highlight_count && ($format = $row->title_format())) {
            $pl->render_needed = true;
            $t .= ' need-format" data-format="' . $format;
        }

        $t .= '" tabindex="5">' . $highlight_text . '</a>'
            . $pl->_contentDownload($row);

        if ($this->has_badges && (string) $row->paperTags !== ""
            && ($t = $row->viewable_tags($pl->contact)) !== ""
            && ($t = $pl->tagger->unparse_badges_html($t)) !== "")
            $t .= $pl->maybe_conflict_nooverride($row, $t, $pl->contact->can_view_tags($row, false));

        return $t;
    }
    public function text($pl, $row) {
        return $row->title;
    }
}

class StatusPaperColumn extends PaperColumn {
    private $is_long;
    public function __construct($name, $is_long, $extra = 0) {
        parent::__construct($name, Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("className" => "pl_status", "comparator" => "status_compare"));
        $this->is_long = $is_long;
    }
    public function sort_prepare($pl, &$rows, $sorter) {
        $force = $pl->search->limitName != "a" && $pl->contact->privChair;
        foreach ($rows as $row)
            $row->_status_sort_info = ($pl->contact->can_view_decision($row, $force) ? $row->outcome : -10000);
    }
    public function status_compare($a, $b) {
        $x = $b->_status_sort_info - $a->_status_sort_info;
        $x = $x ? $x : ($a->timeWithdrawn > 0) - ($b->timeWithdrawn > 0);
        $x = $x ? $x : ($b->timeSubmitted > 0) - ($a->timeSubmitted > 0);
        return $x ? $x : ($b->paperStorageId > 1) - ($a->paperStorageId > 1);
    }
    public function header($pl, $ordinal) {
        return "Status";
    }
    public function content($pl, $row, $rowidx) {
        if ($row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0)
            $pl->any->need_submit = true;
        if ($row->outcome > 0 && $pl->contact->can_view_decision($row))
            $pl->any->accepted = true;
        if ($row->outcome > 0 && $row->timeFinalSubmitted <= 0
            && $pl->contact->can_view_decision($row))
            $pl->any->need_final = true;
        $status_info = $pl->contact->paper_status_info($row, $pl->search->limitName != "a" && $pl->contact->allow_administer($row));
        if (!$this->is_long && $status_info[0] == "pstat_sub")
            return "";
        return "<span class=\"pstat $status_info[0]\">" . htmlspecialchars($status_info[1]) . "</span>";
    }
    public function text($pl, $row) {
        $status_info = $pl->contact->paper_status_info($row, $pl->search->limitName != "a" && $pl->contact->allow_administer($row));
        return $status_info[1];
    }
}

class ReviewStatusPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("revstat", Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("comparator" => "review_status_compare"));
    }
    public function prepare(PaperList $pl, $visible) {
        global $Conf;
        if ($pl->contact->is_reviewer()
            || $Conf->timeAuthorViewReviews()
            || $pl->contact->privChair) {
            $pl->qopts["startedReviewCount"] = true;
            return true;
        } else
            return false;
    }
    public function sort_prepare($pl, &$rows, $sorter) {
        foreach ($rows as $row) {
            if (!$pl->contact->can_view_review_assignment($row, null, null))
                $row->_review_status_sort_info = 2147483647;
            else
                $row->_review_status_sort_info = $row->num_reviews_submitted()
                    + $row->num_reviews_started($pl->contact) / 1000.0;
        }
    }
    public function review_status_compare($a, $b) {
        $av = $a->_review_status_sort_info;
        $bv = $b->_review_status_sort_info;
        return ($av < $bv ? 1 : ($av == $bv ? 0 : -1));
    }
    public function header($pl, $ordinal) {
        return '<span class="hottooltip" data-hottooltip="# completed reviews / # assigned reviews" data-hottooltip-dir="b">#&nbsp;Reviews</span>';
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->can_view_review_assignment($row, null, null);
    }
    public function content($pl, $row, $rowidx) {
        $done = $row->num_reviews_submitted();
        $started = $row->num_reviews_started($pl->contact);
        return "<b>$done</b>" . ($done == $started ? "" : "/$started");
    }
    public function text($pl, $row) {
        $done = $row->num_reviews_submitted();
        $started = $row->num_reviews_started($pl->contact);
        return $done . ($done == $started ? "" : "/$started");
    }
}

class AuthorsPaperColumn extends PaperColumn {
    private $aufull;
    public function __construct() {
        parent::__construct("authors", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    public function header($pl, $ordinal) {
        return "Authors";
    }
    public function prepare(PaperList $pl, $visible) {
        $this->aufull = !$pl->is_folded("aufull");
        return $pl->contact->can_view_some_authors();
    }
    private function affiliation_map($row) {
        $nonempty_count = 0;
        $aff = [];
        foreach ($row->author_list() as $i => $au) {
            if ($i && $au->affiliation === $aff[$i - 1])
                $aff[$i - 1] = null;
            $aff[] = $au->affiliation;
            $nonempty_count += ($au->affiliation !== "");
        }
        if ($nonempty_count != 0 && $nonempty_count != count($aff)) {
            foreach ($aff as &$affx)
                if ($affx === "")
                    $affx = "unaffiliated";
        }
        return $aff;
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->can_view_authors($row, true);
    }
    public function content($pl, $row, $rowidx) {
        $out = [];
        $highlight = get($pl->search->matchPreg, "authorInformation", "");
        if (!$highlight && !$this->aufull) {
            foreach ($row->author_list() as $au)
                $out[] = $au->abbrevname_html();
            return join(", ", $out);
        } else {
            $affmap = $this->affiliation_map($row);
            $aus = $affout = [];
            $any_affhl = false;
            foreach ($row->author_list() as $i => $au) {
                $name = Text::highlight($au->name(), $highlight, $didhl);
                if (!$this->aufull
                    && ($first = htmlspecialchars($au->firstName))
                    && (!$didhl || substr($name, 0, strlen($first)) === $first)
                    && ($initial = Text::initial($first)) !== "")
                    $name = $initial . substr($name, strlen($first));
                $auy[] = $name;
                if ($affmap[$i] !== null) {
                    $out[] = $this->aufull ? commajoin($auy) : join(", ", $auy);
                    $affout[] = Text::highlight($affmap[$i], $highlight, $didhl);
                    $any_affhl = $any_affhl || $didhl;
                    $auy = [];
                }
            }
            // $affout[0] === "" iff there are no nonempty affiliations
            if (($any_affhl || $this->aufull) && $affout[0] !== "") {
                foreach ($out as $i => &$x)
                    $x .= ' <span class="auaff">(' . $affout[$i] . ')</span>';
            }
            if ($this->aufull)
                return commajoin($out);
            else
                return join($any_affhl ? "; " : ", ", $out);
        }
    }
    public function text($pl, $row) {
        if (!$pl->contact->can_view_authors($row, true))
            return "";
        $out = [];
        if (!$this->aufull) {
            foreach ($row->author_list() as $au)
                $out[] = $au->abbrevname_text();
            return join(", ", $out);
        } else {
            $affmap = $this->affiliation_map($row);
            $aus = [];
            foreach ($row->author_list() as $i => $au) {
                $aus[] = $au->name();
                if ($affmap[$i] !== null) {
                    $aff = ($affmap[$i] !== "" ? " ($affmap[$i])" : "");
                    $out[] = commajoin($aus) . $aff;
                    $aus = [];
                }
            }
            return commajoin($out);
        }
    }
}

class CollabPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("collab", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    public function prepare(PaperList $pl, $visible) {
        global $Conf;
        return !!$Conf->setting("sub_collab") && $pl->contact->can_view_some_authors();
    }
    public function header($pl, $ordinal) {
        return "Collaborators";
    }
    public function content_empty($pl, $row) {
        return ($row->collaborators == ""
                || strcasecmp($row->collaborators, "None") == 0
                || !$pl->contact->can_view_authors($row, true));
    }
    public function content($pl, $row, $rowidx) {
        $x = "";
        foreach (explode("\n", $row->collaborators) as $c)
            $x .= ($x === "" ? "" : ", ") . trim($c);
        return Text::highlight($x, get($pl->search->matchPreg, "collaborators"));
    }
    public function text($pl, $row) {
        $x = "";
        foreach (explode("\n", $row->collaborators) as $c)
            $x .= ($x === "" ? "" : ", ") . trim($c);
        return $x;
    }
}

class AbstractPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("abstract", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
        $this->no_embedded_header = true;
    }
    public function header($pl, $ordinal) {
        return "Abstract";
    }
    public function content_empty($pl, $row) {
        return $row->abstract == "";
    }
    public function content($pl, $row, $rowidx) {
        $t = Text::highlight($row->abstract, get($pl->search->matchPreg, "abstract"), $highlight_count);
        if (!$highlight_count && ($format = $row->format_of($row->abstract))) {
            $pl->render_needed = true;
            $t = '<div class="need-format" data-format="' . $format . '.plx">'
                . $t . '</div>';
        }
        return $t;
    }
    public function text($pl, $row) {
        return $row->abstract;
    }
}

class TopicListPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("topics", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    public function prepare(PaperList $pl, $visible) {
        global $Conf;
        if (!$Conf->has_topics())
            return false;
        if ($visible)
            $pl->qopts["topics"] = 1;
        return true;
    }
    public function header($pl, $ordinal) {
        return "Topics";
    }
    public function content_empty($pl, $row) {
        return !isset($row->topicIds) || $row->topicIds == "";
    }
    public function content($pl, $row, $rowidx) {
        return $row->unparse_topics_html(true, $pl->reviewer_contact());
    }
}

class ReviewerTypePaperColumn extends PaperColumn {
    protected $xreviewer;
    public function __construct($name) {
        parent::__construct($name, Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("comparator" => "reviewer_type_compare"));
    }
    public function analyze($pl, &$rows) {
        $this->xreviewer = $pl->prepare_xreviewer($rows);
    }
    public function sort_prepare($pl, &$rows, $sorter) {
        if (!$this->xreviewer) {
            foreach ($rows as $row) {
                $row->_reviewer_type_sort_info = 2 * $row->reviewType;
                if (!$row->_reviewer_type_sort_info && $row->conflictType)
                    $row->_reviewer_type_sort_info = -$row->conflictType;
                else if ($row->reviewType > 0 && !$row->reviewSubmitted)
                    $row->_reviewer_type_sort_info += 1;
            }
        } else {
            foreach ($rows as $row)
                if (isset($row->_xreviewer)) {
                    $row->_reviewer_type_sort_info = 2 * $row->_xreviewer->reviewType;
                    if (!$row->_xreviewer->reviewSubmitted)
                        $row->_reviewer_type_sort_info += 1;
                } else
                    $row->_reviewer_type_sort_info = 0;
        }
    }
    public function reviewer_type_compare($a, $b) {
        return $b->_reviewer_type_sort_info - $a->_reviewer_type_sort_info;
    }
    public function header($pl, $ordinal) {
        if ($this->xreviewer)
            return $pl->contact->name_html_for($this->xreviewer) . "<br />review</span>";
        else
            return "Review";
    }
    public function content($pl, $row, $rowidx) {
        if ($this->xreviewer && !isset($row->_xreviewer))
            $xrow = (object) array("reviewType" => 0, "conflictType" => 0);
        else if ($this->xreviewer)
            $xrow = $row->_xreviewer;
        else
            $xrow = $row;
        $ranal = null;
        if ($xrow->reviewType) {
            $ranal = new PaperListReviewAnalysis($xrow);
            if ($ranal->needsSubmit)
                $pl->any->need_review = true;
            $t = $ranal->icon_html(true);
        } else if ($xrow->conflictType > 0)
            $t = review_type_icon(-1);
        else
            $t = "";
        $x = null;
        if (!$this->xreviewer && $row->leadContactId && $row->leadContactId == $pl->contact->contactId)
            $x[] = review_lead_icon();
        if (!$this->xreviewer && $row->shepherdContactId && $row->shepherdContactId == $pl->contact->contactId)
            $x[] = review_shepherd_icon();
        if ($x || ($ranal && $ranal->round)) {
            $c = ["pl_revtype"];
            $t && ($c[] = "hasrev");
            $x && ($c[] = "haslead");
            $ranal && $ranal->round && ($c[] = "hasround");
            $t && ($x[] = $t);
            return '<div class="' . join(" ", $c) . '">' . join('&nbsp;', $x) . '</div>';
        } else
            return $t;
    }
}

class ReviewSubmittedPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("revsubmitted", Column::VIEW_COLUMN | Column::COMPLETABLE, array("className" => "pl_text"));
    }
    public function prepare(PaperList $pl, $visible) {
        return !!$pl->contact->isPC;
    }
    public function header($pl, $ordinal) {
        return "Review status";
    }
    public function content_empty($pl, $row) {
        return !$row->reviewId;
    }
    public function content($pl, $row, $rowidx) {
        if (!$row->reviewId)
            return "";
        $ranal = new PaperListReviewAnalysis($row);
        if ($ranal->needsSubmit)
            $pl->any->need_review = true;
        return $ranal->status_html();
    }
}

class ReviewDelegationPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("revdelegation", Column::VIEW_COLUMN,
                            array("className" => "pl_text",
                                  "comparator" => "review_delegation_compare"));
    }
    public function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->isPC)
            return false;
        $pl->qopts["reviewerName"] = true;
        $pl->qopts["allReviewScores"] = true;
        $pl->qopts["reviewLimitSql"] = "PaperReview.requestedBy=" . $pl->reviewer_cid();
        return true;
    }
    public function review_delegation_compare($a, $b) {
        $x = strcasecmp($a->reviewLastName, $b->reviewLastName);
        $x = $x ? $x : strcasecmp($a->reviewFirstName, $b->reviewFirstName);
        return $x ? $x : strcasecmp($a->reviewEmail, $b->reviewEmail);
    }
    public function header($pl, $ordinal) {
        return "Reviewer";
    }
    public function content($pl, $row, $rowidx) {
        global $Conf;
        $t = Text::user_html($row->reviewFirstName, $row->reviewLastName, $row->reviewEmail) . "<br /><small>Last login: ";
        return $t . ($row->reviewLastLogin ? $Conf->printableTimeShort($row->reviewLastLogin) : "Never") . "</small>";
    }
}

class AssignReviewPaperColumn extends ReviewerTypePaperColumn {
    public function __construct() {
        parent::__construct("assrev");
    }
    public function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->is_manager())
            return false;
        if ($visible > 0 && ($tid = $pl->table_id()))
            $pl->add_header_script("add_assrev_ajax(" . json_encode("#$tid") . ")");
        $pl->qopts["reviewer"] = $pl->reviewer_cid();
        return true;
    }
    public function analyze($pl, &$rows) {
        $this->xreviewer = false;
    }
    public function header($pl, $ordinal) {
        return "Assignment";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->allow_administer($row);
    }
    public function content($pl, $row, $rowidx) {
        if ($row->reviewerConflictType >= CONFLICT_AUTHOR)
            return '<span class="author">Author</span>';
        $rt = ($row->reviewerConflictType > 0 ? -1 : min(max($row->reviewerReviewType, 0), REVIEW_PRIMARY));
        if ($pl->reviewer_contact()->can_accept_review_assignment_ignore_conflict($row)
            || $rt > 0)
            $options = array(0 => "None",
                             REVIEW_PRIMARY => "Primary",
                             REVIEW_SECONDARY => "Secondary",
                             REVIEW_PC => "Optional",
                             -1 => "Conflict");
        else
            $options = array(0 => "None", -1 => "Conflict");
        return Ht::select("assrev$row->paperId", $options, $rt, ["tabindex" => 3]);
    }
}

class DesirabilityPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("desirability", Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("comparator" => "desirability_compare"));
    }
    public function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->privChair)
            return false;
        if ($visible)
            $pl->qopts["desirability"] = 1;
        return true;
    }
    public function desirability_compare($a, $b) {
        return $b->desirability - $a->desirability;
    }
    public function header($pl, $ordinal) {
        return "Desirability";
    }
    public function content($pl, $row, $rowidx) {
        return htmlspecialchars($this->text($pl, $row));
    }
    public function text($pl, $row) {
        return get($row, "desirability") + 0;
    }
}

class TopicScorePaperColumn extends PaperColumn {
    private $contact;
    public function __construct() {
        parent::__construct("topicscore", Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("comparator" => "topic_score_compare"));
    }
    public function prepare(PaperList $pl, $visible) {
        global $Conf;
        if (!$Conf->has_topics() || !$pl->contact->isPC)
            return false;
        if ($visible) {
            $this->contact = $pl->reviewer_contact();
            $pl->qopts["reviewer"] = $pl->reviewer_cid();
            $pl->qopts["topics"] = 1;
        }
        return true;
    }
    public function topic_score_compare($a, $b) {
        return $b->topic_interest_score($this->contact) - $a->topic_interest_score($this->contact);
    }
    public function header($pl, $ordinal) {
        return "Topic<br/>score";
    }
    public function content($pl, $row, $rowidx) {
        return htmlspecialchars($row->topic_interest_score($this->contact));
    }
    public function text($pl, $row) {
        return $row->topic_interest_score($this->contact);
    }
}

class PreferencePaperColumn extends PaperColumn {
    private $editable;
    private $contact;
    private $viewer_contact;
    private $is_direct;
    private $careful;
    public function __construct($name, $editable, $contact = null) {
        parent::__construct($name, Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("comparator" => "preference_compare",
                                  "className" => $editable ? "pl_editrevpref" : "pl_revpref"));
        $this->editable = $editable;
        $this->contact = $contact;
    }
    public function make_column($name, $errors) {
        global $Me;
        $p = strpos($name, ":");
        $cids = ContactSearch::make_pc(substr($name, $p + 1), $Me)->ids;
        if (count($cids) == 0)
            self::make_column_error($errors, "No PC member matches “" . htmlspecialchars(substr($name, $p + 1)) . "”.", 2);
        else if (count($cids) == 1) {
            $pcm = pcMembers();
            return parent::register(new PreferencePaperColumn($name, $this->editable, $pcm[$cids[0]]));
        } else
            self::make_column_error($errors, "“" . htmlspecialchars(substr($name, $p + 1)) . "” matches more than one PC member.", 2);
        return null;
    }
    public function make_editable() {
        return new PreferencePaperColumn($this->name, true, $this->contact);
    }
    public function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->isPC)
            return false;
        $this->viewer_contact = $pl->contact;
        if (!$this->contact)
            $this->contact = $pl->reviewer_contact();
        $this->careful = $this->contact->contactId != $pl->contact->contactId;
        if (($this->careful || !$this->name /* == this is the user factory */)
            && !$pl->contact->is_manager())
            return false;
        if ($visible) {
            $this->is_direct = $this->contact->contactId == $pl->reviewer_cid();
            if ($this->is_direct) {
                $pl->qopts["reviewer"] = $pl->reviewer_cid();
                $pl->qopts["reviewerPreference"] = 1;
            } else
                $pl->qopts["allReviewerPreference"] = 1;
            $pl->qopts["topics"] = 1;
        }
        if ($this->editable && $visible > 0 && ($tid = $pl->table_id())) {
            $reviewer_cid = 0;
            if ($pl->contact->privChair)
                $reviewer_cid = $pl->reviewer_cid() ? : 0;
            $pl->add_header_script("add_revpref_ajax(" . json_encode("#$tid") . ",$reviewer_cid)", "revpref_ajax");
        }
        return true;
    }
    public function completion_name() {
        return $this->name ? : "pref:<user>";
    }
    private function preference_values($row) {
        if ($this->careful && !$this->viewer_contact->allow_administer($row))
            return [null, null];
        else if ($this->is_direct)
            return [$row->reviewerPreference, $row->reviewerExpertise];
        else
            return $row->reviewer_preference($this->contact);
    }
    public function preference_compare($a, $b) {
        list($ap, $ae) = $this->preference_values($a);
        list($bp, $be) = $this->preference_values($b);
        if ($ap === null || $bp === null)
            return $ap === $bp ? 0 : ($ap === null ? 1 : -1);
        if ($ap != $bp)
            return $ap < $bp ? 1 : -1;

        if ($ae !== $be) {
            if (($ae === null) !== ($be === null))
                return $ae === null ? 1 : -1;
            return (float) $ae < (float) $be ? 1 : -1;
        }

        $at = $a->topic_interest_score($this->contact);
        $bt = $b->topic_interest_score($this->contact);
        if ($at != $bt)
            return $at < $bt ? 1 : -1;
        return 0;
    }
    public function header($pl, $ordinal) {
        if ($this->careful)
            return $pl->contact->name_html_for($this->contact) . "<br />preference</span>";
        else
            return "Preference";
    }
    public function content_empty($pl, $row) {
        return $this->careful && !$pl->contact->allow_administer($row);
    }
    private function show_content($pl, $row, $zero_empty) {
        $ptext = $this->text($pl, $row);
        if ($ptext[0] === "-")
            $ptext = "−" /* U+2122 MINUS SIGN */ . substr($ptext, 1);
        if ($zero_empty && $ptext === "0")
            return "";
        else if ($this->careful && !$pl->contact->can_administer($row, false))
            return '<span class="fx5">' . $ptext . '</span><span class="fn5">?</span>';
        else
            return $ptext;
    }
    public function content($pl, $row, $rowidx) {
        $conflicted = $this->is_direct ? $row->reviewerConflictType > 0
            : $row->has_conflict($this->contact);
        if ($conflicted && !$pl->contact->allow_administer($row))
            return review_type_icon(-1);
        else if (!$this->editable)
            return $this->show_content($pl, $row, false);
        else {
            $ptext = $this->text($pl, $row);
            $iname = "revpref" . $row->paperId;
            if (!$this->is_direct)
                $iname .= "u" . $this->contact->contactId;
            return '<input name="' . $iname . '" class="revpref" value="' . ($ptext !== "0" ? $ptext : "") . '" type="text" size="4" tabindex="2" placeholder="0" />' . ($conflicted && !$this->is_direct ? "&nbsp;" . review_type_icon(-1) : "");
        }
    }
    public function text($pl, $row) {
        return unparse_preference($this->preference_values($row));
    }
}

class PreferenceListPaperColumn extends PaperColumn {
    private $topics;
    public function __construct($name, $topics) {
        $this->topics = $topics;
        parent::__construct($name, Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    public function prepare(PaperList $pl, $visible) {
        global $Conf;
        if ($this->topics && !$Conf->has_topics())
            $this->topics = false;
        if (!$pl->contact->is_manager())
            return false;
        if ($visible) {
            $pl->qopts["allReviewerPreference"] = $pl->qopts["allConflictType"] = 1;
            if ($this->topics)
                $pl->qopts["topics"] = 1;
        }
        $Conf->stash_hotcrp_pc($pl->contact);
        return true;
    }
    public function header($pl, $ordinal) {
        return "Preferences";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->allow_administer($row);
    }
    public function content($pl, $row, $rowidx) {
        $prefs = $row->reviewer_preferences();
        $ts = array();
        if ($prefs || $this->topics)
            foreach (pcMembers() as $pcid => $pc) {
                if (($pref = get($prefs, $pcid))
                    && ($pref[0] !== 0 || $pref[1] !== null)) {
                    $t = "P" . $pref[0];
                    if ($pref[1] !== null)
                        $t .= unparse_expertise($pref[1]);
                    $ts[] = $pcid . $t;
                } else if ($this->topics
                           && ($tscore = $row->topic_interest_score($pc)))
                    $ts[] = $pcid . "T" . $tscore;
            }
        $pl->row_attr["data-allpref"] = join(" ", $ts);
        if (!empty($ts)) {
            $t = '<span class="need-allpref">Loading</span>';
            $pl->render_needed = true;
            return $t;
        } else
            return '';
    }
}

class ReviewerListPaperColumn extends PaperColumn {
    private $topics;
    public function __construct() {
        parent::__construct("reviewers", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    public function prepare(PaperList $pl, $visible) {
        global $Conf;
        if (!$pl->contact->can_view_some_review_identity(null))
            return false;
        $this->topics = $Conf->has_topics();
        if ($visible) {
            $pl->qopts["reviewList"] = 1;
            if ($pl->contact->privChair)
                $pl->qopts["allReviewerPreference"] = $pl->qopts["topics"] = 1;
        }
        return true;
    }
    public function header($pl, $ordinal) {
        return "Reviewers";
    }
    public function content($pl, $row, $rowidx) {
        // see also search.php > getaction == "reviewers"
        if (!isset($pl->review_list[$row->paperId]))
            return "";
        $prefs = $topics = false;
        if ($pl->contact->privChair) {
            $prefs = $row->reviewer_preferences();
            $topics = $this->topics && $row->has_topics();
        }
        $pcm = null;
        if ($pl->contact->isPC)
            $pcm = pcMembers();
        $x = array();
        foreach ($pl->review_list[$row->paperId] as $xrow) {
            $ranal = new PaperListReviewAnalysis($xrow);
            $n = $pl->contact->reviewer_html_for($xrow) . "&nbsp;" . $ranal->icon_html(false);
            if ($prefs || $topics) {
                $pref = get($prefs, $xrow->contactId);
                if ($topics)
                    $pref[2] = $row->topic_interest_score((int) $xrow->contactId);
                $n .= unparse_preference_span($pref);
            }
            $x[] = '<span class="nw">' . $n . '</span>';
        }
        return $pl->maybeConflict($row, join(", ", $x),
                                  $pl->contact->can_view_review_identity($row, null, false));
    }
}

class PCConflictListPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("pcconf", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    public function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->privChair)
            return false;
        if ($visible)
            $pl->qopts["allConflictType"] = 1;
        return true;
    }
    public function header($pl, $ordinal) {
        return "PC conflicts";
    }
    public function content($pl, $row, $rowidx) {
        $y = [];
        $pcm = pcMembers();
        foreach ($row->conflicts() as $id => $type)
            if (($pc = get($pcm, $id)))
                $y[$pc->sort_position] = $pl->contact->reviewer_html_for($pc);
        ksort($y);
        return join(", ", $y);
    }
}

class ConflictMatchPaperColumn extends PaperColumn {
    private $field;
    public function __construct($name, $field) {
        parent::__construct($name, Column::VIEW_ROW);
        $this->field = $field;
    }
    public function prepare(PaperList $pl, $visible) {
        return $pl->contact->privChair;
    }
    public function header($pl, $ordinal) {
        if ($this->field == "authorInformation")
            return "<strong>Potential conflict in authors</strong>";
        else
            return "<strong>Potential conflict in collaborators</strong>";
    }
    public function content_empty($pl, $row) {
        return get($pl->search->matchPreg, $this->field, "") == "";
    }
    public function content($pl, $row, $rowidx) {
        $preg = get($pl->search->matchPreg, $this->field, "");
        if ($preg == "")
            return "";
        $text = "";
        $field = $this->field;
        foreach (explode("\n", $row->$field) as $line)
            if (($line = trim($line)) != "") {
                $line = Text::highlight($line, $preg, $n);
                if ($n)
                    $text .= ($text ? "; " : "") . $line;
            }
        if ($text != "")
            unset($row->folded);
        return $text;
    }
}

class TagListPaperColumn extends PaperColumn {
    private $editable;
    public function __construct($editable) {
        parent::__construct("tags", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
        $this->editable = $editable;
    }
    public function make_editable() {
        return new TagListPaperColumn(true);
    }
    public function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->can_view_tags(null))
            return false;
        if ($visible)
            $pl->qopts["tags"] = 1;
        return true;
    }
    public function annotate_field_js(PaperList $pl, &$fjs) {
        $fjs["highlight_tags"] = $pl->search->highlight_tags();
        if (TagInfo::has_votish())
            $fjs["votish_tags"] = array_keys(TagInfo::vote_tags() + TagInfo::approval_tags());
    }
    public function header($pl, $ordinal) {
        return "Tags";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->can_view_tags($row, true);
    }
    public function content($pl, $row, $rowidx) {
        $viewable = $row->viewable_tags($pl->contact);
        $pl->row_attr["data-tags"] = trim($viewable);
        if ($this->editable)
            $pl->row_attr["data-tags-editable"] = 1;
        if ($viewable !== "" || $this->editable) {
            $pl->render_needed = true;
            return "<span class=\"need-tags\"></span>";
        } else
            return "";
    }
}

class TagPaperColumn extends PaperColumn {
    protected $is_value;
    protected $dtag;
    protected $ctag;
    protected $editable = false;
    static private $sortf_ctr = 0;
    public function __construct($name, $tag, $is_value) {
        parent::__construct($name, Column::VIEW_COLUMN | Column::COMPLETABLE, array("comparator" => "tag_compare"));
        $this->dtag = $tag;
        $this->is_value = $is_value;
        $this->className = ($this->is_value ? "pl_tagval" : "pl_tag");
    }
    public function make_column($name, $errors) {
        $p = strpos($name, ":") ? : strpos($name, "#");
        return parent::register(new TagPaperColumn($name, substr($name, $p + 1), $this->is_value));
    }
    public function make_editable() {
        return new EditTagPaperColumn($this->name, $this->dtag, $this->is_value);
    }
    public function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->can_view_tags(null))
            return false;
        if (!$this->dtag && $visible === PaperColumn::PREP_COMPLETION)
            return true;
        $tagger = new Tagger($pl->contact);
        if (!($ctag = $tagger->check($this->dtag, Tagger::NOVALUE | Tagger::ALLOWCONTACTID)))
            return false;
        $this->ctag = strtolower(" $ctag#");
        if ($visible)
            $pl->qopts["tags"] = 1;
        return true;
    }
    public function completion_name() {
        return $this->dtag ? "#$this->dtag" : "#<tag>";
    }
    protected function _tag_value($row) {
        if (($p = strpos($row->paperTags, $this->ctag)) === false)
            return null;
        else
            return (float) substr($row->paperTags, $p + strlen($this->ctag));
    }
    public function sort_prepare($pl, &$rows, $sorter) {
        global $Conf;
        $sorter->sortf = $sortf = "_tag_sort_info." . self::$sortf_ctr;
        ++self::$sortf_ctr;
        $careful = !$pl->contact->privChair
            && $Conf->setting("tag_seeall") <= 0;
        $unviewable = $empty = $sorter->reverse ? -(TAG_INDEXBOUND - 1) : TAG_INDEXBOUND - 1;
        if ($this->editable)
            $empty = $sorter->reverse ? -TAG_INDEXBOUND : TAG_INDEXBOUND;
        foreach ($rows as $row)
            if ($careful && !$pl->contact->can_view_tags($row, true))
                $row->$sortf = $unviewable;
            else if (($row->$sortf = $this->_tag_value($row)) === null)
                $row->$sortf = $empty;
    }
    public function tag_compare($a, $b, $sorter) {
        $sortf = $sorter->sortf;
        return $a->$sortf < $b->$sortf ? -1 :
            ($a->$sortf == $b->$sortf ? 0 : 1);
    }
    public function header($pl, $ordinal) {
        return "#$this->dtag";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->can_view_tags($row, true);
    }
    public function content($pl, $row, $rowidx) {
        if (($v = $this->_tag_value($row)) === null)
            return "";
        else if ($v === 0.0 && !$this->is_value)
            return "&#x2713;";
        else
            return $v;
    }
    public function text($pl, $row) {
        if (($v = $this->_tag_value($row)) === null)
            return "";
        else if ($v === 0.0 && !$this->is_value)
            return "X";
        else
            return $v;
    }
}

class EditTagPaperColumn extends TagPaperColumn {
    private $editsort;
    public function __construct($name, $tag, $is_value) {
        if ($is_value === null)
            $is_value = true;
        parent::__construct($name, $tag, $is_value);
        $this->className = $this->is_value ? "pl_edittagval" : "pl_edittag";
        $this->editable = true;
    }
    public function prepare(PaperList $pl, $visible) {
        $this->editsort = false;
        if (($p = parent::prepare($pl, $visible)) && $visible > 0
            && ($tid = $pl->table_id())) {
            $sorter = get($pl->sorters, 0);
            if (("edit" . $sorter->type == $this->name
                 || $sorter->type == $this->name)
                && !$sorter->reverse
                && (!$pl->search->thenmap || $pl->search->is_order_anno)
                && $this->is_value)
                $this->editsort = true;
            $pl->add_header_script("add_edittag_ajax(" . json_encode("#$tid") . ($this->editsort ? "," . json_encode($this->dtag) : "") . ")");
        }
        return $p;
    }
    public function content($pl, $row, $rowidx) {
        $v = $this->_tag_value($row);
        if ($this->editsort && !isset($pl->row_attr["data-tags"]))
            $pl->row_attr["data-tags"] = $this->dtag . "#" . $v;
        if (!$pl->contact->can_change_tag($row, $this->dtag, 0, 0, true))
            return $this->is_value ? (string) $v : ($v === null ? "" : "&#x2713;");
        else if (!$this->is_value)
            return "<input type='checkbox' class='cb edittag' name='tag:$this->dtag $row->paperId' value='x' tabindex='6'"
                . ($v !== null ? " checked='checked'" : "") . " />";
        else
            return "<input type='text' class='edittagval' size='4' name='tag:$this->dtag $row->paperId' value=\""
                . ($v !== null ? htmlspecialchars($v) : "") . "\" tabindex='6' />";
    }
}

class ScorePaperColumn extends PaperColumn {
    public $score;
    public $max_score;
    private $form_field;
    private $xreviewer;
    public function __construct($score) {
        parent::__construct($score, Column::VIEW_COLUMN | Column::FOLDABLE | Column::COMPLETABLE,
                            array("comparator" => "score_compare"));
        $this->minimal = true;
        $this->className = "pl_score";
        $this->score = $score;
    }
    public static function lookup_all() {
        $reg = array();
        foreach (ReviewForm::all_fields() as $f)
            if (($c = self::_make_column($f->id)))
                $reg[$f->display_order] = $c;
        ksort($reg);
        return $reg;
    }
    private static function _make_column($name) {
        if (($f = ReviewForm::field_search($name))
            && $f->has_options && $f->display_order !== false) {
            $c = parent::lookup_local($f->id);
            $c = $c ? : PaperColumn::register(new ScorePaperColumn($f->id));
            return $c;
        } else
            return null;
    }
    public function make_column($name, $errors) {
        return self::_make_column($name);
    }
    public function prepare(PaperList $pl, $visible) {
        if (!$pl->scoresOk)
            return false;
        $this->form_field = ReviewForm::field($this->score);
        if ($this->form_field->view_score <= $pl->contact->permissive_view_score_bound())
            return false;
        if ($visible) {
            $pl->qopts["scores"][$this->score] = true;
            $this->max_score = count($this->form_field->options);
        }
        return true;
    }
    public function analyze($pl, &$rows) {
        $this->xreviewer = $pl->prepare_xreviewer($rows);
    }
    public function sort_prepare($pl, &$rows, $sorter) {
        $this->_sortinfo = $sortinfo = "_score_sort_info." . $this->score . $sorter->score;
        $this->_avginfo = $avginfo = "_score_sort_avg." . $this->score;
        $reviewer = $pl->reviewer_cid();
        $field = $this->form_field;
        foreach ($rows as $row)
            if (($scores = $row->viewable_scores($field, $pl->contact, null)) !== null) {
                $scoreinfo = new ScoreInfo($scores);
                $row->$sortinfo = $scoreinfo->sort_data($sorter->score, $reviewer);
                $row->$avginfo = $scoreinfo->mean();
            } else {
                $row->$sortinfo = ScoreInfo::empty_sort_data($sorter->score);
                $row->$avginfo = -1;
            }
        $this->_textual_sort = ScoreInfo::sort_by_strcmp($sorter->score);
    }
    public function score_compare($a, $b) {
        $sortinfo = $this->_sortinfo;
        if ($this->_textual_sort)
            $x = strcmp($b->$sortinfo, $a->$sortinfo);
        else
            $x = $b->$sortinfo - $a->$sortinfo;
        if (!$x) {
            $avginfo = $this->_avginfo;
            $x = $b->$avginfo - $a->$avginfo;
        }
        return $x < 0 ? -1 : ($x == 0 ? 0 : 1);
    }
    public function header($pl, $ordinal) {
        return $this->form_field->web_abbreviation();
    }
    public function completion_name() {
        if ($this->score && ($ff = ReviewForm::field($this->score)))
            return $ff->abbreviation;
        else
            return null;
    }
    public function completion_instances() {
        return self::lookup_all();
    }
    public function content_empty($pl, $row) {
        // Do not use viewable_scores to determine content emptiness, since
        // that would load the scores from the DB -- even for folded score
        // columns.
        return !$row->may_have_viewable_scores($this->form_field, $pl->contact, true);
    }
    public function content($pl, $row, $rowidx) {
        $wrap_conflict = false;
        $scores = $row->viewable_scores($this->form_field, $pl->contact, false);
        if ($scores === null && $pl->contact->allow_administer($row)) {
            $wrap_conflict = true;
            $scores = $row->viewable_scores($this->form_field, $pl->contact, true);
        }
        if ($scores) {
            $my_score = null;
            if (!$this->xreviewer)
                $my_score = get($scores, $pl->reviewer_cid());
            else if (isset($row->_xreviewer))
                $my_score = get($scores, $row->_xreviewer->reviewContactId);
            $t = $this->form_field->unparse_graph($scores, 1, $my_score);
            if ($pl->table_type && $rowidx % 16 == 15)
                $t .= "<script>scorechart()</script>";
            return $wrap_conflict ? '<span class="fx5">' . $t . '</span>' : $t;
        }
        return "";
    }
}

class OptionPaperColumn extends PaperColumn {
    private $opt;
    public function __construct($opt) {
        parent::__construct($opt ? $opt->abbr : null, Column::VIEW_COLUMN | Column::FOLDABLE | Column::COMPLETABLE,
                            array("comparator" => "option_compare"));
        if ($opt && $opt instanceof TextPaperOption)
            $this->view = Column::VIEW_ROW;
        $this->minimal = true;
        $this->className = "pl_option";
        if ($opt && $opt->type == "checkbox")
            $this->className .= " plc";
        else if ($opt && $opt->type == "numeric")
            $this->className .= " plrd";
        $this->opt = $opt;
    }
    public static function lookup_all() {
        $reg = array();
        foreach (PaperOption::option_list() as $opt)
            if ($opt->display() >= 0)
                $reg[] = self::_make_column($opt);
        return $reg;
    }
    private static function _make_column($opt) {
        $s = parent::lookup_local($opt->abbr);
        $s = $s ? : PaperColumn::register(new OptionPaperColumn($opt));
        return $s;
    }
    public function make_column($name, $errors) {
        $p = strpos($name, ":") ? : -1;
        $opts = PaperOption::search(substr($name, $p + 1));
        if (count($opts) == 1) {
            reset($opts);
            $opt = current($opts);
            if ($opt->display() >= 0)
                return self::_make_column($opt);
            self::make_column_error($errors, "Option “" . htmlspecialchars(substr($name, $p + 1)) . "” can’t be displayed.");
        } else if ($p > 0)
            self::make_column_error($errors, "No such option “" . htmlspecialchars(substr($name, $p + 1)) . "”.", 1);
        return null;
    }
    public function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->can_view_some_paper_option($this->opt))
            return false;
        $pl->qopts["options"] = true;
        return true;
    }
    public function option_compare($a, $b) {
        return $this->opt->value_compare($a->option($this->opt->id),
                                         $b->option($this->opt->id));
    }
    public function header($pl, $ordinal) {
        return htmlspecialchars($this->opt->name);
    }
    public function completion_name() {
        return $this->opt ? $this->opt->abbr : null;
    }
    public function completion_instances() {
        return self::lookup_all();
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->can_view_paper_option($row, $this->opt, true);
    }
    public function content($pl, $row, $rowidx) {
        $t = "";
        if (($ok = $pl->contact->can_view_paper_option($row, $this->opt, false))
            || ($pl->contact->allow_administer($row)
                && $pl->contact->can_view_paper_option($row, $this->opt, true))) {
            $t = $this->opt->unparse_column_html($pl, $row);
            if (!$ok && $t !== "") {
                if ($this->view == Column::VIEW_ROW)
                    $t = '<div class="fx5">' . $t . '</div>';
                else
                    $t = '<span class="fx5">' . $t . '</div>';
            }
        }
        return $t;
    }
}

class FormulaPaperColumn extends PaperColumn {
    private static $registered = array();
    public static $list = array(); // Used by search.php
    public $formula;
    private $formula_function;
    public $statistics;
    public function __construct($name, $formula) {
        parent::__construct(strtolower($name), Column::VIEW_COLUMN | Column::FOLDABLE | Column::COMPLETABLE,
                            array("minimal" => true, "comparator" => "formula_compare"));
        $this->className = "pl_formula";
        $this->formula = $formula;
        if ($formula && $formula->formulaId)
            self::$list[$formula->formulaId] = $formula;
    }
    public static function lookup_all() {
        return self::$registered;
    }
    public static function register($fdef) {
        PaperColumn::register($fdef);
        self::$registered[] = $fdef;
    }
    public function make_column($name, $errors) {
        foreach (self::$registered as $col)
            if (strcasecmp($col->formula->name, $name) == 0)
                return $col;
        if (substr($name, 0, 4) === "edit")
            return null;
        $formula = new Formula($name);
        if (!$formula->check()) {
            if ($errors && strpos($name, "(") !== false)
                self::make_column_error($errors, $formula->error_html(), 1);
            return null;
        }
        $fdef = new FormulaPaperColumn("formulax" . (count(self::$registered) + 1), $formula);
        self::register($fdef);
        return $fdef;
    }
    public function completion_name() {
        if ($this->formula && $this->formula->name) {
            if (strpos($this->formula->name, " ") !== false)
                return "\"{$this->formula->name}\"";
            else
                return $this->formula->name;
        } else
            return "(<formula>)";
    }
    public function prepare(PaperList $pl, $visible) {
        if (!$this->formula && $visible === PaperColumn::PREP_COMPLETION)
            return true;
        if (!$pl->scoresOk
            || !$this->formula->check()
            || !($pl->search->limitName == "a"
                 ? $pl->contact->can_view_formula_as_author($this->formula)
                 : $pl->contact->can_view_formula($this->formula)))
            return false;
        $this->formula_function = $this->formula->compile_function($pl->contact);
        if ($visible)
            $this->formula->add_query_options($pl->qopts, $pl->contact);
        $this->statistics = new ScoreInfo;
        return true;
    }
    public function sort_prepare($pl, &$rows, $sorter) {
        $formulaf = $this->formula_function;
        $this->formula_sorter = $sorter = "_formula_sort_info." . $this->formula->name;
        foreach ($rows as $row)
            $row->$sorter = $formulaf($row, null, $pl->contact, Formula::SORTABLE);
    }
    public function formula_compare($a, $b) {
        $sorter = $this->formula_sorter;
        $as = $a->$sorter;
        $bs = $b->$sorter;
        if ($as === null || $bs === null)
            return $as === $bs ? 0 : ($as === null ? -1 : 1);
        else
            return $as == $bs ? 0 : ($as < $bs ? -1 : 1);
    }
    public function header($pl, $ordinal) {
        $x = $this->formula->column_header();
        if ($this->formula->headingTitle
            && $this->formula->headingTitle != $x)
            return "<span class=\"hottooltip\" data-hottooltip=\"" . htmlspecialchars($this->formula->headingTitle) . "\">" . htmlspecialchars($x) . "</span>";
        else
            return htmlspecialchars($x);
    }
    public function content($pl, $row, $rowidx) {
        $formulaf = $this->formula_function;
        $s = $formulaf($row, null, $pl->contact);
        $t = $this->formula->unparse_html($s, $pl->contact);
        if ($row->conflictType > 0 && $pl->contact->allow_administer($row)) {
            $ss = $formulaf($row, null, $pl->contact, null, true);
            $tt = $this->formula->unparse_html($ss, $pl->contact);
            if ($tt !== $t) {
                $this->statistics->add($ss);
                return '<span class="fn5">' . $t . '</span><span class="fx5">' . $tt . '</span>';
            }
        }
        $this->statistics->add($s);
        return $t;
    }
    public function text($pl, $row) {
        $formulaf = $this->formula_function;
        $s = $formulaf($row, null, $pl->contact);
        return $this->formula->unparse_text($s, $pl->contact);
    }
    public function has_statistics() {
        return $this->statistics && $this->statistics->count();
    }
    public function statistic($pl, $what) {
        return $this->formula->unparse_html($this->statistics->statistic($what), $pl->contact);
    }
}

class TagReportPaperColumn extends PaperColumn {
    private static $registered = array();
    private $tag;
    private $viewtype;
    public function __construct($tag) {
        parent::__construct("tagrep_" . preg_replace('/\W+/', '_', $tag),
                            Column::VIEW_ROW | Column::FOLDABLE);
        $this->className = "pl_tagrep";
        $this->tag = $tag;
    }
    public static function lookup_all() {
        return self::$registered;
    }
    public static function register($fdef) {
        PaperColumn::register($fdef);
        self::$registered[] = $fdef;
    }
    public function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->can_view_any_peruser_tags($this->tag))
            return false;
        if ($visible)
            $pl->qopts["tags"] = 1;
        $dt = TagInfo::defined_tag($this->tag);
        if (!$dt || $dt->rank || (!$dt->vote && !$dt->approval))
            $this->viewtype = 0;
        else
            $this->viewtype = $dt->approval ? 1 : 2;
        return true;
    }
    public function header($pl, $ordinal) {
        return "#~" . $this->tag . " tags";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->can_view_peruser_tags($row, $this->tag, true);
    }
    public function content($pl, $row, $rowidx) {
        $a = [];
        preg_match_all('/ (\d+)~' . preg_quote($this->tag) . '#(\S+)/i', $row->all_tags_text(), $m);
        for ($i = 0; $i != count($m[0]); ++$i) {
            if ($this->viewtype == 2 && $m[2][$i] <= 0)
                continue;
            $n = $pl->contact->name_html_for($m[1][$i]);
            if ($this->viewtype != 1)
                $n .= " (" . $m[2][$i] . ")";
            $a[$m[1][$i]] = $n;
        }
        if (empty($a))
            return "";
        $pl->contact->ksort_cid_array($a);
        $str = '<span class="nw">' . join(',</span>  <span class="nw">', $a) . '</span>';
        return $pl->maybeConflict($row, $str, $row->conflictType <= 0 || $pl->contact->can_view_peruser_tags($row, $this->tag, false));
    }
}

class TimestampPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("timestamp", Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("comparator" => "update_time_compare"));
    }
    public function update_time_compare($a, $b) {
        $at = max($a->timeFinalSubmitted, $a->timeSubmitted, 0);
        $bt = max($b->timeFinalSubmitted, $b->timeSubmitted, 0);
        return $at > $bt ? -1 : ($at == $bt ? 0 : 1);
    }
    public function header($pl, $ordinal) {
        return "Timestamp";
    }
    public function content_empty($pl, $row) {
        return max($row->timeFinalSubmitted, $row->timeSubmitted) <= 0;
    }
    public function content($pl, $row, $rowidx) {
        global $Conf;
        $t = max($row->timeFinalSubmitted, $row->timeSubmitted, 0);
        if ($t > 0)
            return $Conf->printableTimestamp($t);
        else
            return "";
    }
}

class NumericOrderPaperColumn extends PaperColumn {
    private $order;
    public function __construct($order) {
        parent::__construct("numericorder", Column::VIEW_NONE,
                            array("comparator" => "numeric_order_compare"));
        $this->order = $order;
    }
    public function numeric_order_compare($a, $b) {
        return +get($this->order, $a->paperId) - +get($this->order, $b->paperId);
    }
}

class LeadPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("lead", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    public function prepare(PaperList $pl, $visible) {
        return $pl->contact->can_view_lead(null, true);
    }
    public function header($pl, $ordinal) {
        return "Discussion lead";
    }
    public function content_empty($pl, $row) {
        return !$row->leadContactId
            || !$pl->contact->can_view_lead($row, true);
    }
    public function content($pl, $row, $rowidx) {
        $visible = $pl->contact->can_view_lead($row, null);
        return $pl->_contentPC($row, $row->leadContactId, $visible);
    }
}

class ShepherdPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("shepherd", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    public function prepare(PaperList $pl, $visible) {
        global $Conf;
        return $pl->contact->isPC
            || ($Conf->has_any_accepts() && $Conf->timeAuthorViewDecision());
    }
    public function header($pl, $ordinal) {
        return "Shepherd";
    }
    public function content_empty($pl, $row) {
        return !$row->shepherdContactId
            || !$pl->contact->can_view_shepherd($row, true);
        // XXX external reviewer can view shepherd even if external reviewer
        // cannot view reviewer identities? WHO GIVES A SHIT
    }
    public function content($pl, $row, $rowidx) {
        $visible = $pl->contact->can_view_shepherd($row, null);
        return $pl->_contentPC($row, $row->shepherdContactId, $visible);
    }
}

class FoldAllPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("foldall", Column::VIEW_NONE);
    }
    public function prepare(PaperList $pl, $visible) {
        $pl->qopts["foldall"] = true;
        return true;
    }
}

function initialize_paper_columns() {
    global $Conf;

    PaperColumn::register(new SelectorPaperColumn("sel", array("minimal" => true)));
    PaperColumn::register(new SelectorPaperColumn("selon", array("minimal" => true, "className" => "pl_sel")));
    PaperColumn::register(new SelectorPaperColumn("selconf", array("className" => "pl_confselector")));
    PaperColumn::register(new SelectorPaperColumn("selunlessconf", array("minimal" => true, "className" => "pl_sel")));
    PaperColumn::register(new IdPaperColumn);
    PaperColumn::register(new TitlePaperColumn);
    PaperColumn::register(new StatusPaperColumn("status", false));
    PaperColumn::register(new StatusPaperColumn("statusfull", true));
    PaperColumn::register(new ReviewerTypePaperColumn("revtype"));
    PaperColumn::register(new ReviewStatusPaperColumn);
    PaperColumn::register(new ReviewSubmittedPaperColumn);
    PaperColumn::register(new ReviewDelegationPaperColumn);
    PaperColumn::register(new AssignReviewPaperColumn);
    PaperColumn::register(new TopicScorePaperColumn);
    PaperColumn::register(new TopicListPaperColumn);
    PaperColumn::register(new PreferencePaperColumn("pref", false));
    PaperColumn::register_synonym("revpref", "pref");
    PaperColumn::register(new PreferenceListPaperColumn("allpref", false));
    PaperColumn::register_synonym("allrevpref", "allpref");
    PaperColumn::register(new PreferenceListPaperColumn("alltopicpref", true));
    PaperColumn::register_synonym("allrevtopicpref", "alltopicpref");
    PaperColumn::register(new DesirabilityPaperColumn);
    PaperColumn::register(new ReviewerListPaperColumn);
    PaperColumn::register(new AuthorsPaperColumn);
    PaperColumn::register(new CollabPaperColumn);
    PaperColumn::register_synonym("co", "collab");
    PaperColumn::register(new TagListPaperColumn(false));
    PaperColumn::register(new AbstractPaperColumn);
    PaperColumn::register(new LeadPaperColumn);
    PaperColumn::register(new ShepherdPaperColumn);
    PaperColumn::register(new PCConflictListPaperColumn);
    PaperColumn::register(new ConflictMatchPaperColumn("authorsmatch", "authorInformation"));
    PaperColumn::register(new ConflictMatchPaperColumn("collabmatch", "collaborators"));
    PaperColumn::register(new TimestampPaperColumn);
    PaperColumn::register(new FoldAllPaperColumn);
    PaperColumn::register_factory("tag:", new TagPaperColumn(null, null, false));
    PaperColumn::register_factory("tagval:", new TagPaperColumn(null, null, true));
    PaperColumn::register_factory("opt:", new OptionPaperColumn(null));
    PaperColumn::register_factory("#", new TagPaperColumn(null, null, null));
    PaperColumn::register_factory("pref:", new PreferencePaperColumn(null, false));

    if (count(PaperOption::option_list()))
        PaperColumn::register_factory("", new OptionPaperColumn(null));

    foreach (ReviewForm::all_fields() as $f)
        if ($f->has_options) {
            PaperColumn::register_factory("", new ScorePaperColumn(null));
            break;
        }

    if ($Conf && $Conf->setting("formulas")) {
        $result = Dbl::q("select * from Formula order by lower(name)");
        while ($result && ($row = $result->fetch_object("Formula"))) {
            $fid = $row->formulaId;
            FormulaPaperColumn::register(new FormulaPaperColumn("formula$fid", $row));
        }
    }
    PaperColumn::register_factory("", new FormulaPaperColumn("", null));

    $tagger = new Tagger;
    if ($Conf && (TagInfo::has_vote() || TagInfo::has_approval() || TagInfo::has_rank())) {
        $vt = array();
        foreach (TagInfo::defined_tags() as $v)
            if ($v->vote || $v->approval || $v->rank)
                $vt[] = $v->tag;
        foreach ($vt as $n)
            TagReportPaperColumn::register(new TagReportPaperColumn($n));
    }
}

initialize_paper_columns();
