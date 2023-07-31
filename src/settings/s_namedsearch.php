<?php
// settings/s_namedsearch.php -- HotCRP settings for named searches
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class NamedSearch_Setting {
    /** @var string */
    public $id;
    /** @var string */
    public $name;
    /** @var string */
    public $q;
    /** @var 'default'|'none'|'highlight' */
    public $display;

    /** @var bool */
    public $deleted = false;

    function __construct($j = null) {
        $j = $j ?? (object) [];
        $this->id = $this->name = $j->name ?? "";
        $this->q = $j->q ?? "";
        $this->display = $j->display ?? "default";
    }

    /** @return object */
    function export_json() {
        $j = (object) ["name" => $this->name, "q" => $this->q, "owner" => "chair"];
        if ($this->display !== "default") {
            $j->display = $this->display;
        }
        return $j;
    }

    /** @param NamedSearch_Setting|object $a
     * @param NamedSearch_Setting|object $b
     * @return int */
    static function compare($a, $b) {
        $ad = $a->display ?? "default";
        $bd = $b->display ?? "default";
        if ($ad === "highlight" && $bd !== "highlight") {
            return -1;
        } else if ($bd === "highlight" && $ad !== "highlight") {
            return 1;
        } else {
            return strnatcasecmp($a->name, $b->name);
        }
    }
}

class NamedSearch_SettingParser extends SettingParser {
    /** @var object */
    private $settings_json;

    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name_matches("named_search/", "*")) {
            $sv->set_oldv($si, new NamedSearch_Setting);
        } else if ($si->name_matches("named_search/", "*", "/title")) {
            if (($name = $sv->vstr("{$si->name0}{$si->name1}/name") ?? "") !== "") {
                $sv->set_oldv($si->name, "‘ss:{$name}’");
            } else {
                $sv->set_oldv($si->name, "Named search");
            }
        }
    }

    function prepare_oblist(Si $si, SettingValues $sv) {
        if ($si->name === "named_search") {
            $this->settings_json = $this->settings_json ?? $sv->conf->setting_json("named_searches") ?? [];
            $m = [];
            foreach ($this->settings_json as $sj) {
                $m[] = new NamedSearch_Setting($sj);
            }
            $sv->append_oblist("named_search", $m, "name");
        }
    }

    function apply_req(Si $si, SettingValues $sv) {
        assert($si->name === "named_search");
        $j = [];
        foreach ($sv->oblist_nondeleted_keys("named_search") as $ctr) {
            $ns = $sv->newv("named_search/{$ctr}");
            $sv->error_if_missing("named_search/{$ctr}/name");
            $sv->error_if_duplicate_member("named_search", $ctr, "name", "Search name");
            if ($ns->q === "") {
                $sv->warning_at("named_search/{$ctr}/q", "Empty search");
            } else {
                $ps = new PaperSearch($sv->conf->root_user(), $ns->q);
                foreach ($ps->message_list() as $mi) {
                    $sv->append_item_at("named_search/{$ctr}/q", $mi);
                }
            }
            $j[] = $ns->export_json();
            usort($j, "NamedSearch_Setting::compare");
        }
        $sv->update("named_searches", empty($j) ? "" : json_encode_db($j));
        return true;
    }

    static function crosscheck(SettingValues $sv) {
        if (!$sv->has_interest("named_search")) {
            return;
        }
        foreach ($sv->oblist_keys("named_search") as $ctr) {
            $ns = $sv->oldv("named_search/{$ctr}");
            if ($ns->q !== "") {
                $ps = new PaperSearch($sv->conf->root_user(), $ns->q);
                foreach ($ps->message_list() as $mi) {
                    $sv->append_item_at("named_search/{$ctr}/q", $mi);
                }
            }
        }
    }
}
