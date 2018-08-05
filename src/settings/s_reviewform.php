<?php
// src/settings/s_reviewform.php -- HotCRP review form definition page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class ReviewForm_SettingParser extends SettingParser {
    private $nrfj;
    private $option_error;

    public static $setting_prefixes = ["shortName_", "description_", "order_", "authorView_", "options_", "option_class_prefix_"];

    private function check_options(SettingValues $sv, $fid, $fj) {
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
                } else if (preg_match('/^(?:0\.\s*)?No entry$/i', $line))
                    $allow_empty = true;
                else
                    return false;
            }
        }

        // numeric options must start from 1
        if (!$letters && count($opts) > 0 && $lowonum != 1)
            return false;

        $text = "";
        $seqopts = array();
        for ($onum = $lowonum; $onum < $lowonum + count($opts); ++$onum) {
            if (!isset($opts[$onum]))       // options out of order
                return false;
            $seqopts[] = $opts[$onum];
        }

        unset($fj->option_letter, $fj->allow_empty);
        if ($letters) {
            $seqopts = array_reverse($seqopts, true);
            $fj->option_letter = chr($lowonum);
        }
        $fj->options = array_values($seqopts);
        if ($allow_empty)
            $fj->allow_empty = true;
        return true;
    }

    private function populate_field($fj, ReviewField $f, SettingValues $sv, $fid) {
        $sn = simplify_whitespace(get($sv->req, "shortName_$fid", ""));
        if ($sn === "<None>" || $sn === "<New field>" || $sn === "Field name")
            $sn = "";

        if (isset($sv->req["order_$fid"]))
            $pos = cvtnum(get($sv->req, "order_$fid"));
        else
            $pos = get($fj, "position", -1);
        if ($pos > 0 && $sn == ""
            && isset($sv->req["description_$fid"])
            && trim($sv->req["description_$fid"]) === ""
            && (!$f->has_options
                || (isset($sv->req["options_$fid"])
                    ? trim($sv->req["options_$fid"]) === ""
                    : empty($fj->options))))
            $pos = -1;

        if ($sn !== "")
            $fj->name = $sn;
        else if ($pos > 0)
            $sv->error_at("shortName_$fid", "Missing review field name.");

        if (isset($sv->req["authorView_$fid"]))
            $fj->visibility = $sv->req["authorView_$fid"];

        if (isset($sv->req["description_$fid"])) {
            $x = CleanHTML::basic_clean($sv->req["description_$fid"], $err);
            if ($x !== false) {
                $fj->description = trim($x);
                if ($fj->description === "")
                    unset($fj->description);
            } else if ($pos > 0)
                $sv->error_at("description_$fid", htmlspecialchars($sn) . " description: " . $err);
        }

        if ($pos > 0)
            $fj->position = $pos;
        else
            unset($fj->position);

        if ($f->has_options) {
            $ok = true;
            if (isset($sv->req["options_$fid"]))
                $ok = $this->check_options($sv, $fid, $fj);
            if ((!$ok || count($fj->options) < 2) && $pos > 0) {
                $sv->error_at("options_$fid", htmlspecialchars($sn) . ": Invalid options.");
                if ($this->option_error)
                    $sv->error_at(null, $this->option_error);
                $this->option_error = false;
            }
            if (isset($sv->req["option_class_prefix_$fid"])) {
                $prefixes = ["sv", "svr", "sv-blpu", "sv-publ", "sv-viridis", "sv-viridisr"];
                $pindex = array_search($sv->req["option_class_prefix_$fid"], $prefixes) ? : 0;
                if (get($sv->req, "option_class_prefix_flipped_$fid"))
                    $pindex ^= 1;
                $fj->option_class_prefix = $prefixes[$pindex];
            }
        }

        if (isset($sv->req["round_list_$fid"])) {
            $fj->round_mask = 0;
            foreach (explode(" ", trim($sv->req["round_list_$fid"])) as $round_name)
                if ($round_name !== "")
                    $fj->round_mask |= 1 << (int) $sv->conf->round_number($round_name, false);
        }
    }

    static function requested_fields(SettingValues $sv) {
        $fs = [];
        $max_fields = ["s" => "s00", "t" => "t00"];
        foreach ($sv->conf->review_form()->fmap as $fid => $f) {
            $fs[$f->short_id] = true;
            if (strcmp($f->short_id, $max_fields[$f->short_id[0]]) > 0)
                $max_fields[$f->short_id[0]] = $f->short_id;
        }
        for ($i = 1; ; ++$i) {
            $fid = sprintf("s%02d", $i);
            if (isset($sv->req["shortName_$fid"]) || isset($sv->req["order_$fid"]))
                $fs[$fid] = true;
            else if (strcmp($fid, $max_fields["s"]) > 0)
                break;
        }
        for ($i = 1; ; ++$i) {
            $fid = sprintf("t%02d", $i);
            if (isset($sv->req["shortName_$fid"]) || isset($sv->req["order_$fid"]))
                $fs[$fid] = true;
            else if (strcmp($fid, $max_fields["t"]) > 0)
                break;
        }
        return $fs;
    }

    function parse(SettingValues $sv, Si $si) {
        $this->nrfj = (object) array();
        $this->option_error = "Review fields with options must have at least two choices, numbered sequentially from 1 (higher numbers are better) or lettered with consecutive uppercase letters (lower letters are better). Example: <pre>1. Low quality
2. Medium quality
3. High quality</pre>";

        $rf = $sv->conf->review_form();
        foreach (self::requested_fields($sv) as $fid => $x) {
            $finfo = ReviewInfo::field_info($fid, $sv->conf);
            if (!$finfo) {
                if (isset($sv->req["order_$fid"]) && $sv->req["order_$fid"] > 0)
                    $sv->error_at("shortName_$fid", htmlspecialchars($sv->req["shortName_$fid"]) . ": Too many review fields. You must delete some other fields before adding this one.");
                continue;
            }
            if (isset($rf->fmap[$finfo->id]))
                $f = $rf->fmap[$finfo->id];
            else
                $f = new ReviewField($finfo, $sv->conf);
            $fj = $f->unparse_json(true);
            if (isset($sv->req["shortName_$fid"])) {
                $this->populate_field($fj, $f, $sv, $fid);
                $xf = clone $f;
                $xf->assign($fj);
                $fj = $xf->unparse_json(true);
            }
            $this->nrfj->{$finfo->id} = $fj;
        }

        $sv->need_lock["PaperReview"] = true;
        return true;
    }

    private function clear_existing_fields($fields, Conf $conf) {
        // clear fields from main storage
        $clear_sfields = $clear_tfields = [];
        foreach ($fields as $f) {
            if ($f->main_storage) {
                if ($f->has_options)
                    $result = $conf->qe("update PaperReview set {$f->main_storage}=0");
                else
                    $result = $conf->qe("update PaperReview set {$f->main_storage}=null");
            }
            if ($f->json_storage) {
                if ($f->has_options)
                    $clear_sfields[] = $f;
                else
                    $clear_tfields[] = $f;
            }
        }
        if (!$clear_sfields && !$clear_tfields)
            return;

        // clear fields from json storage
        $clearf = Dbl::make_multi_qe_stager($conf->dblink);
        $result = $conf->qe("select * from PaperReview where sfields is not null or tfields is not null");
        while (($rrow = ReviewInfo::fetch($result, $conf))) {
            $cleared = false;
            foreach ($clear_sfields as $f)
                if (isset($rrow->{$f->id})) {
                    unset($rrow->{$f->id}, $rrow->{$f->short_id});
                    $cleared = true;
                }
            if ($cleared)
                $clearf("update PaperReview set sfields=? where paperId=? and reviewId=?", [$rrow->unparse_sfields(), $rrow->paperId, $rrow->reviewId]);
            $cleared = false;
            foreach ($clear_tfields as $f)
                if (isset($rrow->{$f->id})) {
                    unset($rrow->{$f->id}, $rrow->{$f->short_id});
                    $cleared = true;
                }
            if ($cleared)
                $clearf("update PaperReview set tfields=? where paperId=? and reviewId=?", [$rrow->unparse_tfields(), $rrow->paperId, $rrow->reviewId]);
        }
        $clearf(null);
    }

    private function clear_nonexisting_options($fields, Conf $conf) {
        $updates = [];

        // clear options from main storage
        $clear_sfields = [];
        foreach ($fields as $f) {
            if ($f->main_storage) {
                $result = $conf->qe("update PaperReview set {$f->main_storage}=0 where {$f->main_storage}>" . count($f->options));
                if ($result && $result->affected_rows > 0)
                    $updates[$f->name] = true;
            }
            if ($f->json_storage)
                $clear_sfields[] = $f;
        }

        if ($clear_sfields) {
            // clear options from json storage
            $clearf = Dbl::make_multi_qe_stager($conf->dblink);
            $result = $conf->qe("select * from PaperReview where sfields is not null");
            while (($rrow = ReviewInfo::fetch($result, $conf))) {
                $cleared = false;
                foreach ($clear_sfields as $f)
                    if (isset($rrow->{$f->id}) && $rrow->{$f->id} > count($f->options)) {
                        unset($rrow->{$f->id}, $rrow->{$f->short_id});
                        $cleared = $updates[$f->name] = true;
                    }
                if ($cleared)
                    $clearf("update PaperReview set sfields=? where paperId=? and reviewId=?", [$rrow->unparse_sfields(), $rrow->paperId, $rrow->reviewId]);
            }
            $clearf(null);
        }

        return array_keys($updates);
    }

    function save(SettingValues $sv, Si $si) {
        global $Now;
        if (!$sv->update("review_form", json_encode_db($this->nrfj)))
            return;
        $oform = $sv->conf->review_form();
        $nform = new ReviewForm($this->nrfj, $sv->conf);
        $clear_fields = $clear_options = [];
        $reset_wordcount = $assign_ordinal = false;
        foreach ($nform->all_fields() as $nf) {
            $of = get($oform->fmap, $nf->id);
            if ($nf->displayed && (!$of || !$of->displayed))
                $clear_fields[] = $nf;
            else if ($nf->displayed && $nf->has_options
                     && count($nf->options) < count($of->options))
                $clear_options[] = $nf;
            if ($of && $of->include_word_count() != $nf->include_word_count())
                $reset_wordcount = true;
            if ($of && $of->displayed && $of->view_score < VIEWSCORE_AUTHORDEC
                && $nf->displayed && $nf->view_score >= VIEWSCORE_AUTHORDEC)
                $assign_ordinal = true;
            foreach (self::$setting_prefixes as $fx)
                unset($sv->req[$fx . $nf->short_id]);
        }
        $sv->conf->invalidate_caches(["rf" => true]);
        // reset existing review values
        if (!empty($clear_fields))
            $this->clear_existing_fields($clear_fields, $sv->conf);
        // ensure no review has a nonexisting option
        if (!empty($clear_options)) {
            $updates = $this->clear_nonexisting_options($clear_options, $sv->conf);
            if (!empty($updates)) {
                sort($updates);
                $sv->warning_at(null, "Your changes invalidated some existing review scores.  The invalid scores have been reset to “Unknown”.  The relevant fields were: " . join(", ", $updates) . ".");
            }
        }
        // reset all word counts if author visibility changed
        if ($reset_wordcount)
            $sv->conf->qe("update PaperReview set reviewWordCount=null");
        // assign review ordinals if necessary
        if ($assign_ordinal) {
            $rrows = [];
            $result = $sv->conf->qe("select * from PaperReview where reviewOrdinal=0 and reviewSubmitted>0");
            while (($rrow = ReviewInfo::fetch($result, $sv->conf)))
                $rrows[] = $rrow;
            Dbl::free($result);
            $locked = false;
            foreach ($rrows as $rrow)
                if ($nform->nonempty_view_score($rrow) >= VIEWSCORE_AUTHORDEC) {
                    if (!$locked) {
                        $sv->conf->qe("lock tables PaperReview write");
                        $locked = true;
                    }
                    $max_ordinal = $sv->conf->fetch_ivalue("select coalesce(max(reviewOrdinal), 0) from PaperReview where paperId=? group by paperId", $rrow->paperId);
                    if ($max_ordinal !== null)
                        $sv->conf->qe("update PaperReview set reviewOrdinal=?, timeDisplayed=? where paperId=? and reviewId=?", $max_ordinal + 1, $Now, $rrow->paperId, $rrow->reviewId);
                }
            if ($locked)
                $sv->conf->qe("unlock tables");
        }
    }
}

