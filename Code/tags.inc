<?php
// tags.inc -- HotCRP helper functions for dealing with tags
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function checkTagError($tag, $forsearch = false) {
    if ($tag == "")
	return "Empty tag.";
    else if (strlen($tag) > TAG_MAXLEN)
	return "Tag \"$tag\" too long; maximum " . TAG_MAXLEN . " characters.";
    else if (!preg_match('/\A~?[a-zA-Z!@*_:.][-a-zA-Z0-9!@*_:.\/]*#?\d*\Z/', $tag))
	// Note that tags MUST NOT contain HTML special characters.
	return "Tag \"$tag\" contains odd characters.";
    else if (($tag == "none" || $tag == "any") && !$forsearch)
	return "Tag \"$tag\" is reserved.";
    else
	return "";
}

function checkTag($tag, $reporterr = true) {
    global $Conf, $Me;
    $e = checkTagError($tag);
    if ($e && $reporterr)
	$Conf->errorMsg(htmlspecialchars($e));
    if ($e != "")
	return false;
    else if ($tag[0] == "~")
	return $Me->contactId . $tag;
    else
	return $tag;
}

function tagBase($tag) {
    if (($pos = strpos($tag, "#")) > 0)
	return substr($tag, 0, $pos);
    else
	return $tag;
}

function chairTags() {
    global $Conf;
    if ($Conf->setting("tag_chair"))
	return array_flip(preg_split('/\s+/', $Conf->settingText("tag_chair", "")));
    else {
	$result = $Conf->q("select tag from ChairTag");
	$c = array();
	while (($row = edb_row($result)))
	    $c[$row[0]] = 1;
	return $c;
    }
}

function setTags($pids, $tagtext, $mode, $chair, $reporterr = true) {
    global $Conf, $Me;
    if (!is_array($pids))
	$pids = array($pids);

    // check tags for direct validity
    $ok = true;
    $tags = array();
    foreach (preg_split('/\s+/', $tagtext) as $tag) {
	if ($mode == "so" || $mode == "ao")
	    $tag .= "#";
	if ($tag != "" && ($tag = checkTag($tag, $reporterr))
	    && ($ok = ($ok && $tag)))
	    $tags[] = $tag;
    }
    if (!$ok)
	return false;
    if (count($tags) == 0 && $mode != 'p') {
	if ($reporterr)
	    $Conf->errorMsg("No tags specified.");
	return false;
    }
    
    // lock
    $while = "while tagging papers";
    $Conf->qe("lock tables PaperTag write, ActionLog write", $while);

    // check chair tags
    if (!$chair) {
	$chtags = chairTags();
	$removetags = array();
	foreach ($tags as $tag) {
	    $tb = tagBase($tag);
	    if (isset($chtags[$tb]))
		$removetags[] = $tag;
	}
	$tags = array_diff($tags, $removetags);
	if (count($removetags) > 0 && $mode != 'p' && $reporterr)
	    $Conf->errorMsg("Attempt to change chair-only tags ignored.");
	if (count($tags) == 0 && $mode != 'p') {
	    $Conf->q("unlock tables");
	    return false;
	}
    }

    // delete tags
    if ($mode != "a" && $mode != "ao") {
	$q = "delete from PaperTag where (";
	if ($mode == "s" || $mode == "so")
	    $q .= "true or ";
	else
	    foreach ($pids as $pid)
		$q .= "paperId=$pid or ";
	$q = substr($q, 0, strlen($q) - 4) . ")";
	if ($mode != "p") {
	    $q .= " and (";
	    foreach ($tags as $tag)
		$q .= "tag='" . sqlq(tagBase($tag)) . "' or ";
	    $q = substr($q, 0, strlen($q) - 4) . ")";
	} else {
	    if (!$chair && count($chtags)) {
		foreach ($chtags as $ct => $xxx)
		    if ($ct[0] == '~')
			$q .= " and tag!='$Me->contactId" . sqlq($ct) . "'";
		    else
			$q .= " and tag!='" . sqlq($ct) . "'";
	    }
	    if (!$chair && $roundtags)
		foreach (explode(' ', $roundtags) as $tround)
		    if ($tround)
			$q .= " and tag!='$Me->contactId" . "~" . sqlq($tround) . "'";
	    $q .= " and (tag like '$Me->contactId~%' or tag not like '%~%')";
	}
	$Conf->qe($q, $while);
    }

    // add tags
    if ($mode != "d" && count($tags)) {
	$q = "insert into PaperTag (paperId, tag, tagIndex) values ";
	$tagIndex = array();
	foreach ($pids as $pid) {
	    // jump through some hoops for discussion list tags
	    foreach ($tags as $tag) {
		$base = tagBase($tag);
		if (strlen($base) == strlen($tag))
		    $q .= "($pid, '" . sqlq($base) . "', 0), ";
		else if (strlen($base) < strlen($tag) - 1)
		    $q .= "($pid, '" . sqlq($base) . "', " . substr($tag, strlen($base) + 1) . "), ";
		else {
		    if (!isset($tagIndex[$base])) {
			$result = $Conf->qe("select max(tagIndex) from PaperTag where tag='" . sqlq($base) . "'", $while);
			if (($row = edb_row($result)))
			    $tagIndex[$base] = $row[0] + 1;
			else
			    $tagIndex[$base] = 1;
		    }
		    $q .= "($pid, '" . sqlq($base) . "', " . $tagIndex[$base] . "), ";
		    $tagIndex[$base]++;
		}
	    }
	}
	$Conf->qe(substr($q, 0, strlen($q) - 2) . " on duplicate key update tagIndex=tagIndex", $while);
    }

    $modeexplanation = array("so" => "define ordered", "ao" => "add ordered", "d" => "remove", "s" => "define", "a" => "add", "p" => "set");
    $Conf->log("Tag " . $modeexplanation[$mode] . ": " . join(", ", $tags), $Me, $pids);
    
    $Conf->qe("unlock tables", $while);
    return true;
}

function tagsToText($t, $makeUrls, $contact = null) {
    global $Me, $Conf, $ConfSiteBase, $ConfSiteSuffix;
    if ($contact === null)
	$contact = $Me;
    if (is_object($contact))
	$contact = $contact->contactId;
    $t = str_replace("#0", "", $t);
    if (strpos($t, "~") !== false) {
	$t = str_replace(" ${contact}~", " ~", " $t ");
	$t = trim(preg_replace('/ \d+~\S+ /', ' ', $t));
    }
    if ($makeUrls === false)
	return $t;
    if (!is_string($makeUrls))
	$makeUrls = $ConfSiteBase;
    return preg_replace('/(~?[a-zA-Z!@*_:.][-a-zA-Z0-9!@*_:.\/]*)/',
			"<a class='q' href=\"${makeUrls}search$ConfSiteSuffix?q=tag:\$1\">\$1</a>",
			$t);
}
