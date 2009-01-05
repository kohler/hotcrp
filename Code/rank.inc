<?php
// rank.inc -- HotCRP helper functions for dealing with ranks
// HotCRP is Copyright (c) 2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE
require_once("tags.inc");

class PaperRank {

    var $tag;
    var $papersel;
    var $userrank;
    var $papershuffle;
    var $pref;
    var $anypref;
    var $rank;
    var $currank;

    function PaperRank($tag, $papersel) {
	global $Conf;
	$this->tag = $tag;
	$this->papersel = $papersel;
	$this->userrank = array();

	// generate random order for paper comparisons
	if (count($papersel)) {
	    $range = range(0, count($papersel) - 1);
	    shuffle($range);
	    $this->papershuffle = array_combine($papersel, $range);
	} else
	    $this->papershuffle = array();

	// delete global ranks
	$while = "while computing global ranking";
	$Conf->qe("delete from PaperTag where tag='" . sqlq($tag) . "'", $while);

	// load current ranks: $userrank maps user => [rank, paper]
	$result = $Conf->qe("select paperId, tag, tagIndex from PaperTag where tag like '%~" . sqlq_for_like($tag) . "' and paperId in (" . join(",", $papersel) . ")", $while);
	$len = strlen($tag) + 1;
	while (($row = edb_row($result))) {
	    $l = (int) substr($row[1], 0, strlen($row[1]) - $len);
	    $this->userrank[$l][] = array((int) $row[2], (int) $row[0]);
	}

	// sort $userrank[$user] by descending rank order
	foreach ($this->userrank as $user => &$ranks)
	    usort($ranks, array($this, "_comparUserrank"));

	$this->rank = array();
	$this->currank = 0;
    }

    function _comparUserrank($a, $b) {
	if ($a[0] != $b[0])
	    return ($a[0] < $b[0] ? -1 : 1);
	else if ($a[1] != $b[1])
	    return ($this->papershuffle[$a[1]] < $this->papershuffle[$b[1]] ? -1 : 1);
	else
	    return 0;
    }

    function _nextRank() {
	$this->currank += orderedTagStep("ao");
	return $this->currank;
    }


    // compare two vote sets
    function _comparRankIRV($a, $b) {
	for ($i = 0; $i < count($a); ++$i)
	    if ($a[$i] != $b[$i])
		return $a[$i] < $b[$i] ? -1 : 1;
	return 0;
    }

