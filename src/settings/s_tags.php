<?php
// settings/s_tags.php -- HotCRP settings > tags page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Tags_SettingParser extends SettingParser {
    /** @var SettingValues */
    private $sv;
    /** @var Tagger */
    private $tagger;
    /** @var TagMap */
    private $base_map;
    private $diffs = [];
    private $cleaned = false;

    /** @return int */
    static function si_flag(Si $si) {
        if ($si->name === "tag_hidden") {
            return TagInfo::TF_HIDDEN;
        } else if ($si->name === "tag_readonly") {
            return TagInfo::TF_READONLY;
        } else if ($si->name === "tag_sitewide") {
            return TagInfo::TF_SITEWIDE;
        } else if ($si->name === "tag_vote_approval") {
            return TagInfo::TF_APPROVAL;
        } else if ($si->name === "tag_vote_allotment") {
            return TagInfo::TF_ALLOTMENT;
        } else if ($si->name === "tag_rank") {
            return TagInfo::TF_RANK;
        } else {
            return 0;
        }
    }

    /** @return list<TagInfo> */
    static function sorted_settings_for(TagMap $map, Si $si) {
        return $map->sorted_settings_having(self::si_flag($si));
    }

    function __construct(SettingValues $sv) {
        $this->sv = $sv;
        $this->tagger = new Tagger($sv->user);
        $this->base_map = TagMap::make($sv->conf, false);
    }

    function set_oldv(Si $si, SettingValues $sv) {
        if (in_array($si->name, ["tag_hidden", "tag_readonly", "tag_sitewide", "tag_vote_approval"])) {
            $sv->set_oldv($si->name, self::render_tags(self::sorted_settings_for($sv->conf->tags(), $si)));
        } else if ($si->name === "tag_vote_allotment") {
            $x = [];
            foreach ($sv->conf->tags()->sorted_settings_having(TagInfo::TF_ALLOTMENT) as $t) {
                $x[] = "{$t->tag}#{$t->allotment}";
            }
            $sv->set_oldv("tag_vote_allotment", join(" ", $x));
        } else if ($si->name === "tag_rank") {
            $sv->set_oldv("tag_rank", $sv->conf->setting_data("tag_rank") ?? "");
        }
    }

    /** @param list<TagInfo> $tl
     * @return string */
    static function render_tags($tl) {
        $last = null;
        $tx = [];
        foreach ($tl as $ti) {
            if (!$last || $last->tag !== $ti->tag) {
                $tx[] = $ti->tag;
                $last = $ti;
            }
        }
        return join(" ", $tx);
    }
    static function print_tag_chair(SettingValues $sv) {
        $sv->print_entry_group("tag_readonly", null, [
            "class" => "need-suggest tags",
            "hint" => "PC members can see these tags, but only administrators can change them.",
            "autocomplete" => "off"
        ]);
    }
    static function print_tag_sitewide(SettingValues $sv) {
        if ($sv->newv("tag_sitewide") || $sv->conf->has_any_manager()) {
            $sv->print_entry_group("tag_sitewide", null, [
                "class" => "need-suggest tags",
                "hint" => "Administrators can see and change these tags for every submission.",
                "autocomplete" => "off"
            ]);
        }
    }
    static function print_tag_approval(SettingValues $sv) {
        $sv->print_entry_group("tag_vote_approval", null, [
            "class" => "need-suggest tags",
            "hint" => "<a href=\"" . $sv->conf->hoturl("help", "t=voting") . "\">Help</a>",
            "autocomplete" => "off"
        ]);
    }
    static function print_tag_vote(SettingValues $sv) {
        $sv->print_entry_group("tag_vote_allotment", null, [
            "class" => "need-suggest tags",
            "hint" => "“vote#10” declares an allotment of 10 votes per PC member. (<a href=\"" . $sv->conf->hoturl("help", "t=voting") . "\">Help</a>)",
            "autocomplete" => "off"
        ]);
    }
    static function print_tag_rank(SettingValues $sv) {
        $sv->print_entry_group("tag_rank", null, [
            "hint" => 'The <a href="' . $sv->conf->hoturl("offline") . '">offline reviewing page</a> will expose support for uploading rankings by this tag. (<a href="' . $sv->conf->hoturl("help", "t=ranking") . '">Help</a>)',
            "autocomplete" => "off"
        ]);
    }
    static function print_tag_seeall(SettingValues $sv) {
        $sv->print_checkbox("tag_visibility_conflict", "PC can see tags for conflicted submissions");
    }

    private function strip_presets($si, $v) {
        $have = [];
        foreach (self::sorted_settings_for($this->base_map, $si) as $ti) {
            $have[$ti->tag] = true;
        }
        $newv = [];
        foreach (Tagger::split_unpack($v) as $tv) {
            if (!isset($have[$tv[0]])) {
                $newv[] = $tv[1] === null ? $tv[0] : "{$tv[0]}#{$tv[1]}";
            }
        }
        return join(" ", $newv);
    }

    function apply_req(Si $si, SettingValues $sv) {
        assert($this->sv === $sv);
        if (($v = $sv->base_parse_req($si)) === null) {
            return true;
        }
        if (self::si_flag($si) !== 0) {
            $v = $this->strip_presets($si, $v);
        }
        if ($si->name === "tag_vote_allotment" || $si->name === "tag_vote_approval") {
            if ($sv->update($si, $v)) {
                $sv->request_write_lock("PaperTag");
                $sv->request_store_value($si);
            }
        } else if ($si->name === "tag_rank") {
            if (strpos($v, " ") === false) {
                $sv->save("tag_rank", $v);
            } else if ($v !== null) {
                $sv->error_at("tag_rank", "<0>Multiple ranking tags are not supported");
            }
        } else if (self::si_flag($si) !== 0) {
            $sv->save($si, $v);
        } else {
            return false;
        }
        return true;
    }

    function store_value(Si $si, SettingValues $sv) {
        if (($si->name === "tag_vote_allotment"
             || $si->name === "tag_vote_approval")
            && !$this->cleaned) {
            $old_votish = $new_votish = [];
            foreach (Tagger::split_unpack(strtolower($sv->oldv("tag_vote_allotment") . " " . $sv->oldv("tag_vote_approval"))) as $ti) {
                $old_votish[] = $ti[0];
            }
            foreach (Tagger::split_unpack(strtolower($sv->newv("tag_vote_allotment") . " " . $sv->newv("tag_vote_approval"))) as $ti) {
                $new_votish[] = $ti[0];
            }
            $new_votish[] = "";

            // remove negative votes
            $removals = [];
            foreach ($new_votish as $t) {
                list($tag, $unused_index) = Tagger::unpack($t);
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
                $sv->conf->qe("delete from PaperTag where tag?a", array_values($removed_votish));
            }

            $sv->mark_invalidate_caches(["autosearch" => true]);
            $this->cleaned = true;
        }
    }

    static function crosscheck(SettingValues $sv) {
        $vs = [];
        '@phan-var array<string,list<int>> $vs';
        $vinfo = [
            ["tag_approval", "tag_vote_approval", "approval voting"],
            ["tag_vote", "tag_vote_allotment", "allotment voting"],
            ["tag_rank", "tag_rank", "ranking"]
        ];
        foreach ($vinfo as $di => $dd) {
            foreach (Tagger::split_unpack($sv->conf->setting_data($dd[0]) ?? "") as $tv) {
                $ltag = strtolower($tv[0]);
                $vs[$ltag][] = $di;
                $m = $vs[$ltag];
                if (count($m) === 2) {
                    $sv->warning_at($vinfo[$m[0]][1], "<0>Tag ‘{$tv[0]}’ is also used for {$dd[2]}");
                }
                if (count($m) > 1) {
                    $sv->warning_at($dd[1], "<0>Tag ‘{$tv[0]}’ is also used for {$vinfo[$m[0]][2]}");
                }
            }
        }
    }
}
