<?php
// review.inc -- HotCRP helper class for producing review forms and tables
// HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $scoreHelps;
$scoreHelps = array();

global $ratingTypes;
$ratingTypes = array("n" => "average", 1 => "very helpful",
		     0 => "too short", -1 => "too vague",
		     -4 => "too narrow",
		     -2 => "not constructive", -3 => "not correct");

class ReviewForm {

    const WEB_OPTIONS = 1;
    const WEB_FULL = 2;
    const WEB_LEFT = 4;
    const WEB_RIGHT = 8;
    const WEB_FINAL = 32;

    var $updatedWhen;

    var $reviewFields;
    var $fieldOrder;
    var $shortName;
    var $fieldName;
    var $fieldRows;
    var $abbrevName;
    var $description;
    var $authorView;
    var $options;
    var $topicName;
    var $topicOrder;

    function __construct($conf) {
	$this->updatedWhen = 0;

	$this->reviewFields = array('paperSummary' => 0,
				    'commentsToAuthor' => 0,
				    'commentsToPC' => 0,
				    'commentsToAddress' => 0,
				    'weaknessOfPaper' => 0,
				    'strengthOfPaper' => 0,
				    'textField7' => 0,
				    'textField8' => 0,
				    'potential' => 1,
				    'fixability' => 1,
				    'overAllMerit' => 1,
				    'reviewerQualification' => 1,
				    'novelty' => 1,
				    'technicalMerit' => 1,
				    'interestToCommunity' => 1,
				    'longevity' => 1,
				    'grammar' => 1,
				    'likelyPresentation' => 1,
				    'suitableForShort' => 1);

	$this->updateFromDB($conf);
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
	foreach ($this->fieldOrder as $fn) {
	    if (count($a) == 1 && strcasecmp($a[0], $this->abbrevName[$fn]) == 0)
		return $fn;
	    for ($i = 0; $i < count($a); ++$i)
		if ($a[$i] != "-" && $a[$i] != "_"
		    && !preg_match("{\\b$a[$i]}i", $this->shortName[$fn]))
		    break;
	    if ($i == count($a))
		$field = ($field === null ? $fn : false);
	}
	return $field;
    }

    function parseOption($optname, $text, $strict) {
	if (!$strict && strlen($text) > 1
	    && preg_match('/\A\s*([0-9]+|[A-Z])(\W|\z)/', $text, $m))
	    $text = $m[1];
	if (!$strict && ($xval = cvtint($text)) > 0)
	    $text = $xval;
	if (!$text || !isset($this->options[$optname][$text]))
	    return null;
	$opt1 = $this->reviewFields[$optname];
	return ($opt1 <= 1 ? $text : $opt1 - ord($text));
    }

    function unparseOption($field, $value, $preserveEmpty = false,
			   $scclass = false) {
	$opt1 = defval($this->reviewFields, $field);
	if (!$value && !$preserveEmpty)
	    return "";
	if ($opt1 <= 1)
	    $x = $value;
	else if (is_int($value) || ctype_digit($value))
	    $x = chr($opt1 - $value);
	else {
	    $value = $opt1 - ord($value);
	    $x = chr($opt1 - $value);
	}
	if ($scclass) {
	    $nvalues = count($this->options[$field]);
	    $sckey = (int) (($value - 1) * 8.0 / ($nvalues - 1) + 1.5);
	    $class = ($scclass === true ? "sc$sckey" : "$scclass sc$sckey");
	    return "<span class='$class'>$x</span>";
	} else
	    return $x;
    }

    function analyzeField($field) {
	if (!isset($this->reviewFields[$field]))
	    return null;
	$sn = $this->shortName[$field];
	$an = $this->abbrevName[$field];
	$an1 = self::abbreviateField($sn, 1);
	$a = (object) array("name" => $field,
			    "shortName" => $sn,
			    "shortHtml" => htmlspecialchars($sn),
			    "abbrevName" => $an,
			    "abbrevHtml" => htmlspecialchars($an),
			    "abbrevName1" => $an1,
			    "abbrevHtml1" => htmlspecialchars($an1),
			    "options" => defval($this->options, $field, null));
	if ($a->options) {
	    $scores = array_keys($a->options);
	    if (count($scores) == 1)
		$a->typScore = $scores[0];
	    else {
		if (count($scores) == 2) {
		    $a->typScore0 = $scores[0];
		    $a->typScore = $scores[1];
		} else {
		    $a->typScore0 = $scores[1];
		    $a->typScore = $scores[2];
		}
		if (is_int($a->typScore) || ctype_digit($a->typScore))
		    $a->typScoreRange = $a->typScore0 . "-" . $a->typScore;
		else
		    $a->typScoreRange = $a->typScore0 . $a->typScore;
	    }
	}
	return $a;
    }

