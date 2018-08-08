<?php
// papercolumn.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class PaperColumn extends Column {
    const OVERRIDE_NONE = 0;
    const OVERRIDE_FOLD_IFEMPTY = 1;
    const OVERRIDE_FOLD_BOTH = 2;
    const OVERRIDE_ALWAYS = 3;
    public $override = 0;

    const PREP_SORT = -1;
    const PREP_FOLDED = 0; // value matters
    const PREP_VISIBLE = 1; // value matters

    function __construct(Conf $conf, $cj) {
        parent::__construct($cj);
    }

    static function make(Conf $conf, $cj) {
        if ($cj->callback[0] === "+") {
            $class = substr($cj->callback, 1);
            return new $class($conf, $cj);
        } else
            return call_user_func($cj->callback, $conf, $cj);
    }


    function mark_editable() {
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
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function header(PaperList $pl, $is_text) {
        return "ID";
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        return $a->paperId - $b->paperId;
    }
    function content(PaperList $pl, PaperInfo $row) {
        $href = $pl->_paperLink($row);
        return "<a href=\"$href\" class=\"pnum taghl\">#$row->paperId</a>";
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->paperId;
    }
}

class SelectorPaperColumn extends PaperColumn {
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
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
        if ($this->checked($pl, $row))
            $c .= ' checked="checked"';
        return '<span class="pl_rownum fx6">' . $pl->count . '. </span>'
            . '<input type="checkbox" class="uix js-range-click" name="pap[]" value="' . $row->paperId . '"' . $c . ' />';
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $this->checked($pl, $row) ? "Y" : "N";
    }
}

class TitlePaperColumn extends PaperColumn {
    private $has_decoration = false;
    private $highlight = false;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
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

        if ($row->title !== "")
            $highlight_text = Text::highlight($row->title, $this->highlight, $highlight_count);
        else {
            $highlight_text = "[No title]";
            $highlight_count = 0;
        }

        if (!$highlight_count && ($format = $row->title_format())) {
            $pl->need_render = true;
            $t .= ' need-format" data-format="' . $format
                . '" data-title="' . htmlspecialchars($row->title);
        }

        $t .= '">' . $highlight_text . '</a>'
            . $pl->_contentDownload($row);

        if ($this->has_decoration && (string) $row->paperTags !== "") {
            if ($pl->row_tags_overridable
                && ($deco = $pl->tagger->unparse_decoration_html($pl->row_tags_overridable))) {
                $decx = $pl->tagger->unparse_decoration_html($pl->row_tags);
                if ($deco !== $decx) {
                    if ($decx)
                        $t .= '<span class="fn5">' . $decx . '</span>';
                    $t .= '<span class="fx5">' . $deco . '</span>';
                } else
                    $t .= $deco;
            } else if ($pl->row_tags)
                $t .= $pl->tagger->unparse_decoration_html($pl->row_tags);
        }

        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->title;
    }
}

class StatusPaperColumn extends PaperColumn {
    private $is_long;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->is_long = $cj->name === "statusfull";
        $this->override = PaperColumn::OVERRIDE_FOLD_BOTH;
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        foreach ($rows as $row) {
            if ($row->outcome && $pl->user->can_view_decision($row))
                $row->_status_sort_info = $row->outcome;
            else
                $row->_status_sort_info = -10000;
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $x = $b->_status_sort_info - $a->_status_sort_info;
        $x = $x ? : ($a->timeWithdrawn > 0) - ($b->timeWithdrawn > 0);
        $x = $x ? : ($b->timeSubmitted > 0) - ($a->timeSubmitted > 0);
        return $x ? : ($b->paperStorageId > 1) - ($a->paperStorageId > 1);
    }
    function header(PaperList $pl, $is_text) {
        return "Status";
    }
    function content(PaperList $pl, PaperInfo $row) {
        $status_info = $pl->user->paper_status_info($row, !$pl->search->limit_author() && $pl->user->can_administer($row));
        if (!$this->is_long && $status_info[0] == "pstat_sub")
            return "";
        return "<span class=\"pstat $status_info[0]\">" . htmlspecialchars($status_info[1]) . "</span>";
    }
    function text(PaperList $pl, PaperInfo $row) {
        $status_info = $pl->user->paper_status_info($row, !$pl->search->limit_author() && $pl->user->allow_administer($row));
        return $status_info[1];
    }
}

