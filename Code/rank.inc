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
