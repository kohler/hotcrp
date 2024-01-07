<?php
// confinvariants.php -- HotCRP invariant checker
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class ConfInvariants {
    /** @var Conf */
    public $conf;
    /** @var array<string,true> */
    public $problems = [];
    /** @var string */
    public $prefix;
    /** @var ?list<string> */
    private $irow;
    /** @var ?list<string> */
    private $msgbuf;

    function __construct(Conf $conf, $prefix = "") {
        $this->conf = $conf;
        $this->prefix = $prefix;
    }

    function buffer_messages() {
        $this->msgbuf = $this->msgbuf ?? [];
    }

    /** @return string */
    function take_buffered_messages() {
        assert($this->msgbuf !== null);
        $m = $this->msgbuf;
        $this->msgbuf = null;
        return join("", $m);
    }

    /** @param string $q
     * @param mixed ...$args
     * @return ?bool */
    private function invariantq($q, ...$args) {
        $result = $this->conf->ql_apply($q, $args);
        if (!Dbl::is_error($result)) {
            $this->irow = $result->fetch_row();
            $result->close();
            return $this->irow !== null;
        } else {
            $this->irow = null;
            return null;
        }
    }

    /** @param string $abbrev
     * @param ?string $text
     * @param ?string $no_row_text */
    private function invariant_error($abbrev, $text = null, $no_row_text = null) {
        if (str_starts_with($abbrev, "!")) {
            $abbrev = substr($abbrev, 1);
            if ($this->problems[$abbrev] ?? false) {
                return;
            }
        }
        $this->problems[$abbrev] = true;
        if ($no_row_text !== null && $this->irow === null) {
            $text = $no_row_text;
        } else if ($text === null) {
            $text = $abbrev;
        }
        $text = preg_replace_callback('/\{(\d+)\}/', function ($m) {
            $v = $this->irow[$m[1]] ?? "";
            if (!is_usascii($v)) {
                $v = bin2hex($v);
                if (str_starts_with($v, "736861322d") && strlen($v) === 74) {
                    $v = "sha2-" . substr($v, 10);
                }
            }
            return $v;
        }, $text);
        $msg = "{$this->prefix}{$this->conf->dbname} invariant violation: {$text}";
        if ($this->msgbuf !== null) {
            $this->msgbuf[] = $msg . "\n";
        } else {
            error_log($msg);
        }
    }

    /** @return bool */
    function ok() {
        return empty($this->problems);
    }

    /** @return $this */
    function check_settings() {
        foreach ($this->conf->decision_set() as $dinfo) {
            if (($dinfo->id > 0) !== (($dinfo->catbits & DecisionInfo::CAT_YES) !== 0)) {
                $this->invariant_error("decision_id", "decision {$dinfo->id} has wrong category");
            }
        }
        return $this;
    }

    /** @return $this */
    function check_summary_settings() {
        // settings correctly materialize database facts

        // `no_papersub` === no submitted papers
        $any = $this->invariantq("select paperId from Paper where timeSubmitted>0 limit 1");
        if ($any !== !($this->conf->setting("no_papersub") ?? false)) {
            $this->invariant_error("no_papersub", "paper #{0} is submitted but no_papersub is true", "no paper is submitted but no_papersub is false");
        }

        // `paperacc` === any accepted submitted papers
        $any = $this->invariantq("select paperId from Paper where outcome>0 and timeSubmitted>0 limit 1");
        if ($any !== !!($this->conf->setting("paperacc") ?? false)) {
            $this->invariant_error("paperacc", "paper #{0} is accepted but paperacc is false", "no paper is accepted but paperacc is true");
        }

        // `rev_tokens` === any papers with reviewToken
        $any = $this->invariantq("select reviewId from PaperReview where reviewToken!=0 limit 1");
        if ($any !== !!($this->conf->setting("rev_tokens") ?? false)) {
            $this->invariant_error("rev_tokens");
        }

        // `paperlead` === any papers with defined lead or shepherd
        $any = $this->invariantq("select paperId from Paper where leadContactId>0 or shepherdContactId>0 limit 1");
        if ($any !== !!($this->conf->setting("paperlead") ?? false)) {
            $this->invariant_error("paperlead");
        }

        // `papermanager` === any papers with defined manager
        $any = $this->invariantq("select paperId from Paper where managerContactId>0 limit 1");
        if ($any !== !!($this->conf->setting("papermanager") ?? false)) {
            $this->invariant_error("papermanager");
        }

        // `metareviews` === any assigned metareviews
        $any = $this->invariantq("select paperId from PaperReview where reviewType=" . REVIEW_META . " limit 1");
        if ($any !== !!($this->conf->setting("metareviews") ?? false)) {
            $this->invariant_error("metareviews");
        }

        // `has_topics` === any defined topics
        $any = $this->invariantq("select topicId from TopicArea limit 1");
        if (!$any !== !$this->conf->setting("has_topics")) {
            $this->invariant_error("has_topics");
        }

        // `has_colontag` === any tags ending with `:`
        $any = $this->invariantq("select tag from PaperTag where tag like '%:' limit 1");
        if ($any && !$this->conf->setting("has_colontag")) {
            $this->invariant_error("has_colontag", "has tag {0} but no has_colontag");
        }

        return $this;
    }

    /** @return $this */
    function check_papers() {
        // submitted xor withdrawn
        $any = $this->invariantq("select paperId from Paper where timeSubmitted>0 and timeWithdrawn>0 limit 1");
        if ($any) {
            $this->invariant_error("submitted_withdrawn", "paper #{0} is both submitted and withdrawn");
        }

        // `dataOverflow` is JSON
        $result = $this->conf->ql("select paperId, dataOverflow from Paper where dataOverflow is not null");
        while (($row = $result->fetch_row())) {
            if (json_decode($row[1]) === null) {
                $this->invariant_error("dataOverflow", "#{$row[0]}: invalid dataOverflow");
            }
        }
        Dbl::free($result);

        // no empty text options
        $text_options = [];
        foreach ($this->conf->options() as $ox) {
            if ($ox->type === "text") {
                $text_options[] = $ox->id;
            }
        }
        if (count($text_options)) {
            $any = $this->invariantq("select paperId from PaperOption where optionId?a and data='' limit 1", $text_options);
            if ($any) {
                $this->invariant_error("text_option_empty", "text option with empty text");
            }
        }

        // no funky PaperConflict entries
        $any = $this->invariantq("select paperId from PaperConflict where conflictType<=0 limit 1");
        if ($any) {
            $this->invariant_error("PaperConflict_zero", "PaperConflict with zero conflictType");
        }

        // no unknown decisions
        $any = $this->invariantq("select paperId, outcome from Paper where outcome?A", $this->conf->decision_set()->ids());
        if ($any) {
            $this->invariant_error("unknown_decision", "paper #{0} with unknown outcome #{1}");
        }

        return $this;
    }

    /** @return $this */
    function check_reviews() {
        // reviewType is defined correctly
        $any = $this->invariantq("select paperId, reviewId from PaperReview where reviewType<0 and (reviewNeedsSubmit!=0 or reviewSubmitted is not null) limit 1");
        if ($any) {
            $this->invariant_error("negative_reviewType", "bad nonexistent review #{0}/{1}");
        }

        // review rounds are defined
        $result = $this->conf->qe("select reviewRound, count(*) from PaperReview group by reviewRound");
        $defined_rounds = $this->conf->defined_rounds();
        while (($row = $result->fetch_row())) {
            if (!isset($defined_rounds[$row[0]]))
                $this->invariant_error("undefined_review_round", "{$row[1]} PaperReviews for reviewRound {$row[0]}, which is not defined");
        }
        Dbl::free($result);

        // at least one round-0 time setting is defined if round 0 exists
        if (!$this->conf->has_rounds()
            || $this->conf->fetch_ivalue("select exists (select * from PaperReview where reviewRound=0) from dual")) {
            if ($this->conf->setting("pcrev_soft") === null
                && $this->conf->setting("pcrev_hard") === null
                && $this->conf->setting("extrev_soft") === null
                && $this->conf->setting("extrev_hard") === null) {
                $this->invariant_error("round0_settings", "at least one setting for unnamed review round should be present");
            }
        }

        // reviewNeedsSubmit is defined correctly
        $any = $this->invariantq("select r.paperId, r.reviewId from PaperReview r
            left join (select paperId, requestedBy, count(reviewId) ct, count(reviewSubmitted) cs
                       from PaperReview where reviewType>0 and reviewType<" . REVIEW_SECONDARY . "
                       group by paperId, requestedBy) q
                on (q.paperId=r.paperId and q.requestedBy=r.contactId)
            where r.reviewType=" . REVIEW_SECONDARY . " and reviewSubmitted is null
            and if(coalesce(q.ct,0)=0,1,if(q.cs=0,-1,0))!=r.reviewNeedsSubmit
            limit 1");
        if ($any) {
            $this->invariant_error("reviewNeedsSubmit", "bad reviewNeedsSubmit for review #{0}/{1}");
        }

        // submitted and ordinaled reviews are displayed
        $any = $this->invariantq("select paperId, reviewId from PaperReview where timeDisplayed=0 and (reviewSubmitted is not null or reviewOrdinal>0) limit 1");
        if ($any) {
            $this->invariant_error("review_timeDisplayed", "submitted/ordinal review #{0}/{1} has no timeDisplayed");
        }

        return $this;
    }

    /** @return $this */
    function check_comments() {
        // comments are nonempty
        $any = $this->invariantq("select paperId, commentId from PaperComment where comment is null and commentOverflow is null and not exists (select * from DocumentLink where paperId=PaperComment.paperId and linkId=PaperComment.commentId and linkType>=" . DocumentInfo::LINKTYPE_COMMENT_BEGIN . " and linkType<" . DocumentInfo::LINKTYPE_COMMENT_END . ") limit 1");
        if ($any) {
            $this->invariant_error("empty comment #{0}/{1}");
        }

        // non-draft comments are displayed
        $any = $this->invariantq("select paperId, commentId from PaperComment where timeDisplayed=0 and (commentType&" . CommentInfo::CT_DRAFT . ")=0 limit 1");
        if ($any) {
            $this->invariant_error("submitted comment #{0}/{1} has no timeDisplayed");
        }

        return $this;
    }

    /** @return $this */
    function check_responses() {
        // responses have author visibility
        $any = $this->invariantq("select paperId, commentId from PaperComment where (commentType&" . CommentInfo::CT_RESPONSE  . ")!=0 and (commentType&" . CommentInfo::CTVIS_AUTHOR . ")=0 limit 1");
        if ($any) {
            $this->invariant_error("response #{0}/{1} is not author-visible");
        }

        // response rounds make sense
        $any = $this->invariantq("select paperId, commentId from PaperComment where (commentType&" . CommentInfo::CT_RESPONSE  . ")!=0 and commentRound=0 limit 1");
        if ($any) {
            $this->invariant_error("response #{0}/{1} has zero round");
        }
        $any = $this->invariantq("select paperId, commentId from PaperComment where (commentType&" . CommentInfo::CT_RESPONSE  . ")=0 and commentRound!=0 limit 1");
        if ($any) {
            $this->invariant_error("non-response #{0}/{1} has non-zero round");
        }
        $any = $this->invariantq("select paperId, commentId from PaperComment where commentTags like '%response#%' limit 1");
        if ($any) {
            $this->invariant_error("comment #{0}/{1} has `response` tag");
        }

        return $this;
    }

    /** @return $this */
    function check_automatic_tags() {
        $dt = $this->conf->tags();
        $user = $this->conf->root_user();
        $checkers = $qs = [];
        foreach ($dt->entries_having(TagInfo::TF_AUTOMATIC) as $t) {
            $checkers[] = $ch = new ConfInvariant_AutomaticTagChecker($t);
            $qs[] = $ch->clause;
        }

        if (empty($checkers)) {
            return $this;
        }

        $srch = new PaperSearch($user, ["q" => join(" THEN ", $qs), "t" => "all"]);
        $rowset = $user->paper_set(["paperId" => $srch->paper_ids()]);
        $nch = count($checkers);
        foreach ($rowset as $row) {
            for ($chi = $srch->paper_group_index($row->paperId) ?? 0; $chi < $nch; ++$chi) {
                $ch = $checkers[$chi];
                if ($ch->reported) {
                    continue;
                }
                $v0 = $row->tag_value($ch->tag);
                $v1 = $ch->expected_value($row);
                if ($v0 === $v1) {
                    continue;
                }
                if ($v0 === null || $v1 === null) {
                    $this->invariant_error("autosearch", "automatic tag #{$ch->tag} disagrees with search {$ch->dt->automatic_search()} on #{$row->paperId}");
                } else {
                    $this->invariant_error("autosearch", "automatic tag #{$ch->tag} has bad value " . json_encode($v0) . " (expected " . json_encode($v1) . ") on #{$row->paperId}");
                }
                $ch->reported = true;
            }
        }

        $vcheckers = $qs = [];
        foreach ($checkers as $ch) {
            if (!$ch->reported && $ch->value_formula) {
                $vcheckers[] = $ch;
                $qs[] = "#{$ch->tag}";
            }
        }
        if (empty($vcheckers)) {
            return;
        }

        $rowset = $this->conf->paper_set(["q" => join(" OR ", $qs), "t" => "all"]);
        foreach ($rowset as $row) {
            $chi = 0;
            while ($chi < count($vcheckers)) {
                $ch = $vcheckers[$chi];
                $v0 = $row->tag_value($ch->tag);
                $v1 = $v0 !== null ? $ch->expected_value($row) : null;
                if ($v0 !== $v1) {
                    $this->invariant_error("autosearch", "automatic tag #{$ch->tag} has bad value " . json_encode($v0) . " (expected " . json_encode($v1) . ") on #{$row->paperId}");
                    array_splice($vcheckers, $chi, 1);
                } else {
                    ++$chi;
                }
            }
        }

        return $this;
    }

    /** @return $this */
    function check_documents() {
        // paper denormalizations match
        $any = $this->invariantq("select p.paperId, ps.paperId from Paper p join PaperStorage ps on (ps.paperStorageId=p.paperStorageId) where p.paperStorageId>1 and p.paperId!=ps.paperId limit 1");
        if ($any) {
            $this->invariant_error("paper_id_denormalization", "bad PaperStorage link, paper #{0} (storage paper #{1})");
        }
        $any = $this->invariantq("select p.paperId, ps.paperStorageId, p.sha1, p.size, p.mimetype, p.timestamp, ps.sha1, ps.size, ps.mimetype, ps.timestamp from Paper p join PaperStorage ps on (ps.paperStorageId=p.paperStorageId) where p.finalPaperStorageId<=0 and p.paperStorageId>1 and (p.sha1!=ps.sha1 or p.size!=ps.size or p.mimetype!=ps.mimetype or p.timestamp!=ps.timestamp) limit 1");
        if ($any) {
            assert(count($this->irow) === 10);
            for ($n = 2; $n !== 6 && $this->irow[$n] === $this->irow[$n + 4]; ++$n) {
            }
            $this->invariant_error("paper_denormalization", "bad Paper denormalization, document #{0}.{1} ({{$n}}!={" . ($n+4) . "})");
        }
        $any = $this->invariantq("select p.paperId, ps.paperStorageId, p.sha1, p.size, p.mimetype, p.timestamp, ps.sha1, ps.size, ps.mimetype, ps.timestamp from Paper p join PaperStorage ps on (ps.paperStorageId=p.finalPaperStorageId) where p.finalPaperStorageId>1 and (p.sha1!=ps.sha1 or p.size!=ps.size or p.mimetype!=ps.mimetype or p.timestamp!=ps.timestamp) limit 1");
        if ($any) {
            assert(count($this->irow) === 10);
            for ($n = 2; $n !== 6 && $this->irow[$n] === $this->irow[$n + 4]; ++$n) {
            }
            $this->invariant_error("paper_denormalization", "bad Paper final denormalization, document #{0}.{1} ({{$n}}!={" . ($n+4) . "})");
        }

        // filterType is never zero
        $any = $this->invariantq("select paperStorageId from PaperStorage where filterType=0 limit 1");
        if ($any) {
            $this->invariant_error("filterType", "bad PaperStorage filterType, id #{0}");
        }

        return $this;
    }

    /** @return $this */
    function check_users() {
        // load paper authors
        $authors = [];
        $result = $this->conf->qe("select paperId, authorInformation from Paper");
        while (($row = $result->fetch_row())) {
            $pid = intval($row[0]);
            foreach (explode("\n", $row[1]) as $auline) {
                if ($auline !== "") {
                    $au = Author::make_tabbed($auline);
                    if ($au->email !== "" && validate_email($au->email)) {
                        $authors[strtolower($au->email)][] = $pid;
                    }
                }
            }
        }
        Dbl::free($result);

        // load users
        $primary = [];
        $result = $this->conf->qe("select " . $this->conf->user_query_fields() . " from ContactInfo");
        while (($u = $result->fetch_object())) {
            $u->contactId = intval($u->contactId);
            $u->primaryContactId = intval($u->primaryContactId);
            $u->roles = intval($u->roles);
            $u->disabled = intval($u->disabled);
            $u->cflags = intval($u->cflags);
            unset($authors[strtolower($u->email)]);

            // anonymous users are disabled
            if (str_starts_with($u->email, "anonymous")
                && Contact::is_anonymous_email($u->email)
                && ($u->cflags & Contact::CF_UDISABLED) === 0) {
                $this->invariant_error("anonymous_user_enabled", "anonymous user {$u->email} is not disabled");
            }

            // text is utf8
            if (!is_valid_utf8($u->firstName)
                || !is_valid_utf8($u->lastName)
                || !is_valid_utf8($u->affiliation)) {
                $this->invariant_error("user_text_utf8", "user {$u->email} has non-UTF8 text");
            }

            // whitespace is simplified
            $t = " ";
            foreach ([$u->firstName, $u->lastName, $u->email, $u->affiliation] as $s) {
                if ($s !== "")
                    $t .= "{$s} ";
            }
            if (strcspn($t, "\r\n\t") !== strlen($t)
                || strpos($t, "  ") !== false
                || strpos($u->email, " ") !== false) {
                $this->invariant_error("user_whitespace", "user {$u->email}/{$u->contactId} has invalid whitespace");
            }

            // roles have only expected bits
            if (($u->roles & ~Contact::ROLE_DBMASK) !== 0) {
                $this->invariant_error("user_roles", "user {$u->email} has funky roles {$u->roles}");
            }

            // disabled has only expected bits
            if (($u->disabled & Contact::CFM_DISABLEMENT & ~Contact::CFM_DB) !== 0) {
                $this->invariant_error("user_disabled", sprintf("user {$u->email}/{$u->contactId} bad disabled %x", $u->disabled));
            }

            // cflags has only expected bits
            if (($u->cflags & ~Contact::CFM_DB) !== 0) {
                $this->invariant_error("user_cflags", sprintf("user {$u->email}/{$u->contactId} bad cflags %x", $u->cflags));
            }

            // cflags reflects disabled
            if ($u->disabled !== ($u->cflags & Contact::CFM_DISABLEMENT)) {
                $this->invariant_error("user_cflags_disabled", sprintf("user {$u->email}/{$u->contactId} disabled %x unreflected in cflags %x", $u->disabled, $u->cflags));
            }

            // contactTags is a valid tag string
            if ($u->contactTags !== null
                && ($u->contactTags === ""
                    || !TagMap::is_tag_string($u->contactTags, true))) {
                $this->invariant_error("user_tag_strings", "bad user tags ‘{$u->contactTags}’ for {$u->email}/{$u->contactId}");
            }

            // primary contactIds are not negative
            if ($u->primaryContactId < 0) {
                $this->invariant_error("primary_user_negative", "primary user ID for {$u->email} is negative");
            } else if ($u->primaryContactId !== 0) {
                $primary[$u->contactId] = $u->primaryContactId;
            }
        }
        Dbl::free($result);

        // primary contactIds can be resolved in at most 2 rounds
        $tprimary = $primary;
        for ($i = 0; $i !== 2 && !empty($tprimary); ++$i) {
            $nprimary = [];
            foreach ($tprimary as $uid => $puid) {
                if (isset($primary[$puid])) {
                    $nprimary[$uid] = $primary[$puid];
                }
            }
            $tprimary = $nprimary;
        }
        if (!empty($tprimary)) {
            $baduid = (array_keys($tprimary))[0];
            $this->invariant_error("primary_resolvable", "primary user ID for #{$baduid} cannot be quickly resolved");
        }

        // authors are all accounted for
        if (false) { // XXX currently violated by email changes
            foreach ($authors as $lemail => $pids) {
                $this->invariant_error("author_contacts", "author {$lemail} of #{$pids[0]} lacking from database");
            }
        }

        return $this;
    }

    /** @return $this */
    function check_document_inactive() {
        $result = $this->conf->ql("select paperStorageId, finalPaperStorageId from Paper");
        $pids = [];
        while ($result && ($row = $result->fetch_row())) {
            if ($row[0] > 1) {
                $pids[] = (int) $row[0];
            }
            if ($row[1] > 1) {
                $pids[] = (int) $row[1];
            }
        }
        Dbl::free($result);
        sort($pids);
        $any = $this->invariantq("select s.paperId, s.paperStorageId from PaperStorage s where s.paperStorageId?a and s.inactive limit 1", $pids);
        if ($any) {
            $this->invariant_error("inactive", "paper {0} document {1} is inappropriately inactive");
        }

        $oids = $nonempty_oids = [];
        foreach ($this->conf->options()->universal() as $o) {
            if ($o->has_document()) {
                $oids[] = $o->id;
                if (!$o->allow_empty_document())
                    $nonempty_oids[] = $o->id;
            }
        }

        if (!empty($oids)) {
            $any = $this->invariantq("select o.paperId, o.optionId, s.paperStorageId from PaperOption o join PaperStorage s on (s.paperStorageId=o.value and s.inactive and s.paperStorageId>1) where o.optionId?a limit 1", $oids);
            if ($any) {
                $this->invariant_error("inactive", "paper {0} option {1} document {2} is inappropriately inactive");
            }

            $any = $this->invariantq("select o.paperId, o.optionId, s.paperStorageId, s.paperId from PaperOption o join PaperStorage s on (s.paperStorageId=o.value and s.paperStorageId>1 and s.paperId!=o.paperId) where o.optionId?a limit 1", $oids);
            if ($any) {
                $this->invariant_error("paper {0} option {1} document {2} belongs to different paper {3}");
            }
        }

        if (!empty($nonempty_oids)) {
            $any = $this->invariantq("select o.paperId, o.optionId from PaperOption o where o.optionId?a and o.value<=1 limit 1", $nonempty_oids);
            if ($any) {
                $this->invariant_error("paper {0} option {1} links to empty document");
            }
        }

        $any = $this->invariantq("select l.paperId, l.linkId, s.paperStorageId from DocumentLink l join PaperStorage s on (l.documentId=s.paperStorageId and s.inactive) limit 1");
        if ($any) {
            $this->invariant_error("inactive", "paper {0} link {1} document {2} is inappropriately inactive");
        }

        return $this;
    }

    /** @return $this */
    function check_all() {
        $ro = new ReflectionObject($this);
        foreach ($ro->getMethods() as $m) {
            if (str_starts_with($m->name, "check_")
                && $m->name !== "check_all") {
                $this->{$m->name}();
            }
        }
        return $this;
    }

    /** @param ?string $prefix
     * @return bool */
    static function test_all(Conf $conf, $prefix = null) {
        $prefix = $prefix ?? caller_landmark() . ": ";
        return (new ConfInvariants($conf, $prefix))->check_all()->ok();
    }

    /** @param ?string $prefix
     * @return bool */
    static function test_summary_settings(Conf $conf, $prefix = null) {
        $prefix = $prefix ?? caller_landmark() . ": ";
        return (new ConfInvariants($conf, $prefix))->check_summary_settings()->ok();
    }

    /** @param ?string $prefix
     * @return bool */
    static function test_document_inactive(Conf $conf, $prefix = null) {
        $prefix = $prefix ?? caller_landmark() . ": ";
        return (new ConfInvariants($conf, $prefix))->check_document_inactive()->ok();
    }
}

class ConfInvariant_AutomaticTagChecker {
    /** @var string */
    public $tag;
    /** @var TagInfo */
    public $dt;
    /** @var Contact */
    public $user;
    /** @var string */
    public $clause;
    /** @var SearchTerm */
    public $term;
    /** @var ?float */
    public $value_constant;
    /** @var ?callable(PaperInfo,?int,Contact):mixed */
    public $value_formula;
    /** @var bool */
    public $reported = false;

    function __construct(TagInfo $dt) {
        $this->tag = $dt->tag;
        $this->dt = $dt;
        $this->user = $dt->conf->root_user();
        $this->term = (new PaperSearch($this->user, [
            "q" => $dt->automatic_search() ?? "ALL", "t" => "all"
        ]))->full_term();
        $ftext = $dt->automatic_formula_expression();
        if (($ftext ?? "0") === "0") {
            $this->value_constant = 0.0;
            $vsfx = "#0";
        } else {
            $f = new Formula($ftext);
            if ($f->check($this->user)) {
                $this->value_formula = $f->compile_function();
            }
            $vsfx = "";
        }
        $this->clause = "(({$dt->automatic_search()}) XOR #{$dt->tag}{$vsfx})";
    }

    /** @return ?float */
    function expected_value(PaperInfo $row) {
        if (!$this->term->test($row, null)) {
            return null;
        } else if ($this->value_formula) {
            $v = call_user_func($this->value_formula, $row, null, $this->user);
            if (is_bool($v)) {
                $v = $v ? 0.0 : null;
            } else if (is_int($v)) {
                $v = (float) $v;
            }
            return $v;
        } else {
            return $this->value_constant;
        }
    }
}
