<?php
// Code/reviewsetform.php -- HotCRP review form definition page
// HotCRP is Copyright (c) 2006-2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function rf_checkOptions(&$var, &$options, &$order, &$levelChar) {
    global $Conf;
    if (!isset($var))
	return false;
    $var = cleannl($var);
    if ($var && ord($var[0]) >= 65 && ord($var[0]) <= 90
	&& $Conf->setting("allowPaperOption") >= 9)
	$expect = $var[0];
    else
	$expect = 1;
    $text = '';
    $options = array();
    foreach (explode("\n", $var) as $line) {
	$line = trim($line);
	if ($line != "") {
	    if (preg_match("/^$expect\\.\\s*(\\S.*)/", $line, $matches)
		|| preg_match("/^$expect\\s+(\\S.*)/", $line, $matches)) {
		$text .= "$expect. $matches[1]\n";
		$options[$expect] = $matches[1];
		$expect = (ord($expect) >= 65 ? chr(ord($expect) + 1) : $expect + 1);
	    } else
		return false;
	}
    }
    $var = $text;
    if (ord($expect) >= 65)
	$options = array_reverse($options, true);
    $levelChar = (ord($expect) >= 65 ? ord($expect) : 1);
    return (count($options) >= 2 || (isset($order) && rcvtint($order) < 0));
}

