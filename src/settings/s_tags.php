<?php
// src/settings/s_tags.php -- HotCRP settings > tags page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Tags_SettingRenderer {
    static function render_tags($tl) {
        $tl = array_filter($tl, function ($t) {
            return !$t->pattern_instance;
        });
        return join(" ", array_map(function ($t) { return $t->tag; }, $tl));
    }
    static function render(SettingValues $sv) {
        // Tags
        $tagmap = $sv->conf->tags();
        echo "<h3 class=\"settings\">Tags</h3>\n";
        echo "<table><tbody class=\"secondary-settings\">";
        $sv->set_oldv("tag_chair", self::render_tags($tagmap->filter("chair")));
        $sv->echo_entry_row("tag_chair", "Chair-only tags", "PC members can view these tags, but only administrators can change them.", ["class" => "need-tagcompletion"]);

        $sv->set_oldv("tag_sitewide", self::render_tags($tagmap->filter("sitewide")));
        if ($sv->newv("tag_sitewide") || $sv->conf->has_any_manager())
            $sv->echo_entry_row("tag_sitewide", "Site-wide tags", "Administrators can view and change these tags for every paper.", ["class" => "need-tagcompletion"]);

        $sv->set_oldv("tag_approval", self::render_tags($tagmap->filter("approval")));
        $sv->echo_entry_row("tag_approval", "Approval voting tags", "<a href=\"" . hoturl("help", "t=votetags") . "\">What is this?</a>", ["class" => "need-tagcompletion"]);

        $x = [];
        foreach ($tagmap->filter("vote") as $t)
            $x[] = "{$t->tag}#{$t->vote}";
        $sv->set_oldv("tag_vote", join(" ", $x));
        $sv->echo_entry_row("tag_vote", "Allotment voting tags", "“vote#10” declares an allotment of 10 votes per PC member. <span class=\"barsep\">·</span> <a href=\"" . hoturl("help", "t=votetags") . "\">What is this?</a>", ["class" => "need-tagcompletion"]);

        $sv->set_oldv("tag_rank", $sv->conf->setting_data("tag_rank", ""));
        $sv->echo_entry_row("tag_rank", "Ranking tag", "The <a href='" . hoturl("offline") . "'>offline reviewing page</a> will expose support for uploading rankings by this tag. <span class='barsep'>·</span> <a href='" . hoturl("help", "t=ranking") . "'>What is this?</a>");
        echo "</tbody></table>";

        echo "<div class='g'></div>\n";
        $sv->echo_checkbox('tag_seeall', "PC can see tags for conflicted papers");

        Ht::stash_script('suggest($(".need-tagcompletion"), taghelp_tset)', "taghelp_tset");
    }
    static function render_styles(SettingValues $sv) {
        $tag_color_data = $sv->conf->setting_data("tag_color", "");
        $tag_colors_rows = array();
        foreach (explode("|", TagInfo::BASIC_COLORS) as $k) {
            preg_match_all("{\\b(\\S+)=$k\\b}", $tag_color_data, $m);
            $sv->set_oldv("tag_color_$k", join(" ", get($m, 1, [])));
            $tag_colors_rows[] = "<tr class=\"{$k}tag\"><td class=\"lxcaption\"></td>"
                . "<td class=\"lxcaption taghl\">$k</td>"
                . "<td class=\"lentry\" style=\"font-size:1rem\">" . $sv->render_entry("tag_color_$k", ["class" => "need-tagcompletion"]) . "</td></tr>";
        }

        $tag_badge_data = $sv->conf->setting_data("tag_badge", "");
        foreach (["normal" => "black badge", "red" => "red badge",
                  "yellow" => "yellow badge", "green" => "green badge",
                  "blue" => "blue badge", "white" => "white badge",
                  "pink" => "pink badge", "gray" => "gray badge"]
                 as $k => $desc) {
            preg_match_all("{\\b(\\S+)=$k\\b}", $tag_badge_data, $m);
            $sv->set_oldv("tag_badge_$k", join(" ", get($m, 1, [])));
            $tag_colors_rows[] = "<tr><td class=\"lxcaption\"></td>"
                . "<td class=\"lxcaption\"><span class=\"badge {$k}badge\" style=\"margin:0\">$desc</span></td>"
                . "<td class=\"lentry\" style=\"font-size:1rem\">" . $sv->render_entry("tag_badge_$k", ["class" => "need-tagcompletion"]) . "</td></tr>";
        }

        echo Ht::hidden("has_tag_color", 1), Ht::hidden("has_tag_badge", 1),
            '<h3 class="settings g">Styles and colors</h3>',
            "<p class=\"settingtext\">Papers and PC members tagged with a style name, or with one of the associated tags, will appear in that style in lists.</p>",
            "<table id='foldtag_color'><tr><th colspan='2'>Style name</th><th>Tags</th></tr>",
            join("", $tag_colors_rows), "</table>\n";

        Ht::stash_script('suggest($(".need-tagcompletion"), taghelp_tset)', "taghelp_tset");
    }
}


