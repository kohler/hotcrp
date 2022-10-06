<?php
// papersearch.php -- HotCRP class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class SearchWord {
    /** @var string */
    public $source;
    /** @var string */
    public $qword;
    /** @var string */
    public $word;
    /** @var bool */
    public $quoted;
    /** @var ?bool */
    public $kwexplicit;
    public $kwdef;
    /** @var ?string */
    public $compar;
    /** @var ?string */
    public $cword;
    /** @var ?int */
    public $pos1;
    /** @var ?int */
    public $pos1w;
    /** @var ?int */
    public $pos2;
    /** @param string $qword
     * @param string $source */
    function __construct($qword, $source) {
        $this->source = $source;
        $this->qword = $qword;
        $this->word = self::unquote($qword);
        $this->quoted = strlen($qword) !== strlen($this->word);
    }
    /** @param string $text
     * @return string */
    static function quote($text) {
        if ($text === ""
            || !preg_match('/\A[-A-Za-z0-9_.@\/]+\z/', $text)) {
            $text = "\"" . str_replace("\"", "\\\"", $text) . "\"";
        }
        return $text;
    }
    /** @param string $text
     * @return string */
    static function unquote($text) {
        if ($text !== ""
            && $text[0] === "\""
            && strpos($text, "\"", 1) === strlen($text) - 1) {
            return substr($text, 1, -1);
        } else {
            return $text;
        }
    }
    /** @return string */
    function source_html() {
        return htmlspecialchars($this->source);
    }
    /** @param ?string $cword */
    function set_compar_word($cword) {
        $cword = $cword ?? $this->word;
        if ($this->quoted) {
            $this->compar = "";
            $this->cword = $cword;
        } else {
            preg_match('/\A(?:[=!<>]=?|≠|≤|≥)?/', $cword, $m);
            $this->compar = $m[0] === "" ? "" : CountMatcher::canonical_relation($m[0]);
            $this->cword = ltrim(substr($cword, strlen($m[0])));
        }
    }
}

class SearchOperator {
    /** @var string */
    public $op;
    /** @var bool */
    public $unary;
    /** @var int */
    public $precedence;
    public $opinfo;

    static private $list = null;

    /** @param string $op
     * @param bool $unary
     * @param int $precedence */
    function __construct($op, $unary, $precedence, $opinfo = null) {
        $this->op = $op;
        $this->unary = $unary;
        $this->precedence = $precedence;
        $this->opinfo = $opinfo;
    }

    /** @return string */
    function unparse() {
        $x = strtoupper($this->op);
        return $this->opinfo === null ? $x : $x . ":" . $this->opinfo;
    }

    /** @return ?SearchOperator */
    static function get($name) {
        if (!self::$list) {
            self::$list["("] = new SearchOperator("(", true, 0);
            self::$list[")"] = new SearchOperator(")", true, 0);
            self::$list["NOT"] = new SearchOperator("not", true, 8);
            self::$list["-"] = new SearchOperator("not", true, 8);
            self::$list["!"] = new SearchOperator("not", true, 8);
            self::$list["+"] = new SearchOperator("+", true, 8);
            self::$list["SPACE"] = new SearchOperator("space", false, 7);
            self::$list["AND"] = new SearchOperator("and", false, 6);
            self::$list["XOR"] = new SearchOperator("xor", false, 5);
            self::$list["OR"] = new SearchOperator("or", false, 4);
            self::$list["SPACEOR"] = new SearchOperator("or", false, 3);
            self::$list["THEN"] = new SearchOperator("then", false, 2);
            self::$list["HIGHLIGHT"] = new SearchOperator("highlight", false, 1, "");
        }
        return self::$list[$name] ?? null;
    }
}

class SearchScope {
    /** @var ?SearchOperator */
    public $op;
    /** @var ?SearchTerm */
    public $leftqe;
    /** @var int */
    public $pos1;
    /** @var int */
    public $pos2;
    /** @var ?SearchScope */
    public $next;
    /** @var ?string */
    public $defkw;
    /** @var ?int */
    public $defkw_pos1;
    /** @var ?SearchScope */
    public $defkw_scope;
    /** @var bool */
    public $defkw_error = false;

    /** @param ?SearchOperator $op
     * @param ?SearchTerm $leftqe
     * @param int $pos1
     * @param int $pos2
     * @param ?SearchScope $next */
    function __construct($op, $leftqe, $pos1, $pos2, $next) {
        $this->op = $op;
        $this->leftqe = $leftqe;
        $this->pos1 = $pos1;
        $this->pos2 = $pos2;
        if (($this->next = $next)) {
            $this->defkw = $next->defkw;
            $this->defkw_pos1 = $next->defkw_pos1;
            $this->defkw_scope = $next->defkw_scope;
        }
    }
    /** @param ?SearchTerm $curqe
     * @return array{?SearchTerm,SearchScope} */
    function pop($curqe) {
        assert(!!$this->op);
        if ($curqe) {
            if ($this->leftqe) {
                $curqe = SearchTerm::combine($this->op, $this->leftqe, $curqe);
            } else if ($this->op->op !== "+" && $this->op->op !== "(") {
                $curqe = SearchTerm::combine($this->op, $curqe);
            }
            $curqe->apply_strspan($this->pos1, $this->pos2);
        } else {
            $curqe = $this->leftqe;
        }
        return [$curqe, $this->next];
    }
}

class CanonicalizeScope {
    /** @var SearchOperator */
    public $op;
    /** @var list<string> */
    public $qe;

    /** @param SearchOperator $op
     * @param list<string> $qe */
    function __construct($op, $qe) {
        $this->op = $op;
        $this->qe = $qe;
    }
}

class SearchQueryInfo {
    /** @var PaperSearch
     * @readonly */
    public $srch;
    /** @var array<string,?list<string>> */
    public $tables = [];
    /** @var array<string,string> */
    public $columns = [];
    /** @var array<string,mixed> */
    public $query_options = [];
    /** @var int */
    public $depth = 0;
    private $_has_my_review = false;
    private $_has_review_signatures = false;
    /** @var list<ReviewField> */
    private $_review_scores;

