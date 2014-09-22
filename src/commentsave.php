<?php
// commentsave.php -- HotCRP helper class for saving comments
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CommentSave {

    static private $crow;

    static function watch_callback($prow, $minic) {
        $ctype = self::$crow->commentType;
        if (($ctype & COMMENTTYPE_RESPONSE) && ($ctype & COMMENTTYPE_DRAFT))
            $tmpl = "@responsedraftnotify";
        else if ($ctype & COMMENTTYPE_RESPONSE)
            $tmpl = "@responsenotify";
        else
            $tmpl = "@commentnotify";
        if ($minic->canViewComment($prow, self::$crow, false)
            // Don't send notifications about draft responses to the chair,
            // even though the chair can see draft responses.
            && ($tmpl !== "@responsedraftnotify" || $minic->actAuthorView($prow)))
            HotCRPMailer::send_to($minic, $tmpl, $prow, array("comment_row" => self::$crow));
    }

    static function save($req, $prow, $crow, $contact, $is_response) {
        global $Conf, $Now;
        if (is_array($req))
            $req = (object) $req;

        if ($is_response && @$req->submit)
            $ctype = COMMENTTYPE_RESPONSE | COMMENTTYPE_AUTHOR;
        else if ($is_response)
            $ctype = COMMENTTYPE_RESPONSE | COMMENTTYPE_AUTHOR | COMMENTTYPE_DRAFT;
        else if (@$req->visibility == "a" || @$req->visibility == "au")
            $ctype = COMMENTTYPE_AUTHOR;
        else if (@$req->visibility == "p" || @$req->visibility == "pc")
            $ctype = COMMENTTYPE_PCONLY;
        else if (@$req->visibility == "admin")
            $ctype = COMMENTTYPE_ADMINONLY;
        else // $req->visibility == "r" || $req->visibility == "rev"
            $ctype = COMMENTTYPE_REVIEWER;
        if ($is_response ? $prow->blind : $Conf->is_review_blind(!!@$req->blind))
            $ctype |= COMMENTTYPE_BLIND;

        // tags
        if ($is_response)
            $ctags = " response ";
        else if (@$req->tags
                 && preg_match_all(',\S+,', $req->tags, $m)) {
            $tagger = new Tagger($contact);
            $ctags = array();
            foreach ($m[0] as $text)
                if (($text = $tagger->check($text, Tagger::NOVALUE)))
                    $ctags[] = $text;
            $tagger->sort($ctags);
            $ctags = count($ctags) ? " " . join(" ", $ctags) . " " : null;
        } else
            $ctags = null;

        // backwards compatibility
        if ($Conf->sversion < 68)
            $ctags = null;

        // query
        $text = @$req->text;
        if ($text === false || $text === null)
            $text = "";
        if ($text === "" && $crow) {
            $change = true;
            $q = "delete from PaperComment where commentId=$crow->commentId";
        } else if ($text === "")
            /* do nothing */;
        else if (!$crow) {
            $change = true;
            $qa = "contactId, paperId, timeModified, comment, timeNotified, replyTo, commentType";
            $qb = "$contact->contactId, $prow->paperId, $Now, '" . sqlq($text) . "', $Now, 0, $ctype";
            if ($ctags !== null) {
                $qa .= ", commentTags";
                $qb .= ", '" . sqlq($ctags) . "'";
            }
            $q = "insert into PaperComment ($qa) select $qb\n";
            if ($ctype & COMMENTTYPE_RESPONSE) {
                // make sure there is exactly one response
                $q .= " from (select Paper.paperId, coalesce(commentId,0) commentId
                from Paper
                left join PaperComment on (PaperComment.paperId=Paper.paperId and (commentType&" . COMMENTTYPE_RESPONSE . ")!=0)
                where Paper.paperId=$prow->paperId group by Paper.paperId) t
        where t.commentId=0";
            }
        } else {
            $change = ($crow->commentType >= COMMENTTYPE_AUTHOR)
                != ($ctype >= COMMENTTYPE_AUTHOR);
            if ($crow->timeModified >= $Now)
                $Now = $crow->timeModified + 1;
            // do not notify on updates within 3 hours
            $qa = "";
            if ($crow->timeNotified + 10800 < $Now
                || (($ctype & COMMENTTYPE_RESPONSE)
                    && !($ctype & COMMENTTYPE_DRAFT)
                    && ($crow->commentType & COMMENTTYPE_DRAFT)))
                $qa = ", timeNotified=$Now";
            $q = "update PaperComment set timeModified=$Now$qa, comment='" . sqlq($text) . "', commentType=$ctype";
            if ($Conf->sversion >= 68)
                $q .= ", commentTags=" . ($ctags === null ? "NULL" : "'" . sqlq($ctags) . "'");
            $q .= " where commentId=$crow->commentId";
        }

        $result = Dbl::real_qe($q);
        if (!$result)
            return false;

        // comment ID
        $cid = $crow ? $crow->commentId : $result->insert_id;
        if (!$cid)
            return false;
        $contact->log_activity("Comment $cid " . ($text !== "" ? "saved" : "deleted"), $prow->paperId);
        // maybe adjust ordinal
        if ((!$crow || !$crow->ordinal
             || ($crow->commentType >= COMMENTTYPE_AUTHOR) != ($ctype >= COMMENTTYPE_AUTHOR))
            && !($ctype & (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT))
            && ($ctype & COMMENTTYPE_VISIBILITY) != COMMENTTYPE_ADMINONLY) {
            $q = "update PaperComment,
	(select coalesce(count(commentId),0) commentCount,
		coalesce(max(PaperComment.ordinal),0) maxOrdinal
	     from Paper
	     left join PaperComment on (PaperComment.paperId=Paper.paperId and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and ";
            if ($ctype >= COMMENTTYPE_AUTHOR)
                $q .= "commentType>=" . COMMENTTYPE_AUTHOR;
            else
                $q .= "commentType>=" . COMMENTTYPE_PCONLY . " and commentType<" . COMMENTTYPE_AUTHOR;
            $q .= " and commentId!=$cid)
	     where Paper.paperId=$prow->paperId group by Paper.paperId) t
	set ordinal=greatest(t.commentCount+1,t.maxOrdinal+1)
	where commentId=$cid";
            $Conf->qe($q);
        }
        if ($text !== "") {
            $crows = $Conf->comment_rows($Conf->comment_query("commentId=$cid"), $contact);
            if ((self::$crow = @$crows[$cid])
                && self::$crow->timeNotified == self::$crow->timeModified)
                genericWatch($prow, WATCHTYPE_COMMENT, "CommentSave::watch_callback", $contact);
            return self::$crow;
        } else
            return null;
    }

}
