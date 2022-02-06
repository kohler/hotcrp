<?php
// settings/s_tags.php -- HotCRP settings > tags page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Tags_SettingRenderer {
    static function render_tags($tl) {
        $tl = array_filter($tl, function ($t) {
            return !$t->pattern_instance;
        });
        return join(" ", array_map(function ($t) { return $t->tag; }, $tl));
    }
    static function print_tag_chair(SettingValues $sv) {
        $sv->print_entry_group("tag_chair", null, ["class" => "need-suggest tags"], "PC members can see these tags, but only administrators can change them.");
    }
    static function print_tag_sitewide(SettingValues $sv) {
        if ($sv->newv("tag_sitewide") || $sv->conf->has_any_manager()) {
            $sv->print_entry_group("tag_sitewide", null, ["class" => "need-suggest tags"], "Administrators can see and change these tags for every submission.");
        }
    }
    static function print_tag_approval(SettingValues $sv) {
        $sv->print_entry_group("tag_approval", null, ["class" => "need-suggest tags"], "<a href=\"" . $sv->conf->hoturl("help", "t=votetags") . "\">Help</a>");
    }
    static function print_tag_vote(SettingValues $sv) {
        $sv->print_entry_group("tag_vote", null, ["class" => "need-suggest tags"], "“vote#10” declares an allotment of 10 votes per PC member. (<a href=\"" . $sv->conf->hoturl("help", "t=votetags") . "\">Help</a>)");
    }
    static function print_tag_rank(SettingValues $sv) {
        $sv->print_entry_group("tag_rank", null, null, 'The <a href="' . $sv->conf->hoturl("offline") . '">offline reviewing page</a> will expose support for uploading rankings by this tag. (<a href="' . $sv->conf->hoturl("help", "t=ranking") . '">Help</a>)');
    }
    static function print(SettingValues $sv) {
        // Tags
        echo '<div class="form-g">';
        $sv->print_group("tags/main");
        echo "</div>\n";

        echo '<div class="form-g">';
        $sv->print_group("tags/visibility");
        echo "</div>\n";
    }
    static function print_tag_seeall(SettingValues $sv) {
        echo '<div class="form-g-2">';
        $sv->print_checkbox('tag_seeall', "PC can see tags for conflicted submissions");
        echo '</div>';
    }
    static function print_styles(SettingValues $sv) {
        $skip_colors = [];
        if ($sv->conf->opt("tagNoSettingsColors")) {
            $skip_colors = preg_split('/[\s|]+/', $sv->conf->opt("tagNoSettingsColors"));
        }
        $tag_color_data = $sv->conf->setting_data("tag_color") ?? "";
        $tag_colors_rows = [];
        foreach ($sv->conf->tags()->canonical_colors() as $k) {
            if (in_array($k, $skip_colors)) {
                continue;
            }
            preg_match_all("/(?:\\A|\\s)(\\S+)=$k(?=\\s|\\z)/", $tag_color_data, $m);
            $sv->set_oldv("tag_color_$k", join(" ", $m[1] ?? []));
            $tag_colors_rows[] = "<tr class=\"{$k}tag\"><td class=\"remargin-left\"></td>"
                . "<td class=\"pad taghl align-middle\"><label for=\"tag_color_{$k}\">{$k}</label></td>"
                . "<td class=\"lentry\">"
                  . $sv->feedback_at("tag_color_$k")
                  . $sv->entry("tag_color_$k", ["class" => "need-suggest tags"])
                . "</td><td class=\"remargin-right\"></td></tr>";
        }

        echo Ht::hidden("has_tag_color", 1),
            "<p>Submissions tagged with a style name, or with an associated tag, appear in that style in lists. This also applies to PC tags.</p>",
            '<table class="demargin"><tr><th></th><th class="settings-simplehead" style="min-width:8rem">Style name</th><th class="settings-simplehead">Tags</th><th></th></tr>',
            join("", $tag_colors_rows), "</table>\n";
    }
}


class Tags_SettingParser extends SettingParser {
    /** @var SettingValues */
    private $sv;
    /** @var Tagger */
    private $tagger;
    private $diffs = [];
    private $cleaned = false;

    function __construct(SettingValues $sv) {
        $this->sv = $sv;
        $this->tagger = new Tagger($sv->user);
    }

