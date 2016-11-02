<?php
// papercolumn.php -- HotCRP helper classes for paper list content
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperColumn extends Column {
    static public $by_name = [];
    static public $factories = [];
    static private $synonyms = [];
    static private $j_by_name = null;
    static private $j_factories = null;

    const PREP_SORT = -1;
    const PREP_FOLDED = 0; // value matters
    const PREP_VISIBLE = 1; // value matters
    const PREP_COMPLETION = 2;

    function __construct($name, $flags, $extra = array()) {
        parent::__construct($name, $flags, $extra);
    }

    static function lookup_local($name) {
        $lname = strtolower($name);
        if (isset(self::$synonyms[$lname]))
            $lname = self::$synonyms[$lname];
        return get(self::$by_name, $lname, null);
    }

    static function _add_json($cj) {
        if (is_object($cj) && isset($cj->name) && is_string($cj->name)) {
            self::$j_by_name[strtolower($cj->name)] = $cj;
            return true;
        } else if (is_object($cj) && isset($cj->prefix) && is_string($cj->prefix)) {
            self::$j_factories[] = $cj;
            return true;
        } else
            return false;
    }
    private static function _expand_json($cj) {
        $f = null;
        if (($factory_class = get($cj, "factory_class")))
            $f = new $factory_class($cj);
        else if (($factory = get($cj, "factory")))
            $f = call_user_func($factory, $cj);
        else
            return null;
        if (isset($cj->name))
            self::$by_name[strtolower($cj->name)] = $f;
        else
            self::$factories[] = [strtolower($cj->prefix), $f];
        return $f;
    }

    static function lookup(Contact $user, $name, $errors = null) {
        $lname = strtolower($name);
        if (isset(self::$synonyms[$lname]))
            $lname = self::$synonyms[$lname];
        if (isset(self::$by_name[$lname]))
            return self::$by_name[$lname];
        foreach (self::$factories as $f)
            if (str_starts_with($lname, $f[0])
                && ($x = $f[1]->make_column($user, $name, $errors)))
                return $x;
        if (($colon = strpos($lname, ":")) > 0
            && ($syn = get(self::$synonyms, substr($lname, 0, $colon))))
            return self::lookup($user, $syn . substr($lname, $colon));
        if (self::$j_by_name === null) {
            self::$j_by_name = self::$j_factories = [];
            if (($jlist = opt("paperColumns")))
                expand_json_includes_callback($jlist, "PaperColumn::_add_json");
        }
        if (isset(self::$j_by_name[$name]))
            return self::_expand_json(self::$j_by_name[$name]);
        foreach (self::$j_factories as $fj)
            if (str_starts_with($lname, strtolower($fj->prefix))
                && ($fx = self::_expand_json($fj))
                && ($x = $fx->make_column($user, $name, $errors)))
                return $x;
        return null;
    }

    static function register($fdef) {
        $lname = strtolower($fdef->name);
        assert(!isset(self::$by_name[$lname]) && !isset(self::$synonyms[$lname]));
        self::$by_name[$lname] = $fdef;
        assert(func_num_args() == 1); // XXX backwards compat
        return $fdef;
    }
    static function register_factory($prefix, $f) {
        self::$factories[] = array(strtolower($prefix), $f);
    }
    static function register_synonym($new_name, $old_name) {
        $lold = strtolower($old_name);
        $lname = strtolower($new_name);
        assert(isset(self::$by_name[$lold]) && !isset(self::$by_name[$lname]) && !isset(self::$synonyms[$lname]));
        self::$synonyms[$lname] = $lold;
    }
    static function make_column_error($errors, $ehtml, $eprio) {
        if ($errors)
            $errors->add($ehtml, $eprio);
    }
    function make_editable() {
        return $this;
    }

    function prepare(PaperList $pl, $visible) {
        return true;
    }
    function realize(PaperList $pl) {
        return $this;
    }
    function annotate_field_js(PaperList $pl, &$fjs) {
    }

    function analyze($pl, &$rows) {
    }

    function sort_prepare($pl, &$rows, $sorter) {
    }
    function id_compare($a, $b) {
        return $a->paperId - $b->paperId;
    }

    function header(PaperList $pl, $is_text) {
        if ($is_text)
            return "<" . $this->name . ">";
        else
            return "&lt;" . htmlspecialchars($this->name) . "&gt;";
    }
    function completion_name() {
        if ($this->completable)
            return $this->name;
        else
            return false;
    }
    function completion_instances(Contact $user) {
        return array($this);
    }

    function content_empty(PaperList $pl, PaperInfo $row) {
        return false;
    }

    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        return "";
    }
    function text(PaperList $pl, PaperInfo $row) {
        return "";
    }

    function has_statistics() {
        return false;
    }
}

class IdPaperColumn extends PaperColumn {
    function __construct() {
        parent::__construct("id", Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("minimal" => true, "comparator" => "id_compare"));
    }
    function header(PaperList $pl, $is_text) {
        return "ID";
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $href = $pl->_paperLink($row);
        return "<a href=\"$href\" class=\"pnum taghl\" tabindex=\"4\">#$row->paperId</a>";
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->paperId;
    }
}

