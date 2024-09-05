<?php
// settings/s_tagstyle.php -- HotCRP settings > tags > colors page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class TagStyle_Setting {
    /** @var string */
    public $id = "\$";
    /** @var string */
    public $style = "";
    /** @var string */
    public $tags = "";
    /** @var int */
    public $styleflags = TagStyle::DYNAMIC;
    /** @var bool */
    public $deleted = false;
    /** @var int */
    public $order = PHP_INT_MAX;

    /** @param TagStyle $ks
     * @param int $count */
    static function make($ks, $count) {
        $tss = new TagStyle_Setting;
        $tss->id = $tss->style = $ks->style;
        $tss->styleflags = $ks->styleflags;
        if (($tss->styleflags & TagStyle::DYNAMIC) !== 0) {
            $tss->order = PHP_INT_MAX;
        } else {
            $tss->order = $count;
        }
        return $tss;
    }

    /** @param string $tag */
    function add($tag) {
        if ($tag !== "") {
            if ($this->tags === "") {
                $this->tags = $tag;
            } else {
                $this->tags .= " {$tag}";
            }
        }
    }

    /** @param TagStyle_Setting $a
     * @param TagStyle_Setting $b
     * @return int */
    static function compare($a, $b) {
        return ($a->styleflags & TagStyle::TEXT) <=> ($b->styleflags & TagStyle::TEXT)
            ? : $a->order <=> $b->order
            ? : strcasecmp($a->style, $b->style);
    }
}

class TagStyle_SettingParser extends SettingParser {
    function set_oldv(Si $si, SettingValues $sv) {
        if (($si->name0 === "tag_style/" || $si->name0 === "badge/")
            && $si->name2 === "") {
            $sv->set_oldv($si, new TagStyle_Setting);
        }
    }

    function prepare_oblist(Si $si, SettingValues $sv) {
        $badge = $si->name === "badge";

        $dt = $sv->conf->tags();
        $kmap = [];
        $stylematch = $badge ? TagStyle::BADGE : TagStyle::STYLE;
        foreach ($dt->listed_style_names($stylematch) as $name) {
            $kmap[$name] = TagStyle_Setting::make($dt->find_style($name), count($kmap));
        }

        $tc = $sv->conf->setting_data($badge ? "tag_badge" : "tag_color") ?? "";
        preg_match_all('/(?:\A|\s)([^\s=]*+)=(\S++)/', $tc, $m, PREG_SET_ORDER);
        foreach ($m as $mx) {
            $style = $mx[2];
            if (!array_key_exists($style, $kmap)) {
                if (!($ks = $dt->known_style($style, $stylematch))) {
                    continue;
                }
                $style = $ks->style;
                if (!array_key_exists($style, $kmap)) {
                    $kmap[$style] = TagStyle_Setting::make($ks, count($kmap));
                }
            }
            if ($mx[1] !== "") {
                $kmap[$style]->add($mx[1]);
            }
        }
        usort($kmap, "TagStyle_Setting::compare");
        $sv->append_oblist($si->name, $kmap, "style");
    }

    static function print(SettingValues $sv) {
        echo Ht::hidden("has_tag_style", 1),
            "<p>Submissions and PC members tagged with a style name, or with an associated tag, appear in that style in lists.</p>",
            '<table class="demargin"><thead><tr><th></th><th class="settings-simplehead" style="min-width:8rem">Style name</th><th class="settings-simplehead">Tags</th><th></th></tr></thead><tbody class="settings-tag-style-table">';
        $dt = $sv->conf->tags();
        foreach ($sv->oblist_keys("tag_style") as $ctr) {
            $style = $sv->oldv("tag_style/{$ctr}/style");
            echo '<tr class="tag-', $style, '"><td class="remargin-left"></td>',
                '<td class="pad taghl align-middle">',
                '<label for="tag_style/', $ctr, '/tags">', $style, '</label></td>',
                '<td class="lentry">',
                Ht::hidden("tag_style/{$ctr}/style", $style),
                $sv->feedback_at("tag_style/{$ctr}/style"),
                $sv->entry("tag_style/{$ctr}/tags", ["class" => "need-suggest tags", "spellcheck" => false, "autocomplete" => "off"]),
                '</td><td class="remargin-right"></td></tr>';
            TagMap::stash_ensure_pattern("tag-{$style}");
        }
        echo Ht::unstash(), '</tbody></table>';
    }

    private function _apply_tag_style_req(Si $si, SettingValues $sv) {
        $badge = $si->name === "badge";
        $stylematch = $badge ? TagStyle::BADGE : TagStyle::STYLE;
        $bs = [];
        foreach ($sv->oblist_nondeleted_keys($si->name) as $ctr) {
            $br = $sv->newv("{$si->name}/{$ctr}");
            $ks = $sv->conf->tags()->known_style($br->style, $stylematch);
            $sn = $ks ? $ks->style : $br->style;
            $bs_count = count($bs);
            foreach (explode(" ", $br->tags) as $tag) {
                if ($tag !== "")
                    $bs[] = "{$tag}={$sn}";
            }
            if ($ks
                && ($ks->styleflags & TagStyle::DYNAMIC) !== 0
                && count($bs) === $bs_count) {
                $bs[] = "={$sn}"; // keep it in the list
            }
        }
        if ($sv->update($badge ? "tag_badge" : "tag_color", join(" ", $bs))) {
            $sv->mark_invalidate_caches(["tags" => true]);
        }
        return true;
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "tag_style" || $si->name === "badge") {
            return $this->_apply_tag_style_req($si, $sv);
        } else if (($si->name0 === "tag_style/" || $si->name0 === "badge/")
                   && $si->name2 === "/style") {
            $badge = $si->name0 === "badge/";
            $stylematch = $badge ? TagStyle::BADGE : TagStyle::STYLE;
            if (($v = $sv->base_parse_req($si->name)) !== null
                && $sv->conf->tags()->known_style($v, $stylematch)) {
                $sv->save($si, $v);
            } else {
                $type = $badge ? "badge" : "tag";
                $sv->error_at($si, "<0>Unknown {$type} style ‘{$v}’");
            }
            return true;
        } else {
            return false;
        }
    }
}
