<?php
// papersearch.php -- HotCRP helper class for searching for papers
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SearchOperator {
    var $op;
    var $unary;
    var $precedence;
    function __construct($what, $unary, $precedence) {
        $this->op = $what;
        $this->unary = $unary;
        $this->precedence = $precedence;
    }
}

global $searchOperators;
$searchOperators = array("(" => new SearchOperator("(", true, null),
                         "NOT" => new SearchOperator("not", true, 6),
                         "-" => new SearchOperator("not", true, 6),
                         "+" => new SearchOperator("+", true, 6),
                         "SPACE" => new SearchOperator("and", false, 5),
                         "AND" => new SearchOperator("and", false, 4),
                         "OR" => new SearchOperator("or", false, 3),
                         "XAND" => new SearchOperator("and", false, 2),
                         "XOR" => new SearchOperator("or", false, 2),
                         "THEN" => new SearchOperator("then", false, 1),
                         ")" => null);

class SearchTerm {
    var $type;
    var $link;
    var $flags;
    var $value;

    function __construct($t, $f = 0, $v = null, $other = null) {
        $this->type = $t;
        $this->link = false;
        $this->flags = $f;
        $this->value = $v;
        if ($other) {
            foreach ($other as $k => $v)
                $this->$k = $v;
        }
    }
    static function combine($combiner, $terms) {
        if (!is_array($terms) && $terms)
            $terms = array($terms);
        if (count($terms) == 0)
            return null;
        else if ($combiner == "not") {
            assert(count($terms) == 1);
            return self::negate($terms[0]);
        } else if (count($terms) == 1)
            return $terms[0];
        else
            return new SearchTerm($combiner, 0, $terms);
    }
    static function negate($term) {
        if (!$term)
            return null;
        else if ($term->type == "not")
            return $term->value;
        else if ($term->type == "f")
            return new SearchTerm("t");
        else if ($term->type == "t")
            return new SearchTerm("f");
        else
            return new SearchTerm("not", 0, $term);
    }
    static function make_float($float) {
        return new SearchTerm("float", 0, null, array("float" => $float));
    }
    static function merge_float(&$float1, $float2) {
        if (!$float1 || !$float2)
            return $float1 ? $float1 : $float2;
        else
            return array_replace_recursive($float1, $float2);
    }
    static function extract_float(&$float, $qe) {
        if (!isset($float))
            $float = null;
        if ($qe && ($qefloat = $qe->get("float"))) {
            $float = self::merge_float($float, $qefloat);
            return $qe->type == "float" ? null : $qe;
        } else
            return $qe;
    }
    static function combine_float($float, $combiner, $terms) {
        $qe = self::combine($combiner, $terms);
        if ($float && !$qe)
            return SearchTerm::make_float($float);
        else {
            if ($float)
                $qe->set("float", $float);
            return $qe;
        }
    }
    function isfalse() {
        return $this->type == "f";
    }
    function islistcombiner() {
        return $this->type == "and" || $this->type == "or" || $this->type == "then";
    }
    function set($k, $v) {
        $this->$k = $v;
    }
    function get($k, $defval = null) {
        return isset($this->$k) ? $this->$k : $defval;
    }
    function set_float($k, $v) {
        if (!isset($this->float))
            $this->float = array();
        $this->float[$k] = $v;
    }
    function get_float($k, $defval = null) {
        if (isset($this->float) && isset($this->float[$k]))
            return $this->float[$k];
        else
            return $defval;
    }
}

class SearchReviewValue {
    public $countexpr;
    public $contactsql;
    private $_contactset;
    public $fieldsql;
    public $compar = 0;
    public $allowed = 0;
    public $view_score;

    function __construct($countexpr, $contacts = null, $fieldsql = null,
                         $view_score = null) {
        $this->countexpr = $countexpr;
        if (!$contacts || is_string($contacts))
            $this->contactsql = $contacts;
        else
            $this->contactsql = sql_in_numeric_set($contacts);
        $this->_contactset = $contacts;
        $this->fieldsql = $fieldsql;
        if (preg_match('/\A([!<>]?=|[<>])(-?\d+)\z/', $countexpr, $m)) {
            if ($m[1] == "!=" || $m[1] == ">" || $m[1] == ">=")
                $this->allowed |= 4;
            if ($m[1] == "=" || $m[1] == ">=" || $m[1] == "<=")
                $this->allowed |= 2;
            if ($m[1] == "!=" || $m[1] == "<" || $m[1] == "<=")
                $this->allowed |= 1;
            $this->compar = (int) $m[2];
        }
        $this->view_score = $view_score;
    }
    function test($n) {
        if ($n > $this->compar)
            return ($this->allowed & 4) != 0;
        else if ($n == $this->compar)
            return ($this->allowed & 2) != 0;
        else
            return ($this->allowed & 1) != 0;
    }
    public function conservative_countexpr() {
        if ($this->allowed & 1)
            return ">=0";
        else
            return ($this->allowed & 2 ? ">=" : ">") . $this->compar;
    }
    static function negateCountexpr($countexpr) {
        $t = new SearchReviewValue($countexpr);
        if ($t->allowed) {
            $x = array("", "<", "=", "<=", ">", "!=", ">=");
            return $x[$t->allowed ^ 7] . $t->compar;
        } else
            return $countexpr;
    }
    function onlyContact($contactid) {
        return is_array($this->_contactset) && count($this->_contactset) == 1
            && $this->_contactset[0] == $contactid;
    }
    function restrictContact($contactid) {
        if (!$this->_contactset)
            $cset = array($contactid);
        else if (!is_array($this->_contactset))
            $cset = $this->_contactset . " and \3=$contactid";
        else if (in_array($contactid, $this->_contactset))
            $cset = array($contactid);
        else
            return null;
        return new SearchReviewValue($this->countexpr, $cset, $this->fieldsql);
    }
    function contactWhere($fieldname) {
        return str_replace("\3", $fieldname, "\3" . $this->contactsql);
    }
    static function any() {
        return new SearchReviewValue(">0", null);
    }
}

class SearchQueryInfo {
    public $tables = array();
    public $columns = array();
    public $negated = false;

    public function add_table($table, $joiner = false) {
        assert($joiner || !count($this->tables));
        $this->tables[$table] = $joiner;
    }
    public function add_column($name, $expr) {
        assert(!isset($this->columns[$name]) || $this->columns[$name] == $expr);
        $this->columns[$name] = $expr;
    }
    public function add_manager_column() {
        global $Conf;
        if (!isset($this->columns["managerContactId"]))
            $this->columns["managerContactId"] = "Paper.managerContactId";
    }
}

class SearchContactMatch {
    public $sql;
    public $pc_only;
    public $ids = null;

    public function __construct($sql, $pc_only = false) {
        $this->sql = $sql;
        $this->pc_only = $pc_only;
    }
}

class PaperSearch {

    const F_REVIEWTYPEMASK = 0x00007;
    const F_COMPLETE = 0x00008;
    const F_INCOMPLETE = 0x00010;
    const F_NONCONFLICT = 0x00020;
    const F_AUTHOR = 0x00040;
    const F_REVIEWER = 0x00080;
    const F_AUTHORCOMMENT = 0x00100;
    const F_ALLOWRESPONSE = 0x00200;
    const F_ALLOWCOMMENT = 0x00400;
    const F_FALSE = 0x1000;
    const F_XVIEW = 0x2000;

    var $contact;
    public $cid;
    private $contactId;         // for backward compatibility
    var $privChair;
    private $amPC;

    var $limitName;
    var $qt;
    var $allowAuthor;
    var $fields;
    var $orderTags = array();
    private $_reviewer;
    private $_reviewer_fixed;
    var $matchPreg;
    private $urlbase;
    public $warnings = array();

    var $q;

    var $regex = array();
    public $overrideMatchPreg = false;
    private $contact_match = array();
    private $noratings = false;
    private $interestingRatings = array();
    private $needflags;
    private $reviewAdjust = false;
    private $_reviewAdjustError = false;
    private $_thenError = false;
    private $_ssRecursion = array();
    var $thenmap = null;
    var $headingmap = null;
    var $viewmap;

    private $_matchTable = null;

    static private $_sort_keywords = null;
    static private $_table_number = 0;

    static private $_keywords = array("ti" => "ti", "title" => "ti",
        "ab" => "ab", "abstract" => "ab",
        "au" => "au", "author" => "au",
        "co" => "co", "collab" => "co", "collaborators" => "co",
        "re" => "re", "rev" => "re", "review" => "re",
        "sre" => "cre", "srev" => "cre", "sreview" => "cre",
        "cre" => "cre", "crev" => "cre", "creview" => "cre",
        "subre" => "cre", "subrev" => "cre", "subreview" => "cre",
        "ire" => "ire", "irev" => "ire", "ireview" => "ire",
        "pri" => "pri", "primary" => "pri", "prire" => "pri", "prirev" => "pri",
        "cpri" => "cpri", "cprimary" => "cpri",
        "ipri" => "ipri", "iprimary" => "ipri",
        "sec" => "sec", "secondary" => "sec", "secre" => "sec", "secrev" => "sec",
        "csec" => "csec", "csecondary" => "csec",
        "isec" => "isec", "isecondary" => "isec",
        "ext" => "ext", "external" => "ext", "extre" => "ext", "extrev" => "ext",
        "cext" => "cext", "cexternal" => "cext",
        "iext" => "iext", "iexternal" => "iext",
        "cmt" => "cmt", "comment" => "cmt",
        "aucmt" => "aucmt", "aucomment" => "aucmt",
        "resp" => "response", "response" => "response",
        "anycmt" => "anycmt", "anycomment" => "anycmt",
        "tag" => "tag",
        "notag" => "notag",
        "ord" => "order", "order" => "order",
        "rord" => "rorder", "rorder" => "rorder",
        "revord" => "rorder", "revorder" => "rorder",
        "decision" => "decision", "dec" => "decision",
        "topic" => "topic",
        "option" => "option", "opt" => "option",
        "manager" => "manager", "admin" => "manager", "administrator" => "manager",
        "lead" => "lead",
        "shepherd" => "shepherd", "shep" => "shepherd",
        "conflict" => "conflict", "conf" => "conflict",
        "reconflict" => "reconflict", "reconf" => "reconflict",
        "pcconflict" => "pcconflict", "pcconf" => "pcconflict",
        "status" => "status", "has" => "has",
        "rating" => "rate", "rate" => "rate",
        "revpref" => "revpref", "pref" => "revpref",
        "repref" => "revpref",
        "ss" => "ss", "search" => "ss",
        "HEADING" => "HEADING", "heading" => "HEADING",
        "show" => "show", "VIEW" => "show", "view" => "show",
        "hide" => "hide", "edit" => "edit",
        "sort" => "sort", "showsort" => "showsort",
        "sortshow" => "showsort", "editsort" => "editsort",
        "sortedit" => "editsort");
    static private $_noheading_keywords = array(
        "HEADING" => "HEADING", "heading" => "HEADING",
        "show" => "show", "VIEW" => "show", "view" => "show",
        "hide" => "hide", "edit" => "edit",
        "sort" => "sort", "showsort" => "showsort",
        "sortshow" => "showsort", "editsort" => "editsort",
        "sortedit" => "editsort");


    function __construct($me, $opt) {
        global $Conf;
        if (is_string($opt))
            $opt = array("q" => $opt);

        // contact facts
        $this->contact = $me;
        $this->privChair = $me->privChair;
        $this->amPC = $me->isPC;
        $this->cid = $me->contactId;

        // paper selection
        $ptype = defval($opt, "t", "");
        if ($ptype === 0)
            $ptype = "";
        if ($this->privChair && !$ptype && $Conf->timeUpdatePaper())
            $this->limitName = "all";
        else if (($me->privChair && $ptype == "act")
                 || ($me->isPC
                     && (!$ptype || $ptype == "act" || $ptype == "all")
                     && $Conf->can_pc_see_all_submissions()))
            $this->limitName = "act";
        else if ($me->privChair && $ptype == "unm")
            $this->limitName = "unm";
        else if ($me->isPC && (!$ptype || $ptype == "s" || $ptype == "unm"))
            $this->limitName = "s";
        else if ($me->isPC && ($ptype == "und" || $ptype == "undec"))
            $this->limitName = "und";
        else if ($me->isPC && ($ptype == "acc" || $ptype == "revs"
                               || $ptype == "reqrevs" || $ptype == "req"
                               || $ptype == "lead" || $ptype == "rable"
                               || $ptype == "manager"))
            $this->limitName = $ptype;
        else if ($this->privChair && ($ptype == "all" || $ptype == "unsub"))
            $this->limitName = $ptype;
        else if ($ptype == "r" || $ptype == "rout" || $ptype == "a")
            $this->limitName = $ptype;
        else if ($ptype == "rable")
            $this->limitName = "r";
        else if (!$me->is_reviewer())
            $this->limitName = "a";
        else if (!$me->is_author())
            $this->limitName = "r";
        else
            $this->limitName = "ar";

        // track other information
        $this->allowAuthor = false;
        if ($me->privChair || $me->is_author()
            || ($this->amPC && $Conf->submission_blindness() != Conference::BLIND_ALWAYS))
            $this->allowAuthor = true;

        // default query fields
        // NB: If a complex query field, e.g., "re", "tag", or "option", is
        // default, then it must be the only default or query construction
        // will break.
        $this->fields = array();
        $qtype = defval($opt, "qt", "n");
        if ($qtype == "n" || $qtype == "ti")
            $this->fields["ti"] = 1;
        if ($qtype == "n" || $qtype == "ab")
            $this->fields["ab"] = 1;
        if ($this->allowAuthor && ($qtype == "n" || $qtype == "au" || $qtype == "ac"))
            $this->fields["au"] = 1;
        if ($this->privChair && $qtype == "ac")
            $this->fields["co"] = 1;
        if ($this->amPC && $qtype == "re")
            $this->fields["re"] = 1;
        if ($this->amPC && $qtype == "tag")
            $this->fields["tag"] = 1;
        $this->qt = ($qtype == "n" ? "" : $qtype);

        // the query itself
        $this->q = trim(defval($opt, "q", ""));

        // URL base
        if (isset($opt["urlbase"]))
            $this->urlbase = $opt["urlbase"];
        else {
            $this->urlbase = hoturl_site_relative("search", "t=" . urlencode($this->limitName));
            if ($qtype != "n")
                $this->urlbase .= "&qt=" . urlencode($qtype);
        }

        $this->_reviewer = defval($opt, "reviewer", false);
        $this->_reviewer_fixed = !!$this->_reviewer;
    }

    function __destruct() {
        global $Conf;
        if ($this->_matchTable)
            $Conf->q("drop temporary table $this->_matchTable");
    }

    // begin changing contactId to cid
    public function __get($name) {
        if ($name == "contactId") {
            trigger_error(caller_landmark() . ": PaperSearch->contactId deprecated, use cid", E_USER_NOTICE);
            return $this->cid;
        } else
            return null;
    }