class ReviewForm_SettingRenderer {
static function render(SettingValues $sv) {
    global $ConfSitePATH;

    $samples = json_decode(file_get_contents("$ConfSitePATH/etc/reviewformlibrary.json"));

    $rf = $sv->conf->review_form();
    $req = [];
    if ($sv->use_req())
        foreach (array_keys(ReviewForm_SettingParser::requested_fields($sv)) as $fid) {
            foreach (ReviewForm_SettingParser::$setting_prefixes as $fx)
                if (isset($sv->req["$fx$fid"]))
                    $req["$fx$fid"] = $sv->req["$fx$fid"];
        }

    Ht::stash_html('<div id="review_form_caption_description" class="hidden">'
      . '<p>Enter an HTML description for the review form.
Include any guidance you’d like to provide for reviewers.
Note that complex HTML will not appear on offline review forms.</p></div>'
      . '<div id="review_form_caption_options" class="hidden">'
      . '<p>Enter one option per line, numbered starting from 1 (higher numbers
are better). For example:</p>
<pre class="entryexample dark">1. Reject
2. Weak reject
3. Weak accept
4. Accept</pre>
<p>Or use consecutive capital letters (lower letters are better).</p>
<p>Normally scores are mandatory: a review with a missing score cannot be
submitted. Add a “<code>No entry</code>” line to make the score optional.</p></div>');

    $rfj = [];
    foreach ($rf->fmap as $f)
        $rfj[$f->short_id] = $f->unparse_json();

    // track whether fields have any nonempty values
    $where = ["false", "false"];
    foreach ($rf->fmap as $f) {
        $fj = $rfj[$f->short_id];
        $fj->internal_id = $f->id;
        $fj->has_any_nonempty = false;
        if ($f->json_storage) {
            if ($f->has_options)
                $where[0] = "sfields is not null";
            else
                $where[1] = "tfields is not null";
        } else {
            if ($f->has_options)
                $where[] = "{$f->main_storage}!=0";
            else
                $where[] = "coalesce({$f->main_storage},'')!=''";
        }
    }

    $unknown_nonempty = array_values($rfj);
    $limit = 0;
    while (!empty($unknown_nonempty)) {
        $result = $sv->conf->qe("select * from PaperReview where " . join(" or ", $where) . " limit $limit,100");
        $expect_limit = $limit + 100;
        while (($rrow = ReviewInfo::fetch($result, $sv->conf))) {
            for ($i = 0; $i < count($unknown_nonempty); ++$i) {
                $fj = $unknown_nonempty[$i];
                $fid = $fj->internal_id;
                if (isset($rrow->$fid)
                    && (isset($fj->options) ? (int) $rrow->$fid !== 0 : $rrow->$fid !== "")) {
                    $fj->has_any_nonempty = true;
                    array_splice($unknown_nonempty, $i, 1);
                } else
                    ++$i;
            }
            ++$limit;
        }
        Dbl::free($result);
        if ($limit !== $expect_limit) // ran out of reviews
            break;
    }

    // output settings json
    Ht::stash_script("review_form_settings({"
        . "fields:" . json_encode_browser($rfj)
        . ", samples:" . json_encode_browser($samples)
        . ", errf:" . json_encode_browser($sv->message_field_map())
        . ", req:" . json_encode_browser($req)
        . ", stemplate:" . json_encode_browser(ReviewField::make_template(true, $sv->conf))
        . ", ttemplate:" . json_encode_browser(ReviewField::make_template(false, $sv->conf))
        . "})");

    echo Ht::hidden("has_review_form", 1),
        "<div id=\"reviewform_container\"></div>",
        "<div id=\"reviewform_removedcontainer\"></div>",
        Ht::button("Add score field", ["class" => "btn settings-add-review-field score"]),
        "<span class='sep'></span>",
        Ht::button("Add text field", ["class" => "btn settings-add-review-field"]);
    Ht::stash_script('$("button.settings-add-review-field").on("click", function () { review_form_settings.add(hasClass(this,"score")?1:0) })');
}
}
