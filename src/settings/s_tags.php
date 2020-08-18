<?php
// src/settings/s_tags.php -- HotCRP settings > tags page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Tags_SettingRenderer {
    static function render_tags($tl) {
        $tl = array_filter($tl, function ($t) {
            return !$t->pattern_instance;
        });
        return join(" ", array_map(function ($t) { return $t->tag; }, $tl));
    }
    static function render_tag_chair(SettingValues $sv) {
        // Remove `~~` tags from the set of defined chair-only tags. (They can
        // get on the list if they're defined in some other way.)
        $ts = array_filter($sv->conf->tags()->filter("chair"), function ($t) {
            return !str_starts_with($t->tag, "~~")
                && $t->tag !== "perm:*";
        });
        $sv->set_oldv("tag_chair", self::render_tags($ts));
        $sv->echo_entry_group("tag_chair", null, ["class" => "need-suggest tags"], "PC members can see these tags, but only administrators can change them.");
    }
    static function render_tag_sitewide(SettingValues $sv) {
        $sv->set_oldv("tag_sitewide", self::render_tags($sv->conf->tags()->filter("sitewide")));
        if ($sv->newv("tag_sitewide") || $sv->conf->has_any_manager()) {
            $sv->echo_entry_group("tag_sitewide", null, ["class" => "need-suggest tags"], "Administrators can see and change these tags for every submission.");
        }
    }
    static function render_tag_approval(SettingValues $sv) {
        $sv->set_oldv("tag_approval", self::render_tags($sv->conf->tags()->filter("approval")));
        $sv->echo_entry_group("tag_approval", null, ["class" => "need-suggest tags"], "<a href=\"" . $sv->conf->hoturl("help", "t=votetags") . "\">Help</a>");
    }
    static function render_tag_vote(SettingValues $sv) {
        $x = [];
        foreach ($sv->conf->tags()->filter("allotment") as $t) {
            $x[] = "{$t->tag}#{$t->allotment}";
        }
        $sv->set_oldv("tag_vote", join(" ", $x));
        $sv->echo_entry_group("tag_vote", null, ["class" => "need-suggest tags"], "“vote#10” declares an allotment of 10 votes per PC member. (<a href=\"" . $sv->conf->hoturl("help", "t=votetags") . "\">Help</a>)");
    }
    static function render_tag_rank(SettingValues $sv) {
        $sv->set_oldv("tag_rank", $sv->conf->setting_data("tag_rank") ?? "");
        $sv->echo_entry_group("tag_rank", null, null, 'The <a href="' . $sv->conf->hoturl("offline") . '">offline reviewing page</a> will expose support for uploading rankings by this tag. (<a href="' . $sv->conf->hoturl("help", "t=ranking") . '">Help</a>)');
    }
    static function render(SettingValues $sv) {
        // Tags
        $tagmap = $sv->conf->tags();
        echo "<h3 class=\"form-h\">Tags</h3>\n";

        echo '<div class="form-g">';
        $sv->render_group("tags/main");
        echo "</div>\n";

        echo '<div class="form-g">';
        $sv->render_group("tags/visibility");
        echo "</div>\n";
    }
    static function render_tag_seeall(SettingValues $sv) {
        echo '<div class="form-g-1">';
        $sv->echo_checkbox('tag_seeall', "PC can see tags for conflicted submissions");
        echo '</div>';
    }
    static function render_styles(SettingValues $sv) {
        $skip_colors = [];
        if ($sv->conf->opt("tagNoSettingsColors")) {
            $skip_colors = preg_split('/[\s|]+/', $sv->conf->opt("tagNoSettingsColors"));
        }
        $tag_color_data = $sv->conf->setting_data("tag_color") ?? "";
        $tag_colors_rows = array();
        foreach ($sv->conf->tags()->canonical_colors() as $k) {
            if (in_array($k, $skip_colors)) {
                continue;
            }
            preg_match_all("{(?:\\A|\\s)(\\S+)=$k(?=\\s|\\z)}", $tag_color_data, $m);
            $sv->set_oldv("tag_color_$k", join(" ", $m[1] ?? []));
            $tag_colors_rows[] = "<tr class=\"{$k}tag\"><td class=\"remargin-left\"></td>"
                . "<td class=\"pad taghl align-middle\">$k</td>"
                . "<td class=\"lentry\">"
                  . $sv->render_feedback_at("tag_color_$k", "mb-0")
                  . $sv->render_entry("tag_color_$k", ["class" => "need-suggest tags"])
                . "</td><td class=\"remargin-right\"></td></tr>";
        }

        echo Ht::hidden("has_tag_color", 1),
            '<h3 class="form-h" id="colors-and-styles">Colors and styles</h3>',
            "<p>Submissions tagged with a style name, or with an associated tag, appear in that style in lists. This also applies to PC tags.</p>",
            '<table class="demargin"><tr><th></th><th class="settings-simplehead" style="min-width:8rem">Style name</th><th class="settings-simplehead">Tags</th><th></th></tr>',
            join("", $tag_colors_rows), "</table>\n";
    }
}


class Tags_SettingParser extends SettingParser {
    private $sv;
    private $tagger;
    private $diffs = [];

