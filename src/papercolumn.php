<?php
// papercolumn.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class PaperColumn extends Column {
    const OVERRIDE_NONE = 0;
    const OVERRIDE_FOLD_IFEMPTY = 1; // XXX backward compat
    const OVERRIDE_IFEMPTY = 1;
    const OVERRIDE_IFEMPTY_LINK = 2;
    const OVERRIDE_BOTH = 3;
    const OVERRIDE_FORCE = 4;
    const OVERRIDE_NONCONFLICTED = 16;
    /** @var int */
    public $override = 0;

    /** @deprecated */
    const PREP_CHECK = 0;
    /** @deprecated */
    const PREP_SORT = 1;
    /** @deprecated */
    const PREP_VISIBLE = 2;

    /** @param object $cj */
    function __construct(Conf $conf, $cj) {
        parent::__construct($cj);
    }

    /** @return PaperColumn */
    static function make(Conf $conf, $cj) {
        if ($cj->function[0] === "+") {
            $class = substr($cj->function, 1);
            /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName */
            return new $class($conf, $cj);
        }
        return call_user_func($cj->function, $conf, $cj);
    }

    /** @param XtParams $ctx
     * @param string $name
     * @param string|MessageItem|list<MessageItem> $message */
    static function column_error_at($ctx, $name, $message) {
        if ($ctx instanceof XtParams && $ctx->paper_list) {
            $ml = is_string($message) ? MessageItem::warning($message) : $message;
            $ctx->paper_list->column_error_at($name, $ml);
        }
    }

    /** @param Contact|XtParams $ctx
     * @param string|MessageItem|list<MessageItem> $message
     * @deprecated */
    static function column_error($ctx, $message) {
        error_log(debug_string_backtrace());
    }


    /** @param int $visible */
    function prepare(PaperList $pl, $visible) {
        return true;
    }
    function field_json(PaperList $pl) {
        //assert($this->is_visible);
        $j = [
            "name" => $this->name,
            "title" => $this->header($pl, false)
        ];
        if ($this->order !== null) {
            $j["order"] = $this->order;
        }
        if ($this->className !== "pl_" . $this->name) {
            $j["className"] = $this->className;
        }
        if ($this->as_row) {
            $j["as_row"] = true;
        } else if ($this->has_statistics()) {
            $j["has_statistics"] = true;
        }
        if ($this->sort) {
            $j["sort"] = $this->default_sort_descending() ? "descending" : "ascending";
            if (($sn = $this->sort_name()) !== $this->name) {
                $j["sort_name"] = $sn;
            }
        }
        if (($vol = $this->view_options())) {
            foreach ($vol as $n => $v) {
                $j["view_options"][] = ViewOptionList::unparse_pair($n, $v);
            }
        }
        return $j;
    }

    /** @param int $sortindex */
    function prepare_sort(PaperList $pl, $sortindex) {
    }
    /** @return int */
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        error_log("unexpected " . get_class($this) . "::compare");
        return $a->paperId <=> $b->paperId;
    }

    function reset(PaperList $pl) {
    }

    /** @param bool $is_text
     * @return string */
    function header(PaperList $pl, $is_text) {
        if (isset($this->title_html) && !$is_text) {
            return $this->title_html;
        }
        $t = $this->title ?? "<{$this->name}>";
        return $is_text ? $t : htmlspecialchars($t);
    }
    /** @return string */
    function sort_name() {
        return $this->name;
    }
    /** @param string ...$keys
     * @return string */
    final function sort_name_with_options(...$keys) {
        $a = [$this->name];
        foreach ($keys as $k) {
            if (($v = $this->view_option($k)))
                $a[] = ViewOptionList::unparse_pair($k, $v);
        }
        return join(" ", $a);
    }
    /** @return string */
    final function full_sort_name() {
        $sn = $this->sort_name();
        $sd = $this->sort_option();
        return $sd ? "{$sn} {$sd}" : $sn;
    }

    /** @return list<string> */
    static function user_view_option_schema() {
        return ["format=given_name,first|family_name,last^"];
    }
    /** @return int */
    function user_view_option_name_flags(Conf $conf) {
        if (($format = $this->view_option("format")) !== null) {
            return $format === "given_name" ? 0 : NAME_L;
        }
        return $conf->sort_by_last ? NAME_L : 0;
    }

    /** @return bool */
    function content_empty(PaperList $pl, PaperInfo $row) {
        return false;
    }
    /** @return string */
    function content(PaperList $pl, PaperInfo $row) {
        return "";
    }
    /** @return string */
    function text(PaperList $pl, PaperInfo $row) {
        return "";
    }
    /** @return mixed */
    function json(PaperList $pl, PaperInfo $row) {
        return $this->text($pl, $row);
    }

    /** @return bool */
    function has_statistics() {
        return false;
    }
    /** @return ?ScoreInfo */
    function statistics() {
        return null;
    }
}

