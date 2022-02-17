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

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
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

    function test_topics() {
        $this->conf->qe("delete from TopicInterest");
        $this->conf->qe("truncate table TopicArea");
        $this->conf->qe("alter table TopicArea auto_increment=0");
        $this->conf->qe("delete from PaperTopic");

        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '[]');
        $sv = SettingValues::make_request($this->u_chair, [
            "has_topics" => 1,
            "topic__newlist" => "Fart\n   Barf"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '{"2":"Barf","1":"Fart"}');

        // duplicate topic not accepted
        $sv = SettingValues::make_request($this->u_chair, [
            "has_topics" => 1,
            "topic__newlist" => "Fart"
        ]);
        xassert(!$sv->execute());
        xassert_eqq($sv->reqstr("topic__3__name"), "Fart");
        xassert($sv->has_error_at("topic__3__name"));
        xassert_neqq(strpos($sv->full_feedback_text(), "is not unique"), false);
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '{"2":"Barf","1":"Fart"}');

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topics" => 1,
            "topic__newlist" => "Fart2"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '{"2":"Barf","1":"Fart","3":"Fart2"}');

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topics" => 1,
            "topic__1__id" => "2",
            "topic__1__name" => "Fért",
            "topic__2__id" => "",
            "topic__2__name" => "Festival Fartal",
            "topic__3__id" => "\$",
            "topic__3__name" => "Fet",
            "topic__newlist" => "Fart3"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Fart","3":"Fart2","6":"Fart3","2":"Fért","4":"Festival Fartal","5":"Fet"}');
    }

    function test_decision_types() {
        $this->conf->save_refresh_setting("outcome_map", null);
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","1":"Accepted","-1":"Rejected"}');
        xassert_eqq($this->conf->setting("decisions"), null);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_decisions" => 1,
            "decision__1__name" => "Accepted!",
            "decision__1__id" => "1",
            "decision__2__name" => "Newly accepted",
            "decision__2__id" => "\$"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","1":"Accepted!","2":"Newly accepted","-1":"Rejected"}');
        xassert_eqq($this->conf->setting("decisions"), null);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_decisions" => 1,
            "decision__1__id" => "1",
            "decision__1__delete" => "1"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","2":"Newly accepted","-1":"Rejected"}');

        // accept-category with “reject” in the name is rejected by default
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decisions" => 1,
            "decision__1__id" => "2",
            "decision__1__name" => "Rejected"
        ]);
        xassert(!$sv->execute());
        xassert_neqq(strpos($sv->full_feedback_text(), "Accept-category decision"), false);

        // duplicate decision names are rejected
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decisions" => 1,
            "decision__1__id" => "2",
            "decision__1__name" => "Rejected",
            "decision__1__name_force" => "1"
        ]);
        xassert(!$sv->execute());
        xassert_neqq(strpos($sv->full_feedback_text(), "is not unique"), false);
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","2":"Newly accepted","-1":"Rejected"}');

        // can override name conflict
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decisions" => 1,
            "decision__1__id" => "2",
            "decision__1__name" => "Really Rejected",
            "decision__1__name_force" => "1"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","2":"Really Rejected","-1":"Rejected"}');

        // missing name => error
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decisions" => 1,
            "decision__1__id" => "\$"
        ]);
        xassert(!$sv->execute());

        // restore default decisions => no database setting
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decisions" => 1,
            "decision__1__id" => "\$",
            "decision__1__name" => "Accepted",
            "decision__2__id" => "2",
            "decision__2__delete" => "1"
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
            "has_review_form" => 1,
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
        xassert_eqq($rf->value_class(1), "sv sv9");
        xassert_eqq($rf->value_class(2), "sv sv7");
        xassert_eqq($rf->value_class(3), "sv sv5");
        xassert_eqq($rf->value_class(4), "sv sv3");
        xassert_eqq($rf->value_class(5), "sv sv1");
        $rf = $this->conf->find_review_field("B9");
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
            "has_review_form" => 1,
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
        xassert_eqq($rf->value_class(1), "sv sv1");
        xassert_eqq($rf->value_class(2), "sv sv3");
        xassert_eqq($rf->value_class(3), "sv sv5");
        xassert_eqq($rf->value_class(4), "sv sv7");
        xassert_eqq($rf->value_class(5), "sv sv9");
        $rf = $this->conf->find_review_field("B9");
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
            "has_review_form" => 1,
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
}
