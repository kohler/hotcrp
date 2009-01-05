<?php
// contactauthors.php -- HotCRP paper contact author management page
// HotCRP is Copyright (c) 2006-2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/papertable.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();


// header
function confHeader() {
    global $ConfSiteSuffix, $prow, $Conf;
    $title = ($prow ? "Paper #$prow->paperId Contact Authors" : "Paper Contact Authors");
    $Conf->header($title, "contactauthors", actionBar($prow, false, "Contact Authors", "contactauthors$ConfSiteSuffix?p=" . ($prow ? $prow->paperId : "")), false);
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// grab paper row
$prow = null;
function getProw($contactId) {
    global $prow;
    if (!($prow = PaperTable::paperRow($whyNot)))
	errorMsgExit(whyNotText($whyNot, "view"));
}
getProw($Me->contactId);


// check permissions
$notAuthor = !$Me->amPaperAuthor($prow->paperId);
if ($notAuthor && !$Me->privChair)
    errorMsgExit("You are not an author of paper #$prow->paperId.  If you believe this is incorrect, get a registered author to list you as a coauthor, or contact the site administrator.");

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


if (!$Me->canEditContactAuthors($prow))
    errorMsgExit("You can't manage paper #$prow->paperId since you are not a contact author.  If you believe this is incorrect, get a registered author to list you as a coauthor, or contact the site administrator.");


if (isset($_REQUEST["add"])) {
    if (!isset($_REQUEST["email"]) || trim($_REQUEST["email"]) == "")
	$Conf->errorMsg("You must enter the new contact author's email address.");
    else if (($id = $Conf->getContactId($_REQUEST["email"], true)) > 0) {
	if (addContactAuthor($prow->paperId, $id))
	    $Conf->confirmMsg("Contact author added.");
    }
}

foreach ($_REQUEST as $k => $v)
    if (substr($k, 0, 3) == "rem" && ($id = cvtint(substr($k, 3))) > 0) {
	$while = "while removing contact author";
	$Conf->qe("lock tables PaperConflict write, ActionLog write", $while);
	$result = $Conf->qe("select count(paperId) from PaperConflict where paperId=$prow->paperId and conflictType>=" . CONFLICT_AUTHOR . " group by paperId", $while);
	$row = edb_row($result);
	if (!$Me->privChair && (!$row || $row[0] <= 1))
	    $Conf->errorMsg("Only a system administrator can remove the last contact author.");
	else if (removeContactAuthor($prow->paperId, $id)) {
	    $Conf->confirmMsg("Contact author removed.");
	    $Conf->log("Removed as contact author by $Me->email", $id, $prow->paperId);
	}
	$Conf->qe("unlock tables", $while);
    }


if ($OK) {
    $paperTable = new PaperTable($prow);
    $paperTable->initialize(false, false, true);
    $paperTable->mode = "contact";

    $paperTable->paptabBegin();

    // Contact authors
    $t = "<form method='post' action=\"contactauthors$ConfSiteSuffix?p=$prow->paperId&amp;post=1\" enctype='multipart/form-data' accept-charset='UTF-8'>"
	. "<div class='papt'>Contact authors</div>"
	. "<div class='paphint'>A paper's contact authors are HotCRP users who can edit paper information and view reviews.  Every paper author with a HotCRP account is a contact author by default, but you can add additional contact authors who aren't named in the author list.  Every paper must have at least one contact author.</div>"
	. "<div class='papv'>"
	. "<table class='pltable'>
    <tr class='pl_headrow'><th>Name</th> <th>Email</th> <th></th></tr>\n";
    $q = "select firstName, lastName, email, contactId
	from ContactInfo
	join PaperConflict using (contactId)
	where paperId=$prow->paperId and conflictType>=" . CONFLICT_AUTHOR . "
	order by lastName, firstName, email";
    $result = $Conf->qe($q, "while finding contact authors");
    $numContacts = edb_nrows($result);
    while ($row = edb_row($result)) {
	$t .= "<tr><td class='pad'>" . contactHtml($row[0], $row[1])
	    . "</td> <td class='pad'>" . htmlspecialchars($row[2]) . "</td>";
	if ($Me->privChair || ($numContacts > 1 && $row[3] != $Me->contactId))
	    $t .= " <td class='pad'><button class='b' type='submit' name='rem$row[3]' value='1'>Remove contact author</button></td>";
	$t .= "</tr>\n    ";
    }

    $t .= "    <tr><td class='pad'><input class='textlite' type='text' name='name' size='20' /></td>
	<td class='pad'><input class='textlite' type='text' name='email' size='20' /></td>
	<td class='pad'><input class='bb' type='submit' name='add' value='Add contact author' /></td>
    </tr>
  </table></div></form>";

    $paperTable->_paptabSepContaining($t);

    $paperTable->paptabEndWithReviewMessage();

} else {
    $Conf->errorMsg("The paper disappeared!");
}

$Conf->footer();
