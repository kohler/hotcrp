<?php
// pc_option.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

class Option_PaperColumn extends PaperColumn {
    private $opt;
    private $fr;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
        $this->opt = $conf->paper_opts->get($cj->option_id);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_some_option($this->opt))
            return false;
        $pl->qopts["options"] = true;
        $this->fr = new FieldRender(0);
        $optcj = $this->opt->list_display($this->row);
        if (is_array($optcj) && isset($optcj["className"])) {
            $this->className = $optcj["className"];
        } else {
            $this->className = "pl_option";
        }
        return true;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        return $this->opt->value_compare($a->option($this->opt),
                                         $b->option($this->opt));
    }
    function header(PaperList $pl, $is_text) {
        return $is_text ? $this->opt->title() : $this->opt->title_html();
    }
    function completion_name() {
        return $this->opt->search_keyword();
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_option($row, $this->opt);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $ov = $row->option($this->opt);
        if (!$ov) {
            return "";
        }

        $fr = $this->fr;
        $fr->clear(FieldRender::CFLIST | FieldRender::CFHTML | ($this->viewable_row() ? 0 : FieldRender::CFCOLUMN));
        $this->opt->render($fr, $ov);
        if ((string) $fr->value === "") {
            return "";
        }

        $klass = "";
        if ($fr->value_long) {
            $klass = strlen($fr->value) > 190 ? "pl_longtext" : "pl_shorttext";
        }
        if ($fr->value_format !== 0 && $fr->value_format !== 5) {
            $pl->need_render = true;
            $klass .= ($klass === "" ? "" : " ") . "need-format";
            return '<div class="' . $klass . ($klass === "" ? "" : " ")
                . 'need-format" data-format="' . $fr->value_format . '">'
                . htmlspecialchars($fr->value) . '</div>';
        } else if (!$fr->value_long) {
            return $fr->value_html();
        } else if ($fr->value_format === 0) {
            return '<div class="' . $klass . ' format0">'
                . Ht::format0($fr->value) . '</div>';
        } else {
            return '<div class="' . $klass . '">' . $fr->value . '</div>';
        }
    }
    function text(PaperList $pl, PaperInfo $row) {
        $ov = $row->option($this->opt);
        if (!$ov) {
            return "";
        }

        $this->fr->clear(FieldRender::CFCSV | FieldRender::CFVERBOSE);
        $this->opt->render($this->fr, $ov);
        return (string) $this->fr->value;
    }
}

class Option_PaperColumnFactory {
    static private function option_json($xfj, PaperOption $opt) {
        $cj = (array) $xfj;
        $cj["name"] = $opt->search_keyword();
        $cj["option_id"] = $opt->id;
        $optcj = $opt->list_display(null);
        if ($optcj === true) {
            $cj["column"] = true;
        } else if (is_array($optcj)) {
            $cj += $optcj;
        }
        return (object) $cj;
    }
    static function expand($name, $user, $xfj, $m) {
        list($ocolon, $oname) = [$m[1], $m[2]];
        if (!$ocolon && $oname === "options") {
            $x = [];
            foreach ($user->user_option_list() as $opt) {
                if ($opt->display_position() !== false
                    && $opt->list_display(null)
                    && $opt->example_searches())
                    $x[] = self::option_json($xfj, $opt);
            }
            return $x;
        }
        $opts = $user->conf->paper_opts->find_all($oname);
        if (count($opts) == 1) {
            reset($opts);
            $opt = current($opts);
            if ($opt->display_position() !== false
                && $opt->list_display(null))
                return self::option_json($xfj, $opt);
            $user->conf->xt_factory_error("Option “" . htmlspecialchars($oname) . "” can’t be displayed.");
        } else if ($ocolon) {
            $user->conf->xt_factory_error("No such option “" . htmlspecialchars($oname) . "”.");
        }
        return null;
    }
    static function completions(Contact $user, $fxt) {
        $cs = array_map(function ($opt) {
            return $opt->search_keyword();
        }, array_filter($user->user_option_list(), function ($opt) {
            return $opt->display_position() !== false && $opt->example_searches();
        }));
        if (!empty($cs))
            array_unshift($cs, "options");
        return $cs;
    }
}
