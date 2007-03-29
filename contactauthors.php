<?php 
// contactauthors.php -- HotCRP paper contact author management page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
require_once('Code/papertable.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();


// header
function confHeader() {
    global $paperId, $prow, $Conf;
    $title = ($paperId > 0 ? "Paper #$paperId Contact Authors" : "Paper Contact Authors");
    $Conf->header($title, "contactauthors", actionBar($prow, false, "Contact Authors", "contactauthors.php?paperId=$paperId"), false);
    $Conf->expandBody();
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// collect paper ID
maybeSearchPaperId("contactauthors.php", $Me);
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
    return $Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values ($paperId, $contactId, " . CONFLICT_AUTHOR . ") on duplicate key update conflictType=" . CONFLICT_AUTHOR, "while adding contact author");
}

function removeContactAuthor($paperId, $contactId) {
    global $Conf;
    return $Conf->qe("delete from PaperConflict where paperId=$paperId and contactId=$contactId", "while removing contact author");
}


confHeader();


if (!$Me->canManagePaper($prow))
    $Conf->errorMsg("You can't manage paper #$paperId since you are not a contact author.  If you believe this is incorrect, get a registered author to list you as a coauthor, or contact the site administrator.");
else if (isset($_REQUEST["update"])) {
    if (!isset($_REQUEST["email"]) || trim($_REQUEST["email"]) == "")
	$Conf->errorMsg("You must enter the new contact author's email address."); 
    else if (($id = $Conf->getContactId($_REQUEST["email"], true)) > 0) {
	if (addContactAuthor($paperId, $id))
	    $Conf->confirmMsg("Contact author added.");
    }
} else if (isset($_REQUEST["remove"])) {
    if (!$Me->privChair)
	$Conf->errorMsg("Only the PC chair can remove contact authors from a paper.");
    else if (($id = cvtint($_REQUEST['remove'])) <= 0)
	$Conf->errorMsg("Invalid contact author ID in request.");
    else {
	if (removeContactAuthor($paperId, $id))
	    $Conf->confirmMsg("Contact author removed.");
    }
} else
    $Conf->infoMsg("Use this screen to add more contact authors for your paper.  Any contact author can edit paper information, upload new versions, submit the paper, and view reviews." . ($Me->privChair ? "" : "  Only the PC chair can <i>remove</i> contact authors from the paper, so act carefully."));
    



if ($OK) {    
    $paperTable = new PaperTable(false, false, true, false);
    
    echo "<form method='post' action=\"contactauthors.php?paperId=$paperId&amp;post=1\" enctype='multipart/form-data'>";
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
    echo "<tr>\n  <td class='caption'>Contact&nbsp;authors</td>\n";
    echo "  <td class='entry plholder'><table class='pltable'>
    <tr class='pl_headrow'><th>Name</th> <th>Email</th> <th></th></tr>\n";
    $q = "select firstName, lastName, email, contactId
	from ContactInfo
	join PaperConflict using (contactId)
	where paperId=$paperId and conflictType=" . CONFLICT_AUTHOR . "
	order by lastName, firstName, email";
    $result = $Conf->qe($q, "while finding contact authors");
    while ($row = edb_row($result)) {
	echo "<tr><td class='pad'>", contactHtml($row[0], $row[1]), "</td> <td class='pad'>", htmlspecialchars($row[2]), "</td>";
	if ($Me->privChair)
	    echo " <td class='pad'><button class='button_small' type='submit' name='remove' value='$row[3]'>Remove contact author</button></td>";
	echo "</tr>\n    ";
    }

    echo "    <tr><td class='pad'><input class='textlite' type='text' name='name' size='20' onchange='highlightUpdate()' /></td>
	<td class='pad'><input class='textlite' type='text' name='email' size='20' onchange='highlightUpdate()' /></td>
	<td class='pad'><input class='button' type='submit' name='update' value='Add contact author' /></td>
    </tr>
  </table></td>
</tr>

</table>";
    $paperTable->echoDivExit();
    echo "</form>\n";
    
} else {
    $Conf->errorMsg("The paper disappeared!");
    printPaperLinks();
}

$Conf->footer();
