<?php
// t_settings.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Settings_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact
     * @readonly */
    public $u_mgbaker;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
    }

    function test_unambiguous_renumbering() {
        $sv = new SettingValues($this->conf->root_user());
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi"], ["Hello", "Hi", "Hello"]), []);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi"], ["Hello", "Fart", "Hi"]), [1 => 2]);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi"], ["Hi", "Hello"]), [0 => 1, 1 => 0]);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi", "Fart"], ["Hi", "Hello"]), [0 => 1, 1 => 0, 2 => -1]);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi", "Fart"], ["Hi", "Barf"]), [2 => -1]);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi", "Fart"], ["Fart", "Barf"]), [2 => -1]);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi", "Fart"], ["Fart", "Money", "Barf"]), []);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi", "Fart"], ["Fart", "Hello", "Hi"]), [0 => 1, 1 => 2, 2 => 0]);
    }

    function test_setting_info() {
        $si = $this->conf->si("format__0__spec");
        xassert_eqq($si->storage_type, Si::SI_DATA | Si::SI_SLICE);
        xassert_eqq($si->storage_name(), "sub_banal");
        $si = $this->conf->si("format__4__spec");
        xassert_eqq($si->storage_type, Si::SI_DATA | Si::SI_SLICE);
        xassert_eqq($si->storage_name(), "sub_banal_4");
        $si = $this->conf->si("format__m1__active");
        xassert_eqq($si->first_page(), "decisions");

        $si = $this->conf->si("rf__1__order");
        xassert_eqq($si->first_page(), "reviewform");
    }

    function test_message_defaults() {
        xassert(!$this->conf->setting("has_topics"));
        ConfInvariants::test_all($this->conf);

        $sv = SettingValues::make_request($this->u_chair, []);
        $s = $this->conf->si("preference_instructions")->default_value($sv);
        xassert(strpos($s, "review preference") !== false);
        xassert(strpos($s, "topic") === false);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "new_topics" => "Whatever\n"
        ])->parse();

        $s = $this->conf->si("preference_instructions")->default_value($sv);
        xassert(strpos($s, "review preference") !== false);
        xassert(strpos($s, "topic") !== false);
        ConfInvariants::test_all($this->conf);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "topic__1__name" => "Whatever",
            "topic__1__delete" => 1
        ]);
        xassert($sv->execute());

        xassert_eqq($this->conf->setting("has_topics"), null);
    }

    function delete_topics() {
        $this->conf->qe("delete from TopicInterest");
        $this->conf->qe("truncate table TopicArea");
        $this->conf->qe("alter table TopicArea auto_increment=0");
        $this->conf->qe("delete from PaperTopic");
        $this->conf->qe("delete from Settings where name='has_topics'");
    }

    function test_topics() {
        $this->delete_topics();
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '[]');
        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "new_topics" => "Fart\n   Barf"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '{"2":"Barf","1":"Fart"}');

        // duplicate topic not accepted
        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "new_topics" => "Fart"
        ]);
        xassert(!$sv->execute());
        xassert_eqq($sv->reqstr("topic__3__name"), "Fart");
        xassert($sv->has_error_at("topic__3__name"));
        xassert_neqq(strpos($sv->full_feedback_text(), "is not unique"), false);
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '{"2":"Barf","1":"Fart"}');

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "new_topics" => "Fart2"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '{"2":"Barf","1":"Fart","3":"Fart2"}');

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "topic__1__id" => "2",
            "topic__1__name" => "Fért",
            "topic__2__id" => "",
            "topic__2__name" => "Festival Fartal",
            "topic__3__id" => "\$",
            "topic__3__name" => "Fet",
            "new_topics" => "Fart3"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Fart","3":"Fart2","6":"Fart3","2":"Fért","4":"Festival Fartal","5":"Fet"}');

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "topic__1__id" => "1",
            "topic__1__delete" => "1",
            "topic__2__id" => "2",
            "topic__2__delete" => "1",
            "topic__3__id" => "3",
            "topic__3__delete" => "1",
            "topic__4__id" => "4",
            "topic__4__delete" => "1",
            "topic__5__id" => "5",
            "topic__5__delete" => "1",
            "topic__6__id" => "6",
            "topic__6__delete" => "1"
        ]);
        xassert($sv->execute());

        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '[]');
        xassert_eqq($this->conf->setting("has_topics"), null);
        ConfInvariants::test_all($this->conf);
    }

    function test_topics_json() {
        $this->delete_topics();
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '[]');

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "topic": ["Barf", "Fart", "Money"]
        }');
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Barf","2":"Fart","3":"Money"}');

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "topic": []
        }');
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Barf","2":"Fart","3":"Money"}');

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "topic": [{"id": 1, "name": "Berf"}]
        }');
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Berf","2":"Fart","3":"Money"}');

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "topic": [{"id": 1, "name": "Berf"}]
        }', null, true);
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Berf"}');

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "topic": [{"id": "$", "name": "Berf"}]
        }');
        xassert(!$sv->execute());
        xassert_neqq(strpos($sv->full_feedback_text(), "is not unique"), false);

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "topic": [{"name": "Bingle"}, {"name": "Bongle"}]
        }');
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Berf","4":"Bingle","5":"Bongle"}');

        $this->delete_topics();
    }

    function test_decision_types() {
        $this->conf->save_refresh_setting("outcome_map", null);
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","1":"Accepted","-1":"Rejected"}');
        xassert_eqq($this->conf->setting("decisions"), null);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision__1__name" => "Accepted!",
            "decision__1__id" => "1",
            "decision__2__name" => "Newly accepted",
            "decision__2__id" => "\$",
            "decision__2__category" => "accept"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","1":"Accepted!","2":"Newly accepted","-1":"Rejected"}');
        xassert_eqq($this->conf->setting("decisions"), null);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision__1__id" => "1",
            "decision__1__delete" => "1"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","2":"Newly accepted","-1":"Rejected"}');

        // accept-category with “reject” in the name is rejected by default
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision__1__id" => "2",
            "decision__1__name" => "Rejected"
        ]);
        xassert(!$sv->execute());
        xassert_neqq(strpos($sv->full_feedback_text(), "Accept-category decision"), false);

        // duplicate decision names are rejected
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision__1__id" => "2",
            "decision__1__name" => "Rejected",
            "decision__1__name_force" => "1"
        ]);
        xassert(!$sv->execute());
        xassert_neqq(strpos($sv->full_feedback_text(), "is not unique"), false);
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","2":"Newly accepted","-1":"Rejected"}');

        // can override name conflict
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision__1__id" => "2",
            "decision__1__name" => "Really Rejected",
            "decision__1__name_force" => "1",
            "decision__2__id" => "\$",
            "decision__2__name" => "Whatever",
            "decision__2__category" => "reject"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","2":"Really Rejected","-1":"Rejected","-2":"Whatever"}');

        // not change name => no need to override conflict
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision__1__id" => "2",
            "decision__1__name" => "Really Rejected",
            "decision__2__id" => "-2",
            "decision__2__name" => "Well I dunno"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","2":"Really Rejected","-1":"Rejected","-2":"Well I dunno"}');

        // missing name => error
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision__1__id" => "\$"
        ]);
        xassert(!$sv->execute());

        // restore default decisions => no database setting
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision__1__id" => "\$",
            "decision__1__name" => "Accepted",
            "decision__2__id" => "2",
            "decision__2__delete" => "1",
            "decision__3__id" => "-2",
            "decision__3__delete" => "1"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","1":"Accepted","-1":"Rejected"}');
        xassert_eqq($this->conf->setting("outcome_map"), null);
    }

    function test_score_value_class() {
        xassert(!$this->conf->find_review_field("B5"));
        xassert(!$this->conf->find_review_field("B9"));
        xassert(!$this->conf->find_review_field("B10"));
        xassert(!$this->conf->find_review_field("B15"));

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf__1__name" => "B9",
            "rf__1__id" => "s03",
            "rf__1__choices" => "1. A\n2. B\n3. C\n4. D\n5. E\n6. F\n7. G\n8. H\n9. I",
            "rf__2__name" => "B15",
            "rf__2__id" => "s04",
            "rf__2__choices" => "1. A\n2. B\n3. C\n4. D\n5. E\n6. F\n7. G\n8. H\n9. I\n10. J\n11. K\n12. L\n13. M\n14. N\n15. O",
            "rf__3__name" => "B10",
            "rf__3__id" => "s06",
            "rf__3__choices" => "1. A\n2. B\n3. C\n4. D\n5. E\n6. F\n7. G\n8. H\n9. I\n10. J",
            "rf__4__name" => "B5",
            "rf__4__id" => "s07",
            "rf__4__choices" => "A. A\nB. B\nC. C\nD. D\nE. E"
        ]);
        xassert($sv->execute());

        $rf = $this->conf->find_review_field("B5");
        assert($rf instanceof Score_ReviewField);
        xassert_eqq($rf->value_class(1), "sv sv9");
        xassert_eqq($rf->value_class(2), "sv sv7");
        xassert_eqq($rf->value_class(3), "sv sv5");
        xassert_eqq($rf->value_class(4), "sv sv3");
        xassert_eqq($rf->value_class(5), "sv sv1");
        $rf = $this->conf->find_review_field("B9");
        assert($rf instanceof Score_ReviewField);
        xassert_eqq($rf->value_class(1), "sv sv1");
        xassert_eqq($rf->value_class(2), "sv sv2");
        xassert_eqq($rf->value_class(3), "sv sv3");
        xassert_eqq($rf->value_class(4), "sv sv4");
        xassert_eqq($rf->value_class(5), "sv sv5");
        xassert_eqq($rf->value_class(6), "sv sv6");
        xassert_eqq($rf->value_class(7), "sv sv7");
        xassert_eqq($rf->value_class(8), "sv sv8");
        xassert_eqq($rf->value_class(9), "sv sv9");
        $rf = $this->conf->find_review_field("B15");
        assert($rf instanceof Score_ReviewField);
        xassert_eqq($rf->value_class(1), "sv sv1");
        xassert_eqq($rf->value_class(2), "sv sv2");
        xassert_eqq($rf->value_class(3), "sv sv2");
        xassert_eqq($rf->value_class(4), "sv sv3");
        xassert_eqq($rf->value_class(5), "sv sv3");
        xassert_eqq($rf->value_class(6), "sv sv4");
        xassert_eqq($rf->value_class(7), "sv sv4");
        xassert_eqq($rf->value_class(8), "sv sv5");
        xassert_eqq($rf->value_class(9), "sv sv6");
        xassert_eqq($rf->value_class(10), "sv sv6");
        xassert_eqq($rf->value_class(11), "sv sv7");
        xassert_eqq($rf->value_class(12), "sv sv7");
        xassert_eqq($rf->value_class(13), "sv sv8");
        xassert_eqq($rf->value_class(14), "sv sv8");
        xassert_eqq($rf->value_class(15), "sv sv9");
        $rf = $this->conf->find_review_field("B10");
        assert($rf instanceof Score_ReviewField);
        xassert_eqq($rf->value_class(1), "sv sv1");
        xassert_eqq($rf->value_class(2), "sv sv2");
        xassert_eqq($rf->value_class(3), "sv sv3");
        xassert_eqq($rf->value_class(4), "sv sv4");
        xassert_eqq($rf->value_class(5), "sv sv5");
        xassert_eqq($rf->value_class(6), "sv sv5");
        xassert_eqq($rf->value_class(7), "sv sv6");
        xassert_eqq($rf->value_class(8), "sv sv7");
        xassert_eqq($rf->value_class(9), "sv sv8");
        xassert_eqq($rf->value_class(10), "sv sv9");

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf__1__id" => "s03",
            "rf__1__colors" => "svr",
            "rf__2__id" => "s04",
            "rf__2__colors" => "svr",
            "rf__3__id" => "s06",
            "rf__3__colors" => "svr",
            "rf__4__id" => "s07",
            "rf__4__colors" => "svr"
        ]);
        xassert($sv->execute());
        $rf = $this->conf->find_review_field("B5");
        assert($rf instanceof Score_ReviewField);
        xassert_eqq($rf->value_class(1), "sv sv1");
        xassert_eqq($rf->value_class(2), "sv sv3");
        xassert_eqq($rf->value_class(3), "sv sv5");
        xassert_eqq($rf->value_class(4), "sv sv7");
        xassert_eqq($rf->value_class(5), "sv sv9");
        $rf = $this->conf->find_review_field("B9");
        assert($rf instanceof Score_ReviewField);
        xassert_eqq($rf->value_class(1), "sv sv9");
        xassert_eqq($rf->value_class(2), "sv sv8");
        xassert_eqq($rf->value_class(3), "sv sv7");
        xassert_eqq($rf->value_class(4), "sv sv6");
        xassert_eqq($rf->value_class(5), "sv sv5");
        xassert_eqq($rf->value_class(6), "sv sv4");
        xassert_eqq($rf->value_class(7), "sv sv3");
        xassert_eqq($rf->value_class(8), "sv sv2");
        xassert_eqq($rf->value_class(9), "sv sv1");
        $rf = $this->conf->find_review_field("B15");
        assert($rf instanceof Score_ReviewField);
        xassert_eqq($rf->value_class(15), "sv sv1");
        xassert_eqq($rf->value_class(14), "sv sv2");
        xassert_eqq($rf->value_class(13), "sv sv2");
        xassert_eqq($rf->value_class(12), "sv sv3");
        xassert_eqq($rf->value_class(11), "sv sv3");
        xassert_eqq($rf->value_class(10), "sv sv4");
        xassert_eqq($rf->value_class(9), "sv sv4");
        xassert_eqq($rf->value_class(8), "sv sv5");
        xassert_eqq($rf->value_class(7), "sv sv6");
        xassert_eqq($rf->value_class(6), "sv sv6");
        xassert_eqq($rf->value_class(5), "sv sv7");
        xassert_eqq($rf->value_class(4), "sv sv7");
        xassert_eqq($rf->value_class(3), "sv sv8");
        xassert_eqq($rf->value_class(2), "sv sv8");
        xassert_eqq($rf->value_class(1), "sv sv9");
        $rf = $this->conf->find_review_field("B10");
        assert($rf instanceof Score_ReviewField);
        xassert_eqq($rf->value_class(10), "sv sv1");
        xassert_eqq($rf->value_class(9), "sv sv2");
        xassert_eqq($rf->value_class(8), "sv sv3");
        xassert_eqq($rf->value_class(7), "sv sv4");
        xassert_eqq($rf->value_class(6), "sv sv5");
        xassert_eqq($rf->value_class(5), "sv sv5");
        xassert_eqq($rf->value_class(4), "sv sv6");
        xassert_eqq($rf->value_class(3), "sv sv7");
        xassert_eqq($rf->value_class(2), "sv sv8");
        xassert_eqq($rf->value_class(1), "sv sv9");

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf__1__id" => "s03",
            "rf__1__delete" => "1",
            "rf__2__id" => "s04",
            "rf__2__delete" => "1",
            "rf__3__id" => "s06",
            "rf__3__delete" => "1",
            "rf__4__id" => "s07",
            "rf__4__delete" => "1"
        ]);
        xassert($sv->execute());
        xassert(!$this->conf->find_review_field("B5"));
    }

    function test_review_name_required() {
        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf__1__id" => "s90",
            "rf__1__choices" => "1. A\n2. B\n"
        ]);
        xassert(!$sv->execute());
        xassert_neqq(strpos($sv->full_feedback_text(), "Entry required"), false);
    }

    function test_responses() {
        if ($this->conf->setting_data("responses")) {
            $this->conf->save_refresh_setting("responses", null);
            $this->conf->qe("delete from PaperComment where (commentType&?)!=0", CommentInfo::CT_RESPONSE);
        }

        $rrds = $this->conf->response_rounds();
        xassert_eqq(count($rrds), 1);
        xassert_eqq($rrds[0]->number, 0);
        xassert_eqq($rrds[0]->name, "1");
        xassert($rrds[0]->unnamed);

        // rename unnamed response round
        $sv = SettingValues::make_request($this->u_chair, [
            "has_response" => 1,
            "response__1__id" => "0",
            "response__1__name" => "Butt",
            "response__1__open" => "@" . (Conf::$now - 1),
            "response__1__done" => "@" . (Conf::$now + 10000)
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->updated_fields(), ["responses"]);

        $rrds = $this->conf->response_rounds();
        xassert_eqq(count($rrds), 1);
        xassert_eqq($rrds[0]->number, 0);
        xassert_eqq($rrds[0]->name, "Butt");
        xassert(!$rrds[0]->unnamed);

        // add a response
        assert_search_papers($this->u_chair, "has:response", "");
        assert_search_papers($this->u_chair, "has:Buttresponse", "");

        $result = $this->conf->qe("insert into PaperComment (paperId,contactId,timeModified,timeDisplayed,comment,commentType,replyTo,commentRound) values (1,?,?,?,'Hi',?,0,?)", $this->u_chair->contactId, Conf::$now, Conf::$now, CommentInfo::CT_AUTHOR | CommentInfo::CT_RESPONSE, 0);
        $new_commentId = $result->insert_id;

        assert_search_papers($this->u_chair, "has:response", "1");
        assert_search_papers($this->u_chair, "has:Buttresponse", "1");

        // changes ignored if response_active checkbox off
        $sv = SettingValues::make_request($this->u_chair, [
            "has_response_active" => 1,
            "has_response" => 1,
            "response__1__id" => "0",
            "response__1__name" => "ButtJRIOQOIFNINF",
            "response__1__open" => "@" . (Conf::$now - 1),
            "response__1__done" => "@" . (Conf::$now + 10000)
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->updated_fields(), []);

        // add an unnamed response round
        $sv = SettingValues::make_request($this->u_chair, [
            "has_response" => 1,
            "response__1__id" => '$',
            "response__1__name" => "",
            "response__1__open" => "@" . (Conf::$now - 1),
            "response__1__done" => "@" . (Conf::$now + 10000)
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->updated_fields(), ["responses"]);

        $rrds = $this->conf->response_rounds();
        xassert_eqq(count($rrds), 2);
        xassert_eqq($rrds[0]->number, 0);
        xassert_eqq($rrds[0]->name, "1");
        xassert($rrds[0]->unnamed);
        xassert_eqq($rrds[1]->number, 1);
        xassert_eqq($rrds[1]->name, "Butt");
        xassert(!$rrds[1]->unnamed);

        assert_search_papers($this->u_chair, "has:response", "1");
        assert_search_papers($this->u_chair, "has:Buttresponse", "1");

        $this->conf->save_refresh_setting("responses", null);
        $this->conf->qe("delete from PaperComment where paperId=1 and commentId=?", $new_commentId);
    }

    function test_conflictdef() {
        $fr = new FieldRender(FieldRender::CFHTML);
        $this->conf->option_by_id(PaperOption::PCCONFID)->render_description($fr);
        xassert_eqq($fr->value, "Select the PC members who have conflicts of interest with this submission. This includes past advisors and students, people with the same affiliation, and any recent (~2 years) coauthors and collaborators.");
        $this->conf->save_setting("msg.conflictdef", 1, "FART");
        $this->conf->load_settings();
        $this->conf->option_by_id(PaperOption::PCCONFID)->render_description($fr);
        xassert_eqq($fr->value, "Select the PC members who have conflicts of interest with this submission. FART");
        $this->conf->save_setting("msg.conflictdef", null);
        $this->conf->load_settings();
    }

    function test_subform_condition() {
        TestRunner::reset_options();

        // recursive condition not allowed
        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf__1__name" => "Program",
            "sf__1__id" => "\$",
            "sf__1__order" => 100,
            "sf__1__choices" => "Honors\nMBB\nJoint primary\nJoint affiliated\nBasic",
            "sf__1__type" => "radio",
            "sf__1__presence" => "custom",
            "sf__1__condition" => "Program:Honors"
        ]);
        xassert(!$sv->execute());

        // newly-added field conditions can refer to other newly-added fields
        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf__1__name" => "Program",
            "sf__1__id" => "\$",
            "sf__1__order" => 100,
            "sf__1__choices" => "Honors\nMBB\nJoint primary\nJoint affiliated\nBasic",
            "sf__1__type" => "radio",
            "sf__2__name" => "Joint concentration",
            "sf__2__id" => "\$",
            "sf__2__order" => 101,
            "sf__2__type" => "text",
            "sf__2__presence" => "custom",
            "sf__2__condition" => "Program:Joint*"
        ]);
        xassert($sv->execute());
        xassert_eqq(trim($sv->full_feedback_text()), "");
    }
}
