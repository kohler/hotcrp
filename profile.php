<?php
// profile.php -- HotCRP profile management page
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
$Me->goIfInvalid();
if (!$Me->validContact())
    $Me->go(false);
$newProfile = false;
$Error = array();

if (!isset($_REQUEST["u"]) && isset($_REQUEST["user"]))
    $_REQUEST["u"] = $_REQUEST["user"];
if (!isset($_REQUEST["u"]) && isset($_REQUEST["contact"]))
    $_REQUEST["u"] = $_REQUEST["contact"];
if (!isset($_REQUEST["u"]) && isset($_SERVER["PATH_INFO"])
    && preg_match(',\A/(?:new|[^\s/]+)\z,i', $_SERVER["PATH_INFO"]))
    $_REQUEST["u"] = substr($_SERVER["PATH_INFO"], 1);


if (!$Me->privChair)
    $Acct = $Me;		// always this contact
else if (isset($_REQUEST["new"]) || defval($_REQUEST, "u") == "new") {
    $Acct = new Contact();
    $Acct->invalidate();
    $newProfile = true;
} else if (isset($_REQUEST["u"])) {
    $Acct = new Contact();
    if (($id = cvtint($_REQUEST["u"])) > 0)
	$Acct->lookupById($id);
    else
	$Acct->lookupByEmail($_REQUEST["u"]);
    if (!$Acct->valid()) {
	$Conf->errorMsg("Invalid contact.");
	$Acct = $Me;
    }
} else
    $Acct = $Me;

if ($Acct)
    $Acct->lookupAddress();


function tfError(&$tf, $errorField, $text) {
    global $Conf, $Error, $UpdateError;
    if (!isset($tf["lineno"])) {
	$UpdateError = $text;
	if ($errorField)
	    $Error[$errorField] = true;
    } else {
	$lineno = $tf["lineno"];
	if ($tf["filename"])
	    $l = htmlspecialchars($tf["filename"]) . ":" . $lineno;
	else
	    $l = "line " . $lineno;
	$tf["err"][$lineno] = "<span class='lineno'>$l:</span> $text";
    }
    return false;
}

