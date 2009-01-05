<?php
// tags.inc -- HotCRP helper functions for dealing with tags
// HotCRP is Copyright (c) 2006-2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// Note that tags MUST NOT contain HTML or URL special characters:
// no "'&<>

define("CHECKTAG_ALLOWRESERVED", 1);
define("CHECKTAG_NOPRIVATE", 2);
define("CHECKTAG_NOINDEX", 4);
define("CHECKTAG_CHAIR", 8);
define("CHECKTAG_QUIET", 16);
define("CHECKTAG_ERRORARRAY", 32);

function checkTagError($tag, $flags) {
    if ($tag == "")
	return "Empty tag.";
    else if (strlen($tag) > TAG_MAXLEN)
	return "Tag \"$tag\" too long; maximum " . TAG_MAXLEN . " characters.";
    else if (!preg_match('/\A~?~?[a-zA-Z!@*_:.][-a-zA-Z0-9!@*_:.\/]*([#=](-\d)?\d*)?\z/', $tag, $m))
	return "Tag \"$tag\" contains odd characters.";
    else if (count($m) > 1 && $m[1] && ($flags & CHECKTAG_NOINDEX))
	return "Tag numbers not allowed here.";
    else if ($tag[0] === "~" && $tag[1] === "~" && !($flags & CHECKTAG_CHAIR))
	return "Tag \"$tag\" is exclusively for chairs.";
    else if ($tag[0] === "~" && ($flags & CHECKTAG_NOPRIVATE))
	return "Twiddle tags not allowed here.";
    else if (($tag === "none" || $tag === "any") && !($flags & CHECKTAG_ALLOWRESERVED))
	return "Tag \"$tag\" is reserved.";
    else
	return "";
}

function checkTag($tag, $flags) {
    global $Conf, $Me, $Error;
    $e = checkTagError($tag, $flags);
    if ($e && ($flags & CHECKTAG_ERRORARRAY))
	defappend($Error["tags"], htmlspecialchars($e) . "<br />\n");
    else if ($e && !($flags & CHECKTAG_QUIET))
	$Conf->errorMsg(htmlspecialchars($e));
    if ($e != "")
	return false;
    else if ($tag[0] == "~" && $tag[1] != "~")
	return $Me->contactId . $tag;
    else
	return $tag;
}

function tagBase($tag) {
    if ($tag === false)
	return false;
    if (($pos = strpos($tag, "#")) > 0 || ($pos = strpos($tag, "=")) > 0)
	return substr($tag, 0, $pos);
    return $tag;
}

function tagSplit($tag) {
    if ($tag === false)
	return array(false, false);
    else if (!($pos = strpos($tag, "#")) && !($pos = strpos($tag, "=")))
	return array($tag, false);
    else if ($pos == strlen($tag) - 1)
	return array(substr($tag, 0, $pos), false);
    else
	return array(substr($tag, 0, $pos), (int) substr($tag, $pos + 1));
}

function chairTags() {
    global $Conf;
    if ($Conf->setting("tag_chair"))
	return array_flip(preg_split('/\s+/', $Conf->settingText("tag_chair", "")));
    else
	return array();
}

function voteTags() {
    global $Conf;
    if ($Conf->setting("tag_vote")) {
	$vt = array();
	foreach (preg_split('/\s+/', $Conf->settingText("tag_vote", "")) as $t)
	    if (($p = strpos($t, "#")) > 0 || ($p = strpos($t, "=")) > 0)
		$vt[substr($t, 0, $p)] = cvtint(substr($t, $p + 1));
	    else
		$vt[$t] = 1;
	return $vt;
    } else
	return array();
}

global $orderedTagStepMap;
$orderedTagStepMap = array(1, 1, 1, 1, 1, 2, 2, 2, 3, 4);

function orderedTagStep($mode) {
    global $orderedTagStepMap;
    if (strlen($mode) == 2)
	return $orderedTagStepMap[mt_rand(0, 9)];
    else
	return 1;
}