    public function __set($name, $value) {
        if ($name == "contactId") {
            error_log(caller_landmark() . ": PaperSearch->contactId deprecated, use cid");
            $this->cid = $value;
        } else
            $this->$name = $value;
    }


    function warn($text) {
        $this->warnings[] = $text;
    }


    // PARSING
    // Transforms a search string into an expression object, possibly
    // including "and", "or", and "not" expressions (which point at other
    // expressions).

    static function _analyze_field_preg($t) {
        if (is_object($t))
            $word = $t->value;
        else {
            $word = $t;
            $t = (object) array();
        }

        $word = preg_quote(preg_replace('/\s+/', " ", $word));
        if (strpos($word, "*") !== false) {
            $word = str_replace('\*', '\S*', $word);
            $word = str_replace('\\\\\S*', '\*', $word);
        }

        if (preg_match("/[\x80-\xFF]/", $word))
            $t->preg_utf8 = Text::utf8_word_regex($word);
        else {
            $t->preg_raw = Text::word_regex($word);
            $t->preg_utf8 = Text::utf8_word_regex($word);
        }
        return $t;
    }

    private function _searchField($word, $rtype, &$qt) {
        if (!is_array($word))
            $extra = array("regex" => array($rtype, self::_analyze_field_preg($word)));
        else
            $extra = null;

        if ($this->privChair || $this->amPC)
            $qt[] = new SearchTerm($rtype, self::F_XVIEW, $word, $extra);
        else {
            $qt[] = new SearchTerm($rtype, self::F_XVIEW | self::F_REVIEWER, $word, $extra);
            $qt[] = new SearchTerm($rtype, self::F_XVIEW | self::F_AUTHOR, $word, $extra);
        }
    }

    private function _searchAuthors($word, &$qt, $keyword, $quoted) {
        $lword = strtolower($word);
        if ($keyword && !$quoted && $lword === "me")
            $this->_searchField(array($this->cid), "au_cid", $qt);
        else if ($keyword && !$quoted && $this->amPC
                 && ($lword == "pc"
                     || (($pctags = pcTags()) && isset($pctags[$lword])))) {
            $cids = self::_pcContactIdsWithTag($lword);
            $this->_searchField($cids, "au_cid", $qt);
        } else
            $this->_searchField($word, "au", $qt);
    }

    static function _cleanCompar($compar) {
        $compar = trim($compar);
        if ($compar == "" || $compar == "==")
            return "=";
        else if ($compar == "!")
            return "!=";
        else
            return $compar;
    }

    static function _matchCompar($text, $quoted) {
        $text = trim($text);
        if (($text == "any" || $text == "" || $text == "yes") && !$quoted)
            return array("", ">0");
        else if (($text == "none" || $text == "no") && !$quoted)
            return array("", "=0");
        else if (ctype_digit($text))
            return array("", "=" . $text);
        else if (preg_match('/\A(.*?)([<>!]|[<>!=]?=)\s*(\d+)\z/s', $text, $m))
            return array($m[1], self::_cleanCompar($m[2]) . $m[3]);
        else
            return array($text, ">0");
    }

    static function _comparTautology($m) {
        if ($m[1] == "<0")
            return "f";
        else if ($m[1] == ">=0")
            return "t";
        else
            return null;
    }

    static function _typeCompar($compar, $value, $type) {
        $compar = self::_cleanCompar($compar);
        if ($value == 0 && $compar == "<")
            return array("f", null);
        else if ($value == 0 && $compar == ">=")
            return array("t", null);
        else
            return array($type, $compar . $value);
    }

    private static function _pcContactIdsWithTag($tag) {
        if ($tag == "pc")
            return array_keys(pcMembers());
        $a = array();
        foreach (pcMembers() as $cid => $pc)
            if (stripos($pc->contactTags, " $tag ") !== false)
                $a[] = $cid;
        return $a;
    }

    private function add_contact_match($sql, $pc_only = false) {
        $ncm = count($this->contact_match);
        $sql = simplify_whitespace($sql);
        if (!$ncm || $this->contact_match[$ncm - 1]->sql !== $sql
            || $this->contact_match[$ncm - 1]->pc_only !== $pc_only) {
            $this->contact_match[] = new SearchContactMatch($sql, $pc_only);
            ++$ncm;
        }
        return "\1" . ($ncm - 1) . "\1";
    }

    private function _reviewerMatcher($word, $quoted, $pc_only,
                                      $limited = false) {
        if (!$quoted && ($word == "" || strcasecmp($word, "pc") == 0))
            return array_keys(pcMembers());
        else if (!$quoted && strcasecmp($word, "me") == 0) {
            if ($this->_reviewer_fixed)
                return array($this->reviewer_cid());
            else
                return array($this->cid);
        }

        $needtag = $negtag = false;
        $tag = strtolower($word);
        if (substr($tag, 0, 1) === "-" && !$quoted) {
            $needtag = $negtag = true;
            $tag = substr($tag, 1);
        }
        if (substr($tag, 0, 1) === "#" && !$quoted) {
            $needtag = true;
            $tag = substr($tag, 1);
        }

        if (!$quoted && $this->amPC) {
            $pctags = pcTags();
            if (isset($pctags[$tag])) {
                $ids = self::_pcContactIdsWithTag($tag);
                if ($negtag && $pc_only)
                    return array_diff(array_keys(pcMembers()), $ids);
                else if ($negtag)
                    return $this->add_contact_match("\2contactId" . sql_not_in_numeric_set($ids));
                else
                    return $ids;
            } else if ($needtag)
                $this->warn("no such PC tag “" . htmlspecialchars($tag) . "”");
        }

        if ($needtag || $limited)
            return array(-1);
        else
            return $this->add_contact_match(sqlq_for_like($word), $pc_only);
    }

    private function _one_pc_matcher($text, $quoted) {
        if (($text == "any" || $text == "" || $text == "yes") && !$quoted)
            return "!=0";
        else if (($text == "none" || $text == "no") && !$quoted)
            return "=0";
        else
            return $this->_reviewerMatcher($text, $quoted, true);
    }

    function _searchReviewer($word, $rtype, &$qt, $quoted) {
        $rt = 0;
        if ($rtype == "pri" || $rtype == "cpri" || $rtype == "ipri")
            $rt = REVIEW_PRIMARY;
        else if ($rtype == "sec" || $rtype == "csec" || $rtype == "isec")
            $rt = REVIEW_SECONDARY;
        else if ($rtype == "ext" || $rtype == "cext" || $rtype == "iext")
            $rt = REVIEW_EXTERNAL;
        if ($rtype == "cre" || $rtype == "cpri" || $rtype == "csec" || $rtype == "cext")
            $rt |= self::F_COMPLETE;
        if ($rtype == "ire" || $rtype == "ipri" || $rtype == "isec" || $rtype == "iext")
            $rt |= self::F_INCOMPLETE;

        $m = self::_matchCompar($word, $quoted);
        if (($type = self::_comparTautology($m))) {
            $qt[] = new SearchTerm($type);
            return;
        }

        if ($m[0] == "")
            $contacts = null;
        else if (($rt & self::F_REVIEWTYPEMASK) >= REVIEW_PC)
            $contacts = $this->_reviewerMatcher($m[0], $quoted, true);
        else
            $contacts = $this->_reviewerMatcher($m[0], $quoted, false);
        $value = new SearchReviewValue($m[1], $contacts);
        $qt[] = new SearchTerm("re", $rt | self::F_XVIEW, $value);
    }

    function _search_decision($word, &$qt, $quoted, $allow_status) {
        global $Conf;
        if (!$quoted && strcasecmp($word, "yes") == 0)
            $value = ">0";
        else if (!$quoted && strcasecmp($word, "no") == 0)
            $value = "<0";
        else if ($word == "?"
                 || (!$quoted && strcasecmp($word, "none") == 0)
                 || (!$quoted && strcasecmp($word, "unknown") == 0))
            $value = "=0";
        else if (!$quoted && strcasecmp($word, "any") == 0)
            $value = "!=0";
        else {
            $value = matchValue($Conf->outcome_map(), $word, true);
            if (count($value) == 0) {
                $this->warn("“" . htmlspecialchars($word) . "” doesn’t match a " . ($allow_status ? "decision or status." : "decision."));
                $value[] = -10000000;
            }
            $value = sql_in_numeric_set($value);
        }

        $value = array("outcome", $value);
        if ($this->amPC && $Conf->timePCViewDecision(true))
            $qt[] = new SearchTerm("pf", 0, $value);
        else
            $qt[] = new SearchTerm("pf", self::F_XVIEW, $value);
    }

    private function _search_conflict($word, &$qt, $quoted, $pc_only) {
        $m = self::_matchCompar($word, $quoted);
        if (($type = self::_comparTautology($m))) {
            $qt[] = new SearchTerm($type);
            return;
        }

        $contacts = $this->_reviewerMatcher($m[0], $quoted, $pc_only);
        $value = new SearchReviewValue($m[1], $contacts);
        if ($this->privChair
            || (is_array($contacts) && count($contacts) == 1 && $contacts[0] == $this->cid))
            $qt[] = new SearchTerm("conflict", 0, $value);
        else {
            $qt[] = new SearchTerm("conflict", self::F_XVIEW, $value);
            if (($newvalue = $value->restrictContact($this->cid)))
                $qt[] = new SearchTerm("conflict", 0, $newvalue);
        }
    }

    function _searchReviewerConflict($word, &$qt, $quoted) {
        global $Conf;
        $args = array();
        while (preg_match('/\A\s*#?(\d+)(?:-#?(\d+))?\s*,?\s*(.*)\z/s', $word, $m)) {
            $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
            foreach (range($m[1], $m[2]) as $p)
                $args[$p] = true;
            $word = $m[3];
        }
        if ($word != "" || count($args) == 0) {
            $this->warn("The <code>reconflict</code> keyword expects a list of paper numbers.");
            $qt[] = new SearchTerm("f");
        } else {
            $result = $Conf->qe("select distinct contactId from PaperReview where paperId in (" . join(", ", array_keys($args)) . ")");
            $contacts = array();
            while (($row = edb_row($result)))
                $contacts[] = $row[0];
            $qt[] = new SearchTerm("conflict", 0, new SearchReviewValue(">0", $contacts));
        }
    }

    function _searchComment($word, $ctype, &$qt, $quoted) {
        $m = self::_matchCompar($word, $quoted);
        if (($type = self::_comparTautology($m))) {
            $qt[] = new SearchTerm($type);
            return;
        }

        $rt = 0;
        if ($ctype == "response" || $ctype == "anycmt")
            $rt |= self::F_ALLOWRESPONSE;
        if ($ctype == "cmt" || $ctype == "aucmt" || $ctype == "anycmt")
            $rt |= self::F_ALLOWCOMMENT;
        if ($ctype == "aucmt")
            $rt |= self::F_AUTHORCOMMENT;
        if (substr($m[0], 0, 1) === "#") {
            $tag = $this->_check_tag(substr($m[0], 1), false);
            if ($tag !== false) {
                $rt |= ($this->privChair ? 0 : self::F_NONCONFLICT) | self::F_XVIEW;
                $value = new SearchReviewValue($m[1], $tag !== "none" ? $tag : "any");
                $term = new SearchTerm("cmttag", $rt, $value);
                if ($tag === "none")
                    $term = SearchTerm::combine("not", $term);
                $qt[] = $term;
            } else
                $qt[] = new SearchTerm("f");
            if ($tag === false || $tag === "none" || $tag === "any"
                || !($pctags = pcTags()) || !isset($pctags[strtolower($tag)]))
                return;
        }
        $contacts = ($m[0] == "" ? null : $contacts = $this->_reviewerMatcher($m[0], $quoted, false));
        $value = new SearchReviewValue($m[1], $contacts);
        $qt[] = new SearchTerm("cmt", $rt | self::F_XVIEW, $value);
    }

    function _searchReviews($word, $rf, $field, &$qt, $quoted,
                            $noswitch = false) {
        global $Opt;
        $countexpr = ">0";
        $contacts = null;
        $contactword = "";
        $f = $rf->field($field);

        if (preg_match('/\A(.+?[^:=<>!])([:=<>!])(.*)\z/s', $word, $m)
            && !ctype_digit($m[1])) {
            $contacts = $this->_reviewerMatcher($m[1], $quoted, false);
            $word = ($m[2] == ":" ? $m[3] : $m[2] . $m[3]);
            $contactword = $m[1] . ":";
        }

        if ($f->has_options) {
            if ($word == "any")
                $value = "$field>0";
            else if ($word == "none")
                $value = "$field=0";
            else if (preg_match('/\A(\d*?)([<>]?=?)?\s*([A-Za-z]|\d+)\z/s', $word, $m)) {
                if ($m[1] == "")
                    $m[1] = 1;
                $m[2] = self::_cleanCompar($m[2]);
                if ($f->option_letter != (ctype_digit($m[3]) == false))
                    $value = "$field=-1"; // XXX
                else {
                    $score = $m[3];
                    if ($f->option_letter) {
                        if (!defval($Opt, "smartScoreCompare") || $noswitch) {
                            // switch meaning of inequality
                            if ($m[2][0] == "<")
                                $m[2] = ">" . substr($m[2], 1);
                            else if ($m[2][0] == ">")
                                $m[2] = "<" . substr($m[2], 1);
                        }
                        $score = strtoupper($score);
                        $m[3] = $f->option_letter - ord($score);
                    }
                    if (($m[3] < 1 && ($m[2][0] == "<" || $m[2] == "="))
                        || ($m[3] == 1 && $m[2] == "<")
                        || ($m[3] == count($f->options) && $m[2] == ">")
                        || ($m[3] > count($f->options) && ($m[2][0] == ">" || $m[2] == "="))) {
                        if ($f->option_letter)
                            $warnings = array("<" => "worse than", ">" => "better than");
                        else
                            $warnings = array("<" => "less than", ">" => "greater than");
                        $t = new SearchTerm("f");
                        $t->set("contradiction_warning", "No $f->name_html scores are " . ($m[2] == "=" ? "" : $warnings[$m[2][0]] . (strlen($m[2]) == 1 ? " " : " or equal to ")) . $score . ".");
                        $qt[] = $t;
                        return false;
                    } else {
                        $countexpr = (int) $m[1] ? ">=" . $m[1] : "=0";
                        $value = $field . $m[2] . $m[3];
                    }
                }
            } else if ($f->option_letter
                       ? preg_match('/\A\s*([A-Za-z])\s*(-?|\.\.\.?)\s*([A-Za-z])\s*\z/s', $word, $m)
                       : preg_match('/\A\s*(\d+)\s*(-|\.\.\.?)\s*(\d+)\s*\z/s', $word, $m)) {
                $qo = array();
                if ($m[2] == "-" || $m[2] == "") {
                    $this->_searchReviews($contactword . $m[1], $rf, $field, $qo, $quoted);
                    $this->_searchReviews($contactword . $m[3], $rf, $field, $qo, $quoted);
                } else
                    $this->_searchReviews($contactword . ">=" . $m[1], $rf, $field, $qo, $quoted, true);
                if ($this->_searchReviews($contactword . "<" . $m[1], $rf, $field, $qo, $quoted, true))
                    $qo[count($qo) - 1] = SearchTerm::negate($qo[count($qo) - 1]);
                else
                    array_pop($qo);
                if ($this->_searchReviews($contactword . ">" . $m[3], $rf, $field, $qo, $quoted, true))
                    $qo[count($qo) - 1] = SearchTerm::negate($qo[count($qo) - 1]);
                else
                    array_pop($qo);
                $qt[] = new SearchTerm("and", 0, $qo);
                return true;
            } else              // XXX
                $value = "$field=-1";
        } else {
            if ($word == "any")
                $value = "$field!=''";
            else if ($word == "none")
                $value = "$field=''";
            else
                $value = "$field like '%" . sqlq_for_like($word) . "%'";
        }

        $value = new SearchReviewValue($countexpr, $contacts, $value, $f->view_score);
        $qt[] = new SearchTerm("re", self::F_COMPLETE | self::F_XVIEW, $value);
        return true;
    }