function rf_update($lock) {
    global $Conf, $rf, $Error;
    $letterOptions = $Conf->setting("allowPaperOption") >= 9;

    if (isset($_REQUEST['loadsample']) && isset($_REQUEST['sample'])) {
	require_once('reviewtemplate.inc');
	if ($_REQUEST['sample'] == 'sigcomm2005')
	    sigcomm2005Form();
	else if ($_REQUEST['sample'] == 'worlds2005')
	    worlds2005Form();
	else if ($_REQUEST['sample'] == 'cgo2004')
	    cgo36Form();
	else if ($_REQUEST['sample'] == 'hotnetsv')
	    hotnetsVForm();
	else if ($_REQUEST['sample'] == 'pldi2008')
	    pldi2008Form();
	else if ($_REQUEST['sample'] == 'none')
	    noForm();
	else {
	    $Conf->errorMsg("unknown sample form");
	    $_REQUEST['sample'] = 'none';
	}
    } else if (isset($_REQUEST['cancel'])) {
	require_once('reviewtemplate.inc');
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

    foreach (array_keys($rf->reviewFields) as $field) {
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
	$req .= "authorView=" . max(min(rcvtint($_REQUEST["authorView_$field"], 0), 1), -2) . ", ";
	if (isset($_REQUEST["description_$field"])) {
	    if (($d = cleanXHTML($_REQUEST["description_$field"], $err)) === false) {
		$Error["description_$field"] = 1;
		$Conf->errorMsg(htmlspecialchars($sn) . " description: " . $err);
		continue;
	    } else
		$req .= "description='" . sqlq(trim($d)) . "', ";
	}
	if (isset($_REQUEST["order_$field"]))
	    $req .= "sortOrder='" . rcvtint($_REQUEST["order_$field"]) . "', ";
	if ($rf->reviewFields[$field]) {
	    if (rf_checkOptions($_REQUEST["options_$field"], $options, $_REQUEST["order_$field"], $levelChar)) {
		$Conf->qe("delete from ReviewFormOptions where fieldName='" . sqlq($field) . "'", $while);
		$optext = "";
		$i = 1;
		foreach ($options as $val) {
		    $optext .= "('" . sqlq($field) . "', $i, '" . sqlq($val) . "'), ";
		    $i++;
		}
		if ($optext)
		    $Conf->qe("insert into ReviewFormOptions (fieldName, level, description) values " . substr($optext, 0, strlen($optext) - 2), $while);

		$result = $Conf->qe("update PaperReview set $field=0 where $field>" . count($options), $while);
		if (edb_nrows_affected($result, $Conf) > 0)
		    $scoreModified[] = htmlspecialchars($_REQUEST["shortName_$field"]);
		if ($letterOptions)
		    $req .= "levelChar=" . ($rf->reviewFields[$field] ? $levelChar : 0) . ", ";

		unset($_REQUEST["options_$field"]);
		$updates = 1;
	    } else {
		$Error["options_$field"] = 1;
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
	$Conf->errorMsg("Review fields with options must have at least two choices, numbered sequentially from 1" . ($letterOptions ? " or with consecutive uppercase letters" : "") . ".  Enter them like this:  <pre>1. Low quality
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
    } else if (isset($updates))
	$Conf->settings["revform_update"] = time();
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
    global $rf, $Error, $rowidx, $captions, $Conf, $ConfSiteSuffix, $scoreHelps;

    $rowidx = (isset($rowidx) ? $rowidx + 1 : 0);
    $trclass = "tr class='k" . ($rowidx % 2) . "'";
    $x = "<$trclass><td colspan='4'><div class='g'></div></td></tr>\n";

    // field name
    $e = (isset($Error[$row->fieldName]) ? " error" : "");
    $x .= "<$trclass><td class='rxcaption nowrap$e'>Field name</td><td colspan='3' class='entry$e'><b><input type='text' size='50' class='textlite' name='shortName_$row->fieldName' value=\""
	. htmlspecialchars(rf_getField($row, 'shortName'))
	. "\" onchange='hiliter(this)' /></b></td></tr>\n";

    // form position
    $x .= "<$trclass><td class='rxcaption nowrap'>Form position</td><td class='entry'>"
	. "<select name='order_$row->fieldName' onchange='hiliter(this)'>\n"
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
    $x .= "Visibility <a class='scorehelp' href='scorehelp$ConfSiteSuffix?f=vis'>(choices)</a>";
    if (count($scoreHelps) == 0)
	$Conf->footerStuff .= "<script type='text/javascript'>addScoreHelp();</script>";
    if (!isset($scoreHelps["vis"])) {
	$scoreHelps["vis"] = 1;
	$Conf->footerStuff .= "<div class='scorehelpc' id='scorehelp_vis'><strong>Visibility</strong> choices are:
<dl><dt><strong>Authors &amp; reviewers</strong></dt><dd>Visible to authors, reviewers, and the PC</dd>
  <dt><strong>Reviewers only</strong></dt><dd>Visible to reviewers and the PC, but not authors</dd>
  <dt><strong>Private</strong></dt><dd>Set by review authors, but visible only to administrators</dd>
  <dt><strong>Secret</strong></dt><dd>Only set by and visible to administrators</dd></dl>
</div>";
    }
    if (isset($_REQUEST["shortName_$row->fieldName"]) && !isset($_REQUEST["authorView_$row->fieldName"]))
	$_REQUEST["authorView_$row->fieldName"] = 0;
    $x .= " &nbsp;"
	. tagg_select("authorView_$row->fieldName",
		      array(1 => "Authors &amp; reviewers",
			    0 => "Reviewers only",
			    -1 => "Private",
			    -2 => "Secret"),
		      max(min(cvtint(rf_getField($row, "authorView"), -10000), 1), -2))
	. "</td><td class='hint'></td></tr>\n";

    // description
    $error = (isset($Error["description_$row->fieldName"]) ? " error" : "");
    $x .= "<$trclass><td class='rxcaption textarea$error'>Description</td>"
	. "<td class='entry'><textarea name='description_$row->fieldName' class='reviewtext' rows='6' onchange='hiliter(this)'>"
	. htmlentities(rf_getField($row, 'description'))
	. "</textarea></td>";
    if (isset($captions['description'])) {
	$x .= "<td class='hint textarea'>" . $captions['description'] . "</td>";
	unset($captions['description']);
    } else
	$x .= "<td></td>";
    $x .= "</tr>\n";

    // options
    if (isset($rf->options[$row->fieldName]) || $rf->reviewFields[$row->fieldName]) {
	$error = (isset($Error["options_$row->fieldName"]) ? " error" : "");
	$x .= "<$trclass><td class='rxcaption textarea$error'>Options</td><td class='entry$error'><textarea name='options_$row->fieldName' class='reviewtext' rows='6' onchange='hiliter(this)'>";
	$y = '';
	if (isset($rf->options[$row->fieldName])) {
	    foreach ($rf->options[$row->fieldName] as $num => $val)
		$y .= "$num. $val\n";
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

    return $x . "<$trclass><td colspan='4'><div class='g'></div></td></tr>\n";
}

function rf_show() {
    global $Conf, $captions;
    $letterOptions = $Conf->setting("allowPaperOption") >= 9;
    $result = $Conf->qe("select * from ReviewFormField", "while loading review form");
    if (!$result)
	return;

    $captions = array
	("description" => "Enter an HTML description for the review field here,
	including any guidance you'd like to provide to reviewers and authors.",
	 "options" => "Enter one option per line, numbered starting from 1 (higher numbers are better).  For example:
	<pre class='entryexample'>1. Reject
2. Weak reject
3. Weak accept
4. Accept</pre>");
    if ($letterOptions)
	$captions["options"] .= " Or use consecutive capital letters (lower letters are better).";

    echo "<table><tr><td><div class='hgrp'><b>Templates:</b>&nbsp; ",
	tagg_select("sample",
		    array("none" => "(none)",
			  "hotnetsv" => "HotNets V workshop",
			  "pldi2008" => "PLDI 2008",
			  "sigcomm2005" => "SIGCOMM 2005",
			  "worlds2005" => "WORLDS 2005 workshop",
			  "cgo2004" => "CGO 2004 conference"),
		    defval($_REQUEST, "sample", "none")),
	" &nbsp;
<input type='submit' class='b' name='loadsample' value='Load template' /></div></td></tr></table>

<hr class='hr' />

<table class='setreviewform'>\n";

    $out = array();
    while ($row = edb_orow($result)) {
	$order = defval($_REQUEST, "order_$row->fieldName", $row->sortOrder);
	if ($order < 0)
	    $order = 100;
	$sn = defval($_REQUEST, "shortName_$row->fieldName", $row->shortName);
	if ($order == 100 && preg_match('/^additional.*(field|score)$/i', $sn))
	    $order = 200;
	$out[sprintf("%03d.%s.%s", $order, strtolower($sn), $row->fieldName)] = $row;
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