function setTags($pids, $tagtext, $mode, $chair) {
    global $Conf, $ConfSiteSuffix, $Me, $Error;
    if (!is_array($pids))
	$pids = array($pids);
    $mytagprefix = $Me->contactId . "~";

    // check modes
    if ($mode == "da" && !$chair)
	$mode = "d";

    // check tags for direct validity
    $ok = true;
    $tags = array();
    $checkflags = ($chair ? CHECKTAG_CHAIR : 0) + CHECKTAG_ERRORARRAY;
    foreach (preg_split('/\s+/', $tagtext) as $tag)
	if ($tag != "" && ($tag = checkTag($tag, $checkflags))
	    && ($ok = ($ok && $tag)))
	    $tags[] = $tag;
    if (!$ok)
	return false;

    // lock
    $while = "while tagging papers";
    $Conf->qe("lock tables PaperTag write", $while);

    // check chair tags
    $deltags = array();
    if (!$chair) {
	$chtags = chairTags();
	$nexttags = array();
	foreach ($tags as $tag) {
	    if (isset($chtags[tagBase($tag)]))
		$deltags[] = $tag;
	    else
		$nexttags[] = $tag;
	}
	if (count($nexttags) != count($tags))
	    defappend($Error["tags"], "Tag &ldquo;" . htmlspecialchars($deltags[0]) . "&rdquo; can only be changed by the chair.<br />\n");
	$tags = $nexttags;
    }

    // check vote tags
    $vtags = voteTags();
    $vchanges = array();
    if (count($vtags)) {
	$nexttags = array();
	$multivote = 0;
	foreach ($tags as $tag) {
	    $base = tagBase($tag);
	    if (isset($vtags[$base]))
		$deltags[] = $tag;
	    else if (substr($base, 0, strlen($mytagprefix)) === $mytagprefix
		     && isset($vtags[substr($base, strlen($mytagprefix))])) {
		if (isset($vchanges[$base])) // only one vote per tag
		    $multivote++;
		else {
		    if (strlen($base) == strlen($tag))
			$tag .= "#1";
		    $nexttags[] = $tag;
		    $vchanges[$base] = 0;
		}
	    } else
		$nexttags[] = $tag;
	}
	if (count($nexttags) + $multivote != count($tags)) {
	    $t = htmlspecialchars(tagBase($deltags[count($deltags) - 1]));
	    defappend($Error["tags"], "The shared tag &ldquo;$t&rdquo; keeps track of vote totals and cannot be modified.  Use the private tag &ldquo;~$t&rdquo; to change your vote (for instance, &ldquo;~$t#1&rdquo; is one vote).<br />\n");
	}
	$tags = $nexttags;
    }

    // check rank tag
    if (!$chair && $Conf->setting("tag_rank")) {
	$ranktag = $Conf->settingText("tag_rank");
	$nexttags = array();
	foreach ($tags as $tag) {
	    if (tagBase($tag) === $ranktag)
		$deltags[] = $tag;
	    else
		$nexttags[] = $tag;
	}
	if (count($nexttags) != count($tags)) {
	    $t = htmlspecialchars(tagBase($deltags[count($deltags) - 1]));
	    defappend($Error["tags"], "The shared tag &ldquo;$t&rdquo; keeps track of the global ranking and cannot be modified.  Use the private tag &ldquo;~$t&rdquo; to change your ranking.<br />\n");
	}
	$tags = $nexttags;
    }

    // exit if nothing to do
    if (count($tags) == 0 && $mode != 'p') {
	$Conf->q("unlock tables");
	if (count($deltags) == 0)
	    defappend($Error["tags"], "No tags specified.<br />\n");
	return false;
    }

    // delete tags
    if ($mode != "a" && $mode != "ao" && $mode != "aos") {
	$q = "delete from PaperTag where ";
	if ($mode == "s" || $mode == "so" || $mode == "sos")
	    $q .= "true";
	else
	    $q .= "paperId in (" . join(",", $pids) . ")";
	$dels = array();
	if ($mode != "p") {
	    foreach ($tags as $tag) {
		$ts = tagSplit($tag);
		$qx = "";
		if ($ts[1] !== false && ($mode == "d" || $mode == "da"))
		    $qx = " and tagIndex=$ts[1]";
		if ($mode == "da"
		    && substr($ts[0], 0, strlen($mytagprefix)) === $mytagprefix) {
		    $dels[] = "(tag like '%~" . sqlq_for_like(substr($ts[0], strlen($mytagprefix))) . "'$qx)";
		    continue;
		} else if ($mode == "da")
		    $dels[] = "(tag like '%~" . sqlq_for_like($ts[0]) . "'$qx)";
		$dels[] = "(tag='" . sqlq($ts[0]) . "'$qx)";
	    }
	    $q .= " and (" . join(" or ", $dels) . ")";
	} else {
	    if (!$chair && count($chtags)) {
		foreach ($chtags as $ct => $xxx)
		    if ($ct[0] == '~')
			$q .= " and tag!='$Me->contactId" . sqlq($ct) . "'";
		    else
			$q .= " and tag!='" . sqlq($ct) . "'";
	    }
	    $q .= " and (tag like '$mytagprefix%' or tag not like '%~%')";
	}
	$Conf->qe($q, $while);
    }

    // check for vote changes
    if (count($vchanges) && $mode != "d" && $mode != "da") {
	$q = "";
	foreach ($vchanges as $base => &$val) {
	    $q .= ($q === "" ? "" : ",") . "'" . sqlq($base) . "'";
	    $val = $vtags[substr($base, strpos($base, "~") + 1)];
	}
	unset($val);
	if ($mode != "p")	// must delete old versions for correct totals
	    $Conf->qe("delete from PaperTag where paperId in (" . join(",", $pids) . ") and tag in ($q)", "while deleting old votes");
	$result = $Conf->qe("select tag, sum(tagIndex) from PaperTag where tag in ($q) group by tag", "while checking vote totals");
	while (($row = edb_row($result)))
	    $vchanges[$row[0]] = max($vchanges[$row[0]] - $row[1], 0);
    }

    // extract tag indexes into a separate array
    $tagIndex = array();
    $explicitIndex = array();
    $modeOrdered = ($mode == "so" || $mode == "ao" || $mode == "sos"
		    || $mode == "aos");
    foreach ($tags as $tag) {
	$base = tagBase($tag);
	if (strlen($base) + 1 < strlen($tag)) {
	    $tagIndex[$base] = $explicitIndex[$base] =
		(int) substr($tag, strlen($base) + 1);
	} else if (strlen($base) + 1 == strlen($tag) || $modeOrdered) {
	    $result = $Conf->qe("select max(tagIndex) from PaperTag where tag='" . sqlq($base) . "'", $while);
	    if (($row = edb_row($result)))
		$tagIndex[$base] = $row[0] + orderedTagStep($mode);
	    else
		$tagIndex[$base] = orderedTagStep($mode);
	}
    }

    // if inserting tags into an order, shift existing tags
    $reorders = array();
    if (($mode == "ao" || $mode == "aos") && count($explicitIndex)) {
	$q = "";
	foreach ($explicitIndex as $base => $index)
	    $q .= "(tag='" . sqlq($base) . "' and tagIndex>=$index) or ";
	$result = $Conf->qe("select paperId, tag, tagIndex from PaperTag where " . substr($q, 0, strlen($q) - 4) . " order by tagIndex", "while reordering tags");
	while (($row = edb_row($result)))
	    if (!in_array($row[0], $pids))
		$reorders[$row[1]][] = array($row[0], $row[2]);
    }

    // add tags
    $vreduced = array();
    if ($mode != "d" && $mode != "da" && count($tags)) {
	$q_keepold = $q_keepnew = "";
	$delvotes = array();
	foreach ($pids as $pid) {
	    foreach ($tags as $tag) {
		$base = tagBase($tag);
		// choose index, bump running index in ordered mode
		$index = defval($tagIndex, $base, 0);
		if ($modeOrdered)
		    $tagIndex[$base] += orderedTagStep($mode);
		// check vote totals
		if (isset($vchanges[$base])) {
		    if ($index > $vchanges[$base]) {
			$vreduced[substr($base, strpos($base, "~"))] = true;
			$index = $vchanges[$base];
		    } else if ($index < 0) // no negative votes, smarty
			$index = 0;
		    $vchanges[$base] -= $index;
		    if ($index == 0) {
			$delvotes[] = "(paperId=$pid and tag='" . sqlq($base) . "')";
			continue;
		    }
		}
		// add to the right query, which differ in behavior on setting
		// a tag that's already set.  $q_keepnew keeps the new value,
		// $q_keepold keeps the old value.
		$thisq = "($pid, '" . sqlq($base) . "', " . $index . "), ";
		if (isset($explicitIndex[$base]))
		    $q_keepnew .= $thisq;
		else
		    $q_keepold .= $thisq;
	    }
	}
	// if adding ordered tags in the middle of an order, reorder old tags
	foreach ($reorders as $base => $pairs) {
	    $last = null;
	    foreach ($pairs as $p)
		if ($p[1] < $tagIndex[$base]) {
		    $thisq = "($p[0], '" . sqlq($base) . "', " . $tagIndex[$base] . "), ";
		    $q_keepnew .= $thisq;
		    if ($last === null || $last != $p[1])
			$tagIndex[$base] += orderedTagStep($mode);
		    $last = $p[1];
		} else
		    break;
	}
	// store changes
	if ($q_keepnew != "")
	    $Conf->qe("insert into PaperTag (paperId, tag, tagIndex) values " . substr($q_keepnew, 0, strlen($q_keepnew) - 2) . " on duplicate key update tagIndex=values(tagIndex)", $while);
	if ($q_keepold != "")
	    $Conf->qe("insert into PaperTag (paperId, tag, tagIndex) values " . substr($q_keepold, 0, strlen($q_keepold) - 2) . " on duplicate key update tagIndex=tagIndex", $while);
	if (count($delvotes))
	    $Conf->qe("delete from PaperTag where " . join(" or ", $delvotes), "while deleting zero votes");
    }

    // update vote totals
    if (count($vchanges) > 0 || (count($vtags) > 0 && $mode == "p")) {
	// Can't "insert from ... select ..." or "create temporary table"
	// because those unlock tables implicitly.

	// Find relevant vote tags.
	$myvtags = array();
	foreach (($mode == "p" ? $vtags : $vchanges) as $tag => $v) {
	    $base = (($p = strpos($tag, "~")) !== false ? substr($tag, $p + 1) : $tag);
	    $myvtags[] = $base;
	}

	// Defining a tag can update vote totals for more than the selected
	// papers.
	$xpids = $pids;
	if ($mode == "s" || $mode == "so" || $mode == "sos") {
	    $q = "";
	    foreach ($myvtags as $base)
		$q .= "'" . sqlq($base) . "',";
	    $result = $Conf->qe("select paperId from PaperTag where tag in (" . substr($q, 0, strlen($q) - 1) . ") group by paperId", "while counting votes");
	    while (($row = edb_row($result)))
		if (!in_array($row[0], $xpids))
		    $xpids[] = $row[0];
	}

	// count votes
	$vcount = array();
	$q = "select paperId, substring(tag from position('~' in tag) + 1) as tagBase, sum(tagIndex) from PaperTag where paperId in (" . join(",", $xpids) . ") and (";
	foreach ($myvtags as $base) {
	    $q .= "tag like '%~" . sqlq_for_like($base) . "' or ";
	    foreach ($xpids as $p)
		$vcount[$p . ", '" . sqlq($base) . "'"] = 0;
	}
	$result = $Conf->qe(substr($q, 0, strlen($q) - 4) . ") group by paperId, tagBase", "while counting votes");
	while (($row = edb_row($result))) {
	    $x = $row[0] . ", '" . sqlq($row[1]) . "'";
	    $vcount[$x] = $row[2];
	}

	// develop queries
	$ins = array();
	$del = array();
	foreach ($vcount as $k => $v) {
	    if ($v <= 0) {
		$p = strpos($k, ",");
		$del[] = "(paperId=" . substr($k, 0, $p) . " and tag=" . substr($k, $p + 2) . ")";
	    } else
		$ins[] = "($k, $v)";
	}

	// execute queries
	if (count($ins))
	    $Conf->qe("insert into PaperTag (paperId, tag, tagIndex) values " . join(", ", $ins) . " on duplicate key update tagIndex=values(tagIndex)", "while counting votes");
	if (count($del))
	    $Conf->qe("delete from PaperTag where " . join(" or ", $del), "while counting votes");
    }

    $Conf->qe("unlock tables", $while);

    // complain about reduced tags
    if (count($vreduced) > 0) {
	ksort($vreduced);
	$vtext = array();
	$q = "";
	foreach ($vreduced as $k => $v) {
	    $vtext[] = "<a href=\"search$ConfSiteSuffix?q=rorder:$k&amp;showtags=1\">" . htmlspecialchars($k) . "</a>";
	    $q .= ($q === "" ? "" : "+") . "rorder:$k";
	}
	defappend($Error["tags"], "You exhausted your vote allotment for " . textArrayJoin($vtext) . ".  You may want to change <a href=\"search$ConfSiteSuffix?q=$q&amp;showtags=1\">your other votes</a> and try again.<br />\n");
    }

    $modeexplanation = array("so" => "define ordered", "ao" => "add ordered", "sos" => "define sequential", "aos" => "add sequential", "d" => "remove", "da" => "clear twiddle", "s" => "define", "a" => "add", "p" => "set");
    $Conf->log("Tag " . $modeexplanation[$mode] . ": " . join(", ", $tags), $Me, $pids);

    return true;
}

