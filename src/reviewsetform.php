<?php
// src/reviewsetform.php -- HotCRP review form definition page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $review_form_setting_prefixes;
$review_form_setting_prefixes = array("shortName_", "description_",
                                      "order_", "authorView_", "options_");

function rf_check_options($fid, $fj) {
    global $Conf;
    if (!isset($_REQUEST["options_$fid"])) {
        $fj->options = array();
        return @$fj->position ? false : true;
    }

    $text = cleannl($_REQUEST["options_$fid"]);
    $letters = ($text && ord($text[0]) >= 65 && ord($text[0]) <= 90);
    $expect = ($letters ? "[A-Z]" : "[1-9]");

    $opts = array();
    $lowonum = 10000;

    foreach (explode("\n", $text) as $line) {
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
    if (count($opts) < 2 && @$fj->position)
	return false;

    $text = "";
    $seqopts = array();
    for ($onum = $lowonum; $onum < $lowonum + count($opts); ++$onum) {
	if (!isset($opts[$onum]))	// options out of order
	    return false;
        $seqopts[] = $opts[$onum];
    }

    if ($letters) {
	$seqopts = array_reverse($seqopts, true);
	$fj->option_letter = chr($lowonum);
    }
    $fj->options = array_values($seqopts);
    return true;
}

function rf_update() {
    global $Conf, $Error, $review_form_setting_prefixes;

    if (!isset($_REQUEST["update"]) || !check_post())
	return;

    $while = "while updating review form";
    $scoreModified = array();

    $nrfj = (object) array();
    $shortNameError = $optionError = false;

    $rf = reviewForm();
    foreach ($rf->fmap as $fid => $f) {
        $nrfj->$fid = $fj = (object) array();

        $sn = simplify_whitespace(defval($_REQUEST, "shortName_$fid", ""));
        if ($sn == "<None>" || $sn == "<New field>")
            $sn = "";
        $pos = rcvtint($_REQUEST["order_$fid"]);
        if ($pos > 0 && $sn == ""
            && trim(defval($_REQUEST, "description_$fid", "")) == ""
            && trim(defval($_REQUEST, "options_$fid", "")) == "")
            $pos = -1;
        if ($sn != "")
            $fj->name = $sn;
        else if ($pos > 0)
            $shortNameError = $Error["shortName_$fid"] = true;

        $fj->view_score = @$_REQUEST["authorView_$fid"];

        $x = CleanHTML::clean(defval($_REQUEST, "description_$fid", ""), $err);
        if ($x === false) {
            if (@$f->description)
                $fj->description = $f->description;
            if ($pos > 0) {
                $Error["description_$fid"] = true;
                $Conf->errorMsg(htmlspecialchars($sn) . " description: " . $err);
            }
        } else if (($x = trim($x)) != "")
            $fj->description = $x;

        if ($pos > 0)
            $fj->position = $pos;

	if ($f->has_options) {
            $fj->options = array_values($f->options); // default
	    if (!rf_check_options($fid, $fj) && $pos > 0)
		$optionError = $Error["options_$fid"] = true;
	}
    }

    if ($shortNameError)
	$Conf->errorMsg("Each review field should have a name.  Please fix the highlighted fields and save again.");
    if ($optionError)
	$Conf->errorMsg("Review fields with options must have at least two choices, numbered sequentially from 1 (higher numbers are better) or lettered with consecutive uppercase letters (lower letters are better). Example: <pre>1. Low quality
2. Medium quality
3. High quality</pre>  Please fix the highlighted errors and save again.");
    if (!$shortNameError && !$optionError) {
        $Conf->save_setting("review_form", 1, $nrfj);
        foreach ($nrfj as $fid => $fj)
            if (@$fj->position && @$fj->options) {
                $result = $Conf->qe("update PaperReview set $fid=0 where $fid>" . count($fj->options), $while);
                if (edb_nrows_affected($result) > 0)
                    $scoreModified[] = htmlspecialchars($fj->name);
            }
        foreach ($rf->fmap as $fid => $f) {
            foreach ($review_form_setting_prefixes as $fx)
                unset($_REQUEST["$fx$fid"]);
        }
        $Conf->confirmMsg("Review form updated.");
        if (count($scoreModified))
            $Conf->warnMsg("Your changes invalidated some existing review scores.  The invalid scores have been reset to “Unknown”.  The relevant fields were: " . join(", ", $scoreModified) . ".");
    }

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

function rf_show() {
    global $Conf, $ConfSitePATH, $Error, $captions, $review_form_setting_prefixes;

    $rf = reviewForm();
    $fmap = array();
    foreach ($rf->fmap as $fid => $f)
        $fmap[$fid] = $f->has_options;

    $samples = json_decode(file_get_contents("$ConfSitePATH/src/reviewformlibrary.json"));

    $Conf->footerHtml
        ("<div id='revfield_template' style='display:none'>"
         . "<table id='revfield_\$' class='setreviewform foldo errloc_\$' style='width:100%'>"
         . "<tbody><tr class='errloc_shortName_\$'><td class='rxcaption nowrap'>Field name</td>"
         .   "<td colspan='3' class='entry'>" . Ht::entry("shortName_\$", "", array("size" => 50, "class" => "textlite", "style" => "font-weight:bold", "id" => "shortName_\$")) . "</td></tr>"
         . "<tr><td class='rxcaption nowrap'>Form position</td>"
         .   "<td class='entry'>" . Ht::select("order_\$", array(), array("class" => "reviewfield_order", "id" => "order_\$"))
         .   "<span class='sep'></span><span class='fx'>Visibility &nbsp;"
         .   Ht::select("authorView_\$", array("author" => "Authors &amp; reviewers", "pc" => "Reviewers only", "admin" => "Administrators only"), array("class" => "reviewfield_authorView", "id" => "authorView_\$")) . "</span>"
         .   "<span class='fn'>" . Ht::button("Revert", array("class" => "revfield_revert", "id" => "revert2_\$")) . "</span>"
         . "</td></tr>"
         . "<tr class='errloc_description_\$ fx'><td class='rxcaption textarea'>Description</td>"
         .   "<td class='entry'>" . Ht::textarea("description_\$", null, array("class" => "reviewtext", "rows" => 6, "id" => "description_\$")) . "</td></tr>"
         . "<tr class='errloc_options_\$ fx reviewrow_options'><td class='rxcaption textarea'>Options</td>"
         .   "<td class='entry'>" . Ht::textarea("options_\$", null, array("class" => "reviewtext", "rows" => 6, "id" => "options_\$")) . "</td></tr>"
         . "<tr class='fx'><td class='rxcaption'></td>"
         .   "<td class='entry'>" . Ht::select("samples_\$", array(), array("class" => "revfield_samples", "id" => "samples_\$"))
         .   "<span class='sep'></span>" . Ht::button("Revert", array("class" => "revfield_revert", "id" => "revert_\$", "style" => "display:none")) . "</td></tr>"
         . "</tbody></table></div>");

    $req = array();
    if (count($Error))
        foreach ($rf->fmap as $fid => $f) {
            foreach ($review_form_setting_prefixes as $fx)
                if (isset($_REQUEST["$fx$fid"]))
                    $req["$fx$fid"] = $_REQUEST["$fx$fid"];
        }

    $Conf->footerScript("review_form_settings("
                        . json_encode($fmap) . ","
                        . json_encode($rf->unparse_json()) . ","
                        . json_encode($samples) . ","
                        . json_encode($Error) . ","
                        . json_encode($req) . ")");

    $captions = array
	("description" => "Enter an HTML description for the review field here,
	including any guidance you’d like to provide to reviewers and authors.
	(Note that complex HTML will not appear on offline review forms.)",
	 "options" => "Enter one option per line, numbered starting from 1 (higher numbers are better).  For example:
	<pre class='entryexample'>1. Reject
2. Weak reject
3. Weak accept
4. Accept</pre> Or use consecutive capital letters (lower letters are better).");

    echo "<div id=\"reviewform_container\"></div>";
    echo Ht::button("Add score field", array("onclick" => "review_form_settings.add(1)")),
        "<span class='sep'></span>",
        Ht::button("Add text field", array("onclick" => "review_form_settings.add(0)"));
}