function createUser(&$tf, $newProfile, $useRequestPassword = false) {
    global $Conf, $Acct, $Me, $Opt, $OK;
    $external_login = isset($Opt["ldapLogin"]) || isset($Opt["httpAuthLogin"]);

    if (!$external_login)
	$_REQUEST["uemail"] = trim(defval($_REQUEST, "uemail", ""));
    else if ($newProfile)
	$_REQUEST["uemail"] = trim(defval($_REQUEST, "newUsername", ""));
    else
	$_REQUEST["uemail"] = $Acct->email;

    if ($external_login)
	$_REQUEST["upassword"] = $_REQUEST["upassword2"] = $Acct->password;
    else if ($newProfile)
	$_REQUEST["upassword"] = "";
    else if (defval($_REQUEST, "whichpassword") == "t"
	     && isset($_REQUEST["upasswordt"]))
	$_REQUEST["upassword"] = $_REQUEST["upassword2"] = $_REQUEST["upasswordt"];

    // check for missing fields
    $any_missing = false;
    foreach (array("firstName", "lastName", "affiliation", "uemail", "upassword") as $field)
	if (!isset($_REQUEST[$field]))
	    $Error[$field] = $any_missing = true;
    if ($any_missing)
	return tfError($tf, false, "Required form fields missing.");

    // check passwords
    if (!$newProfile && trim(defval($_REQUEST, "upassword", "")) == "")
        $_REQUEST["upassword"] = "";
    if (!$newProfile && $_REQUEST["upassword"] != "") {
	if ($_REQUEST["upassword"] != defval($_REQUEST, "upassword2", ""))
	    return tfError($tf, "password", "The two passwords you entered did not match.");
	else if (trim($_REQUEST["upassword"]) != $_REQUEST["upassword"])
	    return tfError($tf, "password", "Passwords cannot begin or end with spaces.");
    }

    // check email
    if ($newProfile || $_REQUEST["uemail"] != $Acct->email) {
	if ($Conf->getContactId($_REQUEST["uemail"])) {
	    $msg = "An account is already registered with email address &ldquo;" . htmlspecialchars($_REQUEST["uemail"]) . "&rdquo;.";
	    if (!$newProfile)
		$msg .= "You may want to <a href='" . hoturl("mergeaccounts") . "'>merge these accounts</a>.";
	    return tfError($tf, "uemail", $msg);
	} else if ($external_login) {
	    if ($_REQUEST["uemail"] == "")
		return tfError($tf, "newUsername", "Not a valid username.");
	} else if ($_REQUEST["uemail"] == "")
	    return tfError($tf, "uemail", "You must supply an email address.");
	else if (!validateEmail($_REQUEST["uemail"]))
	    return tfError($tf, "uemail", "&ldquo;" . htmlspecialchars($_REQUEST["uemail"]) . "&rdquo; is not a valid email address.");
    }
    if (isset($_REQUEST["preferredEmail"]) && !validateEmail($_REQUEST["preferredEmail"]))
	return tfError($tf, "preferredEmail", "&ldquo;" . htmlspecialchars($_REQUEST["preferredEmail"]) . "&rdquo; is not a valid email address.");

    // at this point we will create the account
    if ($newProfile) {
	$Acct = new Contact();
	if (!$Acct->initialize($_REQUEST["uemail"], true, $useRequestPassword))
	    return tfError($tf, "uemail", "Database error, please try again");
	$Acct->sendAccountInfo(true, false);
	$Conf->log("Account created by $Me->email", $Acct);
    }

    $updatepc = false;

    if ($Me->privChair) {
	// initialize roles too
	if (isset($_REQUEST["pctype"])) {
	    if ($_REQUEST["pctype"] == "chair")
		$_REQUEST["pc"] = $_REQUEST["chair"] = 1;
	    else if ($_REQUEST["pctype"] == "pc") {
		unset($_REQUEST["chair"]);
		$_REQUEST["pc"] = 1;
	    } else {
		unset($_REQUEST["chair"]);
		unset($_REQUEST["pc"]);
	    }
	} else if (isset($_REQUEST["chair"]))
	    $_REQUEST["pc"] = 1;
	$checkass = !isset($_REQUEST["ass"]) && $Me->contactId == $Acct->contactId && ($Acct->roles & Contact::ROLE_ADMIN) != 0;

	$while = "while initializing roles";
	foreach (array("pc" => array("PCMember", Contact::ROLE_PC),
                       "ass" => array("ChairAssistant", Contact::ROLE_ADMIN),
                       "chair" => array("Chair", Contact::ROLE_CHAIR))
                 as $k => $tableinfo) {
            list($table, $role) = $tableinfo;
	    if (($Acct->roles & $role) != 0 && !isset($_REQUEST[$k])) {
		$Conf->qe("delete from $table where contactId=$Acct->contactId", $while);
		$Conf->log("Removed as $table by $Me->email", $Acct);
		$Acct->roles &= ~$role;
		$updatepc = true;
	    } else if (($Acct->roles & $role) == 0 && isset($_REQUEST[$k])) {
		$Conf->qe("insert into $table (contactId) values ($Acct->contactId)", $while);
		$Conf->log("Added as $table by $Me->email", $Acct);
		$Acct->roles |= $role;
		$updatepc = true;
	    }
	}

	// ensure there's at least one system administrator
	if ($checkass) {
	    $result = $Conf->qe("select contactId from ChairAssistant", $while);
	    if (edb_nrows($result) == 0) {
		$Conf->qe("insert into ChairAssistant (contactId) values ($Acct->contactId)", $while);
		$Conf->warnMsg("Refusing to drop the only system administrator.");
		$_REQUEST["ass"] = 1;
		$Acct->roles |= Contact::ROLE_ADMIN;
	    }
	}
    }

    // ensure changes in PC member data are reflected immediately
    if (($Acct->roles & (Contact::ROLE_PC | Contact::ROLE_ADMIN | Contact::ROLE_CHAIR))
	&& !$updatepc
	&& ($Acct->firstName != $_REQUEST["firstName"]
	    || $Acct->lastName != $_REQUEST["lastName"]
	    || $Acct->email != $_REQUEST["uemail"]
	    || $Acct->affiliation != $_REQUEST["affiliation"]))
	$updatepc = true;

    $Acct->firstName = $_REQUEST["firstName"];
    $Acct->lastName = $_REQUEST["lastName"];
    $Acct->email = $_REQUEST["uemail"];
    $Acct->affiliation = $_REQUEST["affiliation"];
    if (!$newProfile && !$external_login && $_REQUEST["upassword"] != "")
	$Acct->change_password($_REQUEST["upassword"]);
    if (isset($_REQUEST["preferredEmail"]))
	$Acct->preferredEmail = $_REQUEST["preferredEmail"];
    foreach (array("voicePhoneNumber", "collaborators",
		   "addressLine1", "addressLine2", "city", "state",
		   "zipCode", "country") as $v)
	if (isset($_REQUEST[$v]))
	    $Acct->$v = $_REQUEST[$v];
    $Acct->defaultWatch = 0;
    if (isset($_REQUEST["watchcomment"]) || isset($_REQUEST["watchcommentall"])) {
	$Acct->defaultWatch |= WATCH_COMMENT;
	if (($Acct->roles & (Contact::ROLE_PC | Contact::ROLE_ADMIN | Contact::ROLE_CHAIR))
	    && isset($_REQUEST["watchcommentall"]))
	    $Acct->defaultWatch |= WATCH_ALLCOMMENTS;
    }
    if (isset($_REQUEST["watchfinalall"])
	&& ($Acct->roles & (Contact::ROLE_ADMIN | Contact::ROLE_CHAIR)))
	$Acct->defaultWatch |= (WATCHTYPE_FINAL_SUBMIT << WATCHSHIFT_ALL);
    $newTags = ($Me->privChair ? null : $Acct->contactTags);
    if (($Acct->roles & (Contact::ROLE_PC | Contact::ROLE_ADMIN | Contact::ROLE_CHAIR))
	&& $Me->privChair
	&& defval($_REQUEST, "contactTags", "") != "") {
	$tagger = new Tagger;
	$tout = "";
	$warn = "";
	foreach (preg_split('/\s+/', $_REQUEST["contactTags"]) as $t) {
	    if ($t != "" && $tagger->check($t, Tagger::NOPRIVATE | Tagger::NOVALUE))
                $tout .= " " . $t;
            else if ($t != "")
                $warn .= $tagger->error_html . "<br />\n";
        }
	if ($warn != "")
	    return tfError($tf, "contactTags", $warn);
	if ($tout != "")
	    $newTags = $tout . " ";
    }
    if ($newTags !== $Acct->contactTags) {
	$Acct->contactTags = $newTags;
	$updatepc = true;
    }

    if ($OK)
	$Acct->updateDB();

    if ($updatepc)
	$Conf->invalidateCaches(array("pc" => 1));

    // if PC member, update collaborators and areas of expertise
    if (($Acct->isPC || $newProfile) && $OK) {
	// remove all current interests
	$Conf->qe("delete from TopicInterest where contactId=$Acct->contactId", "while updating topic interests");

	foreach ($_REQUEST as $key => $value)
	    if ($OK && strlen($key) > 2 && $key[0] == 't' && $key[1] == 'i'
		&& ($id = (int) substr($key, 2)) > 0
		&& is_numeric($value)
		&& ($value = (int) $value) >= 0 && $value < 3)
		$Conf->qe("insert into TopicInterest (contactId, topicId, interest) values ($Acct->contactId, $id, $value)", "while updating topic interests");
    }

    if ($OK) {
	// Refresh the results
	$Acct->lookupByEmail($_REQUEST["uemail"]);
	$Acct->valid();
	if (!$newProfile)
	    $Conf->log("Account updated" . ($Me->contactId == $Acct->contactId ? "" : " by $Me->email"), $Acct);
	foreach (array("firstName", "lastName", "affiliation") as $k)
	    $_REQUEST[$k] = $Acct->$k;
        foreach (array("upassword", "upassword2", "upasswordt") as $k)
            unset($_REQUEST[$k]);
    }

    return $Acct;
}

