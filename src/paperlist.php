<?php
// paperlist.php -- HotCRP helper class for producing paper lists
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class PaperListTableRender {
    public $table_start;
    public $thead;
    public $tbody_class;
    public $body_rows;
    public $tfoot;
    public $table_end;
    public $error;

    public $ncol;
    public $titlecol;

    public $colorindex = 0;
    public $hascolors = false;
    public $skipcallout;
    public $last_trclass = "";
    public $groupstart = [0];

    function __construct($ncol, $titlecol, $skipcallout) {
        $this->ncol = $ncol;
        $this->titlecol = $titlecol;
        $this->skipcallout = $skipcallout;
    }
    static function make_error($error) {
        $tr = new PaperListTableRender(0, 0, 0);
        $tr->error = $error;
        return $tr;
    }
    function tbody_start() {
        return "  <tbody class=\"{$this->tbody_class}\">\n";
    }
    function heading_row($heading, $attr = []) {
        if (!$heading) {
            return "  <tr class=\"plheading\"><td class=\"plheading-blank\" colspan=\"{$this->ncol}\"></td></tr>\n";
        } else {
            $x = "  <tr class=\"plheading\"";
            foreach ($attr as $k => $v)
                if ($k !== "no_titlecol" && $k !== "tdclass")
                    $x .= " $k=\"" . str_replace("\"", "&quot;", $v) . "\"";
            $x .= ">";
            $titlecol = get($attr, "no_titlecol") ? 0 : $this->titlecol;
            if ($titlecol)
                $x .= "<td class=\"plheading-spacer\" colspan=\"{$titlecol}\"></td>";
            $tdclass = get($attr, "tdclass");
            $x .= "<td class=\"plheading" . ($tdclass ? " $tdclass" : "") . "\" colspan=\"" . ($this->ncol - $titlecol) . "\">";
            return $x . $heading . "</td></tr>\n";
        }
    }
    function heading_separator_row() {
        return "  <tr class=\"plheading\"><td class=\"plheading-separator\" colspan=\"{$this->ncol}\"></td></tr>\n";
    }
}

class PaperListReviewAnalysis {
    private $prow;
    public $rrow = null;
    public $round = "";
    function __construct($rrow, PaperInfo $prow) {
        $this->prow = $prow;
        if ($rrow->reviewId) {
            $this->rrow = $rrow;
            if ($rrow->reviewRound)
                $this->round = htmlspecialchars($prow->conf->round_name($rrow->reviewRound));
        }
    }
    function icon_html($includeLink) {
        $rrow = $this->rrow;
        if (($title = get(ReviewForm::$revtype_names, $rrow->reviewType)))
            $title .= " review";
        else
            $title = "Review";
        if (!$rrow->reviewSubmitted)
            $title .= " (" . $this->description_text() . ")";
        $t = review_type_icon($rrow->reviewType, !$rrow->reviewSubmitted, $title);
        if ($includeLink)
            $t = $this->wrap_link($t);
        if ($this->round)
            $t .= '<span class="revround" title="Review round">&nbsp;' . $this->round . "</span>";
        return $t;
    }
    function icon_text() {
        $x = "";
        if ($this->rrow->reviewType)
            $x = get_s(ReviewForm::$revtype_names, $this->rrow->reviewType);
        if ($x !== "" && $this->round)
            $x .= ":" . $this->round;
        return $x;
    }
    function description_text() {
        if (!$this->rrow)
            return "";
        else if ($this->rrow->reviewSubmitted)
            return "complete";
        else if ($this->rrow->reviewType == REVIEW_SECONDARY
                 && $this->rrow->reviewNeedsSubmit <= 0)
            return "delegated";
        else if ($this->rrow->reviewType == REVIEW_EXTERNAL
                 && $this->rrow->timeApprovalRequested)
            return "awaiting approval";
        else if ($this->rrow->reviewModified > 1)
            return "in progress";
        else if ($this->rrow->reviewModified > 0)
            return "accepted";
        else
            return "not started";
    }
    function wrap_link($html, $klass = null) {
        if (!$this->rrow)
            return $html;
        if (!$this->rrow->reviewSubmitted)
            $href = $this->prow->conf->hoturl("review", "r=" . unparseReviewOrdinal($this->rrow));
        else
            $href = $this->prow->conf->hoturl("paper", "p=" . $this->rrow->paperId . "#r" . unparseReviewOrdinal($this->rrow));
        $t = $klass ? "<a class=\"$klass\"" : "<a";
        return $t . ' href="' . $href . '">' . $html . '</a>';
    }
}

class PaperList {
    public $conf;
    public $user;
    public $qreq;
    public $search;
    private $_reviewer_user;

    private $sortable;
    private $foldable;
    private $_unfold_all = false;
    private $_paper_link_page;
    private $_paper_link_mode;
    private $_view_columns = false;
    private $_view_compact_columns = false;
    private $_view_row_numbers = false;
    private $_view_statistics = false;
    private $_view_force = false;
    private $_view_fields = [];
    private $atab;

    private $_table_id;
    private $_table_class;
    private $report_id;
    private $_row_id_pattern;
    private $_selection;

    private $_rowset;
    private $_row_filter;
    private $_columns_by_name;
    private $_column_errors_by_name = [];

    private $_header_script = "";
    private $_header_script_map = [];

    // columns access
    public $qopts; // set by PaperColumn::prepare
    public $sorters = [];
    public $tagger;
    public $need_tag_attr;
    public $table_attr;
    public $row_attr;
    public $row_overridable;
    public $row_tags;
    public $row_tags_overridable;
    public $need_render;
    public $has_editable_tags = false;
    public $check_format;

    // collected during render and exported to caller
    public $count; // also exported to columns access: 1 more than row index
    public $ids;
    public $groups;
    public $any;
    private $_has;
    public $error_html = array();

    static public $include_stash = true;

    static private $stats = [ScoreInfo::SUM, ScoreInfo::MEAN, ScoreInfo::MEDIAN, ScoreInfo::STDDEV_P];

    function __construct(PaperSearch $search, $args = array(), $qreq = null) {
        $this->search = $search;
        $this->conf = $this->search->conf;
        $this->user = $this->search->user;
        if (!$qreq || !($qreq instanceof Qrequest))
            $qreq = new Qrequest("GET", $qreq);
        $this->qreq = $qreq;
        $this->_reviewer_user = $search->reviewer_user();

        $this->sortable = isset($args["sort"]) && $args["sort"];
        $this->foldable = $this->sortable || !!get($args, "foldable")
            || $this->user->is_manager() /* “Override conflicts” fold */;

        $this->_paper_link_page = "";
        if ($qreq->linkto === "paper" || $qreq->linkto === "review" || $qreq->linkto === "assign")
            $this->_paper_link_page = $qreq->linkto;
        else if ($qreq->linkto === "paperedit") {
            $this->_paper_link_page = "paper";
            $this->_paper_link_mode = "edit";
        }
        $this->atab = $qreq->atab;

        $this->tagger = new Tagger($this->user);

        $this->qopts = $this->search->simple_search_options();
        if ($this->qopts === false)
            $this->qopts = ["paperId" => $this->search->paper_ids()];
        $this->qopts["scores"] = [];
        // NB that actually processed the search, setting PaperSearch::viewmap

        foreach ($this->search->sorters ? : [] as $sorter)
            ListSorter::push($this->sorters, $sorter);
        if ($this->sortable && is_string($args["sort"]))
            array_unshift($this->sorters, PaperSearch::parse_sorter($args["sort"]));
        else if ($this->sortable && $qreq->sort)
            array_unshift($this->sorters, PaperSearch::parse_sorter($qreq->sort));

        if (($report = get($args, "report"))) {
            $display = null;
            if (!get($args, "no_session_display"))
                $display = $this->conf->session("{$report}display", null);
            if ($display === null)
                $display = $this->conf->setting_data("{$report}display_default", null);
            if ($display === null && $report === "pl")
                $display = $this->conf->review_form()->default_display();
            $this->set_view_display($display);
        }
        if (is_string(get($args, "display")))
            $this->set_view_display($args["display"]);
        foreach ($this->search->viewmap ? : [] as $k => $v)
            $this->set_view($k, $v);
        if ($this->conf->submission_blindness() != Conf::BLIND_OPTIONAL
            && get($this->_view_fields, "au")
            && get($this->_view_fields, "anonau") === null)
            $this->_view_fields["anonau"] = true;

        $this->_columns_by_name = ["anonau" => [], "aufull" => [], "rownum" => [], "statistics" => []];

        $this->_rowset = get($args, "rowset");
    }

