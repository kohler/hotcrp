<?php
// review.php -- HotCRP helper class for producing review forms and tables
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// JSON schema for settings["review_form"]:
// {FIELD:{"name":NAME,"description":DESCRIPTION,"position":POSITION,
//         "display_space":ROWS,"view_score":AUTHORVIEW,
//         "options":[DESCRIPTION,...],"option_letter":LEVELCHAR}}

global $scoreHelps;
$scoreHelps = array();

class ReviewField {
    public $id;
    public $name;
    public $name_html;
    public $description;
    public $abbreviation;
    public $has_options;
    public $options;
    public $option_letter;
    public $display_space;
    public $view_score;
    public $displayed;
    public $display_order;
    private $analyzed;

    static private $view_score_map = array("secret" => VIEWSCORE_ADMINONLY,
                                           "admin" => VIEWSCORE_REVIEWERONLY,
                                           "pc" => VIEWSCORE_PC,
                                           "author" => VIEWSCORE_AUTHOR);
    static private $view_score_rmap = null;

    public function __construct($rf, $id, $has_options) {
        $this->id = $id;
        $this->has_options = $has_options;
        $this->options = array();
        $this->option_letter = false;
        $this->analyzed = false;
        $this->displayed = false;
    }

    public function assign($j) {
        $this->name = (@$j->name ? $j->name : "<None>");
        $this->name_html = htmlspecialchars($this->name);
        $this->abbreviation = ReviewForm::abbreviateField($this->name);
        $this->description = (@$j->description ? $j->description : "");
        $this->display_space = (int) @$j->display_space;
        if (!$this->has_options && $this->display_space < 3)
            $this->display_space = 3;
        $this->view_score = @$j->view_score;
        if (is_string($this->view_score)
            && isset(self::$view_score_map[$this->view_score]))
            $this->view_score = self::$view_score_map[$this->view_score];
        else if (!is_numeric($this->view_score)
                 || ($vs = intval($this->view_score)) < VIEWSCORE_FALSE
                 || $vs > VIEWSCORE_AUTHOR)
            $this->view_score = VIEWSCORE_PC;
        if (@$j->position) {
            $this->displayed = true;
            $this->display_order = $j->position;
        } else
            $this->displayed = $this->display_order = false;
        if ($this->has_options) {
            $options = @$j->options ? $j->options : array();
            $ol = @$j->option_letter;
            if ($ol && ctype_alpha($ol) && strlen($ol) == 1)
                $this->option_letter = ord($ol) + count($options);
            else if ($ol && (is_int($ol) || ctype_digit($ol)))
                $this->option_letter = (int) $ol;
            else
                $this->option_letter = false;
            $this->options = array();
            if ($this->option_letter) {
                foreach (array_reverse($options, true) as $i => $n)
                    $this->options[chr($this->option_letter - $i - 1)] = $n;
            } else {
                foreach ($options as $i => $n)
                    $this->options[$i + 1] = $n;
            }
        }
        $this->analyzed = false;
    }

    public function unparse_json() {
        $j = (object) array("name" => $this->name);
        if ($this->description)
            $j->description = $this->description;
        if (!$this->has_options && $this->display_space > 3)
            $j->display_space = $this->display_space;
        if ($this->displayed)
            $j->position = $this->display_order;
        $j->view_score = $this->unparse_view_score();
        if ($this->has_options) {
            $j->options = array();
            foreach ($this->options as $otext)
                $j->options[] = $otext;
            if ($this->option_letter) {
                $j->options = array_reverse($j->options);
                $j->option_letter = chr($this->option_letter - count($j->options));
            }
        }
        return $j;
    }

    static public function unparse_view_score_value($vs) {
        if (!self::$view_score_rmap)
            self::$view_score_rmap = array_flip(self::$view_score_map);
        if (isset(self::$view_score_rmap[$vs]))
            return self::$view_score_rmap[$vs];
        else
            return $vs;
    }

    public function unparse_view_score() {
        return self::unparse_view_score_value($this->view_score);
    }

    public function analyze() {
        if (!$this->analyzed) {
            $this->abbreviation1 = ReviewForm::abbreviateField($this->name, 1);
            if ($this->has_options) {
                $scores = array_keys($this->options);
                if (count($scores) == 1) {
                    $this->typical_score = $scores[0];
                    unset($this->typical_score_range);
                } else {
                    $off = count($scores) == 2 ? 0 : 1;
                    $this->typical_score0 = $scores[$off];
                    $this->typical_score = $scores[$off + 1];
                    if ($this->option_letter)
                        $this->typical_score_range = $this->typical_score0 . $this->typical_score;
                    else
                        $this->typical_score_range = $this->typical_score0 . "-" . $this->typical_score;
                }
            }
            $this->analyzed = true;
        }
        return $this;
    }

    public function web_abbreviation() {
        return "<span class='hastitle' title=\"$this->name_html\">"
            . htmlspecialchars($this->abbreviation) . "</span>";
    }

    public function value_class($value) {
        return "sc" . (int) (($value - 1) * 8.0 / (count($this->options) - 1) + 1.5);
    }

    public function unparse_value($value, $scclass = false) {
        if (is_object($value))
            $value = defval($value, $this->id);
        if (!$this->has_options)
            return $value;
        else if (!$value)
            return "";
        else if (!$this->option_letter)
            $x = $value;
        else if (is_int($value) || ctype_digit($value))
            $x = chr($this->option_letter - (int) $value);
        else {
            $value = $this->option_letter - ord($value);
            $x = chr($this->option_letter - $value);
        }
        if ($scclass) {
            $class = ($scclass === true ? "" : "$scclass ") . $this->value_class($value);
            return "<span class='$class'>$x</span>";
        } else
            return $x;
    }

    static public function unparse_letter($option_letter, $value) {
        $ivalue = (int) $value;
        $ch = $option_letter - $ivalue;
        if ($value < $ivalue + 0.25)
            return chr($ch);
        else if ($value < $ivalue + 0.75)
            return chr($ch - 1) . chr($ch);
        else
            return chr($ch - 1);
    }

    public function unparse_average($value) {
        assert($this->has_options);
        if (!$this->option_letter)
            return sprintf("%0.2f", $value);
        else
            return self::unparse_letter($this->option_letter, $value);
    }

    public function unparse_graph($v, $style, $myscore) {
        global $Conf;
        assert($this->has_options);
        $max = count($this->options);

        if (is_string($v))
            $v = scoreCounts($v, $max);

        $avgtext = $this->unparse_average($v->avg);
        if ($v->n > 1 && $v->stddev)
            $avgtext .= sprintf(" &plusmn; %0.2f", $v->stddev);

        $args = "v=";
        for ($key = 1; $key <= $max; $key++)
            $args .= ($args == "v=" ? "" : ",") . $v->v[$key];
        if ($myscore && $v->v[$myscore] > 0)
            $args .= "&amp;h=$myscore";
        if ($this->option_letter)
            $args .= "&amp;c=" . chr($this->option_letter - 1);

        if ($style == 1) {
            $width = 5 * $max + 3;
            $height = 5 * max(3, max($v->v)) + 3;
            $retstr = "<div class=\"scorechart\" style=\"width:${width}px;height:${height}px\" hotcrpscorechart=\"$args&amp;s=1\" title=\"$avgtext\"></div>";
        } else if ($style == 2) {
            $retstr = "<div class=\"sc\">"
                . "<div class=\"scorechart\" style=\"width:64px;height:8px\" hotcrpscorechart=\"$args&amp;s=2\" title=\"$avgtext\"></div>"
                . "<br />";
            if ($this->option_letter) {
                for ($key = $max; $key >= 1; $key--)
                    $retstr .= ($key < $max ? " " : "") . "<span class='" . $this->value_class($key) . "'>" . $v->v[$key] . "</span>";
            } else {
                for ($key = 1; $key <= $max; $key++)
                    $retstr .= ($key > 1 ? " " : "") . "<span class='" . $this->value_class($key) . "'>" . $v->v[$key] . "</span>";
            }
            $retstr .= "<br /><span class='sc_sum'>" . $avgtext . "</span></div>";
        }
        $Conf->footerScript("scorechart()", "scorechart");

        return $retstr;
    }

    public function parse_value($text, $strict) {
        if (!$strict && strlen($text) > 1
            && preg_match('/\A\s*([0-9]+|[A-Z])(\W|\z)/', $text, $m))
            $text = $m[1];
        if (!$strict && ctype_digit($text))
            $text = (int) $text;
        if (!$text || !$this->has_options || !isset($this->options[$text]))
            return null;
        else if ($this->option_letter)
            return $this->option_letter - ord($text);
        else
            return $text;
    }
}

