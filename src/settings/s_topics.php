<?php
// src/settings/s_topics.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Topics_SettingRenderer {
    static function render(SettingValues $sv) {
        // Topics
        // load topic interests
        $result = $sv->conf->q_raw("select topicId, interest from TopicInterest where interest!=0");
        $interests = [];
        while (($row = edb_row($result))) {
            if (!isset($interests[$row[0]]))
                $interests[$row[0]] = [0, 0];
            $interests[$row[0]][$row[1] > 0] += 1;
        }
        Dbl::free($result);

        echo "<h3 class=\"form-h\" id=\"topics\">Topics</h3>\n";
        echo "<p>Authors select the topics that apply to their submissions. PC members can indicate topics they’re interested in or search using the “topic:” keyword. Use a colon to create topic groups, as in “Systems: Correctness” and “Systems: Performance”.";
        if ($sv->conf->has_topics())
            echo " To delete an existing topic, remove its name.";
        echo "</p>\n", Ht::hidden("has_topics", 1);


        if ($sv->conf->has_topics()) {
            echo '<div class="mg has-copy-topics"><table><thead><tr><th style="text-align:left">';
            if (!empty($interests))
                echo '<span class="float-right n"># PC interests: </span>';
            echo '<strong>Current topics</strong></th>';
            if (!empty($interests))
                echo '<th class="padls">Low</th><th class="padls">High</th>';
            echo '</tr></thead><tbody>';
            foreach ($sv->conf->topic_set() as $tid => $tname) {
                if ($sv->use_req() && $sv->has_reqv("top$tid"))
                    $tname = $sv->reqv("top$tid");
                echo '<tr><td class="lentry">',
                    Ht::entry("top$tid", $tname, ["size" => 80, "class" => "need-autogrow wide" . ($sv->has_problem_at("top$tid") ? " has-error" : ""), "aria-label" => "Topic name"]),
                    '</td>';
                if (!empty($interests)) {
                    $tinterests = get($interests, $tid, array());
                    echo '<td class="fx plr padls">', (get($tinterests, 0) ? '<span class="topic-2">' . $tinterests[0] . "</span>" : ""), "</td>",
                        '<td class="fx plr padls">', (get($tinterests, 1) ? '<span class="topic2">' . $tinterests[1] . "</span>" : ""), "</td>";
                }
            }
            echo '</tbody></table>',
                Ht::link("Copy current topics to clipboard", "", ["class" => "ui js-settings-copy-topics"]),
                "</div>\n";
        }

        echo '<div class="mg"><label for="topnew"><strong>New topics</strong></label> (enter one per line)<br>',
            Ht::textarea("topnew", $sv->use_req() ? $sv->reqv("topnew") : "", array("cols" => 80, "rows" => 2, "class" => ($sv->has_problem_at("topnew") ? "has-error " : "") . "need-autogrow", "id" => "topnew")), "</div>";
    }
}

class Topics_SettingParser extends SettingParser {
    private $new_topics;
    private $deleted_topics;
    private $changed_topics;

    private function check_topic($t) {
        $t = simplify_whitespace($t);
        if ($t === "" || !ctype_digit($t))
            return $t;
        else
            return false;
    }

    function parse(SettingValues $sv, Si $si) {
        if ($sv->has_reqv("topnew")) {
            foreach (explode("\n", $sv->reqv("topnew")) as $x) {
                $t = $this->check_topic($x);
                if ($t === false)
                    $sv->error_at("topnew", "Topic name “" . htmlspecialchars($x) . "” is reserved. Please choose another name.");
                else if ($t !== "")
                    $this->new_topics[] = [$t]; // NB array of arrays
            }
        }
        $tmap = $sv->conf->topic_set();
        foreach ($tmap as $tid => $tname) {
            if (($x = $sv->reqv("top$tid")) !== null) {
                $t = $this->check_topic($x);
                if ($t === false)
                    $sv->error_at($k, "Topic name “" . htmlspecialchars($x) . "” is reserved. Please choose another name.");
                else if ($t === "")
                    $this->deleted_topics[] = $tid;
                else if ($tname !== $t)
                    $this->changed_topics[$tid] = $t;
            }
        }
        if (!$sv->has_error()) {
            foreach (["TopicArea", "PaperTopic", "TopicInterest"] as $t)
                $sv->need_lock[$t] = true;
            return true;
        }
    }

    function save(SettingValues $sv, Si $si) {
        if ($this->new_topics)
            $sv->conf->qe("insert into TopicArea (topicName) values ?v", $this->new_topics);
        if ($this->deleted_topics) {
            $sv->conf->qe("delete from TopicArea where topicId?a", $this->deleted_topics);
            $sv->conf->qe("delete from PaperTopic where topicId?a", $this->deleted_topics);
            $sv->conf->qe("delete from TopicInterest where topicId?a", $this->deleted_topics);
        }
        if ($this->changed_topics) {
            foreach ($this->changed_topics as $tid => $t)
                $sv->conf->qe("update TopicArea set topicName=? where topicId=?", $t, $tid);
        }
        $sv->conf->invalidate_topics();
        $has_topics = $sv->conf->fetch_ivalue("select exists (select * from TopicArea)");
        $sv->save("has_topics", $has_topics ? 1 : null);
        if ($this->new_topics || $this->deleted_topics || $this->changed_topics)
            $sv->changes[] = "topics";
    }
}