    function updateFromDB($conf) {
	$this->fieldOrder = array();
	$this->shortName = array();
	$this->fieldName = array();
	$this->fieldRows = array();
	$this->abbrevName = array();
	$this->description = array();
	$this->authorView = array();
	$this->options = array();
	$this->topicName = array();
	$this->topicOrder = array();

	$while = "while updating review form information";

	$result = $conf->qe("select * from ReviewFormField order by sortOrder, shortName", $while);
	if (!$result)
	    return;
	while (($row = edb_orow($result))) {
	    $n = $row->fieldName;
	    if ($row->sortOrder >= 0)
		$this->fieldOrder[] = $row->fieldName;
	    $this->shortName[$n] = $row->shortName;
	    $this->fieldName[strtolower($row->shortName)] = $n;
	    $this->abbrevName[$n] = self::abbreviateField($row->shortName);
	    $this->description[$n] = $row->description;
	    $this->fieldRows[$n] = $row->rows;
	    if (!$this->reviewFields[$n] && $row->rows < 3)
		$this->fieldRows[$n] = 3;
	    $this->authorView[$n] = $row->authorView;
	    if ($this->reviewFields[$n] && isset($row->levelChar))
		$this->reviewFields[$n] = max($row->levelChar, 1);
	}

	$result = $conf->qe("select fieldName, level, description from ReviewFormOptions order by fieldName, level", $while);
	if (!$result)
	    return;
	while (($row = edb_row($result))) {
	    $otext = $this->unparseOption($row[0], $row[1], true);
	    $this->options[$row[0]][$otext] = $row[2];
	}
	foreach ($this->reviewFields as $n => $levelChar)
	    if ($levelChar > 1)
		$this->options[$n] = array_reverse($this->options[$n], true);

	$result = $conf->qe("select topicId, topicName from TopicArea order by topicName", $while);
	if (!$result)
	    return;
	$i = 0;
	$leftovers = array();
	while (($row = edb_row($result))) {
	    $this->topicName[$row[0]] = $row[1];
	    if (substr(strtolower($row[1]), 0, 7) != "none of")
		$this->topicOrder[$row[0]] = $i++;
	    else
		$leftovers[] = $row[0];
	}
	foreach ($leftovers as $topicid)
	    $this->topicOrder[$topicid] = $i++;

	$this->updatedWhen = time();

	$_SESSION["rf"] = serialize($this);
    }

    function validate($conf, $always = false) {
	if ($always || !$this->updatedWhen || $this->updatedWhen <= $conf->settings['revform_update'])
	    $this->updateFromDB($conf);
    }

    function reviewArchiveFields() {
	global $Conf;
        $fields = "reviewId, paperId, contactId, reviewType, requestedBy,
		requestedOn, reviewModified, reviewSubmitted,
		reviewNeedsSubmit, "
	    . join(", ", array_keys($this->reviewFields))
	    . ", reviewRound";
	if ($Conf->sversion >= 37)
	    $fields .= ", reviewNotified";
	return $fields;
    }