    private function _search_revpref($word, &$qt, $quoted) {
        $contacts = null;
        if (preg_match('/\A(.*?[^:=<>!])([:=<>!])(.*)\z/s', $word, $m)
            && !ctype_digit($m[1])) {
            $contacts = $this->_reviewerMatcher($m[1], $quoted, true,
                                                !$this->privChair);
            $word = ($m[2] == ":" ? $m[3] : $m[2] . $m[3]);
        }

        if (!preg_match(',\A(\d*)\s*([<>!]|[<>!=]?=|)\s*(-?\d*)\s*([xyz]?)\z,i', $word, $m)
            || ($m[1] === "" && $m[3] === "" && $m[4] === "")) {
            $qt[] = new SearchTerm("f");
            return;
        }

        if ($m[1] === "")
            $m[1] = "1";
        else if ($m[2] === "")
            list($m[1], $m[3]) = array("1", $m[1]);
        $mx = array((int) $m[1] ? ">=" . $m[1] : "=0");
        $compar = self::_cleanCompar($m[2]);
        if ($m[3] !== "")
            $mx[] = "preference" . $compar . $m[3];
        if ($m[4] !== "")
            $mx[] = "expertise" . $compar . (121 - ord(strtolower($m[4])));

        // since 0 preferences are not stored, we must negate the sense of the
        // comparison if a preference of 0 might match
        $scratch = new SearchReviewValue($mx[1]);
        if ($scratch->test(0))
            foreach ($mx as &$mxv)
                $mxv = SearchReviewValue::negateCountexpr($mxv);

        // PC members can only search their own preferences; we enforce
        // this restriction below in clauseTermSetRevpref.
        $value = new SearchReviewValue($mx[0], $contacts, join(" and ", array_slice($mx, 1)));
        $qt[] = new SearchTerm("revpref", $this->privChair ? 0 : self::F_NONCONFLICT, $value);
    }

    private function _check_tag($tagword, $allow_star) {
        $twiddle = strpos($tagword, "~");
        if ($this->privChair && $twiddle > 0) {
            $c = substr($tagword, 0, $twiddle);
            if ($c === "me")
                $twiddlecid = $this->cid;
            else
                $twiddlecid = matchContact(pcMembers(), null, null, $c);
            if ($twiddlecid == -2)
                $this->warn("“" . htmlspecialchars($c) . "” matches no PC member.");
            else if ($twiddlecid <= 0)
                $this->warn("“" . htmlspecialchars($c) . "” matches more than one PC member; be more specific to disambiguate.");
            $tagword = substr($tagword, $twiddle);
        } else if ($twiddle === 0)
            $twiddlecid = ($tagword[1] === "~" ? "" : $this->cid);
        else
            $twiddlecid = "";

        $tagger = new Tagger($this->contact);
        if ($tagger->check("#" . $tagword, Tagger::ALLOWRESERVED | Tagger::NOVALUE | ($allow_star ? Tagger::ALLOWSTAR : 0)))
            return $twiddlecid . $tagword;
        else {
            $this->warn($tagger->error_html);
            return false;
        }
    }

    function _searchTags($word, $keyword, &$qt) {
        global $Conf;
        if ($word[0] == "#")
            $word = substr($word, 1);

        // allow external reviewers to search their own rank tag
        if (!$this->amPC) {
            $ranktag = "~" . $Conf->setting_data("tag_rank");
            if (!$Conf->setting("tag_rank")
                || substr($word, 0, strlen($ranktag)) !== $ranktag
                || (strlen($word) > strlen($ranktag)
                    && $word[strlen($ranktag)] != "#"))
                return;
        }

        if (preg_match('/\A([^#<>!=]+)(#?)([<>!=]?=?)(-?\d+)\z/', $word, $m)
            && $m[1] != "any" && $m[1] != "none"
            && ($m[2] != "" || $m[3] != "")) {
            $tagword = $m[1];
            $compar = self::_cleanCompar($m[3]) . $m[4];
        } else {
            $tagword = $word;
            $compar = null;
        }

        $value = $this->_check_tag($tagword, $keyword == "tag");
        if ($value === false) {
            $qt[] = new SearchTerm("f");
            return;
        }

        $extra = null;
        if ($keyword == "order" || $keyword == "rorder" || !$keyword)
            $extra = array("tagorder" => (object) array("tag" => $value, "reverse" => $keyword == "rorder"));
        if (($starpos = strpos($value, "*")) !== false) {
            $value = "\3 like '" . str_replace("*", "%", sqlq_for_like($value)) . "'";
            if ($starpos == 0)
                $value .= " and \3 not like '%~%'";
        }
        if ($compar)
            $value = array($value, $compar);

        if ($value !== "any" && $value !== "none")
            $qt[] = new SearchTerm("tag", self::F_XVIEW, $value, $extra);
        else {
            $term = new SearchTerm("tag", self::F_XVIEW, "\3 is not null and (\3 not like '%~%' or \3 like '" . $this->cid . "~%'" . ($this->privChair ? " or \3 like '~~%'" : "") . ")", $extra);
            if ($value === "none")
                $term = SearchTerm::combine("not", $term);
            $qt[] = $term;
        }
    }

    function _searchOptions($word, &$qt, $report_error) {
        if (preg_match('/\A(.*?)([:#][<>!=]?=?|[<>!=]=?)(.*)\z/', $word, $m)) {
            $oname = $m[1];
            if ($m[2][0] == ":" || $m[2][0] == "#")
                $m[2] = substr($m[2], 1);
            $ocompar = self::_cleanCompar($m[2]);
            $oval = strtolower(simplify_whitespace($m[3]));
        } else {
            $oname = $word;
            $ocompar = "=";
            $oval = "";
        }
        $oname = strtolower(simplify_whitespace($oname));

        // match all options
        $qo = array();
        $qxo = array();
        $option_failure = false;
        if ($oname == "none" || $oname == "any")
            $omatches = PaperOption::option_list();
        else
            $omatches = PaperOption::search($oname);
        // global $Conf; $Conf->infoMsg(Ht::pre_text(var_export($omatches, true)));
        if (count($omatches)) {
            foreach ($omatches as $oid => $o) {
                // find the relevant values
                if ($o->type == "numeric") {
                    if (preg_match('/\A\s*([-+]?\d+)\s*\z/', $oval, $m))
                        $xval = $ocompar . $m[1];
                    else if ($oval === "" || $oval === "yes")
                        $xval = "!=0";
                    else if ($oval === "no")
                        $xval = "=0";
                    else {
                        $this->warn("Submission option “" . htmlspecialchars($o->name) . "” takes integer values.");
                        $option_failure = true;
                        continue;
                    }
                } else if ($o->has_selector()) {
                    $xval = matchValue($o->selector, $oval !== "" ? $oval : "yes");
                    if (count($xval) == 0)
                        continue;
                    else if (count($xval) == 1)
                        $xval = $ocompar . $xval[0];
                    else if ($ocompar != "=" && $ocompar != "!=") {
                        $this->warn("Submission option “" . htmlspecialchars("$oname:$oval") . "” matches multiple values, can’t use " . htmlspecialchars($ocompar) . ".");
                        $option_failure = true;
                        continue;
                    } else
                        $xval = ($ocompar == "!=" ? " not in " : " in ")
                            . "(" . join(",", $xval) . ")";
                } else {
                    if ($oval === "" || $oval === "yes")
                        $xval = "!=0";
                    else if ($oval === "no")
                        $xval = "=0";
                    else
                        continue;
                }
                $qo[] = array($o, $xval);
            }
        } else
            foreach (PaperOption::option_list() as $oid => $o)
                if ($o->has_selector()
                    && ($ocompar == "=" || $ocompar == "!=")
                    && $oval == "") {
                    foreach (matchValue($o->selector, $oname) as $xval)
                        $qxo[] = array($o, $ocompar . $xval);
                }

        // report failure
        if (count($qo) == 0 && count($qxo) == 0) {
            if ($option_failure || $report_error) {
                $qt[] = new SearchTerm("f");
                if (!$option_failure)
                    $this->warn("“" . htmlspecialchars($word) . "” doesn’t match a submission option.");
            }
            return false;
        }

        // add expressions
        $qz = array();
        foreach ((count($qo) ? $qo : $qxo) as $o)
            $qz[] = new SearchTerm("option", self::F_XVIEW, $o);
        if ($oname === "none")
            $qz = array(SearchTerm::negate(SearchTerm::combine("or", $qz)));
        $qt = array_merge($qt, $qz);
        return true;
    }

    private function _searchHas($word, &$qt, $quoted) {
        $wordkw = @self::$_keywords[$word] ? : "";
        if (strcasecmp($word, "paper") == 0 || strcasecmp($word, "submission") == 0)
            $qt[] = new SearchTerm("pf", 0, array("paperStorageId", "!=0"));
        else if (strcasecmp($word, "final") == 0 || strcasecmp($word, "finalcopy") == 0)
            $qt[] = new SearchTerm("pf", 0, array("finalPaperStorageId", "!=0"));
        else if (strcasecmp($word, "abstract") == 0)
            $qt[] = new SearchTerm("pf", 0, array("abstract", "!=''"));
        else if (preg_match('/\A(?:cmt|aucmt|anycmt|response)\z/', $wordkw))
            $this->_searchComment(">0", $wordkw, $qt, $quoted);
        else if (strcasecmp($word, "manager") == 0 || strcasecmp($word, "admin") == 0 || strcasecmp($word, "administrator") == 0)
            $qt[] = new SearchTerm("pf", 0, array("managerContactId", "!=0"));
        else if (preg_match('/\A[ci]?(?:re|pri|sec|ext)\z/', $wordkw))
            $this->_searchReviewer(">0", $wordkw, $qt, $quoted);
        else if (preg_match('/\A\w+\z/', $word) && $this->_searchOptions("$word:yes", $qt, false))
            /* OK */;
        else {
            $this->warn("Valid “has:” searches include “paper”, “final”, “abstract”, “comment”, “aucomment”, “response”, “pcrev”, and “extrev”.");
            $qt[] = new SearchTerm("f");
        }
    }

    private function _searchReviewRatings($word, &$qt) {
        global $Conf;
        $this->reviewAdjust = true;
        if (preg_match('/\A(.+?)\s*(|[<>!=]?=?)\s*(\d*)\z/', $word, $m)
            && ($m[3] !== "" || $m[2] === "")
            && $Conf->setting("rev_ratings") != REV_RATINGS_NONE) {
            // adjust counts
            if ($m[3] === "") {
                $m[2] = ">";
                $m[3] = "0";
            }
            if ($m[2] === "")
                $m[2] = ($m[3] == 0 ? "=" : ">=");
            else
                $m[2] = self::_cleanCompar($m[2]);
            $nqt = count($qt);

            // resolve rating type
            if ($m[1] === "+" || $m[1] === "good") {
                $this->interestingRatings["good"] = ">0";
                $term = "nrate_good";
            } else if ($m[1] === "-" || $m[1] === "bad"
                       || $m[1] == "\xE2\x88\x92" /* unicode MINUS */) {
                $this->interestingRatings["bad"] = "<1";
                $term = "nrate_bad";
            } else if ($m[1] === "any") {
                $this->interestingRatings["any"] = "!=100";
                $term = "nrate_any";
            } else {
                $x = array_diff(matchValue(ReviewForm::$rating_types, $m[1]),
                                array("n")); /* don't allow "average" */
                if (count($x) == 0) {
                    $this->warn("Unknown rating type “" . htmlspecialchars($m[1]) . "”.");
                    $qt[] = new SearchTerm("f");
                } else {
                    $type = count($this->interestingRatings);
                    $this->interestingRatings[$type] = " in (" . join(",", $x) . ")";
                    $term = "nrate_$type";
                }
            }

            if (count($qt) == $nqt) {
                if ($m[2][0] === "<" || $m[2] === "!="
                    || ($m[2] === "=" && $m[3] == 0)
                    || ($m[2] === ">=" && $m[3] == 0))
                    $term = "coalesce($term,0)";
                $qt[] = new SearchTerm("revadj", 0, array("rate" => $term . $m[2] . $m[3]));
            }
        } else {
            if ($Conf->setting("rev_ratings") == REV_RATINGS_NONE)
                $this->warn("Review ratings are disabled.");
            else
                $this->warn("Bad review rating query “" . htmlspecialchars($word) . "”.");
            $qt[] = new SearchTerm("f");
        }
    }

    static private function find_end_balanced_parens($str) {
        $pcount = 0;
        for ($pos = 0; $pos < strlen($str)
                 && (!ctype_space($str[$pos]) || $pcount); ++$pos)
            if ($str[$pos] === "(")
                ++$pcount;
            else if ($str[$pos] === ")")
                --$pcount;
        return $pos;
    }

    static public function parse_sorter($text) {
        if (!self::$_sort_keywords)
            self::$_sort_keywords =
                array("by" => "by", "up" => "up", "down" => "down",
                      "reverse" => "down", "reversed" => "down",
                      "count" => "C", "counts" => "C", "av" => "A",
                      "ave" => "A", "average" => "A", "med" => "E",
                      "median" => "E", "var" => "V", "variance" => "V",
                      "max-min" => "D", "my" => "Y", "score" => "");

        $text = simplify_whitespace($text);
        $sort = (object) array("type" => null, "field" => null, "reverse" => null,
                               "score" => null, "empty" => $text == "");
        if (($ch1 = substr($text, 0, 1)) === "-" || $ch1 === "+") {
            $sort->reverse = $ch1 === "-";
            $text = ltrim(substr($text, 1));
        }

        // separate text into words
        $words = array();
        $bypos = false;
        while ($text !== "") {
            if (substr($text, 0, 1) === "(") {
                $pos = self::find_end_balanced_parens($text);
                $m = array("", substr($text, 0, $pos), substr($text, $pos));
            } else
                preg_match(',\A([^\s\(]+)(.*)\z,s', $text, $m);
            $words[] = $m[1];
            $text = ltrim($m[2]);
            if ($m[1] == "by" && $bypos === false)
                $bypos = count($words) - 1;
        }

        // go over words
        $next_words = array();
        for ($i = 0; $i != count($words); ++$i) {
            $w = $words[$i];
            if (($bypos === false || $pos > $bypos)
                && isset(self::$_sort_keywords[$w])) {
                $x = self::$_sort_keywords[$w];
                if ($x === "up")
                    $sort->reverse = false;
                else if ($x === "down")
                    $sort->reverse = true;
                else if (ctype_upper($x))
                    $sort->score = $x;
            } else if ($bypos === false || $pos < $bypos)
                $next_words[] = $w;
        }

        if (count($next_words))
            $sort->type = join(" ", $next_words);
        return $sort;
    }

