<?php
// t_mention.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Mention_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var list<MentionPhrase> */
    public $pc;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        foreach ($conf->pc_members() as $u) {
            $this->pc[] = new MentionPhrase($u, MentionPhrase::TF_NAMED);
        }
    }

    /** @param string $s
     * @param list<MentionPhrase> ...$user_lists
     * @return list<MentionPhrase> */
    function parse_mentions($s, ...$user_lists) {
        if (empty($user_lists)) {
            $user_lists[] = $this->pc;
        }
        return MentionParser::parse($s, ...$user_lists);
    }

    function test_no_mentions() {
        xassert_eqq($this->parse_mentions("nasfn nfdsjan dkn afdsn ndsakn fdsa sdda"), []);
        xassert_eqq($this->parse_mentions("peter@: nasfn nfdsjan dkn afdsn ndsakn fdsa sdda"), []);
    }

    function test_simple() {
        $mpxs = $this->parse_mentions("@Mary@fart @Mary: @Deborah, @Peter Danzig---@Vera:");
        xassert_eqq(count($mpxs), 4);
        xassert_eqq($mpxs[0]->user->email, "mgbaker@cs.stanford.edu");
        xassert_eqq($mpxs[0]->pos1, 11);
        xassert_eqq($mpxs[0]->pos2, 16);
        xassert_eqq($mpxs[1]->user->email, "estrin@usc.edu");
        xassert_eqq($mpxs[1]->pos1, 18);
        xassert_eqq($mpxs[1]->pos2, 26);
        xassert_eqq($mpxs[2]->user->email, "peter.danzig@usc.edu");
        xassert_eqq($mpxs[2]->pos1, 28);
        xassert_eqq($mpxs[2]->pos2, 41);
        xassert_eqq($mpxs[3]->user->email, "vera@bombay.com");
        xassert_eqq($mpxs[3]->pos1, 44);
        xassert_eqq($mpxs[3]->pos2, 49);
    }

    function test_space() {
        $mpxs = $this->parse_mentions("@Peter\n   Danzig\n\n@Peter\n\nDanzig---@Vera");
        xassert_eqq(count($mpxs), 2);
        xassert_eqq($mpxs[0]->user->email, "peter.danzig@usc.edu");
        xassert_eqq($mpxs[0]->pos1, 0);
        xassert_eqq($mpxs[0]->pos2, 16);
        xassert_eqq($mpxs[1]->user->email, "vera@bombay.com");
        xassert_eqq($mpxs[1]->pos1, 35);
        xassert_eqq($mpxs[1]->pos2, 40);
    }

    function test_accents() {
        $mpxs = $this->parse_mentions("@paul @päul @véra");
        xassert_eqq(count($mpxs), 2);
        xassert_eqq($mpxs[0]->user->email, "pfrancis@ntt.jp");
        xassert_eqq($mpxs[0]->pos1, 0);
        xassert_eqq($mpxs[0]->pos2, 5);
        xassert_eqq($mpxs[1]->user->email, "vera@bombay.com");
        xassert_eqq($mpxs[1]->pos1, 13);
        xassert_eqq($mpxs[1]->pos2, 19);
    }

    function test_email() {
        $mpxs = $this->parse_mentions("oh that's @vera@bombay.com hello there");
        xassert_eqq(count($mpxs), 1);
        xassert_eqq($mpxs[0]->user->email, "vera@bombay.com");
        xassert_eqq($mpxs[0]->pos1, 10);
        xassert_eqq($mpxs[0]->pos2, 26);
    }

    function test_initials_hyphens() {
        $mpxs = $this->parse_mentions("Does @J. J. work? Does @Garcia-Luna-Aceves work?");
        xassert_eqq(count($mpxs), 2);
        xassert_eqq($mpxs[0]->user->email, "jj@cse.ucsc.edu");
        xassert_eqq($mpxs[0]->pos1, 5);
        xassert_eqq($mpxs[0]->pos2, 11);
        xassert_eqq($mpxs[1]->user->email, "jj@cse.ucsc.edu");
        xassert_eqq($mpxs[1]->pos1, 23);
        xassert_eqq($mpxs[1]->pos2, 42);

        $mpxs = $this->parse_mentions("Does @J.J. work?");
        xassert_eqq(count($mpxs), 1);
        xassert_eqq($mpxs[0]->user->email, "jj@cse.ucsc.edu");
        xassert_eqq($mpxs[0]->pos1, 5);
        xassert_eqq($mpxs[0]->pos2, 10);
    }

    function test_ambiguous_first() {
        $mpxs = $this->parse_mentions("a @Peter Danzig b @Peter c @Peter Druschel d");
        xassert_eqq(count($mpxs), 2);
        xassert_eqq($mpxs[0]->user->email, "peter.danzig@usc.edu");
        xassert_eqq($mpxs[0]->pos1, 2);
        xassert_eqq($mpxs[0]->pos2, 15);
        xassert_eqq($mpxs[1]->user->email, "pdruschel@cs.rice.edu");
        xassert_eqq($mpxs[1]->pos1, 27);
        xassert_eqq($mpxs[1]->pos2, 42);

        $mpxs = $this->parse_mentions("a @Peter Danzig b @Peter c @Peter Druschel d");
        xassert_eqq(count($mpxs), 2);
        xassert_eqq($mpxs[0]->user->email, "peter.danzig@usc.edu");
        xassert_eqq($mpxs[0]->pos1, 2);
        xassert_eqq($mpxs[0]->pos2, 15);
        xassert_eqq($mpxs[1]->user->email, "pdruschel@cs.rice.edu");
        xassert_eqq($mpxs[1]->pos1, 27);
        xassert_eqq($mpxs[1]->pos2, 42);
    }

    function test_priorities() {
        $user_pdanzig = $this->conf->pc_member_by_email("PETER.DANZIG@usc.edu");
        $mxm_pdanzig = new MentionPhrase($user_pdanzig, MentionPhrase::TF_NAMED);
        $mpxs = $this->parse_mentions("a @Peter Danzig b @Peter c @Peter Druschel d", [$mxm_pdanzig], $this->pc);
        xassert_eqq(count($mpxs), 3);
        xassert_eqq($mpxs[0]->user->email, "peter.danzig@usc.edu");
        xassert_eqq($mpxs[0]->pos1, 2);
        xassert_eqq($mpxs[0]->pos2, 15);
        xassert_eqq($mpxs[1]->user->email, "peter.danzig@usc.edu");
        xassert_eqq($mpxs[1]->pos1, 18);
        xassert_eqq($mpxs[1]->pos2, 24);
        xassert_eqq($mpxs[2]->user->email, "pdruschel@cs.rice.edu");
        xassert_eqq($mpxs[2]->pos1, 27);
        xassert_eqq($mpxs[2]->pos2, 42);

        $user_pdruschel = $this->conf->pc_member_by_email("pdruschel@cs.rice.edu");
        $mxm_pdruschel = new MentionPhrase($user_pdruschel, MentionPhrase::TF_NAMED);
        $mpxs = $this->parse_mentions("A @PETER DANZIG B @PETER C @PETER DRUSCHEL D", [$mxm_pdruschel], $this->pc);
        xassert_eqq(count($mpxs), 3);
        xassert_eqq($mpxs[0]->user->email, "peter.danzig@usc.edu");
        xassert_eqq($mpxs[0]->pos1, 2);
        xassert_eqq($mpxs[0]->pos2, 15);
        xassert_eqq($mpxs[1]->user->email, "pdruschel@cs.rice.edu");
        xassert_eqq($mpxs[1]->pos1, 18);
        xassert_eqq($mpxs[1]->pos2, 24);
        xassert_eqq($mpxs[2]->user->email, "pdruschel@cs.rice.edu");
        xassert_eqq($mpxs[2]->pos1, 27);
        xassert_eqq($mpxs[2]->pos2, 42);
    }

    function test_name_email_prefix() {
        $user_jon = $this->conf->pc_member_by_email("jon@cs.ucl.ac.uk");
        $mxm_jon = new MentionPhrase($user_jon, MentionPhrase::TF_NAMED);
        $mpxs = $this->parse_mentions("@Jon Crowcroft fun", [$mxm_jon], $this->pc);
        xassert_eqq(count($mpxs), 1);
        xassert_eqq($mpxs[0]->user->email, "jon@cs.ucl.ac.uk");
        xassert_eqq($mpxs[0]->pos1, 0);
        xassert_eqq($mpxs[0]->pos2, 14);

        $mpxs = $this->parse_mentions("@pdruschel HELLO", [$mxm_jon], $this->pc);
        xassert_eqq(count($mpxs), 1);
        xassert_eqq($mpxs[0]->user->email, "pdruschel@cs.rice.edu");
        xassert_eqq($mpxs[0]->pos1, 0);
        xassert_eqq($mpxs[0]->pos2, 10);
    }

    /** @return list<string> */
    private function mention_names(Contact $user, PaperInfo $prow) {
        $jr = call_api_result("mentioncompletion", $user, ["p" => $prow->paperId], $prow);
        $ns = [];
        foreach ($jr->content["mentioncompletion"] ?? [] as $m) {
            $ns[] = $m["s"] ?? $m["sm1"] ?? "";
        }
        return $ns;
    }

    function test_mentioncompletion_without_paper() {
        // `p` is optional for this endpoint, so a paperless call must still work
        foreach (["chair@_.com", "estrin@usc.edu"] as $email) {
            $u = $this->conf->checked_user_by_email($email);
            $jr = call_api_result("mentioncompletion", $u, []);
            xassert_eqq($jr->status ?? 200, 200);
            xassert_neqq($jr->content["mentioncompletion"] ?? [], []);
        }
    }

    function test_mentioncompletion_for_author_without_comment_rights() {
        // an author who can't start comments may still write a response, so
        // completion offers the paper's names (co-authors), not the whole PC
        $conf = $this->conf;
        $old_cmt_author = $conf->setting("cmt_author");
        $conf->save_refresh_setting("cmt_author", null);
        $prow = $conf->checked_paper_by_id(2);
        $au = $conf->checked_user_by_email("micke@cdt.luth.se");
        xassert(!$au->isPC && $prow->has_author($au));
        xassert_eqq($au->new_comment_flags($prow), 0);
        $names = $this->mention_names($au, $prow);
        $coauthors = 0;
        foreach ($prow->author_list() as $a) {
            if (strcasecmp($a->email, $au->email) !== 0
                && in_array(Text::name($a->firstName, $a->lastName, $a->email, NAME_P), $names, true)) {
                ++$coauthors;
            }
        }
        xassert_gt($coauthors, 0);
        foreach ($conf->pc_members() as $pc) {
            if (!$prow->has_author($pc)) {
                xassert_not_in_eqq($pc->name(NAME_P), $names);
            }
        }
        $conf->save_refresh_setting("cmt_author", $old_cmt_author);
    }

    /** @param int $ctype
     * @return int */
    private function add_shepherd_comment($ctype) {
        $conf = $this->conf;
        $conf->qe("insert into PaperComment set paperId=13, contactId=?, timeModified=?, comment=?, commentType=?, commentRound=0, replyTo=0",
            $conf->checked_user_by_email("estrin@usc.edu")->contactId,
            Conf::$now, "shepherd note", $ctype);
        return $conf->dblink->insert_id;
    }

    function test_mentioncompletion_gates_shepherd_existence() {
        $conf = $this->conf;
        // let the author comment as well as respond
        $old_cmt_author = $conf->setting("cmt_author");
        $old_cmt_always = $conf->setting("cmt_always");
        $conf->save_refresh_setting("cmt_author", 2);
        $conf->save_refresh_setting("cmt_always", 1);
        $chair = $conf->checked_user_by_email("chair@_.com");
        $shepherd = $conf->checked_user_by_email("estrin@usc.edu");
        xassert_assign($chair, "paper,action,user\n13,shepherd,{$shepherd->email}\n");
        $prow = $conf->checked_paper_by_id(13);

        // an author of #13 may see neither the decision nor the shepherd
        $au = $conf->checked_user_by_email("vern@ee.lbl.gov");
        xassert(!$au->isPC && $au->can_view_paper($prow));
        xassert(!$au->can_view_decision($prow));
        xassert(!$au->can_view_shepherd($prow));
        xassert_not_in_eqq("Shepherd", $this->mention_names($au, $prow));

        // a comment the author sees as “Shepherd” makes the shepherd apparent
        $cmtid = $this->add_shepherd_comment(CommentInfo::CTVIS_AUTHOR
            | CommentInfo::CT_TOPIC_PAPER | CommentInfo::CT_BYSHEPHERD);
        $prow = $conf->checked_paper_by_id(13);
        xassert_eqq(count($prow->viewable_comment_skeletons($au)), 1);
        xassert_in_eqq("Shepherd", $this->mention_names($au, $prow));
        $conf->qe("delete from PaperComment where commentId=?", $cmtid);

        // ... but an unlabeled comment that merely happens to be by the
        // shepherd does not: the author can’t tell who wrote it
        $cmtid = $this->add_shepherd_comment(CommentInfo::CTVIS_AUTHOR
            | CommentInfo::CT_TOPIC_PAPER);
        $prow = $conf->checked_paper_by_id(13);
        xassert_eqq(count($prow->viewable_comment_skeletons($au)), 1);
        xassert_not_in_eqq("Shepherd", $this->mention_names($au, $prow));
        $conf->qe("delete from PaperComment where commentId=?", $cmtid);

        // ... and neither does a visible decision
        xassert_assign($chair, "paper,action,decision\n13,decision,yes\n");
        $conf->save_refresh_setting("au_seedec", Conf::AUSEEREV_YES);
        $prow = $conf->checked_paper_by_id(13);
        $au = $conf->checked_user_by_email("vern@ee.lbl.gov");
        xassert($au->can_view_decision($prow));
        xassert(!$au->can_view_shepherd($prow));
        xassert_in_eqq("Shepherd", $this->mention_names($au, $prow));

        $conf->save_refresh_setting("au_seedec", null);
        xassert_assign($chair, "paper,action,decision\n13,cleardecision,yes\n");
        xassert_assign($chair, "paper,action,user\n13,clearshepherd,{$shepherd->email}\n");
        $conf->save_refresh_setting("cmt_author", $old_cmt_author);
        $conf->save_refresh_setting("cmt_always", $old_cmt_always);
    }
}
