<?php
// mergeaccounts.php -- HotCRP account merging page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
if (!$Me->email)
    $Me->escape();
$MergeError = "";

function crpmerge($MiniMe) {
    global $Conf, $Me, $MergeError;

    if (!$MiniMe->contactId && !$Me->contactId)
        return ($MergeError = "Neither of those accounts has any data associated with this conference.");
    // XXX `act as` merging might be useful?
    if (strcasecmp($Me->email, $_SESSION["trueuser"]->email) != 0)
        return ($MergeError = "You can’t merge accounts when acting as a different user.");
    if ($MiniMe->data("locked") || $Me->data("locked"))
        return ($MergeError = "Attempt to merge a locked account.");

    // determine old & new users
    if (req("prefer"))
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

if (isset($_REQUEST["merge"]) && check_post()) {
    if (!$_REQUEST["email"])
        $MergeError = "Enter an email address to merge.";
    else if (!$_REQUEST["password"])
        $MergeError = "Enter the password of the account to merge.";
    else {
        $MiniMe = $Conf->user_by_email($_REQUEST["email"]);
        if (!$MiniMe)
            $MergeError = "No account for " . htmlspecialchars($_REQUEST["email"]) . " exists.  Did you enter the correct email address?";
        else if (!$MiniMe->check_password($_REQUEST["password"]))
            $MergeError = "That password is incorrect.";
        else if ($MiniMe->contactId == $Me->contactId) {
            $Conf->confirmMsg("Accounts successfully merged.");
            go(hoturl("index"));
        } else
            crpmerge($MiniMe);
    }
}

$Conf->header("Merge accounts", "mergeaccounts", actionBar());


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

echo "<form method='post' action=\"", hoturl_post("mergeaccounts"), "\" accept-charset='UTF-8'>\n";

// Try to prevent glasses interactions from screwing up merges
echo Ht::hidden("actas", $Me->contactId);
?>

<table class='form'>

<tr>
  <td class='caption'>Email</td>
  <td class='entry'><input type='text' name='email' size='50'
    <?php if (isset($_REQUEST["email"])) echo "value=\"", htmlspecialchars($_REQUEST["email"]), "\" "; ?>
  /></td>
</tr>

<tr>
  <td class='caption'>Password</td>
  <td class='entry'><input type='password' name='password' size='50' /></td>
</tr>

<tr>
  <td class='caption'></td>
  <td class='entry'><?php
    echo Ht::radio("prefer", 0, true), "&nbsp;", Ht::label("Keep my current account (" . htmlspecialchars($Me->email) . ")"), "<br />\n",
        Ht::radio("prefer", 1), "&nbsp;", Ht::label("Keep the account named above and delete my current account");
  ?></td>
</tr>

<tr><td class='caption'></td><td class='entry'><?php
    echo Ht::submit("merge", "Merge accounts");
?></td></tr>
<tr class='last'><td class='caption'></td></tr>
</table>
</form>


<?php $Conf->footer();
