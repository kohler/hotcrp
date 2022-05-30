<?php
// settings/s_topic.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Topic_Setting {
    /** @var ?int */
    public $id;
    /** @var string */
    public $name;

    function __construct($id = null, $name = "") {
        $this->id = $id;
        $this->name = $name;
    }
}

class Topic_SettingParser extends SettingParser {
    /** @var array<string> */
    private $topicj;
    /** @var list<string> */
    private $newtopics;

    function set_oldv(SettingValues $sv, Si $si) {
        if ($si->name === "new_topics") {
            $sv->set_oldv($si->name, "");
        } else if ($si->part0 === "topic/" && $si->part2 === "") {
            $idv = $sv->vstr("topic/{$si->part1}/id") ?? "";
            $id = ctype_digit($idv) ? intval($idv) : -1;
            if ($id > 0 && ($name = $sv->conf->topic_set()->name($id)) !== null) {
                $sv->set_oldv($si, new Topic_Setting($id, $name));
            } else {
                $sv->set_oldv($si, new Topic_Setting);
            }
        }
    }

    function prepare_enumeration(SettingValues $sv, Si $si) {
        $m = [];
        foreach ($sv->conf->topic_set() as $id => $name) {
            $m[$id] = new Topic_Setting($id, $name);
        }
        $sv->map_enumeration("topic/", $m);
    }

    static function print(SettingValues $sv) {
        // Topics
        // load topic interests
        $result = $sv->conf->q_raw("select topicId, interest from TopicInterest where interest!=0");
        $interests = [];
        while (($row = $result->fetch_row())) {
            $interests[$row[0]] = $interests[$row[0]] ?? [0, 0];
            $interests[$row[0]][$row[1] > 0 ? 1 : 0] += 1;
        }
        Dbl::free($result);

        echo "<p>Authors select the topics that apply to their submissions. PC members can indicate topics they’re interested in or search using the “topic:” keyword. Use a colon to create topic groups, as in “Systems: Correctness” and “Systems: Performance”.";
        if ($sv->conf->has_topics()) {
            echo " To delete an existing topic, remove its name.";
        }
        echo "</p>\n", Ht::hidden("has_topic", 1);

        if (($topic_counters = $sv->oblist_keys("topic/"))) {
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
                $tid = $sv->vstr("topic/{$ctr}/id") ?? "new";
                echo '<tr><td class="lentry">', Ht::hidden("topic/{$ctr}/id", $tid);
                $sv->print_feedback_at("topic/{$ctr}/name");
                $sv->print_entry("topic/{$ctr}/name", ["class" => "wide", "aria-label" => "Topic name"]);
                echo '</td>';
                if (!empty($interests)) {
                    $ti = $interests[$tid] ?? [null, null];
                    echo '<td class="fx plr padls">', ($ti[0] ? '<span class="topic-1">' . $ti[0] . "</span>" : ""), "</td>",
                        '<td class="fx plr padls">', ($ti[1] ? '<span class="topic1">' . $ti[1] . "</span>" : ""), "</td>";
                }
            }
            echo '</tbody></table>',
                Ht::link("Copy current topics to clipboard", "", ["class" => "ui js-settings-topics-copy"]),
                "</div>\n";
        }

        echo '<div class="mg"><label for="new_topics"><strong>New topics</strong></label> (enter one per line)<br>',
            $sv->feedback_at("new_topics"),
            Ht::textarea("new_topics", $sv->use_req() ? $sv->reqstr("new_topics") : "", ["cols" => 80, "rows" => 2, "class" => ($sv->has_problem_at("new_topics") ? "has-error " : "") . "need-autogrow", "id" => "new_topics"]), "</div>";
    }

    /** @return bool */
    private function _apply_req_newlist(SettingValues $sv, Si $si) {
        $ctr = null;
        foreach (explode("\n", $sv->reqstr($si->name)) as $line) {
            if (($line = simplify_whitespace($line)) !== "") {
                $ctr = $ctr ?? max(0, 0, ...$sv->oblist_keys("topic/")) + 1;
                $sv->set_req("topic/{$ctr}/id", "new");
                $sv->set_req("topic/{$ctr}/name", $line);
                ++$ctr;
            }
        }
        $sv->set_req("new_topics", "");
        return true;
    }

    /** @return bool */
    private function _apply_req_topics(SettingValues $sv, Si $si) {
        $this->topicj = $sv->conf->topic_set()->as_array();
        $this->newtopics = [];
        $oldj = json_encode_db($this->topicj);
        foreach ($sv->oblist_keys("topic/") as $ctr) {
            $tid = $sv->vstr("topic/{$ctr}/id") ?? "new";
            $tname = $sv->base_parse_req("topic/{$ctr}/name");
            if ($sv->reqstr("topic/{$ctr}/delete") || $tname === "") {
                if ($tid !== "new") {
                    unset($this->topicj[$tid]);
                }
            } else {
                if ($tname !== null) {
                    if (preg_match('/\A(?:\d+\z|[-+,;:]|–|—)/', $tname)) {
                        $sv->error_at("topic/{$ctr}/name", "<0>Topic name ‘{$tname}’ is reserved");
                    } else if (ctype_digit($tid)) {
                        $this->topicj[intval($tid)] = $tname;
                    } else {
                        $this->newtopics[] = $tname;
                    }
                }
                $sv->error_if_duplicate_member("topic/", $ctr, "/name", "Topic");
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
        if ($si->name === "new_topics") {
            return $this->_apply_req_newlist($sv, $si);
        } else if ($si->name === "topic") {
            return $this->_apply_req_topics($sv, $si);
        } else {
            return false;
        }
    }

    function store_value(SettingValues $sv, Si $si) {
        $oldm = $sv->conf->topic_set()->as_array();
        $newt2 = $changet = [];
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
