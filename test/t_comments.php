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
        $j = call_api("comment", $this->u_floyd, ["response" => "1", "text" => "Hello"], $paper1);
        xassert(!$j->ok);
        if (!$j->ok) {
            xassert_match($j->message_list[0]->message, '/not open for responses/');
        }

        $sv = SettingValues::make_request($this->u_chair, [
            "rev_open" => "1",
            "has_response" => "1",
            "response_active" => "1",
            "response__1__id" => "0",
            "response__1__name" => "",
            "response__1__open" => "@" . (Conf::$now - 1),
            "response__1__done" => "@" . (Conf::$now + 10000)
        ]);
        xassert($sv->execute());

        $j = call_api("comment", $this->u_floyd, ["response" => "1", "text" => "Hello"], $paper1);
        xassert($j->ok);
        xassert(is_object($j->cmt));
        xassert($j->cmt->text === "Hello");
        $cid = $j->cmt->cid;

        $j = call_api("comment", $this->u_floyd, ["response" => "1", "c" => "new", "text" => "Hi"], $paper1);
        xassert(!$j->ok);
        xassert_match($j->message_list[0]->message, '/concurrent/');
        xassert($j->conflict === true);
        xassert(is_object($j->cmt));
        xassert_eqq($j->cmt->text, "Hello");

        $j = call_api("comment", $this->u_floyd, ["response" => "1", "text" => "Hi"], $paper1);
        xassert($j->ok);
        xassert_eqq($j->cmt->text, "Hi");
        xassert_eqq($j->cmt->cid, $cid);

        $j = call_api("comment", $this->u_floyd, ["c" => "response", "text" => "Ho ho ho"], $paper1);
        xassert($j->ok);
        xassert_eqq($j->cmt->text, "Ho ho ho");
        xassert_eqq($j->cmt->cid, $cid);

        $j = call_api("comment", $this->u_floyd, ["response" => "1", "c" => (string) $cid, "text" => "Hee hee"], $paper1);
        xassert($j->ok);
        xassert_eqq($j->cmt->text, "Hee hee");
        xassert_eqq($j->cmt->cid, $cid);

        $j = call_api("comment", $this->u_floyd, new Qrequest("GET", ["response" => "1"]), $paper1);
        xassert($j->ok);
        xassert_eqq($j->cmt->text, "Hee hee");
        xassert_eqq($j->cmt->cid, $cid);

        $j = call_api("comment", $this->u_floyd, new Qrequest("GET", ["c" => (string) $cid]), $paper1);
        xassert($j->ok);
        xassert_eqq($j->cmt->text, "Hee hee");
        xassert_eqq($j->cmt->cid, $cid);

        $j = call_api("comment", $this->u_floyd, ["c" => "new", "text" => "Nope"], $paper1);
        xassert(!$j->ok);
        xassert_match($j->message_list[0]->message, '/didn’t write this comment/');

        $j = call_api("comment", $this->u_chair, ["c" => "new", "text" => "Yep"], $paper1);
        xassert($j->ok);
        xassert_eqq($j->cmt->text, "Yep");
        xassert_neqq($j->cmt->cid, $cid);

        $j = call_api("comment", $this->u_chair, ["c" => "new", "text" => ""], $paper1);
        xassert(!$j->ok);
        xassert_match($j->message_list[0]->message, '/required/');
    }
}