function parseBulkFile($text, $filename) {
    global $Conf, $Acct;
    $text = cleannl($text);
    if (!is_valid_utf8($text))
	$text = windows_1252_to_utf8($text);
    $tf = array("err" => array(), "filename" => $filename, "lineno" => 0);
    $success = array();

    $csv = new CsvParser($text);
    do {
	$line = $csv->shift(true);
	++$tf["lineno"];
    } while ($csv->count() && $line === false);
    if ($line && array_search("email", $line) !== false)
	$csv->set_header($line);
    else {
	$csv->set_header(array("name", "email", "affiliation"));
        $csv->unshift($line);
	--$tf["lineno"];
    }

    $original_request = $_REQUEST;

    while ($csv->count()) {
	$line = $csv->shift(true);
	++$tf["lineno"];
	if ($line === false)
	    continue;
	foreach (array("firstname" => "firstName", "first" => "firstName",
		       "lastname" => "lastName", "last" => "lastName",
		       "voice" => "voicePhoneNumber", "phone" => "voicePhoneNumber",
		       "address1" => "addressLine1",
		       "address2" => "addressLine2", "postalcode" => "zipCode",
		       "zip" => "zipCode", "tags" => "contactTags") as $k => $x)
	    if (isset($line[$k]) && !isset($line[$x]))
		$line[$x] = $line[$k];
	if (isset($line["name"]) && !isset($line["firstName"]) && !isset($line["lastName"]))
	    list($line["firstName"], $line["lastName"]) = Text::split_name($line["name"]);
	foreach ($line as $k => $v)
	    if (is_string($k))
		$_REQUEST[$k] = $v;
	list($_REQUEST["firstName"], $_REQUEST["lastName"], $_REQUEST["uemail"]) =
	    array(defval($line, "firstName", ""), defval($line, "lastName", ""), defval($line, "email", ""));

	if (createUser($tf, true, true))
	    $success[] = "<a href=\"" . hoturl("profile", "u=" . urlencode($Acct->email)) . "\">"
                . Text::user_html_nolink($Acct) . "</a>";

	foreach (array("firstName", "lastName", "uemail", "affiliation", "preferredEmail",
		       "voicePhoneNumber", "collaborators",
		       "addressLine1", "addressLine2", "city", "state", "zipCode", "country",
		       "pctype", "pc", "chair", "ass",
		       "watchcomment", "watchcommentall", "watchfinalall", "contactTags") as $k)
	    if (isset($original_request[$k]))
		$_REQUEST[$k] = $original_request[$k];
	    else
		unset($_REQUEST[$k]);
    }

    if (count($tf["err"]) > 0) {
	ksort($tf["err"]);
	$errorMsg = "were errors while parsing the uploaded account file. <div class='parseerr'><p>" . join("</p>\n<p>", $tf["err"]) . "</p></div>";
    }
    if (count($success) > 0 && count($tf["err"]) > 0)
	$Conf->confirmMsg("Created " . plural($success, "account") . " " . commajoin($success) . ".<br />However, there $errorMsg");
    else if (count($success) > 0)
	$Conf->confirmMsg("Created " . plural($success, "account") . " " . commajoin($success) . ".");
    else if (count($tf["err"]) > 0)
	$Conf->errorMsg("There $errorMsg");
    else
	$Conf->warnMsg("Nothing to do.");
}

