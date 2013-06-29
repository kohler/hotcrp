<?php
// settings.php -- HotCRP chair-only conference settings management page
// HotCRP is Copyright (c) 2006-2012 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/paperoption.inc");
require_once("Code/cleanxhtml.inc");
$Me->goIfInvalid();
$Me->goIfNotPrivChair();
$Highlight = defval($_SESSION, "settings_highlight", array());
unset($_SESSION["settings_highlight"]);
$Error = array();
$Values = array();
$rf = reviewForm();
$DateExplanation = "Date examples: &ldquo;now&rdquo;, &ldquo;10 Dec 2006 11:59:59pm PST&rdquo; <a href='http://www.gnu.org/software/tar/manual/html_section/Date-input-formats.html'>(more examples)</a>";
$TagStyles = "red|orange|yellow|green|blue|purple|grey|bold|italic|big|small";
$temp_text_id = 0;

$SettingGroups = array("acc" => array(
			     "acct_addr" => "check",
			     "next" => "msg"),
		       "msg" => array(
			     "opt.shortName" => "simplestring",
			     "opt.longName" => "simplestring",
			     "homemsg" => "htmlstring",
			     "conflictdefmsg" => "htmlstring",
			     "next" => "sub"),
		       "sub" => array(
			     "sub_open" => "cdate",
			     "sub_blind" => 3,
			     "sub_reg" => "date",
			     "sub_sub" => "date",
			     "sub_grace" => "grace",
			     "sub_pcconf" => "check",
			     "sub_pcconfsel" => "check",
			     "sub_collab" => "check",
			     "banal" => "special",
			     "sub_freeze" => 1,
			     "pc_seeall" => "check",
			     "next" => "opt"),
		       "opt" => array(
			     "topics" => "special",
			     "options" => "special",
			     "next" => "rev"),
		       "rev" => array(
			     "rev_open" => "cdate",
			     "cmt_always" => "check",
			     "rev_blind" => 2,
			     "rev_notifychair" => "check",
			     "pcrev_any" => "check",
			     "pcrev_soft" => "date",
			     "pcrev_hard" => "date",
			     "x_rev_roundtag" => "special",
			     "pc_seeallrev" => 4,
			     "pc_seeblindrev" => 1,
			     "pcrev_editdelegate" => "check",
			     "extrev_chairreq" => "check",
			     "x_tag_chair" => "special",
			     "x_tag_vote" => "special",
			     "x_tag_rank" => "special",
			     "x_tag_color" => "special",
			     "tag_seeall" => "check",
			     "extrev_soft" => "date",
			     "extrev_hard" => "date",
			     "extrev_view" => 2,
			     "mailbody_requestreview" => "string",
			     "rev_ratings" => 2,
			     "next" => "rfo"),
		       "rfo" => array(
			     "reviewform" => "special",
			     "next" => "dec"),
		       "dec" => array(
			     "au_seerev" => 2,
			     "seedec" => 3,
			     "resp_open" => "check",
			     "resp_done" => "date",
			     "resp_grace" => "grace",
                             "resp_words" => "int",
			     "decisions" => "special",
			     "final_open" => "check",
			     "final_soft" => "date",
			     "final_done" => "date",
			     "final_grace" => "grace"));

$Group = defval($_REQUEST, "group");
if (!isset($SettingGroups[$Group])) {
    if ($Conf->timeAuthorViewReviews())
	$Group = "dec";
    else if ($Conf->deadlinesAfter("sub_sub") || $Conf->timeReviewOpen())
	$Group = "rev";
    else
	$Group = "sub";
}
if ($Group == "rfo")
    require_once("Code/reviewsetform.inc");
if ($Group == "acc")
    require_once("Code/contactlist.inc");


$SettingText = array(
	"sub_open" => "Submissions open setting",
	"sub_reg" => "Paper registration deadline",
	"sub_sub" => "Paper submission deadline",
	"rev_open" => "Reviews open setting",
	"cmt_always" => "Comments open setting",
	"pcrev_soft" => "PC soft review deadline",
	"pcrev_hard" => "PC hard review deadline",
	"extrev_soft" => "External reviewer soft review deadline",
	"extrev_hard" => "External reviewer hard review deadline",
	"sub_grace" => "Submissions grace period",
	"sub_blind" => "Blind submission setting",
	"rev_blind" => "Blind review setting",
	"sub_pcconf" => "Collect PC conflicts setting",
	"sub_pcconfsel" => "Collect conflict types setting",
	"sub_collab" => "Collect collaborators setting",
	"acct_addr" => "Collect addresses setting",
	"sub_freeze" => "Submitters can update until the deadline setting",
	"rev_notifychair" => "Notify chairs about reviews setting",
	"pc_seeall" => "PC can see all papers setting",
	"pcrev_any" => "PC can review any paper setting",
	"extrev_chairreq" => "PC chair must approve proposed external reviewers setting",
	"pcrev_editdelegate" => "PC members can edit delegated reviews setting",
	"pc_seeallrev" => "PC can see all reviews setting",
	"pc_seeblindrev" => "PC can see blind reviewer identities setting",
	"extrev_view" => "External reviewers can view reviews setting",
	"tag_chair" => "Chair tags",
	"tag_vote" => "Voting tags",
	"tag_rank" => "Rank tag",
	"tag_color" => "Tag colors",
	"tag_seeall" => "PC can see tags for conflicted papers",
	"rev_ratings" => "Review ratings setting",
	"au_seerev" => "Authors can see reviews setting",
	"seedec" => "Decision visibility",
	"final_open" => "Collect final versions setting",
	"final_soft" => "Final version upload deadline",
	"final_done" => "Final version upload hard deadline",
	"homemsg" => "Home page message",
	"conflictdefmsg" => "Definition of conflict of interest",
	"mailbody_requestreview" => "Mail template for external review requests"
	);

function parseGrace($v) {
    $t = 0;
    $v = trim($v);
    if ($v == "" || strtoupper($v) == "N/A" || strtoupper($v) == "NONE" || $v == "0")
	return -1;
    if (ctype_digit($v))
	return $v * 60;
    if (preg_match('/^\s*([\d]+):([\d.]+)\s*$/', $v, $m))
	return $m[1] * 60 + $m[2];
    if (preg_match('/^\s*([\d.]+)\s*d(ays?)?(?![a-z])/i', $v, $m)) {
	$t += $m[1] * 3600 * 24;
	$v = substr($v, strlen($m[0]));
    }
    if (preg_match('/^\s*([\d.]+)\s*h(rs?|ours?)?(?![a-z])/i', $v, $m)) {
	$t += $m[1] * 3600;
	$v = substr($v, strlen($m[0]));
    }
    if (preg_match('/^\s*([\d.]+)\s*m(in(ute)?s?)?(?![a-z])/i', $v, $m)) {
	$t += $m[1] * 60;
	$v = substr($v, strlen($m[0]));
    }
    if (preg_match('/^\s*([\d.]+)\s*s(ec(ond)?s?)?(?![a-z])/i', $v, $m)) {
	$t += $m[1];
	$v = substr($v, strlen($m[0]));
    }
    if (trim($v) == "")
	return $t;
    else
	return null;
}

function unparseGrace($v) {
    if ($v === null || $v <= 0 || !is_numeric($v))
	return "none";
    if ($v % 3600 == 0)
	return ($v / 3600) . " hr";
    if ($v % 60 == 0)
	return ($v / 60) . " min";
    return sprintf("%d:%02d", intval($v / 60), $v % 60);
}

function expandMailTemplate($name, $default) {
    global $nullMailer;
    if (!isset($nullMailer)) {
	$nullMailer = new Mailer(null, null);
	$nullMailer->width = 10000000;
    }
    return $nullMailer->expandTemplate($name, $default);
}

function parseValue($name, $type) {
    global $SettingText, $Error, $Highlight;

    // PHP changes incoming variable names, substituting "." with "_".
    if (!isset($_REQUEST[$name]) && substr($name, 0, 4) === "opt."
	&& isset($_REQUEST["opt_" . substr($name, 4)]))
	$_REQUEST[$name] = $_REQUEST["opt_" . substr($name, 4)];

    if (!isset($_REQUEST[$name]))
	return null;
    $v = trim($_REQUEST[$name]);

    if ($type == "check")
	return $v != "";
    if ($type == "cdate" && $v == "1")
	return 1;
    if ($type == "date" || $type == "cdate") {
	if ($v == "" || strtoupper($v) == "N/A" || $v == "0")
	    return -1;
	else if (($v = strtotime($v)) !== false)
	    return $v;
	else
	    $err = $SettingText[$name] . ": invalid date.";
    } else if ($type == "grace") {
	if (($v = parseGrace($v)) !== null)
	    return intval($v);
	else
	    $err = $SettingText[$name] . ": invalid grace period.";
    } else if ($type == "int") {
        if (preg_match("/\\A[-+]?[0-9]+\\z/", $v))
            return intval($v);
	else
	    $err = $SettingText[$name] . ": should be a number.";
    } else if ($type == "string") {
	// Avoid storing the default message in the database
	if (substr($name, 0, 9) == "mailbody_") {
	    $t = expandMailTemplate(substr($name, 9), true);
	    $v = cleannl($v);
	    if ($t["body"] == $v)
		return 0;
	}
	return ($v == "" ? 0 : array(0, $v));
    } else if ($type == "simplestring") {
	$v = simplifyWhitespace($v);
	return ($v == "" ? 0 : array(0, $v));
    } else if ($type == "htmlstring") {
	if (($v = cleanXHTML($v, $err)) === false)
	    $err = $SettingText[$name] . ": $err";
	else
	    return ($v == "" ? 0 : array(0, $v));
    } else if (is_int($type)) {
	if (ctype_digit($v) && $v >= 0 && $v <= $type)
	    return intval($v);
	else
	    $err = $SettingText[$name] . ": parse error on &ldquo;" . htmlspecialchars($v) . "&rdquo;.";
    } else
	return $v;

    $Highlight[$name] = true;
    $Error[] = $err;
    return null;
}

