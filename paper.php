<?php 
include('Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();


// header
function actionTab($text, $name, $default, $disabled) {
    global $paperId, $newPaper;
    if ($disabled)
	return "<div class='tab_disabled'>$text</div>";
    else if ($default)
	return "<div class='tab_default'><a href='paper.php?paperId=" . ($newPaper ? "new" : $paperId) . "&amp;$name=1'>$text</a></div>";
    else
	return "<div class='tab'><a href='paper.php?paperId=" . ($newPaper ? "new" : $paperId) . "&amp;$name=1'>$text</a></div>";
}

function actionBar() {
    global $paperId, $newPaper, $Me, $prow, $Conf, $editMode, $viewMode;
    $x = "<div class='vubar'>";
    if ($newPaper)
	$x .= actionTab("View", "view", false, true);
    else
	$x .= actionTab("View", "view", $viewMode, false);
    if ($newPaper || $Me->canUpdatePaper($prow, $Conf, $whyNot) || !isset($whyNot["author"]))
	$x .= actionTab("Edit", "edit", $editMode, false);
    $x .= goPaperForm() . "</div>\n<div class='clear'></div>\n\n";
    return $x;
}

function confHeader() {
    global $paperId, $newPaper, $Conf;
    if ($paperId > 0)
	$title = "Paper #$paperId";
    else
	$title = ($newPaper ? "New Paper" : "Paper View");
    $Conf->header($title, "paper", actionBar());
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// collect paper ID: either a number or "new"
$newPaper = false;
$paperId = -1;
if (!isset($_REQUEST["paperId"]))
    /* nada */;
else if (trim($_REQUEST["paperId"]) == "new")
    $newPaper = true;
else
    $paperId = cvtint($_REQUEST["paperId"]);


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  Any changes were lost.");


// grab paper row
$prow = null;
function getProw($contactId) {
    global $prow, $paperId, $Conf, $Me;
    $prow = $Conf->getPaperRow($paperId, $contactId, "while fetching paper");
    if ($prow === null)
	errorMsgExit("");
    else if (!$Me->canViewPaper($prow, $Conf, $whyNot))
	errorMsgExit(whyNotText($whyNot, "view", $paperId));
}
if (!$newPaper)
    getProw($Me->contactId);


// mode
if ($newPaper || cvtint($_REQUEST["edit"]) > 0)
    $editMode = true;
else if (cvtint($_REQUEST["view"]) > 0)
    $viewMode = true;
else if ($prow->acknowledged <= 0)
    $editMode = true;
else
    $viewMode = true;


// potentially update paper
$PaperError = array();

function setRequestFromPaper($prow) {
    foreach (array("title", "abstract", "authorInformation", "collaborators") as $x)
	if (!isset($_REQUEST[$x]))
	    $_REQUEST[$x] = $prow->$x;
}

function requestSameAsPaper($prow) {
    global $Conf;
    foreach (array("title", "abstract", "authorInformation", "collaborators") as $x)
	if ($_REQUEST[$x] != $prow->$x)
	    return false;
    if (fileUploaded($_FILES['paperUpload'], $Conf))
	return false;
    $result = $Conf->q("select TopicArea.topicId, PaperTopic.paperId from TopicArea left join PaperTopic on PaperTopic.paperId=$prow->paperId and PaperTopic.topicId=TopicArea.topicId");
    if (!DB::isError($result))
	while (($row = $result->fetchRow())) {
	    $got = isset($_REQUEST["top$row[0]"]) && cvtint($_REQUEST["top$row[0]"]) > 0;
	    if (($row[1] > 0) != $got)
		return false;
	}
    return true;
}

function uploadPaper() {
    global $paperId, $Conf;
    $result = $Conf->storePaper('paperUpload', $paperId);
    if ($result == 0 || PEAR::isError($result)) {
	$Conf->errorMsg("There was an error while trying to update your paper.  Please try again.");
	return false;
    } else
	return true;
}

function updatePaper($contactId, $isSubmit, $isUploadOnly) {
    global $paperId, $newPaper, $PaperError, $Conf;

    // check that all required information has been entered
    array_ensure($_REQUEST, "", "title", "abstract", "authorInformation", "collaborators");
    $q = "";
    foreach (array("title", "abstract", "authorInformation", "collaborators") as $x)
	if (trim($_REQUEST[$x]) == "" && ($isSubmit || $x != "collaborators"))
	    $PaperError[$x] = 1;
	else
	    $q .= "$x='" . sqlqtrim($_REQUEST[$x]) . "', ";

    // any missing fields?
    if (count($PaperError) > 0) {
	$Conf->errorMsg("One or more required fields were left blank.  Fill in those fields and try again." . (isset($PaperError["collaborators"]) ? "  If none of the authors have recent collaborators, just enter \"None\" in the Collaborators field." : ""));
	return false;
    }

    // update Paper table
    $q = substr($q, 0, -2);
    if (!$newPaper)
	$q .= " where paperId=$paperId and withdrawn<=0 and acknowledged<=0";
    else
	$q .= ", contactId=$contactId, paperStorageId=1";
    $result = $Conf->qe(($newPaper ? "insert into" : "update") . " Paper set $q", "while updating paper information");
    if (DB::isError($result))
	return false;

    // fetch paper ID
    if ($newPaper) {
	$result = $Conf->qe("select last_insert_id()", "while updating paper information");
	if (DB::isError($result) || $result->numRows() == 0)
	    return false;
	$row = $result->fetchRow();
	$paperId = $row[0];

	$result = $Conf->qe("insert into PaperConflict set paperId=$paperId, contactId=$contactId, author=1", "while updating paper information");
	if (DB::isError($result))
	    return false;
    }

    // update topics table
    $result = $Conf->qe("delete from PaperTopic where paperId=$paperId", "while updating paper topics");
    if (DB::isError($result))
	return false;
    foreach ($_REQUEST as $key => $value)
	if ($key[0] == 't' && $key[1] == 'o' && $key[2] == 'p'
	    && ($id = cvtint(substr($key, 3))) > 0 && $value > 0) {
	    $result = $Conf->qe("insert into PaperTopic set paperId=$paperId, topicId=$id", "while updating paper topics");
	    if (DB::isError($result))
		return false;
	}

    // upload paper if appropriate
    if (fileUploaded($_FILES['paperUpload'], $Conf) && !uploadPaper())
	return false;
    
    // confirmation message
    $Conf->confirmMsg(($newPaper ? "Created paper #$paperId." : "Updated paper #$paperId."));
    return true;
}

if (isset($_REQUEST["update"]) || isset($_REQUEST["submit"])) {
    // get missing parts of request
    if (!$newPaper)
	setRequestFromPaper($prow);

    // check deadlines
    if ($newPaper)
	$ok = $Me->canStartPaper($Conf, $whyNot);
    else {
	if (isset($_REQUEST["submit"]) && requestSameAsPaper($prow))
	    $ok = $Me->canFinalizePaper($prow, $Conf, $whyNot);
	else
	    $ok = $Me->canUpdatePaper($prow, $Conf, $whyNot);
    }

    // actually update
    if (!$ok)
	$Conf->errorMsg(whyNotText($whyNot, "update", $paperId));
    else if (updatePaper($Me->contactId, isset($_REQUEST["submit"]), false)) {
	getProw($Me->contactId);
	if ($newPaper)
	    $Conf->go("paper.php?paperId=$paperId&edit=1");
	$newPaper = false;
    }

    // use request?
    $useRequest = ($ok || $Me->amAssistant());
}

// XXX finalize here


if (isset($_REQUEST['setoutcome'])) {
    if (!$Me->canSetOutcome($prow))
	$Conf->errorMsg("You cannot set the outcome for paper #$paperId" . ($Me->amAssistant() ? " (but you could if you entered chair mode)" : "") . ".");
    else {
	$o = cvtint(trim($_REQUEST['outcome']));
	$rf = reviewForm();
	if (isset($rf->options['outcome'][$o])) {
	    $result = $Conf->qe("update Paper set outcome=$o where paperId=$paperId", "while changing outcome");
	    if (!DB::isError($result))
		$Conf->confirmMsg("Outcome for paper #$paperId set to " . htmlspecialchars($rf->options['outcome'][$o]) . ".");
	} else
	    $Conf->errorMsg("Bad outcome value!");
	$prow = $Conf->getPaperRow($paperId, $Me->contactId);
    }
 }

confHeader();


function pt_caption_class($what) {
    global $PaperError;
    if (isset($PaperError[$what]))
	return "pt_caption_error";
    else
	return "pt_caption";
}

function pt_data($what, $rows, $authorTable = false) {
    global $editMode, $newPaper, $prow, $useRequest;
    if ($editMode)
	echo "<textarea class='textlite' name='$what' rows='$rows' cols='80' onchange='highlightUpdate()'>";
    if ($useRequest)
	$text = $_REQUEST[$what];
    else if (!$newPaper)
	$text = $prow->$what;
    else
	$text = "";
    if ($authorTable && !$editMode)
	echo authorTable($text, true);
    else
	echo htmlspecialchars($text);
    if ($editMode)
	echo "</textarea>";
}


// begin table
if ($editMode)
    echo "<form method='post' action=\"paper.php?paperId=",
	($newPaper ? "new" : $paperId),
	"&amp;post=1\" enctype='multipart/form-data'>";
echo "<table class='paper'>\n\n";


// title
if (!$newPaper) {
    echo "<tr>\n  <td class='pt_id'><h2>#$paperId</h2></td>\n";
    echo "  <td class='pt_entry' colspan='2'><h2>", htmlspecialchars($prow->title), "</h2></td>\n</tr>\n\n";
}


// paper status
if (!$newPaper) {
    echo "<tr>\n  <td class='pt_caption'>Status:</td>\n";
    echo "  <td class='pt_entry'>", $Me->paperStatus($paperId, $prow, 1);
    if ($prow->author > 0)
	echo "<br/>\nYou are an <span class='author'>author</span> of this paper.";
    else if ($Me->isPC && $prow->conflict > 0)
	echo "<br/>\nYou have a <span class='conflict'>conflict</span> with this paper.";
    if ($prow->reviewType != null) {
	if ($prow->reviewType == REVIEW_PRIMARY)
	    echo "<br/>\nYou are a primary reviewer for this paper.";
	else if ($prow->reviewType == REVIEW_SECONDARY)
	    echo "<br/>\nYou are a secondary reviewer for this paper.";
	else if ($prow->reviewType == REVIEW_REQUESTED)
	    echo "<br/>\nYou were requested to review this paper.";
	else
	    echo "<br/>\nYou began a review for this paper.";
    }
    echo "</td>\n</tr>\n\n";
}


// Editable title
if ($editMode) {
    echo "<tr class='pt_title'>\n  <td class='",
	pt_caption_class("title"), "'>Title:</td>\n";
    echo "  <td class='pt_entry'>";
    pt_data("title", 1);
    echo "</td>\n</tr>\n\n";
}


// Paper
if ($newPaper || ($prow->withdrawn <= 0 && ($editMode || $prow->size > 0))) {
    echo "<tr class='pt_paper'>\n  <td class='",
	pt_caption_class("paper"), "'>Paper",
	($newPaper ? "&nbsp;(optional)" : ""), ":</td>\n";
    echo "  <td class='pt_entry'>";
    if (!$newPaper && $prow->size > 0)
	echo paperDownload($paperId, $prow, 1);
    if ($newPaper || ($editMode && $prow->acknowledged <= 0)) {
	if (!$newPaper && $prow->size > 0)
	    echo "<br/>\n    ";
	echo "<input type='file' name='paperUpload' accept='application/pdf application/postscript' size='", ($newPaper ? 50 : 50), "' />";
	if (!$newPaper && 0)
	    echo "&nbsp;<input class='button' type='submit' name='upload' value='Upload paper' />";
    }
    echo "</td>\n";
    if ($newPaper || ($editMode && $prow->acknowledged <= 0))
	echo "  <td class='pt_hint'>Max size: ", ini_get("upload_max_filesize"), "B</td>\n";
    echo "</tr>\n\n";
}
    

if (!$editMode && $Me->amAssistant()) {
    $q = "select firstName, lastName
	from ContactInfo
	join PCMember using (contactId)
	join PaperConflict using (contactId)
	where paperId=$paperId group by ContactInfo.contactId";
    $result = $Conf->qe($q, "while finding conflicted PC members");
    if (!DB::isError($result) && $result->numRows() > 0) {
	while ($row = $result->fetchRow())
	    $pcConflicts[] = "$row[0] $row[1]";
	echo "<tr class='pt_conflict'>\n  <td class='pt_caption'>PC&nbsp;conflicts:</td>\n  <td class='pt_entry'>", authorTable($pcConflicts), "</td>\n</tr>\n\n";
    }
}


// Outcome
if (!$editMode && $Me->canSetOutcome($prow)) {
    echo "<tr class='pt_outcome'>
  <td class='pt_caption'>Outcome:</td>
  <td class='pt_entry'><form method='get' action='paper.php'><input type='hidden' name='paperId' value='$paperId' /><select class='outcome' name='outcome'>\n";
    $rf = reviewForm();
    $outcomeMap = $rf->options['outcome'];
    $outcomes = array_keys($outcomeMap);
    sort($outcomes);
    $outcomes = array_unique(array_merge(array(0), $outcomes));
    foreach ($outcomes as $key)
	echo "    <option value='", $key, "'", ($prow->outcome == $key ? " selected='selected'" : ""), ">", htmlspecialchars($outcomeMap[$key]), "</option>\n";
    echo "  </select>&nbsp;<input class='button' type='submit' name='setoutcome' value='Set outcome' /></form></td>\n</tr>\n";
}


// Abstract
echo "<tr class='pt_abstract'>\n  <td class='", pt_caption_class("abstract"),
    "'>Abstract:</td>\n  <td class='pt_entry'>";
pt_data("abstract", 5);
echo "</td>\n</tr>\n\n";


// Contact authors
if ($newPaper) {
    echo "<tr class='pt_contactAuthors'>\n  <td class='pt_caption'>";
    echo "Contact&nbsp;author:</td>\n  <td class='pt_entry'>", contactText($Me->firstName, $Me->lastName, $Me->email), "</td>\n";
    echo "  <td class='pt_hint'>You will be able to add more contact authors after you submit the paper.</td>\n";
} else if ($Me->canViewAuthors($prow, $Conf)) {
    $result = $Conf->qe("select firstName, lastName, email, contactId
	from ContactInfo
	join PaperConflict using (contactId)
	where paperId=$paperId and author=1
	order by lastName, firstName", "while finding contact authors");
    echo "<tr class='pt_contactAuthors'>\n  <td class='pt_caption'>";
    echo (!DB::isError($result) && $result->numRows() == 1 ? "Contact&nbsp;author:" : "Contact&nbsp;authors:");
    echo "</td>\n  <td class='pt_entry'>";
    if (!DB::isError($result)) {
	while ($row = $result->fetchRow()) {
	    $au = contactText($row[0], $row[1], $row[2]);
	    $aus[] = $au;
	}
	echo authorTable($aus, false);
    }
    echo "<a class='button_small' href='Author/PaperContacts.php?paperId=$paperId'>Edit&nbsp;contact&nbsp;authors</a>";
    // XXX edit contact authors
    echo "</td>\n</tr>\n\n";
}


// Authors
if ($newPaper || $Me->canViewAuthors($prow, $Conf)) {
    echo "<tr class='pt_authors'>\n  <td class='",
	pt_caption_class("authorInformation"),
	"'>Authors:</td>\n  <td class='pt_entry'>";
    pt_data("authorInformation", 5, true);
    echo "</td>\n";
    if ($editMode)
	echo "  <td class='pt_hint'>List the paper's authors one per line, including any affiliations.  Example: <pre class='entryexample'>Bob Roberts (UCLA)
Ludwig van Beethoven (Colorado)
Zhang, Ping Yen (INRIA)</pre></td>\n";
    echo "</tr>\n\n";

    echo "<tr class='pt_collaborators'>\n  <td class='",
	pt_caption_class("collaborators"),
	"'>Collaborators:</td>\n  <td class='pt_entry'>";
    pt_data("collaborators", 5, true);
    echo "</td>\n";
    if ($editMode)
	echo "  <td class='pt_hint'>List the authors' recent (~2 years) coauthors and collaborators, and any advisor or student relationships.  Be sure to include PC members when appropriate.  We use this information to avoid conflicts of interest when reviewers are assigned.  Use the same format as for authors, above.</td>\n";
    echo "</tr>\n\n";
}


// Topics
if ($topicTable = topicTable($paperId, ($editMode ? (int) $useRequest : -1), $Conf)) { 
    echo "<tr class='pt_topics'>
  <td class='pt_caption'>Topics:</td>
  <td class='pt_entry' id='topictable'>", $topicTable, "</td>\n</tr>\n\n";
}


// Submit button
if ($newPaper)
    echo "<tr class='pt_create'>
  <td></td>
  <td class='pt_entry'><input class='button_default' type='submit' name='update' value='Create paper' /></td>
</tr>\n\n";
else if ($editMode) {
    echo "<tr class='pt_edit'>
  <td></td>
  <td class='pt_entry'><table class='pt_buttons'>\n";
    $buttons = array();
    if ($prow->withdrawn > 0 && ($Conf->timeFinalizePaper() || $Me->amAssistant()))
	$buttons[] = "<input class='button' type='submit' name='revive' value='Revive paper' />";
    else if ($prow->withdrawn > 0)
	$buttons[] = "The paper has been withdrawn, and the deadline for reviving it has passed.";
    else {
	if ($prow->acknowledged <= 0) {
	    if ($Conf->timeUpdatePaper() || $Me->amAssistant())
		$buttons[] = array("<input class='button' type='submit' name='update' value='Save changes' />", "(does not submit paper)");
	    if ($Conf->timeFinalizePaper() || $Me->amAssistant())
		$buttons[] = array("<input class='button_default' type='submit' name='submit' value='Submit paper' />", "(cannot undo)");
	} else if ($Me->amAssistant())
	    $buttons[] = array("<input class='button' type='submit' name='unsubmit' value='Undo submit' />", "(PC chair only)"); 
	$buttons[] = "<input class='button' type='submit' name='withdraw' value='Withdraw paper' />";
    }
    echo "    <tr>";
    foreach ($buttons as $b) {
	$x = (is_array($b) ? $b[0] : $b);
	echo "<td class='ptb_button'>", $x, "</td>";
    }
    echo "</tr>\n    <tr>";
    foreach ($buttons as $b) {
	$x = (is_array($b) ? $b[1] : "");
	echo "<td class='ptb_explain'>", $x, "</td>";
    }
    echo "</tr>\n  </table></td>\n</tr>\n\n";
}


// End
echo "</table>\n";
if ($editMode)
    echo "</form>\n";
echo "<div class='clear'></div>\n\n";

$Conf->footer(); ?>
