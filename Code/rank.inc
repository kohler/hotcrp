<?php
// rank.inc -- HotCRP helper functions for dealing with ranks
// HotCRP is Copyright (c) 2009-2011 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE
require_once("tags.inc");

class PaperRank {

    var $tag;
    var $dest_tag;
    var $ordertype;
    var $papersel;
    var $userrank;
    var $papershuffle;
    var $pref;
    var $anypref;
    var $voters;
    var $totalpref;
    var $deletedpref;
    var $rank;
    var $currank;
    var $info_printed;

    function PaperRank($source_tag, $dest_tag, $papersel, $strict) {
	global $Conf;
	$this->dest_tag = $dest_tag;
	$this->ordertype = ($strict ? "aos" : "ao");
	$this->papersel = $papersel;
	$this->userrank = array();
	$this->info_printed = false;

	// generate random order for paper comparisons
	if (count($papersel)) {
	    $range = range(0, count($papersel) - 1);
	    shuffle($range);
	    $this->papershuffle = array_combine($papersel, $range);
	} else
	    $this->papershuffle = array();

	// delete global ranks
	$while = "while computing global ranking";
	$Conf->qe("delete from PaperTag where tag='" . sqlq($dest_tag) . "'", $while);

	// load current ranks: $userrank maps user => [rank, paper]
	$result = $Conf->qe("select paperId, tag, tagIndex from PaperTag where tag like '%~" . sqlq_for_like($source_tag) . "' and paperId in (" . join(",", $papersel) . ")", $while);
	$len = strlen($source_tag) + 1;
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
	$this->currank += orderedTagStep($this->ordertype);
	return $this->currank;
    }

    function _info() {
	$n = count($this->rank);
	if (!count($this->papersel)
	    || ($n >= count($this->papersel) && !$this->info_printed))
	    return;
	$pct = round($n / count($this->papersel) * 100);
	if (!$this->info_printed) {
	    echo "<div id='foldrankcalculation' class='foldc'><div class='fn info'>Calculating ranks; this can take a while.  <span id='rankpercentage'>$pct</span>% of ranks assigned<span id='rankdeletedpref'></span>.</div></div>";
	    $this->info_printed = true;
	}
	if ($n < count($this->papersel)) {
	    echo "<script type='text/javascript'>document.getElementById('rankpercentage').innerHTML = '$pct'";
	    if ($this->deletedpref > 0)
		echo ";document.getElementById('rankdeletedpref').innerHTML = ', ", round(($this->totalpref - $this->deletedpref) / $this->totalpref * 100), "% of preferences remain'";
	    echo "</script>\n";
	} else
	    echo "<script type='text/javascript'>document.getElementById('foldrankcalculation').className = 'foldo'</script>\n";
	flush();
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
	$this->voters = $this->anypref;
	$this->pref = array_combine($this->papersel, array_fill(0, count($this->papersel), $this->anypref));
	$this->totalpref = 0;
	$this->deletedpref = 0;

	foreach ($this->userrank as $user => &$ranks) {
	    for ($i = 0; $i < count($ranks); ++$i) {
		list($rank, $p1) = $ranks[$i];
		++$this->voters[$p1];
		$j = $i + 1;
		while ($j < count($ranks) && $ranks[$j][0] == $rank) {
		    $p2 = $ranks[$j][1];
		    ++$j;
		}
		for (; $j < count($ranks); ++$j) {
		    $p2 = $ranks[$j][1];
		    ++$this->pref[$p1][$p2];
		    ++$this->anypref[$p1];
		    ++$this->anypref[$p2];
		    ++$this->totalpref;
		}
	    }
	}
    }

    function _reachableClosure(&$reachable, &$papersel) {
	$closure = array();
	// find transitive closure by repeated DFS: O(n^3)
	// destroys $reachable
	foreach ($reachable as $p1 => &$reach) {
	    $work = array_keys($reach);
	    while (count($work)) {
		$p2 = array_pop($work);
		$closure[$p1][$p2] = true;
		if (isset($reachable[$p2]))
		    foreach ($reachable[$p2] as $p3 => $x) {
			if (!isset($reach[$p3])) {
			    $reach[$p3] = true;
			    array_push($work, $p3);
			}
		    }
	    }
	}
	return $closure;
    }