if (!check_post())
    /* do nothing */;
else if (isset($_REQUEST["register"]) && $newProfile
         && fileUploaded($_FILES["bulk"])) {
    if (($text = file_get_contents($_FILES["bulk"]["tmp_name"])) === false)
	$Conf->errorMsg("Internal error: cannot read file.");
    else
	parseBulkFile($text, $_FILES["bulk"]["name"]);
    $Acct = new Contact();
    $Acct->invalidate();
} else if (isset($_REQUEST["register"])) {
    $tf = array();
    if (createUser($tf, $newProfile)) {
	if ($newProfile) {
	    $Conf->confirmMsg("Created <a href=\"" . hoturl("profile", "u=" . urlencode($Acct->email)) . "\">an account for " . htmlspecialchars($Acct->email) . "</a>.  A password has been emailed to that address.  You may now create another account.");
	    $_REQUEST["uemail"] = $_REQUEST["newUsername"] = $_REQUEST["firstName"] = $_REQUEST["lastName"] = $_REQUEST["affiliation"] = "";
	} else
	    $Conf->confirmMsg("Account profile updated.");
	if (isset($_REQUEST["redirect"]))
	    $Me->go(hoturl("index"));
	else
	    redirectSelf();
    }
} else if (isset($_REQUEST["merge"]) && !$newProfile
	   && $Acct->contactId == $Me->contactId)
    $Me->go(hoturl("mergeaccounts"));

