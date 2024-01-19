<?php
// papersearch.php -- HotCRP class for searching for papers
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class SearchScope {
    /** @var int */
    public $pos1;
    /** @var int */
    public $pos2;
    /** @var ?SearchAtom */
    public $defkw;
    /** @var bool */
    public $defkw_error = false;

    /** @param int $pos1
     * @param int $pos2
     * @param ?SearchAtom $defkw */
    function __construct($pos1, $pos2, $defkw) {
        $this->pos1 = $pos1;
        $this->pos2 = $pos2;
        $this->defkw = $defkw;
    }
}

class SearchStringContext {
    /** @var string */
    public $subcontext;
    /** @var int */
    public $ppos1;
    /** @var int */
    public $ppos2;
    /** @var ?SearchStringContext */
    public $parent;
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

class SearchViewElement {
    /** @var 'show'|'hide'|'edit'|'showsort'|'editsort'|'sort' */
    public $action;
    /** @var string */
    public $keyword;
    /** @var list<string> */
    public $decorations = [];
    /** @var ?int */
    public $kwpos1;
    /** @var ?int */
    public $pos1;
    /** @var ?int */
    public $pos2;
    /** @var ?SearchStringContext */
    public $string_context;

    /** @return ?string */
    function show_action() {
        if (in_array($this->action, ["show", "hide", "edit", "showsort", "editsort"])) {
            return substr($this->action, 0, 4);
        } else {
            return null;
        }
    }

    /** @return bool */
    function sort_action() {
        return str_ends_with($this->action, "sort");
    }

    /** @return bool */
    function nondefault_sort_action() {
        return str_ends_with($this->action, "sort")
            && ($this->keyword !== "id" || !empty($this->decorations));
    }
}

class PaperSearchPrepareParam {
    /** @var 0|1|2|3 */
    private $_nest = 0;
    /** @var ?Then_SearchTerm */
    private $_then_term;
    /** @var int */
    private $_then_count;

    /** @return bool */
    function toplevel() {
        return $this->_nest === 0;
    }

    /** @return bool */
    function allow_then() {
        return $this->_nest <= 1;
    }

    /** @return bool */
    function want_field_highlighter() {
        return $this->_nest <= 2;
    }

    /** @param Op_SearchTerm $opterm
     * @return PaperSearchPrepareParam */
    function nest($opterm) {
        if ($opterm->type === "and" || $opterm->type === "space") {
            $level = 0;
        } else if ($opterm->type === "then") {
            $level = 1;
        } else if ($opterm->type !== "not") {
            $level = 2;
        } else {
            $level = 3;
        }
        if ($level <= $this->_nest
            && ($level !== 1 || $this->_nest !== 1)) {
            return $this;
        }
        $x = clone $this;
        $x->_nest = $level;
        return $x;
    }


    /** @return ?Then_SearchTerm */
    function then_term() {
        return $this->_then_term;
    }

    /** @param Then_SearchTerm $then */
    function set_then_term($then) {
        if ($this->_nest <= 1) {
            ++$this->_then_count;
            $this->_then_term = $this->_then_count === 1 ? $then : null;
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
    /** @var bool */
    private $_has_qe = false;

    /** @var int
     * @readonly */
    public $expand_automatic = 0;
    /** @var bool */
    private $_allow_deleted = false;
    /** @var ?string */
    private $_urlbase;
    /** @var ?array<string,string> */
    private $_urlbase_args;
    /** @var ?string
     * @readonly */
    private $_default_sort; // XXX should be used more often

    /** @var ?array<string,TextPregexes> */
    private $_match_preg;
    /** @var ?string */
    private $_match_preg_query;
    /** @var ?list<ContactSearch> */
    private $_contact_searches;
    /** @var ?SearchStringContext */
    private $_string_context;
    /** @var list<int> */
    private $_matches;
    /** @var ?Then_SearchTerm */
    private $_then_term;
    /** @var ?array<int,int> */
    private $_then_map;
    /** @var ?array<int,list<string>> */
    private $_highlight_map;

    static public $search_type_names = [
        "a" => "Your submissions",
        "accepted" => "Accepted",
        "active" => "Active",
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
                && ($user->is_root_user()
                    || $this->conf->unnamed_submission_round()->time_update(true))) {
                $limit = "all";
            } else if ($user->isPC) {
                if ($user->can_view_some_incomplete()
                    && $user->conf->can_pc_view_some_incomplete()) {
                    $limit = "active";
                } else {
                    $limit = "s";
                }
            } else if (!$user->is_reviewer()) {
                $limit = "a";
            } else if (!$user->is_author()) {
                $limit = "r";
            } else {
                $limit = "ar";
            }
        }
        $lword = SearchWord::make_simple($limit);
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
        $this->_urlbase = $base;
        $this->_urlbase_args = $args;
        return $this;
    }

