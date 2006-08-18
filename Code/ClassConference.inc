<?php 

class Conference {
    
    var $connected;
    var $sessionName; // name of this session, security
    var $shortName;
    var $longName;
    var $theme;
    var $paperSite;
    var $conferenceSite;
    var $downloadPrefix;
    var $contactName;
    var $contactEmail;
    var $emailFrom;
    var $allowEmail;
    //
    // Paper formats
    //
    var $allowPDF;
    var $allowPS;

    var $allowReviewerPreferences;
  
    //
    // Database specific stuff
    //
    var $dsn;
    var $DB;

    //
    // Various optional deadlines
    //
    var $submissionDeadline;
    var $reviewDeadline;
    
    var $startTime;
    var $endTime;

    var $saveMessages;
    var $headerPrinted;

    //
    // Useful colors
    //
    var $bgOne = "#9999cc"; // blue
    var $bgTwo = "#669999"; // green
    var $bgInfo = "#ffcccc" ; // gred - uses in informational messages
    var $contrastColorOne = "#eeeeee";
    var $contrastColorTwo = "#cccccc";

    var $taskHeaderColor = "#006633";
    var $headerBg = "#eeeeff";

    var $infoColor = "yellow"; // blue
    var $confirmColor = "lime"; // green
    var $errorColor = "fuchsia"; // should be pink!
    var $warnColor = "lightBlue"; // should be pink!

    function Conference($dbUser, $dbPassword, $dbHost, $dbName) {
	$this->dbHost = $dbHost;
	$this->dbUser = $dbUser;
	$this->dbPassword = $dbPassword;
	$this->dbName = $dbName;
	$this->dsn = "mysql://" . ($this->dbUser) . ":" . ($this->dbPassword) . "@" . ($this->dbHost) . "/" . ($this->dbName) ;
	$this->connected = 0;
	$this->startTime = array();
	$this->endTime = array();
	$this->saveMessages = 1;
	$this->headerPrinted = 0;
    }

  function dump() {
      global $Opt;
      echo "<table border=\"1\" width=\"83%\" bgcolor=$bgOne>";
      echo "<tr> <td>";
      echo "<p>";

      echo "setupPhase is " . $Opt['setupPhase'] . " <br>";
      echo "shortName is " . $this -> shortName . " <br>";
      echo "longName is " . $this -> longName . " <br>";
      //      echo "theme is " . $this -> theme . " <br>";
      echo "paperSite is " . $this -> paperSite . " <br>";
      echo "conferenceSite is " . $this -> conferenceSite . " <br>";
      echo "downloadPrefix is " . $this->downloadPrefix . " <br>";
      echo "contactName is " . $this -> contactName . " <br>";
      echo "contactEmail is " . $this -> contactEmail . " <br>";
      echo "emailFrom is " . $this -> emailFrom . " <br>";
      echo "dbHost is " . $this -> dbHost . " <br>";
      echo "dbUser is " . $this -> dbUser . " <br>";
      echo "dbPassword is " . $this -> dbPassword . " <br>";
      echo "dbDumpDir is " . $this -> dbDumpDir . " <br>";
      echo "dbName is " . $this -> dbName . " <br>";
      echo "dbhandle is " . $this -> dbhandle . " <br>";

      echo "Starting times: <br>";
      reset($this -> startTime);
      while(list($key,$value) = each($this -> startTime)) {
	echo "startTime($key) " . $value . " <br>";
      }

      echo "Ending times: <br>";
      reset($this -> endTime);
      while(list($key,$value) = each($this -> endTime)) {
	echo "endTime($key) " . $value . " <br>";
      }

      echo "</p> </td> </tr> </table>";
    }

  //
  // Initialization functions
  //

  function connect() {
      if (!$this->connected) {
	  if (!IsSet($this->dsn)) {
	      $this->errorMsg("Package misconfigured: dsn is not set");
	  }

	  $this->DB = DB::connect($this->dsn);

	  if (DB::isError($this->DB)) {
	      $this->errorMsg("Unable to connect to database");
	      die($this->DB->getMessage());
	  }

	  $this->connected = 1;
	  $this->updateImportantDates();
      }
  }

    function updateImportantDates() {
	// Suck in the start/end dates
	$this->startTime = array();
	$this->endTime = array();
	$result = $this->q("select name, unix_timestamp(start) as st, unix_timestamp(end) as en from ImportantDates");
	if (!DB::isError($result))
	    while ($row = $result->fetchRow()) {
		$this->startTime[$row[0]] = $row[1];
		$this->endTime[$row[0]] = $row[2];
	    }
	foreach (array("PCReviewAnyPaper", "reviewerViewReviews",
		       "notifyChairAboutReviews") as $x)
	    if (!isset($this->startTime[$x]))
		$this->startTime[$x] = 0;
	if (!isset($this->startTime["blindSubmission"]))
	    $this->startTime["blindSubmission"] = 2;
    }

    function q($query) {
	global $OK;
	if (!$this->connected)
	    $this->connect();
	$result = $this->DB->query($query);
	if (DB::isError($result))
	    $OK = 0;
	return $result;
    }

    function dbErrorText($result, $while = "", $suggestRetry = 1) {
	if ($result != null) {
	    $text = "<p>Database error";
	    if ($while)
		$text .= " $while";
	    $text .= ": " . htmlspecialchars($result->getMessage()) . "</p>";
	}
	if ($suggestRetry)
	    $text .= "\n<p>Please try again or contact the site administrator at $this->emailFrom.</p>";
	return $text;
    }
  
