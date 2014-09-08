<?php
// commentview.php -- HotCRP helper class for producing comment boxes
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CommentView {

    private $ordinals = array();
    private $tagger = null;
    private static $echoed = false;

    static private $visibility_map = array(COMMENTTYPE_ADMINONLY => "admin", COMMENTTYPE_PCONLY => "pc", COMMENTTYPE_REVIEWER => "rev", COMMENTTYPE_AUTHOR => "au");

    function __construct() {
    }

    public static function echo_script($prow) {
        global $Conf, $Me;
        if (Ht::mark_stash("papercomment")) {
            $t = array("papercomment.commenttag_search_url=\"" . hoturl_raw("search", "q=cmt%3A%23\$") . "\"");
            if ($Conf->timeAuthorRespond() || $Me->allowAdminister($prow)) {
                $wordlimit = $Conf->setting("resp_words", 500);
                if ($wordlimit > 0)
                    $t[] = "papercomment.resp_words=$wordlimit";
                if ($Me->canRespond($prow, null))
                    $t[] = "papercomment.responseinstructions=" . json_encode($Conf->message_html("responseinstructions", array("wordlimit" => $wordlimit)));
                if (!$prow->has_author($Me))
                    $t[] = "papercomment.nonauthor=true";
            }
            $Conf->echoScript(join($t, ";"));
        }
    }

    private function _commentOrdinal($prow, $crow) {
        if (($crow->commentType & (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT))
            || $crow->commentType < COMMENTTYPE_PCONLY)
            return null;
        if (isset($this->ordinals[$crow->commentId]))
            return $this->ordinals[$crow->commentId];
        $p = ($crow->commentType >= COMMENTTYPE_AUTHOR ? "A" : "");
        $stored_ordinal = defval($this->ordinals, $p, 0);
        if (isset($crow->ordinal) && $crow->ordinal)
            $n = $crow->ordinal;
        else
            $n = $stored_ordinal + 1;
        $this->ordinals[$p] = max($n, $stored_ordinal);
        return ($this->ordinals[$crow->commentId] = $p . $n);
    }

    private static function user($crow) {
        if (isset($crow->reviewEmail))
            return (object) array("firstName" => @$crow->reviewFirstName, "lastName" => @$crow->reviewLastName, "email" => @$crow->reviewEmail);
        else
            return $crow;
    }

    function json($prow, $crow, $response = false) {
        global $Conf, $Me;

        if ($crow && !isset($crow->commentType))
            setCommentType($crow);
        if ($crow && !$Me->canViewComment($prow, $crow, null))
            return false;

        // placeholder for new comment
        if (!$crow) {
            if (!($response ? $Me->canRespond($prow, $crow) : $Me->canComment($prow, $crow)))
                return false;
            $cj = (object) array("is_new" => true, "editable" => true);
            if ($response)
                $cj->response = true;
            return $cj;
        }

        // otherwise, viewable comment
        $cj = (object) array("cid" => (int) $crow->commentId);
        if ($Me->canComment($prow, $crow))
            $cj->editable = true;
        $cj->ordinal = $this->_commentOrdinal($prow, $crow);
        $cj->visibility = self::$visibility_map[$crow->commentType & COMMENTTYPE_VISIBILITY];
        if ($crow->commentType & COMMENTTYPE_BLIND)
            $cj->blind = true;
        if ($crow->commentType & COMMENTTYPE_DRAFT)
            $cj->draft = true;
        if ($crow->commentType & COMMENTTYPE_RESPONSE)
            $cj->response = true;

        // tags
        if (@$crow->commentTags) {
            if (!$this->tagger)
                $this->tagger = new Tagger;
            if (($tags = $this->tagger->viewable($crow->commentTags)))
                $cj->tags = Tagger::split($tags);
            if ($tags && ($cc = $this->tagger->color_classes($tags)))
                $cj->color_classes = $cc;
        }

        // identity and time
        $idable = $Me->canViewCommentIdentity($prow, $crow, null);
        $idable_override = $idable || $Me->canViewCommentIdentity($prow, $crow, true);
        if ($idable || $idable_override) {
            $user = self::user($crow);
            $cj->author = Text::user_html($user);
            $cj->author_email = $user->email;
            if (!$idable)
                $cj->author_hidden = true;
        }
        if ($crow->timeModified > 0) {
            $cj->modified_at = (int) $crow->timeModified;
            $cj->modified_at_text = $Conf->printableTime($crow->timeModified);
        }

        // text
        $cj->text = $crow->comment;
        return $cj;
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
            $t .= " &nbsp;<span class='barsep'>|</span>&nbsp; <span class='hint'>comment by</span> " . Text::user_html(self::user($crow));
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
        if ($contact->canViewCommentIdentity($prow, $crow, false))
            $n .= " by " . Text::user_text(self::user($crow));
        $x .= str_pad($n, (int) (37.5 + strlen(UnicodeHelper::deaccent($n)) / 2), " ", STR_PAD_LEFT) . "\n";
        $x .= ReviewForm::unparse_title_text($prow, $l);
        // $n = "Updated " . $Conf->printableTime($crow->timeModified);
        // $x .= str_pad($n, (int) (37.5 + strlen($n) / 2), " ", STR_PAD_LEFT) . "\n";
        $x .= "---------------------------------------------------------------------------\n";
        $x .= $crow->comment . "\n";
        return $x;
    }

}
