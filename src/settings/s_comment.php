<?php
// settings/s_comment.php -- HotCRP comment settings
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Comment_SettingParser extends SettingParser {
    private $saved_cmt_author = false;

    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name === "comment_author") {
            $sv->set_oldv($si->name, ($sv->conf->setting("cmt_author") ?? 0) > 0);
        } else if ($si->name === "comment_author_initiate") {
            $v = $sv->conf->setting("cmt_author") ?? 0;
            $sv->set_oldv($si->name, $v === -1 || $v === 2);
        }
    }

    static function print_author_exchange_comments(SettingValues $sv) {
        echo '<div class="has-fold fold', $sv->vstr("comment_author") ? "o" : "c", '">';
        if ((int) $sv->vstr("review_blind") === Conf::BLIND_NEVER) {
            $hint = "";
        } else {
            $hint = "Visible reviewer comments will be identified by “Reviewer A”, “Reviewer B”, etc.";
        }
        $sv->print_checkbox("comment_author", "Authors can <strong>exchange comments</strong> with reviewers", [
            "class" => "uich js-foldup",
            "hint_class" => "fx",
            "hint" => $hint
        ]);
        echo "<div class=\"fx mt-2\">";
        $sv->print_radio_table("comment_author_initiate", [1 => "Authors may initiate comment exchanges", 0 => "A reviewer must leave an author-visible comment first"]);
        echo "</div></div>\n";
    }

    function apply_req(Si $si, SettingValues $sv) {
        if (($si->name === "comment_author" || $si->name === "comment_author_initiate")
            && !$this->saved_cmt_author) {
            $cav = $sv->base_parse_req("comment_author");
            $caiv = $sv->base_parse_req("comment_author_initiate");
            if ($cav !== null && $caiv !== null) {
                $sv->save("cmt_author", $cav ? ($caiv ? 2 : 1) : ($caiv ? -1 : 0));
            }
            $this->saved_cmt_author = true;
            return true;
        } else {
            return false;
        }
    }
}