    public static function combine_sorters($a, $b) {
        foreach (array("type", "reverse", "score", "field") as $k)
            if ($a->$k === null)
                $a->$k = $b->$k;
    }

    function _searchQueryWord($word, $report_error) {
        global $Conf;

        // check for paper number or "#TAG"
        if (preg_match('/\A#?(\d+)(?:-#?(\d+))?\z/', $word, $m)) {
            $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
            return new SearchTerm("pn", 0, array(range($m[1], $m[2]), array()));
        } else if (substr($word, 0, 1) == "#") {
            $re = '/\A#' . ($this->privChair ? '(?:[\w@.]+~)?' : '')
                . TAG_REGEX_OPTVALUE . '[#<>!=\d]*\z/';
            if (preg_match($re, $word, $m)) {
                $qe = $this->_searchQueryWord("tag:" . $word, false);
                if (!$qe->isfalse())
                    return $qe;
            }
        }

        // Allow searches like "ovemer>2"; parse as "ovemer:>2".
        if (preg_match('/\A([-_A-Za-z0-9]+)([<>!=]=?[^:]+)\z/', $word, $m)) {
            $qe = $this->_searchQueryWord($m[1] . ":" . $m[2], false);
            if (!$qe->isfalse())
                return $qe;
        }

        $keyword = null;
        if (($colon = strpos($word, ':')) !== false) {
            $x = substr($word, 0, $colon);
            if (isset(self::$_keywords[$x])) {
                $keyword = self::$_keywords[$x];
                $word = substr($word, $colon + 1);
            } else if (strpos($x, '"') === false) {
                $keyword = $x;
                $word = substr($word, $colon + 1);
            }
            if ($word === false)
                $word = "";
        }

        // Treat unquoted "*", "ANY", and "ALL" as special; return true.
        if ($word == "*" || $word == "ANY" || $word == "ALL" || $word == "")
            return new SearchTerm("t");
        else if ($word == "NONE")
            return new SearchTerm("f");

        $quoted = ($word[0] == '"');
        $negated = false;
        if ($quoted)
            $word = str_replace(array('"', '*'), array('', '\*'), $word);
        if ($keyword ? $keyword == "notag" : isset($this->fields["notag"])) {
            $keyword = "tag";
            $negated = true;
        }

        $qt = array();
        if ($keyword ? $keyword == "ti" : isset($this->fields["ti"]))
            $this->_searchField($word, "ti", $qt);
        if ($keyword ? $keyword == "ab" : isset($this->fields["ab"]))
            $this->_searchField($word, "ab", $qt);
        if ($keyword ? $keyword == "au" : isset($this->fields["au"]))
            $this->_searchAuthors($word, $qt, $keyword, $quoted);
        if ($keyword ? $keyword == "co" : isset($this->fields["co"]))
            $this->_searchField($word, "co", $qt);
        foreach (array("re", "cre", "ire", "pri", "cpri", "ipri", "sec", "csec", "isec", "ext", "cext", "iext") as $rtype)
            if ($keyword ? $keyword == $rtype : isset($this->fields[$rtype]))
                $this->_searchReviewer($word, $rtype, $qt, $quoted);
        foreach (array("cmt", "aucmt", "anycmt", "response") as $ctype)
            if ($keyword ? $keyword == $ctype : isset($this->fields[$ctype]))
                $this->_searchComment($word, $ctype, $qt, $quoted);
        if (($keyword ? $keyword == "revpref" : isset($this->fields["revpref"]))
            && $this->amPC)
            $this->_search_revpref($word, $qt, $quoted);
        foreach (array("lead", "shepherd", "manager") as $ctype)
            if ($keyword ? $keyword == $ctype : isset($this->fields[$ctype])) {
                $x = $this->_one_pc_matcher($word, $quoted);
                $qt[] = new SearchTerm("pf", self::F_XVIEW, array("${ctype}ContactId", $x));
                if ($ctype == "manager" && $word == "me" && !$quoted && $this->privChair)
                    $qt[] = new SearchTerm("pf", self::F_XVIEW, array("${ctype}ContactId", "=0"));
            }
        if (($keyword ? $keyword == "tag" : isset($this->fields["tag"]))
            || $keyword == "order" || $keyword == "rorder")
            $this->_searchTags($word, $keyword, $qt);
        if (($keyword ? $keyword == "topic" : isset($this->fields["topic"]))) {
            $type = "topic";
            $value = null;
            if ($word == "none" || $word == "any")
                $value = $word;
            else {
                $x = strtolower(simplify_whitespace($word));
                $tids = array();
                foreach ($Conf->topic_map() as $tid => $tname)
                    if (strstr(strtolower($tname), $x) !== false)
                        $tids[] = $tid;
                if (count($tids) == 0 && $word != "none" && $word != "any") {
                    $this->warn("“" . htmlspecialchars($x) . "” does not match any defined paper topic.");
                    $type = "f";
                } else
                    $value = $tids;
            }
            $qt[] = new SearchTerm($type, self::F_XVIEW, $value);
        }
        if (($keyword ? $keyword == "option" : isset($this->fields["option"])))
            $this->_searchOptions($word, $qt, true);
        if ($keyword ? $keyword == "status" : isset($this->fields["status"])) {
            if (strcasecmp($word, "withdrawn") == 0 || strcasecmp($word, "withdraw") == 0 || strcasecmp($word, "with") == 0)
                $qt[] = new SearchTerm("pf", 0, array("timeWithdrawn", ">0"));
            else if (strcasecmp($word, "submitted") == 0 || strcasecmp($word, "submit") == 0 || strcasecmp($word, "sub") == 0)
                $qt[] = new SearchTerm("pf", 0, array("timeSubmitted", ">0"));
            else if (strcasecmp($word, "unsubmitted") == 0 || strcasecmp($word, "unsubmit") == 0 || strcasecmp($word, "unsub") == 0)
                $qt[] = new SearchTerm("pf", 0, array("timeSubmitted", "<=0", "timeWithdrawn", "<=0"));
            else
                $this->_search_decision($word, $qt, $quoted, true);
        }
        if ($keyword ? $keyword == "decision" : isset($this->fields["decision"]))
            $this->_search_decision($word, $qt, $quoted, false);
        if (($keyword ? $keyword == "conflict" : isset($this->fields["conflict"]))
            && $this->amPC)
            $this->_search_conflict($word, $qt, $quoted, false);
        if (($keyword ? $keyword == "pcconflict" : isset($this->fields["pcconflict"]))
            && $this->amPC)
            $this->_search_conflict($word, $qt, $quoted, true);
        if (($keyword ? $keyword == "reconflict" : isset($this->fields["reconflict"]))
            && $this->privChair)
            $this->_searchReviewerConflict($word, $qt, $quoted);
        if (($keyword ? $keyword == "round" : isset($this->fields["round"]))
            && $this->amPC) {
            $this->reviewAdjust = true;
            if ($word == "none")
                $qt[] = new SearchTerm("revadj", 0, array("round" => array(0)));
            else if ($word == "any")
                $qt[] = new SearchTerm("revadj", 0, array("round" => range(1, count($Conf->round_list()) - 1)));
            else {
                $x = simplify_whitespace($word);
                $rounds = matchValue($Conf->round_list(), $x);
                if (count($rounds) == 0) {
                    $this->warn("“" . htmlspecialchars($x) . "” doesn’t match a review round.");
                    $qt[] = new SearchTerm("f");
                } else
                    $qt[] = new SearchTerm("revadj", 0, array("round" => $rounds));
            }
        }
        if ($keyword ? $keyword == "rate" : isset($this->fields["rate"]))
            $this->_searchReviewRatings($word, $qt);
        if ($keyword ? $keyword == "has" : isset($this->fields["has"]))
            $this->_searchHas($word, $qt, $quoted);
        if ($keyword ? $keyword == "ss" : isset($this->fields["ss"])) {
            $t = $Conf->setting_data("ss:" . $word, "");
            $search = json_decode($t);
            $qe = null;
            if (isset($this->_ssRecursion[$word]))
                $this->warn("Saved search “" . htmlspecialchars($word) . "” is incorrectly defined in terms of itself.");
            else if ($t == "")
                $this->warn("There is no saved search called “" . htmlspecialchars($word) . "”.");
            else {
                if ($search && is_object($search) && isset($search->q)) {
                    $this->_ssRecursion[$word] = true;
                    $qe = $this->_searchQueryType($search->q);
                    unset($this->_ssRecursion[$word]);
                }
                if (!$qe)
                    $this->warn("The “" . htmlspecialchars($word) . "” saved search is defined incorrectly.");
            }
            $qt[] = ($qe ? $qe : new SearchTerm("f"));
        }
        if ($keyword == "HEADING") {
            if (($heading = simplify_whitespace($word)) != "")
                $this->headingmap = array();
            $qt[] = SearchTerm::make_float(array("heading" => $heading));
        }
        if ($keyword == "show" || $keyword == "hide" || $keyword == "edit"
            || $keyword == "sort" || $keyword == "showsort"
            || $keyword == "editsort") {
            $editing = strpos($keyword, "edit") !== false;
            $sorting = strpos($keyword, "sort") !== false;
            $views = array();
            $a = ($keyword == "hide" ? false : ($editing ? "edit" : true));
            $word = simplify_whitespace($word);
            $ch1 = substr($word, 0, 1);
            if ($ch1 === "-" && !$sorting)
                list($a, $word) = array(false, substr($word, 1));
            $wtype = $word;
            if ($sorting) {
                $sort = self::parse_sorter($wtype);
                $wtype = $sort->type;
            }
            if ($wtype != "" && $keyword != "sort")
                $views[$wtype] = $a;
            if ($sorting)
                $views["sort"] = $word;
            $qt[] = SearchTerm::make_float(array("view" => $views));
        }

        // Finally, look for a review field.
        if ($keyword && !isset(self::$_keywords[$keyword]) && count($qt) == 0) {
            $rf = reviewForm();
            if (($field = $rf->unabbreviateField($keyword)))
                $this->_searchReviews($word, $rf, $field, $qt, $quoted);
            else if (!$this->_searchOptions("$keyword:$word", $qt, false)
                     && $report_error)
                $this->warn("Unrecognized keyword “" . htmlspecialchars($keyword) . "”.");
        }

        // Must always return something
        if (count($qt) == 0)
            $qt[] = new SearchTerm("f");

        $qe = SearchTerm::combine("or", $qt);
        return $negated ? SearchTerm::negate($qe) : $qe;
    }

    static function _searchPopWord(&$str) {
        $wordre = '/\A"[^"]*"?|\A[a-zA-Z][a-zA-Z0-9]*:"[^"]*"?[^\s()]*|\A[^"\s()]+/s';

        preg_match($wordre, $str, $m);
        $str = substr($str, strlen($m[0]));
        $word = $m[0];

        // commas in paper number strings turn into separate words
        if (preg_match('/\A(#?\d+(?:-#?\d+)?),((?:#?\d+(?:-#?\d+)?,?)*)\z/', $word, $m)) {
            $word = $m[1];
            if ($m[2] != "")
                $str = $m[2] . $str;
        }

        // allow a space after a keyword
        if (($colon = strpos($word, ":")) !== false) {
            $x = substr($word, 0, $colon);
            if ((isset(self::$_keywords[$x]) || strpos($x, '"') === false)
                && strlen($word) <= $colon + 1) {
                if (preg_match($wordre, $str, $m)) {
                    $word .= $m[0];
                    $str = substr($str, strlen($m[0]));
                }
            }
        }

        // "show:" may be followed by a parenthesized expression
        $keyword = ($colon !== false ? substr($word, $colon) : "");
        if (substr($str, 0, 1) === "(" && $colon !== false
            && ($keyword = substr($word, 0, $colon)) !== ""
            && ($keyword = @self::$_keywords[$keyword])
            && substr($word, $colon + 1, 1) !== "\""
            && ($keyword === "show" || $keyword === "showsort"
                || $keyword === "sort")) {
            $pos = self::find_end_balanced_parens($str);
            $word .= substr($str, 0, $pos);
            $str = substr($str, $pos);
        }

        $str = ltrim($str);
        return $word;
    }

    static function _searchPopKeyword($str) {
        if (preg_match('/\A([-+()]|(?:AND|OR|NOT|THEN)(?=[\s\(]))/is', $str, $m))
            return array(strtoupper($m[1]), ltrim(substr($str, strlen($m[0]))));
        else
            return array(null, $str);
    }

    static function _searchPopStack($curqe, &$stack) {
        $x = array_pop($stack);
        if (!$curqe)
            return $x->leftqe;
        else if ($x->op->op == "not")
            return SearchTerm::negate($curqe);
        else if ($x->op->op == "+")
            return $curqe;
        else if ($x->used) {
            $x->leftqe->value[] = $curqe;
            return $x->leftqe;
        } else
            return SearchTerm::combine($x->op->op, array($x->leftqe, $curqe));
    }

