<?php
// pc_tag.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

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
    /** @var int */
    private $format = 1;
    /** @var ?string */
    private $real_format;

    const F_DEFAULT = 0x1;
    const F_CHECK = 0x2;
    const F_VALUE = 0x4;
    const F_EMOJI = 0x8;
    const F_TAGANNO = 0x10;

    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
        $this->dtag = $cj->tag;
        $this->is_value = $cj->tagvalue ?? null;
    }
    static function basic_view_option_schema() {
        return ["edit", "format$^"];
    }
    function view_option_schema() {
        return self::basic_view_option_schema();
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
        $this->ti = $pl->conf->tags()->ensure($this->dtag);

        $this->editable = $this->view_option("edit") ?? false;
        if ($this->is_value !== null) {
            $this->format = $this->is_value ? self::F_VALUE : self::F_CHECK;
        }
        if (($f = $this->view_option("format")) !== null
            && preg_match('/\A%?(\d*(?:\.\d*)[bdeEfFgGoxX])\z/', $f, $m)) {
            $this->format = self::F_VALUE;
            $this->real_format = "%{$m[1]}";
        } else if ($f === "check") {
            $this->format = self::F_CHECK;
        } else if ($f === "value") {
            $this->format = self::F_VALUE;
        } else if ($f === "session") {
            $this->format = self::F_TAGANNO;
        }
        if ($this->as_row
            && $this->ti->has_order_anno()) {
            $this->format |= self::F_TAGANNO;
        } else if ($this->format === self::F_DEFAULT
                   && count($this->ti->emoji ?? []) === 1) {
            $this->format |= self::F_EMOJI;
        }

        if ($this->editable) {
            $this->prepare_editable($pl, $visible);
        }
        if ($this->editable) {
            $this->className = "pl_edittag" . ($this->format & self::F_CHECK ? "" : "val");
        } else if ($this->format & self::F_TAGANNO) {
            $this->className = "pll";
        } else {
            $this->className = "pl_tag" . ($this->format & self::F_VALUE ? "val" : "");
        }
        $pl->qopts["tags"] = 1;
        return true;
    }
    private function prepare_editable(PaperList $pl, $visible) {
        if (!$pl->user->can_edit_tag_somewhere($this->etag)) {
            $this->editable = false;
            $ml = [MessageItem::warning("<0>Tag ‘#{$this->dtag}’ cannot be edited")];
            if ($pl->conf->tags()->is_automatic($this->etag)) {
                if ($pl->conf->tags()->is_votish($this->etag)
                    && $pl->user->is_pc_member()) {
                    $ml[] = MessageItem::inform("<0>This tag is set automatically based on per-user votes. Did you mean ‘edit:#~{$this->dtag}’?");
                } else {
                    $ml[] = MessageItem::inform("<0>This tag is set automatically");
                }
            }
            $pl->column_error_at($this->name, $ml);
            return;
        }
        if ($this->format & self::F_DEFAULT) {
            // XXX min/max values
            $dt = $pl->conf->tags()->find($this->etag);
            if (!$dt && ($tw = strpos($this->etag, "~"))) {
                $dt = $pl->conf->tags()->find(substr($this->etag, $tw + 1));
            }
            if ($dt && $dt->is(TagInfo::TF_APPROVAL)) {
                $this->format ^= self::F_DEFAULT | self::F_CHECK;
            }
        }
        if (($visible & FieldRender::CFLIST) !== 0
            && $pl->table_id()
            && !$pl->viewing("facets")) {
            $pl->has_editable_tags = true;
            if (strcasecmp($this->etag, $pl->sort_etag()) === 0
                && ($this->format & self::F_VALUE)) {
                $pl->table_attr["data-drag-tag"] = $this->dtag;
                $this->editsort = true;
            }
        }
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
        $this->statistics = (new ScoreInfo)
            ->set_value_format(new Numeric_ValueFormat($this->real_format));
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
        if ($this->format & self::F_CHECK) {
            $sv = $v !== null;
        } else if (($this->format & self::F_DEFAULT) && $v === 0.0) {
            $sv = true;
        } else {
            $sv = $v;
        }
        $this->statistics->add_overriding($sv, $pl->overriding);

        if ($this->editable
            && ($t = $this->edit_content($pl, $row, $v))) {
            return $t;
        }

        if ($v === null) {
            return "";
        }

        if ($v >= 0.0 && ($this->format & self::F_EMOJI)) {
            /** @phan-suppress-next-line PhanTypeArraySuspiciousNullable */
            $s = Tagger::unparse_emoji_html($this->ti->emoji[0], $v);
        } else if ($sv === true) {
            $s = "✓";
        } else if ($this->real_format) {
            $s = sprintf($this->real_format, $v);
        } else {
            $s = (string) $v;
        }
        if (($this->format & self::F_TAGANNO)
            && ($ta = $this->ti->order_anno_search($v))
            && $ta->heading !== "") {
            $js = $this->format === self::F_TAGANNO ? [] : ["class" => "pltagheading"];
            if (($format = $pl->conf->check_format(null, $ta->heading))) {
                $js["class"] = Ht::add_tokens($js["class"] ?? null, "need-format");
                $js["data-format"] = $format;
                $pl->need_render = true;
            }
            if ($this->format === self::F_TAGANNO) {
                $s = Ht::wrap("span?", htmlspecialchars($ta->heading), $js);
            } else {
                $s .= " " . Ht::wrap("span?", "(" . htmlspecialchars($ta->heading) . ")", $js);
            }
        }
        return $s;
    }
    /** @param ?float $v */
    private function edit_content($pl, $row, $v) {
        if (!$pl->user->can_edit_tag($row, $this->dtag, 0, 0)) {
            return false;
        }
        if ($this->format & self::F_CHECK) {
            $checked = $v === null ? "" : " checked";
            return "<input type=\"checkbox\" class=\"uic uikd js-range-click js-plist-tag\" data-range-type=\"tag:{$this->dtag}\" name=\"tag:{$this->dtag} {$row->paperId}\" value=\"x\"{$checked}>";
        }
        if ($this->editsort) {
            $pl->need_render = true;
        }
        $vt = $v === null ? "" : ($v === true ? "0" : (string) $v);
        return "<input type=\"text\" class=\"uich uikd js-plist-tag\" size=\"4\" name=\"tag:{$this->dtag} {$row->paperId}\" value=\"{$vt}\">";
    }
    function text(PaperList $pl, PaperInfo $row) {
        $v = $row->tag_value($this->etag);
        if ($v === null) {
            return $this->format & self::F_CHECK ? "N" : "";
        }
        if (($this->format & self::F_CHECK)
            || (($this->format & self::F_DEFAULT) && $v === 0.0)) {
            $s = "Y";
        } else if ($this->real_format) {
            $s = sprintf($this->real_format, $v);
        } else {
            $s = (string) $v;
        }
        if (($this->format & self::F_TAGANNO)
            && ($ta = $this->ti->order_anno_search($v))
            && $ta->heading !== "") {
            $s = $this->format === self::F_TAGANNO ? $ta->heading : "{$s} {$ta->heading}";
        }
        return $s;
    }

    function has_statistics() {
        return !$this->editable;
    }
    function statistics() {
        // XXX want Emoji_ValueFormat
        return $this->statistics;
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
            PaperColumn::column_error_at($xtp, $name, $e);
        }
        return $rs;
    }

    static function examples(Contact $user, $xfj) {
        if (!$user->can_view_tags(null)) {
            return [];
        }
        return [
            new SearchExample("#{tag}", "<0>Tag value",
                new FmtArg("view_options", Tag_PaperColumn::basic_view_option_schema()))
        ];
    }
}