    /** @param bool|int $x
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_expand_automatic($x) {
        $n = (int) $x;
        if ($this->_qe !== null
            && ($this->expand_automatic > 0) !== ($n > 0)) {
            $this->clear_compilation();
        }
        $this->expand_automatic = $n;
        return $this;
    }

    /** @return Limit_SearchTerm */
    function limit_term() {
        return $this->_limit_qe;
    }
    /** @return string */
    function limit() {
        return $this->_limit_qe->limit;
    }
    /** @return bool */
    function show_submitted_status() {
        return in_array($this->_limit_qe->limit, ["a", "active", "all"])
            && $this->q !== "re:me";
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
        $this->_has_qe || $this->main_term();
        return parent::has_problem();
    }

    /** @return list<MessageItem> */
    function message_list() {
        $this->_has_qe || $this->main_term();
        return parent::message_list();
    }

    /** @param string $message
     * @return MessageItem */
    function warning($message) {
        return $this->warning_at(null, $message);
    }

    /** @param string|MessageItem $message
     * @param int $pos1
     * @param int $pos2
     * @param ?SearchStringContext $context
     * @return list<MessageItem> */
    function expand_message_context($message, $pos1, $pos2, $context) {
        if (is_string($message)) {
            $mi = MessageItem::warning($message);
        } else {
            $mi = $message;
        }
        $mi->pos1 = $pos1;
        $mi->pos2 = $pos2;
        $mis = [$mi];
        while ($context) {
            $mi->context = $context->subcontext;
            $mi = MessageItem::inform("");
            $mi->landmark = "<5>→ <em>expanded from</em> ";
            $mi->pos1 = $context->ppos1;
            $mi->pos2 = $context->ppos2;
            $mis[] = $mi;
            $context = $context->parent;
        }
        $mi->context = $this->q;
        return $mis;
    }

    /** @param SearchWord $sw
     * @param string $message
     * @return MessageItem */
    function lwarning($sw, $message) {
        $mis = $this->expand_message_context($message, $sw->pos1, $sw->pos2, $sw->string_context);
        $this->append_list($mis);
        return $mis[0];
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
        if ($kwdef) {
            if ($kwdef->parse_has_function ?? null) {
                $qe = call_user_func($kwdef->parse_has_function, $word, $sword, $srch);
            } else if ($kwdef->has ?? null) {
                $sword2 = SearchWord::make_kwarg($kwdef->has, $sword->kwpos1, $sword->pos1, $sword->pos2, $sword->string_context);
                $sword2->kwexplicit = true;
                $sword2->kwdef = $kwdef;
                $qe = call_user_func($kwdef->parse_function, $kwdef->has, $sword2, $srch);
            } else {
                $qe = null;
            }
            if ($qe) {
                return $qe;
            }
        }
        $srch->lwarning($sword, "<0>Unknown search ‘has:{$word}’");
        return new False_SearchTerm;
    }

    /** @return SearchTerm */
    static function parse_searchcontrol($word, SearchWord $sword, PaperSearch $srch) {
        if (strcasecmp($word, "expand_automatic") === 0) {
            if ($srch->expand_automatic === 0) {
                /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
                $srch->expand_automatic = 1;
            }
            return new True_SearchTerm;
        }
        $srch->lwarning($sword, "<0>Unknown search control option ‘{$word}’");
        return new True_SearchTerm;
    }