class SelectorPaperColumn extends PaperColumn {
    public $is_selector = true;
    function __construct($name, $extra) {
        parent::__construct($name, Column::VIEW_COLUMN, $extra);
    }
    function prepare(PaperList $pl, $visible) {
        if ($this->name == "selconf" && !$pl->contact->privChair)
            return false;
        if ($this->name == "selconf" || $this->name == "selunlessconf")
            $pl->qopts["reviewer"] = $pl->reviewer_cid();
        if ($this->name == "selconf" && ($tid = $pl->table_id()))
            $pl->add_header_script("add_conflict_ajax(" . json_encode("#$tid") . ")");
        return true;
    }
    function header(PaperList $pl, $is_text) {
        if ($this->name == "selconf")
            return "Conflict?";
        else if ($is_text)
            return "Selected";
        else
            return "";
    }
    private function checked(PaperList $pl, PaperInfo $row) {
        $def = ($this->name == "selon"
                || ($this->name == "selconf" && $row->reviewerConflictType > 0));
        return $pl->is_selected($row->paperId, $def);
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
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
    function text(PaperList $pl, PaperInfo $row) {
        return $this->checked($pl, $row) ? "Y" : "N";
    }
}

class TitlePaperColumn extends PaperColumn {
    private $has_decoration = false;
    private $highlight = false;
    function __construct() {
        parent::__construct("title", Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("minimal" => true, "comparator" => "title_compare"));
    }
    function prepare(PaperList $pl, $visible) {
        $this->has_decoration = $pl->contact->can_view_tags(null)
            && $pl->conf->tags()->has_decoration;
        if ($this->has_decoration)
            $pl->qopts["tags"] = 1;
        $this->highlight = get($pl->search->matchPreg, "title");
        return true;
    }
    function title_compare($a, $b) {
        return strcasecmp($a->title, $b->title);
    }
    function header(PaperList $pl, $is_text) {
        return "Title";
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $t = '<a href="' . $pl->_paperLink($row) . '" class="ptitle taghl';

        $highlight_text = Text::highlight($row->title, $this->highlight, $highlight_count);

        if (!$highlight_count && ($format = $row->title_format())) {
            $pl->need_render = true;
            $t .= ' need-format" data-format="' . $format
                . '" data-title="' . htmlspecialchars($row->title);
        }

        $t .= '" tabindex="5">' . $highlight_text . '</a>'
            . $pl->_contentDownload($row);

        if ($this->has_decoration && (string) $row->paperTags !== ""
            && ($tags = $row->viewable_tags($pl->contact)) !== ""
            && ($tags = $pl->tagger->unparse_decoration_html($tags)) !== "")
            $t .= $pl->maybe_conflict_nooverride($row, $tags, $pl->contact->can_view_tags($row, false));

        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->title;
    }
}

class StatusPaperColumn extends PaperColumn {
    private $is_long;
    function __construct($name, $is_long, $extra = 0) {
        parent::__construct($name, Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("className" => "pl_status", "comparator" => "status_compare"));
        $this->is_long = $is_long;
    }
    function sort_prepare($pl, &$rows, $sorter) {
        $force = $pl->search->limitName != "a" && $pl->contact->privChair;
        foreach ($rows as $row)
            if ($row->outcome && $pl->contact->can_view_decision($row, $force))
                $row->_status_sort_info = $row->outcome;
            else
                $row->_status_sort_info = -10000;
    }
    function status_compare($a, $b) {
        $x = $b->_status_sort_info - $a->_status_sort_info;
        $x = $x ? $x : ($a->timeWithdrawn > 0) - ($b->timeWithdrawn > 0);
        $x = $x ? $x : ($b->timeSubmitted > 0) - ($a->timeSubmitted > 0);
        return $x ? $x : ($b->paperStorageId > 1) - ($a->paperStorageId > 1);
    }
    function header(PaperList $pl, $is_text) {
        return "Status";
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
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
    function text(PaperList $pl, PaperInfo $row) {
        $status_info = $pl->contact->paper_status_info($row, $pl->search->limitName != "a" && $pl->contact->allow_administer($row));
        return $status_info[1];
    }
}

class ReviewStatusPaperColumn extends PaperColumn {
    function __construct() {
        parent::__construct("revstat", Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("comparator" => "review_status_compare"));
    }
    function prepare(PaperList $pl, $visible) {
        if ($pl->contact->privChair)
            $pl->qopts["startedReviewCount"] = true;
        else if ($pl->contact->is_reviewer())
            $pl->qopts["startedReviewCount"] = $pl->qopts["inProgressReviewCount"] = true;
        else if ($pl->conf->timeAuthorViewReviews())
            $pl->qopts["inProgressReviewCount"] = true;
        else
            return false;
        return true;
    }
    function sort_prepare($pl, &$rows, $sorter) {
        foreach ($rows as $row) {
            if (!$pl->contact->can_view_review_assignment($row, null, null))
                $row->_review_status_sort_info = 2147483647;
            else
                $row->_review_status_sort_info = $row->num_reviews_submitted()
                    + $row->num_reviews_started($pl->contact) / 1000.0;
        }
    }
    function review_status_compare($a, $b) {
        $av = $a->_review_status_sort_info;
        $bv = $b->_review_status_sort_info;
        return ($av < $bv ? 1 : ($av == $bv ? 0 : -1));
    }
    function header(PaperList $pl, $is_text) {
        if ($is_text)
            return "# Reviews";
        else
            return '<span class="need-tooltip" data-tooltip="# completed reviews / # assigned reviews" data-tooltip-dir="b">#&nbsp;Reviews</span>';
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->contact->can_view_review_assignment($row, null, null);
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $done = $row->num_reviews_submitted();
        $started = $row->num_reviews_started($pl->contact);
        return "<b>$done</b>" . ($done == $started ? "" : "/$started");
    }
    function text(PaperList $pl, PaperInfo $row) {
        $done = $row->num_reviews_submitted();
        $started = $row->num_reviews_started($pl->contact);
        return $done . ($done == $started ? "" : "/$started");
    }
}

class AuthorsPaperColumn extends PaperColumn {
    private $aufull;
    private $anonau;
    function __construct() {
        parent::__construct("authors", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    function header(PaperList $pl, $is_text) {
        return "Authors";
    }
    function prepare(PaperList $pl, $visible) {
        $this->aufull = !$pl->is_folded("aufull");
        $this->anonau = !$pl->is_folded("anonau");
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
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->contact->can_view_authors($row, true);
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
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
                    $out[] = join(", ", $auy);
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
            return join($any_affhl || $this->aufull ? "; " : ", ", $out);
        }
    }
    function text(PaperList $pl, PaperInfo $row) {
        if (!$pl->contact->can_view_authors($row) && !$this->anonau)
            return "";
        $out = [];
        if (!$this->aufull) {
            foreach ($row->author_list() as $au)
                $out[] = $au->abbrevname_text();
            return join("; ", $out);
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
            return join("; ", $out);
        }
    }
}

class CollabPaperColumn extends PaperColumn {
    function __construct() {
        parent::__construct("collab", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    function prepare(PaperList $pl, $visible) {
        return !!$pl->conf->setting("sub_collab") && $pl->contact->can_view_some_authors();
    }
    function header(PaperList $pl, $is_text) {
        return "Collaborators";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return ($row->collaborators == ""
                || strcasecmp($row->collaborators, "None") == 0
                || !$pl->contact->can_view_authors($row, true));
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $x = "";
        foreach (explode("\n", $row->collaborators) as $c)
            $x .= ($x === "" ? "" : ", ") . trim($c);
        return Text::highlight($x, get($pl->search->matchPreg, "collaborators"));
    }
    function text(PaperList $pl, PaperInfo $row) {
        $x = "";
        foreach (explode("\n", $row->collaborators) as $c)
            $x .= ($x === "" ? "" : ", ") . trim($c);
        return $x;
    }
}

class AbstractPaperColumn extends PaperColumn {
    function __construct() {
        parent::__construct("abstract", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    function header(PaperList $pl, $is_text) {
        return "Abstract";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return $row->abstract == "";
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $t = Text::highlight($row->abstract, get($pl->search->matchPreg, "abstract"), $highlight_count);
        if (!$highlight_count && ($format = $row->format_of($row->abstract))) {
            $pl->need_render = true;
            $t = '<div class="need-format" data-format="' . $format . '.abs.plx">' . $t . '</div>';
        } else {
            $t = Ht::link_urls(Text::single_line_paragraphs($t));
            $t = preg_replace('/(?:\r\n?){2,}|\n{2,}/', " ¶ ", $t);
        }
        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->abstract;
    }
}

class TopicListPaperColumn extends PaperColumn {
    function __construct() {
        parent::__construct("topics", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->conf->has_topics())
            return false;
        if ($visible)
            $pl->qopts["topics"] = 1;
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Topics";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !isset($row->topicIds) || $row->topicIds == "";
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        return $row->unparse_topics_html(true, $pl->reviewer_contact());
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->unparse_topics_text();
    }
}

class ReviewerTypePaperColumn extends PaperColumn {
    protected $xreviewer;
    function __construct($name) {
        parent::__construct($name, Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("comparator" => "reviewer_type_compare"));
    }
    function analyze($pl, &$rows) {
        $this->xreviewer = $pl->prepare_xreviewer($rows);
    }
    function sort_prepare($pl, &$rows, $sorter) {
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
    function reviewer_type_compare($a, $b) {
        return $b->_reviewer_type_sort_info - $a->_reviewer_type_sort_info;
    }
    function header(PaperList $pl, $is_text) {
        if ($this->xreviewer && $is_text)
            return $pl->contact->name_text_for($this->xreviewer) . " review";
        else if ($this->xreviewer)
            return $pl->contact->name_html_for($this->xreviewer) . "<br />review";
        else
            return "Review";
    }
    const F_CONFLICT = 1;
    const F_LEAD = 2;
    const F_SHEPHERD = 4;
    private function analysis(PaperList $pl, PaperInfo $row) {
        $ranal = null;
        $xrow = $this->xreviewer ? get($row, "_xreviewer") : $row;
        if ($xrow && $xrow->reviewType) {
            $ranal = $pl->make_review_analysis($xrow, $row);
            if ($ranal->needsSubmit)
                $pl->any->need_review = true;
        }
        $flags = 0;
        if ($xrow && $xrow->conflictType > 0)
            $flags |= self::F_CONFLICT;
        if (!$this->xreviewer && ($me = $pl->contact->contactId)) {
            if ($row->leadContactId == $me)
                $flags |= self::F_LEAD;
            if ($row->shepherdContactId == $me)
                $flags |= self::F_SHEPHERD;
        }
        return [$ranal, $flags];
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        list($ranal, $flags) = $this->analysis($pl, $row);
        $t = "";
        if ($ranal)
            $t = $ranal->icon_html(true);
        else if ($flags & self::F_CONFLICT)
            $t = review_type_icon(-1);
        $x = null;
        if ($flags & self::F_LEAD)
            $x[] = review_lead_icon();
        if ($flags & self::F_SHEPHERD)
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
    function text(PaperList $pl, PaperInfo $row) {
        list($ranal, $flags) = $this->analysis($pl, $row);
        $t = null;
        if ($flags & self::F_LEAD)
            $t[] = "Lead";
        if ($flags & self::F_SHEPHERD)
            $t[] = "Shepherd";
        if ($ranal)
            $t[] = $ranal->icon_text();
        if ($flags & self::F_CONFLICT)
            $t[] = "Conflict";
        return $t ? join("; ", $t) : "";
    }
}

class ReviewSubmittedPaperColumn extends PaperColumn {
    function __construct() {
        parent::__construct("revsubmitted", Column::VIEW_COLUMN | Column::COMPLETABLE, array("className" => "pl_text"));
    }
    function prepare(PaperList $pl, $visible) {
        return !!$pl->contact->isPC;
    }
    function header(PaperList $pl, $is_text) {
        return "Review status";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$row->reviewId;
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        if (!$row->reviewId)
            return "";
        $ranal = $pl->make_review_analysis($row, $row);
        if ($ranal->needsSubmit)
            $pl->any->need_review = true;
        return $ranal->status_html();
    }
}

class ReviewDelegationPaperColumn extends PaperColumn {
    function __construct() {
        parent::__construct("revdelegation", Column::VIEW_COLUMN,
                            array("className" => "pl_text",
                                  "comparator" => "review_delegation_compare"));
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->isPC)
            return false;
        $pl->qopts["reviewerName"] = true;
        $pl->qopts["allReviewScores"] = true;
        $pl->qopts["reviewLimitSql"] = "PaperReview.requestedBy=" . $pl->reviewer_cid();
        return true;
    }
    function review_delegation_compare($a, $b) {
        $x = strcasecmp($a->reviewLastName, $b->reviewLastName);
        $x = $x ? $x : strcasecmp($a->reviewFirstName, $b->reviewFirstName);
        return $x ? $x : strcasecmp($a->reviewEmail, $b->reviewEmail);
    }
    function header(PaperList $pl, $is_text) {
        return "Reviewer";
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $t = Text::user_html($row->reviewFirstName, $row->reviewLastName, $row->reviewEmail);
        if ($pl->contact->isPC) {
            if (!$row->reviewLastLogin)
                $time = "Never";
            else if ($pl->contact->privChair)
                $time = $row->conf->unparse_time_short($row->reviewLastLogin);
            else
                $time = $row->conf->unparse_time_obscure($row->reviewLastLogin);
            $t .= "<br /><small class=\"nw\">Last update: $time</small>";
        }
        return $t;
    }
}

class AssignReviewPaperColumn extends ReviewerTypePaperColumn {
    function __construct() {
        parent::__construct("assrev");
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->is_manager())
            return false;
        if ($visible > 0 && ($tid = $pl->table_id()))
            $pl->add_header_script("add_assrev_ajax(" . json_encode("#$tid") . ")");
        $pl->qopts["reviewer"] = $pl->reviewer_cid();
        return true;
    }
    function analyze($pl, &$rows) {
        $this->xreviewer = false;
    }
    function header(PaperList $pl, $is_text) {
        return "Assignment";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->contact->allow_administer($row);
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
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
    function __construct() {
        parent::__construct("desirability", Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("comparator" => "desirability_compare"));
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->privChair)
            return false;
        if ($visible)
            $pl->qopts["desirability"] = 1;
        return true;
    }
    function desirability_compare($a, $b) {
        return $b->desirability - $a->desirability;
    }
    function header(PaperList $pl, $is_text) {
        return "Desirability";
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        return htmlspecialchars($this->text($pl, $row));
    }
    function text(PaperList $pl, PaperInfo $row) {
        return get($row, "desirability") + 0;
    }
}

class TopicScorePaperColumn extends PaperColumn {
    private $contact;
    function __construct() {
        parent::__construct("topicscore", Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("comparator" => "topic_score_compare"));
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->conf->has_topics() || !$pl->contact->isPC)
            return false;
        if ($visible) {
            $this->contact = $pl->reviewer_contact();
            $pl->qopts["reviewer"] = $pl->reviewer_cid();
            $pl->qopts["topics"] = 1;
        }
        return true;
    }
    function topic_score_compare($a, $b) {
        return $b->topic_interest_score($this->contact) - $a->topic_interest_score($this->contact);
    }
    function header(PaperList $pl, $is_text) {
        return $is_text ? "Topic score" : "Topic<br />score";
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        return htmlspecialchars($row->topic_interest_score($this->contact));
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->topic_interest_score($this->contact);
    }
}

class PreferencePaperColumn extends PaperColumn {
    private $editable;
    private $contact;
    private $viewer_contact;
    private $is_direct;
    private $careful;
    function __construct($name, $editable, $contact = null) {
        parent::__construct($name, Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("comparator" => "preference_compare",
                                  "className" => $editable ? "pl_editrevpref" : "pl_revpref"));
        $this->editable = $editable;
        $this->contact = $contact;
    }
    function make_column(Contact $user, $name, $errors) {
        $colon = strpos($name, ":");
        $cids = ContactSearch::make_pc(substr($name, $colon + 1), $user)->ids;
        if (empty($cids))
            self::make_column_error($errors, "No PC member matches “" . htmlspecialchars(substr($name, $colon + 1)) . "”.", 2);
        else if (count($cids) == 1) {
            $pcm = $user->conf->pc_members();
            return new PreferencePaperColumn(substr($name, 0, $colon + 1) . $pcm[$cids[0]]->email, $this->editable, $pcm[$cids[0]]);
        } else
            self::make_column_error($errors, "“" . htmlspecialchars(substr($name, $colon + 1)) . "” matches more than one PC member.", 2);
        return null;
    }
    function make_editable() {
        return new PreferencePaperColumn($this->name, true, $this->contact);
    }
    function prepare(PaperList $pl, $visible) {
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
    function completion_name() {
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
    function preference_compare($a, $b) {
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
    function header(PaperList $pl, $is_text) {
        if ($this->careful && $is_text)
            return $pl->contact->name_text_for($this->contact) . " preference";
        else if ($this->careful)
            return $pl->contact->name_html_for($this->contact) . "<br />preference";
        else
            return "Preference";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return $this->careful && !$pl->contact->allow_administer($row);
    }
    private function show_content(PaperList $pl, PaperInfo $row, $zero_empty) {
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
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $has_cflt = $this->is_direct ? $row->reviewerConflictType > 0
            : $row->has_conflict($this->contact);
        if ($has_cflt && !$pl->contact->allow_administer($row))
            return isset($pl->columns["revtype"]) ? "" : review_type_icon(-1);
        else if (!$this->editable)
            return $this->show_content($pl, $row, false);
        else {
            $ptext = $this->text($pl, $row);
            $iname = "revpref" . $row->paperId;
            if (!$this->is_direct)
                $iname .= "u" . $this->contact->contactId;
            return '<input name="' . $iname . '" class="revpref" value="' . ($ptext !== "0" ? $ptext : "") . '" type="text" size="4" tabindex="2" placeholder="0" />' . ($has_cflt && !$this->is_direct ? "&nbsp;" . review_type_icon(-1) : "");
        }
    }
    function text(PaperList $pl, PaperInfo $row) {
        return unparse_preference($this->preference_values($row));
    }
}

class PreferenceListPaperColumn extends PaperColumn {
    private $topics;
    function __construct($name, $topics) {
        $this->topics = $topics;
        parent::__construct($name, Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    function prepare(PaperList $pl, $visible) {
        if ($this->topics && !$pl->conf->has_topics())
            $this->topics = false;
        if (!$pl->contact->is_manager())
            return false;
        if ($visible) {
            $pl->qopts["allReviewerPreference"] = $pl->qopts["allConflictType"] = 1;
            if ($this->topics)
                $pl->qopts["topics"] = 1;
        }
        $pl->conf->stash_hotcrp_pc($pl->contact);
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Preferences";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->contact->allow_administer($row);
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $prefs = $row->reviewer_preferences();
        $ts = array();
        if ($prefs || $this->topics)
            foreach ($row->conf->pc_members() as $pcid => $pc) {
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
            $pl->need_render = true;
            return $t;
        } else
            return '';
    }
}

class ReviewerListPaperColumn extends PaperColumn {
    private $topics;
    function __construct() {
        parent::__construct("reviewers", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->can_view_some_review_identity(null))
            return false;
        $this->topics = $pl->conf->has_topics();
        if ($visible) {
            $pl->qopts["reviewList"] = 1;
            if ($pl->contact->privChair)
                $pl->qopts["allReviewerPreference"] = $pl->qopts["topics"] = 1;
        }
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Reviewers";
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        // see also search.php > getaction == "reviewers"
        if (!isset($pl->review_list[$row->paperId]))
            return "";
        $x = [];
        foreach ($pl->review_list[$row->paperId] as $xrow) {
            $ranal = $pl->make_review_analysis($xrow, $row);
            $n = $pl->contact->reviewer_html_for($xrow) . "&nbsp;" . $ranal->icon_html(false);
            if ($pl->contact->privChair) {
                $pref = $row->reviewer_preference((int) $xrow->contactId);
                if ($this->topics && $row->has_topics())
                    $pref[2] = $row->topic_interest_score((int) $xrow->contactId);
                $n .= unparse_preference_span($pref);
            }
            $x[] = '<span class="nw">' . $n . '</span>';
        }
        return $pl->maybeConflict($row, join(", ", $x),
                                  $pl->contact->can_view_review_identity($row, null, false));
    }
    function text(PaperList $pl, PaperInfo $row) {
        if (!isset($pl->review_list[$row->paperId])
            || !$pl->contact->can_view_review_identity($row, null))
            return "";
        $x = [];
        foreach ($pl->review_list[$row->paperId] as $xrow)
            $x[] = $pl->contact->name_text_for($xrow);
        return join("; ", $x);
    }
}

class PCConflictListPaperColumn extends PaperColumn {
    function __construct() {
        parent::__construct("pcconf", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->privChair)
            return false;
        if ($visible)
            $pl->qopts["allConflictType"] = 1;
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "PC conflicts";
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $y = [];
        $pcm = $row->conf->pc_members();
        foreach ($row->conflicts() as $id => $type)
            if (($pc = get($pcm, $id)))
                $y[$pc->sort_position] = $pl->contact->reviewer_html_for($pc);
        ksort($y);
        return join(", ", $y);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $y = [];
        $pcm = $row->conf->pc_members();
        foreach ($row->conflicts() as $id => $type)
            if (($pc = get($pcm, $id)))
                $y[$pc->sort_position] = $pl->contact->name_text_for($pc);
        ksort($y);
        return join("; ", $y);
    }
}

class ConflictMatchPaperColumn extends PaperColumn {
    private $field;
    function __construct($name, $field) {
        parent::__construct($name, Column::VIEW_ROW);
        $this->field = $field;
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->contact->privChair;
    }
    function header(PaperList $pl, $is_text) {
        $what = $this->field == "authorInformation" ? "authors" : "collaborators";
        if ($is_text)
            return "Potential conflict in $what";
        else
            return "<strong>Potential conflict in $what</strong>";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return get($pl->search->matchPreg, $this->field, "") == "";
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
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
    function __construct($editable) {
        parent::__construct("tags", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
        $this->editable = $editable;
    }
    function make_editable() {
        return new TagListPaperColumn(true);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->can_view_tags(null))
            return false;
        if ($visible)
            $pl->qopts["tags"] = 1;
        if ($visible && $this->editable && ($tid = $pl->table_id()))
            $pl->add_header_script("plinfo_tags(" . json_encode("#$tid") . ")", "plinfo_tags");
        if ($this->editable)
            $pl->has_editable_tags = true;
        return true;
    }
    function annotate_field_js(PaperList $pl, &$fjs) {
        $fjs["highlight_tags"] = $pl->search->highlight_tags();
        if ($pl->conf->tags()->has_votish)
            $fjs["votish_tags"] = array_values(array_map(function ($t) { return $t->tag; }, $pl->conf->tags()->filter("votish")));
    }
    function header(PaperList $pl, $is_text) {
        return "Tags";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->contact->can_view_tags($row, true);
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $wrap_conflict = false;
        $viewable = $row->viewable_tags($pl->contact);
        if ($viewable === "" && $row->paperTags && $pl->contact->allow_administer($row)) {
            $wrap_conflict = true;
            $viewable = $row->viewable_tags($pl->contact, true);
        }
        $pl->row_attr["data-tags"] = trim($viewable);
        if ($this->editable)
            $pl->row_attr["data-tags-editable"] = 1;
        if ($viewable !== "" || $this->editable) {
            $pl->need_render = true;
            if ($wrap_conflict)
                return '<div class="fx5"><span class="need-tags"></span></div>';
            else
                return '<span class="need-tags"></span>';
        } else
            return "";
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->tagger->unparse_hashed($row->viewable_tags($pl->contact));
    }
}

class TagPaperColumn extends PaperColumn {
    protected $is_value;
    protected $dtag;
    protected $xtag;
    protected $ctag;
    protected $editable = false;
    static private $sortf_ctr = 0;
    function __construct($name, $tag, $is_value) {
        parent::__construct($name, Column::VIEW_COLUMN | Column::COMPLETABLE, array("comparator" => "tag_compare"));
        $this->dtag = $tag;
        $this->is_value = $is_value;
    }
    function make_column(Contact $user, $name, $errors) {
        $p = str_starts_with($name, "#") ? 0 : strpos($name, ":");
        return new TagPaperColumn($name, substr($name, $p + 1), $this->is_value);
    }
    function make_editable() {
        return new EditTagPaperColumn($this->name, $this->dtag, $this->is_value);
    }
    function sorts_my_tag($sorter) {
        return preg_match('/\A(?:edit)?(?:#|tag:|tagval:)\s*(\S+)\z/i', $sorter->type, $m)
            && strcasecmp($m[1], $this->dtag) == 0;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->can_view_tags(null))
            return false;
        if (!$this->dtag && $visible === PaperColumn::PREP_COMPLETION)
            return true;
        $tagger = new Tagger($pl->contact);
        if (!($ctag = $tagger->check($this->dtag, Tagger::NOVALUE | Tagger::ALLOWCONTACTID)))
            return false;
        $this->xtag = strtolower($ctag);
        $this->ctag = " {$this->xtag}#";
        if ($visible)
            $pl->qopts["tags"] = 1;
        $this->className = ($this->is_value ? "pl_tagval" : "pl_tag");
        return true;
    }
    function completion_name() {
        return $this->dtag ? "#$this->dtag" : "#<tag>";
    }
    function sort_prepare($pl, &$rows, $sorter) {
        $sorter->sortf = $sortf = "_tag_sort_info." . self::$sortf_ctr;
        ++self::$sortf_ctr;
        $careful = !$pl->contact->privChair && !$pl->conf->tag_seeall;
        $unviewable = $empty = $sorter->reverse ? -(TAG_INDEXBOUND - 1) : TAG_INDEXBOUND - 1;
        if ($this->editable)
            $empty = $sorter->reverse ? -TAG_INDEXBOUND : TAG_INDEXBOUND;
        foreach ($rows as $row)
            if ($careful && !$pl->contact->can_view_tag($row, $this->xtag, true))
                $row->$sortf = $unviewable;
            else if (($row->$sortf = $row->tag_value($this->xtag)) === null)
                $row->$sortf = $empty;
    }
    function tag_compare($a, $b, $sorter) {
        $sortf = $sorter->sortf;
        return $a->$sortf < $b->$sortf ? -1 :
            ($a->$sortf == $b->$sortf ? 0 : 1);
    }
    function header(PaperList $pl, $is_text) {
        return "#$this->dtag";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->contact->can_view_tag($row, $this->xtag, true);
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        if (($v = $row->tag_value($this->xtag)) === null)
            return "";
        else if ($v === 0.0 && !$this->is_value)
            return "✓";
        else
            return $v;
    }
    function text(PaperList $pl, PaperInfo $row) {
        if (($v = $row->tag_value($this->xtag)) === null)
            return "N";
        else if ($v === 0.0 && !$this->is_value)
            return "Y";
        else
            return $v;
    }
}

class EditTagPaperColumn extends TagPaperColumn {
    private $editsort;
    function __construct($name, $tag, $is_value) {
        if ($is_value === null)
            $is_value = true;
        parent::__construct($name, $tag, $is_value);
        $this->editable = true;
    }
    function prepare(PaperList $pl, $visible) {
        $this->editsort = false;
        if (!parent::prepare($pl, $visible))
            return false;
        if ($visible > 0 && ($tid = $pl->table_id())) {
            $sorter = get($pl->sorters, 0);
            if ($this->sorts_my_tag($sorter)
                && !$sorter->reverse
                && (!$pl->search->thenmap || $pl->search->is_order_anno)
                && $this->is_value) {
                $this->editsort = true;
                $pl->tbody_attr["data-drag-tag"] = $this->dtag;
            }
            $pl->has_editable_tags = true;
            $pl->add_header_script("plinfo_tags(" . json_encode("#$tid") . ")", "plinfo_tags");
        }
        $this->className = $this->is_value ? "pl_edittagval" : "pl_edittag";
        return true;
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $v = $row->tag_value($this->xtag);
        if ($this->editsort && !isset($pl->row_attr["data-tags"]))
            $pl->row_attr["data-tags"] = $this->dtag . "#" . $v;
        if (!$pl->contact->can_change_tag($row, $this->dtag, 0, 0, true))
            return $this->is_value ? (string) $v : ($v === null ? "" : "&#x2713;");
        if (!$this->is_value)
            return '<input type="checkbox" class="cb edittag" name="tag:' . "$this->dtag $row->paperId" . '" value="x" tabindex="6"'
                . ($v !== false ? ' checked="checked"' : '') . " />";
        $t = '<input type="text" class="edittagval';
        if ($this->editsort) {
            $t .= " need-draghandle";
            $pl->need_render = true;
        }
        return $t . '" size="4" name="tag:' . "$this->dtag $row->paperId" . '" value="'
            . ($v !== false ? htmlspecialchars($v) : "") . '" tabindex="6" />';
    }
}

class Score_PaperColumn extends PaperColumn {
    public $score;
    public $max_score;
    private $form_field;
    private $xreviewer;
    function __construct(ReviewField $form_field = null) {
        $score = $form_field ? $form_field->id : null;
        parent::__construct($score, Column::VIEW_COLUMN | Column::FOLDABLE | Column::COMPLETABLE,
                            ["minimal" => true, "className" => "pl_score", "comparator" => "score_compare"]);
        $this->score = $score;
        $this->form_field = $form_field;
    }
    function make_column(Contact $user, $name, $errors) {
        if ($name === "scores") {
            $fs = $user->conf->all_review_fields();
            $errors && ($errors->allow_empty = true);
        } else
            $fs = [$user->conf->review_field_search($name)];
        $fs = array_filter($fs, function ($f) { return $f && $f->has_options && $f->displayed; });
        return array_map(function ($f) { return new Score_PaperColumn($f); }, $fs);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->scoresOk)
            return false;
        else if (!$this->form_field)
            return $visible == self::PREP_COMPLETION;
        else if ($this->form_field->view_score <= $pl->contact->permissive_view_score_bound())
            return false;
        if ($visible) {
            $pl->qopts["scores"][$this->score] = true;
            $this->max_score = count($this->form_field->options);
        }
        return true;
    }
    function analyze($pl, &$rows) {
        $this->xreviewer = $pl->prepare_xreviewer($rows);
    }
    function sort_prepare($pl, &$rows, $sorter) {
        $this->_sortinfo = $sortinfo = "_score_sortinfo." . $this->score . $sorter->score;
        $this->_avginfo = $avginfo = "_score_avginfo." . $this->score;
        $reviewer = $pl->reviewer_cid();
        $field = $this->form_field;
        foreach ($rows as $row)
            if (($scores = $row->viewable_scores($field, $pl->contact, null)) !== null) {
                $scoreinfo = new ScoreInfo($scores);
                $row->$sortinfo = $scoreinfo->sort_data($sorter->score, $reviewer);
                $row->$avginfo = $scoreinfo->mean();
            } else
                $row->$sortinfo = $row->$avginfo = null;
    }
    function score_compare($a, $b) {
        $sortinfo = $this->_sortinfo;
        if (!($x = ScoreInfo::compare($b->$sortinfo, $a->$sortinfo))) {
            $avginfo = $this->_avginfo;
            $x = ScoreInfo::compare($b->$avginfo, $a->$avginfo);
        }
        return $x;
    }
    function header(PaperList $pl, $is_text) {
        return $is_text ? $this->form_field->abbreviation : $this->form_field->web_abbreviation();
    }
    function completion_name() {
        return $this->form_field ? $this->form_field->abbreviation : "scores";
    }
    function completion_instances(Contact $user) {
        return array_merge([$this], $this->make_column($user, "scores", null));
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        // Do not use viewable_scores to determine content emptiness, since
        // that would load the scores from the DB -- even for folded score
        // columns.
        return !$row->may_have_viewable_scores($this->form_field, $pl->contact, true);
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $wrap_conflict = false;
        $scores = $row->viewable_scores($this->form_field, $pl->contact, false);
        if ($scores === null && $pl->contact->allow_administer($row)) {
            $wrap_conflict = true;
            $scores = $row->viewable_scores($this->form_field, $pl->contact, true);
        }
        if (!$scores)
            return "";
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
}

class FormulaGraph_PaperColumn extends PaperColumn {
    public $formula;
    private $indexes_function;
    private $formula_function;
    private $results;
    private $any_real;
    private $_sortinfo;
    private $_avginfo;
    private $xreviewer;
    static private $nregistered;
    function __construct($name, Formula $formula = null) {
        parent::__construct(strtolower($name), Column::VIEW_COLUMN | Column::FOLDABLE | Column::COMPLETABLE,
                            ["minimal" => true, "className" => "pl_score", "comparator" => "score_compare"]);
        $this->formula = $formula;
    }
    function make_column(Contact $user, $name, $errors) {
        if (str_starts_with($name, "g("))
            $name = substr($name, 1);
        else if (str_starts_with($name, "graph("))
            $name = substr($name, 5);
        else
            return null;
        $formula = new Formula($user, $name, true);
        if (!$formula->check()) {
            self::make_column_error($errors, $formula->error_html(), 1);
            return null;
        } else if (!($formula->result_format() instanceof ReviewField)) {
            self::make_column_error($errors, "Graphed formulas must return review fields.", 1);
            return null;
        }
        ++self::$nregistered;
        return new FormulaGraph_PaperColumn("scorex" . self::$nregistered, $formula);
    }
    function completion_name() {
        return "graph(<formula>)";
    }
    function prepare(PaperList $pl, $visible) {
        if (!$this->formula && $visible === PaperColumn::PREP_COMPLETION)
            return true;
        if (!$pl->scoresOk
            || !$this->formula->check($pl->contact)
            || !($pl->search->limitName == "a"
                 ? $pl->contact->can_view_formula_as_author($this->formula)
                 : $pl->contact->can_view_formula($this->formula)))
            return false;
        $this->formula_function = $this->formula->compile_sortable_function();
        $this->indexes_function = null;
        if ($this->formula->is_indexed())
            $this->indexes_function = Formula::compile_indexes_function($pl->contact, $this->formula->datatypes());
        if ($visible)
            $this->formula->add_query_options($pl->qopts);
        return true;
    }
    function analyze($pl, &$rows) {
        $this->xreviewer = $pl->prepare_xreviewer($rows);
    }
    private function scores($pl, PaperInfo $row, $forceShow) {
        $indexesf = $this->indexes_function;
        $indexes = $indexesf ? $indexesf($row, $pl->contact, $forceShow) : [null];
        $formulaf = $this->formula_function;
        $vs = [];
        foreach ($indexes as $i)
            if (($v = $formulaf($row, $i, $pl->contact, $forceShow)) !== null)
                $vs[$i] = $v;
        return $vs;
    }
    function sort_prepare($pl, &$rows, $sorter) {
        $this->_sortinfo = $sortinfo = "_formulagraph_sortinfo." . $this->name;
        $this->_avginfo = $avginfo = "_formulagraph_avginfo." . $this->name;
        $reviewer = $pl->reviewer_cid();
        foreach ($rows as $row)
            if (($scores = $this->scores($pl, $row, false))) {
                $scoreinfo = new ScoreInfo($scores);
                $row->$sortinfo = $scoreinfo->sort_data($sorter->score, $reviewer);
                $row->$avginfo = $scoreinfo->mean();
            } else
                $row->$sortinfo = $row->$avginfo = null;
    }
    function score_compare($a, $b) {
        $sortinfo = $this->_sortinfo;
        if (!($x = ScoreInfo::compare($b->$sortinfo, $a->$sortinfo))) {
            $avginfo = $this->_avginfo;
            $x = ScoreInfo::compare($b->$avginfo, $a->$avginfo);
        }
        return $x;
    }
    function header(PaperList $pl, $is_text) {
        $x = $this->formula->column_header();
        if ($is_text)
            return $x;
        else if ($this->formula->headingTitle && $this->formula->headingTitle != $x)
            return "<span class=\"need-tooltip\" data-tooltip=\"" . htmlspecialchars($this->formula->headingTitle) . "\">" . htmlspecialchars($x) . "</span>";
        else
            return htmlspecialchars($x);
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $values = $this->scores($pl, $row, false);
        $wrap_conflict = false;
        if (empty($values) && $row->conflictType > 0 && $pl->contact->allow_administer($row)) {
            $values = $this->scores($pl, $row, true);
            $wrap_conflict = true;
        }
        if (empty($values))
            return "";
        $my_score = null;
        if (!$this->xreviewer)
            $my_score = get($values, $pl->reviewer_cid());
        else if (isset($row->_xreviewer))
            $my_score = get($values, $row->_xreviewer->reviewContactId);
        $t = $this->formula->result_format()->unparse_graph($values, 1, $my_score);
        if ($pl->table_type && $rowidx % 16 == 15)
            $t .= "<script>scorechart()</script>";
        return $wrap_conflict ? '<span class="fx5">' . $t . '</span>' : $t;
    }
}

class Option_PaperColumn extends PaperColumn {
    private $opt;
    function __construct($opt, $isrow = false) {
        if ($opt && $isrow)
            $name = $opt->abbr . "-row";
        else
            $name = $opt ? $opt->abbr : null;
        parent::__construct($name, Column::VIEW_COLUMN | Column::FOLDABLE | Column::COMPLETABLE,
                            array("comparator" => "option_compare"));
        if (($opt && $opt instanceof TextPaperOption) || $isrow)
            $this->view = Column::VIEW_ROW;
        $this->minimal = true;
        $this->className = "pl_option";
        if ($opt && $opt->type == "checkbox" && !$isrow)
            $this->className .= " plc";
        else if ($opt && $opt->type == "numeric" && !$isrow)
            $this->className .= " plrd";
        $this->opt = $opt;
    }
    function make_column(Contact $user, $name, $errors) {
        if ($name === "options") {
            $errors && ($errors->allow_empty = true);
            $opts = [];
            foreach ($user->user_option_list() as $opt)
                if ($opt->display() >= 0)
                    $opts[] = new Option_PaperColumn($opt, false);
            return $opts;
        }
        $has_colon = false;
        if (str_starts_with($name, "opt:")) {
            $name = substr($name, 4);
            $has_colon = true;
        } else if (strpos($name, ":") !== false)
            return null;
        $isrow = false;
        $opts = $user->conf->paper_opts->search($name);
        if (empty($opts) && str_ends_with($name, "-row")) {
            $isrow = true;
            $name = substr($name, 0, strlen($name) - 4);
            $opts = $user->conf->paper_opts->search($name);
        }
        if (count($opts) == 1) {
            reset($opts);
            $opt = current($opts);
            if ($opt->display() >= 0)
                return new Option_PaperColumn($opt, $isrow);
            self::make_column_error($errors, "Option “" . htmlspecialchars($name) . "” can’t be displayed.");
        } else if ($has_colon)
            self::make_column_error($errors, "No such option “" . htmlspecialchars($name) . "”.", 1);
        return null;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$this->opt)
            return $visible == self::PREP_COMPLETION;
        else if (!$pl->contact->can_view_some_paper_option($this->opt))
            return false;
        $pl->qopts["options"] = true;
        return true;
    }
    function option_compare($a, $b) {
        return $this->opt->value_compare($a->option($this->opt->id),
                                         $b->option($this->opt->id));
    }
    function header(PaperList $pl, $is_text) {
        return $is_text ? $this->opt->name : htmlspecialchars($this->opt->name);
    }
    function completion_name() {
        return $this->opt ? $this->opt->abbr : "options";
    }
    function completion_instances(Contact $user) {
        $reg = [$this];
        foreach ($user->user_option_list() as $opt)
            if ($opt->display() >= 0)
                $reg[] = new Option_PaperColumn($opt, false);
        return $reg;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->contact->can_view_paper_option($row, $this->opt, true);
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $t = "";
        if (($ok = $pl->contact->can_view_paper_option($row, $this->opt, false))
            || ($pl->contact->allow_administer($row)
                && $pl->contact->can_view_paper_option($row, $this->opt, true))) {
            $t = $this->opt->unparse_column_html($pl, $row, $this->view == self::VIEW_ROW);
            if (!$ok && $t !== "") {
                if ($this->view == Column::VIEW_ROW)
                    $t = '<div class="fx5">' . $t . '</div>';
                else
                    $t = '<span class="fx5">' . $t . '</div>';
            }
        }
        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        if ($pl->contact->can_view_paper_option($row, $this->opt))
            return $this->opt->unparse_column_text($pl, $row);
        return "";
    }
}

class Formula_PaperColumn extends PaperColumn {
    public $formula;
    private $formula_function;
    public $statistics;
    private $results;
    private $any_real;
    static private $nregistered;
    function __construct($name, Formula $formula = null) {
        parent::__construct(strtolower($name), Column::VIEW_COLUMN | Column::FOLDABLE | Column::COMPLETABLE,
                            array("minimal" => true, "comparator" => "formula_compare"));
        $this->className = "pl_formula";
        $this->formula = $formula;
    }
    function make_column(Contact $user, $name, $errors) {
        $dfm = $user->conf->defined_formula_map($user);
        if ($name === "formulas")
            return array_map(function ($f) {
                return new Formula_PaperColumn("formula{$f->formulaId}", $f);
            }, $dfm);
        $starts_with_formula = str_starts_with($name, "formula");
        foreach ($dfm as $f)
            if (strcasecmp($f->name, $name) == 0 || ($starts_with_formula && $name === "formula{$f->formulaId}"))
                return new Formula_PaperColumn("formula{$f->formulaId}", $f);
        if (substr($name, 0, 4) === "edit")
            return null;
        $formula = new Formula($user, $name);
        if (!$formula->check()) {
            if ($errors && strpos($name, "(") !== false)
                self::make_column_error($errors, $formula->error_html(), 1);
            return null;
        }
        ++self::$nregistered;
        return new Formula_PaperColumn("formulax" . self::$nregistered, $formula);
    }
    function completion_name() {
        if ($this->formula && $this->formula->name) {
            if (strpos($this->formula->name, " ") !== false)
                return "\"{$this->formula->name}\"";
            else
                return $this->formula->name;
        } else
            return "(<formula>)";
    }
    function prepare(PaperList $pl, $visible) {
        if (!$this->formula && $visible === PaperColumn::PREP_COMPLETION)
            return true;
        if (!$pl->scoresOk
            || !$this->formula->check($pl->contact)
            || !($pl->search->limitName == "a"
                 ? $pl->contact->can_view_formula_as_author($this->formula)
                 : $pl->contact->can_view_formula($this->formula)))
            return false;
        $this->formula_function = $this->formula->compile_function();
        if ($visible)
            $this->formula->add_query_options($pl->qopts);
        return true;
    }
    function realize(PaperList $pl) {
        $f = clone $this;
        $f->statistics = new ScoreInfo;
        return $f;
    }
    function sort_prepare($pl, &$rows, $sorter) {
        $formulaf = $this->formula->compile_sortable_function();
        $this->formula_sorter = $sorter = "_formula_sort_info." . $this->formula->name;
        foreach ($rows as $row)
            $row->$sorter = $formulaf($row, null, $pl->contact);
    }
    function formula_compare($a, $b) {
        $sorter = $this->formula_sorter;
        $as = $a->$sorter;
        $bs = $b->$sorter;
        if ($as === null || $bs === null)
            return $as === $bs ? 0 : ($as === null ? -1 : 1);
        else
            return $as == $bs ? 0 : ($as < $bs ? -1 : 1);
    }
    function header(PaperList $pl, $is_text) {
        $x = $this->formula->column_header();
        if ($is_text)
            return $x;
        else if ($this->formula->headingTitle && $this->formula->headingTitle != $x)
            return "<span class=\"need-tooltip\" data-tooltip=\"" . htmlspecialchars($this->formula->headingTitle) . "\">" . htmlspecialchars($x) . "</span>";
        else
            return htmlspecialchars($x);
    }
    function analyze($pl, &$rows) {
        $formulaf = $this->formula_function;
        $this->results = [];
        $this->any_real = false;
        $isreal = $this->formula->result_format_is_real();
        foreach ($rows as $row) {
            $s = $this->results[$row->paperId] = $formulaf($row, null, $pl->contact);
            if ($row->conflictType > 0 && $pl->contact->allow_administer($row))
                $s = $formulaf($row, null, $pl->contact, true);
            if ($isreal && !$this->any_real && is_float($s)
                && round($s * 100) % 100 != 0)
                $this->any_real = true;
        }
        assert(!!$this->statistics);
    }
    private function unparse($s) {
        $t = $this->formula->unparse_html($s);
        if ($this->any_real && is_float($t))
            $t = sprintf("%.2f", $t);
        return $t;
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $formulaf = $this->formula_function;
        $t = $this->unparse($this->results[$row->paperId]);
        if ($row->conflictType > 0 && $pl->contact->allow_administer($row)) {
            $ss = $formulaf($row, null, $pl->contact, true);
            $tt = $this->unparse($ss);
            if ($tt !== $t) {
                $this->statistics->add($ss);
                return '<span class="fn5">' . $t . '</span><span class="fx5">' . $tt . '</span>';
            }
        }
        // XXX conflict override
        $this->statistics->add($this->results[$row->paperId]);
        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        $formulaf = $this->formula_function;
        $s = $formulaf($row, null, $pl->contact);
        return $this->formula->unparse_text($s);
    }
    function has_statistics() {
        return $this->statistics && $this->statistics->count();
    }
    function statistic($pl, $what) {
        if ($what == ScoreInfo::SUM && !$this->formula->result_format_is_real())
            return "";
        return $this->formula->unparse_html($this->statistics->statistic($what));
    }
}

class TagReportPaperColumn extends PaperColumn {
    private $tag;
    private $viewtype;
    function __construct($tag) {
        parent::__construct($tag ? "tagrep:$tag" : null, Column::VIEW_ROW | Column::FOLDABLE);
        $this->className = "pl_tagrep";
        $this->tag = $tag;
    }
    function make_column(Contact $user, $name, $errors) {
        if (!$user->can_view_most_tags())
            return null;
        $tagset = $user->conf->tags();
        if (str_starts_with($name, "tagrep:"))
            $tag = substr($name, 7);
        else if (str_starts_with($name, "tagreport:"))
            $tag = substr($name, 10);
        else if ($name === "tagreports") {
            $errors && ($errors->allow_empty = true);
            return array_map(function ($t) { return new TagReportPaperColumn($t->tag); },
                             $tagset->filter_by(function ($t) { return $t->vote || $t->approval || $t->rank; }));
        } else
            return null;
        $t = $tagset->check($tag);
        if ($t && ($t->vote || $t->approval || $t->rank))
            return new TagReportPaperColumn($tag);
        return null;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->contact->can_view_any_peruser_tags($this->tag))
            return false;
        if ($visible)
            $pl->qopts["tags"] = 1;
        $dt = $pl->conf->tags()->check($this->tag);
        if (!$dt || $dt->rank || (!$dt->vote && !$dt->approval))
            $this->viewtype = 0;
        else
            $this->viewtype = $dt->approval ? 1 : 2;
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "#~" . $this->tag . " reports";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->contact->can_view_peruser_tags($row, $this->tag, true);
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
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
        $str = '<span class="nb">' . join(',</span> <span class="nb">', $a) . '</span>';
        return $pl->maybeConflict($row, $str, $row->conflictType <= 0 || $pl->contact->can_view_peruser_tags($row, $this->tag, false));
    }
}

class TimestampPaperColumn extends PaperColumn {
    function __construct() {
        parent::__construct("timestamp", Column::VIEW_COLUMN | Column::COMPLETABLE,
                            array("comparator" => "update_time_compare"));
    }
    function update_time_compare($a, $b) {
        $at = max($a->timeFinalSubmitted, $a->timeSubmitted, 0);
        $bt = max($b->timeFinalSubmitted, $b->timeSubmitted, 0);
        return $at > $bt ? -1 : ($at == $bt ? 0 : 1);
    }
    function header(PaperList $pl, $is_text) {
        return "Timestamp";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return max($row->timeFinalSubmitted, $row->timeSubmitted) <= 0;
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        if (($t = max($row->timeFinalSubmitted, $row->timeSubmitted, 0)) > 0)
            return $row->conf->unparse_time_full($t);
        return "";
    }
}

class NumericOrderPaperColumn extends PaperColumn {
    private $order;
    function __construct($order) {
        parent::__construct("numericorder", Column::VIEW_NONE,
                            array("comparator" => "numeric_order_compare"));
        $this->order = $order;
    }
    function numeric_order_compare($a, $b) {
        return +get($this->order, $a->paperId) - +get($this->order, $b->paperId);
    }
}

class LeadPaperColumn extends PaperColumn {
    function __construct() {
        parent::__construct("lead", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->contact->can_view_lead(null, true);
    }
    function header(PaperList $pl, $is_text) {
        return "Discussion lead";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$row->leadContactId
            || !$pl->contact->can_view_lead($row, true);
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $visible = $pl->contact->can_view_lead($row, null);
        return $pl->_contentPC($row, $row->leadContactId, $visible);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $visible = $pl->contact->can_view_lead($row, null);
        return $pl->_textPC($row, $row->leadContactId, $visible);
    }
}

class ShepherdPaperColumn extends PaperColumn {
    function __construct() {
        parent::__construct("shepherd", Column::VIEW_ROW | Column::FOLDABLE | Column::COMPLETABLE);
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->contact->isPC
            || ($pl->conf->has_any_accepts() && $pl->conf->timeAuthorViewDecision());
    }
    function header(PaperList $pl, $is_text) {
        return "Shepherd";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$row->shepherdContactId
            || !$pl->contact->can_view_shepherd($row, true);
        // XXX external reviewer can view shepherd even if external reviewer
        // cannot view reviewer identities? WHO GIVES A SHIT
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        $visible = $pl->contact->can_view_shepherd($row, null);
        return $pl->_contentPC($row, $row->shepherdContactId, $visible);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $visible = $pl->contact->can_view_shepherd($row, null);
        return $pl->_textPC($row, $row->shepherdContactId, $visible);
    }
}

class FoldAllPaperColumn extends PaperColumn {
    function __construct() {
        parent::__construct("foldall", Column::VIEW_NONE);
    }
    function prepare(PaperList $pl, $visible) {
        $pl->qopts["foldall"] = true;
        return true;
    }
}

class PageCount_PaperColumn extends PaperColumn {
    function __construct() {
        parent::__construct("pagecount", Column::VIEW_COLUMN | Column::FOLDABLE | Column::COMPLETABLE,
                            ["className" => "plr", "minimal" => true, "comparator" => "page_count_compare"]);
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->contact->can_view_some_pdf();
    }
    function page_count(Contact $user, PaperInfo $row) {
        if (!$user->can_view_pdf($row))
            return null;
        $dtype = $row->finalPaperStorageId <= 0 ? DTYPE_SUBMISSION : DTYPE_FINAL;
        $doc = $row->document($dtype);
        return $doc ? $doc->npages() : null;
    }
    function sort_prepare($pl, &$rows, $sorter) {
        foreach ($rows as $row)
            $row->_page_count_sort_info = $this->page_count($pl->contact, $row);
    }
    function page_count_compare($a, $b) {
        $ac = $a->_page_count_sort_info;
        $bc = $b->_page_count_sort_info;
        if ($ac === null || $bc === null)
            return $ac === $bc ? 0 : ($ac === null ? -1 : 1);
        else
            return $ac == $bc ? 0 : ($ac < $bc ? -1 : 1);
    }
    function header(PaperList $pl, $is_text) {
        return "Page count";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->contact->can_view_pdf($row);
    }
    function content(PaperList $pl, PaperInfo $row, $rowidx) {
        return (string) $this->page_count($pl->contact, $row);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return (string) $this->page_count($pl->contact, $row);
    }
}

function initialize_paper_columns() {
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
    PaperColumn::register(new PreferencePaperColumn("editpref", true));
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
    PaperColumn::register(new PageCount_PaperColumn);
    PaperColumn::register_factory("tag:", new TagPaperColumn(null, null, false));
    PaperColumn::register_factory("tagval:", new TagPaperColumn(null, null, true));
    PaperColumn::register_factory("opt:", new Option_PaperColumn(null));
    PaperColumn::register_factory("#", new TagPaperColumn(null, null, null));
    PaperColumn::register_factory("pref:", new PreferencePaperColumn(null, false));
    PaperColumn::register_factory("tagrep:", new TagReportPaperColumn(null));
    PaperColumn::register_factory("tagreport:", new TagReportPaperColumn(null));
    PaperColumn::register_factory("tagreports", new TagReportPaperColumn(null));
    PaperColumn::register_factory("", new Formula_PaperColumn("", null));
    PaperColumn::register_factory("g", new FormulaGraph_PaperColumn("", null));
    PaperColumn::register_factory("", new Option_PaperColumn(null));
    PaperColumn::register_factory("", new Score_PaperColumn(null));
}

initialize_paper_columns();
