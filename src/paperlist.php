<?php
// paperlist.php -- HotCRP helper class for producing paper lists
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperListRenderState {
    public $row_folded = null;
    public $has_openau = false;
    public $has_anonau = false;
    public $colorindex = 0;
    public $hascolors = false;
    public $skipcallout;
    public $ncol;
    public $titlecol;
    public $last_trclass = "";
    public $headingstart = array(0);
    function __construct($ncol, $titlecol, $skipcallout) {
        $this->ncol = $ncol;
        $this->titlecol = $titlecol;
        $this->skipcallout = $skipcallout;
    }
}

class PaperListReviewAnalysis {
    public $needsSubmit = false;
    public $round = "";
    private $row = null;
    function __construct($row, Conf $conf) {
        if ($row->reviewId) {
            $this->row = $row;
            $this->needsSubmit = !get($row, "reviewSubmitted");
            if ($row->reviewRound)
                $this->round = htmlspecialchars($conf->round_name($row->reviewRound));
        }
    }
    function icon_html($includeLink) {
        if (($title = get(ReviewForm::$revtype_names, $this->row->reviewType)))
            $title .= " review";
        else
            $title = "Review";
        if ($this->needsSubmit)
            $title .= " (" . strtolower($this->description_html()) . ")";
        $t = review_type_icon($this->row->reviewType, $this->needsSubmit, $title);
        if ($includeLink)
            $t = $this->wrap_link($t);
        if ($this->round)
            $t .= '<span class="revround" title="Review round">&nbsp;' . $this->round . "</span>";
        return $t;
    }
    function icon_text() {
        $x = "";
        if ($this->row->reviewType)
            $x = get_s(ReviewForm::$revtype_names, $this->row->reviewType);
        if ($x !== "" && $this->round)
            $x .= ":" . $this->round;
        return $x;
    }
    function description_html() {
        if (!$this->row)
            return "";
        else if (!$this->needsSubmit)
            return "Complete";
        else if ($this->row->reviewType == REVIEW_SECONDARY
                 && $this->row->reviewNeedsSubmit <= 0)
            return "Delegated";
        else if ($this->row->reviewType == REVIEW_EXTERNAL
                 && $this->row->timeApprovalRequested)
            return "Awaiting&nbsp;approval";
        else if ($this->row->reviewModified > 1)
            return "In&nbsp;progress";
        else if ($this->row->reviewModified > 0)
            return "Accepted";
        else
            return "Not&nbsp;started";
    }
    function status_html() {
        $t = $this->description_html();
        if ($this->needsSubmit && $t !== "Delegated")
            $t = "<strong class=\"overdue\">$t</strong>";
        return $this->needsSubmit ? $t : $this->wrap_link($t);
    }
    function wrap_link($t) {
        if (!$this->row)
            return $t;
        if ($this->needsSubmit)
            $href = hoturl("review", "r=" . unparseReviewOrdinal($this->row));
        else
            $href = hoturl("paper", "p=" . $this->row->paperId . "#r" . unparseReviewOrdinal($this->row));
        return '<a href="' . $href . '">' . $t . '</a>';
    }
}

class PaperList {
    // creator can set to change behavior
    public $papersel = null;
    public $display;

    // columns access
    public $conf;
    public $contact;
    public $columns = [];
    public $sorters = [];
    private $_columns_by_name = [];
    public $scoresOk = false;
    public $search;
    public $tagger;
    public $check_format;
    private $_reviewer = null;
    private $_xreviewer = false;
    public $tbody_attr;
    public $row_attr;
    public $review_list;
    public $table_type;
    public $need_render;
    public $has_editable_tags = false;

    private $sortable;
    private $foldable;
    private $_unfold_all = false;
    private $_paper_link_page;
    private $_paper_link_mode;
    private $_allow_duplicates = false;
    private $_view_columns = false;
    private $_view_compact_columns = false;
    private $_view_statistics = false;
    private $_view_row_numbers = false;
    private $_view_fields = [];
    private $atab;
    private $qreq;

    private $_table_id;
    private $_table_class;
    private $_row_id_pattern;
    private $_selection;
    private $_only_selected;

    public $qopts; // set by PaperColumn::prepare
    private $_header_script = "";
    private $_header_script_map = [];
    private $default_sort_column;

    // collected during render and exported to caller
    public $count;
    public $ids;
    public $any;
    private $_has;
    private $_any_option_checks;
    public $error_html = array();

    static public $include_stash = true;

    function __construct($search, $args = array(), $qreq = null) {
        $this->search = $search;
        $this->conf = $this->search->conf;
        $this->contact = $this->search->contact;
        if (!$qreq || !($qreq instanceof Qrequest))
            $qreq = new Qrequest("GET", $qreq);
        $this->qreq = $qreq;

        $this->sortable = isset($args["sort"]) && $args["sort"];
        if ($this->sortable && is_string($args["sort"]))
            $this->sorters[] = ListSorter::parse_sorter($args["sort"]);
        else if ($this->sortable && $qreq->sort)
            $this->sorters[] = ListSorter::parse_sorter($qreq->sort);
        else
            $this->sorters[] = ListSorter::parse_sorter("");

        $this->foldable = $this->sortable || !!get($args, "foldable")
            || $this->contact->privChair /* “Override conflicts” fold */;

        $this->_paper_link_page = "";
        if ($qreq->linkto === "paper" || $qreq->linkto === "review" || $qreq->linkto === "assign")
            $this->_paper_link_page = $qreq->linkto;
        else if ($qreq->linkto === "paperedit") {
            $this->_paper_link_page = "paper";
            $this->_paper_link_mode = "edit";
        }

        if (is_string(get($args, "display")))
            $this->display = " " . $args["display"] . " ";
        else {
            $svar = get($args, "foldtype", "pl") . "display";
            $this->display = $this->conf->session($svar, "");
        }
        if (isset($args["reviewer"]) && ($r = $args["reviewer"])) {
            if (!is_object($r)) {
                error_log(caller_landmark() . ": XXX warning: 'reviewer' not an object"); // BACKWARD COMPAT
                $r = $this->conf->user_by_id($r);
            }
            $this->_reviewer = $r;
        }
        $this->atab = $qreq->atab;

        $this->tagger = new Tagger($this->contact);
        $this->scoresOk = $this->contact->privChair
            || $this->contact->is_reviewer()
            || $this->conf->timeAuthorViewReviews();

        $this->qopts = ["scores" => [], "options" => true];
        if ($this->search->complexSearch($this->qopts))
            $this->qopts["paperId"] = $this->search->paperList();
        // NB that actually processed the search, setting PaperSearch::viewmap

        foreach ($this->search->viewmap ? : [] as $k => $v)
            $this->set_view($k, $v);
        if ($this->conf->submission_blindness() != Conf::BLIND_OPTIONAL
            && get($this->_view_fields, "au")
            && get($this->_view_fields, "anonau") === null)
            $this->_view_fields["anonau"] = true;
    }

    function set_table_id_class($table_id, $table_class, $row_id_pattern = null) {
        $this->_table_id = $table_id;
        $this->_table_class = $table_class;
        $this->_row_id_pattern = $row_id_pattern;
    }

    function set_view($k, $v) {
        if (in_array($k, ["compact", "cc", "compactcolumn", "ccol", "compactcolumns"]))
            $this->_view_compact_columns = $this->_view_columns = $v;
        else if (in_array($k, ["columns", "column", "col"]))
            $this->_view_columns = $v;
        else if (in_array($k, ["statistics", "stat", "stats", "totals"]))
            $this->_view_statistics = $v;
        else if (in_array($k, ["rownum", "rownumbers"]))
            $this->_view_row_numbers = $v;
        else if (in_array($k, ["authors", "aufull", "anonau"]) && $v
                 && !isset($this->_view_fields["au"]))
            $this->_view_fields[$k] = $this->_view_fields["au"] = $v;
        else
            $this->_view_fields[$k] = $v;
    }