class Id_PaperColumn extends PaperColumn {
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        return $a->paperId <=> $b->paperId;
    }
    function content(PaperList $pl, PaperInfo $row) {
        $href = $pl->_paperLink($row);
        return "<a href=\"{$href}\" class=\"pnum taghl\">#{$row->paperId}</a>";
    }
    function text(PaperList $pl, PaperInfo $row) {
        return (string) $row->paperId;
    }
    function json(PaperList $pl, PaperInfo $row) {
        return $row->paperId;
    }
}

class Selector_PaperColumn extends PaperColumn {
    /** @var bool */
    private $selectall = false;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function view_option_schema() {
        return ["selected"];
    }
    function prepare(PaperList $pl, $visible) {
        $this->selectall = $this->view_option("selected") ?? false;
        return true;
    }
    function header(PaperList $pl, $is_text) {
        if ($is_text) {
            return "Selected";
        } else if ($pl->viewing("facets")) {
            return "";
        }
        return '<input type="checkbox" class="uic js-range-click is-range-group ignore-diff" data-range-type="pap[]" aria-label="Select all">';
    }
    protected function checked(PaperList $pl, PaperInfo $row) {
        return $pl->is_selected($row->paperId, $this->selectall);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $pl->mark_has("sel");
        $c = $this->checked($pl, $row) ? " checked" : "";
        $n = $pl->long_mode ? "data-range-type" : "name";
        return "<span class=\"pl_rownum fx6\">{$pl->count}. </span><input type=\"checkbox\" class=\"uic uikd js-range-click js-selector ignore-diff\" {$n}=\"pap[]\" value=\"{$row->paperId}\"{$c} aria-label=\"#{$row->paperId}\">";
    }
    static function group_content($groupno) {
        // See also `tagannorow_add` in script.js
        return "<input type=\"checkbox\" class=\"uic uikd js-range-click ignore-diff is-range-group\" data-range-type=\"pap[]\" data-range-group=\"auto\" aria-label=\"Select group\">";
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $this->checked($pl, $row) ? "Y" : "N";
    }
    function json(PaperList $pl, PaperInfo $row) {
        return $this->checked($pl, $row);
    }
}

class Title_PaperColumn extends PaperColumn {
    /** @var bool */
    private $want_decoration = true;
    /** @var bool */
    private $want_pdf = true;
    /** @var bool */
    private $highlight;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function view_option_schema() {
        return ["plain", "bare/plain"];
    }
    function prepare(PaperList $pl, $visible) {
        if ($this->view_option("plain")) {
            $this->want_decoration = $this->want_pdf = false;
        } else {
            $this->want_decoration = $pl->user->can_view_tags(null)
                && $pl->conf->tags()->has(TagInfo::TFM_DECORATION);
        }
        if ($this->want_decoration) {
            $pl->qopts["tags"] = 1;
        }
        if ($this->want_pdf
            && !$pl->user->can_view_some_option($pl->conf->option_by_id(DTYPE_SUBMISSION))
            && !$pl->user->can_view_some_option($pl->conf->option_by_id(DTYPE_FINAL))) {
            $this->want_pdf = false;
        }
        $this->highlight = $pl->search->has_field_highlighter("ti");
        return true;
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $collator = $a->conf->collator();
        return $collator->compare($a->title(), $b->title());
    }
    function content(PaperList $pl, PaperInfo $row) {
        $title = $row->title();
        if ($title !== "") {
            $regex = $this->highlight ? $pl->search->field_highlighter("ti", $row->_search_group) : null;
            $highlight_text = Text::highlight($title, $regex, $highlight_count);
        } else {
            $highlight_text = "[No title]";
            $highlight_count = 0;
        }

        if (!$highlight_count && ($format = $row->title_format())) {
            $pl->need_render = true;
            $th = htmlspecialchars($title);
            $klass_extra = " need-format\" data-format=\"{$format}\" data-title=\"{$th}";
        } else {
            $klass_extra = "";
        }

        $link = $pl->_paperLink($row);
        $t = "<a href=\"{$link}\" class=\"ptitle taghl{$klass_extra}\">{$highlight_text}</a>";
        if ($this->want_pdf) {
            $dtype = $row->finalPaperStorageId > 0 ? DTYPE_FINAL : DTYPE_SUBMISSION;
            if (($dtype === DTYPE_FINAL ? $row->finalPaperStorageId : $row->paperStorageId) > 1
                && $pl->user->can_view_option($row, $pl->conf->option_by_id($dtype))
                && ($doc = $row->document($dtype))) {
                $t .= " " . $doc->link_html("", DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE | DocumentInfo::L_FINALTITLE);
            }
        }
        if ($this->want_decoration
            && ($pl->row_tags !== "" || $pl->row_tags_override !== "")) {
            $t .= $row->decoration_html($pl->user, $pl->row_tags, $pl->row_tags_override);
        }
        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->title();
    }
}

