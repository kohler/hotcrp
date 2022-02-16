<?php
// pc_tagreport.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class TagReport_PaperColumn extends PaperColumn {
    /** @var string */
    private $tag;
    /** @var int */
    private $viewtype;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
        $this->tag = $cj->tag;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_peruser_tag(null, $this->tag)) {
            return false;
        }
        if ($visible) {
            $pl->qopts["tags"] = 1;
        }
        $dt = $pl->conf->tags()->check($this->tag);
        if (!$dt || $dt->rank || (!$dt->allotment && !$dt->approval)) {
            $this->viewtype = 0;
        } else {
            $this->viewtype = $dt->approval ? 1 : 2;
        }
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "#~" . $this->tag . " report";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_peruser_tag($row, $this->tag);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $a = [];
        preg_match_all('/ (\d+)~' . preg_quote($this->tag) . '#(\S+)/i', $row->all_tags_text(), $m);
        for ($i = 0; $i != count($m[0]); ++$i) {
            if ($this->viewtype == 2 && $m[2][$i] <= 0) {
                continue;
            }
            $n = $pl->user->reviewer_html_for((int) $m[1][$i]);
            if ($this->viewtype != 1) {
                $n .= " (" . $m[2][$i] . ")";
            }
            $a[intval($m[1][$i])] = $n;
        }
        if (empty($a)) {
            return "";
        } else {
            $pl->user->ksort_cid_array($a);
            return '<span class="nb">' . join(',</span> <span class="nb">', $a) . '</span>';
        }
    }
}

class TagReport_PaperColumnFactory {
    static private function column_json($xfj, $tag) {
        $cj = (array) $xfj;
        $cj["name"] = "tagreport:" . $tag;
        $cj["tag"] = $tag;
        return (object) $cj;
    }
    static function expand($name, Contact $user, $xfj, $m) {
        if (!$user->can_view_most_tags()) {
            return null;
        }
        $tagset = $user->conf->tags();
        if ($name === "tagreports") {
            return array_map(function ($t) use ($xfj) {
                return self::column_json($xfj, $t->tag);
            }, $tagset->filter_by(function ($t) {
                return $t->allotment || $t->approval || $t->rank;
            }));
        } else {
            $t = $tagset->check($m[1]);
            if ($t && ($t->allotment || $t->approval || $t->rank)) {
                return self::column_json($xfj, $m[1]);
            } else {
                return null;
            }
        }
    }
}