    function qe($query, $while = "", $suggestRetry = 0) {
	global $OK;
	if (!$this->connected)
	    $this->connect();
	$result = $this->DB->query($query);
	if (DB::isError($result)) {
	    $this->errorMsg($this->dbErrorText($result, $while . " (" . htmlspecialchars($query) . ")", $suggestRetry));
	    $OK = 0;
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
	    return 0;
	
	$result = $this->q(sprintf("select contactId from ContactInfo where email='%s'", sqlq($email)));
	if ($result && $result->numRows() > 0) {
	    $row = $result->fetchRow();
	    return $row[0];
	}

	if ($registerFromRequest === false)
	    return 0;

	// validate email address
	require_once("Mail/RFC822.php");
	$rfc822 = new Mail_RFC822($email);
	$emailForValidate = $email;
	if (!$rfc822->validateMailbox($emailForValidate))
	    return 0;
	
	$p = (is_string($registerFromRequest) ? $registerFromRequest : "");
	
	// try to add them
	$newguy = new Contact();
	$result = $newguy->initialize($email, $this);
	if (!DB::isError($result)) {
	    
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
	
	if (!DB::isError($result)) {
	    $this->infoMsg("Created account for " . htmlspecialchars($email) . ".");
	    $newguy->sendAccountInfo($this, true);
	    $this->log("$Me->contactId created account", $newguy);
	} else
	    $this->log("$Me->contactId account creation failure", $newguy);
	
	return $newguy->contactId;
    }


  function listMissingReviews($contactId)
    {
      //
      // Make certain that the author has submitted all their reviews
      // prior to viewing their own reviews

      $missingReviews=0;

      //
      //
      // First, check to see if I should have review for requests...
      //
      $q = "select paperId from PaperReview where contactId=$contactId and reviewType>0 and reviewSubmitted<=0 order by paperId";

      $result = $this->qe($q);
      if (!DB::isError($result))
	  while($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
	      $this->errorMsg("Missing review for paper #" . $row['paperId']);
	      $missingReviews = 1;
	  }
      
      return $missingReviews;
    }


    //
    // Can be overridden by subclass
    //
    function parseableTime($value) {
	return date("j M Y g:i:sa",$value);
    }
    function printableTime(&$value) {
	if (isset($value) && $value > 0)
	    return date("l j M Y g:i:sa T", $value);
	else
	    return "N/A";
    }
    function printableStartTime($what) {
	return $this->printableTime($this->startTime[$what]);
    }
    function printableEndTime($what) {
	return $this->printableTime($this->endTime[$what]);
    }
    function printDeadline($what) {
	if (!isset($this->endTime[$what]) || $this->endTime[$what] <= 0)
	    return "No deadline";
	else
	    return "Deadline: " . $this->printableTime($this->endTime[$what]);
    }

    function printableTimeRange($what, $type = "until") {
	$start = &$this->startTime[$what];
	$end = &$this->endTime[$what];
	$now = time();
      
	$str = "";
	if (isset($start) && $start > 0 && $start >= $now) {
	    $str = "after " . $this->printableTime($start);
	    if (isset($end) && $end > 0)
		$str .= " and ";
	}

	if (isset($end) && $end > 0) {
	    $str .= ($type == "by" ? "by " : "until ");
	    $str .= $this->printableTime($end);
	}
	
	return $str;
    }

    function validPeriod($name) {
	$now = time();
	$t = &$this->startTime[$name];
	if (!isset($t) || $t <= 0 || $t >= $now)
	    return 0;
	$t = &$this->endTime[$name];
	return !isset($t) || $t <= 0 || $now <= $t;
    }

    function timeStartPaper() {
	return $this->validPeriod('startPaperSubmission') > 0;
    }
    function timeUpdatePaper() {
	return $this->validPeriod('updatePaperSubmission') > 0;
    }
    function timeFinalizePaper() {
	return $this->validPeriod('finalizePaperSubmission') > 0;
    }
    function timeAuthorViewReviews() {
	return $this->validPeriod('authorViewReviews') > 0;
    }
    function timeAuthorViewDecision() {
	return $this->validPeriod('authorViewDecision') > 0;
    }
    function timePCReviewPreferences() {
	return $this->validPeriod('PCReviewPreferences') > 0;
    }
    function timeReviewOpen() {
	$st = defval($this->startTime['reviewerSubmitReview'], 0);
	return $st > 0 && time() >= $st;
    }
    function timeReviewPaper($isPC = false, $requested = true, $hard = true) {
	if (!($requested
	      || ($isPC && $this->startTime["PCReviewAnyPaper"] > 0)))
	    return false;
	$d = ($isPC ? "PC" : "reviewer") . "SubmitReview" . ($hard ? "Deadline" : "");
	return $this->validPeriod($d) > 0;
    }
    function timePCViewSubmission() {
	return true;
    }
    function timePCViewAllReviews() {
	return $this->validPeriod("PCMeetingView") > 0;
    }
    function timeReviewerViewSubmission() {
	return true;
    }
    function timeEmailChairAboutReview() {
	return $this->startTime["notifyChairAboutReviews"] > 0;
    }
    function timeEmailAuthorsAboutReview() {
	return $this->validPeriod("authorViewReviews") > 0;
    }

    function blindSubmission() {
	return $this->startTime['blindSubmission'];
    }
    

    //
    // Is this particular event/deadline defined?
    //
    function validDeadline($what) {
	return isset($this->startTime[$what]) && isset($this->endTime[$what])
	    && $this->startTime[$what] > 0 && $this->endTime[$what] > 0;
    }

    //
    // 'default' is returned in the time is not set
    // OR if the time value was specified as zero
    //
    function validTimeFor($what, $default) {
	if (!isset($this->startTime[$what]))
	    return $default;
	$start = $this->startTime[$what];
	$end = $this->endTime[$what];
	if ($start <= 0)
	    return $default;

	$now = time();
	if ($now < $start || (IsSet($end) && $end > 0 && $now > $end))
	    return false;
	else
	    return true;
    }

  function go($url) {
      header("Location: $url");
      echo "<html>";
      echo "<body> <p> You should not be here. ";
      echo "You need to <a href=\"$url\"> login </a> </p> </body>";
      echo "</html>";
      exit();
  }

  function goIfInvalidActivity($what,$url) {
      //
      // Hack to avoid modifying lots of files
      //
      if ( ! $this -> connected ) {
	$this -> connect();
      }
      if ( ! $this -> validTimeFor($what, 0) ) {
	$this -> go($url);
      }
    }


    // Emailing

    function emailContactAuthors($prow, $subject, $message) {
	global $Opt, $Me;
	if (!$Opt['sendEmail'])
	    return;

	if ($subject[0] != '[')
	    $subject = "[$this->shortName] $subject";
	$result = $this->qe("select firstName, lastName, email
		from ContactInfo join PaperConflict using (contactId)
		where paperId=$prow->paperId and author>0
		group by ContactInfo.contactId", "while looking up contact authors to send email");
	if (!DB::isError($result)) {
	    while (($row = $result->fetchRow())) {
		$contacts[] = $c = contactText($row[0], $row[1], $row[2]);
		$m = "Dear $c,\n\n" . $message;
		if ($this->allowEmailTo($row[2]))
		    mail($row[2], $subject, $m, "From: $Conf->emailFrom");
		else
		    $this->infoMsg("<pre>" . htmlspecialchars($subject) . "\n\n" . htmlspecialchars($m) . "</pre>");
	    }
	    if ($prow->author <= 0 && count($contacts) > 0 && $Me->amAssistant())
		$this->infoMsg("Sent \"" . htmlspecialchars($subject) . "\" email to paper #$prow->paperId's contact author" . (count($contacts) == 1 ? "" : "s") . ", " . htmlspecialchars(join(", ", $contacts)) . ".");
	}
    }
    
  
    //
    // Paper storage
    //

    function storePaper($uploadId, $prow) {
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
	    if ($this->allowPDF && strncasecmp("%PDF-", $contents, 5) == 0)
		$mimetype = "application/pdf";
	    else if ($this->allowPS && strncasecmp("%!PS-", $contents, 5) == 0)
		$mimetype = "application/postscript";
	    else {
		$mimetype = "application/octet-stream";
		if (isset($_FILES[$uploadid]["type"]))
		    $mimetype = $_FILES[$uploadid]["type"];
		$message = "You appear to be submitting a file that's not in a valid format for this conference.  ";
		if ($this->allowPDF && $this->allowPS)
		    $message .= "Valid formats are PDF and PostScript.  ";
		else if ($this->allowPDF)
		    $message .= "PDF is the only valid format.  ";
		else if ($this->allowPS)
		    $message .= "PostScript is the only valid format.  ";
		else
		    $message .= "Internal error: there are no valid formats.  ";
		$message .= "You tried to upload a file with MIME type '" . htmlentities($mimetype) . "'; ";
		$message .= "its first 10 bytes are " . htmlspecialchars(substr($contents, 0, 5)) . ".<br/><br/>Please check the file contents; if you think this message is in error, contact the site administrator.";
		return $this->errorMsg($message);
	    }
	}

	$result = $this->qe("lock tables Paper write, PaperStorage write", $while);
	if (DB::isError($result))
	    return $result;

	$timestamp = time();
	$result = $this->qe("insert into PaperStorage set paperId=$paperId, timestamp=$timestamp, mimetype='" . sqlq($mimetype) . "', paper='" . sqlq(substr($contents, 0, 400000)) . "'", $while);
	if (!DB::isError($result))
	    $result = $this->qe("select last_insert_id()", $while);
	if (!DB::isError($result)) {
	    $row = $result->fetchRow();
	    $paperStorageId = $row[0];
	    for ($pos = 400000; $pos < strlen($contents) && !DB::isError($result); $pos += 400000)
		$result = $this->qe("update PaperStorage set paper=concat(paper,'" . sqlq(substr($contents, $pos, 400000)) . "') where paperStorageId=$paperStorageId", $while);
	}

	// check that paper storage succeeded
	if (!DB::isError($result))
	    $result = $this->qe("select length(paper) from PaperStorage where paperStorageId=$paperStorageId", $while);
	if (!DB::isError($result)) {
	    if ($result->numRows() == 0)
		$result = new PEAR_Error("Paper upload unsuccessful!");
	    else {
		$row = $result->fetchRow();
		if ($row[0] != strlen($contents))
		    $result = new PEAR_Error("Failed to store your paper.  Usually, this is because the file you tried to upload was too big for our system.");
	    }
	    if (PEAR::isError($result))
		$this->errorMsg($result->getMessage());
	}
    
	if (!DB::isError($result))
	    $result = $this->qe("update Paper
		set paperStorageId=$paperStorageId, size=" . strlen($contents) . ", mimetype='" . sqlq($mimetype) . "', timestamp=$timestamp
		where paperId=$paperId and timeWithdrawn<=0 and timeSubmitted<=0", $while);

	$this->qe("unlock tables", $while);

	if (PEAR::isError($result))
	    return $result;

	// potentially email the paper to Greg Minshall
	if ($Opt['emailSubmissions']) {
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
		. wordWrapIndent(trim($prow->authorInformation), "Authors: ") . "\n"
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
	    $mime->addAttachment($contents, $mimetype, $this->downloadPaperName($paperId, $mimetype), false);

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

    function downloadPaperName($paperId, $mimetype) {
	return $this->downloadPrefix . "paper$paperId" . $this->getFileExtension($mimetype);
    }
    
    function __downloadPaper($paperId, $saveit) {
	// Database query
	$result = $this->q("select mimetype, paper, PaperStorage.paperStorageId, compression
	from Paper
	left join PaperStorage using (paperStorageId)
	where Paper.paperId=$paperId");
	if (DB::isError($result)) {
	    $this->log("Error downloading #$paperId for review" . $result->getMessage(), $_SESSION["Me"]);
	    return $result;
	} else if ($result->numRows() == 0)
	    return new PEAR_Error("There is no paper #$paperId.");

	// Check data
	$row = $result->fetchRow();
	$mimetype = $row[0];
	$content = $row[1];
	$compression = $row[3];
	$length = strlen($content);
	if ($row[2] == 1)
	    return new PEAR_Error("Paper #$paperId has not been uploaded yet.");
	else if ($length == 0)
	    return new PEAR_Error("Paper #$paperId appears to be empty.");

	// Print paper
	header("Content-Type: $mimetype");
	header("Content-Description: PHP Generated Data");
	$name = $this->downloadPaperName($paperId, $mimetype);
	header("Content-Disposition: " . ($saveit ? "attachment" : "inline") . "; filename=$name");
	header("Content-Length: $length"); 
	if ($compression == 1)
	    print gzinflate($content);
	else
	    print $content;
	return 1;
    }

    function downloadPaper($paperId, $saveit) {
	$result = $this->__downloadPaper($paperId, $saveit);
	if (DB::isError($result))
	    $this->errorMsg($this->dbErrorText($result, "while downloading paper", 1));
	else if (PEAR::isError($result))
	    $this->errorMsg($result->getMessage());
	else
	    $this->log("Downloaded #$paperId", $_SESSION["Me"]);
	return $result;
    }

    function __downloadPapers($paperIds, &$tmpdir) {
	// special cases
	if (count($paperIds) == 0)
	    return new PEAR_Error("No papers selected for download.");
	else if (count($paperIds) == 1)
	    return $this->__downloadPaper($paperIds[0], 1);

	// paper query
	$q = "select Paper.paperId, paper, mimetype, compression from PaperStorage join Paper using (paperStorageId) where (";
	foreach ($paperIds as $paperId)
	    $downloadQ[] = "Paper.paperId=$paperId";
	$q .= join(" or ", $downloadQ) . ")";
	$result = $this->q($q);
	if (DB::isError($result))
	    return $result;
	
	// set up temporary directory
	if (($tmpdir = tempdir(null, "crp")) === false)
	    return new PEAR_Error("Could not create temporary directory!");

	// write papers into temporary directory
	while ($row = $result->fetchRow()) {
	    $path = "$tmpdir/" . $this->downloadPaperName($row[0], $row[2]);
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
	header("Content-Disposition: attachment; filename=" . $this->downloadPrefix . "papers.zip");
	header("Content-Type: application/zip");
	header("Content-Length: " . strlen($contents));
	print $contents;
	return 1;
    }

    function downloadPapers($paperIds) {
	$result = $this->__downloadPapers($paperIds, $tmpdir);
	if (DB::isError($result))
	    $this->errorMsg($this->dbErrorText($result, "while downloading paper", 1));
	else if (PEAR::isError($result))
	    $this->errorMsg($result->getMessage());
	else
	    $this->log("Downloaded papers from set " . join(", ", $paperIds), $_SESSION["Me"]);
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
	//   "author"		Only papers authored by $contactId
	//   "myReviewRequests"	Only reviews requested by $contactId
	//   "myReviews"	All reviews authored by $contactId
	//   "myReviewsOpt"	myReviews, + include papers not yet reviewed
	//   "allReviews"	All reviews (multiple rows per paper)
	//   "reviewerName"	Include reviewer names
	//   "joinTable"	A table with paperId columns to join
	//   "order" => $sql	$sql is SQL 'order by' clause (or empty)

	$reviewerQuery = isset($options['myReviews']) || isset($options['allReviews']) || isset($options['myReviewRequests']) || isset($options['myReviewsOpt']);
	$contactId = (is_object($contact) ? $contact->contactId : $contact);

	// fields
	$pq = "select Paper.*,
		PaperConflict.author as author,
		PaperConflict.author is not null as conflict,
		count(AllReviews.reviewSubmitted) as reviewCount,
		count(AllReviews.paperId) as startedReviewCount";
	if (!isset($options['author']))
	    $pq .= ",
		PaperReview.reviewType,
		PaperReview.reviewId,
		PaperReview.reviewModified,
		PaperReview.reviewSubmitted,
		PaperReview.contactId as reviewContactId";
	if (isset($options['reviewerName']))
	    $pq .= ",
		ReviewerContactInfo.firstName as reviewFirstName,
		ReviewerContactInfo.lastName as reviewLastName,
		ReviewerContactInfo.email as reviewEmail";
	if ($reviewerQuery) {
	    global $reviewFields;
	    foreach ($reviewFields as $k => $v)
		$pq .= ",\t\tPaperReview.$k as $k";
	}
	if (isset($options['topics']))
	    $pq .= ",
		group_concat(PaperTopic.topicId) as topicIds,
		group_concat(TopicInterest.interest) as topicInterest";
	if (isset($options['topicInterestScore']))
	    $pq .= ",
		sum(if(interest=2,2,interest-1)) as topicInterestScore";
	if (defval($options['reviewerPreference']))
	    $pq .= ",
		ifnull(PaperReviewPreference.preference, 0) as reviewerPreference";
	if (defval($options['allReviewerPreference']))
	    $pq .= ",
		APRP.allReviewerPreference";
	if (defval($options['desirability']))
	    $pq .= ",
		ifnull(APRP.desirability, 0) as desirability";

	// tables
	$pq .= "
		from Paper\n";

	if (isset($options['reviewId']))
	    $pq .= "		join PaperReview as ReviewSelector on (ReviewSelector.paperId=Paper.paperId)\n";
	if (isset($options['commentId']))
	    $pq .= "		join PaperComments as CommentSelector on (CommentSelector.paperId=Paper.paperId)\n";
	
	if (isset($options['author']))
	    $pq .= "		join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.author>0 and PaperConflict.contactId=$contactId)\n";
	else
	    $pq .= "		left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$contactId)\n";

	if (isset($options['joinTable']))
	    $pq .= "		join " . $options['joinTable'] . " on (" . $options['joinTable'] . ".paperId=Paper.paperId)\n";
	
	$pq .= "		left join PaperReview as AllReviews on (AllReviews.paperId=Paper.paperId)\n";

	if (isset($options['myReviewRequests']))
	    $pq .= "		join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.requestedBy=$contactId and PaperReview.reviewType=" . REVIEW_REQUESTED . ")\n";
	else if (isset($options['myReviews']))
	    $pq .= "		join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$contactId)\n";
	else if (isset($options['myReviewsOpt']))
	    $pq .= "		left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$contactId)\n";
	else if (isset($options['allReviews']))
	    $pq .= "		left join PaperReview on (PaperReview.paperId=Paper.paperId)\n";
	else if (!isset($options['author']))
	    $pq .= "		left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$contactId)\n";

	if (isset($options['reviewerName']))
	    $pq .= "		left join ContactInfo as ReviewerContactInfo on (ReviewerContactInfo.contactId=PaperReview.contactId)\n";
	
	if (isset($options['topics']) || isset($options['topicInterestScore']))
	    $pq .= "		left join PaperTopic on (PaperTopic.paperId=Paper.paperId)
		left join TopicInterest on (TopicInterest.topicId=PaperTopic.topicId and TopicInterest.contactId=$contactId)\n";
	
	if (defval($options['reviewerPreference']))
	    $pq .= "		left join PaperReviewPreference on (PaperReviewPreference.paperId=Paper.paperId and PaperReviewPreference.contactId=$contactId)\n";
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
	
	if (isset($where))
	    $pq .= "		where " . join(" and ", $where) . "\n";

	// grouping and ordering
	if ($reviewerQuery)
	    $pq .= "		group by Paper.paperId, PaperReview.reviewId\n";
	else
	    $pq .= "		group by Paper.paperId\n";
	if (isset($options['order']) && $options['order'] != "order by Paper.paperId")
	    $pq .= "		" . $options['order'];
	else {
	    $pq .= "		order by Paper.paperId";
	    if ($reviewerQuery)
		$pq .= ", PaperReview.reviewId";
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
	    
	    if (DB::isError($result))
		$whyNot['dbError'] = "Database error while fetching paper (" . htmlspecialchars($q) . "): " . htmlspecialchars($result->getMessage());
	    else if ($result->numRows() == 0)
		$whyNot['noPaper'] = 1;
	    else
		return $result->fetchRow(DB_FETCHMODE_OBJECT);
	}

	return null;
    }

    
    function paperContactAuthors($paperId) {
	$result = $this->qe("select firstName, lastName, email, contactId from ContactInfo join PaperConflict using (contactId) where paperId=$paperId", "while looking up paper contact authors");
	$aus = array();
	if (!DB::isError($result))
	    while (($row = $result->fetchRow()))
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
		ReqCI.firstName as reqFirstName, ReqCI.lastName as reqLastName, ReqCI.email as reqEmail, ReqCI.contactId as reqContactId,
		count(PRS.reviewId) as reviewOrdinal
		from PaperReview
		join ContactInfo using (contactId)
		left join ContactInfo as ReqCI on (ReqCI.contactId=PaperReview.requestedBy)
		left join PaperReview as PRS on (PaperReview.paperId=PRS.paperId and PRS.reviewSubmitted>0 and PRS.reviewSubmitted<PaperReview.reviewSubmitted)\n";

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

	$q = $q . " where " . join(" and ", $where) . " group by PaperReview.reviewId order by paperId, reviewSubmitted, reviewType desc, reviewId";

	$result = $this->q($q);
	if (DB::isError($result)) {
	    $whyNot['dbError'] = "Database error while fetching review (" . htmlspecialchars($q) . "): " . htmlspecialchars($result->getMessage());
	    return null;
	}

	$x = array();
	while (($row = $result->fetchRow(DB_FETCHMODE_OBJECT)))
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
	$x = "<div class='$type'>$text</div>\n";
	if ($this->saveMessages)
	    $_SESSION["msgs"] = (isset($_SESSION["msgs"]) ? $_SESSION["msgs"] . $x : $x);
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

    //
    // Conference header, footer
    //
    function header_head($title) {
	global $ConfSiteBase;
	if (!$this->headerPrinted) {
	    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">\n";
	    // PUBLIC \"-//W3C//DTD HTML 4.01//EN\" \"http://www.w3.org/TR/html4/strict.dtd\">\n";
	    echo "<html>\n<head>\n";
	    echo "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" />\n";
	    echo "<meta http-equiv=\"Content-Style-Type\" content=\"text/css\" />\n";
	    echo "<title>", $title, " - ", $this->shortName, "</title>\n";
	    echo "<script src=\"${ConfSiteBase}script.js\" type=\"text/javascript\"></script>\n";
	    echo $this->theme;
	    $this->headerPrinted = 1;
	}
    }

    function header($title, $id = "", $actionBar = "") {
	global $ConfSiteBase;
	if ($this->headerPrinted >= 2)
	    return;
	
	$this->header_head($title);
	echo "</head><body>\n";
	echo "<div id='header'>\n";
	echo "  <div id='header_left'>\n";
	echo "    <div id='header_conference'><a href='",
	    $ConfSiteBase, "index.php'>", $this->shortName,
	    " Submissions</a></div>\n";
	echo "    <h1>", $title, "</h1>\n";
	echo "  </div>\n";
	echo "  <div id='header_links'>\n";
	if ($_SESSION["Me"]->valid()) {
	    echo "    <div id='header_user'><strong>",
		htmlspecialchars($_SESSION["Me"]->email),
		"</strong> &nbsp;|&nbsp; ";
	    if (!$_SESSION["Me"]->amAssistant() && $_SESSION["Me"]->chairContactId > 0)
		echo "<a href=\"", htmlspecialchars(selfHref(array("chairMode" => 0))), "\">Return&nbsp;to&nbsp;chair&nbsp;view</a> &nbsp;|&nbsp; ";
	    echo "<a href='", $ConfSiteBase, "logout.php'>Log&nbsp;out</a></div>\n";
	}
	echo "    <div id='header_time'>", date ("l j M Y g:i:sa T"), "</div>\n";
	echo "    <div id='header_public'><a href='", $this->conferenceSite, "'>", $this->shortName, " Public Site</a></div>\n";
	print "  </div>\n";
	
	$id = ($id ? " id='$id'" : "");
	print "  <div class='clear'></div>\n</div>\n";

	print $actionBar;

	print "<div class='body'$id>\n";
	
	if (isset($_SESSION["msgs"])) {
	    echo $_SESSION["msgs"];
	    unset($_SESSION["msgs"]);
	}
	$this->saveMessages = 0;

	$this->headerPrinted = 2;
    }

    function footer() {
	global $ConfSiteBase;
	//
	// Output standard footer - button to go to main submission
	// site, button to close & pointer to CRP at sourceforge
	//
	echo "</div>\n"; // <div id='body'>
	echo "<div id='footer'>\n";
	echo "  <div id='footer_left'>\n";
	echo "    <div id='footer_conference'><a href='", $ConfSiteBase, "index.php'>", $this->shortName, " Submissions Home</a></div>\n";
	$Me = $_SESSION["Me"];
	if ($Me->valid()) {
	    if (!$Me->amAssistant() && $Me->chairContactId > 0)
		echo "    <div id='footer_chairmode'><a href=\"", htmlspecialchars(selfHref(array("chairMode" => 0))), "\">Return&nbsp;to&nbsp;chair&nbsp;view</a></div>\n";
	    echo "    <div id='footer_logout'><a href='", $ConfSiteBase, "logout.php'>Log&nbsp;out</a></div>\n";
	}
	echo "  </div>\n";
	echo "  <div id='footer_right'>\n";
	echo "    <div id='footer_crp'><a href='http://crp.sourceforge.net/'>CRP</a> Conference Management Software</div>\n";
	echo "  </div><div class='clear'></div></div>\n";
	echo "</body>\n</html>\n";
    }

  function mkTextButton($text, $url, $extra="") {
      return "<form method=\"POST\" action=\"$url\">"
	  . "<input type=\"submit\" value=\"$text\" name=\"textButton\" />"
	  . $extra
	  . "</form>";
  }

  function mkTextButtonPopup($text, $url, $extra="") {
      return "<form method=\"POST\" action=\"$url\" target=_blank>"
	  . "<input type=\"submit\" value=\"$text\" name=\"textButton\" />"
	  . $extra
	  . "</form>";
  }

  function textButton($text, $url, $extra="") {
      print $this->mkTextButton($text, $url, $extra);
  }

  function textButtonPopup($text, $url, $extra="") {
      print $this->mkTextButtonPopup($text, $url, $extra);
  }

  function linkWithPaperId($text, $url, $paperId) {
      print "<a href=\"$url?paperId=$paperId\">" . $text . "</a>";
      //      $this->textButton($text, $url,
      //		 $this->mkHiddenVar("paperId", $paperId)
      //		 );
  }

  function buttonWithPaperId($text, $url, $paperId) {
      $this->textButton($text, $url,
			$this->mkHiddenVar("paperId", $paperId)
			);
  }

  //
  // Functions that make use of the javascript inf confJavaScript.inc
  //
  function popupWindow($label, $text) {
      $target="$this->paperSite/genericPopup.php";
      $message=urlencode($text);

      print "<script type\"text/javascript\">";
      print "popup(\"$target?popupMessage=$message\", \"$label\");";
      print "</script>";
  }

  function popupWarning($text) {
      $this->popupWindow("Warning",
			 "<center> <h1> <big> Warning </big> </h1> </center>"
			 . "<p> $text </p>");
  }

  function taskHeader($what) {
      if ( !IsSet($span) ) {
	$span = "100";
      }
      print "<table bgcolor=" . 
	$this->taskHeaderColor . " width=100%>\n";
      print "<tr> <td align=center width=100%>\n";
      print "<font size=+2 color=white> $what </font>\n";
      print "</td> </tr> </table>\n";
  }

  function alternatingContrast($i) {
      if ($i % 2 == 0) {
	  return $this->contrastColorOne;
      } else {
	  return $this->contrastColorTwo;
      }
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
      //$suffix=sscanf($mimetype,"application/%s");
      //if ( $suffix == "" ) {
      //    return $mimetype;
      //else
      //    return $suffix;
  }

  function getFileTypeAbbrev(&$mimetype) {
      if (!isset($mimetype) || $mimetype == null || $mimetype == "")
	  return "Unknown";
      else if ($mimetype == "application/postscript")
	  return "PostScript";
      else if ($mimetype == "application/pdf")
	  return "PDF";
      else if ($mimetype == "text/plain")
	  return "Text";
      else
	  return $mimetype;
      //$suffix=sscanf($mimetype,"application/%s");
      //if ( $suffix == "" ) {
      //    return $mimetype;
      //else
      //    return $suffix;
  }

  function makeDownloadPath($paperId, &$mimetype) {
      //$ext = $this->getFileExtension($mimetype);
      //$path = $this->paperSite . "/Download/GetPaper/" . 
      //   $this->downloadPrefix . "paper$paperId." . $ext;
      global $ConfSiteBase;
      return $ConfSiteBase . "Download/GetPaper/" . $this->downloadPaperName($paperId, $mimetype);
  }

  
  function composeReviewerRequest($request, $who, $email, $paperList)
    {
	$message = "Greetings,\n\n";
	$message .= "$who->firstName " . $who->lastName . " (" . $who->email . ")";
	$message .= $request . " for ";
	$message .= "The $this->longName ($this->shortName).\n\n";
	$message .= $paperList;
	$message .= "\n";
	$message .= "You can continue to modify your review(s)\n";
	$message .= $this->printableTimeRange('reviewerSubmitReview');
	$message .= "or until you finalize them.\n";
	$message .= "\n\n";
	$message .= "If you are unable to complete the review by the deadline,\n";
	$message .= "please contact " . $who->firstName . " " . $who->lastName ." (" . $who->email . ")\n";
	$message .= "\n";
	$message .= "You can access the website for making your review:\n\n";
	$message .= "Web site: $this->paperSite\n";

	$result = $this->q("SELECT password FROM ContactInfo WHERE email='$email'");
	
	if ( $result && $result -> numRows() == 1 ) {
	  $row = $result->fetchRow();
	  $passwd = $row[0];
	  $cleanPasswd=htmlspecialchars($passwd);
	  $cleanEmail=htmlspecialchars($email);

	  $message .= "Login: $cleanEmail   Password: $cleanPasswd\n\n";
	}

	$message .= "Contact $this->contactName ($this->contactEmail) about problems.\n\n";
	$message .= "Thank you for helping $this->shortName - we understand that reviewing is hard work.\n";
      
	return $message;
    }

    function allowEmailTo($email) {
	global $Opt;
	return $Opt['sendEmail'] && strstr($email, "@")
	    && !preg_match('/@_.com$/', $email);
    }
  
  function sendReviewerRequest($who, $email, $paperList) {
      $message = $this -> composeReviewerRequest("has asked you to review the following papers",
						 $who, $email, $paperList);
      if ($this->allowEmailTo($email))
	  mail($email,
	       "[$this->shortName] Review request",
	       $message,
	       "From: $this->emailFrom",
	       "-f$this->emailFrom");
  }

  function sendUrgentReviewRequest($who, $email, $paperList) {
      $message = $this -> composeReviewerRequest("\nis reminding you to finish your review(s) for the following papers",
						 $who, $email, $paperList);
      if ($this->allowEmailTo($email))
	  mail($email,
	       "[$this->shortName] URGENT: Review reminder",
	       $message,
	       "From: $this->emailFrom",
	       "-f$this->emailFrom");
    }

  //
  // Should really use a contactId for this, but the functions
  // calling it don't have one..
  //
  function getPaperList($email)
    {
      $q = "SELECT paperId, title FROM Paper, ContactInfo "
	. " WHERE (Paper.contactId=ContactInfo.contactId AND "
	. " ContactInfo.email='$email')";
      $r = $this->q($q);
      $retval = "";
      if (DB::isError($r)) {
	$this->errorMsg("Unable to get list of papers for $email: " . $r->getMessage());
      } else {
	$i = 0;
	while( $row=$r->fetchRow() ) {
	  $i++;
	  $retval = $retval . "#$row[0] - $row[1]\n\n";
	}
	$retval = "\nYou have $i papers on file. They are listed below\n" . $retval;
      }
      return $retval;
    }

  function sendPaperStartNotice($email, $paperId, $title) {
      $message = "This is a confirmation notice that you've started\n"
	. " the submission process for your paper, #$paperId, titled\n"
	. " $title\n"
	. "\n"
	. "Remember that you still need to submit your final copy and\n"
	. "finalize your paper before it can be reviewed.\n";
	//. "You have the following papers on record\n";

      if ($this->allowEmailTo($email))
	  mail($email,
	       "[$this->shortName] Paper #$paperId started",
	       $message,
	       "From: $this->emailFrom",
	       "-f$this->emailFrom");
    }

  function sendPaperFinalizeNotice($email, $paperId, $title)
    {
      $message = "This is a confirmation notice that you've finalized\n"
	. " the submission process for your paper, #$paperId, titled\n"
	. " $title\n"
	. "\n"
	. "Keep this email as confirmation of your paper being submitted.\n";
      if ($this->allowEmailTo($email))
	  mail($email,
	       "[$this->shortName] Paper #$paperId submit confirmation",
	       $message,
	       "From: $this->emailFrom",
	       "-f$this->emailFrom");
    }


    function log($text, $who) {
	global $REMOTE_ADDR;

	$text=addslashes($text);
	$this->q("INSERT INTO ActionLog SET  "
		 . " ipaddr='$REMOTE_ADDR', "
		 . " contactId='$who->contactId', action='$text'" );
    }

    ///////////////////////////////////////////////////////////////////////////
    // output summary of review status
    ///////////////////////////////////////////////////////////////////////////

    function countReviewRequests($contactId) {
	$result = $this->qe("select reviewType, count(contactId)
		from ReviewRequest where contactId=$contactId
		group by reviewType order by null", "while counting review requests");
	if (DB::isError($result))
	    $rreq = array(REVIEW_PRIMARY => '0?', REVIEW_SECONDARY => '0?', REVIEW_REQUESTED => '0?');
	else {
	    $rreq = array(REVIEW_PRIMARY => 0, REVIEW_SECONDARY => 0, REVIEW_REQUESTED => 0);
	    while ($row = $result->fetchRow())
		$rreq[$row[0]] += $row[1];
	}
	return $rreq;
    }
  
    function reviewerSummary($pcId, $showConflicts=0, $showTopics=0, $extra="") {
	$query = "SELECT ContactInfo.firstName, "
	    . " ContactInfo.lastName, ContactInfo.email "
	    . " FROM ContactInfo "
	    . " WHERE ContactInfo.contactId=$pcId ";
	;

	$pcresult = $this -> qe($query);
	if (!DB::isError($pcresult)) {
	    $row=$pcresult->fetchRow(DB_FETCHMODE_ASSOC);

	    $first = $row['firstName'];
	    $last = $row['lastName'];
	    $email = $row['email'];

	    $rreq = $this->countReviewRequests($pcId);
	    $primaries = $rreq[REVIEW_PRIMARY];
	    $secondaries = $rreq[REVIEW_SECONDARY];

	    $reviews = $this->countEntries("PaperReview.contactId",
					   $pcId, "PaperReview");
	    $finished = $this->countEntries(
					    "PaperReview.contactId",
					    $pcId,
					    "PaperReview",
					    " AND PaperReview.reviewSubmitted=1 ");

	    $asked = $this->countEntries("ReviewRequest.requestedBy",
					 $pcId, "ReviewRequest");

	    $graded = $this->countEntries("PaperGrade.contactId",
					  $pcId, "PaperGrade");

	    $askedReviews = $this->retCount(
		"select count(PaperReview.contactId) as foo
		from PaperReview join ReviewRequest using (contactId, paperId)
		where ReviewRequest.requestedBy=$pcId");

	    $askedFinished = $this->retCount(
		"select count(ReviewRequest.requestedBy) as foo
		from PaperReview join ReviewRequest using (contactId, paperId)
		where ReviewRequest.requestedBy=$pcId");

	    print "<table width=75% align=center border=1>\n";
	    print "<tr> <td>\n";
	    print "<table width=100% align=center border=0>\n";
	    print "<tr  bgcolor=$this->contrastColorOne> <th> $first $last ( ";
	    print "<a href=\"mailto:$email\"> $email </a> ) </th> </tr>";
	    
	    print "<tr> <td>";
	    print "Assigned $primaries primary reviews ";
	    print "and $secondaries secondary reviews<br>";
	    print "Started $reviews reviews, finished $finished <br>";
	    print "Asked for $asked other reviews, ";
	    print " resulting in $askedReviews reviews being started ";
	    print " and $askedFinished being finished  <br>";
	    print "Graded $graded reviews<br>";
	    if (IsSet($extra) &&  $extra != "") {
		print "<br> $extra ";
	    }
	    print "</td> </tr>";

	    if ($showConflicts) {
		$query = "SELECT Paper.paperId, Paper.title "
		    . " FROM Paper, PaperConflict "
		    . " WHERE PaperConflict.paperId=Paper.paperId "
		    . " AND PaperConflict.contactId=$pcId "
		    . " ORDER by Paper.paperId ";
		$r=$this->qe($query);

		if (!DB::isError($r)) {
		    $conflicts = $r -> numRows();
		    print "<tr> <td>";
		    if ( $conflicts < 1) {
			print "<br> This program committee member has no conflicts ";
		    } else {
			print "<br> This program committee member has the following conflicts";
			print "<table>\n";
			while ($row=$r->fetchRow(DB_FETCHMODE_ASSOC)) {
			    $paperId=$row['paperId'];
			    $title=$row['title'];
			    print "<tr> <td> $paperId </td> <td> $title </td> </tr>\n";
			}
			print "</table>\n";
		    }
		    print "</td> </tr>";
		}
	    }

	    if ( $showTopics ) {
		$query = "SELECT topicName from TopicArea, TopicInterest "
		    . " WHERE TopicInterest.contactId = $pcId "
		    . " AND TopicInterest.interest = 2 "
		    . " AND TopicArea.topicId=TopicInterest.topicId ";
		$r=$this->qe($query);
		if (!DB::isError($r) && $r -> numRows() > 0) {
		    print "<tr> <td>";
		    print "They have high interest in ";
		    print "<ul>";
		    while ($row=$r->fetchRow(DB_FETCHMODE_ASSOC)) {
			print "<li> " . $row["topicName"] . "</li>";
		    }
		    print "</ul>";
		    print "</td> </tr>";
		}
		
		$query = "SELECT topicName from TopicArea, TopicInterest "
		    . " WHERE TopicInterest.contactId = $pcId "
		    . " AND TopicInterest.interest = 1 "
		    . " AND TopicArea.topicId=TopicInterest.topicId ";
		$r=$this->qe($query);
		if (!DB::isError($r) && $r -> numRows() > 0) {
		    print "<tr> <td>";
		    print "They have medium interest in ";
		    print "<ul>";
		    while ($row=$r->fetchRow(DB_FETCHMODE_ASSOC)) {
			print "<li> " . $row["topicName"] . "</li>";
		    }
		    print "</ul>";
		    print "</td> </tr>";
		}

		$query = "SELECT topicName from TopicArea, TopicInterest "
		    . " WHERE TopicInterest.contactId = $pcId "
		    . " AND TopicInterest.interest = 0 "
		    . " AND TopicArea.topicId=TopicInterest.topicId ";
		$r=$this->qe($query);
		if (!DB::isError($r) && $r -> numRows() > 0) {
		    print "<tr> <td>";
		    print "They have NO interest in ";
		    print "<ul>";
		    while ($row=$r->fetchRow(DB_FETCHMODE_ASSOC)) {
			print "<li> " . $row["topicName"] . "</li>";
		    }
		    print "</ul>";
		    print "</td> </tr>";
		}
	    }

	    print "</table>";
	    print "</td> </tr>";
	    print "</table>";
	}
    }

  function retCount($query)
    {
      $result = $this->qe($query);
      if (!DB::isError($result)) {
	$row = $result->fetchRow();
	return $row[0];
      } else {
	return 0;
      }
    }

  function countEntries($field, $who, $table, $extra="")
    {
      return $this->retCount("SELECT "
		      . " COUNT($field) AS foo "
		      . " FROM $table "
		      . " WHERE $field=$who $extra"
		      );
    }

  function reviewRange($what, $table)
    {
      //
      // This is fairly inefficient since we query for each graph
      // but
      $range = array();
      $result = $this -> qe("SELECT min($what), max($what) from $table");
      if ( !DB::isError($result) ) {
	$row = $result->fetchRow();
	$range['min'] = $row[0];
	$range['max'] = $row[1];
      } 
      return $range;
    }

  function graphValuesStr($query, $what, $min, $max)
    {
      $result = "";

      $merit=array();
      for ($i = $min; $i <= $max; $i++) {
	$merit[$i] = 0;
      }
      $result = $this->qe($query);
      if ( DB::isError($result) ) {
	$this->errorMsg("Error: " . $result->getMessage());
      }

      //
      // Determine min/max of attribute
      //
      $sum=0;
      $num=0;

      $retstr = "";
      while($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
	$value = $row[$what];
	if ( !IsSet($merit[$value]) ) {
	  $merit[$value] = 1;
	} else {
	  $merit[$value] += 1;
        }
	$sum += $value;
	$num++;
      }

      $str="../images/GenChart.php?";
      $sep = "";
      foreach ($merit as $key => $value) {	
	$str = $str . $sep . "v[" . $key . "]=$value";
	$sep="&";
      }
      $retstr .= "<table><tr><td align=\"center\">";
      $retstr .= "<img src=\"$str\" ";
      $retstr .= sprintf("alt=\"avg=%0.2f\" ", $avg);
      $retstr .= "align=bottom>";
      $retstr .= "</td><tr><td align=\"center\"><font size=-2>";
      if($num > 0){
        $avg=$sum/$num;
	$retstr .= sprintf("avg=%0.2f", $avg);
	if( $num > 1 ){
	  $dev = 0;
	  foreach ($merit as $val => $count) {	
	    $v = $val-$avg;
	    $dev += $v*$v * $count;
	  }
	  $dev = sqrt( $dev/($num-1) );
	  $retstr .= sprintf(" std=%0.2f", $dev);
	}
      }
      $retstr .= "</font></td></tr></table>";

      return $retstr;
  } 

  function graphValues($query, $what, $min, $max)
  {
    print $this->graphValuesStr($query, $what, $min, $max);
  }

  //
  // Functions to implement a stateful toggle button
  //
  function toggleButtonUpdate($varName)
    {
      $toggleName = 'toggle' . $varName;

      if (IsSet($_REQUEST[$toggleName])) {
        $_REQUEST[$varName] = $_REQUEST[$toggleName];
      }
      else {
        $_REQUEST[$varName] = 0;
      }
    }

  function toggleButton($varName, $whenSet, $whenUnset, $extra="", $getstuff="")
    {
      $toggleName = 'toggle' . $varName;
      $toggleButton = 'toggleButton' . $varName;

      echo "<FORM METHOD=POST ACTION=", $_SERVER["PHP_SELF"], "$getstuff>\n";
      if ($_REQUEST[$varName]) {
	print "<input TYPE=SUBMIT NAME=$toggleButton VALUE=\"$whenSet\">\n";
	print "<input TYPE=hidden NAME=$toggleName VALUE=0>\n";
	print $extra;
      } else {
	print "<input TYPE=SUBMIT NAME=$toggleButton VALUE=\"$whenUnset\">\n";
	print "<input TYPE=hidden NAME=$toggleName VALUE=1>\n";
	print $extra;
      }
      print "</FORM>\n";
    }

  function toggleButtonWithPaperId($varName, $whenSet, $whenUnset, $paperId) 
    {
      $this -> toggleButton($varName, $whenSet, $whenUnset, "", "?paperId=$paperId");
    }

  function mkHiddenVar($name, $value)
    {
      return "<INPUT type=hidden name=$name value=$value>\n";
    }

    function checkConflict($paperId, $contactId) {
	$result = $this->qe("select paperId from PaperConflict
		where contactId=$contactId and paperId=$paperId", "while examining conflicts");
	return !DB::isError($result) && $result->numRows() > 0;
    }

    function allMyConflicts($contactId) {
	$result = $this->qe("select paperId from PaperConflict
		where contactId=$contactId", "while examining conflicts");
	$conflicts = array();
	if (!DB::isError($result))
	    while ($row = $result->fetchRow())
		$conflicts[$row[0]] = 1;
	return $conflicts;
    }

    function allPCConflicts() {
	$result=$this->qe("select paperId from PaperConflict
		join PCMember using (contactId)", "while examining conflicts");
	$conflicts = array();
	if (!DB::isError($result))
	    while ($row = $result->fetchRow())
		$conflicts[$row[0]] = 1;
	return $conflicts;
    }

  //
  // Make a selection option with the specified name
  // selected on the specified contactId
  //
  function makePCSelector($varName, $selected, $size=10) {
    print "<SELECT name=$varName SINGLE size=$size>\n";
    $query = "SELECT ContactInfo.contactId, firstName, lastName, email "
      . " from ContactInfo join PCMember using (contactId) "
      . " ORDER BY lastName, firstName ";
    $result = $this->qe($query);
    if (!DB::isError($result)) {
      while($row=$result->fetchRow()) {
	print "<OPTION VALUE=\"$row[0]\" ";
	if ($row[0] == $selected) {
	  print "SELECTED ";
	}
	print "> $row[1] $row[2] ($row[3]) </OPTION>";
      }
    }
    print "</SELECT>";
  }

  function safeHtml($string) {
    if ( $string == "") {
      return "&nbsp;";
    } else {
      return nl2br(htmlspecialchars($string));
    }
  }

  function okSeeReviewers() {
      global $Me;
      return $Me -> isChair
	|| ($Me -> isPC
	    && $this -> optionLetPCSeeReviewers
	    && $this -> validTimeFor('PCMeetingView', 0)
	    )
	;
  }
  function okSeeUnfinishedReviews()
    {
      global $Me;
      return $Me -> isChair
	|| ($Me -> isPC
	    && $this -> optionLetPCSeeUnfinishedReviews
	    && $this -> validTimeFor('PCMeetingView', 0)
	    )
	;
    }
  function okSeeAuthorInfo()
    {
      global $Me;
      return $Me -> isChair
	|| ($Me -> isPC
	    && $this -> optionLetPCSeeAuthorInfo
	    && $this -> validTimeFor('PCMeetingView', 0)
	    )
	;
    }

  function thereAreTopics() {
    $query = "SELECT * from PaperTopic";
    $result = $this->q($query);
    if ( ! DB::isError($result) ) {
      return $result -> numRows() > 0;
    } else {
      return 0;
    }
  }

  function listTopicsForPaper($paperId) {
    $query="SELECT topicName from TopicArea, PaperTopic "
      . "WHERE PaperTopic.paperId='$paperId' "
      . "AND PaperTopic.topicId=TopicArea.topicId ";
    $result = $this->qe($query);
    if ( ! DB::isError($result) ) {
      print "<ul>";
      while ($row=$result->fetchRow()) {
	print "<li>" . $row[0] . "</li>\n";
      }
      print "</ul>";
    }
  }

  function showReviewers( $paperId, $type ) {
  print "<TR><TD>$type:</TD><TD>";
   $result = $this->qe("SELECT ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, ContactInfo.contactId "
		       . " FROM ContactInfo, $type" . "Reviewer "
		       . " WHERE ( $type" . "Reviewer.paperId='$paperId' "
		       . "         AND $type" . "Reviewer.contactId=ContactInfo.contactId)");
   if (!DB::isError($result)) {
     while($row = $result->fetchRow() ) {
       print "<a href=\"ChairRemoveReviewer.php?paperId=$paperId&who=$row[3]&reviewType=$type\">" . $row[0]. " " . $row[1] . " (" . $row[2] . "), " . "</a>";
       print "<br>";
     }
   } else {
     print "<p> Error: " . $result->getMessage() . " </p>";
   }
  print "</TD></TR>\n";
  }

  function paperTable($showAuthor = true, $showAbs = true, $paperId = -1,
		      $showRev = false, $showTopics = true, $showCollab = -1) {
    if ($showCollab < 0)
	$showCollab = $showAuthor;
    if ($paperId < 0)
	$paperId = $_REQUEST["paperId"];

    $query = "select Paper.title, Paper.abstract, Paper.authorInformation,
	Paper.size, Paper.mimetype, collaborators, pcPaper,
	timeSubmitted, timeWithdrawn
	from Paper";

    $Me = $_SESSION["Me"];
    if (!($Me->isChair || $Me->isPC || $Me->isAssistant))
	$query .= " join PaperConflict using (contactId)";
    $query .= " where Paper.paperId=$paperId";
    if (!($Me->isChair || $Me->isPC || $Me->isAssistant))
	$query .= " and PaperConflict.author=1";

    //$this->warnMsg($query);
    $result = $this->qe($query);
    if (DB::isError($result)) {
	$this->errorMsg("That's odd - paper #$paperId cannot be displayed. "
			. $result->getMessage());
	exit();
    }
 
    $row = $result->fetchRow(DB_FETCHMODE_ASSOC);

    $title = htmlspecialchars($row['title']);
    $abstract = htmlspecialchars($row['abstract']);
    $authorInfo = htmlspecialchars($row['authorInformation']);
    $paperLength = $row['size'];
    $mimetype = $row['mimetype'];
    $collaborators = htmlspecialchars($row['collaborators']);
  
    $badPaper = $paperLength < 100 && $mimetype == 'text/plain';
    ?>
    <table class='papertable'>
       <tr> <th colspan=2 align=center><font size=+1>
       <b> Paper #<?php echo $paperId ?></b> </font> </th> </tr>
   <tr> <td class='caption'>Title:</td> <td> <?php  echo $title ?> </td> </tr>
<?php
    if ( $_SESSION["Me"]->isChair && $row['pcPaper'] ){
?>
   <tr> <td colspan='2' align='center' bgcolor='Red'> This is a PC paper. </td> </tr>
<?php
    }
?>
<?php
    echo "  <tr><td class='caption'>Status:</td> <td>";
    if ($paperLength < 100 && $mimetype == 'text/plain')
	echo "Not yet uploaded";
    else if ($row['timeWithdrawn'] > 0)
	echo "Withdrawn";
    else {
	echo "<a href=\"", $this->makeDownloadPath($paperId, $mimetype), "\">";
	echo ($row['timeSubmitted'] > 0 ? "Submitted" : "In progress");
	echo " (", round($paperLength/1024), "&nbsp;kB ";
	if ($mimetype == "application/pdf")
	    echo "PDF";
	else if ($mimetype == "application/postscript")
	    echo "PostScript";
	else
	    echo $mimetype;
	echo ")</a>";
    }
    echo "</td></tr>\n";
	
    if( $showAuthor ){
?>
   <tr> <td class='caption'>Authors:</td> <td> <?php  echo $authorInfo ?> </td> </tr>
<?php
    }
?>
<?php
    if( $showAbs ){
?>
	<tr> <td class='caption'>Abstract:</td> <td> <?php  echo $abstract ?> </td> </tr>
<?php
    }
?>
<?php
    if( $showCollab ){
?>
   <tr> <td class='caption'> Collaborators: </td> <td> <?php  echo $collaborators ?> </td> </tr>
<?php
    }
    if( $showRev ){
      $this->showReviewers( $paperId, 'Primary' );
      $this->showReviewers( $paperId, 'Secondary' );
    }

    if( $showTopics ){
      $query="SELECT topicName from TopicArea, PaperTopic "
	. "WHERE PaperTopic.paperId=$paperId "
	. "AND PaperTopic.topicId=TopicArea.topicId ";
      
      $result = $this->qe($query);
      if ( ! DB::isError($result) && $result->numRows() > 0 ) {
	print "<tr>";
	print "<td class='caption'>Topics:</td>";
	print "<td>";
	print "<ul>";
	while ($row=$result->fetchRow()) {
	  print "<li>" . $row[0] . "</li>\n";
	}
	print "</ul>";
	print "</td>";
	print "</tr>";
      }
    }

    if ( $this->allowReviewerPreferences ) {
      $query="SELECT ContactInfo.contactId, ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email "
	. " FROM ContactInfo join PaperReviewerPreference "
	. " join PCMember on (contactId)"
	. " WHERE PaperReviewerPreference.paperId='$paperId' "
	. " AND ContactInfo.contactId=PaperReviewerPreference.contactId "
	. " ORDER BY ContactInfo.lastName";

      $result = $this->qe($query);

      if ( ! DB::isError($result) && $result -> numRows() > 0) {
	print "<tr>";
	print "<td> Indicated PC Reviewer Preferences</td>";
	print "<td>";
	print "<ul>";
	while ($row=$result->fetchRow()) {
	  $id = $row[0];
	  $fn = $row[1];
	  $ln = $row[2];
	  $em = $row[3];
	  print "<li> $fn $ln ($em) </li>";
	}
	print "</ul>";
      }
      print "</td>";
      print "</tr>";
    }
?>
</table>
<?php
}

}
