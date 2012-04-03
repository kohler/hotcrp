<?php
// checkformat.inc -- HotCRP/banal integration
// HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function cvtdimen($text, $ndimen = -1) {
    // replace \xC2\xA0 (utf-8 for U+00A0 NONBREAKING SPACE) with ' '
    $text = trim(str_replace("\xC2\xA0", " ", strtolower($text)));
    $n = $text;
    $a = array();
    $unit = array();
    while (preg_match('/^\s*(\d+\.?\d*|\.\d+)\s*(in?|cm?|mm|pt|)\s*(.*)$/', $n, $m)) {
	$a[] = $m[1];
	if ($m[2] == "i" || $m[2] == "in")
	    $unit[] = 72;
	else if ($m[2] == "c" || $m[2] == "cm")
	    $unit[] = 72 * 0.393700787;
	else if ($m[2] == "mm")
	    $unit[] = 72 * 0.0393700787;
	else if ($m[2] == "pt")
	    $unit[] = 1;
	else
	    $unit[] = 0;
	if ($m[3] == "") {	// end of string
	    // fail on bad number of dimensions
	    if ($ndimen > 0 && count($a) != $ndimen)
		return false;

	    // spread known units to unknown positions, using two passes
	    $unitrep = 0;
	    for ($i = count($unit) - 1; $i >= 0; --$i)
		$unit[$i] = $unitrep = ($unit[$i] ? $unit[$i] : $unitrep);
	    $unitrep = 0;
	    for ($i = 0; $i < count($unit); ++$i)
		$unit[$i] = $unitrep = ($unit[$i] ? $unit[$i] : $unitrep);

	    // multiply dimensions by units, fail on unknown units
	    for ($i = 0; $i < count($unit); ++$i)
		if ($unit[$i])
		    $a[$i] *= $unit[$i];
		else
		    return false;

	    return (count($a) == 1 ? $a[0] : $a);
	} else if ($m[3][0] == "x")
	    $n = substr($m[3], 1);
	else if ($m[3][0] == 0xC3 && $m[3][1] == 0x97)
	    // \xC3\x97 is utf-8 for MULTIPLICATION SIGN
	    $n = substr($m[3], 2);
	else
	    return false;
    }
    if ($text == "letter")
	return cvtdimen("8.5in x 11in", $ndimen);
    else if ($text == "a4")
	return cvtdimen("210mm x 297mm", $ndimen);
    else
	return false;
}

function unparsedimen($n, $to = null) {
    if ($to == "paper") {
	if (is_array($n) && count($n) == 2 && abs($n[0] - 612) <= 5 && abs($n[1] - 792) <= 5)
	    return "letter paper (8.5in x 11in)";
	else if (is_array($n) && count($n) == 2 && abs($n[0] - 595.27) <= 5 && abs($n[1] - 841.89) <= 5)
	    return "A4 paper (210mm x 297mm)";
	else
	    $to = null;
    }
    if (is_array($n)) {
	$t = "";
	foreach ($n as $v)
	    // \xC2\xA0 is utf-8 for U+00A0 NONBREAKING SPACE
	    $t .= ($t == "" ? "" : "\xC2\xA0x\xC2\xA0") . unparsedimen($v, $to);
	return $t;
    }
    if (!$to && $n < 18)
	$to = "pt";
    else if (!$to && abs($n - 18 * (int) (($n + 9) / 18)) <= 0.5)
	$to = "in";
    else if (!$to)
	$to = "mm";
    if ($to == "pt")
	return $n . $to;
    else if ($to == "in" || $to == "i")
	return ((int) (100 * $n / 72 + 0.5) / 100) . $to;
    else if ($to == "cm")
	return ((int) (100 * $n / 72 / 0.393700787 + 0.5) / 100) . $to;
    else if ($to == "mm")
	return (int) ($n / 72 / 0.0393700787 + 0.5) . $to;
    else
	return "??" . $to;
}

function commarangejoin($range) {
    $i = 0;
    $a = array();
    while ($i < count($range)) {
	for ($j = $i + 1; $j < count($range) && $range[$j-1] == $range[$j]-1; $j++)
	    /* nada */;
	if ($j == $i + 1)
	    $a[] = $range[$i];
	else
	    $a[] = $range[$i] . "&ndash;" . $range[$j - 1];
	$i = $j;
    }
    return commajoin($a);
}

