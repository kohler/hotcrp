<?php
// mergeaccounts.php -- HotCRP account merging page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
if (!$Me->email)
    $Me->escape();
$MergeError = "";

function crpmerge($qreq, $MiniMe) {
    global $Conf, $Me, $MergeError;

    if (!$MiniMe->contactId && !$Me->contactId)
        return ($MergeError = "Neither of those accounts has any data associated with this conference.");
    // XXX `act as` merging might be useful?
    if (strcasecmp($Me->email, $_SESSION["trueuser"]->email) != 0)
        return ($MergeError = "You can’t merge accounts when acting as a different user.");
    if ($MiniMe->data("locked") || $Me->data("locked"))
        return ($MergeError = "Attempt to merge a locked account.");

    // determine old & new users
    if ($qreq->prefer)
        $merger = new MergeContacts($Me, $MiniMe);
    else
        $merger = new MergeContacts($MiniMe, $Me);

    // send mail at start of process
    HotCRPMailer::send_to($merger->oldu, "@mergeaccount", null,
                          array("cc" => Text::user_email_to($merger->newu),
                                "other_contact" => $merger->newu));

    // actually merge users or change email
    $merger->run();

    // update trueuser
    if (strcasecmp($_SESSION["trueuser"]->email, $merger->newu->email) != 0)
        $_SESSION["trueuser"] = (object) ["email" => $merger->newu->email];

    if (!$merger->has_error()) {
        $Conf->confirmMsg("Merged account " . htmlspecialchars($merger->oldu->email) . ".");
        $merger->newu->log_activity("Merged account " . $merger->oldu->email);
        go(hoturl("index"));
    } else {
        $merger->newu->log_activity("Merged account " . $merger->oldu->email . " with errors");
        $MergeError = '<div class="multimessage">'
            . join("\n", array_map(function ($m) { return '<div class="mmm">' . $m . '</div>'; },
                                   $merger->errors()))
            . '</div>';
    }
}

if (isset($Qreq->merge) && $Qreq->post_ok()) {
    if (!$Qreq->email) {
        $MergeError = "Enter an email address to merge.";
        Ht::set_control_class("email", "has-error");
    } else if (!$Qreq->password) {
        $MergeError = "Enter the password of the account to merge.";
        Ht::set_control_class("password", "has-error");
    } else {
        $MiniMe = $Conf->user_by_email($Qreq->email);
        if (!$MiniMe) {
            $MergeError = "No account for " . htmlspecialchars($Qreq->email) . " exists.  Did you enter the correct email address?";
            Ht::set_control_class("email", "has-error");
        } else if (!$MiniMe->check_password($Qreq->password)) {
            $MergeError = "That password is incorrect.";
            Ht::set_control_class("password", "has-error");
        } else if ($MiniMe->contactId == $Me->contactId) {
            $Conf->confirmMsg("Accounts successfully merged.");
            go(hoturl("index"));
        } else
            crpmerge($Qreq, $MiniMe);
    }
}

$Conf->header("Merge accounts", "mergeaccounts");


if ($MergeError)
    Conf::msg_error($MergeError);
else
    $Conf->infoMsg(
"You may have multiple accounts registered with the "
. $Conf->short_name . " conference; perhaps "
. "multiple people asked you to review a paper using "
. "different email addresses. "
. "If you have been informed of multiple accounts, "
. "enter the email address and the password "
. "of the secondary account. This will merge all the information from "
. "that account into this one. "
);

echo Ht::form(hoturl_post("mergeaccounts"));

// Try to prevent glasses interactions from screwing up merges
echo Ht::hidden("actas", $Me->contactId);

echo '<div class="', Ht::control_class("email", "f-i"), '">',
    Ht::label("Other email", "merge_email"),
    Ht::entry("email", (string) $Qreq->email,
              ["size" => 36, "id" => "merge_email", "autocomplete" => "username", "tabindex" => 1]),
    '</div>
<div class="', Ht::control_class("password", "f-i fx"), '">',
    Ht::label("Other password", "merge_password"),
    Ht::password("password", "",
                 ["size" => 36, "id" => "merge_password", "autocomplete" => "current-password", "tabindex" => 1]),
    '</div>
<div class="f-i">',
    '<div class="checki"><label><span class="checkc">',
    Ht::radio("prefer", 0, true), '</span>',
    "Keep my current account (", htmlspecialchars($Me->email), ")</label></div>",
    '<div class="checki"><label><span class="checkc">',
    Ht::radio("prefer", 1), '</span>',
    "Keep the account named above and delete my current account</label></div>",
    '</div>',
    Ht::actions([Ht::submit("merge", "Merge accounts", ["class" => "btn btn-primary"])]),
    '</form>';


$Conf->footer();
