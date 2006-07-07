<?php 
include('../Code/confHeader.inc');
$Conf->connect();
$_SESSION["Me"]->goIfInvalid("../");
$_SESSION["Me"]->goIfNotChair('../');
include('Code.inc');
$Conf->header_head("Set Review Form");

$haveOptions = array('paperSummary' => 0,
		     'commentsToAuthor' => 0,
		     'commentsToPC' => 0,
		     'commentsToAddress' => 0,
		     'weaknessOfPaper' => 0,
		     'strengthOfPaper' => 0,
		     'potential' => 1,
		     'fixability' => 1,
		     'overAllMerit' => 1,
		     'reviewerQualification' => 1,
		     'novelty' => 1,
		     'technicalMerit' => 1,
		     'interestToCommunity' => 1,
		     'longevity' => 1,
		     'grammar' => 1,
		     'likelyPresentation' => 1,
		     'suitableForShort' => 1);

$rf = reviewForm();

?>
<script type="text/javascript"><!--
function highlightUpdate() {
    var ins = document.getElementsByTagName("input");
    for (var i = 0; i < ins.length; i++)
	if (ins[i].name == "update")
	    ins[i].className = "button_alert";
}
// -->
</script>

<?php $Conf->header("Set Review Form");

function checkOptions(&$var, &$options, &$order) {
    if (!isset($var))
	return false;
    $var = str_replace("\r\n", "\n", $var);
    $var = str_replace("\r", "\n", $var);
    $expect = 1;
    $text = '';
    $options = array();
    foreach (explode("\n", $var) as $line) {
	$line = ltrim(rtrim($line));
	if ($line != "") {
	    if (preg_match("/^$expect\\.\\s*(\\S.*)/", $line, $matches)
		|| preg_match("/^$expect\\s+(\\S.*)/", $line, $matches)) {
		$text .= "$expect. $matches[1]\n";
		$options[$expect++] = $matches[1];
	    } else
		return false;
	}
    }
    $var = $text;
    return ($expect >= 2 || (isset($order) && cvtint($order) < 0));
}

if (isset($_REQUEST['update'])) {
    foreach (array_keys($haveOptions) as $field) {
	$req = '';
	if (isset($_REQUEST["shortName_$field"]))
	    $req .= "shortName='" . sqlq($_REQUEST["shortName_$field"]) . "', ";
	if (isset($_REQUEST["description_$field"]))
	    $req .= "description='" . sqlq($_REQUEST["description_$field"]) . "', ";
	if (isset($_REQUEST["order_$field"]))
	    $req .= "sortOrder='" . cvtint($_REQUEST["order_$field"]) . "', ";
	if ($haveOptions[$field]) {
	    if (checkOptions($_REQUEST["options_$field"], $options, $_REQUEST["order_$field"])) {
		$Conf->qe("delete from ReviewFormOptions where fieldName='" . sqlq($field) . "'", "while updating review form");
		for ($i = 1; $i <= count($options); $i++)
		    $Conf->qe("insert into ReviewFormOptions set fieldName='" . sqlq($field) . "', level=$i, description='" . sqlq($options[$i]) . "'", "while updating review form");
		unset($_REQUEST["options_$field"]);
		$updates = 1;
	    } else {
		$FormError[$field] = 1;
		$optionError = 1;
		continue;
	    }
	}
	if ($req != '') {
	    $result = $Conf->qe("update ReviewFormField set " . substr($req, 0, -2) . " where fieldName='" . sqlq($field) . "'", "while updating review form");
	    if (!DB::isError($result)) {
		unset($_REQUEST["order_$field"], $_REQUEST["shortName_$field"], $_REQUEST["description_$field"]);
		$updates = 1;
	    }
	}
    }
    
    if (isset($optionError))
	$Conf->errorMsg("Review fields with options must have at least two choices, numbered sequentially from 1.  Enter them like this:  <pre>1. Low quality
2. Medium quality
3. High quality</pre>  Please edit the highlighted fields and save again.");

    // alert consumers of change to form
    if (isset($updates)) {
	$Conf->qe("update ImportantDates set name='reviewFormUpdate', start=current_timestamp, end=current_timestamp", "while updating review form");
	$Conf->confirmMsg("Review form updated.");
	$rf->validate($Conf);
    }
 }

?>

<form action='SetReviewForm.php' method='post'>
<table class='setreviewform'>

<tr>
  <th>Field name</th>
  <th>Form position</th>
  <th>Description (HTML allowed)</th>
  <th>Options</th>
</tr>

<?php

function getField($row, $name, $ordinalOrder = null) {
    if (isset($_REQUEST["${name}_$row->fieldName"]))
	return $_REQUEST["${name}_$row->fieldName"];
    else if ($ordinalOrder === null)
	return $row->$name;
    else
	return $ordinalOrder;
}

function formFieldText($row, $ordinalOrder, $numRows) {
    global $rf, $haveOptions, $FormError;

    $x = "<tr class='setrev_$row->fieldName'>\n";
    $x .= "  <td class='form_caption";
    if ($FormError[$row->fieldName])
	$x .= " error";
    $x .= "'><input class='textlite' type='text' name='shortName_$row->fieldName' value=\"" . htmlentities(getField($row, 'shortName')) . "\" onchange='highlightUpdate()' /></td>\n";
    
    $x .= "  <td class='form_entry'><select name='order_$row->fieldName' onchange='highlightUpdate()'>\n";
    $x .= "    <option value='-1'";
    $order = getField($row, 'order', $ordinalOrder);
    if ($order < 0)
	$x .= " selected='selected'";
    $x .= ">Not shown</option>\n";
    for ($i = 0; $i < $numRows; $i++) {
	$x .= "    <option value='$i'";
	if ($order == $i)
	    $x .= " selected='selected'";
	$x .= ">" . ($i + 1) . "</option>\n";
    }
    $x .= "  </select></td>\n";

    $x .= "  <td class='form_entry'><textarea name='description_$row->fieldName' rows='6' cols='30' onchange='highlightUpdate()'>";
    $x .= htmlentities(getField($row, 'description'));
    $x .= "</textarea></td>\n";

    if (isset($rf->options[$row->fieldName]) || $haveOptions[$row->fieldName]) {
	$x .= "  <td class='form_entry'><textarea name='options_$row->fieldName' rows='6' onchange='highlightUpdate()'>";
	$y = '';
	if (isset($rf->options[$row->fieldName])) {
	    for ($i = 1; $i <= count($rf->options[$row->fieldName]); $i++)
		$y .= "$i. " . $rf->options[$row->fieldName][$i] . "\n";
	}
	$x .= htmlentities(getField($row, 'options', $y));
	$x .= "</textarea></td>\n";
    }

    $x .= "</tr>\n";
    return $x;
}

$result = $Conf->qe("select * from ReviewFormField order by sortOrder, shortName", "while loading review form");
if (DB::isError($result)) {
    $Conf->footer();
    exit;
 }

$ordinalOrder = 0;
$notShown = '';
while ($row = $result->fetchRow(DB_FETCHMODE_OBJECT))
    if ($row->sortOrder < 0)
	$notShown .= formFieldText($row, -1, $result->numRows());
    else
	echo formFieldText($row, $ordinalOrder++, $result->numRows());
echo $notShown;

?>

<tr>
  <td colspan='4'><input class='button' type='submit' name='update' value='Save changes' id='submit' /></td>
</tr>

</table>
</form>

</div>
<?php $Conf->footer() ?>
</body>
</html>
