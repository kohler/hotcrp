<?php
// pages/p_settings.php -- HotCRP chair-only conference settings management page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Settings_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var SettingValues */
    public $sv;
    /** @var string */
    public $reqsg;

    /** @param SettingValues $sv
     * @param Contact $user */
    function __construct($sv, $user) {
        assert($sv->conf === $user->conf);
        $this->conf = $user->conf;
        $this->user = $user;
        $this->sv = $sv;
    }

    /** @param Qrequest $qreq
     * @return string */
    function choose_setting_group($qreq) {
        $this->reqsg = $qreq->group;
        if (!$this->reqsg && preg_match('/\A\/\w+\/*\z/', $qreq->path())) {
            $this->reqsg = $qreq->path_component(0);
        }
        $want_group = $this->reqsg;
        if (!$want_group) {
            // try previous global group (NB not conf-specific)
            $want_group = $qreq->gsession("sg");
            if ($want_group && !$this->sv->canonical_group($want_group)) {
                $want_group = null;
            }
        }
        if (!$want_group) {
            if ($this->conf->time_some_author_view_review()) {
                $want_group = "decisions";
            } else if ($this->conf->time_after_setting("sub_sub")
                       || $this->conf->time_review_open()) {
                $want_group = "reviews";
            } else {
                $want_group = "submissions";
            }
        }
        if ($want_group === "list") {
            return "list";
        }
        $canon_group = $this->sv->canonical_group($want_group);
        if (!$canon_group) {
            http_response_code(404);
            $this->conf->error_msg("<0>Settings group not found");
            return "list";
        }
        if ($canon_group !== $this->reqsg
            && !$qreq->post
            && $qreq->post_empty()) {
            $this->conf->redirect_self($qreq, [
                "group" => $canon_group, "#" => $this->sv->group_hashid($want_group)
            ]);
        }
        $this->sv->set_canonical_page($canon_group);
        return $canon_group;
    }

    /** @param Qrequest $qreq */
    function handle_update($qreq) {
        if ($this->sv->execute()) {
            $qreq->set_csession("settings_highlight", $this->sv->message_field_map());
            if (!empty($this->sv->changed_keys())) {
                $this->conf->success_msg("<0>Changes saved");
            } else if (!$this->sv->has_success()) {
                $this->conf->feedback_msg(new MessageItem(null, "<0>No changes", MessageSet::WARNING_NOTE));
            }
            $this->sv->report();
            $this->conf->redirect_self($qreq);
        }
    }

    /** @param string $group
     * @param Qrequest $qreq */
    function print($group, $qreq) {
        if ($group === "error404") {
            http_response_code(404);
        }

        $qreq->print_header("Settings", "settings", [
            "title_div" => "",
            "subtitle" => $this->sv->group_title($group),
            "body_class" => "leftmenu",
            "save_messages" => true
        ]);
        Icons::stash_defs("movearrow0", "movearrow2", "trash");
        echo Ht::unstash(), // clear out other script references
            $this->conf->make_script_file("scripts/settings.js"), "\n",

            Ht::form($this->conf->hoturl("=settings", "group={$group}"),
                     ["id" => "f-settings", "class" => "need-diff-check need-unload-protection"]),

            '<div class="leftmenu-left"><nav class="leftmenu-menu">',
            '<h1 class="leftmenu"><button type="button" class="q uic js-leftmenu">Settings</button></h1>',
            '<ul class="leftmenu-list">';
        foreach ($this->sv->group_members("") as $gj) {
            $title = $gj->short_title ?? $gj->title;
            if ($gj->name === $group) {
                echo '<li class="leftmenu-item active">', $title ?? "(Unlisted)", '</li>';
            } else if ($title && !($gj->unlisted ?? false)) {
                echo '<li class="leftmenu-item ui js-click-child"><a href="',
                    $this->conf->hoturl("settings", "group={$gj->name}"), '">', $title, '</a></li>';
            }
        }
        echo '</ul><div class="leftmenu-if-left if-differs mt-5">',
            Ht::submit("update", "Save changes", ["class" => "btn-primary"]),
            "</div></nav></div>\n",
            '<main class="leftmenu-content main-column">';

        if ($group !== "list") {
            $this->print_extant_group($group, $qreq);
        } else {
            $this->print_list();
        }

        echo "</main></form>\n";
        Ht::stash_script('$("#f-settings").awaken()');
        $qreq->print_footer();
    }

    /** @param string $group
     * @param Qrequest $qreq */
    private function print_extant_group($group, $qreq) {
        $sv = $this->sv;
        echo '<h2 class="leftmenu">', $sv->group_title($group);
        $gj = $sv->cs()->get($group);
        if ($gj && isset($gj->title_help_group)) {
            echo " ", Ht::link(Icons::ui_solid_question(), $sv->conf->hoturl("help", "t={$gj->title_help_group}"), ["class" => "ml-1"]);
        }
        echo '</h2>';

        if (!$sv->use_req()) {
            $sv->crosscheck();
        }
        if ($sv->conf->report_saved_messages() < 1 || $sv->use_req()) {
            // XXX this is janky (if there are any warnings saved in the session,
            // don't crosscheck) but reduces duplicate warnings
            $sv->report();
        }
        $sv->cs()->print_body_members(strtolower($group));

        if ($sv->inputs_printed()) {
            echo '<div class="aab aabig mt-7">',
                '<div class="aabut">', Ht::submit("update", "Save changes", ["class" => "btn-primary"]), '</div>',
                '<div class="aabut">', Ht::submit("cancel", "Cancel", ["formnovalidate" => true]), '</div>',
                '<hr class="c"></div>';
        }
    }

    private function print_list() {
        echo '<h2 class="leftmenu">Settings list</h2>';
        $this->conf->report_saved_messages();
        echo "<dl>\n";
        foreach ($this->sv->group_members("") as $gj) {
            if (isset($gj->title)) {
                echo '<dt><strong><a href="', $this->conf->hoturl("settings", "group={$gj->name}"), '">',
                    $gj->title, '</a></strong></dt><dd>',
                    Ftext::as(5, $gj->description ?? ""), "</dd>\n";
            }
        }
        echo "</dl>\n";
    }

    static function go(Contact $user, Qrequest $qreq) {
        if (isset($qreq->cancel)) {
            $user->conf->redirect_self($qreq);
        }

        $sv = SettingValues::make_request($user, $qreq);
        $sv->set_use_req(isset($qreq->update) && $qreq->valid_post());
        if (!$sv->viewable_by_user()) {
            $user->escape();
        }
        $sv->session_highlight($qreq);

        $sp = new Settings_Page($sv, $user);
        $group = $qreq->group = $sp->choose_setting_group($qreq);
        $qreq->set_gsession("sg", $group);

        if ($sv->use_req()) {
            $sp->handle_update($qreq);
        }
        $sp->print($group, $qreq);
    }
}