    function __construct(PaperSearch $srch) {
        $this->srch = $srch;
        if (!$srch->user->allow_administer_all()) {
            $this->add_reviewer_columns();
        }
        $this->tables["Paper"] = [];
    }
    /** @param string $table
     * @param list<string> $joiner
     * @param bool $required
     * @return ?string */
    function try_add_table($table, $joiner, $required = false) {
        // All added tables must match at most one Paper row each,
        // except MyReviews.
        if (str_ends_with($table, "_")) {
            $table .= count($this->tables);
        }
        if (!isset($this->tables[$table])) {
            if (!$required && count($this->tables) > 32) {
                return null;
            }
            $this->tables[$table] = $joiner;
        } else if ($joiner[0] === "join") {
            $this->tables[$table][0] = "join";
        }
        return $table;
    }
    /** @param string $table
     * @param list<string> $joiner
     * @return string */
    function add_table($table, $joiner = null) {
        return $this->try_add_table($table, $joiner, true);
    }
    function add_column($name, $expr) {
        assert(!isset($this->columns[$name]) || $this->columns[$name] === $expr);
        $this->columns[$name] = $expr;
    }
    /** @return ?string */
    function conflict_table(Contact $user) {
        if ($user->contactXid > 0) {
            return $this->add_table("PaperConflict{$user->contactXid}", ["left join", "PaperConflict", "{}.contactId={$user->contactXid}"]);
        } else {
            return null;
        }
    }
    function add_options_columns() {
        $this->columns["optionIds"] = "coalesce((select group_concat(PaperOption.optionId, '#', value) from PaperOption force index (primary) where paperId=Paper.paperId), '')";
    }
    function add_topics_columns() {
        if ($this->srch->conf->has_topics()) {
            $this->columns["topicIds"] = "coalesce((select group_concat(topicId) from PaperTopic force index (primary) where PaperTopic.paperId=Paper.paperId), '')";
        }
    }
    function add_reviewer_columns() {
        $this->_has_my_review = true;
    }
    function add_review_signature_columns() {
        $this->_has_review_signatures = true;
    }
    function finish_reviewer_columns() {
        $user = $this->srch->user;
        if ($this->_has_my_review) {
            $ct = $this->conflict_table($user);
            $this->add_column("conflictType", $ct ? "{$ct}.conflictType" : "null");
        }
        if ($this->_has_review_signatures) {
            $this->add_column("reviewSignatures", "coalesce((select " . ReviewInfo::review_signature_sql($user->conf, $this->_review_scores) . " from PaperReview r force index (primary) where r.paperId=Paper.paperId), '')");
        } else if ($this->_has_my_review) {
            $act_reviewer_sql = $user->act_reviewer_sql("PaperReview");
            if ($act_reviewer_sql === "false") {
                $this->add_column("myReviewPermissions", "''");
            } else if (isset($this->tables["MyReviews"])) {
                $this->add_column("myReviewPermissions", "coalesce(" . PaperInfo::my_review_permissions_sql("MyReviews.") . ", '')");
            } else {
                $this->add_column("myReviewPermissions", "coalesce((select " . PaperInfo::my_review_permissions_sql() . " from PaperReview force index (primary) where PaperReview.paperId=Paper.paperId and $act_reviewer_sql group by paperId), '')");
            }
        }
    }
    /** @param ReviewField $f */
    function add_score_column($f) {
        if (is_string($f)) {
            error_log("add_score_column error: " . debug_string_backtrace());
            $f = $this->srch->conf->review_field($f);
        }
        $this->add_review_signature_columns();
        if ($f && $f->main_storage && !in_array($f, $this->_review_scores ?? [])) {
            $this->_review_scores[] = $f;
        }
    }
    function add_review_word_count_columns() {
        $this->add_review_signature_columns();
        if (!isset($this->columns["reviewWordCountSignature"])) {
            $this->add_column("reviewWordCountSignature", "coalesce((select group_concat(coalesce(reviewWordCount,'.') order by reviewId) from PaperReview force index (primary) where PaperReview.paperId=Paper.paperId), '')");
        }
    }
    function add_allConflictType_column() {
        if (!isset($this->columns["allConflictType"])) {
            $this->add_column("allConflictType", "coalesce((select group_concat(contactId, ' ', conflictType) from PaperConflict force index (paperId) where PaperConflict.paperId=Paper.paperId), '')");
        }
    }
}