class Status_PaperColumn extends PaperColumn {
    /** @var bool */
    private $show_submitted;
    /** @var array<int,float> */
    private $sortmap;
    /** @var bool */
    private $status_analyzed = false;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_BOTH;
    }
    function prepare(PaperList $pl, $visible) {
        $this->show_submitted = $pl->search->show_submitted_status();
        return true;
    }
    function prepare_sort(PaperList $pl, $sortindex) {
        $this->sortmap = [];
        foreach ($pl->rowset() as $row) {
            $this->sortmap[$row->paperXid] = $row->viewable_decision($pl->user)->order ? : PHP_INT_MAX;
        }
    }
    function reset(PaperList $pl) {
        if ($this->status_analyzed) {
            return;
        }
        $this->status_analyzed = true;
        foreach ($pl->rowset() as $row) {
            if ($row->outcome !== 0 || $row->paperStorageId <= 1) {
                list($class, $name) = $row->status_class_and_name($pl->user);
                if (strlen($name) > 10 && strpos($name, " ") !== false) {
                    $this->className .= " pl-status-long";
                    break;
                }
            }
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $x = $this->sortmap[$a->paperXid] <=> $this->sortmap[$b->paperXid];
        $x = $x ? : ($a->timeWithdrawn > 0 ? 1 : 0) - ($b->timeWithdrawn > 0 ? 1 : 0);
        $x = $x ? : ($b->timeSubmitted > 0 ? 1 : 0) - ($a->timeSubmitted > 0 ? 1 : 0);
        return $x ? : ($b->paperStorageId > 1 ? 1 : 0) - ($a->paperStorageId > 1 ? 1 : 0);
    }
    function content(PaperList $pl, PaperInfo $row) {
        list($class, $name) = $row->status_class_and_name($pl->user);
        if (!$this->show_submitted && $class === "ps-submitted") {
            return "";
        }
        return "<span class=\"pstat {$class}\">" . htmlspecialchars($name) . "</span>";
    }
    function text(PaperList $pl, PaperInfo $row) {
        list($class, $name) = $row->status_class_and_name($pl->user);
        return $name;
    }
}

