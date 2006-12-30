<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../index.php');
$rf = reviewForm();

$Conf->header("Send Mail");

echo "<form method='post' action='SendMail2.php' enctype='multipart/form-data'>
<table class='form'>
<tr>
  <td class='caption'>Mail to</td>
  <td class='entry'><select name='recipients'>
    <option value='submit-not-finalize'>Contact authors who started, but didn't submit a paper</option>
    <option value='submit-and-finalize'>Contact authors who submitted a paper</option>
    <option value='review-finalized'>Reviewers who submitted at least one review</option>
    <option value='review-not-finalize'>Reviewers who haven't submitted at least one review</option>\n";
foreach ($rf->options["outcome"] as $num => $what)
    echo "    <option value='author-outcome$num'>", htmlspecialchars($what), " outcome contact authors</option>\n";
echo "    <option value='author-late-review'>Contact authors who received late reviews</option>
  </select></td>
</tr>

<tr>
  <td class='caption'>Subject</td>
  <td class='entry'><tt>[", htmlspecialchars($Conf->shortName), "]&nbsp;</tt><input type='text' class='textlite-tt' name='subject' value='Paper #%NUMBER%' size='64' /></td>
</tr>

<tr>
  <td class='caption'>Body</td>
  <td class='entry'>Type the email you want to send.
    Use %FIRST%, %LAST% and %EMAIL% for contact details, and %TITLE%
    and %NUMBER% for paper details. For emailing reviews to authors, use
    %REVIEWS% and %COMMENTS%.

    <textarea class='tt' rows='20' name='emailBody' cols='80'>Greetings,

         Title: %TITLE%
    Paper site: ", htmlspecialchars($Conf->paperSite), "/paper.php?paperId=%NUMBER%

Your message here.

", wordwrap("Contact the site administrator, $Conf->contactName <$Conf->contactEmail>, with any questions or concerns.

- $Conf->shortName Conference Submissions"), "\n</textarea></td>
</tr>

<tr>
  <td class='caption'></td>
  <td class='entry'><input type='submit' name='send' value='Send' class='button' /></td>
</tr>

</table>
</form>\n";

$Conf->footer();
