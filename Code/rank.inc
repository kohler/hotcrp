<?php
// rank.inc -- HotCRP helper functions for dealing with ranks
// HotCRP is Copyright (c) 2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// compare two vote sets
function _sortRankIRV($a, $b) {
    for ($i = 0; $i < count($a); ++$i)
	if ($a[$i] != $b[$i])
	    return $a[$i] - $b[$i];
    return 0;
}

// global rank calculation by instant runoff voting
function setRankIRV($papersel, $tag) {
    global $Conf, $orderedTagStepMap;
    require_once("tags.inc");

    // delete global ranks
    $while = "while computing global ranking";
    $Conf->qe("delete from PaperTag where tag='" . sqlq($tag) . "'", $while);
    if (!count($papersel))
	return;

    // load current ranks
    // $userrank maps user => preference => papers
    $result = $Conf->qe("select paperId, tag, tagIndex from PaperTag where tag like '%~" . sqlq_for_like($tag) . "' and paperId in (" . join(",", $papersel) . ")", $while);
    $userrank = array();
    $len = strlen($tag) + 1;
    while (($row = edb_row($result))) {
	$l = (int) substr($row[1], 0, strlen($row[1]) - $len);
	$userrank[$l][(int) $row[2]][] = (int) $row[0];
    }

    // $regrank maps user => papers in rank order;
    //		papers with same preference are shuffled 
    foreach ($userrank as $user => &$uarr) {
	ksort($uarr);
	foreach ($uarr as $pref => &$papers) {
	    if (count($papers) > 1)
		shuffle($papers);
	    foreach ($papers as $p)
		$regrank[$user][] = $p;
	}
    }

    // How many rank each paper?  #1 votes count the most, then #2, and so
    // forth.  Compute in base (# of users).
    $papervotes = array_combine($papersel, array_fill(0, count($papersel), array_fill(0, count($papersel), 0)));
    foreach ($regrank as $user => &$pap)
	foreach ($pap as $ordinal => $p)
	    $papervotes[$p][$ordinal]++;
    // Add a random final number of votes, so no papers are equal.
    $finalvote = range(0, count($papersel));
    shuffle($finalvote);
    foreach ($papervotes as $p => &$votes)
	$votes[count($papersel)] = array_pop($finalvote);

    // now calculate ranks
    $paperrevrank = array();
    while (count($papervotes)) {
	// sort by increasing number of top votes
	uasort($papervotes, "_sortRankIRV");
	// the loser is the first paper in the sort order
	$loser = key($papervotes);
	//$Conf->infoMsg("choose $loser");
	$paperrevrank[] = $loser;
	unset($papervotes[$loser]);
	// redistribute votes for the loser
	foreach ($regrank as $user => &$pap)
	    if (($pos = array_search($loser, $pap)) !== false) {
		array_splice($pap, $pos, 1);
		while ($pos < count($pap)) {
		    $papervotes[$pap[$pos]][$pos+1]--;
		    $papervotes[$pap[$pos]][$pos]++;
		    $pos++;
		}
	    }
    }

    // do the insert
    $firstrank = 0;
    $values = array();
    $sqltag = sqlq($tag);
    while (count($paperrevrank)) {
	$p = array_pop($paperrevrank);
	$firstrank += orderedTagStep("ao");
	$values[] = "($p, '$sqltag', $firstrank)";
    }
    $result = $Conf->qe("insert into PaperTag (paperId, tag, tagIndex) values " . join(", ", $values), $while);

    if ($result)
	$Conf->confirmMsg("Ranks assigned.");
}