    function unfold_all() {
        $this->_unfold_all = true;
    }

    function set_selection(SearchSelection $ssel, $only_selected = false) {
        $this->_selection = $ssel;
        $this->_only_selected = $only_selected;
    }

    function is_selected($paperId, $default = false) {
        return $this->_selection ? $this->_selection->is_selected($paperId) : $default;
    }

    function has($key) {
        return isset($this->_has[$key]);
    }

    function mark_has($key) {
        $this->_has[$key] = true;
    }


    private function find_columns($name, $errors = null) {
        $col = PaperColumn::lookup($this->contact, $name, $errors);
        if (!is_array($col))
            $col = $col ? [$col] : [];
        $ocol = [];
        foreach ($col as $colx)
            if (isset($this->_columns_by_name[$colx->name]))
                $ocol[] = $this->_columns_by_name[$colx->name];
            else
                $ocol[] = $this->_columns_by_name[$colx->name] = $colx;
        return $ocol;
    }
    private function find_column($name, $errors = null) {
        if (array_key_exists($name, $this->_columns_by_name))
            return $this->_columns_by_name[$name];
        else
            return get($this->find_columns($name, $errors), 0);
    }

    private function _sort($rows, $duplicates) {
        global $magic_sort_info;      /* ugh, PHP constraints */

        $code = "global \$magic_sort_info; \$x = 0;\n";
        if (($thenmap = $this->search->thenmap)) {
            foreach ($rows as $row)
                $row->_then_sort_info = $thenmap[$row->paperId];
            $code .= "if ((\$x = \$a->_then_sort_info - \$b->_then_sort_info)) return \$x < 0 ? -1 : 1;\n";
        }

        $magic_sort_info = $this->sorters;
        foreach ($this->sorters as $i => $s) {
            $s->field->sort_prepare($this, $rows, $s);
            $rev = ($s->reverse ? "-" : "");
            if ($s->thenmap === null)
                $code .= "if (!\$x)";
            else
                $code .= "if (!\$x && \$a->_then_sort_info == {$s->thenmap})";
            $code .= " { \$s = \$magic_sort_info[$i]; "
                . "\$x = $rev\$s->field->compare(\$a, \$b, \$s); }\n";
        }

        $code .= "if (!\$x) \$x = \$a->paperId - \$b->paperId;\n";

        if ($duplicates)
            foreach ($rows as $row)
                if (isset($row->reviewId)) {
                    $code .= "if (!\$x) \$x = PaperList::review_row_compare(\$a, \$b);\n";
                    break;
                }

        $code .= "return \$x < 0 ? -1 : (\$x == 0 ? 0 : 1);\n";

        usort($rows, create_function("\$a, \$b", $code));
        unset($magic_sort_info);
        return $rows;
    }

    function sortdef($always = false) {
        if (!empty($this->sorters)
            && $this->sorters[0]->type
            && $this->sorters[0]->thenmap === null
            && ($always || (string) $this->qreq->sort != "")
            && ($this->sorters[0]->type != "id" || $this->sorters[0]->reverse)) {
            $x = ($this->sorters[0]->reverse ? "r" : "");
            if (($fdef = $this->find_column($this->sorters[0]->type))
                && isset($fdef->score))
                $x .= $this->sorters[0]->score;
            return ($fdef ? $fdef->name : $this->sorters[0]->type)
                . ($x ? ",$x" : "");
        } else
            return "";
    }

    function _sortReviewOrdinal(&$rows) {
        for ($i = 0; $i < count($rows); $i++) {
            for ($j = $i + 1; $j < count($rows) && $rows[$i]->paperId == $rows[$j]->paperId; $j++)
                /* do nothing */;
            // insertion sort
            for ($k = $i + 1; $k < $j; $k++) {
                $v = $rows[$k];
                for ($l = $k - 1; $l >= $i; $l--) {
                    $w = $rows[$l];
                    if ($v->reviewOrdinal && $w->reviewOrdinal)
                        $cmp = $v->reviewOrdinal - $w->reviewOrdinal;
                    else if ($v->reviewOrdinal || $w->reviewOrdinal)
                        $cmp = $v->reviewOrdinal ? -1 : 1;
                    else
                        $cmp = $v->reviewId - $w->reviewId;
                    if ($cmp >= 0)
                        break;
                    $rows[$l + 1] = $rows[$l];
                }
                $rows[$l + 1] = $v;
            }
        }
    }


    function _contentDownload($row) {
        if ($row->size == 0 || !$this->contact->can_view_pdf($row))
            return "";
        $dtype = $row->finalPaperStorageId <= 0 ? DTYPE_SUBMISSION : DTYPE_FINAL;
        if ($dtype == DTYPE_FINAL)
            $this->_has["final"] = true;
        $this->_has["paper"] = true;
        return "&nbsp;" . $row->document($dtype)->link_html("", DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE | DocumentInfo::L_FINALTITLE);
    }

    function _paperLink($row) {
        $pt = $this->_paper_link_page ? : "paper";
        if ($pt === "finishreview")
            $pt = $row->reviewNeedsSubmit ? "review" : "paper";
        if ($pt === "review" && !isset($row->reviewId))
            $pt = "paper";
        $pl = "p=" . $row->paperId;
        if ($pt === "paper" && $this->_paper_link_mode)
            $pl .= "&amp;m=" . $this->_paper_link_mode;
        else if ($pt === "review" && isset($row->reviewId)) {
            $pl .= "&amp;r=" . unparseReviewOrdinal($row);
            if ($row->reviewSubmitted > 0)
                $pl .= "&amp;m=r";
        } else if ($pt === "paper" && isset($row->reviewId)
                   && $row->reviewSubmitted > 0
                   && $row->reviewContactId != $this->contact->contactId)
            $pl .= "#r" . unparseReviewOrdinal($row);
        return hoturl($pt, $pl);
    }

    // content downloaders
    static function wrapChairConflict($text) {
        return '<span class="fn5"><em>Hidden for conflict</em> <span class="barsep">·</span> <a href="#">Override conflicts</a></span><span class="fx5">' . $text . "</span>";
    }

    function reviewer_cid() {
        return $this->_reviewer ? $this->_reviewer->contactId : $this->contact->contactId;
    }

    function reviewer_contact() {
        return $this->_reviewer ? : $this->contact;
    }

    function maybeConflict($row, $text, $visible) {
        if ($visible)
            return $text;
        else if ($this->contact->allow_administer($row))
            return self::wrapChairConflict($text);
        else
            return "";
    }

    function maybe_conflict_nooverride($row, $text, $visible) {
        if ($visible)
            return $text;
        else if ($this->contact->allow_administer($row))
            return '<span class="fx5">' . $text . '</span>';
        else
            return "";
    }

    function _contentPC($row, $contactId, $visible) {
        $pcm = $this->conf->pc_members();
        if (isset($pcm[$contactId]))
            return $this->maybeConflict($row, $this->contact->reviewer_html_for($pcm[$contactId]), $visible);
        return "";
    }

    function _textPC($row, $contactId, $visible) {
        $pcm = $this->conf->pc_members();
        if (isset($pcm[$contactId]))
            return $visible ? $this->contact->reviewer_text_for($pcm[$contactId]) : "";
        return "";
    }

