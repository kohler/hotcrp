<?php
// pages/p_help.php -- HotCRP help page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Help_Page {
    /** @param HelpRenderer $hth */
    static function show_help_topics($hth) {
        echo "<dl>\n";
        foreach ($hth->groups() as $ht) {
            if ($ht->name !== "topics" && isset($ht->title)) {
                echo '<dt><strong><a href="', $hth->conf->hoturl("help", "t=$ht->name"), '">', $ht->title, '</a></strong></dt>';
                if (isset($ht->description)) {
                    echo '<dd>', $ht->description ?? "", '</dd>';
                }
                echo "\n";
            }
        }
        echo "</dl>\n";
    }

    static function go(Contact $user, Qrequest $qreq) {
        $conf = $user->conf;

        $help_topics = new ComponentSet($user, [
            '{"name":"topics","title":"Help topics","order":-1000000,"priority":1000000,"print_function":"Help_Page::show_help_topics"}',
            "etc/helptopics.json"
        ], $conf->opt("helpTopics"));

        if (!$qreq->t && preg_match('/\A\/\w+\/*\z/i', $qreq->path())) {
            $qreq->t = $qreq->path_component(0);
        }
        $topic = $qreq->t ? : "topics";
        $want_topic = $help_topics->canonical_group($topic);
        if (!$want_topic) {
            $want_topic = "topics";
        }
        if ($want_topic !== $topic) {
            $conf->redirect_self($qreq, ["t" => $want_topic]);
        }
        $topicj = $help_topics->get($topic);

        $conf->header("Help", "help", ["title_div" => '<hr class="c">', "body_class" => "leftmenu"]);

        $hth = new HelpRenderer($help_topics, $user);

        echo '<div class="leftmenu-left"><nav class="leftmenu-menu"><h1 class="leftmenu">';
        if ($topic !== "topics") {
            echo '<a href="', $conf->hoturl("help"), '" class="q uic js-leftmenu">Help</a>';
        } else {
            echo "Help";
        }
        echo '</h1><ul class="leftmenu-list">';
        $gap = false;
        foreach ($help_topics->groups() as $gj) {
            if (isset($gj->title)) {
                echo '<li class="leftmenu-item',
                    ($gap ? " leftmenu-item-gap3" : ""),
                    ($gj->name === $topic ? ' active">' : ' ui js-click-child">');
                if ($gj->name === $topic) {
                    echo $gj->title;
                } else {
                    echo Ht::link($gj->title, $conf->hoturl("help", "t=$gj->name"));
                }
                echo '</li>';
                $gap = $gj->name === "topics";
            }
        }
        echo "</ul></nav></div>\n",
            '<main id="helpcontent" class="leftmenu-content main-column">',
            '<h2 class="leftmenu">', $topicj->title, '</h2>';
        $hth->print_group($topic, true);
        echo "</main>\n";

        $conf->footer();
    }
}
