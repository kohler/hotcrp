<?php
// settings/s_review.php -- HotCRP settings > reviews page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Review_Setting {
    /** @var int */
    public $id = 0;
    /** @var string */
    public $name = "";
    /** @var ?int */
    public $soft;
    /** @var ?int */
    public $done;
    /** @var ?int */
    public $external_soft;
    /** @var ?int */
    public $external_done;

    public $saved_id; // only set during save
    /** @var bool */
    public $deleted = false;

    /** @return bool */
    function is_empty() {
        return ($this->soft ?? 0) <= 0
            && ($this->done ?? 0) <= 0
            && ($this->external_soft ?? 0) <= 0
            && ($this->external_done ?? 0) <= 0;
    }

    /** @param int $id
     * @return Review_Setting */
    static function make(Conf $conf, $id) {
        $rs = new Review_Setting;
        $rs->id = $id;
        $rs->name = $conf->round_name($id - 1);
        $sfx = $id > 1 ? "_" . ($id - 1) : "";
        $rs->soft = $conf->setting("pcrev_soft{$sfx}");
        $rs->done = $conf->setting("pcrev_hard{$sfx}");
        $rs->external_soft = $conf->setting("extrev_soft{$sfx}");
        $rs->external_done = $conf->setting("extrev_hard{$sfx}");
        if ($rs->external_soft === $rs->soft) {
            $rs->external_soft = null;
        }
        if ($rs->external_done === $rs->done) {
            $rs->external_done = null;
        }
        return $rs;
    }
}

class Review_SettingParser extends SettingParser {
    private $round_transform = [];

