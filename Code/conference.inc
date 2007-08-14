<?php 
// conference.inc -- HotCRP central helper class (singleton)
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Conference {
    
    var $shortName;
    var $longName;
    var $paperSite;
    var $contactName;
    var $contactEmail;
    var $emailFrom;
  
    var $dblink;

    var $settings;
    var $settingTexts;

    var $saveMessages;
    var $headerPrinted;
    var $tableMessages;
    var $tableMessagesObj;

    var $footerStuff;

    // Useful colors
    var $contrastColorOne = "#eeeeee";
    var $contrastColorTwo = "#cccccc";

    function Conference() {
	global $Opt;

	$this->saveMessages = true;
	$this->headerPrinted = 0;
	$this->tableMessages = false;
	$this->footerStuff = "";
	
	$this->contactName = $Opt['contactName'];
	$this->contactEmail = $Opt['contactEmail'];
	$this->emailFrom = $Opt['emailFrom'];
	// remove final slash from $Opt['paperSite']
	$this->paperSite = preg_replace('|/+\Z|', '', $Opt['paperSite']);
	if (!$this->paperSite)
	    die("Package misconfigured: \$Opt['paperSite'] is not set");
	$this->shortName = $Opt['shortName'];
	$this->longName = $Opt['longName'];

	// unpack dsn and connect to database
	if (!isset($Opt['dsn']))
	    die("Package misconfigured: \$Opt['dsn'] is not set");
	else if (preg_match('|^mysql://([^:@/]*)/(.*)|', $Opt['dsn'], $m)) {
	    $this->dblink = mysql_connect(urldecode($m[1]));
	    $dbname = urldecode($m[2]);
	} else if (preg_match('|^mysql://([^:@/]*)@([^/]*)/(.*)|', $Opt['dsn'], $m)) {
	    $this->dblink = mysql_connect(urldecode($m[2]), urldecode($m[1]));
	    $dbname = urldecode($m[3]);
	} else if (preg_match('|^mysql://([^:@/]*):([^@/]*)@([^/]*)/(.*)|', $Opt['dsn'], $m)) {
	    $this->dblink = mysql_connect(urldecode($m[3]), urldecode($m[1]), urldecode($m[2]));
	    $dbname = urldecode($m[4]);
	} else
	    die("Package misconfigured: dsn syntax error");

	if (!$this->dblink || !mysql_select_db($dbname, $this->dblink))
	    die("Unable to connect to database");
	mysql_query("set names 'utf8'", $this->dblink);

	$this->updateSettings();
    }

    //
    // Initialization functions
    //

    function updateSettings() {
	$this->settings = array();
	$this->settingTexts = array();
	$result = $this->q("select name, value, data from Settings");
	while (($row = edb_row($result))) {
	    $this->settings[$row[0]] = $row[1];
	    if ($row[2] !== null)
		$this->settingTexts[$row[0]] = $row[2];
	}
	foreach (array("pc_seeall", "pcrev_any", "extrev_view", "rev_notifychair") as $x)
	    if (!isset($this->settings[$x]))
		$this->settings[$x] = 0;
	if (!isset($this->settings["sub_blind"]))
	    $this->settings["sub_blind"] = 2;
	if (!isset($this->settings["rev_blind"]))
	    $this->settings["rev_blind"] = 2;
	if ($this->settings["pc_seeall"] && !$this->timeFinalizePaper())
	    $this->settings["pc_seeall"] = -1;
    }

    function setting($name, $defval = false) {
	return defval($this->settings[$name], $defval);
    }
    
    function settingText($name, $defval = false) {
	return defval($this->settingTexts[$name], $defval);
    }
    
    // update the 'papersub' setting: are there any submitted papers?
    function updatePapersubSetting($forsubmit) {
	if (!isset($this->settings["papersub"]) && $forsubmit)
	    $this->q("insert into Settings (name, value) values ('papersub', " . time() . ") on duplicate key update name=name");
	else if (defval($this->settings["papersub"]) <= 0 || !$forsubmit)
	    $this->q("update Settings set value=(select max(timeSubmitted) from Paper where timeSubmitted>0 group by paperId>0) where name='papersub'");
    }

    function updatePaperaccSetting($foraccept) {
	if (!isset($this->settings["paperacc"]) && $foraccept)
	    $this->q("insert into Settings (name, value) values ('paperacc', " . time() . ") on duplicate key update name=name");
	else if (defval($this->settings["paperacc"]) <= 0 || !$foraccept)
	    $this->q("update Settings set value=(select max(outcome) from Paper where timeSubmitted>0 group by paperId>0) where name='paperacc'");
    }

    function qx($query) {
	return mysql_query($query, $this->dblink);
    }

    function q($query) {
	global $OK;
	$result = mysql_query($query, $this->dblink);
	if ($result === false)
	    $OK = false;
	return $result;
    }

    function dbErrorText($getdb = true, $while = "", $suggestRetry = true) {
	if ($getdb) {
	    $text = "<p>Database error";
	    if ($while)
		$text .= " $while";
	    $text .= ": " . htmlspecialchars(mysql_error($this->dblink)) . "</p>";
	}
	if ($suggestRetry)
	    $text .= "\n<p>Please try again or contact the site administrator at $this->emailFrom.</p>";
	return $text;
    }
  
    function qe($query, $while = "", $suggestRetry = false) {
	global $OK;
	$result = mysql_query($query, $this->dblink);
	if ($result === false) {
	    $this->errorMsg($this->dbErrorText(true, $while . " (" . htmlspecialchars($query) . ")", $suggestRetry));
	    $OK = false;
	}
	return $result;
    }

    function qe2($query, $while = "", $suggestRetry = false) {
	global $OK;
	$result = mysql_query($query, $this->dblink);
	if ($result === false) {
	    $this->errorMsg($this->dbErrorText(true, $while . " (" . htmlspecialchars($query) . ")", $suggestRetry));
	    $OK = false;
	    return new PEAR_Error("Database error");
	}
	return $result;
    }

    function lastInsertId($while = "", $suggestRetry = false) {
	global $OK;
	$result = mysql_insert_id($this->dblink);
	if (!$result) {
	    $this->errorMsg($this->dbErrorText($result === false, $while, $suggestRetry));
	    $OK = false;
	}
	return $result;
    }


    //
    // Check to see if an email address has been registered
    // in the database
    //
    function getContactId($email, $registerFromRequest = false) {
	global $Me;
	// XXX should lock table
	
	$email = ($email ? trim($email) : "");
	if (!$email)
	    return false;
	
	$result = $this->q(sprintf("select contactId from ContactInfo where email='%s'", sqlq($email)));
	if (edb_nrows($result) > 0) {
	    $row = edb_row($result);
	    return $row[0];
	}

	if ($registerFromRequest === false)
	    return false;

	// validate email address
	require_once("Mail/RFC822.php");
	$rfc822 = new Mail_RFC822($email);
	$emailForValidate = $email;
	if (!$rfc822->validateMailbox($emailForValidate))
	    return false;
	
	$p = (is_string($registerFromRequest) ? $registerFromRequest : "");
	
	// try to add them
	$newguy = new Contact();
	$result = $newguy->initialize($email, $this);
	if ($result) {
	    // rigamarole to handle separate or joined first and last names
	    if (isset($_REQUEST["${p}firstName"]) || isset($_REQUEST["${p}lastName"])) {
		if (isset($_REQUEST["${p}firstName"]))
		    $newguy->firstName = $_REQUEST["${p}firstName"];
		if (isset($_REQUEST["${p}lastName"]))
		    $newguy->lastName = $_REQUEST["${p}lastName"];
	    } else if (!isset($_REQUEST["${p}name"]))
		/* nada */;
	    else if (preg_match('/^(.*),\s*(\S.*)$/', $_REQUEST["${p}name"], $matches)) {
		$newguy->firstName = $matches[2];
		$newguy->lastName = $matches[1];
	    } else if (preg_match('/^(.*)\s+(\S*)$/', $_REQUEST["${p}name"], $matches)) {
		$newguy->firstName = $matches[1];
		$newguy->lastName = $matches[2];
	    } else
		$newguy->lastName = $_REQUEST["${p}name"];

	    // other information is easier
	    if (isset($_REQUEST["${p}affiliation"]))
		$newguy->affiliation = $_REQUEST["${p}affiliation"];
	    if (isset($_REQUEST["${p}voicePhoneNumber"]))
		$newguy->voicePhoneNumber = $_REQUEST["${p}voicePhoneNumber"];
	    if (isset($_REQUEST["${p}faxPhoneNumber"]))
		$newguy->faxPhoneNumber = $_REQUEST["${p}faxPhoneNumber"];

	    // actually update database
	    $result = $newguy->updateDB($this, "while creating account for " . htmlspecialchars($email));
	}
	
	if ($result) {
	    $this->infoMsg("Created account for " . htmlspecialchars($email) . ".");
	    $newguy->sendAccountInfo($this, true, false);
	    $this->log("$Me->email created account", $newguy);
	} else
	    $this->log("$Me->email account creation failure", $newguy);
	
	return $newguy->contactId;
    }


    function parseableTime($value) {
	return date("j M Y g:i:sa",$value);
    }
    function printableTime(&$value) {
	if (isset($value) && $value > 0)
	    return date("l j M Y g:i:sa T", $value);
	else
	    return "N/A";
    }
    function printableTimeShort(&$value) {
	if (isset($value) && $value > 0)
	    return date("j M Y g:i:sa", $value);
	else
	    return "N/A";
    }

    function printableTimeSetting($what) {
	return $this->printableTime($this->settings[$what]);
    }
    function printableDeadlineSetting($what) {
	if (!isset($this->settings[$what]) || $this->settings[$what] <= 0)
	    return "No deadline";
	else
	    return "Deadline: " . $this->printableTime($this->settings[$what]);
    }

    function settingsAfter($name, $grace = null) {
	$t = defval($this->settings[$name], null);
	if ($t !== null && $t > 0 && $grace && isset($this->settings[$grace]))
	    $t += $this->settings[$grace];
	return ($t !== null && $t > 0 && $t <= time());
    }
    function settingsBetween($name1, $name2, $grace = null) {
	$now = time();
	$t = defval($this->settings[$name1], null);
	if ($t === null || ($t <= 0 && $t > $now))
	    return false;
	$t = defval($this->settings[$name2], null);
	if ($t !== null && $t > 0 && $grace && isset($this->settings[$grace]))
	    $t += $this->settings[$grace];
	return ($t === null || $t <= 0 || $t >= $now);
    }

    function timeStartPaper() {
	return $this->settingsBetween('sub_open', 'sub_reg', 'sub_grace');
    }
    function timeUpdatePaper($prow = null) {
	return $this->settingsBetween('sub_open', 'sub_update', 'sub_grace')
	    && (!$prow || $prow->timeSubmitted <= 0 || $this->setting('sub_freeze') <= 0);
    }
    function timeFinalizePaper($prow = null) {
	return $this->settingsBetween('sub_open', 'sub_sub', 'sub_grace')
	    && (!$prow || $prow->timeSubmitted <= 0 || $this->setting('sub_freeze') <= 0);
    }
    function timeSubmitFinalPaper() {
	return $this->settingsBetween('final_open', 'final_done', 'final_grace');
    }
    function timeAuthorViewReviews() {
	return $this->settingsAfter('au_seerev');
    }
    function timeAuthorRespond() {
	return $this->settingsBetween('resp_open', 'resp_done', 'resp_grace');
    }
    function timeAuthorViewDecision() {
	return $this->settingsAfter('au_seedec');
    }
    function timeReviewOpen() {
	$st = defval($this->settings['rev_open'], 0);
	return $st > 0 && time() >= $st;
    }
    function timeReviewPaper($isPC = false, $requested = true, $hard = true) {
	if (!($requested
	      || ($isPC && $this->settings['pcrev_any'] > 0)))
	    return false;
	$d = ($isPC ? "pcrev_" : "extrev_") . ($hard ? "hard" : "soft");
	return $this->settingsBetween('rev_open', $d, 'rev_grace') > 0;
    }
    function timePCReviewPreferences() {
	return $this->settingsAfter('papersub');
    }
    function timePCViewAllReviews() {
	return $this->settingsAfter('pc_seeallrev');
    }
    function timePCViewDecision() {
	return true;
    }
    function timeReviewerViewDecision() {
	return $this->settingsAfter('rev_seedec');
    }
    function timeReviewerViewAcceptedAuthors() {
	return $this->settingsAfter('rev_seedec');
    }
    function timePCViewPaper($prow, $download) {
	if ($prow->timeWithdrawn > 0)
	    return false;
	else if ($prow->timeSubmitted > 0)
	    return $this->settingsAfter('sub_sub', 'sub_grace') || !$download || $this->setting('sub_freeze') > 0;
	else
	    return !$download && $this->setting('pc_seeall') > 0;
    }
    function timeReviewerViewSubmittedPaper() {
	return true;
    }
    function timeEmailChairAboutReview() {
	return $this->settings['rev_notifychair'] > 0;
    }
    function timeEmailAuthorsAboutReview() {
	return $this->settingsAfter('au_seerev');
    }

    function blindSubmission() {
	return $this->settings['sub_blind'];
    }
    function blindReview() {
	return $this->settings['rev_blind'];
    }
    

    function go($url) {
	header("Location: $url");
	echo "<html>";
	echo "<body> <p> You should not be here. ";
	echo "You need to <a href=\"$url\"> login </a> </p> </body>";
	echo "</html>";
	exit();
    }


    function cacheableImage($name, $alt = null, $title = null, $class = null) {
	global $ConfSiteBase, $ConfSitePATH;
	$t = "<img src='${ConfSiteBase}images/$name'";
	if ($alt)
	    $t .= " alt=\"$alt\"";
	if ($title)
	    $t .= " title=\"$title\"";
	if ($class)
	    $t .= " class=\"$class\"";
	return $t . " />";
    }


    //
    // Paper storage
    //

    function storePaper($uploadId, $prow, $final, $override) {
	global $Opt;

	$paperId = (is_numeric($prow) ? $prow : $prow->paperId);
	$while = "while storing paper in database";
	
	if ($uploadId && fileUploaded($_FILES[$uploadId], $this))
	    $filename = $_FILES[$uploadId]['tmp_name'];
	else
	    return 0;

	if (isset($filename)) {
	    if (($contents = file_get_contents($filename)) === false)
		return 0;

	    // Check if paper is postscript or PDF, only kinds allowed.
	    // We ignore the mimetype since MacOS browsers get this wrong
	    $allowPDF = !isset($Opt['disablePDF']);
	    $allowPS = !isset($Opt['disablePS']);
	    if ($allowPDF && strncasecmp("%PDF-", $contents, 5) == 0)
		$mimetype = "application/pdf";
	    else if ($allowPS && strncasecmp("%!PS-", $contents, 5) == 0)
		$mimetype = "application/postscript";
	    else {
		$mimetype = "application/octet-stream";
		if (isset($_FILES[$uploadid]["type"]))
		    $mimetype = $_FILES[$uploadid]["type"];
		$message = "You appear to be submitting a file that's not in a valid format for this conference.  ";
		if ($allowPDF && $allowPS)
		    $message .= "Valid formats are PDF and PostScript.  ";
		else if ($allowPDF)
		    $message .= "PDF is the only valid format.  ";
		else if ($allowPS)
		    $message .= "PostScript is the only valid format.  ";
		else
		    $message .= "Internal error: there are no valid formats.  ";
		$message .= "You tried to upload a file with MIME type '" . htmlentities($mimetype) . "'; ";
		$message .= "its first 10 bytes are " . htmlspecialchars(substr($contents, 0, 5)) . ".<br/><br/>Please check the file contents; if you think this message is in error, contact the site administrator.";
		return $this->errorMsg($message);
	    }
	}

	$result = $this->qe2("lock tables Paper write, PaperStorage write", $while);
	if (PEAR::isError($result))
	    return $result;

	$timestamp = time();
	$result = $this->qe2("insert into PaperStorage set paperId=$paperId, timestamp=$timestamp, mimetype='" . sqlq($mimetype) . "', paper='" . sqlq(substr($contents, 0, 400000)) . "'", $while);
	if (!PEAR::isError($result))
	    $result = $this->qe2("select last_insert_id()", $while);
	if (!PEAR::isError($result)) {
	    $row = edb_row($result);
	    $paperStorageId = $row[0];
	    for ($pos = 400000; $pos < strlen($contents) && $result; $pos += 400000)
		$result = $this->qe2("update PaperStorage set paper=concat(paper,'" . sqlq(substr($contents, $pos, 400000)) . "') where paperStorageId=$paperStorageId", $while);
	}

	// check that paper storage succeeded
	if (!PEAR::isError($result))
	    $result = $this->qe2("select length(paper) from PaperStorage where paperStorageId=$paperStorageId", $while);
	if (!PEAR::isError($result)) {
	    if (edb_nrows($result) == 0)
		$result = new PEAR_Error("Paper upload unsuccessful!");
	    else {
		$row = edb_row($result);
		if ($row[0] != strlen($contents))
		    $result = new PEAR_Error("Failed to store your paper.  Usually, this is because the file you tried to upload was too big for our system.");
	    }
	    if (PEAR::isError($result))
		$this->errorMsg($result->getMessage());
	}
    
	if (!PEAR::isError($result))
	    $result = $this->qe2("update Paper set "
		. ($final ? "finalPaperStorageId" : "paperStorageId") . "=$paperStorageId, "
		. "size=" . strlen($contents) . ", mimetype='" . sqlq($mimetype) . "', timestamp=$timestamp"
		. " where paperId=$paperId and timeWithdrawn<=0", $while);

	$this->qe2("unlock tables", $while);

	if (PEAR::isError($result))
	    return $result;

	// potentially email the paper to Greg Minshall
	if (defval($Opt['emailSubmissions'], false)) {
	    if (is_numeric($prow))
		$prow = $this->paperRow($paperId);
	    
	    require_once("Mail.php");
	    require_once("Mail/mime.php");

	    // prepare data
	    $subject = "[$this->shortName] Paper #$paperId upload";
	    $message = "           Paper: $this->paperSite/paper.php?paperId=$paperId
       Submitted: " . $this->printableTime($timestamp) . "\n\n"
		. wordWrapIndent(trim($prow->title), "Title: ") . "\n"
		. wordWrapIndent(trim($prow->abstract), "Abstract: ") . "\n\n"
		. wordWrapIndent(cleanAuthorText($prow), "Authors: ") . "\n"
		. wordWrapIndent(trim($prow->collaborators), "Collaborators: ") . "\n";

	    // collect contact authors
	    $aus = $this->paperContactAuthors($paperId);
	    $autxt = "";
	    foreach ($aus as $au)
		$autxt .= contactText($au) . "\n";
	    if ($autxt)
		$message .= wordWrapIndent($autxt, "Contact authors: ");
	    $message .= "\n";
	    
	    $mime = new Mail_mime("\n");
	    $mime->setTXTBody($message);
	    $mime->addAttachment($contents, $mimetype, $this->downloadPaperName($paperId, $mimetype, false), false);

	    $body = $mime->get();
	    $hdrs = $mime->headers(array("From" => $this->emailFrom,
					 "Subject" => $subject));

	    $mfac =& Mail::factory("mail");
	    if ($this->allowEmailTo($Opt["emailSubmissions"]))
		$mfac->send($Opt["emailSubmissions"], $hdrs, $body);
	    else
		$this->infoMsg("<pre>" . htmlspecialchars($body) . "</pre>");
	}
	
	return strlen($contents);
    }

    function downloadPaperName($paperId, $mimetype, $final) {
	global $Opt;
	$title = $Opt['downloadPrefix'] . ($final ? "final" : "paper");
	return $title . $paperId . $this->getFileExtension($mimetype);
    }
    
    function __downloadPaper($paperId, $saveit, $final) {
	// Database query
	$storefield = ($final ? "finalPaperStorageId" : "paperStorageId");
	$result = $this->q("select PaperStorage.mimetype, paper, PaperStorage.paperStorageId, compression
	from Paper
	left join PaperStorage on (PaperStorage.paperStorageId=Paper.$storefield)
	where Paper.paperId=$paperId");
	if (!$result) {
	    $this->log("Error downloading for review: " . mysql_error($this->dblink), $_SESSION["Me"], $paperId);
	    return new PEAR_Error("Database error while downloading paper.");
	} else if (edb_nrows($result) == 0)
	    return new PEAR_Error("There is no paper #$paperId.");

	// Check data
	$row = edb_row($result);
	$mimetype = $row[0];
	$content = $row[1];
	$compression = $row[3];
	$length = strlen($content);
	if ($row[2] <= 1)
	    return new PEAR_Error("Paper #$paperId has not been uploaded yet.");
	else if ($length == 0)
	    return new PEAR_Error("Paper #$paperId appears to be empty.");

	// Print paper
	header("Content-Type: $mimetype");
	header("Content-Description: PHP Generated Data");
	$name = $this->downloadPaperName($paperId, $mimetype, $final);
	header("Content-Disposition: " . ($saveit ? "attachment" : "inline") . "; filename=$name");
	header("Content-Length: $length"); 
	if ($compression == 1)
	    print gzinflate($content);
	else
	    print $content;
	return 1;
    }

    function downloadPaper($paperId, $saveit, $final = false) {
	$result = $this->__downloadPaper($paperId, $saveit, $final);
	if (PEAR::isError($result))
	    $this->errorMsg($result->getMessage());
	else
	    $this->log("Downloaded paper", $_SESSION["Me"], $paperId);
	return $result;
    }

    function __downloadPapers($paperIds, &$tmpdir, $final) {
	global $Opt;
	
	// special cases
	if (count($paperIds) == 0)
	    return new PEAR_Error("No papers selected for download.");
	else if (count($paperIds) == 1)
	    return $this->__downloadPaper($paperIds[0], 1, $final);

	// paper query
	$q = "select Paper.paperId, paper, PaperStorage.mimetype, compression from PaperStorage join Paper on (PaperStorage.paperStorageId=Paper." . ($final ? "finalPaperStorageId" : "paperStorageId") . ") where (";
	foreach ($paperIds as $paperId)
	    $downloadQ[] = "Paper.paperId=$paperId";
	$q .= join(" or ", $downloadQ) . ")";
	$result = $this->q($q);
	if (!$result)
	    return new PEAR_Error("Database error while downloading papers: " . mysql_error($this->dblink));
	
	// set up temporary directory
	if (($tmpdir = tempdir(null, "crp")) === false)
	    return new PEAR_Error("Could not create temporary directory!");

	// write papers into temporary directory
	while ($row = edb_row($result)) {
	    $path = "$tmpdir/" . $this->downloadPaperName($row[0], $row[2], $final);
	    if ($row[3] == 1)
		$row[1] = gzinflate($row[1]);
	    if (($len = file_put_contents($path, $row[1])) != strlen($row[1]))
		$warnings[] = "Could not save paper #$row[0].";
	    else if ($len == 0)
		$warnings[] = "Paper #$row[0] has not been uploaded yet.";
	    else
		$downloadPaths[] = $path;
	}
	if (!isset($downloadPaths))
	    return new PEAR_Error("No papers could successfully be downloaded.");
	if (isset($warnings)) {
	    file_put_contents("$tmpdir/README.warnings", join("\n", $warnings) . "\n");
	    $downloadPaths[] = "$tmpdir/README.warnings";
	}

	// run zip
	$out = system("zip -jq $tmpdir/x.zip " . join(" ", $downloadPaths) . " 2>&1", $status);
	if ($status != 0)
	    return new PEAR_Error("<code>zip</code> returned an error.  Its output: <pre>" . htmlspecialchars($out) . "</pre>");
	if (($contents = file_get_contents("$tmpdir/x.zip")) === false)
	    return new PEAR_Error("<code>zip</code> output unreadable or empty.  Its output: <pre>" . htmlspecialchars($out) . "</pre>");

	// output
	header("Content-Description: PHP Generated Data");
	header("Content-Disposition: attachment; filename=" . $Opt['downloadPrefix'] . "papers.zip");
	header("Content-Type: application/zip");
	header("Content-Length: " . strlen($contents));
	print $contents;
	return 1;
    }

    function downloadPapers($paperIds, $final = false) {
	$result = $this->__downloadPapers($paperIds, $tmpdir, $final);
	if (PEAR::isError($result))
	    $this->errorMsg($result->getMessage());
	else
	    $this->log("Downloaded papers", $_SESSION["Me"], $paperIds);
	if (isset($tmpdir))
	    exec("/bin/rm -rf $tmpdir");
	return $result;
    }


    function _paperQuery_where($optarr, $field) {
	$ids = array();
	foreach (mkarray($optarr) as $id)
	    if (($id = cvtint($id)) > 0)
		$ids[] = "$field=$id";
	if (is_array($optarr) && count($ids) == 0)
	    $ids[] = "$field=0";
	return "(" . join(" or ", $ids) . ")";
    }
    
    function paperQuery($contact, $options = array()) {
	// Options:
	//   "paperId" => $pid	Only paperId $pid (if array, any of those)
	//   "reviewId" => $rid Only paper reviewed by $rid
	//   "commentId" => $c  Only paper where comment is $c
	//   "finalized"	Only submitted papers
	//   "accepted"		Only accepted papers
	//   "active"		Only nonwithdrawn papers
	//   "author"		Only papers authored by $contactId
	//   "myReviewRequests"	Only reviews requested by $contactId
	//   "myReviews"	All reviews authored by $contactId
	//   "myOutstandingReviews" All unsubmitted reviews auth by $contactId
	//   "myReviewsOpt"	myReviews, + include papers not yet reviewed
	//   "reviewerContact"	Sets reviewer contact ID
	//   "allReviews"	All reviews (multiple rows per paper)
	//   "allReviewScores"	All review scores (multiple rows per paper)
	//   "reviewerName"	Include reviewer names
	//   "joins"		Table(s) to join
	//   "tags"		Include paperTags
	//   "topics"
	//   "options"
	//   "scores" => array(fields to score)
	//   "order" => $sql	$sql is SQL 'order by' clause (or empty)

	$reviewerQuery = isset($options['myReviews']) || isset($options['allReviews']) || isset($options['myReviewRequests']) || isset($options['myReviewsOpt']) || isset($options['myOutstandingReviews']);
	$allReviewerQuery = isset($options['allReviews']) || isset($options['allReviewScores']);
	$scoresQuery = !$reviewerQuery && isset($options['allReviewScores']);
	$contactId = (is_object($contact) ? $contact->contactId : $contact);
	$reviewContactId = $contactId;
	if (isset($options['reviewerContact']))
	    $reviewContactId = $options['reviewerContact'];

	// fields
	$pq = "select Paper.*, PaperConflict.conflictType,
		count(AllReviews.reviewSubmitted) as reviewCount,
		count(AllReviews.reviewNeedsSubmit) as startedReviewCount";
	if (!isset($options['author'])) {
	    $pq .= ",
		PaperReview.reviewType,
		PaperReview.reviewId,
		PaperReview.reviewModified,
		PaperReview.reviewSubmitted,
		PaperReview.reviewOrdinal,
		PaperReview.reviewBlind,
		PaperReview.contactId as reviewContactId";
	    if (!$allReviewerQuery)
		$pq .= ",
		PaperReview.reviewType as myReviewType,
		PaperReview.reviewSubmitted as myReviewSubmitted,
		PaperReview.reviewNeedsSubmit as myReviewNeedsSubmit";
	    else
		$pq .= ",
		MyPaperReview.reviewType as myReviewType,
		MyPaperReview.reviewSubmitted as myReviewSubmitted,
		MyPaperReview.reviewNeedsSubmit as myReviewNeedsSubmit";
	}
	if (isset($options['reviewerName']))
	    $pq .= ",
		ReviewerContactInfo.firstName as reviewFirstName,
		ReviewerContactInfo.lastName as reviewLastName,
		ReviewerContactInfo.email as reviewEmail";
	if ($reviewerQuery || $scoresQuery) {
	    require_once('review.inc');
	    $rf = reviewForm();
	    global $reviewFields;
	    foreach ($rf->fieldOrder as $k)
		if (!$scoresQuery || $reviewFields[$k])
		    $pq .= ",\t\tPaperReview.$k as $k";
	}
	if (isset($options['topics']))
	    $pq .= ",
		PaperTopics.topicIds,
		PaperTopics.topicInterest";
	if (isset($options['options']) && defval($this->settings["paperOption"]))
	    $pq .= ",
		PaperOptions.optionIds";
	else if (isset($options['options']))
	    $pq .= ",
		'' as optionIds";
	if (isset($options['tags']))
	    $pq .= ",
		PaperTags.paperTags";
	if (isset($options['scores'])) {
	    foreach ($options['scores'] as $field)
		$pq .= ",
		PaperScores.${field}Scores";
	}
	if (isset($options['topicInterestScore']))
	    $pq .= ",
		coalesce(PaperTopics.topicInterestScore, 0) as topicInterestScore";
	if (defval($options['reviewerPreference']))
	    $pq .= ",
		coalesce(PaperReviewPreference.preference, 0) as reviewerPreference";
	if (defval($options['allReviewerPreference']))
	    $pq .= ",
		APRP.allReviewerPreference";
	if (defval($options['desirability']))
	    $pq .= ",
		coalesce(APRP.desirability, 0) as desirability";

	// tables
	$pq .= "
		from Paper\n";

	if (isset($options['reviewId']))
	    $pq .= "		join PaperReview as ReviewSelector on (ReviewSelector.paperId=Paper.paperId)\n";
	if (isset($options['commentId']))
	    $pq .= "		join PaperComment as CommentSelector on (CommentSelector.paperId=Paper.paperId)\n";
	
	if (isset($options['author']))
	    $pq .= "		join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.conflictType=" . CONFLICT_AUTHOR . " and PaperConflict.contactId=$contactId)\n";
	else
	    $pq .= "		left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$contactId)\n";

	if (isset($options['joins']))
	    foreach ($options['joins'] as $jt)
		$pq .= "		join $jt\n";
	
	$pq .= "		left join PaperReview as AllReviews on (AllReviews.paperId=Paper.paperId)\n";

	if (isset($options['myReviewRequests']))
	    $pq .= "		join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.requestedBy=$reviewContactId and PaperReview.reviewType=" . REVIEW_EXTERNAL . ")\n";
	else if (isset($options['myReviews']))
	    $pq .= "		join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$reviewContactId)\n";
	else if (isset($options['myOutstandingReviews']))
	    $pq .= "		join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$reviewContactId and PaperReview.reviewNeedsSubmit>0)\n";
	else if (isset($options['myReviewsOpt']))
	    $pq .= "		left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$reviewContactId)\n";
	else if (isset($options['allReviews']) || isset($options['allReviewScores']))
	    $pq .= "		join PaperReview on (PaperReview.paperId=Paper.paperId)\n";
	else if (!isset($options['author']))
	    $pq .= "		left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$reviewContactId)\n";
	if ($allReviewerQuery)
	    $pq .= "		left join PaperReview as MyPaperReview on (MyPaperReview.paperId=Paper.paperId and MyPaperReview.contactId=$reviewContactId)\n";

	if (isset($options['reviewerName']))
	    $pq .= "		left join ContactInfo as ReviewerContactInfo on (ReviewerContactInfo.contactId=PaperReview.contactId)\n";
	
	if (isset($options['topics']) || isset($options['topicInterestScore'])) {
	    $pq .= "		left join (select paperId";
	    if (isset($options['topics']))
		$pq .= ", group_concat(PaperTopic.topicId) as topicIds, group_concat(TopicInterest.interest) as topicInterest";
	    if (isset($options['topicInterestScore']))
		$pq .= ", sum(if(interest=2,2,interest-1)) as topicInterestScore";
	    $pq .= " from PaperTopic left join TopicInterest on (TopicInterest.topicId=PaperTopic.topicId and TopicInterest.contactId=$reviewContactId) group by paperId) as PaperTopics on (PaperTopics.paperId=Paper.paperId)\n";
	}

	if (isset($options['options']) && defval($this->settings["paperOption"])) {
	    $pq .= "		left join (select paperId, group_concat(PaperOption.optionId) as optionIds from PaperOption group by paperId) as PaperOptions on (PaperOptions.paperId=Paper.paperId)\n";
	}

	if (isset($options['tags']))
	    $pq .= "		left join (select paperId, group_concat(tag, '#', tagIndex order by tag separator ' ') as paperTags from PaperTag group by paperId) as PaperTags on (PaperTags.paperId=Paper.paperId)\n";

	if (isset($options['scores'])) {
	    $pq .= "		left join (select paperId";
	    foreach ($options['scores'] as $field)
		$pq .= ", group_concat($field) as ${field}Scores";
	    $pq .= " from PaperReview where reviewSubmitted>0 group by paperId) as PaperScores on (PaperScores.paperId=Paper.paperId)\n";
	}
	
	if (defval($options['reviewerPreference']))
	    $pq .= "		left join PaperReviewPreference on (PaperReviewPreference.paperId=Paper.paperId and PaperReviewPreference.contactId=$reviewContactId)\n";
	if (defval($options['allReviewerPreference'])
	    || defval($options['desirability'])) {
	    $subq = "select paperId";
	    if (defval($options['allReviewerPreference']))
		$subq .= ", group_concat(concat(lastName,':::',firstName,':::',preference) order by preference desc, lastName, firstName separator ';;;') as allReviewerPreference";
	    if (defval($options['desirability']))
		$subq .= ", sum(greatest(least(preference,1),-1)) as desirability";
	    $subq .= " from PaperReviewPreference";
	    if (defval($options['allReviewerPreference']))
		$subq .= " join ContactInfo using (contactId)";
	    $subq .= " group by paperId";
	    $pq .= "		left join ($subq) as APRP on (APRP.paperId=Paper.paperId)\n";
	}


	// conditions
	if (isset($options['paperId']))
	    $where[] = $this->_paperQuery_where($options['paperId'], "Paper.paperId");
	if (isset($options['reviewId']))
	    $where[] = $this->_paperQuery_where($options['reviewId'], "ReviewSelector.reviewId");
	if (isset($options['commentId']))
	    $where[] = $this->_paperQuery_where($options['commentId'], "CommentSelector.commentId");
	if (isset($options['finalized']))
	    $where[] = "timeSubmitted>0";
	if (isset($options['accepted']))
	    $where[] = "outcome>0";
	if (isset($options['undecided']))
	    $where[] = "outcome=0";
	if (isset($options['active']))
	    $where[] = "timeWithdrawn<=0";
	
	if (isset($where))
	    $pq .= "		where " . join(" and ", $where) . "\n";

	// grouping and ordering
	if ($reviewerQuery || $scoresQuery)
	    $pq .= "		group by Paper.paperId, PaperReview.reviewId\n";
	else
	    $pq .= "		group by Paper.paperId\n";
	if (isset($options['order']) && $options['order'] != "order by Paper.paperId")
	    $pq .= "		" . $options['order'];
	else {
	    $pq .= "		order by Paper.paperId";
	    if ($reviewerQuery || $scoresQuery)
		$pq .= ", PaperReview.reviewOrdinal";
	}

	//$this->infoMsg(htmlspecialchars($pq));
	return $pq . "\n";
    }

    function paperRow($sel, $contactId = -1, &$whyNot = null) {
	$whyNot = array();
	if (!is_array($sel))
	    $sel = array('paperId' => $sel);
	if (isset($sel['paperId']))
	    $whyNot['paperId'] = $sel['paperId'];
	if (isset($sel['reviewId']))
	    $whyNot['reviewId'] = $sel['reviewId'];
	
	if (isset($sel['paperId']) && cvtint($sel['paperId']) < 0)
	    $whyNot['invalidId'] = 'paper';
	else if (isset($sel['reviewId']) && cvtint($sel['reviewId']) < 0)
	    $whyNot['invalidId'] = 'review';
	else {
	    $q = $this->paperQuery($contactId, $sel);
	    $result = $this->q($q);
	    
	    if (!$result)
		$whyNot['dbError'] = "Database error while fetching paper (" . htmlspecialchars($q) . "): " . mysql_error($this->dblink);
	    else if (edb_nrows($result) == 0)
		$whyNot['noPaper'] = 1;
	    else
		return edb_orow($result);
	}

	return null;
    }

    
    function paperContactAuthors($paperId) {
	$result = $this->qe("select firstName, lastName, email, contactId from ContactInfo join PaperConflict using (contactId) where paperId=$paperId and conflictType=" . CONFLICT_AUTHOR, "while looking up paper contact authors");
	$aus = array();
	while (($row = edb_row($result)))
	    $aus[] = $row;
	return $aus;
    }


    function reviewRow($selector, &$whyNot = null) {
	$whyNot = array();

	if (!is_array($selector))
	    $selector = array('reviewId' => $selector);
	if (isset($selector['reviewId'])) {
	    $whyNot['reviewId'] = $selector['reviewId'];
	    if (($reviewId = cvtint($selector['reviewId'])) <= 0) {
		$whyNot['invalidId'] = 'review';
		return null;
	    }
	}
	if (isset($selector['paperId'])) {
	    $whyNot['paperId'] = $selector['paperId'];
	    if (($paperId = cvtint($selector['paperId'])) <= 0) {
		$whyNot['invalidId'] = 'paper';
		return null;
	    }
	}
	
	$q = "select PaperReview.*,
		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email,
		ReqCI.firstName as reqFirstName, ReqCI.lastName as reqLastName, ReqCI.email as reqEmail, ReqCI.contactId as reqContactId
		from PaperReview
		join ContactInfo using (contactId)
		left join ContactInfo as ReqCI on (ReqCI.contactId=PaperReview.requestedBy)\n";

	$where = array();
	if (isset($reviewId))
	    $where[] = "PaperReview.reviewId=$reviewId";
	if (isset($paperId))
	    $where[] = "PaperReview.paperId=$paperId";
	if (isset($selector['contactId']))
	    $where[] = "PaperReview.contactId=" . cvtint($selector['contactId']);
	if (isset($selector['reviewOrdinal']))
	    $where[] = "PaperReview.reviewSubmitted>0 and reviewOrdinal=" . cvtint($selector['reviewOrdinal']);
	else if (isset($selector['submitted']))
	    $where[] = "PaperReview.reviewSubmitted>0";
	if (!count($where)) {
	    $whyNot['internal'] = 1;
	    return null;
	}

	$q = $q . " where " . join(" and ", $where) . " group by PaperReview.reviewId order by paperId, reviewOrdinal, reviewType desc, reviewId";

	$result = $this->q($q);
	if (!$result) {
	    $whyNot['dbError'] = "Database error while fetching review (" . htmlspecialchars($q) . "): " . htmlspecialchars(mysql_error($this->dblink));
	    return null;
	}

	$x = array();
	while (($row = edb_orow($result)))
	    $x[] = $row;
	
	if (isset($selector['array']))
	    return $x;
	else if (count($x) == 1)
	    return $x[0];
	if (count($x) == 0)
	    $whyNot['noReview'] = 1;
	else
	    $whyNot['multipleReviews'] = 1;
	return null;
    }
	

    //
    // Message routines
    //
    function msg($text, $type) {
	$x = "<div class=\"$type\">$text</div>\n";
	if ($this->saveMessages)
	    $_SESSION["msgs"][] = $x;
	else if ($this->tableMessages)
	    echo "<tr>\n  <td class='caption'></td>\n  <td class='entry' colspan='", $this->tableMessages, "'>", $x, "</td>\n</tr>\n\n";
	else
	    echo $x;
    }

    function infoMsg($text) {
	$this->msg($text, 'info');
    }

    function warnMsg($text) {
	$this->msg($text, 'warning');
    }

    function confirmMsg($text) {
	$this->msg($text, 'confirm');
    }

    function errorMsg($text) {
	$this->msg($text, 'error');
	return false;
    }

    function errorMsgExit($text) {
	if ($text)
	    $this->msg($text, 'error');
	$this->footer();
	exit;
    }

    function tableMsg($colspan, $obj = null) {
	$this->tableMessages = $colspan;
	$this->tableMessagesObj = $obj;
    }


    function ajaxExit($others = null) {
	$t = "";
	if (isset($_SESSION["msgs"])) {
	    foreach ($_SESSION["msgs"] as $msg)
		if (preg_match('|\A<div class="(.*?)">(.*)</div>\Z|', $msg, $m))
		    $t .= "<span class=\"$m[1]\">$m[2]</span>\n";
	    unset($_SESSION["msgs"]);
	}
	header("Content-Type: text/json");
	echo "({ \"response\": \"", addcslashes($t, "\r\n\"\\"), "\"";
	if ($others)
	    foreach ($others as $name => $value) {
		echo ",\n\"", $name, "\": ";
		if ($value === null || $value === 0 || $value === false)
		    echo "0";
		else if ($value === true || $value === 1)
		    echo "1";
		else
		    echo "\"", addcslashes($value, "\r\n\"\\"), "\"";
	    }
	echo " })";
	exit;
    }
    

    //
    // Conference header, footer
    //
    function header_head($title) {
	global $ConfSiteBase, $ConfSitePATH, $Opt;
	if (!$this->headerPrinted) {
	    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Strict//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\">\n";
	    // PUBLIC \"-//W3C//DTD HTML 4.01//EN\" \"http://www.w3.org/TR/html4/strict.dtd\">\n";
	    echo "<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"en\" lang=\"en\">\n<head>\n";
	    echo "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" />\n";
	    echo "<meta http-equiv=\"Content-Style-Type\" content=\"text/css\" />\n";
	    echo "<meta http-equiv=\"Content-Script-Type\" content=\"text/javascript\" />\n";
	    if (strstr($title, "<") !== false)
		$title = preg_replace("/<([^>\"']|'[^']*'|\"[^\"]*\")*>/", "", $title);
	    echo "<script type='text/javascript' src='${ConfSiteBase}cacheable.php?file=script.js&amp;mtime=" . filemtime("$ConfSitePATH/script.js") . "'></script>\n";
	    echo "<link rel='stylesheet' type='text/css' href='${ConfSiteBase}cacheable.php?file=style.css&amp;mtime=" . filemtime("$ConfSitePATH/style.css") . "' />\n";
	    echo "<title>", $title, " - ", $this->shortName, "</title>\n";
	    $this->headerPrinted = 1;
	}
    }

    function header($title, $id = "", $actionBar = "<div class='nvubar'></div>", $showTitle = true) {
	global $ConfSiteBase, $Opt;
	if ($this->headerPrinted >= 2)
	    return;
	
	$this->header_head($title);
	$time = time();
	// JavaScript's idea of a timezone offset is the negative of PHP's
	echo "</head><body";
	if ($id)
	    echo " id='$id'";
	echo " onload='hotcrpLoad($time, ", -date("Z", $time) / 60, ")'>\n";
	echo "<div id='header'>\n";
	echo "  <div id='header_left'><h1>";
	if ($title && $showTitle && $title == "Home")
	    echo htmlspecialchars($this->shortName);
	else
	    echo "<a href='${ConfSiteBase}index.php'>", htmlspecialchars($this->shortName), "</a><span class='crumbsep'> &nbsp;&gt;&nbsp; </span>", $title;
	echo "</h1></div><div id='header_user'>";
	if ($_SESSION["Me"]->valid()) {
	    echo "<strong>",
		htmlspecialchars($_SESSION["Me"]->email),
		"</strong> &nbsp;|&nbsp; ";
	    if (!$_SESSION["Me"]->privChair && $_SESSION["Me"]->chairContactId > 0)
		echo "<a href=\"", htmlspecialchars(selfHref(array("chairMode" => 0))), "\">Return&nbsp;to&nbsp;chair&nbsp;view</a> &nbsp;|&nbsp; ";
	    echo "<a href='", $ConfSiteBase, "help.php";
	    if ($id == "search")
		echo "?t=", $id;
	    else if ($id == "settings")
		echo "?t=chair";
	    echo "'>Help</a> &nbsp;|&nbsp; ";
	    echo "<a href='", $ConfSiteBase, "logout.php'>Sign&nbsp;out</a> &nbsp;|&nbsp; ";
	}
	echo date("l j M Y g:i:sa T", $time), "<br /><span id='usertime'></span></div>\n";
	//echo "    <div id='header_public'><a href=\"", htmlspecialchars($Opt['conferenceSite']), "\">", $this->shortName, " Public Site</a></div>\n";
	
	print "  <div class='clear'></div>\n</div>";

	print $actionBar;

	print "<div class='body'>\n";
	
	if (isset($_SESSION["msgs"])) {
	    foreach ($_SESSION["msgs"] as $m)
		echo $m;
	    unset($_SESSION["msgs"]);
	}
	$this->saveMessages = false;

	$this->headerPrinted = 2;
    }

    function footer() {
	global $ConfSiteBase;

	if ($this->tableMessages) {
	    echo "</table>\n\n";
	    if ($this->tableMessagesObj)
		$this->tableMessagesObj->echoDivExit();
	}
	echo "</div>\n"; // <div class='body'>
	echo "<div id='footer'>\n";
	echo "  <div id='footer_crp'><a href='http://www.cs.ucla.edu/~kohler/hotcrp/'>HotCRP</a> Conference Management Software</div>\n";
	echo "  <div class='clear'></div></div>\n";
	echo $this->footerStuff;
	echo "</body>\n</html>\n";
    }

    function getFileExtension(&$mimetype) {
	if (!isset($mimetype) || $mimetype == null || $mimetype == "")
	    return "";
	else if ($mimetype == "application/postscript")
	    return ".ps";
	else if ($mimetype == "application/pdf")
	    return ".pdf";
	else if ($mimetype == "text/plain")
	    return ".txt";
	else
	    return "";
    }

    function makeDownloadPath($paperId, &$mimetype, $final = false) {
	global $ConfSiteBase;
	return $ConfSiteBase . "Download/GetPaper/" . $this->downloadPaperName($paperId, $mimetype, $final);
    }

  
    function allowEmailTo($email) {
	global $Opt;
	return $Opt['sendEmail'] && strstr($email, "@")
	    && !preg_match('/@_\.com$/', $email);
    }
  
    function log($text, $who, $paperId = null) {
	if (is_array($paperId)) {
	    if (count($paperId) == 0)
		$paperId = null;
	    else if (count($paperId) == 1)
		$paperId = $paperId[0];
	    else {
		$text .= " (papers " . join(", ", $paperId) . ")";
		$paperId = null;
	    }
	}
	if ($paperId === null)
	    $paperId = "null";
	$this->q("insert into ActionLog (ipaddr, contactId, paperId, action) values ('" . sqlq($_SERVER['REMOTE_ADDR']) . "', " . (is_numeric($who) ? $who : $who->contactId) . ", $paperId, '" . sqlq($text) . "')");
    }

    
    function textValuesGraph($v, $max, $style) {
	global $ConfSiteBase;
	
	if (is_string($v))
	    $v = scoreCounts($v, $max);

	$avgtext = ($v->n > 1 ? sprintf("%0.2f &plusmn; %0.2f", $v->avg, $v->stddev) : sprintf("%0.2f", $v->avg));

	$url = "";
	for ($key = 1; $key <= $v->max; $key++)
	    $url .= ($url == "" ? "" : ",") . $v->v[$key];
	$url = "${ConfSiteBase}images/GenChart.php?v=$url";

	if ($style == 0) {
	    $retstr = "<table><tr><td align=\"center\">";
	    $retstr .= "<img src=\"" . htmlspecialchars($url) . "\" alt=\"$avgtext\" title=\"$avgtext\" align='bottom' />";
	    $retstr .= "</td><tr><td align=\"center\"><span class='sc_sum'>$avgtext</span></td></tr></table>";
	} else if ($style == 1) {
	    $retstr = "<img src=\"" . htmlspecialchars($url) . "&amp;s=1\" alt=\"$avgtext\" title=\"$avgtext\" width='" . (5 * $v->max + 3)
		. "' height='" . (5 * max(3, max($v->v)) + 3) . "' />";
	} else if ($style == 2) {
	    $retstr = "<img src=\"" . htmlspecialchars($url) . "&amp;s=2\" alt=\"$avgtext\" title=\"$avgtext\" /><br />";
	    for ($key = 1; $key <= $v->max; $key++)
		$retstr .= ($key > 1 ? " " : "") . "<span class='sc$key'>" . $v->v[$key] . "</span>";
	    $retstr .= "<br /><span class='sc_sum'>" . $avgtext . "</span>";
	}
	
	return $retstr;
    }
    
}
