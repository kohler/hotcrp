<?php
// t_comments.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Comments_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact
     * @readonly */
    public $u_floyd;
    /** @var Contact
     * @readonly */
    public $u_mgbaker;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_floyd = $conf->checked_user_by_email("floyd@ee.lbl.gov");
        $this->u_mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
    }

    /** Ensure mgbaker has a submitted review on paper 1. The mention tests
     * below rely on this: under VIEWREV_UNLESSINCOMPLETE a reviewer must
     * finish their own review before they can view others' comments, so an
     * unsubmitted mgbaker is neither notified nor anonymized as a reviewer.
     * In the normal test06/test08 ordering Reviews_Tester establishes this;
     * doing it here lets Comments_Tester also pass when run on its own.
     * Idempotent: a no-op once the review is submitted. */
    private function ensure_paper1_review(PaperInfo $paper1) {
        $rrow = $paper1->review_by_user($this->u_mgbaker);
        if ($rrow && $rrow->reviewStatus >= ReviewInfo::RS_COMPLETED) {
            return;
        }
        $tf = new ReviewValues($this->conf);
        xassert($tf->parse_json(["ovemer" => 2, "revexp" => 1, "papsum" => "No summary", "comaut" => "No comments"]));
        xassert($tf->check_and_save($this->u_mgbaker, $paper1));
    }

    function test_responses() {
        $paper1 = $this->conf->checked_paper_by_id(1);
        $j = call_api("=comment", $this->u_floyd, ["response" => "1", "text" => "Hello"], $paper1);
        xassert(!$j->ok);
        if (!$j->ok) {
            xassert_match($j->message_list[0]->message, '/closed|not open/');
        }

        $sv = SettingValues::make_request($this->u_chair, [
            "review_open" => "1",
            "has_response" => "1",
            "response_active" => "1",
            "response/1/id" => "1",
            "response/1/name" => "",
            "response/1/open" => "@" . (Conf::$now - 1),
            "response/1/done" => "@" . (Conf::$now + 10000)
        ]);
        xassert($sv->execute());

        $j = call_api("=comment", $this->u_floyd, ["response" => "1", "text" => "Hello"], $paper1);
        xassert($j->ok);
        xassert(is_object($j->comment));
        xassert($j->comment->text === "Hello");
        $cid = $j->comment->cid;

        // check response comment tags
        $cmt = ($paper1->fetch_comments("commentId={$cid}"))[0];
        assert(!!$cmt);
        xassert($cmt->has_tag("response"));
        xassert($cmt->has_tag("unnamedresponse"));

        $sv = SettingValues::make_request($this->u_chair, [
            "has_response" => "1",
            "response_active" => "1",
            "response/1/id" => "1",
            "response/1/name" => "R1",
        ]);
        xassert($sv->execute());

        $cmt = ($paper1->fetch_comments("commentId={$cid}"))[0];
        assert(!!$cmt);
        xassert($cmt->has_tag("response"));
        xassert(!$cmt->has_tag("unnamedresponse"));
        xassert($cmt->has_tag("r1RESPONSE"));

        $sv = SettingValues::make_request($this->u_chair, [
            "has_response" => "1",
            "response_active" => "1",
            "response/1/id" => "1",
            "response/1/name" => "",
        ]);
        xassert($sv->execute());

        // return to response
        $j = call_api("=comment", $this->u_floyd, ["response" => "1", "c" => "new", "text" => "Hi"], $paper1);
        xassert(!$j->ok);
        xassert_match($j->message_list[0]->message, '/concurrent/');
        xassert_eqq($j->conflict, true);
        xassert(is_object($j->comment));
        xassert_eqq($j->comment->text, "Hello");

        $j = call_api("=comment", $this->u_floyd, ["response" => "1", "text" => "Hi"], $paper1);
        xassert($j->ok);
        xassert_eqq($j->comment->text, "Hi");
        xassert_eqq($j->comment->cid, $cid);

        $j = call_api("=comment", $this->u_floyd, ["c" => "response", "text" => "Ho ho ho"], $paper1);
        xassert($j->ok);
        xassert_eqq($j->comment->text, "Ho ho ho");
        xassert_eqq($j->comment->cid, $cid);

        $j = call_api("=comment", $this->u_floyd, ["response" => "1", "c" => (string) $cid, "text" => "Hee hee"], $paper1);
        xassert($j->ok);
        xassert_eqq($j->comment->text, "Hee hee");
        xassert_eqq($j->comment->cid, $cid);

        $j = call_api("comment", $this->u_floyd, ["response" => "1"], $paper1);
        xassert($j->ok);
        xassert_eqq($j->comment->text, "Hee hee");
        xassert_eqq($j->comment->cid, $cid);

        $j = call_api("comment", $this->u_floyd, ["c" => (string) $cid], $paper1);
        xassert($j->ok);
        xassert_eqq($j->comment->text, "Hee hee");
        xassert_eqq($j->comment->cid, $cid);

        $j = call_api("=comment", $this->u_floyd, ["c" => "new", "text" => "Nope"], $paper1);
        xassert(!$j->ok);
        xassert_match($j->message_list[0]->message, '/didn’t write this comment/');

        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Yep"], $paper1);
        xassert($j->ok);
        xassert_eqq($j->comment->text, "Yep");
        xassert_neqq($j->comment->cid, $cid);

        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => ""], $paper1);
        xassert(!$j->ok);
        xassert_match($j->message_list[0]->message, '/empty|required/');
    }

    function test_response_combination() {
        // ensure a PC member has a submitted review
        $paper = $this->conf->checked_paper_by_id(4);
        $reviewer = $this->conf->user_by_email("estrin@usc.edu");
        $tf = new ReviewValues($this->conf);
        xassert($tf->parse_json(["ovemer" => 2, "revexp" => 1, "papsum" => "No summary", "comaut" => "No comments"]));
        xassert($tf->check_and_save($reviewer, $paper));

        // PC author submit a draft response
        MailChecker::clear();
        $pc_author = $this->conf->user_by_email("varghese@ccrc.wustl.edu");
        xassert($paper->has_author($pc_author));
        $j = call_api("=comment", $pc_author, ["response" => "1", "text" => "Draft", "draft" => 1], $paper);
        xassert($j->ok);

        // non-PC author submits a non-draft response;
        // the original PC author should get a *separate* notification
        $author = $this->conf->user_by_email("plattner@tik.ee.ethz.ch");
        xassert($paper->has_author($author));
        $j = call_api("=comment", $author, ["response" => "1", "text" => "Non-Draft"], $paper);
        xassert($j->ok);
        MailChecker::check_db("t_comments-response-combination");
    }

    function test_multiple_mentions() {
        $paper1 = $this->conf->checked_paper_by_id(1);
        $this->ensure_paper1_review($paper1);
        MailChecker::clear();
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "@Christian Huitema @Christian Huitema @Christian Huitema Hello"], $paper1);
        xassert($j->ok);
        MailChecker::check_db("t_comments-multiple-mentions");
    }

    function test_email_mentions() {
        xassert_eqq($this->conf->setting("viewrev"), null);
        $this->conf->save_refresh_setting("viewrev", 1);
        $paper1 = $this->conf->checked_paper_by_id(1);
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "@Christian Huitema @rguerin @christophe.diot @d.francis2 @veraxx"], $paper1);
        xassert($j->ok);
        MailChecker::check_db("t_comments-email-mentions");
        $this->conf->save_refresh_setting("viewrev", null);
    }

    function test_mention_censor() {
        // paper1 wants at least two submitted reviews: mgbaker (Reviewer A,
        // ordinal 1) then lixia (Reviewer B, ordinal 2)
        $paper1 = $this->conf->checked_paper_by_id(1);
        $this->ensure_paper1_review($paper1);
        $reviewer = $this->conf->user_by_email("lixia@cs.ucla.edu");
        $tf = new ReviewValues($this->conf);
        xassert($tf->parse_json(["ovemer" => 2, "revexp" => 1, "papsum" => "Flanges", "comaut" => "On the Wilbur Cross Parkway, December 15, 2024"]));
        xassert($tf->check_and_save($reviewer, $paper1));
        MailChecker::clear();

        $this->conf->save_setting("viewrev", Conf::VIEWREV_UNLESSINCOMPLETE);
        $this->conf->save_refresh_setting("tracks", 1, ["_" => ["viewrevid" => "+none"]]);

        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Hello @Reviewer A @Lixia Zhang @Reviewer B @Mary Baker @Christophe Diot"], $paper1);
        xassert($j->ok);
        $cid = $j->comment->cid;

        $paper1->load_comments();
        $cmt = $paper1->comment_by_id($cid);

        $lixia = $this->conf->checked_user_by_email("lixia@cs.ucla.edu");
        xassert($lixia->can_view_comment($paper1, $cmt));
        $cmtj = $cmt->unparse_json($lixia);
        xassert_eqq($cmtj->text, "Hello @Reviewer A @Lixia Zhang @Reviewer B @Reviewer A @Anonymous");
        $pos1a = strpos($cmtj->text, "@Lixia");
        $pos1b = strpos($cmtj->text, "@Reviewer B");
        xassert_eqq($cmtj->my_mentions,
            [[$lixia->contactId, $pos1a, $pos1a + strlen("@Lixia Zhang"), true],
             [$lixia->contactId, $pos1b, $pos1b + strlen("@Reviewer B"), false]]);

        $mgbaker = $this->conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        xassert($mgbaker->can_view_comment($paper1, $cmt));
        $cmtj = $cmt->unparse_json($mgbaker);
        xassert_eqq($cmtj->text, "Hello @Reviewer A @Reviewer B @Reviewer B @Mary Baker @Anonymous");
        $pos1a = strpos($cmtj->text, "@Reviewer A");
        $pos1b = strpos($cmtj->text, "@Mary");
        xassert_eqq($cmtj->my_mentions,
            [[$mgbaker->contactId, $pos1a, $pos1a + strlen("@Reviewer A"), false],
             [$mgbaker->contactId, $pos1b, $pos1b + strlen("@Mary Baker"), true]]);

        $diot = $this->conf->checked_user_by_email("christophe.diot@sophia.inria.fr");
        xassert($diot->can_view_comment($paper1, $cmt));
        $cmtj = $cmt->unparse_json($diot);
        xassert_eqq($cmtj->text, "Hello @Reviewer A @Reviewer B @Reviewer B @Reviewer A @Christophe Diot");
        $pos1a = strpos($cmtj->text, "@Chr");
        xassert_eqq($cmtj->my_mentions,
            [[$diot->contactId, $pos1a, $pos1a + strlen("@Christophe Diot"), true]]);

        MailChecker::check_db("t_comments-mention-censor");

        $this->conf->save_setting("viewrev", null);
        $this->conf->save_refresh_setting("tracks", null);
    }

    function test_attachments() {
        $paper1 = $this->conf->checked_paper_by_id(1);
        $qreq = new Qrequest("POST", ["c" => "new", "text" => "Hello", "attachment:1" => "new"]);
        $qreq->approve_token();
        $qreq->set_file_content("attachment:1:file", "Fart", "text/plain");
        $j = call_api("=comment", $this->u_chair, $qreq, $paper1);
        xassert($j->ok);
        $newid = $j->comment->cid;

        $paper1->load_comments();
        $cmt = $paper1->comment_by_id($newid);
        xassert(!!$cmt);
        xassert_eqq($cmt->content(), "Hello");
        xassert($cmt->has_attachments());
        $dset = $cmt->attachments();
        xassert_eqq(count($dset), 1);
        xassert_eqq($dset->document_by_index(0)->content(), "Fart");

        // posting a new attachment doesn’t delete the old
        $qreq = new Qrequest("POST", ["c" => (string) $newid, "text" => "Hello.", "attachment:1" => "new"]);
        $qreq->approve_token();
        $qreq->set_file_content("attachment:1:file", "Barfo", "text/plain");
        $j = call_api("=comment", $this->u_chair, $qreq, $paper1);
        xassert($j->ok);

        $paper1->load_comments();
        $cmt = $paper1->comment_by_id($j->comment->cid);
        xassert(!!$cmt);
        xassert_eqq($cmt->content(), "Hello.");
        xassert($cmt->has_attachments());
        $dset = $cmt->attachments();
        xassert_eqq(count($dset), 2);
        $attachments = [];
        foreach ($dset as $doc) {
            $attachments[] = $doc->content();
        }
        xassert_eqq($attachments, ["Fart", "Barfo"]);

        // posting without referencing attachments doesn’t delete them
        $qreq = new Qrequest("POST", ["c" => (string) $newid, "text" => "Hello..."]);
        $qreq->approve_token();
        $j = call_api("=comment", $this->u_chair, $qreq, $paper1);
        xassert($j->ok);

        $paper1->load_comments();
        $cmt = $paper1->comment_by_id($j->comment->cid);
        xassert(!!$cmt);
        xassert_eqq($cmt->content(), "Hello...");
        xassert($cmt->has_attachments());
        $dset = $cmt->attachments();
        xassert_eqq(count($dset), 2);
        $attachments = [];
        foreach ($dset as $doc) {
            $attachments[] = $doc->content();
        }
        xassert_eqq($attachments, ["Fart", "Barfo"]);

        // it is possible to delete attachments
        $deldoc = $dset->document_by_index(0);
        $qreq = new Qrequest("POST", ["c" => (string) $newid, "text" => "Hello??", "attachment:1" => $deldoc->paperStorageId, "attachment:1:delete" => 1, "attachment:2" => "new"]);
        $qreq->approve_token();
        $qreq->set_file_content("attachment:2:file", "New", "text/plain");
        $j = call_api("=comment", $this->u_chair, $qreq, $paper1);
        xassert($j->ok);

        $paper1->load_comments();
        $cmt = $paper1->comment_by_id($j->comment->cid);
        xassert(!!$cmt);
        xassert_eqq($cmt->content(), "Hello??");
        xassert($cmt->has_attachments());
        $dset = $cmt->attachments();
        xassert_eqq(count($dset), 2);
        $attachments = [];
        foreach ($dset as $doc) {
            $attachments[] = $doc->content();
        }
        xassert_eqq($attachments, ["Barfo", "New"]);

        // it is possible to delete all attachments
        $deldoc = $dset->document_by_index(0);
        $deldoc2 = $dset->document_by_index(1);
        $qreq = new Qrequest("POST", ["c" => (string) $newid, "text" => "Hello!", "attachment:1" => $deldoc->paperStorageId, "attachment:1:delete" => 1, "attachment:2" => $deldoc2->paperStorageId, "attachment:2:delete" => 1]);
        $qreq->approve_token();
        $j = call_api("=comment", $this->u_chair, $qreq, $paper1);
        xassert($j->ok);

        $paper1->load_comments();
        $cmt = $paper1->comment_by_id($j->comment->cid);
        xassert(!!$cmt);
        xassert_eqq($cmt->content(), "Hello!");
        xassert(!$cmt->has_attachments());
    }

    function test_plain_comment_visibility_topic() {
        $paper1 = $this->conf->checked_paper_by_id(1);

        // create a normal (non-response) comment with explicit visibility + topic
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Visible comment", "visibility" => "rev", "topic" => "paper"], $paper1);
        xassert($j->ok);
        xassert(is_object($j->comment));
        xassert_eqq($j->comment->text, "Visible comment");
        xassert_eqq($j->comment->visibility, "rev");
        xassert_eqq($j->comment->topic, "paper");
        $cid = $j->comment->cid;

        // editing changes visibility + topic, round-tripping through the JSON
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "Visible comment", "visibility" => "au", "topic" => "rev"], $paper1);
        xassert($j->ok);
        xassert_eqq($j->comment->cid, $cid);
        xassert_eqq($j->comment->visibility, "au");
        xassert_eqq($j->comment->topic, "rev");

        // GET the single comment by `c`
        $j = call_api("comment", $this->u_chair, ["c" => (string) $cid], $paper1);
        xassert($j->ok);
        xassert_eqq($j->comment->cid, $cid);
        xassert_eqq($j->comment->text, "Visible comment");

        // GET all comments: the list contains our comment, with content
        $j = call_api("comment", $this->u_chair, [], $paper1);
        xassert($j->ok);
        xassert(is_array($j->comments));
        $found = null;
        foreach ($j->comments as $c) {
            if ($c->cid === $cid)
                $found = $c;
        }
        xassert(!!$found);
        xassert_eqq($found->text, "Visible comment");

        // GET with content=0 omits the comment text
        $j = call_api("comment", $this->u_chair, ["c" => (string) $cid, "content" => "0"], $paper1);
        xassert($j->ok);
        xassert_eqq($j->comment->cid, $cid);
        xassert(!isset($j->comment->text));

        MailChecker::clear();
    }

    function test_comment_blind() {
        $paper1 = $this->conf->checked_paper_by_id(1);
        // blindness is forced by config unless review blindness is optional
        $this->conf->save_refresh_setting("rev_blind", Conf::BLIND_OPTIONAL);

        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Blind on", "blind" => 1], $paper1);
        xassert($j->ok);
        xassert($j->comment->blind === true);

        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Blind off", "blind" => 0], $paper1);
        xassert($j->ok);
        xassert(!isset($j->comment->blind));

        $this->conf->save_refresh_setting("rev_blind", null);
        MailChecker::clear();
    }

    function test_comment_tags() {
        $paper1 = $this->conf->checked_paper_by_id(1);
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Tagged comment", "visibility" => "rev", "tags" => "hot fixme"], $paper1);
        xassert($j->ok);
        $cid = $j->comment->cid;
        xassert(is_array($j->comment->tags));
        // tags are reported with their values (e.g. "hot#0")
        $tagnames = array_map(function ($t) { return explode("#", $t)[0]; }, $j->comment->tags);
        xassert(in_array("hot", $tagnames, true));
        xassert(in_array("fixme", $tagnames, true));

        // tags persist on the stored comment
        $cmt = ($paper1->fetch_comments("commentId={$cid}"))[0];
        xassert(!!$cmt);
        xassert($cmt->has_tag("hot"));
        xassert($cmt->has_tag("fixme"));

        MailChecker::clear();
    }

    function test_comment_delete_flag() {
        $paper1 = $this->conf->checked_paper_by_id(1);
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Doomed comment", "visibility" => "rev"], $paper1);
        xassert($j->ok);
        $cid = $j->comment->cid;

        // explicit delete via `delete` flag
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "delete" => 1], $paper1);
        xassert($j->ok);
        xassert(!isset($j->comment));

        $paper1->load_comments();
        xassert(!$paper1->comment_by_id($cid));

        MailChecker::clear();
    }

    function test_response_c_mismatch() {
        // `response=N` combined with `c=MMM` that names a *different* comment
        // (here a plain comment, not the round-N response) is reported as a
        // concurrent-edit conflict, makes no database change, and returns the
        // named comment's current state.
        $paper1 = $this->conf->checked_paper_by_id(1);
        $sv = SettingValues::make_request($this->u_chair, [
            "review_open" => "1", "has_response" => "1", "response_active" => "1",
            "response/1/id" => "1", "response/1/name" => "",
            "response/1/open" => "@" . (Conf::$now - 1), "response/1/done" => "@" . (Conf::$now + 10000)
        ]);
        xassert($sv->execute());

        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "MMM original", "visibility" => "rev"], $paper1);
        xassert($j->ok);
        $mmm = $j->comment->cid;

        $j = call_api("=comment", $this->u_chair, ["response" => "1", "c" => (string) $mmm, "text" => "should not save"], $paper1);
        xassert(!$j->ok);
        xassert($j->conflict === true);
        xassert_match($j->message_list[0]->message, '/edited concurrently/');
        // the conflict returns the named comment, unchanged
        xassert_eqq($j->comment->cid, $mmm);
        xassert_eqq($j->comment->text, "MMM original");

        // no database change: the comment is untouched and the attempted
        // "should not save" text created nothing
        $paper1->load_comments();
        $cmt = $paper1->comment_by_id($mmm);
        xassert(!!$cmt);
        xassert_eqq($cmt->commentOverflow ?? $cmt->comment, "MMM original");
        foreach ($paper1->all_comments() as $c) {
            xassert_neqq($c->commentOverflow ?? $c->comment, "should not save");
        }

        MailChecker::clear();
    }

    function test_get_response_c_mismatch() {
        // GET `c=NNN&response=1` where comment NNN is not the round-1 response
        $paper1 = $this->conf->checked_paper_by_id(1);
        $sv = SettingValues::make_request($this->u_chair, [
            "review_open" => "1", "has_response" => "1", "response_active" => "1",
            "response/1/id" => "1", "response/1/name" => "",
            "response/1/open" => "@" . (Conf::$now - 1), "response/1/done" => "@" . (Conf::$now + 10000)
        ]);
        xassert($sv->execute());

        // a viewable plain comment: the selector mismatch is a 400
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Plain not a response", "visibility" => "rev"], $paper1);
        xassert($j->ok);
        $cid = $j->comment->cid;
        $jr = call_api_result("comment", $this->u_chair, ["c" => (string) $cid, "response" => "1"], $paper1);
        xassert_eqq($jr->status, 400);
        xassert_eqq($jr->content["message_list"][0]->field, "response");

        // a comment the caller can't see must NOT be disclosed by the mismatch:
        // the permission check wins, so the caller gets not-found, never 400
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Eyes only mismatch", "visibility" => "admin"], $paper1);
        xassert($j->ok);
        $acid = $j->comment->cid;
        $lixia = $this->conf->checked_user_by_email("lixia@cs.ucla.edu");
        xassert($lixia->can_view_paper($paper1));
        $jr = call_api_result("comment", $lixia, ["c" => (string) $acid, "response" => "1"], $paper1);
        xassert(in_array($jr->status, [403, 404], true));
        xassert_neqq($jr->status, 400);

        MailChecker::clear();
    }

    function test_comment_request_errors() {
        $paper1 = $this->conf->checked_paper_by_id(1);

        // nonexistent comment id => 404
        $jr = call_api_result("comment", $this->u_chair, ["c" => "99999999"], $paper1);
        xassert_eqq($jr->status, 404);

        // unknown response round => 404
        $jr = call_api_result("comment", $this->u_chair, ["response" => "99"], $paper1);
        xassert_eqq($jr->status, 404);

        // a non-admin cannot view an admin-only comment
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Eyes only", "visibility" => "admin"], $paper1);
        xassert($j->ok);
        $acid = $j->comment->cid;
        $lixia = $this->conf->checked_user_by_email("lixia@cs.ucla.edu");
        xassert($lixia->can_view_paper($paper1));
        $jr = call_api_result("comment", $lixia, ["c" => (string) $acid], $paper1);
        xassert(in_array($jr->status, [403, 404], true));

        MailChecker::clear();
    }

    function test_response_not_found_message() {
        // ensure response round 1 exists (configured, need not be open)
        $sv = SettingValues::make_request($this->u_chair, [
            "has_response" => "1",
            "response_active" => "1",
            "response/1/id" => "1",
            "response/1/name" => ""
        ]);
        xassert($sv->execute());

        // GET a valid response round with no response posted on this paper
        // => 404, and the message names the response, not "Comment"
        $paper3 = $this->conf->checked_paper_by_id(3);
        xassert(!$paper3->fetch_comments("(commentType&" . CommentInfo::CT_RESPONSE . ")!=0"));
        $jr = call_api_result("comment", $this->u_chair, ["response" => "1"], $paper3);
        xassert_eqq($jr->status, 404);
        xassert_match($jr->content["message_list"][0]->message, '/response not found/i');

        MailChecker::clear();
    }

    function test_comment_attachment_json_shape() {
        $paper1 = $this->conf->checked_paper_by_id(1);
        $qreq = new Qrequest("POST", ["c" => "new", "text" => "Has attachment", "visibility" => "rev", "attachment:1" => "new"]);
        $qreq->approve_token();
        $qreq->set_file_content("attachment:1:file", "FIGURE", "fig.txt", "text/plain");
        $j = call_api("=comment", $this->u_chair, $qreq, $paper1);
        xassert($j->ok);
        $cid = $j->comment->cid;

        // GET the comment and inspect the docs JSON shape used by the round-trip importer
        $j = call_api("comment", $this->u_chair, ["c" => (string) $cid], $paper1);
        xassert($j->ok);
        xassert(isset($j->comment->docs));
        xassert_eqq(count($j->comment->docs), 1);
        $doc = $j->comment->docs[0];
        xassert_eqq($doc->filename, "fig.txt");
        xassert_eqq($doc->mimetype, "text/plain");
        xassert(isset($doc->docid)); // present because the comment is editable

        MailChecker::clear();
    }

    function test_comments_multi() {
        // comments on two different papers
        $paper1 = $this->conf->checked_paper_by_id(1);
        $paper2 = $this->conf->checked_paper_by_id(2);
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Multi one", "visibility" => "rev"], $paper1);
        xassert($j->ok);
        $c1 = $j->comment->cid;
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Multi two", "visibility" => "rev"], $paper2);
        xassert($j->ok);
        $c2 = $j->comment->cid;

        // `comments?q=...` aggregates viewable comments across matching papers
        $j = call_api("comments", $this->u_chair, ["q" => "1 OR 2"]);
        xassert($j->ok);
        xassert(is_array($j->comments));
        $byid = [];
        foreach ($j->comments as $c) {
            $byid[$c->cid] = $c;
        }
        xassert(isset($byid[$c1]));
        xassert(isset($byid[$c2]));
        xassert_eqq($byid[$c1]->text, "Multi one");
        xassert_eqq($byid[$c2]->text, "Multi two");

        // `content=0` omits comment text
        $j = call_api("comments", $this->u_chair, ["q" => "1 OR 2", "content" => "0"]);
        xassert($j->ok);
        foreach ($j->comments as $c) {
            xassert(!isset($c->text));
        }

        // missing `q` => parameter error
        $jr = call_api_result("comments", $this->u_chair, []);
        xassert_eqq($jr->status, 400);
        xassert_match($jr->content["message_list"][0]->message, '/required|missing/i');

        MailChecker::clear();
    }

    function test_comments_multi_paper_param() {
        // `comments?p=N` resolves the paper like a single-paper request and
        // returns just that paper's comments
        $paper1 = $this->conf->checked_paper_by_id(1);
        $paper2 = $this->conf->checked_paper_by_id(2);
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "P-param one", "visibility" => "rev"], $paper1);
        xassert($j->ok);
        $c1 = $j->comment->cid;
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "P-param two", "visibility" => "rev"], $paper2);
        xassert($j->ok);
        $c2 = $j->comment->cid;

        $j = call_api("comments", $this->u_chair, ["p" => "1"]);
        xassert($j->ok);
        xassert(is_array($j->comments));
        $byid = [];
        foreach ($j->comments as $c) {
            $byid[$c->cid] = $c;
        }
        xassert(isset($byid[$c1]));   // paper 1's comment is present
        xassert(!isset($byid[$c2]));  // paper 2's comment is not

        // an unresolvable `p` reports a paper error (not a generic "missing q")
        $jr = call_api_result("comments", $this->u_chair, ["p" => "99999999"]);
        xassert_eqq($jr->status, 404);

        // `q` and `p` together is a conflict error
        $jr = call_api_result("comments", $this->u_chair, ["q" => "1", "p" => "2"]);
        xassert_eqq($jr->status, 400);
        xassert_eqq($jr->content["message_list"][0]->field, "p");
        xassert_match($jr->content["message_list"][0]->message, '/conflict/i');

        MailChecker::clear();
    }

    function test_comments_multi_permission() {
        // an admin-only comment is invisible to a non-admin via `comments`
        $paper1 = $this->conf->checked_paper_by_id(1);
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Admin eyes", "visibility" => "admin"], $paper1);
        xassert($j->ok);
        $acid = $j->comment->cid;

        $lixia = $this->conf->checked_user_by_email("lixia@cs.ucla.edu");
        $j = call_api("comments", $lixia, ["q" => "1"]);
        xassert($j->ok);
        foreach ($j->comments as $c) {
            xassert_neqq($c->cid, $acid);
        }

        MailChecker::clear();
    }
}