function doTags($set, $what) {
    global $Conf, $Values, $Error, $Highlight, $TagStyles;
    $tagger = new Tagger;

    if (!$set && $what == "tag_chair" && isset($_REQUEST["tag_chair"])) {
	$vs = array();
	foreach (preg_split('/\s+/', $_REQUEST["tag_chair"]) as $t)
	    if ($t !== "" && $tagger->check($t, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE))
		$vs[] = $t;
	    else if ($t !== "") {
		$Error[] = "Chair-only tag &ldquo;" . htmlspecialchars($t) . "&rdquo; contains odd characters.";
		$Highlight["tag_chair"] = true;
	    }
	$v = array(count($vs), join(" ", $vs));
	if (!isset($Highlight["tag_chair"])
	    && ($Conf->setting("tag_chair") !== $v[0]
		|| $Conf->settingText("tag_chair") !== $v[1]))
	    $Values["tag_chair"] = $v;
    }

    if (!$set && $what == "tag_vote" && isset($_REQUEST["tag_vote"])) {
	$vs = array();
	foreach (preg_split('/\s+/', $_REQUEST["tag_vote"]) as $t)
	    if ($t !== "" && $tagger->check($t, Tagger::NOPRIVATE | Tagger::NOCHAIR)) {
		if (preg_match('/\A([^#]+)(|#|#0+|#-\d*)\z/', $t, $m))
		    $t = $m[1] . "#1";
		$vs[] = $t;
	    } else if ($t !== "") {
		$Error[] = "Voting tag &ldquo;" . htmlspecialchars($t) . "&rdquo; contains odd characters.";
		$Highlight["tag_vote"] = true;
	    }
	$v = array(count($vs), join(" ", $vs));
	if (!isset($Highlight["tag_vote"])
	    && ($Conf->setting("tag_vote") != $v[0]
		|| $Conf->settingText("tag_vote") !== $v[1])) {
	    $Values["tag_vote"] = $v;
	    $Values["x_tag_vote"] = 1; /* want to get called at set time */
	}
    }

    if ($set && $what == "tag_vote" && isset($Values["tag_vote"])) {
	// check allotments
	$pcm = pcMembers();
	foreach (preg_split('/\s+/', $Values["tag_vote"][1]) as $t) {
	    if ($t === "")
		continue;
	    $base = substr($t, 0, strpos($t, "#"));
	    $allotment = substr($t, strlen($base) + 1);

	    $result = $Conf->q("select paperId, tag, tagIndex from PaperTag where tag like '%~" . sqlq_for_like($base) . "'");
	    $pvals = array();
	    $cvals = array();
	    $negative = false;
	    while (($row = edb_row($result))) {
		$who = substr($row[1], 0, strpos($row[1], "~"));
		if ($row[2] < 0) {
		    $Error[] = "Removed " . Text::user_html($pcm[$who]) . "'s negative &ldquo;$base&rdquo; vote for paper #$row[0].";
		    $negative = true;
		} else {
		    $pvals[$row[0]] = defval($pvals, $row[0], 0) + $row[2];
		    $cvals[$who] = defval($cvals, $who, 0) + $row[2];
		}
	    }

	    foreach ($cvals as $who => $what)
		if ($what > $allotment) {
		    $Error[] = Text::user_html($pcm[$who]) . " already has more than $allotment votes for tag &ldquo;$base&rdquo;.";
		    $Highlight["tag_vote"] = true;
		}

	    $q = ($negative ? " or (tag like '%~" . sqlq_for_like($base) . "' and tagIndex<0)" : "");
	    $Conf->qe("delete from PaperTag where tag='" . sqlq($base) . "'$q", "while counting votes");

	    $q = array();
	    foreach ($pvals as $pid => $what)
		$q[] = "($pid, '" . sqlq($base) . "', $what)";
	    if (count($q) > 0)
		$Conf->qe("insert into PaperTag values " . join(", ", $q), "while counting votes");
	}
    }

    if (!$set && $what == "tag_rank" && isset($_REQUEST["tag_rank"])) {
	$vs = array();
	foreach (preg_split('/\s+/', $_REQUEST["tag_rank"]) as $t)
	    if ($t !== "" && $tagger->check($t, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE))
		$vs[] = $t;
	    else if ($t !== "") {
		$Error[] = "Rank tag &ldquo;" . htmlspecialchars($t) . "&rdquo; contains odd characters.";
		$Highlight["tag_rank"] = true;
	    }
	if (count($vs) > 1) {
	    $Error[] = "At most one rank tag is currently supported.";
	    $Highlight["tag_rank"] = true;
	}
	$v = array(count($vs), join(" ", $vs));
	if (!isset($Highlight["tag_rank"])
	    && ($Conf->setting("tag_rank") !== $v[0]
		|| $Conf->settingText("tag_rank") !== $v[1]))
	    $Values["tag_rank"] = $v;
    }

    if (!$set && $what == "tag_color") {
	$vs = array();
	$any_set = false;
	foreach (explode("|", $TagStyles) as $k)
	    if (isset($_REQUEST["tag_color_" . $k])) {
		$any_set = true;
		foreach (preg_split('/,*\s+/', $_REQUEST["tag_color_" . $k]) as $t)
		    if ($t !== "" && $tagger->check($t, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE))
			$vs[] = $t . "=" . $k;
		    else if ($t !== "") {
			$Error[] = ucfirst($k) . " color tag &ldquo;" . htmlspecialchars($t) . "&rdquo; contains odd characters.";
			$Highlight["tag_color_" . $k] = true;
		    }
	    }
	$v = array(1, join(" ", $vs));
	if ($any_set && $Conf->settingText("tag_color") !== $v[1])
	    $Values["tag_color"] = $v;
    }

    if ($set)
        Tagger::invalidate_defined_tags();
}

function doTopics($set) {
    global $Conf, $Values, $rf;
    if (!$set) {
	$Values["topics"] = true;
	return;
    }
    $while = "while updating topics";

    $numnew = defval($_REQUEST, "newtopcount", 50);
    foreach ($_REQUEST as $k => $v) {
	if (!(strlen($k) > 3 && $k[0] == "t" && $k[1] == "o" && $k[2] == "p"))
	    continue;
	$v = simplifyWhitespace($v);
	if ($k[3] == "n" && $v != "" && cvtint(substr($k, 4), 100) <= $numnew)
	    $Conf->qe("insert into TopicArea (topicName) values ('" . sqlq($v) . "')", $while);
	else if (($k = cvtint(substr($k, 3), -1)) >= 0) {
	    if ($v == "") {
		$Conf->qe("delete from TopicArea where topicId=$k", $while);
		$Conf->qe("delete from PaperTopic where topicId=$k", $while);
                $Conf->qe("delete from TopicInterest where topicId=$k", $while);
	    } else if (isset($rf->topicName[$k]) && $v != $rf->topicName[$k])
		$Conf->qe("update TopicArea set topicName='" . sqlq($v) . "' where topicId=$k", $while);
	}
    }
}

function doCleanOptionValues($id) {
    global $Conf, $Error, $Highlight;

    $name = simplifyWhitespace(defval($_REQUEST, "optn$id", ""));
    if (!isset($_REQUEST["optn$id"])
	|| ($id == "n" && ($name === "" || $name === "New option" || $name === "(Enter new option here)"))) {
	unset($_REQUEST["optn$id"]);
	return;
    } else if ($name === ""
	       || defval($_REQUEST, "optfp$id", "") === "delete") {
	$_REQUEST["optn$id"] = "";
	return;
    } else
	$_REQUEST["optn$id"] = $name;

    if (isset($_REQUEST["optd$id"])) {
	$t = cleanXHTML($_REQUEST["optd$id"], $err);
	if ($t === false) {
	    $Error[] = $err;
	    $Highlight["optd$id"] = true;
	} else
	    $_REQUEST["optd$id"] = $t;
    }

    $optvt = cvtint(defval($_REQUEST, "optvt$id", 0));
    if (!PaperOption::type_is_valid($optvt))
	$optvt = $_REQUEST["optvt$id"] = 0;
    if (PaperOption::type_is_selectorlike($optvt)) {
	$v = "";
	foreach (explode("\n", rtrim(cleannl($_REQUEST["optv$id"]))) as $t)
	    $v .= trim($t) . "\n";
	if ($v == "\n") {
	    $Error[] = "Enter options for the selector, one per line.";
	    $Highlight["optv$id"] = true;
	} else
	    $_REQUEST["optv$id"] = substr($v, 0, strlen($v) - 1);
    } else
	unset($_REQUEST["optv$id"]);
    if (PaperOption::type_is_final($optvt))
	$_REQUEST["optp$id"] = 1;
    if ($optvt == PaperOption::T_FINALPDF)
	$_REQUEST["optdt$id"] = 0;

    $pcview = cvtint(defval($_REQUEST, "optp$id", 0));
    $_REQUEST["optp$id"] = min(max($pcview, 0), 2);

    $displaytype = cvtint(defval($_REQUEST, "optdt$id", 0));
    $_REQUEST["optdt$id"] = min(max($displaytype, 0), 2);
}

function doCleanOptionFormPositions() {
    // valid keys for options, and whether the position is new
    $optname = array();
    $optreorder = array();
    foreach (paperOptions() as $id => $o)
	if (defval($_REQUEST, "optn$id", "") != "") {
	    $optname[$id] = defval($_REQUEST, "optn$id", $o->optionName);
	    $_REQUEST["optfp$id"] = defval($_REQUEST, "optfp$id", $o->sortOrder);
	    $optreorder[$id] = $_REQUEST["optfp$id"] != $o->sortOrder;
	}
    if (isset($_REQUEST["optnn"])) {
	$optname["n"] = $_REQUEST["optnn"];
	$_REQUEST["optfpn"] = defval($_REQUEST, "optfpn", count($optname) - 1);
	$optreorder["n"] = true;
    }

    // assign "optfp" request variables sequentially starting from 0;
    // a changed position takes priority over an unchanged position
    $pos = array();
    $set = array();
    for ($i = 0; $i < count($optname); ++$i) {
	$best = -1;
	$bestpos = 1000;

	foreach ($optname as $id => $name)
	    if (!isset($set[$id])
		&& ($best < 0
		    || $_REQUEST["optfp$id"] < $bestpos
		    || ($_REQUEST["optfp$id"] == $bestpos
			&& $optreorder[$id] && !$optreorder[$best])
		    || ($_REQUEST["optfp$id"] == $bestpos
			&& strcasecmp($name, $optname[$best]) < 0)
		    || ($_REQUEST["optfp$id"] == $bestpos
			&& strcasecmp($name, $optname[$best]) == 0
			&& strcmp($name, $optname[$best]) < 0))) {
		$best = $id;
		$bestpos = $_REQUEST["optfp$id"];
	    }

	$set[$best] = true;
	$_REQUEST["optfp$best"] = $i;
    }
}

