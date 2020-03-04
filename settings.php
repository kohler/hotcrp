<?php
// settings.php -- HotCRP chair-only conference settings management page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
if (!$Me->privChair) {
    $Me->escape();
}

$Sv = SettingValues::make_request($Me, $Qreq);
$Sv->session_highlight();

function choose_setting_group($qreq, SettingValues $sv) {
    global $Conf, $Me;
    $req_group = $qreq->group;
    if (!$req_group && preg_match('/\A\/\w+\/*\z/', $qreq->path())) {
        $req_group = $qreq->path_component(0);
    }
    $want_group = $req_group;
    if (!$want_group && isset($_SESSION["sg"])) { // NB not conf-specific session, global
        $want_group = $_SESSION["sg"];
    }
    $want_group = $sv->canonical_group($want_group);
    if (!$want_group || !$sv->group_title($want_group)) {
        if ($sv->conf->can_some_author_view_review()) {
            $want_group = $sv->canonical_group("decisions");
        } else if ($sv->conf->deadlinesAfter("sub_sub") || $sv->conf->time_review_open()) {
            $want_group = $sv->canonical_group("reviews");
        } else {
            $want_group = $sv->canonical_group("sub");
        }
    }
    if (!$want_group) {
        $Me->escape();
    }
    if ($want_group !== $req_group && !$qreq->post && $qreq->post_empty()) {
        $Conf->self_redirect($qreq, ["group" => $want_group, "anchor" => $sv->group_anchorid($req_group)]);
    }
    $sv->mark_interesting_group($want_group);
    return $want_group;
}
$Group = $Qreq->group = choose_setting_group($Qreq, $Sv);
$_SESSION["sg"] = $Group;

if (isset($Qreq->update) && $Qreq->post_ok()) {
    if ($Sv->execute()) {
        $Me->save_session("settings_highlight", $Sv->message_field_map());
        if (!empty($Sv->changes))
            $Sv->conf->confirmMsg("Changes saved.");
        else
            $Sv->conf->warnMsg("No changes.");
        $Sv->report();
        $Conf->self_redirect($Qreq);
    }
}
if (isset($Qreq->cancel) && $Qreq->post_ok()) {
    $Conf->self_redirect($Qreq);
}

$Sv->crosscheck();

$Conf->header("Settings", "settings", ["subtitle" => $Sv->group_title($Group), "title_div" => '<hr class="c">', "body_class" => "leftmenu"]);
echo Ht::unstash(); // clear out other script references
echo $Conf->make_script_file("scripts/settings.js"), "\n";

echo Ht::form(hoturl_post("settings", "group=$Group"),
              ["id" => "settingsform", "class" => "need-unload-protection"]);

echo '<div class="leftmenu-left"><nav class="leftmenu-menu"><h1 class="leftmenu">Settings</h1><div class="leftmenu-list">';
foreach ($Sv->group_members("") as $gj) {
    if ($gj->name === $Group) {
        echo '<div class="leftmenu-item active">', $gj->title, '</div>';
    } else if ($gj->title) {
        echo '<div class="leftmenu-item ui js-click-child">',
            '<a href="', hoturl("settings", "group={$gj->name}"), '">', $gj->title, '</a></div>';
    }
}
echo '</div><div class="leftmenu-if-left if-alert mt-5">',
    Ht::submit("update", "Save changes", ["class" => "btn-primary"]),
    "</div></nav></div>\n",
    '<main class="leftmenu-content main-column">',
    '<h2 class="leftmenu">', $Sv->group_title($Group), '</h2>';

$Sv->report(isset($Qreq->update) && $Qreq->post_ok());
$Sv->render_group(strtolower($Group), ["top" => true]);


echo '<div class="aab aabig mt-7">',
    '<div class="aabut">', Ht::submit("update", "Save changes", ["class" => "btn-primary"]), '</div>',
    '<div class="aabut">', Ht::submit("cancel", "Cancel", ["formnovalidate" => true]), '</div>',
    '<hr class="c"></div></main></form>', "\n";

Ht::stash_script('hiliter_children("#settingsform")');
$Conf->footer();
