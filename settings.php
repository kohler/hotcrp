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

function choose_setting_group() {
    global $Conf;
    $req_group = req("group");
    if (!$req_group && preg_match(',\A/\w+\z,', Navigation::path()))
        $req_group = substr(Navigation::path(), 1);
    $want_group = $req_group;
    if (!$want_group && isset($_SESSION["sg"])) // NB not conf-specific session, global
        $want_group = $_SESSION["sg"];
    if (isset(SettingGroup::$map[$want_group]))
        $want_group = SettingGroup::$map[$want_group];
    if (!isset(SettingGroup::$all[$want_group])) {
        if ($Conf->timeAuthorViewReviews())
            $want_group = "decisions";
        else if ($Conf->deadlinesAfter("sub_sub") || $Conf->time_review_open())
            $want_group = "reviews";
        else
            $want_group = "sub";
    }
    if ($want_group != $req_group && empty($_POST) && !req("post"))
        redirectSelf(["group" => $want_group]);
    return $want_group;
}
$Group = $_REQUEST["group"] = $_GET["group"] = choose_setting_group();
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

SettingGroup::crosscheck($Sv, $Group);

$Conf->header("Settings &nbsp;&#x2215;&nbsp; <strong>" . SettingGroup::$all[$Group]->description . "</strong>", "settings", actionBar());
echo Ht::unstash(); // clear out other script references
echo $Conf->make_script_file("scripts/settings.js"), "\n";

echo Ht::form(hoturl_post("settings", "group=$Group"), array("id" => "settingsform"));

echo '<div class="leftmenu_menucontainer"><div class="leftmenu_list">';
foreach (SettingGroup::all() as $g) {
    if ($g->name === $Group)
        echo '<div class="leftmenu_item_on">', $g->description, '</div>';
    else
        echo '<div class="leftmenu_item">',
            '<a href="', hoturl("settings", "group={$g->name}"), '">', $g->description, '</a></div>';
}
echo "</div></div>\n",
    '<div class="leftmenu_content_container"><div class="leftmenu_content">',
    '<div class="leftmenu_body">';
Ht::stash_script("jQuery(\".leftmenu_item\").click(divclick)");

function doActionArea($top) {
    echo '<div class="aab aabr aabig">',
        '<div class="aabut">', Ht::submit("update", "Save changes", ["class" => "btn btn-default"]), '</div>',
        '<div class="aabut">', Ht::submit("cancel", "Cancel"), '</div>',
        '<hr class="c" /></div>';
}

echo '<div class="aahc">';
doActionArea(true);

echo "<div>";
$Sv->report(isset($_REQUEST["update"]) && check_post());
$Sv->interesting_groups[$Group] = true;
SettingGroup::$all[$Group]->render($Sv);
echo "</div>";

doActionArea(false);
echo "</div></div></div></div></form>\n";

Ht::stash_script("hiliter_children('#settingsform');jQuery('textarea').autogrow()");
$Conf->footer();