class CheckFormat {

    const ERR_PAPERSIZE = 1;
    const ERR_PAGELIMIT = 2;
    const ERR_COLUMNS = 4;
    const ERR_TEXTBLOCK = 8;
    const ERR_BODYFONTSIZE = 16;
    const ERR_BODYLEADING = 32;

    var $msgs;
    var $errors;
    var $pages;

    function __construct() {
	$this->msgs = array();
	$this->errors = 0;
	$this->pages = 0;
    }

    function msg($type, $what) {
	$this->msgs[] = array($type, $what);
	return 0;
    }

    function analyzeFile($filename, $spec) {
	global $Opt;
	if (isset($Opt["pdftohtml"]))
	    putenv("PHP_PDFTOHTML=" . $Opt["pdftohtml"]);

	$banal_run = "Code/banal -no_app ";
	if (($gtpos = strpos($spec, ">")) !== false) {
	    $banal_run .= substr($spec, $gtpos + 1) . " ";
	    $spec = substr($spec, 0, $gtpos);
	}
	exec($banal_run . escapeshellarg($filename), $bo);

	// analyze banal's output
	$pi = array();
	$papersize = null;
	$page = null;
	for ($bi = 1; $bi < count($bo); $bi++) {
	    $b = $bo[$bi];
	    if (preg_match('/^Paper size:\s+(.*?)\s*$/i', $b, $m)
		&& ($p = cvtdimen($m[1], 2)))
		$papersize = $p;
	    else if (preg_match('/^Page\s+(\d+)/i', $b, $m)) {
		$page = $m[1];
		$pi[$page] = array("pageno" => $m[1]);
	    } else if ($page && preg_match('/^\s*text region:\s+(.*?)\s*$/i', $b, $m)
		       && ($p = cvtdimen($m[1], 2)))
		$pi[$page]["block"] = $p;
	    else if ($page && preg_match('/^\s*body font:\s+(\S+)/i', $b, $m)
		     && ($p = cvtdimen($m[1], 1)))
		$pi[$page]["bodyfont"] = $p;
	    else if ($page && preg_match('/^\s*leading:\s+(\S+)/i', $b, $m)
		     && ($p = cvtdimen($m[1], 1)))
		$pi[$page]["leading"] = $p;
	    else if ($page && preg_match('/^\s*columns:\s+(\d+)/i', $b, $m))
		$pi[$page]["columns"] = $m[1];
	    else if ($page && preg_match('/^\s*type:\s+(\S+)/i', $b, $m))
		$pi[$page]["type"] = $m[1];
	}

	// report results
	if (!$papersize || !count($page))
	    return $this->msg("error", "Analysis failure: no pages or paper size.");
	$banal_desired = explode(";", $spec);
	$pie = array();

	// paper size
	if (count($banal_desired) > 0 && $banal_desired[0]) {
	    $psdefs = array();
	    $ok = false;
	    foreach (explode(" OR ", $banal_desired[0]) as $p) {
		if (!($p = cvtdimen($p, 2)))
		    continue;
		if (abs($p[0] - $papersize[0]) < 9
		    && abs($p[1] - $papersize[1]) < 9) {
		    $ok = true;
		    break;
		}
		$psdefs[] = unparsedimen($p, "paper");
	    }
	    if (!$ok && count($psdefs)) {
		$pie[] = "Paper size mismatch: expected " . commajoin($psdefs, "or") . ", got " . unparsedimen($papersize, "paper");
		$this->errors |= self::ERR_PAPERSIZE;
	    }
	}

	// number of pages
	$minpages = $maxpages = null;
	if (count($banal_desired) > 1 && $banal_desired[1]
	    && preg_match('/\A(\d+)(?:-(\d+))?\z/', $banal_desired[1], $m)) {
	    $m[2] = (isset($m[2]) ? $m[2] : 0);
	    $minpages = ($m[2] ? $m[1] : null);
	    $maxpages = ($m[2] ? $m[2] : $m[1]);
	    if ($m[2] && count($pi) < $m[1]) {
		$pie[] = "Too few pages: expected " . plural($m[1], "or more page") . ", found " . count($pi);
		$this->errors |= self::ERR_PAGELIMIT;
	    } else if (count($pi) > ($m[2] ? $m[2] : $m[1])) {
		$pie[] = "Too many pages: the limit is " . plural($m[2] ? $m[2] : $m[1], "page") . ", found " . count($pi);
		$this->errors |= self::ERR_PAGELIMIT;
	    }
	}
	$this->pages = count($pi);

	// number of columns
	if (count($banal_desired) > 2 && $banal_desired[2]
	    && ($p = cvtint($banal_desired[2])) > 0) {
	    $px = array();
	    foreach ($pi as $pg)
		if (($pp = cvtint(defval($pg, "columns"))) > 0 && $pp != $p
		    && defval($pg, "type") == "body")
		    $px[] = $pg["pageno"];
	    if (count($px) > ($maxpages ? max(0, $maxpages * 0.75) : 0)) {
		$pie[] = "Wrong number of columns: expected " . plural($p, "column") . ", different on " . pluralx($px, "page") . " " . commarangejoin($px);
		$this->errors |= self::ERR_COLUMNS;
	    }
	}

	// text block
	if (count($banal_desired) > 3 && $banal_desired[3]
	    && ($p = cvtdimen($banal_desired[3], 2))) {
	    $px = array();
	    $py = array();
	    $maxx = $maxy = 0;
	    foreach ($pi as $pg)
		if (($pp = defval($pg, "block"))) {
		    if ($pp[0] - $p[0] >= 9) {
			$px[] = $pg["pageno"];
			$maxx = max($maxx, $pp[0]);
		    }
		    if ($pp[1] - $p[1] >= 9) {
			$py[] = $pg["pageno"];
			$maxy = max($maxy, $pp[1]);
		    }
		}
	    if (count($px) > 0) {
		$pie[] = "Margins too small: text width exceeds "
		    . unparsedimen($p[0]) . " by "
		    . (count($px) > 1 ? "up to " : "")
		    . ((int) (100 * $maxx / $p[0] + .5) - 100)
		    . "% on " . pluralx($px, "page") . " "
		    . commarangejoin($px);
		$this->errors |= self::ERR_TEXTBLOCK;
	    }
	    if (count($py) > 0) {
		$pie[] = "Margins too small: text height exceeds "
		    . unparsedimen($p[1]) . " by "
		    . (count($py) > 1 ? "up to " : "")
		    . ((int) (100 * $maxy / $p[1] + .5) - 100)
		    . "% on " . pluralx($py, "page") . " "
		    . commarangejoin($py);
		$this->errors |= self::ERR_TEXTBLOCK;
	    }
	}

	// font size
	if (count($banal_desired) > 4 && $banal_desired[4]
	    && ($p = cvtnum($banal_desired[4])) > 0) {
	    $px = array();
	    $bodypages = 0;
	    $minval = 1000;
	    foreach ($pi as $pg) {
		if (defval($pg, "type") == "body")
		    $bodypages++;
		if (($pp = cvtnum(defval($pg, "bodyfont"))) > 0
		    && $pp < $p && defval($pg, "type") == "body") {
		    $px[] = $pg["pageno"];
		    $minval = min($minval, $pp);
		}
	    }
	    if ($bodypages == 0)
		$pie[] = "Warning: No pages seemed to contain body text; results may be off";
	    else if ($bodypages <= 0.5 * count($pi))
		$pie[] = "Warning: Only " . plural($bodypages, "page") . " seemed to contain body text; results may be off";
	    if (count($px) > 0) {
		$pie[] = "Body font too small: minimum ${p}pt, saw values as small as ${minval}pt on " . pluralx($px, "page") . " " . commarangejoin($px);
		$this->errors |= self::ERR_BODYFONTSIZE;
	    }
	}

	// leading
	if (count($banal_desired) > 5 && $banal_desired[5]
	    && ($p = cvtnum($banal_desired[5])) > 0) {
	    $px = array();
	    $minval = 1000;
	    foreach ($pi as $pg)
		if (($pp = cvtnum(defval($pg, "leading"))) > 0
		    && $pp < $p && defval($pg, "type") == "body") {
		    $px[] = $pg["pageno"];
		    $minval = min($minval, $pp);
		}
	    if (count($px) > 0) {
		$pie[] = "<a href='http://en.wikipedia.org/wiki/Leading'>Leading</a> (line spacing) too small: minimum ${p}pt, saw values as small as ${minval}pt on " . pluralx($px, "page") . " " . commarangejoin($px);
		$this->errors |= self::ERR_BODYLEADING;
	    }
	}

	// results
	if (count($pie) > 0) {
	    $this->msg("warn", "This paper may violate the submission format requirements.  Errors are:\n<ul><li>" . join("</li>\n<li>", $pie) . "</li></ul>\nOnly submissions that comply with the requirements will be considered.  However, the automated format checker uses heuristics and can make mistakes, especially on figures.  If you are confident that the paper already complies with all format requirements, you may submit it as is.");
	    return 1;
	} else {
	    $this->msg("confirm", "Congratulations, this paper appears to comply with the basic submission format requirements.  However, there may be requirements that the automated checker doesn’t check.  You should verify yourself that your paper meets all stated requirements.");
	    return 2;
	}
    }