    /** @param string $word
     * @return ?object */
    private function _find_named_search($word) {
        foreach ($this->conf->named_searches() as $sj) {
            if (strcasecmp($sj->name, $word) === 0)
                return $sj;
        }
        return null;
    }

    /** @param string $word
     * @param object $sj
     * @return ?string */
    private function _expand_named_search($word, $sj) {
        $q = $sj->q ?? null;
        if ($q && ($sj->t ?? "") !== "" && $sj->t !== "s") {
            $q = "({$q}) in:{$sj->t}";
        }
        return $q;
    }

    /** @param string $word
     * @return ?SearchTerm */
    static function parse_named_search($word, SearchWord $sword, PaperSearch $srch) {
        if (!$srch->user->isPC) {
            return null;
        }
        $qe = null;
        if (!($sj = $srch->_find_named_search($word))) {
            $srch->lwarning($sword, "<0>Named search not found");
        } else if (!($nextq = $srch->_expand_named_search($word, $sj))) {
            $srch->lwarning($sword, "<0>Named search defined incorrectly");
        } else {
            $context = new SearchStringContext;
            $context->subcontext = $nextq;
            $context->ppos1 = $sword->kwpos1;
            $context->ppos2 = $sword->pos2;
            $context->parent = $srch->_string_context;
            for ($n = 0, $c = $context; $c; ++$n, $c = $c->parent) {
            }
            if ($n >= 10) {
                $srch->lwarning($sword, "<0>Circular reference in named search definitions");
            } else {
                $srch->_string_context = $context;
                $qe = $srch->_search_expression($nextq);
                $srch->_string_context = $context->parent;
            }
        }
        return $qe ?? new False_SearchTerm;
    }

    /** @param string $kw
     * @param ?SearchWord $sword
     * @param ?SearchScope $scope
     * @param bool $is_defkw
     * @return ?object */
    private function _find_search_keyword($kw, $sword, $scope, $is_defkw) {
        $kwdef = $this->conf->search_keyword($kw, $this->user);
        if ($kwdef && ($kwdef->parse_function ?? null)) {
            return $kwdef;
        }
        if ($scope && (!$is_defkw || !$scope->defkw_error)) {
            $xsword = SearchWord::make_kwarg($kw, $sword->kwpos1, $sword->kwpos1, $sword->pos1, $sword->string_context);
            $this->lwarning($xsword, "<0>Unknown search keyword ‘{$kw}:’");
            if ($is_defkw) {
                $scope->defkw_error = true;
            }
        }
        return null;
    }

    /** @param object $kwdef
     * @param SearchWord $sword
     * @param bool $kwexplicit
     * @return SearchTerm */
    private function _kwdef_parse($kwdef, $sword, $kwexplicit) {
        $sword->kwexplicit = $kwexplicit;
        $sword->kwdef = $kwdef;
        $qx = call_user_func($kwdef->parse_function, $sword->word, $sword, $this);
        if ($qx && !is_array($qx)) {
            return $qx;
        } else if ($qx) {
            return SearchTerm::combine_in("or", $this->_string_context, ...$qx);
        } else {
            return new False_SearchTerm; // assume error already given
        }
    }

    /** @param string $str
     * @return bool */
    static private function _search_word_is_paperid($str) {
        $ch = substr($str, 0, 1);
        return ($ch === "#" || ctype_digit($ch))
            && preg_match('/\A(?:#?\d+(?:(?:-|–|—)#?\d+)?(?:\s*,\s*|\z))+\z/s', $str);
    }

