<?php
// pc_tag.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

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
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
        $this->dtag = $cj->tag;
        $this->is_value = get($cj, "tagvalue");
    }
    function mark_editable() {
        $this->editable = true;
        if ($this->is_value === null) {
            $this->is_value = true;
        }
    }
    function sorts_my_tag($sorter, Contact $user) {
        return strcasecmp(Tagger::check_tag_keyword($sorter->type, $user, Tagger::NOVALUE | Tagger::ALLOWCONTACTID), $this->ltag) == 0;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_tags(null)) {
            return false;
        }
        $tagger = new Tagger($pl->user);
        if (!($ctag = $tagger->check($this->dtag, Tagger::NOVALUE | Tagger::ALLOWCONTACTID))) {
            return false;
        }
        $this->ltag = strtolower($ctag);
        $this->ctag = " {$this->ltag}#";
        if ($visible) {
            $pl->qopts["tags"] = 1;
        }
        if ($this->ltag[0] == ":"
            && !$this->is_value
            && ($dt = $pl->user->conf->tags()->check($this->dtag))
            && $dt->emoji !== null
            && count($dt->emoji) === 1) {
            $this->emoji = $dt->emoji[0];
        }
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
    function sort_name(PaperList $pl, ListSorter $sorter = null) {
        return "#$this->dtag";
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        $k = $sorter->uid;
        $unviewable = $empty = TAG_INDEXBOUND * ($sorter->reverse ? -1 : 1);
        if ($this->editable) {
            $empty = (TAG_INDEXBOUND - 1) * ($sorter->reverse ? -1 : 1);
        }
        foreach ($rows as $row) {
            if (!$pl->user->can_view_tag($row, $this->ltag)) {
                $row->$k = $unviewable;
            } else if (($row->$k = $row->tag_value($this->ltag)) === false) {
                $row->$k = $empty;
            }
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $k = $sorter->uid;
        return $a->$k < $b->$k ? -1 : ($a->$k == $b->$k ? 0 : 1);
    }
    function hexxxxader(PaperList $pl, $is_text) {
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
        return !$pl->user->can_view_tag($row, $this->ltag);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $v = $row->tag_value($this->ltag);
        if ($this->editable
            && ($t = $this->edit_content($pl, $row, $v))) {
            return $t;
        } else if ($v === false) {
            return "";
        } else if ($v >= 0.0 && $this->emoji) {
            return Tagger::unparse_emoji_html($this->emoji, $v);
        } else if ($v === 0.0 && !$this->is_value) {
            return "✓";
        } else {
            return $v;
        }
    }
    private function edit_content($pl, $row, $v) {
        if (!$pl->user->can_change_tag($row, $this->dtag, 0, 0)) {
            return false;
        }
        if (!$this->is_value) {
            return "<input type=\"checkbox\" class=\"uic js-range-click edittag\" data-range-type=\"tag:{$this->dtag}\" name=\"tag:{$this->dtag} {$row->paperId}\" value=\"x\" tabindex=\"2\""
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
        if (($v = $row->tag_value($this->ltag)) === false) {
            return "";
        } else if ($v === 0.0 && !$this->is_value) {
            return "Y";
        } else {
            return $v;
        }
    }

    static function expand($name, $user, $xfj, $m) {
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
            $rs[] = (object) $fj;
        }
        foreach ($tsm->errors() as $e) {
            $user->conf->xt_factory_error($e);
        }
        return $rs;
    }
}
