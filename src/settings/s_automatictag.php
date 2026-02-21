<?php
// settings/s_automatictag.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class AutomaticTag_Setting implements JsonSerializable {
    /** @var string */
    public $id = "\$";
    /** @var string */
    public $tag = "";
    /** @var string */
    public $q = "";
    public $v;

    /** @var bool */
    public $deleted = false;

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $x = ["t" => $this->tag, "q" => $this->q];
        if (($this->v ?? "") !== "") {
            $x["v"] = $this->v;
        }
        return $x;
    }
}

class AutomaticTag_SettingParser extends SettingParser {
    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name0 === "automatic_tag/" && $si->name2 === "") {
            $sv->set_oldv($si, new AutomaticTag_Setting);
        }
    }

    function prepare_oblist(Si $si, SettingValues $sv) {
        $vs = [];
        foreach (json_decode($sv->oldv("tag_autosearch") ?? "[]", true) ?? [] as $tag => $s) {
            $v = new AutomaticTag_Setting;
            $v->id = strtolower($tag);
            $v->tag = $tag;
            $v->q = $s["q"] ?? "";
            $v->v = $s["v"] ?? "";
            $vs[$v->id] = $v;
        }
        $sv->append_oblist("automatic_tag", array_values($vs), "tag");
    }

    private function _apply_automatic_tag_req(Si $si, SettingValues $sv) {
        $djs = [];
        foreach ($sv->oblist_keys("automatic_tag") as $ctr) {
            $atr = $sv->object_newv("automatic_tag/{$ctr}");
            if (!$atr->deleted && !$sv->reqstr("automatic_tag/{$ctr}/delete") /* XXX */) {
                $djs[] = $atr;
            }
        }

        // sort and save
        $collator = $sv->conf->collator();
        usort($djs, function ($a, $b) use ($collator) {
            return $collator->compare($a->tag, $b->tag);
        });
        $as = [];
        foreach ($djs as $atr) {
            $x = ["q" => $atr->q];
            if (($atr->v ?? "") !== "") {
                $x["v"] = $atr->v;
            }
            $as[$atr->tag] = $x;
        }
        if ($sv->update("tag_autosearch", empty($as) ? "" : json_encode($as))) {
            $sv->request_store_value($si);
        }
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "automatic_tag") {
            return $this->_apply_automatic_tag_req($si, $sv);
        }
        if ($si->name0 === "automatic_tag/" && $si->name2 === "/search") {
            $q = $sv->reqstr($si->name);
            if (simplify_whitespace($q) === "") {
                $sv->error_at($si, "<0>Entry required");
            } else {
                $sv->save($si, $q);
                $sv->request_validate($si);
            }
            return true;
        }
        if ($si->name0 === "automatic_tag/" && $si->name2 === "/value") {
            $v = $sv->reqstr($si->name);
            $sv->save($si, $v);
            if ($v !== "") {
                $sv->request_validate($si);
            }
            return true;
        }
        return false;
    }

    function validate(Si $si, SettingValues $sv) {
        $old_at = $sv->oldv($si->name0 . $si->name1);
        $pb = $sv->conf->set_updating_automatic_tags(true);
        if ($si->name2 === "/search") {
            $q = $sv->newv($si);
            $search = new PaperSearch($sv->conf->root_user(), ["q" => $q, "t" => "all"]);
            $search->full_term();
            if ($search->has_problem()) {
                $method = $q === $old_at->q ? "warning_at" : "error_at";
                $sv->$method($si);
                foreach ($search->message_list() as $mi) {
                    $sv->append_item_at($si, $mi);
                }
            }
        } else if ($si->name2 === "/value") {
            $v = $sv->newv($si);
            $formula = Formula::make($sv->conf->root_user(), $sv->newv($si));
            if (!$formula->ok()) {
                $method = $v === $old_at->v ? "warning_at" : "error_at";
                $sv->$method($si);
                foreach ($formula->message_list() as $mi) {
                    $sv->append_item_at($si, $mi);
                }
            }
        }
        $sv->conf->set_updating_automatic_tags(false);
    }

    function store_value(Si $si, SettingValues $sv) {
        if ($si->name === "automatic_tag"
            && $sv->oldv("tag_autosearch") !== $sv->newv("tag_autosearch")) {
            $newt = [];
            foreach (json_decode($sv->newv("tag_autosearch") ?? "", true) ?? [] as $t => $v) {
                $newt[strtolower($t)] = true;
            }
            $csv = ["paper,tag,tag value"];
            foreach (json_decode($sv->oldv("tag_autosearch") ?? "", true) ?? [] as $t => $v) {
                if (!isset($newt[strtolower($t)]))
                    $csv[] = CsvGenerator::quote("#{$t}") . "," . CsvGenerator::quote($t) . ",clear";
            }
            if (count($csv) > 1) {
                $sv->register_cleanup_function("tag_autosearch", function () use ($sv, $csv) {
                    $sv->conf->_update_automatic_tags_csv($csv);
                });
            }
            $sv->mark_invalidate_caches("tags", "autosearch");
        }
    }

    static function crosscheck(SettingValues $sv) {
        $pc = $sv->parser("AutomaticTag_SettingParser");
        foreach ($sv->oblist_keys("automatic_tag") as $ctr) {
            if (($atr = $sv->oldv("automatic_tag/{$ctr}"))) {
                $pc->validate($sv->si("automatic_tag/{$ctr}/search"), $sv);
                if ($atr->v) {
                    $pc->validate($sv->si("automatic_tag/{$ctr}/value"), $sv);
                }
            }
        }
    }
}
