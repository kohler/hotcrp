<?php
// settings/s_automatictag.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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
        } else if ($si->name0 === "automatic_tag/" && $si->name2 === "/search") {
            $q = $sv->reqstr($si->name);
            if (simplify_whitespace($q) === "") {
                $sv->error_at($si, "<0>Entry required");
            } else {
                $search = new PaperSearch($sv->conf->root_user(), ["q" => $q, "t" => "all"]);
                $search->set_expand_automatic(true);
                $search->full_term();
                if ($search->has_problem()) {
                    $old = $sv->oldv($si->name0 . $si->name1);
                    $method = $q === $old->q ? "warning_at" : "error_at";
                    $sv->$method($si);
                    foreach ($search->message_list() as $mi) {
                        $sv->append_item_at($si, $mi);
                    }
                }
                $sv->save($si, $q);
            }
            return true;
        } else if ($si->name0 === "automatic_tag/" && $si->name2 === "/value") {
            $v = $sv->reqstr($si->name);
            if ($v !== "") {
                $formula = new Formula($v);
                if (!$formula->check($sv->conf->root_user())) {
                    $sv->error_at($si);
                    foreach ($formula->message_list() as $mi) {
                        $sv->append_item_at($si, $mi);
                    }
                }
            }
            $sv->save($si, $v);
            return true;
        } else {
            return false;
        }
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
            $sv->mark_invalidate_caches(["tags" => true, "autosearch" => true]);
        }
    }

    static function crosscheck(SettingValues $sv) {
        foreach ($sv->oblist_keys("automatic_tag") as $ctr) {
            $atr = $sv->oldv("automatic_tag/{$ctr}");
            if ($atr && simplify_whitespace($atr->q) !== "") {
                $search = new PaperSearch($sv->conf->root_user(), ["q" => $atr->q, "t" => "all"]);
                $search->set_expand_automatic(true);
                $search->full_term();
                foreach ($search->message_list() as $mi) {
                    $sv->append_item_at("automatic_tag/{$ctr}/search", $mi);
                }
            }
            if ($atr->v) {
                $formula = new Formula($atr->v);
                if (!$formula->check($sv->conf->root_user())) {
                    foreach ($formula->message_list() as $mi) {
                        $sv->append_item_at("automatic_tag/{$ctr}/value", $mi);
                    }
                }
            }
        }
    }
}
