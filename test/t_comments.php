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
        xassert_eqq($j->valid, false);
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

    // When a comment is deleted, the activity-log line should describe the
    // comment's *stored* topic, independent of any (about-to-be-discarded)
    // topic carried by the delete request.
    function test_comment_delete_log_topic() {
        $paper1 = $this->conf->checked_paper_by_id(1);

        // create a plain comment on the submission thread (topic=paper)
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Topic log victim", "visibility" => "rev", "topic" => "paper"], $paper1);
        xassert($j->ok);
        xassert_eqq($j->comment->topic, "paper");
        $cid = (int) $j->comment->cid;

        // delete it, but the request carries a *different* topic ("rev").
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "topic" => "rev", "delete" => 1], $paper1);
        xassert($j->ok);

        // the log reflects the stored topic ("on submission thread"),
        // not the discarded request topic ("rev", which has no thread suffix)
        $action = $this->conf->fetch_value("select action from ActionLog where paperId=? and action like ? order by logId desc limit 1",
            $paper1->paperId, "Comment {$cid}%deleted");
        xassert_eqq($action, "Comment {$cid} on submission thread deleted");

        MailChecker::clear();
    }

    // Editing a comment's text *without* supplying a `tags` field should not
    // affect its existing tags.
    function test_comment_edit_preserves_tags() {
        $paper1 = $this->conf->checked_paper_by_id(1);
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Tagged body", "visibility" => "rev", "tags" => "hot"], $paper1);
        xassert($j->ok);
        $cid = (int) $j->comment->cid;
        $cmt = $paper1->fetch_comments("commentId={$cid}")[0];
        xassert(!!$cmt && $cmt->has_tag("hot"));

        // edit the text, supplying no `tags` field at all
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "Tagged body edited", "visibility" => "rev"], $paper1);
        xassert($j->ok);

        $cmt = $paper1->fetch_comments("commentId={$cid}")[0];
        xassert(!!$cmt);
        xassert($cmt->has_tag("hot"));

        MailChecker::clear();
    }

    // Re-saving a comment with identical content should be a no-op: no new
    // "edited" activity-log entry.
    function test_comment_noop_edit() {
        $paper1 = $this->conf->checked_paper_by_id(1);
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Stable comment", "visibility" => "rev"], $paper1);
        xassert($j->ok);
        $cid = (int) $j->comment->cid;

        $like = "Comment {$cid} edited%";
        $before = $this->conf->fetch_ivalue("select count(*) from ActionLog where paperId=? and action like ?", $paper1->paperId, $like);

        // re-save identical content
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "Stable comment", "visibility" => "rev"], $paper1);
        xassert($j->ok);

        $after = $this->conf->fetch_ivalue("select count(*) from ActionLog where paperId=? and action like ?", $paper1->paperId, $like);
        xassert_eqq($after, $before);

        MailChecker::clear();
    }

    // Editing a comment to remove all mentions should clear its stored
    // mention data.
    function test_comment_edit_clears_mentions() {
        $paper1 = $this->conf->checked_paper_by_id(1);
        MailChecker::clear();
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "@Christian Huitema Hello"], $paper1);
        xassert($j->ok);
        $cid = (int) $j->comment->cid;
        $cmt = $paper1->fetch_comments("commentId={$cid}")[0];
        xassert(!!$cmt && !empty($cmt->data("mentions")));

        MailChecker::clear();
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "Hello"], $paper1);
        xassert($j->ok);
        $cmt = $paper1->fetch_comments("commentId={$cid}")[0];
        xassert(!!$cmt);
        xassert_eqq($cmt->data("mentions"), null);
        MailChecker::clear();
    }

    // Adding/removing an attachment without otherwise changing the comment
    // must still persist the attachment change.
    function test_comment_attachment_only_edit() {
        $paper1 = $this->conf->checked_paper_by_id(1);
        $qreq = new Qrequest("POST", ["c" => "new", "text" => "Same text", "attachment:1" => "new"]);
        $qreq->approve_token();
        $qreq->set_file_content("attachment:1:file", "First", "text/plain");
        $j = call_api("=comment", $this->u_chair, $qreq, $paper1);
        xassert($j->ok);
        $cid = (int) $j->comment->cid;

        $paper1->load_comments();
        $cmt = $paper1->comment_by_id($cid);
        xassert(!!$cmt && count($cmt->attachments()) === 1);

        // add a second attachment, keeping text (and everything else) identical
        $qreq = new Qrequest("POST", ["c" => (string) $cid, "text" => "Same text", "attachment:1" => "new"]);
        $qreq->approve_token();
        $qreq->set_file_content("attachment:1:file", "Second", "text/plain");
        $j = call_api("=comment", $this->u_chair, $qreq, $paper1);
        xassert($j->ok);

        $paper1->load_comments();
        $cmt = $paper1->comment_by_id($cid);
        xassert(!!$cmt);
        $contents = [];
        foreach ($cmt->attachments() as $doc) {
            $contents[] = $doc->content();
        }
        xassert_eqq($contents, ["First", "Second"]);

        MailChecker::clear();
    }

    // Re-submitting a response after reverting it to draft must re-notify,
    // even inside the 3-hour notification window: the "was a draft, now
    // submitted" test has to read the *stored* comment type, not the
    // already-updated requested type.
    function test_response_resubmit_notifies() {
        $paper1 = $this->conf->checked_paper_by_id(1);
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

        // start from a clean slate: drop any existing response on this paper
        foreach ($paper1->fetch_comments("(commentType&" . CommentInfo::CT_RESPONSE . ")!=0") as $c) {
            call_api("=comment", $this->u_floyd, ["response" => "1", "c" => (string) $c->commentId, "delete" => 1], $paper1);
        }
        MailChecker::clear();

        // submit a response -- notifies, so timeNotified === timeModified
        $j = call_api("=comment", $this->u_floyd, ["response" => "1", "text" => "Resp body"], $paper1);
        xassert($j->ok);
        $cid = (int) $j->comment->cid;
        $cmt = $paper1->fetch_comments("commentId={$cid}")[0];
        xassert(!!$cmt && $cmt->timeNotified === $cmt->timeModified);

        // revert to draft -- not displayed, so timeNotified is left behind
        $j = call_api("=comment", $this->u_floyd, ["response" => "1", "c" => (string) $cid, "text" => "Resp body", "draft" => 1], $paper1);
        xassert($j->ok);
        $cmt = $paper1->fetch_comments("commentId={$cid}")[0];
        xassert(!!$cmt && $cmt->timeNotified !== $cmt->timeModified);

        // re-submit within the 3-hour window -- must notify again
        MailChecker::clear();
        $j = call_api("=comment", $this->u_floyd, ["response" => "1", "c" => (string) $cid, "text" => "Resp body"], $paper1);
        xassert($j->ok);
        $cmt = $paper1->fetch_comments("commentId={$cid}")[0];
        xassert(!!$cmt && $cmt->timeNotified === $cmt->timeModified);
        xassert(!empty(MailChecker::$preps));

        MailChecker::clear();
        $j = call_api("=comment", $this->u_floyd, ["response" => "1", "c" => (string) $cid, "delete" => 1], $paper1);
        xassert($j->ok);
        $sv = SettingValues::make_request($this->u_chair, [
            "has_response" => "1", "response_active" => "0", "response/1/id" => "1"
        ]);
        xassert($sv->execute());
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

    // A visible (non-admin-only) comment is assigned a sequential ordinal on
    // save; an admin-only comment gets none. This pins save_ordinal /
    // ordinal_missing / commenttype_needs_ordinal, which the JSON `ordinal`
    // field exposes.
    function test_comment_ordinal_assignment() {
        $paper3 = $this->conf->checked_paper_by_id(3);

        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "First visible", "visibility" => "rev"], $paper3);
        xassert($j->ok);
        xassert(isset($j->comment->ordinal));
        $o1 = (int) $j->comment->ordinal;
        xassert($o1 > 0);

        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Second visible", "visibility" => "rev"], $paper3);
        xassert($j->ok);
        xassert(isset($j->comment->ordinal));
        $o2 = (int) $j->comment->ordinal;
        xassert_eqq($o2, $o1 + 1);

        // an admin-only comment is not assigned an ordinal
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Hidden", "visibility" => "admin"], $paper3);
        xassert($j->ok);
        xassert(!isset($j->comment->ordinal));

        MailChecker::clear();
    }

    // The activity-log line names exactly the fields that changed. This pins
    // the per-field change detection in CommentInfo::save (`$ch`), which a
    // future change-list/history object must reproduce.
    function test_comment_log_change_fields() {
        $paper3 = $this->conf->checked_paper_by_id(3);
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Change body", "visibility" => "rev"], $paper3);
        xassert($j->ok);
        $cid = (int) $j->comment->cid;

        $last_change = function () use ($paper3, $cid) {
            return $this->conf->fetch_value("select action from ActionLog where paperId=? and action like ? order by logId desc limit 1",
                $paper3->paperId, "Comment {$cid} edited%");
        };

        // visibility-only edit logs ": visibility"
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "Change body", "visibility" => "pc"], $paper3);
        xassert($j->ok);
        xassert_eqq($last_change(), "Comment {$cid} edited: visibility");

        // text-only edit logs ": text"
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "Change body 2", "visibility" => "pc"], $paper3);
        xassert($j->ok);
        xassert_eqq($last_change(), "Comment {$cid} edited: text");

        // tags-only edit logs ": tags"
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "Change body 2", "visibility" => "pc", "tags" => "hot"], $paper3);
        xassert($j->ok);
        xassert_eqq($last_change(), "Comment {$cid} edited: tags");

        MailChecker::clear();
    }

    // The API response reports `change_list`, the same per-field diff that the
    // activity log names, computed by CommentStatus.
    function test_comment_change_list() {
        $paper3 = $this->conf->checked_paper_by_id(3);

        // a new comment is a creation, not a set of field changes: no text or
        // visibility in the change list, and the initial log names no fields
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "CL body", "visibility" => "rev"], $paper3);
        xassert($j->ok);
        xassert_eqq($j->valid, true);
        xassert(isset($j->change_list));
        xassert_eqq($j->change_list, []);
        $cid = (int) $j->comment->cid;
        $create_log = $this->conf->fetch_value("select action from ActionLog where paperId=? and action like ? order by logId desc limit 1",
            $paper3->paperId, "Comment {$cid} %");
        xassert(!!$create_log);
        xassert(strpos($create_log, ":") === false);

        // visibility-only edit
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "CL body", "visibility" => "pc"], $paper3);
        xassert($j->ok);
        xassert_eqq($j->change_list, ["visibility"]);

        // text-only edit
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "CL body 2", "visibility" => "pc"], $paper3);
        xassert($j->ok);
        xassert_eqq($j->change_list, ["text"]);

        // tags-only edit
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "CL body 2", "visibility" => "pc", "tags" => "hot"], $paper3);
        xassert($j->ok);
        xassert_eqq($j->change_list, ["tags"]);

        // an unchanged re-save reports no changes
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "CL body 2", "visibility" => "pc", "tags" => "hot"], $paper3);
        xassert($j->ok);
        xassert_eqq($j->change_list, []);

        // delete
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "delete" => 1], $paper3);
        xassert($j->ok);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->change_list, ["delete"]);

        MailChecker::clear();
    }

    // `valid` is true only when a modification is actually committed; failed
    // saves report `"valid": false`.
    function test_comment_valid() {
        $paper3 = $this->conf->checked_paper_by_id(3);

        // a successful save is valid and committed
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Valid body", "visibility" => "rev"], $paper3);
        xassert($j->ok);
        xassert_eqq($j->valid, true);
        $cid = (int) $j->comment->cid;

        // refusing to save an empty new comment is not valid
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => ""], $paper3);
        xassert(!$j->ok);
        xassert_eqq($j->valid, false);

        // an unchanged re-save is still valid (it committed the requested state)
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "Valid body", "visibility" => "rev"], $paper3);
        xassert($j->ok);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->change_list, []);

        // GET responses carry no `valid` field
        $j = call_api("comment", $this->u_chair, ["c" => (string) $cid], $paper3);
        xassert($j->ok);
        xassert(!isset($j->valid));

        MailChecker::clear();
    }

    // `dry_run` validates a modification and reports `valid`/`change_list`
    // without committing anything or returning a `comment` object.
    function test_comment_dry_run() {
        $paper3 = $this->conf->checked_paper_by_id(3);

        // dry-run create: valid, but nothing is saved
        $before = $this->conf->fetch_ivalue("select count(*) from PaperComment where paperId=?", $paper3->paperId);
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Dry body", "visibility" => "rev", "dry_run" => 1], $paper3);
        xassert($j->ok);
        xassert_eqq($j->dry_run, true);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->change_list, []);
        xassert(!isset($j->comment));
        xassert_eqq($this->conf->fetch_ivalue("select count(*) from PaperComment where paperId=?", $paper3->paperId), $before);

        // a real comment to edit/delete
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Real body", "visibility" => "rev"], $paper3);
        xassert($j->ok);
        $cid = (int) $j->comment->cid;

        // dry-run edit: reports the change but does not apply it
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "Real body", "visibility" => "pc", "dry_run" => 1], $paper3);
        xassert($j->ok);
        xassert_eqq($j->dry_run, true);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->change_list, ["visibility"]);
        xassert(!isset($j->comment));
        // the stored comment keeps its original visibility
        $cmt = $paper3->fetch_comments("commentId={$cid}")[0];
        xassert_eqq($cmt->unparse_json($this->u_chair)->visibility, "rev");

        // dry-run delete: reports `["delete"]` but the comment remains
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "delete" => 1, "dry_run" => 1], $paper3);
        xassert($j->ok);
        xassert_eqq($j->dry_run, true);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->change_list, ["delete"]);
        xassert(!isset($j->comment));
        xassert(!!$paper3->fetch_comments("commentId={$cid}"));

        // a real delete afterward still works (state was left clean)
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "delete" => 1], $paper3);
        xassert($j->ok);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->change_list, ["delete"]);
        xassert(!$paper3->fetch_comments("commentId={$cid}"));

        // a dry-run of an invalid (empty) comment is not valid
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "", "dry_run" => 1], $paper3);
        xassert(!$j->ok);
        xassert_eqq($j->valid, false);

        MailChecker::clear();
    }

    // `if_unmodified_since` guards an edit against a concurrent change. A stale
    // (or `0`) precondition rejects the edit as a `conflict`, keyed to the
    // `if_unmodified_since` field, and returns the server's current version.
    function test_comment_if_unmodified_since() {
        $paper3 = $this->conf->checked_paper_by_id(3);

        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "IUS body", "visibility" => "rev"], $paper3);
        xassert($j->ok);
        $cid = (int) $j->comment->cid;
        $mod = $j->comment->modified_at;
        xassert(is_int($mod));

        // a stale precondition rejects the edit as a conflict
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "IUS changed", "visibility" => "rev", "if_unmodified_since" => $mod - 1], $paper3);
        xassert(!$j->ok);
        xassert_eqq($j->conflict, true);
        xassert_eqq($j->valid, false);
        $keyed = false;
        foreach ($j->message_list as $mi) {
            $keyed = $keyed || ($mi->field ?? null) === "if_unmodified_since";
        }
        xassert($keyed);
        // the comment was not changed; the response carries the server version
        xassert_eqq($j->comment->text, "IUS body");
        xassert_eqq(($paper3->fetch_comments("commentId={$cid}"))[0]->content(), "IUS body");

        // `if_unmodified_since=0` also rejects editing an existing comment
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "IUS zero", "visibility" => "rev", "if_unmodified_since" => 0], $paper3);
        xassert(!$j->ok);
        xassert_eqq($j->conflict, true);

        // a current precondition allows the edit
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "IUS ok", "visibility" => "rev", "if_unmodified_since" => $mod], $paper3);
        xassert($j->ok);
        xassert(!isset($j->conflict));
        xassert_eqq($j->comment->text, "IUS ok");

        MailChecker::clear();
    }

    // A comment can be uploaded as an `application/json` request body carrying a
    // comment object (the `unparse_json` round-trip shape).
    function test_comment_json_upload() {
        $paper3 = $this->conf->checked_paper_by_id(3);

        // create via JSON body
        $qreq = TestQreq::post_json(["text" => "JSON body", "visibility" => "rev", "topic" => "paper"]);
        $j = call_api("=comment", $this->u_chair, $qreq, $paper3);
        xassert($j->ok);
        xassert_eqq($j->comment->text, "JSON body");
        xassert_eqq($j->comment->visibility, "rev");
        xassert_eqq($j->comment->topic, "paper");
        $cid = (int) $j->comment->cid;

        // edit via JSON, addressing the comment by `cid`
        $qreq = TestQreq::post_json(["cid" => $cid, "text" => "JSON edited", "visibility" => "pc"]);
        $j = call_api("=comment", $this->u_chair, $qreq, $paper3);
        xassert($j->ok);
        xassert_eqq($j->comment->cid, $cid);
        xassert_eqq($j->comment->text, "JSON edited");
        xassert_eqq($j->comment->visibility, "pc");
        xassert_eqq($j->change_list, ["text", "visibility"]);

        // a one-element array names one comment
        $qreq = TestQreq::post_json([["cid" => $cid, "text" => "JSON array", "visibility" => "pc"]]);
        $j = call_api("=comment", $this->u_chair, $qreq, $paper3);
        xassert($j->ok);
        xassert_eqq($j->comment->text, "JSON array");

        // a multi-element array is rejected (bulk upload is TBD)
        $qreq = TestQreq::post_json([["text" => "a"], ["text" => "b"]]);
        $jr = call_api_result("=comment", $this->u_chair, $qreq, $paper3);
        xassert_eqq($jr->status, 400);

        // a wrong `object` type is rejected, keyed to the `object` field
        $qreq = TestQreq::post_json(["object" => "paper", "text" => "nope"]);
        $jr = call_api_result("=comment", $this->u_chair, $qreq, $paper3);
        xassert_eqq($jr->status, 400);
        xassert_eqq($jr->content["message_list"][0]->field, "object");
        // `object: "comment"` is explicitly accepted
        $qreq = TestQreq::post_json(["object" => "comment", "text" => "yep", "visibility" => "rev", "topic" => "paper"]);
        $j = call_api("=comment", $this->u_chair, $qreq, $paper3);
        xassert($j->ok);
        xassert_eqq($j->comment->text, "yep");

        // a `pid` that disagrees with the request's paper is rejected
        $qreq = TestQreq::post_json(["cid" => $cid, "pid" => 4, "text" => "wrong paper"]);
        $jr = call_api_result("=comment", $this->u_chair, $qreq, $paper3);
        xassert_eqq($jr->status, 400);
        xassert_eqq($jr->content["message_list"][0]->field, "pid");
        // a matching `pid` is fine (and `id` is ignored, not a target)
        $qreq = TestQreq::post_json(["cid" => $cid, "pid" => 3, "id" => 99999, "text" => "right paper"]);
        $j = call_api("=comment", $this->u_chair, $qreq, $paper3);
        xassert($j->ok);
        xassert_eqq($j->comment->cid, $cid);
        xassert_eqq($j->comment->text, "right paper");

        // a `cid` that disagrees with a request-set `c` is a conflict
        $qreq = TestQreq::post_json(["cid" => $cid, "text" => "mismatch"]);
        $qreq->c = (string) ($cid + 1);
        $jr = call_api_result("=comment", $this->u_chair, $qreq, $paper3);
        xassert_eqq($jr->status, 400);
        xassert_eqq($jr->content["message_list"][0]->field, "cid");
        // a `cid` that agrees with `c` is fine
        $qreq = TestQreq::post_json(["cid" => $cid, "text" => "agrees"]);
        $qreq->c = (string) $cid;
        $j = call_api("=comment", $this->u_chair, $qreq, $paper3);
        xassert($j->ok);
        xassert_eqq($j->comment->cid, $cid);
        xassert_eqq($j->comment->text, "agrees");

        // round-trip: GET the comment JSON and re-POST it unchanged
        $j = call_api("comment", $this->u_chair, ["c" => (string) $cid], $paper3);
        xassert($j->ok);
        $qreq = TestQreq::post_json($j->comment);
        $j = call_api("=comment", $this->u_chair, $qreq, $paper3);
        xassert($j->ok);
        xassert_eqq($j->comment->cid, $cid);
        xassert_eqq($j->comment->text, "agrees");
        xassert_eqq($j->change_list, []);

        // delete via JSON
        $qreq = TestQreq::post_json(["cid" => $cid, "delete" => true]);
        $j = call_api("=comment", $this->u_chair, $qreq, $paper3);
        xassert($j->ok);
        xassert_eqq($j->change_list, ["delete"]);
        xassert(!$paper3->fetch_comments("commentId={$cid}"));

        MailChecker::clear();
    }

    // JSON `docs`: inline `content_base64`/`content` uploads, `docid` retains,
    // and an omitted `docs` key keeps the existing attachments.
    function test_comment_json_attachment() {
        $paper3 = $this->conf->checked_paper_by_id(3);

        $qreq = TestQreq::post_json(["text" => "attach", "visibility" => "rev",
            "docs" => [["filename" => "inline.txt", "mimetype" => "text/plain",
                        "content_base64" => base64_encode("INLINE")]]]);
        $j = call_api("=comment", $this->u_chair, $qreq, $paper3);
        xassert($j->ok);
        $cid = (int) $j->comment->cid;
        $docid = $j->comment->docs[0]->docid;
        xassert(is_int($docid));

        $paper3->load_comments();
        $dset = $paper3->comment_by_id($cid)->attachments();
        xassert_eqq(count($dset), 1);
        xassert_eqq($dset->document_by_index(0)->content(), "INLINE");
        xassert_eqq($dset->document_by_index(0)->filename, "inline.txt");

        // omitting `docs` retains the existing attachment
        $qreq = TestQreq::post_json(["cid" => $cid, "text" => "attach 2", "visibility" => "rev"]);
        $j = call_api("=comment", $this->u_chair, $qreq, $paper3);
        xassert($j->ok);
        $paper3->load_comments();
        xassert_eqq(count($paper3->comment_by_id($cid)->attachments()), 1);

        // an explicit list retains by `docid` and adds a new inline doc
        $qreq = TestQreq::post_json(["cid" => $cid, "text" => "attach 2", "visibility" => "rev",
            "docs" => [["docid" => $docid], ["filename" => "b.txt", "content" => "BBB"]]]);
        $j = call_api("=comment", $this->u_chair, $qreq, $paper3);
        xassert($j->ok);
        $paper3->load_comments();
        $contents = [];
        foreach ($paper3->comment_by_id($cid)->attachments() as $doc) {
            $contents[] = $doc->content();
        }
        xassert_eqq($contents, ["INLINE", "BBB"]);

        MailChecker::clear();
    }

    // The comment object can be supplied in a `json` form field; a `content_file`
    // in `docs` then references an uploaded file field.
    function test_comment_json_form_field() {
        $paper3 = $this->conf->checked_paper_by_id(3);

        $obj = ["text" => "form-json comment", "visibility" => "rev",
                "docs" => [["content_file" => "fig"]]];
        $qreq = new Qrequest("POST", ["json" => json_encode($obj)]);
        $qreq->approve_token();
        $qreq->set_file_content("fig", "FIGDATA", "fig.txt", "text/plain");
        $j = call_api("=comment", $this->u_chair, $qreq, $paper3);
        xassert($j->ok);
        xassert_eqq($j->comment->text, "form-json comment");
        $cid = (int) $j->comment->cid;

        $paper3->load_comments();
        $dset = $paper3->comment_by_id($cid)->attachments();
        xassert_eqq(count($dset), 1);
        xassert_eqq($dset->document_by_index(0)->content(), "FIGDATA");
        xassert_eqq($dset->document_by_index(0)->filename, "fig.txt");

        MailChecker::clear();
    }

    // A ZIP upload: `data.json` holds the comment object, and a `content_file`
    // in `docs` references another file in the archive.
    function test_comment_zip_upload() {
        $paper3 = $this->conf->checked_paper_by_id(3);
        $qreq = TestQreq::post_zip([
            "data.json" => ["text" => "zip comment", "visibility" => "rev",
                            "docs" => [["content_file" => "fig.txt"]]],
            "fig.txt" => "ZIPDATA"
        ]);
        $j = call_api("=comment", $this->u_chair, $qreq, $paper3);
        xassert($j->ok);
        xassert_eqq($j->comment->text, "zip comment");
        $cid = (int) $j->comment->cid;

        $paper3->load_comments();
        $dset = $paper3->comment_by_id($cid)->attachments();
        xassert_eqq(count($dset), 1);
        xassert_eqq($dset->document_by_index(0)->content(), "ZIPDATA");
        xassert_eqq($dset->document_by_index(0)->filename, "fig.txt");

        MailChecker::clear();
    }

    // An `upload` capability pointing at a previously-uploaded JSON file.
    function test_comment_upload_capability() {
        $paper3 = $this->conf->checked_paper_by_id(3);
        $old_docstore = $this->conf->opt("docstore");
        $tmpdir = tempdir();
        $this->conf->set_opt("docstore", "{$tmpdir}%h%x");
        $this->conf->refresh_settings();
        xassert(!!$this->conf->docstore());

        // upload a JSON comment object as a file, in one shot
        $json = json_encode(["text" => "capability comment", "visibility" => "rev"]);
        $qreq = (new Qrequest("POST", [
                "start" => 1, "temp" => 1, "size" => strlen($json),
                "filename" => "c.json", "mimetype" => "application/json",
                "offset" => 0, "finish" => 1
            ]))->approve_token()->set_file_content("blob", $json);
        $j = call_api("=upload", $this->u_chair, $qreq, null);
        xassert_eqq($j->ok, true);
        $token = $j->token;
        xassert(is_string($token));

        // POST /comment referencing the uploaded JSON via `upload`
        $qreq = new Qrequest("POST", ["upload" => $token]);
        $qreq->approve_token();
        $j = call_api("=comment", $this->u_chair, $qreq, $paper3);
        xassert($j->ok);
        xassert_eqq($j->comment->text, "capability comment");

        $this->conf->set_opt("docstore", $old_docstore);
        $this->conf->refresh_settings();
        MailChecker::clear();
    }

    // Saving and deleting a comment while acting through a review token: the
    // comment is attributed to the (anonymous) review owner, and — because
    // anonymous actors are suppressed from the activity log — neither the save
    // nor the delete generates an ActionLog row. Pins the review_token branch
    // in Comment_API::finish_post.
    function test_review_token_acting_path() {
        $paper3 = $this->conf->checked_paper_by_id(3);

        // create a fresh anonymous review and grab *its* token
        xassert_assign($this->u_chair, "paper,action,user\n3,review,new-anonymous");
        $token = $this->conf->fetch_ivalue("select reviewToken from PaperReview where paperId=3 and reviewToken!=0 order by reviewId desc limit 1");
        xassert(!!$token);
        $paper3->load_reviews();
        $rrow = $paper3->review_by_token($token);
        xassert(!!$rrow);
        $anon = $this->conf->user_by_id($rrow->contactId);
        xassert($anon->is_anonymous_user());

        // count activity-log rows naming this specific comment, by paperId and
        // the "Comment NNN " prefix (NNN = the commentId)
        $cmt_log_count = function ($cid) use ($paper3) {
            return $this->conf->fetch_ivalue("select count(*) from ActionLog where paperId=? and action like ?",
                $paper3->paperId, "Comment {$cid} %");
        };

        // hold the token, then save a comment through it
        $this->u_chair->change_review_token($token, true);
        $enc = encode_token($token);
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Via token", "visibility" => "rev", "review_token" => $enc], $paper3);
        xassert($j->ok);
        $cid = (int) $j->comment->cid;

        // comment is owned by the anonymous review owner, not the token holder
        $cmt = $paper3->fetch_comments("commentId={$cid}")[0];
        xassert(!!$cmt);
        xassert_eqq($cmt->contactId, $rrow->contactId);
        xassert_neqq($cmt->contactId, $this->u_chair->contactId);

        // anonymous actor => no log row naming this comment for the save
        xassert_eqq($cmt_log_count($cid), 0);

        // delete through the token; still no log row for this comment
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "delete" => 1, "review_token" => $enc], $paper3);
        xassert($j->ok);
        xassert(!$paper3->fetch_comments("commentId={$cid}"));
        xassert_eqq($cmt_log_count($cid), 0);

        $this->u_chair->change_review_token($token, false);
        MailChecker::clear();
    }

    // Creating, editing, and deleting a comment while acting through a
    // not-logged-in review-accept capability link. This is the
    // acting-user != commenter path: CommentStatus resolves the account-less
    // acting user (who carries the @ra capability) to the review owner, the
    // comment is attributed to that reviewer, and the
    // activity log records the link actor (contactId 0, trueContactId -1 "via
    // link") with that reviewer as destContactId.
    function test_review_capability_acting_path() {
        $paper3 = $this->conf->checked_paper_by_id(3);
        $orig_rev_open = $this->conf->setting("rev_open");
        $this->conf->save_refresh_setting("rev_open", 1);

        // a real reviewer with a submitted review (so they may comment)
        xassert_assign($this->u_chair, "paper,action,user\n3,review,external@_.com");
        $reviewer = $this->conf->checked_user_by_email("external@_.com");
        $tf = new ReviewValues($this->conf);
        xassert($tf->parse_json(["ovemer" => 2, "revexp" => 1, "papsum" => "S", "comaut" => "C"]));
        xassert($tf->check_and_save($reviewer, $paper3));

        // not-logged-in user holding the review-accept capability
        $ucap = Contact::make($this->conf);
        $ucap->set_capability("@ra{$paper3->paperId}", $reviewer->contactId);
        xassert(!$ucap->contactId);
        xassert(!$ucap->is_anonymous_user());

        $latest_log = function ($cid) use ($paper3) {
            return Dbl::fetch_first_object($this->conf->dblink,
                "select contactId, destContactId, trueContactId, action from ActionLog where paperId=? and action like ? order by logId desc limit 1",
                $paper3->paperId, "Comment {$cid} %");
        };
        // the link actor logs as contactId 0, dest = resolved reviewer, true -1
        $check_actor = function ($row) use ($reviewer) {
            xassert(!!$row);
            xassert_eqq((int) $row->contactId, 0);                       // not logged in
            xassert_eqq((int) $row->destContactId, $reviewer->contactId); // resolved review owner
            xassert_eqq((int) $row->trueContactId, -1);                  // "via link"
        };

        // create a brand-new comment via the capability link
        $j = call_api("=comment", $ucap, ["c" => "new", "text" => "Created via link", "visibility" => "rev"], $paper3);
        xassert($j->ok);
        $cid = (int) $j->comment->cid;

        // attributed to the resolved reviewer, not the account-less link actor
        $cmt = $paper3->fetch_comments("commentId={$cid}")[0];
        xassert(!!$cmt);
        xassert_eqq($cmt->contactId, $reviewer->contactId);
        $row = $latest_log($cid);
        $check_actor($row);
        xassert(str_starts_with($row->action, "Comment {$cid} "));

        // edit via the capability link
        $j = call_api("=comment", $ucap, ["c" => (string) $cid, "text" => "Edited via link", "visibility" => "rev"], $paper3);
        xassert($j->ok);
        $row = $latest_log($cid);
        $check_actor($row);
        xassert(str_starts_with($row->action, "Comment {$cid} edited"));

        // delete via the capability link
        $j = call_api("=comment", $ucap, ["c" => (string) $cid, "delete" => 1], $paper3);
        xassert($j->ok);
        xassert(!$paper3->fetch_comments("commentId={$cid}"));
        $row = $latest_log($cid);
        $check_actor($row);
        xassert_eqq($row->action, "Comment {$cid} deleted");

        $this->conf->save_refresh_setting("rev_open", $orig_rev_open);
        MailChecker::clear();
    }

    // CommentStatus prepare → abort stages changes in memory but writes nothing
    // and rolls the comment back (the dry-run primitive).
    function test_commentstatus_abort() {
        $paper3 = $this->conf->checked_paper_by_id(3);
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Abort original", "visibility" => "rev"], $paper3);
        xassert($j->ok);
        $cid = (int) $j->comment->cid;
        $crow = $paper3->fetch_comments("commentId={$cid}")[0];
        xassert_eqq($crow->raw_content(), "Abort original");

        $cs = new CommentStatus($this->u_chair);
        $req = ["visibility" => "rev", "topic" => "rev", "submit" => false,
                "blind" => false, "text" => "Abort edited", "tags" => null, "docs" => []];
        xassert($cs->prepare_save($crow, $req));
        // staged in memory, not yet committed
        xassert($crow->prop_changed());
        xassert_eqq($crow->raw_content(), "Abort edited");

        $cs->abort_save();
        // rolled back in memory
        xassert(!$crow->prop_changed());
        xassert_eqq($crow->raw_content(), "Abort original");

        // database untouched
        $fresh = $paper3->fetch_comments("commentId={$cid}")[0];
        xassert_eqq($fresh->raw_content(), "Abort original");

        MailChecker::clear();
    }

    // A staged attachment change is visible through has_attachments()/
    // attachments() before save (the comment reads as its unsaved version),
    // and abort_prop() reverts it.
    function test_set_attachments_reads_staged() {
        $paper1 = $this->conf->checked_paper_by_id(1);
        $qreq = new Qrequest("POST", ["c" => "new", "text" => "Doc holder", "attachment:1" => "new"]);
        $qreq->approve_token();
        $qreq->set_file_content("attachment:1:file", "Body", "text/plain");
        $j = call_api("=comment", $this->u_chair, $qreq, $paper1);
        xassert($j->ok);
        $cid = (int) $j->comment->cid;

        $crow = $paper1->fetch_comments("commentId={$cid}")[0];
        xassert($crow->has_attachments());
        xassert_eqq(count($crow->attachments()), 1);
        xassert(($crow->commentType & CommentInfo::CT_HASDOC) !== 0);

        // stage removal of all attachments, without saving
        $crow->set_attachments([]);
        xassert($crow->docs_changed());
        xassert(!$crow->has_attachments());        // reads the staged version
        xassert_eqq(count($crow->attachments()), 0);
        // set_attachments also clears the CT_HASDOC bit
        xassert(($crow->commentType & CommentInfo::CT_HASDOC) === 0);

        // abort reverts to the saved version
        $crow->abort_prop();
        xassert(!$crow->docs_changed());
        xassert($crow->has_attachments());
        xassert_eqq(count($crow->attachments()), 1);
        xassert(($crow->commentType & CommentInfo::CT_HASDOC) !== 0);

        MailChecker::clear();
    }

    // set_prop tracks net change only: a no-op set records nothing, and a
    // property edited away from then back to its base value is not reported as
    // a diff (so change lists / saves stay accurate).
    function test_set_prop_net_change() {
        $paper1 = $this->conf->checked_paper_by_id(1);
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "Net change", "visibility" => "rev"], $paper1);
        xassert($j->ok);
        $cid = (int) $j->comment->cid;
        $crow = $paper1->fetch_comments("commentId={$cid}")[0];
        xassert(!$crow->prop_changed());

        $base = $crow->commentType;

        // setting to the current value is a no-op
        $crow->set_prop("commentType", $base);
        xassert(!$crow->prop_changed());
        xassert(!$crow->prop_changed("commentType"));

        // change, then change back to base => not dirty
        $crow->set_prop("commentType", $base | CommentInfo::CT_DRAFT);
        xassert($crow->prop_changed("commentType"));
        xassert_eqq($crow->base_prop("commentType"), $base);
        $crow->set_prop("commentType", $base);
        xassert(!$crow->prop_changed("commentType"));
        xassert(!$crow->prop_changed());
        xassert_eqq($crow->commentType, $base);

        // a genuine change is still tracked, with the original base recorded
        $crow->set_prop("commentType", $base | CommentInfo::CT_DRAFT);
        xassert($crow->prop_changed("commentType"));
        xassert_eqq($crow->base_prop("commentType"), $base);

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

    function test_comment_notify_off() {
        $paper1 = $this->conf->checked_paper_by_id(1);
        $this->ensure_paper1_review($paper1);

        // baseline: a chair's mention comment notifies (mentionee + follower)
        MailChecker::clear();
        $j = call_api("=comment", $this->u_chair, ["c" => "new", "text" => "@Christian Huitema notify baseline"], $paper1);
        xassert($j->ok);
        $cid = (int) $j->comment->cid;
        xassert_gt(count(MailChecker::$preps), 0);

        // a chair's `notify=off` suppresses all notifications for the edit
        MailChecker::clear();
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "@Christian Huitema notify off", "notify" => "off"], $paper1);
        xassert($j->ok);
        MailChecker::check0();

        // ... but a truthy/absent `notify` still notifies
        MailChecker::clear();
        $j = call_api("=comment", $this->u_chair, ["c" => (string) $cid, "text" => "@Christian Huitema notify on again"], $paper1);
        xassert($j->ok);
        xassert_gt(count(MailChecker::$preps), 0);

        // `notify=off` is chair-only: a non-chair reviewer's request still notifies
        MailChecker::clear();
        $j = call_api("=comment", $this->u_mgbaker, ["c" => "new", "text" => "@Christian Huitema reviewer notify off", "notify" => "off"], $paper1);
        xassert($j->ok);
        xassert_gt(count(MailChecker::$preps), 0);

        MailChecker::clear();
    }
}
