<?php 
require_once('Code/confHeader.inc');
require_once('Code/papertable.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();


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
function getProw($paperId, $submit, &$tf = null) {
    global $Conf, $Me;
    if (($prow = $Conf->paperRow($paperId, $Me->contactId, $whyNot))
	&& ($submit
	    ? $Me->canSubmitReview($prow, $Conf, $whyNot)
	    : $Me->canViewPaper($prow, $Conf, $whyNot)))
	return $prow;
    else
	return errorMsgExit(whyNotText($whyNot, "review"), $tf);
}

function getRrow($paperId, $reviewId = -1, $must = false, $tf = null) {
    global $Conf, $Me, $rrowError;
    $rrowError = false;
    if ($reviewId > 0)
	$x = array("reviewId" => $reviewId);
    else
	$x = array("paperId" => $paperId, "contactId" => $Me->contactId);
    if (($rrow = $Conf->reviewRow($x, $whyNot)))
	return $rrow;
    if ($must || $reviewId > 0 || !isset($whyNot['noReview'])) {
	$rrowError = true;
	errorMsgExit(whyNotText($whyNot, "review"), $tf);
    }
    return null;
}


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  Any changes were lost.");


// upload review form
if (isset($_REQUEST['uploadForm']) && fileUploaded($_FILES['uploadedFile'], $Conf)) {
    $originalPaperId = $_REQUEST["paperId"];
    $tf = $rf->beginTextForm($_FILES['uploadedFile']['tmp_name'], $_FILES['uploadedFile']['name']);
    while ($rf->parseTextForm($tf, $Conf)) {
	if (($prow = getProw($_REQUEST['paperId'], true, $tf))
	    && (($rrow = getRrow($_REQUEST['paperId'], -1, false, $tf))
		|| !$rrowError)
	    && $rf->checkRequestFields($rrow, 0, $tf)) {
	    $result = $rf->saveRequest($prow, $Me->contactId, $rrow, 0);
	    if (!DB::isError($result))
		$tf['confirm'][] = "Uploaded review for paper #$prow->paperId.";
	}
    }
    $rf->parseTextFormErrors($tf, $Conf);
    if (isset($_REQUEST['redirect']) && $_REQUEST['redirect'] == 'offline')
	go("${ConfSiteBase}uploadreview.php");
    $_REQUEST['paperId'] = $originalPaperId;
}


// get paper and review rows; exit if requested review is not visible,
// or no requested review and paper is not reviewable
if (isset($_REQUEST["reviewId"])) {
    if (!($rrow = getRrow(-1, $_REQUEST['reviewId'], true))
	|| !($prow = getProw($rrow->paperId, false))
	|| !$Me->canViewReview($prow, $rrow, $Conf, $whyNot))
	errorMsgExit(whyNotText($whyNot, "review"));
} else if (isset($_REQUEST["paperId"])) {
    $prow = getProw($_REQUEST["paperId"], false);
    $rrow = getRrow($prow->paperId, -1, false);
    if ($rrow ? !$Me->canViewReview($prow, $rrow, $Conf, $whyNot)
	: !$Me->canReview($prow, $Conf, $whyNot))
	errorMsgExit(whyNotText($whyNot, "review"));
} else
    $prow = $rrow = null;
$paperId = ($prow ? $prow->paperId : -1);


// download form
if (isset($_REQUEST['downloadForm'])) {
    $x = $rf->textFormHeader($Conf);
    $x .= $rf->textForm($prow, $rrow, $Conf, $prow->reviewType > 0,
			($prow->reviewType > 0
			 || ($Me->isPC && $prow->conflict <= 0)
			 || ($Me->amAssistant() && isset($_REQUEST['forceShow']))));
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
    if (!$Me->canSubmitReview($prow, $Conf, $whyNot))
	$Conf->errorMsg(whyNotText($whyNot, "review"));
    else if ($rf->checkRequestFields($rrow, isset($_REQUEST['submit']))) {
	$rf->saveRequest($prow, $Me->contactId, $rrow, isset($_REQUEST['submit']), $Conf);
	$Conf->confirmMsg(isset($_REQUEST['submit']) ? "Review submitted." : "Review saved.");
	$rrow = getRrow($prow->paperId, ($rrow ? $rrow->reviewId : -1), true);
    }


// messages for review viewers
if (!$Me->timeReview($prow, $Conf))
    $Conf->infoMsg("The <a href='${ConfSiteBase}deadlines.php'>deadline</a> for modifying this review has passed.");


// begin table
echo "<table class='reviewformtop'>\n\n";


// title
echo "<tr class='id'>\n  <td class='caption'><h2>Review #", $paperId;
if ($rrow && $rrow->reviewSubmitted > 0)
    echo unparseReviewOrdinal($rrow->reviewOrdinal);
else
    echo "x";
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
if (($revTable = reviewersTable($prow, (isset($rrow) ? $rrow->reviewId : -1)))) {
    echo "<tr class='rev_reviewers'>\n";
    echo "  <td class='caption'>Reviewers</td>\n";
    echo "  <td class='entry'><table class='reviewers'>\n", $revTable, "  </table></td>\n";
    echo "</tr>\n\n";
}


// extra space
echo "<tr>\n  <td class='caption'></td>\n  <td class='entry'><hr class='smgap' /></td>\n</tr>\n\n";


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