    function __get($name) {
        error_log("PaperList::$name " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        return $name === "contact" ? $this->user : null;
    }

    function table_id() {
        return $this->_table_id;
    }
    function set_table_id_class($table_id, $table_class, $row_id_pattern = null) {
        $this->_table_id = $table_id;
        $this->_table_class = $table_class;
        $this->_row_id_pattern = $row_id_pattern;
    }

    function report_id() {
        return $this->report_id;
    }
    function set_report($report) {
        $this->report_id = $report;
    }

    function set_row_filter($filter) {
        $this->_row_filter = $filter;
    }

    function add_column($name, PaperColumn $col) {
        $this->_columns_by_name[$name][] = $col;
    }

    function set_view($k, $v) {
        if ($k !== "" && $k[0] === "\"" && $k[strlen($k) - 1] === "\"")
            $k = substr($k, 1, -1);
        if (in_array($k, ["compact", "cc", "compactcolumn", "ccol", "compactcolumns"]))
            $this->_view_compact_columns = $this->_view_columns = $v;
        else if (in_array($k, ["columns", "column", "col"]))
            $this->_view_columns = $v;
        else if ($k === "force")
            $this->_view_force = $v;
        else if (in_array($k, ["statistics", "stat", "stats", "totals"]))
            $this->_view_statistics = $v;
        else if (in_array($k, ["rownum", "rownumbers"]))
            $this->_view_row_numbers = $v;
        else {
            if ($k === "authors")
                $k = "au";
            if ($v && in_array($k, ["aufull", "anonau"]) && !isset($this->_view_fields["au"]))
                $this->_view_fields["au"] = $v;
            $this->_view_fields[$k] = $v;
        }
    }
    function set_view_display($str) {
        $has_sorters = !!array_filter($this->sorters, function ($s) {
            return $s->thenmap === null;
        });
        $splitter = new SearchSplitter($str);
        while (($w = $splitter->shift()) !== "") {
            if (($colon = strpos($w, ":")) !== false) {
                $action = substr($w, 0, $colon);
                $w = substr($w, $colon + 1);
            } else
                $action = "show";
            if ($action === "sort") {
                if (!$has_sorters && $w !== "sort:id")
                    ListSorter::push($this->sorters, PaperSearch::parse_sorter($w));
            } else if ($action === "edit")
                $this->set_view($w, "edit");
            else
                $this->set_view($w, $action !== "hide");
        }
    }

    function set_selection(SearchSelection $ssel) {
        $this->_selection = $ssel;
    }
    function is_selected($paperId, $default = false) {
        return $this->_selection ? $this->_selection->is_selected($paperId) : $default;
    }

    function unfold_all() {
        $this->_unfold_all = true;
    }

    function mark_has($key, $value = true) {
        if ($value)
            $this->_has[$key] = true;
        else if (!isset($this->_has[$key]))
            $this->_has[$key] = false;
    }
    function has($key) {
        if (!isset($this->_has[$key]))
            $this->_has[$key] = $this->_compute_has($key);
        return $this->_has[$key];
    }
    private function _compute_has($key) {
        // paper options
        if ($key === "paper" || $key === "final") {
            $opt = $this->conf->paper_opts->find($key);
            return $this->user->can_view_some_paper_option($opt)
                && $this->_rowset->any(function ($row) use ($opt) {
                    return ($opt->id == DTYPE_SUBMISSION ? $row->paperStorageId : $row->finalPaperStorageId) > 1
                        && $this->user->can_view_paper_option($row, $opt);
                });
        }
        if (str_starts_with($key, "opt")
            && ($opt = $this->conf->paper_opts->find($key))) {
            return $this->user->can_view_some_paper_option($opt)
                && $this->_rowset->any(function ($row) use ($opt) {
                    return ($ov = $row->option($opt->id))
                        && (!$opt->has_document() || $ov->value > 1)
                        && $this->user->can_view_paper_option($row, $opt);
                });
        }
        // other features
        if ($key === "abstract")
            return $this->_rowset->any(function ($row) {
                return (string) $row->abstract !== "";
            });
        if ($key === "openau")
            return $this->has("authors")
                && (!$this->user->is_manager()
                    || $this->_rowset->any(function ($row) {
                           return $this->user->can_view_authors($row);
                       }));
        if ($key === "anonau")
            return $this->has("authors")
                && $this->user->is_manager()
                && $this->_rowset->any(function ($row) {
                       return $this->user->allow_view_authors($row)
                           && !$this->user->can_view_authors($row);
                   });
        if ($key === "need_submit")
            return $this->_rowset->any(function ($row) {
                return $row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0;
            });
        if ($key === "accepted")
            return $this->_rowset->any(function ($row) {
                return $row->outcome > 0 && $this->user->can_view_decision($row);
            });
        if ($key === "need_final")
            return $this->has("accepted")
                && $this->_rowset->any(function ($row) {
                       return $row->outcome > 0
                           && $this->user->can_view_decision($row)
                           && $row->timeFinalSubmitted <= 0;
                   });
        if (!in_array($key, ["collab", "lead", "shepherd", "topics", "sel", "need_review", "authors", "tags"]))
            error_log("unexpected PaperList::_compute_has({$key})");
        return false;
    }


    private function find_columns($name) {
        if (!array_key_exists($name, $this->_columns_by_name)) {
            $fs = $this->conf->paper_columns($name, $this->user);
            if (!$fs) {
                $errors = $this->conf->xt_factory_errors();
                if (empty($errors)) {
                    if ($this->conf->paper_columns($name, $this->conf->site_contact()))
                        $errors[] = "Permission error.";
                    else
                        $errors[] = "No such column.";
                }
                $this->_column_errors_by_name[$name] = $errors;
            }
            $nfs = [];
            foreach ($fs as $fdef) {
                if ($fdef->name === $name)
                    $nfs[] = PaperColumn::make($this->conf, $fdef);
                else {
                    if (!array_key_exists($fdef->name, $this->_columns_by_name))
                        $this->_columns_by_name[$fdef->name][] = PaperColumn::make($this->conf, $fdef);
                    $nfs = array_merge($nfs, $this->_columns_by_name[$fdef->name]);
                }
            }
            $this->_columns_by_name[$name] = $nfs;
        }
        return $this->_columns_by_name[$name];
    }
    private function find_column($name) {
        return get($this->find_columns($name), 0);
    }

    function _sort_compare($a, $b) {
        foreach ($this->sorters as $s) {
            if (($x = $s->field->compare($a, $b, $s))) {
                return $x < 0 === $s->reverse ? 1 : -1;
            }
        }
        if ($a->paperId != $b->paperId) {
            return $a->paperId < $b->paperId ? -1 : 1;
        } else {
            return 0;
        }
    }
    function _then_sort_compare($a, $b) {
        if (($x = $a->_then_sort_info - $b->_then_sort_info)) {
            return $x < 0 ? -1 : 1;
        }
        foreach ($this->sorters as $s) {
            if (($s->thenmap === null || $s->thenmap === $a->_then_sort_info)
                && ($x = $s->field->compare($a, $b, $s))) {
                return $x < 0 === $s->reverse ? 1 : -1;
            }
        }
        if ($a->paperId != $b->paperId) {
            return $a->paperId < $b->paperId ? -1 : 1;
        } else {
            return 0;
        }
    }
    private function _sort($rows) {
        $overrides = $this->user->add_overrides($this->_view_force ? Contact::OVERRIDE_CONFLICT : 0);

        if (($thenmap = $this->search->thenmap)) {
            foreach ($rows as $row)
                $row->_then_sort_info = $thenmap[$row->paperId];
        }
        foreach ($this->sorters as $s) {
            $s->assign_uid();
            $s->list = $this;
        }
        foreach ($this->sorters as $s) {
            $s->field->analyze_sort($this, $rows, $s);
        }

        usort($rows, [$this, $thenmap ? "_then_sort_compare" : "_sort_compare"]);

        foreach ($this->sorters as $s)
            $s->list = null; // break circular ref
        $this->user->set_overrides($overrides);
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


    function _contentDownload($row) {
        if ($row->size == 0 || !$this->user->can_view_pdf($row))
            return "";
        $dtype = $row->finalPaperStorageId <= 0 ? DTYPE_SUBMISSION : DTYPE_FINAL;
        return "&nbsp;" . $row->document($dtype)->link_html("", DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE | DocumentInfo::L_FINALTITLE);
    }

    function _paperLink(PaperInfo $row) {
        $pt = $this->_paper_link_page ? : "paper";
        $rrow = null;
        if ($pt === "review" || $pt === "finishreview") {
            $rrow = $row->review_of_user($this->user);
            if (!$rrow || ($pt === "finishreview" && !$rrow->reviewNeedsSubmit))
                $pt = "paper";
            else
                $pt = "review";
        }
        $pl = "p=" . $row->paperId;
        if ($pt === "paper" && $this->_paper_link_mode)
            $pl .= "&amp;m=" . $this->_paper_link_mode;
        else if ($pt === "review") {
            $pl .= "&amp;r=" . unparseReviewOrdinal($rrow);
            if ($rrow->reviewSubmitted > 0)
                $pl .= "&amp;m=r";
        }
        return $row->conf->hoturl($pt, $pl);
    }

    // content downloaders
    static function wrapChairConflict($text) {
        return '<span class="fn5"><em>Hidden for conflict</em> <span class="barsep">·</span> <a class="fn5" href="#">Override conflicts</a></span><span class="fx5">' . $text . "</span>";
    }

    function reviewer_user() {
        return $this->_reviewer_user;
    }
    function set_reviewer_user(Contact $user) {
        $this->_reviewer_user = $user;
    }

    function _content_pc($contactId) {
        $pc = $this->conf->pc_member_by_id($contactId);
        return $pc ? $this->user->reviewer_html_for($pc) : "";
    }

    function _text_pc($contactId) {
        $pc = $this->conf->pc_member_by_id($contactId);
        return $pc ? $this->user->reviewer_text_for($pc) : "";
    }

    function _compare_pc($contactId1, $contactId2) {
        $pc1 = $this->conf->pc_member_by_id($contactId1);
        $pc2 = $this->conf->pc_member_by_id($contactId2);
        if ($pc1 === $pc2)
            return 0;
        else if (!$pc1 || !$pc2)
            return $pc1 ? -1 : 1;
        else
            return Contact::compare($pc1, $pc2);
    }

    function displayable_list_actions($prefix) {
        $la = [];
        foreach ($this->conf->list_action_map() as $name => $fjs)
            if (str_starts_with($name, $prefix)) {
                $uf = null;
                foreach ($fjs as $fj)
                    if (Conf::xt_priority_compare($fj, $uf) <= 0
                        && $this->conf->xt_allowed($fj, $this->user)
                        && $this->action_xt_displayed($fj))
                        $uf = $fj;
                if ($uf)
                    $la[$name] = $uf;
            }
        return $la;
    }

    function action_xt_displayed($fj) {
        if (isset($fj->display_if_report)
            && (str_starts_with($fj->display_if_report, "!")
                ? $this->report_id === substr($fj->display_if_report, 1)
                : $this->report_id !== $fj->display_if_report))
            return false;
        if (isset($fj->display_if)
            && !$this->conf->xt_check($fj->display_if, $fj, $this->user))
            return false;
        if (isset($fj->display_if_list_has)) {
            $ifl = $fj->display_if_list_has;
            foreach (is_array($ifl) ? $ifl : [$ifl] as $h) {
                if (!is_bool($h)) {
                    if (str_starts_with($h, "!"))
                        $h = !$this->has(substr($h, 1));
                    else
                        $h = $this->has($h);
                }
                if (!$h)
                    return false;
            }
        }
        if (isset($fj->disabled) && $fj->disabled)
            return false;
        return true;
    }

    static function render_footer_row($arrow_ncol, $ncol, $header,
                            $lllgroups, $activegroup = -1, $extra = null) {
        $foot = "<tr class=\"pl_footrow\">\n   ";
        if ($arrow_ncol) {
            $foot .= '<td class="plf pl_footselector" colspan="' . $arrow_ncol . '">'
                . Icons::ui_upperleft() . "</td>\n   ";
        }
        $foot .= '<td id="plact" class="plf pl_footer linelinks" colspan="' . $ncol . '">';

        if ($header) {
            $foot .= "<table class=\"pl_footerpart\"><tbody><tr>\n"
                . '    <td class="pl_footer_desc">' . $header . "</td>\n"
                . '   </tr></tbody></table>';
        }

        foreach ($lllgroups as $i => $lllg) {
            $attr = ["class" => "linelink pl_footerpart"];
            if ($i === $activegroup)
                $attr["class"] .= " active";
            for ($j = 2; $j < count($lllg); ++$j) {
                if (is_array($lllg[$j])) {
                    foreach ($lllg[$j] as $k => $v)
                        if (str_starts_with($k, "linelink-")) {
                            $k = substr($k, 9);
                            if ($k === "class")
                                $attr["class"] .= " " . $v;
                            else
                                $attr[$k] = $v;
                        }
                }
            }
            $foot .= "<table";
            foreach ($attr as $k => $v)
                $foot .= " $k=\"" . htmlspecialchars($v) . "\"";
            $foot .= "><tbody><tr>\n"
                . "    <td class=\"pl_footer_desc lll\"><a class=\"ui tla\" href=\""
                . $lllg[0] . "\">" . $lllg[1] . "</a></td>\n";
            for ($j = 2; $j < count($lllg); ++$j) {
                $cell = is_array($lllg[$j]) ? $lllg[$j] : ["content" => $lllg[$j]];
                $attr = [];
                foreach ($cell as $k => $v) {
                    if ($k !== "content" && !str_starts_with($k, "linelink-"))
                        $attr[$k] = $v;
                }
                if ($attr || isset($cell["content"])) {
                    $attr["class"] = rtrim("lld " . get($attr, "class", ""));
                    $foot .= "    <td";
                    foreach ($attr as $k => $v)
                        $foot .= " $k=\"" . htmlspecialchars($v) . "\"";
                    $foot .= ">";
                    if ($j === 2 && isset($cell["content"]) && !str_starts_with($cell["content"], "<b>"))
                        $foot .= "<b>:&nbsp;</b> ";
                    if (isset($cell["content"]))
                        $foot .= $cell["content"];
                    $foot .= "</td>\n";
                }
            }
            if ($i < count($lllgroups) - 1)
                $foot .= "    <td>&nbsp;<span class='barsep'>·</span>&nbsp;</td>\n";
            $foot .= "   </tr></tbody></table>";
        }
        return $foot . (string) $extra . "<hr class=\"c\" /></td>\n </tr>";
    }

    private function _footer($ncol, $extra) {
        if ($this->count == 0)
            return "";

        $renderers = [];
        foreach ($this->conf->list_action_renderers() as $name => $fjs) {
            $rf = null;
            foreach ($fjs as $fj)
                if (Conf::xt_priority_compare($fj, $rf) <= 0
                    && $this->conf->xt_allowed($fj, $this->user)
                    && $this->action_xt_displayed($fj))
                    $rf = $fj;
            if ($rf) {
                Conf::xt_resolve_require($rf);
                $renderers[] = $rf;
            }
        }
        usort($renderers, "Conf::xt_position_compare");

        $lllgroups = [];
        $whichlll = -1;
        foreach ($renderers as $rf) {
            if (($lllg = call_user_func($rf->render_callback, $this, $rf))) {
                if (is_string($lllg))
                    $lllg = [$lllg];
                array_unshift($lllg, $rf->name, $rf->title);
                $lllg[0] = SelfHref::make($this->qreq, ["atab" => $lllg[0], "anchor" => "plact"]);
                $lllgroups[] = $lllg;
                if ($this->qreq->fn == $rf->name || $this->atab == $rf->name)
                    $whichlll = count($lllgroups) - 1;
            }
        }

        $footsel_ncol = $this->_view_columns ? 0 : 1;
        return self::render_footer_row($footsel_ncol, $ncol - $footsel_ncol,
            "<b>Select papers</b> (or <a class=\"ui js-select-all\" href=\""
            . SelfHref::make($this->qreq, ["selectall" => 1, "anchor" => "plact"])
            . '">select all ' . $this->count . "</a>), then&nbsp;",
            $lllgroups, $whichlll, $extra);
    }

    private function _default_linkto($page) {
        if (!$this->_paper_link_page)
            $this->_paper_link_page = $page;
    }

    private function _list_columns() {
        switch ($this->report_id) {
        case "a":
            return "id title revstat statusfull authors collab abstract topics reviewers shepherd scores formulas";
        case "authorHome":
            return "id title statusfull";
        case "act":
        case "all":
            return "sel id title revtype revstat statusfull authors collab abstract topics pcconflicts allpref reviewers tags tagreports lead shepherd scores formulas";
        case "reviewerHome":
            $this->_default_linkto("finishreview");
            return "id title revtype status";
        case "ar":
        case "r":
        case "rable":
            $this->_default_linkto("finishreview");
            /* fallthrough */
        case "acc":
        case "lead":
        case "manager":
        case "s":
        case "vis":
            return "sel id title revtype revstat status authors collab abstract topics pcconflicts allpref reviewers tags tagreports lead shepherd scores formulas";
        case "req":
        case "rout":
            $this->_default_linkto("review");
            return "sel id title revtype revstat status authors collab abstract topics pcconflicts allpref reviewers tags tagreports lead shepherd scores formulas";
        case "reqrevs":
            $this->_default_linkto("review");
            return "id title revdelegation revstat status authors collab abstract topics pcconflicts allpref reviewers tags tagreports lead shepherd scores formulas";
        case "reviewAssignment":
            $this->_default_linkto("assign");
            return "id title revpref topicscore desirability assrev authors potentialconflict topics allrevtopicpref reviewers tags scores formulas";
        case "conflictassign":
            $this->_default_linkto("assign");
            return "id title abstract authors potentialconflict revtype editconf tags";
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
            error_log($this->conf->dbname . ": No such report {$this->report_id}");
            return null;
        }
    }

    function _canonicalize_columns($fields) {
        if (is_string($fields))
            $fields = explode(" ", $fields);
        $field_list = array();
        foreach ($fields as $fid) {
            foreach ($this->find_columns($fid) as $fdef)
                $field_list[] = $fdef;
        }
        if ($this->qreq->selectall > 0 && $field_list[0]->name == "sel")
            $field_list[0] = $this->find_column("selon");
        return $field_list;
    }


    function rowset() {
        if ($this->_rowset === null) {
            $this->qopts["scores"] = array_keys($this->qopts["scores"]);
            if (empty($this->qopts["scores"]))
                unset($this->qopts["scores"]);
            $result = $this->conf->paper_result($this->user, $this->qopts);
            $this->_rowset = new PaperInfoSet;
            while (($row = PaperInfo::fetch($result, $this->user))) {
                assert(!$this->_rowset->get($row->paperId));
                $this->_rowset->add($row);
            }
            Dbl::free($result);
        }
        return $this->_rowset;
    }

    private function _rows($field_list) {
        if (!$field_list)
            return null;

        // analyze rows (usually noop)
        $rows = $this->rowset()->all();
        foreach ($field_list as $fdef)
            $fdef->analyze($this, $rows, $field_list);

        // sort rows
        if (!empty($this->sorters))
            $rows = $this->_sort($rows);

        // set `ids`
        $this->ids = [];
        foreach ($rows as $prow)
            $this->ids[] = $prow->paperId;

        // set `groups`
        $this->groups = [];
        if (!empty($this->search->groupmap))
            $this->_collect_groups($rows);

        return $rows;
    }

    private function _collect_groups($srows) {
        $groupmap = $this->search->groupmap ? : [];
        $thenmap = $this->search->thenmap ? : [];
        $rowpos = 0;
        for ($grouppos = 0;
             $rowpos < count($srows) || $grouppos < count($groupmap);
             ++$grouppos) {
            $first_rowpos = $rowpos;
            while ($rowpos < count($srows)
                   && get_i($thenmap, $srows[$rowpos]->paperId) === $grouppos)
                ++$rowpos;
            $ginfo = get($groupmap, $grouppos);
            if (($ginfo === null || $ginfo->is_empty()) && $first_rowpos === 0)
                continue;
            $ginfo = $ginfo ? clone $ginfo : TagInfo::make_empty();
            $ginfo->pos = $first_rowpos;
            $ginfo->count = $rowpos - $first_rowpos;
            // leave off an empty “Untagged” section unless editing
            if ($ginfo->count === 0 && $ginfo->tag && !$ginfo->annoId
                && !$this->has_editable_tags)
                continue;
            $this->groups[] = $ginfo;
        }
    }

    function is_folded($fdef) {
        $fname = $fdef;
        if (is_object($fdef) || ($fdef = $this->find_column($fname)))
            $fname = $fdef->fold ? $fdef->name : null;
        else if ($fname === "force")
            return !$this->_view_force;
        else if ($fname === "rownum")
            return !$this->_view_row_numbers;
        else if ($fname === "statistics")
            return !$this->_view_statistics;
        if ($fname === "authors")
            $fname = "au";
        if (!$fname || $this->_unfold_all || $this->qreq["show$fname"])
            return false;
        return !get($this->_view_fields, $fname);
    }

    private function _wrap_conflict($main_content, $override_content, PaperColumn $fdef) {
        if ($main_content === $override_content)
            return $main_content;
        $tag = $fdef->viewable_row() ? "div" : "span";
        if ((string) $main_content !== "")
            $main_content = "<$tag class=\"fn5\">$main_content</$tag>";
        if ((string) $override_content !== "")
            $override_content = "<$tag class=\"fx5\">$override_content</$tag>";
        return $main_content . $override_content;
    }

    private function _row_field_content(PaperColumn $fdef, PaperInfo $row) {
        $content = "";
        if ($this->row_overridable && $fdef->override === PaperColumn::OVERRIDE_FOLD_IFEMPTY) {
            $empty = $fdef->content_empty($this, $row);
            if ($empty) {
                $overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
                $empty = $fdef->content_empty($this, $row);
                if (!$empty && $fdef->is_visible)
                    $content = $this->_wrap_conflict("", $fdef->content($this, $row), $fdef);
                $this->user->set_overrides($overrides);
            } else if ($fdef->is_visible)
                $content = $fdef->content($this, $row);
        } else if ($this->row_overridable && $fdef->override === PaperColumn::OVERRIDE_FOLD_BOTH) {
            $content1 = $content2 = "";
            $empty1 = $fdef->content_empty($this, $row);
            if (!$empty1 && $fdef->is_visible)
                $content1 = $fdef->content($this, $row);
            $overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
            $empty2 = $fdef->content_empty($this, $row);
            if (!$empty2 && $fdef->is_visible)
                $content2 = $fdef->content($this, $row);
            $this->user->set_overrides($overrides);
            $empty = $empty1 && $empty2;
            $content = $this->_wrap_conflict($content1, $content2, $fdef);
        } else if ($this->row_overridable && $fdef->override === PaperColumn::OVERRIDE_ALWAYS) {
            $overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
            $empty = $fdef->content_empty($this, $row);
            if (!$empty && $fdef->is_visible)
                $content = $fdef->content($this, $row);
            $this->user->set_overrides($overrides);
        } else {
            $empty = $fdef->content_empty($this, $row);
            if (!$empty && $fdef->is_visible)
                $content = $fdef->content($this, $row);
        }
        return [$empty, $content];
    }

    private function _row_setup(PaperInfo $row) {
        ++$this->count;
        $this->row_attr = [];
        $this->row_overridable = $this->user->can_meaningfully_override($row);

        $this->row_tags = $this->row_tags_overridable = null;
        if (isset($row->paperTags) && $row->paperTags !== "") {
            if ($this->row_overridable) {
                $overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
                $this->row_tags_overridable = $row->viewable_tags($this->user);
                $this->user->remove_overrides(Contact::OVERRIDE_CONFLICT);
                $this->row_tags = $row->viewable_tags($this->user);
                $this->user->set_overrides($overrides);
            } else
                $this->row_tags = $row->viewable_tags($this->user);
        }
    }

    private function _row_content($rstate, PaperInfo $row, $fieldDef) {
        // main columns
        $tm = "";
        foreach ($fieldDef as $fdef) {
            if (!$fdef->viewable_column()
                || (!$fdef->is_visible && $fdef->has_content))
                continue;
            list($empty, $content) = $this->_row_field_content($fdef, $row);
            if ($fdef->is_visible) {
                if ($content !== "") {
                    $tm .= "<td class=\"pl " . $fdef->className;
                    if ($fdef->fold)
                        $tm .= " fx{$fdef->fold}";
                    $tm .= "\">" . $content . "</td>";
                } else {
                    $tm .= "<td";
                    if ($fdef->fold)
                        $tm .= " class=\"fx{$fdef->fold}\"";
                    $tm .= "></td>";
                }
            }
            if ($fdef->is_visible ? $content !== "" : !$empty)
                $fdef->has_content = true;
        }

        // extension columns
        $tt = "";
        foreach ($fieldDef as $fdef) {
            if (!$fdef->viewable_row()
                || (!$fdef->is_visible && $fdef->has_content))
                continue;
            list($empty, $content) = $this->_row_field_content($fdef, $row);
            if ($fdef->is_visible) {
                if ($content !== "" && ($ch = $fdef->header($this, false))) {
                    if ($content[0] !== "<"
                        || !preg_match('/\A((?:<(?:div|p).*?>)*)([\s\S]*)\z/', $content, $cm))
                        $cm = [null, "", $content];
                    $content = $cm[1] . '<em class="plx">' . $ch . ':</em> ' . $cm[2];
                }
                $tt .= "<div class=\"" . $fdef->className;
                if ($fdef->fold)
                    $tt .= " fx" . $fdef->fold;
                $tt .= "\">" . $content . "</div>";
            }
            if ($fdef->is_visible ? $content !== "" : !$empty)
                $fdef->has_content = !$empty;
        }

        // filter
        if ($this->_row_filter
            && !call_user_func($this->_row_filter, $this, $row, $fieldDef, $tm, $tt)) {
            --$this->count;
            return "";
        }

        // tags
        if ($this->need_tag_attr) {
            if ($this->row_tags_overridable
                && $this->row_tags_overridable !== $this->row_tags) {
                $this->row_attr["data-tags"] = trim($this->row_tags_overridable);
                $this->row_attr["data-tags-conflicted"] = trim($this->row_tags);
            } else
                $this->row_attr["data-tags"] = trim($this->row_tags);
        }

        // row classes
        $trclass = [];
        $cc = "";
        if (get($row, "paperTags")) {
            if ($this->row_tags_overridable
                && ($cco = $row->conf->tags()->color_classes($this->row_tags_overridable))) {
                $ccx = $row->conf->tags()->color_classes($this->row_tags);
                if ($cco !== $ccx) {
                    $this->row_attr["data-color-classes"] = $cco;
                    $this->row_attr["data-color-classes-conflicted"] = $ccx;
                    $trclass[] = "colorconflict";
                }
                $cc = $this->_view_force ? $cco : $ccx;
                $rstate->hascolors = $rstate->hascolors || str_ends_with($cco, " tagbg");
            } else if ($this->row_tags)
                $cc = $row->conf->tags()->color_classes($this->row_tags);
        }
        if ($cc) {
            $trclass[] = $cc;
            $rstate->hascolors = $rstate->hascolors || str_ends_with($cc, " tagbg");
        }
        if (!$cc || !$rstate->hascolors)
            $trclass[] = "k" . $rstate->colorindex;
        if (($highlightclass = get($this->search->highlightmap, $row->paperId)))
            $trclass[] = $highlightclass[0] . "highlightmark";
        $trclass = join(" ", $trclass);
        $rstate->colorindex = 1 - $rstate->colorindex;
        $rstate->last_trclass = $trclass;

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

    private function _groups_for($grouppos, $rstate, &$body, $last) {
        for ($did_groupstart = false;
             $grouppos < count($this->groups)
             && ($last || $this->count > $this->groups[$grouppos]->pos);
             ++$grouppos) {
            if ($this->count !== 1 && $did_groupstart === false)
                $rstate->groupstart[] = $did_groupstart = count($body);
            $ginfo = $this->groups[$grouppos];
            if ($ginfo->is_empty()) {
                $body[] = $rstate->heading_row(null);
            } else {
                $attr = [];
                if ($ginfo->tag)
                    $attr["data-anno-tag"] = $ginfo->tag;
                if ($ginfo->annoId) {
                    $attr["data-anno-id"] = $ginfo->annoId;
                    $attr["data-tags"] = "{$ginfo->tag}#{$ginfo->tagIndex}";
                    if (get($this->table_attr, "data-drag-tag"))
                        $attr["tdclass"] = "need-draghandle";
                }
                $x = "<span class=\"plheading-group";
                if ($ginfo->heading !== ""
                    && ($format = $this->conf->check_format($ginfo->annoFormat, $ginfo->heading))) {
                    $x .= " need-format\" data-format=\"$format";
                    $this->need_render = true;
                }
                $x .= "\" data-title=\"" . htmlspecialchars($ginfo->heading)
                    . "\">" . htmlspecialchars($ginfo->heading)
                    . ($ginfo->heading !== "" ? " " : "")
                    . "</span><span class=\"plheading-count\">"
                    . plural($ginfo->count, "paper") . "</span>";
                $body[] = $rstate->heading_row($x, $attr);
                $rstate->colorindex = 0;
            }
        }
        return $grouppos;
    }

    private function _field_title($fdef) {
        $t = $fdef->header($this, false);
        if (!$fdef->viewable_column()
            || !$fdef->sort
            || !$this->sortable
            || !($sort_url = $this->search->url_site_relative_raw()))
            return $t;

        $default_score_sort = ListSorter::default_score_sort($this->conf);
        $sort_name = $fdef->sort_name($default_score_sort);
        $sort_url = htmlspecialchars(Navigation::siteurl() . $sort_url)
            . (strpos($sort_url, "?") ? "&amp;" : "?") . "sort=" . urlencode($sort_name);
        $s0 = get($this->sorters, 0);

        $sort_class = "pl_sort";
        if ($s0 && $s0->thenmap === null
            && $sort_name === $s0->field->sort_name($s0->score ? : $default_score_sort)) {
            $sort_class = "pl_sort pl_sorting" . ($s0->reverse ? "_rev" : "_fwd");
            $sort_url .= $s0->reverse ? "" : urlencode(" reverse");
        }

        if ($this->user->overrides() & Contact::OVERRIDE_CONFLICT)
            $sort_url .= "&amp;forceShow=1";
        return '<a class="' . $sort_class . '" rel="nofollow" href="' . $sort_url . '">' . $t . '</a>';
    }

    private function _analyze_folds($rstate, $fieldDef) {
        $classes = $jscol = array();
        $has_sel = false;
        $has_statistics = $has_loadable_statistics = false;
        $default_score_sort = ListSorter::default_score_sort($this->conf);
        foreach ($fieldDef as $fdef) {
            $j = ["name" => $fdef->name,
                  "title" => $fdef->header($this, false),
                  "position" => $fdef->position];
            if ($fdef->className != "pl_" . $fdef->name)
                $j["className"] = $fdef->className;
            if ($fdef->viewable_column()) {
                $j["column"] = true;
                if ($fdef->has_statistics()) {
                    $j["has_statistics"] = true;
                    if ($fdef->has_content)
                        $has_loadable_statistics = true;
                    if ($fdef->has_content && $fdef->is_visible)
                        $has_statistics = true;
                }
                if ($fdef->sort)
                    $j["sort_name"] = $fdef->sort_name($default_score_sort);
            }
            if (!$fdef->is_visible)
                $j["missing"] = true;
            if ($fdef->has_content && !$fdef->is_visible)
                $j["loadable"] = true;
            if ($fdef->fold)
                $j["foldnum"] = $fdef->fold;
            $fdef->annotate_field_js($this, $j);
            $jscol[] = $j;
            if ($fdef->fold)
                $classes[] = "fold" . $fdef->fold . ($fdef->is_visible ? "o" : "c");
            if ($fdef instanceof SelectorPaperColumn)
                $has_sel = true;
        }
        // authorship requires special handling
        if ($this->has("anonau"))
            $classes[] = "fold2" . ($this->is_folded("anonau") ? "c" : "o");
        // row number folding
        if ($has_sel)
            $classes[] = "fold6" . ($this->_view_row_numbers ? "o" : "c");
        if ($this->user->is_track_manager())
            $classes[] = "fold5" . ($this->_view_force ? "o" : "c");
        $classes[] = "fold7" . ($this->_view_statistics ? "o" : "c");
        $classes[] = "fold8" . ($has_statistics ? "o" : "c");
        if ($this->_table_id)
            Ht::stash_script("plinfo.initialize(\"#{$this->_table_id}\"," . json_encode_browser($jscol) . ");");
        return $classes;
    }

    private function _make_title_header_extra($rstate, $fieldDef, $show_links) {
        $titleextra = "";
        if ($show_links && $this->has("authors")) {
            $titleextra .= '<span class="sep"></span>';
            if ($this->conf->submission_blindness() == Conf::BLIND_NEVER)
                $titleextra .= '<a class="ui js-plinfo" href="#" data-plinfo-field="au">'
                    . '<span class="fn1">Show authors</span><span class="fx1">Hide authors</span></a>';
            else if ($this->user->is_manager() && !$this->has("openau"))
                $titleextra .= '<a class="ui js-plinfo" href="#" data-plinfo-field="au anonau">'
                    . '<span class="fn1 fn2">Show authors</span><span class="fx1 fx2">Hide authors</span></a>';
            else if ($this->user->is_manager() && $this->has("anonau"))
                $titleextra .= '<a class="ui js-plinfo fn1" href="#" data-plinfo-field="au">Show authors</a>'
                    . '<a class="ui js-plinfo fx1 fn2" href="#" data-plinfo-field="anonau">Show all authors</a>'
                    . '<a class="ui js-plinfo fx1 fx2" href="#" data-plinfo-field="au anonau">Hide authors</a>';
            else
                $titleextra .= '<a class="ui js-plinfo" href="#" data-plinfo-field="au">'
                    . '<span class="fn1">Show authors</span><span class="fx1">Hide authors</span></a>';
        }
        if ($show_links && $this->has("tags")) {
            $tagfold = $this->find_column("tags")->fold;
            $titleextra .= '<span class="sep"></span>';
            $titleextra .= '<a class="ui js-plinfo" href="#" data-plinfo-field="tags">'
                . '<span class="fn' . $tagfold . '">Show tags</span><span class="fx' . $tagfold . '">Hide tags</span></a>';
        }
        if ($titleextra)
            $titleextra = '<span class="pl_titleextra">' . $titleextra . '</span>';
        return $titleextra;
    }

    private function _column_split($rstate, $colhead, &$body) {
        if (count($rstate->groupstart) <= 1)
            return false;
        $rstate->groupstart[] = count($body);
        $rstate->split_ncol = count($rstate->groupstart) - 1;

        $rownum_marker = "<span class=\"pl_rownum fx6\">";
        $rownum_len = strlen($rownum_marker);
        $nbody = array("<tr>");
        $tbody_class = "pltable" . ($rstate->hascolors ? " pltable_colored" : "");
        for ($i = 1; $i < count($rstate->groupstart); ++$i) {
            $nbody[] = '<td class="plsplit_col top" width="' . (100 / $rstate->split_ncol) . '%"><div class="plsplit_col"><table width="100%">';
            $nbody[] = $colhead . "  <tbody class=\"$tbody_class\">\n";
            $number = 1;
            for ($j = $rstate->groupstart[$i - 1]; $j < $rstate->groupstart[$i]; ++$j) {
                $x = $body[$j];
                if (($pos = strpos($x, $rownum_marker)) !== false) {
                    $pos += strlen($rownum_marker);
                    $x = substr($x, 0, $pos) . preg_replace('/\A\d+/', $number, substr($x, $pos));
                    ++$number;
                } else if (strpos($x, "<td class=\"plheading-blank") !== false)
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

    private function _prepare($report_id = null) {
        $this->_has = [];
        $this->count = 0;
        $this->need_render = false;
        $this->report_id = $this->report_id ? : $report_id;
        return true;
    }

    private function _expand_view_column($k, $report) {
        if (in_array($k, ["anonau", "aufull"]))
            return [];
        $fs = $this->find_columns($k);
        if (!$fs) {
            if (!$this->search->viewmap || !isset($this->search->viewmap[$k])) {
                if (($rfinfo = ReviewInfo::field_info($k, $this->conf))
                    && ($rfield = $this->conf->review_field($rfinfo->id)))
                    $fs = $this->find_columns($rfield->name);
            } else if ($report && isset($this->_column_errors_by_name[$k])) {
                foreach ($this->_column_errors_by_name[$k] as $i => $err)
                    $this->error_html[] = ($i ? "" : "Can’t show “" . htmlspecialchars($k) . "”: ") . $err;
            }
        }
        return $fs;
    }

    private function _view_columns($field_list) {
        // add explicitly requested columns
        $viewmap_add = [];
        foreach ($this->_view_fields as $k => $v) {
            $f = $this->_expand_view_column($k, !!$v);
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
        foreach ($field_list as $fi => $f) {
            if (get($this->_view_fields, $f->name) === "edit")
                $f->mark_editable();
        }

        // remove deselected columns;
        // in compactcolumns view, remove non-minimal columns
        $minimal = $this->_view_compact_columns;
        $field_list2 = array();
        foreach ($field_list as $fdef) {
            $v = get($this->_view_fields, $fdef->name);
            if ($v
                || $fdef->fold
                || ($v !== false && (!$minimal || $fdef->minimal)))
                $field_list2[] = $fdef;
        }
        return $field_list2;
    }

    private function _prepare_sort() {
        $sorters = [];
        foreach ($this->sorters as $sorter) {
            if ($sorter->field) {
                // already prepared (e.g., NumericOrderPaperColumn)
                $sorters[] = $sorter;
            } else if ($sorter->type
                       && ($field = $this->find_column($sorter->type))) {
                if ($field->prepare($this, PaperColumn::PREP_SORT)
                    && $field->sort) {
                    $sorter->field = $field->realize($this);
                    $sorter->name = $field->name;
                    $sorters[] = $sorter;
                }
            } else if ($sorter->type) {
                if ($this->user->can_view_tags(null)
                    && ($tagger = new Tagger($this->user))
                    && ($tag = $tagger->check($sorter->type))
                    && $this->conf->fetch_ivalue("select exists (select * from PaperTag where tag=?)", $tag))
                    $this->search->warn("Unrecognized sort “" . htmlspecialchars($sorter->type) . "”. Did you mean “sort:#" . htmlspecialchars($sorter->type) . "”?");
                else
                    $this->search->warn("Unrecognized sort “" . htmlspecialchars($sorter->type) . "”.");
            }
        }
        if (empty($sorters)) {
            $sorters[] = PaperSearch::parse_sorter("id");
            $sorters[0]->field = $this->find_column("id");
        }
        $this->sorters = $sorters;

        // set defaults
        foreach ($this->sorters as $s) {
            if ($s->reverse === null)
                $s->reverse = false;
            if ($s->score === null)
                $s->score = ListSorter::default_score_sort($this->conf);
        }
    }

    private function _prepare_columns($field_list) {
        $field_list2 = [];
        $this->need_tag_attr = false;
        $this->table_attr = [];
        foreach ($field_list as $fdef) {
            if ($fdef) {
                $fdef->is_visible = !$this->is_folded($fdef);
                $fdef->has_content = false;
                if ($fdef->prepare($this, $fdef->is_visible ? 1 : 0)) {
                    $field_list2[] = $fdef->realize($this);
                }
            }
        }
        assert(empty($this->row_attr));
        return $field_list2;
    }

    function make_review_analysis($xrow, PaperInfo $row) {
        return new PaperListReviewAnalysis($xrow, $row);
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
        $any_empty = null;
        foreach ($fieldDef as $fdef)
            if ($fdef->viewable_column() && $fdef->has_statistics())
                $any_empty = $any_empty || $fdef->statistic($this, ScoreInfo::COUNT) != $this->count;
        if ($any_empty === null)
            return "";
        $t = '  <tr class="pl_statheadrow fx8">';
        if ($rstate->titlecol)
            $t .= "<td colspan=\"{$rstate->titlecol}\" class=\"plstat\"></td>";
        $t .= "<td colspan=\"" . ($rstate->ncol - $rstate->titlecol) . "\" class=\"plstat\">" . foldupbutton(7, "Statistics") . "</td></tr>\n";
        foreach (self::$stats as $stat) {
            $t .= '  <tr';
            if ($this->_row_id_pattern)
                $t .= " id=\"" . str_replace("#", "stat_" . ScoreInfo::$stat_keys[$stat], $this->_row_id_pattern) . "\"";
            $t .= ' class="pl_statrow fx7 fx8" data-statistic="' . ScoreInfo::$stat_keys[$stat] . '">';
            $col = 0;
            foreach ($fieldDef as $fdef) {
                if (!$fdef->viewable_column() || !$fdef->is_visible)
                    continue;
                $class = "plstat " . $fdef->className;
                if ($fdef->has_statistics())
                    $content = $fdef->statistic($this, $stat);
                else if ($col == $rstate->titlecol) {
                    $content = ScoreInfo::$stat_names[$stat];
                    $class = "plstat pl_statheader";
                } else
                    $content = "";
                $t .= '<td class="' . $class;
                if ($fdef->fold)
                    $t .= ' fx' . $fdef->fold;
                $t .= '">' . $content . '</td>';
                ++$col;
            }
            $t .= "</tr>\n";
        }
        return $t;
    }

    function ids_and_groups() {
        if (!$this->_prepare())
            return null;
        $field_list = $this->_columns("id", false);
        $rows = $this->_rows($field_list);
        if ($rows === null)
            return null;
        $this->count = count($this->ids);
        return [$this->ids, $this->groups];
    }

    function paper_ids() {
        $idh = $this->ids_and_groups();
        return $idh ? $idh[0] : null;
    }

    private function _listDescription() {
        switch ($this->report_id) {
        case "reviewAssignment":
            return "Review assignments";
        case "editpref":
            return "Review preferences";
        case "reviewers":
        case "reviewersSel":
            return "Proposed assignments";
        default:
            return null;
        }
    }

    function session_list_object() {
        assert($this->ids !== null);
        return $this->search->create_session_list_object($this->ids, $this->_listDescription(), $this->sortdef());
    }

    function table_render($report_id, $options = array()) {
        if (!$this->_prepare($report_id))
            return PaperListTableRender::make_error("Internal error");
        // need tags for row coloring
        if ($this->user->can_view_tags(null))
            $this->qopts["tags"] = true;

        // get column list
        if (isset($options["field_list"]))
            $field_list = $options["field_list"];
        else
            $field_list = $this->_list_columns();
        if (!$field_list)
            return PaperListTableRender::make_error("No matching report");

        // turn off forceShow
        $overrides = $this->user->remove_overrides(Contact::OVERRIDE_CONFLICT);

        // expand fields, check sort
        $field_list = $this->_columns($field_list, true);
        $rows = $this->_rows($field_list);

        if (empty($rows)) {
            $this->user->set_overrides($overrides);
            if ($rows === null)
                return null;
            if (($altq = $this->search->alternate_query())) {
                $altqh = htmlspecialchars($altq);
                $url = $this->search->url_site_relative_raw($altq);
                if (substr($url, 0, 5) == "search")
                    $altqh = "<a href=\"" . htmlspecialchars(Navigation::siteurl() . $url) . "\">" . $altqh . "</a>";
                return PaperListTableRender::make_error("No matching papers. Did you mean “{$altqh}”?");
            }
            return PaperListTableRender::make_error("No matching papers");
        }

        // get field array
        $fieldDef = array();
        $ncol = $titlecol = 0;
        // folds: au:1, anonau:2, fullrow:3, aufull:4, force:5, rownum:6, statistics:7,
        // statistics-exist:8, [fields]
        $next_fold = 9;
        foreach ($field_list as $fdef) {
            if ($fdef->viewable()) {
                $fieldDef[$fdef->name] = $fdef;
                if ($fdef->fold === true) {
                    $fdef->fold = $next_fold;
                    ++$next_fold;
                }
            }
            if ($fdef->name == "title")
                $titlecol = $ncol;
            if ($fdef->viewable_column() && $fdef->is_visible)
                ++$ncol;
        }

        // count non-callout columns
        $skipcallout = 0;
        foreach ($fieldDef as $fdef) {
            if ($fdef->position === null || $fdef->position >= 100)
                break;
            else
                ++$skipcallout;
        }

        // create render state
        $rstate = new PaperListTableRender($ncol, $titlecol, $skipcallout);

        // collect row data
        $body = array();
        $grouppos = empty($this->groups) ? -1 : 0;
        $need_render = false;
        foreach ($rows as $row) {
            $this->_row_setup($row);
            if ($grouppos >= 0)
                $grouppos = $this->_groups_for($grouppos, $rstate, $body, false);
            $body[] = $this->_row_content($rstate, $row, $fieldDef);
            if ($this->need_render && !$need_render) {
                Ht::stash_script('$(plinfo.render_needed)', 'plist_render_needed');
                $need_render = true;
            }
            if ($this->need_render && $this->count % 16 == 15) {
                $body[count($body) - 1] .= "  " . Ht::script('plinfo.render_needed()') . "\n";
                $this->need_render = false;
            }
        }
        if ($grouppos >= 0 && $grouppos < count($this->groups))
            $this->_groups_for($grouppos, $rstate, $body, true);
        if ($this->count === 0)
            return PaperListTableRender::make_error("No matching papers");

        // analyze `has`, including authors
        foreach ($fieldDef as $fdef)
            $this->mark_has($fdef->name, $fdef->has_content);

        // statistics rows
        $tfoot = "";
        if (!$this->_view_columns)
            $tfoot = $this->_statistics_rows($rstate, $fieldDef);

        // restore forceShow
        $this->user->set_overrides($overrides);

        // header cells
        $colhead = "";
        if (!defval($options, "noheader")) {
            $colhead .= " <thead class=\"pltable\">\n  <tr class=\"pl_headrow\">";
            $titleextra = $this->_make_title_header_extra($rstate, $fieldDef,
                                                          get($options, "header_links"));

            foreach ($fieldDef as $fdef) {
                if (!$fdef->viewable_column() || !$fdef->is_visible)
                    continue;
                if ($fdef->has_content) {
                    $colhead .= "<th class=\"pl plh " . $fdef->className;
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
                } else {
                    $colhead .= "<th";
                    if ($fdef->fold)
                        $colhead .= " class=\"fx{$fdef->fold}\"";
                    $colhead .= "></th>";
                }
            }

            $colhead .= "</tr>\n";

            if ($this->search->is_order_anno
                && isset($this->table_attr["data-drag-tag"])) {
                $drag_tag = $this->tagger->check($this->table_attr["data-drag-tag"]);
                if (strcasecmp($drag_tag, $this->search->is_order_anno) == 0
                    && $this->user->can_change_tag_anno($drag_tag)) {
                    $colhead .= "  <tr class=\"pl_headrow pl_annorow\" data-anno-tag=\"{$this->search->is_order_anno}\">";
                    if ($rstate->titlecol)
                        $colhead .= "<td class=\"plh\" colspan=\"$rstate->titlecol\"></td>";
                    $colhead .= "<td class=\"plh\" colspan=\"" . ($rstate->ncol - $rstate->titlecol) . "\"><a class=\"ui js-annotate-order\" href=\"\">Annotate order</a></td></tr>\n";
                }
            }

            $colhead .= " </thead>\n";
        }

        // table skeleton including fold classes
        $foldclasses = array();
        if ($this->foldable)
            $foldclasses = $this->_analyze_folds($rstate, $fieldDef);
        $enter = "<table class=\"pltable";
        if ($this->_table_class)
            $enter .= " " . $this->_table_class;
        if (get($options, "list"))
            $enter .= " has-hotlist has-fold";
        if (!empty($foldclasses))
            $enter .= " " . join(" ", $foldclasses);
        if ($this->_table_id)
            $enter .= "\" id=\"" . $this->_table_id;
        if (!empty($options["attributes"]))
            foreach ($options["attributes"] as $n => $v)
                $enter .= "\" $n=\"" . htmlspecialchars($v);
        if (get($options, "fold_session_prefix"))
            $enter .= "\" data-fold-session-prefix=\"" . htmlspecialchars($options["fold_session_prefix"]);
        if ($this->search->is_order_anno)
            $enter .= "\" data-order-tag=\"{$this->search->is_order_anno}";
        if ($this->groups)
            $enter .= "\" data-groups=\"" . htmlspecialchars(json_encode_browser($this->groups));
        foreach ($this->table_attr as $k => $v)
            $enter .= "\" $k=\"" . htmlspecialchars($v);
        if (get($options, "list"))
            $enter .= "\" data-hotlist=\"" . htmlspecialchars($this->session_list_object()->info_string());
        if ($this->sortable && ($url = $this->search->url_site_relative_raw())) {
            $url = Navigation::siteurl() . $url . (strpos($url, "?") ? "&" : "?") . "sort={sort}";
            $enter .= "\" data-sort-url-template=\"" . htmlspecialchars($url);
        }
        $enter .= "\">\n";
        if (self::$include_stash)
            $enter .= Ht::unstash();
        $rstate->table_start = $enter;
        $rstate->table_end = "</table>";

        // maybe make columns, maybe not
        if ($this->_view_columns && !empty($this->ids)
            && $this->_column_split($rstate, $colhead, $body)) {
            $rstate->table_start = '<div class="plsplit_col_ctr_ctr"><div class="plsplit_col_ctr">' . $rstate->table_start;
            $rstate->table_end .= "</div></div>";
            $ncol = $rstate->split_ncol;
            $rstate->tbody_class = "pltable_split";
        } else {
            $rstate->thead = $colhead;
            $rstate->tbody_class = "pltable" . ($rstate->hascolors ? " pltable_colored" : "");
        }
        if ($this->has_editable_tags)
            $rstate->tbody_class .= " need-editable-tags";

        // footer
        reset($fieldDef);
        if (current($fieldDef) instanceof SelectorPaperColumn
            && !get($options, "nofooter"))
            $tfoot .= $this->_footer($ncol, get_s($options, "footer_extra"));
        if ($tfoot)
            $rstate->tfoot = ' <tfoot class="pltable' . ($rstate->hascolors ? " pltable_colored" : "") . '">' . $tfoot . "</tfoot>\n";

        // header scripts to set up delegations
        if ($this->_header_script)
            $rstate->thead .= '  ' . Ht::script($this->_header_script) . "\n";

        $rstate->body_rows = $body;
        return $rstate;
    }

    function table_html($report_id, $options = array()) {
        $render = $this->table_render($report_id, $options);
        if ($render->error)
            return $render->error;
        else
            return $render->table_start
                . ($render->thead ? : "")
                . $render->tbody_start()
                . join("", $render->body_rows)
                . "  </tbody>\n"
                . ($render->tfoot ? : "")
                . "</table>";
    }

    function column_json($fieldId) {
        if (!$this->_prepare()
            || !($fdef = $this->find_column($fieldId)))
            return null;

        // field is never folded, no sorting
        $this->set_view($fdef->name, true);
        assert(!$this->is_folded($fdef));
        $this->sorters = [];

        // get rows
        $field_list = $this->_columns([$fdef->name], false);
        assert(count($field_list) === 1);
        $rows = $this->_rows($field_list);
        if ($rows === null)
            return null;
        $fdef = $field_list[0];

        // turn off forceShow
        $overrides = $this->user->remove_overrides(Contact::OVERRIDE_CONFLICT);

        // output field data
        $data = array();
        if (($x = $fdef->header($this, false)))
            $data["{$fdef->name}.headerhtml"] = $x;
        $m = array();
        foreach ($rows as $row) {
            $this->_row_setup($row);
            list($empty, $content) = $this->_row_field_content($fdef, $row);
            $m[$row->paperId] = $content;
            foreach ($this->row_attr as $k => $v) {
                if (!isset($data["attr.$k"]))
                    $data["attr.$k"] = [];
                $data["attr.$k"][$row->paperId] = $v;
            }
        }
        $data["{$fdef->name}.html"] = $m;

        // output statistics
        if ($fdef->has_statistics()) {
            $m = [];
            foreach (self::$stats as $stat)
                $m[ScoreInfo::$stat_keys[$stat]] = $fdef->statistic($this, $stat);
            $data["{$fdef->name}.stat.html"] = $m;
        }
        $this->mark_has($fdef->name, $fdef->has_content);

        // restore forceShow
        $this->user->set_overrides($overrides);
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
            $this->_row_setup($row);
            $p = array("id" => $row->paperId);
            foreach ($field_list as $fdef) {
                if ($fdef->viewable()
                    && !$fdef->content_empty($this, $row)
                    && ($text = $fdef->text($this, $row)) !== "")
                    $p[$fdef->name] = $text;
            }
            $x[$row->paperId] = (object) $p;
        }

        return $x;
    }

    private function _row_text_csv_data(PaperInfo $row, $fieldDef) {
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

    private function _groups_for_csv($grouppos, &$csv) {
        for (; $grouppos < count($this->groups)
               && $this->groups[$grouppos]->pos < $this->count;
               ++$grouppos) {
            $ginfo = $this->groups[$grouppos];
            $csv["__precomment__"] = $ginfo->is_empty() ? "none" : $ginfo->heading;
        }
        return $grouppos;
    }

    function text_csv($report_id, $options = array()) {
        if (!$this->_prepare($report_id))
            return null;

        // get column list, check sort
        if (isset($options["field_list"]))
            $field_list = $options["field_list"];
        else
            $field_list = $this->_list_columns();
        if (!$field_list)
            return null;
        $field_list = $this->_columns($field_list, true);
        $rows = $this->_rows($field_list);
        if ($rows === null || empty($rows))
            return null;

        // get field array
        $fieldDef = array();
        foreach ($field_list as $fdef)
            if ($fdef->viewable() && $fdef->is_visible
                && $fdef->header($this, true) != "")
                $fieldDef[] = $fdef;

        // collect row data
        $body = array();
        $grouppos = empty($this->groups) ? -1 : 0;
        foreach ($rows as $row) {
            $this->_row_setup($row);
            $csv = $this->_row_text_csv_data($row, $fieldDef);
            if ($grouppos >= 0)
                $grouppos = $this->_groups_for_csv($grouppos, $csv);
            $body[] = $csv;
        }

        // header cells
        $header = [];
        foreach ($fieldDef as $fdef)
            if ($fdef->has_content)
                $header[$fdef->name] = $fdef->header($this, true);

        return [$header, $body];
    }


    function display($report_id) {
        if (!($this->_prepare($report_id)
              && ($field_list = $this->_list_columns())))
            return false;
        $field_list = $this->_columns($field_list, false);
        $res = [];
        if ($this->_view_force)
            $res["-3 force"] = "show:force";
        if ($this->_view_compact_columns)
            $res["-2 ccol"] = "show:ccol";
        else if ($this->_view_columns)
            $res["-2 col"] = "show:col";
        if ($this->_view_row_numbers)
            $res["-1 rownum"] = "show:rownum";
        if ($this->_view_statistics)
            $res["-1 statistics"] = "show:statistics";
        $x = [];
        foreach ($this->_view_fields as $k => $v) {
            $f = $this->_expand_view_column($k, false);
            foreach ($f as $col)
                if ($v === "edit"
                    || ($v && ($col->fold || !$col->is_visible))
                    || (!$v && !$col->fold && $col->is_visible)) {
                    if ($v !== "edit")
                        $v = $v ? "show" : "hide";
                    $key = ($col->position ? : 0) . " " . $col->name;
                    $res[$key] = $v . ":" . PaperSearch::escape_word($col->name);
                }
        }
        $anonau = get($this->_view_fields, "anonau") && $this->conf->submission_blindness() == Conf::BLIND_OPTIONAL;
        $aufull = get($this->_view_fields, "aufull");
        if (($anonau || $aufull) && !get($this->_view_fields, "au"))
            $res["150 authors"] = "hide:authors";
        if ($anonau)
            $res["151 anonau"] = "show:anonau";
        if ($aufull)
            $res["151 aufull"] = "show:aufull";
        ksort($res, SORT_NATURAL);
        $res = array_values($res);
        foreach ($this->sorters as $s) {
            $w = "sort:" . ($s->reverse ? "-" : "") . PaperSearch::escape_word($s->field->sort_name($s->score));
            if ($w !== "sort:id")
                $res[] = $w;
        }
        return join(" ", $res);
    }
    static function change_display(Contact $user, $report, $var = null, $val = null) {
        $pl = new PaperList(new PaperSearch($user, "NONE"), ["report" => $report, "sort" => true]);
        if ($var)
            $pl->set_view($var, $val);
        $user->conf->save_session("{$report}display", $pl->display("s"));
    }
}
