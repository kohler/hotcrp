<?php
// src/reviewsetform.php -- HotCRP review form definition page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $review_form_setting_prefixes;
$review_form_setting_prefixes = array("shortName_", "description_",
                                      "order_", "authorView_", "options_",
                                      "option_class_prefix_");

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
        if (!isset($opts[$onum]))       // options out of order
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

    $scoreModified = array();

    $nrfj = (object) array();
    $shortNameError = $optionError = false;

    $rf = reviewForm();
    foreach ($rf->fmap as $fid => $f) {
        $nrfj->$fid = $fj = (object) array();

        $sn = simplify_whitespace(defval($_REQUEST, "shortName_$fid", ""));
        if ($sn == "<None>" || $sn == "<New field>")
            $sn = "";
        if (@$_REQUEST["removed_$fid"] == "1")
            $pos = 0;
        else
            $pos = cvtint(@$_REQUEST["order_$fid"]);
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
            $prefixes = array("sv", "svr", "sv-blpu", "sv-publ");
            $sv = defval($_REQUEST, "option_class_prefix_$fid", "sv");
            $prefix_index = array_search($sv, $prefixes) ? : 0;
            if (@$_REQUEST["option_class_prefix_flipped_$fid"])
                $prefix_index ^= 1;
            if ($prefix_index)
                $fj->option_class_prefix = $prefixes[$prefix_index];
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
                $result = Dbl::qe_raw("update PaperReview set $fid=0 where $fid>" . count($fj->options));
                if ($result && $result->affected_rows > 0)
                    $scoreModified[] = htmlspecialchars($fj->name);
            }
        foreach ($rf->fmap as $fid => $f) {
            foreach ($review_form_setting_prefixes as $fx)
                unset($_REQUEST["$fx$fid"]);
        }
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
    global $Conf, $ConfSitePATH, $Error, $review_form_setting_prefixes;

    $rf = reviewForm();
    $fmap = array();
    foreach ($rf->fmap as $fid => $f)
        $fmap[$fid] = $f->has_options;

    $samples = json_decode(file_get_contents("$ConfSitePATH/src/reviewformlibrary.json"));

    $req = array();
    if (count($Error))
        foreach ($rf->fmap as $fid => $f) {
            foreach ($review_form_setting_prefixes as $fx)
                if (isset($_REQUEST["$fx$fid"]))
                    $req["$fx$fid"] = $_REQUEST["$fx$fid"];
        }

    $Conf->footerHtml('<div id="review_form_caption_description" style="display:none">'
      . '<p>Enter an HTML description for the review form.
Include any guidance you’d like to provide for reviewers.
Note that complex HTML will not appear on offline review forms.</p></div>'
      . '<div id="review_form_caption_options" style="display:none">'
      . '<p>Enter one option per line, numbered starting from 1 (higher numbers
are better). For example:</p>
<pre class="entryexample dark">1. Reject
2. Weak reject
3. Weak accept
4. Accept</pre>
<p>Or use consecutive capital letters (lower letters are better).</p></div>');

    $Conf->footerScript("review_form_settings("
                        . json_encode($fmap) . ","
                        . json_encode($rf->unparse_json()) . ","
                        . json_encode($samples) . ","
                        . json_encode($Error) . ","
                        . json_encode($req) . ")");

    echo "<div id=\"reviewform_container\">",
        Ht::hidden("has_reviewform", 1),
        "</div>";
    echo Ht::button("Add score field", array("onclick" => "review_form_settings.add(1)")),
        "<span class='sep'></span>",
        Ht::button("Add text field", array("onclick" => "review_form_settings.add(0)"));
}
