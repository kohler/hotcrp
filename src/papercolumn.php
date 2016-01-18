<?php
// papercolumn.php -- HotCRP helper classes for paper list content
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperColumn extends Column {
    static public $by_name = array();
    static public $factories = array();

    const PREP_SORT = -1;
    const PREP_FOLDED = 0; // value matters
    const PREP_VISIBLE = 1; // value matters
    const PREP_COMPLETION = 2;

    public function __construct($name, $flags, $extra = array()) {
        parent::__construct($name, $flags, $extra);
    }

    public static function lookup_local($name) {
        $lname = strtolower($name);
        return get(self::$by_name, $lname, null);
    }

    public static function lookup($name, $errors = null) {
        $lname = strtolower($name);
        if (isset(self::$by_name[$lname]))
            return self::$by_name[$lname];
        foreach (self::$factories as $f)
            if (str_starts_with($lname, $f[0])
                && ($x = $f[1]->make_field($name, $errors)))
                return $x;
        return null;
    }

    public static function register($fdef) {
        $lname = strtolower($fdef->name);
        assert(!isset(self::$by_name[$lname]));
        self::$by_name[$lname] = $fdef;
        for ($i = 1; $i < func_num_args(); ++$i) {
            $lname = strtolower(func_get_arg($i));
            assert(!isset(self::$by_name[$lname]));
            self::$by_name[$lname] = $fdef;
        }
        return $fdef;
    }
    public static function register_factory($prefix, $f) {
        self::$factories[] = array(strtolower($prefix), $f);
    }
    public static function register_synonym($new_name, $old_name) {
        $fdef = self::$by_name[strtolower($old_name)];
        $new_name = strtolower($new_name);
        assert($fdef && !isset(self::$by_name[$new_name]));
        self::$by_name[$new_name] = $fdef;
    }

    public function prepare(PaperList $pl, $visible) {
        return true;
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
    private $nformats = 0;
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
        global $Conf;
        $t = '<a href="' . $pl->_paperLink($row) . '" class="ptitle taghl';

        $format = 0;
        if ($pl->live_table && !$this->highlight
            && ($format = $row->paperFormat) === null)
            $format = Conf::$gDefaultFormat;
        if ($format && ($f = $Conf->format_info($format))
            && ($regex = get($f, "simple_regex"))
            && preg_match($regex, $row->title))
            $format = 0;
        if ($format) {
            $t .= ' preformat" data-format="' . $format;
            $Conf->footerScript('$(render_text.titles)', 'render_titles');
            ++$this->nformats;
        }

        $t .= '" tabindex="5">' . Text::highlight($row->title, $this->highlight) . '</a>'
            . $pl->_contentDownload($row);

        if ($this->has_badges && $pl->contact->can_view_tags($row, true)
            && (string) $row->paperTags !== ""
            && ($t = $pl->tagger->viewable($row->paperTags)) !== ""
            && ($t = $pl->tagger->unparse_badges_html($t)) !== "")
            $t .= $pl->maybe_conflict_nooverride($row, $t, $pl->contact->can_view_tags($row, false));

        if ($this->nformats && $rowidx % 16 == 15) {
            $t .= '<script>render_text.titles()</script>';
            $this->nformats = 0;
        }

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
                            array("cssname" => "status", "comparator" => "status_compare"));
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
            if (!$pl->contact->can_count_review($row, null, null))
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
        return !$pl->contact->can_count_review($row, null, null);
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

class SearchOptsPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("searchopts", Column::VIEW_ROW | Column::FOLDABLE);
    }
    public function header($pl, $row, $ordinal) {
        return "Search Options";
    }
    public function content_empty($pl, $row) {
        return false;
    }
    public function content($pl, $row) {
        global $Conf;
        $prow = edb_rows($Conf->qe("select * from PaperOption where paperId=$row->paperId"));
        $options = PaperOption::option_list();
        $content = "";
        $q = explode(" ", $pl->search->q);
        foreach ($q as $word) {
            if (strpos($word, ':') === false)
                continue;
            $keyword = substr($word, 0, strpos($word, ':'));
            $matchingKw = reset(array_filter($options, function ($o) use ($keyword) {return $this->option_search_term($o->abbr) == $keyword;}));
            if (empty($matchingKw))
                continue;
            $keywordId = $matchingKw->id;
            $keywordTitle = !empty($matchingKw->description) ? $matchingKw->description : $matchingKw->name;
            $keywordRow = reset(array_filter($prow, function ($k) use ($keywordId) {return $k[1] == $keywordId;}));
            if ($matchingKw->type == "checkbox") {
                if (empty($keywordRow) || (!$keywordRow[3] && !$keywordRow[2])) {
                    $value = "no";
                } else {
                    $value = "yes";
                }
            } else {
                if (empty($keywordRow))
                    continue;
                $value = !empty($keywordRow[3]) ? $keywordRow[3] : $keywordRow[2];
            }
            $content .= "<br><strong>$keywordTitle</strong>: $value";
        }
        return $content;
    }
    public function text($pl, $row) {
        return content($pl, $row);
    }
    // From settings.php.
    private function option_search_term($oname) {
        $owords = preg_split(',[^a-z_0-9]+,', strtolower(trim($oname)));
        for ($i = 0; $i < count($owords); ++$i) {
            $attempt = join("-", array_slice($owords, 0, $i + 1));
            if (count(PaperOption::search($attempt)) == 1)
                return $attempt;
        }
        return simplify_whitespace($oname);
    }
}

class AbstractPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("abstract", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    public function header($pl, $ordinal) {
        return "Abstract";
    }
    public function content_empty($pl, $row) {
        return $row->abstract == "";
    }
    public function content($pl, $row, $rowidx) {
        return Text::highlight($row->abstract, get($pl->search->matchPreg, "abstract"));
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
        return PaperInfo::unparse_topics($row->topicIds, get($row, "topicInterest"), true);
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
            return $this->xreviewer->name_html() . "<br />Review</span>";
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
        parent::__construct("revsubmitted", Column::VIEW_COLUMN | Column::COMPLETABLE, array("cssname" => "text"));
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
                            array("cssname" => "text",
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
    public function __construct() {
        parent::__construct("topicscore", Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("comparator" => "topic_score_compare"));
    }
    public function prepare(PaperList $pl, $visible) {
        global $Conf;
        if (!$Conf->has_topics() || !$pl->contact->isPC)
            return false;
        if ($visible) {
            $pl->qopts["reviewer"] = $pl->reviewer_cid();
            $pl->qopts["topicInterestScore"] = 1;
        }
        return true;
    }
    public function topic_score_compare($a, $b) {
        return $b->topicInterestScore - $a->topicInterestScore;
    }
    public function header($pl, $ordinal) {
        return "Topic<br/>score";
    }
    public function content($pl, $row, $rowidx) {
        return htmlspecialchars($row->topicInterestScore + 0);
    }
    public function text($pl, $row) {
        return $row->topicInterestScore + 0;
    }
}

class PreferencePaperColumn extends PaperColumn {
    private $editable;
    public function __construct($name, $editable) {
        parent::__construct($name, Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("comparator" => "preference_compare"));
        $this->editable = $editable;
    }
    public function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->isPC)
            return false;
        if ($visible) {
            $pl->qopts["reviewerPreference"] = $pl->qopts["topicInterestScore"] = 1;
            $pl->qopts["reviewer"] = $pl->reviewer_cid();
        }
        if ($this->editable && $visible > 0 && ($tid = $pl->table_id())) {
            $reviewer_cid = 0;
            if ($pl->contact->privChair)
                $reviewer_cid = $pl->reviewer_cid() ? : 0;
            $pl->add_header_script("add_revpref_ajax(" . json_encode("#$tid") . ",$reviewer_cid)");
        }
        return true;
    }
    public function preference_compare($a, $b) {
        list($ap, $bp) = [(float) $a->reviewerPreference, (float) $b->reviewerPreference];
        if ($ap != $bp)
            return $ap < $bp ? 1 : -1;
        list($ae, $be) = [$a->reviewerExpertise, $b->reviewerExpertise];
        if ($ae !== $be) {
            if (($ae === null) !== ($be === null))
                return $ae === null ? 1 : -1;
            return (float) $ae < (float) $be ? 1 : -1;
        }
        list($at, $bt) = [(float) $a->topicInterestScore, (float) $b->topicInterestScore];
        if ($at != $bt)
            return $at < $bt ? 1 : -1;
        return 0;
    }
    public function header($pl, $ordinal) {
        return "Preference";
    }
    public function content($pl, $row, $rowidx) {
        $pref = unparse_preference($row);
        if ($pl->reviewer_cid()
            && $pl->reviewer_cid() != $pl->contact->contactId
            && !$pl->contact->allow_administer($row))
            return "N/A";
        else if (!$this->editable)
            return $pref;
        else if ($row->reviewerConflictType > 0)
            return "N/A";
        else
            return '<input name="revpref' . $row->paperId
                . '" class="revpref" value="' . ($pref !== "0" ? $pref : "") . '" type="text" size="4" tabindex="2" placeholder="0" />';
    }
    public function text($pl, $row) {
        return get($row, "reviewerPreference") + 0;
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
        $topics = $this->topics ? $row->topics() : false;
        $ts = array();
        if ($prefs || $topics)
            foreach (pcMembers() as $pcid => $pc) {
                $pref = get($prefs, $pcid, array());
                if ($this->topics)
                    $pref[2] = $row->topic_interest_score($pc);
                if (($pspan = unparse_preference_span($pref)) !== "")
                    $ts[] = '<span class="nw">' . $pc->reviewer_html() . $pspan . '</span>';
            }
        return join(", ", $ts);
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
            $topics = $this->topics ? $row->topics() : null;
        }
        $pcm = null;
        if ($pl->contact->isPC)
            $pcm = pcMembers();
        $x = array();
        foreach ($pl->review_list[$row->paperId] as $xrow) {
            $ranal = new PaperListReviewAnalysis($xrow);
            if ($pcm && ($p = get($pcm, $xrow->contactId)))
                $n = $p->reviewer_html();
            else
                $n = Text::name_html($xrow);
            $n .= "&nbsp;" . $ranal->icon_html(false);
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
        $conf = $row->conflicts();
        $y = array();
        foreach (pcMembers() as $id => $pc)
            if (get($conf, $id))
                $y[] = $pc->reviewer_html();
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
    public function __construct() {
        parent::__construct("tags", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    public function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->can_view_tags(null))
            return false;
        if ($visible)
            $pl->qopts["tags"] = 1;
        return true;
    }
    public function header($pl, $ordinal) {
        return "Tags";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->can_view_tags($row, true);
    }
    public function content($pl, $row, $rowidx) {
        if ((string) $row->paperTags === "")
            return "";
        $viewable = $pl->tagger->viewable($row->paperTags);
        $noconf = $row->conflictType <= 0;
        $str = $pl->tagger->unparse_and_link($viewable, $row->paperTags,
                                             $pl->search->highlight_tags(), $noconf);
        return $pl->maybeConflict($row, $str, $noconf || $pl->contact->can_view_tags($row, false));
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
        $this->cssname = ($this->is_value ? "tagval" : "tag");
    }
    public function make_field($name, $errors) {
        $p = strpos($name, ":") ? : strpos($name, "#");
        return parent::register(new TagPaperColumn($name, substr($name, $p + 1), $this->is_value));
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
        $unviewable = $empty = $sorter->reverse ? -2147483647 : 2147483647;
        if ($this->editable)
            $empty = $sorter->reverse ? -2147483646 : 2147483646;
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
        else if ($v === 0 && !$this->is_value)
            return "&#x2713;";
        else
            return $v;
    }
    public function text($pl, $row) {
        if (($v = $this->_tag_value($row)) === null)
            return "";
        else if ($v === 0 && !$this->is_value)
            return "X";
        else
            return $v;
    }
}

class EditTagPaperColumn extends TagPaperColumn {
    public function __construct($name, $tag, $is_value) {
        parent::__construct($name, $tag, $is_value);
        $this->cssname = ($this->is_value ? "edittagval" : "edittag");
        $this->editable = true;
    }
    public function make_field($name, $errors) {
        $p = strpos($name, ":") ? : strpos($name, "#");
        return parent::register(new EditTagPaperColumn($name, substr($name, $p + 1), $this->is_value));
    }
    public function prepare(PaperList $pl, $visible) {
        if (($p = parent::prepare($pl, $visible)) && $visible > 0
            && ($tid = $pl->table_id())) {
            $sorter = get($pl->sorters, 0);
            $s = "";
            if (("edit" . $sorter->type == $this->name
                 || $sorter->type == $this->name)
                && !$sorter->reverse
                && !$pl->search->thenmap
                && $this->is_value)
                $s = "," . json_encode($this->dtag);
            $pl->add_header_script("add_edittag_ajax(" . json_encode("#$tid") . $s . ")");
        }
        return $p;
    }
    public function content($pl, $row, $rowidx) {
        $v = $this->_tag_value($row);
        if (!$this->is_value)
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
    private static $registered = array();
    public function __construct($score) {
        parent::__construct($score, Column::VIEW_COLUMN | Column::FOLDABLE | Column::COMPLETABLE,
                            array("comparator" => "score_compare"));
        $this->minimal = true;
        $this->cssname = "score";
        $this->score = $score;
    }
    public static function lookup_all() {
        $reg = array();
        foreach (ReviewForm::all_fields() as $f)
            if (($s = self::_make_field($f->id)))
                $reg[$f->display_order] = $s;
        ksort($reg);
        return $reg;
    }
    private static function _make_field($name) {
        if (($f = ReviewForm::field_search($name))
            && $f->has_options && $f->display_order !== false) {
            $s = parent::lookup_local($f->id);
            $s = $s ? : PaperColumn::register(new ScorePaperColumn($f->id));
            return $s;
        } else
            return null;
    }
    public function make_field($name, $errors) {
        return self::_make_field($name);
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
            if ($pl->live_table && $rowidx % 16 == 15)
                $t .= "<script>scorechart()</script>";
            return $wrap_conflict ? '<span class="fx5">' . $t . '</span>' : $t;
        }
        return "";
    }
}

class FormulaPaperColumn extends PaperColumn {
    private static $registered = array();
    public static $list = array();
    public $formula;
    private $formula_function;
    public $statistics;
    public function __construct($name, $formula) {
        parent::__construct(strtolower($name), Column::VIEW_COLUMN | Column::FOLDABLE | Column::COMPLETABLE,
                            array("minimal" => true, "comparator" => "formula_compare"));
        $this->cssname = "formula";
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
    public function make_field($name, $errors) {
        foreach (self::$registered as $col)
            if (strcasecmp($col->formula->name, $name) == 0)
                return $col;
        if (substr($name, 0, 4) === "edit")
            return null;
        $formula = new Formula($name);
        if (!$formula->check()) {
            if ($errors && strpos($name, "(") !== false)
                $errors->add($formula->error_html(), 1);
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
        $view_bound = $pl->contact->permissive_view_score_bound();
        if ($pl->search->limitName == "a")
            $view_bound = max($view_bound, VIEWSCORE_AUTHOR - 1);
        if (!$pl->scoresOk
            || !$this->formula->check()
            || $this->formula->view_score($pl->contact) <= $view_bound)
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
        $t = $this->formula->unparse_html($s);
        if ($row->conflictType > 0 && $pl->contact->allow_administer($row)) {
            $ss = $formulaf($row, null, $pl->contact, null, true);
            $tt = $this->formula->unparse_html($ss);
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
        return $this->formula->unparse_text($s);
    }
    public function has_statistics() {
        return $this->statistics && $this->statistics->count();
    }
    public function statistic($what) {
        return $this->formula->unparse_html($this->statistics->statistic($what));
    }
}

class TagReportPaperColumn extends PaperColumn {
    private static $registered = array();
    public function __construct($tag) {
        parent::__construct("tagrep_" . preg_replace('/\W+/', '_', $tag),
                            Column::VIEW_ROW | Column::FOLDABLE);
        $this->cssname = "tagrep";
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
        if (!$pl->contact->privChair)
            return false;
        if ($visible)
            $pl->qopts["tags"] = 1;
        return true;
    }
    public function header($pl, $ordinal) {
        return "#~" . $this->tag . " tags";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->can_view_tags($row, true);
    }
    public function content($pl, $row, $rowidx) {
        if (($t = $row->paperTags) === "")
            return "";
        $a = array();
        foreach (pcMembers() as $pcm) {
            $mytag = " " . $pcm->contactId . "~" . $this->tag . "#";
            if (($p = strpos($t, $mytag)) !== false) {
                $n = (int) substr($t, $p + strlen($mytag));
                $a[] = $pcm->name_html() . ($n ? " (#$n)" : "");
            }
        }
        return join(", ", $a);
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
    PaperColumn::register(new SelectorPaperColumn("selon", array("minimal" => true, "cssname" => "sel")));
    PaperColumn::register(new SelectorPaperColumn("selconf", array("cssname" => "confselector")));
    PaperColumn::register(new SelectorPaperColumn("selunlessconf", array("minimal" => true, "cssname" => "sel")));
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
    PaperColumn::register(new PreferencePaperColumn("revpref", false));
    PaperColumn::register_synonym("pref", "revpref");
    PaperColumn::register(new PreferencePaperColumn("editrevpref", true));
    PaperColumn::register_synonym("editpref", "editrevpref");
    PaperColumn::register(new PreferenceListPaperColumn("allrevpref", false));
    PaperColumn::register_synonym("allpref", "allrevpref");
    PaperColumn::register(new PreferenceListPaperColumn("allrevtopicpref", true));
    PaperColumn::register_synonym("alltopicpref", "allrevtopicpref");
    PaperColumn::register(new DesirabilityPaperColumn);
    PaperColumn::register(new ReviewerListPaperColumn);
    PaperColumn::register(new AuthorsPaperColumn);
    PaperColumn::register(new CollabPaperColumn);
    PaperColumn::register_synonym("co", "collab");
    PaperColumn::register(new TagListPaperColumn);
    PaperColumn::register(new SearchOptsPaperColumn);
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
    PaperColumn::register_factory("edittag:", new EditTagPaperColumn(null, null, false));
    PaperColumn::register_factory("edittagval:", new EditTagPaperColumn(null, null, true));
    PaperColumn::register_factory("#", new TagPaperColumn(null, null, false));
    PaperColumn::register_factory("edit#", new EditTagPaperColumn(null, null, true));

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
