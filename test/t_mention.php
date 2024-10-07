<?php
// t_mention.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Mention_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var array<int,Contact> */
    public $pc;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->pc = $conf->pc_members();
    }

    /** @param string $s
     * @param array<Contact|Author> ...$user_lists
     * @return list<MentionPhrase> */
    function parse_mentions($s, ...$user_lists) {
        if (empty($user_lists)) {
            $user_lists = [$this->pc];
        }
        return iterator_to_array(MentionParser::parse($s, ...$user_lists));
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
        $mpxs = $this->parse_mentions("a @Peter Danzig b @Peter c @Peter Druschel d", [$user_pdanzig], $this->pc);
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
        $mpxs = $this->parse_mentions("A @PETER DANZIG B @PETER C @PETER DRUSCHEL D", [$user_pdruschel], $this->pc);
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
        $mpxs = $this->parse_mentions("@Jon Crowcroft fun", [$user_jon], $this->pc);
        xassert_eqq(count($mpxs), 1);
        xassert_eqq($mpxs[0]->user->email, "jon@cs.ucl.ac.uk");
        xassert_eqq($mpxs[0]->pos1, 0);
        xassert_eqq($mpxs[0]->pos2, 14);

        $mpxs = $this->parse_mentions("@pdruschel HELLO", [$user_jon], $this->pc);
        xassert_eqq(count($mpxs), 1);
        xassert_eqq($mpxs[0]->user->email, "pdruschel@cs.rice.edu");
        xassert_eqq($mpxs[0]->pos1, 0);
        xassert_eqq($mpxs[0]->pos2, 10);
    }
}