class ReviewForm {
    const WEB_OPTIONS = 1;
    const WEB_FULL = 2;
    const WEB_LEFT = 4;
    const WEB_RIGHT = 8;
    const WEB_FINAL = 32;

    public $round;
    public $fmap;
    public $forder;
    public $fieldName;

    static public $rating_types = array("n" => "average",
                                        1 => "very helpful",
                                        0 => "too short",
                                        -1 => "too vague",
                                        -4 => "too narrow",
                                        -2 => "not constructive",
                                        -3 => "not correct");
    static private $cache = array();

    static public function get($round = 0) {
        global $Conf;
        if (is_object($round))
            $round = $round->reviewRound;
        else if ($round === null)
            $round = 0;
        if (($rf = @self::$cache[$round]))
            return $rf;
        if (!@self::$cache[0])
            self::$cache[0] = new ReviewForm(0);
        if ($round && $Conf->review_form_json($round))
            return self::$cache[$round] = new ReviewForm($round);
        else if ($round)
            return self::$cache[$round] = self::$cache[0];
        else
            return self::$cache[0];
    }

    static public function clear_cache() {
        self::$cache = array();
    }

    static public function field_list_all_rounds() {
        global $Conf;
        $fields = array();
        foreach ($Conf->round_list() as $i => $rn) {
            $rf = self::get($i);
            if ($rf->round == $i) {
                foreach ($rf->forder as $f)
                    if (!@$fields[$f->id])
                        $fields[$f->id] = $f;
            }
        }
        return $fields;
    }

    function __construct($round) {
        global $Conf;

        $this->round = $round;
        $this->fmap = array();
        foreach (array("paperSummary", "commentsToAuthor", "commentsToPC",
                       "commentsToAddress", "weaknessOfPaper",
                       "strengthOfPaper", "textField7", "textField8") as $fid)
            $this->fmap[$fid] = new ReviewField($this, $fid, false);
        foreach (array("potential", "fixability", "overAllMerit",
                       "reviewerQualification", "novelty", "technicalMerit",
                       "interestToCommunity", "longevity", "grammar",
                       "likelyPresentation", "suitableForShort") as $fid)
            $this->fmap[$fid] = new ReviewField($this, $fid, true);

        $rfj = $Conf->review_form_json($round);
        if (!$rfj)
            $rfj = json_decode('{
"overAllMerit":{"name":"Overall merit","position":1,"view_score":1,
  "options":["Reject","Weak reject","Weak accept","Accept","Strong accept"]},
"reviewerQualification":{"name":"Reviewer expertise","position":2,"view_score":1,
  "options":["No familiarity","Some familiarity","Knowledgeable","Expert"]},
"paperSummary":{"name":"Paper summary","position":3,"display_space":5,"view_score":1},
"commentsToAuthor":{"name":"Comments to authors","position":4,"view_score":1},
"commentsToPC":{"name":"Comments to PC","position":5,"view_score":0}}');

        foreach ($rfj as $fname => $j)
            if (@($f = $this->fmap[$fname]))
                $f->assign($j);

        $forder = array();
        $this->forder = array();
        $this->fieldName = array();
        foreach ($this->fmap as $fid => $f) {
            $this->fieldName[strtolower($f->name)] = $fid;
            if ($f->displayed)
                $forder[sprintf("%03d.%s", $f->display_order, $fid)] = $f;
        }
        ksort($forder);
        foreach ($forder as $f)
            $this->forder[$f->id] = $f;
    }

    private function get_deprecated($table, $element) {
        trigger_error(caller_landmark(2) . ": ReviewForm->$table deprecated", E_USER_NOTICE);
        $x = array();
        foreach ($this->fmap as $f)
            $x[$f->id] = $f->$element;
        return $x;
    }

    public function __get($name) {
        if ($name == "description")
            $x = $this->get_deprecated($name, "description");
        else if ($name == "fieldRows")
            $x = $this->get_deprecated($name, "display_space");
        else if ($name == "authorView")
            $x = $this->get_deprecated($name, "view_score");
        else if ($name == "abbrevName")
            $x = $this->get_deprecated($name, "abbreviation");
        else if ($name == "shortName")
            $x = $this->get_deprecated($name, "name");
        else if ($name == "fieldOrder") {
            trigger_error(caller_landmark() . ": ReviewForm->$name deprecated", E_USER_NOTICE);
            $x = array();
            foreach ($this->forder as $f)
                $x[] = $f->id;
        } else if ($name == "reviewFields") {
            trigger_error(caller_landmark() . ": ReviewForm->$name deprecated", E_USER_NOTICE);
            $x = array();
            foreach ($this->fmap as $f)
                $x[$f->id] = $f->has_options ? ($f->option_letter ? $f->option_letter : 1) : 0;
        } else
            return null;
        $this->$name = $x;
        return $x;
    }

    static function abbreviateField($name, $type = 0) {
        $a = preg_split("/\s+/", ucwords($name));

        // try to filter out noninteresting words
        $b = array();
        foreach ($a as $w)
            if ($w != "Be" && $w != "The" && $w != "A" && $w != "An" && $w != "For" && $w != "To" && $w != "Of")
                $b[] = $w;
        if (count($b) == 0)
            $b = $a;

        array_splice($b, min(3, count($a)));
        $x = "";
        if ($type == 1) {
            foreach ($b as $w)
                $x .= ($x == "" ? "" : "-") . strtolower($w);
        } else {
            foreach ($b as $w)
                $x .= substr($w, 0, 3);
        }
        return $x;
    }

    function unabbreviateField($name) {
        $a = preg_split("/([^a-zA-Z0-9])/", $name, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 0; $i < count($a); ++$i)
            $a[$i] = preg_quote($a[$i], "/");
        $field = null;
        foreach ($this->forder as $f) {
            if (count($a) == 1 && strcasecmp($a[0], $f->abbreviation) == 0)
                return $f->id;
            for ($i = 0; $i < count($a); ++$i)
                if ($a[$i] != "-" && $a[$i] != "\\-" && $a[$i] != "_"
                    && !preg_match("{\\b$a[$i]}i", $f->name))
                    break;
            if ($i == count($a))
                $field = ($field === null ? $f->id : false);
        }
        return $field;
    }

    public function field($id) {
        if (isset($this->fmap[$id]))
            return $this->fmap[$id];
        else
            return null;
    }

    public function unparse_json() {
        $fmap = array();
        foreach ($this->fmap as $fid => $f)
            $fmap[$fid] = $f->unparse_json();
        return $fmap;
    }

    static public function reviewArchiveFields() {
        global $Conf;
        $rf = self::get(0);
        return "reviewId, paperId, contactId, reviewType, requestedBy,
                reviewModified, reviewSubmitted, reviewNeedsSubmit, "
            . join(", ", array_keys($rf->fmap))
            . ", reviewRound, reviewNotified, timeRequested, timeRequestNotified,
            reviewToken, reviewAuthorNotified";
    }

    function webFormRows($contact, $prow, $rrow, $useRequest = false) {
        global $ReviewFormError, $Conf;
        $x = "";
        $revViewScore = $contact->viewReviewFieldsScore($prow, $rrow);
        foreach ($this->forder as $field => $f) {
            if ($f->view_score <= $revViewScore)
                continue;
            $fval = "";
            if ($useRequest && isset($_REQUEST[$field]))
                $fval = $_REQUEST[$field];
            else if ($rrow)
                $fval = $f->unparse_value($rrow->$field);

            $n = $f->name_html;
            $c = "<span class='revfn'>" . $n . "</span>";
            if ($f->view_score < VIEWSCORE_REVIEWERONLY)
                $c .= "<div class='revevis'>(secret)</div>";
            else if ($f->view_score < VIEWSCORE_PC)
                $c .= "<div class='revevis'>(shown only to chairs)</div>";
            else if ($f->view_score < VIEWSCORE_AUTHOR)
                $c .= "<div class='revevis'>(hidden from authors)</div>";

            $x .= "<div class='revt";
            if (isset($ReviewFormError[$field]))
                $x .= " error";
            $x .= "'>" . $c . "<div class='clear'></div></div>";
            if ($f->description)
                $x .= "<div class='revhint'>" . $f->description . "</div>\n";
            $x .= "<div class='revev'>";
            if ($f->has_options) {
                $x .= "<select name='$field' onchange='hiliter(this)'>\n";
                if (!$f->parse_value($fval, true))
                    $x .= "    <option value='0' selected='selected'>(Your choice here)</option>\n";
                foreach ($f->options as $num => $what) {
                    $x .= "    <option value='$num'";
                    if ($num == $fval)
                        $x .= " selected='selected'";
                    $x .= ">$num. " . htmlspecialchars($what) . "</option>\n";
                }
                $x .= "</select>";
            } else {
                $x .= "<textarea name='$field' class='reviewtext' onchange='hiliter(this)' rows='" . $f->display_space . "' cols='60'>" . htmlspecialchars($fval) . "</textarea>";
            }
            $x .= "</div>\n";
        }
        return $x;
    }