class ReviewStatus_PaperColumn extends PaperColumn {
    private $round;
    /** @var array<int,int|float> */
    private $sortmap;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_BOTH;
        $this->round = $cj->round ?? null;
    }
    function prepare(PaperList $pl, $visible) {
        if ($pl->user->privChair
            || $pl->user->is_reviewer()
            || $pl->conf->time_some_author_view_review()) {
            $pl->qopts["reviewSignatures"] = true;
            return true;
        } else {
            return false;
        }
    }
    private function data(PaperInfo $row, Contact $user) {
        $want_assigned = !$row->has_conflict($user) || $user->is_admin($row);
        $done = $started = 0;
        foreach ($row->all_reviews() as $rrow) {
            if ($user->can_view_review_assignment($row, $rrow)
                && ($this->round === null || $this->round === $rrow->reviewRound)) {
                if ($rrow->reviewStatus >= ReviewInfo::RS_COMPLETED) {
                    ++$done;
                    ++$started;
                } else if (($want_assigned
                            ? $rrow->reviewNeedsSubmit > 0
                            : $rrow->reviewStatus > 0)
                           && ($rrow->reviewType != REVIEW_EXTERNAL
                               || $row->conf->ext_subreviews < 2)) {
                    ++$started;
                }
            }
        }
        return [$done, $started];
    }
    function prepare_sort(PaperList $pl, $sortindex) {
        $this->sortmap = [];
        foreach ($pl->rowset() as $row) {
            if (!$pl->user->can_view_review_assignment($row, null)) {
                $this->sortmap[$row->paperXid] = -2147483647.0;
            } else {
                list($done, $started) = $this->data($row, $pl->user);
                $this->sortmap[$row->paperXid] = $done + $started / 1000.0;
            }
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        return $this->sortmap[$a->paperXid] <=> $this->sortmap[$b->paperXid];
    }
    function header(PaperList $pl, $is_text) {
        $round_name = "";
        if ($this->round !== null) {
            $round_name = ($pl->conf->round_name($this->round) ? : "unnamed") . " ";
        }
        if ($is_text) {
            return "# {$round_name}Reviews";
        }
        $class = $round_name === "" ? " nw" : "";
        return "<span class=\"need-tooltip{$class}\" aria-label=\"# completed reviews / # assigned reviews\" data-tooltip-anchor=\"s\"># {$round_name}Reviews</span>";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_review_assignment($row, null);
    }
    function content(PaperList $pl, PaperInfo $row) {
        list($done, $started) = $this->data($row, $pl->user);
        return "<b>{$done}</b>" . ($done == $started ? "" : "/{$started}");
    }
    function text(PaperList $pl, PaperInfo $row) {
        list($done, $started) = $this->data($row, $pl->user);
        return $done . ($done == $started ? "" : "/{$started}");
    }
}

