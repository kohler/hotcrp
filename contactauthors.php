<?php 
// contactauthors.php -- HotCRP paper contact author management page
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/papertable.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();


// header
function confHeader() {
    global $paperId, $ConfSiteSuffix, $prow, $Conf;
    $title = ($paperId > 0 ? "Paper #$paperId Contact Authors" : "Paper Contact Authors");
    $Conf->header($title, "contactauthors", actionBar($prow, false, "Contact Authors", "contactauthors$ConfSiteSuffix?p=$paperId"), false);
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// collect paper ID
maybeSearchPaperId($Me);
$paperId = cvtint($_REQUEST["paperId"]);

// grab paper row
$prow = null;
function getProw($contactId) {
    global $prow, $paperId, $Conf, $Me;
    if (!($prow = $Conf->paperRow($paperId, $contactId, $whyNot))
	|| !$Me->canViewPaper($prow, $Conf, $whyNot))
	errorMsgExit(whyNotText($whyNot, "view"));
}
getProw($Me->contactId);


// check permissions
$notAuthor = !$Me->amPaperAuthor($paperId, $Conf);
if ($notAuthor && !$Me->privChair)
    errorMsgExit("You are not an author of paper #$paperId.  If you believe this is incorrect, get a registered author to list you as a coauthor, or contact the site administrator.");

function pt_data_html($what, $row) {
    global $can_update;
    if (isset($_REQUEST[$what]) && $can_update)
	return htmlspecialchars($_REQUEST[$what]);
    else
	return htmlspecialchars($row->$what);
}

function addContactAuthor($paperId, $contactId) {
    global $Conf;
    // don't want two entries for the same contact, if we can avoid it
    return $Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values ($paperId, $contactId, " . CONFLICT_CONTACTAUTHOR . ") on duplicate key update conflictType=" . CONFLICT_CONTACTAUTHOR, "while adding contact author");
}

function removeContactAuthor($paperId, $contactId) {
    global $Conf;
    return $Conf->qe("delete from PaperConflict where paperId=$paperId and contactId=$contactId", "while removing contact author");
}


confHeader();


if (!$Me->canManagePaper($prow))
    errorMsgExit("You can't manage paper #$paperId since you are not a contact author.  If you believe this is incorrect, get a registered author to list you as a coauthor, or contact the site administrator.");


$needMsg = true;

if (isset($_REQUEST["add"])) {
    $needMsg = false;
    if (!isset($_REQUEST["email"]) || trim($_REQUEST["email"]) == "")
	$Conf->errorMsg("You must enter the new contact author's email address."); 
    else if (($id = $Conf->getContactId($_REQUEST["email"], true)) > 0) {
	if (addContactAuthor($paperId, $id))
	    $Conf->confirmMsg("Contact author added.");
    }
}

foreach ($_REQUEST as $k => $v)
    if (substr($k, 0, 3) == "rem" && ($id = cvtint(substr($k, 3))) > 0) {
	$needMsg = false;
	$while = "while removing contact author";
	$Conf->qe("lock tables PaperConflict write, ActionLog write", $while);
	$result = $Conf->qe("select count(paperId) from PaperConflict where paperId=$paperId and conflictType=" . CONFLICT_CONTACTAUTHOR . " group by paperId", $while);
	$row = edb_row($result);
	if (!$Me->privChair && (!$row || $row[0] <= 1))
	    $Conf->errorMsg("Only a system administrator can remove the last contact author.");
	else if (removeContactAuthor($paperId, $id)) {
	    $Conf->confirmMsg("Contact author removed.");
	    $Conf->log("Removed as contact author by $Me->email", $id, $paperId);
	}
	$Conf->qe("unlock tables", $while);
    }

if ($needMsg)
    $Conf->infoMsg("Use this screen to change your paper's contact authors.  Contact authors can edit paper information, upload new versions, submit the paper, and view reviews, whether or not they're named in the author list.  Every paper must have at least one contact author.");


if ($OK) {    
    $paperTable = new PaperTable(false, false, true, false);
    
    echo "<form method='post' action=\"contactauthors$ConfSiteSuffix?p=$paperId&amp;post=1\" enctype='multipart/form-data' accept-charset='UTF-8'>";
    $paperTable->echoDivEnter();
    echo "<table class='paper'>\n";

    // title
    echo "<tr class='id'>\n  <td class='caption'><h2>#$paperId</h2></td>\n";
    echo "  <td class='entry' colspan='2'><h2>";
    $paperTable->echoTitle($prow);
    echo "</h2></td>\n</tr>\n\n";

    // Paper contents
    $paperTable->echoPaperRow($prow, 0);
    $paperTable->echoAuthorInformation($prow);
    $paperTable->echoAbstractRow($prow);
    $paperTable->echoCollaborators($prow);
    $paperTable->echoTopics($prow);

    // Contact authors
    echo "<tr>\n  <td class='caption textarea'>Contact&nbsp;authors</td>\n";
    echo "  <td class='entry plholder'><table class='pltable'>
    <tr class='pl_headrow'><th>Name</th> <th>Email</th> <th></th></tr>\n";
    $q = "select firstName, lastName, email, contactId
	from ContactInfo
	join PaperConflict using (contactId)
	where paperId=$paperId and conflictType=" . CONFLICT_CONTACTAUTHOR . "
	order by lastName, firstName, email";
    $result = $Conf->qe($q, "while finding contact authors");
    $numContacts = edb_nrows($result);
    while ($row = edb_row($result)) {
	echo "<tr><td class='pad'>", contactHtml($row[0], $row[1]), "</td> <td class='pad'>", htmlspecialchars($row[2]), "</td>";
	if ($Me->privChair || ($numContacts > 1 && $row[3] != $Me->contactId))
	    echo " <td class='pad'><button class='b' type='submit' name='rem$row[3]' value='1'>Remove contact author</button></td>";
	echo "</tr>\n    ";
    }

    echo "    <tr><td class='pad'><input class='textlite' type='text' name='name' size='20' /></td>
	<td class='pad'><input class='textlite' type='text' name='email' size='20' /></td>
	<td class='pad'><input class='hbutton' type='submit' name='add' value='Add contact author' /></td>
    </tr>
  </table></td>
</tr>

<tr class='last'><td class='caption'></td></tr>
</table>";
    $paperTable->echoDivExit();
    echo "</form>\n";
    
} else {
    $Conf->errorMsg("The paper disappeared!");
}

$Conf->footer();
