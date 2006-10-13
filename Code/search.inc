<?php

$searchTags = array('ti:' => 'ti', 'title:' => 'ti',
		    'ab:' => 'ab', 'abstract:' => 'ab',
		    'au:' => 'au', 'author:' => 'au',
		    'co:' => 'co', 'collab:' => 'co', 'collaborators:' => 'co',
		    're:' => 're', 'reviewer:' => 're',
		    'tag:' => 'tag');

class PaperSearch {

    var $fields;
    var $allPapers;
    var $nonblind;
    var $matchPreg;
    
    function PaperSearch($fields, $allPapers = false, $me = null) {
	global $Conf;
	$t = 0;
	foreach (array('ti', 'ab', 'au', 'co', 're', 'tag') as $f)
	    $t += ($this->fields[$f] = (defval($fields[$f], 0) != 0));
	if ($t == 0)
	    $this->fields['ti'] = $this->fields['ab'] = $this->fields['au'] = $this->fields['co'] = $this->fields['re'] = $this->fields['tag'] = 1;
	$this->allPapers = $allPapers;
	$this->nonblind = false;
	if ($me != null) {
	    if (!$me->amAssistant() && $Conf->blindSubmission() > 1)
		$this->fields['au'] = $this->fields['co'] = -1;
	    if (!$me->amAssistant()) {
		$this->fields['re'] = -1;
		$this->allPapers = false;
		$this->nonblind = true;
	    }
	}
    }

    function searchQuery($str, $allWords) {
	$re = "";
	$auextra = ($this->nonblind ? "blind=0 and " : "");
	$qs = array();
	while (($str = ltrim($str)) != "") {
	    preg_match('/^("[^"]+"?|[a-z]+:"[^"]+"?|[^"\s]+)/', $str, $m);
	    $word = $m[0];
	    $str = substr($str, strlen($word));
	    
	    $special = null;
	    if (($colon = strpos($word, ':')) !== false) {
		$x = substr($word, 0, $colon + 1);
		if (isset($searchTags[$x])) {
		    $special = $searchTags[$x];
		    $word = substr($word, $colon + 1);
		}
	    }

	    if ($word[0] == '"')
		$word = str_replace('"', '', $word);

	    $re .= ($re == "" ? "" : "|") . preg_quote($word);
	    $word = preg_replace("/([%_\\\\])/", "\\\$1", $word);
	    $word = sqlq($word);
	    $q = "";
	    if ($special ? $special == "ti" : $this->fields['ti'] > 0)
		$q .= " or (title like '%$word%')";
	    if ($special ? $special == "ab" : $this->fields['ab'] > 0)
		$q .= " or (abstract like '%$word%')";
	    if ($special ? $special == "au" : $this->fields['au'] > 0)
		$q .= " or (${auextra}authorInformation like '%$word%')";
	    if ($special ? $special == "co" : $this->fields['co'] > 0)
		$q .= " or (${auextra}Paper.collaborators like '%$word%')";
	    if ($special ? $special == "re" : $this->fields['re'] > 0)
		$q .= " or (firstName like '%$word%') or (lastName like '%$word%') or (email like '%$word%')";
	    if ($special ? $special == "tag" : $this->fields['tag'] > 0)
		$q .= " or (tag = '$word')";
	    if ($q)
		$qs[] = "(" . substr($q, 4) . ")";
	}
	
	// create a temporary table
	$q = join($allWords ? " and " : " or ", $qs);
	if (!$this->allPapers)
	    $q = "($q) and Paper.timeSubmitted>0";
	if ($this->fields['re'] > 0)
	    $q = "create temporary table Matches select Paper.paperId from Paper left join PaperReview using (paperId) left join ContactInfo on (PaperReview.contactId=ContactInfo.contactId) left join PaperTag on (PaperTag.paperId=Paper.paperId) where $q group by Paper.paperId";
	else
	    $q = "create temporary table Matches select Paper.paperId from Paper left join PaperTag on (PaperTag.paperId=Paper.paperId) where $q group by Paper.paperId";
	
	return array($q, $re);
    }

    function search($str, $allWords) {
	global $Conf;
	$x = $this->searchQuery($str, $allWords);
	$this->matchPreg = $x[1];
	return $Conf->qe($x[0], "while searching papers");
    }

    function paperList($str, $allWords) {
	global $Conf;
	$x = $this->searchQuery($str, $allWords);
	$result = $Conf->qe($x[0], "while searching papers");
	if (DB::isError($result))
	    return array();
	$x = array();
	$result = $Conf->qe("select * from Matches", "while searching papers");
	while (($row = $result->fetchRow()))
	    $x[] = $row[0];
	return $x;
    }
    
}

    /* $find = sqlqtrim($_REQUEST["search"]);
    if ($Me->amAssistant())
	$q .= "	where match(title, abstract, authorInformation, collaborators) against ('" . sqlqtrim($_REQUEST["search"]) . "' in boolean mode)\n";
    else
	$q .= "	where match(title, abstract) against ('" . sqlqtrim($_REQUEST["search"]) . "' in boolean mode)
	or (blind=0 and match(authorInformation, collaborators) against ('" . sqlqtrim($_REQUEST["search"]) . "' in boolean mode)\n"; */