function doOptions($set) {
    global $Conf, $Values, $Error, $Highlight;
    if (!$set) {
	$optkeys = array_keys(paperOptions());
	$optkeys[] = "n";
	$optabbrs = array("paper" => -1, "final" => -1);
	foreach ($optkeys as $id) {
	    doCleanOptionValues($id);
	    if (($oabbr = defval($_REQUEST, "optn$id", ""))) {
		$oabbr = preg_replace("/-+\$/", "", preg_replace("/[^a-z0-9_]+/", "-", strtolower($oabbr)));
		if (defval($optabbrs, $oabbr, 0) == -1) {
		    $Error[] = "Option name &ldquo;" . htmlspecialchars($_REQUEST["optn$id"]) . "&rdquo; is reserved, since it abbreviates to &ldquo;$oabbr&rdquo;.  Please pick another option name.";
		    $Highlight["optn$id"] = true;
		} else if (defval($optabbrs, $oabbr)) {
		    $Error[] = "Two or more options have the same abbreviation, &ldquo;$oabbr&rdquo;.  Please pick another option name to ensure unique abbreviations.";
		    $Highlight["optn$id"] = $Highlight[$optabbrs[$oabbr]] = true;
		} else
		    $optabbrs[$oabbr] = "optn$id";
	    }
	}
	if (count($Error) == 0)
	    $Values["options"] = true;
	return;
    }
    $while = "while updating options";
    doCleanOptionFormPositions();

    $ochange = false;
    $anyo = false;
    foreach (paperOptions() as $id => $o) {
	doCleanOptionValues($id);

	if (isset($_REQUEST["optn$id"]) && $_REQUEST["optn$id"] === "") {
	    // delete option
	    $Conf->qe("delete from OptionType where optionId=$id", $while);
	    $Conf->qe("delete from PaperOption where optionId=$id", $while);
	    $ochange = true;
	    continue;
	}

	// otherwise, option exists
	$anyo = true;

	// did it change?
	if (isset($_REQUEST["optn$id"])
	    && ($_REQUEST["optn$id"] != $o->optionName
		|| defval($_REQUEST, "optd$id") != $o->description
		|| $_REQUEST["optp$id"] != $o->pcView
		|| defval($_REQUEST, "optv$id", "") != defval($o, "optionValues", "")
		|| defval($_REQUEST, "optvt$id", 0) != defval($o, "type", 0)
		|| defval($_REQUEST, "optfp$id", $o->sortOrder) != $o->sortOrder
		|| $_REQUEST["optdt$id"] != $o->displayType)) {
	    $q = "update OptionType set optionName='" . sqlq($_REQUEST["optn$id"])
		. "', description='" . sqlq(defval($_REQUEST, "optd$id", ""))
		. "', pcView=" . $_REQUEST["optp$id"]
		. ", optionValues='" . sqlq(defval($_REQUEST, "optv$id", ""))
		. "', type='" . defval($_REQUEST, "optvt$id", 0)
                . "', sortOrder=" . defval($_REQUEST, "optfp$id", $o->sortOrder);
	    if ($Conf->sversion >= 38)
		$q .= ", displayType=" . $_REQUEST["optdt$id"];
	    $Conf->qe($q . " where optionId=$id", $while);
	    $ochange = true;
	}
    }

    if (isset($_REQUEST["optnn"])) {
	doCleanOptionValues("n");
	$qa = "optionName, description, pcView, optionValues, type, sortOrder";
	$qb = "'" . sqlq($_REQUEST["optnn"])
	    . "', '" . sqlq(defval($_REQUEST, "optdn", ""))
	    . "', " . $_REQUEST["optpn"]
	    . ", '" . sqlq(defval($_REQUEST, "optvn", ""))
            . "', '" . sqlq(defval($_REQUEST, "optvtn", 0))
            . "', '" . sqlq(defval($_REQUEST, "optfpn", 0)) . "'";
	if ($Conf->sversion >= 38) {
	    $qa .= ", displayType";
	    $qb .= ", '" . $_REQUEST["optdtn"] . "'";
	}
	$Conf->qe("insert into OptionType ($qa) values ($qb)", $while);
	$ochange = $anyo = true;
    } else if (trim(defval($_REQUEST, "optdn", "")) != "") {
	$Highlight["optnn"] = true;
	$Error[] = "Specify a name for your new option.";
    }

    if (!$anyo || $ochange)
	$Conf->invalidateCaches(array("paperOption" => $anyo));
}

function doDecisions($set) {
    global $Conf, $Values, $rf, $Error, $Highlight;
    if (!$set) {
	if (defval($_REQUEST, "decn", "") != ""
	    && !defval($_REQUEST, "decn_confirm")) {
	    $delta = (defval($_REQUEST, "dtypn", 1) > 0 ? 1 : -1);
	    $match_accept = (stripos($_REQUEST["decn"], "accept") !== false);
	    $match_reject = (stripos($_REQUEST["decn"], "reject") !== false);
	    if ($delta > 0 && $match_reject) {
		$Error[] = "You are trying to add an Accept-class decision that has &ldquo;reject&rdquo; in its name, which is usually a mistake.  To add the decision anyway, check the &ldquo;Confirm&rdquo; box and try again.";
		$Highlight["decn"] = true;
		return;
	    } else if ($delta < 0 && $match_accept) {
		$Error[] = "You are trying to add a Reject-class decision that has &ldquo;accept&rdquo; in its name, which is usually a mistake.  To add the decision anyway, check the &ldquo;Confirm&rdquo; box and try again.";
		$Highlight["decn"] = true;
		return;
	    }
	}

	$Values["decisions"] = true;
	return;
    }

    // mark all used decisions
    $while = "while updating decisions";
    $dec = $rf->options["outcome"];
    $update = false;
    foreach ($_REQUEST as $k => $v)
	if (strlen($k) > 3 && $k[0] == "d" && $k[1] == "e" && $k[2] == "c"
	    && ($k = cvtint(substr($k, 3), 0)) != 0) {
	    if ($v == "") {
		$Conf->qe("delete from ReviewFormOptions where fieldName='outcome' and level=$k", $while);
		$Conf->qe("update Paper set outcome=0 where outcome=$k", $while);
	    } else if ($v != $dec[$k])
		$Conf->qe("update ReviewFormOptions set description='" . sqlq($v) . "' where fieldName='outcome' and level=$k", $while);
	}

    if (defval($_REQUEST, "decn", "") != "") {
	$delta = (defval($_REQUEST, "dtypn", 1) > 0 ? 1 : -1);
	for ($k = $delta; true; $k += $delta)
	    if (!isset($dec[$k]))
		break;

	$Conf->qe("insert into ReviewFormOptions set fieldName='outcome', level=$k, description='" . sqlq($_REQUEST["decn"]) . "'");
    }
}

