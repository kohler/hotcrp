<?php
// pc_option.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Option_PaperColumn extends PaperColumn {
    /** @var PaperOption */
    private $opt;
    /** @var FieldRender */
    private $fr;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
        $this->opt = $conf->checked_option_by_id($cj->option_id);
    }
    function add_decoration($decor) {
        /* XXX ask PaperOption what decorations are supported */
        return parent::add_decoration($decor)
            || $this->__add_decoration($decor);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_some_option($this->opt)) {
            return false;
        }
        $pl->qopts["options"] = true;
        if ($visible === self::PREP_VISIBLE) {
            $this->fr = new FieldRender($pl->render_context | ($this->as_row ? FieldRender::CFROW : FieldRender::CFCOLUMN), $pl->user);
            $this->fr->make_column($this);
        }
        if ($this->as_row) {
            $this->className = ltrim(preg_replace('/(?: +|\A)(?:plrd|plr|plc)(?= |\z)/', "", $this->className));
        }
        return true;
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
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
        $fr->clear();
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

        $this->fr->clear();
        $this->opt->render($this->fr, $ov);
        return (string) $this->fr->value;
    }
}

class Option_PaperColumnFactory {
    static private function option_json($xfj, PaperOption $opt) {
        $cj = (array) $xfj;
        $cj["name"] = $opt->search_keyword();
        $cj["option_id"] = $opt->id;
        $cs = [];
        foreach ($opt->classes as $k) {
            if (str_starts_with($k, "pl"))
                $cs[] = $k;
        }
        $cj["className"] = join(" ", $cs);
        $cj["prefer_row"] = array_search("prefer-row", $opt->classes, true) !== false;
        return (object) $cj;
    }
    static function expand($name, XtParams $xtp, $xfj, $m) {
        list($ocolon, $oname) = [$m[1], $m[2]];
        if (!$ocolon && $oname === "options") {
            $x = [];
            foreach ($xtp->conf->options() as $opt) {
                if ($xtp->user->can_view_some_option($opt)
                    && $opt->on_render_context(FieldRender::CFLIST))
                    $x[] = self::option_json($xfj, $opt);
            }
            return $x;
        }
        $opts = $xtp->conf->options()->find_all($oname);
        if (count($opts) == 1) {
            reset($opts);
            $opt = current($opts);
            if ($opt->on_render_context(FieldRender::CFLIST)) {
                return self::option_json($xfj, $opt);
            }
            PaperColumn::column_error($xtp, "<0>Submission field ‘{$oname}’ can’t be displayed");
        } else if ($ocolon) {
            PaperColumn::column_error($xtp, "<0>Submission field ‘{$oname}’ not found");
        }
        return null;
    }
    static function completions(Contact $user, $fxt) {
        $cs = [];
        foreach ($user->conf->options() as $opt) {
            if ($user->can_view_some_option($opt)
                && $opt->search_keyword() !== false
                && $opt->on_render_context(FieldRender::CFSUGGEST)) {
                $cs[] = $opt->search_keyword();
            }
        }
        return empty($cs) ? $cs : array_merge(["options"], $cs);
    }
}
