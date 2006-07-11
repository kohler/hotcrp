<?php 
require_once('Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid("../");

$Conf->header("Offline Reviewing", 'offrev');

if ($Me->amReviewer())
    $Conf->infoMsg("Use this site to download a blank review form, or to upload a review form you've already filled out.");
else
    $Conf->infoMsg("You aren't registered as a reviewer or PC member for this conference, but for your information, you may download the review form anyway.");

?>

<table
<tr>
  <td class='form_entry'><form method='get' action='All/ReviewPaper.php'><input class='button_default' type='submit' name='downloadForm' value='Download review form' /></form>

<?php if ($Me->amReviewer()) { ?>
    <form class='upload' action='All/ReviewPaper.php' method='post' enctype='multipart/form-data'>
      <input type='hidden' name='redirect' value='offline' />
      <input type='file' name='uploadedFile' accept='text/plain' size='30' />&nbsp;<input class='button_default' type='submit' value='Upload review form' name='uploadForm' />
    </form>
<?php } ?>
  </td>
</tr>
</table>

<?php
$rf = reviewForm();
$text = $rf->webGuidanceRows($Me->amReviewer());
if ($text)
    echo "<hr/>\n\n<h3>Review form guidance</h3>\n<table>\n", $text, "</table>\n";
?>

<?php $Conf->footer() ?>