    function tfError(&$tf, $isError, $text, $field = null) {
        $e = htmlspecialchars($tf['filename']) . ":";
        if (is_int($field))
            $e .= $field;
        else if ($field === null || !isset($tf['fieldLineno'][$field]))
            $e .= $tf['firstLineno'];
        else
            $e .= $tf['fieldLineno'][$field];
        if (defval($tf, 'paperId'))
            $e .= " (paper #" . $tf['paperId'] . ")";
        $tf[$isError ? 'anyErrors' : 'anyWarnings'] = true;
        $tf['err'][] = "<span class='lineno'>" . $e . ":</span> " . $text;
    }

    function checkRequestFields(&$req, $rrow, &$tf = null) {
        global $ReviewFormError, $Conf;
        $submit = defval($req, "ready", false);
        unset($req["unready"]);
        $nokfields = 0;
        foreach ($this->forder as $field => $f) {
            if (!isset($req[$field]) && !$submit)
                continue;
            $fval = defval($req, $field, ($rrow ? $rrow->$field : ""));
            if (!isset($req[$field]) && $f->view_score >= VIEWSCORE_PC)
                $missing[] = $f->name;
            if ($f->has_options) {
                $fval = trim($fval);
                if ($fval === "" || $fval === "0" || $fval[0] === "(") {
                    if ($submit && $f->view_score >= VIEWSCORE_PC) {
                        $provide[] = $f->name;
                        $ReviewFormError[$field] = 1;
                    }
                } else if (!$f->parse_value($fval, false)) {
                    $outofrange[] = $f;
                    $ReviewFormError[$field] = 1;
                } else
                    $nokfields++;
            } else if (trim($fval) !== "")
                $nokfields++;
        }
        if (isset($missing) && $tf)
            self::tfError($tf, false, commajoin($missing) . " " . pluralx($missing, "field") . " missing from form.  Preserving any existing values.");
        if ($rrow && defval($rrow, "reviewEditVersion", 0) > defval($req, "version", 0)
            && $nokfields > 0 && $tf && isset($tf["text"])) {
            self::tfError($tf, true, "This review has been edited online since you downloaded this offline form, so for safety I am not replacing the online version.  If you want to override your online edits, add a line &ldquo;<code>==+==&nbsp;Version&nbsp;" . $rrow->reviewEditVersion . "</code>&rdquo; to your offline review form for paper #" . $req["paperId"] . " and upload the form again.");
            return 0;
        }
        if (isset($req["reviewerEmail"]) && $rrow
            && $rrow->email != $req["reviewerEmail"]
            && (!isset($req["reviewerName"]) || $req["reviewerName"] != trim("$rrow->firstName $rrow->lastName"))) {
            self::tfError($tf, true, "This review form claims to be written by " . htmlspecialchars($req["reviewerEmail"]) . " rather than the review&rsquo;s owner, " . Text::user_html($rrow) . ".  If you want to upload it anyway, remove the &ldquo;<code>==+==&nbsp;Reviewer</code>&rdquo; line from the form and try again.", "reviewerEmail");
            return 0;
        }
        if (isset($outofrange)) {
            if ($tf)
                foreach ($outofrange as $f)
                    self::tfError($tf, true, "Bad value for field \"" . $f->name_html . "\"", $f->id);
            else {
                foreach ($outofrange as $f)
                    $oor2[] = $f->name_html;
                $Conf->errorMsg("Bad values for " . commajoin($oor2) . ".  Please fix this and submit again.");
            }
            return 0;
        }
        if ($nokfields == 0 && $tf) {
            $tf["ignoredBlank"][] = "#" . $req["paperId"];
            return 0;
        }
        if (isset($provide)) {
            $w = "You did not set some mandatory fields.  Please set " . htmlspecialchars(commajoin($provide)) . " and submit again.";
            if ($tf)
                self::tfError($tf, false, $w);
            else
                $Conf->warnMsg($w);
            $req["unready"] = true;
        }
        return ($nokfields > 0);
    }

    function review_watch_callback($prow, $minic) {
        if ($prow->conflictType == 0
            && $minic->canViewReview($prow, $this->mailer_info["rrow"], false))
            Mailer::send($this->mailer_info["template"], $prow, $minic,
                         $this->mailer_info);
    }