function doBanal($set) {
    global $Conf, $Values, $Highlight, $Error, $ConfSitePATH;
    if ($set)
	return true;
    if (!isset($_REQUEST["sub_banal"])) {
	if (($t = $Conf->settingText("sub_banal", "")) != "")
	    $Values["sub_banal"] = array(0, $t);
	else
	    $Values["sub_banal"] = null;
	return true;
    }

    // check banal subsettings
    require_once("Code/checkformat.inc");
    $old_error_count = count($Error);
    $bs = array_fill(0, 6, "");
    if (($s = trim(defval($_REQUEST, "sub_banal_papersize", ""))) != ""
	&& strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
	$ses = preg_split('/\s*,\s*|\s+OR\s+/i', $s);
	$sout = array();
	foreach ($ses as $ss)
	    if ($ss != "" && cvtdimen($ss, 2))
		$sout[] = $ss;
	    else if ($ss != "") {
		$Highlight["sub_banal_papersize"] = true;
		$Error[] = "Invalid paper size.";
		$sout = null;
		break;
	    }
	if ($sout && count($sout))
	    $bs[0] = join(" OR ", $sout);
    }

    if (($s = trim(defval($_REQUEST, "sub_banal_pagelimit", ""))) != ""
	&& strcasecmp($s, "N/A") != 0) {
	if (($sx = cvtint($s, -1)) > 0)
	    $bs[1] = $sx;
	else if (preg_match('/\A(\d+)\s*-\s*(\d+)\z/', $s, $m)
		 && $m[1] > 0 && $m[2] > 0 && $m[1] <= $m[2])
	    $bs[1] = +$m[1] . "-" . +$m[2];
	else {
	    $Highlight["sub_banal_pagelimit"] = true;
	    $Error[] = "Page limit must be a whole number bigger than 0, or a page range such as <code>2-4</code>.";
	}
    }

    if (($s = trim(defval($_REQUEST, "sub_banal_columns", ""))) != ""
	&& strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
	if (($sx = cvtint($s, -1)) >= 0)
	    $bs[2] = ($sx > 0 ? $sx : $bs[2]);
	else {
	    $Highlight["sub_banal_columns"] = true;
	    $Error[] = "Columns must be a whole number.";
	}
    }

    if (($s = trim(defval($_REQUEST, "sub_banal_textblock", ""))) != ""
	&& strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
	// change margin specifications into text block measurements
	if (preg_match('/^(.*\S)\s+mar(gins?)?/i', $s, $m)) {
	    $s = $m[1];
	    if (!($ps = cvtdimen($bs[0]))) {
		$Highlight["sub_banal_pagesize"] = true;
		$Highlight["sub_banal_textblock"] = true;
		$Error[] = "You must specify a page size as well as margins.";
	    } else if (strpos($s, "x") !== false) {
		if (!($m = cvtdimen($s)) || !is_array($m) || count($m) > 4) {
		    $Highlight["sub_banal_textblock"] = true;
		    $Error[] = "Invalid margin definition.";
		    $s = "";
		} else if (count($m) == 2)
		    $s = array($ps[0] - 2 * $m[0], $ps[1] - 2 * $m[1]);
		else if (count($m) == 3)
		    $s = array($ps[0] - 2 * $m[0], $ps[1] - $m[1] - $m[2]);
		else
		    $s = array($ps[0] - $m[0] - $m[2], $ps[1] - $m[1] - $m[3]);
	    } else {
		$s = preg_replace('/\s+/', 'x', $s);
		if (!($m = cvtdimen($s)) || (is_array($m) && count($m) > 4)) {
		    $Highlight["sub_banal_textblock"] = true;
		    $Error[] = "Invalid margin definition.";
		} else if (!is_array($m))
		    $s = array($ps[0] - 2 * $m, $ps[1] - 2 * $m);
		else if (count($m) == 2)
		    $s = array($ps[0] - 2 * $m[1], $ps[1] - 2 * $m[0]);
		else if (count($m) == 3)
		    $s = array($ps[0] - 2 * $m[1], $ps[1] - $m[0] - $m[2]);
		else
		    $s = array($ps[0] - $m[1] - $m[3], $ps[1] - $m[0] - $m[2]);
	    }
	    $s = (is_array($s) ? unparsedimen($s) : "");
	}
	// check text block measurements
	if ($s && !cvtdimen($s, 2)) {
	    $Highlight["sub_banal_textblock"] = true;
	    $Error[] = "Invalid text block definition.";
	} else if ($s)
	    $bs[3] = $s;
    }

    if (($s = trim(defval($_REQUEST, "sub_banal_bodyfontsize", ""))) != ""
	&& strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
	if (!is_numeric($s) || $s <= 0) {
	    $Highlight["sub_banal_bodyfontsize"] = true;
	    $Error[] = "Minimum body font size must be a number bigger than 0.";
	} else
	    $bs[4] = $s;
    }

    if (($s = trim(defval($_REQUEST, "sub_banal_bodyleading", ""))) != ""
	&& strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
	if (!is_numeric($s) || $s <= 0) {
	    $Highlight["sub_banal_bodyleading"] = true;
	    $Error[] = "Minimum body leading must be a number bigger than 0.";
	} else
	    $bs[5] = $s;
    }

    while (count($bs) > 0 && $bs[count($bs) - 1] == "")
	array_pop($bs);

    // actually create setting
    if (count($Error) == $old_error_count) {
	$Values["sub_banal"] = array(1, join(";", $bs));
	$zoomarg = "";

	// Perhaps we have an old pdftohtml with a bad -zoom.
	for ($tries = 0; $tries < 2; ++$tries) {
	    $cf = new CheckFormat();
	    $s1 = $cf->analyzeFile("$ConfSitePATH/Code/sample.pdf", "letter;2;;6.5inx9in;12;14" . $zoomarg);
	    $e1 = $cf->errors;
	    if ($s1 == 1 && ($e1 & CheckFormat::ERR_PAPERSIZE) && $tries == 0)
		$zoomarg = ">-zoom=1";
	    else if ($s1 != 2 && $tries == 1)
		$zoomarg = "";
	}

	$Values["sub_banal"][1] .= $zoomarg;
	$e1 = $cf->errors;
	$s2 = $cf->analyzeFile("$ConfSitePATH/Code/sample.pdf", "a4;1;;3inx3in;14;15" . $zoomarg);
	$e2 = $cf->errors;
	$want_e2 = CheckFormat::ERR_PAPERSIZE | CheckFormat::ERR_PAGELIMIT
	    | CheckFormat::ERR_TEXTBLOCK | CheckFormat::ERR_BODYFONTSIZE
	    | CheckFormat::ERR_BODYLEADING;
	if ($s1 != 2 || $e1 != 0 || $s2 != 1 || ($e2 & $want_e2) != $want_e2)
	    $Conf->warnMsg("Running the automated paper checker on a sample PDF file produced unexpected results.  Check that your <code>pdftohtml</code> package is up to date.  You may want to disable the automated checker for now. (Internal error information: $s1 $e1 $s2 $e2)");
    }
}

function doSpecial($name, $set) {
    global $Values, $Error, $Highlight;
    if ($name == "x_tag_chair" || $name == "x_tag_vote"
	|| $name == "x_tag_rank" || $name == "x_tag_color")
	doTags($set, substr($name, 2));
    else if ($name == "topics")
	doTopics($set);
    else if ($name == "options")
	doOptions($set);
    else if ($name == "decisions")
	doDecisions($set);
    else if ($name == "reviewform") {
	if (!$set)
	    $Values[$name] = true;
	else {
	    rf_update(false);
	    $Values["revform_update"] = time();
	}
    } else if ($name == "banal")
	doBanal($set);
    else if ($name == "x_rev_roundtag") {
	if (!$set && !isset($_REQUEST["rev_roundtag"]))
	    $Values["rev_roundtag"] = null;
	else if (!$set) {
	    $t = trim($_REQUEST["rev_roundtag"]);
	    if ($t == "" || $t == "(None)")
		$Values["rev_roundtag"] = null;
	    else if (preg_match('/^[a-zA-Z0-9]+$/', $t))
		$Values["rev_roundtag"] = array(1, $t);
	    else {
		$Error[] = "The review round must contain only letters and numbers.";
		$Highlight["rev_roundtag"] = true;
	    }
	}
    }
}

function accountValue($name, $type) {
    global $Values;
    if ($type == "special")
	doSpecial($name, false);
    else if ($name != "next") {
	$v = parseValue($name, $type);
	if ($v === null) {
	    if ($type != "cdate" && $type != "check")
		return;
	    $v = 0;
	}
	if (!is_array($v) && $v <= 0 && !is_int($type))
	    $Values[$name] = null;
	else
	    $Values[$name] = $v;
    }
}

if (isset($_REQUEST["update"]) && check_post()) {
    // parse settings
    $settings = $SettingGroups[$Group];
    foreach ($settings as $name => $value)
	accountValue($name, $value);

    // check date relationships
    foreach (array("sub_reg" => "sub_sub", "pcrev_soft" => "pcrev_hard",
		   "extrev_soft" => "extrev_hard", "final_soft" => "final_done")
	     as $first => $second)
	if (!isset($Values[$first]) && isset($Values[$second]))
	    $Values[$first] = $Values[$second];
	else if (isset($Values[$first]) && isset($Values[$second])) {
	    if ($Values[$second] && !$Values[$first])
		$Values[$first] = $Values[$second];
	    else if ($Values[$second] && $Values[$first] > $Values[$second]) {
		$Error[] = $SettingText[$first] . " must come before " . $SettingText[$second] . ".";
		$Highlight[$first] = true;
		$Highlight[$second] = true;
	    }
	}
    if (array_key_exists("sub_sub", $Values))
	$Values["sub_update"] = $Values["sub_sub"];
    // need to set 'resp_open' to a timestamp,
    // so we can join on later review changes
    if (array_key_exists("resp_open", $Values)
	&& $Values["resp_open"] > 0
	&& defval($Conf->settings, "resp_open") <= 0)
	$Values["resp_open"] = time();

    // update 'papersub'
    if (isset($settings["pc_seeall"])) {
	// see also conference.inc
	$result = $Conf->q("select ifnull(min(paperId),0) from Paper where " . (defval($Values, "pc_seeall", 0) <= 0 ? "timeSubmitted>0" : "timeWithdrawn<=0"));
	if (($row = edb_row($result)) && $row[0] != $Conf->setting("papersub"))
	    $Values["papersub"] = $row[0];
    }

    // warn on other relationships
    if (array_key_exists("resp_open", $Values)
	&& $Values["resp_open"] > 0
	&& (!array_key_exists("au_seerev", $Values)
	    || $Values["au_seerev"] <= 0)
	&& (!array_key_exists("resp_done", $Values)
	    || time() < $Values["resp_done"]))
	$Conf->warnMsg("You have allowed authors to respond to the reviews, but authors can&rsquo;t see the reviews.  This seems odd.");
    if (array_key_exists("sub_freeze", $Values)
	&& $Values["sub_freeze"] == 0
	&& defval($Values, "sub_open", 0) > 0
	&& defval($Values, "sub_sub", 0) <= 0)
	$Conf->warnMsg("You have not set a paper submission deadline, but authors can update their submissions until the deadline.  This seems odd.  You probably should (1) specify a paper submission deadline; (2) select &ldquo;Authors must freeze the final version of each submission&rdquo;; or (3) manually turn off &ldquo;Open site for submissions&rdquo; when submissions complete.");
    foreach (array("pcrev_soft", "pcrev_hard", "extrev_soft", "extrev_hard")
	     as $deadline)
	if (array_key_exists($deadline, $Values)
	    && $Values[$deadline] > time()
	    && $Values[$deadline] != $Conf->setting($deadline)
	    && (array_key_exists("rev_open", $Values)
		? $Values["rev_open"] <= 0
		: $Conf->setting("rev_open") <= 0)) {
	    $Conf->warnMsg("Review deadline set.  You may also want to open the site for reviewing.");
	    $Highlight["rev_open"] = true;
	    break;
	}
    if (array_key_exists("au_seerev", $Values)
	&& $Values["au_seerev"] != AU_SEEREV_NO
	&& $Conf->setting("pcrev_soft") > 0
	&& time() < $Conf->setting("pcrev_soft")
	&& count($Error) == 0)
	$Conf->warnMsg("Authors can now see reviews and comments although it is before the review deadline.  This is sometimes unintentional.");
    if (array_key_exists("final_open", $Values)
	&& $Values["final_open"]
	&& (!array_key_exists("final_done", $Values) || !$Values["final_done"]
	    || $Values["final_done"] > time())
	&& (array_key_exists("seedec", $Values)
	    ? $Values["seedec"] != SEEDEC_ALL
	    : $Conf->setting("seedec") != SEEDEC_ALL))
	$Conf->warnMsg("The system is set to collect final versions, but authors cannot submit final versions until they know their papers have been accepted.  You should change the “Who can see paper decisions” setting to “<strong>Authors</strong>, etc.”");

    // unset text messages that equal the default
    if (array_key_exists("conflictdefmsg", $Values)
	&& $Values["conflictdefmsg"]
	&& trim($Values["conflictdefmsg"][1]) == $Conf->conflictDefinitionText(true))
	$Values["conflictdefmsg"] = null;

    // make settings
    if (count($Error) == 0 && count($Values) > 0) {
	$while = "updating settings";
	$tables = "Settings write, TopicArea write, PaperTopic write, TopicInterest write, OptionType write, PaperOption write";
	if (isset($Values['decisions']) || isset($Values['reviewform']))
	    $tables .= ", ReviewFormOptions write";
	else
	    $tables .= ", ReviewFormOptions read";
	if (isset($Values['decisions']) || isset($Values["tag_vote"]))
	    $tables .= ", Paper write";
	if (isset($Values["tag_vote"]))
	    $tables .= ", PaperTag write";
	if (isset($Values['reviewform']))
	    $tables .= ", ReviewFormField write, PaperReview write";
	else
	    $tables .= ", ReviewFormField read";
	$Conf->qe("lock tables $tables", $while);
	// alert others since we're changing settings
	$Values['revform_update'] = time();

	// apply settings
	$dq = $aq = "";
	foreach ($Values as $n => $v)
	    if (defval($settings, $n) == "special")
		doSpecial($n, true);
	    else {
		$dq .= " or name='$n'";
		if (is_array($v))
		    $aq .= ", ('$n', '" . sqlq($v[0]) . "', '" . sqlq($v[1]) . "')";
		else if ($v !== null)
		    $aq .= ", ('$n', '" . sqlq($v) . "', null)";
		if (substr($n, 0, 4) === "opt.")
		    $Opt[substr($n, 4)] = (is_array($v) ? $v[1] : $v);
	    }
	$Conf->qe("delete from Settings where " . substr($dq, 4), $while);
	if (strlen($aq))
	    $Conf->qe("insert into Settings (name, value, data) values " . substr($aq, 2), $while);

	$Conf->qe("unlock tables", $while);
	$Conf->log("Updated settings group '$Group'", $Me);
	$Conf->updateSettings();
    }

    // report errors
    if (count($Error) > 0) {
	$filter_error = array();
	foreach ($Error as $e)
	    if ($e !== true && $e !== 1)
		$filter_error[] = $e;
	if (count($filter_error))
	    $Conf->errorMsg(join("<br />\n", $filter_error));
    }

    // update the review form in case it's changed
    $rf = $rf->validate(true);
    $_SESSION["settings_highlight"] = $Highlight;
    if (count($Error) == 0)
        redirectSelf();
    unset($_SESSION["settings_highlight"]);
} else if ($Group == "rfo")
    rf_update(false);