function tagsToText($prow, $makeUrls, $contact, $drop = false,
		    $highlight = false) {
    global $Conf, $ConfSiteSuffix;
    $t = defval($prow, "paperTags", "");
    if ($drop || $makeUrls !== false)
	$vtags = voteTags();
    // Who has voted, and how much did they vote?
    if ($makeUrls !== false && count($vtags) > 0) {
	$vote = array();
	foreach (preg_split('/\s+/', $t) as $tag)
	    if (($p = strpos($tag, "~")) !== false) {
		if (($hash = strpos($tag, "#")) === false)
		    $hash = strlen($tag);
		$base = substr($tag, $p + 1, $hash - ($p + 1));
		if (($amt = substr($tag, $hash + 1)) > 0)
		    $vote[$base][substr($tag, 0, $p)] = $amt;
	    }
    }

    // clean #0 and our twiddle tags, remove others' twiddle tags
    $t = str_replace("#0", "", $t);
    if (strpos($t, "~") !== false) {
	$t = str_replace(" " . $contact->contactId . "~", " ~", " $t ");
	if ($contact->privChair)
	    $t = trim(preg_replace('/ \d+~\S+/', '', $t));
	else
	    $t = trim(preg_replace('/ [\d~]+~\S+/', '', $t));
    }

    // drop chair and vote tags when appropriate
    if ($drop && !$contact->privChair && count(($chtags = chairTags())) > 0) {
	foreach ($chtags as $tag => $v)
	    $t = trim(preg_replace("/ " . preg_quote($tag) . "(#-?\\d*)? /", " ", " $t "));
    }
    if ($drop && count($vtags) > 0) {
	foreach ($vtags as $tag => $v)
	    $t = trim(preg_replace("/ " . preg_quote($tag) . "(#-?\\d*)? /", " ", " $t "));
    }
    if ($drop && $Conf->setting("tag_rank"))
	$t = trim(preg_replace("/ " . preg_quote($Conf->settingText("tag_rank")) . "(#-?\\d*)? /", " ", " $t "));

    if ($makeUrls === false || $t === "")
	return $t;

    // decorate with URL matches
    if (!is_string($makeUrls))
	$makeUrls = "";
    if ($highlight !== false && ($pos = strpos($highlight, "~")) !== false)
	$highlight = substr($highlight, $pos);
    $tt = "";
    foreach (preg_split('/\s+/', $t) as $tag) {
	$base = tagBase($tag);
	if (isset($vtags[$base])) {
	    $link = "rorder";
	    $v = array();
	    foreach (pcMembers() as $pcm)
		if (($count = defval($vote[$base], $pcm->contactId, 0)) > 0)
		    $v[] = contactNameHtml($pcm) . ($count > 1 ? " ($count)" : "");
	    if (!$contact->privChair && $prow->conflictType > 0)
		$title = "PC votes hidden";
	    else
		$title = "PC votes: " . join(", ", $v);
	} else if ($base[0] === "~" && isset($vtags[substr($base, 1)])) {
	    $link = "rorder";
	    $title = "Vote search";
	} else {
	    $link = ($base === $tag ? "tag" : "order");
	    $title = "Tag search";
	}
	$tx = "<a class='q' href=\"${makeUrls}search$ConfSiteSuffix?q=$link:$base\" title=\"$title\">" . $base . "</a>" . substr($tag, strlen($base));
	if ($highlight !== false && $highlight == $base)
	    $tt = "<strong>" . $tx . "</strong> " . $tt;
	else
	    $tt .= $tx.  " ";
    }
    return rtrim($tt);
}