    function _searchQueryType($str) {
        global $searchOperators;

        $stack = array();
        $defkwstack = array();
        $defkw = $next_defkw = null;
        $parens = 0;
        $curqe = null;
        $xstr = $str;
        $headstr = "";

        while ($str !== "") {
            list($opstr, $nextstr) = self::_searchPopKeyword($str);
            $op = $opstr ? $searchOperators[$opstr] : null;

            if ($curqe && (!$op || $op->unary)) {
                list($opstr, $op, $nextstr) =
                    array("", $searchOperators["SPACE"], $str);
            }

            if ($opstr === null) {
                $word = self::_searchPopWord($nextstr);
                // Bare any-case "all", "any", "none" are treated as keywords.
                if (!$curqe
                    && (!count($stack) || $stack[count($stack) - 1]->op->op == "then")
                    && ($uword = strtoupper($word))
                    && ($uword === "ALL" || $uword === "ANY" || $uword === "NONE")
                    && preg_match(',\A\s*(?:|THEN(?:\s|\().*)\z,', $nextstr))
                    $word = $uword;
                // Search like "ti:(foo OR bar)" adds a default keyword.
                if ($word[strlen($word) - 1] === ":"
                    && $nextstr !== ""
                    && $nextstr[0] === "(")
                    $next_defkw = $word;
                else {
                    // If no keyword, but default keyword exists, apply it.
                    if ($defkw !== ""
                        && !preg_match(',\A-?[a-zA-Z][a-zA-Z0-9]*:,', $word)) {
                        if ($word[0] == "-")
                            $word = "-" . $defkw . substr($word, 1);
                        else
                            $word = $defkw . $word;
                    }
                    // The heart of the matter.
                    $curqe = $this->_searchQueryWord($word, true);
                    // Don't include 'show:' in headings.
                    if (($colon = strpos($word, ":")) !== false
                        && @self::$_noheading_keywords[substr($word, 0, $colon)]) {
                        $headstr .= substr($xstr, 0, -strlen($str));
                        $xstr = $nextstr;
                    }
                }
            } else if ($opstr == ")") {
                while (count($stack)
                       && $stack[count($stack) - 1]->op->op != "(")
                    $curqe = self::_searchPopStack($curqe, $stack);
                if (count($stack)) {
                    array_pop($stack);
                    --$parens;
                    $defkw = array_pop($defkwstack);
                }
            } else if ($opstr == "(") {
                assert(!$curqe);
                $stack[] = (object) array("op" => $op, "leftqe" => null, "used" => false);
                $defkwstack[] = $defkw;
                $defkw = $next_defkw;
                $next_defkw = null;
                ++$parens;
            } else if (!$op->unary && !$curqe)
                /* ignore bad operator */;
            else {
                while (count($stack)
                       && $stack[count($stack) - 1]->op->precedence > $op->precedence)
                    $curqe = self::_searchPopStack($curqe, $stack);
                if ($op->op == "then" && $curqe) {
                    $curqe->set_float("substr", trim($headstr . substr($xstr, 0, -strlen($str))));
                    $xstr = $nextstr;
                    $headstr = "";
                }
                $top = count($stack) ? $stack[count($stack) - 1] : null;
                if ($top && !$op->unary && $top->op->op == $op->op) {
                    if ($top->used)
                        $top->leftqe->value[] = $curqe;
                    else {
                        $top->leftqe = SearchTerm::combine($op->op, array($top->leftqe, $curqe));
                        $top->used = true;
                    }
                } else
                    $stack[] = (object) array("op" => $op, "leftqe" => $curqe, "used" => false);
                $curqe = null;
            }

            $str = $nextstr;
        }

        if ($curqe)
            $curqe->set_float("substr", trim($headstr . $xstr));
        while (count($stack))
            $curqe = self::_searchPopStack($curqe, $stack);
        return $curqe;
    }

    static function _canonicalizePopStack($curqe, &$stack) {
        $x = array_pop($stack);
        if ($curqe)
            $x->qe[] = $curqe;
        if (!count($x->qe))
            return null;
        if ($x->op->unary) {
            $qe = $x->qe[0];
            if ($x->op->op == "not") {
                if (preg_match('/\A(?:[(-]|NOT )/i', $qe))
                    $qe = "NOT $qe";
                else
                    $qe = "-$qe";
            }
            return $qe;
        } else if (count($x->qe) == 1)
            return $x->qe[0];
        else if ($x->op->op == "and" && $x->op->precedence == 2)
            return "(" . join(" ", $x->qe) . ")";
        else
            return "(" . join(strtoupper(" " . $x->op->op . " "), $x->qe) . ")";
    }

    static function _canonicalizeQueryType($str, $type) {
        global $searchOperators;

        $stack = array();
        $parens = 0;
        $defaultop = ($type == "all" ? "XAND" : "XOR");
        $curqe = null;
        $t = "";

        while ($str !== "") {
            list($opstr, $nextstr) = self::_searchPopKeyword($str);
            $op = $opstr ? $searchOperators[$opstr] : null;

            if ($curqe && (!$op || $op->unary)) {
                list($opstr, $op, $nextstr) =
                    array("", $searchOperators[$parens ? "XAND" : $defaultop], $str);
            }

            if ($opstr === null) {
                $curqe = self::_searchPopWord($nextstr);
            } else if ($opstr == ")") {
                while (count($stack)
                       && $stack[count($stack) - 1]->op->op != "(")
                    $curqe = self::_canonicalizePopStack($curqe, $stack);
                if (count($stack)) {
                    array_pop($stack);
                    --$parens;
                }
            } else if ($opstr == "(") {
                assert(!$curqe);
                $stack[] = (object) array("op" => $op, "qe" => array());
                ++$parens;
            } else {
                while (count($stack)
                       && $stack[count($stack) - 1]->op->precedence > $op->precedence)
                    $curqe = self::_canonicalizePopStack($curqe, $stack);
                $top = count($stack) ? $stack[count($stack) - 1] : null;
                if ($top && !$op->unary && $top->op->op == $op->op)
                    $top->qe[] = $curqe;
                else
                    $stack[] = (object) array("op" => $op, "qe" => array($curqe));
                $curqe = null;
            }

            $str = $nextstr;
        }

        if ($type == "none")
            array_unshift($stack, (object) array("op" => $searchOperators["NOT"], "qe" => array()));
        while (count($stack))
            $curqe = self::_canonicalizePopStack($curqe, $stack);
        return $curqe;
    }

    static function canonicalizeQuery($qa, $qo = null, $qx = null) {
        $x = array();
        if ($qa && ($qa = self::_canonicalizeQueryType(trim($qa), "all")))
            $x[] = $qa;
        if ($qo && ($qo = self::_canonicalizeQueryType(trim($qo), "any")))
            $x[] = $qo;
        if ($qx && ($qx = self::_canonicalizeQueryType(trim($qx), "none")))
            $x[] = $qx;
        if (count($x) == 1)
            return preg_replace('/\A\((.*)\)\z/', '$1', join("", $x));
        else
            return join(" AND ", $x);
    }


    // CLEANING
    // Clean an input expression series into clauses.  The basic purpose of
    // this step is to combine all paper numbers into a single group, and to
    // assign review adjustments (rates & rounds).

    function _queryClean($qe, $below = false) {
        if (!$qe)
            return $qe;
        else if ($qe->type == "not")
            return $this->_queryCleanNot($qe);
        else if ($qe->type == "or")
            return $this->_queryCleanOr($qe);
        else if ($qe->type == "then")
            return $this->_queryCleanThen($qe, $below);
        else if ($qe->type == "and")
            return $this->_queryCleanAnd($qe);
        else
            return $qe;
    }

    function _queryCleanNot($qe) {
        $qv = $this->_queryClean($qe->value, true);
        if ($qv->type == "not")
            return $qv->value;
        else if ($qv->type == "pn")
            return new SearchTerm("pn", 0, array($qv->value[1], $qv->value[0]));
        else if ($qv->type == "revadj") {
            $qv->value["revadjnegate"] = !defval($qv->value, "revadjnegate", false);
            return $qv;
        } else {
            $float = $qe->get("float");
            $qv = SearchTerm::extract_float($float, $qv);
            return SearchTerm::combine_float($float, "not", $qv);
        }
    }

    static function _reviewAdjustmentNegate($ra) {
        global $Conf;
        if (isset($ra->value["round"]))
            $ra->value["round"] = array_diff(array_keys($Conf->round_list()), $ra->value["round"]);
        if (isset($ra->value["rate"]))
            $ra->value["rate"] = "not (" . $ra->value["rate"] . ")";
        $ra->value["revadjnegate"] = false;
    }

    static function _reviewAdjustmentMerge($revadj, $qv, $op) {
        // XXX this is probably not right in fully general cases
        if (!$revadj)
            return $qv;
        list($neg1, $neg2) = array(defval($revadj->value, "revadjnegate"), defval($qv->value, "revadjnegate"));
        if ($neg1 !== $neg2 || ($neg1 && $op == "or")) {
            if ($neg1)
                self::_reviewAdjustmentNegate($revadj);
            if ($neg2)
                self::_reviewAdjustmentNegate($qv);
            $neg1 = $neg2 = false;
        }
        if ($op == "or" || $neg1) {
            if (isset($qv->value["round"]))
                $revadj->value["round"] = array_unique(array_merge(defval($revadj->value, "round", array()), $qv->value["round"]));
            if (isset($qv->value["rate"]))
                $revadj->value["rate"] = "(" . defval($revadj->value, "rate", "false") . ") or (" . $qv->value["rate"] . ")";
        } else {
            if (isset($revadj->value["round"]) && isset($qv->value["round"]))
                $revadj->value["round"] = array_intersect($revadj->value["round"], $qv->value["round"]);
            else if (isset($qv->value["round"]))
                $revadj->value["round"] = $qv->value["round"];
            if (isset($qv->value["rate"]))
                $revadj->value["rate"] = "(" . defval($revadj->value, "rate", "true") . ") and (" . $qv->value["rate"] . ")";
        }
        return $revadj;
    }

    function _queryCleanOr($qe) {
        $revadj = null;
        $float = $qe->get("float");
        $newvalues = array();

        foreach ($qe->value as $qv) {
            $qv = SearchTerm::extract_float($float, $this->_queryClean($qv, true));
            if ($qv && $qv->type == "revadj")
                $revadj = self::_reviewAdjustmentMerge($revadj, $qv, "or");
            else if ($qv)
                $newvalues[] = $qv;
        }

        if ($revadj && count($newvalues) == 0)
            return $revadj;
        else if ($revadj)
            $this->_reviewAdjustError = true;
        return SearchTerm::combine_float($float, "or", $newvalues);
    }

    function _queryCleanAnd($qe) {
        $pn = array(array(), array());
        $revadj = null;
        $float = $qe->get("float");
        $newvalues = array();

        foreach ($qe->value as $qv) {
            $qv = SearchTerm::extract_float($float, $this->_queryClean($qv, true));
            if ($qv && $qv->type == "pn") {
                $pn[0] = array_merge($pn[0], $qv->value[0]);
                $pn[1] = array_merge($pn[1], $qv->value[1]);
            } else if ($qv && $qv->type == "revadj")
                $revadj = self::_reviewAdjustmentMerge($revadj, $qv, "and");
            else if ($qv)
                $newvalues[] = $qv;
        }

        if (count($pn[0]) || count($pn[1]))
            array_unshift($newvalues, new SearchTerm("pn", 0, $pn));
        if ($revadj)            // must be first
            array_unshift($newvalues, $revadj);
        return SearchTerm::combine_float($float, "and", $newvalues);
    }

    function _queryCleanThen($qe, $below) {
        if ($below) {
            $this->_thenError = true;
            $qe->type = "or";
            return $this->_queryCleanOr($qe);
        }
        $float = $qe->get("float");
        for ($i = 0; $i < count($qe->value); ) {
            $qv = $qe->value[$i];
            if ($qv->type == "then")
                array_splice($qe->value, $i, 1, $qv->value);
            else {
                $qe->value[$i] = SearchTerm::extract_float($float, $this->_queryClean($qv, true));
                ++$i;
            }
        }
        return SearchTerm::combine_float($float, "then", $qe->value);
    }

    // apply rounds to reviewer searches
    function _queryMakeAdjustedReviewSearch($roundterm) {
        if ($this->limitName == "r" || $this->limitName == "rout")
            $value = new SearchReviewValue(">0", array($this->cid));
        else if ($this->limitName == "req" || $this->limitName == "reqrevs")
            $value = new SearchReviewValue(">0", null, "requestedBy=" . $this->cid . " and reviewType=" . REVIEW_EXTERNAL);
        else
            $value = new SearchReviewValue(">0");
        $rt = $this->privChair ? 0 : self::F_NONCONFLICT;
        if (!$this->amPC)
            $rt |= self::F_REVIEWER;
        $term = new SearchTerm("re", $rt | self::F_XVIEW, $value, $roundterm->value);
        if (defval($roundterm->value, "revadjnegate")) {
            $term->set("revadjnegate", false);
            return SearchTerm::negate($term);
        } else
            return $term;
    }

    function _queryAdjustReviews($qe, $revadj) {
        $applied = $first_applied = 0;
        $adjustments = array("round", "rate");
        if ($qe->type == "not")
            $this->_queryAdjustReviews($qe->value, $revadj);
        else if ($qe->type == "and") {
            $myrevadj = ($qe->value[0]->type == "revadj" ? $qe->value[0] : null);
            if ($myrevadj) {
                $used_revadj = false;
                foreach ($adjustments as $adj)
                    if (!isset($myrevadj->value[$adj]) && isset($revadj->value[$adj])) {
                        $myrevadj->value[$adj] = $revadj->value[$adj];
                        $used_revadj = true;
                    }
            }

            $rdown = $myrevadj ? $myrevadj : $revadj;
            for ($i = 0; $i < count($qe->value); ++$i)
                if ($qe->value[$i]->type != "revadj")
                    $this->_queryAdjustReviews($qe->value[$i], $rdown);

            if ($myrevadj && !isset($myrevadj->used_revadj)) {
                $qe->value[0] = $this->_queryMakeAdjustedReviewSearch($myrevadj);
                if ($used_revadj)
                    $revadj->used_revadj = true;
            }
        } else if ($qe->type == "or" || $qe->type == "then") {
            for ($i = 0; $i < count($qe->value); ++$i)
                $this->_queryAdjustReviews($qe->value[$i], $revadj);
        } else if ($qe->type == "re" && $revadj) {
            foreach ($adjustments as $adj)
                if (isset($revadj->value[$adj]))
                    $qe->set($adj, $revadj->value[$adj]);
            $revadj->used_revadj = true;
        } else if ($qe->type == "revadj") {
            assert(!$revadj);
            return $this->_queryMakeAdjustedReviewSearch($qe);
        }
        return $qe;
    }

    function _queryExtractInfo($qe, $top, &$contradictions) {
        if ($qe->type == "and" || $qe->type == "or" || $qe->type == "then") {
            foreach ($qe->value as $qv)
                $this->_queryExtractInfo($qv, $top && $qe->type == "and", $contradictions);
        }
        if (($x = $qe->get("regex"))) {
            $this->regex[$x[0]] = defval($this->regex, $x[0], array());
            $this->regex[$x[0]][] = $x[1];
        }
        if (($x = $qe->get("tagorder")))
            $this->orderTags[] = $x;
        if ($top && $qe->type == "re" && !$this->_reviewer_fixed) {
            if ($this->_reviewer === false) {
                $v = $qe->value->contactsql;
                if ($v[0] == "=")
                    $this->_reviewer = (int) substr($v, 1);
                else if ($v[0] == "\1") {
                    $v = (int) substr($v, 1, strpos($v, "\1", 1) - 1);
                    if (count($this->contact_match[$v]->ids) == 1)
                        $this->_reviewer = $this->contact_match[$v]->ids[0];
                }
            } else
                $this->_reviewer = null;
        }
        if ($top && ($x = $qe->get("contradiction_warning")))
            $contradictions[$x] = true;
    }


    // QUERY CONSTRUCTION
    // Build a database query corresponding to an expression.
    // The query may be liberal (returning more papers than actually match);
    // QUERY EVALUATION makes it precise.

    private function _clauseTermSetFlags($t, $sqi, &$q) {
        $flags = $t->flags;
        $this->needflags |= $flags;

        if ($flags & self::F_NONCONFLICT)
            $q[] = "PaperConflict.conflictType is null";
        if ($flags & self::F_AUTHOR)
            $q[] = $this->contact->actAuthorSql("PaperConflict");
        if ($flags & self::F_REVIEWER)
            $q[] = "MyReview.reviewNeedsSubmit=0";
        if ($flags & self::F_XVIEW) {
            $this->needflags |= self::F_NONCONFLICT | self::F_REVIEWER;
            $sqi->add_manager_column();
        }
        if (($flags & self::F_FALSE)
            || ($sqi->negated && ($flags & self::F_XVIEW)))
            $q[] = "false";
    }

