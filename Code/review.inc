<?php 

$reviewFields = array('paperSummary' => 0,
		      'commentsToAuthor' => 0,
		      'commentsToPC' => 0,
		      'commentsToAddress' => 0,
		      'weaknessOfPaper' => 0,
		      'strengthOfPaper' => 0,
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

class ReviewForm {

    var $updatedWhen;

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

    function ReviewForm($conf) {
	$this->updatedWhen = 0;
	$this->updateFromDB($conf);
    }

    function abbreviateField($name) {
	$a = preg_split("/\s+/", ucwords($name));

	// try to filter out noninteresting words
	$b = array();
	foreach ($a as $w)
	    if ($w != "Be" && $w != "The" && $w != "A" && $w != "An" && $w != "For")
		$b[] = $w;
	if (count($b) == 0)
	    $b = $a;
	
	array_splice($b, min(3, count($a)));
	$x = "";
	foreach ($b as $w)
	    $x .= substr($w, 0, 3);
	return $x;
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
	    if ($row->sortOrder >= 0)
		$this->fieldOrder[] = $row->fieldName;
	    $this->shortName[$row->fieldName] = $row->shortName;
	    $this->fieldName[strtolower($row->shortName)] = $row->fieldName;
	    $this->abbrevName[$row->fieldName] = $this->abbreviateField($row->shortName);
	    $this->description[$row->fieldName] = $row->description;
	    $this->fieldRows[$row->fieldName] = ($row->rows >= 3 ? $row->rows : 3);
	    $this->authorView[$row->fieldName] = $row->authorView;
	}
	
	$result = $conf->qe("select fieldName, level, description from ReviewFormOptions order by fieldName, level", $while);
	if (!$result)
	    return;
	while (($row = edb_row($result)))
	    $this->options[$row[0]][$row[1]] = $row[2];

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
    }

    function validate($conf, $always = false) {
	if ($always || !$this->updatedWhen || $this->updatedWhen <= $conf->settings['revform_update'])
	    $this->updateFromDB($conf);
    }

    function webFormRows($rrow, $useRequest = false) {
	global $ReviewFormError;
	$x = '';
	foreach ($this->fieldOrder as $field) {
	    $fval = "";
	    if ($useRequest && isset($_REQUEST[$field]))
		$fval = $_REQUEST[$field];
	    else if ($rrow != null)
		$fval = $rrow->$field;
	    
	    $x .= "<tr class='rev_$field'>\n";
	    
	    $x .= "  <td class='caption";
	    if (isset($ReviewFormError[$field]))
		$x .= " error";
	    if (isset($this->options[$field]))
		$x .= " textarea";
	    $x .= "'>" . htmlspecialchars($this->shortName[$field]);
	    if ($this->authorView[$field] < 0)
		$x .= "<br /><span class='hint'>Chairs only</span>";
	    else if ($this->authorView[$field] == 0)
		$x .= "<br /><span class='hint'>PC only</span>";
	    $x .= "</td>\n";
	    
	    $x .= "  <td class='entry'>";
	    if (isset($this->options[$field])) {
		$x .= "<select name='$field' onchange='highlightUpdate()'>\n";
		if ($fval == "" || $fval <= 0 || $fval > count($this->options[$field]))
		    $x .= "    <option value='0' selected='selected'>(Unknown)</option>\n";
		foreach ($this->options[$field] as $num => $what) {
		    $x .= "    <option value='$num'";
		    if ($num == $fval)
			$x .= " selected='selected'";
		    $x .= "><b>$num.</b> " . htmlspecialchars($what) . "</option>\n";
		}
		$x .= "</select>";
	    } else {
		$x .= "<textarea name='$field' onchange='highlightUpdate()' cols='80' rows='" . $this->fieldRows[$field] . "'>" . htmlspecialchars($fval) . "</textarea>";
	    }
	    $x .= "</td>\n";
	    if ($this->description[$field])
		$x .= "  <td class='hint'>" . $this->description[$field] . "</td>\n";
	    $x .= "</tr>\n";
	}
	return $x;
    }

    function tfError(&$tf, $text, $field = null) {
	$e = htmlspecialchars($tf['filename']) . ":";
	if (is_int($field))
	    $e .= $field;
	else if ($field === null || !isset($tf['fieldLineno'][$field]))
	    $e .= $tf['firstLineno'];
	else
	    $e .= $tf['fieldLineno'][$field];
	if (defval($tf['paperId']))
	    $e .= " (paper #" . $tf['paperId'] . ")";
	$tf['err'][] = "<span class='lineno'>" . $e . ":</span> " . $text;
    }

    function checkRequestFields($req, $rrow, &$tf = null, $conf = null) {
	global $ReviewFormError, $Conf;
	$conf = ($conf ? $conf : $Conf);
	$submit = isset($req["submit"]);
	foreach ($this->fieldOrder as $field) {
	    if (!isset($req[$field]) && !$submit)
		continue;
	    $fval = defval($req[$field], ($rrow ? $rrow->$field : ""));
	    if (!isset($req[$field]))
		$missing[] = $this->shortName[$field];
	    if (isset($this->options[$field])) {
		$fval = trim($fval);
		$max = count($this->options[$field]);
		if ($fval == "" || $fval == "0") {
		    if ($submit) {
			$provide[] = $this->shortName[$field];
			$ReviewFormError[$field] = 1;
		    }
		} else if (($xval = cvtint($fval)) > 0 && $xval <= $max)
		    $fval = $xval;
		else if (preg_match("/^([0-9]+)\\./", $fval, $matches)
			 && $matches[1] > 0 && $matches[1] <= $max)
		    $fval = $matches[1];
		else {
		    $outofrange[] = $field;
		    $ReviewFormError[$field] = 1;
		}
	    }
	}
	if (isset($missing) && $tf)
	    self::tfError($tf, "Warning: " . textArrayJoin($missing) . " " . pluralx($missing, "field") . " missing from form.  Preserving any existing values.");
	if (!isset($provide) && !isset($outofrange))
	    return 1;
	if (isset($provide)) {
	    if ($tf)
		self::tfError($tf, "You must set " . textArrayJoin($provide) . " before you can submit your review.");
	    else
		$conf->errorMsg("You must set " . textArrayJoin($provide) . " before you can submit your review.  Please fix this and submit again.");
	}
	if (isset($outofrange)) {
	    if ($tf)
		foreach ($outofrange as $field)
		    self::tfError($tf, "Bad value for field \"" . htmlentities($this->shortName[$field]) . "\"", $field);
	    else {
		foreach ($outofrange as $field)
		    $oor2[] = htmlspecialchars($this->shortName[$field]);
		$conf->errorMsg("Bad values for " . textArrayJoin($oor2) . ".  Please fix this and submit again.");
	    }
	}
	return 0;
    }

    function saveRequest($req, $rrow, $prow, $contactId, $conf = null) {
	global $Conf, $ConfSiteBase;
	$conf = ($conf ? $conf : $Conf);
	$Me = $_SESSION['Me'];
	$submit = isset($req["submit"]);
	$while = "while storing review";

	if (!$Me->timeReview($prow, $conf)
	    && (!isset($req['override']) || !$Me->amAssistant()))
	    return $conf->errorMsg("The <a href='${ConfSiteBase}deadlines.php'>deadline</a> for entering this review has passed." . ($Me->amAssistant() ? "  Select the \"Override deadlines\" checkbox and try again if you really want to override the deadline." : ""));
	
	$q = "";
	foreach ($this->fieldOrder as $field)
	    if (isset($req[$field])) {
		$fval = $req[$field];
		if (!isset($this->options[$field])) {
		    $fval = preg_replace("/\\A\\s*(.*?)\\s*\\Z/", '$1', $fval);
		    if ($fval != "")
			$fval .= "\n";
		    $req[$field] = $fval;
		}
		$q .= "$field='" . sqlq($fval) . "', ";
	    }

	// potentially assign review ordinal (requires table locking since
	// mySQL is stupid)
	$locked = false;
	if ($submit && (!$rrow || !$rrow->reviewSubmitted)) {
	    $q .= "reviewSubmitted=" . time() . ", reviewNeedsSubmit=0, ";
	    if (!$rrow || !$rrow->reviewOrdinal) {
		$result = $conf->qe("lock tables PaperReview write", $while);
		if (!$result)
		    return $result;
		$locked = true;
		$result = $conf->qe("select coalesce(max(reviewOrdinal), 0) from PaperReview where paperId=$prow->paperId group by paperId", $while);
		if ($result) {
		    $crow = edb_row($result);
		    $q .= "reviewOrdinal=coalesce(reviewOrdinal, " . ($crow[0] + 1) . "), ";
		}
	    }
	}

	// blind?
	if ($conf->blindReview() > 1
	    || ($conf->blindReview() == 1 && defval($req['blind'])))
	    $q .= "reviewBlind=1, ";
	else
	    $q .= "reviewBlind=0, ";

	// actually affect database
	if ($rrow)
	    $result = $conf->qe("update PaperReview set $q reviewModified=" . time() . " where reviewId=$rrow->reviewId", $while);
	else
	    $result = $conf->qe("insert into PaperReview set paperId=$prow->paperId, contactId=$contactId, reviewType=" . REVIEW_PC . ", requestedBy=$contactId, requestedOn=current_timestamp, $q reviewModified=" . time(), $while);

	// unlock tables even if problem
	if ($locked)
	    $conf->qe("unlock tables", $while);
	if (!$result)
	    return $result;

	// look up review ID
	if ($rrow)
	    $req['reviewId'] = $reviewId = $rrow->reviewId;
	else {
	    $result2 = $conf->qe("select last_insert_id()", $while);
	    if (!$result2)
		return $result2;
	    $crow = edb_row($result2);
	    $req['reviewId'] = $reviewId = $crow[0];
	}
	
	// log updates
	$conf->log("Review $reviewId " . ($rrow ? "created" : "saved"), $Me, $prow->paperId);
	if ($submit && (!$rrow || !$rrow->reviewSubmitted))
	    $conf->log("Review $reviewId submitted", $Me, $prow->paperId);

	// potentially email chair and authors
	if ($submit && ($conf->timeEmailChairAboutReview() || $conf->timeEmailAuthorsAboutReview())) {
	    
	    // fetch review ordinal
	    if (!$rrow || !$rrow->reviewSubmitted) {
		$result = $conf->q("select reviewOrdinal from PaperReview where reviewId=$reviewId");
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
	    
	    $m = wordwrap("Review #$prow->paperId$reviewnum for paper #$prow->paperId, \"$prow->title\", " . ($rrow && $rrow->reviewSubmitted ? "has been updated.  " : "has been submitted.  ") . "The review is available at the following address:\n\n");
	    $m .= "  $conf->paperSite/review.php?paperId=$prow->paperId\n\n";
	    $m .= wordwrap("Contact the site administrator, $Conf->contactName ($Conf->contactEmail), with any questions or concerns.

- $Conf->shortName Conference Submissions\n");

	    $s = "[$conf->shortName] Review #$prow->paperId$reviewnum " . ($rrow && $rrow->reviewSubmitted ? "updated" : "submitted");

	    // actually mail
	    if ($conf->timeEmailChairAboutReview())
		if ($conf->allowEmailTo($conf->contactEmail))
		    mail($conf->contactEmail, $s, $m, "From: $conf->emailFrom");
		else
		    $conf->infoMsg("<pre>" . htmlspecialchars("$s\n\n$m") . "</pre>");

	    if ($conf->timeEmailAuthorsAboutReview())
		$conf->emailContactAuthors($prow, $s, $m);
	}
	
	return $result;
    }


    function textFormHeader($conf, $multiple = false, $editable = true) {
	global $Conf;
	$conf = ($conf ? $conf : $Conf);
	
	$x = "==+== $conf->shortName Paper Review";
	if ($editable) {
	    $x .= " Form" . ($multiple ? "s" : "") . "
==-== Fill out lettered sections A through " . chr(65 + count($this->fieldOrder) - 1) . ".\n";
	    if (!$multiple)
		$x .= "==-== To review multiple papers, duplicate the form body from \"Begin Review\"
==-== through \"End Review\".  Make sure to change the paper number.\n";
	    $x .= "==-== DO NOT CHANGE \"==+==\" LINES EXCEPT AS NOTED!
==-== When you are done, upload the review form at this address:
==-== $conf->paperSite/offline.php
==-== That site also has further guidance on the review form.\n\n";
	} else
	    $x .= ($multiple ? "s\n\n" : "\n\n");
	return $x;
    }
    
    function textForm($prow, $rrow, $contact, $conf, $req = null, $neverMyReview = false) {
	global $Conf;
	$conf = ($conf ? $conf : $Conf);

	$rrow_contactId = 0;
	if (isset($rrow) && isset($rrow->reviewContactId))
	    $rrow_contactId = $rrow->reviewContactId;
	else if (isset($rrow) && isset($rrow->contactId))
	    $rrow_contactId = $rrow->contactId;
	$myReview = !$neverMyReview
	    && (!$rrow || $rrow_contactId == 0 || $rrow_contactId == $contact->contactId);
	$viewAllFields = $contact->canViewAllReviewFields($prow, $conf);

	$x = "==+== =========================================================================\n";
	//$x .= "$prow->paperId:$myReview:$viewAllFields:$rrow->contactId:$rrow->reviewContactId;;$prow->conflictType;;$prow->reviewType\n";

	$x .= "==+== Begin Review";
	if ($req && isset($req['reviewOrdinal']))
	    $x .= " #" . $prow->paperId . unparseReviewOrdinal($req['reviewOrdinal']);
	else if ($rrow && isset($rrow->reviewOrdinal))
	    $x .= " #" . $prow->paperId . unparseReviewOrdinal($rrow->reviewOrdinal);
	$x .= "\n";
	if (!$myReview && $prow)
	    $x .= wordWrapIndent($prow->title, "==-== Paper: ", "==-==        ") . "\n";
	if ($contact->canViewReviewerIdentity($prow, $rrow, $conf)) {
	    if ($rrow && isset($rrow->reviewFirstName))
		$x .= "==+== Review by " . contactText($rrow->reviewFirstName, $rrow->reviewLastName, $rrow->reviewEmail) . "\n";
	    else if ($myReview)
		$x .= "==+== Review by " . contactText($contact) . "\n";
	    else if ($rrow && isset($rrow->lastName))
		$x .= "==+== Review by " . contactText($rrow) . "\n";
	}
	if ($rrow && $rrow->reviewModified)
	    $x .= "==-== Modified " . $conf->printableTime($rrow->reviewModified) . "\n";

	if ($myReview) {
	    $x .= "\n==+== Paper #";
	    if ($prow)
		$x .= "$prow->paperId\n";
	    else
		$x .= "000000\n==-== Replace '000000' with the actual paper number.\n";
	    if ($prow)
		$x .= wordWrapIndent($prow->title, "==-== Title: ", "==-==        ") . "\n";
	    $x .= "\n==-== Uploading this form will submit the review, making it available to
==-== authors and the PC, unless you delete the following line.
==+== Submit Review\n";
	    if ($conf->blindReview() == 1) {
		$blind = "Anonymous";
		if ($rrow && !$rrow->reviewBlind)
		    $blind = "Open";
		$x .= "\n==-== " . $conf->shortName . " allows either anonymous or open review.
==-== To submit a non-anonymous review, change 'Anonymous' to 'Open' here.
==+== $blind Review\n";
	    }
	}

	$i = 0;
	$numericMessage = 0;
	foreach ($this->fieldOrder as $field) {
	    $i++;
	    if (!$viewAllFields && $this->authorView[$field] <= 0)
		continue;

	    $fval = "";
	    if ($req && isset($req[$field]))
		$fval = rtrim($req[$field]);
	    else if ($rrow != null && isset($rrow->$field))
		$fval = rtrim(str_replace("\r\n", "\n", $rrow->$field));
	    if (isset($this->options[$field]) && $fval == "0")
		$fval = "";
	    else if (isset($this->options[$field]) && isset($this->options[$field][$fval]))
		$fval = "$fval. " . $this->options[$field][$fval];
	    if (!$myReview && $fval == "")
		continue;

	    $x .= "\n==+== " . chr(64 + $i) . ". " . $this->shortName[$field];
	    if ($this->authorView[$field] < 0)
		$x .= " (Chairs only)";
	    else if ($this->authorView[$field] == 0)
		$x .= " (PC only)";
	    $x .= "\n";
	    if (isset($this->options[$field]) && $myReview) {
		foreach ($this->options[$field] as $num => $value) {
		    $y = ($num == 1 ? "==-== Choices: 1. " : "==-==          $num. ");
		    $x .= wordWrapIndent($value, $y, strlen($y)) . "\n";
		}
		if ($numericMessage++ == 0)
		    $x .= "==-== Enter the number of your choice in the space below.\n";
	    }
	    $x .= "\n" . preg_replace("/^==\\+==/m", "\\==+==", $fval) . "\n";
	}
	return $x . "\n==+== End Review\n";
    }

    function garbageMessage(&$tf, $lineno, &$garbage) {
	if (isset($garbage))
	    self::tfError($tf, "Review form appears to begin with garbage; ignoring it.", $lineno);
	unset($garbage);
    }

    function beginTextForm($filename, $printFilename) {
	if (($contents = file_get_contents($filename)) === false)
	    return null;
	$contents = preg_replace("/\\r\\n/", "\n", $contents);
	$contents = strtr($contents, "\r", "\n");
	return array('text' => $contents, 'filename' => $printFilename,
		     'lineno' => 0, 'err' => array(), 'confirm' => array());
    }
    
    function parseTextForm(&$tf, $conf = null) {
	global $Conf;
	$conf = ($conf ? $conf : $Conf);

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
		if (preg_match("/Paper Review Form/", $line)
		    && !preg_match("/$conf->shortName Paper Review Form/", $line)) {
		    preg_match('/\A==\+==\s+(.*?)\s+Paper Review Form/', $line, $m);
		    $this->garbageMessage($tf, $lineno, $garbage);
		    self::tfError($tf, "This review form appears to be for the wrong conference!  (I was expecting \"" . htmlentities($conf->shortName) . " Paper Review Form\", but found \"" . htmlentities($m[1]) . " Paper Review Form\".)", $lineno);
		    return null;
		} else if (preg_match('/^==\+== Paper #?(\d+)/i', $line, $match)) {
		    if ($nfields > 0)
			break;
		    else {
			$req['paperId'] = $tf['paperId'] = $match[1];
			$req['blind'] = 1;
			$tf['firstLineno'] = $lineno;
		    }
		} else if (preg_match('/^==\+== Submit Review\s*$/i', $line)) {
		    $req['submit'] = true;
		} else if (preg_match('/^==\+== Open Review\s*$/i', $line)) {
		    $req['blind'] = 0;
		} else if (preg_match('/^==\+== [A-Z]\.\s*(.*?)\s*$/', $line, $match)) {
		    $fname = str_replace(" (PC only)", "", $match[1]);
		    $fn =& $this->fieldName[strtolower($fname)];
		    if (isset($fn)) {
			$field = $fn;
			$tf['fieldLineno'][$fn] = $lineno;
			$nfields++;
		    } else {
			$this->garbageMessage($tf, $lineno, $garbage);
			self::tfError($tf, "Review field \"" . htmlentities($fname) . "\" is not used for $conf->shortName reviews.  Ignoring this section.", $lineno);
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
		    $req[$field] = defval($req[$field], "") . $line;
		$mode = 2;
	    }

	    $text = substr($text, strlen($line));
	}

	if ($nfields == 0 && $anyDirectives)
	    self::tfError($tf, "That didn't appear to be a review form; I was not able to extract any information from it.  Please check its formatting and try again.", $lineno);

	$tf['text'] = $text;
	$tf['lineno'] = $lineno - 1;

	if ($nfields == 0 && $text) // try again
	    return $this->parseTextForm($tf, $conf);
	else if ($nfields == 0)
	    return null;
	else
	    return $req;
    }
    
    function textFormMessages(&$tf, $conf = null) {
	global $Conf;
	$conf = ($conf ? $conf : $Conf);

	if (count($tf['err']) > 0)
	    $conf->errorMsg("There were some errors while parsing the uploaded reviews file. <div class='parseerr'><p>" . join("</p>\n<p>", $tf['err']) . "</p></div>");

	if (count($tf['confirm']) > 0)
	    $conf->confirmMsg("<div class='parseerr'><p>" . join("</p>\n<p>", $tf['confirm']) . "</p></div>");
    }

    function webDisplayRows($rrow, $viewAllFields) {
	global $ReviewFormError;
	$x = '';

	$showFields = array();
	$lastFieldOptions = 0;
	foreach ($this->fieldOrder as $field) {
	    $fval = ($rrow ? $rrow->$field : "");
	    $options = isset($this->options[$field]);
	    if ((!$options && $fval == "")
		|| ($this->authorView[$field] <= 0 && !$viewAllFields))
		continue;
	    if ($lastFieldOptions && !$options)
		$showFields[$lastFieldOptions] = 0;
	    if ($lastFieldOptions && $options) {
		$showFields[$field] = 2;
		$lastFieldOptions = 0;
	    } else if ($options) {
		$showFields[$field] = 1;
		$lastFieldOptions = $field;
	    } else {
		$showFields[$field] = 0;
		$lastFieldOptions = 0;
	    }
	}
	
	foreach ($showFields as $field => $display) {
	    $fval = ($rrow ? $rrow->$field : "");
	    $options = isset($this->options[$field]);

	    if ($display <= 1)
		$x .= "\n<tr class='rev_$field'>\n";

	    $x .= "  <td class='caption rev_$field'>";
	    $n = htmlspecialchars($this->shortName[$field]);
	    if (preg_match("/\\A\\S+\\s+\\S+\\Z/", $n))
		$n = preg_replace("/\\s+/", "&nbsp;", $n);
	    $x .= "$n";
	    if ($this->authorView[$field] < 0)
		$x .= "<br /><span class='hint'>Chairs only</span>";
	    else if ($this->authorView[$field] == 0)
		$x .= "<br /><span class='hint'>PC only</span>";
	    $x .= "</td>\n";
	    
	    $x .= "  <td class='entry rev_$field";
	    if ($display >= 1)
		$x .= ($display == 1 ? " leftentry'>" : " rightentry'>");
	    else
		$x .= "' colspan='3'>";
	    if ($options) {
		if ($fval == "" || $fval <= 0 || $fval > count($this->options[$field]))
		    $x .= "<span class='rev_${field}_0 rev_unknown'>Unknown</span>";
		else
		    $x .= "<span class='rev_${field}_$fval'><span class='rev_num rev_num_$fval'>$fval.</span> " . htmlspecialchars($this->options[$field][$fval]) . "</span>";
	    } else
		$x .= htmlWrapText(htmlspecialchars($fval));
	    $x .= "</td>\n";

	    if ($display != 1)
		$x .= "</tr>\n";
	}

	return $x;
    }
    
    function webGuidanceRows($viewAllFields) {
	global $ReviewFormError;
	$x = '';
	$needRow = 1;
	
	foreach ($this->fieldOrder as $field) {
	    if ($this->authorView[$field] <= 0 && !$viewAllFields)
		continue;
	    $options = isset($this->options[$field]);
	    if (!$this->description[$field] && !$options)
		continue;
	    
	    $x .= "<tr class='rev_$field'>\n";
	    $x .= "  <td class='caption rev_$field'>";
	    $x .= htmlspecialchars($this->shortName[$field]) . "</td>\n";
	    
	    $x .= "  <td class='entry rev_$field'>";
	    if ($this->description[$field])
		$x .= "<div class='rev_description'>" . $this->description[$field] . "</div>";
	    if ($options) {
		$x .= "<div class='rev_options'>Choices are:";
		foreach ($this->options[$field] as $num => $val)
		    $x .= "<br/>\n$num. " . htmlspecialchars($val);
		$x .= "</div>";
	    }
	    
	    $x .= "</td>\n</tr>\n";
	}

	return $x;
    }

    function webFieldAbbrev($field) {
	return "<acronym title=\"" . htmlspecialchars($this->shortName[$field]) . "\">" . htmlspecialchars($this->abbrevName[$field]) . "</acronym>";
    }
    
    function webNumericScoresHeader($prow, $contact, $conf) {
	$viewAllFields = $contact->canViewAllReviewFields($prow, $conf);
	$x = "";
	foreach ($this->fieldOrder as $field)
	    if (($this->authorView[$field] > 0 || $viewAllFields)
		&& isset($this->options[$field]))
		$x .= "<th>" . $this->webFieldAbbrev($field) . "</th>";
	return $x;
    }

    function webNumericScoresRow($rrow, $prow, $contact, $conf, &$anyScores) {
	$view = $contact->canViewReview($prow, $rrow, $conf);
	$viewAllFields = $contact->canViewAllReviewFields($prow, $conf);
	$x = "";
	foreach ($this->fieldOrder as $field)
	    if (($this->authorView[$field] > 0 || $viewAllFields)
		&& isset($this->options[$field])) {
		if ($view && $rrow->$field) {
		    $x .= "<td class='revscore rs_$field'>" . $rrow->$field . "</td>";
		    $anyScores = true;
		} else
		    $x .= "<td class='revscore rs_$field'></td>";
	    }
	return $x;
    }
    
    function webNumericScoresTable($rrows, $prow, $contact, $conf, $links) {
	$viewAllFields = $contact->canViewAllReviewFields($prow, $conf);
	$x = "<table class='reviewscores'>\n<tr class='rs_heading'><td></td><td></td>";
	foreach ($this->fieldOrder as $field) {
	    if (($this->authorView[$field] > 0 || $viewAllFields)
		&& isset($this->options[$field]))
		$x .= "<td>" . htmlspecialchars($this->shortName[$field]) . "</td>";
	}
	$x .= "</tr>\n";
	$reviewnum = 65;
	foreach ($rrows as $rrow) {
	    $x .= "<tr class='rs_rev'>";
	    $rtxt = chr($reviewnum++);
	    $x .= "<td class='rs_num'>"
		. ($links ? "<a href='#review$rtxt'>" : "")
		. "Review $rtxt" . ($links ? "</a>" : "") . "</td>";
	    if ($contact->canViewReviewerIdentity($prow, $rrow, $conf))
		$x .= "<td class='rs_id'>" . contactHtml($rrow) . "</td>";
	    else
		$x .= "<td></td>";
	    foreach ($this->fieldOrder as $field) {
		if (($this->authorView[$field] > 0 || $viewAllFields)
		    && isset($this->options[$field]))
		    $x .= "<td class='rs_$field'>" . $rrow->$field . "</td>";
	    }
	    $x .= "</tr>\n";
	}
	return $x . "</table>";
    }

    function webTopicArray($topicIds, $interests = null) {
	if (!$topicIds)
	    return array();
	if (!is_array($topicIds))
	    $topicIds = explode(",", $topicIds);
	if ($interests && !is_array($interests))
	    $interests = explode(",", $interests);
	$out = array();
	for ($i = 0; $i < count($topicIds); $i++)
	    $out[$this->topicOrder[$topicIds[$i]]] =
		"<span class='topic" . (isset($interests) ? $interests[$i] : 1)
		. "'>" . htmlspecialchars($this->topicName[$topicIds[$i]])
		. "</span>";
	ksort($out);
	return array_values($out);
    }

    function numNumericScores($prow, $contact, $conf) {
	$viewAllFields = $contact->canViewAllReviewFields($prow, $conf);
	$n = 0;
	foreach ($this->fieldOrder as $field)
	    if (($this->authorView[$field] > 0 || $viewAllFields)
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
    
}


function reviewForm($conf = null) {
    global $Conf;
    $conf = ($conf ? $conf : $Conf);
    if (!isset($_SESSION["reviewForm"]))
	$_SESSION["reviewForm"] = new ReviewForm($conf);
    else
	$_SESSION["reviewForm"]->validate($conf);
    return $_SESSION["reviewForm"];
}