    function save_review($req, $rrow, $prow, $contact, &$tf = null) {
        global $Conf, $Opt;
        $submit = defval($req, "ready", false) && !defval($req, "unready", false)
            && (!$rrow || !$rrow->reviewSubmitted);
        $admin = $contact->allowAdminister($prow);

        if (!$contact->timeReview($prow, $rrow)
            && (!isset($req['override']) || !$admin))
            return $Conf->errorMsg("The <a href='" . hoturl("deadlines") . "'>deadline</a> for entering this review has passed." . ($admin ? "  Select the “Override deadlines” checkbox and try again if you really want to override the deadline." : ""));

        $q = array();
        $diff_view_score = VIEWSCORE_FALSE;
        foreach ($this->forder as $field => $f)
            if (isset($req[$field])) {
                $fval = $req[$field];
                if ($f->has_options) {
                    if (!($fval = $f->parse_value($fval, false)))
                        continue;
                } else {
                    $fval = rtrim($fval);
                    if ($fval != "")
                        $fval .= "\n";
                    // Check for valid UTF-8.  Re-encode invalid UTF-8 as if
                    // it were Windows-1252, which is a superset of
                    // ISO-8859-1.
                    if (!is_valid_utf8($fval))
                        $fval = windows_1252_to_utf8($fval);
                    $req[$field] = $fval;
                }
                if ($rrow && strcmp($rrow->$field, $fval) != 0
                    && strcmp(cleannl($rrow->$field), cleannl($fval)) != 0)
                    $diff_view_score = max($diff_view_score, $f->view_score);
                $q[] = "$field='" . sqlq($fval) . "'";
            }

        // get the current time
        $now = time();
        if ($rrow && $rrow->reviewModified && $rrow->reviewModified > $now)
            $now = $rrow->reviewModified + 1;

        // potentially assign review ordinal (requires table locking since
        // mySQL is stupid)
        $locked = false;
        if ($submit && (!$rrow || !$rrow->reviewSubmitted)) {
            $diff_view_score = max($diff_view_score, VIEWSCORE_AUTHOR);
            $q[] = "reviewSubmitted=$now, reviewNeedsSubmit=0";
            if (!$rrow || !$rrow->reviewOrdinal) {
                $result = $Conf->qe("lock tables PaperReview write");
                if (!$result)
                    return $result;
                $locked = true;
                $result = $Conf->qe("select coalesce(max(reviewOrdinal), 0) from PaperReview where paperId=$prow->paperId group by paperId");
                if ($result) {
                    $crow = edb_row($result);
                    $q[] = "reviewOrdinal=coalesce(reviewOrdinal, " . ($crow[0] + 1) . ")";
                }
            }
        }

        // check whether used a review token
        $usedReviewToken = $rrow && $rrow->reviewToken
            && ($tokens = $contact->review_tokens())
            && array_search($rrow->reviewToken, $tokens) !== false;

        // blind? reviewer type? edit version?
        $reviewBlind = $Conf->is_review_blind(!!@$req["blind"]);
        if ($rrow && $reviewBlind != $rrow->reviewBlind)
            $diff_view_score = max($diff_view_score, VIEWSCORE_ADMINONLY);
        $q[] = "reviewBlind=" . ($reviewBlind ? 1 : 0);
        if ($rrow && $rrow->reviewType == REVIEW_EXTERNAL
            && $contact->contactId == $rrow->contactId
            && $contact->isPC && !$usedReviewToken)
            $q[] = "reviewType=" . REVIEW_PC;
        if ($rrow && $diff_view_score > VIEWSCORE_FALSE
            && isset($req["version"]) && ctype_digit($req["version"])
            && $req["version"] > defval($rrow, "reviewEditVersion"))
            $q[] = "reviewEditVersion=" . ($req["version"] + 0);
        $notify = $notify_author = false;
        if (!$rrow || $diff_view_score > VIEWSCORE_FALSE) {
            $q[] = "reviewModified=" . $now;
            // do not notify on updates within 3 hours
            if ($submit && $diff_view_score > VIEWSCORE_ADMINONLY) {
                if (!$rrow || !$rrow->reviewNotified
                    || $rrow->reviewNotified + 10800 < $now)
                    $q[] = $notify = "reviewNotified=" . $now;
                if ((!$rrow || !$rrow->reviewAuthorNotified
                     || $rrow->reviewAuthorNotified + 10800 < $now)
                    && $diff_view_score >= VIEWSCORE_AUTHOR)
                    $q[] = $notify_author = "reviewAuthorNotified=" . $now;
            }
        }

        // actually affect database
        if ($rrow) {
            $result = $Conf->qe("update PaperReview set " . join(", ", $q) . " where reviewId=$rrow->reviewId");
            $reviewId = $rrow->reviewId;
            $contactId = $rrow->contactId;
        } else {
            $result = $Conf->qe("insert into PaperReview set paperId=$prow->paperId, contactId=$contact->contactId, reviewType=" . REVIEW_PC . ", requestedBy=$contact->contactId, " . join(", ", $q));
            $reviewId = $Conf->lastInsertId();
            $contactId = $contact->contactId;
        }

        // unlock tables even if problem
        if ($locked)
            $Conf->qe("unlock tables");
        if (!$result)
            return $result;

        // look up review ID
        if (!$reviewId)
            return $reviewId;
        $req['reviewId'] = $reviewId;

        // log updates -- but not if review token is used
        if (!$usedReviewToken && $diff_view_score > VIEWSCORE_FALSE) {
            $reviewLogname = "Review $reviewId";
            if ($rrow && $contact->contactId != $rrow->contactId)
                $reviewLogname .= " by $rrow->email";
            if ($submit && (!$rrow || !$rrow->reviewSubmitted))
                $contact->log_activity("$reviewLogname submitted", $prow);
            else
                $contact->log_activity("$reviewLogname saved", $prow);
        }

        // potentially email chair, reviewers, and authors
        $notify_rrow = null;
        if ($submit && ($notify || $notify_author)) {
            if ($rrow && $rrow->reviewSubmitted)
                $notify_rrow = $rrow;
            else
                $notify_rrow = $Conf->reviewRow(array("reviewId" => $reviewId));
        }
        if ($notify_rrow) {
            // need an up-to-date review row to send email successfully
            $fake_rrow = (object) array("paperId" => $prow->paperId,
                 "reviewId" => $reviewId, "contactId" => $contactId,
                 "reviewType" => $notify_rrow->reviewType,
                 "requestedBy" => $notify_rrow->requestedBy,
                 "reviewBlind" => $reviewBlind,
                 "reviewSubmitted" => $notify_rrow->reviewSubmitted);

            $tmpl = ($rrow && $rrow->reviewSubmitted ? "@reviewupdate" : "@reviewsubmit");
            $submitter = $contact;
            if ($contactId != $submitter->contactId)
                $submitter = Contact::find_by_id($contactId);

            // construct mail
            $rest = array("template" => $tmpl, "rrow" => $fake_rrow,
                          "reviewer_contact" => $submitter,
                          "reviewNumber" => $prow->paperId . unparseReviewOrdinal($notify_rrow->reviewOrdinal));
            if ($Conf->timeEmailChairAboutReview())
                Mailer::send_manager($tmpl, $prow, $rest);
            if ($diff_view_score >= VIEWSCORE_PC) {
                $this->mailer_info = $rest;
                genericWatch($prow, WATCHTYPE_REVIEW, array($this, "review_watch_callback"), $contact);
                unset($this->mailer_info);
            }
            if ($Conf->timeEmailAuthorsAboutReview() && $notify_author) {
                $rest["infoMsg"] = "since a review was updated during the response period.";
                if ($Conf->is_review_blind($fake_rrow)) {
                    $rest["infoMsg"] .= " Reviewer anonymity was preserved.";
                    unset($rest["reviewer_contact"]);
                }
                Mailer::send_contacts($tmpl, $prow, $rest);
            }
        }

        // if external, forgive the requestor from finishing their review
        if ($rrow && $rrow->reviewType == REVIEW_EXTERNAL && $submit)
            $Conf->q("update PaperReview set reviewNeedsSubmit=0 where paperId=$prow->paperId and contactId=$rrow->requestedBy and reviewType=" . REVIEW_SECONDARY . " and reviewSubmitted is null");

        if ($tf) {
            if ($submit && (!$rrow || !$rrow->reviewSubmitted))
                $tf["newlySubmitted"][] = "#$prow->paperId";
            else if ($diff_view_score > VIEWSCORE_FALSE)
                $tf["updated"][] = "#$prow->paperId";
            else
                $tf["unchanged"][] = "#$prow->paperId";
        }

        return $result;
    }


    static function textFormHeader($type, $editable) {
        global $Conf, $ConfSiteSuffix, $Opt;

        $x = "==+== " . $Opt["shortName"] . " Paper Review";
        if ($editable) {
            $x .= " Form" . ($type === true ? "s" : "") . "\n";
            $x .= "==-== DO NOT CHANGE LINES THAT START WITH \"==+==\" UNLESS DIRECTED!
==-== For further guidance, or to upload this file when you are done, go to:
==-== " . $Opt["paperSite"] . "/offline$ConfSiteSuffix\n\n";
        } else
            $x .= ($type === true ? "s\n\n" : "\n\n");
        return $x;
    }

    function cleanDescription($d) {
        $d = preg_replace('|\s*<\s*br\s*/?\s*>\s*(?:<\s*/\s*br\s*>\s*)?|si', "\n", $d);
        $d = preg_replace('|\s*<\s*li\s*>|si', "\n* ", $d);
        $d = preg_replace(',<(?:[^"\'>]|".*?"|\'.*?\')*>,s', "", $d);
        return html_entity_decode($d, ENT_QUOTES, "UTF-8");
    }

    function textForm($prow, $rrow, $contact, $req = null, $alwaysMyReview = false) {
        global $Conf, $Opt;

        $rrow_contactId = 0;
        if (isset($rrow) && isset($rrow->reviewContactId))
            $rrow_contactId = $rrow->reviewContactId;
        else if (isset($rrow) && isset($rrow->contactId))
            $rrow_contactId = $rrow->contactId;
        $myReview = $alwaysMyReview
            || (!$rrow || $rrow_contactId == 0 || $rrow_contactId == $contact->contactId);
        $revViewScore = $contact->viewReviewFieldsScore($prow, $rrow);

        $x = "==+== =====================================================================\n";
        //$x .= "$prow->paperId:$myReview:$revViewScore:$rrow->contactId:$rrow->reviewContactId;;$prow->conflictType;;$prow->reviewType\n";

        $x .= "==+== Begin Review";
        if ($req && isset($req['reviewOrdinal']))
            $x .= " #" . $prow->paperId . unparseReviewOrdinal($req['reviewOrdinal']);
        else if ($rrow && isset($rrow->reviewOrdinal))
            $x .= " #" . $prow->paperId . unparseReviewOrdinal($rrow->reviewOrdinal);
        $x .= "\n";
        if ($myReview && $rrow && defval($rrow, "reviewEditVersion"))
            $x .= "==+== Version " . $rrow->reviewEditVersion . "\n";
        if (!$myReview && $prow)
            $x .= wordWrapIndent($prow->title, "==-== Paper: ", "==-==        ") . "\n";
        if ($contact->canViewReviewerIdentity($prow, $rrow, null)) {
            if ($rrow && isset($rrow->reviewFirstName))
                $x .= "==+== Reviewer: " . Text::user_text($rrow->reviewFirstName, $rrow->reviewLastName, $rrow->reviewEmail) . "\n";
            else if ($rrow && isset($rrow->lastName))
                $x .= "==+== Reviewer: " . Text::user_text($rrow) . "\n";
            else if ($myReview)
                $x .= "==+== Reviewer: " . Text::user_text($contact) . "\n";
        }
        if ($rrow && $rrow->reviewModified)
            $x .= "==-== Updated " . $Conf->printableTime($rrow->reviewModified) . "\n";

        if ($myReview) {
            if ($prow)
                $x .= "\n==+== Paper #$prow->paperId\n";
            else
                $x .= "\n==+== Paper Number\n\n(Enter paper number here)\n";
            if ($prow)
                $x .= wordWrapIndent($prow->title, "==-== Title: ", "==-==        ") . "\n";
            $x .= "
==+== Review Readiness
==-== Enter \"Ready\" if the review is ready for others to see:

Ready\n";
            if ($Conf->review_blindness() == Conference::BLIND_OPTIONAL) {
                $blind = "Anonymous";
                if ($rrow && !$rrow->reviewBlind)
                    $blind = "Open";
                $x .= "\n==+== Review Anonymity
==-== " . $Opt["shortName"] . " allows either anonymous or open review.
==-== Enter \"Open\" if you want to expose your name to authors:

$blind\n";
            }
        }

        $i = 0;
        $numericMessage = 0;
        foreach ($this->forder as $field => $f) {
            $i++;
            if ($f->view_score <= $revViewScore)
                continue;

            $fval = "";
            if ($req && isset($req[$field]))
                $fval = rtrim($req[$field]);
            else if ($rrow != null && isset($rrow->$field)) {
                if ($f->has_options)
                    $fval = $f->unparse_value($rrow->$field);
                else
                    $fval = rtrim(str_replace("\r\n", "\n", $rrow->$field));
            }
            if ($f->has_options && isset($f->options[$fval]))
                $fval = "$fval. " . $f->options[$fval];
            else if (!$fval)
                $fval = "";
            if (!$myReview && $fval == "")
                continue;

            $x .= "\n==+== " . chr(64 + $i) . ". " . $f->name;
            if ($f->view_score < VIEWSCORE_REVIEWERONLY)
                $x .= " (secret)";
            else if ($f->view_score < VIEWSCORE_PC)
                $x .= " (shown only to chairs)";
            else if ($f->view_score < VIEWSCORE_AUTHOR)
                $x .= " (hidden from authors)";
            $x .= "\n";
            if ($f->description) {
                $d = cleannl($f->description);
                if (strpbrk($d, "&<") !== false)
                    $d = self::cleanDescription($d);
                $x .= wordWrapIndent($d, "==-==    ", "==-==    ");
            }
            if ($f->has_options && $myReview) {
                $first = true;
                foreach ($f->options as $num => $value) {
                    $y = ($first ? "==-== Choices: " : "==-==          ") . "$num. ";
                    $x .= wordWrapIndent($value, $y, str_pad("==-==", strlen($y))) . "\n";
                    $first = false;
                }
                if ($f->option_letter)
                    $x .= "==-== Enter the letter of your choice:\n";
                else
                    $x .= "==-== Enter the number of your choice:\n";
                if ($fval == "")
                    $fval = "(Your choice here)";
            }
            $x .= "\n" . preg_replace("/^==\\+==/m", "\\==+==", $fval) . "\n";
        }
        return $x . "\n==+== Scratchpad (for unsaved private notes)\n\n==+== End Review\n";
    }