class ReviewStatus_PaperColumn extends PaperColumn {
    private $round;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_FOLD_BOTH;
        $this->round = get($cj, "round", null);
    }
    function prepare(PaperList $pl, $visible) {
        if ($pl->user->privChair || $pl->user->is_reviewer() || $pl->conf->can_some_author_view_review()) {
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
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
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
            if ($i != 0 && $au->affiliation === $aff[$i - 1])
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
            if (($any_affhl || $this->aufull)
                && !empty($out)
                && $affout[0] !== "") {
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
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_FOLD_IFEMPTY;
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
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
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
            $t = '<div class="' . $klass . ' format0">' . Ht::format0($t) . '</div>';
        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->abstract;
    }
}

class ReviewerType_PaperColumn extends PaperColumn {
    protected $contact;
    private $not_me;
    private $rrow_key;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
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
    private function analysis(PaperList $pl, PaperInfo $row) {
        $rrow = $row->review_of_user($this->contact);
        if ($rrow && (!$this->not_me || $pl->user->can_view_review_identity($row, $rrow)))
            $ranal = $pl->make_review_analysis($rrow, $row);
        else
            $ranal = null;
        if ($ranal && !$ranal->rrow->reviewSubmitted)
            $pl->mark_has("need_review");
        $flags = 0;
        if ($row->conflict_type($this->contact)
            && (!$this->not_me || $pl->user->can_view_conflicts($row)))
            $flags |= self::F_CONFLICT;
        if ($row->leadContactId == $this->contact->contactId
            && (!$this->not_me || $pl->user->can_view_lead($row)))
            $flags |= self::F_LEAD;
        if ($row->shepherdContactId == $this->contact->contactId
            && (!$this->not_me || $pl->user->can_view_shepherd($row)))
            $flags |= self::F_SHEPHERD;
        return [$ranal, $flags];
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        $k = $sorter->uid;
        foreach ($rows as $row) {
            list($ranal, $flags) = $this->analysis($pl, $row);
            if ($ranal && $ranal->rrow->reviewType) {
                $row->$k = 2 * $ranal->rrow->reviewType;
                if ($ranal->rrow->reviewSubmitted)
                    $row->$k += 1;
            } else
                $row->$k = ($flags & self::F_CONFLICT ? -2 : 0);
            if ($flags & self::F_LEAD)
                $row->$k += 30;
            if ($flags & self::F_SHEPHERD)
                $row->$k += 60;
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $k = $sorter->uid;
        return $b->$k - $a->$k;
    }
    function header(PaperList $pl, $is_text) {
        if (!$this->not_me || $pl->report_id() === "conflictassign")
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
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function prepare(PaperList $pl, $visible) {
        return parent::prepare($pl, $visible) && $pl->user->is_manager();
    }
    function header(PaperList $pl, $is_text) {
        if ($is_text)
            return $pl->user->name_text_for($this->contact) . " assignment";
        else
            return $pl->user->name_html_for($this->contact) . "<br />assignment";
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
                          $options, $rt, ["class" => "uich js-assign-review", "tabindex" => 2]);
    }
}

class PreferenceList_PaperColumn extends PaperColumn {
    private $topics;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->topics = get($cj, "topics");
    }
    function prepare(PaperList $pl, $visible) {
        if ($this->topics && !$pl->conf->has_topics())
            $this->topics = false;
        if (!$pl->user->is_manager())
            return false;
        if ($visible) {
            $pl->qopts["allReviewerPreference"] = true;
            if ($this->topics)
                $pl->qopts["topics"] = true;
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
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_some_review_identity())
            return false;
        $this->topics = $pl->conf->has_topics();
        $pl->qopts["reviewSignatures"] = true;
        if ($pl->conf->review_blindness() === Conf::BLIND_OPTIONAL)
            $this->override = PaperColumn::OVERRIDE_FOLD_BOTH;
        else
            $this->override = PaperColumn::OVERRIDE_FOLD_IFEMPTY;
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Reviewers";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_review_identity($row, null);
    }
    function content(PaperList $pl, PaperInfo $row) {
        // see also search.php > getaction == "reviewers"
        $x = [];
        foreach ($row->reviews_by_display() as $xrow)
            if ($pl->user->can_view_review_identity($row, $xrow)) {
                $ranal = $pl->make_review_analysis($xrow, $row);
                $x[] = $pl->user->reviewer_html_for($xrow) . " " . $ranal->icon_html(false);
            }
        if ($x)
            return '<span class="nb">' . join(',</span> <span class="nb">', $x) . '</span>';
        else
            return "";
    }
    function text(PaperList $pl, PaperInfo $row) {
        $x = [];
        foreach ($row->reviews_by_display() as $xrow)
            if ($pl->user->can_view_review_identity($row, $xrow))
                $x[] = $pl->user->name_text_for($xrow);
        return join("; ", $x);
    }
}

class TagList_PaperColumn extends PaperColumn {
    private $editable;
    function __construct(Conf $conf, $cj, $editable = false) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_ALWAYS;
        $this->editable = $editable;
    }
    function mark_editable() {
        $this->editable = true;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_tags(null))
            return false;
        if ($visible)
            $pl->qopts["tags"] = 1;
        if ($visible && $this->editable)
            $pl->has_editable_tags = true;
        $pl->need_tag_attr = true;
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
        if ($this->editable)
            $pl->row_attr["data-tags-editable"] = 1;
        if ($this->editable || $pl->row_tags || $pl->row_tags_overridable) {
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
    private $is_value;
    private $dtag;
    private $ltag;
    private $ctag;
    private $editable = false;
    private $emoji = false;
    private $editsort;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_FOLD_IFEMPTY;
        $this->dtag = $cj->tag;
        $this->is_value = get($cj, "tagvalue");
    }
    function mark_editable() {
        $this->editable = true;
        if ($this->is_value === null)
            $this->is_value = true;
    }
    function sorts_my_tag($sorter, Contact $user) {
        return strcasecmp(Tagger::check_tag_keyword($sorter->type, $user, Tagger::NOVALUE | Tagger::ALLOWCONTACTID), $this->ltag) == 0;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_tags(null))
            return false;
        $tagger = new Tagger($pl->user);
        if (!($ctag = $tagger->check($this->dtag, Tagger::NOVALUE | Tagger::ALLOWCONTACTID)))
            return false;
        $this->ltag = strtolower($ctag);
        $this->ctag = " {$this->ltag}#";
        if ($visible)
            $pl->qopts["tags"] = 1;
        if ($this->ltag[0] == ":"
            && !$this->is_value
            && ($dt = $pl->user->conf->tags()->check($this->dtag))
            && count($dt->emoji) == 1)
            $this->emoji = $dt->emoji[0];
        if ($this->editable && $visible > 0 && ($tid = $pl->table_id())) {
            $sorter = get($pl->sorters, 0);
            if ($this->sorts_my_tag($sorter, $pl->user)
                && !$sorter->reverse
                && (!$pl->search->thenmap || $pl->search->is_order_anno)
                && $this->is_value) {
                $this->editsort = true;
                $pl->table_attr["data-drag-tag"] = $this->dtag;
            }
            $pl->has_editable_tags = true;
        }
        $this->className = ($this->editable ? "pl_edit" : "pl_")
            . ($this->is_value ? "tagval" : "tag");
        $pl->need_tag_attr = true;
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
        $unviewable = $empty = TAG_INDEXBOUND * ($sorter->reverse ? -1 : 1);
        if ($this->editable)
            $empty = (TAG_INDEXBOUND - 1) * ($sorter->reverse ? -1 : 1);
        foreach ($rows as $row) {
            if (!$pl->user->can_view_tag($row, $this->ltag))
                $row->$k = $unviewable;
            else if (($row->$k = $row->tag_value($this->ltag)) === false)
                $row->$k = $empty;
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $k = $sorter->uid;
        return $a->$k < $b->$k ? -1 : ($a->$k == $b->$k ? 0 : 1);
    }
    function header(PaperList $pl, $is_text) {
        if (($twiddle = strpos($this->dtag, "~")) > 0) {
            $cid = (int) substr($this->dtag, 0, $twiddle);
            if ($cid == $pl->user->contactId)
                return "#" . substr($this->dtag, $twiddle);
            else if (($p = $pl->conf->cached_user_by_id($cid))) {
                if ($is_text)
                    return $pl->user->name_text_for($p) . " #" . substr($this->dtag, $twiddle);
                else
                    return $pl->user->name_html_for($p) . "<br />#" . substr($this->dtag, $twiddle);
            }
        }
        return "#$this->dtag";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_tag($row, $this->ltag);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $v = $row->tag_value($this->ltag);
        if ($this->editable
            && ($t = $this->edit_content($pl, $row, $v)))
            return $t;
        else if ($v === false)
            return "";
        else if ($v >= 0.0 && $this->emoji)
            return Tagger::unparse_emoji_html($this->emoji, $v);
        else if ($v === 0.0 && !$this->is_value)
            return "✓";
        else
            return $v;
    }
    private function edit_content($pl, $row, $v) {
        if (!$pl->user->can_change_tag($row, $this->dtag, 0, 0))
            return false;
        if (!$this->is_value) {
            return "<input type=\"checkbox\" class=\"uix js-range-click edittag\" data-range-type=\"tag:{$this->dtag}\" name=\"tag:{$this->dtag} {$row->paperId}\" value=\"x\" tabindex=\"2\""
                . ($v !== false ? ' checked="checked"' : '') . " />";
        }
        $t = '<input type="text" class="edittagval';
        if ($this->editsort) {
            $t .= " need-draghandle";
            $pl->need_render = true;
        }
        return $t . '" size="4" name="tag:' . "$this->dtag $row->paperId" . '" value="'
            . ($v !== false ? htmlspecialchars($v) : "") . '" tabindex="2" />';
    }
    function text(PaperList $pl, PaperInfo $row) {
        if (($v = $row->tag_value($this->ltag)) === false)
            return "";
        else if ($v === 0.0 && !$this->is_value)
            return "Y";
        else
            return $v;
    }
}

