<?php
// search/st_formula.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Formula_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var Formula */
    private $formula;
    private $function;
    function __construct(Formula $formula) {
        parent::__construct("formula");
        $this->user = $formula->user;
        $this->formula = $formula;
        $this->function = $formula->compile_function();
    }
    /** @param string $word
     * @param SearchWord $sword
     * @param PaperSearch $srch
     * @param bool $is_graph
     * @return ?Formula */
    static private function read_formula($word, $sword, $srch, $is_graph) {
        $formula = null;
        if (preg_match('/\A[^(){}\[\]]+\z/', $word)) {
            $formula = $srch->conf->find_named_formula($word);
        }
        if (!$formula) {
            $formula = new Formula($word, $is_graph ? Formula::ALLOW_INDEXED : 0);
        }
        if (!$formula->check($srch->user)) {
            $srch->lwarning($sword, "<0>Invalid formula matches no submissions");
            foreach ($formula->message_list() as $mi) {
                $mi = $srch->message_set()->append_item($mi->with(["problem_status" => MessageSet::WARNING]));
                if ($mi->pos1 !== null) {
                    $mi->pos1 += $sword->pos1w;
                    $mi->pos2 += $sword->pos1w;
                    $mi->context = $srch->q;
                }
            }
            $formula = null;
        }
        return $formula;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (($formula = self::read_formula($word, $sword, $srch, false))) {
            return new Formula_SearchTerm($formula);
        }
        return new False_SearchTerm;
    }
    static function parse_graph($word, SearchWord $sword, PaperSearch $srch) {
        if (($formula = self::read_formula($word, $sword, $srch, true))) {
            return (new True_SearchTerm)->add_view_anno("show:graph($word)", $sword);
        }
        return null;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $this->formula->add_query_options($sqi->query_options);
        return "true";
    }
    function test(PaperInfo $row, $rrow) {
        $formulaf = $this->function;
        return !!$formulaf($row, $rrow ? $rrow->contactId : null, $this->user);
    }
}