    function placeholder(Si $si, SettingValues $sv) {
        if ($si->name0 === "review/" && $si->name2 === "/name") {
            $idv = $sv->vstr("review/{$si->name1}/id");
            return ctype_digit($idv) && $idv !== "0" ? "unnamed" : "(new round)";
        } else {
            return null;
        }
    }

    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name0 === "review/" && $si->name2 === "") {
            $sv->set_oldv($si, new Review_Setting);
        } else if ($si->name0 === "review/" && $si->name2 === "/title") {
            $n = $sv->vstr("review/{$si->name1}/name");
            if ($n === "" && !$sv->conf->has_rounds()) {
                $sv->set_oldv($si, "Review");
            } else {
                $sv->set_oldv($si, ($n === "" ? "Default" : "‘{$n}’") . " review");
            }
        } else if ($si->name === "review_default_round_index") {
            $sv->set_oldv($si, 0);
            $t = $sv->conf->setting_data("rev_roundtag") ?? "";
            if (($round = $sv->conf->round_number($t)) !== null
                && ($ctr = $sv->search_oblist("review", "id", $round + 1))) {
                $sv->set_oldv($si, $ctr);
            }
        } else if ($si->name === "review_default_round") {
            $t = $sv->conf->setting_data("rev_roundtag") ?? null;
            $sv->set_oldv($si, $t ?? "unnamed");
        } else if ($si->name === "review_default_external_round_index") {
            $sv->set_oldv($si, 0);
            $t = $sv->conf->setting_data("extrev_roundtag") ?? null;
            if ($t !== null
                && ($round = $sv->conf->round_number($t)) !== null
                && ($ctr = $sv->search_oblist("review", "id", $round + 1))) {
                $sv->set_oldv($si, $ctr);
            }
        } else if ($si->name === "review_default_external_round") {
            $t = $sv->conf->setting_data("extrev_roundtag") ?? null;
            $sv->set_oldv($si, $t === "" ? "unnamed" : ($t ?? ""));
        }
    }

    function prepare_oblist(Si $si, SettingValues $sv) {
        $m = [];
        foreach ($sv->conf->defined_rounds() as $i => $name) {
            $m[] = Review_Setting::make($sv->conf, $i + 1);
        }
        $sv->append_oblist("review", $m, "name");
    }


    static function print(SettingValues $sv) {
        $sv->print_checkbox("review_open", "<b>Enable reviewing</b>");
        $sv->print_checkbox("comment_allow_always", "Allow comments even if reviewing is closed");

        echo '<hr class="form-sep">';
        $sv->print_radio_table("review_blind", [Conf::BLIND_ALWAYS => "Yes, reviews are anonymous",
                   Conf::BLIND_NEVER => "No, reviewer names are visible to authors",
                   Conf::BLIND_OPTIONAL => "Depends: reviewers decide whether to expose their names"],
            '<strong>Review anonymity:</strong> Are reviewer names hidden from authors?');
    }

    /** @param SettingValues $sv
     * @param int|'$' $ctr
     * @param array<int,int> $round_map */
    private static function print_round($sv, $ctr, $round_map) {
        $idv = $sv->vstr("review/{$ctr}/id");
        $id = $ctr !== "\$" && ctype_digit($idv) ? intval($idv) : -1;
        $deleted = ($sv->reqstr("review/{$ctr}/delete") ?? "") !== "";

        echo '<fieldset id="review/', $ctr, '" class="js-settings-review-round mt-3 mb-2 form-g',
            $id > 0 ? "" : " is-new", $deleted ? " deleted" : "",
            '" data-exists-count="', $id > 0 ? $round_map[$id - 1] ?? 0 : 0, '">',
            Ht::hidden("review/{$ctr}/id", $id > 0 ? $id : "new", ["data-default-value" => $id > 0 ? $id : ""]),
            Ht::hidden("review/{$ctr}/delete", $deleted ? "1" : "", ["data-default-value" => ""]);
        $namesi = $sv->si("review/{$ctr}/name");
        echo '<legend>';
        echo $sv->label($namesi->name, "Review round"), " ",
            $sv->entry($namesi->name, ["class" => "ml-1 uii uich js-settings-review-round-name want-delete-marker"]);
        if ($id > 1 || count($sv->conf->defined_rounds()) > 1) {
            echo Ht::button(Icons::ui_use("trash"), ["id" => "review/{$ctr}/deleter", "class" => "ui js-settings-review-round-delete ml-2 need-tooltip", "aria-label" => "Delete review round", "tabindex" => -1]);
        }
        if ($id > 0 && ($round_map[$id - 1] ?? 0) > 0) {
            echo '<span class="ml-3 d-inline-block">',
                '<a href="', $sv->conf->hoturl("search", ["q" => "re:" . ($id > 1 ? $sv->conf->round_name($id - 1) : "unnamed")]), '" target="_blank" rel="noopener">',
                plural($round_map[$id - 1], "review"), '</a></span>';
        }
        if ($ctr === '$') {
            echo '<div class="f-h fx">Names like “R1” and “R2” work well.</div>';
        }
        echo '</legend>';
        $sv->print_feedback_at($namesi->name);

        // deadlines
        echo "<div id=\"review/{$ctr}/edit\" class=\"f-mcol\"><div class=\"flex-grow-0\">";
        $sv->print_entry_group("review/{$ctr}/soft", "PC deadline", ["horizontal" => true]);
        $sv->print_entry_group("review/{$ctr}/done", "Hard deadline", ["horizontal" => true]);
        echo '</div><div class="flex-grow-0">';
        $sv->print_entry_group("review/{$ctr}/external_soft", "External deadline", ["horizontal" => true]);
        $sv->print_entry_group("review/{$ctr}/external_done", "Hard deadline", ["horizontal" => true]);
        echo "</div></div></fieldset>";
        if ($deleted) {
            echo Ht::unstash_script("\$(function(){\$(\"#review\\\\/{$ctr}\\\\/deleter\").click()})");
        }
    }

    static function print_rounds(SettingValues $sv) {
        Icons::stash_defs("trash");
        echo '<p>Reviews are due by the deadline, but <em>cannot be modified</em> after the hard deadline. Most conferences don’t use hard deadlines for reviews.</p>',
            '<p class="f-h">', $sv->type_hint("date"), '</p>',
            Ht::hidden("has_review", 1),
            Ht::unstash();

        // prepare round selector
        $sv->set_oldv("rev_roundtag", $sv->conf->setting_data("rev_roundtag") ?? "");
        $t = $sv->conf->setting_data("extrev_roundtag") ?? "default";
        $sv->set_oldv("extrev_roundtag", $t === "unnamed" ? "" : $t);

        // round deadlines
        echo '<div id="settings-review-rounds">';
        $round_map = Dbl::fetch_iimap($sv->conf->ql("select reviewRound, count(*) from PaperReview group by reviewRound"));
        foreach ($sv->oblist_keys("review") as $ctr) {
            self::print_round($sv, $ctr, $round_map);
        }
        echo '</div><template id="settings-review-round-new" class="hidden">';
        self::print_round($sv, '$', []);
        echo '</template><hr class="form-sep form-nearby">',
            Ht::button("Add round", ["class" => "ui js-settings-review-round-new"]),
            ' &nbsp; <span class="hint"><a href="', $sv->conf->hoturl("help", "t=revround"), '">What is this?</a></span>';

        // default rounds for new assignments
        echo '<hr class="form-sep">';
        $sel = [];
        foreach ($sv->oblist_nondeleted_keys("review") as $ctr) {
            $n = $sv->vstr("review/{$ctr}/name");
            $sel[$ctr] = $n === "" ? "unnamed" : $n;
        }
        $sv->print_select_group("review_default_round_index", null, $sel, [
                "class" => "settings-review-round-selector",
                "hint" => "New review assignments will use this round unless otherwise specified."
            ]);
        $sv->print_select_group("review_default_external_round_index", null,
            [0 => "same as PC"] + $sel,
            ["class" => "settings-review-round-selector"]);
    }


    static function print_pc(SettingValues $sv) {
        echo '<div class="form-g has-fold foldo">';
        $sv->print_checkbox("review_self_assign", "PC members can self-assign reviews", ["class" => "uich js-foldup"]);
        if ($sv->conf->setting("pcrev_any")
            && $sv->conf->check_track_sensitivity(Track::UNASSREV)) {
            echo '<p class="f-h fx">', $sv->setting_group_link("Current track settings", "tracks"), ' may restrict self-assigned reviews.</p>';
        }
        echo "</div>\n";


        $hint = "";
        if ($sv->conf->check_track_sensitivity(Track::VIEWREVID)) {
            $hint = '<p class="settings-radiohint f-h">' . $sv->setting_group_link("Current track settings", "tracks") . ' restrict reviewer name visibility.</p>';
        }
        $sv->print_radio_table("review_identity_visibility_pc", [
                Conf::VIEWREV_ALWAYS => "Yes",
                Conf::VIEWREV_IFASSIGNED => "Only if assigned a review for the same submission",
                Conf::VIEWREV_AFTERREVIEW => "Only after completing a review for the same submission"
            ],
            'Can non-conflicted PC members see <strong>reviewer names</strong>?',
            ["after" => $hint]);


        $hint = "";
        if ($sv->conf->check_track_sensitivity(Track::VIEWREV)
            || $sv->conf->check_track_sensitivity(Track::VIEWALLREV)) {
            $hint = '<p class="settings-radiohint f-h">' . $sv->setting_group_link("Current track settings", "tracks") . ' restrict review visibility.</p>';
        }
        echo '<hr class="form-sep">';
        $sv->print_radio_table("review_visibility_pc", [
                Conf::VIEWREV_ALWAYS => "Yes",
                Conf::VIEWREV_UNLESSINCOMPLETE => "Yes, unless they haven’t completed an assigned review for the same submission",
                Conf::VIEWREV_UNLESSANYINCOMPLETE => "Yes, after completing all their assigned reviews",
                Conf::VIEWREV_AFTERREVIEW => "Only after completing a review for the same submission"
            ], 'Can non-conflicted PC members see <strong>review contents</strong>?',
            ["after" => $hint]);

        echo '<hr class="form-sep">';
        $sv->print_radio_table("comment_visibility_reviewer", [
                1 => ["label" => "Yes", "hint" => '<p class="settings-ap f-hx fn">Commenters will be pseudonymized (e.g., as “Reviewer A”) when reviewer names are hidden. Note that comment <em>contents</em> cannot be pseudonymized reliably.</p>'],
                0 => ["label" => "Only when they can see reviewer names", "hint" => '<p class="settings-ap f-hx fx">Responses and other author-visible comments are always visible to reviewers.</p>']
            ], 'Can reviewers see <strong>comments</strong>?',
            ["item_class" => "uich js-foldup",
             "fold_values" => [0]]);

        echo '<hr class="form-sep"><div class="settings-radio">',
            '<div class="label">Who can <strong>always</strong> see reviewer names and review contents for their assigned papers?</div>',
            '<div class="settings-radioitem checki"><span class="checkc">✓</span>Metareviewers</div>',
            '<div class="settings-radioitem checki"><label><span class="checkc">',
            $sv->print_checkbox_only("review_visibility_lead"),
            '</span>Discussion leads</label></div>',
            '</div>';
    }


    static function print_extrev_view(SettingValues $sv) {
        $sv->print_radio_table("review_identity_visibility_external", [
                Conf::VIEWREV_ALWAYS => "Yes",
                Conf::VIEWREV_AFTERREVIEW => "Yes, after completing their review",
                Conf::VIEWREV_NEVER => "No"
            ], 'Can external reviewers see <strong>reviewer names</strong> for their assigned submissions?');

        echo '<hr class="form-sep">';
        $sv->print_radio_table("review_visibility_external", [
                Conf::VIEWREV_AFTERREVIEW => "Yes, after completing their review",
                Conf::VIEWREV_NEVER => "No"
            ], 'Can external reviewers see <strong>review contents</strong> for their assigned submissions?');
    }
    static function print_extrev_editdelegate(SettingValues $sv) {
        echo '<div id="foldreview_proposal_editable" class="form-g has-fold',
            $sv->vstr("review_proposal") >= 0 ? ' fold1o' : ' fold1c',
            '" data-fold1-values="0 1 2">';
        $sv->print_radio_table("review_proposal", [-1 => "No",
                1 => "Yes, but administrators must approve all requests",
                2 => "Yes, but administrators must approve external reviewers with potential conflicts",
                0 => "Yes"
            ], "Can PC reviewers request external reviews?",
            ["item_class" => "uich js-foldup"]);
        // echo '<p>Secondary PC reviews can be delegated to external reviewers. When the external review is complete, the secondary PC reviewer need not complete a review of their own.</p>', "\n";

        echo '<div class="fx1">';
        echo '<hr class="form-sep">';
        $label3 = "Yes, and external reviews are visible only to their requesters";
        if ($sv->conf->fetch_ivalue("select exists (select * from PaperReview where reviewType=" . REVIEW_EXTERNAL . " and reviewSubmitted>0)")) {
            $label3 = '<label for="review_proposal_editable_3">' . $label3 . '</label><div class="settings-ap f-hx fx">Existing ' . Ht::link("submitted external reviews", $sv->conf->hoturl("search", ["q" => "re:ext:submitted"]), ["target" => "_new"]) . ' will remain visible to others.</div>';
        }
        $sv->print_radio_table("review_proposal_editable", [
                0 => "No",
                1 => "Yes, but external reviewers still own their reviews (requesters cannot adopt them)",
                2 => "Yes, and external reviews are hidden until requesters approve or adopt them",
                3 => $label3
            ], "Can PC members edit the external reviews they requested?",
            ["fold_values" => [3]]);
        echo "</div></div>\n";
    }
    static function print_extrev_requestmail(SettingValues $sv) {
        $t = $sv->expand_mail_template("requestreview", false);
        echo '<div id="foldmailbody_requestreview" class="form-g ',
            ($t == $sv->expand_mail_template("requestreview", true) ? "foldc" : "foldo"),
            '">';
        $sv->set_oldv("mailbody_requestreview", $t["body"]);
        echo '<div class="', $sv->control_class("mailbody_requestreview", "f-i"), '">',
            '<div class="f-c n">',
            '<button type="button" class="q ui js-foldup">', expander(null, 0),
            '<label for="mailbody_requestreview">Mail template for external review requests</label></button>',
            '<span class="fx"> (<a href="', $sv->conf->hoturl("mail"), '">keywords</a> allowed; set to empty for default)</span></div>',
            $sv->textarea("mailbody_requestreview", ["class" => "text-monospace fx", "cols" => 80, "rows" => 20]);
        $sv->print_feedback_at("mailbody_requestreview");
        echo "</div></div>\n";
    }

    static function print_ratings(SettingValues $sv) {
        $sv->print_radio_table("review_rating", [
                REV_RATINGS_NONE => "No",
                REV_RATINGS_PC => "Yes, PC members can rate reviews",
                REV_RATINGS_PC_EXTERNAL => "Yes, PC members and external reviewers can rate reviews"
            ], 'Should HotCRP collect ratings of reviews?   <a class="hint" href="' . $sv->conf->hoturl("help", "t=revrate") . '">Learn more</a>');
    }


    /** @param string $name
     * @param bool $external
     * @return string */
    static function clean_name($name, $external) {
        $ln = strtolower($name);
        if ($ln === "default" || $ln === "") {
            return "";
        } else if ($ln === "unnamed" || $ln === "none") {
            return $external ? "unnamed" : "";
        } else {
            return $name;
        }
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "review") {
            return $this->apply_review_req($si, $sv);
        } else if ($si->name === "review_default_round"
                   || $si->name === "review_default_external_round") {
            if (($n = $sv->reqstr($si->name)) !== null) {
                $this->apply_review_default_round($si, $sv, trim($n));
            }
            return true;
        } else if ($si->name === "review_default_round_index"
                   || $si->name === "review_default_external_round_index") {
            if (($n = $sv->reqstr($si->name)) !== null) {
                $this->apply_review_default_round_index($si, $sv, trim($n));
            }
            return true;
        } else if ($si->name === "review_visibility_external") {
            if (($n = $sv->reqstr($si->name)) === "blind") {
                $sv->save($si, Conf::VIEWREV_AFTERREVIEW);
                $sv->save("review_identity_visibility_external", Conf::VIEWREV_NEVER);
            }
            return false;
        } else if ($si->name2 === "/name") {
            if (($n = $sv->base_parse_req($si)) !== null
                && $n !== $sv->oldv($si)) {
                if (self::clean_name($n, false) === "") {
                    $sv->set_req($si->name, "");
                } else if (($err = Conf::round_name_error($n))) {
                    $sv->error_at($si->name, "<0>{$err}");
                }
            }
            return false;
        } else {
            return false;
        }
    }

    private function apply_review_req(Si $si, SettingValues $sv) {
        $rss = [];
        $old_rsid = [];
        $name_map = [];
        $first_nondeleted = null;
        $latest = null;
        foreach ($sv->oblist_nondeleted_keys("review") as $ctr) {
            $pfx = "review/{$ctr}";
            $ors = $sv->oldv($pfx);
            $nrs = $sv->newv($pfx);
            if ($nrs->id <= 0
                || $nrs->soft !== $ors->soft
                || $nrs->done !== $ors->done) {
                $sv->check_date_before("review/{$ctr}/soft", "review/{$ctr}/done", false);
            }
            if ($nrs->id <= 0
                || $nrs->external_soft !== $ors->external_soft
                || $nrs->external_done !== $ors->external_done) {
                $sv->check_date_before("review/{$ctr}/external_soft", "review/{$ctr}/external_done", false);
            }
            $rss[] = $nrs;
            if ($nrs->id > 0) {
                $old_rsid[$nrs->id] = $nrs;
                $name_map[$ors->name] = $nrs->name;
                if ($ors->name === "") {
                    $name_map["unnamed"] = $nrs->name;
                }
            }
            if (!$first_nondeleted) {
                $first_nondeleted = $nrs;
            }
            if (!$latest
                || ($latest->soft > 0 && $nrs->soft > 0 && $latest->soft < $nrs->soft)) {
                $latest = $nrs;
            }
        }

        // having parsed all names, check for duplicates
        foreach ($sv->oblist_keys("review") as $ctr) {
            $sv->error_if_duplicate_member("review", $ctr, "name", "Review round name");
        }

        // arrange in id order in `$rsid`
        // (complicated because unnamed round === round 0, so changing names may
        // require renumbering rounds)
        $defined_rounds = $sv->conf->defined_rounds();
        $saved_old_rsid = $rsid = $nextrss = [];
        foreach ($rss as $rs) {
            if ($rs->name === "") {
                $rs->saved_id = 1;
                $rsid[1] = $rs;
            } else if ($rs->id > 1) {
                $rs->saved_id = $rs->id;
                $rsid[$rs->id] = $rs;
            } else {
                $nextrss[] = $rs;
            }
        }
        $n = 2;
        foreach ($nextrss as $rs) {
            while (isset($rsid[$n]) || ($defined_rounds[$n - 1] ?? ";") !== ";") {
                ++$n;
            }
            $rs->saved_id = $n;
            $rsid[$n] = $rs;
            ++$n;
        }
        if (empty($rsid)) { // at least one round is always defined
            $rsid[1] = $rs = new Review_Setting;
            $rs->saved_id = 1;
            $latest = $latest ?? $rs;
        }
        ksort($rsid);

        // ensure round 0 appears if saved: change pcrev_soft_0 to `explicit_none`
        if (($rs = $rsid[1] ?? null) && $rs->is_empty()) {
            $sv->si("pcrev_soft_0")->change_subtype("explicit_none");
        }

        // save deadlines and change rounds if necessary
        $tag_rounds = [];
        foreach ($rsid as $rs) {
            $rnum = $rs->saved_id - 1;
            if ($rs->id > 0 && $rs->saved_id !== $rs->id) {
                $this->round_transform[] = "when " . ($rs->id - 1) . " then {$rnum}";
            }
            $sv->save("pcrev_soft_{$rnum}", $rs->soft ?? 0);
            $sv->save("pcrev_hard_{$rnum}", $rs->done ?? 0);
            $sv->save("extrev_soft_{$rnum}", $rs->external_soft === $rs->soft ? -1 : $rs->external_soft ?? -1);
            $sv->save("extrev_hard_{$rnum}", $rs->external_done === $rs->done ? -1 : $rs->external_done ?? -1);
            if ($rnum > 0) {
                assert($rs->name !== "");
                while (count($tag_rounds) < $rnum - 1) {
                    $tag_rounds[] = ";";
                }
                $tag_rounds[] = $rs->name;
            }
        }
        $sv->save("tag_rounds", join(" ", $tag_rounds));

        // update default rounds based on new names
        $first_nondeleted_name = $first_nondeleted ? $first_nondeleted->name : "unnamed";
        if (!$sv->has_req("review_default_round")
            && !$sv->has_req("review_default_round_index")) {
            $oldr = $sv->oldv("review_default_round");
            if ($oldr !== ($newr = $name_map[$oldr] ?? $first_nondeleted_name)) {
                $sv->save("rev_roundtag", self::clean_name($newr, false));
            }
        }
        if (!$sv->has_req("review_default_external_round")
            && !$sv->has_req("review_default_external_round_index")) {
            $oldr = $sv->oldv("review_default_external_round");
            if ($oldr !== ""
                && $oldr !== ($newr = $name_map[$oldr] ?? $first_nondeleted_name)) {
                $sv->save("extrev_roundtag", self::clean_name($newr, true));
            }
        }

        // remove old deadlines, renumber reviews from deleted rounds
        $rnum_bound = max(0, 0, ...array_keys($defined_rounds)) + 1;
        $rnum_bound = max(1, $rnum_bound, ...array_keys($rsid)) + 1;
        for ($rnum = 0; $rnum !== $rnum_bound; ++$rnum) {
            if (!isset($rsid[$rnum + 1])) {
                $sv->save("pcrev_soft_{$rnum}", -1);
                $sv->save("pcrev_hard_{$rnum}", -1);
                $sv->save("extrev_soft_{$rnum}", -1);
                $sv->save("extrev_hard_{$rnum}", -1);
            }
            if (isset($defined_rounds[$rnum]) && !isset($old_rsid[$rnum + 1])) {
                $this->round_transform[] = "when {$rnum} then " . ($latest ? $latest->saved_id - 1 : 0);
            }
        }

        if (!empty($this->round_transform)) {
            $sv->request_write_lock("PaperReview", "ReviewRequest", "PaperReviewRefused");
            $sv->request_store_value($si);
        }
        return true;
    }

    private function apply_review_default_round_index(Si $si, SettingValues $sv, $n) {
        $external = $si->name === "review_default_external_round_index";
        $savekey = $external ? "extrev_roundtag" : "rev_roundtag";
        if (($n === "0" && !$external)
            || ($n !== "0" && !$sv->has_req("review/{$n}/id"))
            || ($n !== "0" && ($sv->reqstr("review/{$n}/delete") ?? "") !== "")) {
            $sv->warning_at($si, "Invalid entry");
        } else if ($n === "0") {
            $sv->save($savekey, "");
        } else {
            $name = self::clean_name(trim($sv->vstr("review/{$n}/name") ?? ""), $external);
            $sv->save($savekey, $name);
        }
    }

    /** @param string $n */
    private function apply_review_default_round(Si $si, SettingValues $sv, $n) {
        $external = $si->name === "review_default_external_round";
        $savekey = $external ? "extrev_roundtag" : "rev_roundtag";
        $newrounds = $sv->newv("tag_rounds") ?? "";
        $name = self::clean_name($n, $external);
        if ($name === "" || $name === "unnamed") {
            $sv->save($savekey, $name);
        } else if (($err = Conf::round_name_error($n))) {
            $sv->error_at($si, "<0>{$err}");
        } else if (stripos(" {$newrounds} ", $n) === false) {
            $sv->error_at($si, "<0>Round ‘{$n}’ is not currently in use");
        } else {
            $sv->save($savekey, $n);
        }
    }

    function store_value(Si $si, SettingValues $sv) {
        if ($si->name === "review" && !empty($this->round_transform)) {
            $qx = "case reviewRound " . join(" ", $this->round_transform) . " else reviewRound end";
            $sv->conf->qe_raw("update PaperReview set reviewRound=" . $qx);
            $sv->conf->qe_raw("update ReviewRequest set reviewRound=" . $qx);
            $sv->conf->qe_raw("update PaperReviewRefused set reviewRound=" . $qx);
        }
    }


    static function crosscheck(SettingValues $sv) {
        $conf = $sv->conf;
        if ($sv->has_interest("review_open")
            && intval($sv->vstr("review_open")) <= 0) {
            self::crosscheck_review_deadlines_closed_reviews($sv);
        }

        if (($sv->has_interest("review_visibility_author") || $sv->has_interest("review/1"))
            && $sv->oldv("review_visibility_author") == Conf::AUSEEREV_YES
            && ($dn = self::crosscheck_future_review_deadline($sv)) !== null
            && !$sv->has_error()) {
            $sv->warning_at(null, "<5>" . $sv->setting_link("Authors can see reviews and comments", "review_visibility_author") . " although it is before a " . $sv->setting_link("review deadline", $dn) . ". This is sometimes unintentional.");
        }

        if (($sv->has_interest("review_blind")
             || $sv->has_interest("review_identity_visibility_external"))
            && $sv->oldv("review_blind") == Conf::BLIND_NEVER
            && $sv->oldv("review_visibility_external") != Conf::VIEWREV_NEVER
            && $sv->oldv("review_identity_visibility_external") == Conf::VIEWREV_NEVER) {
            $sv->warning_at("review_identity_visibility_external", "<5>" . $sv->setting_link("Reviews aren’t anonymous", "review_blind") . ", so external reviewers can see reviewer names and comments despite " . $sv->setting_link("your settings", "review_identity_visibility_external") . ".");
        }

        if ($sv->has_interest("mailbody_requestreview")
            && $sv->vstr("mailbody_requestreview")
            && (strpos($sv->oldv("mailbody_requestreview"), "%LOGINURL%") !== false
                || strpos($sv->oldv("mailbody_requestreview"), "%LOGINURLPARTS%") !== false)) {
            $sv->warning_at("mailbody_requestreview", "<5>The <code>%LOGINURL%</code> and <code>%LOGINURLPARTS%</code> keywords should no longer be used in email templates.");
        }
    }

    static private function crosscheck_future_review_deadline(SettingValues $sv) {
        foreach ($sv->oblist_keys("review") as $ctr) {
            $sn = "review/{$ctr}/soft";
            if (($t = $sv->oldv($sn)) > Conf::$now)
                return $sn;
        }
        return null;
    }

    static private function crosscheck_review_deadlines_closed_reviews(SettingValues $sv) {
        foreach ($sv->oblist_keys("review") as $ctr) {
            if (($rs = $sv->oldv("review/{$ctr}")) && $rs->id > 0) {
                foreach (["soft", "done", "external_soft", "external_done"] as $k) {
                    if (($rs->$k ?? 0) > Conf::$now) {
                        $sv->warning_at("review_open", "<0>Reviewing is currently closed");
                        $sv->inform_at(null, "<5>You are being warned because a " . $sv->setting_link("review deadline", "review/{$ctr}/$k") . " is set for the future.");
                        return;
                    }
                }
            }
        }
    }
}
