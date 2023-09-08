<?php
// pc_tag.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Tag_PaperColumn extends PaperColumn {
    /** @var ?bool */
    private $is_value;
    /** @var string */
    private $dtag;
    /** @var string */
    private $etag;
    /** @var string */
    private $ctag;
    /** @var bool */
    private $editable = false;
    /** @var 0|1|2 */
    private $display = 0;
    /** @var bool */
    private $editsort = false;
    /** @var TagInfo */
    private $ti;
    /** @var array<int,float> */
    private $sortmap;
    /** @var ScoreInfo */
    private $statistics;
    /** @var ?ScoreInfo */
    private $override_statistics;
    /** @var ?string */
    private $real_format;
    /** @var bool */
    private $complex = false;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
        $this->dtag = $cj->tag;
        $this->is_value = $cj->tagvalue ?? null;
    }
    function add_decoration($decor) {
        if ($decor === "edit") {
            $this->editable = true;
            return $this->__add_decoration($decor);
        } else if (preg_match('/\A%?\d*(?:\.\d*)[bdeEfFgGoxX]\z/', $decor)) {
            $this->__add_decoration($decor, [$this->real_format]);
            $this->real_format = $decor;
            return true;
        } else {
            return parent::add_decoration($decor);
        }
    }
    function etag() {
        return $this->etag;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_tags(null)) {
            return false;
        }
        $tagger = new Tagger($pl->user);
        if (!($this->etag = $tagger->check($this->dtag, Tagger::NOVALUE | Tagger::ALLOWCONTACTID))) {
            return false;
        }
        $this->ctag = " {$this->etag}#";
        if ($visible) {
            $pl->qopts["tags"] = 1;
        }
        $this->ti = $pl->conf->tags()->ensure($this->dtag);
        if ($this->as_row
            && $this->ti->has_order_anno()) {
            $this->display = 1;
        } else if (!$this->is_value
                   && count($this->ti->emoji ?? []) === 1) {
            $this->display = 2;
        }
        if ($this->editable) {
            $this->prepare_editable($pl, $visible);
        }
        $this->className = ($this->editable ? "pl_edit" : "pl_")
            . ($this->is_value ? "tagval" : "tag");
        return true;
    }
    private function prepare_editable(PaperList $pl, $visible) {
        if (!$pl->user->can_edit_tag_somewhere($this->etag)) {
            $this->editable = false;
            $pl->column_error("<0>Tag ‘#{$this->dtag}’ cannot be edited");
            if ($pl->conf->tags()->is_automatic($this->etag)) {
                if ($pl->conf->tags()->is_votish($this->etag)
                    && $pl->user->is_pc_member()) {
                    $pl->column_error(new MessageItem(null, "<0>This tag is set automatically based on per-user votes. Did you mean ‘edit:#~{$this->dtag}’?", MessageSet::INFORM));
                } else {
                    $pl->column_error(new MessageItem(null, "<0>This tag is set automatically.", MessageSet::INFORM));
                }
            }
            return;
        }
        if ($this->is_value === null) {
            // XXX min/max values
            $dt = $pl->conf->tags()->find($this->etag);
            if (!$dt && ($tw = strpos($this->etag, "~"))) {
                $dt = $pl->conf->tags()->find(substr($this->etag, $tw + 1));
            }
            $this->is_value = !$dt || !$dt->is(TagInfo::TF_APPROVAL);
        }
        if (($visible & PaperColumn::PREP_VISIBLE) !== 0
            && $pl->table_id()
            && !$pl->viewing("facets")) {
            $pl->has_editable_tags = true;
            if (strcasecmp($this->etag, $pl->sort_etag()) === 0
                && $this->is_value) {
                $pl->table_attr["data-drag-tag"] = $this->dtag;
                $this->editsort = true;
            }
        }
    }
    function completion_name() {
        return "#{$this->dtag}";
    }
    function sort_name() {
        return "#{$this->dtag}";
    }
    function prepare_sort(PaperList $pl, $sortindex) {
        $this->sortmap = [];
        $unviewable = $empty = TAG_INDEXBOUND * ($this->sort_descending ? -1 : 1);
        if ($this->editable) {
            $empty = (TAG_INDEXBOUND - 1) * ($this->sort_descending ? -1 : 1);
        }
        foreach ($pl->rowset() as $row) {
            if (!$pl->user->can_view_tag($row, $this->etag)) {
                $this->sortmap[$row->paperXid] = $unviewable;
            } else {
                $this->sortmap[$row->paperXid] = $row->tag_value($this->etag) ?? $empty;
            }
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        return $this->sortmap[$a->paperXid] <=> $this->sortmap[$b->paperXid];
    }
    function reset(PaperList $pl) {
        $this->statistics = new ScoreInfo;
        $this->override_statistics = null;
    }
    function header(PaperList $pl, $is_text) {
        if (($twiddle = strpos($this->dtag, "~")) > 0) {
            $cid = (int) substr($this->dtag, 0, $twiddle);
            if ($cid == $pl->user->contactId) {
                return "#" . substr($this->dtag, $twiddle);
            } else if (($p = $pl->conf->user_by_id($cid, USER_SLICE))) {
                if ($is_text) {
                    return $pl->user->reviewer_text_for($p) . " #" . substr($this->dtag, $twiddle);
                } else {
                    return $pl->user->reviewer_html_for($p) . "<br>#" . substr($this->dtag, $twiddle);
                }
            }
        }
        return "#{$this->dtag}";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_tag($row, $this->etag);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $v = $row->tag_value($this->etag);
        $sv = $v === 0.0 && !$this->is_value ? true : $v;
        if ($sv !== null && $sv !== true) {
            $this->complex = true;
        }
        if ($pl->overriding !== 0 && !$this->override_statistics) {
            $this->override_statistics = clone $this->statistics;
        }
        if ($pl->overriding <= 1) {
            $this->statistics->add($sv);
        }
        if ($pl->overriding !== 1 && $this->override_statistics) {
            $this->override_statistics->add($sv);
        }

        if ($this->editable
            && ($t = $this->edit_content($pl, $row, $v))) {
            return $t;
        } else if ($v === null) {
            return "";
        } else if ($this->display === 1
                   && ($ta = $this->ti->order_anno_search($v))
                   && $ta->heading !== "") {
            $h = htmlspecialchars($ta->heading);
            $k = "pltagheading";
            if (($format = $pl->conf->check_format(null, $ta->heading))) {
                $k .= " need-format\" data-format\"{$format}";
                $pl->need_render = true;
            }
            return (string) $v . " <span class=\"{$k}\">({$h})</span>";
        } else if ($v >= 0.0 && $this->display === 2) {
            /** @phan-suppress-next-line PhanTypeArraySuspiciousNullable */
            return Tagger::unparse_emoji_html($this->ti->emoji[0], $v);
        } else if ($sv === true) {
            return "✓";
        } else {
            return (string) $v;
        }
    }
    /** @param ?float $v */
    private function edit_content($pl, $row, $v) {
        if (!$pl->user->can_edit_tag($row, $this->dtag, 0, 0)) {
            return false;
        }
        if (!$this->is_value) {
            $checked = $v === null ? "" : " checked";
            return "<input type=\"checkbox\" class=\"uic uikd js-range-click edittag\" data-range-type=\"tag:{$this->dtag}\" name=\"tag:{$this->dtag} {$row->paperId}\" value=\"x\"{$checked}>";
        }
        if ($this->editsort) {
            $pl->need_render = true;
        }
        $vt = $v === null ? "" : ($v === true ? "0" : (string) $v);
        return "<input type=\"text\" class=\"uich uikd edittag\" size=\"4\" name=\"tag:{$this->dtag} {$row->paperId}\" value=\"{$vt}\">";
    }
    function text(PaperList $pl, PaperInfo $row) {
        if (($v = $row->tag_value($this->etag)) === null) {
            return "";
        } else if ($v === 0.0 && !$this->is_value) {
            return "Y";
        } else {
            return (string) $v;
        }
    }

    function has_statistics() {
        return !$this->editable;
    }
    private function unparse_statistic($statistics, $stat) {
        if (!$this->complex && !$this->is_value && $stat !== ScoreInfo::SUM && $stat !== ScoreInfo::COUNT) {
            return "";
        }
        $x = $statistics->statistic($stat);
        if ($x === null) {
            return "";
        } else if (($stat === ScoreInfo::MEAN || $stat === ScoreInfo::MEDIAN)
                   && $this->display === 2) {
            /** @phan-suppress-next-line PhanTypeArraySuspiciousNullable */
            return Tagger::unparse_emoji_html($this->ti->emoji[0], $x);
        } else if ($stat === ScoreInfo::COUNT) {
            return (string) $x;
        } else if ($this->real_format) {
            return sprintf($this->real_format, $x);
        } else if (is_int($x) || round($x) === $x) {
            return (string) $x;
        } else {
            return sprintf("%.2f", $x);
        }
    }
    function statistic_html(PaperList $pl, $stat) {
        $t = $this->unparse_statistic($this->statistics, $stat);
        if ($this->override_statistics) {
            $tt = $this->unparse_statistic($this->override_statistics, $stat);
            $t = $pl->wrap_conflict($t, $tt);
        }
        return $t;
    }

    static function expand($name, XtParams $xtp, $xfj, $m) {
        $tsm = new TagSearchMatcher($xtp->user);
        $tsm->set_avoid_regex(true);
        $tsm->add_check_tag($m[2], true);
        $dt = $xtp->conf->tags();
        $rs = [];
        foreach ($tsm->expand() as $t) {
            $fj = (array) $xfj;
            $fj["name"] = $m[1] . $t;
            $fj["tag"] = $t;
            $fj["title"] = $dt->unparse($t, 0, $xtp->user, TagMap::UNPARSE_HASH | TagMap::UNPARSE_TEXT);
            $fj["title_html"] = $dt->unparse($t, 0, $xtp->user, TagMap::UNPARSE_HASH);
            $fj["sort"] = $fj["sort"] ?? true;
            $fj["function"] = $fj["function"] ?? "+Tag_PaperColumn";
            $rs[] = (object) $fj;
        }
        foreach ($tsm->error_ftexts() as $e) {
            PaperColumn::column_error($xtp, $e);
        }
        return $rs;
    }

    static function completions(Contact $user, $xfj) {
        if ($user->can_view_tags(null)) {
            return ["#<tag>"];
        } else {
            return [];
        }
    }
}
