<?php
// resetpassword.php -- HotCRP password reset page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if (!isset($_REQUEST["resetcap"])
    && preg_match(',\A/(U?1[-\w]+)(?:/|\z),i', Navigation::path(), $m))
    $_REQUEST["resetcap"] = $m[1];

if (!isset($_REQUEST["resetcap"]))
    error_go(false, "You didn’t enter the full password reset link into your browser. Make sure you include the reset code (the string of letters, numbers, and other characters at the end).");

$iscdb = substr($_REQUEST["resetcap"], 0, 1) === "U";
$capmgr = $Conf->capability_manager($_REQUEST["resetcap"]);
$capdata = $capmgr->check($_REQUEST["resetcap"]);
if (!$capdata || $capdata->capabilityType != CAPTYPE_RESETPASSWORD)
    error_go(false, "That password reset code has expired, or you didn’t enter it correctly.");

if ($iscdb)
    $Acct = Contact::contactdb_find_by_id($capdata->contactId);
else
    $Acct = Contact::find_by_id($capdata->contactId);
if (!$Acct)
    error_go(false, "That password reset code refers to a user who no longer exists. Either create a new account or contact the conference administrator.");

if (isset($Opt["ldapLogin"]) || isset($Opt["httpAuthLogin"]))
    error_go(false, "Password reset links aren’t used for this conference. Contact your system administrator if you’ve forgotten your password.");

// don't show information about the current user, if there is one
$Me = new Contact;

$password_class = "";
if (isset($_REQUEST["go"]) && check_post()) {
    if (defval($_REQUEST, "useauto") == "y")
        $_REQUEST["upassword"] = $_REQUEST["upassword2"] = $_REQUEST["autopassword"];
    if (!isset($_REQUEST["upassword"]) || $_REQUEST["upassword"] == "")
        $Conf->errorMsg("You must enter a password.");
    else if ($_REQUEST["upassword"] !== $_REQUEST["upassword2"])
        $Conf->errorMsg("The two passwords you entered did not match.");
    else if (!Contact::valid_password($_REQUEST["upassword"]))
        $Conf->errorMsg("Invalid password.");
    else {
        $Acct->change_password($_REQUEST["upassword"], true);
        $Acct->log_activity("Reset password");
        $Conf->confirmMsg("Your password has been changed. You may now sign in to the conference site.");
        $capmgr->delete($capdata);
        $Conf->save_session("password_reset", (object) array("time" => $Now, "email" => $Acct->email, "password" => $_REQUEST["upassword"]));
        go(hoturl("index"));
    }
    $password_class = " error";
}

$Conf->header("Reset Password", "resetpassword", null);

if (!isset($_REQUEST["autopassword"])
    || trim($_REQUEST["autopassword"]) != $_REQUEST["autopassword"]
    || strlen($_REQUEST["autopassword"]) < 16
    || !preg_match("/\\A[-0-9A-Za-z@_+=]*\\z/", $_REQUEST["autopassword"]))
    $_REQUEST["autopassword"] = Contact::random_password();
if (!isset($_REQUEST["useauto"]) || $_REQUEST["useauto"] != "n")
    $_REQUEST["useauto"] = "y";

$confname = $Opt["longName"];
if ($Opt["shortName"] && $Opt["shortName"] != $Opt["longName"])
    $confname .= " (" . $Opt["shortName"] . ")";
echo "<div class='homegrp'>
Welcome to the ", htmlspecialchars($confname), " submissions site.";
if (isset($Opt["conferenceSite"]))
    echo " For general information about ", htmlspecialchars($Opt["shortName"]), ", see <a href=\"", htmlspecialchars($Opt["conferenceSite"]), "\">the conference site</a>.";

echo "</div>
<hr class='home' />
<div class='homegrp' id='homereset'>\n",
    Ht::form(hoturl_post("resetpassword")),
    '<div class="f-contain">',
    Ht::hidden("resetcap", $_REQUEST["resetcap"]),
    Ht::hidden("autopassword", $_REQUEST["autopassword"]),
    "<p>This form will reset the password for <b>", htmlspecialchars($Acct->email), "</b>. Use our suggested replacement password, or choose your own.</p>
<table>
  <tr><td>",
    Ht::radio("useauto", "y", null),
    "&nbsp;</td><td>", Ht::label("Use password <tt>" . htmlspecialchars($_REQUEST["autopassword"]) . "</tt>"),
    "</td></tr>
  <tr><td>",
    Ht::radio("useauto", "n", null, array("id" => "usemy", "onclick" => "x=\$\$(\"login_d\");if(document.activeElement!=x)x.focus()")),
    "&nbsp;</td><td style='padding-top:1em'>", Ht::label("Use this password:"), "</td></tr>
  <tr><td></td><td><div class='f-i'>
  <div class='f-c", $password_class, "'>Password</div>
  <div class='f-e'><input id='login_d' type='password' name='upassword' size='36' tabindex='1' value='' onkeypress='if(!((x=\$\$(\"usemy\")).checked)) x.click()' /></div>
</div>
<div class='f-i'>
  <div class='f-c", $password_class, "'>Password (again)</div>
  <div class='f-e'><input id='login_d' type='password' name='upassword2' size='36' tabindex='1' value='' /></div>
</div></td></tr>
<tr><td colspan='2' style='padding-top:1em'>
<div class='f-i'>",
    Ht::submit("go", "Reset password", array("tabindex" => 1)),
    "</div></td>
</tr></table>
</div></form>
<hr class='home' /></div>\n";
$Conf->footerScript("crpfocus(\"login\", null, 2)");

echo "<div class='clear'></div>\n";
$Conf->footer();
