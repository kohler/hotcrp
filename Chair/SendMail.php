<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid("../index.php");
$_SESSION[Me] -> goIfNotChair('../index.php');
$Conf -> connect();
?>

<html>

<?php  $Conf->header("Send Mail To Various Groups Of People") ?>

<body>

<FORM METHOD="POST" ACTION="SendMail2.php">

<p> <input type="submit" value="Prepare Mail" name="submit"> </p>


<p> Select the parties to whom you wish to send mail:
<SELECT name=recipients>

<OPTION value="submit-not-finalize">
People who submited, but didn't finalize a paper.
</OPTION>

<OPTION value="submit-and-finalize">
People who submited AND finalized a paper
</OPTION>

<OPTION value="asked-to-review">
People who were ASKED (<i> started or not </i>) to review a paper and have not finalized that review
</OPTION>

<OPTION value="review-not-finalize">
People who STARTED a paper review and DID NOT finalize that review
</OPTION>

<OPTION value="author-accepted">
Contacts for papers selected for the conference
</OPTION>

<OPTION value="author-rejected">
Contacts for papers <b> not </b> selected for the conference
</OPTION>

<OPTION value="review-finalized">
Anyone who reviewed a paper and finalized that review
</OPTION>

<OPTION value="author-late-review">
Anyone who received late reviews.
</OPTION>

</SELECT>
</p>

<p> 
 Now, type the email you want to send. Use %FIRST%, %LAST% and %EMAIL%
for contact details; %TITLE% and %NUMBER% for paper details. For
emailing reviews to authors, use %REVIEWS% and %COMMENTS%.
 </p>

<textarea rows=30 name="emailBody" cols=75></textarea>

<p> <input type="submit" value="Prepare Mail" name="submit"> </p>

</FORM>

</body>
<?php  $Conf->footer() ?>
</html>


