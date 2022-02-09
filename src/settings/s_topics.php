<?php
// settings/s_topics.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Topics_SettingParser extends SettingParser {
    /** @var array<string> */
    private $topicj;
    /** @var list<string> */
    private $newtopics;

    function set_oldv(SettingValues $sv, Si $si) {
        if ($si->name === "topic__newlist") {
            $sv->set_oldv($si->name, "");
        } else if ($si->part0 === "topic__") {
            $tn = $sv->unmap_enumeration_member($si->name, $sv->conf->topic_set()->as_array());
            $sv->set_oldv($si->name, (object) ["name" => $tn]);
        }
    }

    function prepare_enumeration(SettingValues $sv, Si $si) {
        $sv->map_enumeration("topic__", $sv->conf->topic_set()->as_array());
    }

    static function print(SettingValues $sv) {
        // Topics
        // load topic interests
        $result = $sv->conf->q_raw("select topicId, interest from TopicInterest where interest!=0");
        $interests = [];
        while (($row = $result->fetch_row())) {
            $interests[$row[0]] = $interests[$row[0]] ?? [0, 0];
            $interests[$row[0]][$row[1] > 0] += 1;
        }
        Dbl::free($result);

        echo "<p>Authors select the topics that apply to their submissions. PC members can indicate topics they’re interested in or search using the “topic:” keyword. Use a colon to create topic groups, as in “Systems: Correctness” and “Systems: Performance”.";
        if ($sv->conf->has_topics()) {
            echo " To delete an existing topic, remove its name.";
        }
        echo "</p>\n", Ht::hidden("has_topics", 1);

        if (($topic_counters = $sv->enumerate("topic__"))) {
            echo '<div class="mg has-copy-topics"><table><thead><tr><th style="text-align:left">';
            if (!empty($interests)) {
                echo '<span class="float-right n"># PC interests: </span>';
            }
            echo '<strong>Current topics</strong></th>';
            if (!empty($interests)) {
                echo '<th class="padls">Low</th><th class="padls">High</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($topic_counters as $ctr) {
                $tid = $sv->vstr("topic__{$ctr}__id") ?? "\$";
                echo '<tr><td class="lentry">', Ht::hidden("topic__{$ctr}__id", $tid);
                $sv->print_feedback_at("topic__{$ctr}__name");
                $sv->print_entry("topic__{$ctr}__name", ["class" => "wide", "aria-label" => "Topic name"]);
                echo '</td>';
                if (!empty($interests)) {
                    $ti = $interests[$tid] ?? [null, null];
                    echo '<td class="fx plr padls">', ($ti[0] ? '<span class="topic-2">' . $ti[0] . "</span>" : ""), "</td>",
                        '<td class="fx plr padls">', ($ti[1] ? '<span class="topic2">' . $ti[1] . "</span>" : ""), "</td>";
                }
            }
            echo '</tbody></table>',
                Ht::link("Copy current topics to clipboard", "", ["class" => "ui js-settings-topics-copy"]),
                "</div>\n";
        }

        echo '<div class="mg"><label for="topic__newlist"><strong>New topics</strong></label> (enter one per line)<br>',
            $sv->feedback_at("topic__newlist"),
            Ht::textarea("topic__newlist", $sv->use_req() ? $sv->reqstr("topic__newlist") : "", array("cols" => 80, "rows" => 2, "class" => ($sv->has_problem_at("topic__newlist") ? "has-error " : "") . "need-autogrow", "id" => "topic__newlist")), "</div>";
    }

    /** @return bool */
    private function _apply_req_newlist(SettingValues $sv, Si $si) {
        $ctr = null;
        foreach (explode("\n", $sv->reqstr($si->name)) as $line) {
            if (($line = simplify_whitespace($line)) !== "") {
                $ctr = $ctr ?? max(0, 0, ...$sv->enumerate("topic__")) + 1;
                $sv->set_req("topic__{$ctr}__id", "\$");
                $sv->set_req("topic__{$ctr}__name", $line);
                ++$ctr;
            }
        }
        $sv->set_req("topic__newlist", "");
        return true;
    }

    /** @return bool */
    private function _apply_req_topics(SettingValues $sv, Si $si) {
        $this->topicj = $sv->conf->topic_set()->as_array();
        $this->newtopics = [];
        $oldj = json_encode_db($this->topicj);
        foreach ($sv->enumerate("topic__") as $ctr) {
            $tid = $sv->vstr("topic__{$ctr}__id") ?? "\$";
            $tname = $sv->base_parse_req("topic__{$ctr}__name");
            if ($sv->reqstr("topic__{$ctr}__delete") || $tname === "") {
                if ($tid !== "\$") {
                    unset($this->topicj[$tid]);
                }
            } else {
                if ($tname !== null) {
                    if (preg_match('/\A(?:\d+\z|[-+,;:]|–|—)/', $tname)) {
                        $sv->error_at("topic__{$ctr}__name", "<0>Topic name ‘{$tname}’ is reserved");
                    } else if (ctype_digit($tid)) {
                        $this->topicj[intval($tid)] = $tname;
                    } else {
                        $this->newtopics[] = $tname;
                    }
                }
                $sv->error_if_duplicate_member("topic__", $ctr, "__name", "Topic");
            }
        }
        if (!$sv->has_error()
            && (json_encode_db($this->topicj) !== $oldj || !empty($this->newtopics))) {
            // this will be replaced in store_value, but useful for message context:
            $sv->save("has_topics", !empty($this->topicj) || !empty($this->newtopics));
            $sv->request_write_lock("TopicArea", "PaperTopic", "TopicInterest");
            $sv->request_store_value($si);
        }
        return true;
    }

    function apply_req(SettingValues $sv, Si $si) {
        if ($si->name === "topic__newlist") {
            return $this->_apply_req_newlist($sv, $si);
        } else if ($si->name === "topics") {
            return $this->_apply_req_topics($sv, $si);
        } else {
            return false;
        }
    }

    function store_value(SettingValues $sv, Si $si) {
        $oldm = $sv->conf->topic_set()->as_array();
        $newt2 = $delt = $changet = [];
        foreach ($this->topicj as $tid => $tname) {
            if (!isset($oldm[$tid])) {
                $newt2[] = [$tid, $tname];
            } else {
                if ($oldm[$tid] !== $tname) {
                    $changet[] = [$tid, $tname];
                }
                unset($oldm[$tid]);
            }
        }
        if (!empty($this->newtopics)) {
            $sv->conf->qe("insert into TopicArea (topicName) values ?v", $this->newtopics);
        }
        if (!empty($newt2)) {
            $sv->conf->qe("insert into TopicArea (topicId,topicName) values ?v", $newt2);
        }
        if (!empty($oldm)) {
            $sv->conf->qe("delete from TopicArea where topicId?a", array_keys($oldm));
            $sv->conf->qe("delete from PaperTopic where topicId?a", array_keys($oldm));
            $sv->conf->qe("delete from TopicInterest where topicId?a", array_keys($oldm));
        }
        if (!empty($changet)) {
            foreach ($changet as $tn) {
                $sv->conf->qe("update TopicArea set topicName=? where topicId=?", $tn[1], $tn[0]);
            }
        }
        $has_topics = $sv->conf->fetch_ivalue("select exists (select * from TopicArea)");
        $sv->save("has_topics", !!$has_topics);
        $sv->mark_diff("topics");
        $sv->mark_invalidate_caches(["autosearch" => true]);
    }
}
