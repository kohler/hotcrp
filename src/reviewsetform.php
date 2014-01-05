<?php
// Code/reviewsetform.php -- HotCRP review form definition page
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function rf_checkOptions(&$var, &$options, &$order, &$levelChar) {
    global $Conf;
    if (!isset($var))
	return false;

    $var = cleannl($var);
    $letters = ($var && ord($var[0]) >= 65 && ord($var[0]) <= 90);
    $expect = ($letters ? "[A-Z]" : "[1-9]");

    $opts = array();
    $lowonum = 10000;

    foreach (explode("\n", $var) as $line) {
	$line = trim($line);
	if ($line != "") {
	    if ((preg_match("/^($expect)\\.\\s*(\\S.*)/", $line, $m)
		 || preg_match("/^($expect)\\s+(\\S.*)/", $line, $m))
		&& !isset($opts[$m[1]])) {
		$onum = ($letters ? ord($m[1]) : (int) $m[1]);
		$lowonum = min($lowonum, $onum);
		$opts[$onum] = $m[2];
	    } else
		return false;
	}
    }

    // numeric options must start from 1
    if (!$letters && count($opts) > 0 && $lowonum != 1)
	return false;
    // must have at least 2 options, but off-form fields don't count
    if (count($opts) < 2 && (!isset($order) || rcvtint($order) >= 0))
	return false;

    $text = "";
    $seqopts = array();
    for ($onum = $lowonum; $onum < $lowonum + count($opts); ++$onum) {
	if (!isset($opts[$onum]))	// options out of order
	    return false;
	$ochr = ($letters ? chr($onum) : $onum);
	$seqopts[$ochr] = $opts[$onum];
	$text .= $ochr . ". " . $opts[$onum] . "\n";
    }

    if ($letters) {
	$options = array_reverse($seqopts, true);
	$levelChar = $lowonum + count($opts);
    } else {
	$options = $seqopts;
	$levelChar = 1;
    }
    $var = $text;
    return true;
}