class Authors_PaperColumn extends PaperColumn {
    /** @var bool
     * @readonly */
    public $full;
    /** @var bool
     * @readonly */
    public $anon;
    /** @var bool */
    private $highlight;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function view_option_schema() {
        return ["anon", "full", "short/!full"];
    }
    /** @suppress PhanAccessReadOnlyProperty */
    function prepare(PaperList $pl, $visible) {
        $this->full = $this->view_option("full") ?? $pl->viewing("aufull");
        $this->anon = $this->view_option("anon") ?? $pl->viewing("anonau");
        $this->highlight = $pl->search->has_field_highlighter("au");
        return $pl->user->can_view_some_authors();
    }
    function field_json(PaperList $pl) {
        $j = parent::field_json($pl);
        $j["full"] = $this->full;
        $j["anon"] = $this->anon;
        return $j;
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $au1 = $pl->user->allow_view_authors($a) ? $a->author_list() : [];
        $au2 = $pl->user->allow_view_authors($b) ? $b->author_list() : [];
        if (empty($au1) || empty($au2)) {
            return (int) empty($au1) <=> (int) empty($au2);
        }
        $sortspec = $a->conf->sort_by_last ? Contact::SORTSPEC_LAST : Contact::SORTSPEC_FIRST;
        for ($i = 0; $i < count($au1) && $i < count($au2); ++$i) {
            $s1 = Contact::make_sorter($au1[$i], $sortspec);
            $s2 = Contact::make_sorter($au2[$i], $sortspec);
            if (($v = strnatcasecmp($s1, $s2)) !== 0) {
                return $v;
            }
        }
        return count($au1) <=> count($au2);
    }
    private function affiliation_map($row) {
        $nonempty_count = 0;
        $aff = [];
        '@phan-var list<string> $aff';
        foreach ($row->author_list() as $i => $au) {
            if ($i !== 0 && $au->affiliation === $aff[$i - 1]) {
                $aff[$i - 1] = null;
            }
            $aff[] = $au->affiliation;
            $nonempty_count += ($au->affiliation !== "");
        }
        if ($nonempty_count != 0 && $nonempty_count != count($aff)) {
            foreach ($aff as &$affx) {
                if ($affx === "") {
                    $affx = "unaffiliated";
                }
            }
        }
        return $aff;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->allow_view_authors($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $out = [];
        if (!$this->highlight && !$this->full) {
            foreach ($row->author_list() as $au) {
                $out[] = $au->name_h(NAME_P|NAME_I);
            }
            $t = join(", ", $out);
        } else {
            $affmap = $this->affiliation_map($row);
            $aus = $affout = [];
            $any_affhl = false;
            $regex = $this->highlight ? $pl->search->field_highlighter("au", $row->_search_group) : null;
            foreach ($row->author_list() as $i => $au) {
                $name = Text::highlight($au->name(), $regex, $didhl);
                if (!$this->full
                    && ($first = htmlspecialchars($au->firstName))
                    && (!$didhl || substr($name, 0, strlen($first)) === $first)
                    && ($initial = Text::initial($first)) !== "") {
                    $name = $initial . substr($name, strlen($first));
                }
                $aus[] = $name;
                if ($affmap[$i] !== null) {
                    $out[] = join(", ", $aus);
                    $affout[] = Text::highlight($affmap[$i], $regex, $didhl);
                    $any_affhl = $any_affhl || $didhl;
                    $aus = [];
                }
            }
            // $affout[0] === "" iff there are no nonempty affiliations
            if (($any_affhl || $this->full)
                && !empty($out)
                && $affout[0] !== "") {
                foreach ($out as $i => &$x) {
                    $x .= ' <span class="auaff">(' . $affout[$i] . ')</span>';
                }
            }
            $t = join($any_affhl || $this->full ? "; " : ", ", $out);
        }
        if ($pl->conf->submission_blindness() !== Conf::BLIND_NEVER
            && !$pl->user->can_view_authors($row)) {
            $pl->column_class = Ht::add_tokens($pl->column_class, "fx2");
        }
        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        if (!$pl->user->can_view_authors($row) && !$this->anon) {
            return "";
        }
        $out = [];
        if (!$this->full) {
            foreach ($row->author_list() as $au) {
                $out[] = $au->name(NAME_P|NAME_I);
            }
            return join(", ", $out);
        } else {
            $affmap = $this->affiliation_map($row);
            $aus = [];
            foreach ($row->author_list() as $i => $au) {
                $aus[] = $au->name();
                if ($affmap[$i] !== null) {
                    $aff = ($affmap[$i] !== "" ? " ({$affmap[$i]})" : "");
                    $out[] = join(", ", $aus) . $aff;
                    $aus = [];
                }
            }
            return join("; ", $out);
        }
    }
    function json(PaperList $pl, PaperInfo $row) {
        $au = [];
        if ($pl->user->can_view_authors($row) || $this->anon) {
            foreach ($row->author_list() as $auth) {
                $au[] = $auth->unparse_nea_json();
            }
        }
        return $au;
    }
}

class Collab_PaperColumn extends PaperColumn {
    /** @var bool */
    private $highlight;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
    }
    function prepare(PaperList $pl, $visible) {
        $this->highlight = $pl->search->has_field_highlighter("co");
        return !!$pl->conf->setting("sub_collab") && $pl->user->can_view_some_authors();
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$row->has_nonempty_collaborators()
            || !$pl->user->allow_view_authors($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $x = "";
        foreach (explode("\n", $row->collaborators()) as $c) {
            if ($c !== "") {
                $x .= ($x === "" ? "" : "; ") . trim($c);
            }
        }
        $regex = $this->highlight ? $pl->search->field_highlighter("co", $row->_search_group) : null;
        return Text::highlight($x, $regex);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $x = "";
        foreach (explode("\n", $row->collaborators()) as $c) {
            $x .= ($x === "" ? "" : ", ") . trim($c);
        }
        return $x;
    }
}

class Abstract_PaperColumn extends PaperColumn {
    /** @var bool */
    private $highlight;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function prepare(PaperList $pl, $visible) {
        $this->highlight = $pl->search->has_field_highlighter("ab");
        return true;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return $row->abstract() === "";
    }
    function content(PaperList $pl, PaperInfo $row) {
        $ab = $row->abstract();
        $regex = $this->highlight ? $pl->search->field_highlighter("ab", $row->_search_group) : null;
        $t = Text::highlight($ab, $regex, $highlight_count);
        $klass = strlen($t) > 190 ? "pl_longtext" : "pl_shorttext";
        if (!$highlight_count && ($format = $row->abstract_format())) {
            $pl->need_render = true;
            $t = "<div class=\"{$klass} need-format\" data-format=\"{$format}\">{$t}</div>";
        } else {
            $t = Ht::format0_html($t);
            $t = "<div class=\"{$klass} format0\">{$t}</div>";
        }
        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->abstract();
    }
}

class ReviewerType_PaperColumn extends PaperColumn {
    /** @var Contact */
    private $user;
    /** @var bool */
    private $not_me;
    /** @var bool */
    private $simple = false;
    /** @var bool */
    private $description;
    /** @var array<int,int> */
    private $sortmap;