// global rank calculation by conversion of ranks to range values
function setRankByRange($papersel, $tag) {
    global $Conf, $orderedTagStepMap;
    require_once("tags.inc");

    // delete global ranks
    $while = "while computing global ranking";
    $Conf->qe("delete from PaperTag where tag='" . sqlq($tag) . "'", $while);
    if (!count($papersel))
	return;

    // load current ranks
    // $userrank maps user => [preference, paper]
    // also $minuserrank, $maxuserrank
    $result = $Conf->qe("select paperId, tag, tagIndex from PaperTag where tag like '%~" . sqlq_for_like($tag) . "' and paperId in (" . join(",", $papersel) . ")", $while);
    $userrank = array();
    $minuserrank = array();
    $maxuserrank = array();
    $len = strlen($tag) + 1;
    while (($row = edb_row($result))) {
	$l = (int) substr($row[1], 0, strlen($row[1]) - $len);
	$userrank[$l][] = array((int) $row[2], (int) $row[0]);
	if (count($userrank[$l]) == 1)
	    $minuserrank[$l] = $maxuserrank[$l] = (int) $row[2];
	else {
	    $minuserrank[$l] = min($minuserrank[$l], (int) $row[2]);
	    $maxuserrank[$l] = max($maxuserrank[$l], (int) $row[2]);
	}
    }

    // map ranks to ranges
    $paperrange = array_fill(0, count($papersel), 0);
    $paperrangecount = array_fill(0, count($papersel), 0);
    foreach ($userrank as $user => &$uarr)
	foreach ($uarr as $pp) {
	    $paperrange[$pp[1]] +=
		($maxuserrank[$l] - $pp[0] - 0.5)
		/ ($maxuserrank[$l] - $minuserrank[$l] + 1);
	    $paperrangecount[$pp[1]]++;
	}

    // ranges to averages, then sort
    foreach ($paperrange as $p => &$range)
	if ($paperrangecount[$p])
	    $range /= $paperrangecount[$p];
    arsort($paperrange);

    // do the insert, map all ranges to 1-99
    $values = array();
    $sqltag = sqlq($tag);
    foreach ($paperrange as $p => $range)
	$values[] = "($p, '$sqltag', " . (int) max(99 - 99 * $range, 1) . ")";
    $result = $Conf->qe("insert into PaperTag (paperId, tag, tagIndex) values " . join(", ", $values), $while);

    if ($result)
	$Conf->confirmMsg("Ranks assigned.");
}

// compare two [preference, paper] pairs, pick the smaller preference
function _sortRankPreferencePaper($a, $b) {
    return ($a[0] < $b[0] ? -1 : ($a[0] == $b[0] ? 0 : 1));
}

function _sortRankBySchulzeWeakness($a, $b) {
    if ($a[0] != $b[0])
	return ($a[0] < $b[0] ? -1 : 1);
    return ($a[1] > $b[1] ? -1 : ($a[1] == $b[1] ? 0 : 1));
}

function _setRankBySchulzeDefeats(&$papersel, &$pref) {
    // $defeat maps paper1 => paper2 => true
    // first initialize with preferences
    $defeat = array();
    for ($i = 0; $i < count($papersel); ++$i) {
	$p1 = $papersel[$i];
	for ($j = $i + 1; $j < count($papersel); ++$j) {
	    $p2 = $papersel[$j];
	    if ($pref[$p1][$p2] > $pref[$p2][$p1])
		$defeat[$p1][$p2] = true;
	    else if ($pref[$p1][$p2] < $pref[$p2][$p1])
		$defeat[$p2][$p1] = true;
	}
    }

    // make defeats transitive
    do {
	$changed = false;
	foreach ($papersel as $p1) {
	    if (!isset($defeat[$p1]))
		continue;
	    $olddefeats = $defeat[$p1];
	    foreach ($olddefeats as $p2 => $xx) {
		if (!isset($defeat[$p2]))
		    continue;
		foreach ($defeat[$p2] as $p3 => $xxx)
		    if ($p3 != $p1 && !isset($defeat[$p1][$p3]))
			$defeat[$p1][$p3] = $changed = true;
	    }
	}
    } while ($changed);

    return $defeat;
}

