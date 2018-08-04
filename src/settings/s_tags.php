<?php
// src/settings/s_tags.php -- HotCRP settings > tags page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Tags_SettingRenderer {
    static function render_tags($tl) {
        $tl = array_filter($tl, function ($t) {
            return !$t->pattern_instance;
        });
        return join(" ", array_map(function ($t) { return $t->tag; }, $tl));
    }
    static function render_tag_chair(SettingValues $sv) {
        $sv->set_oldv("tag_chair", self::render_tags($sv->conf->tags()->filter("chair")));
        $sv->echo_entry_group("tag_chair", null, ["class" => "need-tagcompletion"], "PC members can see these tags, but only administrators can change them.");
    }
    static function render_tag_sitewide(SettingValues $sv) {
        $sv->set_oldv("tag_sitewide", self::render_tags($sv->conf->tags()->filter("sitewide")));
        if ($sv->newv("tag_sitewide") || $sv->conf->has_any_manager())
            $sv->echo_entry_group("tag_sitewide", null, ["class" => "need-tagcompletion"], "Administrators can see and change these tags for every paper.");
    }
    static function render_tag_approval(SettingValues $sv) {
        $sv->set_oldv("tag_approval", self::render_tags($sv->conf->tags()->filter("approval")));
        $sv->echo_entry_group("tag_approval", null, ["class" => "need-tagcompletion"], "<a href=\"" . hoturl("help", "t=votetags") . "\">Help</a>");
    }
    static function render_tag_vote(SettingValues $sv) {
        $x = [];
        foreach ($sv->conf->tags()->filter("vote") as $t)
            $x[] = "{$t->tag}#{$t->vote}";
        $sv->set_oldv("tag_vote", join(" ", $x));
        $sv->echo_entry_group("tag_vote", null, ["class" => "need-tagcompletion"], "“vote#10” declares an allotment of 10 votes per PC member. (<a href=\"" . hoturl("help", "t=votetags") . "\">Help</a>)");
    }
    static function render_tag_rank(SettingValues $sv) {
        $sv->set_oldv("tag_rank", $sv->conf->setting_data("tag_rank", ""));
        $sv->echo_entry_group("tag_rank", null, null, "The <a href='" . hoturl("offline") . "'>offline reviewing page</a> will expose support for uploading rankings by this tag. (<a href='" . hoturl("help", "t=ranking") . "'>Help</a>)");
    }
    static function render(SettingValues $sv) {
        // Tags
        $tagmap = $sv->conf->tags();
        echo "<h3 class=\"settings\">Tags</h3>\n";

        echo '<div class="settings-g">';
        $sv->render_group("tags/main");
        echo "</div>\n";

        echo '<div class="settings-g">';
        $sv->echo_checkbox('tag_seeall', "PC can see tags for conflicted papers");
        echo "</div>\n";

        Ht::stash_script('suggest($(".need-tagcompletion"), taghelp_tset)', "taghelp_tset");
    }
    static function render_styles(SettingValues $sv) {
        $skip_colors = [];
        if ($sv->conf->opt("tagNoSettingsColors"))
            $skip_colors = preg_split('/[\s|]+/', $sv->conf->opt("tagNoSettingsColors"));
        $tag_color_data = $sv->conf->setting_data("tag_color", "");
        $tag_colors_rows = array();
        foreach ($sv->conf->tags()->canonical_colors() as $k) {
            if (in_array($k, $skip_colors))
                continue;
            preg_match_all("{(?:\\A|\\s)(\\S+)=$k(?=\\s|\\z)}", $tag_color_data, $m);
            $sv->set_oldv("tag_color_$k", join(" ", get($m, 1, [])));
            $tag_colors_rows[] = "<tr class=\"{$k}tag\"><td class=\"remargin-left\"></td>"
                . "<td class=\"lxcaption taghl\">$k</td>"
                . "<td class=\"lentry\" style=\"font-size:1rem\">" . $sv->render_entry("tag_color_$k", ["class" => "need-tagcompletion"]) . "</td>"
                . "<td class=\"remargin-left\"></td></tr>";
        }

        echo Ht::hidden("has_tag_color", 1),
            '<h3 class="settings g">Colors and styles</h3>',
            "<p class=\"settingtext\">Papers tagged with a style name, or with an associated tag, appear in that style in lists. This also applies to PC tags.</p>",
            '<table id="foldtag_color" class="demargin"><tr><th></th><th class="settings-simplehead" style="min-width:8rem">Style name</th><th class="settings-simplehead">Tags</th><th></th></tr>',
            join("", $tag_colors_rows), "</table>\n";

        Ht::stash_script('suggest($(".need-tagcompletion"), taghelp_tset)', "taghelp_tset");
    }
}