    function __construct(SettingValues $sv) {
        $this->sv = $sv;
        $this->tagger = new Tagger($sv->user);
    }
    static function parse_list(Tagger $tagger, SettingValues $sv, Si $si,
                               $checkf, $min_idx) {
        $ts = array();
        foreach (preg_split('/\s+/', $sv->reqv($si->name)) as $t) {
            if ($t !== "" && ($tx = $tagger->check($t, $checkf | Tagger::CHECKVERBOSE))) {
                list($tag, $idx) = Tagger::unpack($tx);
                if ($min_idx) {
                    $tx = $tag . "#" . max($min_idx, (float) $idx);
                }
                $ts[$tag] = $tx;
            } else if ($t !== "") {
                $sv->error_at($si, $tagger->error_html);
            }
        }
        return array_values($ts);
    }
    function my_parse_list(Si $si, $checkf, $min_idx) {
        return self::parse_list($this->tagger, $this->sv, $si, $checkf, $min_idx);
    }
    function parse(SettingValues $sv, Si $si) {
        assert($this->sv === $sv);
        $change = false;

        if ($si->name == "tag_chair" && $sv->has_reqv("tag_chair")) {
            $ts = $this->my_parse_list($si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE | Tagger::ALLOWSTAR, false);
            $change = $sv->update($si->name, join(" ", $ts));
        }

        if ($si->name == "tag_sitewide" && $sv->has_reqv("tag_sitewide")) {
            $ts = $this->my_parse_list($si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE | Tagger::ALLOWSTAR, false);
            $change = $sv->update($si->name, join(" ", $ts));
        }

        if ($si->name == "tag_vote" && $sv->has_reqv("tag_vote")) {
            $ts = $this->my_parse_list($si, Tagger::NOPRIVATE | Tagger::NOCHAIR, 1);
            if (($change = $sv->update("tag_vote", join(" ", $ts)))) {
                $sv->request_write_lock("PaperTag");
            }
        }

        if ($si->name == "tag_approval" && $sv->has_reqv("tag_approval")) {
            $ts = $this->my_parse_list($si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false);
            if (($change = $sv->update("tag_approval", join(" ", $ts)))) {
                $sv->request_write_lock("PaperTag");
            }
        }

        if ($si->name == "tag_rank" && $sv->has_reqv("tag_rank")) {
            $ts = $this->my_parse_list($si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false);
            if (count($ts) > 1) {
                $sv->error_at("tag_rank", "Multiple ranking tags are not supported yet.");
            } else {
                $change = $sv->update("tag_rank", join(" ", $ts));
            }
        }

        if ($si->name == "tag_color") {
            $ts = array();
            foreach ($sv->conf->tags()->canonical_colors() as $k) {
                if ($sv->has_reqv("tag_color_$k")) {
                    foreach ($this->my_parse_list($sv->si("tag_color_$k"), Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE | Tagger::ALLOWSTAR, false) as $t)
                        $ts[] = $t . "=" . $k;
                }
            }
            $change = $sv->update("tag_color", join(" ", $ts));
        }

        if ($si->name == "tag_au_seerev" && $sv->has_reqv("tag_au_seerev")) {
            $ts = $this->my_parse_list($si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false);
            $change = $sv->update("tag_au_seerev", join(" ", $ts));
        }

        if ($change) {
            $this->diffs[$si->name] = true;
        }
        return true;
    }

    function save(SettingValues $sv, Si $si) {
        if (!isset($this->diffs[$si->name])) {
            return;
        }

        if ($si->name == "tag_vote" || $si->name === "tag_approval") {
            // check allotments
            $pcm = $sv->conf->pc_members();
            $removals = [];
            foreach (preg_split('/\s+/', $sv->savedv($si->name)) as $t) {
                list($tag, $index) = Tagger::unpack($t);
                if ($tag !== false) {
                    $removals[] = "right(tag," . (strlen($tag) + 1) . ")='~" . sqlq($tag) . "'";
                }
            }
            if (!empty($removals)) {
                $result = $sv->conf->qe_raw("delete from PaperTag where tagIndex<0 and left(tag,1)!='~' and (" . join(" or ", $removals) . ")");
                if ($result->affected_rows) {
                    $sv->warning_at($si->name, "Removed negative votes.");
                }
            }
            $sv->mark_invalidate_caches(["autosearch" => true]);
        }

        $sv->mark_invalidate_caches(["tags" => true]);
    }

    static function crosscheck(SettingValues $sv) {
        $vs = [];
        '@phan-var array<string,list<string>> $vs';
        $descriptions = [
            "tag_approval" => "approval voting",
            "tag_vote" => "allotment voting",
            "tag_rank" => "ranking"
        ];
        foreach (array_keys($descriptions) as $n) {
            foreach (Tagger::split_unpack($sv->newv($n)) as $ti) {
                $lx = &$vs[strtolower($ti[0])];
                $lx = $lx ?? [];
                $lx[] = $n;
                if (count($lx) === 2) {
                    $sv->warning_at($lx[0], "Tag “" . htmlspecialchars($ti[0]) . "” is also used for " . $descriptions[$n] . ".");
                }
                if (count($lx) > 1) {
                    $sv->warning_at($n, "Tag “" . htmlspecialchars($ti[0]) . "” is also used for " . $descriptions[$lx[0]] . ".");
                }
            }
        }
    }
}
