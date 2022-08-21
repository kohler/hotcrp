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
    /** @var bool */
    public $deleted = false;

    static function make($style, $tags) {
        $tss = new TagStyle_Setting;
        $tss->id = $tss->style = $style;
        $tss->tags = join(" ", $tags);
        return $tss;
    }
}

class TagStyle_SettingParser extends SettingParser {
    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name0 === "tag_style/" && $si->name2 === "") {
            $sv->set_oldv($si, new TagStyle_Setting);
        }
    }

    function prepare_oblist(Si $si, SettingValues $sv) {
        $kmap = [];
        $dt = $sv->conf->tags();
        foreach ($dt->canonical_known_styles() as $ks) {
            if (($ks->sclass & TagStyle::SECRET) === 0)
                $kmap[$ks->name] = [];
        }
        $tc = $sv->conf->setting_data("tag_color") ?? "";
        preg_match_all('/(?:\A|\s)(\S+)=(\S+)(?=\s|\z)/', $tc, $m, PREG_SET_ORDER);
        foreach ($m as $mx) {
            if (array_key_exists($mx[2], $kmap)) {
                $kmap[$mx[2]][] = $mx[1];
            } else if (($ks = $dt->known_style($mx[2]))
                       && ($ks->sclass & TagStyle::SECRET) === 0) {
                $kmap[$ks->name][] = $mx[1];
            }
        }
        $klist = [];
        foreach ($kmap as $style => $tags) {
            $klist[] = TagStyle_Setting::make($style, $tags);
        }
        $sv->append_oblist("tag_style", $klist, "style");
    }

    static function print(SettingValues $sv) {
        echo Ht::hidden("has_tag_style", 1),
            "<p>Submissions tagged with a style name, or with an associated tag, appear in that style in lists. This also applies to PC tags.</p>",
            '<table class="demargin"><tr><th></th><th class="settings-simplehead" style="min-width:8rem">Style name</th><th class="settings-simplehead">Tags</th><th></th></tr>';
        $dt = $sv->conf->tags();
        foreach ($sv->oblist_keys("tag_style") as $ctr) {
            $style = $sv->oldv("tag_style/{$ctr}/style");
            echo '<tr class="tag-', $style, '"><td class="remargin-left"></td>',
                '<td class="pad taghl align-middle">',
                '<label for="tag_style/', $ctr, '/tags">', $style, '</label></td>',
                '<td class="lentry">',
                Ht::hidden("tag_style/{$ctr}/style", $style),
                $sv->feedback_at("tag_style/{$ctr}/style"),
                $sv->entry("tag_style/{$ctr}/tags", ["class" => "need-suggest tags"]),
                '</td><td class="remargin-right"></td></tr>';
            $dt->mark_pattern_fill("tag-{$style}");
        }
        echo Ht::unstash(), '</table>';
    }

    private function _apply_tag_style_req(Si $si, SettingValues $sv) {
        $bs = [];
        foreach ($sv->oblist_nondeleted_keys("tag_style") as $ctr) {
            $br = $sv->newv("tag_style/{$ctr}");
            $ks = $sv->conf->tags()->known_style($br->style);
            $sn = $ks ? $ks->name : $br->style;
            foreach (explode(" ", $br->tags) as $tag) {
                if ($tag !== "") {
                    $bs[] = "{$tag}={$sn}";
                }
            }
        }
        if ($sv->update("tag_color", join(" ", $bs))) {
            $sv->mark_invalidate_caches(["tags" => true]);
        }
        return true;
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "tag_style") {
            return $this->_apply_tag_style_req($si, $sv);
        } else if ($si->name0 === "tag_style/" && $si->name2 === "/style") {
            if (($v = $sv->base_parse_req($si->name)) !== null
                && $sv->conf->tags()->known_style($v)) {
                $sv->save($si, $v);
            } else {
                $sv->error_at($si, "<0>Unknown tag style ‘{$v}’");
            }
            return true;
        } else {
            return false;
        }
    }
}