    function prepare_xreviewer($rows) {
        // PaperSearch is responsible for access control checking use of
        // `reviewerContact`, but we are careful anyway.
        if (($xreviewer = $this->search->reviewer())
            && $xreviewer->contactId != $this->contact->contactId
            && !empty($rows)
            && !$this->_xreviewer) {
            $by_pid = array();
            foreach ($rows as $row)
                $by_pid[$row->paperId] = $row;
            $result = $this->conf->qe_raw("select Paper.paperId, reviewType, reviewId, reviewModified, reviewSubmitted, timeApprovalRequested, reviewNeedsSubmit, reviewOrdinal, reviewBlind, PaperReview.contactId reviewContactId, requestedBy, reviewToken, reviewRound, conflictType from Paper left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=" . $xreviewer->contactId . ") left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=" . $xreviewer->contactId . ") where Paper.paperId in (" . join(",", array_keys($by_pid)) . ") and (PaperReview.contactId is not null or PaperConflict.contactId is not null)");
            while (($xrow = edb_orow($result))) {
                $prow = $by_pid[$xrow->paperId];
                if ($this->contact->allow_administer($prow)
                    || $this->contact->can_view_review_identity($prow, $xrow, true)
                    || ($this->contact->privChair
                        && $xrow->conflictType > 0
                        && !$xrow->reviewType))
                    $prow->_xreviewer = $xrow;
            }
            $this->_xreviewer = $xreviewer;
        }
        return $this->_xreviewer;
    }

    private function _footer($ncol, $extra) {
        if ($this->count == 0)
            return "";

        $revpref = $this->table_type == "editpref";
        $lllgroups = SearchAction::list_all_actions($this->contact, $this->qreq, $this);

        // Upload preferences (review preferences only)
        if ($revpref) {
            $lllgroups[] = [100, "uploadpref", "Upload", "<b>&nbsp;preference file:</b> &nbsp;"
                . "<input class=\"want-focus\" type='file' name='uploadedFile' accept='text/plain' size='20' tabindex='6' onfocus='autosub(\"uploadpref\",this)' />&nbsp; "
                . Ht::submit("fn", "Go", ["value" => "uploadpref", "tabindex" => 6, "onclick" => "return plist_submit.call(this)", "data-plist-submit-all" => 1])];
        }

        // Set preferences (review preferences only)
        if ($revpref) {
            $lllgroups[] = [200, "setpref", "Set preferences", "<b>:</b> &nbsp;"
                . Ht::entry("pref", "", array("class" => "want-focus", "size" => 4, "tabindex" => 6, "onfocus" => 'autosub("setpref",this)'))
                . " &nbsp;" . Ht::submit("fn", "Go", ["value" => "setpref", "tabindex" => 6, "onclick" => "return plist_submit.call(this)"])];
        }

        usort($lllgroups, function ($a, $b) { return $a[0] - $b[0]; });
        $whichlll = 1;
        foreach ($lllgroups as $i => $lllg)
            if ($this->qreq->fn == $lllg[1] || $this->atab == $lllg[1])
                $whichlll = $i + 1;

        // Linelinks container
        $foot = "  <tr class=\"pl_footrow\">";
        if (!$this->_view_columns) {
            $foot .= '<td class="pl_footselector">'
                . Ht::img("_.gif", "^^", "placthook") . "</td>";
            --$ncol;
        }
        $foot .= '<td id="plact" class="pl_footer linelinks' . $whichlll . '" colspan="' . $ncol . '">';

        $foot .= "<table><tbody><tr>\n"
            . '    <td class="pl_footer_desc"><b>Select papers</b> (or <a href="' . selfHref(["selectall" => 1]) . '#plact" onclick="return papersel(true)">select all ' . $this->count . "</a>), then&nbsp;</td>\n"
            . "   </tr></tbody></table>";
        foreach ($lllgroups as $i => $lllg) {
            $x = $i + 1;
            $foot .= "<table><tbody><tr>\n"
                . "    <td class=\"pl_footer_desc lll$x\"><a class=\"lla$x\" href=\"" . selfHref(["atab" => $lllg[1]]) . "#plact\" onclick=\"return crpfocus('plact',this)\">" . $lllg[2] . "</a></td>\n";
            for ($j = 3; $j < count($lllg); ++$j) {
                $cell = is_array($lllg[$j]) ? $lllg[$j] : ["content" => $lllg[$j]];
                $class = isset($cell["class"]) ? "lld$x " . $cell["class"] : "lld$x";
                $foot .= "    <td class=\"$class\"";
                if (isset($cell["id"]))
                    $foot .= " id=\"" . $cell["id"] . "\"";
                $foot .= ">" . $cell["content"] . "</td>\n";
            }
            if ($i < count($lllgroups) - 1)
                $foot .= "    <td>&nbsp;<span class='barsep'>·</span>&nbsp;</td>\n";
            $foot .= "   </tr></tbody></table>";
        }
        $foot .= $extra . "<hr class=\"c\" /></td>\n  </tr>\n";
        return $foot;
    }

    static function _listDescription($listname) {
        switch ($listname) {
          case "reviewAssignment":
            return "Review assignments";
          case "conflict":
            return "Potential conflicts";
          case "editpref":
            return "Review preferences";
          case "reviewers":
          case "reviewersSel":
            return "Proposed assignments";
          default:
            return null;
        }
    }

    private function _default_linkto($page) {
        if (!$this->_paper_link_page)
            $this->_paper_link_page = $page;
        if ($page === "review" || $page === "finishreview")
            $this->_allow_duplicates = true;
    }

    private function _list_columns($listname) {
        switch ($listname) {
        case "a":
            return "id title revstat statusfull authors collab abstract topics reviewers shepherd scores formulas";
        case "authorHome":
            return "id title statusfull";
        case "s":
        case "acc":
            return "sel id title revtype revstat status authors collab abstract topics pcconf allpref reviewers tags tagreports lead shepherd scores formulas";
        case "all":
        case "act":
            return "sel id title revtype statusfull authors collab abstract topics pcconf allpref reviewers tags tagreports lead shepherd scores formulas";
        case "reviewerHome":
            $this->_default_linkto("finishreview");
            return "id title revtype status";
        case "r":
        case "lead":
        case "manager":
            if ($listname == "r")
                $this->_default_linkto("finishreview");
            return "sel id title revtype revstat status authors collab abstract topics pcconf allpref reviewers tags tagreports lead shepherd scores formulas";
        case "rout":
            $this->_default_linkto("review");
            return "sel id title revtype revstat status authors collab abstract topics pcconf allpref reviewers tags tagreports lead shepherd scores formulas";
        case "req":
            $this->_default_linkto("review");
            return "sel id title revtype revstat status authors collab abstract topics pcconf allpref reviewers tags tagreports lead shepherd scores formulas";
        case "reqrevs":
            $this->_default_linkto("review");
            return "id title revdelegation revsubmitted revstat status authors collab abstract topics pcconf allpref reviewers tags tagreports lead shepherd scores formulas";
        case "reviewAssignment":
            $this->_default_linkto("assign");
            return "id title revpref topicscore desirability assrev authors authorsmatch collabmatch topics allrevtopicpref reviewers tags scores formulas";
        case "conflict":
            $this->_default_linkto("assign");
            return "selconf id title abstract authors authorsmatch collabmatch tags foldall";
        case "editpref":
            $this->_default_linkto("paper");
            return "sel id title topicscore revtype editpref authors abstract topics";
        case "reviewers":
            $this->_default_linkto("assign");
            return "selon id title status";
        case "reviewersSel":
            $this->_default_linkto("assign");
            return "sel id title status reviewers";
        default:
            return null;
        }
    }

    function _canonicalize_columns($fields) {
        if (is_string($fields))
            $fields = explode(" ", $fields);
        $field_list = array();
        foreach ($fields as $fid) {
            if (($fid == "scores" || $fid == "formulas") && !$this->scoresOk)
                continue;
            if ($fid == "scores")
                $this->scoresOk = "present";
            foreach ($this->find_columns($fid) as $f)
                $field_list[] = $f;
        }
        if ($this->qreq->selectall > 0 && $field_list[0]->name == "sel")
            $field_list[0] = $this->find_column("selon");
        return $field_list;
    }

