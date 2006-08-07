<?php 
require_once('Code/confHeader.inc');
require_once('Code/papertable.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();


// header
function confHeader() {
    global $paperId, $prow, $Conf;
    $title = ($paperId > 0 ? "Paper #$paperId Review Requests" : "Paper Review Requests");
    $Conf->header($title, "revreq", actionBar($prow, false, "Review requests", "${ConfSiteBase}reqreview.php?paperId=$paperId"));
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
    else if (!$Me->canStartReview($prow, $Conf, $whyNot))
	errorMsgExit(whyNotText($whyNot, "review", $paperId));
}

getProw($Me->contactId);


confHeader();


$rf = reviewForm();

if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  Any changes were lost.");


// begin table
echo "<table class='reviewformtop'>\n\n";


// title
echo "<tr class='id'>\n  <td class='caption'><h2>#", $paperId, "</h2></td>\n";
echo "  <td class='entry' colspan='2'><h2>", htmlspecialchars($prow->title), "</h2></td>\n</tr>\n\n";


// paper table
$canViewAuthors = $Me->canViewAuthors($prow, $Conf, true);
$paperTable = new PaperTable(false, false, true, !$canViewAuthors && $Me->amAssistant());

$paperTable->echoStatusRow($prow, PaperTable::STATUS_DOWNLOAD);
$paperTable->echoAbstractRow($prow);
if ($canViewAuthors || $Me->amAssistant()) {
    $paperTable->echoAuthorInformation($prow);
    $paperTable->echoContactAuthor($prow);
    $paperTable->echoCollaborators($prow);
}
$paperTable->echoTopics($prow);


// reviewer information
if (($revTable = reviewersTable($prow->paperId, null, true))) {
    echo "<tr class='rev_reviewers'>\n";
    echo "  <td class='caption'>Reviewers</td>\n";
    echo "  <td class='entry'><table class='reviewers'>\n", $revTable;
    echo "    <tr><td colspan='4'><input class='textlite' type='text' name='name' value='Name' /><input class='textlite' type='text' name='email' value='Email' /></td><td><input class='button_small' type='submit' name='add' value='Request a review'/></td></tr>\n";
    echo "  </table></td>\n";
    echo "</tr>\n\n";
}


// close this table
echo "</table>\n\n";

echo "<div class='gapbottom'></div>\n";

$Conf->footer(); ?>
