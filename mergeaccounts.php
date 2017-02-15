<?php
// mergeaccounts.php -- HotCRP account merging page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if (!$Me->email)
    $Me->escape();
$MergeError = "";

function crpmergeone($table, $field, $oldid, $newid) {
    global $Conf, $MergeError;
    if (!$Conf->q_raw("update $table set $field=$newid where $field=$oldid"))
        $MergeError .= $Conf->db_error_html(true);
}

function crpmergeoneignore($table, $field, $oldid, $newid) {
    global $Conf, $MergeError;
    if (!$Conf->q_raw("update ignore $table set $field=$newid where $field=$oldid")
        && !$Conf->q_raw("delete from $table where $field=$oldid"))
        $MergeError .= $Conf->db_error_html(true);
}

function crpmerge_database($old_user, $new_user) {
    global $Conf, $MergeError;
    // Now, scan through all the tables that possibly
    // specify a contactID and change it from their 2nd
    // contactID to their first contactId
    $oldid = $old_user->contactId;
    $newid = $new_user->contactId;

    $Conf->q_raw("lock tables Paper write, ContactInfo write, PaperConflict write, ActionLog write, TopicInterest write, PaperComment write, PaperReview write, PaperReview as B write, PaperReviewPreference write, PaperReviewRefused write, ReviewRequest write, PaperWatch write, ReviewRating write");

    crpmergeone("Paper", "leadContactId", $oldid, $newid);
    crpmergeone("Paper", "shepherdContactId", $oldid, $newid);
    crpmergeone("Paper", "managerContactId", $oldid, $newid);

    // paper authorship
    $result = $Conf->qe_raw("select paperId, authorInformation from Paper where authorInformation like " . Dbl::utf8ci("'%\t" . sqlq_for_like($old_user->email) . "\t%'"));
    $qs = array();
    while (($row = PaperInfo::fetch($result, null, $Conf))) {
        foreach ($row->author_list() as $au)
            if (strcasecmp($au->email, $old_user->email) == 0)
                $au->email = $new_user->email;
        $qs[] = "update Paper set authorInformation='" . sqlq($row->parse_author_list()) . "' where paperId=$row->paperId";
    }
    foreach ($qs as $q)
        $Conf->qe_raw($q);

    // ensure uniqueness in PaperConflict
    $result = $Conf->qe_raw("select paperId, conflictType from PaperConflict where contactId=$oldid");
    $values = "";
    while (($row = edb_row($result)))
        $values .= ", ($row[0], $newid, $row[1])";
    if ($values)
        $Conf->qe_raw("insert into PaperConflict (paperId, contactId, conflictType) values " . substr($values, 2) . " on duplicate key update conflictType=greatest(conflictType, values(conflictType))");
    $Conf->qe_raw("delete from PaperConflict where contactId=$oldid");

    if (($old_user->roles | $new_user->roles) != $new_user->roles) {
        $new_user->roles |= $old_user->roles;
        $Conf->qe_raw("update ContactInfo set roles=$new_user->roles where contactId=$newid");
    }

    crpmergeone("ActionLog", "contactId", $oldid, $newid);
    crpmergeoneignore("TopicInterest", "contactId", $oldid, $newid);
    crpmergeone("PaperComment", "contactId", $oldid, $newid);

    // archive duplicate reviews
    crpmergeoneignore("PaperReview", "contactId", $oldid, $newid);
    crpmergeone("PaperReview", "requestedBy", $oldid, $newid);
    crpmergeoneignore("PaperReviewPreference", "contactId", $oldid, $newid);
    crpmergeone("PaperReviewRefused", "contactId", $oldid, $newid);
    crpmergeone("PaperReviewRefused", "requestedBy", $oldid, $newid);
    crpmergeone("ReviewRequest", "requestedBy", $oldid, $newid);
    crpmergeoneignore("PaperWatch", "contactId", $oldid, $newid);
    crpmergeoneignore("ReviewRating", "contactId", $oldid, $newid);

    // Remove the old contact record
    if ($MergeError == "") {
        if (!$Conf->q_raw("delete from ContactInfo where contactId=$oldid"))
            $MergeError .= $Conf->db_error_html(true);
    }

    $Conf->qe_raw("unlock tables");

    // Update PC settings if we need to
    if ($old_user->isPC)
        $Conf->invalidate_caches(["pc" => 1]);
}

function crpmerge($MiniMe) {
    global $Conf, $Me, $MergeError;

    if (!$MiniMe->contactId && !$Me->contactId)
        return ($MergeError = "Neither of those accounts has any data associated with this conference.");
    // XXX `act as` merging might be useful?
    if (strcasecmp($Me->email, $_SESSION["trueuser"]->email))
        return ($MergeError = "You can’t merge accounts when acting as a different user.");
    if ($MiniMe->data("locked") || $Me->data("locked"))
        return ($MergeError = "Attempt to merge a locked account.");

    // determine old & new users
    if (@$_REQUEST["prefer"])
        list($old_user, $new_user) = [$Me, $MiniMe];
    else
        list($old_user, $new_user) = [$MiniMe, $Me];

    // send mail at start of process
    HotCRPMailer::send_to($old_user, "@mergeaccount", null,
                          array("cc" => Text::user_email_to($new_user),
                                "other_contact" => $new_user));

    // actually merge users or change email
    if ($old_user->contactId && $new_user->contactId)
        crpmerge_database($old_user, $new_user);
    else if ($old_user->contactId) {
        $user_status = new UserStatus(["send_email" => false]);
        $user_status->save($user_status->user_to_json($new_user), $old_user);
    }

    // update trueuser
    if (strcasecmp($_SESSION["trueuser"]->email, $new_user->email))
        $_SESSION["trueuser"] = (object) ["email" => $new_user->email];

    if ($MergeError == "") {
        $Conf->confirmMsg("Merged account " . htmlspecialchars($old_user->email) . ".");
        $new_user->log_activity("Merged account $old_user->email");
        go(hoturl("index"));
    } else {
        $new_user->log_activity("Merged account $old_user->email with errors");
        $MergeError .= $Conf->db_error_html(true);
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