    static function unparse_title_text($prow, &$l) {
        $n = "Paper #" . $prow->paperId . ": ";
        $l = max(14, (int) ((75.5 - strlen(UnicodeHelper::deaccent($prow->title)) - strlen($n)) / 2) + strlen($n));
        return wordWrapIndent($prow->title, $n, $l) . "\n";
    }

    function prettyTextForm($prow, $rrow, $contact, $alwaysAuthorView = true) {
        global $Conf, $Opt;

        $rrow_contactId = 0;
        if (isset($rrow) && isset($rrow->reviewContactId))
            $rrow_contactId = $rrow->reviewContactId;
        else if (isset($rrow) && isset($rrow->contactId))
            $rrow_contactId = $rrow->contactId;
        if ($alwaysAuthorView)
            $revViewScore = VIEWSCORE_AUTHOR - 1;
        else
            $revViewScore = $contact->viewReviewFieldsScore($prow, $rrow);

        $x = "===========================================================================\n";
        $n = $Opt["shortName"] . " Review";
        if ($rrow && isset($rrow->reviewOrdinal))
            $n .= " #" . $prow->paperId . unparseReviewOrdinal($rrow->reviewOrdinal);
        $x .= str_pad($n, (int) (37.5 + strlen($n) / 2), " ", STR_PAD_LEFT) . "\n";
        if ($rrow && $rrow->reviewModified) {
            $n = "Updated " . $Conf->printableTime($rrow->reviewModified);
            $x .= str_pad($n, (int) (37.5 + strlen($n) / 2), " ", STR_PAD_LEFT) . "\n";
        }
        $x .= "---------------------------------------------------------------------------\n";
        $x .= self::unparse_title_text($prow, $l);
        if ($rrow && $contact->canViewReviewerIdentity($prow, $rrow, false)) {
            if (isset($rrow->reviewFirstName))
                $n = Text::user_text($rrow->reviewFirstName, $rrow->reviewLastName, $rrow->reviewEmail);
            else if (isset($rrow->lastName))
                $n = Text::user_text($rrow);
            else
                continue;
            $x .= wordWrapIndent($n, "Reviewer: ", $l) . "\n";
        }
        $x .= "---------------------------------------------------------------------------\n\n";

        $i = 0;
        $lastNumeric = true;
        foreach ($this->forder as $field => $f) {
            $i++;
            if ($f->view_score <= $revViewScore)
                continue;

            $fval = "";
            if ($rrow != null && isset($rrow->$field)) {
                if ($f->has_options)
                    $fval = $f->unparse_value($rrow->$field);
                else
                    $fval = rtrim(str_replace("\r\n", "\n", $rrow->$field));
            }
            if ($fval == "")
                continue;

            if ($f->has_options) {
                $y = defval($f->options, $fval, "");
                $sn = $f->name . ":";
                /* "(1-" . count($f->options) . "):"; */
                if (!$lastNumeric)
                    $x .= "\n";
                if (strlen($sn) > 38 + strlen($fval))
                    $x .= $sn . "\n" . wordWrapIndent($y, $fval . ". ", 39 + strlen($fval)) . "\n";
                else
                    $x .= wordWrapIndent($y, $sn . " " . $fval . ". ", 39 + strlen($fval)) . "\n";
                $lastNumeric = true;
            } else {
                $n = "===== " . $f->name . " =====";
                $x .= "\n" . str_pad($n, (int) (37.5 + strlen($n) / 2), " ", STR_PAD_LEFT) . "\n";
                $x .= "\n" . preg_replace("/^==\\+==/m", "\\==+==", $fval) . "\n";
                $lastNumeric = false;
            }
        }
        return $x;
    }

    function garbageMessage(&$tf, $lineno, &$garbage) {
        if (isset($garbage))
            self::tfError($tf, false, "Review form appears to begin with garbage; ignoring it.", $lineno);
        unset($garbage);
    }

    function beginTextForm($filename, $printFilename) {
        if (($contents = file_get_contents($filename)) === false)
            return null;
        return array('text' => cleannl($contents), 'filename' => $printFilename,
                     'lineno' => 0, 'err' => array(), 'confirm' => array());
    }

