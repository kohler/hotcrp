<?php 

class PaperSearch {

    var $fields;
    var $searchAll;
    var $nonblind;
    var $matchPreg;
    
    function PaperSearch($fields, $all = false, $me = null) {
	global $Conf;
	$t = 0;
	foreach (array('ti', 'ab', 'au', 'co', 're') as $f)
	    $t += ($this->fields[$f] = (defval($fields[$f], 0) != 0));
	if ($t == 0)
	    $this->fields['ti'] = $this->fields['ab'] = $this->fields['au'] = $this->fields['co'] = $this->fields['re'] = 1;
	$this->searchAll = $all;
	$this->nonblind = false;
	if ($me != null) {
	    if (!$me->amAssistant() && $Conf->blindSubmission() > 1)
		$this->fields['au'] = $this->fields['co'] = -1;
	    if (!$me->amAssistant()) {
		$this->fields['re'] = -1;
		$this->searchAll = false;
		$this->nonblind = true;
	    }
	}
    }

    function searchQuery($str) {
	$re = $q = "";
	$auextra = ($this->nonblind ? "blind=0 and " : "");
	foreach (preg_split("/\\s+/", $str) as $word)
	    if ($word != "") {
		$re .= ($re == "" ? "" : "|") . preg_quote($word);
		$word = preg_replace("/([%_\\\\])/", "\\\$1", $word);
		$word = sqlq($word);
		if ($this->fields['ti'] > 0)
		    $q .= " or (title like '%$word%')";
		if ($this->fields['ab'] > 0)
		    $q .= " or (abstract like '%$word%')";
		if ($this->fields['au'] > 0)
		    $q .= " or (${auextra}authorInformation like '%$word%')";
		if ($this->fields['co'] > 0)
		    $q .= " or (${auextra}Paper.collaborators like '%$word%')";
		if ($this->fields['re'] > 0)
		    $q .= " or (firstName like '%$word%') or (lastName like '%$word%') or (email like '%$word%')";
	    }
	
	// create a temporary table
	if ($this->fields['re'] > 0)
	    $q = "create temporary table Matches select Paper.paperId from Paper left join PaperReview using (paperId) left join ContactInfo on (PaperReview.contactId=ContactInfo.contactId) where" . substr($q, 3) . " group by Paper.paperId";
	else
	    $q = "create temporary table Matches select paperId from Paper where" . substr($q, 3);
    
	return array($q, $re);
    }

    function search($str) {
	global $Conf;
	$x = $this->searchQuery($str);
	$this->matchPreg = $x[1];
	return $Conf->qe($x[0], "while searching papers");
    }

    function paperList($str) {
	global $Conf;
	$x = $this->searchQuery($str);
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