class Tag_PaperColumnFactory {
    static function expand($name, Conf $conf, $xfj, $m) {
        $tagger = new Tagger($conf->xt_user);
        $ts = [];
        if (($twiddle = strpos($m[2], "~")) > 0
            && !ctype_digit(substr($m[2], 0, $twiddle))) {
            $utext = substr($m[2], 0, $twiddle);
            foreach (ContactSearch::make_pc($utext, $conf->xt_user)->ids as $cid) {
                $ts[] = $cid . substr($m[2], $twiddle);
            }
            if (!$ts) {
                $conf->xt_factory_error("No PC member matches “" . htmlspecialchars($utext) . "”.");
            }
        } else {
            $ts[] = $m[2];
        }
        $flags = Tagger::NOVALUE | ($conf->xt_user->is_manager() ? Tagger::ALLOWCONTACTID : 0);
        $rs = [];
        foreach ($ts as $t) {
            if ($tagger->check($t, $flags)) {
                $fj = (array) $xfj;
                $fj["name"] = $m[1] . $t;
                $fj["tag"] = $t;
                $rs[] = (object) $fj;
            } else {
                $conf->xt_factory_error($tagger->error_html);
            }
        }
        return $rs;
    }
}

class ScoreGraph_PaperColumn extends PaperColumn {
    protected $contact;
    protected $not_me;
    protected $format_field;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function sort_name($score_sort) {
        $score_sort = ListSorter::canonical_long_score_sort($score_sort);
        return $this->name . ($score_sort ? " $score_sort" : "");
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $pl->reviewer_user();
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
    function content(PaperList $pl, PaperInfo $row) {
        $values = $this->score_values($pl, $row);
        if (empty($values))
            return "";
        $pl->need_render = true;
        $cid = $this->contact->contactId;
        if ($this->not_me && !$row->can_view_review_identity_of($cid, $pl->user))
            $cid = 0;
        return $this->format_field->unparse_graph($values, 1, get($values, $cid));
    }
    function text(PaperList $pl, PaperInfo $row) {
        $values = array_map([$this->format_field, "unparse_value"],
            $this->score_values($pl, $row));
        return join(" ", $values);
    }
}

class Score_PaperColumn extends ScoreGraph_PaperColumn {
    public $score;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_FOLD_IFEMPTY;
        $this->format_field = $conf->review_field($cj->review_field_id);
        $this->score = $this->format_field->id;
    }
    function prepare(PaperList $pl, $visible) {
        $bound = $pl->user->permissive_view_score_bound($pl->search->limit_author());
        if ($this->format_field->view_score <= $bound)
            return false;
        if ($visible)
            $pl->qopts["scores"][$this->score] = true;
        parent::prepare($pl, $visible);
        return true;
    }
    function score_values(PaperList $pl, PaperInfo $row) {
        $fid = $this->format_field->id;
        $row->ensure_review_score($this->format_field);
        $scores = [];
        foreach ($row->viewable_submitted_reviews_by_user($pl->user) as $rrow)
            if (isset($rrow->$fid) && $rrow->$fid)
                $scores[$rrow->contactId] = $rrow->$fid;
        return $scores;
    }
    function header(PaperList $pl, $is_text) {
        return $is_text ? $this->format_field->search_keyword() : $this->format_field->web_abbreviation();
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        // Do not use score_values to determine content emptiness, since
        // that would load the scores from the DB -- even for folded score
        // columns.
        return !$row->may_have_viewable_scores($this->format_field, $pl->user);
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
    function __construct(Conf $conf, $order) {
        parent::__construct($conf, ["name" => "numericorder", "sort" => true]);
        $this->order = $order;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        return +get($this->order, $a->paperId) - +get($this->order, $b->paperId);
    }
}
