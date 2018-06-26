<?php
// resetpassword.php -- HotCRP password reset page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");

if ($Conf->external_login())
    error_go(false, "Password reset links aren’t used for this conference. Contact your system administrator if you’ve forgotten your password.");

$resetcap = $Qreq->resetcap;
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
    $Acct = $Conf->contactdb_user_by_id($capdata->contactId);
else
    $Acct = $Conf->user_by_id($capdata->contactId);
if (!$Acct)
    error_go(false, "That password reset code refers to a user who no longer exists. Either create a new account or contact the conference administrator.");

// don't show information about the current user, if there is one
$Me = new Contact;

if (isset($Qreq->go) && $Qreq->post_ok()) {
    $Qreq->password = trim((string) $Qreq->password);
    $Qreq->password2 = trim((string) $Qreq->password2);
    if ($Qreq->password == "") {
        Conf::msg_error("You must enter a password.");
    } else if (!Contact::valid_password($Qreq->password)) {
        Conf::msg_error("Invalid password.");
    } else if ($Qreq->password !== $Qreq->password2) {
        Conf::msg_error("The two passwords you entered did not match.");
    } else {
        $flags = 0;
        if ($Qreq->password === $Qreq->autopassword)
            $flags |= Contact::CHANGE_PASSWORD_PLAINTEXT;
        $Acct->change_password($Qreq->password, $flags);
        if (!$iscdb || !($log_acct = $Conf->user_by_email($Acct->email)))
            $log_acct = $Acct;
        $log_acct->log_activity("Password reset via " . substr($resetcap, 0, 8) . "...");
        $Conf->confirmMsg("Your password has been changed. You may now sign in to the conference site.");
        $capmgr->delete($capdata);
        $Conf->save_session("password_reset", (object) array("time" => $Now, "email" => $Acct->email, "password" => $Qreq->password));
        go(hoturl("index"));
    }
    Ht::error_at("password");
}

$Conf->header("Reset password", "resetpassword", ["action_bar" => false]);

if (!isset($Qreq->autopassword)
    || trim($Qreq->autopassword) !== $Qreq->autopassword
    || strlen($Qreq->autopassword) < 16
    || !preg_match("/\\A[-0-9A-Za-z@_+=]*\\z/", $Qreq->autopassword))
    $Qreq->autopassword = Contact::random_password();

echo "<div class='homegrp'>
Welcome to the ", htmlspecialchars($Conf->full_name()), " submissions site.";
if ($Conf->opt("conferenceSite"))
    echo " For general information about ", htmlspecialchars($Conf->short_name), ", see <a href=\"", htmlspecialchars($Conf->opt("conferenceSite")), "\">the conference site</a>.";

echo "</div>
<hr class='home' />
<div class='homegrp' id='homereset'>\n",
    Ht::form(hoturl_post("resetpassword")),
    '<div class="f-contain">',
    Ht::hidden("resetcap", $resetcap),
    Ht::hidden("autopassword", $Qreq->autopassword),
    "<p>Use this form to reset your password. You may want to use the random password we’ve chosen.</p>",
    '<div class="f-i"><label>Email</label>', htmlspecialchars($Acct->email), '</div>',
    '<div class="f-i"><label>Suggested password</label>',
    htmlspecialchars($Qreq->autopassword), '</div>';
echo '<div class="', Ht::control_class("password", "f-i"), '">
  <label for="reset_password">New password</label>',
    Ht::password("password", "", ["class" => "want-focus", "tabindex" => 1, "size" => 36, "id" => "reset_password", "autocomplete" => "new-password"]), '</div>
<div class="', Ht::control_class("password", "f-i"), '">
  <label for="reset_password2">New password (again)</label>',
    Ht::password("password2", "", ["tabindex" => 1, "size" => 36, "id" => "reset_password2", "autocomplete" => "new-password"]), '</div>
<div class="f-i" style="margin-top:2em">',
    Ht::submit("go", "Reset password", ["class" => "btn btn-primary"]),
    "</div>
</div></form>
<hr class='home' /></div>\n";
Ht::stash_script("focus_within(\$(\"#homereset\"));window.scroll(0,0)");

echo '<hr class="c" />', "\n";
$Conf->footer();
