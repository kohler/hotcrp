<?php 
require_once('../Code/header.inc');
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
		$optext = "";
		for ($i = 1; $i <= count($options); $i++)
		    $optext .= "('" . sqlq($field) . "', $i, '" . sqlq($options[$i]) . "'), ";
		if ($optext)
		    $Conf->qe("insert into ReviewFormOptions (fieldName, level, description) values " . substr($optext, 0, strlen($optext) - 2), $while);
		
		$result = $Conf->qe("update PaperReview set $field=0 where $field>" . count($options), $while);
		if (edb_nrows_affected($result, $Conf) > 0)
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
	    if ($result) {
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
	$t = time();
	$Conf->qe("insert into Settings (name, value) values ('revform_update', $t) on duplicate key update value=$t");
	$Conf->confirmMsg("Review form updated.");
	$rf->validate($Conf, true);
    }
}


function getField($row, $name, $ordinalOrder = null) {
    if (isset($_REQUEST["${name}_$row->fieldName"]))
	return $_REQUEST["${name}_$row->fieldName"];
    else if ($ordinalOrder === null)
	return $row->$name;
    else
	return $ordinalOrder;
}

function formFieldText($row, $ordinalOrder, $numRows) {
    global $rf, $reviewFields, $FormError, $rowidx, $captions;

    $rowidx = (isset($rowidx) ? $rowidx + 1 : 0);
    $trclass = "tr class='k" . ($rowidx % 2) . "'";
    $x = "<$trclass><td colspan='4'><div class='tinygap'></div></td></tr>\n";
    
    // field name
    $x .= "<$trclass><td colspan='4' class='";
    if ($FormError[$row->fieldName])
	$x .= "error ";
    $x .= "'>&nbsp;<b><input type='text' size='50' class='textlite' name='shortName_$row->fieldName' value=\""
	. htmlspecialchars(getField($row, 'shortName'))
	. "\" onchange='highlightUpdate()' /></b></td></tr>\n";

    // form position
    $x .= "<$trclass><td class='xcaption' nowrap='nowrap'><span class='lgsep'></span>Form position</td><td class='entry'>"
	. "<select name='order_$row->fieldName' onchange='highlightUpdate()'>\n"
	. "  <option value='-1'";
    $order = getField($row, 'order', $ordinalOrder);
    if ($order < 0)
	$x .= " selected='selected'";
    $x .= ">Off form</option>";
    for ($i = 0; $i < $numRows; $i++) {
	$x .= "<option value='$i'";
	if ($order == $i)
	    $x .= " selected='selected'";
	$x .= ">" . ($i + 1) . ($i == 0 ? "st" : ($i == 1 ? "nd" : ($i == 2 ? "rd" : "th"))) . "</option>";
    }
    $x .= "\n</select> <span class='sep'></span>";

    // author view
    $x .= "<input type='checkbox' name='authorView_" . $row->fieldName . "' value='1' ";
    if (isset($_REQUEST["shortName_$row"]) && !isset($_REQUEST["authorView_$row"]))
	$_REQUEST["authorView_$row"] = 0;
    if (getField($row, 'authorView') > 0)
	$x .= "checked='checked' ";
    $x .= "/>&nbsp;Visible&nbsp;to&nbsp;authors<td></tr>\n";

    // description
    $x .= "<$trclass><td class='xcaption'>Description</td>"
	. "<td class='entry'><textarea name='description_$row->fieldName' rows='6' cols='80' onchange='highlightUpdate()'>"
	. htmlentities(getField($row, 'description'))
	. "</textarea></td>";
    if (isset($captions['description'])) {
	$x .= "<td class='hint'>" . $captions['description'] . "</td>";
	unset($captions['description']);
    } else
	$x .= "<td></td>";
    $x .= "</tr>\n";

    // options
    if (isset($rf->options[$row->fieldName]) || $reviewFields[$row->fieldName]) {
	$x .= "<$trclass><td class='xcaption'>Options</td><td class='entry'><textarea name='options_$row->fieldName' rows='6' cols='80' onchange='highlightUpdate()'>";
	$y = '';
	if (isset($rf->options[$row->fieldName])) {
	    for ($i = 1; $i <= count($rf->options[$row->fieldName]); $i++)
		$y .= "$i. " . $rf->options[$row->fieldName][$i] . "\n";
	}
	$x .= htmlentities(getField($row, 'options', $y))
	    . "</textarea></td>";
	if (isset($captions['options'])) {
	    $x .= "<td class='hint'>" . $captions['options'] . "</td>";
	    unset($captions['options']);
	} else
	    $x .= "<td></td>";
	$x .= "</tr>\n";
    }
    
    return $x . "<$trclass><td colspan='4'><div class='tinygap'></div></td></tr>\n";
}

$result = $Conf->qe("select * from ReviewFormField order by sortOrder, shortName", "while loading review form");
if (!$result) {
    $Conf->footer();
    exit;
}


$captions = array
    ("description" => "Enter an HTML description for the review	field here,
	including any guidance you'd like to provide to reviewers.",
     "options" => "Enter the allowed options for this field, one per line,
	numbered starting from 1.  For example:
	<pre class='entryexample'>1. Reject
2. Weak reject
3. Weak accept
4. Accept</pre>");

echo "<form action='SetReviewForm.php' method='post'>

<table class='center'><tr><td><input type='submit' class='button' name='update' value='Save changes' /></td></tr></table>

<table class='setreviewform'>\n";

$ordinalOrder = 0;
$notShown = array();
while ($row = edb_orow($result))
    if ($row->sortOrder < 0)
	$notShown[] = $row;
    else
	echo formFieldText($row, $ordinalOrder++, edb_nrows($result));
foreach ($notShown as $row)
    echo formFieldText($row, -1, edb_nrows($result));

echo "</table>

<table class='center'><tr><td><input type='submit' class='button' name='update' value='Save changes' /></td></tr></table>

</form>\n";


$Conf->footer();
