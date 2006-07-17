<?php 
require_once('Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid("../");

$Conf->header("Offline Reviewing", 'offrev');

$pastDeadline = !$Conf->timeReviewPaper($Me->isPC, true, true);

if ($Me->amReviewer()) {
    if ($pastDeadline)
	$Conf->infoMsg("The <a href='All/ImportantDates.php'>deadline</a> for submitting reviews has passed.");
    else
	$Conf->infoMsg("Use this site to download a blank review form, or to upload a review form you've already filled out.");
} else
    $Conf->infoMsg("You aren't registered as a reviewer or PC member for this conference, but for your information, you may download the review form anyway.");

?>

<table>
<tr class='pt_actions'>
  <td class='form_entry'><form method='get' action='All/ReviewPaper.php'><input class='button_default' type='submit' name='downloadForm' value='Download review form' /></form></td>

<?php if ($Me->amReviewer()) {
    $disabled = ($pastDeadline && !$Me->amAssistant() ? " disabled='disabled'" : "");
?>
  <td class='form_entry' id='upload'><table class='compact'>
    <tr>
      <td><form action='All/ReviewPaper.php?form=1' method='post' enctype='multipart/form-data'>
	<input type='hidden' name='redirect' value='offline' />
	<input type='file' name='uploadedFile' accept='text/plain' size='30' <?php echo $disabled ?>/>&nbsp;<input class='button_default' type='submit' value='Upload review form' name='uploadForm' <?php echo $disabled ?>/>
      </form></td>
    </tr>
    <?php if ($pastDeadline && $Me->amAssistant()) { ?>
    <tr>
      <td><input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines</td>
    </tr>
    <?php } ?>
  </table></td>			       
<?php } ?>
</tr>
</table>

<?php
$rf = reviewForm();
$text = $rf->webGuidanceRows($Me->amReviewer());
if ($text)
    echo "<hr/>\n\n<h3>Review form guidance</h3>\n<table>\n", $text, "</table>\n";
?>

<?php $Conf->footer() ?>
