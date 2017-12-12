<?php
// settings.php -- HotCRP chair-only conference settings management page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if (!$Me->privChair)
    $Me->escape();

require_once("src/settingvalues.php");
$Sv = SettingValues::make_request($Me, $_POST, $_FILES);
$Sv->session_highlight();

function choose_setting_group(SettingValues $sv) {
    global $Me;
    $req_group = req("group");
    if (!$req_group && preg_match(',\A/\w+\z,', Navigation::path()))
        $req_group = substr(Navigation::path(), 1);
    $want_group = $req_group;
    if (!$want_group && isset($_SESSION["sg"])) // NB not conf-specific session, global
        $want_group = $_SESSION["sg"];
    $want_group = $sv->canonical_group($want_group);
    if (!$want_group || !$sv->is_titled_group($want_group)) {
        if ($sv->conf->timeAuthorViewReviews())
            $want_group = $sv->canonical_group("decisions");
        else if ($sv->conf->deadlinesAfter("sub_sub") || $sv->conf->time_review_open())
            $want_group = $sv->canonical_group("reviews");
        else
            $want_group = $sv->canonical_group("sub");
    }
    if (!$want_group)
        $Me->escape();
    if ($want_group !== $req_group && empty($_POST) && !req("post"))
        redirectSelf(["group" => $want_group]);
    $sv->mark_interesting_group($want_group);
    return $want_group;
}
$Group = $_REQUEST["group"] = $_GET["group"] = choose_setting_group($Sv);
$_SESSION["sg"] = $Group;

if (isset($_REQUEST["update"]) && check_post()) {
    if ($Sv->execute()) {
        $Sv->conf->save_session("settings_highlight", $Sv->message_field_map());
        if (!empty($Sv->changes))
            $Sv->conf->confirmMsg("Changes saved.");
        else
            $Sv->conf->warnMsg("No changes.");
        $Sv->report();
        redirectSelf();
    }
}
if (isset($_REQUEST["cancel"]) && check_post())
    redirectSelf();

$Sv->crosscheck();

$group_titles = $Sv->group_titles();
$Conf->header("Settings &nbsp;&#x2215;&nbsp; <strong>" . $group_titles[$Group] . "</strong>", "settings", actionBar());
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
    '<div class="leftmenu-content-container"><div class="leftmenu-content aahc">';

function doActionArea($top) {
    echo '<div class="aab aabr aabig">',
        '<div class="aabut">', Ht::submit("update", "Save changes", ["class" => "btn btn-default"]), '</div>',
        '<div class="aabut">', Ht::submit("cancel", "Cancel"), '</div>',
        '<hr class="c" /></div>';
}

doActionArea(true);

$Sv->report(isset($_REQUEST["update"]) && check_post());
$Sv->render_group($Group);

doActionArea(false);
echo "</div></div></form>\n";

Ht::stash_script("hiliter_children('#settingsform')");
$Conf->footer();