function rf_update() {
    global $Conf, $rf, $Error;

    if (isset($_REQUEST['loadsample']) && isset($_REQUEST['sample'])) {
	require_once('reviewtemplate.php');
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
	require_once('reviewtemplate.php');
	noForm();
	$_REQUEST['sample'] = 'none';
    } else
	$_REQUEST['sample'] = 'none';

    if (!isset($_REQUEST["update"]) || !check_post())
	return;

    $while = "while updating review form";
    $scoreModified = array();

    $nrfj = (object) array();
    $shortNameError = $optionError = false;

    foreach ($rf->fmap as $field => $f) {
        $nrfj->$field = $fj = (object) array();

        $sn = simplify_whitespace(defval($_REQUEST, "shortName_$field", ""));
        if (($sn == "" || $sn == "<None>")
            && rcvtint($_REQUEST["order_$field"]) >= 0)
            $shortNameError = $Error["shortName_$field"] = true;
        if ($sn != "" && $sn != "<None>")
            $fj->name = $sn;

        $fj->view_score = max(min(rcvtint($_REQUEST["authorView_$field"], 0),
                                  VIEWSCORE_AUTHOR), VIEWSCORE_ADMINONLY);

        $x = CleanHTML::clean(defval($_REQUEST, "description_$field", ""), $err);
        if ($x === false) {
            $Error["description_$field"] = true;
            $Conf->errorMsg(htmlspecialchars($sn) . " description: " . $err);
            continue;
        } else if (($x = trim($x)) != "")
            $fj->description = $x;

        $x = rcvtint($_REQUEST["order_$field"]);
        if ($x >= 0)
            $fj->position = $x + 1;

	if ($f->has_options) {
	    if (rf_checkOptions($_REQUEST["options_$field"], $options, $_REQUEST["order_$field"], $levelChar)) {
                if ($levelChar != 1)
                    $fj->option_letter = $levelChar;
                $fj->options = array_values($options);

		$result = $Conf->qe("update PaperReview set $field=0 where $field>" . count($options), $while);
		if (edb_nrows_affected($result) > 0)
		    $scoreModified[] = htmlspecialchars($_REQUEST["shortName_$field"]);

		unset($_REQUEST["options_$field"]);
	    } else {
		$optionError = $Error["options_$field"] = true;
		continue;
	    }
	}
    }

    if ($shortNameError)
	$Conf->errorMsg("Each review field should have a name.  Please edit the highlighted fields and save again.");
    if ($optionError)
	$Conf->errorMsg("Review fields with options must have at least two choices, numbered sequentially from 1 or with consecutive uppercase letters.  Enter them like this:  <pre>1. Low quality
2. Medium quality
3. High quality</pre>  Please edit the highlighted fields and save again.");
    if (!$shortNameError && !$optionError) {
        $Conf->save_setting("review_form", 1, $nrfj);
        $Conf->confirmMsg("Review form updated.");
    }

    if (count($scoreModified))
	$Conf->warnMsg("Your changes invalidated some existing review scores.  The invalid scores have been reset to “Unknown”.  The relevant fields were: " . join(", ", $scoreModified) . ".");

    $Conf->invalidateCaches(array("rf" => true));
}

function rf_getField($f, $formname, $fname, $backup = null) {
    if (isset($_REQUEST["${formname}_$f->id"]))
	return $_REQUEST["${formname}_$f->id"];
    else if ($backup !== null)
        return $backup;
    else
	return $f->$fname;
}

function rf_formFieldText($f, $ordinalOrder, $numRows) {
    global $rf, $Error, $rowidx, $captions, $Conf, $scoreHelps;

    $rowidx = (isset($rowidx) ? $rowidx + 1 : 0);
    $trclass = "tr class='k" . ($rowidx % 2) . "'";
    $x = "<$trclass><td colspan='4'><div class='g'></div></td></tr>\n";

    // field name
    $fid = $f->id;
    if (isset($Error[$fid]) || isset($Error["shortName_$fid"]))
        $e = " error";
    else
        $e = "";
    $x .= "<$trclass><td class='rxcaption nowrap$e'>Field name</td><td colspan='3' class='entry$e'><b><input type='text' size='50' class='textlite' name='shortName_$fid' value=\""
	. htmlspecialchars(rf_getField($f, "shortName", "name"))
	. "\" onchange='hiliter(this)' /></b></td></tr>\n";

    // form position
    $x .= "<$trclass><td class='rxcaption nowrap'>Form position</td><td class='entry'>";
    $fp_opt = array();
    $fp_opt["-1"] = "Off form";
    for ($i = 0; $i < $numRows; ++$i)
	$fp_opt["$i"] = ordinal($i + 1);
    $x .= Ht::select("order_$fid", $fp_opt, $ordinalOrder === false ? -1 : $ordinalOrder, array("onchange" => "hiliter(this)")) . " <span class='sep'></span>";

    // author view
    if (count($scoreHelps) == 0)
	$Conf->footerScript("addScoreHelp()");
    if (!isset($scoreHelps["vis"])) {
	$scoreHelps["vis"] = 1;
	$Conf->footerHtml("<div class='scorehelpc' id='scorehelp_vis'><strong>Visibility</strong> choices are:
<dl><dt><strong>Authors &amp; reviewers</strong></dt><dd>Visible to authors, reviewers, and the PC</dd>
  <dt><strong>Reviewers only</strong></dt><dd>Visible to reviewers and the PC, but not authors</dd>
  <dt><strong>Private</strong></dt><dd>Set by review authors, but visible only to administrators</dd>
  <dt><strong>Secret</strong></dt><dd>Only set by and visible to administrators</dd></dl>
</div>");
    }
    if (isset($_REQUEST["shortName_$fid"]) && !isset($_REQUEST["authorView_$fid"]))
	$_REQUEST["authorView_$fid"] = 0;
    $x .= "Visibility &nbsp;"
	. Ht::select("authorView_$fid",
		      array(VIEWSCORE_AUTHOR => "Authors &amp; reviewers",
			    VIEWSCORE_PC => "Reviewers only",
			    VIEWSCORE_REVIEWERONLY => "Private",
			    VIEWSCORE_ADMINONLY => "Secret"),
                     max(min(cvtint(rf_getField($f, "authorView", "view_score"), -10000), VIEWSCORE_AUTHOR), VIEWSCORE_ADMINONLY),
		      array("onchange" => "hiliter(this)"))
	. " &nbsp;<a class='scorehelp small' href='" . hoturl("scorehelp", "f=vis") . "'>(What is this?)</a>"
	. "</td><td class='hint'></td></tr>\n";

    // description
    $error = (isset($Error["description_$fid"]) ? " error" : "");
    $x .= "<$trclass><td class='rxcaption textarea$error'>Description</td>"
	. "<td class='entry'><textarea name='description_$fid' class='reviewtext' rows='6' onchange='hiliter(this)'>"
	. htmlentities(rf_getField($f, "description", "description"))
	. "</textarea></td>";
    if (isset($captions['description'])) {
	$x .= "<td class='hint textarea'>" . $captions['description'] . "</td>";
	unset($captions['description']);
    } else
	$x .= "<td></td>";
    $x .= "</tr>\n";

    // options
    if ($f->has_options) {
	$error = (isset($Error["options_$fid"]) ? " error" : "");
	$x .= "<$trclass><td class='rxcaption textarea$error'>Options</td><td class='entry$error'><textarea name='options_$fid' class='reviewtext' rows='6' onchange='hiliter(this)'>";
	$y = '';
	if (count($f->options)) {
	    foreach ($f->options as $num => $val)
		$y .= "$num. $val\n";
	}
	$x .= htmlentities(rf_getField($f, "options", null, $y))
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

    $captions = array
	("description" => "Enter an HTML description for the review field here,
	including any guidance you’d like to provide to reviewers and authors.
	(Note that complex HTML will not appear on offline review forms.)",
	 "options" => "Enter one option per line, numbered starting from 1 (higher numbers are better).  For example:
	<pre class='entryexample'>1. Reject
2. Weak reject
3. Weak accept
4. Accept</pre> Or use consecutive capital letters (lower letters are better).");

    echo "<table><tr><td><div class='hgrp'><b>Templates:</b>&nbsp; ",
	Ht::select("sample",
		    array("none" => "(none)",
			  "hotnetsv" => "HotNets V workshop",
			  "pldi2008" => "PLDI 2008",
			  "sigcomm2005" => "SIGCOMM 2005",
			  "worlds2005" => "WORLDS 2005 workshop",
			  "cgo2004" => "CGO 2004 conference"),
		    defval($_REQUEST, "sample", "none")),
	" &nbsp;
<input type='submit' name='loadsample' value='Load template' /></div></td></tr></table>

<hr class='hr' />

<table class='setreviewform'>\n";

    $out = array();
    $rf = reviewForm();
    foreach ($rf->fmap as $fid => $f) {
        $order = defval($_REQUEST, "order_$fid", $f->display_order);
        if ($order === false || $order <= 0)
            $order = 100;
        $name = defval($_REQUEST, "shortName_$fid", $f->name);
        if ($order == 100
            && ($name == "" || $name == "<None>"
                || preg_match('/^additional.*(?:field|score)$/i', $name)))
            $order = 200;
        $out[sprintf("%03d.%s.%s", $order, strtolower($name), $fid)] = $f;
    }
    ksort($out);

    $ordinalOrder = 0;
    foreach ($out as $f) {
	$order = defval($_REQUEST, "order_$f->id", $f->display_order);
	if ($order !== false && $order > 0)
	    $order = $ordinalOrder++;
	echo rf_formFieldText($f, $order, count($out));
    }

    echo "</table>\n";
}