class PaperSearch extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var string
     * @readonly */
    public $q;
    /** @var string
     * @readonly */
    private $_qt;
    /** @var ?Contact
     * @readonly */
    private $_reviewer_user;

    /** @var ?SearchTerm */
    private $_qe;
    /** @var Limit_SearchTerm
     * @readonly */
    private $_limit_qe;
    /** @var bool */
    private $_limit_explicit = false;

    /** @var bool
     * @readonly */
    public $expand_automatic = false;
    /** @var bool */
    private $_allow_deleted = false;
    /** @var ?string */
    private $_urlbase;
    /** @var ?string
     * @readonly */
    private $_default_sort; // XXX should be used more often

    /** @var ?array<string,TextPregexes> */
    private $_match_preg;
    /** @var ?string */
    private $_match_preg_query;
    /** @var ?list<ContactSearch> */
    private $_contact_searches;
    /** @var list<int> */
    private $_matches;
    /** @var ?array<int,int> */
    private $_then_map;
    /** @var ?array<int,list<string>> */
    private $_highlight_map;

    static public $search_type_names = [
        "a" => "Your submissions",
        "acc" => "Accepted",
        "act" => "Active",
        "admin" => "Submissions you administer",
        "all" => "All",
        "alladmin" => "Submissions you’re allowed to administer",
        "lead" => "Your discussion leads",
        "r" => "Your reviews",
        "reviewable" => "Reviewable",
        "req" => "Your review requests",
        "rout" => "Your incomplete reviews",
        "s" => "Submitted",
        "undecided" => "Undecided",
        "viewable" => "Submissions you can view"
    ];

    static private $ss_recursion = 0;

    const LFLAG_SUBMITTED = 1;
    const LFLAG_ACTIVE = 2;


    // NB: `$options` can come from an unsanitized user request.
    /** @param string|array|Qrequest $options */
    function __construct(Contact $user, $options) {
        if (is_string($options)) {
            $options = ["q" => $options];
        }

        // contact facts
        $this->conf = $user->conf;
        $this->user = $user;

        // query fields
        // NB: If a complex query field, e.g., "re", "tag", or "option", is
        // default, then it must be the only default or query construction
        // will break.
        $this->_qt = self::_canonical_qt($options["qt"] ?? null);

        // the query itself
        $this->q = trim($options["q"] ?? "");
        $this->_default_sort = $options["sort"] ?? null;
        $this->set_want_ftext(true);

        // reviewer
        if (($reviewer = $options["reviewer"] ?? null)) {
            $ruser = null;
            if (is_string($reviewer)) {
                if (strcasecmp($reviewer, $user->email) === 0) {
                    $ruser = $user;
                } else if ($user->can_view_pc()) {
                    $ruser = $this->conf->pc_member_by_email($reviewer);
                }
            } else if (is_object($reviewer) && ($reviewer instanceof Contact)) {
                $ruser = $reviewer;
            }
            if ($ruser && $ruser !== $this->user) {
                assert($ruser->contactId > 0);
                $this->_reviewer_user = $ruser;
            }
        }

        // paper selection
        $limit = self::canonical_limit($options["t"] ?? "") ?? "";
        if ($limit === "") {
            // Empty limit should be the plausible limit for a default search,
            // as in entering text into a quicksearch box.
            if ($user->privChair
                && ($user->is_root_user() || $this->conf->time_edit_paper())) {
                $limit = "all";
            } else if ($user->isPC) {
                $limit = $this->conf->time_pc_view_active_submissions() ? "act" : "s";
            } else if (!$user->is_reviewer()) {
                $limit = "a";
            } else if (!$user->is_author()) {
                $limit = "r";
            } else {
                $limit = "ar";
            }
        }
        $lword = new SearchWord($limit, "in:{$limit}");
        $this->_limit_qe = Limit_SearchTerm::parse($limit, $lword, $this);
    }

    private function clear_compilation() {
        $this->clear_messages();
        $this->_qe = null;
        $this->_match_preg = null;
        $this->_match_preg_query = null;
        $this->_contact_searches = null;
        $this->_matches = null;
    }

    /** @param bool $x
     * @return $this */
    function set_allow_deleted($x) {
        assert($this->_qe === null);
        $this->_allow_deleted = $x;
        return $this;
    }

    /** @param string $base
     * @param array $args
     * @return $this */
    function set_urlbase($base, $args = []) {
        assert($this->_urlbase === null);
        if (!isset($args["t"])) {
            $args["t"] = $this->_limit_qe->named_limit;
        }
        if (!isset($args["qt"]) && $this->_qt !== "n") {
            $args["qt"] = $this->_qt;
        }
        if (!isset($args["reviewer"])
            && $this->_reviewer_user
            && $this->_reviewer_user->contactId !== $this->user->contactXid) {
            $args["reviewer"] = $this->_reviewer_user->email;
        }
        $this->_urlbase = $this->conf->hoturl_raw($base, $args, Conf::HOTURL_SITEREL);
        return $this;
    }

    /** @param bool $x
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_expand_automatic($x) {
        $this->_qe === null || $this->clear_compilation();
        $this->expand_automatic = $x;
        return $this;
    }

    /** @return string */
    function limit() {
        return $this->_limit_qe->limit;
    }
    /** @return bool */
    function limit_submitted() {
        return ($this->_limit_qe->lflag & self::LFLAG_SUBMITTED) !== 0;
    }
    /** @return bool */
    function limit_author() {
        return $this->_limit_qe->limit === "a";
    }
    /** @return bool */
    function show_submitted_status() {
        return in_array($this->_limit_qe->limit, ["a", "act", "all"])
            && $this->q !== "re:me";
    }
    /** @return bool */
    function limit_accepted() {
        return $this->_limit_qe->limit === "acc";
    }
    /** @return bool */
    function limit_explicit() {
        return $this->_limit_explicit;
    }
    function apply_limit(Limit_SearchTerm $limit) {
        if (!$this->_limit_explicit) {
            $this->_limit_qe->set_limit($limit->named_limit);
            $this->_limit_explicit = true;
        }
    }

    /** @return Contact */
    function reviewer_user() {
        return $this->_reviewer_user ?? $this->user;
    }


    /** @return MessageSet */
    function message_set() {
        return $this;
    }

    /** @return bool */
    function has_problem() {
        $this->_qe || $this->term();
        return parent::has_problem();
    }

    /** @return list<MessageItem> */
    function message_list() {
        $this->_qe || $this->term();
        return parent::message_list();
    }

    /** @param string $message
     * @return MessageItem */
    function warning($message) {
        return $this->warning_at(null, $message);
    }

    /** @param SearchWord $sw
     * @param string $message
     * @return MessageItem */
    function lwarning($sw, $message) {
        $mi = $this->warning($message);
        $mi->pos1 = $sw->pos1;
        $mi->pos2 = $sw->pos2;
        $mi->context = $this->q;
        return $mi;
    }


    /** @return string */
    function urlbase() {
        if ($this->_urlbase === null) {
            $this->set_urlbase("search");
        }
        return $this->_urlbase;
    }


    // PARSING
    // Transforms a search string into an expression object, possibly
    // including "and", "or", and "not" expressions (which point at other
    // expressions).

    /** @return ContactSearch */
    private function _find_contact_search($type, $word) {
        foreach ($this->_contact_searches ?? [] as $cs) {
            if ($cs->type === $type && $cs->text === $word)
                return $cs;
        }
        $this->_contact_searches[] = $cs = new ContactSearch($type, $word, $this->user);
        return $cs;
    }
    /** @return ContactSearch */
    private function _contact_search($type, $word, $quoted, $pc_only) {
        $xword = $word;
        if ($quoted === null) {
            $word = SearchWord::unquote($word);
            $quoted = strlen($word) !== strlen($xword);
        }
        $type |= ($pc_only ? ContactSearch::F_PC : 0)
            | ($quoted ? ContactSearch::F_QUOTED : 0)
            | (!$quoted && $this->user->isPC ? ContactSearch::F_TAG : 0);
        $cs = $this->_find_contact_search($type, $word);
        if ($cs->warn_html) {
            $this->warning("<5>{$cs->warn_html}");
        }
        return $cs;
    }
    /** @param string $word
     * @param ?bool $quoted
     * @param bool $pc_only
     * @return list<int> */
    function matching_uids($word, $quoted, $pc_only) {
        $scm = $this->_contact_search(ContactSearch::F_USER, $word, $quoted, $pc_only);
        return $scm->user_ids();
    }
    /** @param string $word
     * @param bool $quoted
     * @param bool $pc_only
     * @return list<Contact> */
    function matching_contacts($word, $quoted, $pc_only) {
        $scm = $this->_contact_search(ContactSearch::F_USER, $word, $quoted, $pc_only);
        return $scm->users();
    }
    /** @param string $word
     * @param bool $quoted
     * @param bool $pc_only
     * @return ?list<int> */
    function matching_special_uids($word, $quoted, $pc_only) {
        $scm = $this->_contact_search(0, $word, $quoted, $pc_only);
        return $scm->has_error() ? null : $scm->user_ids();
    }

    static function status_field_matcher(Conf $conf, $word, $quoted = null) {
        if (strlen($word) >= 3
            && ($k = Text::simple_search($word, ["w0" => "withdrawn", "s0" => "submitted", "s1" => "ready", "s2" => "complete", "u0" => "in progress", "u1" => "unsubmitted", "u2" => "not ready", "u3" => "incomplete", "u4" => "draft", "a0" => "active", "x0" => "no submission"]))) {
            $k = array_map(function ($x) { return $x[0]; }, array_keys($k));
            $k = array_unique($k);
            if (count($k) === 1) {
                if ($k[0] === "w") {
                    return ["timeWithdrawn", ">0"];
                } else if ($k[0] === "s") {
                    return ["timeSubmitted", ">0"];
                } else if ($k[0] === "u") {
                    return ["timeSubmitted", "<=0", "timeWithdrawn", "<=0"];
                } else if ($k[0] === "x") {
                    return ["timeSubmitted", "<=0", "timeWithdrawn", "<=0", "paperStorageId", "<=1"];
                } else {
                    return ["timeWithdrawn", "<=0"];
                }
            }
        }
        return ["outcome", $conf->decision_set()->matchexpr($word)];
    }

    /** @return SearchTerm */
    static function parse_has($word, SearchWord $sword, PaperSearch $srch) {
        $kword = $word;
        $kwdef = $srch->conf->search_keyword($kword, $srch->user);
        if (!$kwdef && ($kword = strtolower($word)) !== $word) {
            $kwdef = $srch->conf->search_keyword($kword, $srch->user);
        }
        if ($kwdef) {
            if ($kwdef->parse_has_function ?? null) {
                $qe = call_user_func($kwdef->parse_has_function, $word, $sword, $srch);
            } else if ($kwdef->has ?? null) {
                $sword2 = new SearchWord($kwdef->has, $sword->source);
                $sword2->kwexplicit = true;
                $sword2->kwdef = $kwdef;
                $sword2->pos1 = $sword->pos1;
                $sword2->pos1w = $sword->pos1w;
                $sword2->pos2 = $sword->pos2;
                $qe = call_user_func($kwdef->parse_function, $kwdef->has, $sword2, $srch);
            } else {
                $qe = null;
            }
            if ($qe) {
                return $qe;
            }
        }
        $srch->lwarning($sword, "<0>Unknown search ‘has:{$word}’ won’t match anything");
        return new False_SearchTerm;
    }

    /** @param string $word
     * @return ?string */
    private function _expand_saved_search($word) {
        $sj = $this->conf->setting_json("ss:$word");
        if ($sj && is_object($sj) && isset($sj->q)) {
            $q = $sj->q;
            if (isset($sj->t) && $sj->t !== "" && $sj->t !== "s") {
                $q = "($q) in:{$sj->t}";
            }
            return $q;
        } else {
            return null;
        }
    }

    /** @param string $word
     * @return ?SearchTerm */
    static function parse_saved_search($word, SearchWord $sword, PaperSearch $srch) {
        if (!$srch->user->isPC) {
            return null;
        }
        $qe = null;
        ++self::$ss_recursion;
        if (!$srch->conf->setting_data("ss:$word")) {
            $srch->lwarning($sword, "<0>Saved search not found");
        } else if (self::$ss_recursion > 10) {
            $srch->lwarning($sword, "<0>Saved search defined in terms of itself");
        } else if (($nextq = $srch->_expand_saved_search($word))) {
            if (($qe = $srch->_search_expression($nextq))) {
                $qe->set_strspan_owner($nextq);
            }
        } else {
            $srch->lwarning($sword, "<0>Saved search defined incorrectly");
        }
        --self::$ss_recursion;
        return $qe ?? new False_SearchTerm;
    }

    /** @param ?string $keyword
     * @param ?SearchScope $scope */
    private function _search_keyword(&$qt, SearchWord $sword, $keyword, $scope) {
        $word = $sword->word;
        $sword->kwexplicit = !!$scope;
        $lkeyword = $keyword ?? $scope->defkw;
        $sword->kwdef = $this->conf->search_keyword($lkeyword, $this->user);
        if ($sword->kwdef && ($sword->kwdef->parse_function ?? null)) {
            $qx = call_user_func($sword->kwdef->parse_function, $word, $sword, $this);
            if ($qx && !is_array($qx)) {
                $qt[] = $qx;
            } else if ($qx) {
                $qt = array_merge($qt, $qx);
            }
        } else if ($keyword !== null) {
            $sword->pos2 = $sword->pos1 + strlen($keyword) + 1;
            $this->lwarning($sword, "<0>Unknown search ‘{$lkeyword}:’ won’t match anything");
        } else if (!$scope->defkw_scope->defkw_error) {
            $sword->pos1 = $scope->defkw_scope->defkw_pos1;
            $sword->pos2 = $sword->pos1 + strlen($scope->defkw) + 1;
            $this->lwarning($sword, "<0>Unknown search ‘{$lkeyword}:’ won’t match anything");
            $scope->defkw_scope->defkw_error = true;
        }
    }

    /** @param string $word
     * @param string $defkw
     * @return array{string,string} */
    static private function _search_word_breakdown($word, $defkw = "") {
        $ch = substr($word, 0, 1);
        if ($ch !== ""
            && $defkw === ""
            && (ctype_digit($ch) || ($ch === "#" && ctype_digit((string) substr($word, 1, 1))))
            && preg_match('/\A(?:#?\d+(?:(?:-|–|—)#?\d+)?(?:\s*,\s*|\z))+\z/s', $word)) {
            return ["=", $word];
        } else if ($ch === "#"
                   && $defkw === "") {
            return ["#", substr($word, 1)];
        } else if (preg_match('/\A([-_.a-zA-Z0-9]+|"[^"]")((?:[=!<>]=?|≠|≤|≥)[^:]+|:.*)\z/s', $word, $m)) {
            return [$m[1], $m[2]];
        } else {
            return ["", $word];
        }
    }

    /** @return list<string> */
    private function _qt_fields() {
        if ($this->_qt === "n") {
            return $this->user->can_view_some_authors() ? ["ti", "ab", "au"] : ["ti", "ab"];
        } else {
            return [$this->_qt];
        }
    }

    /** @param string $source
     * @param SearchScope $scope
     * @param int $pos1
     * @param int $pos2
     * @param int $dpos
     * @return ?SearchTerm */
    private function _search_word($source, $scope, $pos1, $pos2, $dpos) {
        $word = $source;
        $wordbrk = self::_search_word_breakdown($word, $scope->defkw ?? "");
        $keyword = null;

        if ($wordbrk[0] === "=") {
            // paper numbers
            $st = new PaperID_SearchTerm;
            while (preg_match('/\A#?(\d+)(?:(?:-|–|—)#?(\d+))?\s*,?\s*(.*)\z/s', $word, $m)) {
                $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
                $st->add_range(intval($m[1]), intval($m[2]));
                $word = $m[3];
            }
            return $st;
        } else if ($wordbrk[0] === "#") {
            // `#TAG`
            $ignored = $this->swap_ignore_messages(true);
            $qe = $this->_search_word("hashtag:{$wordbrk[1]}", $scope, $pos1, $pos2, $dpos - 7);
            $this->swap_ignore_messages($ignored);
            if (!($qe instanceof False_SearchTerm)) {
                return $qe;
            }
        } else if ($wordbrk[0] !== "") {
            // `keyword:word` or (potentially) `keyword>word`
            if ($wordbrk[1][0] === ":") {
                $keyword = $wordbrk[0];
                $word = $wordbrk[1];
                $pos = 1;
                while ($pos < strlen($word) && ctype_space($word[$pos])) {
                    ++$pos;
                }
                $word = substr($word, $pos);
                $dpos += strlen($keyword) + $pos;
            } else {
                // Allow searches like "ovemer>2"; parse as "ovemer:>2".
                $ignored = $this->swap_ignore_messages(true);
                $qe = $this->_search_word("{$wordbrk[0]}:{$wordbrk[1]}", $scope, $pos1, $pos2, $dpos - 1);
                $this->swap_ignore_messages($ignored);
                if ($qe instanceof False_SearchTerm) {
                    if ($qe->score_warning) {
                        $this->message_set()->append_item($qe->score_warning);
                        return $qe;
                    }
                } else {
                    return $qe;
                }
            }
        }

        if ($keyword !== null && str_starts_with($keyword, '"')) {
            $keyword = trim(substr($keyword, 1, strlen($keyword) - 2));
        }

        $qt = [];
        $sword = new SearchWord($word, $source);
        $sword->pos1 = $pos1;
        $sword->pos1w = $pos1 + $dpos;
        $sword->pos2 = $pos2;
        if ($keyword !== null || $scope->defkw !== null) {
            $this->_search_keyword($qt, $sword, $keyword, $scope);
        } else {
            // Special-case unquoted "*", "ANY", "ALL", "NONE", "".
            if ($word === "*" || $word === "ANY" || $word === "ALL"
                || $word === "") {
                return new True_SearchTerm;
            } else if ($word === "NONE") {
                return new False_SearchTerm;
            }
            // Otherwise check known keywords.
            foreach ($this->_qt_fields() as $kw) {
                $this->_search_keyword($qt, $sword, $kw, null);
            }
        }
        return SearchTerm::combine("or", ...$qt);
    }

    /** @param string $str
     * @return string */
    static function escape_word($str) {
        $pos = SearchSplitter::span_balanced_parens($str, 0, null, true);
        if ($pos === strlen($str)) {
            return $str;
        } else {
            return "\"" . str_replace("\"", "\\\"", $str) . "\"";
        }
    }

    /** @return ?SearchOperator */
    static private function _shift_keyword(SearchSplitter $splitter, $curqe) {
        if (!$splitter->match('/\G(?:[-+!()]|(?:AND|and|OR|or|NOT|not|XOR|xor|THEN|then|HIGHLIGHT(?::\w+)?)(?=[\s\(]))/s', $m)) {
            return null;
        }
        $op = SearchOperator::get(strtoupper($m[0]));
        if (!$op) {
            $colon = strpos($m[0], ":");
            $op = clone SearchOperator::get(strtoupper(substr($m[0], 0, $colon)));
            $op->opinfo = substr($m[0], $colon + 1);
        }
        if ($curqe && $op->unary) {
            return null;
        }
        $splitter->shift_past($m[0]);
        return $op;
    }

    /** @return string */
    static private function _shift_word(SearchSplitter $splitter, Conf $conf) {
        if (($t = $splitter->shift_keyword()) !== "") {
            $kwx = $t[0] === '"' ? substr($t, 1, -2) : substr($t, 0, -1);
            $kwd = $conf->search_keyword($kwx);
            if ($kwd && ($kwd->allow_parens ?? false)) {
                return $t . $splitter->shift_balanced_parens(null, true);
            }
        }
        return $t . $splitter->shift("()");
    }

    /** @param string $str
     * @return ?SearchTerm */
    private function _search_expression($str) {
        $scope = new SearchScope(null, null, 0, strlen($str), null);
        $next_defkw = null;
        $parens = 0;
        $curqe = null;
        $splitter = new SearchSplitter($str);

        while (!$splitter->is_empty()) {
            $pos1 = $splitter->pos;
            $op = self::_shift_keyword($splitter, $curqe);
            $pos2 = $splitter->last_pos;
            if ($curqe && !$op) {
                $op = SearchOperator::get("SPACE");
            }
            if (!$curqe && $op && $op->op === "highlight") {
                $curqe = new True_SearchTerm;
                $curqe->set_strspan($pos1, $pos1);
            }

            if (!$op) {
                $pos1 = $splitter->pos;
                $word = self::_shift_word($splitter, $this->conf);
                $pos2 = $splitter->last_pos;
                // Bare any-case "all", "any", "none" are treated as keywords.
                if (!$curqe
                    && (!$scope->op || $scope->op->precedence <= 2)
                    && ($uword = strtoupper($word))
                    && ($uword === "ALL" || $uword === "ANY" || $uword === "NONE")
                    && $splitter->match('/\G(?:|(?:THEN|then|HIGHLIGHT(?::\w+)?)(?:\s|\().*)\z/')) {
                    $word = $uword;
                }
                if ($word === "") {
                    error_log("problem: no op, str “{$str}” in “{$this->q}”");
                    break;
                }
                // Search like "ti:(foo OR bar)" adds a default keyword.
                if ($word[strlen($word) - 1] === ":"
                    && preg_match('/\A(?:[-_.a-zA-Z0-9]+:|"[^"]+":)\z/s', $word)
                    && $splitter->starts_with("(")) {
                    $next_defkw = [substr($word, 0, strlen($word) - 1), $pos1];
                } else {
                    // The heart of the matter.
                    $curqe = $this->_search_word($word, $scope, $pos1, $pos2, 0);
                    if (!$curqe->is_uninteresting()) {
                        $curqe->set_strspan($pos1, $pos2);
                    }
                }
            } else if ($op->op === ")") {
                while ($scope->op && $scope->op->op !== "(") {
                    list($curqe, $scope) = $scope->pop($curqe);
                }
                if ($scope->op) {
                    $scope->pos2 = $pos1;
                    list($curqe, $scope) = $scope->pop($curqe);
                    --$parens;
                }
            } else if ($op->op === "(") {
                assert(!$curqe);
                $scope = new SearchScope($op, null, $pos1, $pos2, $scope);
                if ($next_defkw) {
                    $scope->defkw = $next_defkw[0];
                    $scope->defkw_pos1 = $next_defkw[1];
                    $scope->defkw_scope = $scope;
                    $next_defkw = null;
                }
                ++$parens;
            } else if ($op->unary || $curqe) {
                $end_precedence = $op->precedence - ($op->precedence <= 1 ? 1 : 0);
                while ($scope->op && $scope->op->precedence > $end_precedence) {
                    list($curqe, $scope) = $scope->pop($curqe);
                }
                $scope = new SearchScope($op, $curqe, $pos1, $pos2, $scope);
                $curqe = null;
            }
        }

        while ($scope->op) {
            list($curqe, $scope) = $scope->pop($curqe);
        }
        return $curqe;
    }


    static private function _canonical_qt($qt) {
        if (in_array($qt, ["ti", "ab", "au", "ac", "co", "re", "tag"])) {
            return $qt;
        } else {
            return "n";
        }
    }

    /** @param string $curqe
     * @param list<CanonicalizeScope> &$stack
     * @return ?string */
    static private function _pop_canonicalize_stack($curqe, &$stack) {
        $x = array_pop($stack);
        if ($curqe) {
            $x->qe[] = $curqe;
        }
        if (empty($x->qe)) {
            return null;
        } else if ($x->op->unary) {
            $qe = $x->qe[0];
            if ($x->op->op === "not") {
                if (preg_match('/\A(?:[(-]|NOT )/i', $qe)) {
                    $qe = "NOT $qe";
                } else {
                    $qe = "-$qe";
                }
            }
            return $qe;
        } else if (count($x->qe) === 1) {
            return $x->qe[0];
        } else if ($x->op->op === "space") {
            return "(" . join(" ", $x->qe) . ")";
        } else {
            return "(" . join(" " . $x->op->unparse() . " ", $x->qe) . ")";
        }
    }

    static private function _canonical_expression($str, $type, $qt, Conf $conf) {
        $str = trim((string) $str);
        if ($str === "") {
            return "";
        }

        $stack = [];
        '@phan-var list<CanonicalizeScope> $stack';
        $parens = 0;
        $defaultop = $type === "all" ? "SPACE" : "SPACEOR";
        $curqe = null;
        $splitter = new SearchSplitter($str);

        while (!$splitter->is_empty()) {
            $op = self::_shift_keyword($splitter, $curqe);
            if ($curqe && !$op) {
                $op = SearchOperator::get($parens ? "SPACE" : $defaultop);
            }
            if (!$op) {
                $curqe = self::_shift_word($splitter, $conf);
                if ($qt !== "n") {
                    $wordbrk = self::_search_word_breakdown($curqe, "");
                    if ($wordbrk[0] === "") {
                        $curqe = ($qt === "tag" ? "#" : "{$qt}:") . $curqe;
                    } else if ($wordbrk[1] === ":") {
                        $curqe .= $splitter->shift_balanced_parens();
                    }
                }
            } else if ($op->op === ")") {
                while (count($stack)
                       && $stack[count($stack) - 1]->op->op !== "(") {
                    $curqe = self::_pop_canonicalize_stack($curqe, $stack);
                }
                if (count($stack)) {
                    array_pop($stack);
                    --$parens;
                }
            } else if ($op->op === "(") {
                assert(!$curqe);
                $stack[] = new CanonicalizeScope($op, []);
                ++$parens;
            } else {
                $end_precedence = $op->precedence - ($op->precedence <= 1 ? 1 : 0);
                while (count($stack)
                       && $stack[count($stack) - 1]->op->precedence > $end_precedence) {
                    $curqe = self::_pop_canonicalize_stack($curqe, $stack);
                }
                $top = count($stack) ? $stack[count($stack) - 1] : null;
                if ($top && !$op->unary && $top->op->op === $op->op) {
                    $top->qe[] = $curqe;
                } else {
                    $stack[] = new CanonicalizeScope($op, [$curqe]);
                }
                $curqe = null;
            }
        }

        if ($type === "none") {
            array_unshift($stack, new CanonicalizeScope(SearchOperator::get("NOT"), []));
        }
        while (!empty($stack)) {
            $curqe = self::_pop_canonicalize_stack($curqe, $stack);
        }
        return $curqe;
    }

    /** @param ?string $qa
     * @param ?string $qo
     * @param ?string $qx
     * @param ?string $qt
     * @param ?string $t
     * @return string */
    static function canonical_query($qa, $qo, $qx, $qt, Conf $conf, $t = null) {
        $qt = self::_canonical_qt($qt);
        $x = [];
        if (($t ?? "") !== ""
            && ($t = self::long_canonical_limit($t)) !== null) {
            $qa = ($qa ?? "") !== "" ? "({$qa}) in:$t" : "in:$t";
        }
        if (($qa = self::_canonical_expression($qa, "all", $qt, $conf)) !== "") {
            $x[] = $qa;
        }
        if (($qo = self::_canonical_expression($qo, "any", $qt, $conf)) !== "") {
            $x[] = $qo;
        }
        if (($qx = self::_canonical_expression($qx, "none", $qt, $conf)) !== "") {
            $x[] = $qx;
        }
        if (count($x) == 1) {
            return preg_replace('/\A\((.*)\)\z/', '$1', $x[0]);
        } else {
            return join(" AND ", $x);
        }
    }


    // CLEANING
    // Clean an input expression series into clauses.  The basic purpose of
    // this step is to combine all paper numbers into a single group, and to
    // assign review adjustments (rates & rounds).


    // QUERY CONSTRUCTION
    // Build a database query corresponding to an expression.
    // The query may be liberal (returning more papers than actually match);
    // QUERY EVALUATION makes it precise.

    static function unusable_ratings(Contact $user) {
        if ($user->privChair || $user->conf->setting("pc_seeallrev")) {
            return [];
        }
        // This query should return those reviewIds whose ratings
        // are not visible to the current querier:
        // reviews by `$user` on papers with <=2 reviews and <=2 ratings
        $rateset = $user->conf->setting("rev_rating");
        if ($rateset == REV_RATINGS_PC) {
            $npr_constraint = "reviewType>" . REVIEW_EXTERNAL;
        } else {
            $npr_constraint = "true";
        }
        $result = $user->conf->qe("select r.reviewId,
            coalesce((select count(*) from ReviewRating force index (primary) where paperId=r.paperId),0) numRatings,
            coalesce((select count(*) from PaperReview r force index (primary) where paperId=r.paperId and reviewNeedsSubmit=0 and {$npr_constraint}),0) numReviews
            from PaperReview r
            join ReviewRating rr on (rr.paperId=r.paperId and rr.reviewId=r.reviewId)
            where r.contactId={$user->contactId}
            having numReviews<=2 and numRatings<=2");
        return Dbl::fetch_first_columns($result);
    }


    /** @param SearchTerm $qe */
    private function _add_deleted_papers($qe) {
        if ($qe->type === "or" || $qe->type === "then") {
            assert($qe instanceof Op_SearchTerm);
            foreach ($qe->child as $subt) {
                $this->_add_deleted_papers($subt);
            }
        } else if ($qe->type === "pn") {
            assert($qe instanceof PaperID_SearchTerm);
            foreach ($qe->paper_ids() ?? [] as $p) {
                if (array_search($p, $this->_matches) === false)
                    $this->_matches[] = (int) $p;
            }
        }
    }

    /** @param SearchTerm $qe */
    private function _check_missing_papers($qe) {
        $ps = [];
        if ($qe->type === "or" || $qe->type === "then") {
            assert($qe instanceof Op_SearchTerm);
            foreach ($qe->child as $subt) {
                $ps = array_merge($ps, $this->_check_missing_papers($subt));
            }
        } else if ($qe->type === "pn") {
            assert($qe instanceof PaperID_SearchTerm);
            foreach ($qe->ranges() as $r) {
                for ($p = $r[0]; $p < $r[1] && $r[4]; ++$p) {
                    if (array_search($p, $this->_matches) === false) {
                        $ps[] = $p;
                    }
                }
            }
        }
        return $ps;
    }


    // BASIC QUERY FUNCTION

    /** @return SearchTerm */
    function term() {
        if ($this->_qe === null) {
            if ($this->q === "re:me"
                && $this->_limit_qe->lflag === $this->_limit_qe->reviewer_lflag()) {
                $this->_qe = new Limit_SearchTerm($this->user, $this->user, "r", true);
            } else if (($qe = $this->_search_expression($this->q))) {
                $this->_qe = $qe;
            } else {
                $this->_qe = new True_SearchTerm;
            }

            // extract regular expressions
            $this->_qe->configure_search(true, $this);
        }
        return $this->_qe;
    }

    /** @return SearchTerm */
    function full_term() {
        assert($this->user->is_root_user());
        // returns SearchTerm that includes effect of the limit
        $this->_qe || $this->term();
        if ($this->_limit_qe->limit === "all") {
            return $this->_qe;
        } else {
            return SearchTerm::combine("and", $this->_limit_qe, $this->_qe);
        }
    }

    private function _prepare_result(SearchTerm $qe) {
        $sqi = new SearchQueryInfo($this);
        $sqi->add_column("paperId", "Paper.paperId");
        // always include columns needed by rights machinery
        $sqi->add_column("timeSubmitted", "Paper.timeSubmitted");
        $sqi->add_column("timeWithdrawn", "Paper.timeWithdrawn");
        $sqi->add_column("outcome", "Paper.outcome");
        $sqi->add_column("leadContactId", "Paper.leadContactId");
        $sqi->add_column("managerContactId", "Paper.managerContactId");
        if ($this->conf->submission_blindness() === Conf::BLIND_OPTIONAL) {
            $sqi->add_column("blind", "Paper.blind");
        }

        $filter = SearchTerm::andjoin_sqlexpr([
            $this->_limit_qe->sqlexpr($sqi), $qe->sqlexpr($sqi)
        ]);
        //Conf::msg_debugt($filter);
        if ($filter === "false") {
            return Dbl_Result::make_empty();
        }

        // add permissions tables and columns
        // XXX some of this should be shared with paperQuery
        if ($this->conf->rights_need_tags()
            || $this->conf->has_tracks() /* XXX probably only need check_track_view_sensitivity */
            || ($sqi->query_options["tags"] ?? false)
            || ($this->user->privChair
                && $this->conf->has_any_manager()
                && $this->conf->tags()->has_sitewide)) {
            $sqi->add_column("paperTags", "coalesce((select group_concat(' ', tag, '#', tagIndex separator '') from PaperTag force index (primary) where PaperTag.paperId=Paper.paperId), '')");
        }
        if ($sqi->query_options["reviewSignatures"] ?? false) {
            $sqi->add_review_signature_columns();
        }
        foreach ($sqi->query_options["scores"] ?? [] as $f) {
            $sqi->add_score_column($f);
        }
        if ($sqi->query_options["reviewWordCounts"] ?? false) {
            $sqi->add_review_word_count_columns();
        }
        if ($sqi->query_options["authorInformation"] ?? false) {
            $sqi->add_column("authorInformation", "Paper.authorInformation");
        }
        if ($sqi->query_options["pdfSize"] ?? false) {
            $sqi->add_column("size", "Paper.size");
        }

        // create query
        $sqi->finish_reviewer_columns();
        $q = "select ";
        foreach ($sqi->columns as $colname => $value) {
            $q .= $value . " " . $colname . ", ";
        }
        $q = substr($q, 0, strlen($q) - 2) . "\n    from ";
        foreach ($sqi->tables as $tabname => $value) {
            if (!$value) {
                $q .= $tabname;
            } else {
                $joiners = ["{$tabname}.paperId=Paper.paperId"];
                for ($i = 2; $i < count($value); ++$i) {
                    if ($value[$i])
                        $joiners[] = "(" . str_replace("{}", $tabname, $value[$i]) . ")";
                }
                $q .= "\n    " . $value[0] . " " . $value[1] . " as " . $tabname
                    . " on (" . join("\n        and ", $joiners) . ")";
            }
        }
        $q .= "\n    where {$filter}\n    group by Paper.paperId";

        //Conf::msg_debugt($q);
        //error_log($q);
        //Conf::msg_debugt(json_encode($this->_qe->debug_json()));
        //error_log(json_encode($this->_qe->debug_json()));

        // actually perform query
        return $this->conf->qe_raw($q);
    }

    private function _prepare() {
        if ($this->_matches !== null) {
            return;
        }
        $this->_matches = [];
        if ($this->limit() === "none") {
            return;
        }

        $qe = $this->term();
        //Conf::msg_debugt(json_encode($qe->debug_json()));
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);

        // collect papers
        $result = $this->_prepare_result($qe);
        $rowset = PaperInfoSet::make_result($result, $this->user);

        // filter papers
        $thqe = $qe instanceof Then_SearchTerm ? $qe : null;
        $this->_then_map = [];
        if ($thqe && $thqe->has_highlight()) {
            $this->_highlight_map = [];
        }
        foreach ($rowset as $row) {
            if ($this->user->can_view_paper($row)
                && $this->_limit_qe->test($row, null)
                && $qe->test($row, null)) {
                $this->_matches[] = $row->paperId;
                $this->_then_map[$row->paperId] = $thqe ? $thqe->_last_group() : 0;
                if ($this->_highlight_map !== null
                    && ($hls = $thqe->_last_highlights($row)) !== []) {
                    $this->_highlight_map[$row->paperId] = $hls;
                }
            }
        }

        // add deleted papers explicitly listed by number (e.g. action log)
        if ($this->_allow_deleted) {
            $this->_add_deleted_papers($qe);
        } else if ($this->_limit_qe->named_limit === "s"
                   && $this->user->privChair
                   && ($ps = $this->_check_missing_papers($qe))
                   && $this->conf->fetch_ivalue("select exists (select * from Paper where paperId?a)", $ps)) {
            $this->warning("<5>Some incomplete or withdrawn submissions also match this search. " . Ht::link("Show all matching submissions", $this->conf->hoturl("search", ["t" => "all", "q" => $this->q])));
        }

        $this->user->set_overrides($old_overrides);
    }

    /** @return list<int> */
    function paper_ids() {
        $this->_prepare();
        return $this->_matches;
    }

    /** @return list<int> */
    function sorted_paper_ids() {
        $this->_prepare();
        if ($this->_default_sort || $this->sort_field_list()) {
            $pl = new PaperList("empty", $this, ["sort" => $this->_default_sort]);
            return $pl->paper_ids();
        } else {
            return $this->paper_ids();
        }
    }

    /** @return list<TagAnno> */
    function paper_groups() {
        $this->_prepare();
        $qe1 = $this->_qe;
        if ($qe1 instanceof Then_SearchTerm) {
            if ($qe1->nthen > 1) {
                $gs = [];
                for ($i = 0; $i !== $qe1->nthen; ++$i) {
                    $ch = $qe1->child[$i];
                    $h = $ch->get_float("legend");
                    if ($h === null) {
                        $spanstr = $ch->get_float("strspan_owner") ?? $this->q;
                        $h = rtrim(substr($spanstr, $ch->pos1 ?? 0, ($ch->pos2 ?? 0) - ($ch->pos1 ?? 0)));
                    }
                    $gs[] = TagAnno::make_legend($h);
                }
                return $gs;
            } else {
                $qe1 = $qe1->child[0];
            }
        }
        if (($h = $qe1->get_float("legend"))) {
            return [TagAnno::make_legend($h)];
        } else {
            return [];
        }
    }

    /** @param int $pid
     * @return ?int */
    function paper_group_index($pid) {
        $this->_prepare();
        return $this->_then_map[$pid] ?? null;
    }

    /** @return array<int,int> */
    function groups_by_paper_id() {
        $this->_prepare();
        return $this->_then_map;
    }

    /** @return ?array<int,list<string>> */
    function highlights_by_paper_id() {
        $this->_prepare();
        return $this->_highlight_map;
    }

    /** @param iterable<string>|iterable<array{string,?int,?int,?int}> $words
     * @return Generator<array{string,string,list<string>,?int,?int}> */
    static function view_generator($words) {
        foreach ($words as $w) {
            if (is_array($w)) {
                $pos1 = $w[1];
                $pos2 = $w[3];
                $w = $w[0];
            } else {
                $pos1 = $pos2 = null;
            }

            $colon = strpos($w, ":");
            if ($colon === false
                || !in_array(substr($w, 0, $colon), ["show", "sort", "edit", "hide", "showsort", "editsort"])) {
                $w = "show:" . $w;
                $colon = 4;
            }

            $action = substr($w, 0, $colon);
            $d = substr($w, $colon + 1);
            $keyword = null;
            if (str_starts_with($d, "[")) { /* XXX backward compat */
                $d = substr($d, 1, strlen($d) - (str_ends_with($d, "]") ? 2 : 1));
            } else if (str_ends_with($d, "]")
                       && ($lbrack = strrpos($d, "[")) !== false) {
                $keyword = substr($d, 0, $lbrack);
                $d = substr($d, $lbrack + 1, strlen($d) - $lbrack - 2);
            }

            $decorations = [];
            if ($d !== "") {
                $splitter = new SearchSplitter($d);
                while ($splitter->skip_span(" \n\r\t\v\f,")) {
                    $decorations[] = $splitter->shift_balanced_parens(" \n\r\t\v\f,");
                }
            }

            $keyword = $keyword ?? array_shift($decorations) ?? "";
            if ($keyword !== "") {
                if ($keyword[0] === "-") {
                    $keyword = substr($keyword, 1);
                    array_unshift($decorations, "reverse");
                } else if ($keyword[0] === "+") {
                    $keyword = substr($keyword, 1);
                }
                if ($keyword !== "") {
                    yield [$action, $keyword, $decorations, $pos1, $pos2];
                }
            }
        }
    }

    /** @param string|bool $action
     * @param string $keyword
     * @param ?list<string> $decorations
     * @return string */
    static function unparse_view($action, $keyword, $decorations) {
        if (is_bool($action)) {
            $action = $action ? "show" : "hide";
        }
        if (!ctype_alnum($keyword)
            && SearchSplitter::span_balanced_parens($keyword) !== strlen($keyword)) {
            $keyword = "\"" . $keyword . "\"";
        }
        if ($decorations) {
            return "{$action}:{$keyword}[" . join(" ", $decorations) . "]";
        } else {
            return "{$action}:{$keyword}";
        }
    }

    /** @return list<string> */
    private function sort_field_list() {
        $r = [];
        foreach (self::view_generator($this->term()->view_anno() ?? []) as $akd) {
            if (str_ends_with($akd[0], "sort")) {
                $r[] = $akd[1];
            }
        }
        return $r;
    }

    /** @param callable(int):bool $callback
     * @return void */
    function restrict_match($callback) {
        $m = [];
        foreach ($this->paper_ids() as $pid) {
            if (call_user_func($callback, $pid)) {
                $m[] = $pid;
            }
        }
        $this->_matches = $m;
    }

    /** @return bool */
    function test(PaperInfo $prow) {
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $qe = $this->term();
        $x = $this->user->can_view_paper($prow)
            && $this->_limit_qe->test($prow, null)
            && $qe->test($prow, null);
        $this->user->set_overrides($old_overrides);
        return $x;
    }

    /** @param PaperInfoSet|Iterable<PaperInfo> $prows
     * @return list<PaperInfo>
     * @deprecated */
    function filter($prows) {
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $qe = $this->term();
        $results = [];
        foreach ($prows as $prow) {
            if ($this->user->can_view_paper($prow)
                && $this->_limit_qe->test($prow, null)
                && $qe->test($prow, null)) {
                $results[] = $prow;
            }
        }
        $this->user->set_overrides($old_overrides);
        return $results;
    }

    /** @return bool */
    function test_review(PaperInfo $prow, ReviewInfo $rrow) {
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $qe = $this->term();
        $x = $this->user->can_view_paper($prow)
            && $this->_limit_qe->test($prow, $rrow)
            && $qe->test($prow, $rrow);
        $this->user->set_overrides($old_overrides);
        return $x;
    }

    /** @return array<string,mixed>|false */
    function simple_search_options() {
        $queryOptions = [];
        if ($this->_matches === null
            && $this->_limit_qe->simple_search($queryOptions)
            && $this->term()->simple_search($queryOptions)) {
            return $queryOptions;
        } else {
            return false;
        }
    }

    /** @return string|false */
    function alternate_query() {
        if ($this->q !== ""
            && $this->q[0] !== "#"
            && preg_match('/\A' . TAG_REGEX . '\z/', $this->q)
            && $this->user->can_view_tags(null)
            && in_array($this->limit(), ["s", "all", "r"], true)) {
            if ($this->q[0] === "~"
                || $this->conf->fetch_ivalue("select exists(select * from PaperTag where tag=?) from dual", $this->q)) {
                return "#" . $this->q;
            }
        }
        return false;
    }

    /** @return string */
    function default_limited_query() {
        if ($this->user->isPC
            && !$this->_limit_explicit
            && $this->limit() !== ($this->conf->time_pc_view_active_submissions() ? "act" : "s")) {
            return self::canonical_query($this->q, "", "", $this->_qt, $this->conf, $this->limit());
        } else {
            return $this->q;
        }
    }

    /** @return string */
    function url_site_relative_raw($q = null) {
        $url = $this->urlbase();
        $q = $q ?? $this->q;
        if ($q !== "" || substr($url, 0, 6) === "search") {
            $url .= (strpos($url, "?") === false ? "?q=" : "&q=") . urlencode($q);
        }
        return $url;
    }

    /** @return string */
    function description($listname) {
        if ($listname) {
            $lx = $this->conf->_($listname);
        } else {
            $limit = $this->limit();
            if ($this->q === "re:me" && in_array($limit, ["r", "s", "act"], true)) {
                $limit = "r";
            }
            $lx = self::limit_description($this->conf, $limit);
        }
        if ($this->q === ""
            || ($this->q === "re:me" && $this->limit() === "s")
            || ($this->q === "re:me" && $this->limit() === "act")) {
            return $lx;
        } else if (str_starts_with($this->q, "au:")
                   && strlen($this->q) <= 36
                   && $this->term() instanceof Author_SearchTerm) {
            return "$lx by " . ltrim(substr($this->q, 3));
        } else if (strlen($this->q) <= 24
                   || $this->term() instanceof Tag_SearchTerm) {
            return "{$this->q} in $lx";
        } else {
            return "$lx search";
        }
    }

    /** @param ?string $sort
     * @return string */
    function listid($sort = null) {
        $rest = [];
        if ($this->_reviewer_user
            && $this->_reviewer_user->contactXid !== $this->user->contactXid) {
            $rest[] = "reviewer=" . urlencode($this->_reviewer_user->email);
        }
        if ($sort !== null && $sort !== "") {
            $rest[] = "sort=" . urlencode($sort);
        }
        return "p/" . $this->_limit_qe->named_limit . "/" . urlencode($this->q)
            . ($rest ? "/" . join("&", $rest) : "");
    }

    /** @param string $listid
     * @return ?array<string,string> */
    static function unparse_listid($listid) {
        if (preg_match('/\Ap\/([^\/]+)\/([^\/]*)(?:|\/([^\/]*))\z/', $listid, $m)) {
            $args = ["t" => $m[1], "q" => urldecode($m[2])];
            if (isset($m[3]) && $m[3] !== "") {
                foreach (explode("&", $m[3]) as $arg) {
                    if (str_starts_with($arg, "sort=")) {
                        $args["sort"] = urldecode(substr($arg, 5));
                    } else {
                        // XXX `reviewer`
                        error_log(caller_landmark() . ": listid includes $arg");
                    }
                }
            }
            return $args;
        } else {
            return null;
        }
    }

    /** @param list<int> $ids
     * @param ?string $listname
     * @param ?string $sort
     * @return SessionList */
    function create_session_list_object($ids, $listname, $sort = null) {
        $sort = $sort !== null ? $sort : $this->_default_sort;
        $l = (new SessionList($this->listid($sort), $ids, $this->description($listname)))
            ->set_urlbase($this->urlbase());
        if ($this->field_highlighters()) {
            $l->highlight = $this->_match_preg_query ? : true;
        }
        return $l;
    }

    /** @return SessionList */
    function session_list_object() {
        return $this->create_session_list_object($this->sorted_paper_ids(), null);
    }

    /** @return list<string> */
    function highlight_tags() {
        $this->_prepare();
        $ht = $this->term()->get_float("tags") ?? [];
        foreach ($this->sort_field_list() as $s) {
            if (($tag = Tagger::check_tag_keyword($s, $this->user)))
                $ht[] = $tag;
        }
        return array_values(array_unique($ht));
    }


    /** @param string $q */
    function set_field_highlighter_query($q) {
        $ps = new PaperSearch($this->user, ["q" => $q]);
        $this->_match_preg = $ps->field_highlighters();
        $this->_match_preg_query = $q;
    }

    /** @return array<string,TextPregexes> */
    function field_highlighters() {
        $this->term();
        return $this->_match_preg ?? [];
    }

    /** @return string */
    function field_highlighter($field) {
        return ($this->field_highlighters())[$field] ?? "";
    }

    /** @param string $field */
    function add_field_highlighter($field, TextPregexes $regex) {
        if (!$this->_match_preg_query && !$regex->is_empty()) {
            $this->_match_preg[$field] = $this->_match_preg[$field] ?? TextPregexes::make_empty();
            $this->_match_preg[$field]->add_matches($regex);
        }
    }


    /** @return string */
    static function limit_description(Conf $conf, $t) {
        return $conf->_c("search_type", self::$search_type_names[$t] ?? "Submitted");
    }

    /** @param ?string $reqtype
     * @return ?string */
    static function canonical_limit($reqtype) {
        if ($reqtype !== null
            && ($x = Limit_SearchTerm::$reqtype_map[$reqtype] ?? null) !== null) {
            return is_array($x) ? $x[0] : $x;
        } else {
            return null;
        }
    }

    /** @param ?string $reqtype
     * @return ?string */
    static function long_canonical_limit($reqtype) {
        if ($reqtype !== null
            && ($x = Limit_SearchTerm::$reqtype_map[$reqtype] ?? null) !== null) {
            return is_array($x) ? $x[1] : $x;
        } else {
            return null;
        }
    }

    /** @param ?string $reqtype
     * @return list<string> */
    static function viewable_limits(Contact $user, $reqtype = null) {
        if ($reqtype !== null && $reqtype !== "") {
            $reqtype = self::canonical_limit($reqtype);
        }
        $ts = [];
        if ($reqtype === "viewable") {
            $ts[] = "viewable";
        }
        if ($user->isPC) {
            if ($user->conf->time_pc_view_active_submissions()) {
                $ts[] = "act";
            }
            $ts[] = "s";
            if ($user->conf->has_any_accepted()
                && $user->can_view_some_decision()) {
                $ts[] = "acc";
            }
        }
        if ($user->is_reviewer()) {
            $ts[] = "r";
        }
        if ($user->has_outstanding_review()
            || ($user->is_reviewer() && $reqtype === "rout")) {
            $ts[] = "rout";
        }
        if ($user->isPC) {
            if ($user->is_requester() || $reqtype === "req") {
                $ts[] = "req";
            }
            if ($user->is_discussion_lead() || $reqtype === "lead") {
                $ts[] = "lead";
            }
            if (($user->privChair ? $user->conf->has_any_manager() : $user->is_manager())
                || $reqtype === "admin") {
                $ts[] = "admin";
            }
            if ($reqtype === "alladmin") {
                $ts[] = "alladmin";
            }
        }
        if ($user->is_author() || $reqtype === "a") {
            $ts[] = "a";
        }
        if ($user->privChair
            && !$user->conf->time_pc_view_active_submissions()
            && $reqtype === "act") {
            $ts[] = "act";
        }
        if ($user->privChair) {
            $ts[] = "all";
        }
        return $ts;
    }

    /** @return list<string> */
    static function viewable_manager_limits(Contact $user) {
        if ($user->privChair) {
            if ($user->conf->has_any_manager()) {
                $ts = ["admin", "alladmin", "s"];
            } else {
                $ts = ["s"];
            }
            array_push($ts, "acc", "undecided", "all");
        } else {
            $ts = ["admin"];
        }
        return $ts;
    }

    /** @param list<string> $limits
     * @param string $selected
     * @return string */
    static function limit_selector(Conf $conf, $limits, $selected, $extra = []) {
        if ($extra["select"] ?? count($limits) > 1) {
            unset($extra["select"]);
            $sel_opt = [];
            foreach ($limits as $k) {
                $sel_opt[$k] = self::limit_description($conf, $k);
            }
            if (!isset($extra["aria-label"])) {
                $extra["aria-label"] = "Search collection";
            }
            return Ht::select("t", $sel_opt, $selected, $extra);
        } else {
            $t = self::limit_description($conf, $selected);
            if (isset($extra["id"])) {
                $t = '<span id="' . htmlspecialchars($extra["id"]) . "\">{$t}</span>";
            }
            return $t . Ht::hidden("t", $selected);
        }
    }
}
