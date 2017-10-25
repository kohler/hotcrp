<?php
// src/settings/s_topics.php -- HotCRP settings > submission form page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Topics_SettingRenderer {
    static function render(SettingValues $sv) {
        // Topics
        // load topic interests
        $result = $sv->conf->q_raw("select topicId, if(interest>0,1,0), count(*) from TopicInterest where interest!=0 group by topicId, interest>0");
        $interests = array();
        $ninterests = 0;
        while (($row = edb_row($result))) {
            if (!isset($interests[$row[0]]))
                $interests[$row[0]] = array();
            $interests[$row[0]][$row[1]] = $row[2];
            $ninterests += ($row[2] ? 1 : 0);
        }

        echo "<h3 class=\"settings g\">Topics</h3>\n";
        echo "<p class=\"settingtext\">Enter topics one per line.  Authors select the topics that apply to their papers; PC members use this information to find papers they'll want to review.  To delete a topic, delete its name.</p>\n";
        echo Ht::hidden("has_topics", 1),
            "<table id='newtoptable' class='", ($ninterests ? "foldo" : "foldc"), "'>";
        echo "<tr><th colspan='2'></th><th class='fx'><small>Low</small></th><th class='fx'><small>High</small></th></tr>";
        $td1 = '<td class="lcaption">Current</td>';
        foreach ($sv->conf->topic_map() as $tid => $tname) {
            if ($sv->use_req() && isset($sv->req["top$tid"]))
                $tname = $sv->req["top$tid"];
            echo '<tr>', $td1, '<td class="lentry">',
                Ht::entry("top$tid", $tname, array("size" => 40, "style" => "width:20em", "class" => $sv->has_problem_at("top$tid") ? "setting_error" : null)),
                '</td>';

            $tinterests = defval($interests, $tid, array());
            echo '<td class="fx rpentry">', (get($tinterests, 0) ? '<span class="topic-2">' . $tinterests[0] . "</span>" : ""), "</td>",
                '<td class="fx rpentry">', (get($tinterests, 1) ? '<span class="topic2">' . $tinterests[1] . "</span>" : ""), "</td>";

            if ($td1 !== "<td></td>") {
                // example search
                echo "<td class='llentry' style='vertical-align:top' rowspan='40'><div class='f-i'>",
                    "<div class='f-c'>Example search</div>";
                $oabbrev = strtolower($tname);
                if (strstr($oabbrev, " ") !== false)
                    $oabbrev = "\"$oabbrev\"";
                echo "“<a href=\"", hoturl("search", "q=topic:" . urlencode($oabbrev)), "\">",
                    "topic:", htmlspecialchars($oabbrev), "</a>”",
                    "<div class='hint'>Topic abbreviations are also allowed.</div>";
                if ($ninterests)
                    echo "<a class='hint fn' href=\"#\" onclick=\"return fold('newtoptable')\">Show PC interest counts</a>",
                        "<a class='hint fx' href=\"#\" onclick=\"return fold('newtoptable')\">Hide PC interest counts</a>";
                echo "</div></td>";
            }
            echo "</tr>\n";
            $td1 = "<td></td>";
        }
        echo '<tr><td class="lcaption top">New<br><span class="hint">Enter one topic per line.</span></td><td class="lentry top">',
            Ht::textarea("topnew", $sv->use_req() ? get($sv->req, "topnew") : "", array("cols" => 40, "rows" => 2, "style" => "width:20em", "class" => ($sv->has_problem_at("topnew") ? "setting_error " : "") . "need-autogrow")),
            '</td></tr></table>';
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