    function parseTextForm(&$tf) {
        global $Opt;

        $text = $tf['text'];
        $lineno = $tf['lineno'];
        $tf['firstLineno'] = $lineno + 1;
        $tf['fieldLineno'] = array();
        $req = array();
        if (isset($_REQUEST["override"]))
            $req["override"] = $_REQUEST["override"];

        $mode = 0;
        $nfields = 0;
        $field = 0;
        $anyDirectives = 0;

        while ($text != "") {
            $pos = strpos($text, "\n");
            $line = ($pos === FALSE ? $text : substr($text, 0, $pos + 1));
            $lineno++;

            if (substr($line, 0, 6) == "==+== ") {
                // make sure we record that we saw the last field
                if ($mode && $field != null && !isset($req[$field]))
                    $req[$field] = "";

                $anyDirectives++;
                if (preg_match('{\A==\+==\s+(.*?)\s+(Paper Review(?: Form)?s?)\s*\z}', $line, $m)
                    && $m[1] != trim($Opt["shortName"])) {
                    $this->garbageMessage($tf, $lineno, $garbage);
                    self::tfError($tf, true, "Ignoring review form, which appears to be for a different conference.<br />(If this message is in error, replace the line that reads “<code>" . htmlspecialchars(rtrim($line)) . "</code>” with “<code>==+== " . htmlspecialchars(trim($Opt["shortName"])) . " " . $m[2] . "</code>” and upload again.)", $lineno);
                    return null;
                } else if (preg_match('/^==\+== Begin Review/i', $line)) {
                    if ($nfields > 0)
                        break;
                } else if (preg_match('/^==\+== Paper #?(\d+)/i', $line, $match)) {
                    if ($nfields > 0)
                        break;
                    $req['paperId'] = $tf['paperId'] = $match[1];
                    $req['blind'] = 1;
                    $tf['firstLineno'] = $lineno;
                } else if (preg_match('/^==\+== Reviewer:\s*(.*)\s*<(\S+?)>/', $line, $match)) {
                    $tf["fieldLineno"]["reviewerEmail"] = $lineno;
                    $req["reviewerName"] = $match[1];
                    $req["reviewerEmail"] = $match[2];
                } else if (preg_match('/^==\+== Paper (Number|\#)\s*$/i', $line)) {
                    if ($nfields > 0)
                        break;
                    $field = "paperNumber";
                    $tf["fieldLineno"][$field] = $lineno;
                    $mode = 1;
                    $req['blind'] = 1;
                    $tf['firstLineno'] = $lineno;
                } else if (preg_match('/^==\+== Submit Review\s*$/i', $line)
                           || preg_match('/^==\+== Review Ready\s*$/i', $line)) {
                    $req['ready'] = true;
                } else if (preg_match('/^==\+== Open Review\s*$/i', $line)) {
                    $req['blind'] = 0;
                } else if (preg_match('/^==\+== Version\s*(\d+)$/i', $line, $match)) {
                    if (defval($req, "version", 0) < $match[1])
                        $req['version'] = $match[1];
                } else if (preg_match('/^==\+== Review Readiness\s*/i', $line)) {
                    $field = "readiness";
                    $mode = 1;
                } else if (preg_match('/^==\+== Review Anonymity\s*/i', $line)) {
                    $field = "anonymity";
                    $mode = 1;
                } else if (preg_match('/^==\+== [A-Z]\.\s*(.*?)\s*$/', $line, $match)) {
                    $fname = $match[1];
                    if (!isset($this->fieldName[strtolower($fname)]))
                        $fname = preg_replace('/\s*\((hidden from authors|PC only|shown only to chairs|secret)\)\z/', "", $fname);
                    $fn =& $this->fieldName[strtolower($fname)];
                    if (isset($fn)) {
                        $field = $fn;
                        $tf['fieldLineno'][$fn] = $lineno;
                        $nfields++;
                    } else {
                        $this->garbageMessage($tf, $lineno, $garbage);
                        self::tfError($tf, true, "Review field &ldquo;" . htmlentities($fname) . "&rdquo; is not used for " . htmlspecialchars($Opt["shortName"]) . " reviews.  Ignoring this section.", $lineno);
                        $field = null;
                    }
                    $mode = 1;
                } else {
                    $field = null;
                    $mode = 1;
                }
            } else if ($mode < 2 && (substr($line, 0, 5) == "==-==" || ltrim($line) == ""))
                /* ignore line */;
            else {
                if ($mode == 0) {
                    $garbage = $line;
                    $field = null;
                }
                if ($field != null)
                    $req[$field] = defval($req, $field, "") . $line;
                $mode = 2;
            }

            $text = substr($text, strlen($line));
        }

        if ($nfields == 0 && $tf['firstLineno'] == 1)
            self::tfError($tf, true, "That didn&rsquo;t appear to be a review form; I was not able to extract any information from it.  Please check its formatting and try again.", $lineno);

        $tf['text'] = $text;
        $tf['lineno'] = $lineno - 1;

        if (isset($req["readiness"]))
            $req["ready"] = strcasecmp(trim($req["readiness"]), "Ready") == 0;
        if (isset($req["anonymity"]))
            $req["blind"] = strcasecmp(trim($req["anonymity"]), "Open") != 0;

        if (isset($req["paperId"]))
            /* OK */;
        else if (isset($req["paperNumber"])
                 && ($pid = cvtint(trim($req["paperNumber"]), -1)) > 0)
            $req["paperId"] = $tf["paperId"] = $pid;
        else if ($nfields > 0) {
            self::tfError($tf, true, "This review form doesn&rsquo;t report which paper number it is for.  Make sure you've entered the paper number in the right place and try again.", defval($tf["fieldLineno"], "paperNumber", $lineno));
            $nfields = 0;
        }

        if ($nfields == 0 && $text) // try again
            return $this->parseTextForm($tf);
        else if ($nfields == 0)
            return null;
        else
            return $req;
    }

    static function _paperCommaJoin($pl, $a) {
        foreach ($a as &$x)
            if (preg_match('/\A(#?)(\d+)\z/', $x, $m))
                $x = "<a href=\"" . hoturl("paper", "p=$m[2]") . "\">" . $x . "</a>";
        while (preg_match('/\b(\w+)\*/', $pl, $m))
            $pl = preg_replace('/\b' . $m[1] . '\*/', pluralx(count($a), $m[1]), $pl);
        return $pl . commajoin($a);
    }

    function textFormMessages(&$tf) {
        global $Conf;

        if (count($tf['err']) > 0) {
            $Conf->msg("There were " . (defval($tf, 'anyErrors') && defval($tf, 'anyWarnings') ? "errors and warnings" : (defval($tf, 'anyErrors') ? "errors" : "warnings")) . " while parsing the uploaded reviews file. <div class='parseerr'><p>" . join("</p>\n<p>", $tf['err']) . "</p></div>",
                       defval($tf, 'anyErrors') ? "merror" : "warning");
        }

        $confirm = array();
        if (isset($tf["confirm"]) && count($tf["confirm"]) > 0)
            $confirm = array_merge($confirm, $tf["confirm"]);
        if (isset($tf["newlySubmitted"]) && count($tf["newlySubmitted"]) > 0)
            $confirm[] = self::_paperCommaJoin("Submitted new review* for paper* ", $tf["newlySubmitted"]) . ".";
        if (isset($tf["updated"]) && count($tf["updated"]) > 0)
            $confirm[] = self::_paperCommaJoin("Updated review* for paper* ", $tf["updated"]) . ".";
        $nconfirm = count($confirm);
        if (isset($tf["unchanged"]) && count($tf["unchanged"]) > 0)
            $confirm[] = self::_paperCommaJoin("Review* for paper* ", $tf["unchanged"]) . " unchanged.";
        if (isset($tf["ignoredBlank"]) && count($tf["ignoredBlank"]) > 0)
            $confirm[] = self::_paperCommaJoin("Ignored blank review form* for paper* ", $tf["ignoredBlank"]) . ".";
        // self::tfError($tf, false, "Ignored blank " . pluralx(count($tf["ignoredBlank"]), "review form") . " for " . self::_paperCommaJoin("review form* for paper*", $tf["ignoredBlank"]) . ".");
        if (count($confirm))
            $Conf->msg("<div class='parseerr'><p>" . join("</p>\n<p>", $confirm) . "</p></div>", $nconfirm ? "confirm" : "warning");
    }

    function webDisplayRows($rrow, $revViewScore) {
        global $ReviewFormError, $scoreHelps, $Conf;

        // Which fields are options?
        $fshow = array();
        $fdisp = array();
        foreach ($this->forder as $field => $f) {
            $fval = ($rrow ? $rrow->$field : "");
            if ($f->view_score > $revViewScore
                && ($f->has_options || $fval != "")) {
                $fshow[] = $f;
                $fdisp[] = ($f->has_options ? self::WEB_OPTIONS : 0);
            }
        }

        // Group fields into positions on the left or right
        foreach ($fdisp as $i => &$disp) {
            if (!($disp & self::WEB_OPTIONS))
                $disp |= self::WEB_FULL;
            else if ($i > 0 && ($fdisp[$i-1] & self::WEB_LEFT))
                $disp |= self::WEB_RIGHT;
            else if ($i+1 < count($fdisp) && ($fdisp[$i+1] & self::WEB_OPTIONS))
                $disp |= self::WEB_LEFT;
            else
                $disp |= self::WEB_FULL;
            if ($i + 1 == count($fdisp)
                || ($i + 2 == count($fdisp) && ($disp & self::WEB_LEFT)))
                $disp |= self::WEB_FINAL;
        }
        unset($disp);

        // Actually display
        $x = "";
        foreach ($fshow as $fnum => $f) {
            $disp = $fdisp[$fnum];
            $field = $f->id;
            $fval = ($rrow ? $rrow->$field : "");
            if ($f->has_options && $fval)
                $fval = $f->unparse_value($fval);

            $n = $f->name_html;
            if (preg_match("/\\A\\S+\\s+\\S+\\z/", $n))
                $n = preg_replace("/\\s+/", "&nbsp;", $n);

            $c = "<span class='revfn'>" . $n;
            if ($f->has_options) {
                $c .= " <a class='scorehelp' href='" . hoturl("scorehelp", "f=$field") . "'>(?)</a>";
                if (count($scoreHelps) == 0)
                    $Conf->footerScript("addScoreHelp()");
                if (!isset($scoreHelps[$field])) {
                    $scoreHelps[$field] = 1;
                    $help = "<div class='scorehelpc' id='scorehelp_$field'><strong>$n</strong> choices are:<br /><span class='rev_$field'>";
                    foreach ($f->options as $val => $text)
                        $help .= "<span class='rev_num'>$val.</span>&nbsp;" . htmlspecialchars($text) . "<br />";
                    $help .= "</span></div>";
                    $Conf->footerHtml($help);
                }
            }
            $c .= "</span>";
            if ($f->view_score < VIEWSCORE_REVIEWERONLY)
                $c .= "<span class='revvis'>(secret)</span>";
            else if ($f->view_score < VIEWSCORE_PC)
                $c .= "<span class='revvis'>(shown only to chairs)</span>";
            else if ($f->view_score < VIEWSCORE_AUTHOR)
                $c .= "<span class='revvis'>(hidden from authors)</span>";

            if (!($disp & self::WEB_RIGHT)) {
                if ($x == "")
                    $x = "<div class='rvgb'>";
                else if ($disp & self::WEB_FINAL)
                    $x .= "<div class='rvge'>";
                else
                    $x .= "<div class='rvg'>";
            }
            if ($disp & self::WEB_LEFT)
                $x .= "<div class='rvl'>";
            else if ($disp & self::WEB_RIGHT)
                $x .= "<div class='rvr'>";
            else
                $x .= "<div class='rvb'>";
            $x .= "<div class='rv rv_$field'><div class='revt'>" . $c . "<div class='clear'></div>"
                . '</div><div class="revv">';
            if ($f->has_options) {
                if (!$fval || !isset($f->options[$fval]))
                    $x .= "<span class='rev_${field} rev_unknown'>Unknown</span>";
                else {
                    $xfval = substr($f->unparse_value($fval, "rev_num"), 0, -7);
                    $x .= "<span class='rev_${field}'>" . $xfval . ".</span> "
                        . htmlspecialchars($f->options[$fval])
                        . "</span>";
                }
            } else
                $x .= '<div class="revtext">' . link_urls(htmlspecialchars($fval)) . '</div>';
            $x .= "</div></div></div>";
            if ($disp & self::WEB_RIGHT)
                $x .= "<div class='clear'></div>";
            if (!($disp & self::WEB_LEFT))
                $x .= "</div>\n";
        }

        return "<div class='rvtab'>" . $x . "</div>\n";
    }

