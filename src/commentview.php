<?php
// commentview.php -- HotCRP helper class for producing comment boxes
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CommentView {

    private $ncomment_in_table = 0;
    public $nresponse = 0;
    private $mode = 0;
    private $numbers = array();
    private $tagger = null;

    function __construct() {
    }

    function table_begin($classextra) {
        echo '<div class="cmtcard', ($classextra ? " $classextra" : ""),
            '"><div class="cmtcard_head">';
        $this->mode = 1;
        $this->ncomment_in_table = 0;
        $this->numbers = array();
    }

    function table_tobody() {
        echo '</div><div class="cmtcard_body">';
    }

    function table_end() {
        if ($this->mode) {
            echo "</div></div>\n\n";
            $this->mode = 0;
        }
    }

    private function _commentOrdinal($prow, $crow) {
        if (($crow->commentType & (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT))
            || $crow->commentType < COMMENTTYPE_PCONLY)
            return null;
        $p = ($crow->commentType >= COMMENTTYPE_AUTHOR ? "A" : "");
        $stored_number = defval($this->numbers, $p, 0);
        if (isset($crow->ordinal) && $crow->ordinal)
            $n = $crow->ordinal;
        else
            $n = $stored_number + 1;
        $this->numbers[$p] = max($n, $stored_number);
        return $p . $n;
    }

    private function _commentIdentityTime($prow, $crow, $cmttags, $response) {
        global $Conf, $Me;
        $cmtfn = array();
        $cmtvis = "";
        if ($crow && ($number = $this->_commentOrdinal($prow, $crow)))
            $cmtfn[] = "<span class='cmtnumhead'><a class='qq' href='#comment"
                . $crow->commentId . "'><span class='cmtnumat'>@</span>"
                . "<span class='cmtnumnum'>" . $number . "</span></a></span>";
        if ($crow && $Me->canViewCommentIdentity($prow, $crow, null)) {
            $blind = ($crow->commentType & COMMENTTYPE_BLIND)
                && $crow->commentType >= COMMENTTYPE_AUTHOR;
            $cmtfn[] = "<span class='cmtname'>" . ($blind ? "[" : "")
                . Text::user_html($crow) . ($blind ? "]" : "") . "</span>";
        } else if ($crow && $Me->allowAdminister($prow))
            $cmtfn[] = "<span id='foldcid$crow->commentId' class='cmtname fold4c'>"
                . "<a class='q fn4' href=\"javascript:void fold('cid$crow->commentId', 0, 4)\" title='Show author'>+&nbsp;<i>Hidden for blind review</i></a>"
                . "<span class='fx4'><a class='fx4' href=\"javascript:void fold('cid$crow->commentId', 1, 4)\" title='Hide author'>[blind]</a> " . Text::user_html($crow) . "</span>"
                . "</span>";
        if ($crow && $crow->timeModified > 0)
            $cmtfn[] = "<span class='cmttime'>" . $Conf->printableTime($crow->timeModified) . "</span>";
        if ($crow && !$response
            && (!$prow->has_conflict($Me) || $Me->canAdminister($prow))) {
            if ($cmttags) {
                $t = array();
                foreach (explode(" ", $this->tagger->unparse($cmttags)) as $tag)
                    $t[] = "<a href=\"" . hoturl("search", "q=" . urlencode("cmt:#$tag")) . "\" class=\"qq\">#$tag</a>";
                $cmtfn[] = join(" ", $t);
            }
            if ($crow->commentType >= COMMENTTYPE_AUTHOR)
                $x = "";
            else if ($crow->commentType >= COMMENTTYPE_REVIEWER)
                $x = "hidden from authors";
            else if ($crow->commentType >= COMMENTTYPE_PCONLY)
                $x = "shown only to PC reviewers";
            else
                $x = "shown only to administrators";
            if ($x)
                $cmtvis = "<span class='cmtvis'>($x)</span>";
        }
        echo '<span class="cmtfn">', join(' <span class="barsep">&nbsp;|&nbsp;</span> ', $cmtfn), '</span>',
            $cmtvis, '<div class="clear"></div>';
    }

    function show($prow, $crow, $useRequest, $editMode, $foldnew = true) {
        global $Conf, $Me;

        if ($crow)
            setCommentType($crow);
        if ($crow && ($crow->commentType & COMMENTTYPE_RESPONSE))
            return $this->showResponse($prow, $crow, $useRequest, $editMode);

        if ($crow && !$Me->canViewComment($prow, $crow, null))
            return;
        if ($editMode && !$Me->canComment($prow, $crow))
            $editMode = false;
        if ($crow && @$crow->commentTags && !$this->tagger)
            $this->tagger = new Tagger;
        $cmttags = "";
        if ($crow && @$crow->commentTags)
            $cmttags = $this->tagger->viewable($crow->commentTags);

        if ($this->mode != 1) {
            $this->table_begin("");
            echo "<h3>Comments</h3>";
            $this->table_tobody();
        }
        $this->ncomment_in_table++;

        echo "<div";
        if ($crow)
            echo " id=\"comment$crow->commentId\"";
        else
            echo " id=\"commentnew\"";
        echo ' class="cmtg">';
        $opendiv = "";
        if ($crow && !$editMode) {
            if (($crow->commentType & COMMENTTYPE_VISIBILITY) == COMMENTTYPE_ADMINONLY)
                echo ($opendiv = '<div class="cmtadminvis">');
            else if ($cmttags && ($colors = $this->tagger->color_classes($cmttags)))
                echo ($opendiv = '<div class="cmtcolor ' . $colors . '">');
        } else if ($editMode) {
            echo Ht::form(hoturl_post("comment", "p=$prow->paperId&amp;c=" . ($crow ? $crow->commentId : "new")));
            if (!$crow && $foldnew)
                echo '<div class="aahc foldc" id="foldaddcomment">';
            else
                echo '<div class="aahc">';
            echo Ht::hidden("anchor", $crow ? "comment$crow->commentId" : "commentnew");
        }
        echo "<div class='cmtt'>";

        // Links
        if ($crow && ($crow->contactId == $Me->contactId
                      || $Me->allowAdminister($prow))
            && !$editMode && $Me->canComment($prow, $crow))
            echo '<div class="floatright">',
                '<a href="', hoturl("paper", "p=$prow->paperId&amp;c=$crow->commentId#comment$crow->commentId"), '" class="xx editor">',
                '<u>Edit</u></a></div>';

        if (!$crow) {
            if ($editMode && $foldnew)
                echo "<h3><a class='q fn' href=\"",
                    selfHref(array("c" => "new")),
                    "\" onclick='return open_new_comment(1)'>+&nbsp;Add Comment</a><span class='fx'>Add Comment</span></h3>";
            else
                echo "<h3>Add Comment</h3>";
            $Conf->footerScript("hotcrp_load('opencomment')");
        }
        $this->_commentIdentityTime($prow, $crow, $cmttags, false);

        if ($crow && $editMode && $crow->contactId != $Me->contactId)
            echo "<div class='hint'>You didn’t write this comment, but as an administrator you can still make changes.</div>\n";

        echo "</div><div class='cmtv", (!$crow && $editMode && $foldnew ? " fx" : ""), "'>";

        $cmsgs = $Conf->session("comment_msgs");
        if ($crow && $cmsgs && isset($cmsgs[$crow->commentId]))
            echo $cmsgs[$crow->commentId];

        if (!$editMode) {
            echo '<div class="cmttext">',
                link_urls(htmlspecialchars($crow->comment)),
                '</div></div>', ($opendiv ? "</div>" : ""), "</div>\n\n";
            return;
        }

        // From here on, edit mode.
        // form body
        echo "<textarea name='comment' class='reviewtext' rows='5' cols='60' onchange='hiliter(this)'>";
        if ($useRequest)
            echo htmlspecialchars(defval($_REQUEST, 'comment'));
        else if ($crow)
            echo htmlspecialchars($crow->comment);
        echo "</textarea>\n  <div class='g'></div>\n";
        // tags
        if ($Conf->sversion >= 68) {
            $cmtedittags = "";
            if ($crow && @$crow->commentTags)
                $cmtedittags = $this->tagger->unparse($this->tagger->editable($crow->commentTags));
            echo "<table style=\"float:right\"><tr><td>Tags: &nbsp; </td>
  <td>", Ht::entry("commenttags", $cmtedittags, array("size" => 40, "onchange" => "hiliter(this)", "tabindex" => 1)), "</td></tr></table>";
        }
        // visibility
        echo "<table><tr><td>Show to: &nbsp; </td>
    <td><table id='foldcmtvis' class='fold2o'>";
        $ctype = $crow ? $crow->commentType : COMMENTTYPE_REVIEWER | COMMENTTYPE_BLIND;
        echo "<tr><td>", Ht::radio_h("visibility", "a", ($useRequest ? defval($_REQUEST, "visibility") == "a" : $ctype >= COMMENTTYPE_AUTHOR), array("id" => "cmtvis_a", "onchange" => "docmtvis(this)", "tabindex" => 1)),
            "&nbsp;</td><td>", Ht::label("Authors and reviewers" . ($Conf->review_blindness() == Conference::BLIND_ALWAYS ? " (anonymous to authors)" : ""));
        // blind?
        if ($Conf->review_blindness() == Conference::BLIND_OPTIONAL)
            echo " &nbsp; (",
                Ht::checkbox_h("blind", 1, ($useRequest ? defval($_REQUEST, "blind") : $ctype & COMMENTTYPE_BLIND), array("tabindex" => 1)),
                "&nbsp;", Ht::label("Anonymous to authors"), ")";
        if ($Conf->timeAuthorViewReviews())
            echo "<br /><span class='fx2 hint'>Authors will be notified immediately.</span>";
        else
            echo "<br /><span class='fx2 hint'>Authors cannot view comments at the moment.</span>";
        echo "</td></tr>\n";
        echo "<tr><td>", Ht::radio_h("visibility", "r", ($useRequest ? defval($_REQUEST, "visibility") == "r" : ($ctype & COMMENTTYPE_VISIBILITY) == COMMENTTYPE_REVIEWER), array("onchange" => "docmtvis(this)", "tabindex" => 1)),
            "&nbsp;</td><td>", Ht::label("PC and external reviewers"), "</td></tr>\n";
        echo "<tr><td>", Ht::radio_h("visibility", "p", ($useRequest ? defval($_REQUEST, "visibility") == "p" : ($ctype & COMMENTTYPE_VISIBILITY) == COMMENTTYPE_PCONLY), array("onchange" => "docmtvis(this)", "tabindex" => 1)),
            "&nbsp;</td><td>", Ht::label("PC reviewers only"), "</td></tr>\n";
        echo "<tr><td>", Ht::radio_h("visibility", "admin", ($useRequest ? defval($_REQUEST, "visibility") == "admin" : ($ctype & COMMENTTYPE_VISIBILITY) == COMMENTTYPE_ADMINONLY), array("onchange" => "docmtvis(this)", "tabindex" => 1)),
            "&nbsp;</td><td>", Ht::label("Administrators only"), "</td></tr>\n";
        echo "</table></td></tr></table>\n";
        $Conf->footerScript("docmtvis(false)");

        // actions
        echo "<div class=\"clear\"></div>\n";
        $buttons = array();
        $buttons[] = Ht::submit("submit", "Save", array("class" => "bb"));
        if ($crow) {
            $buttons[] = Ht::submit("cancel", "Cancel");
            $buttons[] = "";
            $buttons[] = Ht::submit("delete", "Delete comment");
        } else
            $buttons[] = Ht::js_button("Cancel", "cancel_comment()");
        $post = "";
        if (!$Me->timeReview($prow, null))
            $post = Ht::checkbox("override") . "&nbsp;" . Ht::label("Override&nbsp;deadlines");
        echo Ht::actions($buttons, null, $post);

        echo "</div></div></form></div>\n\n";
    }

    function showResponse($prow, $crow, $useRequest, $editMode) {
        global $Conf, $Me;

        if ($editMode && !$Me->canRespond($prow, $crow)
            && ($crow || !$Me->allowAdminister($prow)))
            $editMode = false;
        if (!$crow && !$editMode)
            return;

        $this->nresponse++;
        $this->table_end();

        if ($editMode) {
            echo Ht::form(hoturl_post("comment", "p=$prow->paperId" . ($crow ? "&amp;c=$crow->commentId" : "") . "&amp;response=1")),
                '<div class="aahc">', Ht::hidden("anchor", "comment$crow->commentId");
        }

        $this->table_begin("response");

        // Links
        if ($crow && ($prow->has_author($Me)
                      || $Me->allowAdminister($prow))
            && !$editMode && $Me->canRespond($prow, $crow))
            echo "<div class='floatright'>",
                "<a href='", hoturl("paper", "p=$prow->paperId&amp;c=$crow->commentId#comment$crow->commentId") . "' class='xx'>",
                Ht::img("edit.png", "[Edit]", "b"),
                "&nbsp;<u>Edit</u></a></div>";

        echo "<h3";
        if ($crow)
            echo " id='comment$crow->commentId'";
        else
            echo " id='response'";
        echo ">Response</h3>";
        $this->_commentIdentityTime($prow, $crow, " response ", true);

        $this->table_tobody();

        $cmsgs = $Conf->session("comment_msgs");
        if ($crow && $cmsgs && isset($cmsgs[$crow->commentId]))
            echo $cmsgs[$crow->commentId];
        else if ($editMode && $crow && ($crow->commentType & COMMENTTYPE_DRAFT))
            echo "<div class='xwarning'>This is a draft response. Reviewers won’t see it until you submit.</div>";

        if (!$editMode) {
            echo '<div class="cmtg">';
            if ($Me->allowAdminister($prow)
                && ($crow->commentType & COMMENTTYPE_DRAFT))
                echo "<i>The <a href='", hoturl("comment", "c=$crow->commentId"), "'>authors’ response</a> is not yet ready for reviewers to view.</i>";
            else if (!$Me->canViewComment($prow, $crow, null))
                echo "<i>The authors’ response is not yet ready for reviewers to view.</i>";
            else
                echo htmlWrapText(htmlspecialchars($crow->comment));
            echo "</div>";
            $this->table_end();
            return;
        }

        $wordlimit = $Conf->setting("resp_words", 500);
        echo '<div class="hint">',
            $Conf->message_html("responseinstructions", array("wordlimit" => $wordlimit)),
            "</div>\n";
        if (!$prow->has_author($Me))
            echo "<div class='hint'>Although you aren’t a contact for this paper, as an administrator you can edit the authors’ response.</div>\n";

        // From here on, edit mode.
        // form body
        if ($useRequest)
            $ctext = defval($_REQUEST, "comment", "");
        else
            $ctext = ($crow ? $crow->comment : "");
        echo "<textarea id='responsetext' name='comment' class='reviewtext' rows='5' cols='60' onchange='hiliter(this)'>", htmlspecialchars($ctext), "</textarea>\n  ";

        // actions
        $buttons = array();
        $buttons[] = Ht::submit("submitresponse", "Submit", array("class" => "bb"));
        if ($Me->allowAdminister($prow) || !$crow
            || ($crow->commentType & COMMENTTYPE_DRAFT))
            $buttons[] = Ht::submit("savedraft", "Save as draft");
        if ($crow) {
            $buttons[] = "";
            $buttons[] = Ht::submit("delete", "Delete response");
        }
        if ($wordlimit > 0) {
            $buttons[] = "";
            $wc = preg_match_all("/[^\\pZ\\s]+/u", $ctext, $cm);
            $wct = ($wordlimit < $wc ? plural($wc - $wordlimit, "word") . " over" : plural($wordlimit - $wc, "word") . " left");
            $buttons[] = "<div id='responsewc' class='words"
                . ($wordlimit < $wc ? " wordsover" :
                   ($wordlimit * 0.9 < $wc ? " wordsclose" : "")) . "'>"
                . $wct . "</div>";
            $Conf->footerScript("set_response_wc(\"responsetext\",\"responsewc\",$wordlimit)");
        }
        $post = "";
        if (!$Conf->timeAuthorRespond())
            $post = Ht::checkbox("override") . "&nbsp;" . Ht::label("Override&nbsp;deadlines");
        echo Ht::actions($buttons, null, $post);

        $this->table_end();
        echo "</div></form>\n\n";
    }


    static function commentFlowEntry($contact, $crow, $trclass) {
        // See also ReviewForm::reviewFlowEntry
        global $Conf;
        $a = "<a href=\"" . hoturl("paper", "p=$crow->paperId#comment$crow->commentId") . "\"";
        $t = "<tr class='$trclass'><td class='pl_activityicon'>" . $a . ">"
            . Ht::img("comment24.png", "[Comment]", "dlimg")
            . "</a></td><td class='pl_activityid pnum'>"
            . $a . ">#$crow->paperId</a></td><td class='pl_activitymain'><small>"
            . $a . " class=\"ptitle\">" . htmlspecialchars($crow->shortTitle);
        if (strlen($crow->shortTitle) != strlen($crow->title))
            $t .= "...";
        $t .= "</a>";
        if ($contact->canViewCommentIdentity($crow, $crow, false))
            $t .= " &nbsp;<span class='barsep'>|</span>&nbsp; <span class='hint'>comment by</span> " . Text::user_html($crow->reviewFirstName, $crow->reviewLastName, $crow->reviewEmail);
        $t .= " &nbsp;<span class='barsep'>|</span>&nbsp; <span class='hint'>posted</span> " . $Conf->parseableTime($crow->timeModified, false);
        $t .= "</small><br /><a class='q'" . substr($a, 3)
            . ">" . htmlspecialchars($crow->shortComment);
        if (strlen($crow->shortComment) < strlen($crow->comment))
            $t .= "...";
        return $t . "</a></td></tr>";
    }


    static function unparse_text($prow, $crow, $contact) {
        global $Conf;

        $x = "===========================================================================\n";
        $n = ($crow->commentType & COMMENTTYPE_RESPONSE ? "Response" : "Comment");
        if ($contact->canViewCommentIdentity($prow, $crow, false)) {
            $n .= " by ";
            if (isset($crow->reviewFirstName))
                $n .= Text::user_text($crow->reviewFirstName, $crow->reviewLastName, $crow->reviewEmail);
            else
                $n .= Text::user_text($crow);
        }
        $x .= str_pad($n, (int) (37.5 + strlen(UnicodeHelper::deaccent($n)) / 2), " ", STR_PAD_LEFT) . "\n";
        $x .= ReviewForm::unparse_title_text($prow, $l);
        // $n = "Updated " . $Conf->printableTime($crow->timeModified);
        // $x .= str_pad($n, (int) (37.5 + strlen($n) / 2), " ", STR_PAD_LEFT) . "\n";
        $x .= "---------------------------------------------------------------------------\n";
        $x .= $crow->comment . "\n";
        return $x;
    }

}
