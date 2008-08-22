<?php
// commentview.inc -- HotCRP helper class for producing comment boxes
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
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
	if ($crow && $Me->canViewCommentIdentity($prow, $crow, $Conf)) {
	    $blind = ($crow->blind && $crow->forAuthors > 0);
	    echo $sep, ($blind ? "[" : ""), contactHtml($crow), ($blind ? "]" : "");
	    $sep = $xsep;
	} else if ($crow && $Me->privChair) {
	    echo $sep, "<span id='foldcid$crow->commentId' class='fold4c'>",
		foldbutton("cid$crow->commentId", "comment", 4),
		" <span class='ellipsis4'><i>Hidden for blind review</i></span>",
		"<span class='extension4'>", contactHtml($crow), "</span>",
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
	    $x = array();
	    if (!$crow->forAuthors)
		$x[] = "hidden from authors";
	    else if ($crow->blind)
		$x[] = "anonymous to authors";
	    if ($crow->forReviewers == 0)
		$x[] = "hidden from external reviewers";
	    else if ($crow->forReviewers == -1)
		$x[] = "tied to reviews";
	    if (count($x))
		echo "<span class='hint'>(", join(", ", $x), ")</span>";
	}
	echo "<div class='clear'></div>";
    }

    function show($prow, $crow, $useRequest, $editMode, $foldnew = true) {
	global $Conf, $ConfSiteSuffix, $Me, $forceShow, $linkExtra;

	if ($crow && $crow->forAuthors > 1)
	    return $this->showResponse($prow, $crow, $useRequest, $editMode);

	if ($crow && !$Me->canViewComment($prow, $crow, $Conf))
	    return;
	if ($this->mode != 1)
	    $this->beginTable();
	if ($editMode && !$Me->canComment($prow, $crow, $Conf))
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
	    && !$editMode && $Me->canComment($prow, $crow, $Conf)) {
	    $a = "<a href='paper$ConfSiteSuffix?p=$prow->paperId&amp;c=$crow->commentId$linkExtra#comment$crow->commentId'>";
	    echo "<div class='floatright'>", $a,
		$Conf->cacheableImage("newreview.png", "[Edit]", null, "b"),
		"</a>&nbsp;", $a, "Edit</a></div>";
	}

	if (!$crow && $editMode && $foldnew)
	    echo "<h3><a class='q unfolder' href='javascript:fold(\"addcomment\", 0)'>+&nbsp;Add Comment</a><span class='extension'>Add Comment</span></h3>";
	else if (!$crow)
	    echo "<h3>Add Comment</h3>";
	$this->commentIdentityTime($prow, $crow, false);

	if ($crow && $editMode && $crow->contactId != $Me->contactId)
	    echo "<div class='hint'>You didn't write this comment, but as an administrator you can still make changes.</div>\n";

	echo "</div><div class='cmtv", (!$crow && $editMode && $foldnew ? " extension" : ""), "'>";
    
	if (!$editMode) {
	    echo htmlWrapText(htmlspecialchars($crow->comment)), "</div>\n\n";
	    return;
	}

	// From here on, edit mode.
	// form body
	echo "<textarea name='comment' rows='10' cols='80' onchange='hiliter(this)'>";
	if ($useRequest)
	    echo htmlspecialchars(defval($_REQUEST, 'comment'));
	else if ($crow)
	    echo htmlspecialchars($crow->comment);
	echo "</textarea>
  <div class='g'></div>
  Visibility: &nbsp;<input type='checkbox' name='forAuthors' value='1'";
	if ($useRequest ? defval($_REQUEST, "forAuthors") : ($crow && $crow->forAuthors))
	    echo " checked='checked'";
	echo " onchange='hiliter(this)' />&nbsp;<span class='hastitle' title='Show this comment to authors along with the reviews. (The comment is hidden when reviews are invisible.)'>Shown to authors</span>\n";
	// blind?
	if ($Conf->blindReview() == 1) {
	    echo " &nbsp; (<input type='checkbox' name='blind' value='1'";
	    if ($useRequest ? defval($_REQUEST, 'blind')
		: (!$crow || $crow->blind))
		echo " checked='checked'";
	    echo " onchange='hiliter(this)' />&nbsp;Anonymous to authors)\n";
	}
	// For authors?
	echo "<span class='sep'></span><input type='checkbox' name='reviewLinked' value='1'";
	if (($useRequest && defval($_REQUEST, 'reviewLinked'))
	    || (!$useRequest && $crow && $crow->forReviewers == -1))
	    echo " checked='checked'";
	echo " onchange='hiliter(this)' />&nbsp;<span class='hastitle' title='Hide this comment from PC members and reviewers who cannot see the reviews.'>Tied to reviews</span>\n";

	// review actions
	echo "<div class='aa'><table class='pt_buttons'>\n",
	    "    <tr>\n",
	    "      <td class='ptb_button'><input class='bb' type='submit' value='Save' name='submit' /></td>\n";
	if ($crow)
	    echo "      <td class='ptb_button'><input class='b' type='submit' value='Cancel' name='cancel' /></td>\n";
	else
	    echo "      <td class='ptb_button'><button class='b' type='button' onclick='javascript:fold(\"addcomment\", 1)'>Cancel</button></td>\n";
	if ($crow)
	    echo "      <td class='ptb_button'><span class='lgsep'></span><input class='b' type='submit' value='Delete comment' name='delete' /></td>\n";
	echo "    </tr>\n  </table></div>\n";
	if (!$Me->timeReview($prow, null, $Conf))
	    echo "<input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines";

	echo "</div></form>\n\n";
    }

    function showResponse($prow, $crow, $useRequest, $editMode) {
	global $Conf, $ConfSiteSuffix, $Me, $forceShow, $linkExtra;

	$this->endTable();
	if ($editMode && !$Me->canRespond($prow, $crow, $Conf))
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
	    && !$editMode && $Me->canRespond($prow, $crow, $Conf)) {
	    $a = "<a href='paper$ConfSiteSuffix?p=$prow->paperId&amp;c=$crow->commentId$linkExtra#comment$crow->commentId'>";
	    echo "<div class='floatright'>", $a,
		$Conf->cacheableImage("newreview.png", "[Edit]", null, "b"),
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

	if (!$editMode) {
	    if ($Me->privChair && $crow->forReviewers < 1)
		echo "<i>The <a href='comment$ConfSiteSuffix?c=$crow->commentId$linkExtra'>authors' response</a> is not yet ready for reviewers to view.</i>";
	    else if (!$Me->canViewComment($prow, $crow, $Conf))
		echo "<i>The authors' response is not yet ready for reviewers to view.</i>";
	    else
		echo htmlWrapText(htmlspecialchars($crow->comment));
	    echo "</td><td></td></tr>\n", tagg_cbox("cmt", true),
		"</td></tr>\n</table>\n";
	    return;
	}

	// From here on, edit mode.
	// form body
	echo "<textarea name='comment' rows='10' cols='80' onchange='hiliter(this)'>";
	if ($useRequest)
	    echo htmlspecialchars(defval($_REQUEST, 'comment'));
	else if ($crow)
	    echo htmlspecialchars($crow->comment);
	echo "</textarea>
  <div class='g'></div>
  <input type='checkbox' name='forReviewers' value='1' ";
	if ($useRequest ? defval($_REQUEST, "forReviewers")
	    : (!$crow || $crow->forReviewers > 0))
	    echo "checked='checked' ";
	echo "onchange='hiliter(this)' />&nbsp;The response is ready for reviewers to view.\n";

	// review actions
	echo "<div class='aa'><table class='pt_buttons'>
    <tr>\n";
	echo "      <td class='ptb_button'><input class='bb' type='submit' value='Save' name='submit' /></td>\n";
	if ($crow)
	    echo "      <td class='ptb_button'><input class='b' type='submit' value='Delete response' name='delete' /></td>\n";
	echo "    </tr>\n  </table></div>\n";
	if (!$Conf->timeAuthorRespond())
	    echo "<input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines";

	echo "</td><td></td></tr>\n", tagg_cbox("cmt", true),
	    "</td></tr>\n</table>\n</div></form>\n\n";
    }

}