    function webGuidanceRows($revViewScore, $extraclass="") {
        global $ReviewFormError;
        $x = '';
        $needRow = 1;

        foreach ($this->forder as $field => $f) {
            if ($f->view_score <= $revViewScore
                || (!$f->description && !$f->has_options))
                continue;

            $x .= "<tr class='rev_$field'>\n";
            $x .= "  <td class='caption rev_$field$extraclass'>";
            $x .= $f->name_html . "</td>\n";

            $x .= "  <td class='entry rev_$field$extraclass'>";
            if ($f->description)
                $x .= "<div class='rev_description'>" . $f->description . "</div>";
            if ($f->has_options) {
                $x .= "<div class='rev_options'>Choices are:";
                foreach ($f->options as $num => $val)
                    $x .= "<br />\n<span class='rev_num'>$num.</span> " . htmlspecialchars($val);
                $x .= "</div>";
            }

            $x .= "</td>\n</tr>\n";
            $extraclass = "";
        }

        return $x;
    }

    function _showWebDisplayBody($prow, $rrows, $rrow, $reviewOrdinal, &$options) {
        global $Conf, $Me;

        // Do not show rating counts if rater identity is unambiguous.
        // See also PaperSearch::_clauseTermSetRating.
        $visibleRatings = false;
        if (isset($rrow->numRatings) && $rrow->numRatings > 0) {
            if (!isset($options["nsubraters"])) {
                $options["nsubraters"] = 0;
                $rateset = $Conf->setting("rev_ratings");
                foreach ($rrows as $rr)
                    if ($rr->reviewSubmitted
                        && ($rateset == REV_RATINGS_PC
                            ? $rr->reviewType > REVIEW_EXTERNAL
                            : $rateset == REV_RATINGS_PC_EXTERNAL))
                        $options["nsubraters"]++;
            }
            $visibleRatings = ($rrow->contactId != $Me->contactId
                    || $Me->canAdminister($prow) || $options["nsubraters"] > 2
                    || $Conf->timePCViewAllReviews()
                    || strpos($rrow->allRatings, ",") !== false);
        }
        if ($Me->can_view_review_ratings($prow, $rrow)
            && ($Me->can_rate_review($prow, $rrow) || $visibleRatings)) {
            $ratesep = "";
            echo "<div class='rev_rating'>";
            if ($visibleRatings) {
                $rates = array();
                foreach (explode(",", $rrow->allRatings) as $r)
                    $rates[$r] = defval($rates, $r, 0) + 1;
                echo "<span class='rev_rating_summary'>Ratings: ";
                $ratearr = array();
                foreach ($rates as $type => $count)
                    if (isset(self::$rating_types[$type]))
                        $ratearr[] = $count . " &ldquo;" . htmlspecialchars(self::$rating_types[$type]) . "&rdquo;";
                echo join(", ", $ratearr), "</span>";
                $ratesep = " &nbsp;<span class='barsep'>|</span>&nbsp; ";
            }
            if ($rrow->contactId != $Me->contactId) {
                $ratinglink = hoturl_post("review", "r=$reviewOrdinal" . (isset($_REQUEST["reviewId"]) ? "" : "&amp;allr=1"));
                echo $ratesep,
                    Ht::form_div($ratinglink, array("id" => "ratingform_$reviewOrdinal", "divclass" => "inline")),
                    "How helpful is this review? &nbsp;",
                    Ht::select("rating", self::$rating_types, ($rrow->myRating === null ? "n" : $rrow->myRating)),
                    " ", Ht::submit("Save", array("class" => "fx7")),
                    "</div></form>",
                    "<span id='ratingform_${reviewOrdinal}result'></span>";
                if (!defval($options, "ratingsajax")) {
                    $Conf->footerScript("addRatingAjax()");
                    $options["ratingsajax"] = true;
                }
            }
            echo " &nbsp;<span class='barsep'>|</span>&nbsp; <a href='", hoturl("help", "t=revrate"), "'>What is this?</a></div>";
        }

        if (defval($options, "editmessage"))
            echo "<div class='hint'>", defval($options, "editmessage"), "</div>\n";

        echo "<div class='clear'></div></div><div class=\"revcard_body\">",
            $this->webDisplayRows($rrow, $Me->viewReviewFieldsScore($prow, $rrow)),
            "</div></div>\n\n";
    }

    private function _review_buttons($prow, $rrow, $type, $reviewPostLink) {
        global $Conf, $Me;
        $buttons = array();

        // refuse?
        if ($type == "top" && $rrow && !$rrow->reviewModified
            && $rrow->reviewType < REVIEW_SECONDARY) {
            $buttons[] = Ht::submit("accept", "Accept review", array("class" => "b"));
            $buttons[] = Ht::button("Decline review",
                                    array("class" => "b",
                                          "onclick" => "popup(this,'ref',0)"));
            // Also see $_REQUEST["refuse"] case in review.php.
            $Conf->footerHtml("<div id='popup_ref' class='popupc'>
  <p style='margin:0 0 0.3em'>Select “Decline review” to decline this review. Thank you for keeping us informed.</p>
  " . Ht::form_div($reviewPostLink) . Ht::hidden("refuse", "refuse")
    . "<textarea id='refusereviewreason' class='temptext' name='reason' rows='3' cols='40'>Optional explanation</textarea>
    <div class='popup_actions'>"
    . Ht::js_button("Cancel", "popup(null,'ref',1)")
    . " &nbsp;" . Ht::submit("Decline review", array("class" => "bb"))
    . "</div></div></form></div>", "declinereviewform");
            $Conf->footerScript("mktemptext('refusereviewreason','Optional explanation')", "declinereviewform_temptext");
            $buttons[] = "";
        }

        $submitted = $rrow && $rrow->reviewSubmitted;
        if (!$submitted) {
            $buttons[] = Ht::submit("submit", "Submit review", array("class" => "bb"));
            $buttons[] = Ht::submit("savedraft", "Save as draft");
        } else
            $buttons[] = Ht::submit("submit", "Save changes", array("class" => "bb"));

        if ($rrow && $type == "bottom" && $Me->allowAdminister($prow)) {
            $buttons[] = "";
            if ($submitted)
                $buttons[] = array(Ht::submit("unsubmit", "Unsubmit review"), "(admin only)");
            $buttons[] = array(Ht::js_button("Delete review", "popup(this,'d',0)"), "(admin only)");
            $Conf->footerHtml("<div id='popup_d' class='popupc'>
  <p>Be careful: This will permanently delete all information about this
  review assignment from the database and <strong>cannot be
  undone</strong>.</p>
  " . Ht::form_div($reviewPostLink, array("divclass" => "popup_actions"))
    . Ht::js_button("Cancel", "popup(null,'d',1)") . " &nbsp;"
    . Ht::submit("delete", "Delete review", array("class" => "bb"))
    . "</div></form></div>");
        }

        return $buttons;
    }