// header and script
$Conf->header("Settings", "settings", actionBar());


function decorateSettingName($name, $text, $islabel = false) {
    global $Highlight;
    if (isset($Highlight[$name]))
	$text = "<span class='error'>$text</span>";
    if ($islabel)
	$text = tagg_label($text);
    return $text;
}

function setting($name, $defval = null) {
    global $Error, $Conf;
    if (count($Error) > 0)
	return defval($_REQUEST, $name, $defval);
    else
	return defval($Conf->settings, $name, $defval);
}

function settingText($name, $defval = null) {
    global $Error, $Conf;
    if (count($Error) > 0)
	return defval($_REQUEST, $name, $defval);
    else
	return defval($Conf->settingTexts, $name, $defval);
}

function doCheckbox($name, $text, $tr = false, $js = "hiliter(this)") {
    $x = setting($name);
    echo ($tr ? "<tr><td class='nowrap'>" : ""),
	tagg_checkbox($name, 1, $x !== null && $x > 0, array("onchange" => $js, "id" => "cb$name")),
	"&nbsp;", ($tr ? "</td><td>" : ""),
	decorateSettingName($name, $text, true),
	($tr ? "</td></tr>\n" : "<br />\n");
}

function doRadio($name, $varr) {
    $x = setting($name);
    if ($x === null || !isset($varr[$x]))
	$x = 0;
    echo "<table>\n";
    foreach ($varr as $k => $text) {
	echo "<tr><td class='nowrap'>", tagg_radio_h($name, $k, $k == $x),
	    "&nbsp;</td><td>";
	if (is_array($text))
	    echo decorateSettingName($name, $text[0], true), "<br /><small>", $text[1], "</small>";
	else
	    echo decorateSettingName($name, $text, true);
	echo "</td></tr>\n";
    }
    echo "</table>\n";
}

function doSelect($name, $nametext, $varr, $tr = false) {
    echo ($tr ? "<tr><td class='nowrap lcaption'>" : ""),
	decorateSettingName($name, $nametext),
	($tr ? "</td><td class='lentry'>" : ": &nbsp;"),
	tagg_select($name, $varr, setting($name),
		    array("onchange" => "hiliter(this)")),
	($tr ? "</td></tr>\n" : "<br />\n");
}

function doTextRow($name, $text, $v, $size = 30,
                   $capclass = "lcaption", $tempText = "") {
    global $Conf, $temp_text_id;
    $settingname = (is_array($text) ? $text[0] : $text);
    if ($tempText) {
        ++$temp_text_id;
        $Conf->footerScript("mktemptext('tptx$temp_text_id','$tempText')");
        $ttid = " id='tptx$temp_text_id'";
    } else
        $ttid = "";
    echo "<tr><td class='$capclass nowrap'>", decorateSettingName($name, $settingname), "</td><td class='lentry'><input$ttid type='text' class='textlite' name='$name' value=\"", htmlspecialchars($v), "\" size='$size' onchange='hiliter(this)' />";
    if (is_array($text) && isset($text[2]))
	echo $text[2];
    if (is_array($text) && $text[1])
	echo "<br /><span class='hint'>", $text[1], "</span>";
    echo "</td></tr>\n";
}

function doDateRow($name, $text, $othername = null, $capclass = "lcaption") {
    global $Conf, $Error, $DateExplanation;
    $x = setting($name);
    if ($x === null || (count($Error) == 0 && $x <= 0)
	|| (count($Error) == 0 && $othername && setting($othername) == $x))
	$v = "N/A";
    else if (count($Error) == 0)
	$v = $Conf->parseableTime($x, true);
    else
	$v = $x;
    if ($DateExplanation) {
	if (is_array($text))
	    $text[1] = $DateExplanation . "<br />" . $text[1];
	else
	    $text = array($text, $DateExplanation);
	$DateExplanation = null;
    }
    doTextRow($name, $text, $v, 30, $capclass, "N/A");
}

function doGraceRow($name, $text, $capclass = "lcaption") {
    global $GraceExplanation;
    if (!isset($GraceExplanation)) {
	$text = array($text, "Example: &ldquo;15 min&rdquo;");
	$GraceExplanation = true;
    }
    doTextRow($name, $text, unparseGrace(setting($name)), 15, $capclass, "none");
}

function doActionArea($top) {
    echo "<div class='aa'", ($top ? " style='margin-top:0'" : ""), ">
  <input type='submit' class='bb' name='update' value='Save changes' />
  &nbsp;<input type='submit' class='b' name='cancel' value='Cancel' />
</div>";
}



// Accounts
function doAccGroup() {
    global $Conf, $Me, $belowHr;

    doCheckbox("acct_addr", "Collect users&rsquo; addresses and phone numbers");

    echo "<hr class='hr' /><h3>Program committee &amp; system administrators</h3>";

    echo "<p><a href='", hoturl("profile", "u=new"), "' class='button'>Create account</a> &nbsp;|&nbsp; ",
	"Select a user&rsquo;s name to edit a profile or change PC/administrator status.</p>\n";
    $pl = new ContactList($Me, false);
    echo $pl->text("pcadminx", hoturl("users", "t=pcadmin"));
}

// Messages
function doMsgGroup() {
    global $Conf, $Opt;

    echo "<div class='f-c'>", decorateSettingName("opt.shortName", "Conference abbreviation"), "</div>
<input class='textlite' name='opt.shortName' size='20' onchange='hiliter(this)' value=\"", htmlspecialchars($Opt["shortName"]), "\" />
<div class='g'></div>\n";

    echo "<div class='f-c'>", decorateSettingName("opt.longName", "Full conference name"), "</div>
<input class='textlite' name='opt.longName' size='70' onchange='hiliter(this)' value=\"", htmlspecialchars($Opt["longName"]), "\" />
<div class='g'></div>\n";

    echo "<div class='f-c'>", decorateSettingName("homemsg", "Home page message"), " <span class='f-cx'>(XHTML allowed)</span></div>
<textarea class='textlite' name='homemsg' cols='60' rows='10' onchange='hiliter(this)'>", htmlspecialchars(settingText("homemsg", "")), "</textarea>
<div class='g'></div>\n";

    echo "<div class='f-c'>", decorateSettingName("conflictdefmsg", "Definition of conflict of interest"), " <span class='f-cx'>(XHTML allowed)</span></div>
<textarea class='textlite' name='conflictdefmsg' cols='60' rows='2' onchange='hiliter(this)'>", htmlspecialchars(settingText("conflictdefmsg", $Conf->conflictDefinitionText(true))), "</textarea>";
}