    /** @param string $str
     * @return ?list<string> */
    static private function _search_word_inferred_keyword($str) {
        if (preg_match('/\A([_a-zA-Z0-9][-_.a-zA-Z0-9]*)([=!<>]=?|≠|≤|≥)([^:\"]+\z|[^:\"]*\".*)/s', $str, $m)) {
            return $m;
        } else {
            return null;
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

    /** @param string $kw
     * @param SearchWord $sword
     * @param SearchScope $scope
     * @return ?SearchTerm */
    private function _search_word($kw, $sword, $scope) {
        // Explicitly provided keyword
        if ($kw !== "") {
            if (($kwdef = $this->_find_search_keyword($kw, $sword, $scope, false))) {
                return $this->_kwdef_parse($kwdef, $sword, true);
            } else {
                return new False_SearchTerm;
            }
        }

        // Paper ID search term (`1-2`, `#1-#2`, etc.)
        if (!$sword->quoted
            && !$scope->defkw
            && self::_search_word_is_paperid($sword->word)) {
            return PaperID_SearchTerm::parse_normal($sword->word);
        }

        // Tag search term
        if (!$sword->quoted
            && !$scope->defkw
            && str_starts_with($sword->word, "#")
            && ($kwdef = $this->_find_search_keyword("hashtag", $sword, null, false))) {
            return $this->_kwdef_parse($kwdef, $sword, false);
        }

        // Inferred keyword: user wrote `ovemer>2`, meant `ovemer:>2`
        if (!$sword->quoted
            && ($m = self::_search_word_inferred_keyword($sword->qword))
            && ($kwdef = $this->_find_search_keyword($m[1], $sword, null, false))) {
            $pos1 = $sword->pos1 + strlen($m[1]);
            if (($m[2] === "=" || $m[2] === "==") && !($kwdef->needs_relation ?? false)) {
                $sw = $m[3];
                $pos1 += strlen($m[2]);
            } else {
                $sw = $m[2] . $m[3];
            }
            $swordi = SearchWord::make_kwarg($sw, $sword->pos1, $pos1, $sword->pos2, $this->_string_context);
            return $this->_kwdef_parse($kwdef, $swordi, true);
        }

        // Default keyword (`ti:(xxx OR yyy)`, etc.)
        if ($scope->defkw) {
            $sword->kwpos1 = $scope->defkw->kwpos1;
            if (($kwdef = $this->_find_search_keyword($scope->defkw->kword, $sword, $scope, true))) {
                return $this->_kwdef_parse($kwdef, $sword, true);
            } else {
                return new False_SearchTerm;
            }
        }

        // Special words: unquoted `*`, `ANY`, `ALL`, `NONE`; empty string
        if (strlen($sword->qword) <= 4) {
            $qword = strtoupper($sword->qword);
            if ($qword === "NONE") {
                return new False_SearchTerm;
            }
            if ($qword === ""
                || $qword === "*"
                || $qword === "ANY"
                || $qword === "ALL") {
                return new True_SearchTerm;
            }
        }

        // Last case: check preconfigured keywords
        $qt = [];
        foreach ($this->_qt_fields() as $kw) {
            if (($kwdef = $this->_find_search_keyword($kw, $sword, null, false)))
                $qt[] = $this->_kwdef_parse($kwdef, $sword, false);
        }
        return SearchTerm::combine_in("or", $this->_string_context, ...$qt);
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

    /** @param ?SearchAtom $sa
     * @param string $str
     * @param SearchScope $scope
     * @param int $depth
     * @return ?SearchTerm */
    private function _parse_atom($sa, $str, $scope, $depth) {
        if (!$sa) {
            return null;
        } else if ($sa->op) {
            $child = [];
            foreach ($sa->child as $sac) {
                $child[] = $this->_parse_atom($sac, $str, $scope, $depth);
            }
            if ($sa->op->type === "+" || $sa->op->type === "(") {
                return $child[0];
            }
            $st = SearchTerm::combine_in($sa->op, $this->_string_context, ...$child);
        } else if ($sa->kword === null && $sa->text === "") {
            $st = new True_SearchTerm;
        } else if ($sa->kword !== null
                   && str_starts_with($sa->text, "(")
                   && ($kwdef = $this->conf->search_keyword($sa->kword, $this->user))
                   && !($kwdef->allow_parens ?? false)) {
            // Search like `ti:(foo OR bar)` adds a default keyword; must reparse.
            $st = $this->_search_expression($str, new SearchScope($sa->pos1, $sa->pos2, $sa), $depth + 1);
        } else {
            $sword = SearchWord::make_kwarg($sa->text, $sa->kwpos1, $sa->pos1, $sa->pos2, $this->_string_context);
            $st = $this->_search_word($sa->kword ?? "", $sword, $scope);
        }
        if ($st && !$st->is_uninteresting()) {
            $st->apply_strspan($sa->kwpos1, $sa->pos2, $this->_string_context);
        }
        return $st;
    }

    /** @param string $str
     * @param ?SearchScope $scope
     * @param int $depth
     * @return ?SearchTerm */
    private function _search_expression($str, $scope = null, $depth = 0) {
        if ($depth >= 512) {
            return null;
        }
        $scope = $scope ?? new SearchScope(0, strlen($str), null);
        $splitter = new SearchSplitter($str, $scope->pos1, $scope->pos2);
        return $this->_parse_atom($splitter->parse_expression(), $str, $scope, $depth);
    }


    static private function _canonical_qt($qt) {
        if (in_array($qt, ["ti", "ab", "au", "ac", "co", "re", "tag"])) {
            return $qt;
        } else {
            return "n";
        }
    }

    /** @param ?SearchAtom $sa
     * @param string $type
     * @param string $qt
     * @param Conf $conf
     * @param int $depth
     * @return string */
    static private function _canonicalize_atom($sa, $type, $qt, $conf, $depth) {
        if (!$sa) {
            return "";
        }
        if ($sa->op) {
            $child = [];
            foreach ($sa->flattened_children() as $sac) {
                $child[] = self::_canonicalize_atom($sac, $type, $qt, $conf, $depth);
            }
            if ($sa->op->type === "+" || $sa->op->type === "(") {
                return $child[0];
            } else if ($sa->op->type === "not") {
                if ($child[0] === "") {
                    return "NOT";
                } else if (str_starts_with($child[0], "(")
                           || strpos($child[0], " ") !== false) {
                    return "NOT {$child[0]}";
                } else {
                    return "-{$child[0]}";
                }
            }
            if ($sa->op->type === "space") {
                $op = "";
            } else {
                $op = strtoupper($sa->op->type);
                if ($sa->op->subtype !== null) {
                    $op .= ":{$sa->op->subtype}";
                }
            }
            $a = [];
            foreach ($child as $i => $s) {
                if ($i !== 0 && $op !== "") {
                    $a[] = $op;
                }
                if ($s !== "") {
                    $a[] = $s;
                }
            }
            return "(" . join(" ", $a) . ")";
        }
        if ($sa->kword === null && $sa->text === "") {
            return "";
        }
        if ($sa->kword !== null
            && str_starts_with($sa->text, "(")
            && ($kwdef = $conf->search_keyword($sa->kword))
            && !($kwdef->allow_parens ?? false)) {
            // Search like `ti:(foo OR bar)` adds a default keyword; must recanonicalize.
            $s = self::_canonical_expression($sa->text, $type, "n", $conf, $depth + 1);
            if (!str_starts_with($s, "(")) {
                $s = "({$s})";
            }
        } else {
            $s = $sa->text;
        }
        if ($sa->kword !== null) {
            return "{$sa->kword}:{$s}";
        } else if ($qt === "n") {
            return $s;
        } else if ($qt === "tag") {
            return "#{$s}";
        } else {
            return "{$qt}:{$s}";
        }
    }

    static private function _canonical_expression($str, $type, $qt, Conf $conf, $depth = 0) {
        if ($depth >= 512) {
            return "";
        }
        $splitter = new SearchSplitter($str);
        $sa = $splitter->parse_expression($type === "all" ? "SPACE" : "SPACEOR");
        if ($type === "none" && $sa) {
            $sax = SearchAtom::make_op(SearchOperator::get("NOT"), 0, strlen($str), null);
            $sax->child[] = $sa;
            $sa = $sax;
        }
        return self::_canonicalize_atom($sa, $type, $qt, $conf, $depth);
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
            $qa = ($qa ?? "") !== "" ? "({$qa}) in:{$t}" : "in:{$t}";
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
        if (count($x) === 1) {
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
        if ($user->privChair
            || $user->conf->setting("viewrev") === Conf::VIEWREV_ALWAYS) {
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
    function main_term() {
        if ($this->_qe === null) {
            $this->_has_qe = true;
            if ($this->q === "re:me") {
                $this->_qe = new Limit_SearchTerm($this->user, $this->user, "r", true);
            } else if (($qe = $this->_search_expression($this->q))) {
                $this->_qe = $qe;
            } else {
                $this->_qe = new True_SearchTerm;
            }

            // extract regular expressions
            $param = new PaperSearchPrepareParam;
            $this->_qe->prepare_visit($param, $this);
            $this->_then_term = $param->then_term();
        }
        return $this->_qe;
    }

    /** @return SearchTerm */
    function full_term() {
        assert($this->user->is_root_user());
        assert(!$this->_has_qe || $this->_qe);
        // returns SearchTerm that includes effect of the limit
        $this->_has_qe || $this->main_term();
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
                && $this->conf->tags()->has(TagInfo::TF_SITEWIDE))) {
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

        $qe = $this->main_term();
        //Conf::msg_debugt(json_encode($qe->debug_json()));
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);

        // collect papers
        $result = $this->_prepare_result($qe);
        $rowset = PaperInfoSet::make_result($result, $this->user);

        // filter papers
        $thqe = $this->_then_term;
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

    /** @return ?Then_SearchTerm */
    function then_term() {
        return $this->_then_term;
    }

    /** @return list<TagAnno> */
    function paper_groups() {
        $this->_prepare();
        if ($this->_then_term) {
            $groups = $this->_then_term->group_terms();
            if (count($groups) > 1) {
                $gs = [];
                foreach ($groups as $i => $ch) {
                    $spanstr = $ch->get_float("strspan_owner") ?? $this->q;
                    $srch = rtrim(substr($spanstr, $ch->pos1 ?? 0, ($ch->pos2 ?? 0) - ($ch->pos1 ?? 0)));
                    $h = $ch->get_float("legend");
                    $ta = TagAnno::make_legend($h ?? $srch);
                    $ta->set_prop("search", $srch);
                    $gs[] = $ta;
                }
                return $gs;
            }
            $qe1 = $this->_then_term->child[0];
        } else {
            $qe1 = $this->_qe;
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

    /** @param iterable<string>|iterable<array{string,?int,?int,?int,?SearchStringContext}> $words
     * @return Generator<SearchViewElement> */
    static function view_generator($words) {
        foreach ($words as $w) {
            $sve = new SearchViewElement;
            if (is_array($w)) {
                $sve->kwpos1 = $w[1];
                $sve->pos1 = $w[2];
                $sve->pos2 = $w[3];
                $sve->string_context = $w[4];
                $w = $w[0];
            }

            $colon = strpos($w, ":");
            if ($colon === false
                || !in_array(substr($w, 0, $colon), ["show", "sort", "edit", "hide", "showsort", "editsort"])) {
                $w = "show:" . $w;
                $colon = 4;
            }

            $sve->action = substr($w, 0, $colon);
            $d = substr($w, $colon + 1);
            $keyword = null;
            if (str_starts_with($d, "[")) { /* XXX backward compat */
                $dlen = strlen($d);
                for ($ltrim = 1; $ltrim !== $dlen && ctype_space($d[$ltrim]); ++$ltrim) {
                }
                $rtrim = $dlen;
                if ($rtrim > $ltrim && $d[$rtrim - 1] === "]") {
                    --$rtrim;
                    while ($rtrim > $ltrim && ctype_space($d[$rtrim - 1])) {
                        --$rtrim;
                    }
                }
                $sve->pos1 = $sve->pos1 !== null ? $sve->pos1 + $ltrim : null;
                $sve->pos2 = $sve->pos2 !== null ? $sve->pos2 - ($dlen - $rtrim) : null;
                $d = substr($d, $ltrim, $rtrim - $ltrim);
            } else if (str_ends_with($d, "]")
                       && ($lbrack = strrpos($d, "[")) !== false) {
                $keyword = substr($d, 0, $lbrack);
                $d = substr($d, $lbrack + 1, strlen($d) - $lbrack - 2);
            }

            if ($d !== "") {
                $splitter = new SearchSplitter($d);
                while ($splitter->skip_span(" \n\r\t\v\f,")) {
                    $sve->decorations[] = $splitter->shift_balanced_parens(" \n\r\t\v\f,");
                }
            }

            $keyword = $keyword ?? array_shift($sve->decorations) ?? "";
            if ($keyword !== "") {
                if ($keyword[0] === "-") {
                    array_unshift($sve->decorations, "reverse");
                }
                if ($keyword[0] === "-" || $keyword[0] === "+") {
                    $keyword = substr($keyword, 1);
                    $sve->pos1 = $sve->pos1 !== null ? $sve->pos1 + 1 : null;
                }
                if ($keyword !== "") {
                    $sve->keyword = $keyword;
                    yield $sve;
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
        foreach (self::view_generator($this->main_term()->view_anno() ?? []) as $sve) {
            if ($sve->sort_action()) {
                $r[] = $sve->keyword;
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
        $x = $this->user->can_view_paper($prow)
            && $this->_limit_qe->test($prow, null)
            && $this->main_term()->test($prow, null);
        $this->user->set_overrides($old_overrides);
        return $x;
    }

    /** @return bool */
    function test_review(PaperInfo $prow, ReviewInfo $rrow) {
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $x = $this->user->can_view_paper($prow)
            && $this->_limit_qe->test($prow, $rrow)
            && $this->main_term()->test($prow, $rrow);
        $this->user->set_overrides($old_overrides);
        return $x;
    }

    /** @return array<string,mixed>|false */
    function simple_search_options() {
        $queryOptions = [];
        if ($this->_matches === null
            && $this->_limit_qe->simple_search($queryOptions)
            && $this->main_term()->simple_search($queryOptions)) {
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
            && $this->limit() !== ($this->user->can_view_some_incomplete() ? "active" : "s")) {
            return self::canonical_query($this->q, "", "", $this->_qt, $this->conf, $this->limit());
        } else {
            return $this->q;
        }
    }

    /** @return string */
    function encoded_query_params() {
        $encq = urlencode($this->q);
        $x = "q={$encq}&t={$this->_limit_qe->named_limit}";
        if ($this->_qt !== "n") {
            $x .= "&qt={$this->_qt}";
        }
        if ($this->_reviewer_user
            && $this->_reviewer_user->contactXid !== $this->user->contactXid) {
            $x .= "&reviewer=" . urlencode($this->_reviewer_user->email);
        }
        return $x;
    }

    /** @return string */
    function url_site_relative_raw($args = []) {
        $basepage = $this->_urlbase ?? "search";
        $xargs = [];
        if (!array_key_exists("q", $args)
            && ($this->q !== "" || $basepage === "search")) {
            $xargs["q"] = $this->q;
        }
        $xargs["t"] = $this->_limit_qe->named_limit;
        if ($this->_qt !== "n") {
            $xargs["qt"] = $this->_qt;
        }
        if ($this->_reviewer_user
            && $this->_reviewer_user->contactId !== $this->user->contactXid) {
            $xargs["reviewer"] = $this->_reviewer_user->email;
        }
        $xargs = array_merge($xargs, $this->_urlbase_args ?? [], $args);
        return $this->conf->hoturl_raw($basepage, $xargs, Conf::HOTURL_SITEREL);
    }

    /** @return string */
    function description($listname) {
        if ($listname) {
            $lx = $this->conf->_($listname);
        } else {
            $limit = $this->limit();
            if ($this->q === "re:me" && in_array($limit, ["r", "s", "active"], true)) {
                $limit = "r";
            }
            $lx = self::limit_description($this->conf, $limit);
        }
        if ($this->q === ""
            || ($this->q === "re:me" && $this->limit() === "s")
            || ($this->q === "re:me" && $this->limit() === "active")) {
            return $lx;
        } else if (str_starts_with($this->q, "au:")
                   && strlen($this->q) <= 36
                   && $this->main_term() instanceof Author_SearchTerm) {
            return "{$lx} by " . ltrim(substr($this->q, 3));
        } else if (strlen($this->q) <= 24
                   || $this->main_term() instanceof Tag_SearchTerm) {
            return "{$this->q} in {$lx}";
        } else {
            return "{$lx} search";
        }
    }

    /** @param string $listid
     * @return ?array<string,string> */
    static function unparse_listid($listid) {
        if (preg_match('/\Ap\/([^\/]+)\/([^\/]*)(?:|\/([^\/]*))\z/', $listid, $m)) {
            $args = ["q" => urldecode($m[2]), "t" => $m[1]];
            if (isset($m[3]) && $m[3] !== "") {
                foreach (explode("&", $m[3]) as $arg) {
                    if (str_starts_with($arg, "sort=")) {
                        $args["sort"] = urldecode(substr($arg, 5));
                    } else if (str_starts_with($arg, "qt=")) {
                        $args["qt"] = urldecode(substr($arg, 3));
                    } else if (str_starts_with($arg, "forceShow=")) {
                        $args["forceShow"] = urldecode(substr($arg, 10));
                    } else {
                        // XXX `reviewer`
                        error_log(caller_landmark() . ": listid includes {$arg}");
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
     * @param array{sort?:string,forceShow?:1} $args
     * @return SessionList */
    function create_session_list_object($ids, $listname, $args) {
        $encq = urlencode($this->q);
        $listid = "p/{$this->_limit_qe->named_limit}/{$encq}";

        $rest = [];
        if ($this->_qt !== "n") {
            $rest[] = "qt={$this->_qt}";
        }
        if ($this->_reviewer_user
            && $this->_reviewer_user->contactXid !== $this->user->contactXid) {
            $rest[] = "reviewer=" . urlencode($this->_reviewer_user->email);
        }
        $sort = $args["sort"] ?? $this->_default_sort ?? "";
        if ($sort !== "") {
            $rest[] = "sort=" . urlencode($sort);
        }
        if (!empty($rest)) {
            $listid .= "/" . join("&", $rest);
        }

        $args["q"] = null;
        $l = (new SessionList($listid, $ids, $this->description($listname)))
            ->set_urlbase($this->url_site_relative_raw($args));
        if ($this->field_highlighters()) {
            $l->highlight = $this->_match_preg_query ? : true;
        }
        return $l;
    }

    /** @return SessionList */
    function session_list_object() {
        return $this->create_session_list_object($this->sorted_paper_ids(), null, []);
    }

    /** @return list<string> */
    function highlight_tags() {
        $this->_prepare();
        $ht = $this->main_term()->get_float("tags") ?? [];
        $tagger = null;
        foreach ($this->sort_field_list() as $s) {
            if (preg_match('/\A(?:#|tag:\s*|tagval:\s*)(\S+)\z/', $s, $m)) {
                $tagger = $tagger ?? new Tagger($this->user);
                if (($tag = $tagger->check($m[1]))) {
                    $ht[] = $tag;
                }
            }
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
        $this->main_term();
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
            $ts[] = "s";
            if ($user->conf->has_any_accepted()
                && $user->can_view_some_decision()) {
                $ts[] = "accepted";
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
        if ($user->can_view_some_incomplete()) {
            $ts[] = "active";
        }
        if ($user->privChair) {
            $ts[] = "all";
        }
        return $ts;
    }

    /** @param list<string> $limits
     * @param ?string $reqtype
     * @return string */
    static function default_limit(Contact $user, $limits, $reqtype = null) {
        if ($reqtype && in_array($reqtype, $limits)) {
            return $reqtype;
        } else if (in_array("active", $limits)
                   && $user->conf->can_pc_view_some_incomplete()) {
            return "active";
        } else {
            return $limits[0] ?? "";
        }
    }

    /** @return list<string> */
    static function viewable_manager_limits(Contact $user) {
        if ($user->privChair) {
            if ($user->conf->has_any_manager()) {
                $ts = ["admin", "alladmin", "s"];
            } else {
                $ts = ["s"];
            }
            array_push($ts, "accepted", "undecided", "all");
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