    function show($prow, $rrows, $rrow, &$options) {
        global $Conf, $Opt, $Me, $useRequest;

        if (!$options)
            $options = array();
        $editmode = defval($options, "edit", false);

        $reviewOrdinal = unparseReviewOrdinal($rrow);
        $reviewLinkArgs = "p=$prow->paperId" . ($rrow ? "&amp;r=$reviewOrdinal" : "") . "&amp;m=re";
        $reviewPostLink = hoturl_post("review", $reviewLinkArgs);
        $reviewDownloadLink = hoturl("review", $reviewLinkArgs . "&amp;downloadForm=1");
        $admin = $Me->allowAdminister($prow);

        if ($editmode) {
            echo Ht::form($reviewPostLink), '<div class="aahc">',
                Ht::hidden_default_submit("default", "");
            if ($rrow)
                echo Ht::hidden("version", defval($rrow, "reviewEditVersion", 0) + 1);
        }
        echo '<div class="revcard"><div class="revcard_head">';

        // Links
        if ($rrow) {
            echo "<div class='floatright'>";
            if (!$editmode && $Me->canReview($prow, $rrow))
                echo "<a href='" . hoturl("review", "r=$reviewOrdinal") . "' class='xx'>",
                    Ht::img("edit.png", "[Edit]", "b"),
                    "&nbsp;<u>Edit</u></a><br />";
            echo "<a href='" . hoturl("review", "r=$reviewOrdinal&amp;text=1") . "' class='xx'>",
                Ht::img("txt.png", "[Text]", "b"),
                "&nbsp;<u>Plain text</u></a>",
                "</div>";
        }

        echo "<h3>";
        if ($rrow) {
            echo "<a href='", hoturl("review", "r=$reviewOrdinal"), "' name='review$reviewOrdinal' class='",
                ($editmode ? "q'>Edit " : "u'>"), "Review";
            if ($rrow->reviewSubmitted)
                echo "&nbsp;#", $prow->paperId, unparseReviewOrdinal($rrow->reviewOrdinal);
            echo "</a>";
        } else
            echo "Write Review";
        echo "</h3>\n";

        $open = $sep = " <span class='revinfo'>";
        $xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";
        $showtoken = $rrow && $rrow->reviewToken && $Me->canReview($prow, $rrow);
        if ($rrow && $Me->canViewReviewerIdentity($prow, $rrow, null)
            && (!$showtoken || !preg_match('/^anonymous\d*$/', $rrow->email))) {
            echo $sep, ($rrow->reviewBlind ? "[" : ""), "by ", Text::user_html($rrow), ($rrow->reviewBlind ? "]" : "");
            $sep = $xsep;
        }
        if ($showtoken) {
            echo $sep, "Review token ", encode_token((int) $rrow->reviewToken);
            $sep = $xsep;
        }
        if ($rrow && $rrow->reviewModified > 0) {
            echo $sep, "Modified ", $Conf->printableTime($rrow->reviewModified);
            $sep = $xsep;
        }
        if ($sep != $open)
            echo "</span>\n";

        if (!$editmode) {
            $this->_showWebDisplayBody($prow, $rrows, $rrow, $reviewOrdinal, $options);
            return;
        }

        // From here on, edit mode.
        if (defval($options, "editmessage"))
            echo "<div class='hint'>", defval($options, "editmessage"), "</div>\n";

        // delegate?
        if ($rrow && !$rrow->reviewSubmitted
            && $rrow->contactId == $Me->contactId
            && $rrow->reviewType == REVIEW_SECONDARY) {
            echo "\n<div class='rev_del'>";

            $ndelegated = 0;
            foreach ($rrows as $rr)
                if ($rr->reviewType == REVIEW_EXTERNAL
                    && $rr->requestedBy == $rrow->contactId)
                    $ndelegated++;

            if ($ndelegated == 0)
                echo "As a secondary reviewer, you can <a href=\"", hoturl("assign", "p=$rrow->paperId"), "\">delegate this review to an external reviewer</a>, but if your external reviewer declines to review the paper, you should complete the review yourself.";
            else if ($rrow->reviewNeedsSubmit == 0)
                echo "A delegated external reviewer has submitted their review, but you can still complete your own if you’d like.";
            else
                echo "Your delegated external reviewer has not yet submitted a review.  If they do not, you should complete the review yourself.";
            echo "</div>\n";
        }

        // message?
        if ($rrow && !$Me->ownReview($rrow) && $admin)
            echo "<div class='hint'>You didn’t write this review, but as an administrator you can still make changes.</div>\n";

        // download?
        echo '<div class="clear"></div>';
        echo "<table class='revoff'><tr>
      <td><span class='revfn'>Offline reviewing</span></td>
      <td>Upload form: &nbsp; <input type='file' name='uploadedFile' accept='text/plain' size='30' />
      &nbsp; ", Ht::submit("uploadForm", "Go"), "</td>
    </tr><tr>
      <td></td>
      <td><a href='$reviewDownloadLink'>Download form</a>
      &nbsp;<span class='barsep'>|</span>&nbsp;
      <span class='hint'><strong>Tip:</strong> Use <a href='", hoturl("search"), "'>Search</a> or <a href='", hoturl("offline"), "'>Offline reviewing</a> to download or upload many forms at once.</span></td>
    </tr></table></div>\n";

        // ready?
        $ready = ($useRequest ? defval($_REQUEST, "ready") : !($rrow && $rrow->reviewModified && !$rrow->reviewSubmitted));

        // top save changes button
        echo '<div class="revcard_body">';
        if ($Me->timeReview($prow, $rrow) || $admin) {
            $buttons = $this->_review_buttons($prow, $rrow, "top", $reviewPostLink);
            echo Ht::actions($buttons, array("style" => "margin-top:0"));
        }

        // blind?
        if ($Conf->review_blindness() == Conference::BLIND_OPTIONAL) {
            echo "<div class='revt'><span class='revfn'>",
                Ht::checkbox_h("blind", 1, ($useRequest ? defval($_REQUEST, 'blind') : (!$rrow || $rrow->reviewBlind))),
                "&nbsp;", Ht::label("Anonymous review"),
                "</span><div class='clear'></div></div>\n",
                "<div class='revhint'>", htmlspecialchars($Opt["shortName"]), " allows either anonymous or open review.  Check this box to submit your review anonymously (the authors won&rsquo;t know who wrote the review).</div>\n",
                "<div class='g'></div>\n";
        }

        // form body
        echo $this->webFormRows($Me, $prow, $rrow, $useRequest);

        // review actions
        if ($Me->timeReview($prow, $rrow) || $admin) {
            $buttons = $this->_review_buttons($prow, $rrow, "bottom", $reviewPostLink);
            echo Ht::actions($buttons);

            if ($admin)
                echo Ht::checkbox("override"), "&nbsp;", Ht::label("Override deadlines");
            if ($rrow && $rrow->reviewSubmitted && !$admin)
                echo "<div class='hint'>Only administrators can remove or unsubmit the review at this point.</div>";
        }

        echo "</div></div></div></form>\n\n";
    }

    function maxNumericScore($field) {
        if (($f = $this->field($field)) && $f->has_options)
            return count($f->options);
        else
            return 0;
    }


    function reviewFlowEntry($contact, $rrow, $trclass) {
        // See also CommentView::commentFlowEntry
        global $Conf;
        $barsep = " &nbsp;<span class='barsep'>|</span>&nbsp; ";
        $a = "<a href='" . hoturl("paper", "p=$rrow->paperId#review" . unparseReviewOrdinal($rrow)) . "'";
        $t = "<tr class='$trclass'><td class='pl_activityicon'>" . $a . ">"
            . Ht::img("review24.png", "[Review]", "dlimg")
            . "</a></td><td class='pl_activityid pnum'>"
            . $a . ">#$rrow->paperId</a></td><td class='pl_activitymain'><small>"
            . $a . " class=\"ptitle\">" . htmlspecialchars($rrow->shortTitle);
        if (strlen($rrow->shortTitle) != strlen($rrow->title))
            $t .= "...";
        $t .= "</a>";
        if ($contact->canViewReviewerIdentity($rrow, $rrow, false))
            $t .= $barsep . "<span class='hint'>review by</span> " . Text::user_html($rrow->reviewFirstName, $rrow->reviewLastName, $rrow->reviewEmail);
        $t .= $barsep . "<span class='hint'>submitted</span> " . $Conf->parseableTime($rrow->reviewSubmitted, false);
        $t .= "</small><br /><a class='q'" . substr($a, 3) . ">";

        $revViewScore = $contact->viewReviewFieldsScore($rrow, $rrow);
        if ($rrow->reviewSubmitted) {
            $t .= "Review #" . unparseReviewOrdinal($rrow) . " submitted";
            $xbarsep = $barsep;
        } else
            $xbarsep = "";
        foreach ($this->forder as $field => $f)
            if ($f->view_score > $revViewScore && $f->has_options
                && $rrow->$field) {
                $t .= $xbarsep . $f->name_html . "&nbsp;"
                    . $f->unparse_value($rrow->$field, true);
                $xbarsep = $barsep;
            }

        return $t . "</a></td></tr>";
    }

}
