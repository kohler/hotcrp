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
     * @return list<array{Contact,int,int}> */
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
        xassert_eqq($mpxs[0][0]->email, "mgbaker@cs.stanford.edu");
        xassert_eqq($mpxs[0][1], 11);
        xassert_eqq($mpxs[0][2], 16);
        xassert_eqq($mpxs[1][0]->email, "estrin@usc.edu");
        xassert_eqq($mpxs[1][1], 18);
        xassert_eqq($mpxs[1][2], 26);
        xassert_eqq($mpxs[2][0]->email, "peter.danzig@usc.edu");
        xassert_eqq($mpxs[2][1], 28);
        xassert_eqq($mpxs[2][2], 41);
        xassert_eqq($mpxs[3][0]->email, "vera@bombay.com");
        xassert_eqq($mpxs[3][1], 44);
        xassert_eqq($mpxs[3][2], 49);
    }

    function test_space() {
        $mpxs = $this->parse_mentions("@Peter\n   Danzig\n\n@Peter\n\nDanzig---@Vera");
        xassert_eqq(count($mpxs), 2);
        xassert_eqq($mpxs[0][0]->email, "peter.danzig@usc.edu");
        xassert_eqq($mpxs[0][1], 0);
        xassert_eqq($mpxs[0][2], 16);
        xassert_eqq($mpxs[1][0]->email, "vera@bombay.com");
        xassert_eqq($mpxs[1][1], 35);
        xassert_eqq($mpxs[1][2], 40);
    }

    function test_accents() {
        $mpxs = $this->parse_mentions("@paul @päul @véra");
        xassert_eqq(count($mpxs), 2);
        xassert_eqq($mpxs[0][0]->email, "pfrancis@ntt.jp");
        xassert_eqq($mpxs[0][1], 0);
        xassert_eqq($mpxs[0][2], 5);
        xassert_eqq($mpxs[1][0]->email, "vera@bombay.com");
        xassert_eqq($mpxs[1][1], 13);
        xassert_eqq($mpxs[1][2], 19);
    }

    function test_email() {
        $mpxs = $this->parse_mentions("oh that's @vera@bombay.com hello there");
        xassert_eqq(count($mpxs), 1);
        xassert_eqq($mpxs[0][0]->email, "vera@bombay.com");
        xassert_eqq($mpxs[0][1], 10);
        xassert_eqq($mpxs[0][2], 26);
    }

    function test_initials_hyphens() {
        $mpxs = $this->parse_mentions("Does @J. J. work? Does @Garcia-Luna-Aceves work?");
        xassert_eqq(count($mpxs), 2);
        xassert_eqq($mpxs[0][0]->email, "jj@cse.ucsc.edu");
        xassert_eqq($mpxs[0][1], 5);
        xassert_eqq($mpxs[0][2], 11);
        xassert_eqq($mpxs[1][0]->email, "jj@cse.ucsc.edu");
        xassert_eqq($mpxs[1][1], 23);
        xassert_eqq($mpxs[1][2], 42);

        $mpxs = $this->parse_mentions("Does @J.J. work?");
        xassert_eqq(count($mpxs), 1);
        xassert_eqq($mpxs[0][0]->email, "jj@cse.ucsc.edu");
        xassert_eqq($mpxs[0][1], 5);
        xassert_eqq($mpxs[0][2], 10);
    }

    function test_ambiguous_first() {
        $mpxs = $this->parse_mentions("a @Peter Danzig b @Peter c @Peter Druschel d");
        xassert_eqq(count($mpxs), 2);
        xassert_eqq($mpxs[0][0]->email, "peter.danzig@usc.edu");
        xassert_eqq($mpxs[0][1], 2);
        xassert_eqq($mpxs[0][2], 15);
        xassert_eqq($mpxs[1][0]->email, "pdruschel@cs.rice.edu");
        xassert_eqq($mpxs[1][1], 27);
        xassert_eqq($mpxs[1][2], 42);

        $mpxs = $this->parse_mentions("a @Peter Danzig b @Peter c @Peter Druschel d");
        xassert_eqq(count($mpxs), 2);
        xassert_eqq($mpxs[0][0]->email, "peter.danzig@usc.edu");
        xassert_eqq($mpxs[0][1], 2);
        xassert_eqq($mpxs[0][2], 15);
        xassert_eqq($mpxs[1][0]->email, "pdruschel@cs.rice.edu");
        xassert_eqq($mpxs[1][1], 27);
        xassert_eqq($mpxs[1][2], 42);
    }

    function test_priorities() {
        $user_pdanzig = $this->conf->pc_member_by_email("PETER.DANZIG@usc.edu");
        $mpxs = $this->parse_mentions("a @Peter Danzig b @Peter c @Peter Druschel d", [$user_pdanzig], $this->pc);
        xassert_eqq(count($mpxs), 3);
        xassert_eqq($mpxs[0][0]->email, "peter.danzig@usc.edu");
        xassert_eqq($mpxs[0][1], 2);
        xassert_eqq($mpxs[0][2], 15);
        xassert_eqq($mpxs[1][0]->email, "peter.danzig@usc.edu");
        xassert_eqq($mpxs[1][1], 18);
        xassert_eqq($mpxs[1][2], 24);
        xassert_eqq($mpxs[2][0]->email, "pdruschel@cs.rice.edu");
        xassert_eqq($mpxs[2][1], 27);
        xassert_eqq($mpxs[2][2], 42);

        $user_pdruschel = $this->conf->pc_member_by_email("pdruschel@cs.rice.edu");
        $mpxs = $this->parse_mentions("A @PETER DANZIG B @PETER C @PETER DRUSCHEL D", [$user_pdruschel], $this->pc);
        xassert_eqq(count($mpxs), 3);
        xassert_eqq($mpxs[0][0]->email, "peter.danzig@usc.edu");
        xassert_eqq($mpxs[0][1], 2);
        xassert_eqq($mpxs[0][2], 15);
        xassert_eqq($mpxs[1][0]->email, "pdruschel@cs.rice.edu");
        xassert_eqq($mpxs[1][1], 18);
        xassert_eqq($mpxs[1][2], 24);
        xassert_eqq($mpxs[2][0]->email, "pdruschel@cs.rice.edu");
        xassert_eqq($mpxs[2][1], 27);
        xassert_eqq($mpxs[2][2], 42);
    }
}
