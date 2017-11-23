<?php
// papercolumn.php -- HotCRP helper classes for paper list content
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperColumn extends Column {
    const OVERRIDE_NONE = 0;
    const OVERRIDE_FOLD = 1;
    const OVERRIDE_FOLD_BOTH = 2;
    const OVERRIDE_ALWAYS = 3;
    public $override = 0;

    const PREP_SORT = -1;
    const PREP_FOLDED = 0; // value matters
    const PREP_VISIBLE = 1; // value matters

    function __construct($cj) {
        parent::__construct($cj);
    }

    static function make($cj, Conf $conf) {
        if (($factory_class = get($cj, "factory_class")))
            return new $factory_class($cj, $conf);
        else if (($factory = get($cj, "factory")))
            return call_user_func($factory, $cj, $conf);
        else
            return null;
    }


    function make_editable(PaperList $pl) {
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

    function analyze(PaperList $pl, &$rows, $fields) {
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        error_log("unexpected compare " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        return $a->paperId - $b->paperId;
    }

    function header(PaperList $pl, $is_text) {
        if ($is_text)
            return "<" . $this->name . ">";
        else
            return "&lt;" . htmlspecialchars($this->name) . "&gt;";
    }
    function completion_name() {
        if (!$this->completion)
            return false;
        else if (is_string($this->completion))
            return $this->completion;
        else
            return $this->name;
    }
    function sort_name($score_sort) {
        return $this->name;
    }
    function alternate_display_name() {
        return false;
    }

    function content_empty(PaperList $pl, PaperInfo $row) {
        return false;
    }

    function content(PaperList $pl, PaperInfo $row) {
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
    function __construct($cj) {
        parent::__construct($cj);
    }
    function header(PaperList $pl, $is_text) {
        return "ID";
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        return $a->paperId - $b->paperId;
    }
    function content(PaperList $pl, PaperInfo $row) {
        $href = $pl->_paperLink($row);
        return "<a href=\"$href\" class=\"pnum taghl\" tabindex=\"4\">#$row->paperId</a>";
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->paperId;
    }
}

class SelectorPaperColumn extends PaperColumn {
    public $is_selector = true;
    function __construct($cj) {
        parent::__construct($cj);
    }
    function header(PaperList $pl, $is_text) {
        return $is_text ? "Selected" : "";
    }
    protected function checked(PaperList $pl, PaperInfo $row) {
        return $pl->is_selected($row->paperId, $this->name == "selon");
    }
    function content(PaperList $pl, PaperInfo $row) {
        $pl->mark_has("sel");
        $c = "";
        if ($this->checked($pl, $row)) {
            $c .= ' checked="checked"';
            unset($row->folded);
        }
        return '<span class="pl_rownum fx6">' . $pl->count . '. </span>'
            . '<input type="checkbox" class="js-range-click" name="pap[]" value="' . $row->paperId . '" tabindex="3"' . $c . ' />';
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $this->checked($pl, $row) ? "Y" : "N";
    }
}

class ConflictSelector_PaperColumn extends SelectorPaperColumn {
    private $contact;
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $pl->reviewer_user();
        if (!$pl->user->is_manager())
            return false;
        if (($tid = $pl->table_id()))
            $pl->add_header_script("add_assrev_ajax(" . json_encode_browser("#$tid") . ")");
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Conflict?";
    }
    protected function checked(PaperList $pl, PaperInfo $row) {
        return $pl->is_selected($row->paperId, $row->conflict_type($this->contact) > 0);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $disabled = $row->conflict_type($this->contact) >= CONFLICT_AUTHOR;
        if (!$pl->user->allow_administer($row)) {
            $disabled = true;
            if (!$pl->user->can_view_conflicts($row))
                return "";
        }
        $pl->mark_has("sel");
        $c = "";
        if ($disabled)
            $c .= ' disabled="disabled"';
        if ($this->checked($pl, $row)) {
            $c .= ' checked="checked"';
            unset($row->folded);
        }
        return '<input type="checkbox" '
            . 'name="assrev' . $row->paperId . 'u' . $this->contact->contactId
            . '" value="-1" tabindex="3"' . $c . ' />';
    }
}

class TitlePaperColumn extends PaperColumn {
    private $has_decoration = false;
    private $highlight = false;
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        $this->has_decoration = $pl->user->can_view_tags(null)
            && $pl->conf->tags()->has_decoration;
        if ($this->has_decoration)
            $pl->qopts["tags"] = 1;
        $this->highlight = $pl->search->field_highlighter("title");
        return true;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $cmp = strcasecmp($a->unaccented_title(), $b->unaccented_title());
        if (!$cmp)
            $cmp = strcasecmp($a->title, $b->title);
        return $cmp;
    }
    function header(PaperList $pl, $is_text) {
        return "Title";
    }
    function content(PaperList $pl, PaperInfo $row) {
        $t = '<a href="' . $pl->_paperLink($row) . '" class="ptitle taghl';

        $highlight_text = Text::highlight($row->title, $this->highlight, $highlight_count);

        if (!$highlight_count && ($format = $row->title_format())) {
            $pl->need_render = true;
            $t .= ' need-format" data-format="' . $format
                . '" data-title="' . htmlspecialchars($row->title);
        }

        $t .= '" tabindex="5">' . $highlight_text . '</a>'
            . $pl->_contentDownload($row);

        if ($this->has_decoration && (string) $row->paperTags !== "") {
            if ($pl->row_overridable) {
                if (($vto = $row->viewable_tags($pl->user, true))
                    && ($deco = $pl->tagger->unparse_decoration_html($vto))) {
                    $vtx = $row->viewable_tags($pl->user, false);
                    $decx = $pl->tagger->unparse_decoration_html($vtx);
                    if ($deco !== $decx) {
                        if ($decx)
                            $t .= '<span class="fn5">' . $decx . '</span>';
                        $t .= '<span class="fx5">' . $deco . '</span>';
                    } else
                        $t .= $deco;
                }
            } else if (($vt = $row->viewable_tags($pl->user)))
                $t .= $pl->tagger->unparse_decoration_html($vt);
        }

        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->title;
    }
}

class StatusPaperColumn extends PaperColumn {
    private $is_long;
    function __construct($cj) {
        parent::__construct($cj);
        $this->is_long = $cj->name === "statusfull";
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        $force = $pl->search->limitName != "a" && $pl->user->privChair;
        foreach ($rows as $row)
            if ($row->outcome && $pl->user->can_view_decision($row, $force))
                $row->_status_sort_info = $row->outcome;
            else
                $row->_status_sort_info = -10000;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $x = $b->_status_sort_info - $a->_status_sort_info;
        $x = $x ? $x : ($a->timeWithdrawn > 0) - ($b->timeWithdrawn > 0);
        $x = $x ? $x : ($b->timeSubmitted > 0) - ($a->timeSubmitted > 0);
        return $x ? $x : ($b->paperStorageId > 1) - ($a->paperStorageId > 1);
    }
    function header(PaperList $pl, $is_text) {
        return "Status";
    }
    function content(PaperList $pl, PaperInfo $row) {
        if ($row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0)
            $pl->mark_has("need_submit");
        if ($row->outcome > 0 && $pl->user->can_view_decision($row))
            $pl->mark_has("accepted");
        if ($row->outcome > 0 && $row->timeFinalSubmitted <= 0
            && $pl->user->can_view_decision($row))
            $pl->mark_has("need_final");
        $status_info = $pl->user->paper_status_info($row, $pl->search->limitName != "a" && $pl->user->allow_administer($row));
        if (!$this->is_long && $status_info[0] == "pstat_sub")
            return "";
        return "<span class=\"pstat $status_info[0]\">" . htmlspecialchars($status_info[1]) . "</span>";
    }
    function text(PaperList $pl, PaperInfo $row) {
        $status_info = $pl->user->paper_status_info($row, $pl->search->limitName != "a" && $pl->user->allow_administer($row));
        return $status_info[1];
    }
}

class ReviewStatus_PaperColumn extends PaperColumn {
    private $round;
    function __construct($cj) {
        parent::__construct($cj);
        $this->override = PaperColumn::OVERRIDE_FOLD_BOTH;
        $this->round = get($cj, "round", null);
    }
    function prepare(PaperList $pl, $visible) {
        if ($pl->user->privChair || $pl->user->is_reviewer() || $pl->conf->timeAuthorViewReviews()) {
            $pl->qopts["reviewSignatures"] = true;
            return true;
        } else
            return false;
    }
    private function data(PaperInfo $row, Contact $user) {
        $want_assigned = !$row->conflict_type($user) || $user->can_administer($row);
        $done = $started = 0;
        foreach ($row->reviews_by_id() as $rrow)
            if ($user->can_view_review_assignment($row, $rrow)
                && ($this->round === null || $this->round === $rrow->reviewRound)) {
                if ($rrow->reviewSubmitted > 0) {
                    ++$done;
                    ++$started;
                } else if ($want_assigned ? $rrow->reviewNeedsSubmit > 0 : $rrow->reviewModified > 0)
                    ++$started;
            }
        return [$done, $started];
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        foreach ($rows as $row) {
            if (!$pl->user->can_view_review_assignment($row, null))
                $row->_review_status_sort_info = -2147483647;
            else {
                list($done, $started) = $this->data($row, $pl->user);
                $row->_review_status_sort_info = $done + $started / 1000.0;
            }
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $av = $a->_review_status_sort_info;
        $bv = $b->_review_status_sort_info;
        return ($av < $bv ? 1 : ($av == $bv ? 0 : -1));
    }
    function header(PaperList $pl, $is_text) {
        $round_name = "";
        if ($this->round !== null)
            $round_name = ($pl->conf->round_name($this->round) ? : "unnamed") . " ";
        if ($is_text)
            return "# {$round_name}Reviews";
        else
            return '<span class="need-tooltip" data-tooltip="# completed reviews / # assigned reviews" data-tooltip-dir="b">#&nbsp;' . $round_name . 'Reviews</span>';
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_review_assignment($row, null);
    }
    function content(PaperList $pl, PaperInfo $row) {
        list($done, $started) = $this->data($row, $pl->user);
        return "<b>$done</b>" . ($done == $started ? "" : "/$started");
    }
    function text(PaperList $pl, PaperInfo $row) {
        list($done, $started) = $this->data($row, $pl->user);
        return $done . ($done == $started ? "" : "/$started");
    }
}

class Authors_PaperColumn extends PaperColumn {
    private $aufull;
    private $anonau;
    private $highlight;
    function __construct($cj) {
        parent::__construct($cj);
    }
    function header(PaperList $pl, $is_text) {
        return "Authors";
    }
    function prepare(PaperList $pl, $visible) {
        $this->aufull = !$pl->is_folded("aufull");
        $this->anonau = !$pl->is_folded("anonau");
        $this->highlight = $pl->search->field_highlighter("authorInformation");
        return $pl->user->can_view_some_authors();
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
        return !$pl->user->allow_view_authors($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $out = [];
        if (!$this->highlight && !$this->aufull) {
            foreach ($row->author_list() as $au)
                $out[] = $au->abbrevname_html();
            $t = join(", ", $out);
        } else {
            $affmap = $this->affiliation_map($row);
            $aus = $affout = [];
            $any_affhl = false;
            foreach ($row->author_list() as $i => $au) {
                $name = Text::highlight($au->name(), $this->highlight, $didhl);
                if (!$this->aufull
                    && ($first = htmlspecialchars($au->firstName))
                    && (!$didhl || substr($name, 0, strlen($first)) === $first)
                    && ($initial = Text::initial($first)) !== "")
                    $name = $initial . substr($name, strlen($first));
                $auy[] = $name;
                if ($affmap[$i] !== null) {
                    $out[] = join(", ", $auy);
                    $affout[] = Text::highlight($affmap[$i], $this->highlight, $didhl);
                    $any_affhl = $any_affhl || $didhl;
                    $auy = [];
                }
            }
            // $affout[0] === "" iff there are no nonempty affiliations
            if (($any_affhl || $this->aufull) && $affout[0] !== "") {
                foreach ($out as $i => &$x)
                    $x .= ' <span class="auaff">(' . $affout[$i] . ')</span>';
            }
            $t = join($any_affhl || $this->aufull ? "; " : ", ", $out);
        }
        if ($pl->conf->submission_blindness() !== Conf::BLIND_NEVER
            && !$pl->user->can_view_authors($row))
            $t = '<div class="fx2">' . $t . '</div>';
        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        if (!$pl->user->can_view_authors($row) && !$this->anonau)
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

class Collab_PaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
        $this->override = PaperColumn::OVERRIDE_FOLD;
    }
    function prepare(PaperList $pl, $visible) {
        return !!$pl->conf->setting("sub_collab") && $pl->user->can_view_some_authors();
    }
    function header(PaperList $pl, $is_text) {
        return "Collaborators";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return $row->collaborators == ""
            || strcasecmp($row->collaborators, "None") == 0
            || !$pl->user->allow_view_authors($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $x = "";
        foreach (explode("\n", $row->collaborators) as $c)
            $x .= ($x === "" ? "" : ", ") . trim($c);
        return Text::highlight($x, $pl->search->field_highlighter("collaborators"));
    }
    function text(PaperList $pl, PaperInfo $row) {
        $x = "";
        foreach (explode("\n", $row->collaborators) as $c)
            $x .= ($x === "" ? "" : ", ") . trim($c);
        return $x;
    }
}

class Abstract_PaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function header(PaperList $pl, $is_text) {
        return "Abstract";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return $row->abstract == "";
    }
    function content(PaperList $pl, PaperInfo $row) {
        $t = Text::highlight($row->abstract, $pl->search->field_highlighter("abstract"), $highlight_count);
        $klass = strlen($t) > 190 ? "pl_longtext" : "pl_shorttext";
        if (!$highlight_count && ($format = $row->format_of($row->abstract))) {
            $pl->need_render = true;
            $t = '<div class="' . $klass . ' need-format" data-format="'
                . $format . '.abs.plx">' . $t . '</div>';
        } else
            $t = '<div class="' . $klass . '">' . Ht::format0($t) . '</div>';
        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->abstract;
    }
}

class ReviewerType_PaperColumn extends PaperColumn {
    protected $contact;
    private $self;
    private $rrow_key;
    function __construct($cj, Conf $conf = null) {
        parent::__construct($cj);
        if ($conf && isset($cj->user))
            $this->contact = $conf->pc_member_by_email($cj->user);
    }
    function contact() {
        return $this->contact;
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $this->contact ? : $pl->reviewer_user();
        $this->not_me = $this->contact->contactId !== $pl->user->contactId;
        return true;
    }
    const F_CONFLICT = 1;
    const F_LEAD = 2;
    const F_SHEPHERD = 4;
    private function analysis(PaperList $pl, PaperInfo $row, $forceShow = null) {
        $rrow = $row->review_of_user($this->contact);
        if ($rrow && (!$this->not_me || $pl->user->can_view_review_identity($row, $rrow, $forceShow)))
            $ranal = $pl->make_review_analysis($rrow, $row);
        else
            $ranal = null;
        if ($ranal && !$ranal->rrow->reviewSubmitted)
            $pl->mark_has("need_review");
        $flags = 0;
        if ($row->conflict_type($this->contact)
            && (!$this->not_me || $pl->user->can_view_conflicts($row, $forceShow)))
            $flags |= self::F_CONFLICT;
        if ($row->leadContactId == $this->contact->contactId
            && (!$this->not_me || $pl->user->can_view_lead($row, $forceShow)))
            $flags |= self::F_LEAD;
        if ($row->shepherdContactId == $this->contact->contactId
            && (!$this->not_me || $pl->user->can_view_shepherd($row, $forceShow)))
            $flags |= self::F_SHEPHERD;
        return [$ranal, $flags];
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        $k = $sorter->uid;
        foreach ($rows as $row) {
            list($ranal, $flags) = $this->analysis($pl, $row, true);
            if ($ranal && $ranal->rrow->reviewType) {
                $row->$k = 16 * $ranal->rrow->reviewType;
                if ($ranal->rrow->reviewSubmitted)
                    $row->$k += 8;
            } else
                $row->$k = ($flags & self::F_CONFLICT ? -16 : 0);
            if ($flags & self::F_LEAD)
                $row->$k += 4;
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $k = $sorter->uid;
        return $b->$k - $a->$k;
    }
    function header(PaperList $pl, $is_text) {
        if (!$this->not_me)
            return "Review";
        else if ($is_text)
            return $pl->user->name_text_for($this->contact) . " review";
        else
            return $pl->user->name_html_for($this->contact) . "<br />review";
    }
    function content(PaperList $pl, PaperInfo $row) {
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
            ($flags & (self::F_LEAD | self::F_SHEPHERD)) && ($c[] = "haslead");
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

class AssignReview_PaperColumn extends ReviewerType_PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $this->contact ? : $pl->reviewer_user();
        if (!$pl->user->is_manager())
            return false;
        if ($visible > 0 && ($tid = $pl->table_id()))
            $pl->add_header_script("add_assrev_ajax(" . json_encode_browser("#$tid") . ")");
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Assignment";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->allow_administer($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $ci = $row->contact_info($this->contact);
        if ($ci->conflictType >= CONFLICT_AUTHOR)
            return '<span class="author">Author</span>';
        if ($ci->conflictType > 0)
            $rt = -1;
        else
            $rt = min(max($ci->reviewType, 0), REVIEW_META);
        if ($this->contact->can_accept_review_assignment_ignore_conflict($row)
            || $rt > 0)
            $options = array(0 => "None",
                             REVIEW_PRIMARY => "Primary",
                             REVIEW_SECONDARY => "Secondary",
                             REVIEW_PC => "Optional",
                             REVIEW_META => "Metareview",
                             -1 => "Conflict");
        else
            $options = array(0 => "None", -1 => "Conflict");
        return Ht::select("assrev{$row->paperId}u{$this->contact->contactId}",
                          $options, $rt, ["tabindex" => 3]);
    }
}

class PreferenceList_PaperColumn extends PaperColumn {
    private $topics;
    function __construct($cj) {
        parent::__construct($cj);
        $this->topics = get($cj, "topics");
    }
    function prepare(PaperList $pl, $visible) {
        if ($this->topics && !$pl->conf->has_topics())
            $this->topics = false;
        if (!$pl->user->is_manager())
            return false;
        if ($visible) {
            $pl->qopts["allReviewerPreference"] = $pl->qopts["allConflictType"] = 1;
            if ($this->topics)
                $pl->qopts["topics"] = 1;
        }
        $pl->conf->stash_hotcrp_pc($pl->user);
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Preferences";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->allow_administer($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
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

class ReviewerList_PaperColumn extends PaperColumn {
    private $topics;
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_some_review_identity())
            return false;
        $this->topics = $pl->conf->has_topics();
        $pl->qopts["reviewSignatures"] = true;
        if ($visible && $pl->user->privChair)
            $pl->qopts["allReviewerPreference"] = $pl->qopts["topics"] = true;
        if ($pl->conf->review_blindness() === Conf::BLIND_OPTIONAL)
            $this->override = PaperColumn::OVERRIDE_FOLD_BOTH;
        else
            $this->override = PaperColumn::OVERRIDE_FOLD;
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Reviewers";
    }
    private function reviews_with_names(PaperInfo $row) {
        $row->ensure_reviewer_names();
        $rrows = $row->reviews_by_id();
        foreach ($rrows as $rrow)
            Contact::set_sorter($rrow, $row->conf);
        usort($rrows, "Contact::compare");
        return $rrows;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_review_identity($row, null);
    }
    function content(PaperList $pl, PaperInfo $row) {
        // see also search.php > getaction == "reviewers"
        $x = [];
        foreach ($this->reviews_with_names($row) as $xrow)
            if ($pl->user->can_view_review_identity($row, $xrow)) {
                $ranal = $pl->make_review_analysis($xrow, $row);
                $n = $pl->user->reviewer_html_for($xrow) . "&nbsp;" . $ranal->icon_html(false);
                if ($pl->user->privChair) {
                    $pref = $row->reviewer_preference((int) $xrow->contactId);
                    if ($this->topics && $row->has_topics())
                        $pref[2] = $row->topic_interest_score((int) $xrow->contactId);
                    $n .= unparse_preference_span($pref);
                }
                $x[] = '<span class="nw">' . $n . '</span>';
            }
        return join(", ", $x);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $x = [];
        foreach ($this->reviews_with_names($row) as $xrow)
            if ($pl->user->can_view_review_identity($row, $xrow))
                $x[] = $pl->user->name_text_for($xrow);
        return join("; ", $x);
    }
}

class ConflictMatch_PaperColumn extends PaperColumn {
    private $field;
    private $highlight;
    function __construct($cj) {
        parent::__construct($cj);
        if ($cj->name === "authorsmatch")
            $this->field = "authorInformation";
        else
            $this->field = "collaborators";
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $pl->reviewer_user();
        $this->highlight = $pl->search->field_highlighter($this->field);
        $general_pregexes = $this->contact->aucollab_general_pregexes();
        return $pl->user->is_manager() && !empty($general_pregexes);
    }
    function header(PaperList $pl, $is_text) {
        $what = $this->field == "authorInformation" ? "authors" : "collaborators";
        if ($is_text)
            return "Potential conflict in $what";
        else
            return "<strong>Potential conflict in $what</strong>";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->allow_administer($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $field = $this->field;
        if (!$row->field_match_pregexes($this->contact->aucollab_general_pregexes(), $field))
            return "";
        $text = [];
        $aus = $field === "collaborators" ? $row->collaborator_list() : $row->author_list();
        foreach ($aus as $au) {
            $matchers = [];
            foreach ($this->contact->aucollab_matchers() as $matcher)
                if ($matcher->test($au))
                    $matchers[] = $matcher;
            if (!empty($matchers))
                $text[] = PaperInfo_AuthorMatcher::highlight_all($au, $matchers);
        }
        if (!empty($text))
            unset($row->folded);
        return join("; ", $text);
    }
}

class TagList_PaperColumn extends PaperColumn {
    private $editable;
    function __construct($cj, $editable = false) {
        parent::__construct($cj);
        $this->override = PaperColumn::OVERRIDE_ALWAYS;
        $this->editable = $editable;
    }
    function make_editable(PaperList $pl) {
        return new TagList_PaperColumn($this->column_json(), true);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_tags(null))
            return false;
        if ($visible)
            $pl->qopts["tags"] = 1;
        if ($visible && $this->editable && ($tid = $pl->table_id()))
            $pl->add_header_script("plinfo_tags(" . json_encode_browser("#$tid") . ")", "plinfo_tags");
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
        return !$pl->user->can_view_tags($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        if ($row->paperTags && $pl->row_overridable) {
            $viewable = trim($row->viewable_tags($pl->user, true));
            $pl->row_attr["data-tags-conflicted"] = trim($row->viewable_tags($pl->user, false));
        } else
            $viewable = trim($row->viewable_tags($pl->user));
        $pl->row_attr["data-tags"] = $viewable;
        if ($this->editable)
            $pl->row_attr["data-tags-editable"] = 1;
        if ($viewable !== "" || $this->editable) {
            $pl->need_render = true;
            return '<span class="need-tags"></span>';
        } else
            return "";
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->tagger->unparse_hashed($row->viewable_tags($pl->user));
    }
}

class Tag_PaperColumn extends PaperColumn {
    protected $is_value;
    protected $dtag;
    protected $xtag;
    protected $ctag;
    protected $editable = false;
    protected $emoji = false;
    function __construct($cj) {
        parent::__construct($cj);
        $this->override = PaperColumn::OVERRIDE_FOLD;
        $this->dtag = $cj->tag;
        $this->is_value = get($cj, "tagvalue");
    }
    function make_editable(PaperList $pl) {
        $is_value = $this->is_value || $this->is_value === null;
        $cj = $this->column_json() + ["tagvalue" => $is_value, "tag" => $this->dtag];
        return new EditTag_PaperColumn((object) $cj);
    }
    function sorts_my_tag($sorter, Contact $user) {
        return strcasecmp(Tagger::check_tag_keyword($sorter->type, $user, Tagger::NOVALUE | Tagger::ALLOWCONTACTID), $this->xtag) == 0;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_tags(null))
            return false;
        $tagger = new Tagger($pl->user);
        if (!($ctag = $tagger->check($this->dtag, Tagger::NOVALUE | Tagger::ALLOWCONTACTID)))
            return false;
        $this->xtag = strtolower($ctag);
        $this->ctag = " {$this->xtag}#";
        if ($visible)
            $pl->qopts["tags"] = 1;
        $this->className = ($this->is_value ? "pl_tagval" : "pl_tag");
        if ($this->dtag[0] == ":" && !$this->is_value
            && ($dt = $pl->user->conf->tags()->check($this->dtag))
            && count($dt->emoji) == 1)
            $this->emoji = $dt->emoji[0];
        return true;
    }
    function completion_name() {
        return "#$this->dtag";
    }
    function sort_name($score_sort) {
        return "#$this->dtag";
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        $k = $sorter->uid;
        $careful = !$pl->user->privChair && !$pl->conf->tag_seeall;
        $unviewable = $empty = $sorter->reverse ? -(TAG_INDEXBOUND - 1) : TAG_INDEXBOUND - 1;
        if ($this->editable)
            $empty = $sorter->reverse ? -TAG_INDEXBOUND : TAG_INDEXBOUND;
        foreach ($rows as $row)
            if ($careful && !$pl->user->can_view_tag($row, $this->xtag, true))
                $row->$k = $unviewable;
            else if (($row->$k = $row->tag_value($this->xtag)) === false)
                $row->$k = $empty;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $k = $sorter->uid;
        return $a->$k < $b->$k ? -1 : ($a->$k == $b->$k ? 0 : 1);
    }
    function header(PaperList $pl, $is_text) {
        return "#$this->dtag";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_tag($row, $this->xtag);
    }
    function content(PaperList $pl, PaperInfo $row) {
        if (($v = $row->tag_value($this->xtag)) === false)
            return "";
        else if ($v >= 0.0 && $this->emoji)
            return Tagger::unparse_emoji_html($this->emoji, $v);
        else if ($v === 0.0 && !$this->is_value)
            return "✓";
        else
            return $v;
    }
    function text(PaperList $pl, PaperInfo $row) {
        if (($v = $row->tag_value($this->xtag)) === false)
            return "N";
        else if ($v === 0.0 && !$this->is_value)
            return "Y";
        else
            return $v;
    }
}

class Tag_PaperColumnFactory {
    static function expand($name, Conf $conf, $xfj, $m) {
        $fj = (array) $xfj;
        $fj["name"] = $name;
        $fj["tag"] = $m[1];
        return (object) $fj;
    }
}

class EditTag_PaperColumn extends Tag_PaperColumn {
    private $editsort;
    function __construct($cj) {
        parent::__construct($cj);
        $this->editable = true;
    }
    function prepare(PaperList $pl, $visible) {
        $this->editsort = false;
        if (!parent::prepare($pl, $visible))
            return false;
        if ($visible > 0 && ($tid = $pl->table_id())) {
            $sorter = get($pl->sorters, 0);
            if ($this->sorts_my_tag($sorter, $pl->user)
                && !$sorter->reverse
                && (!$pl->search->thenmap || $pl->search->is_order_anno)
                && $this->is_value) {
                $this->editsort = true;
                $pl->tbody_attr["data-drag-tag"] = $this->dtag;
            }
            $pl->has_editable_tags = true;
            $pl->add_header_script("plinfo_tags(" . json_encode_browser("#$tid") . ")", "plinfo_tags");
        }
        $this->className = $this->is_value ? "pl_edittagval" : "pl_edittag";
        return true;
    }
    function content(PaperList $pl, PaperInfo $row) {
        $v = $row->tag_value($this->xtag);
        if ($this->editsort && !isset($pl->row_attr["data-tags"]))
            $pl->row_attr["data-tags"] = $this->dtag . "#" . $v;
        if (!$pl->user->can_change_tag($row, $this->dtag, 0, 0, true))
            return $this->is_value ? (string) $v : ($v === false ? "" : "&#x2713;");
        if (!$this->is_value)
            return '<input type="checkbox" class="edittag" name="tag:' . "$this->dtag $row->paperId" . '" value="x" tabindex="6"'
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

class ScoreGraph_PaperColumn extends PaperColumn {
    protected $contact;
    protected $not_me;
    function __construct($cj) {
        parent::__construct($cj);
    }
    function sort_name($score_sort) {
        $score_sort = ListSorter::canonical_long_score_sort($score_sort);
        return $this->name . ($score_sort ? " $score_sort" : "");
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $pl->context_user();
        $this->not_me = $this->contact->contactId !== $pl->user->contactId;
        if ($visible && $this->not_me
            && (!$pl->user->privChair || $pl->conf->has_any_manager()))
            $pl->qopts["reviewSignatures"] = true;
    }
    function score_values(PaperList $pl, PaperInfo $row) {
        return null;
    }
    protected function set_sort_fields(PaperList $pl, PaperInfo $row, ListSorter $sorter) {
        $k = $sorter->uid;
        $avgk = $k . "avg";
        $s = $this->score_values($pl, $row);
        if ($s !== null) {
            $scoreinfo = new ScoreInfo($s, true);
            $cid = $this->contact->contactId;
            if ($this->not_me
                && !$row->can_view_review_identity_of($cid, $pl->user))
                $cid = 0;
            $row->$k = $scoreinfo->sort_data($sorter->score, $cid);
            $row->$avgk = $scoreinfo->mean();
        } else
            $row->$k = $row->$avgk = null;
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        foreach ($rows as $row)
            self::set_sort_fields($pl, $row, $sorter);
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $k = $sorter->uid;
        if (!($x = ScoreInfo::compare($b->$k, $a->$k, -1))) {
            $k .= "avg";
            $x = ScoreInfo::compare($b->$k, $a->$k);
        }
        return $x;
    }
    function field_content(PaperList $pl, ReviewField $field, PaperInfo $row) {
        $values = $this->score_values($pl, $row);
        if (empty($values))
            return "";
        $pl->need_render = true;
        $cid = $this->contact->contactId;
        if ($this->not_me && !$row->can_view_review_identity_of($cid, $pl->user))
            $cid = 0;
        return $field->unparse_graph($values, 1, get($values, $cid));
    }
}

class Score_PaperColumn extends ScoreGraph_PaperColumn {
    public $score;
    private $form_field;
    function __construct($cj, Conf $conf) {
        parent::__construct($cj);
        $this->override = PaperColumn::OVERRIDE_FOLD;
        $this->form_field = $conf->review_field($cj->review_field_id);
        $this->score = $this->form_field->id;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->scoresOk
            || $this->form_field->view_score <= $pl->user->permissive_view_score_bound())
            return false;
        if ($visible)
            $pl->qopts["scores"][$this->score] = true;
        parent::prepare($pl, $visible);
        return true;
    }
    function score_values(PaperList $pl, PaperInfo $row) {
        $fid = $this->form_field->id;
        $row->ensure_review_score($this->form_field);
        $scores = [];
        foreach ($row->viewable_submitted_reviews_by_user($pl->user) as $rrow)
            if (isset($rrow->$fid) && $rrow->$fid)
                $scores[$rrow->contactId] = $rrow->$fid;
        return $scores;
    }
    function header(PaperList $pl, $is_text) {
        return $is_text ? $this->form_field->search_keyword() : $this->form_field->web_abbreviation();
    }
    function alternate_display_name() {
        return $this->form_field->id;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        // Do not use score_values to determine content emptiness, since
        // that would load the scores from the DB -- even for folded score
        // columns.
        return !$row->may_have_viewable_scores($this->form_field, $pl->user);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return parent::field_content($pl, $this->form_field, $row);
    }
}

class Score_PaperColumnFactory {
    static function xt_user_visible_fields($name, Conf $conf = null) {
        if ($name === "scores") {
            $fs = $conf->all_review_fields();
            $conf->xt_factory_mark_matched();
        } else
            $fs = [$conf->find_review_field($name)];
        $vsbound = $conf->xt_user->permissive_view_score_bound();
        return array_filter($fs, function ($f) use ($vsbound) {
            return $f && $f->has_options && $f->displayed && $f->view_score > $vsbound;
        });
    }
    static function expand($name, Conf $conf, $xfj, $m) {
        return array_map(function ($f) use ($xfj) {
            $cj = (array) $xfj;
            $cj["name"] = $f->search_keyword();
            $cj["review_field_id"] = $f->id;
            return (object) $cj;
        }, self::xt_user_visible_fields($name, $conf));
    }
    static function completions(Contact $user, $fxt) {
        if (!$user->can_view_some_review())
            return [];
        $vsbound = $user->permissive_view_score_bound();
        $cs = array_map(function ($f) {
            return $f->search_keyword();
        }, array_filter($user->conf->all_review_fields(), function ($f) use ($vsbound) {
            return $f->has_options && $f->displayed && $f->view_score > $vsbound;
        }));
        if (!empty($cs))
            array_unshift($cs, "scores");
        return $cs;
    }
}

class NumericOrderPaperColumn extends PaperColumn {
    private $order;
    function __construct($order) {
        parent::__construct([
            "name" => "numericorder", "sort" => true
        ]);
        $this->order = $order;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        return +get($this->order, $a->paperId) - +get($this->order, $b->paperId);
    }
}

class FoldAll_PaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        $pl->qopts["foldall"] = true;
        return true;
    }
}
