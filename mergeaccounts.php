<?php
// mergeaccounts.php -- HotCRP account merging page
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
if (!$Me->email) {
    $Me->escape();
}
$MergeError = "";

function crpmerge($qreq, $MiniMe) {
    global $Conf, $Me, $MergeError;

    if (!$MiniMe->contactId && !$Me->contactId) {
        return ($MergeError = "Neither of those accounts has any data associated with this conference.");
    }
    // XXX `act as` merging might be useful?
    if ($Me->is_actas_user()) {
        return ($MergeError = "You can’t merge accounts when acting as a different user.");
    }
    if ($MiniMe->data("locked") || $Me->data("locked")) {
        return ($MergeError = "Attempt to merge a locked account.");
    }

    // determine old & new users
    if ($qreq->prefer) {
        $merger = new MergeContacts($Me, $MiniMe);
    } else {
        $merger = new MergeContacts($MiniMe, $Me);
    }

    // send mail at start of process
    HotCRPMailer::send_to($merger->oldu, "@mergeaccount",
                          ["cc" => Text::nameo($merger->newu, NAME_MAILQUOTE|NAME_E),
                           "other_contact" => $merger->newu]);

    // actually merge users or change email
    $merger->run();

    if (!$merger->has_error()) {
        $Conf->confirmMsg("Merged account " . htmlspecialchars($merger->oldu->email) . ".");
        $merger->newu->log_activity("Account merged " . $merger->oldu->email);
        $Conf->redirect();
    } else {
        $merger->newu->log_activity("Account merged " . $merger->oldu->email . " with errors");
        $MergeError = '<div class="multimessage">'
            . join("\n", array_map(function ($m) { return '<div class="mmm">' . $m . '</div>'; },
                                   $merger->error_texts()))
            . '</div>';
    }
}

if (isset($Qreq->merge) && $Qreq->valid_post()) {
    if (!$Qreq->email) {
        $MergeError = "Enter an email address to merge.";
        Ht::error_at("email");
    } else if (!$Qreq->password) {
        $MergeError = "Enter the password of the account to merge.";
        Ht::error_at("password");
    } else {
        $MiniMe = $Conf->user_by_email($Qreq->email);
        if (!$MiniMe) {
            $MiniMe = $Conf->contactdb_user_by_email($Qreq->email);
        }
        if (!$MiniMe) {
            $MergeError = "No account for " . htmlspecialchars($Qreq->email) . " exists.  Did you enter the correct email address?";
            Ht::error_at("email");
        } else if (!$MiniMe->check_password($Qreq->password)) {
            $MergeError = "That password is incorrect.";
            Ht::error_at("password");
        } else if ($MiniMe->contactId && $MiniMe->contactId == $Me->contactId) {
            $Conf->confirmMsg("Accounts successfully merged.");
            $Conf->redirect();
        } else {
            crpmerge($Qreq, $MiniMe);
        }
    }
}

$Conf->header("Merge accounts", "mergeaccounts");


if ($MergeError) {
    Conf::msg_error($MergeError);
} else {
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
}

echo Ht::form($Conf->hoturl_post("mergeaccounts"));

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
    Ht::actions([Ht::submit("merge", "Merge accounts", ["class" => "btn-primary"])]),
    '</form>';


$Conf->footer();
