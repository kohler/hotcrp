<?php
// settings/s_sround.php -- HotCRP settings > submission rounds page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Sround_Setting {
    // used by ReviewForm constructor
    /** @var string */
    public $id;
    /** @var string */
    public $tag;
    /** @var int */
    public $open;
    public $register;
    // XXX update
    public $submit;
    public $grace;
    public $freeze;

    static function make_json($jx) {
        $sr = new Sround_Setting;
        $sr->id = $sr->tag = $jx->tag;
        $sr->open = $jx->open ?? 0;
        $sr->register = $jx->register ?? 0;
        $sr->submit = $jx->submit ?? 0;
        $sr->grace = $jx->grace ?? null;
        $sr->freeze = $jx->freeze ?? null;
        return $sr;
    }

    function export_json() {
        $j = ["tag" => $this->tag];
        if ($this->open > 0) {
            $j["open"] = $this->open;
        }
        if ($this->register > 0) {
            $j["register"] = $this->register;
        }
        // XXX update
        if ($this->submit > 0) {
            $j["submit"] = $this->submit;
        }
        if ($this->grace !== null) {
            $j["grace"] = $this->grace;
        }
        if ($this->freeze !== null) {
            $j["freeze"] = $this->freeze;
        }
        return $j;
    }
}

class Sround_SettingParser extends SettingParser {
    private $round_transform = [];

    function placeholder(Si $si, SettingValues $sv) {
        if ($si->name0 === "submission/" && $si->name2 === "/name") {
            $idv = $sv->vstr("submission/{$si->name1}/id");
            return ctype_digit($idv) && $idv !== "0" ? "unnamed" : "(new round)";
        } else {
            return null;
        }
    }

    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name0 === "submission/" && $si->name2 === "") {
            $sv->set_oldv($si, new Sround_Setting);
        } else if ($si->name0 === "submission/" && $si->name2 === "/title") {
            $n = $sv->vstr("submission/{$si->name1}/tag");
            $sv->set_oldv($si, ($n === "" ? "Default" : "‘{$n}’") . " submission class");
        }
    }

    function prepare_oblist(Si $si, SettingValues $sv) {
        $srs = $sv->oldv("submission_rounds");
        $m = [];
        if ($srs && ($j = json_decode($srs)) && is_array($j)) {
            foreach ($j as $jx) {
                $m[] = Sround_Setting::make_json($jx);
            }
        }
        usort($m, "SubmissionRound::compare");
        $sv->append_oblist("submission", $m, "tag");
    }


    /** @param SettingValues $sv
     * @param int|'$' $ctr */
    private static function print_round($sv, $ctr) {
        $idv = $sv->vstr("submission/{$ctr}/id");
        $deleted = ($sv->reqstr("submission/{$ctr}/delete") ?? "") !== "";
        if ($idv === "") {
            $idv = "new";
        }

        echo '<fieldset id="submission/', $ctr,
            '" class="js-settings-submission-round mt-3 mb-2 form-g',
            $idv !== "new" ? "" : " is-new", $deleted ? " deleted" : "", '">',
            Ht::hidden("submission/{$ctr}/id", $idv, ["data-default-value" => $idv === "new" ? "" : $idv]),
            Ht::hidden("submission/{$ctr}/delete", $deleted ? "1" : "", ["data-default-value" => ""]);
        $namesi = $sv->si("submission/{$ctr}/tag");
        echo '<legend>', $sv->label($namesi->name, "Submission class"), ' &nbsp;',
            $sv->entry($namesi->name, ["class" => "uii uich js-settings-submission-round-name want-focus want-delete-marker"]),
            Ht::button(Icons::ui_use("trash"), ["name" => "submission/{$ctr}/deleter", "class" => "ui js-settings-submission-round-delete ml-2 need-tooltip", "aria-label" => "Delete review round", "tabindex" => -1]);
        /*if ($id > 0 && ($round_map[$id - 1] ?? 0) > 0) {
            echo '<span class="ml-3 d-inline-block">',
                '<a href="', $sv->conf->hoturl("search", ["q" => "re:" . ($id > 1 ? $sv->conf->round_name($id - 1) : "unnamed")]), '" target="_blank" rel="noopener">',
                plural($round_map[$id - 1], "review"), '</a></span>';
        }*/
        echo '</legend>';
        $sv->print_feedback_at($namesi->name);

        // deadlines
        echo "<div id=\"submission/{$ctr}/edit\"><div class=\"flex-grow-0\">";
        $sv->print_entry_group("submission/{$ctr}/registration", "Registration deadline", ["horizontal" => true, "group_class" => "medium"]);
        $sv->print_entry_group("submission/{$ctr}/done", "Submission deadline", ["horizontal" => true, "group_class" => "medium"]);
        echo '</div></div></fieldset>';
        if ($deleted) {
            echo Ht::unstash_script("\$(function(){\$(\"#submission\\\\/{$ctr}\\\\/deleter\").click()})");
        }
    }

    static function print_rounds(SettingValues $sv) {
        Icons::stash_defs("trash");
        echo Ht::hidden("has_submission", 1),
            Ht::unstash(),
            '<div id="settings-submission-rounds">';

        foreach ($sv->oblist_keys("submission") as $ctr) {
            self::print_round($sv, $ctr);
        }

        echo '</div><template id="settings-submission-round-new" class="hidden">';
        self::print_round($sv, '$');
        echo '</template><hr class="form-sep form-nearby">',
            Ht::button("Add submission class", ["class" => "ui js-settings-submission-round-new"]);
    }


    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "submission") {
            return $this->apply_submission_req($si, $sv);
        } else if ($si->name2 === "/tag") {
            if (($n = $sv->base_parse_req($si)) !== null
                && $n !== $sv->oldv($si)) {
                if (($err = Conf::round_name_error($n))) {
                    $sv->error_at($si->name, "<0>{$err}");
                }
            }
            return false;
        } else {
            return false;
        }
    }

    private function apply_submission_req(Si $si, SettingValues $sv) {
        $srs = [];
        foreach ($sv->oblist_nondeleted_keys("submission") as $ctr) {
            $pfx = "submission/{$ctr}";
            if ($sv->oldv("{$pfx}/registration") !== $sv->newv("{$pfx}/registration")
                || $sv->oldv("{$pfx}/done") !== $sv->newv("{$pfx}/done")) {
                $sv->check_date_before("submission/{$ctr}/registration", "submission/{$ctr}/done", false);
            }
            $srs[] = $sv->newv($pfx);
        }

        // having parsed all names, check for duplicates
        foreach ($sv->oblist_keys("submission") as $ctr) {
            $sv->error_if_duplicate_member("submission", $ctr, "tag", "Submission class name");
        }

        // save
        $srj = [];
        foreach ($srs as $sr) {
            $srj[] = $sr->export_json();
        }
        $sv->save("submission_rounds", empty($srj) ? "" : json_encode_db($srj));
        return true;
    }

    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("submission") || $sv->has_interest("tag_readonly")) {
            foreach ($sv->conf->submission_round_list() as $i => $sr) {
                if (!$sr->unnamed
                    && !$sv->conf->tags()->is_readonly($sr->tag)) {
                    $ctr = $i + 1;
                    $sv->warning_at("submission/{$ctr}/tag", "<5>PC members can change the tag ‘" . htmlspecialchars($sr->tag) . "’. Tags used for submission classes should usually be " . $sv->setting_link("read-only", "tag_readonly") . ".");
                    $sv->warning_at("tag_readonly");
                }
            }
        }
    }
}