    function _calculateDefeats(&$papersel) {
	// $defeat maps paper1 => paper2 => true
	// first initialize with preferences
	//$t0 = microtime(true);

	$defeat = array();
	for ($i = 0; $i < count($papersel); ++$i) {
	    $p1 = $papersel[$i];
	    for ($j = $i + 1; $j < count($papersel); ++$j) {
		$p2 = $papersel[$j];
		$pref12 = $this->pref[$p1][$p2];
		$pref21 = $this->pref[$p2][$p1];
		if ($pref12 > $pref21)
		    $defeat[$p1][$p2] = true;
		else if ($pref12 < $pref21)
		    $defeat[$p2][$p1] = true;
	    }
	}

	// $defeat maps paper1 => paper2 => true
	$defeat = $this->_reachableClosure($defeat, $papersel);

	//echo "<p>Defeat calc ", (microtime(true) - $t0), "</p>"; flush();
	return $defeat;
    }

    function _calculateSchwartz(&$schwartz, &$nonschwartz, &$defeat, &$papersel) {
	//$t0 = microtime(true);

	// find Schwartz set, which contains anyone who suffers no
	// unambiguous defeats
	$nonschwartz = array();
	for ($i = 0; $i < count($papersel); ++$i) {
	    $p1 = $papersel[$i];
	    for ($j = $i + 1; $j < count($papersel); ++$j) {
		$p2 = $papersel[$j];
		$d12 = isset($defeat[$p1]) && isset($defeat[$p1][$p2]);
		$d21 = isset($defeat[$p2]) && isset($defeat[$p2][$p1]);
		if ($d12 && !$d21)
		    $nonschwartz[$p2] = true;
		else if ($d21 && !$d12)
		    $nonschwartz[$p1] = true;
	    }
	}

	$schwartz = array();
	foreach ($papersel as $p1) {
	    if (!isset($nonschwartz[$p1]))
		$schwartz[] = $p1;
	}
	$nonschwartz = array_keys($nonschwartz);
	//error_log("SCH " . join(",", $schwartz) . " (" . join(",",$papersel) . ")");
	//echo "<p>Schwartz calc ", (microtime(true) - $t0), "</p>"; flush();
	assert(count($schwartz) != 0);
	if (count($schwartz) == 0)
	    exit;
    }

    function _comparWeakness($a, $b) {
	// This function is used to determine the weakest preference.  Schulze
	// will remove the weakest preference to attempt to break cycles.

	// If every voter voted on every candidate, the outcome of ranking
	// would not be sensitive to the weakness comparison function.
	// However, in the PC context, we expect most voters to express
	// preferences for a small subset of the candidates.  As a result the
	// outcome is quite sensitive to this comparison.

	// We expect that most good papers will have many voters, whereas bad
	// papers will tend to have fewer voters (because a bad paper won't
	// get additional reviews).  That argues that we should privilege
	// preferences involving an infrequently-reviewed paper.

	// The following algorithm ranks preferences by margins: in general,
	// the smaller margin is weaker.  (Thus, a 10-9 preference is
	// considered weaker than a 4-2 preference.  The alternative is
	// "winning votes", which would consider 4-2 weaker because it has
	// fewer winning votes.)  However, the algorithm scales the margins by
	// the minimum number of voters who expressed a preference in either
	// involved paper.  This deflates the margins for frequently-reviewed
	// papers and, as a result, preserves preferences for
	// infrequently-reviewed papers.

	// If scaled margins give a tie, we compare scaled winning votes, then
	// as a last resort, the preference held by more voters is considered
	// weaker.

	$aminvoters = $a[2];
	$bminvoters = $b[2];

	$awin_scaled = $a[0] / $aminvoters;
	$alose_scaled = $a[1] / $aminvoters;
	$bwin_scaled = $b[0] / $bminvoters;
	$blose_scaled = $b[1] / $bminvoters;

	$amargin = $awin_scaled - $alose_scaled;
	$bmargin = $bwin_scaled - $blose_scaled;

	if ($amargin != $bmargin)
	    return ($amargin < $bmargin ? -1 : 1);

	if ($awin_scaled != $bwin_scaled)
	    return ($awin_scaled < $bwin_scaled ? -1 : 1);

	if ($alose_scaled != $blose_scaled)
	    return ($alose_scaled > $blose_scaled ? -1 : 1);

	if ($aminvoters != $bminvoters)
	    return ($aminvoters > $bminvoters ? -1 : 1);

	return 0;
    }

