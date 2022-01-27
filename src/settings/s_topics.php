<?php
// src/settings/s_topics.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Topics_SettingRenderer {
    static function render(SettingValues $sv) {
        // Topics
        // load topic interests
        $result = $sv->conf->q_raw("select topicId, interest from TopicInterest where interest!=0");
        $interests = [];
        while (($row = $result->fetch_row())) {
            if (!isset($interests[$row[0]])) {
                $interests[$row[0]] = [0, 0];
            }
            $interests[$row[0]][$row[1] > 0] += 1;
        }
        Dbl::free($result);

        echo "<p>Authors select the topics that apply to their submissions. PC members can indicate topics they’re interested in or search using the “topic:” keyword. Use a colon to create topic groups, as in “Systems: Correctness” and “Systems: Performance”.";
        if ($sv->conf->has_topics()) {
            echo " To delete an existing topic, remove its name.";
        }
        echo "</p>\n", Ht::hidden("has_topics", 1);


        if ($sv->conf->has_topics()) {
            echo '<div class="mg has-copy-topics"><table><thead><tr><th style="text-align:left">';
            if (!empty($interests)) {
                echo '<span class="float-right n"># PC interests: </span>';
            }
            echo '<strong>Current topics</strong></th>';
            if (!empty($interests)) {
                echo '<th class="padls">Low</th><th class="padls">High</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($sv->conf->topic_set() as $tid => $tname) {
                if ($sv->use_req() && $sv->has_req("top$tid")) {
                    $tname = $sv->reqstr("top$tid");
                }
                echo '<tr><td class="lentry">',
                    Ht::entry("top$tid", $tname, ["size" => 80, "class" => "need-autogrow wide" . ($sv->has_problem_at("top$tid") ? " has-error" : ""), "aria-label" => "Topic name"]),
                    '</td>';
                if (!empty($interests)) {
                    $tinterests = $interests[$tid] ?? [];
                    echo '<td class="fx plr padls">', ($tinterests[0] ?? null ? '<span class="topic-2">' . $tinterests[0] . "</span>" : ""), "</td>",
                        '<td class="fx plr padls">', ($tinterests[1] ?? null ? '<span class="topic2">' . $tinterests[1] . "</span>" : ""), "</td>";
                }
            }
            echo '</tbody></table>',
                Ht::link("Copy current topics to clipboard", "", ["class" => "ui js-settings-copy-topics"]),
                "</div>\n";
        }

        echo '<div class="mg"><label for="topnew"><strong>New topics</strong></label> (enter one per line)<br>',
            Ht::textarea("topnew", $sv->use_req() ? $sv->reqstr("topnew") : "", array("cols" => 80, "rows" => 2, "class" => ($sv->has_problem_at("topnew") ? "has-error " : "") . "need-autogrow", "id" => "topnew")), "</div>";
    }
}

class Topics_SettingParser extends SettingParser {
    private function check_topic($t) {
        $t = simplify_whitespace($t);
        if (!preg_match('/\A(?:\d+\z|[-+,;:]|–|—)/', $t)) {
            return $t;
        } else {
            return false;
        }
    }

    /** @return list<object> */
    function unparse_current_json(Conf $conf) {
        $j = [];
        foreach ($conf->topic_set() as $tid => $tname) {
            $j[] = (object) ["id" => $tid, "name" => $tname];
        }
        return $j;
    }

    function set_oldv(SettingValues $sv, Si $si) {
        $sv->set_oldv("topics", json_encode_db($this->unparse_current_json($sv->conf)));
    }

    function apply_req(SettingValues $sv, Si $si) {
        $j = json_decode($sv->oldv("topics")) ?? [];
        for ($i = 0; $i !== count($j); ) {
            $tid = $j[$i]->id;
            if (($x = $sv->reqstr("top$tid")) !== null) {
                $t = $this->check_topic($x);
                if ($t === false) {
                    $sv->error_at("top$tid", "<0>Topic name “{$x}” is reserved. Please choose another name.");
                    ++$i;
                } else if ($t === "") {
                    array_splice($j, $i, 1);
                } else {
                    $j[$i]->name = $t;
                    ++$i;
                }
            } else {
                ++$i;
            }
        }
        if ($sv->has_req("topnew")) {
            foreach (explode("\n", $sv->reqstr("topnew")) as $x) {
                $t = $this->check_topic($x);
                if ($t === false) {
                    $sv->error_at("topnew", "<0>Topic name “" . trim($x) . "” is reserved. Please choose another name.");
                } else if ($t !== "") {
                    $j[] = (object) ["name" => $t];
                }
            }
        }
        if (!$sv->has_error()
            && $sv->update("topics", json_encode_db($j))) {
            $sv->save("has_topics", !empty($j));
            $sv->request_write_lock("TopicArea", "PaperTopic", "TopicInterest");
            $sv->request_store_value($si);
        }
        return true;
    }

    function store_value(SettingValues $sv, Si $si) {
        $oldm = $sv->conf->topic_set()->as_array();
        $newj = json_decode($sv->newv("topics"));
        $newt1 = $newt2 = $delt = $changet = [];
        foreach ($newj as $t) {
            if (!isset($t->id) || $t->id === "new") {
                $newt1[] = [$t->name];
            } else if (!isset($oldm[$t->id])) {
                $newt2[] = [$t->id, $t->name];
            } else {
                if ($oldm[$t->id] !== $t->name) {
                    $changet[] = $t;
                }
                unset($oldm[$t->id]);
            }
        }
        if (!empty($newt1)) {
            $sv->conf->qe("insert into TopicArea (topicName) values ?v", $newt1);
        }
        if (!empty($newt2)) {
            $sv->conf->qe("insert into TopicArea (topicId,topicName) values ?v", $newt1);
        }
        if (!empty($oldm)) {
            $sv->conf->qe("delete from TopicArea where topicId?a", array_keys($oldm));
            $sv->conf->qe("delete from PaperTopic where topicId?a", array_keys($oldm));
            $sv->conf->qe("delete from TopicInterest where topicId?a", array_keys($oldm));
        }
        if (!empty($changet)) {
            foreach ($changet as $t) {
                $sv->conf->qe("update TopicArea set topicName=? where topicId=?", $t->name, $t->id);
            }
        }
        if (!empty($newt1) || !empty($newt1) || !empty($oldm) || !empty($changet)) {
            $has_topics = $sv->conf->fetch_ivalue("select exists (select * from TopicArea)");
            $sv->save("has_topics", !!$has_topics);
            $sv->mark_diff("topics");
            $sv->mark_invalidate_caches(["autosearch" => true]);
        }
    }
}
