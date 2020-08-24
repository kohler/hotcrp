<?php
// confinvariants.php -- HotCRP invariant checker
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class ConfInvariants {
    /** @var Conf */
    public $conf;
    /** @var array<string,true> */
    public $problems = [];

    /** @var ?list<string> */
    static private $invariant_row = null;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    private function invariantq($q, $args = []) {
        $result = $this->conf->ql_apply($q, $args);
        if (!Dbl::is_error($result)) {
            self::$invariant_row = $result->fetch_row();
            $result->close();
            return !!self::$invariant_row;
        } else {
            return null;
        }
    }

    private function invariant_error($abbrev, $text = null) {
        $this->problems[$abbrev] = true;
        if ((string) $text === "") {
            $text = $abbrev;
        }
        foreach (self::$invariant_row ?? [] as $i => $v) {
            $text = str_replace("{{$i}}", $v, $text);
        }
        trigger_error("{$this->conf->dbname} invariant error: $text");
    }

    /** @return bool */
    function ok() {
        return empty($this->problems);
    }

    /** @return $this */
    function exec_main() {
        // local invariants
        $any = $this->invariantq("select paperId from Paper where timeSubmitted>0 and timeWithdrawn>0 limit 1");
        if ($any) {
            $this->invariant_error("submitted_withdrawn", "paper #{0} is both submitted and withdrawn");
        }

        // settings correctly materialize database facts
        $any = $this->invariantq("select paperId from Paper where timeSubmitted>0 limit 1");
        if ($any !== !($this->conf->setting("no_papersub") ?? false)) {
            $this->invariant_error("no_papersub");
        }

        $any = $this->invariantq("select paperId from Paper where outcome>0 and timeSubmitted>0 limit 1");
        if ($any !== !!($this->conf->setting("paperacc") ?? false)) {
            $this->invariant_error("paperacc");
        }

        $any = $this->invariantq("select reviewId from PaperReview where reviewToken!=0 limit 1");
        if ($any !== !!($this->conf->setting("rev_tokens") ?? false)) {
            $this->invariant_error("rev_tokens");
        }

        $any = $this->invariantq("select paperId from Paper where leadContactId>0 or shepherdContactId>0 limit 1");
        if ($any !== !!($this->conf->setting("paperlead") ?? false)) {
            $this->invariant_error("paperlead");
        }

        $any = $this->invariantq("select paperId from Paper where managerContactId>0 limit 1");
        if ($any !== !!($this->conf->setting("papermanager") ?? false)) {
            $this->invariant_error("papermanager");
        }

        $any = $this->invariantq("select paperId from PaperReview where reviewType=" . REVIEW_META . " limit 1");
        if ($any !== !!($this->conf->setting("metareviews") ?? false)) {
            $this->invariant_error("metareviews");
        }

        $result = $this->conf->ql("select paperId, dataOverflow from Paper where dataOverflow is not null");
        while (($row = $result->fetch_row())) {
            if (json_decode($row[1]) === null) {
                $this->invariant_error("#{$row[0]}: invalid dataOverflow");
            }
        }
        Dbl::free($result);

        // no empty text options
        $text_options = array();
        foreach ($this->conf->options() as $ox) {
            if ($ox->type === "text") {
                $text_options[] = $ox->id;
            }
        }
        if (count($text_options)) {
            $any = $this->invariantq("select paperId from PaperOption where optionId?a and data='' limit 1", [$text_options]);
            if ($any) {
                $this->invariant_error("text_option_empty", "text option with empty text");
            }
        }

        // no funky PaperConflict entries
        $any = $this->invariantq("select paperId from PaperConflict where conflictType<=0 limit 1");
        if ($any) {
            $this->invariant_error("PaperConflict_zero", "PaperConflict with zero conflictType");
        }

        // reviewNeedsSubmit is defined correctly
        $any = $this->invariantq("select r.paperId, r.reviewId from PaperReview r
            left join (select paperId, requestedBy, count(reviewId) ct, count(reviewSubmitted) cs
                       from PaperReview where reviewType<" . REVIEW_SECONDARY . "
                       group by paperId, requestedBy) q
                on (q.paperId=r.paperId and q.requestedBy=r.contactId)
            where r.reviewType=" . REVIEW_SECONDARY . " and reviewSubmitted is null
            and if(coalesce(q.ct,0)=0,1,if(q.cs=0,-1,0))!=r.reviewNeedsSubmit
            limit 1");
        if ($any) {
            $this->invariant_error("reviewNeedsSubmit", "bad reviewNeedsSubmit for review #{0}/{1}");
        }

        // review rounds are defined
        $result = $this->conf->qe("select reviewRound, count(*) from PaperReview group by reviewRound");
        $defined_rounds = $this->conf->defined_round_list();
        while (($row = $result->fetch_row())) {
            if (!isset($defined_rounds[$row[0]]))
                $this->invariant_error("undefined_review_round", "{$row[1]} PaperReviews for reviewRound {$row[0]}, which is not defined");
        }
        Dbl::free($result);

        // anonymous users are disabled
        $any = $this->invariantq("select email from ContactInfo where email regexp '^anonymous[0-9]*\$' and not disabled limit 1");
        if ($any) {
            $this->invariant_error("anonymous_user_enabled", "anonymous user is not disabled");
        }

        // check tag strings
        $result = $this->conf->qe("select distinct contactTags from ContactInfo where contactTags is not null union select distinct commentTags from PaperComment where commentTags is not null");
        while (($row = $result->fetch_row())) {
            if ($row[0] === "" || !TagMap::is_tag_string($row[0], true)) {
                $this->invariant_error("tag_strings", "bad tag string “{$row[0]}”");
            }
        }
        Dbl::free($result);

        // paper denormalizations match
        $any = $this->invariantq("select p.paperId from Paper p join PaperStorage ps on (ps.paperStorageId=p.paperStorageId) where p.finalPaperStorageId<=0 and p.paperStorageId>1 and (p.sha1!=ps.sha1 or p.size!=ps.size or p.mimetype!=ps.mimetype or p.timestamp!=ps.timestamp) limit 1");
        if ($any) {
            $this->invariant_error("paper_denormalization", "bad Paper denormalization, paper #{0}");
        }
        $any = $this->invariantq("select p.paperId from Paper p join PaperStorage ps on (ps.paperStorageId=p.finalPaperStorageId) where p.finalPaperStorageId>1 and (p.sha1 != ps.sha1 or p.size!=ps.size or p.mimetype!=ps.mimetype or p.timestamp!=ps.timestamp) limit 1");
        if ($any) {
            $this->invariant_error("paper_final_denormalization", "bad Paper final denormalization, paper #{0}");
        }

        // filterType is never zero
        $any = $this->invariantq("select paperStorageId from PaperStorage where filterType=0 limit 1");
        if ($any) {
            $this->invariant_error("filterType", "bad PaperStorage filterType, id #{0}");
        }

        // has_colontag is defined
        $any = $this->invariantq("select tag from PaperTag where tag like '%:' limit 1");
        if ($any && !$this->conf->setting("has_colontag")) {
            $this->invariant_error("has_colontag", "has tag {0} but no has_colontag");
        }

        // has_permtag is defined
        $any = $this->invariantq("select tag from PaperTag where tag like 'perm:%' limit 1");
        if ($any && !$this->conf->setting("has_permtag")) {
            $this->invariant_error("has_permtag", "has tag {0} but no has_permtag");
        }

        // has_topics is defined
        $any = $this->invariantq("select topicId from TopicArea limit 1");
        if (!$any !== !$this->conf->setting("has_topics")) {
            $this->invariant_error("has_topics");
        }

        // autosearches are correct
        $dt = $this->conf->tags();
        if ($dt->has_autosearch) {
            $autosearch_dts = array_values($dt->filter("autosearch"));
            $q = join(" THEN ", array_map(function ($t) {
                return "((" . $t->autosearch . ") XOR #" . $t->tag . ")";
            }, $autosearch_dts));
            $search = new PaperSearch($this->conf->root_user(), ["q" => $q, "t" => "all"]);
            $p = [];
            foreach ($search->paper_ids() as $pid) {
                $then = $search->thenmap[$pid] ?? 0;
                if (!isset($p[$then])) {
                    $dt = $autosearch_dts[$then];
                    $this->invariant_error("autosearch", "autosearch #" . $dt->tag . " disagrees with search " . $dt->autosearch . " on #" . $pid);
                    $p[$then] = true;
                }
            }
        }

        // comments are nonempty
        $any = $this->invariantq("select paperId, commentId from PaperComment where comment is null and commentOverflow is null and not exists (select * from DocumentLink where paperId=PaperComment.paperId and linkId=PaperComment.commentId and linkType>=0 and linkType<1024) limit 1");
        if ($any) {
            $this->invariant_error("empty comment #{0}/{1}");
        }

        // non-draft comments are displayed
        $any = $this->invariantq("select paperId, commentId from PaperComment where timeDisplayed=0 and (commentType&" . COMMENTTYPE_DRAFT . ")=0 limit 1");
        if ($any) {
            $this->invariant_error("submitted comment #{0}/{1} has no timeDisplayed");
        }

        // submitted and ordinaled reviews are displayed
        $any = $this->invariantq("select paperId, reviewId from PaperReview where timeDisplayed=0 and (reviewSubmitted is not null or reviewOrdinal>0) limit 1");
        if ($any) {
            $this->invariant_error("submitted/ordinal review #{0}/{1} has no timeDisplayed");
        }

        return $this;
    }

    /** @return $this */
    function exec_document_inactive() {
        $ie = [];
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
        $any = $this->invariantq("select s.paperId, s.paperStorageId from PaperStorage s where s.paperStorageId?a and s.inactive limit 1", [$pids]);
        if ($any) {
            $this->invariant_error("paper {0} document {1} is inappropriately inactive");
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
            $any = $this->invariantq("select o.paperId, o.optionId, s.paperStorageId from PaperOption o join PaperStorage s on (s.paperStorageId=o.value and s.inactive and s.paperStorageId>1) where o.optionId?a limit 1", [$oids]);
            if ($any) {
                $this->invariant_error("paper {0} option {1} document {2} is inappropriately inactive");
            }

            $any = $this->invariantq("select o.paperId, o.optionId, s.paperStorageId, s.paperId from PaperOption o join PaperStorage s on (s.paperStorageId=o.value and s.paperStorageId>1 and s.paperId!=o.paperId) where o.optionId?a limit 1", [$oids]);
            if ($any) {
                $this->invariant_error("paper {0} option {1} document {2} belongs to different paper {3}");
            }
        }

        if (!empty($nonempty_oids)) {
            $any = $this->invariantq("select o.paperId, o.optionId from PaperOption o where o.optionId?a and o.value<=1 limit 1", [$nonempty_oids]);
            if ($any) {
                $this->invariant_error("paper {0} option {1} links to empty document");
            }
        }

        $any = $this->invariantq("select l.paperId, l.linkId, s.paperStorageId from DocumentLink l join PaperStorage s on (l.documentId=s.paperStorageId and s.inactive) limit 1");
        if ($any) {
            $this->invariant_error("paper {0} link {1} document {2} is inappropriately inactive");
        }

        return $this;
    }

    /** @return $this */
    function exec_all() {
        $this->exec_main();
        $this->exec_document_inactive();
        return $this;
    }

    /** @return bool */
    static function test_all(Conf $conf) {
        return (new ConfInvariants($conf))->exec_all()->ok();
    }

    /** @return bool */
    static function test_document_inactive(Conf $conf) {
        return (new ConfInvariants($conf))->exec_document_inactive()->ok();
    }
}
