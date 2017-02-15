<?php
// resetpassword.php -- HotCRP password reset page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");

if ($Conf->external_login())
    error_go(false, "Password reset links aren’t used for this conference. Contact your system administrator if you’ve forgotten your password.");

$resetcap = req("resetcap");
if ($resetcap === null && preg_match(',\A/(U?1[-\w]+)(?:/|\z),i', Navigation::path(), $m))
    $resetcap = $m[1];
if (!$resetcap)
    error_go(false, "You didn’t enter the full password reset link into your browser. Make sure you include the reset code (the string of letters, numbers, and other characters at the end).");

$iscdb = substr($resetcap, 0, 1) === "U";
$capmgr = $Conf->capability_manager($resetcap);
$capdata = $capmgr->check($resetcap);
if (!$capdata || $capdata->capabilityType != CAPTYPE_RESETPASSWORD)
    error_go(false, "That password reset code has expired, or you didn’t enter it correctly.");

if ($iscdb)
    $Acct = Contact::contactdb_find_by_id($capdata->contactId);
else
    $Acct = $Conf->user_by_id($capdata->contactId);
if (!$Acct)
    error_go(false, "That password reset code refers to a user who no longer exists. Either create a new account or contact the conference administrator.");

// don't show information about the current user, if there is one
$Me = new Contact;

$password_class = "";
if (isset($_POST["go"]) && check_post()) {
    $_POST["password"] = trim((string) @$_POST["password"]);
    $_POST["password2"] = trim((string) @$_POST["password2"]);
    if ($_POST["password"] == "")
        Conf::msg_error("You must enter a password.");
    else if ($_POST["password"] !== $_POST["password2"])
        Conf::msg_error("The two passwords you entered did not match.");
    else if (!Contact::valid_password($_POST["password"]))
        Conf::msg_error("Invalid password.");
    else {
        $flags = 0;
        if ($_POST["password"] === @$_POST["autopassword"])
            $flags |= Contact::CHANGE_PASSWORD_PLAINTEXT;
        $Acct->change_password(null, $_POST["password"], $flags);
        if (!$iscdb || !($log_acct = $Conf->user_by_email($Acct->email)))
            $log_acct = $Acct;
        $log_acct->log_activity("Password reset via " . substr($resetcap, 0, 8) . "...");
        $Conf->confirmMsg("Your password has been changed. You may now sign in to the conference site.");
        $capmgr->delete($capdata);
        $Conf->save_session("password_reset", (object) array("time" => $Now, "email" => $Acct->email, "password" => $_POST["password"]));
        go(hoturl("index"));
    }
    $password_class = " error";
}

$Conf->header("Reset password", "resetpassword", false);

if (!isset($_POST["autopassword"])
    || trim($_POST["autopassword"]) != $_POST["autopassword"]
    || strlen($_POST["autopassword"]) < 16
    || !preg_match("/\\A[-0-9A-Za-z@_+=]*\\z/", $_POST["autopassword"]))
    $_POST["autopassword"] = Contact::random_password();

echo "<div class='homegrp'>
Welcome to the ", htmlspecialchars($Conf->full_name()), " submissions site.";
if (opt("conferenceSite"))
    echo " For general information about ", htmlspecialchars($Conf->short_name), ", see <a href=\"", htmlspecialchars(opt("conferenceSite")), "\">the conference site</a>.";

echo "</div>
<hr class='home' />
<div class='homegrp' id='homereset'>\n",
    Ht::form(hoturl_post("resetpassword")),
    '<div class="f-contain">',
    Ht::hidden("resetcap", $resetcap),
    Ht::hidden("autopassword", $_POST["autopassword"]),
    "<p>Use this form to reset your password. You may want to use the random password we’ve chosen.</p>";
echo '<table style="margin-bottom:2em">',
    '<tr><td class="lcaption">Your email</td><td>', htmlspecialchars($Acct->email), '</td></tr>
<tr><td class="lcaption">Suggested password</td><td>', htmlspecialchars($_POST["autopassword"]), '</td></tr></table>';
//Our suggested replacement password password for <b>", htmlspecialchars($Acct->email), "</b>. Use our suggested replacement password, or choose your own.</p>",
echo '<div class="f-i">
  <div class="f-c', $password_class, '">New password</div>
  <div class="f-e">', Ht::password("password", "", array("class" => "want-focus", "tabindex" => 1, "size" => 36)), '</div>
</div>
<div class="f-i">
  <div class="f-c', $password_class, '">New password (again)</div>
  <div class="f-e">', Ht::password("password2", "", array("tabindex" => 1, "size" => 36)), '</div>
</div>
<div class="f-i" style="margin-top:2em">',
    Ht::submit("go", "Reset password", array("tabindex" => 1)),
    "</div>
</div></form>
<hr class='home' /></div>\n";
Ht::stash_script("crpfocus(\"login\", null, 2)");

echo '<hr class="c" />', "\n";
$Conf->footer();
