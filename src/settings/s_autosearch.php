<?php
// src/settings/s_autosearch.php -- HotCRP settings > tags page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Autosearch_SettingRenderer {
    static function render_autosearch(SettingValues $sv, $name, $q, $xpos) {
        echo '<div class="settings_tag_autosearch">',
            Ht::hidden("tag_autosearch_n_$xpos", $name),
            Ht::entry("tag_autosearch_t_$xpos", $name, $sv->sjs("tag_autosearch_t_$xpos", array("placeholder" => "Tag", "size" => 20, "id" => "tag_autosearch_t_$xpos"))),
            ' &nbsp;',
            Ht::entry("tag_autosearch_q_$xpos", $q, $sv->sjs("tag_autosearch_q_$xpos", ["placeholder" => "Search", "size" => 50, "id" => "tag_autosearch_q_$xpos"])),
            '</div>';

    }
    static function render(SettingValues $sv) {
        // Tags
        $tagmap = $sv->conf->tags();
        echo "<h3 class=\"settings\">Automatic tags</h3>\n";
        echo "<p class=\"settingtext\">Automatic tags are set based on the result of a search.</p>\n";
        echo "<div class='g'></div>\n", Ht::hidden("has_tag_autosearch", 1), "\n\n";
        $autosearch = json_decode($sv->oldv("tag_autosearch"), true) ? : [];
        ksort($autosearch, SORT_STRING | SORT_FLAG_CASE);
        echo '<div id="settings_tag_autosearch" class="c">';
        $pos = 0;
        foreach ($autosearch as $name => $s)
            self::render_autosearch($sv, $name, $s["q"], ++$pos);
        echo "</div>\n",
            '<div style="margin-top:2em">',
            Ht::js_button("Add automatic tag", "settings_tag_autosearch.call(this)", ["class" => "settings_tag_autosearch_new btn"]),
            "</div>\n<div id=\"settings_newtag_autosearch\" style=\"display:none\">";
        self::render_autosearch($sv, null, null, 0);
        echo "</div>\n\n";

        Ht::stash_script('suggest($(".need-tagcompletion"), taghelp_tset)', "taghelp_tset");
    }
}


class Autosearch_SettingParser extends SettingParser {
    static private function findk($autosearch, $dropk) {
        foreach ($autosearch as $k => $v)
            if (strcasecmp($k, $dropk) == 0)
                return $k;
        return false;
    }
    static private function dropk($autosearch, $dropk) {
        unset($autosearch[self::findk($autosearch, $dropk)]);
        return $autosearch;
    }
    function parse(SettingValues $sv, Si $si) {
        $tagger = new Tagger;
        $autosearch = json_decode($sv->oldv("tag_autosearch"), true) ? : [];
        $new_autosearch = [];
        for ($pos = 1; isset($sv->req["tag_autosearch_n_$pos"]); ++$pos) {
            $tag = simplify_whitespace($sv->req["tag_autosearch_t_$pos"]);
            $had_tag = self::findk($autosearch, $tag);
            $autosearch = self::dropk($autosearch, $sv->req["tag_autosearch_n_$pos"]);
            $tagx = $tagger->check($tag, Tagger::NOPRIVATE | Tagger::NOVALUE);
            $searchq = simplify_whitespace($sv->req["tag_autosearch_q_$pos"]);
            $search = null;
            if ($tag !== "" && $searchq !== "") {
                $search = new PaperSearch($sv->conf->site_contact(), ["q" => $searchq, "t" => "all"]);
                $search->prepare_term();
            }
            if ($tag !== "" && !$tagx)
                $sv->error_at("tag_autosearch_t_$pos", "Automatic tag: " . $tagger->error_html);
            if (!empty($search->warnings))
                $sv->error_at("tag_autosearch_q_$pos", "Automatic tag search: " . join("; ", $search->warnings));
            if ($tagx && empty($search->warnings)) {
                $autosearch = self::dropk($autosearch, $tagx);
                $autosearch[$tagx] = ["q" => $searchq];
            }
        }
        $sv->update("tag_autosearch", empty($autosearch) ? null : json_encode($autosearch));
        $sv->need_lock["PaperTag"] = $sv->need_lock["Paper"] =
            $sv->need_lock["PaperConflict"] = $sv->need_lock["PaperReview"] = true;
        return true;
    }
    function save(SettingValues $sv, Si $si) {
        $old_autosearch = json_decode($sv->oldv("tag_autosearch"), true) ? : [];
        $autosearch = json_decode($sv->savedv("tag_autosearch"), true) ? : [];
        $csv = ["paper,tag"];
        // XXX copied from Conf::update_autosearch
        foreach ($old_autosearch as $k => $v) {
            $nk = self::findk($autosearch, $k);
            if (!$nk || $autosearch[$nk]["q"] !== $v["q"])
                $csv[] = "all," . CsvGenerator::quote("{$k}#clear");
        }
        foreach ($autosearch as $k => $v) {
            $ok = self::findk($old_autosearch, $k);
            if (!$ok || $old_autosearch[$ok]["q"] !== $v["q"]) {
                $csv[] = "all," . CsvGenerator::quote("{$k}#clear");
                $csv[] = CsvGenerator::quote($v["q"]) . "," . CsvGenerator::quote($k);
            }
        }
        if (count($csv) > 1) {
            $sv->conf->_update_autosearch_tags_csv($csv);
        }
        $sv->conf->invalidate_caches(["taginfo" => true]);
    }
}
