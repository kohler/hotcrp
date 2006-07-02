<?php 
//
// Configuration for a Contact (i.e. someone using the reviewing system)
//
// Assumes global variable DB
//
class Conference {
  var $connected;
  var $setupPhase;
  var $sessionName; // name of this session, security
  var $shortName;
  var $longName;
  var $theme;
  var $paperSite;
  var $conferenceSite;
  var $paperPrefix;
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
  }

  function dump()
    {
      echo "<table border=\"1\" width=\"83%\" bgcolor=$bgOne>";
      echo "<tr> <td>";
      echo "<p>";

      echo "setupPhase is " . $this -> setupPhase . " <br>";
      echo "shortName is " . $this -> shortName . " <br>";
      echo "longName is " . $this -> longName . " <br>";
      //      echo "theme is " . $this -> theme . " <br>";
      echo "paperSite is " . $this -> paperSite . " <br>";
      echo "conferenceSite is " . $this -> conferenceSite . " <br>";
      echo "paperPrefix is " . $this -> paperPrefix . " <br>";
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
      $this->startTime = array();
      $this->endTime = array();
      //
      // Suck in the start/end dates
      //
      $result = $this->q("SELECT name, unix_timestamp(start) as st, unix_timestamp(end) as en FROM ImportantDates");

      if (!DB::isError($result)) {

	while($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
	  $name = $row['name'];
	  $start = $row['st'];
	  $end = $row['en'];

	  if ( $start != 0 ) {
	    $this->startTime[$name] = $start;
	  }

	  if ( $end != 0 ) {
	    $this->endTime[$name] = $end;
	  }
	}
      }	
    }

  function q($query) {
      if (!$this->connected)
	  $this->connect();
      return $this->DB->query($query);
  }

  function dbErrorText($result, $while = "", $suggestRetry = 1) {
      if ($result != null) {
	  $text = "<p>Database error";
	  if ($while)
	      $text .= " $while";
	  $text .= ": " . $result->getMessage() . "</p>";
      }
      if ($suggestRetry)
	  $text .= "\n<p>Please try again or contact the site administrator at $this->emailFrom.</p>";
      return $text;
  }
  
  function qe($query, $while = "", $suggestRetry = 0) {
      if (!$this->connected)
	  $this->connect();
      $result = $this->DB->query($query);
      if (DB::isError($result))
	  $this->errorMsg($this->dbErrorText($result, $while, $suggestRetry));
      return $result;
  }

  //
  // Return the number of papers submitted by an author.
  //

  function countSubmittedById($id) {
    if (1) {
      $query = "SELECT COUNT(*) FROM Paper WHERE "
	. "Paper.contactId='$id'";
    }

    $result = $this->q($query);

    if ( !DB::isError($result) && $result -> numRows() == 1 ) {
      $row = $result->fetchRow();
      return $row[0];
    } else {
      return 0;
    }
  }

  //
  // Return the number of papers submitted by an author
  // that have been finalized.
  //
  function countFinalizedById($id) {
    $query = "SELECT COUNT(*) FROM Paper WHERE "
      . " (Paper.contactId='$id' AND Paper.acknowledged=1 )";
    $result = $this->q($query);

    if ( !DB::isError($result) && $result -> numRows() == 1 ) {
      $row = $result->fetchRow();
      return $row[0];
    } else {
      return 0;
    }
  }

  //
  // Check to see if an email address has been registered
  // in the database
  //
  function emailRegistered($email) {
      $email = ltrim(rtrim($email));
      $result = $this->q(sprintf("select ContactInfo.contactId from ContactInfo where (email='%s')", mysql_real_escape_string($email)));
      if ($result && $result->numRows() > 0) {
	  $row = $result->fetchRow();
	  return $row[0];
      } else {
	  return 0;
      }
  }

  //
  // Determine roles baased on email address and/or contactId
  //
  function emailIsPC($email)
    {
      $result=$this->q("SELECT PCMember.pcId FROM ContactInfo, PCMember "
			  . " WHERE ( email='$email' "
		       . " AND PCMember.contactId = ContactInfo.contactId )");
      if ( $result && $result -> numRows() > 0 ) {
	$row = $result->fetchRow();
	return $row[0];
      } else {
	return 0;
      }
    }

  function contactIsPC($id)
    {
      $result=$this->q("SELECT PCMember.pcId FROM PCMember "
		       . " WHERE PCMember.contactId=$id");
      if ( $result && $result -> numRows() > 0 ) {
	$row = $result->fetchRow();
	return $row[0];
      } else {
	return 0;
      }
    }

  function emailIsChair($email)
    {

      if ( $this -> setupPhase ) {
	return 1;
      }
      $result=$this->q("SELECT Chair.pcId FROM ContactInfo, Chair "
			  . " WHERE ( email='$email' "
		       . " AND Chair.contactId = ContactInfo.contactId )");
      if ( $result && $result -> numRows() > 0 ) {
	$row = $result->fetchRow();
	return $row[0];
      } else {
	return 0;
      }
    }

  function contactIsChair($id)
    {
      if ( $this -> setupPhase ) {
	return 1;
      }
      $result=$this->q("SELECT Chair.chairId FROM Chair "
		       . " WHERE Chair.contactId=$id");
      if ( $result && $result -> numRows() > 0 ) {
	$row = $result->fetchRow();
	return $row[0];
      } else {
	return 0;
      }
    }

  function contactIsAssistant($id)
    {
      $result=$this->q("SELECT ChairAssistant.chairAssistantId FROM ChairAssistant "
		       . " WHERE ChairAssistant.contactId=$id");
      if ( $result && $result -> numRows() > 0 ) {
	$row = $result->fetchRow();
	return $row[0];
      } else {
	return 0;
      }
    }

  //
  // Some paper queries (about paperId's)
  //
  function reviewablePaper($paperId)
    {
      $result = $this->q("SELECT paperId FROM Paper WHERE "
			 . " paperId='$paperId' AND withdrawn=0");

      if ( ! $result || $result -> numRows() != 1) {
	return 0;
      } else {
	return 1;
      }
    }

  //
  // Is there a request for contactId to review the specified paper?
  //
  function reviewRequestFor($paperId, $contactId)
    {
      $result = $this->q("SELECT reviewRequestId FROM ReviewRequest "
			 . "WHERE paperId='$paperId' AND asked='$contactId'");
      if ( $result ) {
	 $num=  $result -> numRows();
	if ($num > 0 ) {
	  return 1;	
	} else {
	  return 0;
	}
      } else {
	return 0;
      }
    }

  function primaryReviewerFor($paperId, $contactId)
    {
      $result = $this->q("SELECT primaryReviewerId FROM PrimaryReviewer "
			 . " WHERE paperId='$paperId' AND reviewer='$contactId'");
      if ( $result && $result -> numRows() >= 1) {
	return 1;
      } else {
	return 0;
      }
    }

  function secondaryReviewerFor($paperId, $contactId)
    {
      $result = $this->q("SELECT secondaryReviewerId FROM SecondaryReviewer "
			 . " WHERE paperId='$paperId' AND reviewer='$contactId'");
      if ( $result && $result -> numRows() >= 1) {
	return 1;
      } else {
	return 0;
      }
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
      $q =  " SELECT ReviewRequest.paperId  "
	. "  FROM ReviewRequest, Paper "
	. "  WHERE ReviewRequest.asked=$contactId "
	. " AND ReviewRequest.paperId=Paper.paperId "
	. "  AND Paper.withdrawn = 0 "
	. "  ORDER BY paperId ";

      $result = $this->qe($q);
      if ( !DB::isError($result) ) {
	while($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {

	  $thisMissing=1;
	  $paperId=$row['paperId'];
	  $q2="SELECT finalized, paperId FROM PaperReview "
	    . " WHERE paperId=$paperId "
	    . " AND reviewer=$contactId "
	    . " AND finalized=1 ";
	  $r2 = $this->qe($q2);
	  if (!DB::isError($r2)) {

	    if ( $r2->numRows() > 0) {
	      $thisMissing = 0;
	    }
	  }
	    
	  if ($thisMissing ) {
	    $this->errorMsg("Missing review for paper #"
			    . $row['paperId']);
	    $missingReviews=1;
	  }
	}
      }

      //
      // First, check to see if I have primary requests...
      //
      $q =  " SELECT Paper.paperId  "
	. "  FROM PrimaryReviewer,Paper "
	. "  WHERE PrimaryReviewer.reviewer=$contactId "
	. " AND PrimaryReviewer.paperId=Paper.paperId  AND Paper.withdrawn = 0 "
	. "  ORDER BY paperId ";

      $result = $this->qe($q);
      if ( !DB::isError($result) ) {
	while($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {

	  $thisMissing=1;
	  $paperId=$row['paperId'];
	  $q2="SELECT finalized FROM PaperReview "
	    . " WHERE paperId=$paperId "
	    . " AND reviewer=$contactId "
	    . " AND finalized=1 ";
	  $r2 = $this->qe($q2);
	  if (DB::isError($r2)) {
	    if ( $r2 -> numRows() ) {
	      $thisMissing = 0;
	    }
	  }
	    
	  if ($thisMissing ) {
	    $this->errorMsg("Missing review for paper #"
			    . $row['paperId']);
	    $missingReviews=1;
	  }
	}
      }

      
      return $missingReviews;
    }


  //
  // Manage start-stop of various activities
  //
  function setTimeRange($what, $start, $stop)
    {
      $this -> startTime[$what] = $start;
      $this -> endTime[$what] = $stop;
    }

  //
  // Can be overridden by subclass
  //
  function parseableTime($value)
    {
      return date ("d F Y h:i:s A",$value);
      
    }
  function printTime($value)
    {
      return date ("l dS of F Y h:i:s A",$value);
      
    }
  function printableStartTime($what)
    {
      return $this -> printTime($this -> startTime[$what]);
    }

  function printableEndTime($what)
    {
      return $this -> printTime($this -> endTime[$what]);
    }

  function printTimeRange($what)
    {
      $start = $this -> startTime[$what];
      $end = $this -> endTime[$what];
      $now = time();

      $str = "";
      if ( IsSet($start) && $start != 0 && $start >= $now ) {
	$str = "after " . $this -> printTime($start) . " ";
	if ( IsSet($end) && $end != 0 ) {
	  $str .= "and ";
	}
      }

      if ( IsSet($end) && $end != 0 ) {
	$str .= "until " . $this -> printTime($end) . " ";
      }
      
      return $str;

    }

  //
  // Is this particular event/deadline defined?
  //
  function validDeadline($what)
    {
      if (IsSet($this->startTime[$what]) &&
	  IsSet($this->endTime[$what]) ) {
	return 1;
      } else {
	return 0;
      }
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
	  return 0;
      else
	  return 1;
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

  //
  // Paper storage
  //

  function storePaper($uploadedFileName, $mimetype, $paperId) {
      if (!IsSet($uploadedFileName)
	  || $uploadedFileName == "none" || $uploadedFileName == "") {
	$mimetype="application/txt";
	$contents="This is a placeholder since no file was specified";
      } else {
	//
	// They actualy provided a file name
	//
	$fn = @fopen("$uploadedFileName", "r");
	if ( ! $fn ) {
	  return 0;
	}
	$size = $uploadedFileName;
	$maxsize = get_cfg_var("upload_max_filesize");
	if ($size > $maxsize) {
	  $this->errorMsg("Your file is $size bytes, which is larger than the maximum "
			  . "upload size of $maxsize -- double check your file. "
			  . "If you need this limit increased, contact $this->contactEmail");
	}

	$contents = fread($fn, filesize("$uploadedFileName"));
      }

      //
      // Check if paper is postscript or PDF, only kinds allowed.
      // We ignore the mimetype since MacOS browsers get this wrong
      //
      $paperOk = 0;

      if ( $this->allowPDF
	   && strncasecmp("%PDF-", $contents, 5) == 0) {
	$mimetype="application/pdf";
	$paperOk = 1;
      } else  if ( $this->allowPS
		   && strncasecmp("%!PS-", $contents, 5) == 0 ) {
	$mimetype=="application/postscript";
	$paperOk = 1;
      } else  if ($mimetype == "application/txt") {
	$paperOk = 1;
	//
	//	probably the place holder -- treat as OK
	//
      } 

      if ( !$paperOk) {
	$message = "You appear to be submitting a file that is not "
	  . " a valid format for this conference or is a valid format but "
	  . " is an invalid file. ";

	if ($this->allowPDF) {
	  $message = $message
	    . "You may submit a PDF file. A valid PDF file "
	    . " has the string '%PDF-' at the beginning of the file "
	    . " and is has an 'application/pdf' MIME type.";
	} 

	if ($this->allowPS) {
	  $message = $message
	    . "You may submit a PostScript File. "
	    . " A valid PostScript file has the string '%!PS-' at the beginning "
	    . " of the file "
	    . " and is has an 'application/postscript' MIME type.";
	  }

	$message .= "<br> <br>";
	$message .= "The file you tried to upload has a MIME type of '$mimetype' "
	  . " and the first five bytes of the file look like "
	  . " '" . htmlentities(substr($contents, 0, 5)) . "'. ";

	$message = $message
	  . "Please check the file contents; if you think "
	  . " this message is in error, contact the conference organizer. ";
	$this->errorMsg($message);

	return 0;
      }


      $query="SELECT mimetype FROM PaperStorage WHERE PaperStorage.paperId=$paperId";
      $result=$this->q($query);

      if ( DB::isError($result) || $result->numRows() == 0 ) {
	$query="INSERT into PaperStorage SET paperId='$paperId', "
	  . "paper='', mimetype='$mimetype'";
	$result = $this->qe($query);
      } else {
	$query="UPDATE PaperStorage SET  "
	  . "paper='', mimetype='$mimetype'"
	  . " WHERE paperId='$paperId' ";
	$result = $this->qe($query);
      }
      if ( DB::isError($result) ) {
	return $result;
      }

      $sofar = 0;
      $contents = base64_encode($contents);
      $len = strlen( $contents );
      $stride = 400000;

      while( $sofar < $len ){
        $chunk = addslashes( substr($contents,$sofar,$stride) );
	// print "<P>Chunk $sofar</P>";
	$query="UPDATE PaperStorage SET  "
	  . "paper=concat(paper,'$chunk') "
	  . " WHERE paperId='$paperId' ";
	error_log($query, 3, "/tmp/error_log_mvb.$sofar");
	$result = $this->qe($query);
	if ( DB::isError($result) ) {
	  return $result;
	}
	$sofar += $stride;
      }
      return $result;
    }


  //
  // Message routines
  //
  function msg($text, $type) {
      echo "<div class='", $type, "'>", $text, "</div>\n";
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
  }

  //
  // Conference header, footer
  //
  function header($title) {
      global $ConfSiteBase;
      
      //      echo '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">';
      echo '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 3.2 Final//EN">';
      echo "<html>\n";
      echo "<head> <title>", $title, " - ", $this->shortName, "</title>\n";

      echo $this -> theme;

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
	      "</strong> &nbsp;|&nbsp; <a href='", $ConfSiteBase, "All/Logout.php'>Logout</a></div>\n";
      }
      echo "    <div id='header_time'>", date ("l j M Y g:i:sa T"), "</div>\n";
      echo "    <div id='header_public'><a href='", $this->conferenceSite, "'>", $this->shortName, " Public Site</a></div>\n";
      //$this->textButton("Go To $this->shortName Site", $this->conferenceSite);
      //$this->textButton("Go To $this->shortName Submissions", $ConfSiteBase . "index.php");
      print "  </div>\n";
      print "  <div class='clear'></div>\n</div>\n";
  }

  function footer() {
      global $ConfSiteBase;
      //
      // Output standard footer - button to go to main submission
      // site, button to close & pointer to CRP at sourceforge
      //
      echo "<div id='footer'>\n";
      echo "  <div id='footer_left'>\n";
      echo "    <div id='footer_conference'><a href='", $ConfSiteBase, "index.php'>", $this->shortName, " Submissions Home</a></div>\n";
      if ($_SESSION["Me"]->valid())
	  echo "    <div id='footer_logout'><a href='", $ConfSiteBase, "All/Logout.php'>Logout</a></div>\n";
      echo "  </div>\n";
      echo "  <div id='footer_right'>\n";
      echo "    <div id='footer_crp'><a href='http://crp.sourceforge.net/'>CRP</a> Conference Management Software</div>\n";
      echo "  </div><div class='clear'></div></div>\n";
      //print "<hr>";
      //print "<table align=center width=50%>";
      //print "<tr> <td align=left> ";
      //$this->textButton("Goto Main Index",
      //"$this->paperSite/index.php");
      //print "</td> <td align=right>";
      //print "<SCRIPT type=\"text/javascript\">";
      //print "function footerCloseMe() {";
      //print "if (window.opener) window.opener.focus();";
      //print "window.close();";
      //print "return false;}";
      //print "</SCRIPT>";
      //print "<form method=post action=$_SERVER[PHP_SELF]>\n";
      //print "<input type=submit onClick=footerCloseMe() value=\"Close Window\">";
      //print "<input type=submit onClick=\"javascript:window.close()\" value=\"Close Window\">";
      //print "</form>";
      //print "</td> </tr>";
      //print "</table>";
      //print "<h5>";
      //print "<a href=\"http://crp.sourceforge.net\"> Conference Review Package -- Copyright © 2001&nbsp; </a><br>";
      //print "All rights reserved.<br>";
      //print "</h5>";
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

  function taskHeader($what, $span) {
      if ( !IsSet($span) ) {
	$span = "100";
      }
      print "<table bgcolor=" . 
	$this->taskHeaderColor . " width=$span%>\n";
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

  function downloadPaper($paperId) {
      $result = $this->q("SELECT mimetype, paper FROM PaperStorage "
			 . " WHERE ( PaperStorage.paperId='$paperId' )");
      if ( !DB::isError($result) ) {

	$row = $result->fetchRow();
	$mimetype=$row[0];
	$content=base64_decode(/*stripslashes(*/$row[1]/*)*/);
	$length=strlen($content);

	if ( $length == 0 ) {
	  print "<html> Paper #$paperId appears to be empty </html>\n";
	  return 0;
	} else {
	  //
	  // Send out headers
	  //
	  header( "Content-Description: PHP3 Generated Data" );
	  $prefix = $this->paperPrefix;
	  $ext= $this-> getFileExtension($mimetype);
	  $name= $prefix . "-$paperId." . $ext;
	  header( "Content-disposition: inline; filename=$name" );
	  header( "Content-Type: $mimetype" );
	  header( "Content-length: $length" ); 
	  //
	  // Send out paper
	  //
	  print $content;
	  return 1;
	}
      } else {
	$this->log("Error downloading $paperId for review" . $result->getMessage(), $_SESSION["Me"]);
      }
    }

  function getFileExtension($mimetype)
    {
      if ($mimetype == NULL ) {
	return "txt";
      } else if ( $mimetype == "application/postscript" ) {
	return "ps";
      } else if ($mimetype == "application/pdf") {
	return "pdf";
      } else if ($mimetype == "application/txt") {
	return "txt";
      } else {
	return "txt";
	$suffix=sscanf($mimetype,"application/%s");
	if ( $suffix == "" ) {
	  return $mimetype;
	} else {
	  return $suffix;
	}
      }
    }

  function makeDownloadPath($paperId, $mimetype) {
      $ext = $this->getFileExtension($mimetype);
      //
      // This mechanisms (using /GetPaper/) appears to have
      // problems on some web servers. I don't know why - dirk
      // The other mechanism appears to work
      //
      if ( 0 ) {
	  $path = $this->paperSite . "/Download/GetPaper/" . 
	      $this->paperPrefix . "-$paperId." . $ext;
      } else {
	  $path = $this->paperSite . "/Download/GetPaper.php?paperId=$paperId";
      }
      return $path;
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
	$message .= $this->printTimeRange('reviewerSubmitReview');
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

  function sendReviewerRequest($who, $email, $paperList) {
      $message = $this -> composeReviewerRequest("has asked you to review the following papers",
						 $who, $email, $paperList);
      if ($this->allowEmail)
	  mail($email,
	       "Request To Review Paper(s) for $this->shortName",
	       $message,
	       "From: $this->emailFrom",
	       "-f$this->emailFrom");
  }

  function sendUrgentReviewRequest($who, $email, $paperList) {
      $message = $this -> composeReviewerRequest("\nis reminding you to finish your review(s) for the following papers",
						 $who, $email, $paperList);
      if ($this->allowEmail)
	  mail($email,
	       "URGENT: Reminder to Review Paper(s) for $this->shortName",
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

      if ($this->allowEmail)
	  mail($email,
	       "Confirmation of starting paper $paperId",
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
      if ($this->allowEmail)
	  mail($email,
	       "Confirmation of finalizing paper $paperId",
	       $message,
	       "From: $this->emailFrom",
	       "-f$this->emailFrom");
    }

  function deletePaper($paperId, $verbose = 1)
    {
      $tables = array(
		      'PaperStorage',
		      'Paper',
		      'PaperAuthor',
		      'PaperConflict',
		      'PaperTopic',
		      'ReviewRequest',
		      'PrimaryReviewer',
		      'SecondaryReviewer',
		      'PaperReview',
		      'PaperReviewerPreference'
		      );
      foreach ($tables as $table ) {
	$result = $this->q("DELETE FROM $table WHERE paperId='$paperId'");
	if ( DB::isError($result)) {
	  if ( $verbose ) {
	    $this->errorMsg("Unable to delete from $table: " . $result->getMessage());
	  }
	} else {
	  //	  $this->infoMsg("Deleted " . $this->DB->affectedRows() . " records from $table");
	}
      }

      $this->log("Delete paper $paperId", $_SESSION["Me"]);
    }

  function sendPaperDeleteNotice($paperId)
    {
      $query = "SELECT Paper.title, Paper.abstract, Paper.authorInformation, "
	. " ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email "
	. " FROM Paper,ContactInfo WHERE Paper.paperId=$paperId AND ContactInfo.contactId=Paper.contactId ";
      $result = $this->qe($query);

      if ( DB::isError($result) ) {
	$this->errorMsg("That's odd - paper #$paperId isn't suitable for deleting. "
			. $result->getMessage());
      } 

      $row = $result->fetchRow();
      $i = 0;
      $title = $this->safeHtml($row[$i++]);
      $abstract = $this->safeHtml($row[$i++]);
      $authorInfo = $this->safeHtml($row[$i++]);
      $first = $this->safeHtml($row[$i++]);
      $last = $this->safeHtml($row[$i++]);
      $email = $this->safeHtml($row[$i++]);

      $message = "This is a confirmation notice that your paper #$paperId titled\n"
	. " $title\n"
	. "\n"
	. "has been removed from the $this->shortName conference database.\n"
	. "This is usually done to remove duplicate entries or submissions.\n"
	. "This was done by the program chair - if you have questions, contact them\n"
	. "by sending email to $this->contactEmail\n"
	;

      if ($this->allowEmail)
	  mail($email,
	       "Confirmation of removal of paper $paperId",
	       $message,
	       "From: $this->emailFrom",
	       "-f$this->emailFrom");

      if ($this->allowEmail)
	  mail($this->contactEmail,
	       "Confirmation of removal of paper $paperId sent to $email",
	       $message,
	       "From: $this->emailFrom",
	       "-f$this->emailFrom");
    }

  function log($text, $who)
    {
      global $REMOTE_ADDR;

      $text=addslashes($text);
      $this->q("INSERT INTO ActionLog SET  "
	       . " ipaddr='$REMOTE_ADDR', "
	       . " contactId='$who->contactId', action='$text'" );
    }

  
  /////////////////////////////////////////////////////////////////////////////
  // output summary of review status
  /////////////////////////////////////////////////////////////////////////////
  function reviewerSummary($pcId, $showConflicts=0, $showTopics=0, $extra="")
    {

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

	$primaries = $this->countEntries("PrimaryReviewer.reviewer",
					 $pcId, "PrimaryReviewer");

	$secondaries = $this->countEntries("SecondaryReviewer.reviewer",
					   $pcId, "SecondaryReviewer");

	$reviews = $this->countEntries("PaperReview.reviewer",
				       $pcId, "PaperReview");
	$finished = $this->countEntries(
					"PaperReview.reviewer",
					$pcId,
					"PaperReview",
					" AND PaperReview.finalized=1 ");

	$asked = $this->countEntries("ReviewRequest.requestedBy",
				     $pcId, "ReviewRequest");

	$graded = $this->countEntries("PaperGrade.contactId",
				     $pcId, "PaperGrade");

	$askedReviews = $this->retCount("SELECT COUNT(PaperReview.reviewer) "
				       . " AS foo "
				       . " FROM PaperReview, ReviewRequest "
				       . " WHERE ReviewRequest.requestedBy=$pcId "
				       . " AND ReviewRequest.asked=PaperReview.reviewer "
				       . " AND ReviewRequest.paperId=PaperReview.paperId "
		     );

	$askedFinished = $this->retCount("SELECT COUNT(ReviewRequest.requestedBy) "
					 . " AS foo "
					 . " FROM PaperReview, ReviewRequest "
					 . " WHERE ReviewRequest.requestedBy=$pcId "
					 . " AND PaperReview.reviewer=ReviewRequest.asked "
					 . " AND ReviewRequest.paperId=PaperReview.paperId "
					 . " AND PaperReview.finalized=1"
					 );

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
	    . " AND PaperConflict.authorId=$pcId "
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
	    . " AND TopicArea.topicAreaId=TopicInterest.topicId ";
	  $r=$this->qe($query);
	  if (!DB::isError($r) && $r -> numRows() > 0) {
	    print "<tr> <td>";
	    print "They have high interest in ";
	    print "<ul>";
	    while ($row=$r->fetchRow(DB_FETCHMODE_ASSOC)) {
	      print "<li> " . $row[topicName] . "</li>";
	    }
	    print "</ul>";
	    print "</td> </tr>";
	  }

	  $query = "SELECT topicName from TopicArea, TopicInterest "
	    . " WHERE TopicInterest.contactId = $pcId "
	    . " AND TopicInterest.interest = 1 "
	    . " AND TopicArea.topicAreaId=TopicInterest.topicId ";
	  $r=$this->qe($query);
	  if (!DB::isError($r) && $r -> numRows() > 0) {
	    print "<tr> <td>";
	    print "They have medium interest in ";
	    print "<ul>";
	    while ($row=$r->fetchRow(DB_FETCHMODE_ASSOC)) {
	      print "<li> " . $row[topicName] . "</li>";
	    }
	    print "</ul>";
	    print "</td> </tr>";
	  }

	  $query = "SELECT topicName from TopicArea, TopicInterest "
	    . " WHERE TopicInterest.contactId = $pcId "
	    . " AND TopicInterest.interest = 0 "
	    . " AND TopicArea.topicAreaId=TopicInterest.topicId ";
	  $r=$this->qe($query);
	  if (!DB::isError($r) && $r -> numRows() > 0) {
	    print "<tr> <td>";
	    print "They have NO interest in ";
	    print "<ul>";
	    while ($row=$r->fetchRow(DB_FETCHMODE_ASSOC)) {
	      print "<li> " . $row[topicName] . "</li>";
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

  function checkConflict($paperId, $contactId)
    {
       $result=$this->qe("SELECT PaperConflict.paperId "
			 . " FROM PaperConflict"
			 . " WHERE PaperConflict.authorId='$contactId' "
			 . " AND PaperConflict.paperId='$paperId' "
			 );
       if (!DB::isError($result)) {
	 while ($row = $result->fetchRow()) {
	   if ( $row[0] == $paperId ) {
	     return 1;
	   }
	 }
	 return 0;
       } else {
	 return (0);
       }
    }

  function allMyConflicts($contactId)
    {
       $result=$this->qe("SELECT PaperConflict.paperId "
			 . " FROM PaperConflict"
			 . " WHERE PaperConflict.authorId='$contactId' ");
       $conflicts = array();
       if (!DB::isError($result)) {
	 while ($row = $result->fetchRow()) {
	   $conflicts[$row[0]] = 1;
	   }
	 }

       return $conflicts;
    }

  function allPCConflicts()
    {
       $result=$this->qe("SELECT PaperConflict.paperId "
			 . " FROM PaperConflict, PCMember"
			 . " WHERE PaperConflict.authorId=PCMember.contactId ");
       $conflicts = array();
       if (!DB::isError($result)) {
	 while ($row = $result->fetchRow()) {
	   $conflicts[$row[0]] = 1;
	   }
	 }

       return $conflicts;
    }

  //
  // Make a selection option with the specified name
  // selected on the specified contactId
  //
  function makePCSelector($varName, $selected, $size=10) {
    print "<SELECT name=$varName SINGLE size=$size>\n";
    $query = "SELECT ContactInfo.contactId, firstName, lastName, email "
      . " FROM ContactInfo, PCMember "
      . " WHERE (PCMember.contactId=ContactInfo.contactId) "
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
      return nl2br(stripslashes($string));
    }
  }

  function okSeeReviewers()
    {
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
      . "AND PaperTopic.topicId=TopicArea.topicAreaId ";
    $result = $this->qe($query);
    if ( ! DB::isError($result) ) {
      print "<ul>";
      while ($row=$result->fetchRow()) {
	print "<li>" . $row[0] . "</li>\n";
      }
      print "</ul>";
    }
  }

function showReviewers( $paperId, $type )
{
  print "<TR><TD>$type:</TD><TD>";
   $result = $this->qe("SELECT ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, ContactInfo.contactId "
		       . " FROM ContactInfo, $type" . "Reviewer "
		       . " WHERE ( $type" . "Reviewer.paperId='$paperId' "
		       . "         AND $type" . "Reviewer.reviewer=ContactInfo.contactId)");
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

function paperTable( $showAuthor = true, $showAbs = true, $paperId = -1,
		     $showRev = false, $showTopics = true, $showCollab = -1 )
{
  if( $showCollab < 0 ){
    $showCollab = $showAuthor;
  }
  if( $paperId < 0 ){
    $paperId = $_REQUEST[paperId];
  }
  $query = "SELECT Paper.title, Paper.abstract, Paper.authorInformation, "
    . " LENGTH(PaperStorage.paper) as size, PaperStorage.mimetype, Paper.collaborators, Paper.pcPaper "
    . " FROM Paper, PaperStorage WHERE "
    . " Paper.paperId=$paperId "
    . " AND PaperStorage.paperId=$paperId";

  if ( !($_SESSION["Me"]->isChair || $_SESSION["Me"]->isPC || $_SESSION["Me"] -> isAssistant) ){
    $query = $query . " AND Paper.contactId='" . $_SESSION["Me"]->contactId . "' ";
  }
  //$this->warnMsg($query);

  $result = $this->qe($query);

  if ( DB::isError($result) ) {
    $this->errorMsg("That's odd - paper #$paperId cannot be displayed. "
		    . $result->getMessage());
    exit();
  } 
 
  $row = $result->fetchRow(DB_FETCHMODE_ASSOC);

  $title = $this->safeHtml($row['title']);
  $abstract = $this->safeHtml($row['abstract']);
  $authorInfo = $this->safeHtml($row['authorInformation']);
  $paperLength = $row['size'];
  $mimetype = $row['mimetype'];
  $collaborators = $this->safeHtml($row['collaborators']);
  
  $badPaper = $paperLength < 100 && $mimetype == 'application/txt';
    ?>
    <table align=center border=1 bgcolor="<?php echo $this->bgOne?>" >
       <tr> <th colspan=2 align=center><font size=+1>
       <b> Paper #
<?php
    echo $paperId;
    if( $badPaper ){
        echo ' (not yet uploaded)';
    } else {
?>
       <a href="<?php  echo $this->makeDownloadPath($paperId, $mimetype) ?>" target=_blank>
	  (Download paper of type <?php  echo $mimetype . ", " . $paperLength . " bytes" ?>) </a>
<?php
    }
?>
	</b> </font> </th> </tr>
   <tr> <td> Title: </td> <td> <?php  echo $title ?> </td> </tr>
<?php
    if ( $_SESSION["Me"]->isChair && $row['pcPaper'] ){
?>
   <TR> <TD COLSPAN=2 ALIGN=CENTER BGCOLOR='Red'> This is a PC paper. </TD> </TR>
<?php
    }
?>
<?php
    if( $showAuthor ){
?>
   <tr> <td> Author Information: </td> <td> <?php  echo $authorInfo ?> </td> </tr>
<?php
    }
?>
<?php
    if( $showAbs ){
?>
   <tr> <td> Abstract: </td> <td> <?php  echo $abstract ?> </td> </tr>
<?php
    }
?>
<?php
    if( $showCollab ){
?>
   <tr> <td> Collaborators: </td> <td> <?php  echo $collaborators ?> </td> </tr>
<?php
    }
    if( $showRev ){
      $this->showReviewers( $paperId, 'Primary' );
      $this->showReviewers( $paperId, 'Secondary' );
    }

    if( $showTopics ){
      $query="SELECT topicName from TopicArea, PaperTopic "
	. "WHERE PaperTopic.paperId=$paperId "
	. "AND PaperTopic.topicId=TopicArea.topicAreaId ";
      
      $result = $this->qe($query);
      if ( ! DB::isError($result) && $result->numRows() > 0 ) {
	print "<tr>";
	print "<td> Indicated Topics </td>";
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
	. " FROM ContactInfo, PCMember, PaperReviewerPreference "
	. " WHERE PaperReviewerPreference.paperId='$paperId' "
	. " AND ContactInfo.contactId=PCMember.contactId "
	. " AND PCMember.contactId=PaperReviewerPreference.contactId "
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
?>
