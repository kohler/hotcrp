<?php 
require_once('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../');
$Conf->header("Edit Review Form");
$rf = reviewForm();

function checkOptions(&$var, &$options, &$order) {
    if (!isset($var))
	return false;
    $var = str_replace("\r\n", "\n", $var);
    $var = str_replace("\r", "\n", $var);
    $expect = 1;
    $text = '';
    $options = array();
    foreach (explode("\n", $var) as $line) {
	$line = trim($line);
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
    $while = "while updating review form";
    $scoreModified = array();
    $Conf->qe("lock tables ReviewFormField write, PaperReview write, ReviewFormOptions write");
    
    foreach (array_keys($reviewFields) as $field) {
	$req = '';
	if (isset($_REQUEST["shortName_$field"]))
	    $req .= "shortName='" . sqlq($_REQUEST["shortName_$field"]) . "', ";
	if (isset($_REQUEST["authorView_$field"]))
	    $req .= "authorView=1, ";
	else
	    $req .= "authorView=0, ";
	if (isset($_REQUEST["description_$field"]))
	    $req .= "description='" . sqlq($_REQUEST["description_$field"]) . "', ";
	if (isset($_REQUEST["order_$field"]))
	    $req .= "sortOrder='" . cvtint($_REQUEST["order_$field"]) . "', ";
	if ($reviewFields[$field]) {
	    if (checkOptions($_REQUEST["options_$field"], $options, $_REQUEST["order_$field"])) {
		$Conf->qe("delete from ReviewFormOptions where fieldName='" . sqlq($field) . "'", $while);
		for ($i = 1; $i <= count($options); $i++)
		    $Conf->qe("insert into ReviewFormOptions (fieldName, level, description) values ('" . sqlq($field) . "', $i, '" . sqlq($options[$i]) . "')", $while);
		
		$result = $Conf->qe("update PaperReview set $field=0 where $field>" . count($options), $while);
		if (!DB::isError($result) && $Conf->DB->affectedRows() > 0)
		    $scoreModified[] = htmlspecialchars($_REQUEST["shortName_$field"]);
		
		unset($_REQUEST["options_$field"]);
		$updates = 1;
	    } else {
		$FormError[$field] = 1;
		$optionError = 1;
		continue;
	    }
	}
	if ($req != '') {
	    $result = $Conf->qe("update ReviewFormField set " . substr($req, 0, -2) . " where fieldName='" . sqlq($field) . "'", $while);
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

    if (count($scoreModified))
	$Conf->warnMsg("Your changes invalidated some existing review scores.  The invalid scores have been reset to \"Unknown\".  The relevant fields were: " . join(", ", $scoreModified) . ".");

    $Conf->qe("unlock tables");

    // alert consumers of change to form
    if (isset($updates)) {
	$Conf->qe("delete from ImportantDates where name='reviewFormUpdate'"); 
	$Conf->qe("insert into ImportantDates (name, start) values ('reviewFormUpdate', current_timestamp)");
	$Conf->confirmMsg("Review form updated.");
	$rf->validate($Conf, true);
    }
}

?>

<form action='SetReviewForm.php' method='post'>
<table class='setreviewform'>

<tr>
  <th>Field name</th>
  <th>Form position</th>
  <th class='entry'>Description (HTML allowed)</th>
  <th class='entry'>Options</th>
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
    global $rf, $reviewFields, $FormError;

    $x = "<tr class='setrev_$row->fieldName'>\n";
    $x .= "  <td class='setrev_shortName";
    if ($FormError[$row->fieldName])
	$x .= " error";
    $x .= "'><input class='textlite' type='text' name='shortName_$row->fieldName' value=\"" . htmlentities(getField($row, 'shortName')) . "\" onchange='highlightUpdate()' />\n";

    // special case checkbox
    $x .= "<p><input type='checkbox' name='authorView_$row->fieldName' value='1' ";
    if (isset($_REQUEST["shortName_$row"]) && !isset($_REQUEST["authorView_$row"]))
	$_REQUEST["authorView_$row"] = 0;
    if (getField($row, 'authorView') > 0)
	$x .= "checked='checked' ";
    $x .= "/>&nbsp;Visible&nbsp;to&nbsp;authors</p></td>\n";
    
    $x .= "  <td class='entry'><select name='order_$row->fieldName' onchange='highlightUpdate()'>\n";
    $x .= "    <option value='-1'";
    $order = getField($row, 'order', $ordinalOrder);
    if ($order < 0)
	$x .= " selected='selected'";
    $x .= ">Not on form</option>\n";
    for ($i = 0; $i < $numRows; $i++) {
	$x .= "    <option value='$i'";
	if ($order == $i)
	    $x .= " selected='selected'";
	$x .= ">" . ($i + 1) . "</option>\n";
    }
    $x .= "  </select></td>\n";

    $x .= "  <td class='entry textarea'><textarea name='description_$row->fieldName' rows='6' cols='30' onchange='highlightUpdate()'>";
    $x .= htmlentities(getField($row, 'description'));
    $x .= "</textarea></td>\n";

    if (isset($rf->options[$row->fieldName]) || $reviewFields[$row->fieldName]) {
	$x .= "  <td class='entry textarea'><textarea name='options_$row->fieldName' rows='6' onchange='highlightUpdate()'>";
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

<?php $Conf->footer() ?>