function databaseTracks($who) {
    global $Conf;
    $tracks = (object) array("soleAuthor" => array(),
			     "author" => array(),
			     "review" => array(),
			     "comment" => array());

    // find authored papers
    $result = $Conf->qe("select Paper.paperId, count(pc.contactId)
	from Paper
	join PaperConflict c on (c.paperId=Paper.paperId and c.contactId=$who and c.conflictType>=" . CONFLICT_AUTHOR . ")
	join PaperConflict pc on (pc.paperId=Paper.paperId and pc.conflictType>=" . CONFLICT_AUTHOR . ")
	group by Paper.paperId order by Paper.paperId");
    while (($row = edb_row($result))) {
	if ($row[1] == 1)
	    $tracks->soleAuthor[] = $row[0];
	$tracks->author[] = $row[0];
    }

    // find reviews
    $result = $Conf->qe("select paperId from PaperReview
	where PaperReview.contactId=$who
	group by paperId order by paperId");
    while (($row = edb_row($result)))
	$tracks->review[] = $row[0];

    // find comments
    $result = $Conf->qe("select paperId from PaperComment
	where PaperComment.contactId=$who
	group by paperId order by paperId");
    while (($row = edb_row($result)))
	$tracks->comment[] = $row[0];

    return $tracks;
}

function textArrayPapers($pids) {
    $ls = "&amp;list=" . join("+", $pids);
    return commajoin(preg_replace('/(\d+)/', "<a href='" . hoturl("paper", "p=\$1$ls") . "'>\$1</a>", $pids));
}

if (isset($_REQUEST["delete"]) && $OK && check_post()) {
    if (!$Me->privChair)
	$Conf->errorMsg("Only administrators can delete users.");
    else if ($Acct->contactId == $Me->contactId)
	$Conf->errorMsg("You aren’t allowed to delete yourself.");
    else {
	$tracks = databaseTracks($Acct->contactId);
	if (count($tracks->soleAuthor))
	    $Conf->errorMsg("This user can’t be deleted since they are sole contact for " . pluralx($tracks->soleAuthor, "paper") . " " . textArrayPapers($tracks->soleAuthor) . ".  You will be able to delete the user after deleting those papers or adding additional paper contacts.");
	else {
	    $while = "while deleting user";
	    foreach (array("ContactInfo", "Chair", "ChairAssistant",
			   "ContactAddress",
			   "PCMember", "PaperComment",
			   "PaperConflict", "PaperReview",
			   "PaperReviewPreference", "PaperReviewRefused",
			   "PaperWatch", "ReviewRating", "TopicInterest")
		     as $table)
		$Conf->qe("delete from $table where contactId=$Acct->contactId", $while);
	    // tags are special because of voting tags, so go through Tagger
	    $result = $Conf->qe("select paperId, tag from PaperTag where tag like '" . $Acct->contactId . "~%'", $while);
	    $pids = $tags = array();
	    while (($row = edb_row($result))) {
		$pids[$row[0]] = 1;
		$tags[substr($row[1], strlen($Acct->contactId))] = 1;
	    }
	    if (count($pids) > 0) {
                $tagger = new Tagger($Acct);
		$tagger->save(array_keys($pids), join(" ", array_keys($tags)), "d");
            }
            // clear caches
            if ($Acct->isPC || $Acct->privChair)
                $Conf->invalidateCaches(array("pc" => 1));
	    // done
	    $Conf->confirmMsg("Permanently deleted user " . htmlspecialchars($Acct->email) . ".");
	    $Conf->log("Permanently deleted user " . htmlspecialchars($Acct->email) . " ($Acct->contactId)", $Me);
	    $Me->go(hoturl("users", "t=all"));
	}
    }
}

function crpformvalue($val, $field = null) {
    global $Acct;
    if (isset($_REQUEST[$val]))
	return htmlspecialchars($_REQUEST[$val]);
    else if ($field == "password" && $Acct->password_type != 0)
        return "";
    else if ($field !== false) {
	$v = $field ? $Acct->$field : $Acct->$val;
	return htmlspecialchars($v === null ? "" : $v);
    } else
	return "";
}

function fcclass($what = false) {
    global $Error;
    return ($what && isset($Error[$what]) ? "f-c error" : "f-c");
}

function feclass($what = false) {
    global $Error;
    return ($what && isset($Error[$what]) ? "f-e error" : "f-e");
}

function echofield($type, $classname, $captiontext, $entrytext) {
    if ($type <= 1)
	echo "<div class='f-i'>";
    if ($type >= 1)
	echo "<div class='f-ix'>";
    echo "<div class='", fcclass($classname), "'>", $captiontext, "</div>",
	"<div class='", feclass($classname), "'>", $entrytext, "</div></div>\n";
    if ($type > 2)
	echo "<div class='clear'></div></div>\n";
}

function textinput($name, $value, $size, $id = false, $password = false) {
    return "<input type=\"" . ($password ? "password" : "text")
	. "\" class=\"textlite\" name=\"$name\" " . ($id ? "id=\"$id\" " : "")
	. "size=\"$size\" value=\"$value\" onchange=\"hiliter(this)\" />";
}


if (!$newProfile) {
    $_REQUEST["pc"] = ($Acct->roles & Contact::ROLE_PC) != 0;
    $_REQUEST["ass"] = ($Acct->roles & Contact::ROLE_ADMIN) != 0;
    $_REQUEST["chair"] = ($Acct->roles & Contact::ROLE_CHAIR) != 0;
}

$_REQUEST["pctype"] = defval($_REQUEST, "pctype");
if (!in_array($_REQUEST["pctype"], array("chair", "pc", "no"))) {
    if (defval($_REQUEST, "chair"))
	$_REQUEST["pctype"] = "chair";
    else
	$_REQUEST["pctype"] = defval($_REQUEST, "pc") ? "pc" : "no";
}


if ($newProfile)
    $Conf->header("Create Account", "account", actionBar("account"));
else
    $Conf->header($Me->contactId == $Acct->contactId ? "Your Profile" : "Account Profile", "account", actionBar("account", $Acct));


if (isset($UpdateError))
    $Conf->errorMsg($UpdateError);
else if (isset($Me->fresh) && $Me->fresh === "redirect") {
    $ispc = ($Acct->roles & Contact::ROLE_PC) != 0;
    unset($Me->fresh);
    $msgs = array();
    $amsg = "";
    if (!$Me->firstName && !$Me->lastName)
	$msgs[] = "enter your name";
    if (!$Me->affiliation)
	$msgs[] = "enter your affiliation";
    if ($ispc && !$Me->collaborators)
	$msgs[] = "list your recent collaborators";
    $msgs[] = "update your " . (count($msgs) ? "other " : "") . "contact information";
    if (!$Me->affiliation || ($ispc && !$Me->collaborators)) {
	$amsg .= "  We use your ";
	if (!$Me->affiliation)
	    $amsg .= "affiliation ";
	if ($ispc && !$Me->collaborators)
	    $amsg .= ($Me->affiliation ? "" : "and ") . "recent collaborators ";
	$amsg .= "to detect paper conflicts; enter “None”";
	if (!$Me->affiliation)
	    $amsg .= " or “Unaffiliated”";
	$amsg .= " if you have none.";
    }
    if ($ispc) {
	$result = $Conf->q("select count(ta.topicId), count(ti.topicId) from TopicArea ta left join TopicInterest ti on (ti.contactId=$Me->contactId and ti.topicId=ta.topicId)");
	if (($row = edb_row($result)) && $row[0] && !$row[1]) {
	    $msgs[] = "tell us your topic interests";
	    $amsg .= "  We use your topic interests to assign you papers you might like.";
	}
    }
    $Conf->infoMsg("Please take a moment to " . commajoin($msgs) . "." . $amsg);
}


$params = array();
if ($newProfile)
    $params[] = "u=new";
else if ($Me->contactId != $Acct->contactId)
    $params[] = "u=" . urlencode($Acct->email);
if (isset($_REQUEST["ls"]))
    $params[] = "ls=" . urlencode($_REQUEST["ls"]);
echo "<form id='accountform' method='post' action='",
    hoturl_post("profile", (count($params) ? join("&amp;", $params) : "")),
    "' enctype='multipart/form-data' accept-charset='UTF-8' autocomplete='off'><div class='aahc'>\n";
if (isset($_REQUEST["redirect"]))
    echo "<input type='hidden' name='redirect' value=\"", htmlspecialchars($_REQUEST["redirect"]), "\" />\n";
if ($Me->privChair)
    echo "<input type='hidden' name='whichpassword' value='' />\n";

echo "<table id='foldaccount' class='form foldc ",
    ($_REQUEST["pctype"] == "no" ? "fold1c" : "fold1o"),
    " fold2c'>
<tr>
  <td class='caption initial'>Contact information</td>
  <td class='entry'><div class='f-contain'>\n\n";

if (!isset($Opt["ldapLogin"]) && !isset($Opt["httpAuthLogin"]))
    echofield(0, "uemail", "Email", textinput("uemail", crpformvalue("uemail", "email"), 52, "account_d"));
else if (!$newProfile) {
    echofield(0, "uemail", "Username", crpformvalue("uemail", "email"));
    echofield(0, "preferredEmail", "Email", textinput("preferredEmail", crpformvalue("preferredEmail"), 52, "account_d"));
} else {
    echofield(0, "uemail", "Username", textinput("newUsername", crpformvalue("newUsername", false), 52, "account_d"));
    echofield(0, "preferredEmail", "Email", textinput("preferredEmail", crpformvalue("preferredEmail"), 52));
}

echofield(1, "firstName", "First&nbsp;name", textinput("firstName", crpformvalue("firstName"), 24));
echofield(3, "lastName", "Last&nbsp;name", textinput("lastName", crpformvalue("lastName"), 24));

if (!$newProfile && !isset($Opt["ldapLogin"]) && !isset($Opt["httpAuthLogin"])) {
    echo "<div class='f-i'><div class='f-ix'>
  <div class='", fcclass('password'), "'>Password</div>
  <div class='", feclass('password'), "'><input class='textlite fn' type='password' name='upassword' size='24' value=\"", crpformvalue('upassword', 'password'), "\" onchange='hiliter(this)' />";
    if ($Me->privChair && $Acct->password_type == 0)
	echo "<input class='textlite fx' type='text' name='upasswordt' size='24' value=\"", crpformvalue('upasswordt', 'password'), "\" onchange='hiliter(this)' />";
    echo "</div>
</div><div class='fn f-ix'>
  <div class='", fcclass('password'), "'>Repeat password</div>
  <div class='", feclass('password'), "'>", textinput("upassword2", crpformvalue("upassword2", "password"), 24, false, true), "</div>
</div>\n";
    if ($Acct->password_type == 0) {
        echo "  <div class='f-h'>The password is stored in our database in cleartext and will be mailed to you if you have forgotten it, so don’t use a login password or any other high-security password.";
        if ($Me->privChair)
            echo "  <span class='sep'></span><span class='f-cx'><a class='fn' href='javascript:void shiftPassword(0)'>Show password</a><a class='fx' href='javascript:void shiftPassword(1)'>Hide password</a></span>";
        echo "</div>\n";
    }
    echo "  <div class='clear'></div></div>\n\n";
}


echofield(0, "affiliation", "Affiliation", textinput("affiliation", crpformvalue("affiliation"), 52));


$any_address = ($Acct->addressLine1 || $Acct->addressLine2 || $Acct->city
		|| $Acct->state || $Acct->zipCode || $Acct->country);
if ($Conf->setting("acct_addr") || $Acct->amReviewer()
    || $any_address || $Acct->voicePhoneNumber) {
    echo "<div class='g'></div>\n";
    if ($Conf->setting("acct_addr") || $any_address) {
	echofield(0, false, "Address line 1", textinput("addressLine1", crpformvalue("addressLine1"), 52));
	echofield(0, false, "Address line 2", textinput("addressLine2", crpformvalue("addressLine2"), 52));
	echofield(0, false, "City", textinput("city", crpformvalue("city"), 52));
	echofield(1, false, "State/Province/Region", textinput("state", crpformvalue("state"), 24));
	echofield(3, false, "ZIP/Postal code", textinput("zipCode", crpformvalue("zipCode"), 12));
	echofield(0, false, "Country", Countries::selector("country", (isset($_REQUEST["country"]) ? $_REQUEST["country"] : $Acct->country)));
    }
    echofield(1, false, "Phone <span class='f-cx'>(optional)</span>", textinput("voicePhoneNumber", crpformvalue("voicePhoneNumber"), 24));
    echo "<div class='clear'></div></div>\n";
}

if ($newProfile) {
    echo "<div class='f-i'><table style='font-size: smaller'><tr><td>", foldbutton("account", "", 2),
	"&nbsp;</td><td><a href=\"javascript:void fold('account',null,2)\"><strong>Bulk account creation</strong></a></td></tr>",
	"<tr class='fx2'><td></td><td>",
	"<p>Upload a CSV file with one line per account. Either specify a header like “<code>name,email,affiliation,address1</code>” or give name, email address, and affiliation, in that order.  Each new account's role and PC information is set from the form below.  Example:</p>\n",
	"<pre class='entryexample'>
John Adams,john@earbox.org,UC Berkeley
\"Adams, John Quincy\",quincy@whitehouse.gov
</pre>\n",
	"<div class='g'></div>Upload: <input type='file' name='bulk' size='30' onchange='hiliter(this)' />",
	"</td></tr></table></div>\n\n";
}

echo "</div></td>\n</tr>\n\n";

echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div></td></tr>\n\n",
    "<tr><td class='caption'>Email notification</td><td class='entry'>";
if ((!$newProfile && $Acct->isPC) || $Me->privChair) {
    echo "<table><tr><td>Send mail on: &nbsp;</td>",
	"<td>", tagg_checkbox_h("watchcomment", 1, $Acct->defaultWatch & (WATCH_COMMENT | WATCH_ALLCOMMENTS)), "&nbsp;",
	tagg_label("Reviews and comments for authored or reviewed papers"), "</td></tr>",
	"<tr><td></td><td>", tagg_checkbox_h("watchcommentall", 1, $Acct->defaultWatch & WATCH_ALLCOMMENTS), "&nbsp;",
	tagg_label("Reviews and comments for <i>any</i> paper"), "</td></tr>";
    if ($Me->privChair)
	echo "<tr><td></td><td>", tagg_checkbox_h("watchfinalall", 1, $Acct->defaultWatch & (WATCHTYPE_FINAL_SUBMIT << WATCHSHIFT_ALL)), "&nbsp;",
	    tagg_label("Updates to final versions"), "</td></tr>";
    echo "</table>";
} else
    echo tagg_checkbox_h("watchcomment", WATCH_COMMENT, $Acct->defaultWatch & (WATCH_COMMENT | WATCH_ALLCOMMENTS)), "&nbsp;",
	tagg_label("Send mail on new comments for authored or reviewed papers");
echo "</td></tr>\n\n";


if ($newProfile || $Acct->contactId != $Me->contactId || $Me->privChair) {
    echo "<tr>
  <td class='caption'>Roles</td>
  <td class='entry'><table><tr><td class='nowrap'>\n";

    foreach (array("chair" => "PC chair", "pc" => "PC member",
		   "no" => "Not on the PC") as $k => $v) {
	echo tagg_radio_h("pctype", $k, $k == $_REQUEST["pctype"],
			  array("id" => "pctype_$k", "onchange" => "hiliter(this);fold('account',\$\$('pctype_no').checked,1)")),
	    "&nbsp;", tagg_label($v), "<br />\n";
    }

    echo "</td><td><span class='sep'></span></td><td class='nowrap'>";
    echo tagg_checkbox_h("ass", 1, defval($_REQUEST, "ass")),
	"&nbsp;</td><td>", tagg_label("System administrator"), "<br />",
	"<div class='hint'>System administrators have full control over all site operations.  Administrators need not be members of the PC.  There’s always at least one system administrator.</div></td></tr></table>\n";
    echo "  </td>\n</tr>\n\n";
}


if ($newProfile || $Acct->isPC || $Me->privChair) {
    echo "<tr class='fx1'><td class='caption'></td><td class='entry'><div class='g'></div><strong>Program committee information</strong></td></tr>\n";


    echo "<tr class='fx1'>
  <td class='caption'>Collaborators and other affiliations</td>
  <td class='entry'><div class='hint'>Please list potential conflicts of interest.  ", $Conf->conflictDefinitionText(), "  List one conflict per line.
    We use this information when assigning reviews.
    For example: &ldquo;<tt>Ping Yen Zhang (INRIA)</tt>&rdquo;
    or, for a whole institution, &ldquo;<tt>INRIA</tt>&rdquo;.</div>
    <textarea class='textlite' name='collaborators' rows='5' cols='50' onchange='hiliter(this)'>", crpformvalue("collaborators"), "</textarea></td>
</tr>\n\n";

    $result = $Conf->q("select TopicArea.topicId, TopicArea.topicName, TopicInterest.interest from TopicArea left join TopicInterest on TopicInterest.contactId=$Acct->contactId and TopicInterest.topicId=TopicArea.topicId order by TopicArea.topicName");
    if (edb_nrows($result) > 0) {
	echo "<tr id='topicinterest' class='fx1'>
  <td class='caption'>Topic interests</td>
  <td class='entry' id='topicinterest'><div class='hint'>
    Please indicate your interest in reviewing papers on these conference
    topics. We use this information to help match papers to reviewers.</div>
    <table class='topicinterest'>
       <tr><td></td><th>Low</th><th>Med.</th><th>High</th></tr>\n";
	for ($i = 0; $i < edb_nrows($result); $i++) {
	    $row = edb_row($result);
	    echo "      <tr><td class='ti_topic'>", htmlspecialchars($row[1]), "</td>";
	    $tiname = "ti$row[0]";
	    $interest = cvtint(defval($_REQUEST, $tiname, ""));
	    if ($interest < 0 || $interest > 2)
		$interest = isset($row[2]) ? $row[2] : 1;
	    for ($j = 0; $j < 3; $j++) {
		echo "<td class='ti_interest'>",
		    tagg_radio_h("ti$row[0]", $j, $interest == $j), "</td>";
	    }
	    echo "</td></tr>\n";
	}
	echo "    </table></td>
</tr>";
    }


    if ($Conf->sversion >= 35 && ($Me->privChair || $Acct->contactTags)) {
	echo "<tr class='fx1'><td class='caption'></td><td class='entry'><div class='gs'></div></td></tr>\n",
	    "<tr class='fx1'><td class='caption'>Tags</td><td class='entry'>";
	if ($Me->privChair) {
	    echo "<div class='", feclass("contactTags"), "'>",
		textinput("contactTags", trim(crpformvalue("contactTags")), 60),
		"</div>
  <div class='hint'>Tags represent PC subgroups and are set by administrators.  Separate tags by spaces.  Example: &ldquo;heavy&rdquo;.<br /><strong>Tip:</strong>&nbsp;Use <a href='", hoturl("settings", "group=rev&amp;tagcolor=1#tagcolor"), "'>tag colors</a> to highlight subgroups in review lists.</div></td>
</tr>\n\n";
	} else {
	    echo trim($Acct->contactTags), "
  <div class='hint'>Tags represent PC subgroups and are set by administrators.</div></td>
</tr>\n\n";
	}
    }
}


echo "<tr class='last'><td class='caption'></td>
  <td class='entry'><div class='aa'><table class='pt_buttons'>\n";
$buttons = array("<input class='bb' type='submit' value='"
		 . ($newProfile ? "Create account" : "Save changes")
		 . "' name='register' />");
if ($Me->privChair && !$newProfile && $Me->contactId != $Acct->contactId) {
    $tracks = databaseTracks($Acct->contactId);
    $buttons[] = array("<button type='button' class='b' onclick=\"popup(this, 'd', 0)\">Delete user</button>", "(admin only)");
    if (count($tracks->soleAuthor)) {
	$Conf->footerHtml("<div id='popup_d' class='popupc'>
  <p><strong>This user cannot be deleted</strong> because they are the sole
  contact for " . pluralx($tracks->soleAuthor, "paper") . " " . textArrayPapers($tracks->soleAuthor) . ".
  Delete these papers from the database or add alternate paper contacts and
  you will be able to delete this user.</p>
  <div class='popup_actions'>
    <button type='button' class='b' onclick=\"popup(null, 'd', 1)\">Close</button>
  </div></div>");
    } else {
	if (count($tracks->author) + count($tracks->review) + count($tracks->comment)) {
	    $x = $y = array();
	    if (count($tracks->author)) {
		$x[] = "contact for " . pluralx($tracks->author, "paper") . " " . textArrayPapers($tracks->author);
		$y[] = "delete " . pluralx($tracks->author, "this") . " " . pluralx($tracks->author, "authorship association");
	    }
	    if (count($tracks->review)) {
		$x[] = "reviewer for " . pluralx($tracks->review, "paper") . " " . textArrayPapers($tracks->review);
		$y[] = "<strong>permanently delete</strong> " . pluralx($tracks->review, "this") . " " . pluralx($tracks->review, "review");
	    }
	    if (count($tracks->comment)) {
		$x[] = "commenter for " . pluralx($tracks->comment, "paper") . " " . textArrayPapers($tracks->comment);
		$y[] = "<strong>permanently delete</strong> " . pluralx($tracks->comment, "this") . " " . pluralx($tracks->comment, "comment");
	    }
	    $dialog = "<p>This user is " . commajoin($x) . ".
  Deleting the user will also " . commajoin($y) . ".</p>";
	} else
	    $dialog = "";
	$Conf->footerHtml("<div id='popup_d' class='popupc'>
  <p>Be careful: This will permanently delete all information about this
  user from the database and <strong>cannot be undone</strong>.</p>
  $dialog
  <form method='post' action=\"" . hoturl_post("profile", "u=" . urlencode($Acct->email)) . "\" enctype='multipart/form-data' accept-charset='UTF-8'>
    <div class='popup_actions'>
      <button type='button' class='b' onclick=\"popup(null, 'd', 1)\">Cancel</button>
      &nbsp;<input class='bb' type='submit' name='delete' value='Delete user' />
    </div>
  </form></div>");
    }
}
if (!$newProfile && $Acct->contactId == $Me->contactId)
    $buttons[] = "<input class='b' type='submit' value='Merge with another account' name='merge' style='margin-left:2ex' />";
echo "    <tr>\n";
foreach ($buttons as $b) {
    $x = (is_array($b) ? $b[0] : $b);
    echo "      <td class='ptb_button'>", $x, "</td>\n";
}
echo "    </tr>\n    <tr>\n";
foreach ($buttons as $b) {
    $x = (is_array($b) ? $b[1] : "");
    echo "      <td class='ptb_explain'>", $x, "</td>\n";
}
echo "    </tr>\n    </table></div></td>\n</tr>
</table></div></form>\n";

$Conf->footerScript("crpfocus(\"account\")");
$Conf->footer();