class Tags_SettingParser extends SettingParser {
    private $sv;
    private $tagger;
    function __construct(SettingValues $sv) {
        $this->sv = $sv;
        $this->tagger = new Tagger($sv->user);
    }
    static function parse_list(Tagger $tagger, SettingValues $sv, Si $si,
                               $checkf, $min_idx) {
        $ts = array();
        foreach (preg_split('/\s+/', $sv->req[$si->name]) as $t)
            if ($t !== "" && ($tx = $tagger->check($t, $checkf))) {
                list($tag, $idx) = TagInfo::unpack($tx);
                if ($min_idx)
                    $tx = $tag . "#" . max($min_idx, (float) $idx);
                $ts[$tag] = $tx;
            } else if ($t !== "")
                $sv->error_at($si->name, $si->title . ": " . $tagger->error_html);
        return array_values($ts);
    }
    function my_parse_list(Si $si, $checkf, $min_idx) {
        return self::parse_list($this->tagger, $this->sv, $si, $checkf, $min_idx);
    }
    function parse(SettingValues $sv, Si $si) {
        assert($this->sv === $sv);

        if ($si->name == "tag_chair" && isset($sv->req["tag_chair"])) {
            $ts = $this->my_parse_list($si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE | Tagger::ALLOWSTAR, false);
            $sv->update($si->name, join(" ", $ts));
        }

        if ($si->name == "tag_sitewide" && isset($sv->req["tag_sitewide"])) {
            $ts = $this->my_parse_list($si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE | Tagger::ALLOWSTAR, false);
            $sv->update($si->name, join(" ", $ts));
        }

        if ($si->name == "tag_vote" && isset($sv->req["tag_vote"])) {
            $ts = $this->my_parse_list($si, Tagger::NOPRIVATE | Tagger::NOCHAIR, 1);
            if ($sv->update("tag_vote", join(" ", $ts)))
                $sv->need_lock["PaperTag"] = true;
        }

        if ($si->name == "tag_approval" && isset($sv->req["tag_approval"])) {
            $ts = $this->my_parse_list($si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false);
            if ($sv->update("tag_approval", join(" ", $ts)))
                $sv->need_lock["PaperTag"] = true;
        }

        if ($si->name == "tag_rank" && isset($sv->req["tag_rank"])) {
            $ts = $this->my_parse_list($si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false);
            if (count($ts) > 1)
                $sv->error_at("tag_rank", "Multiple ranking tags are not supported yet.");
            else
                $sv->update("tag_rank", join(" ", $ts));
        }

        if ($si->name == "tag_color") {
            $ts = array();
            foreach ($sv->conf->tags()->canonical_colors() as $k) {
                if (isset($sv->req["tag_color_$k"])) {
                    foreach ($this->my_parse_list($sv->si("tag_color_$k"), Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE | Tagger::ALLOWSTAR, false) as $t)
                        $ts[] = $t . "=" . $k;
                }
            }
            $sv->update("tag_color", join(" ", $ts));
        }

        if ($si->name == "tag_au_seerev" && isset($sv->req["tag_au_seerev"])) {
            $ts = $this->my_parse_list($si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false);
            $sv->update("tag_au_seerev", join(" ", $ts));
        }

        return true;
    }

    function save(SettingValues $sv, Si $si) {
        if ($si->name == "tag_vote" && $sv->has_savedv("tag_vote")) {
            // check allotments
            $pcm = $sv->conf->pc_members();
            foreach (preg_split('/\s+/', $sv->savedv("tag_vote")) as $t) {
                if ($t === "")
                    continue;
                $base = substr($t, 0, strpos($t, "#"));
                $allotment = substr($t, strlen($base) + 1);
                $sqlbase = sqlq_for_like($base);

                $result = $sv->conf->q("select paperId, tag, tagIndex from PaperTag where tag like '%~{$sqlbase}'");
                $pvals = array();
                $cvals = array();
                $negative = false;
                while (($row = edb_row($result))) {
                    $who = substr($row[1], 0, strpos($row[1], "~"));
                    if ($row[2] < 0) {
                        $sv->error_at(null, "Removed " . Text::user_html($pcm[$who]) . "’s negative “{$base}” vote for paper #$row[0].");
                        $negative = true;
                    } else {
                        $pvals[$row[0]] = get($pvals, $row[0], 0) + $row[2];
                        $cvals[$who] = get($cvals, $who, 0) + $row[2];
                    }
                }

                foreach ($cvals as $who => $what)
                    if ($what > $allotment)
                        $sv->error_at("tag_vote", Text::user_html($pcm[$who]) . " already has more than $allotment votes for tag “{$base}”.");

                $q = ($negative ? " or (tag like '%~{$sqlbase}' and tagIndex<0)" : "");
                $sv->conf->qe_raw("delete from PaperTag where tag='" . sqlq($base) . "'$q");

                $q = array();
                foreach ($pvals as $pid => $what)
                    $q[] = "($pid, '" . sqlq($base) . "', $what)";
                if (count($q) > 0)
                    $sv->conf->qe_raw("insert into PaperTag values " . join(", ", $q));
            }
        }

        if ($si->name == "tag_approval" && $sv->has_savedv("tag_approval")) {
            $pcm = $sv->conf->pc_members();
            foreach (preg_split('/\s+/', $sv->savedv("tag_approval")) as $t) {
                if ($t === "")
                    continue;
                $result = $sv->conf->q_raw("select paperId, tag, tagIndex from PaperTag where tag like '%~" . sqlq_for_like($t) . "'");
                $pvals = array();
                $negative = false;
                while (($row = edb_row($result))) {
                    $who = substr($row[1], 0, strpos($row[1], "~"));
                    if ($row[2] < 0) {
                        $sv->error_at(null, "Removed " . Text::user_html($pcm[$who]) . "’s negative “{$t}” approval vote for paper #$row[0].");
                        $negative = true;
                    } else
                        $pvals[$row[0]] = get($pvals, $row[0], 0) + 1;
                }

                $q = ($negative ? " or (tag like '%~" . sqlq_for_like($t) . "' and tagIndex<0)" : "");
                $sv->conf->qe_raw("delete from PaperTag where tag='" . sqlq($t) . "'$q");

                $q = array();
                foreach ($pvals as $pid => $what)
                    $q[] = "($pid, '" . sqlq($t) . "', $what)";
                if (count($q) > 0)
                    $sv->conf->qe_raw("insert into PaperTag values " . join(", ", $q));
            }
        }

        $sv->conf->invalidate_caches(["taginfo" => true]);
    }
}