// Submissions
function doSubGroup() {
    global $Conf;

    doCheckbox('sub_open', '<b>Open site for submissions</b>');

    echo "<div class='g'></div>\n";
    echo "<strong>Blind submission:</strong> Are author names hidden from reviewers?<br />\n";
    doRadio("sub_blind", array(BLIND_ALWAYS => "Yes&mdash;submissions are anonymous", BLIND_NEVER => "No&mdash;author names are visible to reviewers", BLIND_UNTILREVIEW => "Blind until review&mdash;author names become visible after review submission", BLIND_OPTIONAL => "Depends&mdash;authors decide whether to expose their names"));

    echo "<div class='g'></div>\n<table>\n";
    doDateRow("sub_reg", "Paper registration deadline", "sub_sub");
    doDateRow("sub_sub", "Paper submission deadline");
    doGraceRow("sub_grace", 'Grace period');
    echo "</table>\n";

    echo "<div class='g'></div>\n<table id='foldpcconf' class='fold",
	($Conf->setting("sub_pcconf") ? "o" : "c"), "'>\n";
    doCheckbox("sub_pcconf", "Collect authors&rsquo; PC conflicts", true,
	       "hiliter(this);void fold('pcconf',!this.checked)");
    echo "<tr class='fx'><td></td><td>";
    doCheckbox("sub_pcconfsel", "Collect PC conflict types (&ldquo;Advisor/student,&rdquo; &ldquo;Recent collaborator,&rdquo; etc.)");
    echo "</td></tr>\n";
    doCheckbox("sub_collab", "Collect authors&rsquo; other collaborators as text", true);
    echo "</table>\n";

    if (is_executable("Code/banal")) {
	echo "<div class='g'></div><table id='foldbanal' class='", ($Conf->setting("sub_banal") ? "foldo" : "foldc"), "'>";
	doCheckbox("sub_banal", "<strong>Automated format checker<span class='fx'>:</span></strong>", true, "hiliter(this);void fold('banal',!this.checked)");
	echo "<tr class='fx'><td></td><td class='top'><table>";
	$bsetting = explode(";", preg_replace("/>.*/", "", $Conf->settingText("sub_banal", "")));
	for ($i = 0; $i < 6; $i++)
	    if (defval($bsetting, $i, "") == "")
		$bsetting[$i] = "N/A";
	doTextRow("sub_banal_papersize", array("Paper size", "Examples: &ldquo;letter&rdquo;, &ldquo;A4&rdquo;, &ldquo;8.5in&nbsp;x&nbsp;14in&rdquo;,<br />&ldquo;letter OR A4&rdquo;"), setting("sub_banal_papersize", $bsetting[0]), 18, "lxcaption", "N/A");
	doTextRow("sub_banal_pagelimit", "Page limit", setting("sub_banal_pagelimit", $bsetting[1]), 4, "lxcaption", "N/A");
	doTextRow("sub_banal_textblock", array("Text block", "Examples: &ldquo;6.5in&nbsp;x&nbsp;9in&rdquo;, &ldquo;1in&nbsp;margins&rdquo;"), setting("sub_banal_textblock", $bsetting[3]), 18, "lxcaption", "N/A");
	echo "</table></td><td><span class='sep'></span></td><td class='top'><table>";
	doTextRow("sub_banal_bodyfontsize", array("Minimum body font size", null, "&nbsp;pt"), setting("sub_banal_bodyfontsize", $bsetting[4]), 4, "lxcaption", "N/A");
	doTextRow("sub_banal_bodyleading", array("Minimum leading", null, "&nbsp;pt"), setting("sub_banal_bodyleading", $bsetting[5]), 4, "lxcaption", "N/A");
	doTextRow("sub_banal_columns", array("Columns", null), setting("sub_banal_columns", $bsetting[2]), 4, "lxcaption", "N/A");
	echo "</table></td></tr></table>";
    }

    echo "<hr class='hr' />\n";
    doRadio("sub_freeze", array(0 => "<strong>Authors can update submissions until the deadline</strong>", 1 => array("Authors must freeze the final version of each submission", "&ldquo;Authors can update submissions until the deadline&rdquo; is usually the best choice.  Freezing submissions can be useful when there is no submission deadline.")));

    echo "<div class='g'></div><table>\n";
    // compensate for pc_seeall magic
    if ($Conf->setting("pc_seeall") < 0)
	$Conf->settings["pc_seeall"] = 1;
    doCheckbox('pc_seeall', "PC can see <i>all registered papers</i> until submission deadline<br /><small>Check this box if you want to collect review preferences <em>before</em> most papers are submitted. After the submission deadline, PC members can only see submitted papers.</small>", true);
    echo "</table>";
}

// Submission options
function checkOptionNameUnique($oname) {
    if ($oname == "" || $oname == "none" || $oname == "any")
	return false;
    $m = 0;
    foreach (paperOptions() as $oid => $o)
	if (strstr(strtolower($o->optionName), $oname) !== false)
	    $m++;
    return $m == 1;
}

function doOptGroupOption($o) {
    global $Conf, $Error, $temp_text_id;
    $id = $o->optionId;
    if (count($Error) > 0 && isset($_REQUEST["optn$id"]))
	$o = (object) array("optionId" => $id,
		"optionName" => defval($_REQUEST, "optn$id", $o->optionName),
		"description" => defval($_REQUEST, "optd$id", $o->description),
		"type" => defval($_REQUEST, "optvt$id", $o->type),
		"optionValues" => defval($_REQUEST, "optv$id", $o->optionValues),
		"pcView" => defval($_REQUEST, "optp$id", $o->pcView),
		"sortOrder" => defval($_REQUEST, "optfp$id", $o->sortOrder));

    if ($id == "n") {
        ++$temp_text_id;
        $Conf->footerScript("mktemptext('tptx$temp_text_id','(Enter new option here)')");
        $ttid = " id='tptx$temp_text_id'";
    } else
        $ttid = "";
    echo "<tr><td><div class='f-contain'>\n",
	"  <div class='f-i'>",
	"<div class='f-c'>",
	decorateSettingName("optn$id", ($id === "n" ? "New option name" : "Option name")),
	"</div>",
	"<div class='f-e'><input$ttid type='text' class='textlite temptext",
	($o->optionName == "(Enter new option here)" ? "" : "off"),
	"' name='optn$id' value=\"", htmlspecialchars($o->optionName), "\" size='50' onchange='hiliter(this)' /></div>\n",
	"  <div class='f-i'>",
	"<div class='f-c'>",
	decorateSettingName("optd$id", "Description"),
	"</div>",
	"<div class='f-e'><textarea class='textlite' name='optd$id' rows='2' cols='50' onchange='hiliter(this)'>", htmlspecialchars($o->description), "</textarea></div>",
	"</div></td>";

    if ($id !== "n") {
	echo "<td style='padding-left: 1em'><div class='f-i'>",
	    "<div class='f-c'>Example search</div>",
	    "<div class='f-e'>";
	$oabbrev = simplifyWhitespace($o->optionName);
	foreach (preg_split('/\s+/', preg_replace('/[^a-z\s]/', '', strtolower($o->optionName))) as $oword)
	    if (checkOptionNameUnique($oword)) {
		$oabbrev = $oword;
		break;
	    }
	if ($o->optionValues !== "") {
	    $a = explode("\n", $o->optionValues);
	    if (count($a) > 1 && $a[1] !== "")
		$oabbrev .= "#" . strtolower(simplifyWhitespace($a[1]));
	}
	if (strstr($oabbrev, " ") !== false)
	    $oabbrev = "\"$oabbrev\"";
	echo "&ldquo;<a href=\"", hoturl("search", "q=opt:" . urlencode($oabbrev)), "\">",
	    "opt:", htmlspecialchars($oabbrev), "</a>&rdquo;",
	    "</div></div></td>";
    }

    echo "</tr>\n  <tr><td colspan='2'><table id='foldoptvis$id' class='fold2c fold3o'><tr>";

    echo "<td class='pad'><div class='f-i'><div class='f-c'>",
	decorateSettingName("optvt$id", "Type"), "</div><div class='f-e'>";
    $oval = $o->optionValues;
    $optvt = (count($Error) > 0 ? defval($_REQUEST, "optvt$id", 0) : $o->type);
    $finalOptions = ($Conf->collectFinalPapers() || $optvt >= PaperOption::T_FINALPDF);

    $otypes = array();
    if ($finalOptions)
	$otypes["xxx1"] = array("optgroup", "Options for submissions");
    $otypes[PaperOption::T_CHECKBOX] = "Checkbox";
    $otypes[PaperOption::T_SELECTOR] = "Selector";
    $otypes[PaperOption::T_RADIO] = "Radio buttons";
    $otypes[PaperOption::T_NUMERIC] = "Numeric";
    $otypes[PaperOption::T_TEXT] = "Text";
    $otypes[PaperOption::T_TEXT_5LINE] = "Multiline text";
    $otypes[PaperOption::T_PDF] = "PDF";
    $otypes[PaperOption::T_SLIDES] = "Slides";
    $otypes[PaperOption::T_VIDEO] = "Video";
    if ($finalOptions) {
	$otypes["xxx2"] = array("optgroup", "Options for accepted papers");
	$otypes[PaperOption::T_FINALPDF] = "Alternate final version";
	$otypes[PaperOption::T_FINALSLIDES] = "Final slides";
	$otypes[PaperOption::T_FINALVIDEO] = "Final video";
    }
    echo tagg_select("optvt$id", $otypes, $optvt, array("onchange" => "doopttype(this)", "id" => "optvt$id")),
	"</div></div></td>";
    $Conf->footerScript("doopttype(\$\$('optvt$id'),true)");

    echo "<td class='fn2 pad'><div class='f-i'><div class='f-c'>",
	decorateSettingName("optp$id", "Visibility"), "</div><div class='f-e'>",
	tagg_select("optp$id", array("Administrators only", "Visible to reviewers", "Visible if authors are visible"), $o->pcView, array("onchange" => "hiliter(this)")),
	"</div></div></td>";

    echo "<td class='pad'><div class='f-i'><div class='f-c'>",
        decorateSettingName("optfp$id", "Form order"), "</div><div class='f-e'>";
    $x = array();
    // can't use "foreach (paperOptions())" because caller uses cursor
    for ($n = 0; $n < count(paperOptions()); ++$n)
        $x[$n] = ordinal($n + 1);
    if ($id === "n")
        $x[$n] = ordinal($n + 1);
    else
        $x["delete"] = "Delete option";
    echo tagg_select("optfp$id", $x, $o->sortOrder, array("onchange" => "hiliter(this)")),
        "</div></div></td>";

    if ($Conf->sversion >= 38) {
	echo "<td class='pad fn3'><div class='f-i'><div class='f-c'>",
	    decorateSettingName("optdt$id", "Display"), "</div><div class='f-e'>";
	$optdt = (count($Error) > 0 ? defval($_REQUEST, "optdt$id", 0) : $o->displayType);
	echo tagg_select("optdt$id", array(PaperOption::DT_NORMAL => "Normal",
					   PaperOption::DT_HIGHLIGHT => "Highlighted",
					   PaperOption::DT_SUBMISSION => "Near submission"), $optdt,
			 array("onchange" => "hiliter(this)")),
	    "</div></div></td>";
    }

    if (isset($otypes[PaperOption::T_FINALPDF]))
	echo "<td class='pad fx2'><div class='f-i'><div class='f-c'>&nbsp;</div><div class='f-e hint' style='margin-top:0.7ex'>(Set by accepted authors during final version submission period)</div></div></td>";

    echo "</tr></table>";

    $value = $o->optionValues;
    $rows = 3;
    if (PaperOption::type_is_selectorlike($optvt))
	$rows = max(count(explode("\n", $value)), 3);
    else
	$value = "";
    echo "<div id='foldoptv$id' class='", (PaperOption::type_is_selectorlike($optvt) ? "foldo" : "foldc"),
	"'><div class='fx'>",
	"<div class='hint' style='margin-top:1ex'>Enter choices one per line.  The first choice will be the default.</div>",
	"<textarea class='textlite' name='optv$id' rows='", $rows, "' cols='50' onchange='hiliter(this)'>", htmlspecialchars($value), "</textarea>",
	"</div></div>";

    echo "</div></td></tr>\n";
}

