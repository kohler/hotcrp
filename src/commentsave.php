<?php
// commentsave.php -- HotCRP helper class for saving comments
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CommentSave {

    static private $crow;

    static function watch_callback($prow, $minic) {
        $tmpl = (self::$crow->commentType & COMMENTTYPE_RESPONSE ? "@responsenotify" : "@commentnotify");
        if ($minic->canViewComment($prow, self::$crow, false))
            Mailer::send($tmpl, $prow, $minic, null, array("comment_row" => self::$crow));
    }

    static function save($req, $prow, $crow, $contact, $is_response) {
        global $Conf, $Now;
        if (is_array($req))
            $req = (object) $req;

        if ($is_response && @$req->submit)
            $ctype = COMMENTTYPE_RESPONSE | COMMENTTYPE_AUTHOR;
        else if ($is_response)
            $ctype = COMMENTTYPE_RESPONSE | COMMENTTYPE_AUTHOR | COMMENTTYPE_DRAFT;
        else if (@$req->visibility == "a")
            $ctype = COMMENTTYPE_AUTHOR;
        else if (@$req->visibility == "p")
            $ctype = COMMENTTYPE_PCONLY;
        else if (@$req->visibility == "admin")
            $ctype = COMMENTTYPE_ADMINONLY;
        else // $visibility == "r"
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
            $ctags = count($ctags) ? " " . join(" ", $ctags) . " " : null;
        } else
            $ctags = null;

        // backwards compatibility
        if ($Conf->sversion < 53) {
            $fora = ($ctype & COMMENTTYPE_RESPONSE ? 2
                     : ($ctype >= COMMENTTYPE_AUTHOR ? 1 : 0));
            $forr = ($ctype & COMMENTTYPE_DRAFT ? 0
                     : ($ctype < COMMENTTYPE_PCONLY ? 2
                        : ($ctype >= COMMENTTYPE_REVIEWER ? 1 : 0)));
            $blind = ($ctype & COMMENTTYPE_BLIND ? 1 : 0);
        }
        if ($Conf->sversion < 68)
            $ctags = null;

        $while = $insert_id_while = "while saving comment";

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
            $qa = "contactId, paperId, timeModified, comment, timeNotified, replyTo";
            $qb = "$contact->cid, $prow->paperId, $Now, '" . sqlq($text) . "', $Now, 0";
            if (!($ctype & (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT))
                && ($ctype & COMMENTTYPE_VISIBILITY) != COMMENTTYPE_ADMINONLY
                && $Conf->sversion >= 43) {
                $qa .= ", ordinal";
                $qb .= ", greatest(commentCount,maxOrdinal)+1";
            }
            if ($Conf->sversion >= 53) {
                $qa .= ", commentType";
                $qb .= ", $ctype";
            } else {
                $qa .= ", forAuthors, forReviewers, blind";
                $qb .= ", $fora, $forr, $blind";
            }
            if ($ctags !== null) {
                $qa .= ", commentTags";
                $qb .= ", '" . sqlq($ctags) . "'";
            }
            $q = "insert into PaperComment ($qa) select $qb\n";
            if ($ctype & COMMENTTYPE_RESPONSE) {
                // make sure there is exactly one response
                $q .= "	from (select Paper.paperId, coalesce(commentId,0) commentId, 0 commentCount, 0 maxOrdinal
		from Paper
		left join PaperComment on (PaperComment.paperId=Paper.paperId and ";
                if ($Conf->sversion >= 53)
                    $q .= "(commentType&" . COMMENTTYPE_RESPONSE . ")!=0";
                else
                    $q .= "forAuthors=2";
                $q .= ") where Paper.paperId=$prow->paperId group by Paper.paperId) t
	where t.commentId=0";
                $insert_id_while = false;
            } else {
                $q .= "	from (select Paper.paperId, coalesce(count(commentId),0) commentCount, coalesce(max(PaperComment.ordinal),0) maxOrdinal
		from Paper
		left join PaperComment on (PaperComment.paperId=Paper.paperId and ";
                if ($Conf->sversion >= 53) {
                    $q .= "(commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and ";
                    if ($ctype >= COMMENTTYPE_AUTHOR)
                        $q .= "commentType>=" . COMMENTTYPE_AUTHOR;
                    else
                        $q .= "commentType>=" . COMMENTTYPE_PCONLY . " and commentType<" . COMMENTTYPE_AUTHOR;
                } else
                    $q .= "forReviewers!=2 and forAuthors=$fora";
                $q .= ") where Paper.paperId=$prow->paperId group by Paper.paperId) t";
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
            $q = "update PaperComment set timeModified=$Now$qa, comment='" . sqlq($text) . "', ";
            if ($Conf->sversion >= 53)
                $q .= "commentType=$ctype";
            else
                $q .= "forReviewers=$forr, forAuthors=$fora, blind=$blind";
            if ($Conf->sversion >= 68)
                $q .= ", commentTags=" . ($ctags === null ? "NULL" : "'" . sqlq($ctags) . "'");
            $q .= " where commentId=$crow->commentId";
        }

        $result = $Conf->qe($q, $while);
        if (!$result)
            return false;

        // comment ID
        $cid = $crow ? $crow->commentId : $Conf->lastInsertId($insert_id_while);
        if (!$cid)
            return false;
        $Conf->log("Comment $cid " . ($text !== "" ? "saved" : "deleted"), $contact, $prow->paperId);
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