function _setRankBySchulzeStep(&$papersel, &$pref, &$defeat,
			       &$rank, &$currank) {
    //error_log("SET " . join(",", $papersel));
    // base case: only one paper
    if (count($papersel) == 1) {
	$currank += orderedTagStep("ao");
	$rank[$papersel[0]] = $currank;
	return;
    }

    // find Schwartz set, which contains anyone who suffers no
    // unambiguous defeats.
    // Nonschwartz = { a | \exists b : (not a defeats b) and (b defeats a) }.
    $schwartz = array_flip($papersel);
    $nonschwartz = array();
    foreach ($defeat as $p1 => &$d) {
	foreach ($d as $p2 => $x)
	    if (isset($schwartz[$p1]) && isset($schwartz[$p2])
		&& !isset($defeat[$p2][$p1])) {
		$nonschwartz[$p2] = true;
		unset($schwartz[$p2]);
	    }
    }
    //error_log("SCH " . join(",", array_keys($schwartz)));
    assert(count($schwartz) != 0);
    if (count($schwartz) == 0)
	exit;

    // $weakness measures weaknesses of defeats within the Schwartz set
    $weakness = array();
    foreach ($schwartz as $p1 => $x) {
	foreach ($schwartz as $p2 => $xx)
	    if (isset($defeat[$p1][$p2]))
		$weakness["$p1 $p2"] = array($pref[$p1][$p2], $pref[$p2][$p1]);
    }

    if (count($weakness) == 0) {
	// if no defeats, end with a tie
	$currank += orderedTagStep("ao");
	foreach ($schwartz as $p1 => $x)
	    $rank[$p1] = $currank;

    } else {
	// remove the preferences corresponding to the weakest defeat
	// and try again
	uasort($weakness, "_sortRankBySchulzeWeakness");
	$thisweakness = null;
	while (1) {
	    if ($thisweakness !== null
		&& _sortRankBySchulzeWeakness($thisweakness, current($weakness)) != 0)
		break;
	    $thisweakness = current($weakness);
	    $thispair = explode(" ", key($weakness));
	    $pref[(int) $thispair[0]][(int) $thispair[1]] = 0;
	    $pref[(int) $thispair[1]][(int) $thispair[0]] = 0;
	    next($weakness);
	}

	$schwartz = array_keys($schwartz);
	$newdefeat = _setRankBySchulzeDefeats($schwartz, $pref);
	_setRankBySchulzeStep($schwartz, $pref, $newdefeat, $rank, $currank);
    }

    // recurse on the non-Schwartz set
    if (count($nonschwartz) != 0) {
	$nonschwartz = array_keys($nonschwartz);
	_setRankBySchulzeStep($nonschwartz, $pref, $defeat, $rank, $currank);
    }
}

// global rank calculation by the Schulze method
function setRankBySchulze($papersel, $tag) {
    global $Conf, $orderedTagStepMap;
    require_once("tags.inc");

    // delete global ranks
    $while = "while computing global ranking";
    $Conf->qe("delete from PaperTag where tag='" . sqlq($tag) . "'", $while);
    if (!count($papersel))
	return;

    // load current ranks
    // $userrank maps user => [preference, paper]
    $result = $Conf->qe("select paperId, tag, tagIndex from PaperTag where tag like '%~" . sqlq_for_like($tag) . "' and paperId in (" . join(",", $papersel) . ")", $while);
    $userrank = array();
    $len = strlen($tag) + 1;
    while (($row = edb_row($result))) {
	$l = (int) substr($row[1], 0, strlen($row[1]) - $len);
	$userrank[$l][] = array((int) $row[2], (int) $row[0]);
    }

    // $pref maps paper1 => paper2 => # users preferring paper1 to paper2
    $anypref = array_combine($papersel, array_fill(0, count($papersel), 0));
    $pref = array_combine($papersel, array_fill(0, count($papersel), $anypref));
    foreach ($userrank as $user => &$uarr) {
	usort($uarr, "_sortRankPreferencePaper");
	for ($i = 0; $i < count($uarr); ++$i) {
	    $j = $i + 1;
	    while ($j < count($uarr) && $uarr[$i][0] == $uarr[$j][0])
		++$j;
	    for (; $j < count($uarr); ++$j) {
		++$pref[$uarr[$i][1]][$uarr[$j][1]];
		++$anypref[$uarr[$i][1]];
		++$anypref[$uarr[$j][1]];
	    }
	}
    }

    // run Schulze
    $currank = 0;
    $rank = array();
    $defeat = _setRankBySchulzeDefeats($papersel, $pref);
    _setRankBySchulzeStep($papersel, $pref, $defeat, $rank, $currank);

    // correct output rankings for papers with no input rankings
    // (set them to 999)
    $norank = 999;
    while ($norank < $currank + 5)
	$norank = $norank * 10 + 9;
    foreach ($papersel as $p)
	if ($anypref[$p] == 0)
	    $rank[$p] = $norank;

    // do the insert
    $values = array();
    $sqltag = sqlq($tag);
    foreach ($rank as $p => $r)
	$values[] = "($p, '$sqltag', $r)";
    $result = $Conf->qe("insert into PaperTag (paperId, tag, tagIndex) values " . join(", ", $values), $while);

    if ($result)
	$Conf->confirmMsg("Ranks assigned.");
}
