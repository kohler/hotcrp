<?php
// search/st_formula.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Formula_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var Formula */
    private $formula;
    function __construct(Formula $formula) {
        parent::__construct("formula");
        $this->user = $formula->user;
        $this->formula = $formula;
        $formula->prepare();
    }
    /** @param string $word
     * @param SearchWord $sword
     * @param PaperSearch $srch
     * @param bool $is_graph
     * @return ?Formula */
    static private function read_formula($word, $sword, $srch, $is_graph) {
        $nf = null;
        if (preg_match('/\A[^(){}\[\]]+\z/', $word)) {
            $nf = $srch->conf->find_named_formula($word);
        }
        // the formula parses within the search's string context: its errors
        // expand from this word, and its parse is charged to the search
        $config = (new FormulaConfig)->set_use_viewer_permissions($srch->use_viewer_permissions())
            ->set_string_context($sword->string_context->child($sword->kwpos1, $sword->pos2));
        if ($nf) {
            $formula = $nf->realize($srch->user, $config);
        } else {
            if ($is_graph) {
                $config->set_allow_indexed(true);
            }
            $formula = Formula::make($srch->user, $word, $config);
        }
        $srch->message_set()->append_list($formula->message_list());
        return $formula->ok() ? $formula : null;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (($formula = self::read_formula($word, $sword, $srch, false))) {
            return new Formula_SearchTerm($formula);
        }
        return new False_SearchTerm;
    }
    static function parse_graph($word, SearchWord $sword, PaperSearch $srch) {
        if (self::read_formula($word, $sword, $srch, true)) {
            return (new True_SearchTerm)->add_view_anno("show:graph({$word})", $sword);
        }
        return null;
    }
    function paper_options(&$oids) {
        $this->formula->paper_options($oids);
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $this->formula->add_query_options($sqi->query_options);
        return "true";
    }
    function need_pretest() {
        return true;
    }
    function pretest(PaperInfo $row, $xinfo) {
        return null;
    }
    function test(PaperInfo $row, $xinfo) {
        if ($xinfo && $xinfo instanceof ReviewInfo) {
            return !!$this->formula->eval($row, $xinfo->contactId);
        }
        return !!$this->formula->eval($row, null);
    }
}
