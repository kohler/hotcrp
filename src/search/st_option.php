<?php
// search/st_option.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Option_SearchTerm extends SearchTerm {
    /** @var PaperOption */
    protected $option;

    /** @param string $type */
    function __construct($type, PaperOption $option) {
        parent::__construct($type);
        $this->option = $option;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_options_columns();
        if (!$sqi->negated && !$this->option->include_empty) {
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
                "parse_callback" => "Option_SearchTerm::parse",
                "has" => "any"
            ];
        } else {
            return null;
        }
    }
    /** @param list<PaperOption> $os */
    static private function make_present($os) {
        $sts = [];
        foreach ($os as $o) {
            $sts[] = new OptionPresent_SearchTerm($o, count($os) > 1);
        }
        return SearchTerm::combine("or", $sts);
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        // option name and option content
        if ($sword->kwdef->name === "option") {
            if (!$sword->quoted && strcasecmp($word, "any") === 0) {
                return self::make_present($srch->conf->options()->normal());
            } else if (!$sword->quoted && strcasecmp($word, "none") === 0) {
                return self::make_present($srch->conf->options()->normal())->negate();
            } else if (preg_match('/\A(.*?)(?::|(?=[#=!<>]|≠|≤|≥))(.*)\z/', $word, $m)) {
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
                $ts = array_map(function ($o) { return "“" . htmlspecialchars($o->search_keyword()) . "”"; }, $os2);
                $srch->warn("“" . htmlspecialchars($oname) . "” matches more than one submission field. Try " . commajoin($ts, " or ") . ", or use “" . htmlspecialchars($oname) . "*” if you mean to match them all.");
            } else {
                $srch->warn("“" . htmlspecialchars($oname) . "” matches no submission fields.");
            }
            return new False_SearchTerm;
        }

        // handle any/none
        if (!$sword->quoted && strcasecmp($ocontent, "any") === 0) {
            return self::make_present($os);
        } else if (!$sword->quoted && strcasecmp($ocontent, "none") === 0) {
            return self::make_present($os)->negate();
        }

        // handle other searches
        if ($sword->quoted) {
            $sword->compar = "";
            $sword->cword = $ocontent;
        } else {
            preg_match('/\A(?:[=!<>]=?|≠|≤|≥)?/', $ocontent, $m);
            $sword->compar = $m[0] === "" ? "" : CountMatcher::canonical_comparator($m[0]);
            $sword->cword = ltrim(substr($ocontent, strlen($m[0])));
        }

        $ts = [];
        foreach ($os as $o) {
            $nwarn = $srch->message_count();
            if (($st = $o->parse_search($sword, $srch))) {
                $ts[] = $st;
            } else if ($nwarn === $srch->message_count()) {
                $srch->warn("Submission field " . htmlspecialchars($o->search_keyword()) . " (" . $o->title_html() . ") does not understand search “" . htmlspecialchars($ocontent) . "”.");
            }
        }
        return SearchTerm::combine("or", $ts);
    }
}
