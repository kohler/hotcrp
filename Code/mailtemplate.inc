<?php
// mailtemplate.inc -- HotCRP mail templates
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $mailTemplates;
$mailTemplates = array
    ("createaccount" =>
     array("[%CONFSHORTNAME%] New account information",
	   "Greetings,

An account has been created for you at the %CONFNAME% submissions site, including an initial password.

        Site: %URL%/
       Email: %EMAIL%
    Password: %PASSWORD%

To sign in directly, either click the link below or paste it into your web browser's location field.

    %URL%/login.php?email=%URLENC(EMAIL)%&password=%URLENC(PASSWORD)%

If you already have an account under a different email address, you may merge this new account into that one.  Sign in to the site and select \"Merge accounts\".

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "accountinfo" =>
     array("[%CONFSHORTNAME%] Account information",
	   "Dear %NAME%,

Here is your account information for the %CONFNAME% submissions site.

        Site: %URL%/
       Email: %EMAIL%
    Password: %PASSWORD%

To sign in directly, either click the link below or paste it into your web browser's location field.

    %URL%/login.php?email=%URLENC(EMAIL)%&password=%URLENC(PASSWORD)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "mergeaccount" =>
     array("[%CONFSHORTNAME%] Merged account",
	   "Dear %NAME%,

Your account at the %CONFSHORTNAME% submissions site has been merged with the account of %OTHERCONTACT%.  From now on, you should log in using the %OTHEREMAIL% account.

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "requestreview" =>
     array("[%CONFSHORTNAME%] Review request for paper #%NUMBER%",
	   "Dear %NAME%,

%OTHERCONTACT% has asked you to review %CONFNAME% paper #%NUMBER%.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/review.php?paperId=%NUMBER%

If you are willing to review this paper, you may enter your review on the conference site, or complete a review form offline and upload it.  %IF(DEADLINE(extrev_soft))%Please enter your review by %DEADLINE(extrev_soft)%.  %ENDIF%If you cannot complete the review, please refuse the review on the paper site or contact %OTHERNAME% directly.  For reference, your account information is as follows.

        Site: %URL%/
       Email: %EMAIL%
    Password: %PASSWORD%

To sign in, either click the link below or paste it into your web browser's location field.

    %URL%/login.php?email=%URLENC(EMAIL)%&password=%URLENC(PASSWORD)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

Thanks for your help -- we appreciate that reviewing is hard work!
- %CONFSHORTNAME% Submissions\n"),

     "retractrequest" =>
     array("[%CONFSHORTNAME%] Retracting review request for paper #%NUMBER%",
	   "Dear %NAME%,

%OTHERNAME% has retracted a previous request that you review %CONFNAME% paper #%NUMBER%.  There's no need to complete your review.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

Thank you,
- %CONFSHORTNAME% Submissions\n"),

     "proposereview" =>
     array("[%CONFSHORTNAME%] Proposed reviewer for paper #%NUMBER%",
	   "Greetings,

%OTHERCONTACT% would like %CONTACT% to review %CONFNAME% paper #%NUMBER%.  Visit the assignment page to approve or deny the request.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/assign.php?paperId=%NUMBER%

- %CONFSHORTNAME% Submissions\n"),

     "denyreviewrequest" =>
     array("[%CONFSHORTNAME%] Proposed reviewer for paper #%NUMBER% denied",
	   "Dear %NAME%,

Your proposal that %OTHERCONTACT% review %CONFNAME% paper #%NUMBER% has been denied by an administrator.  You may want to propose someone else.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/paper.php?paperId=%NUMBER%

Contact the site administrator, %ADMIN%, with any questions or concerns.

Thank you,
- %CONFSHORTNAME% Submissions\n"),

     "refusereviewrequest" =>
     array("[%CONFSHORTNAME%] Review request for paper #%NUMBER% refused",
	   "Dear %NAME%,

%OTHERCONTACT% cannot complete the review of %CONFNAME% paper #%NUMBER% that you requested.  %IF(REASON)%They gave the reason \"%REASON%\".  %ENDIF%You may want to find an alternate reviewer.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/paper.php?paperId=%NUMBER%

- %CONFSHORTNAME% Submissions\n"));


class Mailer {

    var $row;
    var $contact;
    var $otherContact;
    var $ifstack;
    var $text;
    var $textstart;
    var $reason;

    function Mailer($row, $contact, $otherContact = null, $rest = array()) {
	$this->row = $row;
	$this->contact = $contact;
	$this->otherContact = $otherContact;
	$this->ifstack = array();
	$this->text = "";
	$this->textstart = 0;
	$this->reason = defval($rest["reason"], "");
    }
    
    function _pushIf($yes) {
	if ($yes)
	    array_push($this->ifstack, true);
	else
	    array_push($this->ifstack, $this->text);
    }

    function _popIf() {
	if (count($this->ifstack) == 0)
	    /* ignore */;
	else if (($pop = array_pop($this->ifstack)) === true)
	    /* ignore */;
	else {
	    $this->text = $pop;
	    if ($this->textstart > strlen($this->text))
		$this->textstart = strlen($this->text);
	}
    }
    
    function expandvar($what, $isbool = false) {
	global $Conf;
	$len = strlen($what);

	if ($len > 6 && substr($what, 0, 4) == "%IF(" && substr($what, $len - 2) == ")%") {
	    $inner = "%" . substr($what, 4, $len - 6) . "%";
	    $this->_pushIf($this->expandvar($inner, true));
	    return "";
	}
	if ($what == "%ENDIF%") {
	    $this->_popIf();
	    return "";
	}
	
	if ($len > 7 && substr($what, 0, 5) == "%OPT(" && substr($what, $len - 2) == ")%") {
	    $inner = "%" . substr($what, 5, $len - 7) . "%";
	    if ($isbool)
		return $this->expandvar($inner, true);
	    else if ($this->expandvar($inner, true))
		return $this->expandvar($inner, false);
	    else
		return "";
	}
	
	if ($len > 10 && substr($what, 0, 8) == "%URLENC(" && substr($what, $len - 2) == ")%") {
	    $inner = "%" . substr($what, 8, $len - 10) . "%";
	    if ($isbool)
		return $this->expandvar($inner, true);
	    else
		return urlencode($this->expandvar($inner, false));
	}

	if ($len > 12 && substr($what, 0, 10) == "%DEADLINE(" && substr($what, $len - 2) == ")%") {
	    $inner = substr($what, 10, $len - 12);
	    if ($isbool)
		return $Conf->setting($inner) > 0;
	    else
		return $Conf->printableTimeSetting($inner);
	}

	if ($what == "%CONFNAME%") {
	    $t = $Conf->longName;
	    if ($Conf->shortName && $Conf->shortName != $Conf->longName)
		$t .= " ($Conf->shortName)";
	    return $t;
	}
	if ($what == "%CONFSHORTNAME%")
	    return $Conf->shortName;
	if ($what == "%CONFLONGNAME%")
	    return $Conf->longName;
	if ($what == "%ADMIN%")
	    return "$Conf->contactName <$Conf->contactEmail>";
	if ($what == "%URL%")
	    return $Conf->paperSite;
	
	if ($what == "%EMAIL%")
	    return $this->contact->email;
	if ($what == "%PASSWORD%")
	    return $this->contact->password;
	if ($what == "%CONTACT%")
	    return contactText($this->contact);
	if ($what == "%NAME%")
	    return contactNameText($this->contact);
	if ($what == "%FIRST%")
	    return $this->contact->firstName;
	if ($what == "%LAST%")
	    return $this->contact->lastName;

	if ($what == "%OTHERCONTACT%" && $this->otherContact)
	    return contactText($this->otherContact);
	if ($what == "%OTHERNAME%" && $this->otherContact)
	    return contactNameText($this->otherContact);
	if ($what == "%OTHEREMAIL%" && $this->otherContact)
	    return $this->otherContact->email;
	if ($what == "%REASON%")
	    return $this->reason;

	// rest is only there if we have a real paper
	if (defval($this->row->paperId) <= 0)
	    return ($isbool ? false : $what);
    
	if ($what == "%TITLE%")
	    return $this->row->title;
	if ($what == "%NUMBER%")
	    return $this->row->paperId;
	if ($what == "%AUTHOR%" || $what == "%AUTHORS%") {
	    if ($Conf->blindSubmission() > 1
		|| ($Conf->blindSubmission() == 1 && $this->row->blind))
		return ($isbool ? false : "Hidden for blind review");
	    return $this->row->authorInformation;
	}
	if ($what == "%REVIEWS%") {
	    $contact = new Contact;
	    $contact->makeMinicontact($this->row);
	    return $this->getReviews($this->row->paperId, $contact, false);
	}
	if ($what == "%COMMENTS%")
	    return $this->getComments($this->row->paperId);

	return $what;
    }

    function getReviews($paperId, $contact, $finalized) {
	global $Conf, $Me, $rf;

	$result = $Conf->qe("select Paper.title, PaperReview.*,
		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email,
		conflictType, ContactReview.reviewType as myReviewType
 		from PaperReview
		join Paper using (paperId)
		join ContactInfo on (ContactInfo.contactId=PaperReview.contactId)
		left join PaperConflict on (PaperConflict.contactId=$contact->contactId and PaperConflict.paperId=PaperReview.paperId)
		left join PaperReview as ContactReview on (ContactReview.contactId=$contact->contactId and ContactReview.paperId=PaperReview.paperId)
		where PaperReview.paperId=$paperId order by reviewOrdinal", "while retrieving reviews");
	if (edb_nrows($result)) {
	    $text = "";
	    while (($row = edb_orow($result)))
		if ($row->reviewSubmitted > 0) {
		    $text .= $rf->authorTextForm($row, $row, $contact, $Conf, null) . "\n";
		}
	    return $text;
	} else
	    return "";
    }

    function getComments($paperId) {
	global $Conf;
	
	$result = $Conf->qe("select PaperComment.*,
 		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email,
		PaperConflict.conflictType
		from PaperComment
 		join ContactInfo on (ContactInfo.contactId=PaperComment.contactId)
		left join PaperConflict on (PaperConflict.paperId=PaperComment.paperId and PaperConflict.contactId=PaperComment.contactId)
		where PaperComment.paperId=$paperId and forAuthors>0 order by commentId", "while retrieving comments");
	$text = "";
	while (($row = edb_orow($result))) {
	    $text .= "===========================================================================\n";
	    $n = ($row->conflictType == CONFLICT_AUTHOR ? "Author's Response" : "Comment");
	    if ($row->blind <= 0)
		$n .= " by " . contactText($row);
	    $text .= str_pad($n, (int) (37.5 + strlen($n) / 2), " ", STR_PAD_LEFT) . "\n";
	    $n = "Updated " . $Conf->printableTime($row->timeModified);
	    $text .= str_pad($n, (int) (37.5 + strlen($n) / 2), " ", STR_PAD_LEFT) . "\n";
	    $text .= "---------------------------------------------------------------------------\n";
	    $text .= $row->comment . "\n\n";
	}
	return $text;
    }

    function expand($text) {
	if (is_array($text)) {
	    $a = array();
	    foreach ($text as $t)
		$a[] = $this->expand($t);
	    return $a;
	}
	
	$lines = explode("\n", $text);
	$this->text = "";
	for ($i = 0; $i < count($lines); $i++) {
	    $line = rtrim($lines[$i]);
	    if ($line != "" && ctype_alpha($line[0]))
		while ($i+1 < count($lines) && ($linex = rtrim($lines[$i+1])) != ""
		       && ctype_alpha($linex[0])) {
		    $line .= " " . $linex;
		    $i++;
		}

	    if ($line == "")
		$this->text .= "\n";
	    else if (preg_match('/^%[\w()]+%$/', $line)) {
		if (($m = $this->expandvar($line, false)) != "")
		    $this->text .= $m . "\n";
	    } else if (preg_match('/^([ \t][ \t]*.*?: )(%OPT\([\w()]+\)%)$/', $line, $m)) {
		
		if ($this->expandvar($m[2], true))
		    $this->text .= wordWrapIndent($this->expandvar($m[2]), $m[1], tabLength($m[1], true)) . "\n";
	    } else if (preg_match('/^([ \t][ \t]*.*?: )(%[\w()]+%)$/', $line, $m))
		$this->text .= wordWrapIndent($this->expandvar($m[2]), $m[1], tabLength($m[1], true)) . "\n";
	    else if (strpos($line, '%') !== false) {
		$this->textstart = strlen($this->text);
		while (preg_match('/^(.*?)(%[\w()]+%)(.*)$/s', $line, $m)) {
		    $this->text .= $m[1];
		    $this->text .= $this->expandvar($m[2]);
		    $line = $m[3];
		}
		$this->text .= $line;
		$this->text = substr($this->text, 0, $this->textstart) . wordWrapIndent(substr($this->text, $this->textstart), "", 0) . "\n";
	    } else
		$this->text .= wordWrapIndent($line, "", 0) . "\n";
	}
	return $this->text;
    }

    function sendTemplate($template, $row, $contact, $otherContact = null, $rest = array()) {
	global $Conf, $mailTemplates;

	if (is_string($template) && $template[0] == "@")
	    $template = $mailTemplates[substr($template, 1)];

	if (!isset($rest["emailTo"]) || !$rest["emailTo"])
	    $emailTo = $contact;
	else if (is_string($rest["emailTo"]))
	    $emailTo = (object) array("email" => $rest["emailTo"]);
	else
	    $emailTo = $rest["emailTo"];
	if (!$emailTo || !$emailTo->email)
	    return $Conf->errorMsg("no email in sendTemplate");
	$headers = defval($rest["headers"], "");
	if ($headers && $headers[strlen($headers) - 1] != '\n')
	    $headers .= "\n";
	
	$mailer = new Mailer($row, $contact, $otherContact, $rest);
	$m = $mailer->expand($template);
	$m[0] = rtrim($m[0]);
	if ($Conf->allowEmailTo($emailTo->email))
	    return mail(contactEmailTo($emailTo), $m[0], $m[1], "${headers}From: $Conf->emailFrom");
	else
	    return $Conf->infoMsg("<pre>" . htmlspecialchars("To: " . contactEmailTo($emailTo) . "\nSubject: $m[0]\n$headers\n$m[1]") . "</pre>");
    }
    
}