    // bits 0-4 are for conflicts
    // bit  5   is for no conflict
    // bits 6-9 are for reviews
    // bit  10  is for lead
    // bit  11  is for shepherd
    const FM_CONFLICT = 30;
    const F_CONFLICT = 2;
    const F_NOCONFLICT = 32;
    const FM_REVIEW = 0x3C0;
    const FS_REVIEWTYPE = 7;
    const F_REVIEWCOMPLETE = 0x40;
    const F_LEAD = 0x400;
    const F_SHEPHERD = 0x800;

    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        if (isset($cj->user)) {
            $this->user = $conf->pc_member_by_email($cj->user);
        }
        assert(self::FM_CONFLICT === Conflict::FM_PCTYPE);
    }
    function view_option_schema() {
        return ["simple", "description", "desc/description", "confdesc/description", "user$"];
    }
    function prepare(PaperList $pl, $visible) {
        $this->simple = $this->view_option("simple") ?? false;
        if (($utext = $this->view_option("user"))
            && !($this->user = ContactSearch::make_pc($utext, $pl->user)->user1())) {
            return false;
        } else if (!$this->user) {
            $this->user = $pl->reviewer_user();
        }
        $this->not_me = $this->user->contactXid !== $pl->user->contactXid;
        $this->description = $pl->conf->setting("sub_pcconfsel")
            && ($this->view_option("description") ?? false);
        return true;
    }
    /** @return Contact */
    function contact() {
        return $this->user;
    }
    /** @return array{?PaperListReviewAnalysis,int} */
    private function analysis(PaperList $pl, PaperInfo $row) {
        $rrow = $row->review_by_user($this->user);
        if ($rrow
            && ($this->not_me
                ? $pl->user->can_view_review_identity($row, $rrow)
                : !$rrow->is_ghost())) {
            $ranal = $pl->make_review_analysis($rrow, $row);
        } else {
            $ranal = null;
        }
        if ($ranal && $ranal->rrow->reviewStatus < ReviewInfo::RS_DELIVERED) {
            $pl->mark_has("need_review");
        }
        if (($ct = $row->conflict_type($this->user)) > CONFLICT_MAXUNCONFLICTED
            && (!$this->not_me || $pl->user->can_view_conflicts($row))) {
            $flags = $this->description ? $ct & self::FM_CONFLICT : self::F_CONFLICT;
        } else {
            $flags = self::F_NOCONFLICT;
        }
        if ($ranal && $ranal->rrow->reviewType) {
            $flags |= $ranal->rrow->reviewType << self::FS_REVIEWTYPE;
            if ($ranal->rrow->reviewStatus >= ReviewInfo::RS_COMPLETED) {
                $flags |= self::F_REVIEWCOMPLETE;
            }
        }
        if ($row->leadContactId === $this->user->contactXid
            && (!$this->not_me || $pl->user->can_view_lead($row))) {
            $flags |= self::F_LEAD;
        }
        if ($row->shepherdContactId === $this->user->contactXid
            && (!$this->not_me || $pl->user->can_view_shepherd($row))) {
            $flags |= self::F_SHEPHERD;
        }
        return [$ranal, $flags];
    }
    function prepare_sort(PaperList $pl, $sortindex) {
        $this->sortmap = [];
        foreach ($pl->rowset() as $row) {
            list($ranal, $flags) = $this->analysis($pl, $row);
            $this->sortmap[$row->paperXid] = $flags;
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        return $this->sortmap[$a->paperXid] <=> $this->sortmap[$b->paperXid];
    }
    function header(PaperList $pl, $is_text) {
        if (!$this->not_me || $this->simple) {
            return "Review";
        } else if ($is_text) {
            return $pl->user->reviewer_text_for($this->user) . " review";
        }
        return $pl->user->reviewer_html_for($this->user) . "<br>review";
    }
    function content(PaperList $pl, PaperInfo $row) {
        list($ranal, $flags) = $this->analysis($pl, $row);
        $x = [];
        $t = "";
        if ($ranal) {
            $t = $ranal->icon_html(true);
        } else if (($flags & self::F_CONFLICT) !== 0
                   && $pl->search->limit() !== "a") {
            $t = review_type_icon(-1);
            if ($this->description) {
                $t .= " " . $pl->conf->conflict_set()->unparse_html($flags & self::FM_CONFLICT);
            }
        }
        if ($flags & self::F_LEAD) {
            $x[] = review_lead_icon();
        }
        if ($flags & self::F_SHEPHERD) {
            $x[] = review_shepherd_icon();
        }
        $hasround = $ranal && $ranal->rrow->reviewRound > 0;
        if (empty($x) && !$hasround) {
            return $t;
        }
        $c = ["pl_revtype"];
        if ($t) {
            $c[] = "hasrev";
            $x[] = $t;
        }
        if (($flags & (self::F_LEAD | self::F_SHEPHERD)) !== 0) {
            $c[] = "haslead";
        }
        if ($hasround) {
            $c[] = "hasround";
        }
        return '<div class="' . join(" ", $c) . '">' . join(" ", $x) . '</div>';
    }
    function text(PaperList $pl, PaperInfo $row) {
        list($ranal, $flags) = $this->analysis($pl, $row);
        $t = [];
        if ($flags & self::F_LEAD) {
            $t[] = "Lead";
        }
        if ($flags & self::F_SHEPHERD) {
            $t[] = "Shepherd";
        }
        if ($ranal) {
            $t[] = $ranal->icon_text();
        }
        if ($flags & self::F_CONFLICT) {
            $t[] = "Conflict";
        }
        return empty($t) ? "" : join("; ", $t);
    }
}

class TagList_PaperColumn extends PaperColumn {
    private $editable;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_FORCE;
    }
    function view_option_schema() {
        return ["edit"];
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_tags(null)) {
            return false;
        }
        $pl->qopts["tags"] = 1;
        $this->editable = $this->view_option("edit") ?? false;
        if ($this->editable) {
            $pl->has_editable_tags = true;
        }
        return true;
    }
    function field_json(PaperList $pl) {
        $j = parent::field_json($pl);
        $j["highlight_tags"] = $pl->search->highlight_tags();
        if ($pl->conf->tags()->has(TagInfo::TFM_VOTES)) {
            $j["votish_tags"] = array_values(array_map(function ($t) { return $t->tag; }, $pl->conf->tags()->sorted_entries_having(TagInfo::TFM_VOTES)));
        }
        return $j;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_tags($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        if ($this->editable) {
            $pl->row_attr["data-tags-editable"] = 1;
        }
        if ($this->editable || $pl->row_tags !== "" || $pl->row_tags_override !== "") {
            $pl->need_render = true;
            return '<span class="need-tags"></span>';
        } else {
            return "";
        }
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->tagger->unparse_hashed($row->sorted_viewable_tags($pl->user));
    }
}

