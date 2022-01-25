<?php
// pc_tag.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
    /** @var ?string */
    private $emoji;
    /** @var bool */
    private $editsort = false;
    /** @var array<int,float> */
    private $sortmap;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
        $this->dtag = $cj->tag;
        $this->is_value = $cj->tagvalue ?? null;
    }
    function add_decoration($decor) {
        if ($decor === "edit") {
            $this->editable = true;
            $this->is_value = $this->is_value ?? true;
            return $this->__add_decoration($decor);
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
        if ($this->etag[0] == ":"
            && !$this->is_value
            && ($dt = $pl->user->conf->tags()->check($this->dtag))
            && isset($dt->emoji)
            && count($dt->emoji) === 1) {
            /** @phan-suppress-next-line PhanTypeArraySuspiciousNullable */
            $this->emoji = $dt->emoji[0];
        }
        if ($this->editable
            && !$pl->user->can_edit_tag_somewhere($this->etag)) {
            $pl->column_error("<0>Tag ‘#{$this->dtag}’ is read-only");
            if ($pl->conf->tags()->is_automatic($this->etag)) {
                if ($pl->conf->tags()->is_votish($this->etag)) {
                    $pl->column_error(new MessageItem(null, "<0>That tag is set automatically based on per-user votes. Did you mean to edit ‘#~{$this->dtag}’?", MessageSet::INFORM));
                } else {
                    $pl->column_error(new MessageItem(null, "<0>That tag is set automatically.", MessageSet::INFORM));
                }
            }
        }
        if ($this->editable
            && ($visible & PaperColumn::PREP_VISIBLE)
            && $pl->table_id()) {
            $pl->has_editable_tags = true;
            if (strcasecmp($this->etag, $pl->sort_etag()) === 0
                && $this->is_value) {
                $pl->table_attr["data-drag-tag"] = $this->dtag;
                $this->editsort = true;
            }
        }
        $this->className = ($this->editable ? "pl_edit" : "pl_")
            . ($this->is_value ? "tagval" : "tag");
        return true;
    }
    function completion_name() {
        return "#$this->dtag";
    }
    function sort_name() {
        return "#$this->dtag";
    }
    function prepare_sort(PaperList $pl, $sortindex) {
        $this->sortmap = [];
        $unviewable = $empty = TAG_INDEXBOUND * ($this->sort_reverse ? -1 : 1);
        if ($this->editable) {
            $empty = (TAG_INDEXBOUND - 1) * ($this->sort_reverse ? -1 : 1);
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
    function header(PaperList $pl, $is_text) {
        if (($twiddle = strpos($this->dtag, "~")) > 0) {
            $cid = (int) substr($this->dtag, 0, $twiddle);
            if ($cid == $pl->user->contactId) {
                return "#" . substr($this->dtag, $twiddle);
            } else if (($p = $pl->conf->cached_user_by_id($cid))) {
                if ($is_text) {
                    return $pl->user->reviewer_text_for($p) . " #" . substr($this->dtag, $twiddle);
                } else {
                    return $pl->user->reviewer_html_for($p) . "<br>#" . substr($this->dtag, $twiddle);
                }
            }
        }
        return "#$this->dtag";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_tag($row, $this->etag);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $v = $row->tag_value($this->etag);
        if ($this->editable
            && ($t = $this->edit_content($pl, $row, $v))) {
            return $t;
        } else if ($v === null) {
            return "";
        } else if ($v >= 0.0 && $this->emoji) {
            return Tagger::unparse_emoji_html($this->emoji, $v);
        } else if ($v === 0.0 && !$this->is_value) {
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
            return "<input type=\"checkbox\" class=\"uic js-range-click edittag\" data-range-type=\"tag:{$this->dtag}\" name=\"tag:{$this->dtag} {$row->paperId}\" value=\"x\" tabindex=\"2\""
                . ($v !== null ? ' checked="checked">' : '>');
        }
        $t = '<input type="text" class="edittagval';
        if ($this->editsort) {
            $t .= " need-draghandle";
            $pl->need_render = true;
        }
        return $t . '" size="4" name="tag:' . "$this->dtag $row->paperId" . '" value="'
            . ($v !== null ? htmlspecialchars((string) $v) : "") . '" tabindex="2">';
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

    static function expand($name, Contact $user, $xfj, $m) {
        $tsm = new TagSearchMatcher($user);
        $tsm->set_avoid_regex(true);
        $tsm->add_check_tag($m[2], true);
        $dt = $user->conf->tags();
        $rs = [];
        foreach ($tsm->expand() as $t) {
            $fj = (array) $xfj;
            $fj["name"] = $m[1] . $t;
            $fj["tag"] = $t;
            $fj["title"] = $dt->unparse($t, 0, $user, TagMap::UNPARSE_HASH | TagMap::UNPARSE_TEXT);
            $fj["title_html"] = $dt->unparse($t, 0, $user, TagMap::UNPARSE_HASH);
            $fj["sort"] = $fj["sort"] ?? true;
            $fj["function"] = $fj["function"] ?? "+Tag_PaperColumn";
            $rs[] = (object) $fj;
        }
        foreach ($tsm->error_texts() as $e) {
            PaperColumn::column_error($user, $e);
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
