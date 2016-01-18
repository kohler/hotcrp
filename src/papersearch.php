<?php
// papersearch.php -- HotCRP helper class for searching for papers
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SearchOperator {
    public $op;
    public $unary;
    public $precedence;
    public $opinfo;
    function __construct($what, $unary, $precedence) {
        $this->op = $what;
        $this->unary = $unary;
        $this->precedence = $precedence;
    }

    static public $list;
}

SearchOperator::$list =
        array("(" => new SearchOperator("(", true, null),
              "NOT" => new SearchOperator("not", true, 7),
              "-" => new SearchOperator("not", true, 7),
              "+" => new SearchOperator("+", true, 7),
              "SPACE" => new SearchOperator("and2", false, 6),
              "AND" => new SearchOperator("and", false, 5),
              "OR" => new SearchOperator("or", false, 4),
              "XAND" => new SearchOperator("and2", false, 3),
              "XOR" => new SearchOperator("or", false, 3),
              "THEN" => new SearchOperator("then", false, 2),
              "HIGHLIGHT" => new SearchOperator("highlight", false, 1),
              ")" => null);


class SearchTerm {
    public $type;
    public $link;
    public $flags;
    public $value;
    public $float;
    public $regex;

    function __construct($t, $f = 0, $v = null, $other = null) {
        if ($t instanceof SearchOperator) {
            $this->type = $t->op;
            if ($t->opinfo)
                $this->set_float("opinfo", $t->opinfo);
        } else
            $this->type = $t;
        $this->link = false;
        $this->flags = $f;
        $this->value = $v;
        if ($other) {
            foreach ($other as $k => $v)
                $this->$k = $v;
        }
    }
    private function append($term) {
        if ($term && $term->float) {
            $this->float = $this->float ? : array();
            foreach ($term->float as $k => $v)
                if ($k === "sort" && isset($this->float["sort"]))
                    array_splice($this->float["sort"], count($this->float["sort"]), 0, $v);
                else if (is_array(@$this->float[$k]) && is_array($v))
                    $this->float[$k] = array_replace_recursive($this->float[$k], $v);
                else if ($k !== "opinfo" || !isset($this->float[$k]))
                    $this->float[$k] = $v;
        }
        if ($term)
            $this->value[] = $term;
        return $this;
    }
    private function finish() {
        if ($this->type === "not")
            return $this->_finish_not();
        else if ($this->type === "and" || $this->type === "and2")
            return $this->_finish_and();
        else if ($this->type === "or")
            return $this->_finish_or();
        else if ($this->type === "then" || $this->type === "highlight")
            return $this->_finish_then();
        else
            return $this;
    }
    private function _finish_not() {
        $qv = $this->value ? $this->value[0] : null;
        if (!$qv || $qv->is_false())
            $this->type = "t";
        else if ($qv->is_true())
            $this->type = "f";
        else if ($qv->type === "not") {
            $qr = clone $qv->value[0];
            $qr->float = $this->float;
            return $qr;
        } else if ($qv->type === "pn") {
            $this->type = "pn";
            $this->value = array($qv->value[1], $qv->value[0]);
        } else if ($qv->type === "revadj") {
            $qr = clone $qv->value[0];
            $qr->float = $this->float;
            $qr->value["revadjnegate"] = !@$qr->value["revadjnegate"];
            return $qr;
        }
        return $this;
    }
    private function _flatten_values() {
        $qvs = array();
        foreach ($this->value ? : array() as $qv)
            if ($qv->type === $this->type)
                $qvs = array_merge($qvs, $qv->value);
            else
                $qvs[] = $qv;
        return $qvs;
    }
    private function _finish_and() {
        $pn = array(array(), array());
        $revadj = null;
        $newvalue = array();
        $any = false;

        foreach ($this->_flatten_values() as $qv)
            if ($qv->is_false()) {
                $this->type = "f";
                return $this;
            } else if ($qv->is_true())
                $any = true;
            else if ($qv->type === "pn" && $this->type === "and2") {
                $pn[0] = array_merge($pn[0], $qv->value[0]);
                $pn[1] = array_merge($pn[1], $qv->value[1]);
            } else if ($qv->type === "revadj")
                $revadj = PaperSearch::_reviewAdjustmentMerge($revadj, $qv, "and");
            else
                $newvalue[] = $qv;

        return $this->_finish_combine($newvalue, $pn, $revadj, $any);
    }
    private function _finish_or() {
        $pn = array(array(), array());
        $revadj = null;
        $newvalue = array();

        foreach ($this->_flatten_values() as $qv) {
            if ($qv->is_true()) {
                $this->type = "t";
                return $this;
            } else if ($qv->is_false())
                /* skip */;
            else if ($qv->type === "pn" && count($qv->value[0]))
                $pn[0] = array_merge($pn[0], array_values(array_diff($qv->value[0], $qv->value[1])));
            else if ($qv->type === "revadj")
                $revadj = PaperSearch::_reviewAdjustmentMerge($revadj, $qv, "or");
            else
                $newvalue[] = $qv;
        }

        return $this->_finish_combine($newvalue, $pn, $revadj, false);
    }
    private function _finish_combine($newvalue, $pn, $revadj, $any) {
        if (count($pn[0]) || count($pn[1]))
            $newvalue[] = new SearchTerm("pn", 0, $pn);
        if ($revadj)            // must be first
            array_unshift($newvalue, $revadj);
        if (!$newvalue && $any)
            $this->type = "t";
        else if (!$newvalue)
            $this->type = "f";
        else if (count($newvalue) == 1) {
            $qr = clone $newvalue[0];
            $qr->float = $this->float;
            return $qr;
        } else
            $this->value = $newvalue;
        return $this;
    }
    private function _finish_then() {
        $ishighlight = $this->type !== "then";
        $opinfo = strtolower(@$this->get_float("opinfo", ""));
        $newvalues = $newhvalues = $newhmasks = $newhtypes = array();
        $this->type = "then";

        foreach ($this->value as $qvidx => $qv) {
            if ($qv && $qvidx && $ishighlight) {
                if ($qv->type === "then") {
                    for ($i = 0; $i < $qv->nthen; ++$i) {
                        $newhvalues[] = $qv->value[$i];
                        $newhmasks[] = (1 << count($newvalues)) - 1;
                        $newhtypes[] = $opinfo;
                    }
                } else {
                    $newhvalues[] = $qv;
                    $newhmasks[] = (1 << count($newvalues)) - 1;
                    $newhtypes[] = $opinfo;
                }
            } else if ($qv && $qv->type === "then") {
                $pos = count($newvalues);
                for ($i = 0; $i < $qv->nthen; ++$i)
                    $newvalues[] = $qv->value[$i];
                for ($i = $qv->nthen; $i < count($qv->value); ++$i)
                    $newhvalues[] = $qv->value[$i];
                foreach ($qv->highlights ? : array() as $hlmask)
                    $newhmasks[] = $hlmask << $pos;
                foreach ($qv->highlight_types ? : array() as $hltype)
                    $newhtypes[] = $hltype;
            } else if ($qv)
                $newvalues[] = $qv;
        }

        // set default headings
        foreach ($newvalues as $qv)
            if (($substr = $qv->get_float("substr")) !== null
                && $qv->get_float("heading") === null) {
                $substr = preg_replace(',\A\(\s*(.*)\s*\)\z,', '$1', $substr);
                $qv->set_float("heading", $substr);
            }

        $this->set("nthen", count($newvalues));
        $this->set("highlights", $newhmasks);
        $this->set("highlight_types", $newhtypes);
        array_splice($newvalues, $this->nthen, 0, $newhvalues);
        $this->value = $newvalues;
        $this->set_float("sort", array());
        return $this;
    }
    static function make_op($op, $terms) {
        $qr = new SearchTerm($op);
        if ($terms)
            foreach (is_array($terms) ? $terms : array($terms) as $qt)
                $qr->append($qt);
        return $qr->finish();
    }
    static function make_not($term) {
        $qr = new SearchTerm("not");
        return $qr->append($term)->finish();
    }
    static function make_opstr($op, $left, $right, $opstr) {
        $lstr = $left && !$op->unary ? $left->get_float("substr") : null;
        $rstr = $right ? $right->get_float("substr") : null;
        $qr = new SearchTerm($op);
        if (!$op->unary)
            $qr->append($left);
        $qr = $qr->append($right)->finish();
        if ($op->unary && $lstr !== null)
            $qr->set_float("substr", $opstr . $lstr);
        else if (!$op->unary && $lstr !== null && $rstr !== null)
            $qr->set_float("substr", $lstr . $opstr . $rstr);
        else
            $qr->set_float("substr", $lstr !== null ? $lstr : $rstr);
        return $qr;
    }
    static function make_float($float) {
        return new SearchTerm("t", 0, null, array("float" => $float));
    }
    function is_false() {
        return $this->type === "f";
    }
    function is_true() {
        return $this->type === "t";
    }
    function is_then() {
        return $this->type === "then" || $this->type === "highlight";
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


class CountMatcher {
    private $_countexpr;
    private $compar = 0;
    private $allowed = 0;

    static public $opmap = array("" => 2, "#" => 2, "=" => 2, "==" => 2,
                                 "!" => 5, "!=" => 5, "≠" => 5,
                                 "<" => 1, "<=" => 3, "≤" => 3,
                                 "≥" => 6, ">=" => 6, ">" => 4);
    static public $oparray = array(false, "<", "=", "<=", ">", "!=", ">=", false);

    function __construct($countexpr) {
        $this->_countexpr = $countexpr;
        if (preg_match('/\A([=!<>]=?|≠|≤|≥)\s*(-?\d+)\z/', $countexpr, $m)) {
            $this->allowed = self::$opmap[$m[1]];
            $this->compar = (int) $m[2];
        } else
            error_log(caller_landmark() . ": bogus countexpr $countexpr");
    }
    function test($n) {
        return self::compare($n, $this->allowed, $this->compar);
    }
    static function compare($x, $compar, $y) {
        if (!is_int($compar))
            $compar = self::$opmap[$compar];
        if ($x > $y)
            return ($compar & 4) != 0;
        else if ($x == $y)
            return ($compar & 2) != 0;
        else
            return ($compar & 1) != 0;
    }
    static function compare_string($x, $compar_y) {
        if (preg_match('/\A([=!<>]=?|≠|≤|≥)\s*(-?\d+)\z/', $compar_y, $m))
            return self::compare($x, $m[1], $m[2]);
        else
            return false;
    }
    public function countexpr() {
        if ($this->allowed)
            return self::$oparray[$this->allowed] . $this->compar;
        else
            return $this->_countexpr;
    }
    public function conservative_countexpr() {
        if ($this->allowed & 1)
            return ">=0";
        else
            return ($this->allowed & 2 ? ">=" : ">") . $this->compar;
    }
    static function negate_countexpr_string($str) {
        $t = new CountMatcher($str);
        if ($t->allowed)
            return self::$oparray[$t->allowed ^ 7] . $t->compar;
        else
            return $str;
    }
    static function canonical_comparator($str) {
        if (($x = self::$opmap[trim($str)]))
            return self::$oparray[$x];
        else
            return false;
    }
    static function canonicalize($countexpr) {
        $x = new CountMatcher($countexpr);
        return $x->allowed ? $x->countexpr() : false;
    }
}

class CommentTagMatcher extends CountMatcher {
    public $tag;

    function __construct($countexpr, $tag) {
        parent::__construct($countexpr);
        $this->tag = $tag;
    }
}

class ContactCountMatcher extends CountMatcher {
    private $_contacts = null;

    function __construct($countexpr, $contacts) {
        parent::__construct($countexpr);
        $this->set_contacts($contacts);
    }
    function has_contacts() {
        return $this->_contacts !== null;
    }
    function contact_set() {
        return $this->_contacts;
    }
    function contact_match_sql($fieldname) {
        if ($this->_contacts === null)
            return "true";
        else
            return $fieldname . sql_in_numeric_set($this->_contacts);
    }
    function test_contact($cid) {
        return $this->_contacts === null || in_array($cid, $this->_contacts);
    }
    function add_contact($cid) {
        if ($this->_contacts === null)
            $this->_contacts = array();
        if (!in_array($cid, $this->_contacts))
            $this->_contacts[] = $cid;
    }
    function set_contacts($contacts) {
        assert($contacts === null || is_array($contacts) || is_int($contacts));
        $this->_contacts = is_int($contacts) ? array($contacts) : $contacts;
    }
}

class ReviewSearchMatcher extends ContactCountMatcher {
    const COMPLETE = 1;
    const INCOMPLETE = 2;
    const INPROGRESS = 4;

    public $review_type = 0;
    public $completeness = 0;
    public $fieldsql = null;
    public $view_score = null;
    public $round = null;
    public $rate = null;
    public $wordcountexpr = null;

    function __construct($countexpr, $contacts = null, $fieldsql = null,
                         $view_score = null) {
        parent::__construct($countexpr, $contacts);
        $this->fieldsql = $fieldsql;
        $this->view_score = $view_score;
    }
    function simple_name() {
        if (!$this->has_contacts() && $this->fieldsql === null
            && $this->round === null && $this->rate === null
            && $this->wordcountexpr === null) {
            $name = $this->review_type ? ReviewForm::$revtype_names[$this->review_type] : "All";
            if ($this->completeness & self::COMPLETE)
                $name .= "Complete";
            if ($this->completeness & self::INCOMPLETE)
                $name .= "Incomplete";
            if ($this->completeness & self::INPROGRESS)
                $name .= "Inprogress";
            return $name;
        } else
            return false;
    }
    static function parse_review_type($type) {
        $type = strtolower($type);
        if ($type === "pri" || $type === "primary")
            return REVIEW_PRIMARY;
        else if ($type === "sec" || $type === "secondary")
            return REVIEW_SECONDARY;
        else if ($type === "ext" || $type === "external")
            return REVIEW_EXTERNAL;
        else if ($type === "pc" || $type === "pcre" || $type === "pcrev"
                 || $type === "optional")
            return REVIEW_PC;
        else
            return 0;
    }
}

class RevprefSearchMatcher extends ContactCountMatcher {
    public $preference_match = null;
    public $expertise_match = null;

    function __construct($countexpr, $contacts) {
        parent::__construct($countexpr, $contacts);
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
        assert(!isset($this->columns[$name]) || $this->columns[$name] === $expr);
        $this->columns[$name] = $expr;
    }
    public function add_rights_columns() {
        global $Conf;
        if (!isset($this->columns["managerContactId"]))
            $this->columns["managerContactId"] = "Paper.managerContactId";
        if (!isset($this->columns["leadContactId"]))
            $this->columns["leadContactId"] = "Paper.leadContactId";
    }
}

class ContactSearch {
    const F_SQL = 1;
    const F_TAG = 2;
    const F_PC = 4;
    const F_QUOTED = 8;
    const F_NOUSER = 16;

    public $type;
    public $text;
    public $me_cid;
    private $cset = null;
    public $ids = false;
    private $only_pc = false;
    private $contacts = false;
    public $warn_html = false;

    public function __construct($type, $text, $cid, $cset = null) {
        $this->type = $type;
        $this->text = $text;
        $this->me_cid = is_object($cid) ? $cid->contactId : $cid;
        $this->cset = $cset;
        if ($this->type & self::F_SQL) {
            $result = Dbl::qe("select contactId from ContactInfo where $text");
            $this->ids = Dbl::fetch_first_columns($result);
        }
        if ($this->ids === false && (!($this->type & self::F_QUOTED) || $this->text === ""))
            $this->ids = $this->check_simple();
        if ($this->ids === false && !($this->type & self::F_QUOTED) && ($this->type & self::F_TAG))
            $this->ids = $this->check_pc_tag();
        if ($this->ids === false && !($this->type & self::F_NOUSER))
            $this->ids = $this->check_user();
    }
    static function make_pc($text, $cid) {
        return new ContactSearch(self::F_PC | self::F_TAG, $text, $cid);
    }
    static function make_special($text, $cid) {
        return new ContactSearch(self::F_PC | self::F_TAG | self::F_NOUSER, $text, $cid);
    }
    static function make_cset($text, $cid, $cset) {
        return new ContactSearch(0, $text, $cid, $cset);
    }
    private function check_simple() {
        if ($this->text === ""
            || strcasecmp($this->text, "pc") == 0
            || (strcasecmp($this->text, "any") == 0 && ($this->type & self::F_PC)))
            return array_keys(pcMembers());
        else if (strcasecmp($this->text, "me") == 0
                 && (!($this->type & self::F_PC)
                     || (($pcm = pcMembers()) && isset($pcm[$this->me_cid]))))
            return array($this->me_cid);
        else
            return false;
    }
    private function check_pc_tag() {
        $need = $neg = false;
        $x = strtolower($this->text);
        if (substr($x, 0, 1) === "-") {
            $need = $neg = true;
            $x = substr($x, 1);
        }
        if (substr($x, 0, 1) === "#") {
            $need = true;
            $x = substr($x, 1);
        }

        $pctags = pcTags();
        if (isset($pctags[$x])) {
            $a = array();
            foreach (pcMembers() as $cid => $pc)
                if ($pc->has_tag($x))
                    $a[] = $cid;
            if ($neg && ($this->type & self::F_PC))
                return array_diff(array_keys(pcMembers()), $a);
            else if (!$neg)
                return $a;
            else {
                $result = Dbl::qe("select contactId from ContactInfo where contactId ?A", $a);
                return Dbl::fetch_first_columns($result);
            }
        } else if ($need) {
            $this->warn_html = "No such PC tag “" . htmlspecialchars($this->text) . "”.";
            return array();
        } else
            return false;
    }
    private function check_user() {
        if (strcasecmp($this->text, "anonymous") == 0
            && !$this->cset
            && !($this->type & self::F_PC)) {
            $result = Dbl::qe_raw("select contactId from ContactInfo where email regexp '^anonymous[0-9]*\$'");
            return Dbl::fetch_first_columns($result);
        }

        // split name components
        list($f, $l, $e) = Text::split_name($this->text, true);
        if ($f === "" && $l === "" && strpos($e, "@") === false)
            $n = $e;
        else
            $n = trim($f . " " . $l);

        // generalize email
        $estar = $e && strpos($e, "*") !== false;
        if ($e && !$estar) {
            if (preg_match('/\A(.*)@(.*?)((?:[.](?:com|net|edu|org|us|uk|fr|be|jp|cn))?)\z/', $e, $m))
                $e = ($m[1] === "" ? "*" : $m[1]) . "@*" . $m[2] . ($m[3] ? : "*");
            else
                $e = "*$e*";
        }

        // contact database if not restricted to PC or cset
        $result = null;
        if ($this->cset)
            $cs = $this->cset;
        else if ($this->type & self::F_PC)
            $cs = pcMembers();
        else {
            $q = array();
            if ($n !== "") {
                $x = sqlq_for_like(UnicodeHelper::deaccent($n));
                $q[] = "unaccentedName like '%" . preg_replace('/[\s*]+/', "%", $x) . "%'";
            }
            if ($e !== "") {
                $x = sqlq_for_like($e);
                $q[] = "email like '" . preg_replace('/[\s*]+/', "%", $x) . "'";
            }
            $result = Dbl::qe_raw("select firstName, lastName, unaccentedName, email, contactId, roles from ContactInfo where " . join(" or ", $q));
            $cs = array();
            while ($result && ($row = $result->fetch_object("Contact")))
                $cs[$row->contactId] = $row;
        }

        // filter results
        $nreg = $ereg = null;
        if ($n !== "")
            $nreg = PaperSearch::analyze_field_preg($n);
        if ($e !== "" && $estar)
            $ereg = '{\A' . str_replace('\*', '.*', preg_quote($e)) . '\z}i';
        else if ($e !== "") {
            $ereg = str_replace('@\*', '@(?:|.*[.])', preg_quote($e));
            $ereg = preg_replace('/\A\\\\\*/', '(?:.*[@.]|)', $ereg);
            $ereg = '{\A' . preg_replace('/\\\\\*$/', '(?:[@.].*|)', $ereg) . '\z}i';
        }

        $ids = array();
        foreach ($cs as $id => $acct)
            if ($ereg && preg_match($ereg, $acct->email)) {
                // exact email match trumps all else
                if (strcasecmp($e, $acct->email) == 0) {
                    $ids = array($id);
                    break;
                }
                $ids[] = $id;
            } else if ($nreg) {
                $n = $acct->firstName === "" || $acct->lastName === "" ? "" : " ";
                $n = $acct->firstName . $n . $acct->lastName;
                if (PaperSearch::match_field_preg($nreg, $n, $acct->unaccentedName))
                    $ids[] = $id;
            }

        Dbl::free($result);
        return $ids;
    }
    public function contacts() {
        global $Me;
        if ($this->contacts === false) {
            $this->contacts = array();
            $pcm = pcMembers();
            foreach ($this->ids as $cid)
                if ($this->cset && ($p = @$this->cset[$cid]))
                    $this->contacts[] = $p;
                else if (($p = @$pcm[$cid]))
                    $this->contacts[] = $p;
                else if ($Me->contactId == $cid)
                    $this->contacts[] = $Me;
                else
                    $this->contacts[] = Contact::find_by_id($cid);
        }
        return $this->contacts;
    }
    public function contact($i) {
        $this->contacts();
        return @$this->contacts[$i];
    }
}

class PaperSearch {
    const F_MANAGER = 0x0001;
    const F_NONCONFLICT = 0x0002;
    const F_AUTHOR = 0x0004;
    const F_REVIEWER = 0x0008;

    const F_AUTHORCOMMENT = 0x00200;
    const F_ALLOWRESPONSE = 0x00400;
    const F_ALLOWCOMMENT = 0x00800;
    const F_ALLOWDRAFT = 0x01000;
    const F_REQUIREDRAFT = 0x02000;

    const F_FALSE = 0x10000;
    const F_XVIEW = 0x20000;

    var $contact;
    public $cid;
    private $contactId;         // for backward compatibility
    var $privChair;
    private $amPC;

    var $limitName;
    var $qt;
    var $allowAuthor;
    private $fields;
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
    private $needflags = 0;
    private $_query_options = array();
    private $reviewAdjust = false;
    private $_reviewAdjustError = false;
    private $_ssRecursion = array();
    private $_allow_deleted = false;
    public $thenmap = null;
    public $headingmap = null;
    public $highlightmap = null;
    public $viewmap;
    public $sorters;
    private $_highlight_tags = null;

    private $_matches = null;

    static private $_sort_keywords = null;

    static private $_keywords = array("ti" => "ti", "title" => "ti",
        "ab" => "ab", "abstract" => "ab",
        "au" => "au", "author" => "au",
        "co" => "co", "collab" => "co", "collaborators" => "co",
        "r" => "re", "re" => "re", "rev" => "re", "review" => "re",
        "cre" => "cre", "crev" => "cre", "creview" => "cre",
        "ire" => "ire", "irev" => "ire", "ireview" => "ire",
        "pre" => "pre", "prev" => "pre", "preview" => "pre",
        "sre" => "cre", "srev" => "cre", "sreview" => "cre", // deprecated
        "subre" => "cre", "subrev" => "cre", "subreview" => "cre", // deprecated
        "pri" => "pri", "primary" => "pri",
        "prire" => "pri", "prirev" => "pri", "prireview" => "pri",
        "cpri" => "cpri", "cprimary" => "cpri",
        "cprire" => "cpri", "cprirev" => "cpri", "cprireview" => "cpri",
        "ipri" => "ipri", "iprimary" => "ipri",
        "iprire" => "ipri", "iprirev" => "ipri", "iprireview" => "ipri",
        "ppri" => "ppri", "pprimary" => "ppri",
        "pprire" => "ppri", "pprirev" => "ppri", "pprireview" => "ppri",
        "sec" => "sec", "secondary" => "sec",
        "secre" => "sec", "secrev" => "sec", "secreview" => "sec",
        "csec" => "csec", "csecondary" => "csec",
        "csecre" => "csec", "csecrev" => "csec", "csecreview" => "csec",
        "isec" => "isec", "isecondary" => "isec",
        "isecre" => "isec", "isecrev" => "isec", "isecreview" => "isec",
        "psec" => "psec", "psecondary" => "psec",
        "psecre" => "psec", "psecrev" => "psec", "psecreview" => "psec",
        "ext" => "ext", "external" => "ext",
        "extre" => "ext", "extrev" => "ext", "extreview" => "ext",
        "cext" => "cext", "cexternal" => "cext",
        "cextre" => "cext", "cextrev" => "cext", "cextreview" => "cext",
        "iext" => "iext", "iexternal" => "iext",
        "iextre" => "iext", "iextrev" => "iext", "iextreview" => "iext",
        "pext" => "pext", "pexternal" => "pext",
        "pextre" => "pext", "pextrev" => "pext", "pextreview" => "pext",
        "cmt" => "cmt", "comment" => "cmt",
        "aucmt" => "aucmt", "aucomment" => "aucmt",
        "resp" => "response", "response" => "response",
        "draftresp" => "draftresponse", "draftresponse" => "draftresponse",
        "draft-resp" => "draftresponse", "draft-response" => "draftresponse",
        "respdraft" => "draftresponse", "responsedraft" => "draftresponse",
        "resp-draft" => "draftresponse", "response-draft" => "draftresponse",
        "anycmt" => "anycmt", "anycomment" => "anycmt",
        "tag" => "tag",
        "notag" => "notag",
        "color" => "color", "style" => "color",
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
        "status" => "status", "has" => "has", "is" => "is",
        "rating" => "rate", "rate" => "rate",
        "revpref" => "revpref", "pref" => "revpref",
        "repref" => "revpref",
        "ss" => "ss", "search" => "ss",
        "formula" => "formula", "f" => "formula",
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
    static private $_canonical_review_keywords = array(
        "re" => 1, "cre" => 1, "ire" => 1, "pre" => 1,
        "pri" => 1, "cpri" => 1, "ipri" => 1, "ppri" => 1,
        "sec" => 1, "csec" => 1, "isec" => 1, "psec" => 1,
        "ext" => 1, "cext" => 1, "iext" => 1, "pext" => 1);


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
        else if (($me->privChair && $ptype === "act")
                 || ($me->isPC
                     && (!$ptype || $ptype === "act" || $ptype === "all")
                     && $Conf->can_pc_see_all_submissions()))
            $this->limitName = "act";
        else if ($me->privChair && $ptype === "unm")
            $this->limitName = "unm";
        else if ($me->isPC && (!$ptype || $ptype === "s" || $ptype === "unm"))
            $this->limitName = "s";
        else if ($me->isPC && ($ptype === "und" || $ptype === "undec"))
            $this->limitName = "und";
        else if ($me->isPC && ($ptype === "acc" || $ptype === "revs"
                               || $ptype === "reqrevs" || $ptype === "req"
                               || $ptype === "lead" || $ptype === "rable"
                               || $ptype === "manager"))
            $this->limitName = $ptype;
        else if ($this->privChair && ($ptype === "all" || $ptype === "unsub"))
            $this->limitName = $ptype;
        else if ($ptype === "r" || $ptype === "rout" || $ptype === "a")
            $this->limitName = $ptype;
        else if ($ptype === "rable")
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
            || ($this->amPC && $Conf->submission_blindness() != Conf::BLIND_ALWAYS))
            $this->allowAuthor = true;

        // default query fields
        // NB: If a complex query field, e.g., "re", "tag", or "option", is
        // default, then it must be the only default or query construction
        // will break.
        $this->fields = array();
        $qtype = defval($opt, "qt", "n");
        if ($qtype === "n" || $qtype === "ti")
            $this->fields["ti"] = 1;
        if ($qtype === "n" || $qtype === "ab")
            $this->fields["ab"] = 1;
        if ($this->allowAuthor && ($qtype === "n" || $qtype === "au" || $qtype === "ac"))
            $this->fields["au"] = 1;
        if ($this->privChair && $qtype === "ac")
            $this->fields["co"] = 1;
        if ($this->amPC && $qtype === "re")
            $this->fields["re"] = 1;
        if ($this->amPC && $qtype === "tag")
            $this->fields["tag"] = 1;
        $this->qt = ($qtype === "n" ? "" : $qtype);

        // the query itself
        $this->q = trim(defval($opt, "q", ""));

        // URL base
        if (isset($opt["urlbase"]))
            $this->urlbase = $opt["urlbase"];
        else {
            $this->urlbase = hoturl_site_relative_raw("search", "t=" . urlencode($this->limitName));
            if ($qtype !== "n")
                $this->urlbase .= "&qt=" . urlencode($qtype);
        }
        if (strpos($this->urlbase, "&amp;") !== false)
            trigger_error(caller_landmark() . " PaperSearch::urlbase should be a raw URL", E_USER_NOTICE);

        $this->_reviewer = defval($opt, "reviewer", false);
        $this->_reviewer_fixed = !!$this->_reviewer;

        $this->_allow_deleted = defval($opt, "allow_deleted", false);
    }

    // begin changing contactId to cid
    public function __get($name) {
        if ($name === "contactId") {
            trigger_error(caller_landmark() . ": PaperSearch->contactId deprecated, use cid", E_USER_NOTICE);
            return $this->cid;
        } else
            return null;
    }

    public function __set($name, $value) {
        if ($name === "contactId") {
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

    static public function analyze_field_preg($reg) {
        if (is_object($reg))
            $word = $reg->value;
        else {
            $word = $reg;
            $reg = (object) array();
        }

        $word = preg_quote(preg_replace('/\s+/', " ", $word));
        if (strpos($word, "*") !== false) {
            $word = str_replace('\*', '\S*', $word);
            $word = str_replace('\\\\\S*', '\*', $word);
        }

        if (preg_match("/[\x80-\xFF]/", $word))
            $reg->preg_utf8 = Text::utf8_word_regex($word);
        else {
            $reg->preg_raw = Text::word_regex($word);
            $reg->preg_utf8 = Text::utf8_word_regex($word);
        }
        return $reg;
    }

    static public function match_field_preg($reg, $raw, $deacc) {
        if (!isset($reg->preg_raw))
            return !!preg_match('{' . $reg->preg_utf8 . '}ui', $raw);
        else if ($deacc)
            return !!preg_match('{' . $reg->preg_utf8 . '}ui', $deacc);
        else
            return !!preg_match('{' . $reg->preg_raw . '}i', $raw);
    }

    private function _searchField($word, $rtype, &$qt) {
        if (!is_array($word))
            $extra = array("regex" => array($rtype, self::analyze_field_preg($word)));
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
                 && ($lword === "pc"
                     || (($pctags = pcTags()) && isset($pctags[$lword])))) {
            $cids = self::_pcContactIdsWithTag($lword);
            $this->_searchField($cids, "au_cid", $qt);
        } else
            $this->_searchField($word, "au", $qt);
    }

    static function _matchCompar($text, $quoted) {
        $text = trim($text);
        $compar = null;
        if (preg_match('/\A(.*?)([=!<>]=?|≠|≤|≥)\s*(\d+)\z/s', $text, $m)) {
            $text = $m[1];
            $compar = $m[2] . $m[3];
        }
        if (($text === "any" || $text === "" || $text === "yes") && !$quoted)
            return array("", $compar ? : ">0");
        else if (($text === "none" || $text === "no") && !$quoted)
            return array("", "=0");
        else if (!$compar && ctype_digit($text))
            return array("", "=" . $text);
        else
            return array($text, $compar ? : ">0");
    }

    static private function _tautology($compar) {
        if ($compar === "<0")
            return new SearchTerm("f");
        else if ($compar === ">=0")
            return new SearchTerm("t");
        else
            return null;
    }

    private static function _pcContactIdsWithTag($tag) {
        if ($tag === "pc")
            return array_keys(pcMembers());
        $a = array();
        foreach (pcMembers() as $cid => $pc)
            if ($pc->has_tag($tag))
                $a[] = $cid;
        return $a;
    }

    private function make_contact_match($type, $text, $me_cid) {
        foreach ($this->contact_match as $i => $cm)
            if ($cm->type === $type && $cm->text === $text
                && $cm->me_cid === $me_cid)
                return $cm;
        return $this->contact_match[] = new ContactSearch($type, $text, $me_cid);
    }

    private function _reviewerMatcher($word, $quoted, $pc_only) {
        $type = 0;
        if ($pc_only)
            $type |= ContactSearch::F_PC;
        if ($quoted)
            $type |= ContactSearch::F_QUOTED;
        if (!$quoted && $this->amPC)
            $type |= ContactSearch::F_TAG;
        $me_cid = $this->_reviewer_fixed ? $this->reviewer_cid() : $this->cid;
        $scm = $this->make_contact_match($type, $word, $me_cid);
        if ($scm->warn_html)
            $this->warn($scm->warn_html);
        if (count($scm->ids))
            return $scm->ids;
        else
            return array(-1);
    }

    private function _one_pc_matcher($text, $quoted) {
        if (($text === "any" || $text === "" || $text === "yes") && !$quoted)
            return "!=0";
        else if (($text === "none" || $text === "no") && !$quoted)
            return "=0";
        else
            return $this->_reviewerMatcher($text, $quoted, true);
    }

    private function _search_reviewer($qword, $keyword, &$qt) {
        global $Conf;
        if (preg_match('/\A(.*)(pri|sec|ext)\z/', $keyword, $m)) {
            $qword = $m[2] . ":" . $qword;
            $keyword = $m[1];
        }

        if (str_starts_with($keyword, "c"))
            $qword = "complete:" . $qword;
        else if (str_starts_with($keyword, "i"))
            $qword = "incomplete:" . $qword;
        else if (str_starts_with($keyword, "p"))
            $qword = "inprogress:" . $qword;

        $rt = 0;
        $completeness = 0;
        $quoted = false;
        $contacts = null;
        $rounds = null;
        $count = ">0";
        $wordcount = null;

        $tailre = '(?:\z|:|(?=[=!<>]=?|≠|≤|≥))(.*)\z/';

        while ($qword !== "") {
            if (preg_match('/\A((?:[=!<>]=?|≠|≤|≥|)\d+|any|none|yes|no)' . $tailre, $qword, $m)) {
                $count = self::_matchCompar($m[1], false);
                $count = $count[1];
                $qword = $m[2];
            } else if (preg_match('/\A(pri|primary|sec|secondary|ext|external)' . $tailre, $qword, $m)) {
                $rt = ReviewSearchMatcher::parse_review_type($m[1]);
                $qword = $m[2];
            } else if (preg_match('/\A(complete|done|incomplete|inprogress)' . $tailre, $qword, $m)) {
                if ($m[1] === "complete" || $m[1] === "done")
                    $completeness |= ReviewSearchMatcher::COMPLETE;
                else if ($m[1] === "incomplete")
                    $completeness |= ReviewSearchMatcher::INCOMPLETE;
                else
                    $completeness |= ReviewSearchMatcher::INPROGRESS;
                $qword = $m[2];
            } else if (preg_match('/\Awords((?:[=!<>]=?|≠|≤|≥)\d+)(?:\z|:)(.*)\z/', $qword, $m)) {
                $wordcount = CountMatcher::canonicalize($m[1]);
                $qword = $m[2];
            } else if (preg_match('/\A([A-Za-z0-9]+)' . $tailre, $qword, $m)
                       && (($round = $Conf->round_number($m[1], false))
                           || $m[1] === "unnamed")) {
                if ($rounds === null)
                    $rounds = array();
                $rounds[] = $round;
                $qword = $m[2];
            } else if (preg_match('/\A(..*?|"[^"]+(?:"|\z))' . $tailre, $qword, $m)) {
                if (($quoted = $m[1][0] === "\""))
                    $m[1] = str_replace(array('"', '*'), array('', '\*'), $m[1]);
                $contacts = $m[1];
                $qword = $m[2];
            } else {
                $count = "<0";
                break;
            }
        }

        if (($qr = self::_tautology($count))) {
            $qr->set_float("used_revadj", true);
            $qt[] = $qr;
            return;
        }
        if ($completeness == 0 && $wordcount)
            $completeness = ReviewSearchMatcher::COMPLETE;

        if ($contacts)
            $contacts = $this->_reviewerMatcher($contacts, $quoted, $rt >= REVIEW_PC);
        $value = new ReviewSearchMatcher($count, $contacts);
        $value->review_type = $rt;
        $value->completeness = $completeness;
        $value->round = $rounds;
        $value->wordcountexpr = $wordcount;
        $qt[] = new SearchTerm("re", self::F_XVIEW, $value);
    }

    static public function matching_decisions($word, $quoted = null) {
        global $Conf;
        if ($quoted === null && ($quoted = ($word && $word[0] === '"')))
            $word = str_replace('"', '', $word);
        $lword = strtolower($word);
        if (!$quoted) {
            if ($lword === "yes")
                return ">0";
            else if ($lword === "no")
                return "<0";
            else if ($lword === "?" || $lword === "none" || $lword === "unknown")
                return array(0);
            else if ($lword === "any")
                return "!=0";
        }
        $flags = $quoted ? Text::SEARCH_ONLY_EXACT : Text::SEARCH_UNPRIVILEGE_EXACT;
        return array_keys(Text::simple_search($word, $Conf->decision_map(), $flags));
    }

    static public function status_field_matcher($word, $quoted = null) {
        if (strcasecmp($word, "withdrawn") == 0 || strcasecmp($word, "withdraw") == 0 || strcasecmp($word, "with") == 0)
            return ["timeWithdrawn", ">0"];
        else if (strcasecmp($word, "submitted") == 0 || strcasecmp($word, "submit") == 0 || strcasecmp($word, "sub") == 0)
            return ["timeSubmitted", ">0"];
        else if (strcasecmp($word, "unsubmitted") == 0 || strcasecmp($word, "unsubmit") == 0 || strcasecmp($word, "unsub") == 0)
            return ["timeSubmitted", "<=0", "timeWithdrawn", "<=0"];
        else if (strcasecmp($word, "active") == 0)
            return ["timeWithdrawn", "<=0"];
        else
            return ["outcome", self::matching_decisions($word, $quoted)];
    }

    private function _search_status($word, &$qt, $quoted, $allow_status) {
        global $Conf;
        if ($allow_status)
            $fval = self::status_field_matcher($word, $quoted);
        else
            $fval = ["outcome", self::matching_decisions($word, $quoted)];
        if (is_array($fval[1]) && count($fval[1]) == 0) {
            $this->warn("“" . htmlspecialchars($word) . "” doesn’t match a " . ($allow_status ? "decision or status." : "decision."));
            $fval[1][] = -10000000;
        }

        $flags = 0;
        if ($fval[0] === "outcome" && !($this->amPC && $Conf->timePCViewDecision(true)))
            $flags = self::F_XVIEW;
        $qt[] = new SearchTerm("pf", $flags, $fval);
    }

    private function _search_conflict($word, &$qt, $quoted, $pc_only) {
        $m = self::_matchCompar($word, $quoted);
        if (($qr = self::_tautology($m[1]))) {
            $qt[] = $qr;
            return;
        }

        $contacts = $this->_reviewerMatcher($m[0], $quoted, $pc_only);
        $value = new ContactCountMatcher($m[1], $contacts);
        if ($this->privChair
            || (is_array($contacts) && count($contacts) == 1 && $contacts[0] == $this->cid))
            $qt[] = new SearchTerm("conflict", 0, $value);
        else {
            $qt[] = new SearchTerm("conflict", self::F_XVIEW, $value);
            if ($value->test_contact($this->cid))
                $qt[] = new SearchTerm("conflict", 0, new ContactCountMatcher($m[1], $this->cid));
        }
    }

    private function _searchReviewerConflict($word, &$qt, $quoted) {
        $args = array();
        while (preg_match('/\A\s*#?(\d+)(?:-#?(\d+))?\s*,?\s*(.*)\z/s', $word, $m)) {
            $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
            foreach (range($m[1], $m[2]) as $p)
                $args[$p] = true;
            $word = $m[3];
        }
        if ($word !== "" || count($args) == 0) {
            $this->warn("The <code>reconflict</code> keyword expects a list of paper numbers.");
            $qt[] = new SearchTerm("f");
        } else {
            $result = Dbl::qe("select distinct contactId from PaperReview where paperId in (" . join(", ", array_keys($args)) . ")");
            $contacts = Dbl::fetch_first_columns($result);
            $qt[] = new SearchTerm("conflict", 0, new ContactCountMatcher(">0", $contacts));
        }
    }

    private function _search_comment_tag($rt, $tag, $rvalue, $round, &$qt) {
        $xtag = $tag;
        if ($xtag === "none")
            $xtag = "any";
        $term = new SearchTerm("cmttag", $rt, new CommentTagMatcher($rvalue, $xtag));
        if ($round !== null)
            $term->commentRound = $round;
        if ($tag === "none")
            $term = SearchTerm::make_not($term);
        $qt[] = $term;
    }

    private function _search_comment($word, $ctype, &$qt, $quoted) {
        global $Conf;
        $m = self::_matchCompar($word, $quoted);
        if (($qr = self::_tautology($m[1]))) {
            $qt[] = $qr;
            return;
        }

        // canonicalize comment type
        $ctype = strtolower($ctype);
        if (str_ends_with($ctype, "resp"))
            $ctype .= "onse";
        if (str_ends_with($ctype, "-draft"))
            $ctype = "draft" . substr($ctype, 0, strlen($ctype) - 6);
        else if (str_ends_with($ctype, "draft"))
            $ctype = "draft" . substr($ctype, 0, strlen($ctype) - 5);
        if (str_starts_with($ctype, "draft-"))
            $ctype = "draft" . substr($ctype, 6);

        $rt = 0;
        $round = null;
        if (str_starts_with($ctype, "draft") && str_ends_with($ctype, "response")) {
            $rt |= self::F_REQUIREDRAFT | self::F_ALLOWDRAFT;
            $ctype = substr($ctype, 5);
        }
        if ($ctype === "response" || $ctype === "anycmt")
            $rt |= self::F_ALLOWRESPONSE;
        else if (str_ends_with($ctype, "response")) {
            $rname = substr($ctype, 0, strlen($ctype) - 8);
            $round = $Conf->resp_round_number($rname);
            if ($round === false) {
                $this->warn("No such response round “" . htmlspecialchars($ctype) . "”.");
                $qt[] = new SearchTerm("f");
                return;
            }
            $rt |= self::F_ALLOWRESPONSE;
        }
        if ($ctype === "cmt" || $ctype === "aucmt" || $ctype === "anycmt")
            $rt |= self::F_ALLOWCOMMENT;
        if ($ctype === "aucmt")
            $rt |= self::F_AUTHORCOMMENT;
        if (substr($m[0], 0, 1) === "#") {
            $rt |= ($this->privChair ? 0 : self::F_NONCONFLICT) | self::F_XVIEW;
            $tags = $this->_expand_tag(substr($m[0], 1), false);
            foreach ($tags as $tag)
                $this->_search_comment_tag($rt, $tag, $m[1], $round, $qt);
            if (!count($tags)) {
                $qt[] = new SearchTerm("f");
                return;
            } else if (count($tags) !== 1 || $tags[0] === "none" || $tags[0] === "any"
                       || !pcTags($tags[0]))
                return;
        }
        $contacts = ($m[0] === "" ? null : $this->_reviewerMatcher($m[0], $quoted, false));
        $value = new ContactCountMatcher($m[1], $contacts);
        $term = new SearchTerm("cmt", $rt | self::F_XVIEW, $value);
        if ($round !== null)
            $term->commentRound = $round;
        $qt[] = $term;
    }

    private function _search_review_field($word, $f, &$qt, $quoted, $noswitch = false) {
        global $Opt;
        $countexpr = ">0";
        $contacts = null;
        $contactword = "";
        $field = $f->id;

        if (preg_match('/\A(.+?[^:=<>!])([:=<>!]|≠|≤|≥)(.*)\z/s', $word, $m)
            && !ctype_digit($m[1])) {
            $contacts = $this->_reviewerMatcher($m[1], $quoted, false);
            $word = ($m[2] === ":" ? $m[3] : $m[2] . $m[3]);
            $contactword = $m[1] . ":";
        }

        if ($f->has_options) {
            if ($word === "any")
                $value = "$field>0";
            else if ($word === "none")
                $value = "$field=0";
            else if (preg_match('/\A(\d*?)([=!<>]=?|≠|≤|≥)?\s*([A-Za-z]|\d+)\z/s', $word, $m)) {
                if ($m[1] === "")
                    $m[1] = 1;
                $m[2] = CountMatcher::canonical_comparator($m[2]);
                if ($f->option_letter != (ctype_digit($m[3]) == false))
                    $value = "$field=-1"; // XXX
                else {
                    $score = $m[3];
                    if ($f->option_letter) {
                        if (!@$Opt["smartScoreCompare"] || $noswitch) {
                            // switch meaning of inequality
                            if ($m[2][0] === "<")
                                $m[2] = ">" . substr($m[2], 1);
                            else if ($m[2][0] === ">")
                                $m[2] = "<" . substr($m[2], 1);
                        }
                        $score = strtoupper($score);
                        $m[3] = $f->option_letter - ord($score);
                    }
                    if (($m[3] < 1 && ($m[2][0] === "<" || $m[2] === "="))
                        || ($m[3] == 1 && $m[2] === "<")
                        || ($m[3] == count($f->options) && $m[2] === ">")
                        || ($m[3] > count($f->options) && ($m[2][0] === ">" || $m[2] === "="))) {
                        if ($f->option_letter)
                            $warnings = array("<" => "worse than", ">" => "better than");
                        else
                            $warnings = array("<" => "less than", ">" => "greater than");
                        $t = new SearchTerm("f");
                        $t->set("contradiction_warning", "No $f->name_html scores are " . ($m[2] === "=" ? "" : $warnings[$m[2][0]] . (strlen($m[2]) == 1 ? " " : " or equal to ")) . $score . ".");
                        $t->set_float("used_revadj", true);
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
                if ($m[2] === "-" || $m[2] === "") {
                    $this->_search_review_field($contactword . $m[1], $f, $qo, $quoted);
                    $this->_search_review_field($contactword . $m[3], $f, $qo, $quoted);
                } else
                    $this->_search_review_field($contactword . ">=" . $m[1], $f, $qo, $quoted, true);
                if ($this->_search_review_field($contactword . "<" . $m[1], $f, $qo, $quoted, true))
                    $qo[count($qo) - 1] = SearchTerm::make_not($qo[count($qo) - 1]);
                else
                    array_pop($qo);
                if ($this->_search_review_field($contactword . ">" . $m[3], $f, $qo, $quoted, true))
                    $qo[count($qo) - 1] = SearchTerm::make_not($qo[count($qo) - 1]);
                else
                    array_pop($qo);
                $qt[] = SearchTerm::make_op("and", $qo);
                return true;
            } else              // XXX
                $value = "$field=-1";
        } else {
            if ($word === "any")
                $value = "$field!=''";
            else if ($word === "none")
                $value = "$field=''";
            else
                $value = "$field like " . Dbl::utf8ci("'%" . sqlq_for_like($word) . "%'");
        }

        $value = new ReviewSearchMatcher($countexpr, $contacts, $value, $f->view_score);
        $value->completeness = ReviewSearchMatcher::COMPLETE;
        $qt[] = new SearchTerm("re", self::F_XVIEW, $value);
        return true;
    }

    private function _search_revpref($word, &$qt, $quoted) {
        $contacts = null;
        if (preg_match('/\A(.*?[^:=<>!])([:=!<>]=?|≠|≤|≥)(.*)\z/s', $word, $m)
            && !ctype_digit($m[1])) {
            $contacts = $this->_reviewerMatcher($m[1], $quoted, true);
            $word = ($m[2] === ":" ? $m[3] : $m[2] . $m[3]);
        }

        if (!preg_match(',\A(\d*)\s*([=!<>]=?|≠|≤|≥|)\s*(-?\d*)\s*([xyz]?)\z,i', $word, $m)
            || ($m[1] === "" && $m[3] === "" && $m[4] === "")) {
            $qt[] = new SearchTerm("f");
            return;
        }

        if ($m[1] !== "" && $m[2] === "")
            $m = array($m[0], "1", "=", $m[1], "");
        if ($m[1] === "")
            $m[1] = "1";
        if ($m[2] === "")
            $m[2] = "=";

        // PC members can only search their own preferences.
        // Admins can search papers they administer.
        $value = new RevprefSearchMatcher((int) $m[1] ? ">=" . $m[1] : "=0", $contacts);
        if ($m[3] !== "")
            $value->preference_match = new CountMatcher($m[2] . $m[3]);
        if ($m[4] !== "")
            $value->expertise_match = new CountMatcher($m[2] . (121 - ord(strtolower($m[4]))));
        if ($this->privChair)
            $qt[] = new SearchTerm("revpref", 0, $value);
        else {
            if ($this->contact->is_manager())
                $qt[] = new SearchTerm("revpref", self::F_MANAGER, $value);
            if ($value->test_contact($this->cid)) {
                $xvalue = clone $value;
                $xvalue->set_contacts($this->cid);
                $qt[] = new SearchTerm("revpref", 0, $xvalue);
            }
        }
    }

    private function _search_formula($word, &$qt, $quoted) {
        if (preg_match('/\A[^(){}\[\]]+\z/', $word) && !$quoted
            && ($result = Dbl::qe("select * from Formula where name=?", $word))
            && ($row = $result->fetch_object())) {
            $formula = new Formula($row);
            Dbl::free($result);
        } else
            $formula = new Formula($word);
        if ($formula->check())
            $qt[] = new SearchTerm("formula", self::F_XVIEW, $formula);
        else {
            $this->warn($formula->error_html());
            $qt[] = new SearchTerm("f");
        }
    }

    private function _expand_tag($tagword, $allow_star) {
        // see also TagAssigner
        $ret = array("");
        $twiddle = strpos($tagword, "~");
        if ($this->privChair && $twiddle > 0 && !ctype_digit(substr($tagword, 0, $twiddle))) {
            $c = substr($tagword, 0, $twiddle);
            $ret = ContactSearch::make_pc($c, $this->cid)->ids;
            if (count($ret) == 0)
                $this->warn("“" . htmlspecialchars($c) . "” doesn’t match a PC email.");
            $tagword = substr($tagword, $twiddle);
        } else if ($twiddle === 0 && @$tagword[1] !== "~")
            $ret[0] = $this->cid;

        $tagger = new Tagger($this->contact);
        if (!$tagger->check("#" . $tagword, Tagger::ALLOWRESERVED | Tagger::NOVALUE | ($allow_star ? Tagger::ALLOWSTAR : 0))) {
            $this->warn($tagger->error_html);
            $ret = array();
        }
        foreach ($ret as &$x)
            $x .= $tagword;
        return $ret;
    }

    private function _search_one_tag($value, $old_arg) {
        if (($starpos = strpos($value, "*")) !== false) {
            $arg = "(\3 like '" . str_replace("*", "%", sqlq_for_like($value)) . "'";
            if ($starpos == 0)
                $arg .= " and \3 not like '%~%'";
            $arg .= ")";
        } else if ($value === "any" || $value === "none")
            $arg = "(\3 is not null and (\3 not like '%~%' or \3 like '{$this->cid}~%'" . ($this->privChair ? " or \3 like '~~%'" : "") . "))";
        else
            $arg = "\3='" . sqlq($value) . "'";
        return $old_arg ? "$old_arg or $arg" : $arg;
    }

    private function _search_tags($word, $keyword, &$qt) {
        global $Conf;
        if ($word[0] === "#")
            $word = substr($word, 1);

        // allow external reviewers to search their own rank tag
        if (!$this->amPC) {
            $ranktag = "~" . $Conf->setting_data("tag_rank");
            if (!$Conf->setting("tag_rank")
                || substr($word, 0, strlen($ranktag)) !== $ranktag
                || (strlen($word) > strlen($ranktag)
                    && $word[strlen($ranktag)] !== "#"))
                return;
        }

        if (preg_match('/\A([^#=!<>\x80-\xFF]+)(?:#|=)(-?\d+)(?:\.\.\.?|-)(-?\d+)\z/', $word, $m)) {
            $tagword = $m[1];
            $compar = array(null, ">=" . $m[2], "<=" . $m[3]);
        } else if (preg_match('/\A([^#=!<>\x80-\xFF]+)(#?)([=!<>]=?|≠|≤|≥|)(-?\d+)\z/', $word, $m)
            && $m[1] !== "any" && $m[1] !== "none"
            && ($m[2] !== "" || $m[3] !== "")) {
            $tagword = $m[1];
            $compar = array(null, CountMatcher::canonical_comparator($m[3]) . $m[4]);
        } else {
            $tagword = $word;
            $compar = array(null);
        }

        $negated = false;
        if (substr($tagword, 0, 1) === "-" && $keyword === "tag") {
            $negated = true;
            $tagword = ltrim(substr($tagword, 1));
        }

        $tags = $this->_expand_tag($tagword, $keyword === "tag");
        if (!count($tags))
            return new SearchTerm("f");

        foreach ($tags as $tag)
            $compar[0] = $this->_search_one_tag($tag, $compar[0]);
        $term = new SearchTerm("tag", self::F_XVIEW, $compar);
        if ($tags[0] === "none")
            $term = SearchTerm::make_not($term);
        else if ($keyword === "order" || $keyword === "rorder" || !$keyword)
            $term->set_float("sort", array(($keyword === "rorder" ? "-" : "") . "#" . $tags[0]));
        $qt[] = $term;
    }

    private function _search_color($word, &$qt) {
        global $Conf;
        if (!$this->amPC)
            return;
        $word = strtolower($word);
        if (!preg_match(',\A(any|none|' . TagInfo::BASIC_COLORS_PLUS . ')\z,', $word))
            return new SearchTerm("f");
        $any = $word === "any" || $word === "none";
        $qx = array();
        foreach (TagInfo::color_tags($any ? null : $word) as $tag) {
            array_push($qx, sqlq($tag), sqlq("{$this->cid}~$tag"));
            if ($this->privChair)
                $qx[] = sqlq("~~$tag");
        }
        if (count($qx))
            $qe = new SearchTerm("tag", self::F_XVIEW,
                                 array("\3 in ('" . join("','", $qx) . "')"));
        else
            $qe = new SearchTerm("f");
        if ($word === "none")
            $qe = SearchTerm::make_not($qe);
        $qt[] = $qe;
    }

    static public function analyze_option_search($word) {
        if (preg_match('/\A(.*?)([:#](?:[=!<>]=?|≠|≤|≥|)|[=!<>]=?|≠|≤|≥)(.*)\z/', $word, $m)) {
            $oname = $m[1];
            if ($m[2][0] === ":" || $m[2][0] === "#")
                $m[2] = substr($m[2], 1);
            $ocompar = CountMatcher::canonical_comparator($m[2]);
            $oval = strtolower(simplify_whitespace($m[3]));
        } else {
            $oname = $word;
            $ocompar = "=";
            $oval = "";
        }
        $oname = strtolower(simplify_whitespace($oname));

        // match all options
        $qo = $warn = array();
        $option_failure = false;
        if ($oname === "none" || $oname === "any")
            $omatches = PaperOption::option_list();
        else
            $omatches = PaperOption::search($oname);
        // Conf::msg_info(Ht::pre_text(var_export($omatches, true)));
        if (count($omatches)) {
            foreach ($omatches as $oid => $o) {
                // selectors handle “yes”, “”, and “no” specially
                if ($o->has_selector()) {
                    $xval = array();
                    if ($oval === "") {
                        foreach ($o->selector as $k => $v)
                            if (strcasecmp($v, "yes") == 0)
                                $xval[$k] = $v;
                        if (count($xval) == 0)
                            $xval = $o->selector;
                    } else
                        $xval = Text::simple_search($oval, $o->selector);
                    if (count($xval) == 0)
                        $warn[] = "“" . htmlspecialchars($oval) . "” doesn’t match any " . htmlspecialchars($oname) . " values.";
                    else if (count($xval) == 1) {
                        reset($xval);
                        $qo[] = array($o, $ocompar, key($xval), $oval);
                    } else if ($ocompar !== "=" && $ocompar !== "!=")
                        $warn[] = "Submission option “" . htmlspecialchars("$oname:$oval") . "” matches multiple values, can’t use " . htmlspecialchars($ocompar) . ".";
                    else
                        $qo[] = array($o, $ocompar === "=" ? "in" : "not in", array_keys($xval), $oval);
                    continue;
                }

                if ($oval === "" || $oval === "yes" || $oval === "'yes'")
                    $qo[] = array($o, "!=", 0, $oval);
                else if ($oval === "no" || $oval === "'no'")
                    $qo[] = array($o, "=", 0);
                else if ($o->type == "text") {
                    if (!empty($oval)) {
                        $xval = " like '%' and Option_X.data like " . str_replace("+", " ", $oval);
                    }
                } else if ($o->type === "numeric") {
                    if (preg_match('/\A\s*([-+]?\d+)\s*\z/', $oval, $m))
                        $qo[] = array($o, $ocompar, $m[1]);
                    else
                        $warn[] = "Submission option “" . htmlspecialchars($o->name) . "” takes integer values.";
                } else if ($o->type === "attachments") {
                    if ($oval === "any")
                        $qo[] = array($o, "!=", 0, $oval);
                    else if (preg_match('/\A\s*([-+]?\d+)\s*\z/', $oval, $m)) {
                        if (CountMatcher::compare(0, $ocompar, $m[1]))
                            $qo[] = array($o, "=", 0);
                        $qo[] = array($o, ">0", "special", "attachment-count", $ocompar, $m[1]);
                    } else
                        $qo[] = array($o, ">0", "special", "attachment-name", $oval);
                } else
                    continue;
            }
        } else if (($ocompar === "=" || $ocompar === "!=") && $oval === "")
            foreach (PaperOption::option_list() as $oid => $o)
                if ($o->has_selector()) {
                    foreach (Text::simple_search($oname, $o->selector) as $xval => $text)
                        $qo[] = array($o, $ocompar, $xval);
                }

        return (object) array("os" => $qo, "warn" => $warn, "negate" => $oname === "none");
    }

    function _search_options($word, &$qt, $report_error) {
        $os = self::analyze_option_search($word);
        foreach ($os->warn as $w)
            $this->warn($w);
        if (!count($os->os)) {
            if ($report_error && !count($os->warn))
                $this->warn("“" . htmlspecialchars($word) . "” doesn’t match a submission option.");
            if ($report_error || count($os->warn))
                $qt[] = new SearchTerm("f");
            return false;
        }

        // add expressions
        $qz = array();
        foreach ($os->os as $oq)
            if ($oq[2] === "special") {
                $qz[] = new SearchTerm("option", self::F_XVIEW, $oq);
            } else {
                $cmp = ctype_alpha($oq[1][0]) ? " $oq[1] " : $oq[1];
                $value = is_array($oq[2]) ? "(" . join(",", $oq[2]) . ")" : $oq[2];
                $qz[] = new SearchTerm("option", self::F_XVIEW, array($oq[0], $cmp . $value));
            }
        if ($os->negate)
            $qz = array(SearchTerm::make_not(SearchTerm::make_op("or", $qz)));
        $qt = array_merge($qt, $qz);
        return true;
    }

    private function _search_has($word, &$qt, $quoted) {
        global $Conf;
        $lword = strtolower($word);
        $lword = @self::$_keywords[$lword] ? : $lword;
        if ($lword === "paper" || $lword === "sub" || $lword === "submission")
            $qt[] = new SearchTerm("pf", 0, array("paperStorageId", "!=0"));
        else if ($lword === "final" || $lword === "finalcopy")
            $qt[] = new SearchTerm("pf", 0, array("finalPaperStorageId", "!=0"));
        else if ($lword === "ab")
            $qt[] = new SearchTerm("pf", 0, array("abstract", "!=''"));
        else if (preg_match('/\A(?:(?:draft-?)?\w*resp(?:onse)?|\w*resp(?:onse)(?:-?draft)?|cmt|aucmt|anycmt)\z/', $lword))
            $this->_search_comment(">0", $lword, $qt, $quoted);
        else if ($lword === "manager" || $lword === "admin" || $lword === "administrator")
            $qt[] = new SearchTerm("pf", 0, array("managerContactId", "!=0"));
        else if (preg_match('/\A[cip]?(?:re|pri|sec|ext)\z/', $lword))
            $this->_search_reviewer(">0", $lword, $qt);
        else if ($lword === "lead")
            $qt[] = new SearchTerm("pf", self::F_XVIEW, array("leadContactId", "!=0"));
        else if ($lword === "shep" || $lword === "shepherd")
            $qt[] = new SearchTerm("pf", self::F_XVIEW, array("shepherdContactId", "!=0"));
        else if ($lword === "dec" || $lword === "decision")
            $this->_search_status("yes", $qt, false, false);
        else if (preg_match('/\A[\w-]+\z/', $lword) && $this->_search_options("$lword:yes", $qt, false))
            /* OK */;
        else {
            $x = array("“paper”", "“final”", "“abstract”", "“comment”", "“aucomment”", "“re”", "“extre”");
            foreach ($Conf->resp_round_list() as $i => $rname) {
                if (!in_array("“response”", $x))
                    array_push($x, "“response”", "“draftresponse”");
                if ($i)
                    $x[] = "“{$rname}response”";
            }
            foreach (PaperOption::option_list() as $o)
                array_push($x, "“" . htmlspecialchars($o->abbr) . "”");
            $this->warn("Unknown “has:” search. I understand " . commajoin($x) . ".");
            $qt[] = new SearchTerm("f");
        }
    }

    private function _searchReviewRatings($word, &$qt) {
        global $Conf;
        $this->reviewAdjust = true;
        if (preg_match('/\A(.+?)\s*(|[=!<>]=?|≠|≤|≥)\s*(\d*)\z/', $word, $m)
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
                $m[2] = CountMatcher::canonical_comparator($m[2]);
            $nqt = count($qt);

            // resolve rating type
            if ($m[1] === "+" || $m[1] === "good") {
                $this->interestingRatings["good"] = ">0";
                $term = "nrate_good";
            } else if ($m[1] === "-" || $m[1] === "bad"
                       || $m[1] === "\xE2\x88\x92" /* unicode MINUS */) {
                $this->interestingRatings["bad"] = "<1";
                $term = "nrate_bad";
            } else if ($m[1] === "any") {
                $this->interestingRatings["any"] = "!=100";
                $term = "nrate_any";
            } else {
                $x = Text::simple_search($m[1], ReviewForm::$rating_types);
                unset($x["n"]); /* don't allow "average" */
                if (count($x) == 0) {
                    $this->warn("Unknown rating type “" . htmlspecialchars($m[1]) . "”.");
                    $qt[] = new SearchTerm("f");
                } else {
                    $type = count($this->interestingRatings);
                    $this->interestingRatings[$type] = " in (" . join(",", array_keys($x)) . ")";
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
        $pcount = $quote = 0;
        for ($pos = 0; $pos < strlen($str)
                 && (!ctype_space($str[$pos]) || $pcount || $quote); ++$pos) {
            $ch = $str[$pos];
            if ($quote) {
                if ($ch === "\\" && $pos + 1 < strlen($str))
                    ++$pos;
                else if ($ch === "\"")
                    $quote = 0;
            } else if ($ch === "\"")
                $quote = 1;
            else if ($ch === "(" || $ch === "[" || $ch === "{")
                ++$pcount;
            else if ($ch === ")" || $ch === "]" || $ch === "}") {
                if (!$pcount)
                    break;
                --$pcount;
            }
        }
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
        $sort = ListSorter::make_empty($text === "");
        if (($ch1 = substr($text, 0, 1)) === "-" || $ch1 === "+") {
            $sort->reverse = $ch1 === "-";
            $text = ltrim(substr($text, 1));
        }

        // separate text into words
        $words = array();
        $bypos = false;
        while ($text !== "") {
            preg_match(',\A([^\s\(]*)(.*)\z,s', $text, $m);
            if (substr($m[2], 0, 1) === "(") {
                $pos = self::find_end_balanced_parens($m[2]);
                $m[1] .= substr($m[2], 0, $pos);
                $m[2] = substr($m[2], $pos);
            }
            $words[] = $m[1];
            $text = ltrim($m[2]);
            if ($m[1] === "by" && $bypos === false)
                $bypos = count($words) - 1;
        }

        // go over words
        $next_words = array();
        for ($i = 0; $i != count($words); ++$i) {
            $w = $words[$i];
            if (($bypos === false || $i > $bypos)
                && isset(self::$_sort_keywords[$w])) {
                $x = self::$_sort_keywords[$w];
                if ($x === "up")
                    $sort->reverse = false;
                else if ($x === "down")
                    $sort->reverse = true;
                else if (ctype_upper($x))
                    $sort->score = $x;
            } else if ($bypos === false || $i < $bypos)
                $next_words[] = $w;
        }

        if (count($next_words))
            $sort->type = join(" ", $next_words);
        return $sort;
    }

    private static function _expand_saved_search($word, $recursion) {
        global $Conf;
        if (isset($recursion[$word]))
            return false;
        $t = $Conf->setting_data("ss:" . $word, "");
        $search = json_decode($t);
        if ($search && is_object($search) && isset($search->q))
            return $search->q;
        else
            return null;
    }

    function _searchQueryWord($word, $report_error) {
        global $Conf;

        // check for paper number or "#TAG"
        if (preg_match('/\A#?(\d+)(?:-#?(\d+))?\z/', $word, $m)) {
            $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
            return new SearchTerm("pn", 0, array(range($m[1], $m[2]), array()));
        } else if (substr($word, 0, 1) === "#") {
            $qe = $this->_searchQueryWord("tag:" . $word, false);
            if (!$qe->is_false())
                return $qe;
        }

        // Allow searches like "ovemer>2"; parse as "ovemer:>2".
        if (preg_match('/\A([-_A-Za-z0-9]+)((?:[=!<>]=?|≠|≤|≥)[^:]+)\z/', $word, $m)) {
            $qe = $this->_searchQueryWord($m[1] . ":" . $m[2], false);
            if (!$qe->is_false())
                return $qe;
        }

        $keyword = null;
        if (($colon = strpos($word, ":")) > 0) {
            $x = substr($word, 0, $colon);
            if (strpos($x, '"') === false) {
                $keyword = @self::$_keywords[$x] ? : $x;
                $word = substr($word, $colon + 1);
                if ($word === false)
                    $word = "";
            }
        }

        // Treat unquoted "*", "ANY", and "ALL" as special; return true.
        if ($word === "*" || $word === "ANY" || $word === "ALL" || $word === "")
            return new SearchTerm("t");
        else if ($word === "NONE")
            return new SearchTerm("f");

        $qword = $word;
        $quoted = ($word[0] === '"');
        $negated = false;
        if ($quoted)
            $word = str_replace(array('"', '*'), array('', '\*'), $word);
        if ($keyword === "notag") {
            $keyword = "tag";
            $negated = true;
        }

        $qt = array();
        if ($keyword ? $keyword === "ti" : isset($this->fields["ti"]))
            $this->_searchField($word, "ti", $qt);
        if ($keyword ? $keyword === "ab" : isset($this->fields["ab"]))
            $this->_searchField($word, "ab", $qt);
        if ($keyword ? $keyword === "au" : isset($this->fields["au"]))
            $this->_searchAuthors($word, $qt, $keyword, $quoted);
        if ($keyword ? $keyword === "co" : isset($this->fields["co"]))
            $this->_searchField($word, "co", $qt);
        if ($keyword ? $keyword === "re" : isset($this->fields["re"]))
            $this->_search_reviewer($qword, "re", $qt);
        else if ($keyword && @self::$_canonical_review_keywords[$keyword])
            $this->_search_reviewer($qword, $keyword, $qt);
        if (preg_match('/\A(?:(?:draft-?)?\w*resp(?:onse)|\w*resp(?:onse)?(-?draft)?|cmt|aucmt|anycmt)\z/', $keyword))
            $this->_search_comment($word, $keyword, $qt, $quoted);
        if ($keyword === "revpref" && $this->amPC)
            $this->_search_revpref($word, $qt, $quoted);
        foreach (array("lead", "shepherd", "manager") as $ctype)
            if ($keyword === $ctype) {
                $x = $this->_one_pc_matcher($word, $quoted);
                $qt[] = new SearchTerm("pf", self::F_XVIEW, array("${ctype}ContactId", $x));
                if ($ctype === "manager" && $word === "me" && !$quoted && $this->privChair)
                    $qt[] = new SearchTerm("pf", self::F_XVIEW, array("${ctype}ContactId", "=0"));
            }
        if (($keyword ? $keyword === "tag" : isset($this->fields["tag"]))
            || $keyword === "order" || $keyword === "rorder")
            $this->_search_tags($word, $keyword, $qt);
        if ($keyword === "color")
            $this->_search_color($word, $qt);
        if ($keyword === "topic") {
            $type = "topic";
            $value = null;
            if ($word === "none" || $word === "any")
                $value = $word;
            else {
                $x = strtolower(simplify_whitespace($word));
                $tids = array();
                foreach ($Conf->topic_map() as $tid => $tname)
                    if (strstr(strtolower($tname), $x) !== false)
                        $tids[] = $tid;
                if (count($tids) == 0 && $word !== "none" && $word !== "any") {
                    $this->warn("“" . htmlspecialchars($x) . "” does not match any defined paper topic.");
                    $type = "f";
                } else
                    $value = $tids;
            }
            $qt[] = new SearchTerm($type, self::F_XVIEW, $value);
        }
        if ($keyword === "option")
            $this->_search_options($word, $qt, true);
        if ($keyword === "status" || $keyword === "is")
            $this->_search_status($word, $qt, $quoted, true);
        if ($keyword === "decision")
            $this->_search_status($word, $qt, $quoted, false);
        if ($keyword === "conflict" && $this->amPC)
            $this->_search_conflict($word, $qt, $quoted, false);
        if ($keyword === "pcconflict" && $this->amPC)
            $this->_search_conflict($word, $qt, $quoted, true);
        if ($keyword === "reconflict" && $this->privChair)
            $this->_searchReviewerConflict($word, $qt, $quoted);
        if ($keyword === "round" && $this->amPC) {
            $this->reviewAdjust = true;
            if ($word === "none")
                $qt[] = new SearchTerm("revadj", 0, array("round" => array(0)));
            else if ($word === "any")
                $qt[] = new SearchTerm("revadj", 0, array("round" => range(1, count($Conf->round_list()) - 1)));
            else {
                $x = simplify_whitespace($word);
                $rounds = Text::simple_search($x, $Conf->round_list());
                if (count($rounds) == 0) {
                    $this->warn("“" . htmlspecialchars($x) . "” doesn’t match a review round.");
                    $qt[] = new SearchTerm("f");
                } else
                    $qt[] = new SearchTerm("revadj", 0, array("round" => array_keys($rounds)));
            }
        }
        if ($keyword === "rate")
            $this->_searchReviewRatings($word, $qt);
        if ($keyword === "has")
            $this->_search_has($word, $qt, $quoted);
        if ($keyword === "formula")
            $this->_search_formula($word, $qt, $quoted);
        if ($keyword === "ss" && $this->amPC) {
            if (($nextq = self::_expand_saved_search($word, $this->_ssRecursion))) {
                $this->_ssRecursion[$word] = true;
                $qe = $this->_searchQueryType($nextq);
                unset($this->_ssRecursion[$word]);
            } else
                $qe = null;
            if (!$qe && $nextq === false)
                $this->warn("Saved search “" . htmlspecialchars($word) . "” is incorrectly defined in terms of itself.");
            else if (!$qe && !$Conf->setting_data("ss:$word"))
                $this->warn("There is no “" . htmlspecialchars($word) . "” saved search.");
            else if (!$qe)
                $this->warn("The “" . htmlspecialchars($word) . "” saved search is defined incorrectly.");
            $qt[] = ($qe ? : new SearchTerm("f"));
        }
        if ($keyword === "HEADING") {
            if (($heading = simplify_whitespace($word)) !== "")
                $this->headingmap = array();
            $qt[] = SearchTerm::make_float(array("heading" => $heading));
        }
        if ($keyword === "show" || $keyword === "hide" || $keyword === "edit"
            || $keyword === "sort" || $keyword === "showsort"
            || $keyword === "editsort") {
            $editing = strpos($keyword, "edit") !== false;
            $sorting = strpos($keyword, "sort") !== false;
            $views = array();
            $a = ($keyword === "hide" ? false : ($editing ? "edit" : true));
            $word = simplify_whitespace($word);
            $ch1 = substr($word, 0, 1);
            if ($ch1 === "-" && !$sorting)
                list($a, $word) = array(false, substr($word, 1));
            $wtype = $word;
            if ($sorting) {
                $sort = self::parse_sorter($wtype);
                $wtype = $sort->type;
            }
            if ($wtype !== "" && $keyword !== "sort")
                $views[$wtype] = $a;
            $f = array("view" => $views);
            if ($sorting)
                $f["sort"] = array($word);
            $qt[] = SearchTerm::make_float($f);
        }

        // Finally, look for a review field.
        if ($keyword && !isset(self::$_keywords[$keyword]) && count($qt) == 0) {
            if (($field = ReviewForm::field_search($keyword)))
                $this->_search_review_field($word, $field, $qt, $quoted);
            else if (!$this->_search_options("$keyword:$word", $qt, false)
                     && $report_error)
                $this->warn("Unrecognized keyword “" . htmlspecialchars($keyword) . "”.");
        }

        $qe = SearchTerm::make_op("or", $qt);
        return $negated ? SearchTerm::make_not($qe) : $qe;
    }

    static public function pop_word(&$str) {
        $wordre = '/\A\s*(?:"[^"]*"?|[a-zA-Z][a-zA-Z0-9]*:(?:"[^"]*(?:"|\z)|[^"\s()]*)+|[^"\s()]+)/s';

        if (!preg_match($wordre, $str, $m))
            return ($str = "");
        $str = substr($str, strlen($m[0]));
        $word = ltrim($m[0]);

        // commas in paper number strings turn into separate words
        if (preg_match('/\A(#?\d+(?:-#?\d+)?),((?:#?\d+(?:-#?\d+)?,?)*)\z/', $word, $m)) {
            $word = $m[1];
            if ($m[2] !== "")
                $str = $m[2] . $str;
        }

        // check for keyword
        $keyword = false;
        if (($colon = strpos($word, ":")) > 0) {
            $x = substr($word, 0, $colon);
            if (strpos($x, '"') === false)
                $keyword = @self::$_keywords[$x] ? : $x;
        }

        // allow a space after a keyword
        if ($keyword && strlen($word) <= $colon + 1 && preg_match($wordre, $str, $m)) {
            $word .= $m[0];
            $str = substr($str, strlen($m[0]));
        }

        // "show:" may be followed by a parenthesized expression
        if ($keyword
            && (substr($str, 0, 1) === "(" || substr($str, 0, 2) === "-(")
            && substr($word, $colon + 1, 1) !== "\""
            && ($keyword === "show" || $keyword === "showsort"
                || $keyword === "sort" || $keyword === "formula")) {
            $pos = self::find_end_balanced_parens($str);
            $word .= substr($str, 0, $pos);
            $str = substr($str, $pos);
        }

        $str = ltrim($str);
        return $word;
    }

    static function _searchPopKeyword($str) {
        if (preg_match('/\A([-+()]|(?:AND|and|OR|or|NOT|not|THEN|then|HIGHLIGHT(?::\w+)?)(?=[\s\(]))/s', $str, $m))
            return array(strtoupper($m[1]), ltrim(substr($str, strlen($m[0]))));
        else
            return array(null, $str);
    }

    static private function _searchPopStack($curqe, &$stack) {
        $x = array_pop($stack);
        if (!$curqe)
            return $x->leftqe;
        else if ($x->op->op === "+")
            return $curqe;
        else
            return SearchTerm::make_opstr($x->op, $x->leftqe, $curqe, $x->substr);
    }

    private function _searchQueryType($str) {
        $stack = array();
        $defkwstack = array();
        $defkw = $next_defkw = null;
        $parens = 0;
        $curqe = null;

        while ($str !== "") {
            list($opstr, $nextstr) = self::_searchPopKeyword($str);
            $op = $opstr ? SearchOperator::$list[$opstr] : null;
            if ($opstr && !$op && ($colon = strpos($opstr, ":"))
                && ($op = SearchOperator::$list[substr($opstr, 0, $colon)])) {
                $op = clone $op;
                $op->opinfo = substr($opstr, $colon + 1);
            }

            if ($curqe && (!$op || $op->unary)) {
                list($opstr, $op, $nextstr) =
                    array("", SearchOperator::$list["SPACE"], $str);
            }
            if (!$curqe && $op && $op->op === "highlight") {
                $curqe = new SearchTerm("t");
                $curqe->set_float("substr", "");
            }

            if ($opstr === null) {
                $prevstr = $nextstr;
                $word = self::pop_word($nextstr);
                // Bare any-case "all", "any", "none" are treated as keywords.
                if (!$curqe
                    && (!count($stack) || $stack[count($stack) - 1]->op->precedence <= 2)
                    && ($uword = strtoupper($word))
                    && ($uword === "ALL" || $uword === "ANY" || $uword === "NONE")
                    && preg_match(',\A\s*(?:|(?:THEN|then|HIGHLIGHT(?::\w+)?)(?:\s|\().*)\z,', $nextstr))
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
                        if ($word[0] === "-")
                            $word = "-" . $defkw . substr($word, 1);
                        else
                            $word = $defkw . $word;
                    }
                    // The heart of the matter.
                    $curqe = $this->_searchQueryWord($word, true);
                    // Don't include 'show:' in headings.
                    if (($colon = strpos($word, ":")) === false
                        || !@self::$_noheading_keywords[substr($word, 0, $colon)])
                        $curqe->set_float("substr", substr($prevstr, 0, strlen($prevstr) - strlen($nextstr)));
                }
            } else if ($opstr === ")") {
                while (count($stack)
                       && $stack[count($stack) - 1]->op->op !== "(")
                    $curqe = self::_searchPopStack($curqe, $stack);
                if ($curqe && ($x = $curqe->get_float("substr")) !== null)
                    $curqe->set_float("substr", "(" . rtrim($x) . ")");
                if (count($stack)) {
                    array_pop($stack);
                    --$parens;
                    $defkw = array_pop($defkwstack);
                }
            } else if ($opstr === "(") {
                assert(!$curqe);
                $stack[] = (object) array("op" => $op, "leftqe" => null, "substr" => "(");
                $defkwstack[] = $defkw;
                $defkw = $next_defkw;
                $next_defkw = null;
                ++$parens;
            } else if ($op->unary || $curqe) {
                $end_precedence = $op->precedence - ($op->precedence <= 1);
                while (count($stack)
                       && $stack[count($stack) - 1]->op->precedence > $end_precedence)
                    $curqe = self::_searchPopStack($curqe, $stack);
                $stack[] = (object) array("op" => $op, "leftqe" => $curqe, "substr" => substr($str, 0, strlen($str) - strlen($nextstr)));
                $curqe = null;
            }

            $str = $nextstr;
        }

        while (count($stack))
            $curqe = self::_searchPopStack($curqe, $stack);
        return $curqe;
    }


    static private function _canonicalizePopStack($curqe, &$stack) {
        $x = array_pop($stack);
        if ($curqe)
            $x->qe[] = $curqe;
        if (!count($x->qe))
            return null;
        if ($x->op->unary) {
            $qe = $x->qe[0];
            if ($x->op->op === "not") {
                if (preg_match('/\A(?:[(-]|NOT )/i', $qe))
                    $qe = "NOT $qe";
                else
                    $qe = "-$qe";
            }
            return $qe;
        } else if (count($x->qe) == 1)
            return $x->qe[0];
        else if ($x->op->op === "and2" && $x->op->precedence == 2)
            return "(" . join(" ", $x->qe) . ")";
        else
            return "(" . join(strtoupper(" " . $x->op->op . " "), $x->qe) . ")";
    }

    static private function _canonicalizeQueryType($str, $type) {
        $stack = array();
        $parens = 0;
        $defaultop = ($type === "all" ? "XAND" : "XOR");
        $curqe = null;
        $t = "";

        while ($str !== "") {
            list($opstr, $nextstr) = self::_searchPopKeyword($str);
            $op = $opstr ? SearchOperator::$list[$opstr] : null;

            if ($curqe && (!$op || $op->unary)) {
                list($opstr, $op, $nextstr) =
                    array("", SearchOperator::$list[$parens ? "XAND" : $defaultop], $str);
            }

            if ($opstr === null) {
                $curqe = self::pop_word($nextstr);
            } else if ($opstr === ")") {
                while (count($stack)
                       && $stack[count($stack) - 1]->op->op !== "(")
                    $curqe = self::_canonicalizePopStack($curqe, $stack);
                if (count($stack)) {
                    array_pop($stack);
                    --$parens;
                }
            } else if ($opstr === "(") {
                assert(!$curqe);
                $stack[] = (object) array("op" => $op, "qe" => array());
                ++$parens;
            } else {
                $end_precedence = $op->precedence - ($op->precedence <= 1);
                while (count($stack)
                       && $stack[count($stack) - 1]->op->precedence > $end_precedence)
                    $curqe = self::_canonicalizePopStack($curqe, $stack);
                $top = count($stack) ? $stack[count($stack) - 1] : null;
                if ($top && !$op->unary && $top->op->op === $op->op)
                    $top->qe[] = $curqe;
                else
                    $stack[] = (object) array("op" => $op, "qe" => array($curqe));
                $curqe = null;
            }

            $str = $nextstr;
        }

        if ($type === "none")
            array_unshift($stack, (object) array("op" => SearchOperator::$list["NOT"], "qe" => array()));
        while (count($stack))
            $curqe = self::_canonicalizePopStack($curqe, $stack);
        return $curqe;
    }

    static function canonical_query($qa, $qo = null, $qx = null) {
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
        if ($neg1 !== $neg2 || ($neg1 && $op === "or")) {
            if ($neg1)
                self::_reviewAdjustmentNegate($revadj);
            if ($neg2)
                self::_reviewAdjustmentNegate($qv);
            $neg1 = $neg2 = false;
        }
        if ($op === "or" || $neg1) {
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

    // apply rounds to reviewer searches
    static private $adjustments = array("round", "rate");

    function _queryMakeAdjustedReviewSearch($roundterm) {
        $value = new ReviewSearchMatcher(">0");
        if ($this->limitName === "r" || $this->limitName === "rout")
            $value->add_contact($this->cid);
        else if ($this->limitName === "req" || $this->limitName === "reqrevs")
            $value->fieldsql = "requestedBy=" . $this->cid . " and reviewType=" . REVIEW_EXTERNAL;
        foreach (self::$adjustments as $adj)
            if (isset($roundterm->value[$adj]))
                $value->$adj = $roundterm->value[$adj];
        $rt = $this->privChair ? 0 : self::F_NONCONFLICT;
        if (!$this->amPC)
            $rt |= self::F_REVIEWER;
        $term = new SearchTerm("re", $rt | self::F_XVIEW, $value, $roundterm->value);
        if (defval($roundterm->value, "revadjnegate")) {
            $term->set("revadjnegate", false);
            return SearchTerm::make_not($term);
        } else
            return $term;
    }

    private function _query_adjust_reviews($qe, $revadj) {
        $applied = $first_applied = 0;
        if ($qe->type === "not")
            $this->_query_adjust_reviews($qe->value, $revadj);
        else if ($qe->type === "and" || $qe->type === "and2") {
            $myrevadj = ($qe->value[0]->type === "revadj" ? $qe->value[0] : null);
            if ($myrevadj) {
                $used_revadj = false;
                foreach (self::$adjustments as $adj)
                    if (!isset($myrevadj->value[$adj]) && isset($revadj->value[$adj])) {
                        $myrevadj->value[$adj] = $revadj->value[$adj];
                        $used_revadj = true;
                    }
            }

            $rdown = $myrevadj ? : $revadj;
            for ($i = 0; $i < count($qe->value); ++$i)
                if ($qe->value[$i]->type !== "revadj")
                    $this->_query_adjust_reviews($qe->value[$i], $rdown);

            if ($myrevadj && !isset($myrevadj->used_revadj)) {
                $qe->value[0] = $this->_queryMakeAdjustedReviewSearch($myrevadj);
                if ($used_revadj)
                    $revadj->used_revadj = true;
            }
        } else if ($qe->type === "or" || $qe->type === "then") {
            for ($i = 0; $i < count($qe->value); ++$i)
                $this->_query_adjust_reviews($qe->value[$i], $revadj);
        } else if ($qe->type === "re" && $revadj) {
            foreach (self::$adjustments as $adj)
                if (isset($revadj->value[$adj]) && !isset($qe->value->$adj))
                    $qe->value->$adj = $revadj->value[$adj];
            $revadj->used_revadj = true;
        } else if ($qe->get_float("used_revadj")) {
            $revadj && $revadj->used_revadj = true;
        } else if ($qe->type === "revadj") {
            assert(!$revadj);
            return $this->_queryMakeAdjustedReviewSearch($qe);
        }
        return $qe;
    }

    private function _queryExtractInfo($qe, $top, $highlight, &$contradictions) {
        if ($qe->type === "and" || $qe->type === "and2"
            || $qe->type === "or" || $qe->type === "then") {
            $isand = $qe->type === "and" || $qe->type === "and2";
            $nthen = $qe->type === "then" ? $qe->nthen : count($qe->value);
            foreach ($qe->value as $qvidx => $qv)
                $this->_queryExtractInfo($qv, $top && $isand, $qvidx >= $nthen, $contradictions);
        }
        if (($x = $qe->get("regex"))) {
            $this->regex[$x[0]] = defval($this->regex, $x[0], array());
            $this->regex[$x[0]][] = $x[1];
        }
        if ($top && $qe->type === "re" && !$this->_reviewer_fixed && !$highlight) {
            if ($this->_reviewer === false
                && ($v = $qe->value->contact_set())
                && count($v) == 1)
                $this->_reviewer = $v[0];
            else
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
        global $Conf;
        $flags = $t->flags;
        $this->needflags |= $flags;

        if ($flags & self::F_MANAGER) {
            if ($Conf->has_any_manager() && $this->privChair)
                $q[] = "(managerContactId=$this->cid or (managerContactId=0 and PaperConflict.conflictType is null))";
            else if ($this->privChair)
                $q[] = "true";
            else if ($this->contact->is_manager())
                $q[] = "managerContactId=$this->cid";
            else
                $q[] = "false";
            $sqi->add_rights_columns();
        }
        if ($flags & self::F_NONCONFLICT)
            $q[] = "PaperConflict.conflictType is null";
        if ($flags & self::F_AUTHOR)
            $q[] = $this->contact->actAuthorSql("PaperConflict");
        if ($flags & self::F_REVIEWER)
            $q[] = "MyReview.reviewNeedsSubmit=0"; // i.e. not null
        if ($flags & self::F_XVIEW) {
            $this->needflags |= self::F_NONCONFLICT | self::F_REVIEWER;
            $sqi->add_rights_columns();
        }
        if (($flags & self::F_FALSE)
            || ($sqi->negated && ($flags & self::F_XVIEW)))
            $q[] = "false";
    }

    private function _clauseTermSetField($t, $field, $sqi, &$f) {
        $this->needflags |= $t->flags;
        $v = $t->value;
        if ($v !== "" && $v[0] === "*")
            $v = substr($v, 1);
        if ($v !== "" && $v[strlen($v) - 1] === "*")
            $v = substr($v, 0, strlen($v) - 1);
        if ($sqi->negated)
            // The purpose of _clauseTermSetField is to match AT LEAST those
            // papers that contain "$t->value" as a word in the $field field.
            // A substring match contains at least those papers; but only if
            // the current term is positive (= not negated).  If the current
            // term is negated, we say NO paper matches this clause.  (NOT no
            // paper is every paper.)  Later code will check for a substring.
            $f[] = "false";
        else {
            $q = array();
            $this->_clauseTermSetFlags($t, $sqi, $q);
            $q[] = "true";
            $f[] = "(" . join(" and ", $q) . ")";
        }
        $t->link = $field;
        $this->needflags |= self::F_XVIEW;
    }

    private function _clauseTermSetTable($t, $value, $compar, $shorttab,
                                         $table, $field, $where, $sqi, &$f) {
        // see also first "tag" case below
        $q = array();
        $this->_clauseTermSetFlags($t, $sqi, $q);

        if ($value === "none" && !$compar)
            list($compar, $value) = array("=0", "");
        else if (($value === "" || $value === "any") && !$compar)
            list($compar, $value) = array(">0", "");
        else if (!$compar || $compar === ">=1")
            $compar = ">0";
        else if ($compar === "<=0" || $compar === "<1")
            $compar = "=0";

        $thistab = $shorttab . "_" . count($sqi->tables);
        if ($value === "") {
            if ($compar === ">0" || $compar === "=0")
                $thistab = "Any" . $shorttab;
            $tdef = array("left join", $table);
        } else if (is_array($value)) {
            if (count($value))
                $tdef = array("left join", $table, "$thistab.$field in (" . join(",", $value) . ")");
            else
                $tdef = array("left join", $table, "false");
        } else if (strpos($value, "\3") !== false) {
            $tdef = array("left join", $table, str_replace("\3", "$thistab.$field", $value));
        } else {
            $tdef = array("left join", $table, "$thistab.$field='" . sqlq($value) . "'");
        }
        if ($where)
            $tdef[2] .= str_replace("%", $thistab, $where);

        if ($compar !== ">0" && $compar !== "=0") {
            $tdef[1] = "(select paperId, count(*) ct from " . $tdef[1] . " as " . $thistab;
            if (count($tdef) > 2)
                $tdef[1] .= " where " . array_pop($tdef);
            $tdef[1] .= " group by paperId)";
            $sqi->add_column($thistab . "_ct", "$thistab.ct");
            $q[] = "coalesce($thistab.ct,0)$compar";
        } else {
            $sqi->add_column($thistab . "_ct", "count($thistab.$field)");
            if ($compar === "=0")
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
        $result = Dbl::qe("select MPR.reviewId
        from PaperReview as MPR
        left join (select paperId, count(reviewId) as numReviews from PaperReview where $npr_constraint and reviewNeedsSubmit<=0 group by paperId) as NPR on (NPR.paperId=MPR.paperId)
        left join (select paperId, count(rating) as numRatings from PaperReview join ReviewRating using (reviewId) group by paperId) as NRR on (NRR.paperId=MPR.paperId)
        where MPR.contactId=$contactId
        and numReviews<=2
        and numRatings<=2");
        return Dbl::fetch_first_columns($result);
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

    private function _clauseTermSetReviews($t, $sqi) {
        global $Conf;
        $rsm = $t->value;
        if (($thistab = $rsm->simple_name()))
            $thistab = "Reviews_" . $thistab;
        else
            $thistab = "Reviews_" . count($sqi->tables);

        if (!isset($sqi->tables[$thistab])) {
            $where = array();
            $reviewtable = "PaperReview r";
            if ($rsm->review_type)
                $where[] = "reviewType=" . $rsm->review_type;
            $cwhere = array();
            if ($rsm->completeness & ReviewSearchMatcher::COMPLETE)
                $cwhere[] = "reviewSubmitted>0";
            if ($rsm->completeness & ReviewSearchMatcher::INCOMPLETE)
                $cwhere[] = "reviewNeedsSubmit>0";
            if ($rsm->completeness & ReviewSearchMatcher::INPROGRESS)
                $cwhere[] = "(reviewNeedsSubmit>0 and reviewModified>0)";
            if (count($cwhere))
                $where[] = "(" . join(" or ", $cwhere) . ")";
            if ($rsm->round !== null) {
                if (count($rsm->round) == 0)
                    $where[] = "false";
                else
                    $where[] = "reviewRound" . sql_in_numeric_set($rsm->round);
            }
            if ($rsm->rate !== null)
                $this->_clauseTermSetRating($reviewtable, $where, $rsm->rate);
            if ($rsm->has_contacts())
                $where[] = $rsm->contact_match_sql("r.contactId");
            if ($rsm->fieldsql)
                $where[] = $rsm->fieldsql;
            if ($rsm->wordcountexpr && $Conf->sversion >= 99)
                $where[] = "reviewWordCount" . $rsm->wordcountexpr;
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
        $q[] = "coalesce($thistab.count,0)" . $rsm->conservative_countexpr();
        $t->link = $thistab;
        return "(" . join(" and ", $q) . ")";
    }

    private function _clauseTermSetRevpref($t, $sqi) {
        $thistab = "Revpref_" . count($sqi->tables);
        $rsm = $t->value;

        if ($rsm->preference_match
            && $rsm->preference_match->test(0)
            && !$rsm->expertise_match) {
            $q = "select Paper.paperId, count(ContactInfo.contactId) as count"
                . " from Paper join ContactInfo"
                . " left join PaperReviewPreference on (PaperReviewPreference.paperId=Paper.paperId and PaperReviewPreference.contactId=ContactInfo.contactId)"
                . " where coalesce(preference,0)" . $rsm->preference_match->countexpr()
                . " and " . ($rsm->has_contacts() ? $rsm->contact_match_sql("ContactInfo.contactId") : "(roles&" . Contact::ROLE_PC . ")!=0")
                . " group by Paper.paperId";
        } else {
            $where = array();
            if ($rsm->has_contacts())
                $where[] = $rsm->contact_match_sql("contactId");
            if ($rsm->preference_match)
                $where[] = "preference" . $rsm->preference_match->countexpr();
            if ($rsm->expertise_match)
                $where[] = "expertise" . $rsm->expertise_match->countexpr();
            $q = "select paperId, count(PaperReviewPreference.preference) as count"
                . " from PaperReviewPreference";
            if (count($where))
                $q .= " where " . join(" and ", $where);
            $q .= " group by paperId";
        }
        $sqi->add_table($thistab, array("left join", "($q)"));

        $q = array();
        $this->_clauseTermSetFlags($t, $sqi, $q);
        $q[] = "coalesce($thistab.count,0)" . $t->value->countexpr();
        $sqi->add_column($thistab . "_matches", "$thistab.count");
        $t->link = $thistab . "_matches";
        return "(" . join(" and ", $q) . ")";
    }

    private function _clauseTermSetComments($thistab, $extrawhere, $t, $sqi) {
        global $Conf;
        if (!isset($sqi->tables[$thistab])) {
            $where = array();
            if (!($t->flags & self::F_ALLOWRESPONSE))
                $where[] = "(commentType&" . COMMENTTYPE_RESPONSE . ")=0";
            if (!($t->flags & self::F_ALLOWCOMMENT))
                $where[] = "(commentType&" . COMMENTTYPE_RESPONSE . ")!=0";
            if (!($t->flags & self::F_ALLOWDRAFT))
                $where[] = "(commentType&" . COMMENTTYPE_DRAFT . ")=0";
            else if ($t->flags & self::F_REQUIREDRAFT)
                $where[] = "(commentType&" . COMMENTTYPE_DRAFT . ")!=0";
            if ($t->flags & self::F_AUTHORCOMMENT)
                $where[] = "commentType>=" . COMMENTTYPE_AUTHOR;
            if (@$t->commentRound !== null)
                $where[] = "commentRound=" . $t->commentRound;
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
        if ($tt === "ti") {
            $sqi->add_column("title", "Paper.title");
            $this->_clauseTermSetField($t, "title", $sqi, $f);
        } else if ($tt === "ab") {
            $sqi->add_column("abstract", "Paper.abstract");
            $this->_clauseTermSetField($t, "abstract", $sqi, $f);
        } else if ($tt === "au") {
            $sqi->add_column("authorInformation", "Paper.authorInformation");
            $this->_clauseTermSetField($t, "authorInformation", $sqi, $f);
        } else if ($tt === "co") {
            $sqi->add_column("collaborators", "Paper.collaborators");
            $this->_clauseTermSetField($t, "collaborators", $sqi, $f);
        } else if ($tt === "au_cid") {
            $this->_clauseTermSetTable($t, $t->value, null, "AuthorConflict",
                                       "PaperConflict", "contactId",
                                       " and " . $this->contact->actAuthorSql("%"),
                                       $sqi, $f);
        } else if ($tt === "re") {
            $f[] = $this->_clauseTermSetReviews($t, $sqi);
        } else if ($tt === "revpref") {
            $f[] = $this->_clauseTermSetRevpref($t, $sqi);
        } else if ($tt === "conflict") {
            $this->_clauseTermSetTable($t, $t->value->contact_set(),
                                       $t->value->countexpr(), "Conflict",
                                       "PaperConflict", "contactId", "",
                                       $sqi, $f);
        } else if ($tt === "cmt") {
            if ($t->value->has_contacts())
                $thistab = "Comments_" . count($sqi->tables);
            else {
                $rtype = $t->flags & (self::F_ALLOWCOMMENT | self::F_ALLOWRESPONSE | self::F_AUTHORCOMMENT | self::F_ALLOWDRAFT | self::F_REQUIREDRAFT);
                $thistab = "Numcomments_" . $rtype;
                if (@$t->commentRound !== null)
                    $thistab .= "_" . $t->commentRound;
            }
            $f[] = $this->_clauseTermSetComments($thistab, $t->value->contact_match_sql("contactId"), $t, $sqi);
        } else if ($tt === "cmttag") {
            $thistab = "TaggedComments_" . count($sqi->tables);
            if ($t->value->tag === "any")
                $match = "is not null";
            else
                $match = "like " . Dbl::utf8ci("'% " . sqlq($t->value->tag) . " %'");
            $f[] = $this->_clauseTermSetComments($thistab, "commentTags $match", $t, $sqi);
        } else if ($tt === "pn") {
            $q = array();
            if (count($t->value[0]))
                $q[] = "Paper.paperId in (" . join(",", $t->value[0]) . ")";
            if (count($t->value[1]))
                $q[] = "Paper.paperId not in (" . join(",", $t->value[1]) . ")";
            if (!count($q))
                $q[] = "false";
            $f[] = "(" . join(" and ", $q) . ")";
        } else if ($tt === "pf") {
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
        } else if ($tt === "tag") {
            $extra = "";
            for ($i = 1; $i < count($t->value); ++$i)
                $extra .= " and %.tagIndex" . $t->value[$i];
            $this->_clauseTermSetTable($t, $t->value[0], null, "Tag",
                                       "PaperTag", "tag", $extra,
                                       $sqi, $f);
        } else if ($tt === "topic") {
            $this->_clauseTermSetTable($t, $t->value, null, "Topic",
                                       "PaperTopic", "topicId", "",
                                       $sqi, $f);
        } else if ($tt === "option") {
            // expanded from _clauseTermSetTable
            $q = array();
            $this->_clauseTermSetFlags($t, $sqi, $q);
            $optionNum = count($sqi->tables);
            $thistab = "Option_" . $optionNum;
            $sqi->add_table($thistab, array("left join", "PaperOption", "$thistab.optionId=" . $t->value[0]->id));
            if ($t->value[0]->type == "text") {
                $sqi->add_column($thistab . "_x", "$thistab.value");
                $t->link = $thistab . "_x";
                $newValue = str_replace("Option_X.data like '", "Option_$optionNum.data like '", $t->value[1]);
                $q[] = $sqi->columns[$t->link] . $newValue;
            } else {
                $sqi->add_column($thistab . "_x", "coalesce($thistab.value,0)" . $t->value[1]);
                $t->link = $thistab . "_x";
                $q[] = $sqi->columns[$t->link];
            }
            $f[] = "(" . join(" and ", $q) . ")";
        } else if ($tt === "formula") {
            $q = array("true");
            $this->_clauseTermSetFlags($t, $sqi, $q);
            $t->value->add_query_options($this->_query_options, $this->contact);
            if (!$t->link)
                $t->link = $t->value->compile_function($this->contact);
            $f[] = "(" . join(" and ", $q) . ")";
        } else if ($tt === "not") {
            $ff = array();
            $sqi->negated = !$sqi->negated;
            $this->_clauseTermSet($t->value[0], $sqi, $ff);
            $sqi->negated = !$sqi->negated;
            if (!count($ff))
                $ff[] = "true";
            $f[] = "not (" . join(" or ", $ff) . ")";
        } else if ($tt === "and" || $tt === "and2") {
            $ff = array();
            foreach ($t->value as $subt)
                $this->_clauseTermSet($subt, $sqi, $ff);
            if (!count($ff))
                $ff[] = "false";
            $f[] = "(" . join(" and ", $ff) . ")";
        } else if ($tt === "or" || $tt === "then") {
            $ff = array();
            foreach ($t->value as $subt)
                $this->_clauseTermSet($subt, $sqi, $ff);
            if (!count($ff))
                $ff[] = "false";
            $f[] = "(" . join(" or ", $ff) . ")";
        } else if ($tt === "f")
            $f[] = "false";
        else if ($tt === "t")
            $f[] = "true";
    }


    // QUERY EVALUATION
    // Check the results of the query, reducing the possibly conservative
    // overestimate produced by the database to a precise result.

    private function _clauseTermCheckFlags($t, $row) {
        $flags = $t->flags;
        if (($flags & self::F_MANAGER)
            && !$this->contact->can_administer($row, true))
            return false;
        if (($flags & self::F_AUTHOR)
            && !$this->contact->act_author_view($row))
            return false;
        if (($flags & self::F_REVIEWER)
            && $row->myReviewNeedsSubmit !== 0
            && $row->myReviewNeedsSubmit !== "0")
            return false;
        if (($flags & self::F_NONCONFLICT) && $row->conflictType)
            return false;
        if ($flags & self::F_XVIEW) {
            if (!$this->contact->can_view_paper($row))
                return false;
            if ($t->type === "tag" && !$this->contact->can_view_tags($row, true))
                return false;
            if (($t->type === "au" || $t->type === "au_cid" || $t->type === "co")
                && !$this->contact->can_view_authors($row, true))
                return false;
            if ($t->type === "conflict"
                && !$this->contact->can_view_conflicts($row, true))
                return false;
            if ($t->type === "pf" && $t->value[0] === "outcome"
                && !$this->contact->can_view_decision($row, true))
                return false;
            if ($t->type === "option"
                && !$this->contact->can_view_paper_option($row, $t->value[0], true))
                return false;
            if ($t->type === "re" && ($fieldname = $t->link)
                && !isset($row->$fieldname)) {
                $row->$fieldname = 0;
                $rrow = (object) array("paperId" => $row->paperId);
                $count_only = !$t->value->fieldsql;
                foreach (explode(",", defval($row, $fieldname . "_info", "")) as $info)
                    if ($info !== "") {
                        list($rrow->reviewId, $rrow->contactId, $rrow->reviewType, $rrow->reviewSubmitted, $rrow->reviewNeedsSubmit, $rrow->requestedBy, $rrow->reviewToken, $rrow->reviewBlind) = explode(" ", $info);
                        if (($count_only
                             ? $this->contact->can_count_review($row, $rrow, true)
                             : $this->contact->can_view_review($row, $rrow, true))
                            && (!$t->value->has_contacts()
                                || $this->contact->can_view_review_identity($row, $rrow, true))
                            && (!isset($t->value->view_score)
                                || $t->value->view_score > $this->contact->view_score_bound($row, $rrow)))
                            ++$row->$fieldname;
                    }
            }
            if (($t->type === "cmt" || $t->type === "cmttag")
                && ($fieldname = $t->link)
                && !isset($row->$fieldname)) {
                $row->$fieldname = 0;
                $crow = (object) array("paperId" => $row->paperId);
                foreach (explode(",", defval($row, $fieldname . "_info", "")) as $info)
                    if ($info !== "") {
                        list($crow->contactId, $crow->commentType) = explode(" ", $info);
                        if ($this->contact->can_view_comment($row, $crow, true))
                            ++$row->$fieldname;
                    }
            }
            if ($t->type === "pf" && $t->value[0] === "leadContactId"
                && !$this->contact->can_view_lead($row, true))
                return false;
            if ($t->type === "pf" && $t->value[0] === "shepherdContactId"
                && !$this->contact->can_view_shepherd($row, true))
                return false;
            if ($t->type === "pf" && $t->value[0] === "managerContactId"
                && !$this->contact->can_view_paper_manager($row))
                return false;
        }
        if ($flags & self::F_FALSE)
            return false;
        return true;
    }

    function _clauseTermCheckField($t, $row) {
        $field = $t->link;
        if (!$this->_clauseTermCheckFlags($t, $row)
            || $row->$field === "")
            return false;

        $field_deaccent = $field . "_deaccent";
        if (!isset($row->$field_deaccent)) {
            if (preg_match('/[\x80-\xFF]/', $row->$field))
                $row->$field_deaccent = UnicodeHelper::deaccent($row->$field);
            else
                $row->$field_deaccent = false;
        }

        if (!isset($t->preg_utf8))
            self::analyze_field_preg($t);
        return self::match_field_preg($t, $row->$field, $row->$field_deaccent);
    }

    function _clauseTermCheck($t, $row) {
        $tt = $t->type;

        // collect columns
        if ($tt === "ti" || $tt === "ab" || $tt === "au" || $tt === "co")
            return $this->_clauseTermCheckField($t, $row);
        else if ($tt === "au_cid") {
            assert(is_array($t->value));
            return $this->_clauseTermCheckFlags($t, $row)
                && $row->{$t->link} != 0;
        } else if ($tt === "re" || $tt === "conflict" || $tt === "revpref"
                   || $tt === "cmt" || $tt === "cmttag") {
            if (!$this->_clauseTermCheckFlags($t, $row))
                return false;
            else {
                $fieldname = $t->link;
                return $t->value->test((int) $row->$fieldname);
            }
        } else if ($tt === "pn") {
            if (count($t->value[0]) && array_search($row->paperId, $t->value[0]) === false)
                return false;
            else if (count($t->value[1]) && array_search($row->paperId, $t->value[1]) !== false)
                return false;
            else
                return true;
        } else if ($tt === "pf") {
            if (!$this->_clauseTermCheckFlags($t, $row))
                return false;
            else {
                $ans = true;
                for ($i = 0; $ans && $i < count($t->value); $i += 2) {
                    $fieldname = $t->value[$i];
                    $expr = $t->value[$i + 1];
                    if (is_array($expr))
                        $ans = in_array($row->$fieldname, $expr);
                    else
                        $ans = CountMatcher::compare_string($row->$fieldname, $expr);
                }
                return $ans;
            }
        } else if ($tt === "tag" || $tt === "topic") {
            if (!$this->_clauseTermCheckFlags($t, $row))
                return false;
            $fieldname = $t->link;
            if ($t->value[0] === "none")
                return $row->$fieldname == 0;
            else
                return $row->$fieldname != 0;
        } else if ($tt === "option") {
            if (!$this->_clauseTermCheckFlags($t, $row))
                return false;
            $fieldname = $t->link;
            if ($row->$fieldname == 0)
                return false;
            if (@$t->value[2] === "special") {
                $ov = $row->option($t->value[0]->id);
                if ($t->value[3] === "attachment-count")
                    return CountMatcher::compare(count($ov->values), $t->value[4], $t->value[5]);
                else if ($t->value[3] === "attachment-name") {
                    $reg = self::analyze_field_preg($t->value[4]);
                    foreach ($ov->documents($row) as $doc)
                        if (self::match_field_preg($reg, $doc->filename, false))
                            return true;
                }
                return false;
            }
            return true;
        } else if ($tt === "formula") {
            $formulaf = $t->link;
            return !!$formulaf($row, null, $this->contact);
        } else if ($tt === "not") {
            return !$this->_clauseTermCheck($t->value[0], $row);
        } else if ($tt === "and" || $tt === "and2") {
            foreach ($t->value as $subt)
                if (!$this->_clauseTermCheck($subt, $row))
                    return false;
            return true;
        } else if ($tt === "or") {
            foreach ($t->value as $subt)
                if ($this->_clauseTermCheck($subt, $row))
                    return true;
            return false;
        } else if ($tt === "then") {
            for ($i = 0; $i < $t->nthen; ++$i)
                if ($this->_clauseTermCheck($t->value[$i], $row))
                    return true;
            return false;
        } else if ($tt === "f")
            return false;
        else if ($tt === "t" || $tt === "revadj")
            return true;
        else {
            error_log("PaperSearch::_clauseTermCheck: $tt defaults, correctness unlikely");
            return true;
        }
    }

    private function _add_deleted_papers($qe) {
        if ($qe->type === "or" || $qe->type === "then") {
            foreach ($qe->value as $subt)
                $this->_add_deleted_papers($subt);
        } else if ($qe->type === "pn") {
            foreach ($qe->value[0] as $p)
                if (array_search($p, $this->_matches) === false)
                    $this->_matches[] = $p;
        }
    }


    // BASIC QUERY FUNCTION

    private function _add_sorters($qe, $thenmap) {
        foreach ($qe->get_float("sort", array()) as $s)
            if (($s = ListSorter::parse_sorter($s))) {
                $s->thenmap = $thenmap;
                $this->sorters[] = $s;
            }
        if (!$qe->get_float("sort") && $qe->type === "pn") {
            $pn = array_diff($qe->value[0], $qe->value[1]);
            $s = ListSorter::make_field(new NumericOrderPaperColumn(array_flip($pn)));
            $s->thenmap = $thenmap;
            $this->sorters[] = $s;
        }
    }

    function _search() {
        global $Conf;
        if ($this->_matches === false)
            return false;
        assert($this->_matches === null);

        if ($this->limitName === "x") {
            $this->_matches = array();
            return true;
        }

        // parse and clean the query
        $qe = $this->_searchQueryType($this->q);
        //Conf::msg_info(Ht::pre_text(var_export($qe, true)));
        if (!$qe)
            $qe = new SearchTerm("t");

        // apply complex limiters (only current example: "acc" for non-chairs)
        $limit = $this->limitName;
        if ($limit === "acc" && !$this->privChair)
            $qe = SearchTerm::make_op("and", array($qe, $this->_searchQueryWord("dec:yes", false)));

        // apply review rounds (top down, needs separate step)
        if ($this->reviewAdjust) {
            $qe = $this->_query_adjust_reviews($qe, null);
            if ($this->_reviewAdjustError)
                $this->warn("Unexpected use of “round:” or “rate:” ignored.  Stick to the basics, such as “re:reviewername round:roundname”.");
        }

        //Conf::msg_info(Ht::pre_text(var_export($qe, true)));

        // collect clauses into tables, columns, and filters
        $sqi = new SearchQueryInfo;
        $sqi->add_table("Paper");
        $sqi->add_column("paperId", "Paper.paperId");
        // always include columns needed by rights machinery
        $sqi->add_column("timeSubmitted", "Paper.timeSubmitted");
        $sqi->add_column("timeWithdrawn", "Paper.timeWithdrawn");
        $sqi->add_column("outcome", "Paper.outcome");
        $filters = array();
        $this->_clauseTermSet($qe, $sqi, $filters);
        //Conf::msg_info(Ht::pre_text(var_export($filters, true)));

        // status limitation parts
        if ($limit === "rable") {
            $limitcontact = $this->_reviewer_fixed ? $this->reviewer() : $this->contact;
            if ($limitcontact->can_accept_review_assignment_ignore_conflict(null))
                $limit = $Conf->can_pc_see_all_submissions() ? "act" : "s";
            else if (!$limitcontact->isPC)
                $limit = "r";
        }
        if ($limit === "s" || $limit === "req"
            || $limit === "acc" || $limit === "und"
            || $limit === "unm"
            || ($limit === "rable" && !$Conf->can_pc_see_all_submissions()))
            $filters[] = "Paper.timeSubmitted>0";
        else if ($limit === "act" || $limit === "r" || $limit === "rable")
            $filters[] = "Paper.timeWithdrawn<=0";
        else if ($limit === "unsub")
            $filters[] = "(Paper.timeSubmitted<=0 and Paper.timeWithdrawn<=0)";
        else if ($limit === "lead")
            $filters[] = "Paper.leadContactId=" . $this->cid;
        else if ($limit === "manager") {
            if ($this->privChair)
                $filters[] = "(Paper.managerContactId=" . $this->cid . " or Paper.managerContactId=0)";
            else
                $filters[] = "Paper.managerContactId=" . $this->cid;
            $filters[] = "Paper.timeSubmitted>0";
        }

        // decision limitation parts
        if ($limit === "acc")
            $filters[] = "Paper.outcome>0";
        else if ($limit === "und")
            $filters[] = "Paper.outcome=0";

        // other search limiters
        if ($limit === "a") {
            $filters[] = $this->contact->actAuthorSql("PaperConflict");
            $this->needflags |= self::F_AUTHOR;
        } else if ($limit === "r") {
            $filters[] = "MyReview.reviewType is not null";
            $this->needflags |= self::F_REVIEWER;
        } else if ($limit === "ar") {
            $filters[] = "(" . $this->contact->actAuthorSql("PaperConflict") . " or (Paper.timeWithdrawn<=0 and MyReview.reviewType is not null))";
            $this->needflags |= self::F_AUTHOR | self::F_REVIEWER;
        } else if ($limit === "rout") {
            $filters[] = "MyReview.reviewNeedsSubmit!=0";
            $this->needflags |= self::F_REVIEWER;
        } else if ($limit === "revs")
            $sqi->add_table("Limiter", array("join", "PaperReview"));
        else if ($limit === "req")
            $sqi->add_table("Limiter", array("join", "PaperReview", "Limiter.requestedBy=$this->cid and Limiter.reviewType=" . REVIEW_EXTERNAL));
        else if ($limit === "unm")
            $filters[] = "Paper.managerContactId=0";

        // add common tables: conflicts, my own review, paper blindness
        if ($this->needflags & (self::F_MANAGER | self::F_NONCONFLICT | self::F_AUTHOR)) {
            $sqi->add_table("PaperConflict", array("left join", "PaperConflict", "PaperConflict.contactId=$this->cid"));
            $sqi->add_column("conflictType", "PaperConflict.conflictType");
        }
        if ($this->needflags & self::F_REVIEWER) {
            if ($Conf->submission_blindness() == Conf::BLIND_OPTIONAL)
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
                        || $qe->type === "then"
                        || $qe->get_float("heading")
                        || $limit === "rable");
        if ($need_filter) {
            $sqi->add_rights_columns();
            if ($Conf->submission_blindness() == Conf::BLIND_OPTIONAL)
                $sqi->add_column("paperBlind", "Paper.blind");
        }

        // XXX some of this should be shared with paperQuery
        if (($need_filter && $Conf->has_track_tags())
            || @$this->_query_options["tags"]) {
            $sqi->add_table("PaperTags", array("left join", "(select paperId, group_concat(' ', tag, '#', tagIndex separator '') as paperTags from PaperTag group by paperId)"));
            $sqi->add_column("paperTags", "PaperTags.paperTags");
        }
        if (@$this->_query_options["scores"] || @$this->_query_options["reviewTypes"] || @$this->_query_options["reviewContactIds"]) {
            $j = "group_concat(contactId order by reviewId) reviewContactIds";
            $sqi->add_column("reviewContactIds", "R_submitted.reviewContactIds");
            if (@$this->_query_options["reviewTypes"]) {
                $j .= ", group_concat(reviewType order by reviewId) reviewTypes";
                $sqi->add_column("reviewTypes", "R_submitted.reviewTypes");
            }
            foreach (@$this->_query_options["scores"] ? : array() as $f) {
                $j .= ", group_concat($f order by reviewId) {$f}Scores";
                $sqi->add_column("{$f}Scores", "R_submitted.{$f}Scores");
            }
            $sqi->add_table("R_submitted", array("left join", "(select paperId, $j from PaperReview where reviewSubmitted>0 group by paperId)"));
        }

        // create query
        $q = "select ";
        foreach ($sqi->columns as $colname => $value)
            $q .= $value . " " . $colname . ", ";
        $q = substr($q, 0, strlen($q) - 2) . "\n    from ";
        foreach ($sqi->tables as $tabname => $value)
            if (!$value)
                $q .= $tabname;
            else {
                $joiners = array("$tabname.paperId=Paper.paperId");
                for ($i = 2; $i < count($value); ++$i)
                    $joiners[] = "(" . $value[$i] . ")";
                $q .= "\n    " . $value[0] . " " . $value[1] . " as " . $tabname
                    . " on (" . join("\n        and ", $joiners) . ")";
            }
        if (count($filters))
            $q .= "\n    where " . join("\n        and ", $filters);
        $q .= "\n    group by Paper.paperId";

        //Conf::msg_info(Ht::pre_text_wrap($q));

        // actually perform query
        $result = Dbl::qe_raw($q);
        if (!$result)
            return ($this->_matches = false);
        $this->_matches = array();

        // correct query, create thenmap, headingmap, highlightmap
        $need_then = $qe->type === "then";
        $this->thenmap = null;
        if ($need_then && $qe->nthen > 1)
            $this->thenmap = array();
        $this->highlightmap = array();
        if ($need_filter) {
            $delete = array();
            while (($row = PaperInfo::fetch($result, $this->cid))) {
                if (!$this->contact->can_view_paper($row)
                    || ($limit === "rable"
                        && !$limitcontact->can_accept_review_assignment_ignore_conflict($row)))
                    $x = false;
                else if ($need_then) {
                    $x = false;
                    for ($i = 0; $i < $qe->nthen && $x === false; ++$i)
                        if ($this->_clauseTermCheck($qe->value[$i], $row))
                            $x = $i;
                } else
                    $x = !!$this->_clauseTermCheck($qe, $row);
                if ($x === false)
                    continue;
                $this->_matches[] = (int) $row->paperId;
                if ($this->thenmap !== null)
                    $this->thenmap[$row->paperId] = $x;
                if ($need_then)
                    for ($j = $qe->nthen; $j < count($qe->value); ++$j)
                        if ($this->_clauseTermCheck($qe->value[$j], $row)
                            && ($qe->highlights[$j - $qe->nthen] & (1 << $x))) {
                            $this->highlightmap[$row->paperId] = $qe->highlight_types[$j - $qe->nthen] . "highlight";
                            break;
                        }
            }
        } else
            while (($row = $result->fetch_object()))
                $this->_matches[] = (int) $row->paperId;
        Dbl::free($result);

        // add deleted papers explicitly listed by number (e.g. action log)
        if ($this->_allow_deleted)
            $this->_add_deleted_papers($qe);

        // view and sort information
        $this->viewmap = $qe->get_float("view", array());
        $this->sorters = array();
        $this->_add_sorters($qe, null);
        if ($qe->type === "then")
            for ($i = 0; $i < $qe->nthen; ++$i)
                $this->_add_sorters($qe->value[$i], $this->thenmap ? $i : null);
        $this->headingmap = array();
        if ($qe->type === "then") {
            for ($i = 0; $i < $qe->nthen; ++$i)
                $this->headingmap[$i] = $qe->value[$i]->get_float("heading");
        } else if (($h = $qe->get_float("heading")))
            $this->headingmap[0] = $h;

        // extract regular expressions and set _reviewer if the query is
        // about exactly one reviewer, and warn about contradictions
        $contradictions = array();
        $this->_queryExtractInfo($qe, true, false, $contradictions);
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
        if (($limit === "s" || $limit === "act")
            && $this->q === "re:me")
            $limit = "r";
        else if ($this->q !== "")
            return true;
        if ($Conf->has_tracks()) {
            if (!$this->privChair || $limit === "rable")
                return true;
        }
        if ($limit === "rable") {
            $c = ($this->_reviewer_fixed ? $this->reviewer() : $this->contact);
            if ($c->isPC)
                $limit = $Conf->can_pc_see_all_submissions() ? "act" : "s";
            else
                $limit = "r";
        }
        if ($limit === "s" || $limit === "revs")
            $queryOptions["finalized"] = 1;
        else if ($limit === "unsub") {
            $queryOptions["unsub"] = 1;
            $queryOptions["active"] = 1;
        } else if ($limit === "acc") {
            if ($this->privChair || $Conf->timeAuthorViewDecision()) {
                $queryOptions["accepted"] = 1;
                $queryOptions["finalized"] = 1;
            } else
                return true;
        } else if ($limit === "und") {
            $queryOptions["undecided"] = 1;
            $queryOptions["finalized"] = 1;
        } else if ($limit === "r")
            $queryOptions["myReviews"] = 1;
        else if ($limit === "rout")
            $queryOptions["myOutstandingReviews"] = 1;
        else if ($limit === "a") {
            // If complex author SQL, always do search the long way
            if ($this->contact->actAuthorSql("%", true))
                return true;
            $queryOptions["author"] = 1;
        } else if ($limit === "req" || $limit === "reqrevs")
            $queryOptions["myReviewRequests"] = 1;
        else if ($limit === "act")
            $queryOptions["active"] = 1;
        else if ($limit === "lead")
            $queryOptions["myLead"] = 1;
        else if ($limit === "unm")
            $queryOptions["finalized"] = $queryOptions["unmanaged"] = 1;
        else if ($limit === "all")
            /* no limit */;
        else
            return true; /* don't understand limit */
        return false;
    }

    function alternate_query() {
        if ($this->q !== "" && $this->q[0] !== "#"
            && preg_match('/\A' . TAG_REGEX . '\z/', $this->q)) {
            if ($this->q[0] === "~")
                return "#" . $this->q;
            $result = Dbl::qe("select paperId from PaperTag where tag=? limit 1", $this->q);
            if (count(Dbl::fetch_first_columns($result)))
                return "#" . $this->q;
        }
        return false;
    }

    function has_sort() {
        return @$this->sorters;
    }

    function paperList() {
        if ($this->_matches === null)
            $this->_search();
        return $this->_matches ? : array();
    }

    function url_site_relative_raw($q = null) {
        $url = $this->urlbase;
        if ($q === null)
            $q = $this->q;
        if ($q !== "" || substr($this->urlbase, 0, 6) === "search")
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
        if ($this->q === "")
            return false;
        else if (strlen($this->q) <= 24)
            return htmlspecialchars($this->q);
        else if (!preg_match(',\A(#|-#|tag:|-tag:|notag:|order:|rorder:)(.*)\z,', $this->q, $m))
            return false;
        $tagger = new Tagger($this->contact);
        if (!$tagger->check($m[2]))
            return false;
        else if ($m[1] === "-tag:")
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
        if ($this->q === "")
            return $listname;
        if (($td = $this->_tag_description())) {
            if ($listname === "Submitted papers") {
                if ($this->q === "re:me")
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
                                 $this->url_site_relative_raw());
        if ($this->matchPreg)
            $l->matchPreg = $this->matchPreg;
        return $l;
    }

    function session_list_object($sort = null) {
        return $this->create_session_list_object($this->paperList(),
                                                 null, $sort);
    }

    function highlight_tags() {
        if ($this->_highlight_tags === null) {
            $this->_highlight_tags = array();
            foreach ($this->sorters ? : array() as $s)
                if ($s->type[0] === "#")
                    $this->_highlight_tags[] = substr($s->type, 1);
        }
        return $this->_highlight_tags;
    }


    static function search_types($me) {
        global $Conf;
        $tOpt = array();
        if ($me->isPC && $Conf->can_pc_see_all_submissions())
            $tOpt["act"] = "Active papers";
        if ($me->isPC)
            $tOpt["s"] = "Submitted papers";
        if ($me->isPC && $Conf->timePCViewDecision(false) && $Conf->has_any_accepts())
            $tOpt["acc"] = "Accepted papers";
        if ($me->privChair)
            $tOpt["all"] = "All papers";
        if ($me->privChair && !$Conf->can_pc_see_all_submissions()
            && defval($_REQUEST, "t") === "act")
            $tOpt["act"] = "Active papers";
        if ($me->is_reviewer())
            $tOpt["r"] = "Your reviews";
        if ($me->has_outstanding_review()
            || ($me->is_reviewer() && defval($_REQUEST, "t") === "rout"))
            $tOpt["rout"] = "Your incomplete reviews";
        if ($me->isPC)
            $tOpt["req"] = "Your review requests";
        if ($me->isPC && $Conf->has_any_lead_or_shepherd()
            && $me->is_discussion_lead())
            $tOpt["lead"] = "Your discussion leads";
        if ($me->isPC && $Conf->has_any_manager()
            && ($me->privChair || $me->is_manager()))
            $tOpt["manager"] = "Papers you administer";
        if ($me->is_author())
            $tOpt["a"] = "Your submissions";
        return $tOpt;
    }

    static function manager_search_types($me) {
        global $Conf;
        if ($me->privChair) {
            if ($Conf->has_any_manager())
                $tOpt = array("manager" => "Papers you administer",
                              "unm" => "Unmanaged submissions",
                              "s" => "All submissions");
            else
                $tOpt = array("s" => "Submitted papers");
            $tOpt["acc"] = "Accepted papers";
            $tOpt["und"] = "Undecided papers";
            $tOpt["all"] = "All papers";
        } else
            $tOpt = array("manager" => "Papers you administer");
        return $tOpt;
    }

    static function searchTypeSelector($tOpt, $type, $tabindex) {
        if (count($tOpt) > 1) {
            $sel_opt = array();
            foreach ($tOpt as $k => $v) {
                if (count($sel_opt) && $k === "a")
                    $sel_opt["xxxa"] = null;
                if (count($sel_opt) && ($k === "lead" || $k === "r") && !isset($sel_opt["xxxa"]))
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

    private static function simple_search_completion($prefix, $map, $flags = 0) {
        $x = array();
        foreach ($map as $id => $str) {
            $match = null;
            foreach (preg_split(',[^a-z0-9_]+,', strtolower($str)) as $word)
                if ($word !== ""
                    && ($m = Text::simple_search($word, $map, $flags))
                    && isset($m[$id]) && count($m) == 1
                    && !Text::is_boring_word($word)) {
                    $match = $word;
                    break;
                }
            $x[] = $prefix . ($match ? : "\"$str\"");
        }
        return $x;
    }

    function search_completion($category = "") {
        global $Conf, $Me;
        $res = array();

        if ($this->amPC && (!$category || $category === "ss")) {
            foreach ($Conf->settingTexts as $k => $v)
                if (substr($k, 0, 3) == "ss:" && ($v = json_decode($v)))
                    $res[] = $k;
        }

        array_push($res, "has:submission", "has:abstract", "has:finalcopy");
        if ($this->amPC && $Conf->has_any_manager())
            $res[] = "has:admin";
        if ($this->amPC && $Conf->has_any_lead_or_shepherd())
            $res[] = "has:lead";
        if ($this->contact->can_view_some_decision()) {
            $res[] = "has:decision";
            if (!$category || $category === "dec") {
                $res[] = array("pri" => -1, "nosort" => true, "i" => array("dec:any", "dec:none", "dec:yes", "dec:no"));
                $dm = $Conf->decision_map();
                unset($dm[0]);
                $res = array_merge($res, self::simple_search_completion("dec:", $dm, Text::SEARCH_UNPRIVILEGE_EXACT));
            }
        }
        if ($this->amPC || $this->contact->can_view_some_decision())
            $res[] = "has:shepherd";
        if ($this->contact->can_view_some_review())
            array_push($res, "has:re", "has:cre", "has:ire", "has:pre", "has:comment", "has:aucomment");
        if ($this->contact->is_reviewer())
            array_push($res, "has:primary", "has:secondary", "has:external");
        foreach ($Conf->resp_round_list() as $i => $rname) {
            if (!in_array("has:response", $res))
                $res[] = "has:response";
            if ($i)
                $res[] = "has:{$rname}response";
        }
        if ($this->contact->can_view_some_draft_response())
            foreach ($Conf->resp_round_list() as $i => $rname) {
                if (!in_array("has:draftresponse", $res))
                    $res[] = "has:draftresponse";
                if ($i)
                    $res[] = "has:draft{$rname}response";
            }
        foreach (PaperOption::option_list() as $o)
            if ($this->contact->can_view_some_paper_option($o))
                array_push($res, "has:{$o->abbr}", "opt:{$o->abbr}");
        if ($this->contact->is_reviewer() && $Conf->has_rounds()
            && (!$category || $category === "round")) {
            $res[] = array("pri" => -1, "nosort" => true, "i" => array("round:any", "round:none"));
            $rlist = array();
            foreach ($Conf->round_list() as $rnum => $round)
                if ($rnum && $round !== ";")
                    $rlist[$rnum] = $round;
            $res = array_merge($res, self::simple_search_completion("round:", $rlist));
        }
        if ($Conf->has_topics() && (!$category || $category === "topic")) {
            foreach ($Conf->topic_map() as $tname)
                $res[] = "topic:\"{$tname}\"";
        }
        if (!$category || $category === "style") {
            $res[] = array("pri" => -1, "nosort" => true, "i" => array("style:any", "style:none", "color:any", "color:none"));
            foreach (explode("|", TagInfo::BASIC_COLORS) as $t)
                array_push($res, "style:$t", "color:$t");
        }
        if (!$category || $category === "show" || $category === "hide") {
            $cats = array();
            $pl = new PaperList(new PaperSearch($Me, ""));
            foreach (PaperColumn::$by_name as $c)
                if (($cat = $c->completion_name())
                    && $c->prepare($pl, PaperColumn::PREP_COMPLETION))
                    $cats[$cat] = true;
            foreach (PaperColumn::$factories as $f) {
                foreach ($f[1]->completion_instances() as $c)
                    if (($cat = $c->completion_name())
                        && $c->prepare($pl, PaperColumn::PREP_COMPLETION))
                        $cats[$cat] = true;
            }
            foreach (array_keys($cats) as $cat)
                array_push($res, "show:$cat", "hide:$cat");
            array_push($res, "show:compact", "show:statistics", "show:rownumbers");
        }

        return $res;
    }
}
