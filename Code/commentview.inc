<?php
// commentview.inc -- HotCRP helper class for producing comment boxes
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CommentView {

    var $ncomment;
    var $nresponse;
    
    function CommentView() {
	$this->ncomment = $this->nresponse = 0;
    }

    function commentIdentityTime($prow, $crow) {
	global $Conf, $Me;
	$open = $sep = " <span class='revinfo'>";
	$xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";
	if ($crow && $Me->canViewCommentIdentity($prow, $crow, $Conf)) {
	    $blind = ($crow->blind && $crow->forAuthors > 0);
	    echo $sep, ($blind ? "[" : ""), "by ", contactHtml($crow);
	    $sep = ($blind ? "]" : "") . $xsep;
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
	if ($sep != $open)
	    echo "</span>\n";
    }

    function show($prow, $crow, $useRequest, $editMode, $foldnew = true) {
	global $Conf, $ConfSiteSuffix, $Me, $forceShow, $linkExtra;

	if ($crow && $crow->forAuthors > 1)
	    return $this->showResponse($prow, $crow, $useRequest, $editMode);

	if (!$Me->canViewComment($prow, $crow, $Conf))
	    return;
	if ($editMode && !$Me->canComment($prow, $crow, $Conf))
	    $editMode = false;
	$this->ncomment++;

	if ($editMode) {
	    echo "<form action='comment$ConfSiteSuffix?";
	    if ($crow)
		echo "c=$crow->commentId";
	    else
		echo "p=$prow->paperId";
	    echo "$linkExtra&amp;post=1' method='post' enctype='multipart/form-data' accept-charset='UTF-8'>";
	    if (!$crow && $foldnew)
		echo "<div class='aahc foldc' id='foldaddcomment'>";
	    else
		echo "<div class='aahc'>";
	}

	echo "<table class='pbox'><tr>
  <td class='pboxl'></td>
  <td class='pboxr'>", tagg_cbox("cmt", false),
	    "\t<tr><td></td><td class='cmthead'>";

	// Links
	if ($crow && ($crow->contactId == $Me->contactId || $Me->privChair)
	    && !$editMode) {
	    $a = "<a href='paper$ConfSiteSuffix?p=$prow->paperId&amp;c=$crow->commentId$linkExtra#comment$crow->commentId'>";
	    echo "<div class='floatright'>", $a,
		$Conf->cacheableImage("newreview.png", "[Edit]", null, "b"),
		"</a>&nbsp;", $a, "Edit</a></div>";
	}

	echo "<h3";
	if ($crow)
	    echo " id='comment$crow->commentId'";
	else
	    echo " id='commentnew'";
	if ($editMode)
	    echo " class='editable'";
	echo ">";
	if (!$crow && $editMode && $foldnew)
	    echo "<a class='q unfolder' href='javascript:fold(\"addcomment\", 0)'>+&nbsp;Add Comment</a><span class='extension'>Add Comment</span>";
	else
	    echo ($crow ? "Comment" : "Add Comment");
	echo "</h3>";
	$this->commentIdentityTime($prow, $crow);

	if (!$crow || $prow->conflictType >= CONFLICT_AUTHOR)
	    /* do nothing */;
	else if (!$crow->forAuthors && !$crow->forReviewers)
	    echo "<div class='hint'>For PC only</div>";
	else {
	    echo "<div class='hint'>For PC";
	    if ($crow->forReviewers)
		echo " + reviewers";
	    if ($crow->forAuthors && $crow->blind)
		echo " + authors (anonymous to authors)";
	    else if ($crow->forAuthors)
		echo " + authors";
	    echo "</div>\n";
	}

	if ($crow && $editMode && $crow->contactId != $Me->contactId)
	    echo "<div class='hint'>You didn't write this comment, but as an administrator you can still make changes.</div>\n";

	echo "</td><td></td></tr>
  <tr><td></td><td class='cmtcc", (!$crow && $editMode && $foldnew ? " extension" : ""), "'>";
    
	if (!$editMode) {
	    echo htmlWrapText(htmlspecialchars($crow->comment)),
		"</td><td></td></tr>\n", tagg_cbox("cmt", true),
		"</td></tr>\n</table>\n\n";
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
  Visible to PC and: <input type='checkbox' name='forReviewers' value='1'";
	if (($useRequest && defval($_REQUEST, 'forReviewers'))
	    || (!$useRequest && $crow && $crow->forReviewers)
	    || (!$useRequest && !$crow && $Conf->setting("extrev_view") > 0))
	    echo " checked='checked'";
	echo " onchange='hiliter(this)' />&nbsp;Reviewers &nbsp;
    <input type='checkbox' name='forAuthors' value='1'";
	if ($useRequest ? defval($_REQUEST, 'forAuthors') : ($crow && $crow->forAuthors))
	    echo " checked='checked'";
	echo " onchange='hiliter(this)' />&nbsp;Authors\n";

	// blind?
	if ($Conf->blindReview() == 1) {
	    echo "<span class='lgsep'></span><input type='checkbox' name='blind' value='1'";
	    if ($useRequest ? defval($_REQUEST, 'blind')
		: (!$crow || $crow->blind))
		echo " checked='checked'";
	    echo " onchange='hiliter(this)' />&nbsp;Anonymous to authors\n";
	}

	// review actions
	echo "<div class='aa'><table class='pt_buttons'>
    <tr>\n";
	echo "      <td class='ptb_button'><input class='bb' type='submit' value='Save' name='submit' /></td>\n";
	if ($crow)
	    echo "      <td class='ptb_button'><input class='b' type='submit' value='Delete comment' name='delete' /></td>\n";
	else
	    echo "      <td class='ptb_button'><button class='b' type='button' onclick='javascript:fold(\"addcomment\", 1)'>Cancel</button></td>\n";
	echo "    </tr>\n  </table></div>\n";
	if (!$Me->timeReview($prow, null, $Conf))
	    echo "<input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines";

	echo "</td><td></td></tr>\n", tagg_cbox("cmt", true),
	    "</td></tr>\n</table>\n</div></form>\n\n";
    }

    function showResponse($prow, $crow, $useRequest, $editMode) {
	global $Conf, $ConfSiteSuffix, $Me, $forceShow, $linkExtra;

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
	    echo "$linkExtra&amp;response=1&amp;post=1' method='post' enctype='multipart/form-data' accept-charset='UTF-8'><div class='aahc'>\n";
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
	if ($editMode)
	    echo " class='editable'";
	echo ">Response</h3>";
	$this->commentIdentityTime($prow, $crow);

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
