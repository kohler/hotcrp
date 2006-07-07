<?php 
include('../Code/confHeader.inc');
$Conf->connect();
$_SESSION["Me"]->goIfInvalid("../");
$_SESSION["Me"]->goIfNotChair('../');
include('Code.inc');
$Conf->header_head("Set Review Form");

$reviewFields = array('overAllMerit' => 2,
		      'reviewerQualification' => 2,
		      'novelty' => 2,
		      'technicalMerit' => 2,
		      'interestToCommunity' => 2,
		      'longevity' => 2,
		      'grammar' => 2,
		      'likelyPresentation' => 2,
		      'suitableForShort' => 2,
		      'paperSummary' => 1,
		      'commentsToAuthor' => 1,
		      'commentsToPC' => 1,
		      'commentsToAddress' => 1,
		      'weaknessOfPaper' => 1,
		      'strengthOfPaper' => 1,
		      'potential' => 2,
		      'fixability' => 2);

?>
<script type="text/javascript"><!--
function highlightUpdate(id) {
    var ins = document.getElementsByTagName("input");
    for (var i = 0; i < ins.length; i++)
	if (ins[i].name == "update")
	    ins[i].className = "button_alert";
    if (id) {
	var chg = document.getElementById("chg" + id);
	if (chg.value == "")
	    chg.value = "chg";
    }
}
function doRemove(id) {
    var but = document.getElementById("rem" + id);
    var chg = document.getElementById("chg" + id);
    var row = document.getElementById("pcrow" + id);
    chg.value = (but.value == "Remove" ? "rem" : "chg");
    var x = row.className.replace(/ *removed/, '');
    row.className = x + (but.value == "Remove" ? " removed" : "");
    but.value = (but.value == "Remove" ? "Do not remove" : "Remove");
    highlightUpdate(id);
}
// -->
</script>

<?php $Conf->header("Set Review Form") ?>

<table class='setreviewform'>
<?php
$result = $Conf->qe("select * from ReviewFormField order by sortOrder", "while loading review form");
if (DB::isError($result)) {
    $Conf->footer();
    exit;
 }

function showFormComponent($which) {
    global $Conf, $shortNames, $fieldName, $shortName;
    echo "<tr>\n";
    echo "<td>\n";
    echo "  <select name='form[]'>\n";
    
    echo "    <option value='none'";
    if ($which == null || $which == "none")
	echo " selected='selected'";
    echo ">(None)</option>\n";
    
    foreach ($shortNames as $name) {
	echo "    <option value='" . $fieldName[$name] . "'";
	if ($fieldName[$name] == $which)
	    echo " selected='selected'";
	echo ">", htmlspecialchars($name), "</option>\n";
    }
    echo "    </select>\n  </td>\n";

    echo "</tr>\n";
}

while ($row = $result->fetchRow(DB_FETCHMODE_OBJECT)) {
    $shortName[$row->fieldName] = $row->shortName;
    $fieldName[$row->shortName] = $row->fieldName;
    $description[$row->description] = $row->description;
    if ($row->sortOrder >= 0)
	$shown[] = $row->fieldName;
 }

$shortNames = array_values($shortName);
sort($shortNames);

foreach ($shown as $fn)
    showFormComponent($fn);

?>

</table>
</form>

</div>
<?php $Conf->footer() ?>
</body>
</html>
