<?php
// settings.php -- HotCRP chair-only conference settings management page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
if (!$Me->privChair)
    $Me->escape();

require_once("src/settingvalues.php");
$Sv = SettingValues::make_request($Me, $Qreq);
$Sv->session_highlight();

function choose_setting_group($qreq, SettingValues $sv) {
    global $Me;
    $req_group = $qreq->group;
    if (!$req_group && preg_match(',\A/\w+\z,', Navigation::path()))
        $req_group = substr(Navigation::path(), 1);
    $want_group = $req_group;
    if (!$want_group && isset($_SESSION["sg"])) // NB not conf-specific session, global
        $want_group = $_SESSION["sg"];
    $want_group = $sv->canonical_group($want_group);
    if (!$want_group || !$sv->is_titled_group($want_group)) {
        if ($sv->conf->can_some_author_view_review())
            $want_group = $sv->canonical_group("decisions");
        else if ($sv->conf->deadlinesAfter("sub_sub") || $sv->conf->time_review_open())
            $want_group = $sv->canonical_group("reviews");
        else
            $want_group = $sv->canonical_group("sub");
    }
    if (!$want_group)
        $Me->escape();
    if ($want_group !== $req_group && !$qreq->post && $qreq->post_empty())
        SelfHref::redirect($qreq, ["group" => $want_group]);
    $sv->mark_interesting_group($want_group);
    return $want_group;
}
$Group = $Qreq->group = choose_setting_group($Qreq, $Sv);
$_SESSION["sg"] = $Group;

if (isset($Qreq->update) && $Qreq->post_ok()) {
    if ($Sv->execute()) {
        $Sv->conf->save_session("settings_highlight", $Sv->message_field_map());
        if (!empty($Sv->changes))
            $Sv->conf->confirmMsg("Changes saved.");
        else
            $Sv->conf->warnMsg("No changes.");
        $Sv->report();
        SelfHref::redirect($Qreq);
    }
}
if (isset($Qreq->cancel) && $Qreq->post_ok())
    SelfHref::redirect($Qreq);

$Sv->crosscheck();

$group_titles = $Sv->group_titles();
$Conf->header("Settings &nbsp;&#x2215;&nbsp; <strong>" . $group_titles[$Group] . "</strong>", "settings");
echo Ht::unstash(); // clear out other script references
echo $Conf->make_script_file("scripts/settings.js"), "\n";

echo Ht::form(hoturl_post("settings", "group=$Group"), array("id" => "settingsform"));

echo '<div class="leftmenu-menu-container"><div class="leftmenu-list">';
foreach ($group_titles as $name => $title) {
    if ($name === $Group)
        echo '<div class="leftmenu-item-on">', $title, '</div>';
    else
        echo '<div class="leftmenu-item ui js-click-child">',
            '<a href="', hoturl("settings", "group={$name}"), '">', $title, '</a></div>';
}
echo "</div></div>\n",
    '<div class="leftmenu-content-container"><div class="leftmenu-content">';

function doActionArea($top) {
    echo '<div class="aab aabr aabig">',
        '<div class="aabut">', Ht::submit("update", "Save changes", ["class" => "btn btn-primary"]), '</div>',
        '<div class="aabut">', Ht::submit("cancel", "Cancel"), '</div>',
        '<hr class="c" /></div>';
}

doActionArea(true);

$Sv->report(isset($Qreq->update) && $Qreq->post_ok());
$Sv->render_group($Group);

doActionArea(false);
echo "</div></div></form>\n";

Ht::stash_script('hiliter_children("#settingsform", true)');
$Conf->footer();
