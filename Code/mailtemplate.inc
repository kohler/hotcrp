<?php
// mailtemplate.inc -- HotCRP mail templates
// HotCRP is Copyright (c) 2006-2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $mailHeaders;
$mailHeaders = array("cc" => "Cc", "bcc" => "Bcc", "replyto" => "Reply-To");

global $mailTemplates;
$mailTemplates = array
    ("createaccount" =>
     array("subject" => "[%CONFSHORTNAME%] New account information",
	   "body" => "Greetings,

An account has been created for you at the %CONFNAME% submissions site, including an initial password.

        Site: %URL%/
       Email: %EMAIL%
    Password: %OPT(PASSWORD)%

To sign in directly, either click the link below or paste it into your web browser's location field.

%LOGINURL%

If you already have an account under a different email address, you may merge this new account into that one.  Sign in to the site and select \"Merge accounts\".

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "accountinfo" =>
     array("subject" => "[%CONFSHORTNAME%] Account information",
	   "body" => "Dear %NAME%,

Here is your account information for the %CONFNAME% submissions site.

        Site: %URL%/
       Email: %EMAIL%
    Password: %OPT(PASSWORD)%

To sign in directly, either click the link below or paste it into your web browser's location field.

%LOGINURL%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "mergeaccount" =>
     array("subject" => "[%CONFSHORTNAME%] Merged account",
	   "body" => "Dear %NAME%,

Your account at the %CONFSHORTNAME% submissions site has been merged with the account of %OTHERCONTACT%.  From now on, you should log in using the %OTHEREMAIL% account.

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "requestreview" =>
     array("subject" => "[%CONFSHORTNAME%] Review request for paper #%NUMBER%",
	   "body" => "Dear %NAME%,

On behalf of the %CONFNAME% program committee, %OTHERCONTACT% would like to solicit your help with the review of %CONFNAME% paper #%NUMBER%.%IF(REASON)%  They supplied this note: %REASON%%ENDIF%

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/paper%PHP%?p=%NUMBER%

If you are willing to review this paper, you may enter your review on the conference site or complete a review form offline and upload it.%IF(DEADLINE(extrev_soft))%  Your review is requested by %DEADLINE(extrev_soft)%.%ENDIF%

Once you've decided, please take a moment to accept or refuse this review request by using one of these links.  You may also contact %OTHERNAME% directly or refuse the request using the conference site.

      Accept: %URL%/review%PHP%?p=%NUMBER%&accept=1&%LOGINURLPARTS%
      Refuse: %URL%/review%PHP%?p=%NUMBER%&refuse=1&%LOGINURLPARTS%

For reference, your account information is as follows.

        Site: %URL%/
       Email: %EMAIL%
    Password: %OPT(PASSWORD)%

To sign in, either click the link below or paste it into your web browser's location field.

%LOGINURL%

Contact the site administrator, %ADMIN%, with any questions or concerns.

Thanks for your help -- we appreciate that reviewing is hard work!
- %CONFSHORTNAME% Submissions\n"),

     "retractrequest" =>
     array("subject" => "[%CONFSHORTNAME%] Retracting review request for paper #%NUMBER%",
	   "body" => "Dear %NAME%,

%OTHERNAME% has retracted a previous request that you review %CONFNAME% paper #%NUMBER%.  There's no need to complete your review.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

Thank you,
- %CONFSHORTNAME% Submissions\n"),

     "proposereview" =>
     array("subject" => "[%CONFSHORTNAME%] Proposed reviewer for paper #%NUMBER%",
	   "body" => "Greetings,

%OTHERCONTACT% would like %CONTACT% to review %CONFNAME% paper #%NUMBER%.%IF(REASON)%  They supplied this note: %REASON%%ENDIF%

Visit the assignment page to approve or deny the request.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/assign%PHP%?p=%NUMBER%

- %CONFSHORTNAME% Submissions\n"),

     "denyreviewrequest" =>
     array("subject" => "[%CONFSHORTNAME%] Proposed reviewer for paper #%NUMBER% denied",
	   "body" => "Dear %NAME%,

Your proposal that %OTHERCONTACT% review %CONFNAME% paper #%NUMBER% has been denied by an administrator.  You may want to propose someone else.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/paper%PHP%?p=%NUMBER%

Contact the site administrator, %ADMIN%, with any questions or concerns.

Thank you,
- %CONFSHORTNAME% Submissions\n"),

     "refusereviewrequest" =>
     array("subject" => "[%CONFSHORTNAME%] Review request for paper #%NUMBER% refused",
	   "body" => "Dear %NAME%,

%OTHERCONTACT% cannot complete the review of %CONFNAME% paper #%NUMBER% that you requested.  %IF(REASON)%They gave the reason \"%REASON%\".  %ENDIF%You may want to find an alternate reviewer.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/paper%PHP%?p=%NUMBER%

- %CONFSHORTNAME% Submissions\n"),

     "authorwithdraw" =>
     array("subject" => "[%CONFSHORTNAME%] Withdrawn paper #%NUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

An author of %CONFNAME% paper #%NUMBER% has withdrawn the paper from consideration.  The paper will not be reviewed.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/paper%PHP%?p=%NUMBER%

Contact the site administrator, %ADMIN%, with any questions or concerns.

Thank you,
- %CONFSHORTNAME% Submissions\n"),

     "adminwithdraw" =>
     array("subject" => "[%CONFSHORTNAME%] Withdrawn paper #%NUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

%CONFNAME% paper #%NUMBER% has been withdrawn from consideration and will not be reviewed.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/paper%PHP%?p=%NUMBER%

%IF(REASON)%The paper was withdrawn by an administrator, who provided the following reason: %REASON%%ELSE%The paper was withdrawn by an administrator.%ENDIF%

Contact the site administrator, %ADMIN%, with any questions or concerns.

Thank you,
- %CONFSHORTNAME% Submissions\n"),

     "withdrawreviewer" =>
     array("subject" => "[%CONFSHORTNAME%] Withdrawn paper #%NUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

%CONFSHORTNAME% paper #%NUMBER%, which you reviewed or have been assigned to review, has been withdrawn from consideration for the conference.

Authors can voluntarily withdraw a submission at any time, as can the chair.  %REASON%

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/paper%PHP%?p=%NUMBER%

You are not expected to complete your review; in fact the system will not allow it unless the paper is revived.

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "deletepaper" =>
     array("subject" => "[%CONFSHORTNAME%] Deleted paper #%NUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

Your %CONFNAME% paper #%NUMBER% has been removed from the submission database by an administrator.  This is usually done to remove duplicate papers.  %IF(REASON)%The following reason was provided for deleting the paper: %REASON%%ENDIF%

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "reviewsubmit" =>
     array("subject" => "[%CONFSHORTNAME%] Submitted review #%REVIEWNUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

Review #%REVIEWNUMBER% for %CONFNAME% paper #%NUMBER% has been submitted.  The review is available at the paper site.

  Paper site: %URL%/paper%PHP%?p=%NUMBER%
       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
   Review by: %OPT(REVIEWAUTHOR)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "reviewupdate" =>
     array("subject" => "[%CONFSHORTNAME%] Updated review #%REVIEWNUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

Review #%REVIEWNUMBER% for %CONFNAME% paper #%NUMBER% has been updated.  The review is available at the paper site.

  Paper site: %URL%/paper%PHP%?p=%NUMBER%
       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
   Review by: %OPT(REVIEWAUTHOR)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "acceptnotify" =>
     array("subject" => "[%CONFSHORTNAME%] Accepted paper #%NUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

The %CONFNAME% program committee is delighted to inform you that your paper #%NUMBER% has been accepted to appear in the conference.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/paper%PHP%?p=%NUMBER%

Your paper was one of %NUMACCEPTED% accepted out of %NUMSUBMITTED% submissions.  Congratulations!

Reviews and comments on your paper are appended to this email.  The submissions site also has the paper's reviews and comments, as well as more information about review scores.  To sign in, either click the link below or paste it into your web browser's location field.

%LOGINURL%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions

%REVIEWS%
%COMMENTS%\n"),

     "rejectnotify" =>
     array("subject" => "[%CONFSHORTNAME%] Rejected paper #%NUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

The %CONFNAME% program committee is sorry to inform you that your paper #%NUMBER% was rejected, and will not appear in the conference.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/paper%PHP%?p=%NUMBER%

%NUMACCEPTED% papers were accepted out of %NUMSUBMITTED% submissions.

Reviews and comments on your paper are appended to this email.  The submissions site also has the paper's reviews and comments, as well as more information about review scores.  To sign in, either click the link below or paste it into your web browser's location field.

%LOGINURL%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions

%REVIEWS%
%COMMENTS%\n"),

     "commentnotify" =>
     array("subject" => "[%CONFSHORTNAME%] Comment for #%NUMBER% %TITLEHINT%",
	   "body" => "A comment for %CONFNAME% paper #%NUMBER% is available as shown below.  For the most up-to-date comments, or to turn off comment emails, see the paper site.

  Paper site: %URL%/paper%PHP%?p=%NUMBER%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions

%COMMENTS%\n"),

     "responsenotify" =>
     array("subject" => "[%CONFSHORTNAME%] Response for #%NUMBER% %TITLEHINT%",
	   "body" => "The authors' response for %CONFNAME% paper #%NUMBER% is available as shown below.  The authors may still update their response; for the most up-to-date version, or to turn off comment emails, see the paper site.

  Paper site: %URL%/paper%PHP%?p=%NUMBER%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions

%COMMENTS%\n"),

     "genericmailtool" =>
     array("subject" => "[%CONFSHORTNAME%] Paper #%NUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

Your message here.

       Title: %TITLE%
  Paper site: %URL%/paper%PHP%?p=%NUMBER%

To sign in to the submissions site, either click the link below or paste it into your web browser's location field.

%LOGINURL%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "reviewremind" =>
     array("subject" => "[%CONFSHORTNAME%] Review reminder for paper #%NUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

This is a reminder to finish your review for %CONFNAME% paper #%NUMBER%. %IF(REVIEWDEADLINE)% Reviews are requested by %REVIEWDEADLINE%. %ENDIF% If you are unable to complete the review, please \"refuse\" the review using the site or contact the person who requested the review directly.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/paper%PHP%?p=%NUMBER%

To sign in to the submissions site, either click the link below or paste it into your web browser's location field.

%LOGINURL%

Thank you for your help -- we appreciate that reviewing is hard work.

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "myreviewremind" =>
     array("subject" => "[%CONFSHORTNAME%] Review reminder for paper #%NUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

This is a reminder from %OTHERCONTACT% to finish your review for %CONFNAME% paper #%NUMBER%. %IF(REVIEWDEADLINE)% Reviews are requested by %REVIEWDEADLINE%. %ENDIF% If you are unable to complete the review, please \"refuse\" the review using the site or contact %OTHERNAME% directly.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/paper%PHP%?p=%NUMBER%

To sign in to the submissions site, either click the link below or paste it into your web browser's location field.

%LOGINURL%

Thank you for your help -- we appreciate that reviewing is hard work.

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n")

);


class Mailer {

    const EXPAND_BODY = 0;
    const EXPAND_HEADER = 1;
    const EXPAND_EMAIL = 2;

    var $row;
    var $contact;
    var $otherContact;
    var $ifstack;
    var $text;
    var $textstart;
    var $hideSensitive;
    var $hideReviews;
    var $reason;
    var $rrow;
    var $reviewNumber;
    var $commentId;
    var $statistics;
    var $width;
    var $expansionType;

    function Mailer($row, $contact, $otherContact = null, $rest = array()) {
	$this->row = $row;
	$this->contact = $contact;
	$this->otherContact = $otherContact;
	$this->ifstack = array();
	$this->text = "";
	$this->textstart = 0;
	$this->hideSensitive = defval($rest, "hideSensitive", false);
	$this->reason = defval($rest, "reason", "");
	$this->rrow = defval($rest, "rrow", null);
	$this->reviewNumber = defval($rest, "reviewNumber", "");
	$this->commentId = defval($rest, "commentId", null);
	$this->hideReviews = defval($rest, "hideReviews", false);
	$this->statistics = null;
	$this->width = 75;
	$this->expansionType = null;
    }

    function _pushIf($yes) {
	if ($yes !== false && $yes !== true && $yes !== null)
	    $yes = (bool) $yes;
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

    function _expandContact($contact, $out) {
	$firstName = $contact;
	$lastName = $email = null;
	_cleanContactText($firstName, $lastName, $email);
	if (is_object($contact) && defval($contact, "preferredEmail", "") != "")
	    $email = $contact->preferredEmail;

	if ($out[0] == "n")
	    $t = trim("$firstName $lastName");
	else if ($out[0] == "f")
	    $t = $firstName;
	else if ($out[0] == "l")
	    $t = $lastName;
	else
	    $t = "";
	if ($t == "" && $out == "n" && $email
	    && $this->expansionType != self::EXPAND_EMAIL)
	    $t = $email;
	if ($t != "" && $this->expansionType == self::EXPAND_EMAIL
	    && preg_match('#[\000-\037()[\]<>@,;:\\".]#', $t))
	    $t = "\"" . addcslashes($t, '"\\') . "\"";

	if ($email == "" && $this->expansionType == self::EXPAND_EMAIL)
	    $email = "<none>";
	if ($out == "e")
	    $t = $email;
	else if ($out == "ne" && $this->expansionType == self::EXPAND_EMAIL) {
	    if ($t == "")
		$t = $email;
	    else if ($email[0] == "<")
		$t .= " $email";
	    else
		$t .= " <$email>";
	} else if ($out == "ne" && $email != "")
	    $t = ($t == "" ? $email : "$t <$email>");

	return $t;
    }

    function expandvar($what, $isbool = false) {
	global $Conf, $ConfSiteSuffix, $Opt;
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
	    $row_reviewType = isset($this->row->reviewType) ? $this->row->reviewType : null;
	    if ($row_reviewType <= 0 && $Conf->setting("pcrev_soft") != $Conf->setting("extrev_soft")) {
		if ($isbool && ($Conf->setting("pcrev_soft") > 0) == ($Conf->setting("extrev_soft") > 0))
		    return $Conf->setting("pcrev_soft") > 0;
		else
		    return ($isbool ? null : $what);
	    }
	    $what = "%DEADLINE(" . ($row_reviewType >= REVIEW_PC ? "pcrev_soft" : "extrev_soft") . ")%";
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
	    $t = $Opt["longName"];
	    if ($Opt["shortName"] && $Opt["shortName"] != $Opt["longName"])
		$t .= " (" . $Opt["shortName"] . ")";
	    return $t;
	}
	if ($what == "%CONFSHORTNAME%")
	    return $Opt["shortName"];
	if ($what == "%CONFLONGNAME%")
	    return $Opt["longName"];
	if ($what == "%ADMIN%")
	    return $this->_expandContact((object) array("fullName" => $Opt["contactName"], "email" => $Opt["contactEmail"]), "ne");
	if ($what == "%URL%")
	    return $Opt["paperSite"];
	if ($what == "%PHP%")
	    return $ConfSiteSuffix;
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

	if ($what == "%OTHERCONTACT%" && $this->otherContact)
	    return $this->_expandContact($this->otherContact, "ne");
	if ($what == "%OTHERNAME%" && $this->otherContact)
	    return $this->_expandContact($this->otherContact, "n");
	if ($what == "%OTHEREMAIL%" && $this->otherContact)
	    return $this->_expandContact($this->otherContact, "e");

	// if no contact, this is a pre-expansion
	if (!$this->contact) {
	    if ($what == "%PASSWORD%" && isset($Opt["ldapLogin"]) && $isbool)
		return false;
	    else
		return ($isbool ? null : $what);
	}

	if ($what == "%CONTACT%")
	    return $this->_expandContact($this->contact, "ne");
	if ($what == "%NAME%")
	    return $this->_expandContact($this->contact, "n");
	if ($what == "%FIRST%")
	    return $this->_expandContact($this->contact, "f");
	if ($what == "%LAST%")
	    return $this->_expandContact($this->contact, "l");
	if ($what == "%EMAIL%")
	    return $this->_expandContact($this->contact, "e");

	$password = null;
	if (!isset($Opt["ldapLogin"]))
	    $password = ($this->hideSensitive ? "HIDDEN" : $this->contact->password);
	if ($what == "%LOGINURL%")
	    return $Opt["paperSite"] . "/?email=" . urlencode($this->contact->email) . ($password ? "&password=" . urlencode($password) : "");
	if ($what == "%LOGINURLPARTS%")
	    return "email=" . urlencode($this->contact->email) . ($password ? "&password=" . urlencode($password) : "");
	if ($what == "%PASSWORD%")
	    return ($isbool || $password !== null ? $password : "");

	if ($what == "%REASON%")
	    return $this->reason;

	// rest is only there if we have a real paper
	if (!$this->row || defval($this->row, "paperId") <= 0)
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
		&& defval($this->row, "conflictType") < CONFLICT_AUTHOR
		&& defval($this->contact, "conflictType") < CONFLICT_AUTHOR)
		return ($isbool ? false : "Hidden for blind review");
	    cleanAuthor($this->row);
	    return rtrim($this->row->authorInformation);
	}

	if ($what == "%SHEPHERD%" || $what == "%SHEPHERDNAME%"
	    || $what == "%SHEPHERDEMAIL%") {
	    $pc = pcMembers();
	    if (defval($this->row, "shepherdContactId") <= 0
		|| !defval($pc, $this->row->shepherdContactId, null)) {
		if ($isbool)
		    return false;
		else if ($this->expansionType == self::EXPAND_EMAIL)
		    return "<none>";
		else
		    return "(no shepherd assigned)";
	    }
	    $shep = $pc[$this->row->shepherdContactId];
	    if ($what == "%SHEPHERD%")
		return $this->_expandContact($shep, "ne");
	    else if ($what == "%SHEPHERDNAME%")
		return $this->_expandContact($shep, "n");
	    else
		return $this->_expandContact($shep, "e");
	}

	if ($what == "%REVIEWAUTHOR%" && $this->otherContact) {
	    if (reviewBlind($this->rrow)
		&& defval($this->contact, "privChair") <= 0
		&& (!isset($this->contact->canViewReviewerIdentity)
		    || !$this->contact->canViewReviewerIdentity($this->row, $this->rrow, true))) {
		if ($isbool)
		    return false;
		else if ($this->expansionType == self::EXPAND_EMAIL)
		    return "<hidden>";
		else
		    return "Hidden for blind review";
	    }
	    return $this->_expandContact($this->otherContact, "ne");
	}

	if ($what == "%REVIEWS%")
	    return $this->getReviews($this->contact, false);
	if ($what == "%COMMENTS%")
	    return $this->getComments($this->contact);

	return ($isbool ? false : $what);
    }

    function getReviews($contact, $finalized) {
	global $Conf, $Me, $rf;
	if ($this->hideReviews)
	    return "[Reviews are hidden since you have incomplete reviews of your own.]";

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
		    $text .= $rf->prettyTextForm($row, $row, $contact, true) . "\n";
	    return $text;
	} else
	    return "";
    }

    function getComments($contact) {
	global $Conf, $rf;
	if ($this->hideReviews)
	    return "";

	$q = "select PaperComment.*,
		ContactInfo.firstName as reviewFirstName,
		ContactInfo.lastName as reviewLastName,
		ContactInfo.email as reviewEmail,
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
	$text = "";
	// save old au_seerev setting, and reset it so authors can see them.
	$old_au_seerev = $Conf->setting("au_seerev");
	$Conf->settings["au_seerev"] = AU_SEEREV_ALWAYS;
	$crows = $Conf->commentRows($q . "\n\t\torder by commentId");
	foreach ($crows as $crow)
	    if ($contact->canViewComment($this->row, $crow, $whyNot, true))
		$text .= $rf->prettyTextComment($this->row, $crow, $contact) . "\n";
	$Conf->settings["au_seerev"] = $old_au_seerev;
	return $text;
    }

    function expand($text, $field = null) {
	if (is_array($text)) {
	    $a = array();
	    foreach ($text as $k => $t)
		$a[$k] = $this->expand($t, $k);
	    return $a;
	}

	// leave early on empty string
	if ($text == "")
	    return "";

	// width, expansion type based on field
	$width = 100000;
	if ($field == "to" || $field == "cc" || $field == "bcc"
	    || $field == "replyto")
	    $this->expansionType = self::EXPAND_EMAIL;
	else if ($field != "body" && $field != "")
	    $this->expansionType = self::EXPAND_HEADER;
	else {
	    $this->expansionType = self::EXPAND_BODY;
	    $width = $this->width;
	}

	// separate text into lines
	$lines = explode("\n", cleannl($text));
	if (count($lines) && $lines[count($lines) - 1] === "")
	    array_pop($lines);

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
		    $this->text .= wordWrapIndent($this->expandvar($m[2]), $m[1], tabLength($m[1], true), $width) . "\n";
		else if ($yes === null)
		    $this->text .= $line . "\n";
	    } else if (preg_match('/^([ \t][ \t]*.*?: )(%[\w()]+%)$/', $line, $m))
		$this->text .= wordWrapIndent($this->expandvar($m[2]), $m[1], tabLength($m[1], true), $width) . "\n";
	    else if (preg_match('/^([ \t][ \t]*.*?: )(%URL%\S*)$/', $line, $m)) {
		// do not wrap lines that start with %URL%
		$this->textstart = strlen($this->text);
		while (preg_match('/^(.*?)(%[\w()]+%)(.*)$/s', $line, $m)) {
		    $this->text .= $m[1];
		    $this->text .= $this->expandvar($m[2]);
		    $line = $m[3];
		}
		$this->text .= $line . "\n";
	    } else if (strpos($line, '%') !== false) {
		$this->textstart = strlen($this->text);
		while (preg_match('/^(.*?)(%[\w()]+%)(.*)$/s', $line, $m)) {
		    $this->text .= $m[1];
		    $this->text .= $this->expandvar($m[2]);
		    $line = $m[3];
		}
		$this->text .= $line;
		$this->text = substr($this->text, 0, $this->textstart) . wordWrapIndent(substr($this->text, $this->textstart), "", 0, $width) . "\n";
	    } else
		$this->text .= wordWrapIndent($line, "", 0, $width) . "\n";
	}

	// lose newlines on header expansion
	if ($this->expansionType != self::EXPAND_BODY)
	    $this->text = rtrim(preg_replace('/[\r\n\f\x0B]+/', ' ', $this->text));

	return $this->text;
    }

    function expandTemplate($templateName, $expand = false, $default = false) {
	global $Conf, $mailTemplates;
	$m = $mailTemplates[$templateName];
	if (!$default && ($t = $Conf->settingText("mailsubj_" . $templateName)) !== false)
	    $m["subject"] = $t;
	if (!$default && ($t = $Conf->settingText("mailbody_" . $templateName)) !== false)
	    $m["body"] = $t;
	if ($expand)
	    $m = $this->expand($m);
	return $m;
    }

    function prepareToSend($template, $row, $contact, $otherContact = null,
			   &$rest = array()) {
	global $Conf, $mailHeaders, $mailTemplates;

	// look up template
	if (is_string($template) && $template[0] == "@")
	    $template = self::expandTemplate(substr($template, 1));
	// add rest fields to template for expansion
	foreach ($mailHeaders as $f => $x)
	    if (isset($rest[$f]))
		$template[$f] = $rest[$f];

	if (!isset($rest["emailTo"]) || !$rest["emailTo"])
	    $emailTo = $contact;
	else if (is_string($rest["emailTo"]))
	    $emailTo = (object) array("email" => $rest["emailTo"]);
	else
	    $emailTo = $rest["emailTo"];
	if (!$emailTo || !$emailTo->email)
	    return $Conf->errorMsg("no email in Mailer::send");

	// use preferredEmail if it's set
	if (defval($emailTo, "preferredEmail", "") != "") {
	    $xemailTo = (object) array("email" => $emailTo->preferredEmail);
	    foreach (array("firstName", "lastName", "fullName") as $k)
		if (defval($emailTo, $k, "") != "")
		    $xemailTo->$k = $emailTo->$k;
	    $emailTo = $xemailTo;
	}

	// expand the template
	$mailer = new Mailer($row, $contact, $otherContact, $rest);
	$m = $mailer->expand($template);
	$m["subject"] = substr(Mailer::mimeHeader("Subject: ", $m["subject"]), 9);
	$m["to"] = $emailTo->email;
	$m["allowEmail"] = $Conf->allowEmailTo($m["to"]);
	$hdr = Mailer::mimeEmailHeader("To: ", contactEmailTo($emailTo));
	$m["fullTo"] = substr($hdr, 4);

	// parse headers
	$headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n" . $hdr . "\r\n";
	foreach ($mailHeaders as $n => $h)
	    if (isset($m[$n]) && $m[$n] != "" && $m[$n] != "<none>") {
		$hdr = Mailer::mimeEmailHeader($h . ": ", $m[$n]);
		if ($hdr === false) {
		    if (isset($rest["error"]))
			$rest["error"] = $n;
		    else
			$Conf->errorMsg("$h &ldquo;<tt>" . htmlspecialchars($m[$n]) . "</tt>&rdquo; isn't a valid email list.");
		    return false;
		}
		$m[$n] = substr($hdr, strlen($h) + 2);
		$headers .= $hdr . "\r\n";
	    } else
		unset($m[$n]);
	$m["headers"] = $headers;

	return $m;
    }

    function sendPrepared($preparation) {
	global $Conf, $Opt;
	if ($preparation["allowEmail"]) {
	    // set sendmail parameters
	    $extra = defval($Opt, "sendmailParam", "");
	    if (isset($Opt["emailSender"])) {
		@ini_set("sendmail_from", $Opt["emailSender"]);
		if (!isset($Opt["sendmailParam"]))
		    $extra = "-f" . escapeshellarg($Opt["emailSender"]);
	    }

	    // try to extract a valid To: header
	    $to = $preparation["to"];
	    $headers = $preparation["headers"];
	    if (($topos = strpos($headers, "\r\nTo: ")) !== false
		&& ($nlpos = strpos($headers, "\r\n", $topos + 1)) !== false
		&& ($nlpos + 2 == strlen($headers) || !ctype_space($headers[$nlpos + 2]))) {
		$to = substr($headers, $topos + 6, $nlpos - $topos - 6);
		$headers = substr($headers, 0, $topos) . substr($headers, $nlpos);
	    } else if ($topos !== false)
		$to = "";

	    return mail($to, $preparation["subject"], $preparation["body"], $headers . "From: " . $Opt["emailFrom"], $extra);
	} else if (!$Opt["sendEmail"])
	    return $Conf->infoMsg("<pre>" . htmlspecialchars("To: " . $preparation["to"] . "\n" . $preparation["headers"] . "Subject: " . $preparation["subject"] . "\n\n" . $preparation["body"]) . "</pre>");
    }

    function send($template, $row, $contact, $otherContact = null, $rest = array()) {
	$preparation = self::prepareToSend($template, $row, $contact, $otherContact, $rest);
	if ($preparation)
	    self::sendPrepared($preparation);
    }

    function sendContactAuthors($template, $row, $otherContact = null, $rest = array()) {
	global $Conf, $Me, $mailTemplates;
	$qa = ($Conf->sversion >= 25 ? ", preferredEmail" : "");

	$result = $Conf->qe("select ContactInfo.contactId,
		firstName, lastName, email$qa, password, conflictType, 0 as myReviewType
		from ContactInfo join PaperConflict using (contactId)
		where paperId=$row->paperId and conflictType>=" . CONFLICT_AUTHOR . "
		group by ContactInfo.contactId", "while looking up contact authors to send email");

	// must set the current conflict type in $row for each contact
	$old_conflictType = $row->conflictType;

	$contacts = array();
	while (($contact = edb_orow($result))) {
	    $row->conflictType = $contact->conflictType;
	    Mailer::send($template, $row, Contact::makeMinicontact($contact), $otherContact, $rest);
	    $contacts[] = contactHtml($contact);
	}

	$row->conflictType = $old_conflictType;
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
	global $Conf, $Me, $Opt, $mailTemplates;
	$qa = ($Conf->sversion >= 25 ? ", preferredEmail" : "");

	$result = $Conf->qe("select ContactInfo.contactId,
		firstName, lastName, email$qa, password,
		conflictType, reviewType as myReviewType
		from ContactInfo
		join PaperReview on (PaperReview.contactId=ContactInfo.contactId and PaperReview.paperId=$row->paperId)
		left join PaperConflict on (PaperConflict.contactId=ContactInfo.contactId and PaperConflict.paperId=$row->paperId)
		group by ContactInfo.contactId", "while looking up reviewers to send email");

	if (!isset($rest["cc"]))
	    $rest["cc"] = defval($Opt, "emailCc", $Opt["contactName"] . " <" . $Opt["contactEmail"] . ">");

	// must set the current conflict type in $row for each contact
	$old_conflictType = $row->conflictType;

	$contacts = array();
	while (($contact = edb_orow($result))) {
	    $row->conflictType = $contact->conflictType;
	    Mailer::send($template, $row, Contact::makeMinicontact($contact), $otherContact, $rest);
	    $contacts[] = contactHtml($contact);
	}

	$row->conflictType = $old_conflictType;
	if ($row->conflictType < CONFLICT_AUTHOR && count($contacts) && $Me->privChair) {
	    $endmsg = (isset($rest["infoMsg"]) ? ", " . $rest["infoMsg"] : ".");
	    $Conf->infoMsg("Sent email to paper #$row->paperId's " . pluralx($contacts, "reviewer") . ", " . commajoin($contacts) . $endmsg);
	}
    }


    /// Quote potentially non-ASCII header text a la RFC2047 and/or RFC822.
    function mimeAppend(&$result, &$linelen, $str, $utf8) {
	if ($utf8) {
	    // replace all special characters used by the encoder
	    $str = str_replace(array('=',   '_',   '?',   ' '),
			       array('=3D', '=5F', '=3F', '_'), $str);
	    // define nonsafe characters
	    if ($utf8 > 1)
		$matcher = ',[^-0-9a-zA-Z!*+/=_],';
	    else
		$matcher = ',[\x80-\xFF],';
	    preg_match_all($matcher, $str, $m, PREG_OFFSET_CAPTURE);
	    $xstr = "";
	    $last = 0;
	    foreach ($m[0] as $mx) {
		$xstr .= substr($str, $last, $mx[1] - $last)
		    . "=" . strtoupper(dechex(ord($mx[0])));
		$last = $mx[1] + 1;
	    }
	    $xstr .= substr($str, $last);
        } else
	    $xstr = $str;

	// append words to the line
	while ($xstr != "") {
	    $z = strlen($xstr);
	    assert($z > 0);

	    // add a line break
	    $maxlinelen = ($utf8 ? 76 - 12 : 78);
	    if (($linelen + $z > $maxlinelen && $linelen > 30)
		|| ($utf8 && substr($result, strlen($result) - 2) == "?=")) {
		$result .= "\r\n ";
		$linelen = 1;
		while (!$utf8 && $xstr !== "" && ctype_space($xstr[0])) {
		    $xstr = substr($xstr, 1);
		    --$z;
		}
	    }

	    // if encoding, skip intact UTF-8 characters;
	    // otherwise, try to break at a space
	    if ($utf8 && $linelen + $z > $maxlinelen) {
		$z = $maxlinelen - $linelen;
		if ($xstr[$z - 1] == "=")
		    $z -= 1;
		else if ($xstr[$z - 2] == "=")
		    $z -= 2;
		while ($z > 3
		       && $xstr[$z] == "="
		       && ($chr = hexdec(substr($xstr, $z + 1, 2))) >= 128
		       && $chr < 192)
		    $z -= 3;
	    } else if ($linelen + $z > $maxlinelen) {
		$y = strrpos(substr($xstr, 0, $maxlinelen - $linelen), " ");
		if ($y > 0)
		    $z = $y;
	    }

	    // append
	    if ($utf8)
		$astr = "=?utf-8?q?" . substr($xstr, 0, $z) . "?=";
	    else
		$astr = substr($xstr, 0, $z);

	    $result .= $astr;
	    $linelen += strlen($astr);

	    $xstr = substr($xstr, $z);
	}
    }

    function mimeEmailHeader($header, $str) {
	if (preg_match('/[\r\n]/', $str))
	    $str = simplifyWhitespace($str);

	$text = $header;
	$linelen = strlen($text);

	// separate $str into emails, quote each separately
	while (preg_match("/\\A[,\\s]*((?:(?:\"(?:[^\"\\\\]|\\\\.)*\"|[^\\s\\000-\\037()[\\]<>@,;:\\\\\".]+)\\s*?)*)\\s*<\\s*(.*?)\\s*>\\s*(.*)\\z/s", $str, $m)
	       || preg_match("/\\A[,\\s]*()<?([^\\s\\000-\\037()[\\]<>,;:\\\\\"]+)\\s*(.*)\\z/s", $str, $m)) {
	    list($name, $email, $str) = array($m[1], $m[2], $m[3]);
	    if (strpos($email, "@") !== false && !validateEmail($email))
		return false;
	    if ($str != "" && $str[0] != ",")
		return false;
	    if ($email == "none" || $email == "hidden")
		continue;

	    if ($text !== $header) {
		$text .= ", ";
		$linelen += 2;
	    }

	    // unquote any existing UTF-8 encoding
	    if ($name[0] == "="
		&& strcasecmp(substr($name, 0, 10), "=?utf-8?q?") == 0)
		$name = self::mimeHeaderUnquote($name);

	    $utf8 = preg_match('/[\x80-\xFF]/', $name) ? 2 : 0;
	    if ($name[0] == "\"" && preg_match("/\\A\"([^\\\\\"]|\\\\.)*\"\\z/s", $name)) {
		if ($utf8)
		    self::mimeAppend($text, $linelen, substr($name, 1, -1), $utf8);
		else
		    self::mimeAppend($text, $linelen, $name, false);
	    } else if ($utf8)
		self::mimeAppend($text, $linelen, $name, $utf8);
	    else if (preg_match(',\A[-!#$%&\'*+/0-9=?A-Z^_`a-z{|}~ \t]*\z,', $name))
		self::mimeAppend($text, $linelen, $name, false);
	    else {
		$name = preg_replace(',(?=[^-!#$%&\'*+/0-9=?A-Z^_`a-z{|}~ \t]),', '\\', $name);
		self::mimeAppend($text, $linelen, "\"$name\"", false);
	    }

	    if ($name == "")
		self::mimeAppend($text, $linelen, $email, false);
	    else
		self::mimeAppend($text, $linelen, " <$email>", false);
	}

	if (!preg_match('/\A[\s,]*\z/', $str))
	    return false;
	return $text;
    }

    function mimeHeader($header, $str) {
	if (preg_match('/[\r\n]/', $str))
	    $str = simplifyWhitespace($str);

	$text = $header;
	$linelen = strlen($text);
	if (preg_match('/[\x80-\xFF]/', $str))
	    self::mimeAppend($text, $linelen, $str, true);
	else
	    self::mimeAppend($text, $linelen, $str, false);
	return $text;
    }

    function mimeHeaderUnquote($text) {
	if (strlen($text) > 2 && $text[0] == '=' && $text[1] == '?') {
	    $out = '';
	    while (preg_match('/\A=\?utf-8\?q\?(.*?)\?=(\r?\n )?/i', $text, $m)) {
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
