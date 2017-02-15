<?php
// src/settings/s_reviewform.php -- HotCRP review form definition page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class ReviewForm_SettingParser extends SettingParser {
    private $nrfj;

    public static $setting_prefixes = ["shortName_", "description_", "order_", "authorView_", "options_", "option_class_prefix_"];

    private function check_options($sv, $fid, $fj) {
        if (!isset($sv->req["options_$fid"])) {
            $fj->options = array();
            return get($fj, "position") ? false : true;
        }

        $text = cleannl($sv->req["options_$fid"]);
        $letters = ($text && ord($text[0]) >= 65 && ord($text[0]) <= 90);
        $expect = ($letters ? "[A-Z]" : "[1-9]");

        $opts = array();
        $lowonum = 10000;
        $allow_empty = false;

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line != "") {
                if ((preg_match("/^($expect)\\.\\s*(\\S.*)/", $line, $m)
                     || preg_match("/^($expect)\\s+(\\S.*)/", $line, $m))
                    && !isset($opts[$m[1]])) {
                    $onum = ($letters ? ord($m[1]) : (int) $m[1]);
                    $lowonum = min($lowonum, $onum);
                    $opts[$onum] = $m[2];
                } else if (preg_match('/^No entry$/i', $line))
                    $allow_empty = true;
                else
                    return false;
            }
        }

        // numeric options must start from 1
        if (!$letters && count($opts) > 0 && $lowonum != 1)
            return false;
        // must have at least 2 options, but off-form fields don't count
        if (count($opts) < 2 && get($fj, "position"))
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
        if ($allow_empty)
            $fj->allow_empty = true;
        return true;
    }

    public function parse(SettingValues $sv, Si $si) {
        $this->nrfj = (object) array();
        $option_error = "Review fields with options must have at least two choices, numbered sequentially from 1 (higher numbers are better) or lettered with consecutive uppercase letters (lower letters are better). Example: <pre>1. Low quality
    2. Medium quality
    3. High quality</pre>";

        $rf = $sv->conf->review_form();
        foreach ($rf->fmap as $fid => $f) {
            $fj = (object) array();

            $sn = simplify_whitespace(defval($sv->req, "shortName_$fid", ""));
            if ($sn == "<None>" || $sn == "<New field>" || $sn == "Field name")
                $sn = "";
            $pos = cvtint(get($sv->req, "order_$fid"));
            if ($pos > 0 && $sn == ""
                && trim(defval($sv->req, "description_$fid", "")) == ""
                && trim(defval($sv->req, "options_$fid", "")) == "")
                $pos = -1;
            if ($sn != "")
                $fj->name = $sn;
            else if ($pos > 0)
                $sv->error_at("shortName_$fid", "Missing review field name.");

            $fj->visibility = get($sv->req, "authorView_$fid");

            $x = CleanHTML::basic_clean(defval($sv->req, "description_$fid", ""), $err);
            if ($x === false) {
                if (get($f, "description"))
                    $fj->description = $f->description;
                if ($pos > 0)
                    $sv->error_at("description_$fid", htmlspecialchars($sn) . " description: " . $err);
            } else if (($x = trim($x)) != "")
                $fj->description = $x;

            if ($pos > 0)
                $fj->position = $pos;

            if ($f->has_options) {
                $fj->options = array_values($f->options); // default
                if (!$this->check_options($sv, $fid, $fj) && $pos > 0) {
                    $sv->error_at("options_$fid", "Invalid options.");
                    if ($option_error)
                        $sv->error_at(null, $option_error);
                    $option_error = false;
                }
                $prefixes = array("sv", "svr", "sv-blpu", "sv-publ", "sv-viridis", "sv-viridisr");
                $class_prefix = defval($sv->req, "option_class_prefix_$fid", "sv");
                $prefix_index = array_search($class_prefix, $prefixes) ? : 0;
                if (get($sv->req, "option_class_prefix_flipped_$fid"))
                    $prefix_index ^= 1;
                $fj->option_class_prefix = $prefixes[$prefix_index];
            }

            $fj->round_mask = 0;
            if (($rlist = get($sv->req, "round_list_$fid")))
                foreach (explode(" ", trim($rlist)) as $round_name)
                    $fj->round_mask |= 1 << $sv->conf->round_number($round_name, false);

            $xf = clone $f;
            $xf->assign($fj);
            $this->nrfj->$fid = $xf->unparse_json(true);
        }

        $sv->need_lock["PaperReview"] = true;
        return true;
    }

    public function save(SettingValues $sv, Si $si) {
        if ($sv->update("review_form", json_encode($this->nrfj))) {
            $rf = $sv->conf->review_form();
            $scoreModified = array();
            foreach ($this->nrfj as $fid => $fj)
                if (get($fj, "position") && get($fj, "options")) {
                    $result = $sv->conf->qe_raw("update PaperReview set $fid=0 where $fid>" . count($fj->options));
                    if ($result && $result->affected_rows > 0)
                        $scoreModified[] = htmlspecialchars($fj->name);
                    Dbl::free($result);
                }
            foreach ($rf->fmap as $fid => $f) {
                foreach (self::$setting_prefixes as $fx)
                    unset($sv->req["$fx$fid"]);
            }
            if (count($scoreModified))
                $sv->warning_at(null, "Your changes invalidated some existing review scores.  The invalid scores have been reset to “Unknown”.  The relevant fields were: " . join(", ", $scoreModified) . ".");
            $sv->conf->invalidate_caches(["rf" => true]);
            // reset all word counts in case author visibility changed
            $sv->conf->qe("update PaperReview set reviewWordCount=null");
        }
    }
}

class ReviewForm_SettingRenderer extends SettingRenderer {
function render(SettingValues $sv) {
    global $ConfSitePATH;

    $rf = $sv->conf->review_form();
    $fmap = array();
    foreach ($rf->fmap as $fid => $f)
        $fmap[$fid] = $f->has_options;

    $samples = json_decode(file_get_contents("$ConfSitePATH/etc/reviewformlibrary.json"));

    $req = array();
    if ($sv->use_req())
        foreach ($rf->fmap as $fid => $f) {
            foreach (ReviewForm_SettingParser::$setting_prefixes as $fx)
                if (isset($sv->req["$fx$fid"]))
                    $req["$fx$fid"] = $sv->req["$fx$fid"];
        }

    Ht::stash_html('<div id="review_form_caption_description" style="display:none">'
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
<p>Or use consecutive capital letters (lower letters are better).</p>
<p>Normally scores are mandatory: a review with a missing score cannot be
submitted. Add a line “<code>No entry</code>” to make the score optional.</p></div>');

    Ht::stash_script("review_form_settings("
                     . json_encode($fmap) . ","
                     . json_encode($rf->unparse_full_json()) . ","
                     . json_encode($samples) . ","
                     . json_encode($sv->message_field_map()) . ","
                     . json_encode($req) . ")");

    echo Ht::hidden("has_review_form", 1),
        "<div id=\"reviewform_removedcontainer\"></div>",
        "<div id=\"reviewform_container\"></div>",
        Ht::button("Add score field", array("onclick" => "review_form_settings.add(1)")),
        "<span class='sep'></span>",
        Ht::button("Add text field", array("onclick" => "review_form_settings.add(0)"));
}
}

SettingGroup::register("reviewform", "Review form", 600, new ReviewForm_SettingRenderer);
SettingGroup::register_synonym("rfo", "reviewform");
