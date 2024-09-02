<?php
// settings/s_namedsearch.php -- HotCRP settings for named searches
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class NamedSearch_Setting {
    /** @var string */
    public $id;
    /** @var string */
    public $name;
    /** @var string */
    public $q;
    /** @var ?string */
    public $description;
    /** @var 'default'|'none'|'highlight' */
    public $display;

    /** @var bool */
    public $deleted = false;

    function __construct($sj = null) {
        $sj = $sj ?? (object) [];
        $this->id = $this->name = $sj->name ?? "";
        $this->q = $sj->q ?? "";
        if ($this->q !== "" && ($sj->t ?? "") !== "" && $sj->t !== "s") {
            $this->q = "({$sj->q}) in:{$sj->t}";
        }
        $this->description = $sj->description ?? "";
        $this->display = $sj->display ?? "default";
    }

    /** @return object */
    function export_json() {
        $sj = (object) ["name" => $this->name, "q" => $this->q, "owner" => "chair"];
        if ($this->description !== "") {
            $sj->description = $this->description;
        }
        if ($this->display !== "default") {
            $sj->display = $this->display;
        }
        return $sj;
    }
}

class NamedSearch_SettingParser extends SettingParser {
    /** @var array */
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
                if ((int) strpos($sj->name, "~") === 0
                    && $sj->owner === "chair")
                    $m[] = new NamedSearch_Setting($sj);
            }
            $sv->append_oblist("named_search", $m, "name");
        }
    }

    function apply_req(Si $si, SettingValues $sv) {
        assert($si->name === "named_search");
        $sjl = [];
        $known_names = [];
        foreach ($sv->oblist_nondeleted_keys("named_search") as $ctr) {
            $ns = $sv->newv("named_search/{$ctr}");
            // XXX check name
            $sv->error_if_missing("named_search/{$ctr}/name");
            $sv->error_if_duplicate_member("named_search", $ctr, "name", "Search name");
            if (!$sv->has_error_at("named_search/{$ctr}/name")
                && ($error = SearchConfig_API::name_error($ns->name, "search"))) {
                $sv->error_at("named_search/{$ctr}/name", "<0>{$error}");
            }
            if ($ns->q === "") {
                $sv->warning_at("named_search/{$ctr}/search", "<0>Empty search");
            } else {
                $ps = new PaperSearch($sv->conf->root_user(), $ns->q);
                foreach ($ps->message_list() as $mi) {
                    $sv->append_item_at("named_search/{$ctr}/search", $mi);
                }
            }
            $sjl[] = $ns->export_json();
            $known_names[strtolower($ns->name)] = true;
        }
        foreach ($this->settings_json as $sj) {
            if ((int) strpos($sj->name, "~") > 0
                || ($sj->owner !== "chair" && !isset($known_names[strtolower($sj->name)])))
                $sjl[] = $sj;
        }
        usort($sjl, [$sv->conf, "named_search_compare"]);
        $sv->update("named_searches", empty($sjl) ? "" : json_encode_db($sjl));
        return true;
    }

    static function crosscheck(SettingValues $sv) {
        if (!$sv->has_interest("named_search")) {
            return;
        }
        foreach ($sv->oblist_keys("named_search") as $ctr) {
            $ns = $sv->oldv("named_search/{$ctr}");
            if ($ns->q !== "") {
                SearchConfig_API::append_search_messages($sv->conf->root_user(), $ns->name, $ns->q, $ctr, $sv);
            }
        }
    }
}
