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

    %LOGINURL%

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

    %LOGINURL%

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

    %LOGINURL%

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

- %CONFSHORTNAME% Submissions\n"),

     "authorwithdraw" =>
     array("[%CONFSHORTNAME%] Withdrawn paper #%NUMBER% %TITLEHINT%",
	   "Dear %NAME%,

An author of %CONFNAME% paper #%NUMBER% has withdrawn the paper from consideration.  The paper will not be reviewed.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/paper.php?paperId=%NUMBER%

Contact the site administrator, %ADMIN%, with any questions or concerns.

Thank you,
- %CONFSHORTNAME% Submissions\n"),

     "adminwithdraw" =>
     array("[%CONFSHORTNAME%] Withdrawn paper #%NUMBER% %TITLEHINT%",
	   "Dear %NAME%,

%CONFNAME% paper #%NUMBER% has been withdrawn from consideration and will not be reviewed.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/paper.php?paperId=%NUMBER%

%IF(REASON)%The paper was withdrawn by an administrator, who provided the following reason: %REASON%%ELSE%The paper was withdrawn by an administrator.%ENDIF%

Contact the site administrator, %ADMIN%, with any questions or concerns.

Thank you,
- %CONFSHORTNAME% Submissions\n"),     
     
     "withdrawreviewer" =>
     array("[%CONFSHORTNAME%] Withdrawn paper #%NUMBER% %TITLEHINT%",
	   "Dear %NAME%,

%CONFSHORTNAME% paper #%NUMBER%, which you reviewed or have been assigned to review, has been withdrawn from consideration for the conference.

Authors can voluntarily withdraw a submission at any time, as can the chair.  %REASON%

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/paper.php?paperId=%NUMBER%

You are not expected to complete your review; in fact the system will not allow it unless the paper is revived.

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "deletepaper" =>
     array("[%CONFSHORTNAME%] Deleted paper #%NUMBER% %TITLEHINT%",
	   "Dear %NAME%,

Your %CONFNAME% paper #%NUMBER% has been removed from the submission database by an administrator.  This is usually done to remove duplicate papers.  %IF(REASON)%The following reason was provided for deleting the paper: %REASON%%ENDIF%

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "reviewsubmit" =>
     array("[%CONFSHORTNAME%] Submitted review #%REVIEWNUMBER% %TITLEHINT%",
	   "Dear %NAME%,

Review #%REVIEWNUMBER% for %CONFNAME% paper #%NUMBER% has been submitted.  The review is available at the paper site.

  Paper site: %URL%/review.php?paperId=%NUMBER%
       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
   Review by: %OPT(REVIEWAUTHOR)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "reviewupdate" =>
     array("[%CONFSHORTNAME%] Updated review #%REVIEWNUMBER% %TITLEHINT%",
	   "Dear %NAME%,

Review #%REVIEWNUMBER% for %CONFNAME% paper #%NUMBER% has been updated.  The review is available at the paper site.

  Paper site: %URL%/review.php?paperId=%NUMBER%
       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
   Review by: %OPT(REVIEWAUTHOR)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "acceptnotify" =>
     array("[%CONFSHORTNAME%] Accepted paper #%NUMBER% %TITLEHINT%",
	   "Dear %NAME%,

The %CONFNAME% program committee is delighted to inform you that your paper #%NUMBER% has been accepted to appear in the conference.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/review.php?paperId=%NUMBER%

Your paper was one of %NUMACCEPTED% accepted out of %NUMSUBMITTED% submissions.  Congratulations!

Reviews and comments on your paper are appended to this email.  The submissions site also has the paper's reviews and comments, as well as more information about review scores.  To sign in, either click the link below or paste it into your web browser's location field.

    %LOGINURL%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions

%REVIEWS%
%COMMENTS%\n"),

     "rejectnotify" =>
     array("[%CONFSHORTNAME%] Rejected paper #%NUMBER% %TITLEHINT%",
	   "Dear %NAME%,

The %CONFNAME% program committee is sorry to inform you that your paper #%NUMBER% was rejected, and will not appear in the conference.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/review.php?paperId=%NUMBER%

%NUMACCEPTED% papers were accepted out of %NUMSUBMITTED% submissions.

Reviews and comments on your paper are appended to this email.  The submissions site also has the paper's reviews and comments, as well as more information about review scores.  To sign in, either click the link below or paste it into your web browser's location field.

    %LOGINURL%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions

%REVIEWS%
%COMMENTS%\n"),

     "commentnotify" =>
     array("[%CONFSHORTNAME%] Comment for #%NUMBER% %TITLEHINT%",
	   "A comment for %CONFNAME% paper #%NUMBER% has been submitted or updated.  The comment is copied below, and is available at the paper site.

  Paper site: %URL%/comment.php?paperId=%NUMBER%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions

%COMMENTS%\n"),
     
     "genericmailtool" =>
     array("[%CONFSHORTNAME%] Paper #%NUMBER% %TITLEHINT%",
	   "Dear %NAME%,

Your message here.

       Title: %TITLE%
  Paper site: %URL%/paper.php?paperId=%NUMBER%

To sign in to the submissions site, either click the link below or paste it into your web browser's location field.

    %LOGINURL%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "reviewremind" =>
     array("[%CONFSHORTNAME%] Review reminder for paper #%NUMBER% %TITLEHINT%",
	   "Dear %NAME%,

This is a reminder to finish your review for %CONFNAME% paper #%NUMBER%. %IF(REVIEWDEADLINE)% Reviews are requested by %REVIEWDEADLINE%. %ENDIF% If you are unable to complete the review, please \"refuse\" the review using the site or contact the person who requested the review directly.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/review.php?paperId=%NUMBER%

To sign in to the submissions site, either click the link below or paste it into your web browser's location field.

    %LOGINURL%

Thank you for your help -- we appreciate that reviewing is hard work.

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n")

);


class Mailer {

    var $row;
    var $contact;
    var $otherContact;
    var $ifstack;
    var $text;
    var $textstart;
    var $reason;
    var $rrow;
    var $reviewNumber;
    var $commentId;
    var $statistics;
    var $width;

    function Mailer($row, $contact, $otherContact = null, $rest = array()) {
	$this->row = $row;
	$this->contact = $contact;
	$this->otherContact = $otherContact;
	$this->ifstack = array();
	$this->text = "";
	$this->textstart = 0;
	$this->reason = defval($rest["reason"], "");
	$this->rrow = defval($rest["rrow"], null);
	$this->reviewNumber = defval($rest["reviewNumber"], "");
	$this->commentId = defval($rest["commentId"], null);
	$this->statistics = null;
	$this->width = 75;
    }
    
    function _pushIf($yes) {
	if ($yes === true || $yes === null)
	    array_push($this->ifstack, $yes);
	else
	    array_push($this->ifstack, $this->text);
    }

    function _popIf() {
	if (count($this->ifstack) == 0)
	    return null;
	else if (($pop = array_pop($this->ifstack)) === true || $pop === null)
	    return $pop;
	else {
	    $this->text = $pop;
	    if ($this->textstart > strlen($this->text))
		$this->textstart = strlen($this->text);
	    return false;
	}
    }
    
    function expandvar($what, $isbool = false) {
	global $Conf;
	$len = strlen($what);

	if ($len > 6 && substr($what, 0, 4) == "%IF(" && substr($what, $len - 2) == ")%") {
	    $inner = "%" . substr($what, 4, $len - 6) . "%";
	    $this->_pushIf(($yes = $this->expandvar($inner, true)));
	    return ($yes === null ? $what : "");
	} else if ($what == "%ELSE%") {
	    $yes = $this->_popIf();
	    $this->_pushIf($yes === null ? $yes : !$yes);
	    return ($yes === null ? $what : "");
	} if ($what == "%ENDIF%") {
	    $yes = $this->_popIf();
	    return ($yes === null ? $what : "");
	}
	
	if ($len > 7 && substr($what, 0, 5) == "%OPT(" && substr($what, $len - 2) == ")%") {
	    $inner = "%" . substr($what, 5, $len - 7) . "%";
	    if ($isbool)
		return $this->expandvar($inner, true);
	    else if (($yes = $this->expandvar($inner, true)))
		return $this->expandvar($inner, false);
	    else
		return ($yes === null ? $what : "");
	}
	
	if ($len > 10 && substr($what, 0, 8) == "%URLENC(" && substr($what, $len - 2) == ")%") {
	    $inner = "%" . substr($what, 8, $len - 10) . "%";
	    $yes = $this->expandvar($inner, true);
	    if ($isbool)
		return $yes;
	    else if ($yes)
		return urlencode($this->expandvar($inner, false));
	    else
		return ($yes === null ? $what : "");
	}

	if ($what == "%REVIEWDEADLINE%") {
	    if (defval($this->row->reviewType) <= 0 && $Conf->setting("pcrev_soft") != $Conf->setting("extrev_soft")) {
		if ($isbool && ($Conf->setting("pcrev_soft") > 0) == ($Conf->setting("extrev_soft") > 0))
		    return $Conf->setting("pcrev_soft") > 0;
		else
		    return ($isbool ? null : $what);
	    }
	    $what = "%DEADLINE(" . (defval($this->row->reviewType) >= REVIEW_PC ? "pcrev_soft" : "extrev_soft") . ")%";
	    $len = strlen($what);
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
	if (($what == "%NUMACCEPTED%" || $what == "%NUMSUBMITTED%")
	    && $this->statistics === null) {
	    $this->statistics = array(0, 0);
	    $result = $Conf->q("select outcome, count(paperId) from Paper where timeSubmitted>0 group by outcome");
	    while (($row = edb_row($result))) {
		$this->statistics[0] += $row[1];
		if ($row[0] > 0)
		    $this->statistics[1] += $row[1];
	    }
	}
	if ($what == "%NUMSUBMITTED%")
	    return $this->statistics[0];
	if ($what == "%NUMACCEPTED%")
	    return $this->statistics[1];
	
	// if no contact, this is a pre-expansion
	if (!$this->contact)
	    return ($isbool ? null : $what);

	if ($what == "%LOGINURL%")
	    return $Conf->paperSite . "/login.php?email=" . urlencode($this->contact->email) . "&password=" . urlencode($this->contact->password);
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
	if (!$this->row || defval($this->row->paperId) <= 0)
	    return ($isbool ? false : $what);

	if ($what == "%TITLE%")
	    return $this->row->title;
	if ($what == "%TITLEHINT%") {
	    if (($tw = titleWords($this->row->title)))
		return "\"$tw\"";
	    else
		return "";
	}
	if ($what == "%NUMBER%" || $what == "%PAPER%")
	    return $this->row->paperId;
	if ($what == "%REVIEWNUMBER%")
	    return $this->reviewNumber;
	if ($what == "%AUTHOR%" || $what == "%AUTHORS%") {
	    if (paperBlind($this->row)
		&& defval($this->row->conflictType) < CONFLICT_AUTHOR
		&& defval($this->contact->conflictType) < CONFLICT_AUTHOR)
		return ($isbool ? false : "Hidden for blind review");
	    cleanAuthor($this->row);
	    return $this->row->authorInformation;
	}

	if ($what == "%REVIEWAUTHOR%" && $this->otherContact) {
	    if (reviewBlind($this->rrow)
		&& defval($this->contact->privChair) <= 0
		&& (!isset($this->contact->canViewReviewerIdentity)
		    || !$this->contact->canViewReviewerIdentity($this->row, $this->rrow, $Conf, true)))
		return ($isbool ? false : "Hidden for blind review");
	    return contactText($this->otherContact);
	}
	
	if ($what == "%REVIEWS%")
	    return $this->getReviews($this->contact, false);
	if ($what == "%COMMENTS%")
	    return $this->getComments($this->contact);

	return ($isbool ? false : $what);
    }

    function getReviews($contact, $finalized) {
	global $Conf, $Me, $rf;

	$result = $Conf->qe("select Paper.title, PaperReview.*,
		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email,
		conflictType, ContactReview.reviewType as myReviewType
 		from PaperReview
		join Paper using (paperId)
		join ContactInfo on (ContactInfo.contactId=PaperReview.contactId)
		left join PaperConflict on (PaperConflict.contactId=$contact->contactId and PaperConflict.paperId=PaperReview.paperId)
		left join PaperReview as ContactReview on (ContactReview.contactId=$contact->contactId and ContactReview.paperId=PaperReview.paperId)
		where PaperReview.paperId=" . $this->row->paperId . " order by reviewOrdinal", "while retrieving reviews");
	if (edb_nrows($result)) {
	    $text = "";
	    while (($row = edb_orow($result)))
		if ($row->reviewSubmitted)
		    $text .= $rf->prettyTextForm($row, $row, $contact, $Conf, true) . "\n";
	    return $text;
	} else
	    return "";
    }

    function getComments($contact) {
	global $Conf;

	$q = "select PaperComment.*,
 		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email,
		PaperConflict.conflictType
		from PaperComment
 		join ContactInfo on (ContactInfo.contactId=PaperComment.contactId)
		left join PaperConflict on (PaperConflict.paperId=PaperComment.paperId and PaperConflict.contactId=PaperComment.contactId)";
	if (is_array($this->commentId))
	    $q .= "\n\t\twhere PaperComment.commentId in (" . join(", ", $this->commentId) . ")";
	else if ($this->commentId)
	    $q .= "\n\t\twhere PaperComment.commentId=$this->commentId";
	else
	    $q .= "\n\t\twhere PaperComment.paperId=" . $this->row->paperId;
	$result = $Conf->qe($q . "\n\t\torder by commentId", "while retrieving comments");
	$text = "";
	while (($crow = edb_orow($result))) {
	    if (!$contact->canViewComment($this->row, $crow, $Conf, $whyNot, true))
		continue;
	    $text .= "===========================================================================\n";
	    $n = ($crow->conflictType >= CONFLICT_AUTHOR ? "Author's Response" : "Comment");
	    if ($contact->canViewCommentIdentity($this->row, $crow, $Conf, true))
		$n .= " by " . contactText($crow);
	    $text .= str_pad($n, (int) (37.5 + strlen($n) / 2), " ", STR_PAD_LEFT) . "\n";
	    // $n = "Updated " . $Conf->printableTime($crow->timeModified);
	    // $text .= str_pad($n, (int) (37.5 + strlen($n) / 2), " ", STR_PAD_LEFT) . "\n";
	    $text .= "---------------------------------------------------------------------------\n";
	    $text .= $crow->comment . "\n\n";
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
	    if ($line == "")
		$this->text .= "\n";
	    else if (preg_match('/^%[\w()]+%$/', $line)) {
		if (($m = $this->expandvar($line, false)) != "")
		    $this->text .= $m . "\n";
	    } else if (preg_match('/^([ \t][ \t]*.*?: )(%OPT\([\w()]+\)%)$/', $line, $m)) {
		
		if (($yes = $this->expandvar($m[2], true)))
		    $this->text .= wordWrapIndent($this->expandvar($m[2]), $m[1], tabLength($m[1], true), $this->width) . "\n";
		else if ($yes === null)
		    $this->text .= $line . "\n";
	    } else if (preg_match('/^([ \t][ \t]*.*?: )(%[\w()]+%)$/', $line, $m))
		$this->text .= wordWrapIndent($this->expandvar($m[2]), $m[1], tabLength($m[1], true), $this->width) . "\n";
	    else if (strpos($line, '%') !== false) {
		$this->textstart = strlen($this->text);
		while (preg_match('/^(.*?)(%[\w()]+%)(.*)$/s', $line, $m)) {
		    $this->text .= $m[1];
		    $this->text .= $this->expandvar($m[2]);
		    $line = $m[3];
		}
		$this->text .= $line;
		$this->text = substr($this->text, 0, $this->textstart) . wordWrapIndent(substr($this->text, $this->textstart), "", 0, $this->width) . "\n";
	    } else
		$this->text .= wordWrapIndent($line, "", 0, $this->width) . "\n";
	}
	return $this->text;
    }

    function prepareToSend($template, $row, $contact, $otherContact = null, $rest = array()) {
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
	    return $Conf->errorMsg("no email in Mailer::send");
	$headers = defval($rest["headers"], "");
	if ($headers && substr($headers, strlen($headers) - 2) != "\r\n")
	    $headers .= "\r\n";
	$headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n";
	
	$mailer = new Mailer($row, $contact, $otherContact, $rest);
	$m = $mailer->expand($template);
	$m[0] = Mailer::mimeHeaderQuote(rtrim($m[0]), 73 - 21);
	$m["allowEmail"] = $Conf->allowEmailTo($emailTo->email);
	$m["to"] = contactEmailTo($emailTo);
	$m["headers"] = $headers;
	return $m;
    }

    function sendPrepared($preparation) {
	global $Conf;
	if ($preparation["allowEmail"])
	    return mail($preparation["to"], $preparation[0], $preparation[1], $preparation["headers"] . "From: $Conf->emailFrom");
	else
	    return $Conf->infoMsg("<pre>" . htmlspecialchars("To: " . $preparation["to"] . "\nSubject: $preparation[0]\n" . $preparation["headers"] . "\n$preparation[1]") . "</pre>");
    }
    
    function send($template, $row, $contact, $otherContact = null, $rest = array()) {
	$preparation = self::prepareToSend($template, $row, $contact, $otherContact, $rest);
	if ($preparation)
	    self::sendPrepared($preparation);
    }

    function sendContactAuthors($template, $row, $otherContact = null, $rest = array()) {
	global $Conf, $Me, $mailTemplates;

	$result = $Conf->qe("select ContactInfo.contactId,
		firstName, lastName, email, password, conflictType, 0 as myReviewType
		from ContactInfo join PaperConflict using (contactId)
		where paperId=$row->paperId and conflictType>=" . CONFLICT_AUTHOR . "
		group by ContactInfo.contactId", "while looking up contact authors to send email");

	$contacts = array();
	while (($contact = edb_orow($result))) {
	    Mailer::send($template, $row, Contact::makeMinicontact($contact), $otherContact, $rest);
	    $contacts[] = contactHtml($contact);
	}

	if ($row->conflictType < CONFLICT_AUTHOR && count($contacts) && $Me->privChair) {
	    $endmsg = (isset($rest["infoMsg"]) ? ", " . $rest["infoMsg"] : ".");
	    if (isset($rest["infoNames"]) && $Me->privChair)
		$contactsmsg = pluralx($contacts, "contact author") . ", " . commajoin($contacts);
	    else
		$contactsmsg = "contact author(s)";
	    $Conf->infoMsg("Sent email to paper #$row->paperId's $contactsmsg$endmsg");
	}
    }

    function sendReviewers($template, $row, $otherContact = null, $rest = array()) {
	global $Conf, $Me, $mailTemplates;

	$result = $Conf->qe("select ContactInfo.contactId,
		firstName, lastName, email, password, reviewType as myReviewType
		from ContactInfo join PaperReview using (contactId)
		where paperId=$row->paperId
		group by ContactInfo.contactId", "while looking up reviewers to send email");

	$rest["headers"] = "Cc: $Conf->contactName <$Conf->contactEmail>";
	
	$contacts = array();
	while (($contact = edb_orow($result))) {
	    Mailer::send($template, $row, Contact::makeMinicontact($contact), $otherContact, $rest);
	    $contacts[] = contactHtml($contact);
	}

	if ($row->conflictType < CONFLICT_AUTHOR && count($contacts) && $Me->privChair) {
	    $endmsg = (isset($rest["infoMsg"]) ? ", " . $rest["infoMsg"] : ".");
	    $Conf->infoMsg("Sent email to paper #$row->paperId's " . pluralx($contacts, "reviewer") . ", " . commajoin($contacts) . $endmsg);
	}
    }

    function mimeHeaderQuote($text, $len) {
	// replace all special characters used by the encoder
	$text = str_replace(array('=',   '_',   '?',   ' '),
			    array('=3D', '=5F', '=3F', '_'), $text);
	// replace all extended characters (\x80-xFF)
	$text = preg_replace('#([\x80-\xFF])#e',
			     '"=" . strtoupper(dechex(ord("\1")))', $text);
	$xpref = "";
	$lenregex = "|(.{0," . ($len - 2) . "}[^=][^=])|";
	while (strlen($text) > $len) {
	    preg_match($lenregex, $text, $m);
	    $xpref .= "=?utf-8?q?" . $m[1] . "?=\r\n ";
	    $text = substr($text, strlen($m[1]));
	}
	return $xpref . "=?utf-8?q?" . $text . "?=";
    }

    function mimeHeaderUnquote($text) {
	if (strlen($text) > 2 && $text[0] == '=' && $text[1] == '?') {
	    $out = '';
	    while (preg_match('/\A=\?utf-8\?q\?(.*?)\?=(\r\n )?/', $text, $m)) {
		$f = str_replace('_', ' ', $m[1]);
		$out .= preg_replace('/=([0-9A-F][0-9A-F])/e',
				     'chr(hexdec("\1"))', $f);
		$text = substr($text, strlen($m[0]));
	    }
	    return $out . $text;
	} else
	    return $text;
    }
    
}
