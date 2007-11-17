<?php 
// Chair/SetReviewForm.php -- HotCRP review form definition page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function rf_checkOptions(&$var, &$options, &$order) {
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

function rf_update($lock) {
    global $Conf, $reviewFields, $rf, $Error;
    
    if (isset($_REQUEST['loadsample']) && isset($_REQUEST['sample'])) {
	require_once('sampleforms.inc');
	if ($_REQUEST['sample'] == 'sigcomm2005')
	    sigcomm2005Form();
	else if ($_REQUEST['sample'] == 'worlds2005')
	    worlds2005Form();
	else if ($_REQUEST['sample'] == 'cgo2004')
	    cgo36Form();
	else if ($_REQUEST['sample'] == 'hotnetsv')
	    hotnetsVForm();
	else if ($_REQUEST['sample'] == 'none')
	    noForm();
	else {
	    $Conf->errorMsg("unknown sample form");
	    $_REQUEST['sample'] = 'none';
	}
    } else if (isset($_REQUEST['cancel'])) {
	require_once('sampleforms.inc');
	noForm();
	$_REQUEST['sample'] = 'none';
    } else
	$_REQUEST['sample'] = 'none';

    if (!isset($_REQUEST['update']))
	return;
    
    $while = "while updating review form";
    $scoreModified = array();
    if ($lock)
	$Conf->qe("lock tables ReviewFormField write, PaperReview write, ReviewFormOptions write");
    
    foreach (array_keys($reviewFields) as $field) {
	$req = '';
	if (isset($_REQUEST["shortName_$field"])) {
	    $sn = trim($_REQUEST["shortName_$field"]);
	    if ($sn == "" || $sn == "<None>") {
		$Error[$field] = 1;
		$shortNameError = true;
		$sn = "<None>";
	    }
	    $req .= "shortName='" . sqlq($sn) . "', ";
	}
	if (isset($_REQUEST["authorView_$field"]))
	    $req .= "authorView=1, ";
	else
	    $req .= "authorView=0, ";
	if (isset($_REQUEST["description_$field"]))
	    $req .= "description='" . sqlq(trim($_REQUEST["description_$field"])) . "', ";
	if (isset($_REQUEST["order_$field"]))
	    $req .= "sortOrder='" . cvtint($_REQUEST["order_$field"]) . "', ";
	if ($reviewFields[$field]) {
	    if (rf_checkOptions($_REQUEST["options_$field"], $options, $_REQUEST["order_$field"])) {
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
		$Error[$field] = 1;
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

    if (isset($shortNameError))
	$Conf->errorMsg("Each review field should have a name.  Please edit the highlighted fields and save again.");
    if (isset($optionError))
	$Conf->errorMsg("Review fields with options must have at least two choices, numbered sequentially from 1.  Enter them like this:  <pre>1. Low quality
2. Medium quality
3. High quality</pre>  Please edit the highlighted fields and save again.");

    if (count($scoreModified))
	$Conf->warnMsg("Your changes invalidated some existing review scores.  The invalid scores have been reset to \"Unknown\".  The relevant fields were: " . join(", ", $scoreModified) . ".");

    if ($lock)
	$Conf->qe("unlock tables");

    // alert consumers of change to form
    if ($lock && isset($updates)) {
	$t = time();
	$Conf->qe("insert into Settings (name, value) values ('revform_update', $t) on duplicate key update value=$t");
	$Conf->confirmMsg("Review form updated.");
	$rf->validate($Conf, true);
    }
}

function rf_getField($row, $name, $ordinalOrder = null) {
    if (isset($_REQUEST["${name}_$row->fieldName"]))
	return $_REQUEST["${name}_$row->fieldName"];
    else if ($ordinalOrder === null)
	return $row->$name;
    else
	return $ordinalOrder;
}

function rf_formFieldText($row, $ordinalOrder, $numRows) {
    global $rf, $reviewFields, $Error, $rowidx, $captions;

    $rowidx = (isset($rowidx) ? $rowidx + 1 : 0);
    $trclass = "tr class='k" . ($rowidx % 2) . "'";
    $x = "<$trclass><td colspan='4'><div class='tinygap'></div></td></tr>\n";
    
    // field name
    $e = (isset($Error[$row->fieldName]) ? " error" : "");
    $x .= "<$trclass><td class='rxcaption nowrap$e'>Field name</td><td colspan='3' class='entry$e'><b><input type='text' size='50' class='textlite' name='shortName_$row->fieldName' value=\""
	. htmlspecialchars(rf_getField($row, 'shortName'))
	. "\" onchange='highlightUpdate()' /></b></td></tr>\n";

    // form position
    $x .= "<$trclass><td class='rxcaption nowrap'>Form position</td><td class='entry'>"
	. "<select name='order_$row->fieldName' onchange='highlightUpdate()'>\n"
	. "  <option value='-1'";
    if ($ordinalOrder < 0)
	$x .= " selected='selected'";
    $x .= ">Off form</option>";
    for ($i = 0; $i < $numRows; $i++) {
	$x .= "<option value='$i'";
	if ($ordinalOrder == $i)
	    $x .= " selected='selected'";
	$x .= ">" . ($i + 1) . ($i == 0 ? "st" : ($i == 1 ? "nd" : ($i == 2 ? "rd" : "th"))) . "</option>";
    }
    $x .= "\n</select> <span class='sep'></span>";

    // author view
    $x .= "<input type='checkbox' name='authorView_$row->fieldName' value='1' ";
    $fname = $row->fieldName;
    if (isset($_REQUEST["shortName_$fname"]) && !isset($_REQUEST["authorView_$fname"]))
	$_REQUEST["authorView_$fname"] = 0;
    if (rf_getField($row, 'authorView') > 0)
	$x .= "checked='checked' ";
    $x .= "/>&nbsp;Visible&nbsp;to&nbsp;authors</td><td class='hint'></td></tr>\n";

    // description
    $x .= "<$trclass><td class='rxcaption textarea'>Description</td>"
	. "<td class='entry'><textarea name='description_$row->fieldName' rows='6' cols='80' onchange='highlightUpdate()'>"
	. htmlentities(rf_getField($row, 'description'))
	. "</textarea></td>";
    if (isset($captions['description'])) {
	$x .= "<td class='hint textarea'>" . $captions['description'] . "</td>";
	unset($captions['description']);
    } else
	$x .= "<td></td>";
    $x .= "</tr>\n";

    // options
    if (isset($rf->options[$row->fieldName]) || $reviewFields[$row->fieldName]) {
	$x .= "<$trclass><td class='rxcaption textarea'>Options</td><td class='entry'><textarea name='options_$row->fieldName' rows='6' cols='80' onchange='highlightUpdate()'>";
	$y = '';
	if (isset($rf->options[$row->fieldName])) {
	    for ($i = 1; $i <= count($rf->options[$row->fieldName]); $i++)
		$y .= "$i. " . $rf->options[$row->fieldName][$i] . "\n";
	}
	$x .= htmlentities(rf_getField($row, 'options', $y))
	    . "</textarea></td>";
	if (isset($captions['options'])) {
	    $x .= "<td class='hint textarea'>" . $captions['options'] . "</td>";
	    unset($captions['options']);
	} else
	    $x .= "<td></td>";
	$x .= "</tr>\n";
    }
    
    return $x . "<$trclass><td colspan='4'><div class='tinygap'></div></td></tr>\n";
}

function rf_show() {
    global $Conf, $captions;
    $result = $Conf->qe("select * from ReviewFormField", "while loading review form");
    if (!$result)
	return;

    $captions = array
	("description" => "Enter an HTML description for the review field here,
	including any guidance you'd like to provide to reviewers and authors.",
	 "options" => "Enter the allowed options for this field, one per line,
	numbered starting from 1.  For example:
	<pre class='entryexample'>1. Reject
2. Weak reject
3. Weak accept
4. Accept</pre>");


    echo "<table><tr><td><div class='hgrp'><b>Templates:</b>&nbsp;
<select name='sample'>";
    foreach (array("none" => "(none)",
		   "hotnetsv" => "HotNets V workshop",
		   "sigcomm2005" => "SIGCOMM 2005",
		   "worlds2005" => "WORLDS 2005 workshop",
		   "cgo2004" => "CGO 2004 conference") as $k => $v) {
	echo "<option value='$k'";
	if ($k == defval($_REQUEST, 'sample', 'none'))
	    echo " selected='selected'";
	echo ">$v</option>";
    }
    echo "</select> &nbsp;
<input type='submit' class='button' name='loadsample' value='Load template' /></div></td></tr></table>

<hr />

<table class='setreviewform'>\n";

    $out = array();
    while ($row = edb_orow($result)) {
	$order = defval($_REQUEST, "order_$row->fieldName", $row->sortOrder);
	if ($order < 0)
	    $order = 100;
	$sn = defval($_REQUEST, "shortName_$row->fieldName", $row->shortName);
	$out[sprintf("%03d.%s", $order, strtolower($sn))] = $row;
    }

    ksort($out);
    $ordinalOrder = 0;
    foreach ($out as $row) {
	$order = defval($_REQUEST, "order_$row->fieldName", $row->sortOrder);
	if ($order >= 0)
	    $order = $ordinalOrder++;
	echo rf_formFieldText($row, $order, count($out));
    }

    echo "</table>\n";
}


if (!isset($Me)) {
    require_once('../Code/header.inc');
    $Me = $_SESSION["Me"];
    $Me->goIfInvalid();
    $Me->goIfNotPrivChair('../');
    $Conf->header("Edit Review Form");
    $rf = reviewForm();
    rf_update(true);
    echo "<form action='SetReviewForm.php' method='post'>
<div class='smgap'></div>\n";
    rf_show();
    echo "<table class='center'><tr><td><input type='submit' class='button' name='update' value='Save changes' />
    <input type='submit' class='button' name='cancel' value='Cancel' /></td></tr></table>

</form>\n";
    $Conf->footer();
}