    function set_oldv(SettingValues $sv, Si $si) {
        if ($si->name === "tag_chair") {
            $ts = array_filter($sv->conf->tags()->filter("chair"), function ($t) {
                return !str_starts_with($t->tag, "~~")
                    && !str_starts_with($t->tag, "perm:");
            });
            $sv->set_oldv("tag_chair", Tags_SettingRenderer::render_tags($ts));
        } else if ($si->name === "tag_sitewide") {
            $sv->set_oldv("tag_sitewide", Tags_SettingRenderer::render_tags($sv->conf->tags()->filter("sitewide")));
        } else if ($si->name === "tag_approval") {
            $sv->set_oldv("tag_approval", Tags_SettingRenderer::render_tags($sv->conf->tags()->filter("approval")));
        } else if ($si->name === "tag_vote") {
            $x = [];
            foreach ($sv->conf->tags()->filter("allotment") as $t) {
                $x[] = "{$t->tag}#{$t->allotment}";
            }
            $sv->set_oldv("tag_vote", join(" ", $x));
        } else if ($si->name === "tag_rank") {
            $sv->set_oldv("tag_rank", $sv->conf->setting_data("tag_rank") ?? "");
        }
    }

    function apply_req(SettingValues $sv, Si $si) {
        assert($this->sv === $sv);
        if ($si->name === "tag_vote") {
            if (($v = $sv->base_parse_req($si)) !== null
                && $sv->update("tag_vote", $v)) {
                $sv->request_write_lock("PaperTag");
                $sv->request_store_value($si);
            }
        } else if ($si->name === "tag_approval") {
            if (($v = $sv->base_parse_req($si)) !== null
                && $sv->update("tag_approval", $v)) {
                $sv->request_write_lock("PaperTag");
                $sv->request_store_value($si);
            }
        } else if ($si->name === "tag_rank") {
            if (($v = $sv->base_parse_req($si)) !== null
                && strpos($v, " ") === false) {
                $sv->save("tag_rank", $v);
            } else if ($v !== null) {
                $sv->error_at("tag_rank", "<0>Multiple ranking tags are not supported");
            }
        } else if ($si->name === "tag_color") {
            $ts = [];
            foreach ($sv->conf->tags()->canonical_colors() as $k) {
                if ($sv->has_req("tag_color_$k")
                    && ($v = $sv->base_parse_req("tag_color_{$k}")) !== null
                    && $v !== "") {
                    $ts[] = preg_replace('/(?=\z| )/', "={$k}", $v);
                }
            }
            $sv->save("tag_color", join(" ", $ts));
        } else {
            return false;
        }
        return true;
    }

    function store_value(SettingValues $sv, Si $si) {
        if (($si->name === "tag_vote" || $si->name === "tag_approval")
            && !$this->cleaned) {
            $old_votish = $new_votish = [];
            foreach (Tagger::split_unpack(strtolower($sv->oldv("tag_vote") . " " . $sv->oldv("tag_approval"))) as $ti) {
                $old_votish[] = $ti[0];
            }
            foreach (Tagger::split_unpack(strtolower($sv->newv("tag_vote") . " " . $sv->newv("tag_approval"))) as $ti) {
                $new_votish[] = $ti[0];
            }
            $new_votish[] = "";

            // remove negative votes
            $pcm = $sv->conf->pc_members();
            $removals = [];
            foreach ($new_votish as $t) {
                list($tag, $index) = Tagger::unpack($t);
                if ($tag !== false) {
                    $removals[] = "right(tag," . (strlen($tag) + 1) . ")='~" . sqlq($tag) . "'";
                }
            }
            if (!empty($removals)) {
                $result = $sv->conf->qe_raw("delete from PaperTag where tagIndex<0 and left(tag,1)!='~' and (" . join(" or ", $removals) . ")");
                if ($result->affected_rows) {
                    $sv->warning_at($si->name, "<0>Removed negative votes");
                }
            }

            // remove no-longer-active voting tags
            if (($removed_votish = array_diff($old_votish, $new_votish))) {
                $result = $sv->conf->qe("delete from PaperTag where tag?a", array_values($removed_votish));
            }

            $sv->mark_invalidate_caches(["autosearch" => true]);
            $this->cleaned = true;
        }
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
            foreach (Tagger::split_unpack($sv->conf->setting_data($n) ?? "") as $ti) {
                $lx = &$vs[strtolower($ti[0])];
                $lx = $lx ?? [];
                $lx[] = $n;
                if (count($lx) === 2) {
                    $sv->warning_at($lx[0], "<0>Tag ‘{$ti[0]}’ is also used for " . $descriptions[$n]);
                }
                if (count($lx) > 1) {
                    $sv->warning_at($n, "<0>Tag ‘{$ti[0]}’ is also used for " . $descriptions[$lx[0]]);
                }
            }
        }
    }
}