class Tag_SettingParser extends SettingParser {
    private $tagger;
    public function __construct() {
        $this->tagger = new Tagger;
    }
    private function parse_list(SettingValues $sv, Si $si, $checkf, $min_idx) {
        $ts = array();
        foreach (preg_split('/\s+/', $sv->req[$si->name]) as $t)
            if ($t !== "" && ($tx = $this->tagger->check($t, $checkf))) {
                list($tag, $idx) = TagInfo::unpack($tx);
                if ($min_idx)
                    $tx = $tag . "#" . max($min_idx, (float) $idx);
                $ts[$tag] = $tx;
            } else if ($t !== "")
                $sv->error_at($si->name, $si->title . ": " . $this->tagger->error_html);
        return array_values($ts);
    }
    public function parse(SettingValues $sv, Si $si) {
        if ($si->name == "tag_chair" && isset($sv->req["tag_chair"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE | Tagger::ALLOWSTAR, false);
            $sv->update($si->name, join(" ", $ts));
        }

        if ($si->name == "tag_sitewide" && isset($sv->req["tag_sitewide"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE | Tagger::ALLOWSTAR, false);
            $sv->update($si->name, join(" ", $ts));
        }

        if ($si->name == "tag_vote" && isset($sv->req["tag_vote"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR, 1);
            if ($sv->update("tag_vote", join(" ", $ts)))
                $sv->need_lock["PaperTag"] = true;
        }

        if ($si->name == "tag_approval" && isset($sv->req["tag_approval"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false);
            if ($sv->update("tag_approval", join(" ", $ts)))
                $sv->need_lock["PaperTag"] = true;
        }

        if ($si->name == "tag_rank" && isset($sv->req["tag_rank"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false);
            if (count($ts) > 1)
                $sv->error_at("tag_rank", "At most one rank tag is currently supported.");
            else
                $sv->update("tag_rank", join(" ", $ts));
        }

        if ($si->name == "tag_color") {
            $ts = array();
            foreach (explode("|", TagInfo::BASIC_COLORS) as $k)
                if (isset($sv->req["tag_color_$k"])) {
                    foreach ($this->parse_list($sv, $sv->si("tag_color_$k"), Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE | Tagger::ALLOWSTAR, false) as $t)
                        $ts[] = $t . "=" . $k;
                }
            $sv->update("tag_color", join(" ", $ts));
        }

        if ($si->name == "tag_badge") {
            $ts = array();
            foreach (explode("|", TagInfo::BASIC_BADGES) as $k)
                if (isset($sv->req["tag_badge_$k"])) {
                    foreach ($this->parse_list($sv, $sv->si("tag_badge_$k"), Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE | Tagger::ALLOWSTAR, false) as $t)
                        $ts[] = $t . "=" . $k;
                }
            $sv->update("tag_badge", join(" ", $ts));
        }

        if ($si->name == "tag_au_seerev" && isset($sv->req["tag_au_seerev"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false);
            $sv->update("tag_au_seerev", join(" ", $ts));
        }

        return true;
    }

    public function save(SettingValues $sv, Si $si) {
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
                        $pvals[$row[0]] = defval($pvals, $row[0], 0) + $row[2];
                        $cvals[$who] = defval($cvals, $who, 0) + $row[2];
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
                        $pvals[$row[0]] = defval($pvals, $row[0], 0) + 1;
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
