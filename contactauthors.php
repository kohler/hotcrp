<?php 
include('Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();


// header
function actionTab($text, $url, $default, $disabled) {
    if ($disabled)
	return "<span class='sep'></span><span class='tab_disabled'>$text</span>";
    else if ($default)
	return "<span class='sep'></span><span class='tab_default'><a href='$url'>$text</a></span>";
    else
	return "<span class='sep'></span><span class='tab'><a href='$url'>$text</a></span>";
}

function actionBar($prow) {
    global $Me, $Conf;
    $paperId = ($prow == null ? -1 : $prow->paperId);
    $disableView = $paperId < 0;
    $x = "<div class='vubar'>";
    $x .= actionTab("View", "paper.php?paperId=$paperId&amp;mode=view", false, $disableView);
    $x .= actionTab("Edit", "paper.php?paperId=$paperId&amp;mode=edit", false, ($disableView || ($prow && !$Me->canUpdatePaper($prow, $Conf))));
    if ($prow && ($Me->isPC || $Me->canViewReviews($prow, $Conf)))
	$x .= actionTab("Reviews" . ($prow ? " ($prow->reviewCount)" : ""), "paper.php?paperId=$paperId&amp;mode=reviews", false, false);
    $x .= actionTab("Contact Authors", "contactauthors.php?paperId=$paperId", true, false);
    $x .= "<span class='gopaper'>" . goPaperForm() . "</span>";
    $x .= "</div>\n";
    return $x;
}

function confHeader() {
    global $paperId, $prow, $Conf;
    $title = ($paperId > 0 ? "Paper #$paperId Contact Authors" : "Paper Contact Authors");
    $Conf->header($title, "contactauthors", actionBar($prow));
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// collect paper ID
$paperId = cvtint($_REQUEST["paperId"]);

// grab paper row
$prow = null;
function getProw($contactId) {
    global $prow, $paperId, $Conf, $Me;
    $prow = $Conf->paperRow($paperId, $contactId, "while fetching paper");
    if ($prow === null)
	errorMsgExit("");
    else if (!$Me->canViewPaper($prow, $Conf, $whyNot))
	errorMsgExit(whyNotText($whyNot, "view", $paperId));
}

getProw($Me->contactId);


// check permissions
$notAuthor = !$Me->amPaperAuthor($paperId, $Conf);
if ($notAuthor && !$Me->amAssistant())
    errorMsgExit("You are not an author of paper #$paperId.  If you believe this is incorrect, get a registered author to list you as a coauthor, or contact the site administrator.");

function pt_data_html($what, $row) {
    global $can_update;
    if (isset($_REQUEST[$what]) && $can_update)
	return htmlspecialchars($_REQUEST[$what]);
    else
	return htmlspecialchars($row->$what);
}

function addContactAuthor($paperId, $contactId) {
    // don't want two entries for the same contact, if we can avoid it
    global $Conf;
    
    $result = $Conf->qe("lock tables PaperConflict write", "while adding contact author");
    if (DB::isError($result))
	return $result;

    $result = $Conf->qe("select author from PaperConflict where paperId=$paperId and contactId=$contactId", "while adding contact author");
    if (!DB::isError($result) && $result->numRows() > 0)
	$q = "update PaperConflict set author=1 where paperId=$paperId and contactId=$contactId";
    else
	$q = "insert into PaperConflict set paperId=$paperId, contactId=$contactId, author=1";

    $result = $Conf->qe($q, "while adding contact author");

    $Conf->qe("unlock tables");
    return $result;
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
    else if (($id = $Conf->getContactId($_REQUEST["email"])) > 0) {
	$result = addContactAuthor($paperId, $id);
	if (!DB::isError($result))
	    $Conf->confirmMsg("Contact author added.");
    }
} else if (isset($_REQUEST["remove"])) {
    if (!$Me->amAssistant())
	$Conf->errorMsg("Only the PC chair can remove contact authors from a paper.");
    else if (($id = cvtint($_REQUEST['remove'])) <= 0)
	$Conf->errorMsg("Invalid contact author ID in request.");
    else {
	$result = removeContactAuthor($paperId, $id);
	if (!DB::isError($result))
	    $Conf->confirmMsg("Contact author removed.");
    }
} else
    $Conf->infoMsg("Use this screen to add more contact authors for your paper.  Any contact author can edit paper information, upload new versions, submit the paper, and view reviews." . ($Me->amAssistant() ? "" : "  Only the PC chair can <i>remove</i> contact authors from the paper, so act carefully."));
    



if ($OK) {    
    echo "<form method='post' action=\"contactauthors.php?paperId=$paperId&amp;form=1\" enctype='multipart/form-data'>
<table class='paperauthors'>
<tr class='id'>
  <td class='caption'><h2>#$paperId</h2></td>
  <td class='entry' colspan='2'><h2>", htmlspecialchars($prow->title), "</h2></td>\n</tr>\n";

?>

<tr>
  <td class='caption'>Status:</td>
  <td class='entry'><?php echo $Me->paperStatus($paperId, $prow, 1) ?></td>
</tr>


<tr>
  <td class='caption'>Contact&nbsp;authors:</td>
  <td class='entry plholder'><table class='pltable'>
    <tr class='pl_headrow'><th>Name</th> <th>Email</th> <th></th></tr>
    <?php {
      $q = "select firstName, lastName, email, contactId
	from ContactInfo
	join PaperConflict using (contactId)
	where paperId=$paperId and author=1
	order by lastName, firstName";
      $result = $Conf->qe($q, "while finding contact authors");
      if (!DB::isError($result)) {
	  while ($row = $result->fetchRow()) {
	      echo "<tr><td>", htmlspecialchars(contactText($row[0], $row[1])), "</td> <td>", htmlspecialchars($row[2]), "</td>";
	      if ($Me->amAssistant())
		  echo " <td><button class='button_small' type='submit' name='remove' value='$row[3]'>Remove contact author</button></td>";
	      echo "</tr>\n    ";
	  }
      }
    } ?>
    <tr><td><input class='textlite' type='text' name='name' size='20' onchange='highlightUpdate()' /></td>
	<td><input class='textlite' type='text' name='email' size='20' onchange='highlightUpdate()' /></td>
	<td><input class='button_default' type='submit' name='update' value='Add contact author' /></td>
    </tr>
  </table></td>
</tr>

<tr>
  <td class='caption'>Authors:</td>
  <td class='entry'><?php
    echo authorTable($prow->authorInformation);
?></td>
</tr>

</table>
</form>


<?php
    
} else {
    $Conf->errorMsg("The paper disappeared!");
    printPaperLinks();
}

$Conf->footer(); ?>