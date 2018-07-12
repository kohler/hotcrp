<?php
// src/settings/s_topics.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

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

        echo "<h3 class=\"settings g\">Topics</h3>\n";
        echo "<p class=\"settingtext\">Authors select the topics that apply to their submissions. PC members can indicate topics they’re interested in or search using the “topic:” keyword.";
        if ($sv->conf->topic_map())
            echo " To delete an existing topic, remove its name.";
        echo "</p>\n", Ht::hidden("has_topics", 1);


        if ($sv->conf->topic_map()) {
            echo '<div class="mg has-copy-topics"><table><thead><tr><th style="text-align:left">';
            if (!empty($interests))
                echo '<span class="floatright n"># PC interests: </span>';
            echo '<strong>Current topics</strong></th>';
            if (!empty($interests))
                echo '<th class="ccaption">Low</th><th class="ccaption">High</th>';
            echo '</tr></thead><tbody>';
            foreach ($sv->conf->topic_map() as $tid => $tname) {
                if ($sv->use_req() && isset($sv->req["top$tid"]))
                    $tname = $sv->req["top$tid"];
                echo '<tr><td class="lentry">',
                    Ht::entry("top$tid", $tname, ["size" => 80, "class" => "need-autogrow wide" . ($sv->has_problem_at("top$tid") ? " has-error" : "")]),
                    '</td>';
                if (!empty($interests)) {
                    $tinterests = get($interests, $tid, array());
                    echo '<td class="fx rpentry">', (get($tinterests, 0) ? '<span class="topic-2">' . $tinterests[0] . "</span>" : ""), "</td>",
                        '<td class="fx rpentry">', (get($tinterests, 1) ? '<span class="topic2">' . $tinterests[1] . "</span>" : ""), "</td>";
                }
            }
            echo '</tbody></table>',
                Ht::link("Copy current topics to clipboard", "", ["class" => "ui js-settings-copy-topics"]),
                "</div>\n";
        }

        echo '<div class="mg"><strong>New topics</strong> (enter one per line)<br>',
            Ht::textarea("topnew", $sv->use_req() ? get($sv->req, "topnew") : "", array("cols" => 80, "rows" => 2, "class" => ($sv->has_problem_at("topnew") ? "has-error " : "") . "need-autogrow")), "</div>";
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
        if (isset($sv->req["topnew"]))
            foreach (explode("\n", $sv->req["topnew"]) as $x) {
                $t = $this->check_topic($x);
                if ($t === false)
                    $sv->error_at("topnew", "Topic name “" . htmlspecialchars($x) . "” is reserved. Please choose another name.");
                else if ($t !== "")
                    $this->new_topics[] = [$t]; // NB array of arrays
            }
        $tmap = $sv->conf->topic_map();
        foreach ($sv->req as $k => $x)
            if (strlen($k) > 3 && substr($k, 0, 3) === "top"
                && ctype_digit(substr($k, 3))) {
                $tid = (int) substr($k, 3);
                $t = $this->check_topic($x);
                if ($t === false)
                    $sv->error_at($k, "Topic name “" . htmlspecialchars($x) . "” is reserved. Please choose another name.");
                else if ($t === "")
                    $this->deleted_topics[] = $tid;
                else if (isset($tmap[$tid]) && $tmap[$tid] !== $t)
                    $this->changed_topics[$tid] = $t;
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
