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

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_floyd = $conf->checked_user_by_email("floyd@ee.lbl.gov");
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
        xassert($j->conflict === true);
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
}
