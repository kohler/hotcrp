<?php
// pc_option.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Option_PaperColumn extends PaperColumn {
    private $opt;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_FOLD_IFEMPTY;
        $this->opt = $conf->paper_opts->get($cj->option_id);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_some_paper_option($this->opt))
            return false;
        $pl->qopts["options"] = true;
        return true;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        return $this->opt->value_compare($a->option($this->opt->id),
                                         $b->option($this->opt->id));
    }
    function header(PaperList $pl, $is_text) {
        return $is_text ? $this->opt->title : htmlspecialchars($this->opt->title);
    }
    function completion_name() {
        return $this->opt->search_keyword();
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_paper_option($row, $this->opt);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $this->opt->unparse_list_html($pl, $row, $this->viewable_row());
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $this->opt->unparse_list_text($pl, $row);
    }
}

class Option_PaperColumnFactory {
    static private function option_json($xfj, PaperOption $opt, $isrow) {
        $cj = (array) $xfj;
        $cj["name"] = $opt->search_keyword() . ($isrow ? ":row" : "");
        if ($isrow)
            $cj["row"] = true;
        $optcj = $opt->list_display($isrow);
        if ($optcj === true && !$isrow)
            $optcj = ["column" => true, "className" => "pl_option"];
        if (is_array($optcj))
            $cj += $optcj;
        $cj["option_id"] = $opt->id;
        return (object) $cj;
    }
    static function expand($name, Conf $conf, $xfj, $m) {
        list($ocolon, $oname, $isrow) = [$m[1], $m[2], !!$m[3]];
        if (!$ocolon && $oname === "options") {
            $conf->xt_factory_mark_matched();
            $x = [];
            foreach ($conf->xt_user->user_option_list() as $opt)
                if ($opt->display() >= 0 && $opt->list_display($isrow))
                    $x[] = self::option_json($xfj, $opt, $isrow);
            return $x;
        }
        $opts = $conf->paper_opts->find_all($oname);
        if (!$opts && $isrow) {
            $oname .= $m[3];
            $opts = $conf->paper_opts->find_all($oname);
        }
        if (count($opts) == 1) {
            reset($opts);
            $opt = current($opts);
            if ($opt->display() >= 0 && $opt->list_display($isrow))
                return self::option_json($xfj, $opt, $isrow);
            $conf->xt_factory_error("Option “" . htmlspecialchars($oname) . "” can’t be displayed.");
        } else if ($ocolon)
            $conf->xt_factory_error("No such option “" . htmlspecialchars($oname) . "”.");
        return null;
    }
    static function completions(Contact $user, $fxt) {
        $cs = array_map(function ($opt) {
            return $opt->search_keyword();
        }, array_filter($user->user_option_list(), function ($opt) {
            return $opt->display() >= 0;
        }));
        if (!empty($cs))
            array_unshift($cs, "options");
        return $cs;
    }
}
