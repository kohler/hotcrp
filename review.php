<?php 
require_once('Code/confHeader.inc');
require_once('Code/papertable.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();


// header
function confHeader() {
    global $paperId, $prow, $mode, $Conf;
    if ($paperId > 0)
	$title = "Review Paper #$paperId";
    else
	$title = "Review Papers";
    $Conf->header($title, "review", actionBar($prow, false, "editreview"));
}

function errorMsgExit($msg, &$tf = null) {
    global $Conf;
    if ($tf == null) {
	confHeader();
	$Conf->errorMsgExit($msg);
    } else {
	$tf['err'][] = $tf['firstLineno'] . ": $msg";
	return null;
    }
}


//  
function get_prow($paperIdIn, &$tf = null) {
    global $Conf, $prow, $Me;

    if (($paperId = cvtint(trim($paperIdIn))) <= 0)
	return ($prow = errorMsgExit("Bad paper ID \"" . htmlentities($paperIdIn) . "\".", $tf));
    
    $result = $Conf->qe($Conf->paperQuery($Me->contactId, array("paperId" => $paperId)), "while requesting paper to review");
    if (DB::isError($result) || $result->numRows() == 0)
	$prow = errorMsgExit("No such paper #$paperId.", $tf);
    else {
	$prow = $result->fetchRow(DB_FETCHMODE_OBJECT);
	if (!$Me->canStartReview($prow, $Conf, $whyNot))
	    $prow = errorMsgExit(whyNotText($whyNot, "review", $prow->paperId), $tf);
    }
}

function get_rrow($paperId, $reviewId = -1, $tf = null) {
    global $Conf, $rrow, $Me, $reviewOrdinal;

    $q = "select PaperReview.*, firstName, lastName, email,
		count(PRS.reviewId) as reviewOrdinal
		from PaperReview
		join ContactInfo using (contactId)
		left join PaperReview as PRS on (PRS.paperId=PaperReview.paperId and PRS.reviewSubmitted>0 and PRS.reviewSubmitted<PaperReview.reviewSubmitted)";
    if ($reviewId > 0)
	$q = "$q where PaperReview.reviewId=$reviewId";
    else
	$q = "$q where PaperReview.paperId=$paperId and PaperReview.contactId=$Me->contactId";
    $result = $Conf->qe("$q group by PRS.paperId", "while retrieving reviews");

    if (DB::isError($result) || $result->numRows() == 0) {
	if ($reviewId > 0)
	    errorMsgExit("No such paper review #$reviewId.", $tf);
	$rrow = null;
    } else {
	$rrow = $result->fetchRow(DB_FETCHMODE_OBJECT);
	$_REQUEST['reviewId'] = $rrow->reviewId;
    }
}

$rf = reviewForm();

$originalPaperId = cvtint($_REQUEST["paperId"]);

if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  Any changes were lost.");

if (isset($_REQUEST['uploadForm']) && fileUploaded($_FILES['uploadedFile'], $Conf)) {
    $tf = $rf->beginTextForm($_FILES['uploadedFile']['tmp_name'], $_FILES['uploadedFile']['name']);
    $paperId = $originalPaperId;
    while ($rf->parseTextForm($tf, $originalPaperId, $Conf)) {
	get_prow($_REQUEST['paperId'], $tf);
	get_rrow($_REQUEST['paperId'], -1, $tf);
	if ($prow != null && $rf->validateRequest($rrow, 0, $tf)) {
	    $result = $rf->saveRequest($prow, $Me->contactId, $rrow, 0);
	    if (!DB::isError($result))
		$tf['confirm'][] = "Uploaded review for paper #$prow->paperId.";
	}
	$paperId = -1;
    }
    $rf->parseTextFormErrors($tf, $Conf);
    if (isset($_REQUEST['redirect']) && $_REQUEST['redirect'] == 'offline')
	go("${ConfSiteBase}uploadreview.php");
 }

$paperId = $originalPaperId;
if (isset($_REQUEST["reviewId"])) {
    get_rrow(-1, cvtint(trim($_REQUEST["reviewId"])));
    if ($Me->contactId != $rrow->contactId && !$Me->amAssistant())
	errorMsgExit("You did not create review #$rrow->reviewId, so you cannot edit it.");
    $paperId = $rrow->paperId;
    get_prow($paperId);
} else if ($paperId > 0) {
    get_prow($paperId);
    get_rrow($paperId);
} else
    $prow = $rrow = null;

if (isset($_REQUEST['downloadForm'])) {
    $isReviewer = ($Me->isReviewer || $Me->isPC);
    $x = $rf->textFormHeader($Conf);
    $x .= $rf->textForm($paperId, $Conf, $prow, $rrow, $isReviewer, $isReviewer);
    header("Content-Description: PHP Generated Data");
    header("Content-Disposition: attachment; filename=" . $Conf->downloadPrefix . "review" . ($paperId > 0 ? "-$paperId.txt" : ".txt"));
    header("Content-Type: text/plain");
    header("Content-Length: " . strlen($x));
    print $x;
    exit;
 }


confHeader();


if ($paperId <= 0) {
    $Conf->errorMsg("No paper selected to review.");
    $Conf->footer();
    exit;
 }

if (isset($_REQUEST['update']) || isset($_REQUEST['submit']))
    if ($rf->validateRequest($rrow, isset($_REQUEST['submit']))) {
	$rf->saveRequest($prow, $Me->contactId, $rrow, isset($_REQUEST['submit']), $Conf);
	$Conf->confirmMsg(isset($_REQUEST['submit']) ? "Review submitted." : "Review saved.");
	get_rrow($paperId);
    }

$overrideMsg = '';
if ($Me->amAssistant())
    $overrideMsg = "  Select the \"Override deadlines\" checkbox and try again if you really want to override this deadline.";

if (!$Me->timeReview($prow, $Conf))
    $Conf->infoMsg("The <a href='${ConfSiteBase}deadlines.php'>deadline</a> for modifying this review has passed.");


// begin table
echo "<table class='reviewformtop'>\n\n";


// title
echo "<tr class='id'>\n  <td class='caption'><h2>Review #", $paperId;
if ($rrow && $rrow->reviewSubmitted > 0)
    echo unparseReviewOrdinal($rrow->reviewOrdinal);
echo "</h2></td>\n";
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
if (($revTable = reviewersTable($prow->paperId, (isset($rrow) ? $rrow->reviewId : -1)))) {
    echo "<tr class='rev_reviewers'>\n";
    echo "  <td class='caption'>Reviewers</td>\n";
    echo "  <td class='entry'><table class='reviewers'>\n", $revTable, "  </table></td>\n";
    echo "</tr>\n\n";
}


// review information
// XXX reviewer ID
// XXX "<td class='entry'>", htmlspecialchars(contactText($rrow)), "</td>"
echo "<tr class='rev_rev'>\n";
echo "  <td class='caption'></td>\n";
echo "  <td class='entry'>";
// echo reviewStatus((isset($rrow) ? $rrow : $prow), true, true), "; ",
//    reviewType($paperId, $prow, true), "<br/>";
echo "<form class='downloadreviewform' action='review.php' method='get'>",
    "<input type='hidden' name='paperId' value='$paperId' />",
    "<input class='button_small' type='submit' value='Download form' name='downloadForm' id='downloadForm' />",
    "</form>";
echo "<form class='downloadreviewform' action='review.php?form=1' method='post' enctype='multipart/form-data'>",
    "<input type='hidden' name='paperId' value='$paperId' />",
    "<input type='file' name='uploadedFile' accept='text/plain' size='30' />&nbsp;<input class='button_small' type='submit' value='Upload form' name='uploadForm' />",
    "</form>";
echo "</td>\n";
echo "</tr>\n\n";


// extra space
echo "<tr>\n  <td class='caption'></td>\n  <td class='entry'>&nbsp;</td>\n</tr>\n\n";


// close this table
echo "</table>\n\n";


// start review form
echo "<form action='review.php?";
if (isset($rrow))
    echo "reviewId=", $rrow->reviewId;
else
    echo "paperId=", $prow->paperId;
if (isset($_REQUEST['forceShow']) && $_REQUEST['forceShow'])
    echo "&amp;forceShow=1";
echo "&amp;post=1' method='post' enctype='multipart/form-data'>\n";
echo "<table class='reviewform'>\n";


// form body
echo $rf->webFormRows($rrow, 1);


// review actions
if ($Me->timeReview($prow, $Conf) || $Me->amAssistant()) {
    echo "<tr class='rev_actions'>
  <td class='caption'></td>
  <td class='entry'><table class='pt_buttons'>
    <tr>\n";
    if (!isset($rrow) || !$rrow->reviewSubmitted) {
	echo "      <td class='ptb_button'><input class='button_default' type='submit' value='Save changes' name='update' /></td>
      <td class='ptb_button'><input class='button_default' type='submit' value='Submit' name='submit' /></td>
    </tr>
    <tr>
      <td class='ptb_explain'>(does not submit review)</td>
      <td class='ptb_explain'>(allow PC to see review)</td>\n";
    } else
	echo "      <td class='ptb_button'><input class='button_default' type='submit' value='Resubmit' name='submit' /></td>\n";
    if (!$Me->timeReview($prow, $Conf))
	echo "    </tr>\n    <tr>\n      <td colspan='3'><input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines</td>\n";
    echo "    </tr>\n  </table></td>\n</tr>\n\n";
}

echo "</table>\n</form>\n\n";

echo "<div class='gapbottom'></div>\n";

$Conf->footer(); ?>
