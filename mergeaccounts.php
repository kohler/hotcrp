<?php
// mergeaccounts.php -- HotCRP account merging page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if ($Me->is_empty())
    $Me->escape();
$MergeError = "";

function crpmergeone($table, $field, $oldid, $newid) {
    global $Conf, $MergeError;
    if (!$Conf->q("update $table set $field=$newid where $field=$oldid"))
        $MergeError .= $Conf->db_error_html(true);
}

function crpmergeoneignore($table, $field, $oldid, $newid) {
    global $Conf, $MergeError;
    if (!$Conf->q("update ignore $table set $field=$newid where $field=$oldid")
        && !$Conf->q("delete from $table where $field=$oldid"))
        $MergeError .= $Conf->db_error_html(true);
}

if (isset($_REQUEST["merge"]) && check_post()) {
    if (!$_REQUEST["email"])
        $MergeError = "Enter an email address to merge.";
    else if (!$_REQUEST["password"])
        $MergeError = "Enter the password of the account to merge.";
    else {
        $MiniMe = Contact::find_by_email($_REQUEST["email"]);
        if (!$MiniMe)
            $MergeError = "No account for " . htmlspecialchars($_REQUEST["email"]) . " exists.  Did you enter the correct email address?";
        else if (!$MiniMe->check_password($_REQUEST["password"]))
            $MergeError = "That password is incorrect.";
        else if ($MiniMe->contactId == $Me->contactId) {
            $Conf->confirmMsg("Accounts successfully merged.");
            go(hoturl("index"));
        } else if (!$MiniMe->contactId || !$Me->contactId)
            $MergeError = "Internal error.";
        else {
            // Do they prefer the account they named?
            if (defval($_REQUEST, 'prefer')) {
                $mm = $Me;
                $Me = $MiniMe;
                $MiniMe = $mm;
                $_SESSION["trueuser"]->contactId = $Me->contactId;
                $_SESSION["trueuser"]->dsn = $Conf->dsn;
                $_SESSION["trueuser"]->email = $Me->email;
            }

            HotCRPMailer::send_to($MiniMe, "@mergeaccount", null,
                                  array("cc" => Text::user_email_to($Me),
                                        "other_contact" => $Me));

            // Now, scan through all the tables that possibly
            // specify a contactID and change it from their 2nd
            // contactID to their first contactId
            $oldid = $MiniMe->contactId;
            $newid = $Me->contactId;

            $Conf->q("lock tables Paper write, ContactInfo write, PaperConflict write, PCMember write, ChairAssistant write, Chair write, ActionLog write, TopicInterest write, PaperComment write, PaperReview write, PaperReview as B write, PaperReviewArchive write, PaperReviewPreference write, PaperReviewRefused write, ReviewRequest write, ContactAddress write, PaperWatch write, ReviewRating write");

            crpmergeone("Paper", "leadContactId", $oldid, $newid);
            crpmergeone("Paper", "shepherdContactId", $oldid, $newid);
            crpmergeone("Paper", "managerContactId", $oldid, $newid);

            // paper authorship
            $result = $Conf->qe("select paperId, authorInformation from Paper where authorInformation like '%\t" . sqlq_for_like($MiniMe->email) . "\t%'");
            $qs = array();
            while (($row = edb_row($result))) {
                $row[1] = str_ireplace("\t" . $MiniMe->email . "\t", "\t" . $Me->email . "\t", $row[1]);
                $qs[] = "update Paper set authorInformation='" . sqlq($row[1]) . "' where paperId=$row[0]";
            }
            foreach ($qs as $q)
                $Conf->qe($q);

            // ensure uniqueness in PaperConflict
            $result = $Conf->qe("select paperId, conflictType from PaperConflict where contactId=$oldid");
            $values = "";
            while (($row = edb_row($result)))
                $values .= ", ($row[0], $newid, $row[1])";
            if ($values)
                $Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values " . substr($values, 2) . " on duplicate key update conflictType=greatest(conflictType, values(conflictType))");
            $Conf->qe("delete from PaperConflict where contactId=$oldid");

            crpmergeoneignore("PCMember", "contactId", $oldid, $newid);
            crpmergeoneignore("ChairAssistant", "contactId", $oldid, $newid);
            crpmergeoneignore("Chair", "contactId", $oldid, $newid);
            if (($MiniMe->roles | $Me->roles) != $Me->roles) {
                $Me->roles |= $MiniMe->roles;
                $Conf->qe("update ContactInfo set roles=$Me->roles where contactId=$Me->contactId");
            }

            crpmergeone("ActionLog", "contactId", $oldid, $newid);
            crpmergeoneignore("TopicInterest", "contactId", $oldid, $newid);
            crpmergeone("PaperComment", "contactId", $oldid, $newid);

            // archive duplicate reviews
            $result = $Conf->q("select PaperReview.reviewId from PaperReview join PaperReview B on (PaperReview.paperId=B.paperId and PaperReview.contactId=$oldid and B.contactId=$newid)");
            while (($row = edb_row($result))) {
                $fields = ReviewForm::reviewArchiveFields();
                if (!$Conf->q("insert into PaperReviewArchive ($fields) select $fields from PaperReview where reviewId=$row[0]"))
                    $MergeError .= $Conf->db_error_html(true);
            }
            crpmergeoneignore("PaperReview", "contactId", $oldid, $newid);
            crpmergeone("PaperReview", "requestedBy", $oldid, $newid);
            crpmergeone("PaperReviewArchive", "contactId", $oldid, $newid);
            crpmergeone("PaperReviewArchive", "requestedBy", $oldid, $newid);
            crpmergeoneignore("PaperReviewPreference", "contactId", $oldid, $newid);
            crpmergeone("PaperReviewRefused", "contactId", $oldid, $newid);
            crpmergeone("PaperReviewRefused", "requestedBy", $oldid, $newid);
            crpmergeone("ReviewRequest", "requestedBy", $oldid, $newid);
            crpmergeoneignore("PaperWatch", "contactId", $oldid, $newid);
            crpmergeoneignore("ReviewRating", "contactId", $oldid, $newid);

            // Remove the old contact record
            if ($MergeError == "") {
                if (!$Conf->q("delete from ContactInfo where contactId=$oldid"))
                    $MergeError .= $Conf->db_error_html(true);
                if (!$Conf->q("delete from ContactAddress where contactId=$oldid"))
                    $MergeError .= $Conf->db_error_html(true);
            }

            $Conf->qe("unlock tables");

            // Update PC settings if we need to
            if ($MiniMe->isPC)
                $Conf->invalidateCaches(array("pc" => 1));

            if ($MergeError == "") {
                $Conf->confirmMsg("Account " . htmlspecialchars($MiniMe->email) . " successfully merged.");
                $Me->log_activity("Merged account $MiniMe->email");
                go(hoturl("index"));
            } else {
                $Me->log_activity("Merged account $MiniMe->email with errors");
                $MergeError .= $Conf->db_error_html(true);
            }
        }
    }
}

$Conf->header("Merge Accounts");


if ($MergeError)
    $Conf->errorMsg($MergeError);
else
    $Conf->infoMsg(
"You may have multiple accounts registered with the "
. $Opt["shortName"] . " conference; perhaps "
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
  <td class='caption initial'>Email</td>
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
