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
    var $description;
    var $authorView;
    var $options;
    var $topicName;
    var $topicOrder;

    function ReviewForm($conf) {
	$this->updatedWhen = 0;
	$this->updateFromDB($conf);
    }

    function updateFromDB($conf) {
	$this->fieldOrder = array();
	$this->shortName = array();
	$this->fieldName = array();
	$this->fieldRows = array();
	$this->description = array();
	$this->authorView = array();
	$this->options = array();
	$this->topicName = array();
	$this->topicOrder = array();

	$while = "while updating review form information";
	
	$result = $conf->qe("select fieldName, shortName, description, sortOrder, rows, authorView from ReviewFormField order by sortOrder, shortName", $while);
	if (DB::isError($result))
	    return;
	while (($row = $result->fetchRow(DB_FETCHMODE_OBJECT))) {
	    if ($row->sortOrder >= 0)
		$this->fieldOrder[] = $row->fieldName;
	    $this->shortName[$row->fieldName] = $row->shortName;
	    $this->fieldName[strtolower($row->shortName)] = $row->fieldName;
	    $this->description[$row->fieldName] = $row->description;
	    $this->fieldRows[$row->fieldName] = ($row->rows >= 3 ? $row->rows : 3);
	    $this->authorView[$row->fieldName] = $row->authorView;
	}
	
	$result = $conf->qe("select fieldName, level, description from ReviewFormOptions order by fieldName, level", $while);
	if (DB::isError($result))
	    return;
	while (($row = $result->fetchRow()))
	    $this->options[$row[0]][$row[1]] = $row[2];

	$result = $conf->qe("select topicId, topicName from TopicArea order by topicName", $while);
	if (DB::isError($result))
	    return;
	$i = 0;
	while (($row = $result->fetchRow())) {
	    $this->topicName[$row[0]] = $row[1];
	    $this->topicOrder[$row[0]] = $i++;
	}
	
	$this->updatedWhen = time();
    }

    function validate($conf, $always = false) {
	if ($always || !$this->updatedWhen || $this->updatedWhen <= $conf->startTime['reviewFormUpdate'])
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
	    if (!$this->authorView[$field])
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

    function checkRequestFields($req, $rrow, &$tf = null, $conf = null) {
	global $ReviewFormError, $Conf;
	$conf = ($conf ? $conf : $Conf);
	$submit = isset($req["submit"]);
	foreach ($this->fieldOrder as $field) {
	    if (!isset($req[$field]) && !$submit)
		continue;
	    $fval = (isset($req[$field]) ? $req[$field] : ($rrow ? $rrow->$field : ""));
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
	if (!isset($provide) && !isset($outofrange))
	    return 1;
	if (isset($provide))
	    $conf->errorMsg("You must choose values for " . textArrayJoin($provide) . " before submitting your review.  Please fix this and submit again.");
	if (isset($outofrange)) {
	    if ($tf)
		foreach ($outofrange as $field)
		    $tf['err'][] = $tf['fieldLineno'][$field] . ": Bad value for field \"" . htmlentities($this->shortName[$field]) . "\".";
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

	if ($submit && (!$rrow || !$rrow->reviewSubmitted))
	    $q .= "reviewSubmitted=" . time() . ", ";

	// blind?
	if ($conf->blindReview() > 1
	    || ($conf->blindReview() == 1 && defval($req['blind'])))
	    $q .= "blind=1, ";
	else
	    $q .= "blind=0, ";
	
	if ($rrow)
	    $result = $conf->qe("update PaperReview set $q reviewModified=" . time() . " where reviewId=$rrow->reviewId", "while storing review");
	else
	    $result = $conf->qe("insert into PaperReview set paperId=$prow->paperId, contactId=$contactId, reviewType=" . REVIEW_PC . ", requestedBy=$contactId, requestedOn=current_timestamp, $q reviewModified=" . time(), " while storing review");
	if (DB::isError($result))
	    return $result;

	// log updates
	$conf->log("Review " . ($rrow ? "$rrow->reviewId " : "") . "for $prow->paperId saved", $Me);
	if ($submit && (!$rrow || !$rrow->reviewSubmitted))
	    $conf->log("Review " . ($rrow ? "$rrow->reviewId " : "") . "for $prow->paperId submitted", $Me);
	
	// potentially email chair and authors
	if ($submit && ($conf->timeEmailChairAboutReview() || $conf->timeEmailAuthorsAboutReview())) {
	    
	    // fetch review ordinal
	    if (!$rrow || !$rrow->reviewSubmitted) {
		$result = $conf->q("select PaperReview.reviewId, count(PRS.reviewId) as reviewOrdinal
		from PaperReview
		left join PaperReview as PRS on (PaperReview.paperId=PRS.paperId and PRS.reviewSubmitted>0 and PRS.reviewSubmitted<PaperReview.reviewSubmitted)
		where " . ($rrow ? "PaperReview.reviewId=$rrow->reviewId" : "PaperReview.paperId=$prow->paperId and PaperReview.contactId=$contactId") . "
		group by PaperReview.reviewId");
		if (!DB::isError($result) && $result->numRows() == 1) {
		    $r = $result->fetchRow();
		    $req['reviewId'] = $r[0];
		    $req['reviewOrdinal'] = $r[1];
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
	    $x .= " Form
==-== Fill out lettered sections A through " . chr(65 + count($this->fieldOrder) - 1) . ".\n";
	    if (!$multiple)
		$x .= "==-== To review multiple papers, duplicate the form body from \"Begin Review\"
==-== through \"End Review\".  Make sure to change the paper number.\n";
	    $x .= "==-== DO NOT CHANGE \"==+==\" LINES EXCEPT AS NOTED!
==-== When you are done, upload the review form at this address:
==-== $conf->paperSite/uploadreview.php
==-== That site also has further guidance on the review form.\n\n";
	} else
	    $x .= "\n\n";
	return $x;
    }
    
    function textForm($prow, $rrow, $conf, $req = null, $reviewer = true) {
	global $Conf;
	$conf = ($conf ? $conf : $Conf);

	$x = "==+== =========================================================================\n";

	if ($reviewer || !$prow) {
	    $x .= "==+== Begin Review

==+== Paper #";
	    if ($prow)
		$x .= "$prow->paperId\n";
	    else
		$x .= "000000\n==-== Replace '000000' with the actual paper number.\n";
	    if ($prow)
		$x .= "==-== Title: $prow->title\n";
	    $x .= "\n==-== Uploading this form will submit the review, making it available to
==-== authors and the PC, unless you delete the following line.
==+== Submit Review\n";
	    if ($conf->blindReview() == 1)
		$x .= "\n==-== " . $conf->shortName . " allows either anonymous or open review.
==-== To submit a non-anonymous review, change 'Anonymous' to 'Open' here.
==+== " . ($rrow && !$rrow->blind ? "Open" : "Anonymous") . " Review\n";
	} else {
	    $x .= "==+== Begin Review #$prow->paperId";
	    if ($req && isset($req['reviewOrdinal']))
		$x .= unparseReviewOrdinal($req['reviewOrdinal']);
	    else if ($rrow && isset($rrow->reviewOrdinal))
		$x .= unparseReviewOrdinal($rrow->reviewOrdinal);
	    $x .= "\n";
	    if ($conf->blindReview() > 1
		|| ($conf->blindReview() == 1 && !$rrow->blind))
		$x .= "==-== by " . contactText($rrow) . "\n";
	    if ($rrow->reviewModified)
		$x .= "==-== Modified " . $conf->printableTime($rrow->reviewModified) . "\n";
	}

	$i = 0;
	$numericMessage = 0;
	foreach ($this->fieldOrder as $field) {
	    if (!$reviewer && !$this->authorView[$field])
		continue;
	    $x .= "\n==+== " . chr(65 + $i++) . ". " . $this->shortName[$field];
	    if (!$this->authorView[$field])
		$x .= " (PC only)";
	    $x .= "\n";
	    if (isset($this->options[$field]) && $reviewer) {
		foreach ($this->options[$field] as $num => $value)
		    if ($num == 1)
			$x .= "==-== Choices: $num. $value\n";
		    else
			$x .= "==-==          $num. $value\n";
		if ($numericMessage++ == 0)
		    $x .= "==-== Enter the number of your choice here.\n";
	    }
	    $x .= "\n";
	    
	    $fval = "";
	    if ($req && isset($req[$field]))
		$fval = rtrim($req[$field]);
	    else if ($rrow != null)
		$fval = rtrim($rrow->$field);
	    if (isset($this->options[$field]) && $fval == "0")
		$fval = "";
	    else if (isset($this->options[$field]) && isset($this->options[$field][$fval]))
		$fval = "$fval. " . $this->options[$field][$fval];
	    $x .= preg_replace("/^==\\+==/m", "\\==+==", $fval);

	    $x .= "\n";
	}
	return $x . "\n==+== End Review\n";
    }

    function garbageMessage(&$tf, $lineno, &$garbage) {
	if (isset($garbage))
	    $tf['err'][] = "$lineno: Review form appears to begin with garbage; ignoring it.";
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

	$mode = 0;
	$nfields = 0;
	$field = 0;
	$anyDirectives = 0;
	
	while ($text != "") {
	    $pos = strpos($text, "\n");
	    $line = ($pos === FALSE ? $text : substr($text, 0, $pos + 1));
	    $lineno++;

	    if (substr($line, 0, 6) == "==+== ") {
		$anyDirectives++;
		if (preg_match("/Paper Form/", $line)
		    && !preg_match("/$conf->shortName Paper Form/", $line)) {
		    $this->garbageMessage($tf, $lineno, $garbage);
		    $tf['err'][] = "$lineno: This review form appears to be for the wrong conference!";
		    return null;
		} else if (preg_match("/^==\\+== Paper \#?(\\d+)/i", $line, $match)) {
		    if ($nfields > 0)
			break;
		    else {
			$req['paperId'] = $match[1];
			$req['blind'] = 1;
			$tf['firstLineno'] = $lineno;
		    }
		} else if (preg_match("/^==\\+== Submit Review\\s*\$/i", $line)) {
		    $req["submit"] = true;
		} else if (preg_match("/^==\\+== Open Review\\s*\$/i", $line)) {
		    $req['blind'] = 0;
		} else if (preg_match("/^==\\+== [A-Z]\\.\\s*(.*?)\\s*\$/", $line, $match)) {
		    $fname = str_replace(" (PC only)", "", $match[1]);
		    $fn =& $this->fieldName[strtolower($fname)];
		    if (isset($fn)) {
			$field = $fn;
			$tf['fieldLineno'][$fn] = $lineno;
			$nfields++;
		    } else {
			$this->garbageMessage($tf, $lineno, $garbage);
			$tf['err'][] = "$lineno: Review field \"" . htmlentities($fname) . "\" is not used for $conf->shortName reviews.  Ignoring this section.";
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
		    $req[$field] = (isset($req[$field]) ? $req[$field] . $line : $line);
		$mode = 2;
	    }

	    $text = substr($text, strlen($line));
	}

	if ($nfields == 0 && $anyDirectives)
	    $tf['err'][] = "$lineno: That didn't appear to be a review form; I was not able to extract any information from it.  Please check its formatting and try again.";

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

	if (count($tf['err']) > 0) {
	    $filename = htmlspecialchars($tf['filename']);
	    $conf->errorMsg("There were some errors while parsing the uploaded reviews file. <div class='parseerr'><p>$filename:" . join("</p>\n<p>$filename:", $tf['err']) . "</p></div>");
	}

	if (count($tf['confirm']) > 0)
	    $conf->confirmMsg("<div class='parseerr'><p>" . join("</p>\n<p>", $tf['confirm']) . "</p></div>");
    }
    
    function webDisplayRows($rrow, $viewAllFields) {
	global $ReviewFormError;
	$x = '';
	$needRow = 1;
	
	foreach ($this->fieldOrder as $field) {
	    $fval = ($rrow ? $rrow->$field : "");
	    $options = isset($this->options[$field]);
	    if (!$options && $fval == "")
		continue;
	    if (!$this->authorView[$field] && !$viewAllFields)
		continue;
	    
	    if ($needRow == 0 && (!$options || $rowColumns == 2)) {
		$x .= "</tr>\n";
		$needRow = 1;
	    }
	    if ($needRow) {
		$x .= "\n<tr class='rev_$field'>\n";
		$rowColumns = 0;
	    }
	    
	    $x .= "  <td class='caption rev_$field'>";
	    $n = htmlspecialchars($this->shortName[$field]);
	    if (preg_match("/\\A\\S+\\s+\\S+\\Z/", $n))
		$n = preg_replace("/\\s+/", "&nbsp;", $n);
	    $x .= "$n";
	    if (!$this->authorView[$field])
		$x .= "<br /><span class='hint'>PC only</span>";
	    $x .= "</td>\n";
	    
	    $x .= "  <td class='entry rev_$field";
	    if ($options) {
		$x .= ($rowColumns == 0 ? " leftentry'" : " rightentry'");
		if ($fval == "" || $fval <= 0 || $fval > count($this->options[$field]))
		    $x .= "><span class='rev_${field}_0'>Unknown</span>";
		else
		    $x .= "><span class='rev_${field}_$fval'><span class='rev_num rev_num_$fval'>$fval.</span> " . htmlspecialchars($this->options[$field][$fval]) . "</span>";
	    } else
		$x .= "' colspan='3'>" . htmlWrapText(htmlspecialchars($fval));
	    $x .= "</td>\n";

	    $needRow = !$options;
	    $rowColumns++;
	}

	return $x . "</tr>\n";
    }
    
    function webGuidanceRows($viewAllFields) {
	global $ReviewFormError;
	$x = '';
	$needRow = 1;
	
	foreach ($this->fieldOrder as $field) {
	    if (!$this->authorView[$field] && !$viewAllFields)
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

    function webNumericScoresHeader($prow, $contact, $conf) {
	$viewAllFields = $contact->canViewAllReviewFields($prow, $conf);
	$x = "";
	foreach ($this->fieldOrder as $field)
	    if (($this->authorView[$field] || $viewAllFields)
		&& isset($this->options[$field]))
		$x .= "<th>" . htmlspecialchars($this->shortName[$field]) . "</th>";
	return $x;
    }

    function webNumericScoresRow($rrow, $prow, $contact, $conf, &$anyScores) {
	$view = $contact->canViewReview($prow, $rrow, $conf);
	$viewAllFields = $contact->canViewAllReviewFields($prow, $conf);
	$x = "";
	foreach ($this->fieldOrder as $field)
	    if (($this->authorView[$field] || $viewAllFields)
		&& isset($this->options[$field]) && $rrow->$field) {
		if ($view) {
		    $x .= "<td class='revscore rs_$field'>" . $rrow->$field . "</td>";
		    $anyScores = true;
		} else
		    $x .= "<td class='revscore rs_$field'></td>";
	    }
	return $x;
    }

    function numNumericScores($prow, $contact, $conf) {
	$viewAllFields = $contact->canViewAllReviewFields($prow, $conf);
	$n = 0;
	foreach ($this->fieldOrder as $field)
	    if (($this->authorView[$field] || $viewAllFields)
		&& isset($this->options[$field]))
		$n++;
	return $n;
    }
    
    function webNumericScoresTable($rrows, $prow, $contact, $conf, $links) {
	$viewAllFields = $contact->canViewAllReviewFields($prow, $conf);
	$x = "<table class='reviewscores'>\n<tr class='rs_heading'><td></td><td></td>";
	foreach ($this->fieldOrder as $field) {
	    if (($this->authorView[$field] || $viewAllFields)
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
		if (($this->authorView[$field] || $viewAllFields)
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


/*
class FormVariable {
  var $formName;
  var $label;
  var $value;

  function FormVariable($name, $l = "") {
    $this -> formName = $name;
    $this -> label = $l;
  }

  function getLabel() {
    return $this->label;
  }

  function printLabel() {
    print $this->getLabel();
  }

  //
  // Load a value from another object
  //
  function load(&$array) {
    if (IsSet($array[$this->formName])) {
      $this->value=stripslashes($array[$this->formName]);
    }
  }

  function mkset() {
    return "$this->formName='"
      . addslashes($this->value)
      . "'";
  }

  function editable() {
    print "<p> Subclass didn't specify an editable method </p>";
  }

  function viewable() {
    print nl2br(htmlentities($this->value));
  }

  function getASCII() {
    return $this->value;
  }
}

class FormMessage extends FormVariable{
  function FormMessage($message, $name2 = "") {
    $this -> FormVariable("",$name2);
    $this -> value = $message;
  }

  function mkset() {
    return "";
  }

  function editable() {
    print "<TABLE BGCOLOR=\"YELLOW\"><TR><TD>";
    print $this->value;
    print "</TD></TR></TABLE>";
  }

  function viewable() {
    //$this->editable();
  }
}

class FormTextArea extends FormVariable{
  var $cols;
  var $rows;

  function FormTextArea($name, $c, $r, $name2 = "") {
    $this -> FormVariable($name,$name2);
    $this -> cols = $c;
    $this -> rows = $r;
  }

  function editable() {
    print "<textarea wrap=virtual rows=\"$this->rows\" cols=\"$this->cols\" name=\"$this->formName\">";
    print $this->value;
    print "</textarea>";
  }
}

class FormText extends FormVariable{
  var $cols;

  function FormText($name1, $c, $name2 ="") {
    $this -> FormVariable($name1, $name2);
    $this -> cols = $c;
    if ($this->cols == 0) {
      $this->cols=10;
    }
  }

  function editable() {
    print "<input type=\"text\" name=\"$this->formName\" ";
    print "size='$this->cols' ";
    print "value='$this->value'";
    print ">\n";
  }
}


class FormNumericRange extends FormVariable{
  var $min;
  var $max;

  function FormNumericRange($name1, $mn, $mx, $name2="") {
    $this -> FormVariable($name1, $name2);
    $this -> min = $mn;
    $this -> max = $mx;
    return $this;
  }

  function editable() {
    print "<SELECT name=\"$this->formName\" SINGLE>\n";
    for ($i = $this -> min; $i <= $this -> max; $i++) {
      $selected = "";
      if ($i == $this->value) {
	$selected = "SELECTED";
      }
      print "<OPTION $selected VALUE=\"$i\"> $i </OPTION>\n";
    }
    print "</SELECT>";
  }
}

class FormLongTextLabeledRange extends FormNumericRange{
  var $labels;

  function FormLongTextLabeledRange($name1, $l, $mn, $mx, $name2="") {
    $this -> FormNumericRange($name1, $mn, $mx, $name2);
    $this -> labels = $l;
  }

  function editable() {
    print "<TABLE BORDER>\n";
    for ($i = $this -> min; $i <= $this -> max; $i++) {
      $selected = "";
      if ($i == $this->value) {
	$selected = "CHECKED";
      }
      print "<TR><TD><INPUT TYPE=RADIO name=\"$this->formName\" $selected VALUE=\"$i\"></TD><TD>";
      print $this->labels[$i];
      print " </TD></TR>\n";
    }
    print "</TABLE>";
  }

  function viewable() {
    print $this->labels[$this->value];
  }

  function getASCII() {
    return $this->labels[$this->value];
  }
}

class FormNumericLabeledRange extends FormNumericRange{
  var $labels;

  function FormNumericLabeledRange($name1, $l, $mn, $mx, $name2="") {
    $this -> FormNumericRange($name1, $mn, $mx, $name2);
    $this -> labels = $l;
  }

  function editable() {
    print "<SELECT name=\"$this->formName\" SINGLE>\n";
    for ($i = $this -> min; $i <= $this -> max; $i++) {
      $selected = "";
      if ($i == $this->value) {
	$selected = "SELECTED";
      }
      print "<OPTION $selected VALUE=\"$i\">";
      print $this->labels[$i];
      print " </OPTION>\n";
    }
    print "</SELECT>";
  }

  function viewable() {
    print $this->labels[$this->value];
  }

  function getASCII() {
    return $this->labels[$this->value];
  }
}

class FormCollection {
  var $numfields;
  var $fields;
  var $fieldorder;

  function FormCollection() {
    $this->fields=array();
    $this->fieldorder=array();
  }

  function add(&$obj) {
    $name = $obj->formName;
    $this->fields[$name] = &$obj;
    array_push($this->fieldorder, $name);
  }

  function load(&$data) {
    for($i = 0; $i < sizeof($this->fieldorder); $i++) {
      $name = $this->fieldorder[$i];
      $this->fields[$name]->load($data);
    }
  }

  function printEditableAsRows() {
    for($i = 0; $i < sizeof($this->fieldorder); $i++) {
      $name = $this->fieldorder[$i];
      print "<tr> <td>\n";
      $this->fields[$name]->printLabel();
      print "</td> <td>\n";
      $this->fields[$name]->editable();
      print "</td> </tr>\n";
    }
  }

  function printViewableAsRows() {
    for($i = 0; $i < sizeof($this->fieldorder); $i++) {
      $name = $this->fieldorder[$i];
      print "<tr> <td>\n";
      $this->fields[$name]->printLabel();
      print "</td> <td>\n";
      $this->fields[$name]->viewable();
      print "</td> </tr>\n";
    }
  }
  
  function mkset() {
    $set = "";
    $sep = "";
    for($i = 0; $i < sizeof($this->fieldorder); $i++) {
      $name = $this->fieldorder[$i];
      $item = $this->fields[$name]->mkset();
      if( $item != "" ){
        $set = $set . $sep . $this->fields[$name]->mkset();
        $sep = ", ";
      }
    }
    return $set;
  }

}

Class ConferenceReview extends FormCollection {

  var $paperId;
  var $reviewId;
  var $valid;

  var $paperFields;
  var $authorFields;

  var $paperTopics;

  var $reviewFields;
  var $reviewExisted;

  var $checkFields;

  //
  // For formatted print/multireviews
  //
  var $shortFields;
  var $longFields;

  function ConferenceReview($Conf, $contactId, $pid)
    {
      $this->FormCollection();

      $this->paperTopics = array();

      $this->paperId = $pid;
      $this->reviewId= $r;
      $this->valid = 0;

      $result = $Conf-> q("SELECT Paper.*, PaperStorage.mimetype "
			  . " FROM Paper LEFT JOIN PaperStorage "
			  . " ON (PaperStorage.paperId='$this->paperId')"
			  . " WHERE Paper.paperId='$this->paperId'"
			  );

      if (DB::isError($result)) {
	$Conf->errorMsg("query : " . $result->getMessage());
	return 0;
      } else if ($result->numRows() == 0) {
	$Conf->errorMsg("No rows of data?");
	return 0;
      } else {
	$this->paperFields = $result->fetchRow(DB_FETCHMODE_ASSOC);
	//
	// Pull out the author information, load it.
	//
	$authorId = $this->paperFields['contactId'];
	$result = $Conf->q("SELECT * FROM ContactInfo WHERE contactId='$authorId'");
	if (DB::isError($result)) {
	  $Conf->errorMsg("Invalid query for author information");
	  return;
	} else {
	  $this->authorFields = $result->fetchRow(DB_FETCHMODE_ASSOC);
	}

	//
	// Get paper topics
	// 

	$query="select TopicArea.topicName from TopicArea INNER JOIN PaperTopic ON TopicArea.topicId = PaperTopic.topicId WHERE PaperTopic.paperId = " . $this->paperId;
        $result = $Conf->q($query);
        if ( DB::isError($result)) {
          $Conf->errorMsg("Error in query for paper topics: " . $result->getMessage());
        }
        else {
 	  $i = 0;
	  while ( $row = $result->fetchRow()) {
	    $this->paperTopics[$i] = $row[0];
	    $i++;
	  }
        }

	$this->valid = 1;

	//
	// Now, pull the review if it exists
	//
	
	$myid = $contactId;
	$result = $Conf->q("SELECT *, unix_timestamp(lastModified) as timestamp FROM PaperReview WHERE "
			   . " contactId='$myid' AND paperId='$this->paperId'");
	if (DB::isError($result)) {
	  $this->reviewExisted = 0;
	} else {
	  $this->reviewExisted = 1;
	  $this->reviewFields = $result->fetchRow(DB_FETCHMODE_ASSOC);
	  if ( !is_array($this->reviewFields) ) {
	    $this->reviewExisted = 0;
	  }
	}
      }
    }

  function finalized() {
    return $this->reviewFields['reviewSubmitted'];
  }

  function saveReview($Conf, $contactId)
    {
      //
      // Collect all the fields..
      //
      if ($this->valid) {
	$set = $this -> mkset();
	$myid = $contactId;
	
	if ($this->reviewExisted) {
	  $result=$Conf->qe("UPDATE PaperReview SET $set WHERE "
			   . " contactId='$myid' AND paperId='$this->paperId'");
	} else {
	  $result=$Conf->qe("INSERT INTO PaperReview SET "
			   . " contactId='$myid', paperId='$this->paperId', "
			   . $set );
	}
      } else {
	$Conf->errorMsg("Unable to save review because of invalid state");
      }
    }

  function printAnonReviewHeader($conf, $showAuthorsResponse=0)
    {
      return $this->printVisibleReviewHeader($conf,false,$showAuthorsResponse);
      $title = htmlentities($this->paperFields['title']);
      $abstract = nl2br(htmlentities($this->paperFields['abstract']));
      $collaborators = nl2br(htmlentities($this->paperFields['collaborators']));
      $authorsResponse = nl2br(htmlentities($this->paperFields['authorsResponse']));
      $mimetype=$this->paperFields['mimetype'];
      $paperId=$this->paperId;
      print "<table border=1 align=center>";

      print "<tr> <td width=15%> <h3> <b> Paper #$this->paperId </td> <td>";
      print "<a href=\"";
      print  $conf->makeDownloadPath($paperId, $mimetype);
      print "\" target=_blank>";
      print " (Download paper of type $mimetype ) </a> </b> <h3> </td> <tr>\n";
      print "<tr> <td> Title: </td> <td> $title </td> </tr>\n";
      print "<tr> <td> Abstract: </td> <td> $abstract </td> </tr>\n";
      //print "<tr> <td> Collaborators: </td> <td> $collaborators </td> </tr>\n";
      print "<tr> <td> Paper Topics: </td> <td>";
      foreach ($this->paperTopics as $topic ) {
        print "$topic<br>";
      }
      print "</td> </tr>\n";

      if ($showAuthorsResponse && strlen($authorsResponse) > 0) {
	print "<tr> <td> Authors Response: </td> <td ALIGN=LEFT> $authorsResponse </td> </tr>\n";
      } else if ($showAuthorsResponse) {
	print "<tr> <td colspan=2 align=center> No author response submitted </td> </tr>\n";
      }	
      print "</table>";
    }

  function printVisibleReviewHeader($conf, $showAuthor = true, $showResp = true)
    {
      $authorsResponse = nl2br(htmlentities($this->paperFields['authorsResponse']));
      if( 0 ){
      $title = htmlentities($this->paperFields['title']);
      $abstract = nl2br(htmlentities($this->paperFields['abstract']));
      $collaborators = nl2br(htmlentities($this->paperFields['collaborators']));
      $mimetype=$this->paperFields['mimetype'];

      $email = $this->authorFields['firstName']
	. ' '
	. $this->authorFields['lastName']
	. ' ('
	. $this->authorFields['email']
	. ')';

      $authorInfo = nl2br(htmlentities($this->paperFields['authorInformation']));

      $paperId=$this->paperId;
      print "<table border=1 align=center>";

      print "<tr> <td width=15%> <h3> <b> Paper #$this->paperId </td> <td>";
      print "<a href=\"";
      print  $conf->makeDownloadPath($paperId, $mimetype);
      print "\" target=_blank>";
      print " (Download paper of type $mimetype ) </a> </b> <h3> </td> <tr>\n";
      print "<tr> <td> Contact Email: </td> <td> $email </td> </tr>\n";
      print "<tr> <td> Author Info: </td> <td> $authorInfo </td> </tr>\n";
      print "<tr> <td> Title: </td> <td> $title </td> </tr>\n";
      print "<tr> <td> Abstract: </td> <td> $abstract </td> </tr>\n";
      print "<tr> <td> Collaborators: </td> <td> $collaborators </td> </tr>\n";
      print "<tr> <td> Paper Topics: </td> <td>";
      foreach ($this->paperTopics as $topic ) {
        print "$topic<br>";
      }
      print "</td> </tr>\n";
      }
      $conf->paperTable( $showAuthor, true, $this->paperId, false, false, false );
      if( $showResp ){
	print "<CENTER>";
	if (strlen($authorsResponse) > 0) {
	  print "<TABLE BORDER BGCOLOR='" . $conf->bgOne . "'><TR><TH>Authors Response:</TH></TR><TR>";
	  print "<TD ALIGN=LEFT> $authorsResponse</TD></TR></TABLE>\n";
	} else if ($showAuthorsResponse) {
	  print "<P>No author response submitted</P>\n";
	}	
	print "</CENTER>";
      }
      //print "</table>";
    }

  function printTableHeader()
    {
      print "<table columns=2 border=1 align=center>\n";
      print "<tr>\n";
      print "<th width=25%> Attribute </th>\n";
      print "<th width=75% colspan=12> Value </th>\n";
      print "</tr>\n";
    }

  function printTableFooter()
    {
      print "</table>";
    }

  function printEditable()
    {
      $this->printTableHeader();
      $this->printEditableAsRows();
      $this->printTableFooter();
    }

  function printViewable()
    {
      $this->printTableHeader();
      $this->printViewableAsRows();
      $this->printTableFooter();
    }

  function getAnonReviewHeaderASCII($conf)
    {
      $title = $this->paperFields['title'];
      $abstract = $this->paperFields['abstract'];
      $paperId=$this->paperId;

      return "Review for paper #$paperId\n"
	. "Title: $title\n"
	. "Abstract: $abstract\n\n"
	;
    }

  function getReviewASCII()
    {
      $msg = "";
      for($i = 0; $i < sizeof($this->fieldorder); $i++) {
	$name = $this->fieldorder[$i];
	$msg .= $this->fields[$name]->getLabel()
	  . " :\n " . $this->fields[$name]->getASCII()
	  . "\n\n";
      }
      return $msg;
    }


  /////////////////////////////////////////////////////////////////////////////
  // Viewing reviews
  /////////////////////////////////////////////////////////////////////////////
  function seeReviewAttribute()
    {
      $this -> printViewable();
    }

  function authorViewReview()
    {
      $this -> printViewable();
    }

  function getTextForAuthors() {
    $resultStr = "";
    for($i = 0; $i < sizeof($this->fieldorder); $i++) {
      $name = $this->fieldorder[$i];
      // exclude the fields that the authors should not see.
      // if the hidden fields are review form specific, this 
      // function should go in the subclasses
      if ($name != "reviewSubmitted" and   $name != "commentsToPC") {
	$resultStr .= $this->fields[$name]->getLabel() .":\n";
	$resultStr .= $this->fields[$name]->getASCII(). "\n\n";
      }
    }
    return $resultStr;
  }
}
*/