function doOptGroup() {
    global $Conf, $rf;

    echo "<h3>Submission options</h3>\n";
    echo "Options are selected by authors at submission time.  Examples have included &ldquo;PC-authored paper,&rdquo; &ldquo;Consider this paper for a Best Student Paper award,&rdquo; and &ldquo;Allow the shadow PC to see this paper.&rdquo;  The &ldquo;option name&rdquo; should be brief (&ldquo;PC paper,&rdquo; &ldquo;Best Student Paper,&rdquo; &ldquo;Shadow PC&rdquo;).  The optional description can explain further and may use XHTML.  ";
    echo "Add options one at a time.\n";
    echo "<div class='g'></div>\n";
    echo "<table>";
    $sep = "";
    $nopt = 0;
    foreach (paperOptions() as $o) {
	echo $sep;
	doOptGroupOption($o);
	$sep = "<tr><td colspan='2'><hr class='hr' /></td></tr>\n";
	++$nopt;
    }

    echo $sep;

    doOptGroupOption((object) array("optionId" => "n", "optionName" => "(Enter new option here)", "description" => "", "pcView" => 1, "type" => 0, "optionValues" => "", "sortOrder" => $nopt, "displayType" => 0));

    echo "</table>\n";


    // Topics
    // load topic interests
    $result = $Conf->q("select topicId, interest, count(*) from TopicInterest group by topicId, interest");
    $interests = array();
    $ninterests = 0;
    while (($row = edb_row($result))) {
	if (!isset($interests[$row[0]]))
	    $interests[$row[0]] = array();
	$interests[$row[0]][$row[1]] = $row[2];
	$ninterests += ($row[2] ? 1 : 0);
    }

    echo "<hr class='hr' /><h3>Topics</h3>\n";
    echo "Enter topics one per line.  Authors select the topics that apply to their papers; PC members use this information to find papers they'll want to review.  To delete a topic, delete its name.\n";
    echo "<div class='g'></div><table id='newtoptable' class='", ($ninterests ? "foldo" : "foldc"), "'>";
    echo "<tr><th colspan='2'></th><th class='fx'><small>Low</small></th><th class='fx'><small>High</small></th></tr>";
    $td1 = "<td class='lcaption'>Current</td>";
    foreach ($rf->topicOrder as $tid => $crap) {
	echo "<tr>$td1<td class='lentry'><input type='text' class='textlite' name='top$tid' value=\"", htmlspecialchars($rf->topicName[$tid]), "\" size='40' onchange='hiliter(this)' /></td>";

	$tinterests = defval($interests, $tid, array());
	echo "<td class='fx rpentry'>", (defval($tinterests, 0) ? "<span class='topic0'>" . $tinterests[0] . "</span>" : ""), "</td>",
	    "<td class='fx rpentry'>", (defval($tinterests, 2) ? "<span class='topic2'>" . $tinterests[2] . "</span>" : ""), "</td>";

	if ($td1 !== "<td></td>") {
	    // example search
	    echo "<td class='llentry' style='vertical-align: top' rowspan='40'><div class='f-i'>",
		"<div class='f-c'>Example search</div>";
	    $oabbrev = strtolower($rf->topicName[$tid]);
	    if (strstr($oabbrev, " ") !== false)
		$oabbrev = "\"$oabbrev\"";
	    echo "&ldquo;<a href=\"", hoturl("search", "q=topic:" . urlencode($oabbrev)), "\">",
		"topic:", htmlspecialchars($oabbrev), "</a>&rdquo;",
		"<div class='hint'>Topic abbreviations are also allowed.</div>";
	    if ($ninterests)
		echo "<a class='hint fn' href=\"javascript:void fold('newtoptable')\">Show PC interest counts</a>",
		    "<a class='hint fx' href=\"javascript:void fold('newtoptable')\">Hide PC interest counts</a>";
	    echo "</div></td>";
	}
	echo "</tr>\n";
	$td1 = "<td></td>";
    }
    $td1 = "<td class='lcaption' rowspan='40'>New<br /><small><a href='javascript:void authorfold(\"newtop\",1,1)'>More</a> | <a href='javascript:void authorfold(\"newtop\",1,-1)'>Fewer</a></small></td>";
    for ($i = 1; $i <= 40; $i++) {
	echo "<tr id='newtop$i' class='auedito'>$td1<td class='lentry'><input type='text' class='textlite' name='topn$i' value=\"\" size='40' onchange='hiliter(this)' /></td></tr>\n";
	$td1 = "";
    }
    echo "</table>",
	"<input id='newtopcount' type='hidden' name='newtopcount' value='40' />";
    $Conf->echoScript("authorfold(\"newtop\",0,3)");
}

// Reviews
function doRevGroup() {
    global $Conf, $Error, $DateExplanation, $TagStyles;

    doCheckbox('rev_open', '<b>Open site for reviewing</b>');
    doCheckbox('cmt_always', 'Allow comments even if reviewing is closed');

    echo "<div class='g'></div>\n";
    echo "<strong>Review anonymity:</strong> Are reviewer names hidden from authors?<br />\n";
    doRadio("rev_blind", array(BLIND_ALWAYS => "Yes&mdash;reviews are anonymous", BLIND_NEVER => "No&mdash;reviewer names are visible to authors", BLIND_OPTIONAL => "Depends&mdash;reviewers decide whether to expose their names"));

    echo "<div class='g'></div>\n";
    doCheckbox('rev_notifychair', 'PC chairs are notified of new reviews by email');

    echo "<hr class='hr' />";


    // Review visibility
    echo "<h3>Review visibility</h3>\n";

    echo "Can PC members <strong>see all reviews</strong> except for conflicts?<br />\n";
    doRadio("pc_seeallrev", array(1 => "Yes",
				  3 => "Yes, unless they haven&rsquo;t completed an assigned review for the same paper",
				  4 => "Yes, after completing all their assigned reviews",
				  0 => "Only after completing a review for the same paper"));

    echo "<div class='g'></div>\n";
    echo "Can PC members see <strong>reviewer names</strong> except for conflicts?<br />\n";
    doRadio("pc_seeblindrev", array(0 => "Yes",
				    1 => "Only after completing a review for the same paper<br /><span class='hint'>This setting also hides reviewer-only comments from PC members who have not completed a review for the paper.</span>"));

    echo "<div class='g'></div>";
    echo "Can external reviewers see the other reviews for their assigned papers, once they&rsquo;ve submitted their own?<br />\n";
    doRadio("extrev_view", array(2 => "Yes", 1 => "Yes, but they can&rsquo;t see who wrote blind reviews", 0 => "No"));

    echo "<hr class='hr' />";


    // PC reviews
    echo "<h3>PC reviews</h3>\n";

    echo "<table>\n";
    $date_text = $DateExplanation;
    $DateExplanation = null;
    doDateRow("pcrev_soft", array("Deadline", "Reviews are due by the deadline."), "pcrev_hard");
    doDateRow("pcrev_hard", array("Hard deadline", "Reviews <em>cannot be entered or changed</em> after the hard deadline.  If set, this should generally be after the PC meeting.<br />$date_text"));
    if (!($rev_roundtag = settingText("rev_roundtag")))
	$rev_roundtag = "(None)";
    doTextRow("rev_roundtag", array("Review round", "This will mark new PC review assignments by default.  Examples: &ldquo;R1&rdquo;, &ldquo;R2&rdquo; &nbsp;<span class='barsep'>|</span>&nbsp; <a href='" . hoturl("help", "t=revround") . "'>What is this?</a>"), $rev_roundtag, 15, "lcaption", "(None)");
    echo "</table>\n";

    echo "<div class='g'></div>\n";
    doCheckbox('pcrev_any', 'PC members can review <strong>any</strong> submitted paper');

    echo "<hr class='hr' />";


    // External reviews
    echo "<h3>External reviews</h3>\n";

    doCheckbox('extrev_chairreq', "PC chair must approve proposed external reviewers");
    doCheckbox("pcrev_editdelegate", "PC members can edit external reviews they requested");
    echo "<div class='g'></div>";

    echo "<table>\n";
    doDateRow("extrev_soft", "Deadline", "extrev_hard");
    doDateRow("extrev_hard", "Hard deadline");
    echo "</table>\n";

    echo "<div class='g'></div>\n";
    $t = expandMailTemplate("requestreview", false);
    echo "<table id='foldmailbody_requestreview' class='foldc'>",
	"<tr><td>", foldbutton("mailbody_requestreview", ""), "&nbsp;</td>",
	"<td><a href='javascript:void fold(\"mailbody_requestreview\")' class='q'><strong>Mail template for external review requests</strong></a>",
	" <span class='fx'>(<a href='", hoturl("mail"), "'>keywords</a> allowed)<br /></span>
<textarea class='tt fx' name='mailbody_requestreview' cols='80' rows='20' onchange='hiliter(this)'>", htmlspecialchars($t["body"]), "</textarea>",
	"</td></tr></table>\n";

    echo "<hr class='hr' />";

    // Tags
    $tagger = new Tagger;
    echo "<h3>Tags</h3>\n";

    echo "<table><tr><td class='lcaption'>", decorateSettingName("tag_chair", "Chair-only tags"), "</td>";
    if (count($Error) > 0)
	$v = defval($_REQUEST, "tag_chair", "");
    else
        $v = join(" ", array_keys($tagger->chair_tags()));
    echo "<td><input type='text' class='textlite' name='tag_chair' value=\"", htmlspecialchars($v), "\" size='40' onchange='hiliter(this)' /><br /><div class='hint'>Only PC chairs can change these tags.  (PC members can still <i>view</i> the tags.)</div></td></tr>";

    echo "<tr><td class='lcaption'>", decorateSettingName("tag_vote", "Voting tags"), "</td>";
    if (count($Error) > 0)
	$v = defval($_REQUEST, "tag_vote", "");
    else {
	$x = "";
	foreach ($tagger->vote_tags() as $n => $v)
	    $x .= "$n#$v ";
	$v = trim($x);
    }
    echo "<td><input type='text' class='textlite' name='tag_vote' value=\"", htmlspecialchars($v), "\" size='40' onchange='hiliter(this)' /><br /><div class='hint'>&ldquo;vote#10&rdquo; declares a voting tag named &ldquo;vote&rdquo; with an allotment of 10 votes per PC member. &nbsp;<span class='barsep'>|</span>&nbsp; <a href='", hoturl("help", "t=votetags"), "'>What is this?</a></div></td></tr>";

    echo "<tr><td class='lcaption'>", decorateSettingName("tag_rank", "Ranking tag"), "</td>";
    if (count($Error) > 0)
	$v = defval($_REQUEST, "tag_rank", "");
    else
	$v = $Conf->settingText("tag_rank", "");
    echo "<td><input type='text' class='textlite' name='tag_rank' value=\"", htmlspecialchars($v), "\" size='40' onchange='hiliter(this)' /><br /><div class='hint'>If set, the <a href='", hoturl("offline"), "'>offline reviewing page</a> will expose support for uploading rankings by this tag. &nbsp;<span class='barsep'>|</span>&nbsp; <a href='", hoturl("help", "t=ranking"), "'>What is this?</a></div></td></tr>";
    echo "</table>";

    echo "<div class='g'></div>\n";
    doCheckbox('tag_seeall', "PC can see tags for conflicted papers");

    echo "<div class='g'></div>\n";
    echo "<table id='foldtag_color' class='",
	(defval($_REQUEST, "tagcolor") ? "foldo" : "foldc"), "'><tr>",
	"<td>", foldbutton("tag_color", ""), "&nbsp;</td>",
	"<td><a href='javascript:void fold(\"tag_color\")' name='tagcolor' class='q'><strong>Styles and colors</strong></a><br />\n",
	"<div class='hint fx'>Papers tagged with a style name, or with one of the associated tags (if any), will appear in that style in paper lists.</div>",
	"<div class='smg fx'></div>",
	"<table class='fx'><tr><th colspan='2'>Style name</th><th>Tags</th></tr>";
    $t = $Conf->settingText("tag_color", "");
    foreach (explode("|", $TagStyles) as $k) {
	if (count($Error) > 0)
	    $v = defval($_REQUEST, "tag_color_$k", "");
	else {
	    preg_match_all("/(\\S+)=$k/", $t, $m);
	    $v = join(" ", $m[1]);
	}
	echo "<tr class='k0 ${k}tag'><td class='lxcaption'></td><td class='lxcaption'>$k</td><td class='lentry' style='font-size: 10.5pt'><input type='text' class='textlite' name='tag_color_$k' value=\"", htmlspecialchars($v), "\" size='40' onchange='hiliter(this)' /></td></tr>"; /* MAINSIZE */
    }
    echo "</table></td></tr></table>\n";

    echo "<hr class='hr' />";

    // Tags
    echo "<h3>Review ratings</h3>\n";

    echo "Should HotCRP collect ratings of reviews? &nbsp; <a class='hint' href='", hoturl("help", "t=revrate"), "'>(Learn more)</a><br />\n";
    doRadio("rev_ratings", array(REV_RATINGS_PC => "Yes, PC members can rate reviews", REV_RATINGS_PC_EXTERNAL => "Yes, PC members and external reviewers can rate reviews", REV_RATINGS_NONE => "No"));
}

