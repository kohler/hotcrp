<?php
// search/st_option.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

abstract class Option_SearchTerm extends SearchTerm {
    /** @var Contact */
    protected $user;
    /** @var PaperOption */
    protected $option;

    /** @param string $type */
    function __construct(Contact $user, PaperOption $option, $type) {
        parent::__construct($type);
        $this->user = $user;
        $this->option = $option;
    }

    /** @return PaperOption */
    function option() {
        return $this->option;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_options_columns();
        if (!$this->option->include_empty) {
            return "exists (select * from PaperOption where paperId=Paper.paperId and optionId={$this->option->id})";
        } else {
            return "true";
        }
    }

    static function parse_factory($keyword, Contact $user, $kwfj, $m) {
        $f = $user->conf->find_all_fields($keyword);
        if (count($f) === 1 && $f[0] instanceof PaperOption) {
            return (object) [
                "name" => $keyword,
                "parse_function" => "Option_SearchTerm::parse",
                "has" => "any"
            ];
        } else {
            return null;
        }
    }
    /** @param list<PaperOption> $os */
    static private function make_present($os, Contact $user) {
        $sts = [];
        foreach ($os as $o) {
            $sts[] = new OptionPresent_SearchTerm($user, $o, count($os) > 1);
        }
        return SearchTerm::combine("or", $sts);
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        // option name and option content
        if ($sword->kwdef->name === "option") {
            if (!$sword->quoted && strcasecmp($word, "any") === 0) {
                return self::make_present($srch->conf->options()->normal(), $srch->user);
            } else if (!$sword->quoted && strcasecmp($word, "none") === 0) {
                return self::make_present($srch->conf->options()->normal(), $srch->user)->negate();
            } else if (preg_match('/\A(.*?)(?::|(?=[#=!<>]|≠|≤|≥))(.*)\z/s', $word, $m)) {
                $oname = $m[1];
                $ocontent = $m[2];
            } else {
                $oname = $word;
                $ocontent = "any";
            }
        } else {
            $oname = $sword->kwdef->name;
            $ocontent = $word;
        }

        // find options by name
        $os = $srch->conf->abbrev_matcher()->findp($oname, Conf::MFLAG_OPTION);
        if (empty($os)) {
            if (($os2 = $srch->conf->abbrev_matcher()->find_all($oname, Conf::MFLAG_OPTION))) {
                $ts = array_map(function ($o) { return "‘" . $o->search_keyword() . "’"; }, $os2);
                $srch->lwarning($sword, "<0>Submission field ‘{$oname}’ is ambiguous");
                $srch->message_set()->msg_at(null, "<0>Try " . commajoin($ts, " or ") . ", or use ‘{$oname}*’ if you mean to match them all.", MessageSet::INFORM);
            } else {
                $srch->lwarning($sword, "Submission field ‘{$oname}’ not found");
            }
            return new False_SearchTerm;
        }

        // handle any/none
        if (!$sword->quoted && strcasecmp($ocontent, "any") === 0) {
            return self::make_present($os, $srch->user);
        } else if (!$sword->quoted && strcasecmp($ocontent, "none") === 0) {
            return self::make_present($os, $srch->user)->negate();
        }

        // handle other searches
        if ($sword->quoted) {
            $sword->compar = "";
            $sword->cword = $ocontent;
        } else {
            preg_match('/\A(?:[=!<>]=?|≠|≤|≥)?/', $ocontent, $m);
            $sword->compar = $m[0] === "" ? "" : CountMatcher::canonical_relation($m[0]);
            $sword->cword = ltrim(substr($ocontent, strlen($m[0])));
        }

        $ts = [];
        foreach ($os as $o) {
            $nwarn = $srch->message_set()->message_count();
            if (($st = $o->parse_search($sword, $srch))) {
                $ts[] = $st;
            } else if ($nwarn === $srch->message_set()->message_count()) {
                $srch->lwarning($sword, "<0>Submission field ‘{$oname}’ does not support this search");
            }
        }
        return SearchTerm::combine("or", $ts);
    }
}