    function _schulzeStep(&$stack) {
	//error_log("SET " . join(",", $papersel));
	list($papersel, $defeat) = array_pop($stack);

	// base case: only one paper
	if (count($papersel) == 1) {
	    $this->rank[$papersel[0]] = $this->_nextRank();
	    $this->_info();
	    return;
	}

	$this->_calculateSchwartz($schwartz, $nonschwartz, $defeat, $papersel);
	//echo "<p>S ", join(" ", $schwartz), "<br />NS ", join(" ", $nonschwartz), "</p>"; flush();

	// recurse on the non-Schwartz set second
	if (count($nonschwartz))
	    $stack[] = array($nonschwartz, $defeat);

	// $weakness measures weaknesses of defeats within the Schwartz set
	$weakness = array();
	foreach ($schwartz as $p1)
	    foreach ($schwartz as $p2) {
		$pref12 = $this->pref[$p1][$p2];
		$pref21 = $this->pref[$p2][$p1];
		if ($pref12 > $pref21) {
		    $minvoters = min($this->voters[$p1], $this->voters[$p2]);
		    $weakness["$p1 $p2"] = array($pref12, $pref21, $minvoters);
		}
	    }

	if (count($weakness) == 0) {
	    // if no defeats, end with a tie
	    $grouprank = 0;
	    foreach ($schwartz as $p1) {
		$nextrank = $this->_nextRank();
		$grouprank = ($grouprank ? $grouprank : $nextrank);
		$this->rank[$p1] = $grouprank;
	    }
	    $this->_info();

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
		//echo "DROP $x&gt;$y ", join(",", $thisweakness), "<br />"; flush();
		$this->pref[(int) $x][(int) $y] = 0;
		$this->pref[(int) $y][(int) $x] = 0;
		$this->deletedpref += $thisweakness[0] + $thisweakness[1];
		next($weakness);
	    }

	    $newdefeat = $this->_calculateDefeats($schwartz);
	    $stack[] = array($schwartz, $newdefeat);
	}
    }

    // global rank calculation by the Schulze method
    function schulze() {
	$this->_calculatePrefs();

	// run Schulze
	$defeat = $this->_calculateDefeats($this->papersel);
	$stack = array(array($this->papersel, $defeat));
	while (count($stack)) {
	    $this->_schulzeStep($stack);
	    $this->_info();
	}

	// correct output rankings for papers with no input rankings
	// (set them to 999)
	$norank = 999;
	while ($norank < $this->currank + 5)
	    $norank = $norank * 10 + 9;
	foreach ($this->papersel as $p)
	    if ($this->anypref[$p] == 0)
		$this->rank[$p] = $norank;
	$this->_info();
    }



    // global rank calculation by CIVS Ranked Pairs

    function _comparStrength($a, $b) {
	if ($a[0] != $b[0])
	    return ($a[0] > $b[0] ? -1 : 1);
	if ($a[1] != $b[1])
	    return ($a[1] < $b[1] ? -1 : 1);
	return 0;
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
	    $this->_info();
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
	    $nextrank = $this->_nextRank();
	    if ($pa[1] != $px) {
		$grouprank = $nextrank;
		$px = $pa[1];
	    }
	    $this->rank[$pa[0]] = $grouprank;
	}
	$this->_info();

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
	$this->_info();
    }


    // save calculated ranks
    function save() {
	global $Conf;
	$sqltag = sqlq($this->dest_tag);
	$values = array();
	foreach ($this->rank as $p => $rank)
	    $values[] = "($p, '$sqltag', $rank)";
	$result = $Conf->qe("insert into PaperTag (paperId, tag, tagIndex) values " . join(", ", $values), "while saving ranks");
	if ($result)
	    $Conf->confirmMsg("Ranks saved.");
    }

}
