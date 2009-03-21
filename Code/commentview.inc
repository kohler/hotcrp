<?php
// commentview.inc -- HotCRP helper class for producing comment boxes
// HotCRP is Copyright (c) 2006-2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CommentView {

    var $ncomment;
    var $nresponse;
    var $mode;

    function CommentView() {
	$this->ncomment = $this->nresponse = $this->mode = 0;
    }

    function beginTable() {
	echo "<table class='pbox'><tr>
  <td class='pboxl'></td>
  <td class='pboxr'>", tagg_cbox("cmt", false),
	    "\t<tr><td></td><td class='cmthead'><h3>Comments</h3></td><td></td></tr>
  <tr><td></td><td class='cmtcc'>";
	$this->mode = 1;
    }

    function endTable() {
	if ($this->mode) {
	    echo "</td><td></td></tr>\n", tagg_cbox("cmt", true),
		"</td></tr>\n</table>\n\n";
	    $this->mode = 0;
	}
    }

    function commentIdentityTime($prow, $crow, $response) {
	global $Conf, $Me, $forceShow;
	echo "<span class='cmtfn'>";
	$sep = "";
	$xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";
	if ($crow && $Me->canViewCommentIdentity($prow, $crow)) {
	    $blind = ($crow->blind && $crow->forAuthors > 0);
	    echo $sep, ($blind ? "[" : ""), contactHtml($crow), ($blind ? "]" : "");
	    $sep = $xsep;
	} else if ($crow && $Me->privChair) {
	    echo $sep, "<span id='foldcid$crow->commentId' class='fold4c'>",
		"<a class='q fn4' href=\"javascript:void fold('cid$crow->commentId', 0, 4)\" title='Show author'>+&nbsp;<i>Hidden for blind review</i></a>",
		"<span class='fx4'><a class='fx4' href=\"javascript:void fold('cid$crow->commentId', 1, 4)\" title='Hide author'>[blind]</a> ", contactHtml($crow), "</span>",
		"</span>";
	    $sep = $xsep;
	}
	if ($crow && $crow->timeModified > 0) {
	    echo $sep, $Conf->printableTime($crow->timeModified);
	    $sep = $xsep;
	}
	echo "</span>";
	if ($crow && !$response
	    && ($prow->conflictType == 0 || ($Me->privChair && $forceShow))) {
	    if ($crow->forAuthors == 0 && $crow->forReviewers == 0)
		$x = "shown only to PC reviewers";
	    else if ($crow->forAuthors == 0)
		$x = "hidden from authors";
	    else
		$x = "";
	    if ($x)
		echo "<span class='hint'>(", $x, ")</span>";
	}
	echo "<div class='clear'></div>";
    }

    function show($prow, $crow, $useRequest, $editMode, $foldnew = true) {
	global $Conf, $ConfSiteSuffix, $CommentMsg, $Me, $forceShow, $linkExtra;

	if ($crow && $crow->forAuthors > 1)
	    return $this->showResponse($prow, $crow, $useRequest, $editMode);

	if ($crow && !$Me->canViewComment($prow, $crow))
	    return;
	if ($this->mode != 1)
	    $this->beginTable();
	if ($editMode && !$Me->canComment($prow, $crow))
	    $editMode = false;
	$this->ncomment++;

	if ($editMode) {
	    echo "<form action='comment$ConfSiteSuffix?p=$prow->paperId";
	    if ($crow)
		echo "&amp;c=$crow->commentId";
	    echo "$linkExtra&amp;post=1";
	    if ($crow)
		echo "#comment$crow->commentId";
	    echo "' method='post' enctype='multipart/form-data' accept-charset='UTF-8'>";
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
	echo " class='cmtt'>";

	// Links
	if ($crow && ($crow->contactId == $Me->contactId || $Me->privChair)
	    && !$editMode && $Me->canComment($prow, $crow)) {
	    $a = "<a href='paper$ConfSiteSuffix?p=$prow->paperId&amp;c=$crow->commentId$linkExtra#comment$crow->commentId'>";
	    echo "<div class='floatright'>", $a,
		$Conf->cacheableImage("edit.png", "[Edit]", null, "b"),
		"</a>&nbsp;", $a, "Edit</a></div>";
	}

	if (!$crow && $editMode && $foldnew)
	    echo "<h3><a class='q fn' href=\"", selfHref(array("c" => "new")),
		"\" onclick='fold(\"addcomment\", 0);location.hash=\"#commentnew\";return false'>+&nbsp;Add Comment</a><span class='fx'>Add Comment</span></h3>";
	else if (!$crow)
	    echo "<h3>Add Comment</h3>";
	$this->commentIdentityTime($prow, $crow, false);

	if ($crow && $editMode && $crow->contactId != $Me->contactId)
	    echo "<div class='hint'>You didn't write this comment, but as an administrator you can still make changes.</div>\n";

	echo "</div><div class='cmtv", (!$crow && $editMode && $foldnew ? " fx" : ""), "'>";

	if (isset($CommentMsg) && $crow && isset($CommentMsg[$crow->commentId]))
	    echo $CommentMsg[$crow->commentId];

	if (!$editMode) {
	    echo htmlWrapText(htmlspecialchars($crow->comment)), "</div>\n\n";
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
    <td>", tagg_radio_h("visibility", "p", ($useRequest ? defval($_REQUEST, "visibility") == "p" : ($crow && $crow->forAuthors == 0 && $crow->forReviewers == 0))),
	    "&nbsp;", tagg_label("PC reviewers only"), "<br />\n";
	echo tagg_radio_h("visibility", "r", ($useRequest ? defval($_REQUEST, "visibility") == "r" : (!$crow || ($crow->forAuthors == 0 && $crow->forReviewers != 0)))),
	    "&nbsp;", tagg_label("PC and external reviewers"), "<br />\n";
	echo tagg_radio_h("visibility", "a", ($useRequest ? defval($_REQUEST, "visibility") == "a" : ($crow && $crow->forAuthors != 0))),
	    "&nbsp;", tagg_label("Authors and reviewers");
	// blind?
	if ($Conf->blindReview() == BLIND_OPTIONAL) {
	    echo " &nbsp; (",
		tagg_checkbox_h("blind", 1, ($useRequest ? defval($_REQUEST, "blind") : !$crow || $crow->blind)),
		"&nbsp;", tagg_label("Anonymous to authors"), ")\n";
	} else if ($Conf->blindReview() == BLIND_ALWAYS)
	    echo " (anonymous to authors)";
	echo "</td></tr></table>\n";

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
	global $Conf, $ConfSiteSuffix, $CommentMsg, $Me, $forceShow, $linkExtra;

	$this->endTable();
	if ($editMode && !$Me->canRespond($prow, $crow)
	    && ($crow || !$Me->privChair))
	    $editMode = false;
	$this->nresponse++;
	$wordlimit = $Conf->setting("resp_words", 0);

	if ($editMode) {
	    echo "<form action='comment$ConfSiteSuffix?";
	    if ($crow)
		echo "c=$crow->commentId";
	    else
		echo "p=$prow->paperId";
	    echo "$linkExtra&amp;response=1&amp;post=1";
	    if ($crow)
		echo "#comment$crow->commentId";
	    echo "' method='post' enctype='multipart/form-data' accept-charset='UTF-8'><div class='aahc'>\n";
	}

	echo "<table class='pbox'>
<tr>
  <td class='pboxl'></td>
  <td class='pboxr'>", tagg_cbox("cmt", false, "response"),
	    "\t<tr><td></td><td class='cmthead'>";

	// Links
	if ($crow && ($prow->conflictType >= CONFLICT_AUTHOR || $Me->privChair)
	    && !$editMode && $Me->canRespond($prow, $crow)) {
	    $a = "<a href='paper$ConfSiteSuffix?p=$prow->paperId&amp;c=$crow->commentId$linkExtra#comment$crow->commentId'>";
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
	    echo "<div class='hint'>The authors' response is intended to address
reviewer concerns and correct misunderstandings.
The response should be addressed to the program committee, who
will consider it when making their decision.  Don't try to
augment the paper's content or form&mdash;the conference deadline
has passed.  Please keep the response short and to the point",
		$limittext, ".</div>\n";
	    if ($prow->conflictType < CONFLICT_AUTHOR)
		echo "<div class='hint'>Although you aren't a contact author for this paper, as an administrator you can edit the authors' response.</div>\n";
	}

	echo "</td><td></td></tr>
  <tr><td></td><td class='cmtcc'>";

	if (isset($CommentMsg) && $crow && isset($CommentMsg[$crow->commentId]))
	    echo $CommentMsg[$crow->commentId];

	if (!$editMode) {
	    if ($Me->privChair && $crow->forReviewers == 0)
		echo "<i>The <a href='comment$ConfSiteSuffix?c=$crow->commentId$linkExtra'>authors' response</a> is not yet ready for reviewers to view.</i>";
	    else if (!$Me->canViewComment($prow, $crow))
		echo "<i>The authors' response is not yet ready for reviewers to view.</i>";
	    else
		echo htmlWrapText(htmlspecialchars($crow->comment));
	    echo "</td><td></td></tr>\n", tagg_cbox("cmt", true),
		"</td></tr>\n</table>\n";
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
	    "</td></tr>\n</table>\n</div></form>\n\n";
    }

}
