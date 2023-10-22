<?php
// t_settings.php -- HotCRP tests
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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
        $si = $this->conf->si("fmtstore_s_0");
        xassert_eqq($si->storage_type, Si::SI_DATA | Si::SI_SLICE);
        xassert_eqq($si->storage_name(), "sub_banal");
        $si = $this->conf->si("fmtstore_s_4");
        xassert_eqq($si->storage_type, Si::SI_DATA | Si::SI_SLICE);
        xassert_eqq($si->storage_name(), "sub_banal_4");

        $si = $this->conf->si("rf/1/order");
        xassert_eqq($si->first_page(), "reviewform");

        $si = $this->conf->si("track/1/perm/view/tag");
        xassert_eqq($si->first_page(), "tags");
    }

    function test_message_defaults() {
        xassert(!$this->conf->setting("has_topics"));
        ConfInvariants::test_all($this->conf);

        $sv = SettingValues::make_request($this->u_chair, []);
        $s = $this->conf->si("preference_instructions")->default_value($sv);
        xassert_str_contains($s, "review preference");
        xassert_not_str_contains($s, "topic");

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "new_topics" => "Whatever\n"
        ]);
        xassert($sv->execute());

        $s = $this->conf->si("preference_instructions")->default_value($sv);
        xassert_str_contains($s, "review preference");
        xassert_str_contains($s, "topic");

        ConfInvariants::test_all($this->conf);
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '{"1":"Whatever"}');

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "topic/1/name" => "Whatever",
            "topic/1/delete" => 1
        ]);
        xassert($sv->execute());

        xassert_eqq($this->conf->setting("has_topics"), null);
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '[]');
    }

    function delete_topics() {
        $this->conf->qe("delete from TopicInterest");
        $this->conf->qe("truncate table TopicArea");
        $this->conf->qe("alter table TopicArea auto_increment=0");
        $this->conf->qe("delete from PaperTopic");
        $this->conf->save_refresh_setting("has_topics", null);
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
        xassert_eqq($sv->reqstr("topic/3/name"), "Fart");
        xassert($sv->has_error_at("topic/3/name"));
        xassert_str_contains($sv->full_feedback_text(), "is not unique");
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '{"2":"Barf","1":"Fart"}');

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "new_topics" => "Fart2"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '{"2":"Barf","1":"Fart","3":"Fart2"}');

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "topic/1/id" => "2",
            "topic/1/name" => "Fért",
            "topic/2/id" => "",
            "topic/2/name" => "Festival Fartal",
            "topic/3/id" => "new",
            "topic/3/name" => "Fet",
            "new_topics" => "Fart3"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Fart","3":"Fart2","6":"Fart3","2":"Fért","4":"Festival Fartal","5":"Fet"}');

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "topic/1/id" => "",
            "topic/1/name" => "Fért",
            "topic/2/id" => "",
            "topic/2/name" => "Festival Fartal",
            "topic/3/id" => "",
            "topic/3/name" => "Foop"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Fart","3":"Fart2","6":"Fart3","2":"Fért","4":"Festival Fartal","5":"Fet","7":"Foop"}');

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "topic/1/id" => "1",
            "topic/1/delete" => "1",
            "topic/2/id" => "2",
            "topic/2/delete" => "1",
            "topic/3/id" => "3",
            "topic/3/delete" => "1",
            "topic/4/id" => "4",
            "topic/4/delete" => "1",
            "topic/5/id" => "5",
            "topic/5/delete" => "1",
            "topic/6/id" => "6",
            "topic/6/delete" => "1",
            "topic/7/id" => "7",
            "topic/7/delete" => "1"
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
            "reset": true,
            "topic_reset": false,
            "topic": [{"id": 1, "name": "Berf"}]
        }');
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Berf","2":"Fart","3":"Money"}');

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "topic": [{"id": 1, "name": "Berf"}], "reset": true
        }');
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), ["topics"]);
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Berf"}');

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "topic": [{"id": "new", "name": "Berf"}]
        }');
        xassert(!$sv->execute());
        xassert_str_contains($sv->full_feedback_text(), "is not unique");

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "topic": [{"name": "Bingle"}, {"name": "Bongle"}]
        }');
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Berf","4":"Bingle","5":"Bongle"}');

        $this->delete_topics();
    }

    /** @return string */
    private function json_decision_map() {
        $x = [];
        foreach ($this->conf->decision_set() as $dec) {
            $x[$dec->id] = $dec->name;
        }
        return json_encode((object) $x);
    }

    function test_decision_types() {
        xassert(ConfInvariants::test_summary_settings($this->conf));

        $this->conf->save_refresh_setting("outcome_map", null);
        xassert_eqq($this->json_decision_map(), '{"0":"Unspecified","1":"Accepted","-1":"Rejected"}');
        xassert_eqq($this->conf->setting("decisions"), null);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision/1/name" => "Accepted!",
            "decision/1/id" => "1",
            "decision/2/name" => "Newly accepted",
            "decision/2/id" => "new",
            "decision/2/category" => "accept"
        ]);
        xassert($sv->execute());
        xassert_eqq($this->json_decision_map(), '{"0":"Unspecified","1":"Accepted!","2":"Newly accepted","-1":"Rejected"}');
        xassert_eqq($this->conf->setting("decisions"), null);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision/1/id" => "1",
            "decision/1/delete" => "1"
        ]);
        xassert($sv->execute());
        xassert_eqq($this->json_decision_map(), '{"0":"Unspecified","2":"Newly accepted","-1":"Rejected"}');
        xassert(ConfInvariants::test_summary_settings($this->conf));

        // accept-category with “reject” in the name is rejected by default
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision/1/id" => "2",
            "decision/1/name" => "Rejected"
        ]);
        xassert(!$sv->execute());
        xassert_str_contains($sv->full_feedback_text(), "Accept-category decision");

        // duplicate decision names are rejected
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision/1/id" => "2",
            "decision/1/name" => "Rejected",
            "decision/1/name_force" => "1"
        ]);
        xassert(!$sv->execute());
        xassert_str_contains($sv->full_feedback_text(), "is not unique");
        xassert_eqq($this->json_decision_map(), '{"0":"Unspecified","2":"Newly accepted","-1":"Rejected"}');

        // can override name conflict
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision/1/id" => "2",
            "decision/1/name" => "Really Rejected",
            "decision/1/name_force" => "1",
            "decision/2/id" => "new",
            "decision/2/name" => "Whatever",
            "decision/2/category" => "reject"
        ]);
        xassert($sv->execute());
        xassert_eqq($this->json_decision_map(), '{"0":"Unspecified","2":"Really Rejected","-1":"Rejected","-2":"Whatever"}');

        // not change name => no need to override conflict
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision/1/id" => "2",
            "decision/1/name" => "Really Rejected",
            "decision/2/id" => "-2",
            "decision/2/name" => "Well I dunno"
        ]);
        xassert($sv->execute());
        xassert_eqq($this->json_decision_map(), '{"0":"Unspecified","2":"Really Rejected","-1":"Rejected","-2":"Well I dunno"}');

        // missing name => error
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision/1/id" => "new"
        ]);
        xassert(!$sv->execute());
        xassert(ConfInvariants::test_summary_settings($this->conf));

        // restore default decisions => no database setting
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision/1/id" => "new",
            "decision/1/name" => "Accepted",
            "decision/2/id" => "2",
            "decision/2/delete" => "1",
            "decision/3/id" => "-2",
            "decision/3/delete" => "1"
        ]);
        xassert($sv->execute());
        xassert_eqq($this->json_decision_map(), '{"0":"Unspecified","1":"Accepted","-1":"Rejected"}');
        xassert_eqq($this->conf->setting("outcome_map"), null);
        xassert(ConfInvariants::test_summary_settings($this->conf));
    }

    function test_decision_setting_as_list() {
        $x = $this->conf->setting_data("outcome_map");
        $this->conf->save_refresh_setting("outcome_map", 1, '["Unspecified","Accepted","Accepted II"]'); // Old settings could save this format
        xassert_eqq($this->json_decision_map(), '{"0":"Unspecified","1":"Accepted","2":"Accepted II"}');
        xassert_eqq($this->conf->decision_set()->unparse_database(), '{"1":"Accepted","2":"Accepted II"}');
        $this->conf->save_refresh_setting("outcome_map", 1, $x);
    }

    /** @param ReviewField $rf
     * @param int|string $fval
     * @return string */
    static function unparse_text_field_content($rf, $fval) {
        $t = [];
        $rf->unparse_text_field($t, $fval, ["flowed" => false]);
        return join("", $t);
    }

    function test_scores() {
        xassert(!$this->conf->find_review_field("B5"));
        xassert(!$this->conf->find_review_field("B9"));
        xassert(!$this->conf->find_review_field("B10"));
        xassert(!$this->conf->find_review_field("B15"));

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/name" => "B9",
            "rf/1/id" => "s03",
            "rf/1/values_text" => "1. A\n2. B\n3. C\n4. D\n5. E\n6. F\n7. G\n8. H\n9. I",
            "rf/2/name" => "B15",
            "rf/2/id" => "s04",
            "rf/2/values_text" => "1. A\n2. B\n3. C\n4. D\n5. E\n6. F\n7. G\n8. H\n9. I\n10. J\n11. K\n12. L\n13. M\n14. N\n15. O",
            "rf/3/name" => "B10",
            "rf/3/id" => "s06",
            "rf/3/values_text" => "1. A\n2. B\n3. C\n4. D\n5. E\n6. F\n7. G\n8. H\n9. I\n10. J",
            "rf/4/name" => "B5",
            "rf/4/id" => "s07",
            "rf/4/values_text" => "A. A\nB. B\nC. C\nD. D\nE. E",
            "rf/4/required" => 1
        ]);
        xassert($sv->execute());

        $rf = $this->conf->find_review_field("B5");
        assert($rf instanceof Score_ReviewField);
        xassert($rf->required);
        xassert_array_eqq($rf->ordered_symbols(), ["A", "B", "C", "D", "E"]);
        xassert_array_eqq($rf->ordered_values(), ["A", "B", "C", "D", "E"]);
        xassert_eqq($rf->unparse_value(1), "E");
        xassert_eqq($rf->unparse_value(2), "D");
        xassert_eqq($rf->unparse_value(3), "C");
        xassert_eqq($rf->unparse_value(4), "B");
        xassert_eqq($rf->unparse_value(5), "A");
        xassert_eqq($rf->unparse_json(1), "E");
        xassert_eqq($rf->unparse_json(2), "D");
        xassert_eqq($rf->unparse_json(3), "C");
        xassert_eqq($rf->unparse_json(4), "B");
        xassert_eqq($rf->unparse_json(5), "A");
        xassert_eqq(self::unparse_text_field_content($rf, 5), "\nB5\n--\nA. A\n");
        xassert_eqq(self::unparse_text_field_content($rf, 4), "\nB5\n--\nB. B\n");
        xassert_eqq(self::unparse_text_field_content($rf, 3), "\nB5\n--\nC. C\n");
        xassert_eqq(self::unparse_text_field_content($rf, 2), "\nB5\n--\nD. D\n");
        xassert_eqq(self::unparse_text_field_content($rf, 1), "\nB5\n--\nE. E\n");
        xassert_eqq(self::unparse_text_field_content($rf, 0), "");
        xassert_eqq($rf->value_class(1), "sv sv9");
        xassert_eqq($rf->value_class(2), "sv sv7");
        xassert_eqq($rf->value_class(3), "sv sv5");
        xassert_eqq($rf->value_class(4), "sv sv3");
        xassert_eqq($rf->value_class(5), "sv sv1");
        $rf = $this->conf->find_review_field("B9");
        assert($rf instanceof Score_ReviewField);
        xassert_array_eqq($rf->ordered_symbols(), [1, 2, 3, 4, 5, 6, 7, 8, 9]);
        xassert_array_eqq($rf->ordered_values(), ["A", "B", "C", "D", "E", "F", "G", "H", "I"]);
        xassert_eqq($rf->unparse_value(1), "1");
        xassert_eqq($rf->unparse_value(2), "2");
        xassert_eqq($rf->unparse_value(3), "3");
        xassert_eqq($rf->unparse_value(4), "4");
        xassert_eqq($rf->unparse_value(5), "5");
        xassert_eqq($rf->unparse_value(6), "6");
        xassert_eqq($rf->unparse_value(7), "7");
        xassert_eqq($rf->unparse_value(8), "8");
        xassert_eqq($rf->unparse_value(9), "9");
        xassert_eqq($rf->unparse_json(1), 1);
        xassert_eqq($rf->unparse_json(2), 2);
        xassert_eqq($rf->unparse_json(3), 3);
        xassert_eqq($rf->unparse_json(4), 4);
        xassert_eqq($rf->unparse_json(5), 5);
        xassert_eqq($rf->unparse_json(6), 6);
        xassert_eqq($rf->unparse_json(7), 7);
        xassert_eqq($rf->unparse_json(8), 8);
        xassert_eqq($rf->unparse_json(9), 9);
        xassert_eqq(self::unparse_text_field_content($rf, 1), "\nB9\n--\n1. A\n");
        xassert_eqq(self::unparse_text_field_content($rf, 2), "\nB9\n--\n2. B\n");
        xassert_eqq(self::unparse_text_field_content($rf, 3), "\nB9\n--\n3. C\n");
        xassert_eqq(self::unparse_text_field_content($rf, 4), "\nB9\n--\n4. D\n");
        xassert_eqq(self::unparse_text_field_content($rf, 5), "\nB9\n--\n5. E\n");
        xassert_eqq(self::unparse_text_field_content($rf, 6), "\nB9\n--\n6. F\n");
        xassert_eqq(self::unparse_text_field_content($rf, 7), "\nB9\n--\n7. G\n");
        xassert_eqq(self::unparse_text_field_content($rf, 8), "\nB9\n--\n8. H\n");
        xassert_eqq(self::unparse_text_field_content($rf, 9), "\nB9\n--\n9. I\n");
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
        xassert_array_eqq($rf->ordered_symbols(), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15]);
        xassert_array_eqq($rf->ordered_values(), ["A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O"]);
        xassert_eqq($rf->unparse_value(1), "1");
        xassert_eqq($rf->unparse_value(2), "2");
        xassert_eqq($rf->unparse_value(3), "3");
        xassert_eqq($rf->unparse_value(4), "4");
        xassert_eqq($rf->unparse_value(5), "5");
        xassert_eqq($rf->unparse_value(6), "6");
        xassert_eqq($rf->unparse_value(7), "7");
        xassert_eqq($rf->unparse_value(8), "8");
        xassert_eqq($rf->unparse_value(9), "9");
        xassert_eqq($rf->unparse_value(10), "10");
        xassert_eqq($rf->unparse_value(11), "11");
        xassert_eqq($rf->unparse_value(12), "12");
        xassert_eqq($rf->unparse_value(13), "13");
        xassert_eqq($rf->unparse_value(14), "14");
        xassert_eqq($rf->unparse_value(15), "15");
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
        xassert_array_eqq($rf->ordered_symbols(), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
        xassert_array_eqq($rf->ordered_values(), ["A", "B", "C", "D", "E", "F", "G", "H", "I", "J"]);
        xassert_eqq($rf->unparse_value(1), "1");
        xassert_eqq($rf->unparse_value(2), "2");
        xassert_eqq($rf->unparse_value(3), "3");
        xassert_eqq($rf->unparse_value(4), "4");
        xassert_eqq($rf->unparse_value(5), "5");
        xassert_eqq($rf->unparse_value(6), "6");
        xassert_eqq($rf->unparse_value(7), "7");
        xassert_eqq($rf->unparse_value(8), "8");
        xassert_eqq($rf->unparse_value(9), "9");
        xassert_eqq($rf->unparse_value(10), "10");
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
            "rf/1/id" => "s03",
            "rf/1/scheme" => "svr",
            "rf/2/id" => "s04",
            "rf/2/scheme" => "svr",
            "rf/3/id" => "s06",
            "rf/3/scheme" => "svr",
            "rf/4/id" => "s07",
            "rf/4/scheme" => "svr"
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->decorated_feedback_text(), "");
        $rf = $this->conf->find_review_field("B5");
        assert($rf instanceof Score_ReviewField);
        xassert_array_eqq($rf->ordered_symbols(), ["A", "B", "C", "D", "E"]);
        xassert_array_eqq($rf->ordered_values(), ["A", "B", "C", "D", "E"]);
        xassert_eqq($rf->unparse_computed(0.81), "E");
        xassert_eqq($rf->unparse_computed(1), "E");
        xassert_eqq($rf->unparse_computed(1.2), "E");
        xassert_eqq($rf->unparse_computed(1.4), "D~E");
        xassert_eqq($rf->unparse_computed(1.5), "D~E");
        xassert_eqq($rf->unparse_computed(1.6), "D~E");
        xassert_eqq($rf->unparse_computed(1.8), "D");
        xassert_eqq($rf->unparse_computed(2), "D");
        xassert_eqq($rf->unparse_computed(2.2), "D");
        xassert_eqq($rf->unparse_computed(5), "A");
        xassert_eqq($rf->value_class(1), "sv sv1");
        xassert_eqq($rf->value_class(2), "sv sv3");
        xassert_eqq($rf->value_class(3), "sv sv5");
        xassert_eqq($rf->value_class(4), "sv sv7");
        xassert_eqq($rf->value_class(5), "sv sv9");
        $rf = $this->conf->find_review_field("B9");
        assert($rf instanceof Score_ReviewField);
        xassert_array_eqq($rf->ordered_symbols(), [1, 2, 3, 4, 5, 6, 7, 8, 9]);
        xassert_array_eqq($rf->ordered_values(), ["A", "B", "C", "D", "E", "F", "G", "H", "I"]);
        xassert_eqq($rf->unparse_value(1), "1");
        xassert_eqq($rf->unparse_value(9), "9");
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
        xassert_array_eqq($rf->ordered_symbols(), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15]);
        xassert_array_eqq($rf->ordered_values(), ["A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O"]);
        xassert_eqq($rf->unparse_value(1), "1");
        xassert_eqq($rf->unparse_value(15), "15");
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
        xassert_array_eqq($rf->ordered_symbols(), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
        xassert_array_eqq($rf->ordered_values(), ["A", "B", "C", "D", "E", "F", "G", "H", "I", "J"]);
        xassert_eqq($rf->unparse_value(1), "1");
        xassert_eqq($rf->unparse_value(10), "10");
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
            "rf/1/id" => "s03",
            "rf/1/delete" => "1",
            "rf/2/id" => "s04",
            "rf/2/delete" => "1",
            "rf/3/id" => "s06",
            "rf/3/delete" => "1",
            "rf/4/id" => "s07",
            "rf/4/delete" => "1"
        ]);
        xassert($sv->execute());
        xassert(!$this->conf->find_review_field("B5"));
    }

    function test_review_name_required() {
        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s90",
            "rf/1/values_text" => "1. A\n2. B\n"
        ]);
        xassert(!$sv->execute());
        xassert_str_contains($sv->full_feedback_text(), "Entry required");
    }

    function test_review_renumber_choices() {
        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s05",
            "rf/1/name" => "Mf",
            "rf/1/values_text" => "A. A\nB. B\nC. C",
            "rf/2/id" => "s90",
            "rf/2/name" => "Jf",
            "rf/2/values_text" => "A. A\nB. B\nC. C"
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), ["review_form"]);

        xassert_eqq($sv->conf->fetch_ivalue("select reviewId from PaperReview where paperId=30 limit 1"), null);

        $sv->conf->save_refresh_setting("rev_open", 1);
        save_review(30, $this->u_mgbaker, [
            "ovemer" => 2, "revexp" => 1, "mf" => "A", "jf" => "A"
        ]);
        $u_jj = $this->conf->checked_user_by_email("jj@cse.ucsc.edu");
        save_review(30, $u_jj, [
            "ovemer" => 2, "revexp" => 1, "mf" => "B", "jf" => "B"
        ]);
        $u_floyd = $this->conf->checked_user_by_email("floyd@ee.lbl.gov");
        save_review(30, $u_floyd, [
            "ovemer" => 2, "revexp" => 1, "mf" => "C", "jf" => "C", "ready" => true
        ]);

        assert_search_papers($this->u_chair, "mf:C", "30");
        assert_search_papers($this->u_chair, "mf:<B", "30"); // XXX

        $rrow = checked_fresh_review(30, $this->u_mgbaker);
        xassert_eqq($rrow->fidval("s05"), 3);
        xassert_eqq($rrow->fidval("s90"), 3);
        $rrow = checked_fresh_review(30, $u_jj);
        xassert_eqq($rrow->fidval("s05"), 2);
        xassert_eqq($rrow->fidval("s90"), 2);
        $rrow = checked_fresh_review(30, $u_floyd);
        xassert_eqq($rrow->fidval("s05"), 1);
        xassert_eqq($rrow->fidval("s90"), 1);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s05",
            "rf/1/name" => "Mf",
            "rf/1/values_text" => "1. A\n2. B\n3. C",
            "rf/2/id" => "s90",
            "rf/2/name" => "Jf",
            "rf/2/values_text" => "X. B\nY. C\nZ. A"
        ]);
        xassert($sv->execute());

        $rrow = checked_fresh_review(30, $this->u_mgbaker);
        xassert_eqq($rrow->fidval("s05"), 1);
        xassert_eqq($rrow->fidval("s90"), 1);
        $rrow = checked_fresh_review(30, $u_jj);
        xassert_eqq($rrow->fidval("s05"), 2);
        xassert_eqq($rrow->fidval("s90"), 3);
        $rrow = checked_fresh_review(30, $u_floyd);
        xassert_eqq($rrow->fidval("s05"), 3);
        xassert_eqq($rrow->fidval("s90"), 2);

        xassert_eqq(review_score($this->conf, "s05")->ids(), [3, 2, 1]);
        xassert_eqq(review_score($this->conf, "s90")->ids(), [3, 1, 2]);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s05",
            "has_rf/1/values" => 1,
            "rf/1/values/1/id" => 3,        // was `1`, now `2`
            "rf/1/values/1/symbol" => 2,
            "rf/1/values/1/order" => 2,
            "rf/1/values/1/name" => "Yep",
            "rf/1/values/2/id" => 2,        // was `2`, now `1`
            "rf/1/values/2/symbol" => 1,
            "rf/1/values/2/order" => 1,
            "rf/1/values/2/name" => "It's bad",
            "rf/1/values/3/id" => 1,        // was `3`, now `3`
            "rf/1/values/3/symbol" => 3,
            "rf/1/values/3/order" => 3,
            "rf/1/values/3/name" => "Problem"
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->full_feedback_text(), "");

        xassert_eqq(review_score($this->conf, "s05")->values(), ["It's bad", "Yep", "Problem"]);

        $rrow = checked_fresh_review(30, $this->u_mgbaker);
        xassert_eqq($rrow->fidval("s05"), 2);
        $rrow = checked_fresh_review(30, $u_jj);
        xassert_eqq($rrow->fidval("s05"), 1);
        $rrow = checked_fresh_review(30, $u_floyd);
        xassert_eqq($rrow->fidval("s05"), 3);

        xassert_eqq(review_score($this->conf, "s05")->ids(), [2, 3, 1]);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s05",
            "has_rf/1/values" => 1,
            "rf/1/values/1/id" => 3,        // was `2`, now `3`
            "rf/1/values/1/symbol" => 3,
            "rf/1/values/1/order" => 3,
            "rf/1/values/1/name" => "Yep",
            "rf/1/values/2/id" => 2,        // was `1`, now `2`
            "rf/1/values/2/symbol" => 2,
            "rf/1/values/2/order" => 2,
            "rf/1/values/2/name" => "It's bad",
            "rf/1/values/3/id" => 1,        // was `3`, now `1`
            "rf/1/values/3/symbol" => 1,
            "rf/1/values/3/order" => 1,
            "rf/1/values/3/name" => "Problem"
        ]);
        xassert($sv->execute());

        xassert_eqq(review_score($this->conf, "s05")->values(), ["Problem", "It's bad", "Yep"]);

        $rrow = checked_fresh_review(30, $this->u_mgbaker);
        xassert_eqq($rrow->fidval("s05"), 3);
        $rrow = checked_fresh_review(30, $u_jj);
        xassert_eqq($rrow->fidval("s05"), 2);
        $rrow = checked_fresh_review(30, $u_floyd);
        xassert_eqq($rrow->fidval("s05"), 1);

        xassert_eqq(review_score($this->conf, "s05")->ids(), [1, 2, 3]);
        foreach ($this->conf->setting_json("review_form") as $rj) {
            if ($rj->id === "s05")
                xassert(!isset($rj->ids));
        }

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s05",
            "rf/2/id" => "s90",
            "rf/1/delete" => "1",
            "rf/2/delete" => "1"
        ]);
        xassert($sv->execute());

        $rrow = checked_fresh_review(30, $this->u_mgbaker);
        xassert_eqq($rrow->fidval("s05"), null);
        xassert_eqq($rrow->fidval("s90"), null);
        $rrow = checked_fresh_review(30, $u_jj);
        xassert_eqq($rrow->fidval("s05"), null);
        xassert_eqq($rrow->fidval("s90"), null);
        $rrow = checked_fresh_review(30, $u_floyd);
        xassert_eqq($rrow->fidval("s05"), null);
        xassert_eqq($rrow->fidval("s90"), null);

        $this->conf->qe("delete from PaperReview where paperId=30");
    }

    function test_review_conditions() {
        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s05",
            "rf/1/name" => "Mf",
            "rf/1/values_text" => "A. A\nB. B\nC. C",
            "rf/1/presence" => "custom",
            "rf/1/condition" => "re:ext",
            "rf/2/id" => "s90",
            "rf/2/name" => "Jf",
            "rf/2/values_text" => "A. A\nB. B\nC. C",
            "rf/2/presence" => "custom",
            "rf/2/condition" => "re:pri"
        ]);
        xassert($sv->execute());

        $s05 = $this->conf->checked_review_field("s05");
        xassert_eqq($s05->exists_condition(), "re:ext");
        $s90 = $this->conf->checked_review_field("s90");
        xassert_eqq($s90->exists_condition(), "re:pri");

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s05",
            "rf/1/name" => "Mf",
            "rf/1/values_text" => "1. A\n2. B\n3. C",
            "rf/2/id" => "s90",
            "rf/2/name" => "Jf",
            "rf/2/values_text" => "X. B\nY. C\nZ. A"
        ]);
        xassert($sv->execute());

        $s05 = $this->conf->checked_review_field("s05");
        xassert_eqq($s05->exists_condition(), "re:ext");
        $s90 = $this->conf->checked_review_field("s90");
        xassert_eqq($s90->exists_condition(), "re:pri");

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s05",
            "rf/1/presence" => "round:unnamed"
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->full_feedback_text(), "");

        $s05 = $this->conf->checked_review_field("s05");
        xassert_neqq($s05->round_mask, 0);
        xassert_eqq($s05->exists_condition(), "round:unnamed");

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s05",
            "rf/1/condition" => "all"
        ]);
        xassert($sv->execute());

        $s05 = $this->conf->checked_review_field("s05");
        xassert_eqq($s05->round_mask, 0);
        xassert_eqq($s05->exists_condition(), null);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s05",
            "rf/1/condition" => "none"
        ]);
        xassert($sv->execute());

        $s05 = $this->conf->checked_review_field("s05");
        xassert_eqq($s05->round_mask, 0);
        xassert_eqq($s05->exists_condition(), "none");

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s05",
            "rf/2/id" => "s90",
            "rf/1/delete" => "1",
            "rf/2/delete" => "1"
        ]);
        xassert($sv->execute());
    }

    function test_review_field_id_new() {
        $rfkeys = array_keys($this->conf->all_review_fields());

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/name" => "Nude Feeled",
            "rf/1/id" => "new",
            "rf/1/type" => "radio",
            "rf/1/values_text" => "1. A\n2. B\n3. C\n4. D\n5. E\n6. F\n7. G\n8. H\n9. I",
            "rf/2/name" => "Clothings",
            "rf/2/id" => "new",
            "rf/2/type" => "text",
            "rf/2/required" => "1"
        ]);
        xassert($sv->execute());
        xassert_eqq(trim($sv->full_feedback_text()), "");

        $rf1 = $this->conf->find_review_field("NudFee");
        xassert(!!$rf1);
        xassert_not_in_eqq($rf1->short_id, $rfkeys);
        xassert_eqq($rf1->short_id[0], "s");
        xassert_eqq($rf1->required, false);

        $rf2 = $this->conf->find_review_field("Clothings");
        xassert(!!$rf2);
        xassert_not_in_eqq($rf2->short_id, $rfkeys);
        xassert_eqq($rf2->short_id[0], "t");
        xassert_eqq($rf2->required, true);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => $rf2->short_id,
            "rf/1/delete" => 1
        ]);
        xassert($sv->execute());
    }

    function test_review_rounds() {
        $tn = Conf::$now + 10;

        // reset existing review rounds
        $sv = SettingValues::make_request($this->u_chair, [
            "has_review" => 1,
            "reset" => 1
        ]);
        xassert($sv->execute());
        xassert(!$sv->conf->has_rounds());

        // add a review round
        $sv = SettingValues::make_request($this->u_chair, [
            "has_review" => 1,
            "review/1/id" => "new",
            "review/1/name" => "Butt",
            "review/1/soft" => "@{$tn}",
            "review/1/done" => "@" . ($tn + 10),
            "review/2/id" => "new",
            "review/2/name" => "Fart",
            "review/2/soft" => "@" . ($tn + 1),
            "review/2/done" => "@" . ($tn + 10),
            "review_default_round" => "Fart"
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->conf->round_list(), ["", "Butt", "Fart"]);

        // check review_default_round
        $sv = SettingValues::make_request($this->u_chair, [
            "has_review" => 1,
            "review_default_round" => "biglemd"
        ]);
        xassert(!$sv->execute());

        // check deadline relationships
        $sv = SettingValues::make_request($this->u_chair, [
            "has_review" => 1,
            "review/1/name" => "Butt",
            "review/1/soft" => "@{$tn}",
            "review/1/done" => "@" . ($tn - 10)
        ]);
        xassert(!$sv->execute());
        xassert_str_contains(strtolower($sv->full_feedback_text()), "must come before");

        xassert_eqq($sv->conf->round_number("Butt"), 1);
        $sv->conf->save_refresh_setting("pcrev_hard_1", $tn - 10);
        $sv = SettingValues::make_request($this->u_chair, [
            "has_review" => 1,
            "review/1/name" => "Butt",
            "review/1/soft" => "@{$tn}",
            "review/1/done" => "@" . ($tn - 10)
        ]);
        xassert($sv->execute());

        $sv = SettingValues::make_request($this->u_chair, [
            "has_review" => 1,
            "review/1/name" => "Butt",
            "review/1/soft" => "@{$tn}",
            "review/1/done" => "none"
        ]);
        xassert($sv->execute());
    }

    function test_responses() {
        if ($this->conf->setting_data("responses")) {
            $this->conf->save_refresh_setting("responses", null);
            $this->conf->qe("delete from PaperComment where (commentType&?)!=0", CommentInfo::CT_RESPONSE);
        }

        $rrds = $this->conf->response_rounds();
        xassert_eqq(count($rrds), 1);
        xassert_eqq($rrds[0]->id, 1);
        xassert_eqq($rrds[0]->name, "1");
        xassert($rrds[0]->unnamed);
        $t0 = Conf::$now - 1;

        // rename unnamed response round
        $sv = SettingValues::make_request($this->u_chair, [
            "has_response" => 1,
            "response/1/id" => "1",
            "response/1/name" => "Butt",
            "response/1/open" => "@{$t0}",
            "response/1/done" => "@" . ($t0 + 10000),
            "response/1/wordlimit" => "0"
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->changed_keys(), ["responses"]);

        $rrds = $this->conf->response_rounds();
        xassert_eqq(count($rrds), 1);
        xassert_eqq($rrds[0]->id, 1);
        xassert_eqq($rrds[0]->name, "Butt");
        xassert_eqq($rrds[0]->open, $t0);
        xassert_eqq($rrds[0]->done, $t0 + 10000);
        xassert(!$rrds[0]->unnamed);

        // add a response
        assert_search_papers($this->u_chair, "has:response", "");
        assert_search_papers($this->u_chair, "has:Buttresponse", "");

        $result = $this->conf->qe("insert into PaperComment (paperId,contactId,timeModified,timeDisplayed,comment,commentType,replyTo,commentRound) values (1,?,?,?,'Hi',?,0,?)", $this->u_chair->contactId, Conf::$now, Conf::$now, CommentInfo::CTVIS_AUTHOR | CommentInfo::CT_RESPONSE, 1);
        $new_commentId = $result->insert_id;

        assert_search_papers($this->u_chair, "has:response", "1");
        assert_search_papers($this->u_chair, "has:Buttresponse", "1");

        // changes ignored if response_active checkbox off
        $sv = SettingValues::make_request($this->u_chair, [
            "response_requires_active" => 1,
            "has_response_active" => 1,
            "has_response" => 1,
            "response/1/id" => "1",
            "response/1/name" => "ButtJRIOQOIFNINF",
            "response/1/open" => "@{$t0}",
            "response/1/done" => "@" . ($t0 + 10001)
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->changed_keys(), []);
        $rrd = $this->conf->response_round_by_id(1);
        xassert_eqq($rrd->name, "Butt");
        xassert_eqq($rrd->open, $t0);
        xassert_eqq($rrd->done, $t0 + 10000);

        // add an unnamed response round
        $sv = SettingValues::make_request($this->u_chair, [
            "has_response" => 1,
            "response/1/id" => "new",
            "response/1/name" => "",
            "response/1/open" => "@{$t0}",
            "response/1/done" => "@" . ($t0 + 10002),
            "response/1/wordlimit" => "0"
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->changed_keys(), ["responses"]);

        $rrds = $this->conf->response_rounds();
        xassert_eqq(count($rrds), 2);
        xassert_eqq($rrds[0]->id, 1);
        xassert_eqq($rrds[0]->name, "1");
        xassert_eqq($rrds[0]->open, $t0);
        xassert_eqq($rrds[0]->done, $t0 + 10002);
        xassert($rrds[0]->unnamed);
        xassert_eqq($rrds[1]->id, 2);
        xassert_eqq($rrds[1]->name, "Butt");
        xassert_eqq($rrds[1]->done, $t0 + 10000);
        xassert(!$rrds[1]->unnamed);

        assert_search_papers($this->u_chair, "has:response", "1");
        assert_search_papers($this->u_chair, "has:unnamedresponse", "");
        assert_search_papers($this->u_chair, "has:Buttresponse", "1");

        // switch response round names
        $sv = SettingValues::make_request($this->u_chair, [
            "has_response" => 1,
            "response/1/id" => "1",
            "response/1/name" => "Butt",
            "response/2/id" => "2",
            "response/2/name" => "unnamed"
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->changed_keys(), ["responses"]);

        $rrds = $this->conf->response_rounds();
        xassert_eqq(count($rrds), 2);
        xassert_eqq($rrds[0]->id, 1);
        xassert_eqq($rrds[0]->name, "1");
        xassert_eqq($rrds[0]->done, $t0 + 10000);
        xassert($rrds[0]->unnamed);
        xassert_eqq($rrds[1]->id, 2);
        xassert_eqq($rrds[1]->name, "Butt");
        xassert_eqq($rrds[1]->done, $t0 + 10002);
        xassert(!$rrds[1]->unnamed);

        assert_search_papers($this->u_chair, "has:response", "1");
        assert_search_papers($this->u_chair, "has:unnamedresponse", "1");
        assert_search_papers($this->u_chair, "has:Buttresponse", "");

        // response instructions & defaults
        $definstrux = $this->conf->fmt()->default_translation("resp_instrux");
        xassert_eqq($rrds[0]->instructions, null);
        xassert_eqq($rrds[0]->instructions($this->conf), $definstrux);
        xassert_eqq($rrds[1]->instructions, null);
        xassert_eqq($rrds[1]->instructions($this->conf), $definstrux);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_response" => 1,
            "response/1/id" => "1",
            "response/1/instructions" => "PANTS",
            "response/2/id" => "2",
            "response/2/instructions" => $definstrux
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->changed_keys(), ["responses"]);

        $rrds = $this->conf->response_rounds();
        xassert_eqq($rrds[0]->instructions, "PANTS");
        xassert_eqq($rrds[0]->instructions($this->conf), "PANTS");
        xassert_eqq($rrds[1]->instructions, null);
        xassert_eqq($rrds[1]->instructions($this->conf), $definstrux);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_response" => 1,
            "response/1/id" => "1",
            "response/1/instructions" => $definstrux
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->changed_keys(), ["responses"]);

        $rrds = $this->conf->response_rounds();
        xassert_eqq($rrds[0]->instructions, null);
        xassert_eqq($rrds[0]->instructions($this->conf), $definstrux);
        xassert_eqq($rrds[1]->instructions, null);
        xassert_eqq($rrds[1]->instructions($this->conf), $definstrux);

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

    function test_subform_options() {
        TestRunner::reset_options();

        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/name" => "Program",
            "sf/1/id" => "new",
            "sf/1/order" => 100,
            "sf/1/values_text" => "Honors\nMBB\nJoint primary\nJoint affiliated\nBasic",
            "sf/1/type" => "radio"
        ]);
        xassert($sv->execute());

        $opt = $sv->conf->option_by_id(2);
        assert($opt instanceof Selector_PaperOption);
        xassert_eqq($opt->name, "Program");
        xassert_array_eqq($opt->values(), ["Honors", "MBB", "Joint primary", "Joint affiliated", "Basic"]);
        xassert_array_eqq($opt->ids(), [1, 2, 3, 4, 5]);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/name" => "Program",
            "sf/1/id" => "2",
            "sf/1/order" => 100,
            "sf/1/values_text" => "Honors\nMBB\n Joint primary \n\n\n",
            "sf/1/type" => "radio"
        ]);
        xassert($sv->execute());
        $opt = $sv->conf->option_by_id(2);
        assert($opt instanceof Selector_PaperOption);
        xassert_eqq($opt->name, "Program");
        xassert_array_eqq($opt->values(), ["Honors", "MBB", "Joint primary"]);
        xassert_array_eqq($opt->ids(), [1, 2, 3]);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/name" => "Program",
            "sf/1/id" => "new",
            "sf/1/order" => 102,
            "sf/1/values_text" => "A\nB",
            "sf/1/type" => "radio"
        ]);
        xassert(!$sv->execute());
        xassert_str_contains($sv->full_feedback_text(), "is not unique");

        // test option values and renumbering
        $sv->conf->qe("delete from PaperOption where optionId=2");
        $sv->conf->qe("insert into PaperOption (paperId, optionId, value, data) values (1, 2, 1, null)");
        xassert_eqq($sv->conf->find_all_fields("Program"), [$sv->conf->option_by_id(2)]);
        xassert_eqq(search_text_col($this->u_chair, "1", "Program"), "1 Honors\n");

        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/name" => "Program",
            "sf/1/id" => "2",
            "sf/1/values_text" => "MBB\n Joint primary? \n  Honors\n\n"
        ]);
        xassert($sv->execute());
        $opt = $sv->conf->option_by_id(2);
        assert($opt instanceof Selector_PaperOption);
        xassert_array_eqq($opt->values(), ["MBB", "Joint primary?", "Honors"]);
        xassert_array_eqq($opt->ids(), [2, 3, 1]);

        xassert_eqq(search_text_col($this->u_chair, "1", "Program"), "1 Honors\n");
        xassert_eqq($sv->conf->fetch_ivalue("select value from PaperOption where paperId=1 and optionId=2"), 3);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/name" => "Program",
            "sf/1/id" => "2",
            "has_sf/1/values" => 1,
            "sf/1/values/1/id" => "2",
            "sf/1/values/1/name" => "Fart primary",
            "sf/1/values/1/order" => "3",
            "sf/1/values/2/id" => "3",
            "sf/1/values/2/name" => "MBB",
            "sf/1/values/2/order" => "2",
            "sf/1/values/3/id" => "1",
            "sf/1/values/3/name" => "Honors",
            "sf/1/values/3/order" => "1"
        ]);
        xassert($sv->execute());
        $opt = $sv->conf->option_by_id(2);
        assert($opt instanceof Selector_PaperOption);
        xassert_array_eqq($opt->values(), ["Honors", "MBB", "Fart primary"]);
        xassert_array_eqq($opt->ids(), [1, 3, 2]);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/id" => "2",
            "sf/1/values_text" => "\n\n",
        ]);
        xassert(!$sv->execute());
        xassert_str_contains($sv->full_feedback_text(), "Entry required");

        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/name" => "Program",
            "sf/1/id" => "2",
            "sf/1/delete" => "1"
        ]);
        xassert($sv->execute());
        $opt = $sv->conf->option_by_id(2);
        xassert_eqq($opt, null);

        xassert_eqq($sv->conf->fetch_ivalue("select value from PaperOption where optionId=2 limit 1"), null);
    }

    function test_subform_condition() {
        TestRunner::reset_options();

        // recursive condition not allowed
        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/name" => "Program",
            "sf/1/id" => "new",
            "sf/1/order" => 100,
            "sf/1/values_text" => "Honors\nMBB\nJoint primary\nJoint affiliated\nBasic",
            "sf/1/type" => "radio",
            "sf/1/presence" => "custom",
            "sf/1/condition" => "Program:Honors"
        ]);
        xassert(!$sv->execute());
        xassert_str_contains(strtolower($sv->full_feedback_text()), "field condition");

        // newly-added field conditions can refer to other newly-added fields
        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/name" => "Program",
            "sf/1/id" => "new",
            "sf/1/order" => 100,
            "sf/1/values_text" => "Honors\nMBB\nJoint primary\nJoint affiliated\nBasic",
            "sf/1/type" => "radio",
            "sf/2/name" => "Joint concentration",
            "sf/2/id" => "new",
            "sf/2/order" => 101,
            "sf/2/type" => "text",
            "sf/2/presence" => "custom",
            "sf/2/condition" => "Program:Joint*"
        ]);
        xassert($sv->execute());
        xassert_eqq(trim($sv->full_feedback_text()), "");

        $opts = $this->conf->find_all_fields("Joint concentration");
        xassert_eqq(count($opts), 1);
        $opt = $opts[0];
        $optid = $opt->id;
        xassert_eqq($opt->exists_condition(), "Program:Joint*");

        // conditions are preserved
        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/id" => $optid,
            "sf/1/name" => "Joint concentration?"
        ]);
        xassert($sv->execute());

        $opt = $this->conf->checked_option_by_id($optid);
        xassert_eqq($opt->exists_condition(), "Program:Joint*");
        xassert_eqq($opt->name, "Joint concentration?");

        // `final` presence obeyed
        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/name" => "Joint concentration?",
            "sf/1/condition" => "phase:final"
        ]);
        xassert($sv->execute());
        xassert_eqq(trim($sv->full_feedback_text()), "");

        $opt = $this->conf->checked_option_by_id($optid);
        xassert_eqq($opt->is_final(), true);
    }

    function test_json_settings_api() {
        $x = call_api("settings", $this->u_chair, []);
        xassert($x->ok);
        xassert(!isset($x->changes));
        xassert(is_object($x->settings));
        xassert_eqq($x->settings->review_blind, "blind");

        $x = call_api("=settings", $this->u_chair, ["settings" => "{}"]);
        xassert($x->ok);
        xassert_eqq($x->message_list, []);
        xassert_eqq($x->changes, []);

        $x = call_api("=settings", $this->u_chair, ["settings" => "{\"notgood\":true}"]);
        xassert($x->ok);
        xassert_eqq(count($x->message_list), 1);
        $mi = $x->message_list[0];
        xassert_eqq($mi->status, 1);
        xassert_eqq($mi->field, "notgood");
        xassert_eqq($mi->message, "<0>Unknown setting");

        $x = call_api("=settings", $this->u_chair, ["settings" => "{\"response_active\":1}"]);
        xassert(!$x->ok);
        xassert_eqq(count($x->message_list), 1);
        $mi = $x->message_list[0];
        xassert_eqq($mi->status, 2);
        xassert_eqq($mi->field, "response_active");
        xassert_eqq($mi->message, "<0>Boolean required");

        $x = call_api("=settings", $this->u_chair, ["settings" => "{\"review_blind\":\"open\"}"]);
        xassert($x->ok);
        xassert_eqq($x->changes, ["rev_blind"]);
        xassert_eqq($x->settings->review_blind, "open");
        xassert_eqq($this->conf->fetch_ivalue("select value from Settings where name='rev_blind'"), 0);

        $x = call_api("=settings", $this->u_chair, ["settings" => "{\"review_blind\":\"blind\"}"]);
        xassert($x->ok);
        xassert_eqq($x->changes, ["rev_blind"]);
        xassert_eqq($x->settings->review_blind, "blind");
        xassert_eqq($this->conf->fetch_ivalue("select value from Settings where name='rev_blind'"), null);

        $x = call_api("settings", $this->u_mgbaker, []);
        xassert(!$x->ok);
        xassert(!isset($x->settings));
    }

    function test_terms_exist() {
        xassert_eqq($this->conf->opt("clickthrough_submit"), null);
        xassert_eqq($this->conf->_i("clickthrough_submit"), null);

        $sv = SettingValues::make_request($this->u_chair, [
            "submission_terms" => ""
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), []);
        xassert_eqq($this->conf->opt("clickthrough_submit"), null);
        xassert_eqq($this->conf->_i("clickthrough_submit"), null);

        $sv = SettingValues::make_request($this->u_chair, [
            "submission_terms" => "xxx"
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), ["opt.clickthrough_submit", "msg.clickthrough_submit"]);
        xassert_neqq($this->conf->opt("clickthrough_submit"), null);
        xassert_eqq($this->conf->_i("clickthrough_submit"), "xxx");

        $sv = SettingValues::make_request($this->u_chair, [
            "submission_terms" => "xxx"
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), []);
        xassert_neqq($this->conf->opt("clickthrough_submit"), null);
        xassert_eqq($this->conf->_i("clickthrough_submit"), "xxx");

        $sv = SettingValues::make_request($this->u_chair, [
            "submission_terms" => ""
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), ["opt.clickthrough_submit", "msg.clickthrough_submit"]);
        xassert_eqq($this->conf->opt("clickthrough_submit"), null);
        xassert_eqq($this->conf->_i("clickthrough_submit"), null);
    }

    static function unexpected_unified_diff($x, $y) {
        $dmp = new dmp\diff_match_patch;
        $diff = $dmp->line_diff($x, $y);
        $udiff = $dmp->line_diff_toUnified($diff, 10, 50);
        fwrite(STDERR, $udiff);
        xassert_eqq($udiff, "", caller_landmark());
    }

    function test_json_settings_roundtrip() {
        $rf1 = $this->conf->find_review_field("NudFee");
        xassert_eqq($rf1->required, false);

        $x = call_api("settings", $this->u_chair, []);
        xassert($x->ok);
        xassert(!isset($x->changes));
        xassert(is_object($x->settings));
        xassert_eqq($x->settings->review_blind, "blind");
        xassert_eqq($x->settings->rf[5]->required, false);

        $sa = json_encode_browser($x->settings, JSON_PRETTY_PRINT);

        $x = call_api("=settings", $this->u_chair, ["settings" => $sa]);
        xassert($x->ok);
        xassert_eqq($x->message_list, []);
        xassert_eqq($x->changes, []);
        xassert_eqq($this->conf->setting_data("ioptions"), null);
        xassert_eqq($this->conf->fetch_ivalue("select value from Settings where name='rev_blind'"), null);

        $sb = json_encode_browser($x->settings, JSON_PRETTY_PRINT);
        if ($sa !== $sb) {
            self::unexpected_unified_diff($sa, $sb);
        }

        $x = call_api("=settings", $this->u_chair, ["settings" => $sb]);
        xassert($x->ok);
        xassert_eqq($x->message_list, []);
        xassert_eqq($x->changes, []);
        xassert_eqq($this->conf->fetch_ivalue("select value from Settings where name='rev_blind'"), null);

        $sc = json_encode_browser($x->settings, JSON_PRETTY_PRINT);
        if ($sb !== $sc) {
            self::unexpected_unified_diff($sb, $sc);
        }

        $x->settings->reset = true;
        $x = call_api("=settings", $this->u_chair, ["settings" => json_encode_browser($x->settings)]);
        xassert($x->ok);
        xassert_eqq($x->message_list, []);
        xassert_eqq($x->changes, []);

        $sd = json_encode_browser($x->settings, JSON_PRETTY_PRINT);
        if ($sc !== $sd) {
            self::unexpected_unified_diff($sc, $sd);
        }
    }

    function test_json_settings_errors() {
        $x = call_api("=settings", $this->u_chair, ["dryrun" => 1, "settings" => "{\"review\":[\"a\"]}"]);
        xassert(!$x->ok);
        $mi = $x->message_list[0] ?? null;
        xassert_eqq($mi->status, 2);
        xassert_eqq($mi->pos1, 11);
        xassert_eqq($mi->pos2, 14);
    }

    function test_json_settings_silent_roundtrip() {
        $sv = new SettingValues($this->u_chair);
        $j1 = json_encode($sv->all_jsonv(["reset" => true]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string($j1, "<roundtrip>");
        $sv->parse();
        $j2 = json_encode($sv->all_jsonv(["reset" => true]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $j3 = json_encode($sv->all_jsonv(["new" => true, "reset" => true]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        xassert_eqq($j2, $j1);
        if ($j3 !== $j1) {
            self::unexpected_unified_diff($j1, $j3);
        }
    }

    function test_new_fixed_id() {
        $oids = array_keys($this->conf->options()->universal());
        sort($oids);
        xassert_eqq($oids, [1, 2, 3]);
        xassert_neqq($this->conf->option_by_id(1), null);
        xassert_neqq($this->conf->option_by_id(2), null);
        xassert_neqq($this->conf->option_by_id(3), null);
        xassert_eqq($this->conf->option_by_id(100), null);

        // can create new options with unknown IDs
        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"sf":[{"id":100,"name":"New fixed option","type":"text"}]}');
        xassert($sv->execute());
        xassert_eqq($sv->full_feedback_text(), "");

        $opt = $this->conf->option_by_id(100);
        xassert_neqq($opt, null);
        xassert_eqq($opt->name ?? null, "New fixed option");

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"sf":[{"id":100,"name":"New fixed option","delete":true},{"id":102,"name":"Whatever","delete":true}]}');
        xassert($sv->execute());
        xassert_eqq($sv->full_feedback_text(), "");

        // but cannot create new options with bad IDs
        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"sf":[{"id":-100,"name":"Bad fixed option"}]}');
        xassert(!$sv->execute());

        // and cannot create options that overlap fixed options
        $this->conf->set_opt("fixedOptions", '[{"id":102,"name":"Fixed version","type":"text","configurable":false}]');
        $this->conf->load_settings();
        $o102 = $this->conf->option_by_id(102);
        xassert_eqq($o102->name, "Fixed version");

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"sf":[{"id":102,"name":"Bad fixed option","type":"text"}]}');
        xassert(!$sv->execute());

        // reset options
        $this->conf->set_opt("fixedOptions", null);
        $this->conf->load_settings();
        $oids = array_keys($this->conf->options()->universal());
        sort($oids);
        xassert_eqq($oids, [1, 2, 3]);

        // a new option with a fixed ID doesn't collide with new options
        // without fixed IDs
        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"sf":[{"id":"new","name":"First New","type":"text"},{"id":5,"name":"Second New","type":"text"},{"id":"new","name":"Third New","type":"text"}]}');
        xassert($sv->execute());
        xassert_eqq($this->conf->options()->find("First New")->id, 4);
        xassert_eqq($this->conf->options()->find("Second New")->id, 5);
        xassert_eqq($this->conf->options()->find("Third New")->id, 6);

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"sf":[{"id":4,"delete":true},{"id":5,"delete":true},{"id":6,"delete":true}]}');
        xassert($sv->execute());
    }

    function test_title_properties() {
        xassert_eqq($this->conf->setting_data("ioptions"), null);

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"sf":[{"id":"title","name":"Not Title"}]}');
        xassert($sv->execute());
        xassert_eqq($sv->full_feedback_text(), "");

        $opt = $this->conf->option_by_id(PaperOption::TITLEID);
        xassert_eqq($opt->name, "Not Title");
        xassert_eqq($opt->required, PaperOption::REQ_REGISTER);

        // only possible to configure allowed properties
        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"sf":[{"id":"title","required":"no"}]}');
        xassert(!$sv->execute());
        xassert_str_contains($sv->full_feedback_text(), "cannot be configured");

        $opt = $this->conf->option_by_id(PaperOption::TITLEID);
        xassert_eqq($opt->name, "Not Title");
        xassert_eqq($opt->required, PaperOption::REQ_REGISTER);

        // saving same title
        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"sf":[{"id":"title","name":"Not Title"}]}');
        xassert($sv->execute());
        xassert_eqq($sv->full_feedback_text(), "");

        $opt = $this->conf->option_by_id(PaperOption::TITLEID);
        xassert_eqq($opt->name, "Not Title");
        xassert_eqq($opt->required, PaperOption::REQ_REGISTER);

        // returning to default title
        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"sf":[{"id":"title","name":"Title"}]}');
        xassert($sv->execute());
        xassert_eqq($sv->full_feedback_text(), "");

        $opt = $this->conf->option_by_id(PaperOption::TITLEID);
        xassert_eqq($opt->name, "Title");
        xassert_eqq($opt->required, PaperOption::REQ_REGISTER);
        xassert_eqq($this->conf->setting_data("ioptions"), null);
    }

    function test_cannot_delete_intrinsic() {
        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"sf":[{"id":"title","delete":true}]}');
        xassert(!$sv->execute());
        xassert_str_contains($sv->full_feedback_text(), "cannot be deleted");
    }

    function test_intrinsic_title_shift() {
        xassert_eqq($this->conf->setting("ioptions"), null);
        xassert_eqq($this->conf->opt("noAbstract"), null);

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"sf_abstract":"optional","sf":[{"id":"abstract","name":"Abstract"}]}');
        xassert($sv->execute());

        $opt = $this->conf->option_by_id(PaperOption::ABSTRACTID);
        xassert_eqq($opt->edit_title(), "Abstract (optional)");
        xassert_eqq($this->conf->opt("noAbstract"), 2);
        xassert_eqq($this->conf->setting("ioptions"), null);

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"sf_abstract":"required","sf":[{"id":"abstract","name":"Abstract (optional)"}]}');
        xassert($sv->execute());

        $opt = $this->conf->option_by_id(PaperOption::ABSTRACTID);
        xassert_eqq($opt->edit_title(), "Abstract");
        xassert_eqq($this->conf->opt("noAbstract"), null);
        xassert_eqq($this->conf->setting("ioptions"), null);
    }

    function test_intrinsic_vs_wizard_settings() {
        xassert_eqq($this->conf->setting("ioptions"), null);
        xassert_eqq($this->conf->opt("noAbstract"), null);

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"sf_abstract":"optional","sf":[{"id":1,"name":"Calories?"}]}');
        xassert($sv->execute());

        $opt = $this->conf->option_by_id(PaperOption::ABSTRACTID);
        xassert_eqq($opt->edit_title(), "Abstract (optional)");
        xassert_eqq($this->conf->opt("noAbstract"), 2);
        xassert_eqq($this->conf->setting("ioptions"), null);

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"sf_abstract":"required","sf":[{"id":"abstract","name":"Abstract (optional)"}]}');
        xassert($sv->execute());

        $opt = $this->conf->option_by_id(PaperOption::ABSTRACTID);
        xassert_eqq($opt->edit_title(), "Abstract");
        xassert_eqq($this->conf->opt("noAbstract"), null);
        xassert_eqq($this->conf->setting("ioptions"), null);

    }

    function test_site_contact() {
        $dsc = $this->conf->default_site_contact();
        $sc = $this->conf->site_contact();
        xassert_eqq($dsc->name(), "Jane Chair");
        xassert_eqq($dsc->email, "chair@_.com");
        xassert_eqq($sc->name(), "Eddie Kohler");
        xassert_eqq($sc->email, "ekohler@hotcrp.lcdf.org");
        xassert_eqq($this->conf->setting_data("opt.contactName"), null);
        xassert_eqq($this->conf->setting_data("opt.contactEmail"), null);

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"site_contact_name":"Jane Chair","site_contact_email":"chair@_.com"}');
        xassert($sv->execute());

        $dsc = $this->conf->default_site_contact();
        $sc = $this->conf->site_contact();
        xassert_eqq($dsc->name(), "Jane Chair");
        xassert_eqq($dsc->email, "chair@_.com");
        xassert_eqq($sc->name(), "Jane Chair");
        xassert_eqq($sc->email, "chair@_.com");
        xassert_eqq($this->conf->opt("contactEmail"), "");
        xassert_eqq($this->conf->setting_data("opt.contactEmail"), "");

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"site_contact_name":"Eddie Kohler","site_contact_email":"ekohler@hotcrp.lcdf.org"}');
        xassert($sv->execute());

        $dsc = $this->conf->default_site_contact();
        $sc = $this->conf->site_contact();
        xassert_eqq($dsc->name(), "Jane Chair");
        xassert_eqq($dsc->email, "chair@_.com");
        xassert_eqq($sc->name(), "Eddie Kohler");
        xassert_eqq($sc->email, "ekohler@hotcrp.lcdf.org");
        xassert_eqq($this->conf->setting_data("opt.contactName"), null);
        xassert_eqq($this->conf->setting_data("opt.contactEmail"), null);
        unset($this->conf->opt_override["contactName"]);
        unset($this->conf->opt_override["contactEmail"]);
    }

    function test_site_contact_empty_defaults() {
        $this->conf->set_opt("contactName", "Your Name");
        $this->conf->set_opt("contactEmail", "you@example.com");
        xassert(!array_key_exists("contactName", $this->conf->opt_override));
        xassert(!array_key_exists("contactEmail", $this->conf->opt_override));
        xassert_eqq($this->conf->setting("opt.contactName"), null);
        xassert_eqq($this->conf->setting("opt.contactEmail"), null);

        $this->conf->refresh_options();
        $dsc = $this->conf->default_site_contact();
        $sc = $this->conf->site_contact();
        xassert_eqq($dsc->name(), "Jane Chair");
        xassert_eqq($dsc->email, "chair@_.com");
        xassert_eqq($sc->name(), "Jane Chair");
        xassert_eqq($sc->email, "chair@_.com");
        xassert_eqq($this->conf->setting_data("opt.contactName"), null);
        xassert_eqq($this->conf->setting_data("opt.contactEmail"), null);

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"site_contact_name":"Jane Chair","site_contact_email":"chair@_.com"}');
        xassert($sv->execute());

        $dsc = $this->conf->default_site_contact();
        $sc = $this->conf->site_contact();
        xassert_eqq($dsc->name(), "Jane Chair");
        xassert_eqq($dsc->email, "chair@_.com");
        xassert_eqq($sc->name(), "Jane Chair");
        xassert_eqq($sc->email, "chair@_.com");
        xassert_eqq($this->conf->setting_data("opt.contactName"), null);
        xassert_eqq($this->conf->setting_data("opt.contactEmail"), null);

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"site_contact_name":"Eddie Kohler","site_contact_email":"ekohler@hotcrp.lcdf.org"}');
        xassert($sv->execute());

        $dsc = $this->conf->default_site_contact();
        $sc = $this->conf->site_contact();
        xassert_eqq($dsc->name(), "Jane Chair");
        xassert_eqq($dsc->email, "chair@_.com");
        xassert_eqq($sc->name(), "Eddie Kohler");
        xassert_eqq($sc->email, "ekohler@hotcrp.lcdf.org");
        xassert_eqq($this->conf->setting_data("opt.contactName"), "Eddie Kohler");
        xassert_eqq($this->conf->setting_data("opt.contactEmail"), "ekohler@hotcrp.lcdf.org");

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"site_contact_name":"","site_contact_email":""}');
        xassert($sv->execute());

        $dsc = $this->conf->default_site_contact();
        $sc = $this->conf->site_contact();
        xassert_eqq($dsc->name(), "Jane Chair");
        xassert_eqq($dsc->email, "chair@_.com");
        xassert_eqq($sc->name(), "Jane Chair");
        xassert_eqq($sc->email, "chair@_.com");
        xassert_eqq($this->conf->setting_data("opt.contactName"), null);
        xassert_eqq($this->conf->setting_data("opt.contactEmail"), null);

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"site_contact_name":"Your Name","site_contact_email":"you@example.com"}');
        xassert($sv->execute());

        $dsc = $this->conf->default_site_contact();
        $sc = $this->conf->site_contact();
        xassert_eqq($dsc->name(), "Jane Chair");
        xassert_eqq($dsc->email, "chair@_.com");
        xassert_eqq($sc->name(), "Jane Chair");
        xassert_eqq($sc->email, "chair@_.com");
        xassert_eqq($this->conf->setting_data("opt.contactName"), null);
        xassert_eqq($this->conf->setting_data("opt.contactEmail"), null);

        $this->conf->set_opt("contactName", "Eddie Kohler");
        $this->conf->set_opt("contactEmail", "ekohler@hotcrp.lcdf.org");
        $this->conf->save_setting("opt.contactName", null);
        $this->conf->save_refresh_setting("opt.contactEmail", null);
        unset($this->conf->opt_override["contactName"]);
        unset($this->conf->opt_override["contactEmail"]);
    }

    function test_default_review_round() {
        xassert_eqq($this->conf->assignment_round_option(false), "Fart");
        xassert_eqq($this->conf->assignment_round_option(true), "Fart");

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"review":[{"name":"Butt","delete":true},{"name":"Fart","delete":true},{"name":"R1"},{"name":"R2"}]}');
        xassert($sv->execute());

        xassert_eqq($this->conf->setting_data("tag_rounds"), "; ; R1 R2");
        xassert_eqq($this->conf->assignment_round_option(false), "R1");
        xassert_eqq($this->conf->assignment_round_option(true), "R1");

        $sv = new SettingValues($this->u_chair);
        $j = $sv->all_jsonv();
        xassert_eqq($j->review[0]->name, "R1");
        xassert_eqq($j->review[0]->id, 4);
        xassert_eqq($this->conf->round_name(3), "R1");

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"review":[{"id":4,"name":"RR1"}]}');
        xassert($sv->execute());

        xassert_eqq($this->conf->setting_data("tag_rounds"), "; ; RR1 R2");
        xassert_eqq($this->conf->assignment_round_option(false), "RR1");
        xassert_eqq($this->conf->assignment_round_option(true), "RR1");

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"review":[{"id":4,"name":"R1"}],"review_default_round":"RR1"}');
        xassert(!$sv->execute());

        xassert_eqq($this->conf->round_name(3), "RR1");
        xassert_eqq($this->conf->assignment_round_option(false), "RR1");
        xassert_eqq($this->conf->assignment_round_option(true), "RR1");

        $sv = new SettingValues($this->u_chair);
        $sv->add_json_string('{"review":[{"id":4,"name":"R1"}],"review_default_round":"R1"}');
        xassert($sv->execute());

        xassert_eqq($this->conf->round_name(3), "R1");
        xassert_eqq($this->conf->assignment_round_option(false), "R1");
        xassert_eqq($this->conf->assignment_round_option(true), "R1");
    }
}
