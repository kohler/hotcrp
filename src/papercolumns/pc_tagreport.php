<?php
// pc_tagreport.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class TagReport_PaperColumn extends PaperColumn {
    private $tag;
    private $viewtype;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_FOLD_IFEMPTY;
        $this->tag = $cj->tag;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_any_peruser_tags($this->tag))
            return false;
        if ($visible)
            $pl->qopts["tags"] = 1;
        $dt = $pl->conf->tags()->check($this->tag);
        if (!$dt || $dt->rank || (!$dt->vote && !$dt->approval))
            $this->viewtype = 0;
        else
            $this->viewtype = $dt->approval ? 1 : 2;
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "#~" . $this->tag . " report";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_peruser_tags($row, $this->tag);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $a = [];
        preg_match_all('/ (\d+)~' . preg_quote($this->tag) . '#(\S+)/i', $row->all_tags_text(), $m);
        for ($i = 0; $i != count($m[0]); ++$i) {
            if ($this->viewtype == 2 && $m[2][$i] <= 0)
                continue;
            $n = $pl->user->name_html_for($m[1][$i]);
            if ($this->viewtype != 1)
                $n .= " (" . $m[2][$i] . ")";
            $a[$m[1][$i]] = $n;
        }
        if (empty($a))
            return "";
        $pl->user->ksort_cid_array($a);
        return '<span class="nb">' . join(',</span> <span class="nb">', $a) . '</span>';
    }
}

class TagReport_PaperColumnFactory {
    static private function column_json($xfj, $tag) {
        $cj = (array) $xfj;
        $cj["name"] = "tagreport:" . strtolower($tag);
        $cj["tag"] = $tag;
        return (object) $cj;
    }
    static function expand($name, Conf $conf, $xfj, $m) {
        if (!$conf->xt_user->can_view_most_tags())
            return null;
        $tagset = $conf->tags();
        if ($name === "tagreports") {
            $conf->xt_factory_mark_matched();
            return array_map(function ($t) use ($xfj) {
                return self::column_json($xfj, $t->tag);
            }, $tagset->filter_by(function ($t) {
                return $t->vote || $t->approval || $t->rank;
            }));
        } else {
            $t = $tagset->check($m[1]);
            if ($t && ($t->vote || $t->approval || $t->rank))
                return self::column_json($xfj, $m[1]);
            else
                return null;
        }
    }
}