    private function _clauseTermSetField(&$t, $field, $sqi, &$f) {
        $this->needflags |= $t->flags;
        $v = $t->value;
        if ($v != "" && $v[0] == "*")
            $v = substr($v, 1);
        if ($v != "" && $v[strlen($v) - 1] == "*")
            $v = substr($v, 0, strlen($v) - 1);
        if ($sqi->negated)
            // The purpose of _clauseTermSetField is to match AT LEAST those
            // papers that contain "$t->value" as a word in the $field field.
            // A substring match contains at least those papers; but only if
            // the current term is positive (= not negated).  If the current
            // term is negated, we say NO paper matches this clause.  (NOT no
            // paper is every paper.)  Later code will check for a substring.
            $f[] = "false";
        else if (!ctype_alnum($v))
            $f[] = "true";
        else {
            $q = array();
            $this->_clauseTermSetFlags($t, $sqi, $q);
            $q[] = "Paper.$field like '%$v%'";
            $f[] = "(" . join(" and ", $q) . ")";
        }
        $t->link = $field;
        $this->needflags |= self::F_XVIEW;
    }

    private function _clauseTermSetTable(&$t, $value, $compar, $shorttab,
                                         $table, $field, $where, $sqi, &$f) {
        // see also first "tag" case below
        $q = array();
        $this->_clauseTermSetFlags($t, $sqi, $q);

        if ($value == "none" && !$compar)
            list($compar, $value) = array("=0", "");
        else if (($value == "" || $value == "any") && !$compar)
            list($compar, $value) = array(">0", "");
        else if (!$compar || $compar == ">=1")
            $compar = ">0";
        else if ($compar == "<=0" || $compar == "<1")
            $compar = "=0";

        $thistab = $shorttab . "_" . count($sqi->tables);
        if ($value == "") {
            if ($compar == ">0" || $compar == "=0")
                $thistab = "Any" . $shorttab;
            $tdef = array("left join", $table);
        } else if (is_array($value)) {
            if (count($value))
                $tdef = array("left join", $table, "$thistab.$field in (" . join(",", $value) . ")");
            else
                $tdef = array("left join", $table, "false");
        } else if ($value[0] == "\1") {
            $tdef = array("left join", $table, str_replace("\3", "$thistab.$field", "\3$value"));
        } else if ($value[0] == "\3") {
            $tdef = array("left join", $table, str_replace("\3", "$thistab.$field", $value));
        } else {
            $tdef = array("left join", $table, "$thistab.$field='" . sqlq($value) . "'");
        }
        if ($where)
            $tdef[2] .= str_replace("%", $thistab, $where);

        if ($compar != ">0" && $compar != "=0") {
            $tdef[1] = "(select paperId, count(*) ct from " . $tdef[1] . " as " . $thistab;
            if (count($tdef) > 2)
                $tdef[1] .= " where " . array_pop($tdef);
            $tdef[1] .= " group by paperId)";
            $sqi->add_column($thistab . "_ct", "$thistab.ct");
            $q[] = "coalesce($thistab.ct,0)$compar";
        } else {
            $sqi->add_column($thistab . "_ct", "count($thistab.$field)");
            if ($compar == "=0")
                $q[] = "$thistab.$field is null";
            else
                $q[] = "$thistab.$field is not null";
        }

        $sqi->add_table($thistab, $tdef);
        $t->link = $thistab . "_ct";
        $f[] = "(" . join(" and ", $q) . ")";
    }