    function _analyzePaper($paperId, $documentType, $spec, &$tmpdir) {
	global $Conf, $prow, $Opt;
	$result = $Conf->paperStorageResult($paperId, $documentType);
	if (!($row = $Conf->paperStorageRow($result))
	    || $row->paperStorageId <= 1)
	    return $this->msg("error", "No such paper.");
	if ($row->mimetype != "application/pdf")
	    return $this->msg("error", "The format checker only works for PDF files.");
	if (!isset($row->filename)) {
	    if (!$tmpdir && ($tmpdir = tempdir()) == false)
		return $this->msg("error", "Cannot create temporary directory.");
	    if (file_put_contents("$tmpdir/paper.pdf", $row->content) != strlen($row->content))
		return $this->msg("error", "Failed to save PDF to temporary file for analysis.");
	    $row->filename = "$tmpdir/paper.pdf";
	}
	return $this->analyzeFile($row->filename, $spec);
    }

    function analyzePaper($paperId, $documentType, $spec) {
	global $Conf, $Opt;
	// constrain the number of concurrent banal executions to banalLimit
	// (counter resets every 2 seconds)
	$t = (int) (time() / 2);
	$n = ($Conf->settingText("banal_count") == $t ? $Conf->setting("banal_count") + 1 : 1);
	$limit = defval($Opt, "banalLimit", 8);
	if ($limit > 0 && $n > $limit)
	    return $this->msg("error", "Server too busy to check paper formats at the moment.  This is a transient error; feel free to try again.");
	if ($limit > 0)
	    $Conf->q("insert into Settings (name,value,data) values ('banal_count',$n,'$t') on duplicate key update value=$n, data='$t'");

	$tmpdir = null;
	$status = $this->_analyzePaper($paperId, $documentType, $spec, $tmpdir);
	if ($tmpdir)
	    exec("/bin/rm -rf $tmpdir");

	if ($limit > 0)
	    $Conf->q("update Settings set value=value-1 where name='banal_count' and data='$t'");
	return $status;
    }

    function reportMessages() {
	global $Conf;
	foreach ($this->msgs as $m)
	    if ($m[0] == "error")
		$Conf->errorMsg($m[1]);
	    else if ($m[0] == "warn")
		$Conf->warnMsg($m[1]);
	    else if ($m[0] == "confirm")
		$Conf->confirmMsg($m[1]);
	    else if ($m[0] == "info")
		$Conf->infoMsg($m[1]);
    }

}

global $checkFormatErrors;
$checkFormatErrors = array(CheckFormat::ERR_PAPERSIZE => "papersize",
			   CheckFormat::ERR_PAGELIMIT => "pagelimit",
			   CheckFormat::ERR_COLUMNS => "columns",
			   CheckFormat::ERR_TEXTBLOCK => "textblock",
			   CheckFormat::ERR_BODYFONTSIZE => "bodyfontsize",
			   CheckFormat::ERR_BODYLEADING => "bodyleading");