    function irv() {
	global $Conf;
	if (!count($this->papersel))
	    return;

	// $regrank maps user => papers in rank order;
	//		 papers with same rank are shuffled
	foreach ($this->userrank as $user => &$ranks) {
	    foreach ($ranks as $rr)
		$regrank[$user][] = $rr[1];
	}

	// How many rank each paper?  #1 votes count the most, then #2, and so
	// forth.  Compute in base (# of users).
	$papervotes = array_combine($this->papersel, array_fill(0, count($this->papersel), array_fill(0, count($this->papersel), 0)));
	foreach ($regrank as $user => &$pap) {
	    foreach ($pap as $ordinal => $p)
		$papervotes[$p][$ordinal]++;
	}
	// Add a random final number of votes, so no papers are equal.
	foreach ($papervotes as $p => &$votes)
	    $votes[count($this->papersel)] = $this->papershuffle[$p];

	// now calculate ranks
	while (count($papervotes)) {
	    // sort by increasing number of top votes
	    uasort($papervotes, array("PaperRank", "_comparRankIRV"));
	    // the loser is the first paper in the sort order
	    $loser = key($papervotes);
	    //$Conf->infoMsg("choose $loser");
	    $this->rank[$loser] = $this->_nextRank();
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
    }


    // global rank calculation by conversion of ranks to range values
    function rangevote() {
	global $Conf;

	// calculate $minuserrank, $maxuserrank
	$minuserrank = $maxuserrank = array();
	foreach ($this->userrank as $user => &$ranks) {
	    foreach ($ranks as $rr) {
		if (defval($minuserrank, $user, $rr[0] + 1) < $rr[0])
		    $minuserrank[$user] = $rr[0];
		if (defval($maxuserrank, $user, $rr[0] - 1) < $rr[0])
		    $maxuserrank[$user] = $rr[0];
	    }
	}

	// map ranks to ranges
	$paperrange = array_fill(0, count($this->papersel), 0);
	$paperrangecount = array_fill(0, count($this->papersel), 0);
	foreach ($this->userrank as $user => &$ranks)
	    foreach ($ranks as $rr) {
		$paperrange[$rr[1]] +=
		    ($maxuserrank[$user] - $rr[0] + 0.5)
		    / ($maxuserrank[$user] - $minuserrank[$user] + 1);
		$paperrangecount[$rr[1]]++;
	    }

	// ranges to averages
	foreach ($paperrange as $p => $range) {
	    if ($paperrangecount[$p])
		$range /= $paperrangecount[$p];
	    $this->rank[$p] = (int) max(99 - 99 * $range, 1);
	}
    }


    // global rank calculation by range values (1-99)
    function rawrangevote() {
	global $Conf;

	// calculate $minuserrank, $maxuserrank
	$minuserrank = $maxuserrank = array();
	foreach ($this->userrank as $user => &$ranks) {
	    foreach ($ranks as &$rr) {
		if ($rr[0] >= 1)
		    $rr[0] = min($rr[0], 99);
	    }
	}

	// map ranks to ranges
	$paperrange = array_fill(0, count($this->papersel), 0);
	$paperrangecount = array_fill(0, count($this->papersel), 0);
	foreach ($this->userrank as $user => &$ranks)
	    foreach ($ranks as $rr) {
		$paperrange[$rr[1]] += $rr[0];
		$paperrangecount[$rr[1]]++;
	    }

	// ranges to averages
	foreach ($paperrange as $p => $range) {
	    if ($paperrangecount[$p]) {
		$range = ($range + 0.5) / $paperrangecount[$p];
		$this->rank[$p] = min((int) $range, 99);
	    } else
		$this->rank[$p] = 99;
	}
    }


    function _calculatePrefs() {
	$this->anypref = array_combine($this->papersel, array_fill(0, count($this->papersel), 0));
	$this->pref = array_combine($this->papersel, array_fill(0, count($this->papersel), $this->anypref));
	foreach ($this->userrank as $user => &$ranks) {
	    for ($i = 0; $i < count($ranks); ++$i) {
		$j = $i + 1;
		while ($j < count($ranks) && $ranks[$i][0] == $ranks[$j][0])
		    ++$j;
		for (; $j < count($ranks); ++$j) {
		    ++$this->pref[$ranks[$i][1]][$ranks[$j][1]];
		    ++$this->anypref[$ranks[$i][1]];
		    ++$this->anypref[$ranks[$j][1]];
		}
	    }
	}
    }

    function _reachableClosure(&$reachable, &$papersel) {
	do {
	    $changed = false;
	    foreach ($papersel as $p1) {
		if (!isset($reachable[$p1]))
		    continue;
		$r = $reachable[$p1];
		foreach ($r as $p2 => $xx) {
		    if (!isset($reachable[$p2]))
			continue;
		    foreach ($reachable[$p2] as $p3 => $xxx)
			if ($p3 != $p1 && !isset($reachable[$p1][$p3]))
			    $reachable[$p1][$p3] = $changed = true;
		}
	    }
	} while ($changed);
    }

    function _reachableClosure2(&$reachable, &$papersel, $pairs) {
	while (count($pairs)) {
	    list($p1, $p2) = array_pop($pairs);
	    $reachable[$p1][$p2] = true;
	    foreach ($papersel as $px) {
		if (isset($reachable[$px][$p1]) && !isset($reachable[$px][$p2]))
		    array_push($pairs, array($px, $p2));
		if (isset($reachable[$p2][$px]) && !isset($reachable[$p1][$px]))
		    array_push($pairs, array($p1, $px));
	    }
	}
    }

    function _calculateDefeats(&$papersel) {
	// $defeat maps paper1 => paper2 => true
	// first initialize with preferences
	$defeat = array();
	for ($i = 0; $i < count($papersel); ++$i) {
	    $p1 = $papersel[$i];
	    for ($j = $i + 1; $j < count($papersel); ++$j) {
		$p2 = $papersel[$j];
		if ($this->pref[$p1][$p2] > $this->pref[$p2][$p1])
		    $defeat[$p1][$p2] = true;
		else if ($this->pref[$p1][$p2] < $this->pref[$p2][$p1])
		    $defeat[$p2][$p1] = true;
	    }
	}

	$this->_reachableClosure($defeat, $papersel);
	return $defeat;
    }

    function _calculateSchwartz(&$schwartz, &$nonschwartz, &$defeat, &$papersel) {
	// find Schwartz set, which contains anyone who suffers no
	// unambiguous defeats
	$work = array_flip($papersel);
	$schwartz = $nonschwartz = array();
	while (count($work)) {
	    reset($work);
	    $p = key($work);

	    // find all papers defeated by $p, set $circular if they defeat $p
	    $active = array($p);
	    $pdefeat = array();
	    $circular = false;
	    while (count($active)) {
		$px = array_pop($active);
		$pdefeat[$px] = true;
		if (isset($defeat[$px]))
		    foreach ($defeat[$px] as $py => $x) {
			if (!isset($pdefeat[$py])
			    && (isset($work[$py]) || isset($schwartz[$py])))
			    array_push($active, $py);
			if ($py == $p)
			    $circular = true;
		    }
	    }

	    // if an element not in $pdefeat defeats $p, nonschwartz
	    $inschwartz = true;
	    foreach ($papersel as $px)
		if (!isset($pdefeat[$px]) && isset($defeat[$px][$p])) {
		    $inschwartz = false;
		    break;
		}

	    // add to schwartz if proper
	    if ($inschwartz) {
		$schwartz[$p] = true;
		if ($circular)
		    $pdefeat = array();
		unset($pdefeat[$p]);
		unset($work[$p]);
	    }

	    // remove from nonschwartz
	    foreach ($pdefeat as $px => $x) {
		$nonschwartz[$px] = true;
		unset($work[$px]);
	    }
	}

	$schwartz = array_keys($schwartz);
	$nonschwartz = array_keys($nonschwartz);
	//error_log("SCH " . join(",", $schwartz) . " (" . join(",",$papersel) . ")");
	assert(count($schwartz) != 0);
	if (count($schwartz) == 0)
	    exit;
    }

    function _comparWeakness($a, $b) {
	if ($a[0] != $b[0])
	    return ($a[0] < $b[0] ? -1 : 1);
	if ($a[1] != $b[1])
	    return ($a[1] > $b[1] ? -1 : 1);
	return 0;
    }

    function _comparStrength($a, $b) {
	if ($a[0] != $b[0])
	    return ($a[0] > $b[0] ? -1 : 1);
	if ($a[1] != $b[1])
	    return ($a[1] < $b[1] ? -1 : 1);
	return 0;
    }

    function _schulzeStep(&$papersel, &$defeat) {
	//error_log("SET " . join(",", $papersel));
	// base case: only one paper
	if (count($papersel) == 1) {
	    $this->rank[$papersel[0]] = $this->_nextRank();
	    return;
	}

	$this->_calculateSchwartz($schwartz, $nonschwartz, $defeat, $papersel);

	// $weakness measures weaknesses of defeats within the Schwartz set
	$weakness = array();
	foreach ($schwartz as $p1)
	    foreach ($schwartz as $p2) {
		$pref12 = $this->pref[$p1][$p2];
		$pref21 = $this->pref[$p2][$p1];
		if ($pref12 > $pref21)
		    $weakness["$p1 $p2"] = array($pref12, $pref21);
	    }

	if (count($weakness) == 0) {
	    // if no defeats, end with a tie
	    $nextrank = $this->_nextRank();
	    foreach ($schwartz as $p1)
		$this->rank[$p1] = $nextrank;

	} else {
	    // remove the preferences corresponding to the weakest defeat
	    // and try again
	    uasort($weakness, array($this, "_comparWeakness"));
	    $thisweakness = null;
	    while (1) {
		if ($thisweakness !== null
		    && $this->_comparWeakness($thisweakness, current($weakness)) != 0)
		    break;
		$thisweakness = current($weakness);
		list($x, $y) = explode(" ", key($weakness));
		//error_log("... ${x}d$y " . $this->pref[(int) $x][(int) $y] . "," . $this->pref[(int) $y][(int) $x]);
		$this->pref[(int) $x][(int) $y] = 0;
		$this->pref[(int) $y][(int) $x] = 0;
		next($weakness);
	    }

	    $newdefeat = $this->_calculateDefeats($schwartz);
	    $this->_schulzeStep($schwartz, $newdefeat);
	}

	// recurse on the non-Schwartz set
	if (count($nonschwartz) != 0)
	    $this->_schulzeStep($nonschwartz, $defeat);
    }

    // global rank calculation by the Schulze method
    function schulze() {
	$this->_calculatePrefs();

	// run Schulze
	$defeat = $this->_calculateDefeats($this->papersel);
	$this->_schulzeStep($this->papersel, $defeat);

	// correct output rankings for papers with no input rankings
	// (set them to 999)
	$norank = 999;
	while ($norank < $this->currank + 5)
	    $norank = $norank * 10 + 9;
	foreach ($this->papersel as $p)
	    if ($this->anypref[$p] == 0)
		$this->rank[$p] = $norank;
    }



    // global rank calculation by CIVS Ranked Pairs

    function _includePairs(&$defeat, &$reachable, &$adddefeat) {
	foreach ($adddefeat as $x)
	    $defeat[$x[0]][$x[1]] = true;
	$this->_reachableClosure2($reachable, $this->papersel, $adddefeat);
	$adddefeat = array();
    }

    function _comparPreferenceAgainst($a, $b) {
	if ($a[1] != $b[1])
	    return $a[1] < $b[1] ? -1 : 1;
	if ($a[0] != $b[0])
	    return $this->papershuffle[$a[0]] < $this->papershuffle[$b[0]] ? 1 : -1;
	return 0;
    }

    function _civsrpStep(&$papersel, &$defeat) {
	//error_log("SET " . join(",", $papersel));
	// base case: only one paper
	if (count($papersel) == 1) {
	    $this->rank[$papersel[0]] = $this->_nextRank();
	    return;
	}

	$this->_calculateSchwartz($schwartz, $nonschwartz, $defeat, $papersel);

	// $prefagainst measures strongest preferences against papers in the
	// Schwartz set, from preferences among the Schwartz set
	$prefagainst = array();
	foreach ($schwartz as $p2) {
	    $px = 0;
	    foreach ($schwartz as $p1)
		if (isset($this->pref[$p1][$p2]))
		    $px = max($px, $this->pref[$p1][$p2]);
	    $prefagainst[] = array($p2, $px);
	}
	usort($prefagainst, array($this, "_comparPreferenceAgainst"));

	// rank the Schwartz set
	$px = -1;
	foreach ($prefagainst as $pa) {
	    if ($pa[1] != $px) {
		$currank = $this->_nextRank();
		$px = $pa[1];
	    }
	    $this->rank[$pa[0]] = $currank;
	}

	// recurse on the non-Schwartz set
	if (count($nonschwartz) != 0)
	    $this->_civsrpStep($nonschwartz, $defeat);
    }

    function civsrp() {
	// calculate preferences
	$this->_calculatePrefs();

	// create and sort preference pairs
	$strength = array();
	foreach ($this->pref as $p1 => &$p1pref) {
	    foreach ($p1pref as $p2 => $pref12) {
		$pref21 = $this->pref[$p2][$p1];
		if ($pref12 > $pref21)
		    $strength["$p1 $p2"] = array($pref12, $pref21);
	    }
	}
	uasort($strength, array($this, "_comparStrength"));

	// add them to the graph
	$defeat = array();
	$reachable = array();
	$adddefeat = array();
	foreach ($strength as $k => $value) {
	    if (count($adddefeat) && $this->_comparStrength($lastvalue, $value))
		$this->_includePairs($defeat, $reachable, $adddefeat);
	    list($p1, $p2) = explode(" ", $k);
	    if (!isset($reachable[$p2][$p1]))
		$adddefeat[] = array($p1, $p2);
	    $lastvalue = $value;
	}
	if (count($adddefeat))
	    $this->_includePairs($defeat, $reachable, $adddefeat);

	// run CIVS-RP
	$this->_civsrpStep($this->papersel, $defeat);

	// correct output rankings for papers with no input rankings
	// (set them to 999)
	$norank = 999;
	while ($norank < $this->currank + 5)
	    $norank = $norank * 10 + 9;
	foreach ($this->papersel as $p)
	    if ($this->anypref[$p] == 0)
		$this->rank[$p] = $norank;
    }


    // save calculated ranks
    function save() {
	global $Conf;
	$sqltag = sqlq($this->tag);
	$values = array();
	foreach ($this->rank as $p => $rank)
	    $values[] = "($p, '$sqltag', $rank)";
	$result = $Conf->qe("insert into PaperTag (paperId, tag, tagIndex) values " . join(", ", $values), "while saving ranks");
	if ($result)
	    $Conf->confirmMsg("Ranks saved.");
    }

}
