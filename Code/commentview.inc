<?php
// commentview.inc -- HotCRP helper class for producing comment boxes
// HotCRP is Copyright (c) 2006-2012 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CommentView {

    var $ncomment_in_table;
    var $nresponse;
    var $mode;
    var $numbers;

    function __construct() {
	$this->ncomment_in_table = $this->nresponse = $this->mode = 0;
	$this->numbers = array();
    }

    function beginTable() {
	echo "<div class='pboxc'><table class='pbox'><tr>
  <td class='pboxl'></td>
  <td class='pboxr'>", tagg_cbox("cmt", false),
	    "\t<tr><td></td><td class='cmthead'><h3>Comments</h3></td><td></td></tr>
  <tr><td></td><td class='cmtcc'>";
	$this->mode = 1;
	$this->ncomment_in_table = 0;
	$this->numbers = array();
    }

    function endTable() {
	if ($this->mode) {
	    echo "</td><td></td></tr>\n", tagg_cbox("cmt", true),
		"</td></tr>\n</table></div>\n\n";
	    $this->mode = 0;
	}
    }

    function commentOrdinal($prow, $crow) {
	if (($crow->forAuthors == 2 && $crow->forReviewers == 0)
	    || $crow->forReviewers == 2)
	    return null;
	$p = ($crow->forAuthors ? "A" : "");
	$stored_number = defval($this->numbers, $p, 0);
	if (isset($crow->ordinal) && $crow->ordinal)
	    $n = $crow->ordinal;
	else
	    $n = $stored_number + 1;
	$this->numbers[$p] = max($n, $stored_number);
	return $p . $n;
    }

    function commentIdentityTime($prow, $crow, $response) {
	global $Conf, $Me, $forceShow;
	echo "<span class='cmtfn'>";
	$sep = "";
	$xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";
	if ($crow && ($number = $this->commentOrdinal($prow, $crow))) {
	    echo "<span class='cmtnumhead'><a class='qq' href='#comment",
		$crow->commentId, "'><span class='cmtnumat'>@</span>",
		"<span class='cmtnumnum'>", $number, "</span></a></span>";
	    $sep = $xsep;
	}
	if ($crow && $Me->canViewCommentIdentity($prow, $crow)) {
	    $blind = ($crow->blind && $crow->forAuthors > 0);
	    echo $sep, "<span class='cmtname'>", ($blind ? "[" : ""),
		contactHtml($crow), ($blind ? "]" : ""), "</span>";
	    $sep = $xsep;
	} else if ($crow && $Me->privChair) {
	    echo $sep, "<span id='foldcid$crow->commentId' class='cmtname fold4c'>",
		"<a class='q fn4' href=\"javascript:void fold('cid$crow->commentId', 0, 4)\" title='Show author'>+&nbsp;<i>Hidden for blind review</i></a>",
		"<span class='fx4'><a class='fx4' href=\"javascript:void fold('cid$crow->commentId', 1, 4)\" title='Hide author'>[blind]</a> ", contactHtml($crow), "</span>",
		"</span>";
	    $sep = $xsep;
	}
	if ($crow && $crow->timeModified > 0) {
	    echo $sep, "<span class='cmttime'>", $Conf->printableTime($crow->timeModified), "</span>";
	    $sep = $xsep;
	}
	echo "</span>";
	if ($crow && !$response
	    && ($prow->conflictType == 0 || ($Me->privChair && $forceShow))) {
	    $forReviewers = ($crow->forReviewers == -1 ? 1 : $crow->forReviewers);
	    if ($crow->forAuthors == 0 && $forReviewers == 2)
		$x = "shown only to PC chairs";
	    else if ($crow->forAuthors == 0 && $forReviewers == 0)
		$x = "shown only to PC reviewers";
	    else if ($crow->forAuthors == 0)
		$x = "hidden from authors";
	    else
		$x = "";
	    if ($x)
		echo "<span class='cmtvis'>(", $x, ")</span>";
	}
	echo "<div class='clear'></div>";
    }

    function show($prow, $crow, $useRequest, $editMode, $foldnew = true) {
	global $Conf, $Me, $forceShow, $linkExtra;

	if ($crow && $crow->forAuthors > 1)
	    return $this->showResponse($prow, $crow, $useRequest, $editMode);

	if ($crow && !$Me->canViewComment($prow, $crow))
	    return;
	if ($this->mode != 1)
	    $this->beginTable();
	if ($editMode && !$Me->canComment($prow, $crow))
	    $editMode = false;
	$this->ncomment_in_table++;

	if ($editMode) {
	    echo "<form action='", hoturl("comment", "p=$prow->paperId&amp;c=" . ($crow ? $crow->commentId : "new") . "$linkExtra&amp;post=1"),
		"' method='post' enctype='multipart/form-data' accept-charset='UTF-8'>";
	    if (!$crow && $foldnew)
		echo "<div class='aahc foldc' id='foldaddcomment'>";
	    else
		echo "<div class='aahc'>";
	}

	echo "<div";
	if ($crow)
	    echo " id='comment$crow->commentId'";
	else
	    echo " id='commentnew'";
	echo " class='cmtg", ($this->ncomment_in_table == 1 ? " cmtg1" : ""), "'>";
	if ($crow && !$editMode && $crow->forReviewers == 2)
	    echo "<div class='cmtadminvis'>";
	echo "<div class='cmtt'>";

	// Links
	if ($crow && ($crow->contactId == $Me->contactId || $Me->privChair)
	    && !$editMode && $Me->canComment($prow, $crow)) {
	    $a = "<a href='" . hoturl("paper", "p=$prow->paperId&amp;c=$crow->commentId$linkExtra#comment$crow->commentId") . "'>";
	    echo "<div class='floatright'>", $a,
		$Conf->cacheableImage("edit.png", "[Edit]", null, "b"),
		"</a>&nbsp;", $a, "Edit</a></div>";
	}

	if (!$crow) {
	    if ($editMode && $foldnew)
		echo "<h3><a class='q fn' href=\"",
		    selfHref(array("c" => "new")),
		    "\" onclick='return open_new_comment(1)'>+&nbsp;Add Comment</a><span class='fx'>Add Comment</span></h3>";
	    else
		echo "<h3>Add Comment</h3>";
	    $Conf->footerScript("hotcrpLoad('opencomment')");
	}
	$this->commentIdentityTime($prow, $crow, false);

	if ($crow && $editMode && $crow->contactId != $Me->contactId)
	    echo "<div class='hint'>You didn&rsquo;t write this comment, but as an administrator you can still make changes.</div>\n";

	echo "</div><div class='cmtv", (!$crow && $editMode && $foldnew ? " fx" : ""), "'>";

	if (isset($_SESSION["comment_msgs"]) && $crow
	    && isset($_SESSION["comment_msgs"][$crow->commentId]))
	    echo $_SESSION["comment_msgs"][$crow->commentId];

	if (!$editMode) {
	    echo htmlWrapText(htmlspecialchars($crow->comment)), "</div>";
	    if ($crow && $crow->forReviewers == 2)
		echo "</div>";
	    echo "</div>\n\n";
	    return;
	}

	// From here on, edit mode.
	// form body
	echo "<textarea name='comment' class='reviewtext' rows='10' cols='60' onchange='hiliter(this)'>";
	if ($useRequest)
	    echo htmlspecialchars(defval($_REQUEST, 'comment'));
	else if ($crow)
	    echo htmlspecialchars($crow->comment);
	echo "</textarea>
  <div class='g'></div>
  <table><tr><td>Show to: &nbsp; </td>
    <td><table id='foldcmtvis' class='fold2o'>";
	$forAuthors = $crow && $crow->forAuthors != 0;
	$forReviewers = ($crow && $crow->forReviewers != -1 ? $crow->forReviewers : 1);
	echo "<tr><td>", tagg_radio_h("visibility", "a", ($useRequest ? defval($_REQUEST, "visibility") == "a" : ($forAuthors != 0)), array("id" => "cmtvis_a", "onchange" => "docmtvis(this)")),
	    "&nbsp;</td><td>", tagg_label("Authors and reviewers" . ($Conf->blindReview() == BLIND_ALWAYS ? " (anonymous to authors)" : ""));
	// blind?
	if ($Conf->blindReview() == BLIND_OPTIONAL)
	    echo " &nbsp; (",
		tagg_checkbox_h("blind", 1, ($useRequest ? defval($_REQUEST, "blind") : !$crow || $crow->blind)),
		"&nbsp;", tagg_label("Anonymous to authors"), ")";
	if ($Conf->timeAuthorViewReviews())
	    echo "<br /><span class='fx2 hint'>Authors will be notified of new comments immediately.</span>";
	else
	    echo "<br /><span class='fx2 hint'>Authors cannot view comments at the moment.</span>";
	echo "</td></tr>\n";
	echo "<tr><td>", tagg_radio_h("visibility", "r", ($useRequest ? defval($_REQUEST, "visibility") == "r" : ($forAuthors == 0 && $forReviewers == 1)), array("onchange" => "docmtvis(this)")),
	    "&nbsp;</td><td>", tagg_label("PC and external reviewers"), "</td></tr>\n";
	echo "<tr><td>", tagg_radio_h("visibility", "p", ($useRequest ? defval($_REQUEST, "visibility") == "p" : ($forAuthors == 0 && $forReviewers == 0)), array("onchange" => "docmtvis(this)")),
	    "&nbsp;</td><td>", tagg_label("PC reviewers only"), "</td></tr>\n";
	echo "<tr><td>", tagg_radio_h("visibility", "admin", ($useRequest ? defval($_REQUEST, "visibility") == "admin" : ($forAuthors == 0 && $forReviewers == 2)), array("onchange" => "docmtvis(this)")),
	    "&nbsp;</td><td>", tagg_label("PC chairs only"), "</td></tr>\n";
	echo "</table></td></tr></table>\n";
	$Conf->footerScript("docmtvis(false)");

	// review actions
	echo "<div class='aa'><table class='pt_buttons'>\n",
	    "    <tr>\n",
	    "      <td class='ptb_button'><input class='bb' type='submit' value='Save' name='submit' /></td>\n";
	if ($crow)
	    echo "      <td class='ptb_button'><input class='b' type='submit' value='Cancel' name='cancel' /></td>\n";
	else
	    echo "      <td class='ptb_button'><button class='b' type='button' onclick='javascript:void fold(\"addcomment\", 1)'>Cancel</button></td>\n";
	if ($crow)
	    echo "      <td class='ptb_button'><span class='lgsep'></span><input class='b' type='submit' value='Delete comment' name='delete' /></td>\n";
	echo "    </tr>\n  </table></div>\n";
	if (!$Me->timeReview($prow, null))
	    echo tagg_checkbox("override"), "&nbsp;", tagg_label("Override&nbsp;deadlines");

	echo "</div></div></form>\n\n";
    }

    function showResponse($prow, $crow, $useRequest, $editMode) {
	global $Conf, $Me, $forceShow, $linkExtra;

	$this->endTable();
	if ($editMode && !$Me->canRespond($prow, $crow)
	    && ($crow || !$Me->privChair))
	    $editMode = false;
	$this->nresponse++;
	$wordlimit = $Conf->setting("resp_words", 0);

	if ($editMode) {
	    echo "<form action='", hoturl("comment", ($crow ? "c=$crow->commentId" : "p=$prow->paperId") . "$linkExtra&amp;response=1&amp;post=1");
	    if ($crow)
		echo "#comment$crow->commentId";
	    echo "' method='post' enctype='multipart/form-data' accept-charset='UTF-8'><div class='aahc'>\n";
	}

	echo "<div class='pboxc'><table class='pbox'>
<tr>
  <td class='pboxl'></td>
  <td class='pboxr'>", tagg_cbox("cmt", false, "response"),
	    "\t<tr><td></td><td class='cmthead'>";

	// Links
	if ($crow && ($prow->conflictType >= CONFLICT_AUTHOR || $Me->privChair)
	    && !$editMode && $Me->canRespond($prow, $crow)) {
	    $a = "<a href='" . hoturl("paper", "p=$prow->paperId&amp;c=$crow->commentId$linkExtra#comment$crow->commentId") . "'>";
	    echo "<div class='floatright'>", $a,
		$Conf->cacheableImage("edit.png", "[Edit]", null, "b"),
		"</a>&nbsp;", $a, "Edit</a></div>";
	}

	echo "<h3";
	if ($crow)
	    echo " id='comment$crow->commentId'";
	else
	    echo " id='response'";
	echo ">Response</h3>";
	$this->commentIdentityTime($prow, $crow, true);

	if ($editMode) {
	    $limittext = ($wordlimit ? ": the conference system will enforce a limit of $wordlimit words" : "");
	    echo "<div class='hint'>The authors&rsquo; response is intended to address
reviewer concerns and correct misunderstandings.
The response should be addressed to the program committee, who
will consider it when making their decision.  Don&rsquo;t try to
augment the paper&rsquo;s content or form&mdash;the conference deadline
has passed.  Please keep the response short and to the point",
		$limittext, ".</div>\n";
	    if ($prow->conflictType < CONFLICT_AUTHOR)
		echo "<div class='hint'>Although you aren&rsquo;t a contact for this paper, as an administrator you can edit the authors&rsquo; response.</div>\n";
	}

	echo "</td><td></td></tr>
  <tr><td></td><td class='cmtcc'>";

	if (isset($_SESSION["comment_msgs"]) && $crow
	    && isset($_SESSION["comment_msgs"][$crow->commentId]))
	    echo $_SESSION["comment_msgs"][$crow->commentId];

	if (!$editMode) {
	    echo "<div class='cmtg cmtg1'>";
	    if ($Me->privChair && $crow->forReviewers == 0)
		echo "<i>The <a href='", hoturl("comment", "c=$crow->commentId$linkExtra"), "'>authors&rsquo; response</a> is not yet ready for reviewers to view.</i>";
	    else if (!$Me->canViewComment($prow, $crow))
		echo "<i>The authors&rsquo; response is not yet ready for reviewers to view.</i>";
	    else
		echo htmlWrapText(htmlspecialchars($crow->comment));
	    echo "</div></td><td></td></tr>\n", tagg_cbox("cmt", true),
		"</td></tr>\n</table></div>\n";
	    return;
	}

	// From here on, edit mode.
	// form body
	echo "<textarea name='comment' class='reviewtext' rows='10' cols='60' onchange='hiliter(this)'>";
	if ($useRequest)
	    echo htmlspecialchars(defval($_REQUEST, 'comment'));
	else if ($crow)
	    echo htmlspecialchars($crow->comment);
	echo "</textarea>
  <div class='g'></div>\n  ",
	    tagg_checkbox_h("forReviewers", 1, ($useRequest ? defval($_REQUEST, "forReviewers") : !$crow || $crow->forReviewers != 0)),
	    "&nbsp;", tagg_label("This response should be sent to the reviewers."), "\n";

	// review actions
	echo "<div class='aa'><table class='pt_buttons'>
    <tr>\n";
	echo "      <td class='ptb_button'><input class='bb' type='submit' value='Save' name='submit' /></td>\n";
	if ($crow)
	    echo "      <td class='ptb_button'><input class='b' type='submit' value='Delete response' name='delete' /></td>\n";
	echo "    </tr>\n  </table></div>\n";
	if (!$Conf->timeAuthorRespond())
	    echo tagg_checkbox("override"), "&nbsp;", tagg_label("Override&nbsp;deadlines");

	echo "</td><td></td></tr>\n", tagg_cbox("cmt", true),
	    "</td></tr>\n</table>\n</div></div></div></form>\n\n";
    }


    static function commentFlowEntry($contact, $crow, $trclass) {
	// See also ReviewForm::reviewFlowEntry
	global $Conf;
	$a = "<a href='" . hoturl("paper", "p=$crow->paperId#comment$crow->commentId") . "'>";
	$t = "<tr class='$trclass'><td class='pl_activityicon'>" . $a
	    . $Conf->cacheableImage("comment24.png", "[Comment]", null, "dlimg")
	    . "</a></td><td class='pl_activityid'>"
	    . $a . "#$crow->paperId</a></td><td class='pl_activitymain'><small>"
	    . $a . htmlspecialchars($crow->shortTitle);
	if (strlen($crow->shortTitle) != strlen($crow->title))
	    $t .= "...";
	$t .= "</a>";
	if ($contact->canViewCommentIdentity($crow, $crow, true))
	    $t .= " &nbsp;<span class='barsep'>|</span>&nbsp; <span class='hint'>comment by</span> " . contactHtml($crow->reviewFirstName, $crow->reviewLastName, $crow->reviewEmail);
	$t .= " &nbsp;<span class='barsep'>|</span>&nbsp; <span class='hint'>posted</span> " . $Conf->parseableTime($crow->timeModified, false);
	$t .= "</small><br /><a class='q'" . substr($a, 3)
	    . htmlspecialchars($crow->shortComment);
	if (strlen($crow->shortComment) < strlen($crow->comment))
	    $t .= "...";
	return $t . "</a></td></tr>";
    }

}