    static function unusableRatings($privChair, $contactId) {
        global $Conf;
        if ($privChair || $Conf->timePCViewAllReviews())
            return array();
        $noratings = array();
        $rateset = $Conf->setting("rev_rating");
        if ($rateset == REV_RATINGS_PC)
            $npr_constraint = "reviewType>" . REVIEW_EXTERNAL;
        else
            $npr_constraint = "true";
        // This query supposedly returns those reviewIds whose ratings
        // are not visible to the current querier
        $result = $Conf->q("select MPR.reviewId
        from PaperReview as MPR
        left join (select paperId, count(reviewId) as numReviews from PaperReview where $npr_constraint and reviewNeedsSubmit<=0 group by paperId) as NPR on (NPR.paperId=MPR.paperId)
        left join (select paperId, count(rating) as numRatings from PaperReview join ReviewRating using (reviewId) group by paperId) as NRR on (NRR.paperId=MPR.paperId)
        where MPR.contactId=$contactId
        and numReviews<=2
        and numRatings<=2");
        while (($row = edb_row($result)))
            $noratings[] = $row[0];
        return $noratings;
    }

    private function _clauseTermSetRating(&$reviewtable, &$where, $rate) {
        $noratings = "";
        if ($this->noratings === false)
            $this->noratings = self::unusableRatings($this->privChair, $this->cid);
        if (count($this->noratings) > 0)
            $noratings .= " and not (reviewId in (" . join(",", $this->noratings) . "))";
        else
            $noratings = "";

        foreach ($this->interestingRatings as $k => $v)
            $reviewtable .= " left join (select reviewId, count(rating) as nrate_$k from ReviewRating where rating$v$noratings group by reviewId) as Ratings_$k on (Ratings_$k.reviewId=r.reviewId)";
        $where[] = $rate;
    }

    private function _clauseTermSetReviews($thistab, $extrawhere, &$t, $sqi) {
        if (!isset($sqi->tables[$thistab])) {
            $where = array();
            $reviewtable = "PaperReview r";
            if ($t->flags & self::F_REVIEWTYPEMASK)
                $where[] = "reviewType=" . ($t->flags & self::F_REVIEWTYPEMASK);
            if ($t->flags & self::F_COMPLETE)
                $where[] = "reviewSubmitted>0";
            else if ($t->flags & self::F_INCOMPLETE)
                $where[] = "reviewNeedsSubmit>0";
            $rrnegate = $t->get("revadjnegate");
            if (($x = $t->get("round")) !== null) {
                if (count($x) == 0)
                    $where[] = $rrnegate ? "true" : "false";
                else
                    $where[] = "reviewRound " . ($rrnegate ? "not " : "") . "in (" . join(",", $x) . ")";
            }
            if (($x = $t->get("rate")) !== null)
                $this->_clauseTermSetRating($reviewtable, $where, $rrnegate ? "(not $x)" : $x);
            if ($extrawhere)
                $where[] = $extrawhere;
            $wheretext = "";
            if (count($where))
                $wheretext = " where " . join(" and ", $where);
            $sqi->add_table($thistab, array("left join", "(select r.paperId, count(r.reviewId) count, group_concat(r.reviewId, ' ', r.contactId, ' ', r.reviewType, ' ', coalesce(r.reviewSubmitted,0), ' ', r.reviewNeedsSubmit, ' ', r.requestedBy, ' ', r.reviewToken, ' ', r.reviewBlind) info from $reviewtable$wheretext group by paperId)"));
            $sqi->add_column($thistab . "_info", $thistab . ".info");
        }
        $q = array();
        $this->_clauseTermSetFlags($t, $sqi, $q);
        // Make the database query conservative (so change equality
        // constraints to >= constraints, and ignore <=/</!= constraints).
        // We'll do the precise query later.
        $q[] = "coalesce($thistab.count,0)" . $t->value->conservative_countexpr();
        $t->link = $thistab;
        return "(" . join(" and ", $q) . ")";
    }

    private function _clauseTermSetRevpref($thistab, $extrawhere, &$t, $sqi) {
        if (!isset($sqi->tables[$thistab])) {
            $where = array();
            $reviewtable = "PaperReviewPreference";
            if ($extrawhere)
                $where[] = $extrawhere;
            $wheretext = "";
            if (count($where))
                $wheretext = " where " . join(" and ", $where);
            $sqi->add_table($thistab, array("left join", "(select paperId, count(PaperReviewPreference.preference) as count from $reviewtable$wheretext group by paperId)"));
        }
        $q = array();
        $this->_clauseTermSetFlags($t, $sqi, $q);
        $q[] = "coalesce($thistab.count,0)" . $t->value->countexpr;
        $sqi->add_column($thistab . "_matches", "$thistab.count");
        $t->link = $thistab . "_matches";
        return "(" . join(" and ", $q) . ")";
    }

    private function _clauseTermSetComments($thistab, $extrawhere, &$t, $sqi) {
        global $Conf;
        if (!isset($sqi->tables[$thistab])) {
            $where = array();
            if (!($t->flags & self::F_ALLOWRESPONSE))
                $where[] = "(commentType&" . COMMENTTYPE_RESPONSE . ")=0";
            if (!($t->flags & self::F_ALLOWCOMMENT))
                $where[] = "(commentType&" . COMMENTTYPE_RESPONSE . ")!=0";
            if ($t->flags & self::F_AUTHORCOMMENT)
                $where[] = "commentType>=" . COMMENTTYPE_AUTHOR;
            if ($extrawhere)
                $where[] = $extrawhere;
            $wheretext = "";
            if (count($where))
                $wheretext = " where " . join(" and ", $where);
            $sqi->add_table($thistab, array("left join", "(select paperId, count(commentId) count, group_concat(contactId, ' ', commentType) info from PaperComment$wheretext group by paperId)"));
            $sqi->add_column($thistab . "_info", $thistab . ".info");
        }
        $q = array();
        $this->_clauseTermSetFlags($t, $sqi, $q);
        $q[] = "coalesce($thistab.count,0)" . $t->value->conservative_countexpr();
        $t->link = $thistab;
        return "(" . join(" and ", $q) . ")";
    }

    private function _clauseTermSet(&$t, $sqi, &$f) {
        $tt = $t->type;
        $thistab = null;

        // collect columns
        if ($tt == "ti") {
            $sqi->add_column("title", "Paper.title");
            $this->_clauseTermSetField($t, "title", $sqi, $f);
        } else if ($tt == "ab") {
            $sqi->add_column("abstract", "Paper.abstract");
            $this->_clauseTermSetField($t, "abstract", $sqi, $f);
        } else if ($tt == "au") {
            $sqi->add_column("authorInformation", "Paper.authorInformation");
            $this->_clauseTermSetField($t, "authorInformation", $sqi, $f);
        } else if ($tt == "co") {
            $sqi->add_column("collaborators", "Paper.collaborators");
            $this->_clauseTermSetField($t, "collaborators", $sqi, $f);
        } else if ($tt == "au_cid") {
            $this->_clauseTermSetTable($t, $t->value, null, "AuthorConflict",
                                       "PaperConflict", "contactId",
                                       " and " . $this->contact->actAuthorSql("%"),
                                       $sqi, $f);
        } else if ($tt == "re") {
            $extrawhere = array();
            if ($t->value->contactsql)
                $extrawhere[] = $t->value->contactWhere("r.contactId");
            if ($t->value->fieldsql)
                $extrawhere[] = $t->value->fieldsql;
            $extrawhere = join(" and ", $extrawhere);
            if ($extrawhere == "" && $t->get("round") === null && $t->get("rate") === null)
                $thistab = "Numreviews_" . ($t->flags & (self::F_REVIEWTYPEMASK | self::F_COMPLETE | self::F_INCOMPLETE));
            else
                $thistab = "Reviews_" . count($sqi->tables);
            $f[] = $this->_clauseTermSetReviews($thistab, $extrawhere, $t, $sqi);
        } else if ($tt == "revpref") {
            $extrawhere = array();
            if ($t->value->contactsql)
                $extrawhere[] = $t->value->contactWhere("contactId");
            if ($t->value->fieldsql)
                $extrawhere[] = $t->value->fieldsql;
            $extrawhere = join(" and ", $extrawhere);
            $thistab = "Revpref_" . count($sqi->tables);
            $f[] = $this->_clauseTermSetRevpref($thistab, $extrawhere, $t, $sqi);
        } else if ($tt == "conflict") {
            $this->_clauseTermSetTable($t, "\3" . $t->value->contactsql, $t->value->countexpr, "Conflict",
                                       "PaperConflict", "contactId", "",
                                       $sqi, $f);
        } else if ($tt == "cmt") {
            if ($t->value->contactsql)
                $thistab = "Comments_" . count($sqi->tables);
            else {
                $rtype = $t->flags & (self::F_ALLOWCOMMENT | self::F_ALLOWRESPONSE | self::F_AUTHORCOMMENT);
                $thistab = "Numcomments_" . $rtype;
            }
            $f[] = $this->_clauseTermSetComments($thistab, $t->value->contactWhere("contactId"), $t, $sqi);
        } else if ($tt == "cmttag") {
            $thistab = "TaggedComments_" . count($sqi->tables);
            if ($t->value->contactsql === "any")
                $match = "is not null";
            else
                $match = "like '% " . sqlq($t->value->contactsql) . " %'";
            $f[] = $this->_clauseTermSetComments($thistab, "commentTags $match", $t, $sqi);
        } else if ($tt == "pn") {
            $q = array();
            if (count($t->value[0]))
                $q[] = "Paper.paperId in (" . join(",", $t->value[0]) . ")";
            if (count($t->value[1]))
                $q[] = "Paper.paperId not in (" . join(",", $t->value[1]) . ")";
            if (!count($q))
                $q[] = "false";
            $f[] = "(" . join(" and ", $q) . ")";
        } else if ($tt == "pf") {
            $q = array();
            $this->_clauseTermSetFlags($t, $sqi, $q);
            for ($i = 0; $i < count($t->value); $i += 2) {
                if (is_array($t->value[$i + 1]))
                    $q[] = "Paper." . $t->value[$i] . " in (" . join(",", $t->value[$i + 1]) . ")";
                else
                    $q[] = "Paper." . $t->value[$i] . $t->value[$i + 1];
            }
            $f[] = "(" . join(" and ", $q) . ")";
            for ($i = 0; $i < count($t->value); $i += 2)
                $sqi->add_column($t->value[$i], "Paper." . $t->value[$i]);
        } else if ($tt == "tag" && is_array($t->value)) {
            $this->_clauseTermSetTable($t, $t->value[0], null, "Tag",
                                       "PaperTag", "tag", " and %.tagIndex" . $t->value[1],
                                       $sqi, $f);
        } else if ($tt == "tag") {
            $this->_clauseTermSetTable($t, $t->value, null, "Tag",
                                       "PaperTag", "tag", "",
                                       $sqi, $f);
        } else if ($tt == "topic") {
            $this->_clauseTermSetTable($t, $t->value, null, "Topic",
                                       "PaperTopic", "topicId", "",
                                       $sqi, $f);
        } else if ($tt == "option") {
            // expanded from _clauseTermSetTable
            $q = array();
            $this->_clauseTermSetFlags($t, $sqi, $q);
            $thistab = "Option_" . count($sqi->tables);
            $sqi->add_table($thistab, array("left join", "PaperOption", "$thistab.optionId=" . $t->value[0]->id));
            $sqi->add_column($thistab . "_x", "coalesce($thistab.value,0)" . $t->value[1]);
            $t->link = $thistab . "_x";
            $q[] = $sqi->columns[$t->link];
            $f[] = "(" . join(" and ", $q) . ")";
        } else if ($tt == "not") {
            $ff = array();
            $sqi->negated = !$sqi->negated;
            $this->_clauseTermSet($t->value, $sqi, $ff);
            $sqi->negated = !$sqi->negated;
            if (!count($ff))
                $ff[] = "true";
            $f[] = "not (" . join(" or ", $ff) . ")";
        } else if ($tt == "and") {
            $ff = array();
            foreach ($t->value as $subt)
                $this->_clauseTermSet($subt, $sqi, $ff);
            if (!count($ff))
                $ff[] = "false";
            $f[] = "(" . join(" and ", $ff) . ")";
        } else if ($tt == "or" || $tt == "then") {
            $ff = array();
            foreach ($t->value as $subt)
                $this->_clauseTermSet($subt, $sqi, $ff);
            if (!count($ff))
                $ff[] = "false";
            $f[] = "(" . join(" or ", $ff) . ")";
        } else if ($tt == "f")
            $f[] = "false";
        else if ($tt == "t")
            $f[] = "true";
    }


    // QUERY EVALUATION
    // Check the results of the query, reducing the possibly conservative
    // overestimate produced by the database to a precise result.

    private function _clauseTermCheckFlags($t, &$row) {
        $flags = $t->flags;
        if (($flags & self::F_AUTHOR)
            && !$this->contact->actAuthorView($row))
            return false;
        if (($flags & self::F_REVIEWER)
            && $row->myReviewNeedsSubmit !== 0
            && $row->myReviewNeedsSubmit !== "0")
            return false;
        if (($flags & self::F_NONCONFLICT) && $row->conflictType)
            return false;
        if ($flags & self::F_XVIEW) {
            if (!$this->contact->canViewPaper($row))
                return false;
            if ($t->type == "tag" && !$this->contact->canViewTags($row, true))
                return false;
            if (($t->type == "au" || $t->type == "au_cid" || $t->type == "co"
                 || $t->type == "conflict")
                && !$this->contact->allowViewAuthors($row))
                return false;
            if ($t->type == "pf" && $t->value[0] == "outcome"
                && !$this->contact->canViewDecision($row, true))
                return false;
            if ($t->type == "option"
                && !$this->contact->canViewPaperOption($row, $t->value[0], true))
                return false;
            if ($t->type == "re" && ($fieldname = $t->link)
                && !isset($row->$fieldname)) {
                $row->$fieldname = 0;
                $rrow = (object) array("paperId" => $row->paperId);
                $count_only = !$t->value->fieldsql;
                foreach (explode(",", defval($row, $fieldname . "_info", "")) as $info)
                    if ($info != "") {
                        list($rrow->reviewId, $rrow->contactId, $rrow->reviewType, $rrow->reviewSubmitted, $rrow->reviewNeedsSubmit, $rrow->requestedBy, $rrow->reviewToken, $rrow->reviewBlind) = explode(" ", $info);
                        if (($count_only
                             ? $this->contact->canCountReview($row, $rrow, true)
                             : $this->contact->canViewReview($row, $rrow, true))
                            && (!$t->value->contactsql
                                || $this->contact->canViewReviewerIdentity($row, $rrow, true))
                            && (!isset($t->value->view_score)
                                || $t->value->view_score > $this->contact->viewReviewFieldsScore($row, $rrow)))
                            ++$row->$fieldname;
                    }
            }
            if (($t->type == "cmt" || $t->type == "cmttag")
                && ($fieldname = $t->link)
                && !isset($row->$fieldname)) {
                $row->$fieldname = 0;
                $crow = (object) array("paperId" => $row->paperId);
                foreach (explode(",", defval($row, $fieldname . "_info", "")) as $info)
                    if ($info != "") {
                        list($crow->contactId, $crow->commentType) = explode(" ", $info);
                        if ($this->contact->canViewComment($row, $crow, true))
                            ++$row->$fieldname;
                    }
            }
            if ($t->type == "pf" && $t->value[0] == "leadContactId"
                && !$this->contact->can_view_lead($row, true))
                return false;
            if ($t->type == "pf" && $t->value[0] == "shepherdContactId"
                && !$this->contact->can_view_shepherd($row, true))
                return false;
            if ($t->type == "pf" && $t->value[0] == "managerContactId"
                && !$this->contact->can_view_paper_manager($row))
                return false;
        }
        if ($flags & self::F_FALSE)
            return false;
        return true;
    }

    function _clauseTermCheckField(&$t, &$row) {
        $field = $t->link;
        if (!$this->_clauseTermCheckFlags($t, $row)
            || $row->$field == "")
            return false;

        $field_deaccent = $field . "_deaccent";
        if (!isset($row->$field_deaccent)) {
            if (preg_match('/[\x80-\xFF]/', $row->$field))
                $row->$field_deaccent = UnicodeHelper::deaccent($row->$field);
            else
                $row->$field_deaccent = false;
        }

        if (!isset($t->preg_utf8))
            self::_analyze_field_preg($t);

        if (!isset($t->preg_raw))
            return !!preg_match('{' . $t->preg_utf8 . '}ui', $row->$field);
        else if ($row->$field_deaccent)
            return !!preg_match('{' . $t->preg_utf8 . '}ui', $row->$field_deaccent);
        else
            return !!preg_match('{' . $t->preg_raw . '}i', $row->$field);
    }

    function _clauseTermCheck(&$t, &$row) {
        $tt = $t->type;

        // collect columns
        if ($tt == "ti" || $tt == "ab" || $tt == "au" || $tt == "co")
            return $this->_clauseTermCheckField($t, $row);
        else if ($tt == "re" || $tt == "conflict" || $tt == "revpref"
                 || $tt == "cmt" || $tt == "cmttag") {
            if (!$this->_clauseTermCheckFlags($t, $row))
                return false;
            else {
                $fieldname = $t->link;
                return $t->value->test((int) $row->$fieldname);
            }
        } else if ($tt == "pn") {
            if (count($t->value[0]) && array_search($row->paperId, $t->value[0]) === false)
                return false;
            else if (count($t->value[1]) && array_search($row->paperId, $t->value[1]) !== false)
                return false;
            else
                return true;
        } else if ($tt == "pf") {
            if (!$this->_clauseTermCheckFlags($t, $row))
                return false;
            else {
                $ans = true;
                for ($i = 0; $ans && $i < count($t->value); $i += 2) {
                    $fieldname = $t->value[$i];
                    $expr = $t->value[$i + 1];
                    if (is_array($expr))
                        $ans = in_array($row->$fieldname, $expr);
                    else if ($expr[0] == '=')
                        $ans = $row->$fieldname == substr($expr, 1);
                    else if ($expr[0] == '!')
                        $ans = $row->$fieldname != substr($expr, 2);
                    else if ($expr[0] == '<' && $expr[1] == '=')
                        $ans = $row->$fieldname <= substr($expr, 2);
                    else if ($expr[0] == '>' && $expr[1] == '=')
                        $ans = $row->$fieldname >= substr($expr, 2);
                    else if ($expr[0] == '<')
                        $ans = $row->$fieldname < substr($expr, 1);
                    else if ($expr[0] == '>')
                        $ans = $row->$fieldname > substr($expr, 1);
                    else if ($expr[0] == "\1")
                        $ans = array_search($row->$fieldname, $this->contact_match[substr($expr, 1, strpos($expr, "\1", 1) - 1)]->ids) !== false;
                    else
                        $ans = false;
                }
                return $ans;
            }
        } else if ($tt == "tag" || $tt == "topic" || $tt == "option") {
            if (!$this->_clauseTermCheckFlags($t, $row))
                return false;
            else {
                $fieldname = $t->link;
                if (is_string($t->value) && $t->value == "none")
                    return $row->$fieldname == 0;
                else
                    return $row->$fieldname != 0;
            }
        } else if ($tt == "not") {
            return !$this->_clauseTermCheck($t->value, $row);
        } else if ($tt == "and") {
            foreach ($t->value as $subt)
                if (!$this->_clauseTermCheck($subt, $row))
                    return false;
            return true;
        } else if ($tt == "or" || $tt == "then") {
            foreach ($t->value as $subt)
                if ($this->_clauseTermCheck($subt, $row))
                    return true;
            return false;
        } else if ($tt == "f")
            return false;
        else if ($tt == "t")
            return true;
        else
            return true;
    }


    // BASIC QUERY FUNCTION

    function _search() {
        global $Conf;
        if ($this->_matchTable === false)
            return false;
        assert($this->_matchTable === null);
        $this->_matchTable = "PaperMatches_" . self::$_table_number;
        ++self::$_table_number;

        if ($this->limitName == "x") {
            if (!$Conf->qe("create temporary table $this->_matchTable select Paper.paperId from Paper where false"))
                return ($this->_matchTable = false);
            else
                return true;
        }

        // parse and clean the query
        $qe = $this->_searchQueryType($this->q);
        //$Conf->infoMsg(Ht::pre_text(var_export($qe, true)));
        if (!$qe)
            $qe = new SearchTerm("t");

        // apply complex limiters (only current example: "acc" for non-chairs)
        $limit = $this->limitName;
        if ($limit == "acc" && !$this->privChair)
            $qe = SearchTerm::combine("and", array($qe, $this->_searchQueryWord("dec:yes", false)));

        // clean query
        $qe = $this->_queryClean($qe);
        // apply review rounds (top down, needs separate step)
        if ($this->reviewAdjust) {
            $qe = $this->_queryAdjustReviews($qe, null);
            if ($this->_reviewAdjustError)
                $this->warn("Unexpected use of “round:” or “rate:” ignored.  Stick to the basics, such as “re:reviewername round:roundname”.");
        }

        //$Conf->infoMsg(Ht::pre_text(var_export($qe, true)));

        // collect clauses into tables, columns, and filters
        $sqi = new SearchQueryInfo;
        $sqi->add_table("Paper");
        $sqi->add_column("paperId", "Paper.paperId");
        // always include columns needed by rights machinery
        $sqi->add_column("timeSubmitted", "Paper.timeSubmitted");
        $sqi->add_column("timeWithdrawn", "Paper.timeWithdrawn");
        $sqi->add_column("outcome", "Paper.outcome");
        $filters = array();
        $this->needflags = 0;
        $this->_clauseTermSet($qe, $sqi, $filters);
        //$Conf->infoMsg(Ht::pre_text(var_export($filters, true)));

        // status limitation parts
        if ($limit == "rable") {
            $limitcontact = $this->_reviewer_fixed ? $this->reviewer() : $this->contact;
            if ($limitcontact->allow_review_assignment_ignore_conflict(null))
                $limit = $Conf->can_pc_see_all_submissions() ? "act" : "s";
            else if (!$limitcontact->isPC)
                $limit = "r";
        }
        if ($limit == "s" || $limit == "req"
            || $limit == "acc" || $limit == "und"
            || $limit == "unm"
            || ($limit == "rable" && !$Conf->can_pc_see_all_submissions()))
            $filters[] = "Paper.timeSubmitted>0";
        else if ($limit == "act" || $limit == "r" || $limit == "rable")
            $filters[] = "Paper.timeWithdrawn<=0";
        else if ($limit == "unsub")
            $filters[] = "(Paper.timeSubmitted<=0 and Paper.timeWithdrawn<=0)";
        else if ($limit == "lead")
            $filters[] = "Paper.leadContactId=" . $this->cid;
        else if ($limit == "manager") {
            if ($this->privChair)
                $filters[] = "(Paper.managerContactId=" . $this->cid . " or Paper.managerContactId=0)";
            else
                $filters[] = "Paper.managerContactId=" . $this->cid;
        }

        // decision limitation parts
        if ($limit == "acc")
            $filters[] = "Paper.outcome>0";
        else if ($limit == "und")
            $filters[] = "Paper.outcome=0";

        // other search limiters
        if ($limit == "a") {
            $filters[] = $this->contact->actAuthorSql("PaperConflict");
            $this->needflags |= self::F_AUTHOR;
        } else if ($limit == "r") {
            $filters[] = "MyReview.reviewType is not null";
            $this->needflags |= self::F_REVIEWER;
        } else if ($limit == "ar") {
            $filters[] = "(" . $this->contact->actAuthorSql("PaperConflict") . " or (Paper.timeWithdrawn<=0 and MyReview.reviewType is not null))";
            $this->needflags |= self::F_AUTHOR | self::F_REVIEWER;
        } else if ($limit == "rout") {
            $filters[] = "MyReview.reviewNeedsSubmit!=0";
            $this->needflags |= self::F_REVIEWER;
        } else if ($limit == "revs")
            $sqi->add_table("Limiter", array("join", "PaperReview"));
        else if ($limit == "req")
            $sqi->add_table("Limiter", array("join", "PaperReview", "Limiter.requestedBy=$this->cid and Limiter.reviewType=" . REVIEW_EXTERNAL));
        else if ($limit == "unm")
            $filters[] = "Paper.managerContactId=0";

        // add common tables: conflicts, my own review, paper blindness
        if ($this->needflags & (self::F_NONCONFLICT | self::F_AUTHOR)) {
            $sqi->add_table("PaperConflict", array("left join", "PaperConflict", "PaperConflict.contactId=$this->cid"));
            $sqi->add_column("conflictType", "PaperConflict.conflictType");
        }
        if ($this->needflags & self::F_REVIEWER) {
            if ($Conf->submission_blindness() == Conference::BLIND_OPTIONAL)
                $sqi->add_column("paperBlind", "Paper.blind");
            $qb = "";
            if (($tokens = $this->contact->review_tokens()))
                $qb = " or MyReview.reviewToken in (" . join(",", $tokens) . ")";
            $sqi->add_table("MyReview", array("left join", "PaperReview", "(MyReview.contactId=$this->cid$qb)"));
            $sqi->add_column("myReviewType", "MyReview.reviewType");
            $sqi->add_column("myReviewNeedsSubmit", "MyReview.reviewNeedsSubmit");
            $sqi->add_column("myReviewSubmitted", "MyReview.reviewSubmitted");
        }

        // add permissions tables if we will filter the results
        $need_filter = (($this->needflags & self::F_XVIEW)
                        || $Conf->has_tracks()
                        || $qe->type == "then"
                        || $qe->get_float("heading")
                        || $limit == "rable");
        if ($need_filter) {
            $sqi->add_manager_column();
            if ($Conf->has_track_tags()) {
                $sqi->add_table("PaperTags", array("left join", "(select paperId, group_concat(' ', tag, '#', tagIndex separator '') as paperTags from PaperTag group by paperId)"));
                $sqi->add_column("paperTags", "PaperTags.paperTags");
            }
            if ($Conf->setting("sub_blind") == Conference::BLIND_OPTIONAL)
                $sqi->add_column("paperBlind", "Paper.blind");
        }

        // search contacts
        if (count($this->contact_match)) {
            $qterm = array();
            foreach ($this->contact_match as $cm) {
                if ($cm->sql[0] == "\2")
                    $qm = "(" . substr($cm->sql, 1) . ")";
                else if (($pos = strpos($cm->sql, "@")) !== false)
                    $qm = "(email like '" . substr($cm->sql, 0, $pos + 1) . "%" . substr($cm->sql, $pos + 1) . "%')";
                else if (preg_match('/\A(.*?)\s*([,\s])\s*(.*)\z/', $cm->sql, $m)) {
                    if ($m[2] == ",")
                        $qm = "(firstName like '" . trim($m[3]) . "%' and lastName like '" . trim($m[1]) . "%')";
                    else
                        $qm = "(concat(firstName, ' ', lastName) like '%$cm->sql%')";
                } else
                    $qm = "(firstName like '%$cm->sql%' or lastName like '%$cm->sql%' or email like '%$cm->sql%')";
                if ($cm->pc_only)
                    $qm = "($qm and PCMember.contactId is not null)";
                $qterm[] = $qm;
                $cm->ids = array();
            }
            $q = "select ContactInfo.contactId, "
                . (count($qterm) == 1 ? "true" : join(", ", $qterm))
                . " from ContactInfo left join PCMember using (contactId)"
                . " where " . join(" or ", $qterm);
            //$Conf->infoMsg(Ht::pre_text_wrap($q));
            $result = $Conf->q($q);
            while (($row = edb_row($result)))
                for ($i = 0; $i < count($this->contact_match); ++$i)
                    if ($row[$i + 1])
                        $this->contact_match[$i]->ids[] = (int) $row[0];
        }

        // create query
        $q = "select ";
        foreach ($sqi->columns as $colname => $value)
            $q .= $value . " " . $colname . ", ";
        $q = substr($q, 0, strlen($q) - 2) . " from ";
        foreach ($sqi->tables as $tabname => $value)
            if (!$value)
                $q .= $tabname;
            else {
                $joiners = array("$tabname.paperId=Paper.paperId");
                for ($i = 2; $i < count($value); ++$i)
                    $joiners[] = $value[$i];
                $q .= " " . $value[0] . " " . $value[1] . " as " . $tabname
                    . " on (" . join(" and ", $joiners) . ")";
            }
        if (count($filters))
            $q .= " where " . join(" and ", $filters);
        $q .= " group by Paper.paperId";

        // clean up contact matches
        if (count($this->contact_match))
            for ($i = 0; $i < count($this->contact_match); $i++)
                $q = str_replace("\1$i\1", sql_in_numeric_set($this->contact_match[$i]->ids), $q);
        //$Conf->infoMsg(Ht::pre_text_wrap($q));

        // actually perform query
        if (!$Conf->qe("create temporary table $this->_matchTable $q"))
            return ($this->_matchTable = false);

        // correct query, create thenmap and headingmap
        $this->thenmap = ($qe->type == "then" ? array() : null);
        $this->headingmap = array();
        if ($need_filter) {
            $delete = array();
            $result = $Conf->qe("select * from $this->_matchTable");
            $qe_heading = $qe->get_float("heading");
            while (($row = PaperInfo::fetch($result, $this->cid))) {
                if (!$this->contact->canViewPaper($row)
                    || ($limit == "rable"
                        && !$limitcontact->allow_review_assignment_ignore_conflict($row)))
                    $x = false;
                else if ($this->thenmap !== null) {
                    $x = false;
                    for ($i = 0; $i < count($qe->value) && $x === false; ++$i)
                        if ($this->_clauseTermCheck($qe->value[$i], $row))
                            $x = $i;
                } else
                    $x = !!$this->_clauseTermCheck($qe, $row);
                if ($x === false)
                    $delete[] = $row->paperId;
                else if ($this->thenmap !== null) {
                    $this->thenmap[$row->paperId] = $x;
                    $qex = $qe->value[$x];
                    $this->headingmap[$row->paperId] =
                        $qex->get_float("heading", $qex->get_float("substr", ""));
                } else if ($qe_heading)
                    $this->headingmap[$row->paperId] = $qe_heading;
            }
            if (count($delete)) {
                $q = "delete from $this->_matchTable where paperId in (" . join(",", $delete) . ")";
                //$Conf->infoMsg(nl2br(str_replace(" ", "&nbsp;", htmlspecialchars($q))));
                if (!$Conf->qe($q)) {
                    $Conf->q("drop temporary table $this->_matchTable");
                    return ($this->_matchTable = false);
                }
            }
            if (!count($this->headingmap))
                $this->headingmap = null;
        }
        $this->viewmap = $qe->get_float("view", array());

        // extract regular expressions and set _reviewer if the query is
        // about exactly one reviewer, and warn about contradictions
        $contradictions = array();
        $this->_queryExtractInfo($qe, true, $contradictions);
        foreach ($contradictions as $contradiction => $garbage)
            $this->warn($contradiction);

        // set $this->matchPreg from $this->regex
        if (!$this->overrideMatchPreg) {
            $this->matchPreg = array();
            foreach (array("ti" => "title", "au" => "authorInformation",
                           "ab" => "abstract", "co" => "collaborators")
                     as $k => $v)
                if (isset($this->regex[$k]) && count($this->regex[$k])) {
                    $a = $b = array();
                    foreach ($this->regex[$k] as $x) {
                        $a[] = $x->preg_utf8;
                        if (isset($x->preg_raw))
                            $b[] = $x->preg_raw;
                    }
                    $x = (object) array("preg_utf8" => join("|", $a));
                    if (count($a) == count($b))
                        $x->preg_raw = join("|", $b);
                    $this->matchPreg[$v] = $x;
                }
        }

        return true;
    }

    function complexSearch(&$queryOptions) {
        global $Conf;
        $limit = $this->limitName;
        if (($limit == "s" || $limit == "act")
            && $this->q == "re:me")
            $limit = "r";
        else if ($this->q)
            return true;
        if ($Conf->has_tracks()) {
            if (!$this->privChair || $limit == "rable")
                return true;
        }
        if ($limit == "rable") {
            $c = ($this->_reviewer_fixed ? $this->reviewer() : $this->contact);
            if ($c->isPC)
                $limit = $Conf->can_pc_see_all_submissions() ? "act" : "s";
            else
                $limit = "r";
        }
        if ($limit == "s" || $limit == "revs")
            $queryOptions["finalized"] = 1;
        else if ($limit == "unsub") {
            $queryOptions["unsub"] = 1;
            $queryOptions["active"] = 1;
        } else if ($limit == "acc") {
            if ($this->privChair || $Conf->timeAuthorViewDecision()) {
                $queryOptions["accepted"] = 1;
                $queryOptions["finalized"] = 1;
            } else
                return true;
        } else if ($limit == "und") {
            $queryOptions["undecided"] = 1;
            $queryOptions["finalized"] = 1;
        } else if ($limit == "r")
            $queryOptions["myReviews"] = 1;
        else if ($limit == "rout")
            $queryOptions["myOutstandingReviews"] = 1;
        else if ($limit == "a") {
            // If complex author SQL, always do search the long way
            if ($this->contact->actAuthorSql("%", true))
                return true;
            $queryOptions["author"] = 1;
        } else if ($limit == "req" || $limit == "reqrevs")
            $queryOptions["myReviewRequests"] = 1;
        else if ($limit == "act")
            $queryOptions["active"] = 1;
        else if ($limit == "lead")
            $queryOptions["myLead"] = 1;
        else if ($limit == "unm")
            $queryOptions["finalized"] = $queryOptions["unmanaged"] = 1;
        return false;
    }

    function numbered_papers() {
        if (preg_match('/\A\s*#?\d[-#\d\s]*\z/s', $this->q)) {
            $a = array();
            foreach (preg_split('/\s+/', $this->q) as $word) {
                if ($word[0] == "#" && preg_match('/\A#\d+(?:-#?\d+)?/', $word))
                    $word = substr($word, 1);
                if (ctype_digit($word))
                    $a[$word] = (int) $word;
                else if (preg_match('/\A(\d+)-#?(\d+)\z/s', $word, $m)) {
                    foreach (range($m[1], $m[2]) as $num)
                        $a[$num] = $num;
                } else
                    return null;
            }
            return array_values($a);
        } else
            return null;
    }

    function alternate_query() {
        global $Conf;
        if ($this->q !== "" && $this->q[0] !== "#"
            && preg_match('/\A' . TAG_REGEX . '\z/', $this->q)) {
            if ($this->q[0] === "~"
                || (($result = $Conf->qe("select paperId from PaperTag where tag='" . sqlq($this->q) . "' limit 1"))
                    && edb_row($result)))
                return "#" . $this->q;
        }
        return false;
    }

    function has_sort() {
        return count($this->orderTags)
            || $this->numbered_papers() !== null
            || @$this->viewmap["sort"];
    }

    function matchTable() {
        if ($this->_matchTable === null)
            $this->_search();
        return $this->_matchTable;
    }

    function paperList() {
        global $Conf;
        if (!$this->_matchTable && !$this->_search())
            return array();
        $x = array();
        $result = $Conf->q("select paperId from $this->_matchTable");
        while (($row = edb_row($result)))
            $x[] = (int) $row[0];
        return $x;
    }

    function url_site_relative($q = null) {
        $url = $this->urlbase;
        if ($q === null)
            $q = $this->q;
        if ($q != "" || substr($this->urlbase, 0, 6) == "search")
            $url .= (strpos($url, "?") === false ? "?" : "&")
                . "q=" . urlencode($q);
        return $url;
    }

    function reviewer() {
        if (is_object($this->_reviewer))
            return $this->_reviewer;
        else if ($this->_reviewer)
            return Contact::find_by_id($this->_reviewer);
        else
            return null;
    }

    function reviewer_cid() {
        if (is_object($this->_reviewer))
            return $this->_reviewer->contactId;
        else if ($this->_reviewer)
            return $this->_reviewer;
        else
            return 0;
    }

    private function _tag_description() {
        if ($this->q == "")
            return false;
        else if (strlen($this->q) <= 24)
            return htmlspecialchars($this->q);
        else if (!preg_match(',\A(#|-#|tag:|-tag:|notag:|order:|rorder:)(.*)\z,', $this->q, $m))
            return false;
        $tagger = new Tagger($this->contact);
        if (!$tagger->check($m[2]))
            return false;
        else if ($m[1] == "-tag:")
            return "no" . substr($this->q, 1);
        else
            return $this->q;
    }

    function description($listname) {
        if (!$listname) {
            $a = array("s" => "Submitted papers", "acc" => "Accepted papers",
                       "act" => "Active papers", "all" => "All papers",
                       "r" => "Your reviews", "a" => "Your submissions",
                       "rout" => "Your incomplete reviews",
                       "req" => "Your review requests",
                       "reqrevs" => "Your review requests",
                       "rable" => "Reviewable papers");
            if (isset($a[$this->limitName]))
                $listname = $a[$this->limitName];
            else
                $listname = "Papers";
        }
        if ($this->q == "")
            return $listname;
        if (($td = $this->_tag_description())) {
            if ($listname == "Submitted papers") {
                if ($this->q == "re:me")
                    return "Your reviews";
                else
                    return $td;
            } else
                return "$td in $listname";
        } else {
            $listname = preg_replace("/s\\z/", "", $listname);
            return "$listname search";
        }
    }

    function listId($sort = "") {
        return "p/" . $this->limitName . "/" . urlencode($this->q)
            . "/" . ($sort ? $sort : "");
    }

    function create_session_list_object($ids, $listname, $sort = "") {
        $l = SessionList::create($this->listId($sort), $ids,
                                 $this->description($listname),
                                 $this->url_site_relative());
        if ($this->matchPreg)
            $l->matchPreg = $this->matchPreg;
        return $l;
    }

    function session_list_object($sort = null) {
        return $this->create_session_list_object($this->paperList(),
                                                 null, $sort);
    }

    static function parsePapersel() {
        global $Me, $papersel, $paperselmap;
        if (!isset($_REQUEST["p"]) && isset($_REQUEST["pap"]))
            $_REQUEST["p"] = $_REQUEST["pap"];
        if (isset($_REQUEST["p"]) && $_REQUEST["p"] == "all") {
            $s = new PaperSearch($Me, $_REQUEST);
            $_REQUEST["p"] = $s->paperList();
        }
        if (isset($_REQUEST["p"]) && is_string($_REQUEST["p"]))
            $_REQUEST["p"] = preg_split('/\s+/', $_REQUEST["p"]);
        if (isset($_REQUEST["p"]) && is_array($_REQUEST["p"])) {
            $papersel = array();
            $paperselmap = array();
            foreach ($_REQUEST["p"] as $p)
                if (($p = cvtint($p)) > 0 && !isset($paperselmap[$p])) {
                    $paperselmap[$p] = count($papersel);
                    $papersel[] = $p;
                }
            if (count($papersel) == 0) {
                unset($papersel);
                unset($paperselmap);
            }
        }
    }

    static function clearPaperselRequest() {
        unset($_REQUEST["p"]);
        unset($_REQUEST["pap"]);
    }

    static function searchTypes($me) {
        global $Conf;
        $tOpt = array();
        if ($me->isPC && $Conf->can_pc_see_all_submissions())
            $tOpt["act"] = "Active papers";
        if ($me->isPC)
            $tOpt["s"] = "Submitted papers";
        if ($me->isPC && $Conf->timePCViewDecision(false) && $Conf->setting("paperacc") > 0)
            $tOpt["acc"] = "Accepted papers";
        if ($me->privChair)
            $tOpt["all"] = "All papers";
        if ($me->privChair && !$Conf->can_pc_see_all_submissions()
            && defval($_REQUEST, "t") == "act")
            $tOpt["act"] = "Active papers";
        if ($me->is_reviewer())
            $tOpt["r"] = "Your reviews";
        if ($me->has_outstanding_review()
            || ($me->is_reviewer() && defval($_REQUEST, "t") == "rout"))
            $tOpt["rout"] = "Your incomplete reviews";
        if ($me->isPC)
            $tOpt["req"] = "Your review requests";
        if ($me->isPC && $Conf->setting("paperlead") > 0
            && $me->is_discussion_lead())
            $tOpt["lead"] = "Your discussion leads";
        if ($me->isPC && $Conf->setting("papermanager") > 0
            && ($me->is_manager() || $me->privChair))
            $tOpt["manager"] = "Papers you administer";
        if ($me->is_author())
            $tOpt["a"] = "Your submissions";
        return $tOpt;
    }

    static function searchTypeSelector($tOpt, $type, $tabindex) {
        if (count($tOpt) > 1) {
            $sel_opt = array();
            foreach ($tOpt as $k => $v) {
                if (count($sel_opt) && $k == "a")
                    $sel_opt["xxxa"] = null;
                if (count($sel_opt) && ($k == "lead" || $k == "r") && !isset($sel_opt["xxxa"]))
                    $sel_opt["xxxb"] = null;
                $sel_opt[$k] = $v;
            }
            $sel_extra = array();
            if ($tabindex)
                $sel_extra["tabindex"] = 1;
            return Ht::select("t", $sel_opt, $type, $sel_extra);
        } else
            return current($tOpt);
    }

}