abstract class ScoreGraph_PaperColumn extends PaperColumn {
    /** @var int */
    protected $cid;
    /** @var string */
    protected $score_sort;
    /** @var Discrete_ReviewField */
    protected $format_field;
    /** @var array<int,null|int|float|list<int>> */
    private $sortmap;
    /** @var array<int,float> */
    private $avgmap;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function view_option_schema() {
        return ["scoresort=" . ScoreInfo::$score_sort_enum];
    }
    function prepare(PaperList $pl, $visible) {
        $ruser = $pl->reviewer_user();
        $this->cid = $ruser->contactId;
        if (($v = $this->view_option("scoresort")) !== null) {
            $this->score_sort = ScoreInfo::parse_score_sort($v);
        }
        if ($this->cid !== $pl->user->contactId
            && (!$pl->user->privChair || $pl->conf->has_any_manager())) {
            $pl->qopts["reviewSignatures"] = true;
        }
    }
    /** @return ScoreInfo */
    abstract function score_info(PaperList $pl, PaperInfo $row);
    function prepare_sort(PaperList $pl, $sortindex) {
        $ss = $this->score_sort ?? $pl->score_sort();
        $this->sortmap = $this->avgmap = [];
        foreach ($pl->rowset() as $row) {
            $sci = $this->score_info($pl, $row);
            if (!$sci->is_empty()) {
                $this->sortmap[$row->paperXid] = $sci->sort_data($ss);
                $this->avgmap[$row->paperXid] = $sci->mean();
            }
        }
    }
    function sort_name() {
        return $this->sort_name_with_options("scoresort");
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $x = ScoreInfo::compare($this->sortmap[$a->paperXid] ?? null, $this->sortmap[$b->paperXid] ?? null, -1);
        return $x ? : ScoreInfo::compare($this->avgmap[$a->paperXid] ?? null, $this->avgmap[$b->paperXid] ?? null);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $sci = $this->score_info($pl, $row);
        return $this->format_field->unparse_graph($sci, Discrete_ReviewField::GRAPH_STACK);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $si = $this->score_info($pl, $row);
        $values = array_map([$this->format_field, "unparse_value"], $si->as_sorted_list());
        return join(" ", array_values($values));
    }
}