    static function review_row_compare($a, $b) {
        if ($a->paperId != $b->paperId)
            return $a->paperId < $b->paperId ? -1 : 1;
        if (!$a->reviewOrdinal !== !$b->reviewOrdinal)
            return $a->reviewOrdinal ? -1 : 1;
        else if ($a->reviewOrdinal != $b->reviewOrdinal)
            return $a->reviewOrdinal < $b->reviewOrdinal ? -1 : 1;
        else if ($a->timeRequested != $b->timeRequested)
            return $a->timeRequested < $b->timeRequested ? -1 : 1;
        else if (isset($a->sorter) && isset($b->sorter)
                 && ($x = strcmp($a->sorter, $b->sorter)) != 0)
            return $x;
        else if ($a->reviewType != $b->reviewType)
            return $a->reviewType < $b->reviewType ? 1 : -1;
        else if ($a->reviewId != $b->reviewId)
            return $a->reviewId < $b->reviewId ? -1 : 1;
        else
            return 0;
    }


    private function _rows($field_list) {
        if (!$field_list)
            return null;

        // make query, fetch rows
        $this->qopts["scores"] = array_keys($this->qopts["scores"]);
        if (empty($this->qopts["scores"]))
            unset($this->qopts["scores"]);
        $result = $this->contact->paper_result($this->qopts);
        if (!$result)
            return null;
        $rowset = new PaperInfoSet;
        $pids = [];
        while (($row = PaperInfo::fetch($result, $this->contact)))
            if (($this->_allow_duplicates || !isset($pids[$row->paperId]))
                && (!$this->_only_selected || $this->is_selected($row->paperId))) {
                $rowset->add($row);
                $pids[$row->paperId] = true;
            }
        Dbl::free($result);

        // prepare review query (see also search > getfn == "reviewers")
        $this->review_list = array();
        if (isset($this->qopts["reviewList"]) && $rowset->all()) {
            $result = $this->conf->qe("select Paper.paperId, reviewId, reviewType,
                reviewSubmitted, reviewModified, timeApprovalRequested, reviewNeedsSubmit, reviewRound,
                reviewOrdinal, timeRequested,
                PaperReview.contactId, lastName, firstName, email
                from Paper
                join PaperReview using (paperId)
                join ContactInfo on (PaperReview.contactId=ContactInfo.contactId)
                where paperId?a", array_keys($pids));
            while (($row = edb_orow($result))) {
                Contact::set_sorter($row, $this->conf);
                $this->review_list[$row->paperId][] = $row;
            }
            foreach ($this->review_list as &$revlist)
                usort($revlist, "PaperList::review_row_compare");
            unset($revlist);
            Dbl::free($result);
        }

        // analyze rows (usually noop)
        $rows = $rowset->all();
        foreach ($field_list as $fdef)
            $fdef->analyze($this, $rows);

        // sort rows
        if (!empty($this->sorters)) {
            $review_rows = count($rows) !== count($pids);
            $rows = $this->_sort($rows, $review_rows);
            if (isset($this->qopts["allReviewScores"]))
                $this->_sortReviewOrdinal($rows);
        }

        // set `any->optID`
        if (($nopts = $this->conf->paper_opts->count_option_list())) {
            foreach ($rows as $prow) {
                foreach ($prow->options() as $o)
                    if (!$this->has("opt$o->id")
                        && $this->contact->can_view_paper_option($prow, $o->option)) {
                        $this->_has["opt$o->id"] = true;
                        --$nopts;
                    }
                if (!$nopts)
                    break;
            }
        }

        $this->ids = [];
        return $rows;
    }

    function is_folded($field) {
        $fname = $field;
        if (is_object($field) || ($field = $this->find_column($field)))
            $fname = $field->fold ? $field->name : null;
        if ($fname === "authors")
            $fname = "au";
        return $fname
            && !$this->_unfold_all
            && !$this->qreq["show$fname"]
            && (($x = get($this->_view_fields, $fname)) === false
                || ($x === null && strpos($this->display, " $fname ") === false));
    }

    private function _check_option_presence($row) {
        for ($i = 0; $i < count($this->_any_option_checks); ) {
            $opt = $this->_any_option_checks[$i];
            if ($opt->id == DTYPE_SUBMISSION)
                $got = $row->paperStorageId > 1;
            else if ($opt->id == DTYPE_FINAL)
                $got = $row->finalPaperStorageId > 1;
            else
                $got = ($ov = $row->option($opt->id)) && $ov->value > 1;
            if ($got && $this->contact->can_view_paper_option($row, $opt)) {
                $this->_has[$opt->field_key()] = true;
                array_splice($this->_any_option_checks, $i, 1);
            } else
                ++$i;
        }
    }

    private function _row_text($rstate, $row, $fieldDef) {
        if ((string) $row->abstract !== "")
            $this->_has["abstract"] = true;
        if (!empty($this->_any_option_checks))
            $this->_check_option_presence($row);
        $this->ids[] = (int) $row->paperId;

        $rowidx = count($this->ids);
        $trclass = "k" . $rstate->colorindex;
        if (get($row, "paperTags")
            && ($viewable = $row->viewable_tags($this->contact, true))
            && ($m = $row->conf->tags()->color_classes($viewable))) {
            if (TagInfo::classes_have_colors($m)) {
                $rstate->hascolors = true;
                $trclass = $m;
            } else
                $trclass .= " " . $m;
            if ($row->conflictType > 0 && !$this->contact->can_view_tags($row, false))
                $trclass .= " conflictmark";
        }
        if (($highlightclass = get($this->search->highlightmap, $row->paperId)))
            $trclass .= " {$highlightclass[0]}highlightmark";
        $rstate->colorindex = 1 - $rstate->colorindex;
        $rstate->last_trclass = $trclass;
        $this->row_attr = [];

        // main columns
        $tm = "";
        foreach ($fieldDef as $fdef) {
            if (!$fdef->viewable_column())
                continue;
            $empty = $fdef->content_empty($this, $row);
            if ($fdef->is_folded) {
                if (!$empty)
                    $fdef->has_content = true;
            } else {
                $c = $empty ? "" : $fdef->content($this, $row, $rowidx);
                if ($c !== "")
                    $fdef->has_content = true;
                $tm .= "<td class=\"pl " . $fdef->className;
                if ($fdef->fold)
                    $tm .= " fx$fdef->fold";
                $tm .= "\">" . $c . "</td>";
            }
        }

        // extension columns
        $tt = "";
        foreach ($fieldDef as $fdef) {
            if (!$fdef->viewable_row())
                continue;
            $empty = $fdef->content_empty($this, $row);
            $is_authors = $fdef->name === "authors";
            if ($fdef->is_folded && !$is_authors) {
                if (!$empty)
                    $fdef->has_content = true;
            } else {
                $c = $empty ? "" : $fdef->content($this, $row, $rowidx);
                if ($c !== "") {
                    $fdef->has_content = true;
                    if (($ch = $fdef->header($this, false))) {
                        if ($c[0] !== "<"
                            || !preg_match('/\A((?:<(?:div|p).*?>)*)([\s\S]*)\z/', $c, $cm))
                            $cm = [null, "", $c];
                        $c = $cm[1] . '<em class="plx">' . $ch . ':</em> ' . $cm[2];
                    }
                }
                $tt .= "<div class=\"" . $fdef->className;
                if ($is_authors) {
                    $tt .= " fx1";
                    if ($this->contact->can_view_authors($row, false))
                        $rstate->has_openau = true;
                    else {
                        $tt .= " fx2";
                        $rstate->has_anonau = true;
                    }
                } else if ($fdef->fold)
                    $tt .= " fx" . $fdef->fold;
                $tt .= "\">" . $c . "</div>";
            }
        }

        if (isset($row->folded) && $row->folded) {
            $trclass .= " fx3";
            $rstate->row_folded = true;
        } else if ($rstate->row_folded)
            $rstate->row_folded = false;

        $t = "  <tr";
        if ($this->_row_id_pattern)
            $t .= " id=\"" . str_replace("#", $row->paperId, $this->_row_id_pattern) . "\"";
        $t .= " class=\"pl $trclass\" data-pid=\"$row->paperId";
        foreach ($this->row_attr as $k => $v)
            $t .= "\" $k=\"" . htmlspecialchars($v);
        $t .= "\">" . $tm . "</tr>\n";

        if ($tt !== "" || $this->table_id()) {
            $t .= "  <tr class=\"plx $trclass\" data-pid=\"$row->paperId\">";
            if ($rstate->skipcallout > 0)
                $t .= "<td colspan=\"$rstate->skipcallout\"></td>";
            $t .= "<td class=\"plx\" colspan=\"" . ($rstate->ncol - $rstate->skipcallout) . "\">$tt</td></tr>\n";
        }

        return $t;
    }

    private function _row_thenval($row) {
        if ($this->search->thenmap)
            return get_i($this->search->thenmap, $row->paperId);
        else
            return 0;
    }

    private function _check_heading($thenval, $rstate, $srows, $lastheading, &$body) {
        if ($this->count != 1 && $thenval != $lastheading)
            $rstate->headingstart[] = count($body);
        while ($lastheading != $thenval) {
            ++$lastheading;
            $ginfo = get($this->search->groupmap, $lastheading);
            if ($ginfo === null || !isset($ginfo->heading)
                || strcasecmp($ginfo->heading, "none") == 0) {
                if ($this->count != 1)
                    $body[] = "  <tr class=\"plheading_blank\"><td class=\"plheading_blank\" colspan=\"$rstate->ncol\"></td></tr>\n";
            } else {
                for ($i = $this->count - 1; $i < count($srows) && $this->_row_thenval($srows[$i]) == $lastheading; ++$i)
                    /* do nothing */;
                $count = plural($i - $this->count + 1, "paper");
                // Leave off an empty "Untagged" section unless editing
                if ($count == 0 && isset($ginfo->tag) && !isset($ginfo->annoId)
                    && !$this->has_editable_tags)
                    continue;

                $x = "  <tr class=\"plheading\"";
                if (isset($ginfo->tag))
                    $x .= " data-anno-tag=\"{$ginfo->tag}\"";
                if (isset($ginfo->annoId))
                    $x .= " data-anno-id=\"{$ginfo->annoId}\" data-tags=\"{$ginfo->tag}#{$ginfo->tagIndex}\"";
                $x .= ">";
                if ($rstate->titlecol)
                    $x .= "<td class=\"plheading_spacer\" colspan=\"$rstate->titlecol\"></td>";
                $x .= "<td class=\"plheading\" colspan=\"" . ($rstate->ncol - $rstate->titlecol) . "\">";
                $x .= "<span class=\"plheading_group";
                if ($ginfo->heading !== ""
                    && ($format = $this->conf->check_format($ginfo->annoFormat, $ginfo->heading))) {
                    $x .= " need-format\" data-format=\"$format";
                    $this->need_render = true;
                }
                $x .= "\" data-title=\"" . htmlspecialchars($ginfo->heading)
                    . "\">" . htmlspecialchars($ginfo->heading)
                    . ($ginfo->heading !== "" ? " " : "")
                    . "</span><span class=\"plheading_count\">$count</span></td></tr>";
                $body[] = $x;
                $rstate->colorindex = 0;
            }
        }
        return $thenval;
    }

    private function _field_title($fdef) {
        if (!$fdef->viewable_column())
            return $fdef->header($this, false);

        $t = $fdef->header($this, false);

        $sort_url = $q = false;
        $sort_class = "pl_sort";
        if ($this->sortable && ($url = $this->search->url_site_relative_raw()))
            $sort_url = htmlspecialchars(Navigation::siteurl() . $url)
                . (strpos($url, "?") ? "&amp;" : "?") . "sort=";

        $defsortname = null;
        if (isset($fdef->is_selector) && $sort_url
            && $this->default_sort_column->name !== "id")
            $defsortname = $this->default_sort_column->name;

        $tooltip = "";
        if ($defsortname == "searchsort") {
            $tooltip = "Sort by search term order";
            $t = "#";
        }

        $s0 = get($this->sorters, 0);
        if ($s0 && $s0->thenmap === null
            && ((($fdef->name == $s0->type || $fdef->name == "edit" . $s0->type)
                 && $sort_url)
                || $defsortname == $s0->type)) {
            $tooltip = $s0->reverse ? "Forward sort" : "Reverse sort";
            $sort_class = "pl_sort_def" . ($s0->reverse ? "_rev" : "");
            $sort_url .= urlencode($s0->type . ($s0->reverse ? "" : " reverse"));
        } else if ($fdef->sort && $sort_url)
            $sort_url .= urlencode($fdef->name);
        else if ($defsortname)
            $sort_url .= urlencode($defsortname);
        else
            $sort_url = false;

        if ($sort_url && $tooltip)
            $t = '<a class="' . $sort_class . ' need-tooltip" rel="nofollow" data-tooltip="' . $tooltip . '" data-tooltip-dir="b" href="' . $sort_url . '">' . $t . '</a>';
        else if ($sort_url)
            $t = '<a class="' . $sort_class . '" rel="nofollow" href="' . $sort_url . '">' . $t . '</a>';
        return $t;
    }

    private function _analyze_folds($rstate, $fieldDef) {
        $classes = $jsmap = $jscol = array();
        $has_sel = false;
        foreach ($fieldDef as $fdef) {
            $j = ["name" => $fdef->name, "title" => $this->_field_title($fdef),
                  "priority" => $fdef->priority];
            if ($fdef->className != "pl_" . $fdef->name)
                $j["className"] = $fdef->className;
            if ($fdef->viewable_column())
                $j["column"] = true;
            if ($fdef->is_folded && $fdef->has_content)
                $j["loadable"] = true;
            if ($fdef->is_folded && $fdef->name !== "authors")
                $j["missing"] = true;
            if ($fdef->fold)
                $j["foldnum"] = $fdef->fold;
            $fdef->annotate_field_js($this, $j);
            $jscol[] = $j;
            if ($fdef->fold && $fdef->name !== "authors") {
                $classes[] = "fold$fdef->fold" . ($fdef->is_folded ? "c" : "o");
                $jsmap[] = "\"$fdef->name\":$fdef->fold";
            }
            if (isset($fdef->is_selector))
                $has_sel = true;
        }
        // authorship requires special handling
        if ($rstate->has_openau || $rstate->has_anonau) {
            $classes[] = "fold1" . ($this->is_folded("authors") ? "c" : "o");
            $jsmap[] = "\"au\":1,\"aufull\":4";
        }
        if ($rstate->has_anonau) {
            $classes[] = "fold2" . ($this->is_folded("anonau") ? "c" : "o");
            $jsmap[] = "\"anonau\":2";
        }
        // total folding, row number folding
        if ($rstate->row_folded)
            $classes[] = "fold3c";
        if ($has_sel) {
            $jsmap[] = "\"rownum\":6";
            $classes[] = "fold6" . ($this->is_folded("rownum") ? "c" : "o");
        }
        if ($this->contact->privChair) {
            $jsmap[] = "\"force\":5";
            $classes[] = "fold5" . ($this->qreq->forceShow ? "o" : "c");
        }
        if ($this->_table_id) {
            if (!empty($jsmap))
                Ht::stash_script("foldmap.pl={" . join(",", $jsmap) . "};");
            $args = ["q" => join(" ", $this->ids), "t" => $this->search->limitName];
            if ($this->_reviewer && $this->_reviewer->email !== $this->contact->email)
                $args["reviewer"] = $this->_reviewer->email;
            Ht::stash_script("plinfo.needload(" . json_encode($args) . ");"
                             . "plinfo.set_fields(" . json_encode($jscol) . ");");
        }
        return $classes;
    }

    private function _make_title_header_extra($rstate, $fieldDef, $show_links) {
        $titleextra = "";
        if (isset($rstate->row_folded))
            $titleextra .= '<span class="sep"></span><a class="fn3" href="#" onclick="return fold(\'pl\',0,3)">Show all papers</a><a class="fx3" href="#" onclick="return fold(\'pl\',1,3)">Hide unlikely conflicts</a>';
        if (($rstate->has_openau || $rstate->has_anonau) && $show_links) {
            $titleextra .= "<span class='sep'></span>";
            if ($this->conf->submission_blindness() == Conf::BLIND_NEVER)
                $titleextra .= '<a class="fn1" href="#" onclick="return plinfo(\'au\',false)">Show authors</a><a class="fx1" href="#" onclick="return plinfo(\'au\',true)">Hide authors</a>';
            else if ($this->contact->privChair && $rstate->has_anonau && !$rstate->has_openau)
                $titleextra .= '<a class="fn1 fn2" href="#" onclick="return plinfo(\'au\',false)||plinfo(\'anonau\',false)">Show authors</a><a class="fx1 fx2" href="#" onclick="return plinfo(\'au\',true)||plinfo(\'anonau\',true)">Hide authors</a>';
            else if ($this->contact->privChair && $rstate->has_anonau)
                $titleextra .= '<a class="fn1" href="#" onclick="return plinfo(\'au\',false)||plinfo(\'anonau\',true)">Show non-anonymous authors</a><a class="fx1 fn2" href="#" onclick="return plinfo(\'anonau\',false)">Show all authors</a><a class="fx1 fx2" href="#" onclick="return plinfo(\'au\',true)||plinfo(\'anonau\',true)">Hide authors</a>';
            else
                $titleextra .= '<a class="fn1" href="#" onclick="return plinfo(\'au\',false)">Show non-anonymous authors</a><a class="fx1" href="#" onclick="return plinfo(\'au\',true)">Hide authors</a>';
        }
        if ($show_links)
            foreach ($fieldDef as $fdef)
                if ($fdef->name == "tags" && $fdef->fold && $fdef->has_content) {
                    $titleextra .= "<span class='sep'></span>";
                    $titleextra .= "<a class='fn$fdef->fold' href='#' onclick='return plinfo(\"tags\",0)'>Show tags</a><a class='fx$fdef->fold' href='#' onclick='return plinfo(\"tags\",1)'>Hide tags</a><span id='tagsloadformresult'></span>";
                }
        return $titleextra ? "<span class='pl_titleextra'>$titleextra</span>" : "";
    }

    private function _column_split($rstate, $colhead, &$body) {
        if (count($rstate->headingstart) <= 1)
            return false;
        $rstate->headingstart[] = count($body);
        $rstate->split_ncol = count($rstate->headingstart) - 1;

        $rownum_marker = "<span class=\"pl_rownum fx6\">";
        $rownum_len = strlen($rownum_marker);
        $nbody = array("<tr>");
        $tbody_class = "pltable" . ($rstate->hascolors ? " pltable_colored" : "");
        for ($i = 1; $i < count($rstate->headingstart); ++$i) {
            $nbody[] = '<td class="plsplit_col top" width="' . (100 / $rstate->split_ncol) . '%"><div class="plsplit_col"><table width="100%">';
            $nbody[] = $colhead . "  <tbody class=\"$tbody_class\">\n";
            $number = 1;
            for ($j = $rstate->headingstart[$i - 1]; $j < $rstate->headingstart[$i]; ++$j) {
                $x = $body[$j];
                if (($pos = strpos($x, $rownum_marker)) !== false) {
                    $pos += strlen($rownum_marker);
                    $x = substr($x, 0, $pos) . preg_replace('/\A\d+/', $number, substr($x, $pos));
                    ++$number;
                } else if (strpos($x, "<tr class=\"plheading_blank") !== false)
                    $x = "";
                $nbody[] = $x;
            }
            $nbody[] = "  </tbody>\n</table></div></td>\n";
        }
        $nbody[] = "</tr>";

        $body = $nbody;
        $rstate->last_trclass = "plsplit_col";
        return true;
    }

    private function _prepare() {
        $this->_has = [];
        $this->count = 0;
        $this->table_type = false;
        $this->need_render = false;
        return true;
    }

    private function _view_columns($field_list) {
        // add explicitly requested columns
        $specials = array_flip(array("cc", "compact", "compactcolumn", "compactcolumns",
                                     "column", "col", "columns", "sort", "rownum", "rownumbers",
                                     "stat", "stats", "statistics", "totals",
                                     "au", "anonau", "aufull"));
        $viewmap_add = [];
        foreach ($this->_view_fields as $k => $v) {
            if (in_array($k, ["au", "anonau", "aufull"]))
                continue;
            $err = new ColumnErrors;
            $f = $this->find_columns($k, $err);
            if (!$f) {
                if ($v && !empty($err->error_html)) {
                    $err->error_html[0] = "Can’t show “" . htmlspecialchars($k) . "”: " . $err->error_html[0];
                    $this->error_html = array_merge($this->error_html, $err->error_html);
                } else if ($v && !$err->allow_empty)
                    $this->error_html[] = "No such column “" . htmlspecialchars($k) . "”.";
                continue;
            }
            foreach ($f as $fx) {
                $viewmap_add[$fx->name] = $v;
                foreach ($field_list as $ff)
                    if ($fx && $fx->name == $ff->name)
                        $fx = null;
                if ($fx)
                    $field_list[] = $fx;
            }
        }
        foreach ($viewmap_add as $k => $v)
            $this->_view_fields[$k] = $v;
        foreach ($field_list as $fi => &$f)
            if (get($this->_view_fields, $f->name) === "edit")
                $f = $f->make_editable();
        unset($f);

        // remove deselected columns;
        // in compactcolumns view, remove non-minimal columns
        $minimal = $this->_view_compact_columns;
        $field_list2 = array();
        foreach ($field_list as $fdef)
            if (($v = get($this->_view_fields, $fdef->name)) !== false
                && (!$minimal || $fdef->minimal || $v))
                $field_list2[] = $fdef;
        return $field_list2;
    }

    private function _prepare_sort() {
        $this->default_sort_column = $this->find_column("id");
        if (!empty($this->sorters))
            $this->sorters[0]->field = null;

        if ($this->search->sorters) {
            foreach ($this->search->sorters as $sorter) {
                if ($sorter->type
                    && ($field = $this->find_column($sorter->type))
                    && $field->prepare($this, PaperColumn::PREP_SORT)
                    && $field->sort)
                    $sorter->field = $field->realize($this);
                else if ($sorter->type) {
                    if ($this->contact->can_view_tags(null)
                        && ($tagger = new Tagger($this->contact))
                        && ($tag = $tagger->check($sorter->type))
                        && ($result = $this->conf->qe("select paperId from PaperTag where tag=? limit 1", $tag))
                        && edb_nrows($result))
                        $this->search->warn("Unrecognized sort “" . htmlspecialchars($sorter->type) . "”. Did you mean “sort:#" . htmlspecialchars($sorter->type) . "”?");
                    else
                        $this->search->warn("Unrecognized sort “" . htmlspecialchars($sorter->type) . "”.");
                    continue;
                }
                ListSorter::push($this->sorters, $sorter);
            }
            if (count($this->sorters) > 1 && $this->sorters[0]->empty)
                array_shift($this->sorters);
        }

        if (empty($this->sorters) || get($this->sorters[0], "field"))
            /* all set */;
        else if ($this->sorters[0]->type
                 && ($c = $this->find_column($this->sorters[0]->type))
                 && $c->prepare($this, PaperColumn::PREP_SORT))
            $this->sorters[0]->field = $c->realize($this);
        else
            $this->sorters[0]->field = $this->default_sort_column;
        if (!empty($this->sorters))
            $this->sorters[0]->type = $this->sorters[0]->field->name;

        // set defaults
        foreach ($this->sorters as $s) {
            if ($s->reverse === null)
                $s->reverse = false;
            if ($s->score === null)
                $s->score = ListSorter::default_score_sort();
        }
    }

    private function _prepare_columns($field_list) {
        $field_list2 = [];
        $this->tbody_attr = [];
        foreach ($field_list as $fdef)
            if ($fdef) {
                $fdef->is_folded = $this->is_folded($fdef);
                $fdef->has_content = false;
                if ($fdef->prepare($this, $fdef->is_folded ? 0 : 1))
                    $field_list2[] = $fdef->realize($this);
            }
        assert(empty($this->row_attr));
        return $field_list2;
    }

    function table_id() {
        return $this->_table_id;
    }

    function make_review_analysis($xrow, PaperInfo $row) {
        return new PaperListReviewAnalysis($xrow, $row->conf);
    }

    function add_header_script($script, $uniqueid = false) {
        if ($uniqueid) {
            if (isset($this->_header_script_map[$uniqueid]))
                return;
            $this->_header_script_map[$uniqueid] = true;
        }
        if ($this->_header_script !== ""
            && ($ch = $this->_header_script[strlen($this->_header_script) - 1]) !== "}"
            && $ch !== "{" && $ch !== ";")
            $this->_header_script .= ";";
        $this->_header_script .= $script;
    }

    private function _columns($field_list, $table_html) {
        $field_list = $this->_canonicalize_columns($field_list);
        if ($table_html)
            $field_list = $this->_view_columns($field_list);
        $this->_prepare_sort(); // NB before prepare_columns so columns see sorter
        return $this->_prepare_columns($field_list);
    }

    private function _statistics_rows($rstate, $fieldDef) {
        $t = "";
        $any_empty = null;
        foreach ($fieldDef as $fdef)
            if ($fdef->viewable_column() && $fdef->has_statistics())
                $any_empty = $any_empty || $fdef->statistic($this, ScoreInfo::COUNT) != $this->count;
        if ($any_empty === null) {
            $this->error_html[] = "No statistics to show. Try adding formulas to your display; for example, “show:statistics show:(count(rev))”.";
            return "";
        }
        foreach (array(ScoreInfo::SUM => "Total", ScoreInfo::MEAN => "Mean",
                       ScoreInfo::MEDIAN => "Median") as $stat => $name) {
            $t .= "<tr>";
            $titled = 0;
            foreach ($fieldDef as $fdef) {
                if (!$fdef->viewable_column())
                    continue;
                if (!$fdef->has_statistics() && is_int($titled) && !$fdef->fold) {
                    ++$titled;
                    continue;
                }
                if (is_int($titled) && $titled) {
                    $name = '<strong>' . $name . '</strong>';
                    if ($any_empty && $stat != ScoreInfo::SUM)
                        $name .= " of nonempty values";
                    $t .= '<td colspan="' . $titled . '" class="pl pl_statheader">' . $name . '</td>';
                }
                $titled = false;
                $t .= '<td class="pl ' . $fdef->className;
                if ($fdef->fold)
                    $t .= ' fx' . $fdef->fold;
                $t .= '">';
                if ($fdef->has_statistics())
                    $t .= $fdef->statistic($this, $stat);
                $t .= '</td>';
            }
            if (is_int($titled))
                return "";
            $t .= "</tr>";
        }
        return $t;
    }

    function id_array() {
        if (!$this->_prepare())
            return null;
        $field_list = $this->_columns("id", false);
        $rows = $this->_rows($field_list);
        if ($rows === null)
            return null;
        $idarray = array();
        foreach ($rows as $row)
            $idarray[] = (int) $row->paperId;
        return $idarray;
    }

    function table_html($listname, $options = array()) {
        if (!$this->_prepare())
            return null;
        // need tags for row coloring
        if ($this->contact->can_view_tags(null))
            $this->qopts["tags"] = 1;
        $this->table_type = $listname;

        // get column list, check sort
        if (isset($options["field_list"]))
            $field_list = $options["field_list"];
        else
            $field_list = $this->_list_columns($listname);
        if (!$field_list) {
            Conf::msg_error("There is no paper list query named “" . htmlspecialchars($listname) . "”.");
            return null;
        }
        $field_list = $this->_columns($field_list, true);
        $rows = $this->_rows($field_list);
        if ($rows === null)
            return null;

        if (empty($rows)) {
            if (($altq = $this->search->alternate_query())) {
                $altqh = htmlspecialchars($altq);
                $url = $this->search->url_site_relative_raw($altq);
                if (substr($url, 0, 5) == "search")
                    $altqh = "<a href=\"" . htmlspecialchars(Navigation::siteurl() . $url) . "\">" . $altqh . "</a>";
                return "No matching papers. Did you mean “${altqh}”?";
            } else
                return "No matching papers";
        }

        // get field array
        $fieldDef = array();
        $ncol = $titlecol = 0;
        // folds: au:1, anonau:2, fullrow:3, aufull:4, force:5, rownum:6, [fields]
        $next_fold = 7;
        foreach ($field_list as $fdef) {
            if ($fdef->viewable()) {
                $fieldDef[] = $fdef;
                $this->columns[$fdef->name] = true;
                if ($fdef->fold) {
                    $fdef->fold = $next_fold;
                    ++$next_fold;
                }
            }
            if ($fdef->name == "title")
                $titlecol = $ncol;
            if ($fdef->viewable_column() && !$fdef->is_folded)
                ++$ncol;
        }

        // count non-callout columns
        $skipcallout = 0;
        foreach ($fieldDef as $fdef)
            if ($fdef->name != "id" && !isset($fdef->is_selector))
                break;
            else
                ++$skipcallout;

        // create render state
        $rstate = new PaperListRenderState($ncol, $titlecol, $skipcallout);
        $this->_any_option_checks = [$this->conf->paper_opts->find_document(DTYPE_SUBMISSION),
                                     $this->conf->paper_opts->find_document(DTYPE_FINAL)];
        foreach ($this->contact->user_option_list() as $o)
            if ($o->is_document())
                $this->_any_option_checks[] = $o;

        // collect row data
        $body = array();
        $lastheading = !empty($this->search->groupmap) ? -1 : -2;
        $need_render = false;
        foreach ($rows as $row) {
            ++$this->count;
            if ($lastheading > -2)
                $lastheading = $this->_check_heading($this->_row_thenval($row), $rstate, $rows,
                                                     $lastheading, $body);
            $body[] = $this->_row_text($rstate, $row, $fieldDef);
            if ($this->need_render && !$need_render) {
                Ht::stash_script('$(plinfo.render_needed)', 'plist_render_needed');
                $need_render = true;
            }
            if ($this->need_render && $this->count % 16 == 15) {
                $body[count($body) - 1] .= "  <script>plinfo.render_needed()</script>\n";
                $this->need_render = false;
            }
        }
        if ($lastheading > -2 && $this->search->is_order_anno)
            while ($lastheading + 1 < count($this->search->groupmap))
                $lastheading = $this->_check_heading($lastheading + 1, $rstate, $rows,
                                                     $lastheading, $body);

        // header cells
        $colhead = "";
        $url = $this->search->url_site_relative_raw();
        if (!defval($options, "noheader")) {
            $colhead .= " <thead class=\"pltable\">\n  <tr class=\"pl_headrow\">";
            $titleextra = $this->_make_title_header_extra($rstate, $fieldDef,
                                                          get($options, "header_links"));

            foreach ($fieldDef as $fdef) {
                if (!$fdef->viewable_column() || $fdef->is_folded)
                    continue;
                $colhead .= "<th class=\"pl " . $fdef->className;
                if ($fdef->fold)
                    $colhead .= " fx" . $fdef->fold;
                $colhead .= "\">";
                if ($fdef->has_content)
                    $colhead .= $this->_field_title($fdef);
                if ($titleextra && $fdef->className == "pl_title") {
                    $colhead .= $titleextra;
                    $titleextra = false;
                }
                $colhead .= "</th>";
            }

            $colhead .= "</tr>\n";

            if ($this->search->is_order_anno
                && isset($this->tbody_attr["data-drag-tag"])
                && $this->tbody_attr["data-drag-tag"] == $this->search->is_order_anno) {
                $colhead .= "  <tr class=\"pl_headrow pl_annorow\" data-anno-tag=\"{$this->search->is_order_anno}\">";
                if ($rstate->titlecol)
                    $colhead .= "<td colspan=\"$rstate->titlecol\"></td>";
                $colhead .= "<td colspan=\"" . ($rstate->ncol - $rstate->titlecol) . "\"><a href=\"#\" onclick=\"return plinfo_tags.edit_anno(this)\">Annotate order</a></td></tr>\n";
            }

            $colhead .= " </thead>\n";
        }

        // table skeleton including fold classes
        $foldclasses = array();
        if ($this->foldable)
            $foldclasses = $this->_analyze_folds($rstate, $fieldDef);
        $enter = "";
        if (self::$include_stash)
            $enter .= Ht::unstash();
        $enter .= "<table class=\"pltable plt_" . htmlspecialchars($listname);
        if ($this->_table_class)
            $enter .= " " . $this->_table_class;
        if (get($options, "list"))
            $enter .= " has-hotlist";
        if (!empty($foldclasses))
            $enter .= " " . join(" ", $foldclasses);
        if ($this->_table_id)
            $enter .= "\" id=\"" . $this->_table_id;
        if (defval($options, "attributes"))
            foreach ($options["attributes"] as $n => $v)
                $enter .= "\" $n=\"" . htmlspecialchars($v);
        if ($this->search->is_order_anno)
            $enter .= "\" data-order-tag=\"{$this->search->is_order_anno}";
        foreach ($this->tbody_attr as $k => $v)
            $enter .= "\" $k=\"" . htmlspecialchars($v);
        if (get($options, "list")) {
            $listobject = $this->search->create_session_list_object($this->ids, self::_listDescription($listname), $this->sortdef());
            if (isset($this->qreq->sort))
                $url .= (strpos($url, "?") ? "&" : "?") . "sort=" . urlencode($this->qreq->sort);
            $listobject->url = $url;
            $enter .= '" data-hotlist="' . htmlspecialchars($listobject->info_string());
        }
        $enter .= "\" data-fold=\"true\">\n";
        $exit = "</table>";

        // maybe make columns, maybe not
        $tbody_class = "pltable";
        if ($this->_view_columns && !empty($this->ids)
            && $this->_column_split($rstate, $colhead, $body)) {
            $enter = '<div class="plsplit_col_ctr_ctr"><div class="plsplit_col_ctr">' . $enter;
            $exit = $exit . "</div></div>";
            $ncol = $rstate->split_ncol;
            $tbody_class = "pltable_split";
        } else {
            $enter .= $colhead;
            $tbody_class .= $rstate->hascolors ? " pltable_colored" : "";
        }

        // footer
        $foot = "";
        if ($this->_view_statistics && !$this->_view_columns)
            $foot .= $this->_statistics_rows($rstate, $fieldDef);
        if ($fieldDef[0] instanceof SelectorPaperColumn
            && !defval($options, "nofooter"))
            $foot .= $this->_footer($ncol, get_s($options, "footer_extra"));
        if ($foot)
            $enter .= ' <tfoot' . ($rstate->hascolors ? ' class="pltable_colored"' : "")
                . ">\n" . $foot . " </tfoot>\n";

        // body
        $enter .= " <tbody class=\"$tbody_class\">\n";

        // header scripts to set up delegations
        if ($this->_header_script)
            $enter .= '  <script>' . $this->_header_script . "</script>\n";

        foreach ($fieldDef as $fdef)
            if ($fdef->has_content && !isset($this->_has[$fdef->name]))
                $this->_has[$fdef->name] = true;
        if ($rstate->has_openau)
            $this->_has["openau"] = true;
        if ($rstate->has_anonau)
            $this->_has["anonau"] = true;

        return $enter . join("", $body) . " </tbody>\n" . $exit;
    }

    function ajaxColumn($fieldId) {
        if (!$this->_prepare()
            || !($fdef = $this->find_column($fieldId)))
            return null;

        // field is never folded, no sorting
        $fname = $fdef->name;
        $this->set_view($fname, true);
        assert(!$this->is_folded($fdef));
        $this->sorters = array();

        // get rows
        $field_list = $this->_columns($fname, false);
        $rows = $this->_rows($field_list);
        if ($rows === null)
            return null;
        $fdef = $field_list[0];

        // output field data
        $data = array();
        if (($x = $fdef->header($this, false)))
            $data["$fname.headerhtml"] = $x;
        $m = array();
        foreach ($rows as $rowidx => $row) {
            $this->row_attr = [];
            if ($fdef->content_empty($this, $row))
                $m[$row->paperId] = "";
            else
                $m[$row->paperId] = $fdef->content($this, $row, $rowidx);
            foreach ($this->row_attr as $k => $v) {
                if (!isset($data["attr.$k"]))
                    $data["attr.$k"] = [];
                $data["attr.$k"][$row->paperId] = $v;
            }
        }
        $data["$fname.html"] = $m;

        if ($fdef->has_content)
            $this->_has[$fname] = true;
        return $data;
    }

    function text_json($fields) {
        if (!$this->_prepare())
            return null;

        // get column list, check sort
        $field_list = $this->_columns($fields, false);
        $rows = $this->_rows($field_list);
        if ($rows === null)
            return null;

        $x = array();
        foreach ($rows as $row) {
            $p = array("id" => $row->paperId);
            foreach ($field_list as $fdef)
                if ($fdef->viewable()
                    && !$fdef->content_empty($this, $row)
                    && ($text = $fdef->text($this, $row)) !== "")
                    $p[$fdef->name] = $text;
            $x[$row->paperId] = (object) $p;
        }

        return $x;
    }

    private function _row_text_csv_data(PaperInfo $row, $fieldDef) {
        if ((string) $row->abstract !== "")
            $this->_has["abstract"] = true;
        if (!empty($this->_any_option_checks))
            $this->_check_option_presence($row);
        $this->ids[] = (int) $row->paperId;

        // main columns
        $csv = [];
        foreach ($fieldDef as $fdef) {
            $empty = $fdef->content_empty($this, $row);
            $c = $empty ? "" : $fdef->text($this, $row);
            if ($c !== "")
                $fdef->has_content = true;
            $csv[$fdef->name] = $c;
        }
        return $csv;
    }

    private function _check_heading_csv($thenval, $lastheading, &$csv) {
        if ($lastheading != $thenval) {
            $ginfo = get($this->search->groupmap, $thenval);
            if ($ginfo === null || !isset($ginfo->heading)
                || strcasecmp($ginfo->heading, "none") == 0) {
                if ($this->count != 1)
                    $csv["__precomment__"] = "none";
            } else
                $csv["__precomment__"] = $ginfo->heading;
        }
        return $thenval;
    }

    function text_csv($listname, $options = array()) {
        if (!$this->_prepare())
            return null;

        // get column list, check sort
        if (isset($options["field_list"]))
            $field_list = $options["field_list"];
        else
            $field_list = $this->_list_columns($listname);
        if (!$field_list)
            return null;
        $field_list = $this->_columns($field_list, true);
        $rows = $this->_rows($field_list);
        if ($rows === null || empty($rows))
            return null;

        // get field array
        $fieldDef = array();
        foreach ($field_list as $fdef)
            if ($fdef->viewable() && !$fdef->is_folded
                && $fdef->header($this, true) != "") {
                $fieldDef[] = $fdef;
                $this->columns[$fdef->name] = true;
            }

        // collect row data
        $body = array();
        $lastheading = !empty($this->search->groupmap) ? -1 : -2;
        foreach ($rows as $row) {
            ++$this->count;
            $csv = $this->_row_text_csv_data($row, $fieldDef);
            if ($lastheading > -2)
                $lastheading = $this->_check_heading_csv($this->_row_thenval($row), $lastheading, $csv);
            $body[] = $csv;
        }

        // header cells
        $header = [];
        foreach ($fieldDef as $fdef)
            if ($fdef->has_content)
                $header[$fdef->name] = $fdef->header($this, true);

        return [$header, $body];
    }
}