    function webFormRows($contact, $prow, $rrow, $useRequest = false) {
	global $ReviewFormError, $Conf;
	$x = "";
	$revViewScore = $contact->viewReviewFieldsScore($prow, $rrow);
	foreach ($this->fieldOrder as $field) {
	    if ($this->authorView[$field] <= $revViewScore)
		continue;
	    $fval = "";
	    $options = isset($this->options[$field]);
	    if ($useRequest && isset($_REQUEST[$field]))
		$fval = $_REQUEST[$field];
	    else if ($rrow) {
		if ($options)
		    $fval = $this->unparseOption($field, $rrow->$field);
		else
		    $fval = $rrow->$field;
	    }

	    $n = htmlspecialchars($this->shortName[$field]);
	    $c = "<span class='revfn'>" . $n . "</span>";
	    if ($this->authorView[$field] < -1)
		$c .= "<div class='revevis'>(secret)</div>";
	    else if ($this->authorView[$field] < 0)
		$c .= "<div class='revevis'>(shown only to chairs)</div>";
	    else if ($this->authorView[$field] == 0)
		$c .= "<div class='revevis'>(hidden from authors)</div>";

	    $x .= "<div class='revt";
	    if (isset($ReviewFormError[$field]))
		$x .= " error";
	    $x .= "'>" . $c . "<div class='clear'></div></div>";
	    if ($this->description[$field])
		$x .= "<div class='revhint'>" . $this->description[$field] . "</div>\n";
	    $x .= "<div class='revev'>";
	    if ($options) {
		$x .= "<select name='$field' onchange='hiliter(this)'>\n";
		if (!$this->parseOption($field, $fval, true))
		    $x .= "    <option value='0' selected='selected'>(Your choice here)</option>\n";
		foreach ($this->options[$field] as $num => $what) {
		    $x .= "    <option value='$num'";
		    if ($num == $fval)
			$x .= " selected='selected'";
		    $x .= ">$num. " . htmlspecialchars($what) . "</option>\n";
		}
		$x .= "</select>";
	    } else {
		$x .= "<textarea name='$field' class='reviewtext' onchange='hiliter(this)' rows='" . $this->fieldRows[$field] . "' cols='60'>" . htmlspecialchars($fval) . "</textarea>";
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
	foreach ($this->fieldOrder as $field) {
	    if (!isset($req[$field]) && !$submit)
		continue;
	    $fval = defval($req, $field, ($rrow ? $rrow->$field : ""));
	    if (!isset($req[$field]) && $this->authorView[$field] >= 0)
		$missing[] = $this->shortName[$field];
	    if (isset($this->options[$field])) {
		$fval = trim($fval);
		if ($fval === "" || $fval === "0" || $fval[0] === "(") {
		    if ($submit && $this->authorView[$field] >= 0) {
			$provide[] = $this->shortName[$field];
			$ReviewFormError[$field] = 1;
		    }
		} else if (!$this->parseOption($field, $fval, false)) {
		    $outofrange[] = $field;
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
	    self::tfError($tf, true, "This review form claims to be written by " . htmlspecialchars($req["reviewerEmail"]) . " rather than the review&rsquo;s owner, " . contactHtml($rrow) . ".  If you want to upload it anyway, remove the &ldquo;<code>==+==&nbsp;Reviewer</code>&rdquo; line from the form and try again.", "reviewerEmail");
	    return 0;
	}
	if (isset($outofrange)) {
	    if ($tf)
		foreach ($outofrange as $field)
		    self::tfError($tf, true, "Bad value for field \"" . htmlentities($this->shortName[$field]) . "\"", $field);
	    else {
		foreach ($outofrange as $field)
		    $oor2[] = htmlspecialchars($this->shortName[$field]);
		$Conf->errorMsg("Bad values for " . commajoin($oor2) . ".  Please fix this and submit again.");
	    }
	    return 0;
	}
	if ($nokfields == 0 && $tf) {
	    $tf["ignoredBlank"][] = "#" . $req["paperId"];
	    return 0;
	}
	if (isset($provide)) {
	    $w = "This review is still not ready for others to see because you did not set some mandatory fields.  Please set " . htmlspecialchars(commajoin($provide)) . " and submit again.";
	    if ($tf)
		self::tfError($tf, false, $w);
	    else
		$Conf->warnMsg($w);
	    $req["unready"] = true;
	}
	return ($nokfields > 0);
    }

    function saveRequest($req, $rrow, $prow, &$tf = null) {
	global $Conf, $Opt;
	$Me = $_SESSION['Me'];
	$submit = defval($req, "ready", false) && !defval($req, "unready", false);
	$while = "while storing review";

	if (!$Me->timeReview($prow, $rrow)
	    && (!isset($req['override']) || !$Me->privChair))
	    return $Conf->errorMsg("The <a href='" . hoturl("deadlines") . "'>deadline</a> for entering this review has passed." . ($Me->privChair ? "  Select the \"Override deadlines\" checkbox and try again if you really want to override the deadline." : ""));

	$q = array();
	$different = false;
	foreach ($this->fieldOrder as $field)
	    if (isset($req[$field])) {
		$fval = $req[$field];
		if (isset($this->options[$field])) {
		    if (!($fval = $this->parseOption($field, $fval, false)))
			continue;
		} else {
		    $fval = rtrim($fval);
		    if ($fval != "")
			$fval .= "\n";
		    $req[$field] = $fval;
		}
		if ($rrow && strcmp($rrow->$field, $fval) != 0
		    && strcmp(cleannl($rrow->$field), cleannl($fval)) != 0) {
		    $different = true;
		    // Check for valid UTF-8.  Re-encode invalid UTF-8 as if
		    // it were Windows-1252, which is a superset of
		    // ISO-8859-1.
		    if (!is_valid_utf8($fval))
			$req[$field] = $fval = windows_1252_to_utf8($fval);
		}
		$q[] = "$field='" . sqlq($fval) . "'";
	    }

	// get the current time
	$now = time();
	if ($rrow && $rrow->reviewModified && $rrow->reviewModified > $now)
	    $now = $rrow->reviewModified + 1;
	$donotify = false;

	// potentially assign review ordinal (requires table locking since
	// mySQL is stupid)
	$locked = false;
	if ($submit && (!$rrow || !$rrow->reviewSubmitted)) {
	    $different = true;
	    $q[] = "reviewSubmitted=$now, reviewNeedsSubmit=0";
	    if (!$rrow || !$rrow->reviewOrdinal) {
		$result = $Conf->qe("lock tables PaperReview write", $while);
		if (!$result)
		    return $result;
		$locked = true;
		$result = $Conf->qe("select coalesce(max(reviewOrdinal), 0) from PaperReview where paperId=$prow->paperId group by paperId", $while);
		if ($result) {
		    $crow = edb_row($result);
		    $q[] = "reviewOrdinal=coalesce(reviewOrdinal, " . ($crow[0] + 1) . ")";
		}
	    }
	}

	// check whether used a review token
	$usedReviewToken = $rrow && $rrow->reviewToken
	    && isset($_SESSION["rev_tokens"])
	    && array_search($rrow->reviewToken, $_SESSION["rev_tokens"]) !== false;

	// blind? reviewer type? edit version?
	$reviewBlind = ($Conf->blindReview() > BLIND_OPTIONAL || ($Conf->blindReview() == BLIND_OPTIONAL && defval($req, 'blind')) ? 1 : 0);
	if ($rrow && $reviewBlind != $rrow->reviewBlind)
	    $different = true;
	$q[] = "reviewBlind=$reviewBlind";
	if ($rrow && $rrow->reviewType == REVIEW_EXTERNAL
	    && $Me->contactId == $rrow->contactId
	    && $Me->isPC && !$usedReviewToken)
	    $q[] = "reviewType=" . REVIEW_PC;
	if ($rrow && $different && isset($req["version"])
	    && $req["version"] > defval($rrow, "reviewEditVersion"))
	    $q[] = "reviewEditVersion=" . $req["version"];
	if (!$rrow || $different) {
	    $q[] = "reviewModified=" . $now;
	    // do not notify on updates within 3 hours
	    if ($submit && (!$rrow || !$rrow->reviewNotified
			    || $rrow->reviewNotified + 10800 < $now)) {
		$q[] = "reviewNotified=" . $now;
		$donotify = true;
	    }
	}

	// actually affect database
	if ($rrow) {
	    $result = $Conf->qe("update PaperReview set " . join(", ", $q) . " where reviewId=$rrow->reviewId", $while);
	    $reviewId = $rrow->reviewId;
	    $contactId = $rrow->contactId;
	} else {
	    $result = $Conf->qe("insert into PaperReview set paperId=$prow->paperId, contactId=$Me->contactId, reviewType=" . REVIEW_PC . ", requestedBy=$Me->contactId, requestedOn=current_timestamp, " . join(", ", $q), $while);
	    $reviewId = $Conf->lastInsertId($while);
	    $contactId = $Me->contactId;
	}

	// unlock tables even if problem
	if ($locked)
	    $Conf->qe("unlock tables", $while);
	if (!$result)
	    return $result;

	// look up review ID
	if (!$reviewId)
	    return $reviewId;
	$req['reviewId'] = $reviewId;

	// log updates -- but not if review token is used
	if (!$usedReviewToken && $different) {
	    $reviewLogname = "Review $reviewId";
	    if ($rrow && $Me->contactId != $rrow->contactId)
		$reviewLogname .= " by $rrow->email";
	    $Conf->log("$reviewLogname saved", $Me, $prow->paperId);
	    if ($submit && (!$rrow || !$rrow->reviewSubmitted))
		$Conf->log("$reviewLogname submitted", $Me, $prow->paperId);
	}

	// potentially email chair and authors
	if ($submit && $donotify && ($Conf->timeEmailChairAboutReview()
				     || $Conf->timeEmailAuthorsAboutReview())) {

	    // fetch review ordinal
	    if (!$rrow || !$rrow->reviewSubmitted) {
		$result = $Conf->q("select reviewOrdinal from PaperReview where reviewId=$reviewId");
		if (edb_nrows($result) == 1) {
		    $crow = edb_row($result);
		    $req['reviewOrdinal'] = $crow[0];
		}
	    }

	    // construct mail
	    if (isset($req['reviewOrdinal']))
		$reviewnum = unparseReviewOrdinal($req['reviewOrdinal']);
	    else if ($rrow && $rrow->reviewSubmitted)
		$reviewnum = unparseReviewOrdinal($rrow->reviewOrdinal);
	    else
		$reviewnum = "x";

	    // need an up-to-date review row to send email successfully
	    $fake_rrow = (object) array("contactId" => $contactId, "reviewBlind" => $reviewBlind);

	    require_once("mailtemplate.inc");

	    $tmpl = ($rrow && $rrow->reviewSubmitted ? "@reviewupdate" : "@reviewsubmit");
	    $submitter = $Me;
	    if ($contactId != $submitter->contactId) {
		$submitter = new Contact();
		$submitter->lookupById($contactId);
	    }
	    $rest = array("rrow" => $fake_rrow, "reviewNumber" => $prow->paperId . $reviewnum);

	    if ($Conf->timeEmailChairAboutReview())
		Mailer::sendAdmin($tmpl, $prow, $submitter, $rest);

	    if ($Conf->timeEmailAuthorsAboutReview()) {
		$rest["infoMsg"] = "since a review was updated during the response period.";
		if (reviewBlind($fake_rrow))
		    $rest["infoMsg"] .= "  Reviewer anonymity was preserved.";
		Mailer::sendContactAuthors($tmpl, $prow, $submitter, $rest);
	    }
	}

	// if external, forgive the requestor from finishing their review
	if ($rrow && $rrow->reviewType == REVIEW_EXTERNAL && $submit)
	    $Conf->q("update PaperReview set reviewNeedsSubmit=0 where paperId=$prow->paperId and contactId=$rrow->requestedBy and reviewType=" . REVIEW_SECONDARY . " and reviewSubmitted is null");

	if ($tf) {
	    if ($submit && (!$rrow || !$rrow->reviewSubmitted))
		$tf["newlySubmitted"][] = "#$prow->paperId";
	    else if ($different)
		$tf["updated"][] = "#$prow->paperId";
	    else
		$tf["unchanged"][] = "#$prow->paperId";
	}

	return $result;
    }


    function textFormHeader($type, $editable) {
	global $Conf, $ConfSiteSuffix, $Opt;

	$x = "==+== " . $Opt["shortName"] . " Paper Review";
	if ($editable) {
	    $x .= " Form" . ($type === true ? "s" : "") . "\n==-== ";
	    if ($type === "blank")
		$x .= "Set the paper number and fill ";
	    else
		$x .= "Fill ";
	    $x .= "out lettered sections A through " . chr(65 + count($this->fieldOrder) - 1) . ".
==-== DO NOT CHANGE LINES THAT START WITH \"==+==\" UNLESS DIRECTED!
==-== A single file can contain multiple forms.
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
	if ($contact->canViewReviewerIdentity($prow, $rrow)) {
	    if ($rrow && isset($rrow->reviewFirstName))
		$x .= "==+== Reviewer: " . contactText($rrow->reviewFirstName, $rrow->reviewLastName, $rrow->reviewEmail) . "\n";
	    else if ($rrow && isset($rrow->lastName))
		$x .= "==+== Reviewer: " . contactText($rrow) . "\n";
	    else if ($myReview)
		$x .= "==+== Reviewer: " . contactText($contact) . "\n";
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
	    if ($Conf->blindReview() == BLIND_OPTIONAL) {
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
	foreach ($this->fieldOrder as $field) {
	    $i++;
	    if ($this->authorView[$field] <= $revViewScore)
		continue;

	    $fval = "";
	    $options = isset($this->options[$field]);
	    if ($req && isset($req[$field]))
		$fval = rtrim($req[$field]);
	    else if ($rrow != null && isset($rrow->$field)) {
		if ($options)
		    $fval = $this->unparseOption($field, $rrow->$field);
		else
		    $fval = rtrim(str_replace("\r\n", "\n", $rrow->$field));
	    }
	    if ($options && isset($this->options[$field][$fval]))
		$fval = "$fval. " . $this->options[$field][$fval];
	    else if (!$fval)
		$fval = "";
	    if (!$myReview && $fval == "")
		continue;

	    $x .= "\n==+== " . chr(64 + $i) . ". " . $this->shortName[$field];
	    if ($this->authorView[$field] < -1)
		$x .= " (secret)";
	    else if ($this->authorView[$field] < 0)
		$x .= " (shown only to chairs)";
	    else if ($this->authorView[$field] == 0)
		$x .= " (hidden from authors)";
	    $x .= "\n";
	    if (defval($this->description, $field)) {
		$d = cleannl($this->description[$field]);
		if (strpbrk($d, "&<") !== false)
		    $d = self::cleanDescription($d);
		$x .= wordWrapIndent($d, "==-==    ", "==-==    ");
	    }
	    if ($options && $myReview) {
		$first = true;
		foreach ($this->options[$field] as $num => $value) {
		    $y = ($first ? "==-== Choices: " : "==-==          ") . "$num. ";
		    $x .= wordWrapIndent($value, $y, str_pad("==-==", strlen($y))) . "\n";
		    $first = false;
		}
		if ($this->reviewFields[$field] <= 1)
		    $x .= "==-== Enter the number of your choice:\n";
		else
		    $x .= "==-== Enter the letter of your choice:\n";
		if ($fval == "")
		    $fval = "(Your choice here)";
	    }
	    $x .= "\n" . preg_replace("/^==\\+==/m", "\\==+==", $fval) . "\n";
	}
	return $x . "\n==+== Scratchpad (for unsaved private notes)\n\n==+== End Review\n";
    }

    function _prettyPaperTitle($prow, &$l) {
	$n = "Paper #" . $prow->paperId . ": ";
	$l = max(14, (int) ((75.5 - strlen($prow->title) - strlen($n)) / 2) + strlen($n));
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
	    $revViewScore = 0;
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
	$x .= $this->_prettyPaperTitle($prow, $l);
	if ($rrow && $contact->canViewReviewerIdentity($prow, $rrow, true)) {
	    if (isset($rrow->reviewFirstName))
		$n = contactText($rrow->reviewFirstName, $rrow->reviewLastName, $rrow->reviewEmail);
	    else if (isset($rrow->lastName))
		$n = contactText($rrow);
	    else
		continue;
	    $x .= wordWrapIndent($n, "Reviewer: ", $l) . "\n";
	}
	$x .= "---------------------------------------------------------------------------\n\n";

	$i = 0;
	$lastNumeric = true;
	foreach ($this->fieldOrder as $field) {
	    $i++;
	    if ($this->authorView[$field] <= $revViewScore)
		continue;
	    $options = isset($this->options[$field]);

	    $fval = "";
	    if ($rrow != null && isset($rrow->$field)) {
		if ($options)
		    $fval = $this->unparseOption($field, $rrow->$field);
		else
		    $fval = rtrim(str_replace("\r\n", "\n", $rrow->$field));
	    }
	    if ($fval == "")
		continue;

	    if ($options) {
		$y = defval($this->options[$field], $fval, "");
		$sn = $this->shortName[$field] . ":";
		/* "(1-" . count($this->options[$field]) . "):"; */
		if (!$lastNumeric)
		    $x .= "\n";
		if (strlen($this->shortName[$field]) > 39 + strlen($fval))
		    $x .= $sn . "\n" . wordWrapIndent($y, $fval . ". ", 39 + strlen($fval)) . "\n";
		else
		    $x .= wordWrapIndent($y, $sn . " " . $fval . ". ", 39 + strlen($fval)) . "\n";
		$lastNumeric = true;
	    } else {
		$n = "===== " . $this->shortName[$field] . " =====";
		$x .= "\n" . str_pad($n, (int) (37.5 + strlen($n) / 2), " ", STR_PAD_LEFT) . "\n";
		$x .= "\n" . preg_replace("/^==\\+==/m", "\\==+==", $fval) . "\n";
		$lastNumeric = false;
	    }
	}
	return $x;
    }

    function prettyTextComment($prow, $crow, $contact) {
	global $Conf;

	$x = "===========================================================================\n";
	$n = ($crow->forAuthors > 1 ? "Author's Response" : "Comment");
	if ($contact->canViewCommentIdentity($prow, $crow, true)) {
	    $n .= " by ";
	    if (isset($crow->reviewFirstName))
		$n .= contactText($crow->reviewFirstName, $crow->reviewLastName, $crow->reviewEmail);
	    else
		$n .= contactText($crow);
	}
	$x .= str_pad($n, (int) (37.5 + strlen($n) / 2), " ", STR_PAD_LEFT) . "\n";
	$x .= $this->_prettyPaperTitle($prow, $l);
	// $n = "Updated " . $Conf->printableTime($crow->timeModified);
	// $x .= str_pad($n, (int) (37.5 + strlen($n) / 2), " ", STR_PAD_LEFT) . "\n";
	$x .= "---------------------------------------------------------------------------\n";
	$x .= $crow->comment . "\n";
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
	foreach ($this->fieldOrder as $field) {
	    $fval = ($rrow ? $rrow->$field : "");
	    $options = isset($this->options[$field]);
	    if ((!$options && $fval == "")
		|| $this->authorView[$field] <= $revViewScore)
		continue;
	    $fshow[] = $field;
	    $fdisp[] = ($options ? self::WEB_OPTIONS : 0);
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
	foreach ($fshow as $fnum => $field) {
	    $disp = $fdisp[$fnum];
	    $fval = ($rrow ? $rrow->$field : "");
	    if (($disp & self::WEB_OPTIONS) && $fval)
		$fval = $this->unparseOption($field, $fval);

	    $n = htmlspecialchars($this->shortName[$field]);
	    if (preg_match("/\\A\\S+\\s+\\S+\\z/", $n))
		$n = preg_replace("/\\s+/", "&nbsp;", $n);

	    $c = "<span class='revfn'>" . $n;
	    if ($disp & self::WEB_OPTIONS) {
		$c .= " <a class='scorehelp' href='" . hoturl("scorehelp", "f=$field") . "'>(?)</a>";
		if (count($scoreHelps) == 0)
		    $Conf->footerScript("addScoreHelp()");
		if (!isset($scoreHelps[$field])) {
		    $scoreHelps[$field] = 1;
		    $help = "<div class='scorehelpc' id='scorehelp_$field'><strong>$n</strong> choices are:<br /><span class='rev_$field'>";
		    foreach ($this->options[$field] as $val => $text)
			$help .= "<span class='rev_num'>$val.</span>&nbsp;" . htmlspecialchars($text) . "<br />";
		    $help .= "</span></div>";
		    $Conf->footerHtml($help);
		}
	    }
	    $c .= "</span>";
	    if ($this->authorView[$field] < -1)
		$c .= "<span class='revvis'>(secret)</span>";
	    else if ($this->authorView[$field] < 0)
		$c .= "<span class='revvis'>(shown only to chairs)</span>";
	    else if ($this->authorView[$field] == 0)
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
		. "</div><div class='revv'>";
	    if ($disp & self::WEB_OPTIONS) {
		if (!$fval || !isset($this->options[$field][$fval]))
		    $x .= "<span class='rev_${field} rev_unknown'>Unknown</span>";
		else {
		    $xfval = substr($this->unparseOption($field, $fval, false, "rev_num"), 0, -7);
		    $x .= "<span class='rev_${field}'>" . $xfval . ".</span> "
			. htmlspecialchars($this->options[$field][$fval])
			. "</span>";
		}
	    } else
		$x .= htmlWrapText(htmlspecialchars($fval));
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

	foreach ($this->fieldOrder as $field) {
	    if ($this->authorView[$field] <= $revViewScore)
		continue;
	    $options = isset($this->options[$field]);
	    if (!$this->description[$field] && !$options)
		continue;

	    $x .= "<tr class='rev_$field'>\n";
	    $x .= "  <td class='caption rev_$field$extraclass'>";
	    $x .= htmlspecialchars($this->shortName[$field]) . "</td>\n";

	    $x .= "  <td class='entry rev_$field$extraclass'>";
	    if ($this->description[$field])
		$x .= "<div class='rev_description'>" . $this->description[$field] . "</div>";
	    if ($options) {
		$x .= "<div class='rev_options'>Choices are:";
		foreach ($this->options[$field] as $num => $val)
		    $x .= "<br />\n<span class='rev_num'>$num.</span> " . htmlspecialchars($val);
		$x .= "</div>";
	    }

	    $x .= "</td>\n</tr>\n";
	    $extraclass = "";
	}

	return $x;
    }

    function webFieldAbbrev($field) {
	return "<span class='hastitle' title=\"" . htmlspecialchars($this->shortName[$field]) . "\">" . htmlspecialchars($this->abbrevName[$field]) . "</span>";
    }

    function webNumericScoresHeader($prow, $contact) {
	$revViewScore = $contact->viewReviewFieldsScore($prow, null);
	$x = "";
	foreach ($this->fieldOrder as $field)
	    if ($this->authorView[$field] > $revViewScore
		&& isset($this->options[$field]))
		$x .= "<th>" . $this->webFieldAbbrev($field) . "</th>";
	return $x;
    }

    function webNumericScoresRow($rrow, $prow, $contact, &$anyScores) {
	$view = $contact->canViewReview($prow, $rrow);
	$revNullViewScore = $contact->viewReviewFieldsScore($prow, null);
	$revViewScore = $contact->viewReviewFieldsScore($prow, $rrow);
	$x = "";
	foreach ($this->fieldOrder as $field)
	    if ($this->authorView[$field] > $revNullViewScore
		&& isset($this->options[$field])) {
		if ($view && $rrow->$field
		    && $this->authorView[$field] > $revViewScore) {
		    $x .= "<td class='revscore rs_$field'>"
			. $this->unparseOption($field, $rrow->$field, false, true)
			. "</td>";
		    $anyScores = true;
		} else
		    $x .= "<td class='revscore rs_$field'></td>";
	    }
	return $x;
    }

    function webTopicArray($topicIds, $interests = null) {
	if (!$topicIds)
	    return array();
	if (!is_array($topicIds))
	    $topicIds = explode(",", $topicIds);
	if ($interests !== null && !is_array($interests))
	    $interests = explode(",", $interests);
	$out = array();
	for ($i = 0; $i < count($topicIds); $i++)
	    $out[$this->topicOrder[$topicIds[$i]]] =
		"<span class='topic" . ($interests ? $interests[$i] : 1)
		. "'>" . htmlspecialchars($this->topicName[$topicIds[$i]])
		. "</span>";
	ksort($out);
	return array_values($out);
    }

    function _showWebDisplayBody($prow, $rrows, $rrow, $reviewOrdinal, &$options) {
	global $Conf, $Me, $ratingTypes, $linkExtra;

	// Do not show rating counts if rater identity is unambiguous.
	// See also PaperSearch::_clauseTermSetRating.
	$visibleRatings = false;
	if ($rrow->numRatings > 0) {
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
		    || $Me->actChair($prow) || $options["nsubraters"] > 2
		    || $Conf->timePCViewAllReviews()
		    || strpos($rrow->allRatings, ",") !== false);
	}
	if ($Me->canRateReview($prow, $rrow)
	    && ($rrow->contactId != $Me->contactId || $visibleRatings)) {
	    $ratesep = "";
	    echo "<div class='rev_rating'>";
	    if ($visibleRatings) {
		$rates = array();
		foreach (explode(",", $rrow->allRatings) as $r)
		    $rates[$r] = defval($rates, $r, 0) + 1;
		echo "<span class='rev_rating_summary'>Ratings: ";
		$ratearr = array();
		foreach ($rates as $type => $count)
		    if (isset($ratingTypes[$type]))
			$ratearr[] = $count . " &ldquo;" . htmlspecialchars($ratingTypes[$type]) . "&rdquo;";
		echo join(", ", $ratearr), "</span>";
		$ratesep = " &nbsp;<span class='barsep'>|</span>&nbsp; ";
	    }
	    if ($rrow->contactId != $Me->contactId) {
		$ratinglink = hoturl("review", "r=$reviewOrdinal");
		if (!isset($_REQUEST["reviewId"]))
		    $ratinglink .= "&amp;allr=1";
		echo $ratesep, "<form id='ratingform_$reviewOrdinal' action='$ratinglink$linkExtra' method='post' enctype='multipart/form-data' accept-charset='UTF-8'><div class='inform'>",
		    "How helpful is this review? &nbsp;",
		    tagg_select("rating", $ratingTypes, ($rrow->myRating === null ? "n" : $rrow->myRating)),
		    " <input class='b fx7' type='submit' value='Save' />",
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

	echo "<div class='clear'></div></td><td></td></tr>
  <tr><td></td><td class='revct'><div class='inrevct'>",
	    $this->webDisplayRows($rrow, $Me->viewReviewFieldsScore($prow, $rrow)),
	    "</div></td>",
	    "<td></td></tr>\n", tagg_cbox("rev", true),
	    "</td></tr>\n</table></div>\n\n";
    }

    function show($prow, $rrows, $rrow, &$options) {
	global $Conf, $Opt, $Me, $linkExtra, $useRequest;

	if (!$options)
	    $options = array();
	$editmode = defval($options, "edit", false);

	$reviewOrdinal = unparseReviewOrdinal($rrow);
	$reviewLinkBase = hoturl("review", "p=$prow->paperId"
				 . ($rrow ? "&amp;r=$reviewOrdinal" : "")
				 . $linkExtra . "&amp;m=re&amp;");
	$reviewLink = $reviewLinkBase . "post=1";
	if ($editmode) {
	    echo "<form method='post' action=\"$reviewLink\" enctype='multipart/form-data' accept-charset='UTF-8'>",
		"<div class='aahc pboxc'>",
		"<input class='hidden' type='submit' name='default' value='' />";
	    if ($rrow)
		echo "<input type='hidden' name='version' value=\"", defval($rrow, "reviewEditVersion", 0) + 1, "\" />";
	} else
	    echo "<div class='pboxc'>";

	echo "<table class='pbox'><tr>
  <td class='pboxl'></td>
  <td class='pboxr'>";

	echo tagg_cbox("rev", false), "\t<tr><td></td><td class='revhead'>";

	// Links
	if ($rrow) {
	    echo "<div class='floatright'>";
	    if (!$editmode && $Me->canReview($prow, $rrow)) {
		$a = "<a href='" . hoturl("review", "r=$reviewOrdinal$linkExtra") . "'>";
		echo $a,
		    $Conf->cacheableImage("edit.png", "[Edit]", null, "b"),
		    "</a>&nbsp;", $a, "Edit</a><br />";
	    }
	    $a = "<a href='" . hoturl("review", "r=$reviewOrdinal&amp;text=1$linkExtra") . "'>";
	    echo $a, $Conf->cacheableImage("txt.png", "[Text]", null, "b"),
		"</a>&nbsp;", $a, "Plain text</a>",
		"</div>";
	}

	echo "<h3>";
	if ($rrow) {
	    echo "<a href='", hoturl("review", "r=$reviewOrdinal$linkExtra"), "' name='review$reviewOrdinal' class='",
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
	if ($rrow && $Me->canViewReviewerIdentity($prow, $rrow)
	    && (!$showtoken || !preg_match('/^anonymous\d*$/', $rrow->email))) {
	    echo $sep, ($rrow->reviewBlind ? "[" : ""), "by ", contactHtml($rrow), ($rrow->reviewBlind ? "]" : "");
	    $sep = $xsep;
	}
	if ($showtoken) {
	    echo $sep, "Review token ", encodeToken((int) $rrow->reviewToken);
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

	// refuse?
	if ($rrow && !$rrow->reviewSubmitted && $rrow->reviewType < REVIEW_SECONDARY) {
	    echo "\n<div class='revref'><a id='popupanchor_ref' href=\"javascript:void popup(null, 'ref', 0)\">Decline review</a> if you are unable or unwilling to complete it</div>\n";
	    // Also see $_REQUEST["refuse"] case in review.php.
	    $Conf->footerHtml("<div id='popup_ref' class='popupc'>
  <p style='margin:0 0 0.3em'>Select “Decline review” to decline this review, and thank you for keeping us informed.</p>
  <form method='post' action=\"$reviewLink\" enctype='multipart/form-data' accept-charset='UTF-8'><div class='inform'>
    <input type='hidden' name='refuse' value='refuse' />
    <textarea id='refusereviewreason' class='temptext' name='reason' rows='3' cols='40'>Optional explanation</textarea>
    <div class='popup_actions'>
      <button type='button' class='b' onclick=\"popup(null, 'ref', 1)\">Cancel</button>
      &nbsp;<input class='bb' type='submit' value='Decline review' />
    </div>
  </div></form></div>");
	    $Conf->footerScript("mktemptext('refusereviewreason','Optional explanation')");
	}

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
		echo "As a secondary reviewer, you can <a href=\"", hoturl("assign", "p=$rrow->paperId$linkExtra"), "\">delegate this review to an external reviewer</a>, but if your external reviewer declines to review the paper, you should complete the review yourself.";
	    else if ($rrow->reviewNeedsSubmit == 0)
		echo "A delegated external reviewer has submitted their review, but you can still complete your own if you'd like.";
	    else
		echo "Your delegated external reviewer has not yet submitted a review.  If they do not, you should complete the review yourself.";
	    echo "</div>\n";
	}

	// message?
	if ($rrow && $rrow->contactId != $Me->contactId
	    && !isset($prow->myReviewId) && $Me->privChair)
	    echo "<div class='hint'>You didn&rsquo;t write this review, but as an administrator you can still make changes.</div>\n";

	// download?
	echo "<div class='clear'></div></td><td></td></tr>
  <tr><td></td><td><table class='revoff'><tr>
      <td><span class='revfn'>Offline reviewing</span></td>
      <td>Upload form: &nbsp; <input type='file' name='uploadedFile' accept='text/plain' size='30' />
      &nbsp; <input class='b' type='submit' value='Go' name='uploadForm' /></td>
    </tr><tr>
      <td></td>
      <td><a href='${reviewLinkBase}downloadForm=1'>Download form</a>
      &nbsp;<span class='barsep'>|</span>&nbsp;
      <span class='hint'><strong>Tip:</strong> Use <a href='", hoturl("search"), "'>Search</a> or <a href='", hoturl("offline"), "'>Offline reviewing</a> to download or upload many forms at once.</span></td>
    </tr></table></td><td></td></tr>\n";

	// top save changes button
	echo "  <tr><td></td><td class='revcc'>";
	if ($Me->timeReview($prow, $rrow) || $Me->privChair)
	    echo "  <div class='aa' style='margin-top:0'><input class='bb' type='submit' value='Save changes' name='update' /></div>\n";

	// blind?
	if ($Conf->blindReview() == BLIND_OPTIONAL) {
	    echo "<div class='revt'><span class='revfn'>",
		tagg_checkbox_h("blind", 1, ($useRequest ? defval($_REQUEST, 'blind') : (!$rrow || $rrow->reviewBlind))),
		"&nbsp;", tagg_label("Anonymous review"),
		"</span><div class='clear'></div></div>\n",
		"<div class='revhint'>", htmlspecialchars($Opt["shortName"]), " allows either anonymous or open review.  Check this box to submit your review anonymously (the authors won&rsquo;t know who wrote the review).</div>\n",
		"<div class='g'></div>\n";
	}

	// form body
	echo $this->webFormRows($Me, $prow, $rrow, $useRequest);

	// review actions
	if ($Me->timeReview($prow, $rrow) || $Me->privChair) {
	    echo "  <div class='g'></div>",
		tagg_checkbox_h("ready", 1, ($useRequest ? defval($_REQUEST, "ready") : !$rrow || !$rrow->reviewModified || $rrow->reviewSubmitted),
				array("disabled" => ($rrow && $rrow->reviewSubmitted && !$Me->privChair))),
		"&nbsp;", tagg_label("The review is ready for others to see.");
	    if ($rrow && $rrow->reviewSubmitted && !$Me->privChair)
		echo "<div class='hint'>Only administrators can remove the review from the system at this point.</div>";

	    echo "<div class='aa'><table class='pt_buttons'>\n";
	    $buttons = array();
	    $buttons[] = "<input class='bb' type='submit' value='Save changes' name='update' />";
	    if ($rrow && $Me->privChair) {
		$buttons[] = array("<button type='button' class='b' onclick=\"popup(this, 'd', 0)\">Delete review</button>", "(admin only)");
		$Conf->footerHtml("<div id='popup_d' class='popupc'>
  <p>Be careful: This will permanently delete all information about this
  review assignment from the database and <strong>cannot be
  undone</strong>.</p>
  <form method='post' action=\"$reviewLink\" enctype='multipart/form-data' accept-charset='UTF-8'>
    <div class='popup_actions'>
      <button type='button' class='b' onclick=\"popup(null, 'd', 1)\">Cancel</button>
      &nbsp;<input class='bb' type='submit' name='delete' value='Delete review' />
    </div>
  </form></div>");
	    }

	    echo "    <tr>\n";
	    foreach ($buttons as $b) {
		$x = (is_array($b) ? $b[0] : $b);
		echo "      <td class='ptb_button'>", $x, "</td>\n";
	    }
	    echo "    </tr>\n    <tr>\n";
	    foreach ($buttons as $b) {
		$x = (is_array($b) ? $b[1] : "");
		echo "      <td class='ptb_explain'>", $x, "</td>\n";
	    }
	    echo "    </tr>\n  </table></div>";
	    if ($Me->privChair)
		echo tagg_checkbox("override"), "&nbsp;", tagg_label("Override deadlines");
	    //echo "</div>\n";
	}

	echo "</td><td></td></tr>\n", tagg_cbox("rev", true),
	    "</td></tr>\n</table></div></form>\n\n";
    }

    function numNumericScores($prow, $contact) {
	$revNullViewScore = $contact->viewReviewFieldsScore($prow, null);
	$n = 0;
	foreach ($this->fieldOrder as $field)
	    if ($this->authorView[$field] > $revNullViewScore
		&& isset($this->options[$field]))
		$n++;
	return $n;
    }

    function maxNumericScore($field) {
	if (isset($this->options[$field]))
	    return count($this->options[$field]);
	else
	    return 0;
    }


    function reviewFlowEntry($contact, $rrow, $trclass) {
	// See also CommentView::commentFlowEntry
	global $Conf;
	$barsep = " &nbsp;<span class='barsep'>|</span>&nbsp; ";
	$a = "<a href='" . hoturl("paper", "p=$rrow->paperId#review" . unparseReviewOrdinal($rrow)) . "'>";
	$t = "<tr class='$trclass'><td class='pl_activityicon'>" . $a
	    . $Conf->cacheableImage("review24.png", "[Review]", null, "dlimg")
	    . "</a></td><td class='pl_activityid'>"
	    . $a . "#$rrow->paperId</a></td><td class='pl_activitymain'><small>"
	    . $a . htmlspecialchars($rrow->shortTitle);
	if (strlen($rrow->shortTitle) != strlen($rrow->title))
	    $t .= "...";
	$t .= "</a>";
	if ($contact->canViewReviewerIdentity($rrow, $rrow, true))
	    $t .= $barsep . "<span class='hint'>review by</span> " . contactHtml($rrow->reviewFirstName, $rrow->reviewLastName, $rrow->reviewEmail);
	$t .= $barsep . "<span class='hint'>submitted</span> " . $Conf->parseableTime($rrow->reviewSubmitted, false);
	$t .= "</small><br /><a class='q'" . substr($a, 3);

	$revViewScore = $contact->viewReviewFieldsScore($rrow, $rrow);
	if ($rrow->reviewSubmitted) {
	    $t .= "Review #" . unparseReviewOrdinal($rrow) . " submitted";
	    $xbarsep = $barsep;
	} else
	    $xbarsep = "";
	foreach ($this->fieldOrder as $field) {
	    if ($this->authorView[$field] <= $revViewScore
		|| !$rrow->$field)
		continue;
	    if (isset($this->options[$field])) {
		$t .= $xbarsep . htmlspecialchars($this->shortName[$field])
		    . "&nbsp;"
		    . $this->unparseOption($field, $rrow->$field, false, true);
		$xbarsep = $barsep;
	    }
	}

	return $t . "</a></td></tr>";
    }

}