class Score_PaperColumn extends ScoreGraph_PaperColumn {
    /** @var bool */
    private $any_review;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
        $this->format_field = $conf->checked_review_field($cj->review_field_id);
        assert($this->format_field instanceof Discrete_ReviewField);
    }
    function view_option_schema() {
        return make_array("anyre", "anyreview/anyre", "any/anyre", ...parent::view_option_schema());
    }
    function prepare(PaperList $pl, $visible) {
        $bound = $pl->user->permissive_view_score_bound($pl->search->limit_term()->is_author());
        if ($this->format_field->view_score <= $bound) {
            return false;
        }
        if (!in_array($this->format_field, $pl->qopts["scores"] ?? [], true)) {
            $pl->qopts["scores"][] = $this->format_field;
        }
        $this->any_review = !!$this->view_option("anyre");
        parent::prepare($pl, $visible);
        return true;
    }
    function score_info(PaperList $pl, PaperInfo $row) {
        $f = $this->format_field;
        $vs = $f->view_score;
        $row->ensure_review_field_order($f->order);
        $sci = new ScoreInfo;
        foreach ($row->viewable_reviews_as_display($pl->user) as $rrow) {
            if (($this->any_review || $rrow->reviewSubmitted)
                && ($fv = $rrow->fval($f)) !== null
                && $f->view_score > $pl->user->view_score_bound($row, $rrow)) {
                $sci->add($fv);
                if ($rrow->contactId === $this->cid
                    && $pl->user->can_view_review_identity($row, $rrow)) {
                    $sci->set_my_score($fv);
                }
            }
        }
        return $sci;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        // Do not use score_info to determine content emptiness, since
        // that would load the scores from the DB -- even for folded score
        // columns.
        return !$row->may_have_viewable_scores($this->format_field, $pl->user);
    }

    /** @return array<ReviewField> */
    static function user_viewable_fields($name, Contact $user) {
        if ($name === "scores") {
            $fs = $user->conf->all_review_fields();
        } else {
            $fs = [$user->conf->find_review_field($name)];
        }
        $vsbound = $user->permissive_view_score_bound();
        return array_filter($fs, function ($f) use ($vsbound) {
            return $f
                && $f instanceof Discrete_ReviewField
                && $f->order > 0
                && $f->view_score > $vsbound;
        });
    }
    /** @return array<ReviewField> */
    static function expand($name, XtParams $xtp, $xfj, $m) {
        return array_map(function ($f) use ($xfj) {
            $cj = (array) $xfj;
            $cj["name"] = $f->search_keyword();
            $cj["review_field_id"] = $f->short_id;
            $cj["title"] = $f->search_keyword();
            $cj["title_html"] = $f->web_abbreviation();
            $cj["order"] = $xfj->order + $f->order;
            return (object) $cj;
        }, self::user_viewable_fields($name, $xtp->user));
    }
    static function examples(Contact $user, $xfj) {
        if (!$user->can_view_some_review()) {
            return [];
        }
        $exs = [];
        foreach (self::user_viewable_fields("scores", $user) as $f) {
            $exs[] = new SearchExample($f->search_keyword(), "<0>Graph of " . $f->name . " scores");
        }
        if (!empty($exs)) {
            $exs[] = new SearchExample("scores", "<0>All score graphs");
        }
        return $exs;
    }
}