// Review form
function doRfoGroup() {
    require_once("Code/reviewsetform.inc");
    rf_show();
}

// Responses and decisions
function doDecGroup() {
    global $Conf, $rf, $Highlight, $Error;

    // doCheckbox('au_seerev', '<b>Authors can see reviews</b>');
    echo "Can <b>authors see reviews and comments</b> for their papers?<br />";
    doRadio("au_seerev", array(AU_SEEREV_NO => "No", AU_SEEREV_ALWAYS => "Yes", AU_SEEREV_YES => "Yes, once they&rsquo;ve completed any requested reviews"));

    echo "<div class='g'></div>\n<table id='foldauresp' class='foldo'>";
    doCheckbox('resp_open', "<b>Collect authors&rsquo; responses to the reviews<span class='fx'>:</span></b>", true, "void fold('auresp',!this.checked)");
    echo "<tr class='fx'><td></td><td><table>";
    doDateRow('resp_done', 'Hard deadline', null, "lxcaption");
    doGraceRow('resp_grace', 'Grace period', "lxcaption");
    doTextRow("resp_words", array("Word limit", "This is a soft limit: authors may submit longer responses. 0 means no limit."), setting("resp_words", 500), 5, "lxcaption", "none");
    echo "</table></td></tr></table>";
    $Conf->footerScript("fold('auresp',!\$\$('cbresp_open').checked)");

    echo "<div class='g'></div>\n<hr class='hr' />\n",
	"Who can see paper <b>decisions</b> (accept/reject)?<br />\n";
    doRadio("seedec", array(SEEDEC_ADMIN => "Only administrators", SEEDEC_NCREV => "Reviewers and non-conflicted PC members", SEEDEC_REV => "Reviewers and <em>all</em> PC members", SEEDEC_ALL => "<b>Authors</b>, reviewers, and all PC members (and reviewers can see accepted papers’ author lists)"));

    echo "<div class='g'></div>\n";
    echo "<table>\n";
    $decs = $rf->options['outcome'];
    krsort($decs);

    // count papers per decision
    $decs_pcount = array();
    $result = $Conf->qe("select outcome, count(*) from Paper where timeSubmitted>0 group by outcome");
    while (($row = edb_row($result)))
	$decs_pcount[$row[0]] = $row[1];

    // real decisions
    $n_real_decs = 0;
    foreach ($decs as $k => $v)
	$n_real_decs += ($k ? 1 : 0);
    $caption = "<td class='lcaption' rowspan='$n_real_decs'>Current decision types</td>";
    foreach ($decs as $k => $v)
	if ($k) {
	    if (count($Error) > 0)
		$v = defval($_REQUEST, "dec$k", $v);
	    echo "<tr>$caption<td class='lentry nowrap'>",
		"<input type='text' class='textlite' name='dec$k' value=\"", htmlspecialchars($v), "\" size='35' onchange='hiliter(this)' />",
		" &nbsp; ", ($k > 0 ? "Accept class" : "Reject class"), "</td>";
	    if (isset($decs_pcount[$k]) && $decs_pcount[$k])
		echo "<td class='lentry nowrap'>", plural($decs_pcount[$k], "paper"), "</td>";
	    echo "</tr>\n";
	    $caption = "";
	}

    // new decision
    $v = "";
    $vclass = 1;
    if (count($Error) > 0) {
	$v = defval($_REQUEST, "decn", $v);
	$vclass = defval($_REQUEST, "dtypn", $vclass);
    }
    echo "<tr><td class='lcaption'>",
	decorateSettingName("decn", "New decision type"),
	"<br /></td>",
	"<td class='lentry nowrap'><input type='text' class='textlite' name='decn' value=\"", htmlspecialchars($v), "\" size='35' onchange='hiliter(this)' /> &nbsp; ",
	tagg_select("dtypn", array(1 => "Accept class", -1 => "Reject class"),
		    $vclass, array("onchange" => "hiliter(this)")),
	"<br /><small>Examples: &ldquo;Accepted as short paper&rdquo;, &ldquo;Early reject&rdquo;</small>",
	"</td>";
    if (defval($Highlight, "decn"))
	echo "<td class='lentry nowrap'>",
	    tagg_checkbox_h("decn_confirm", 1, false),
	    "&nbsp;<span class='error'>", tagg_label("Confirm"), "</span></td>";
    echo "</tr>\n</table>\n";

    // Final versions
    echo "<hr class='hr' />";
    echo "<h3>Final versions</h3>\n";
    echo "<table id='foldfinal' class='foldo'>";
    doCheckbox('final_open', "<b>Collect final versions of accepted papers<span class='fx'>:</span></b>", true, "void fold('final',!this.checked)");
    echo "<tr class='fx'><td></td><td><table>";
    doDateRow("final_soft", "Deadline", "final_done", "lxcaption");
    doDateRow("final_done", "Hard deadline", null, "lxcaption");
    doGraceRow("final_grace", "Grace period", "lxcaption");
    echo "</table><div class='gs'></div>",
	"<small>To collect <em>multiple</em> final versions, such as one in 9pt and one in 11pt, add “Alternate final version” options via <a href='", hoturl("settings", "group=opt"), "'>Settings &gt; Submission options</a>.</small>",
	"</div></td></tr></table>\n\n";
    $Conf->footerScript("fold('final',!\$\$('cbfinal_open').checked)");
}

$belowHr = true;

echo "<form method='post' action='", hoturl_post("settings"), "' enctype='multipart/form-data' accept-charset='UTF-8'><div><input type='hidden' name='group' value='$Group' />\n";

echo "<table class='settings'><tr><td class='caption initial final'>";
echo "<table class='lhsel'>";
foreach (array("acc" => "Accounts",
	       "msg" => "Messages",
	       "sub" => "Submissions",
	       "opt" => "Submission options",
	       "rev" => "Reviews",
	       "rfo" => "Review form",
	       "dec" => "Decisions") as $k => $v) {
    echo "<tr><td>";
    if ($Group == $k)
	echo "<div class='lhl1'><a class='q' href='", hoturl("settings", "group=$k"), "'>$v</a></div>";
    else
	echo "<div class='lhl0'><a href='", hoturl("settings", "group=$k"), "'>$v</a></div>";
    echo "</td></tr>";
}
echo "</table></td><td class='top'><div class='lht'>";

// Good to warn multiple times about GD
if (!function_exists("imagecreate"))
    $Conf->warnMsg("Your PHP installation appears to lack GD support, which is required for drawing graphs.  You may want to fix this problem and restart Apache.", true);

echo "<div class='aahc'>";
doActionArea(true);

if ($Group == "acc")
    doAccGroup();
else if ($Group == "msg")
    doMsgGroup();
else if ($Group == "sub")
    doSubGroup();
else if ($Group == "opt")
    doOptGroup();
else if ($Group == "rev")
    doRevGroup();
else if ($Group == "rfo")
    doRfoGroup();
else
    doDecGroup();

doActionArea(false);
echo "</div></div></td></tr>
</table></div></form>\n";

$Conf->footer();
