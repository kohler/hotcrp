<?php
// commentinfo.php -- HotCRP helper class for comments
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CommentViewState {
    public $ordinals = array();
}

class CommentInfo {
    public $commentId = 0;
    public $commentType = COMMENTTYPE_REVIEWER;

    static private $watching;
    static private $visibility_map = array(COMMENTTYPE_ADMINONLY => "admin", COMMENTTYPE_PCONLY => "pc", COMMENTTYPE_REVIEWER => "rev", COMMENTTYPE_AUTHOR => "au");


    function __construct($x = null, $prow = null) {
        $this->merge(is_object($x) ? $x : null);
        $this->prow = $prow;
    }

    private function merge($x) {
        if ($x)
            foreach ($x as $k => $v)
                $this->$k = $v;
        $this->commentId = (int) @$this->commentId;
        $this->commentType = (int) @$this->commentType;
        $this->commentRound = (int) @$this->commentRound;
    }

    static public function fetch($result, $prow) {
        return $result ? $result->fetch_object("CommentInfo", array(null, $prow)) : null;
    }


    public static function echo_script($prow) {
        global $Conf, $Me;
        if (Ht::mark_stash("papercomment")) {
            $t = array("papercomment.commenttag_search_url=\"" . hoturl_raw("search", "q=cmt%3A%23\$") . "\"");
            if (!$prow->has_author($Me))
                $t[] = "papercomment.nonauthor=true";
            foreach ($Conf->resp_round_list() as $i => $rname) {
                $isuf = $i ? "_$i" : "";
                $wl = $Conf->setting("resp_words$isuf", 500);
                $j = array("words" => $wl);
                $ix = false;
                if ($Me->can_respond($prow, (object) array("commentType" => COMMENTTYPE_RESPONSE, "commentRound" => $i))) {
                    if ($i)
                        $ix = $Conf->message_html("resp_instrux_$i", array("wordlimit" => $wl));
                    if ($ix === false)
                        $ix = $Conf->message_html("resp_instrux", array("wordlimit" => $wl));
                    if ($ix !== false)
                        $j["instrux"] = $ix;
                }
                $t[] = "papercomment.set_resp_round(" . json_encode($i ? $rname : 1) . "," . json_encode($j) . ")";
            }
            $Conf->echoScript(join($t, ";"));
        }
    }

    private function _ordinal($viewstate) {
        if (($this->commentType & (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT))
            || $this->commentType < COMMENTTYPE_PCONLY)
            return null;
        $p = ($this->commentType >= COMMENTTYPE_AUTHOR ? "A" : "");
        if ($viewstate) {
            $last_ordinal = (int) @$viewstate->ordinals[$p];
            if (!@$this->ordinal)
                $this->ordinal = $last_ordinal + 1;
            $viewstate->ordinals[$p] = max($this->ordinal, $last_ordinal);
        }
        return @$this->ordinal ? $p . $this->ordinal : null;
    }

    private static function _user($x) {
        if (isset($x->reviewEmail))
            return (object) array("firstName" => @$x->reviewFirstName, "lastName" => @$x->reviewLastName, "email" => @$x->reviewEmail);
        else
            return $x;
    }

    public function user() {
        return self::_user($this);
    }

    public function unparse_json($contact, $viewstate = null) {
        global $Conf;
        if ($this->commentId && !$contact->can_view_comment($this->prow, $this, null))
            return false;

        // placeholder for new comment
        if (!$this->commentId) {
            if (!$contact->can_comment($this->prow, $this))
                return false;
            $cj = (object) array("is_new" => true, "editable" => true);
            if ($this->commentType & COMMENTTYPE_RESPONSE)
                $cj->response = $Conf->resp_round_name($this->commentRound);
            return $cj;
        }

        // otherwise, viewable comment
        $cj = (object) array("cid" => $this->commentId);
        if ($contact->can_comment($this->prow, $this))
            $cj->editable = true;
        $cj->ordinal = $this->_ordinal($viewstate);
        $cj->visibility = self::$visibility_map[$this->commentType & COMMENTTYPE_VISIBILITY];
        if ($this->commentType & COMMENTTYPE_BLIND)
            $cj->blind = true;
        if ($this->commentType & COMMENTTYPE_DRAFT)
            $cj->draft = true;
        if ($this->commentType & COMMENTTYPE_RESPONSE)
            $cj->response = $Conf->resp_round_name($this->commentRound);

        // tags
        if (@$this->commentTags) {
            $tagger = new Tagger;
            if (($tags = $tagger->viewable($this->commentTags)))
                $cj->tags = TagInfo::split($tags);
            if ($tags && ($cc = TagInfo::color_classes($tags)))
                $cj->color_classes = $cc;
        }

        // identity and time
        $idable = $contact->can_view_comment_identity($this->prow, $this, null);
        $idable_override = $idable || $contact->can_view_comment_identity($this->prow, $this, true);
        if ($idable || $idable_override) {
            $user = $this->user();
            $cj->author = Text::user_html($user);
            $cj->author_email = $user->email;
            if (!$idable)
                $cj->author_hidden = true;
        }
        if ($this->timeModified > 0) {
            $cj->modified_at = (int) $this->timeModified;
            $cj->modified_at_text = $Conf->printableTime($this->timeModified);
        }

        // text
        $cj->text = $this->comment;
        return $cj;
    }

    public function unparse_text($contact, $no_title = false) {
        global $Conf;
        $x = "===========================================================================\n";
        if (!($this->commentType & COMMENTTYPE_RESPONSE))
            $n = "Comment";
        else if (($rname = $Conf->resp_round_text($this->commentRound)))
            $n = "$rname Response";
        else
            $n = "Response";
        if ($contact->can_view_comment_identity($this->prow, $this, false))
            $n .= " by " . Text::user_text($this->user());
        $x .= str_pad($n, (int) (37.5 + strlen(UnicodeHelper::deaccent($n)) / 2), " ", STR_PAD_LEFT) . "\n";
        if (!$no_title)
            $x .= $this->prow->pretty_text_title();
        $x .= "---------------------------------------------------------------------------\n";
        return $x . $this->comment . "\n";
    }

    static public function unparse_flow_entry($crow, $contact, $trclass) {
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
        if ($contact->can_view_comment_identity($crow, $crow, false))
            $t .= " <span class='barsep'>·</span> <span class='hint'>comment by</span> " . Text::user_html(self::_user($crow));
        $t .= " <span class='barsep'>·</span> <span class='hint'>posted</span> " . $Conf->parseableTime($crow->timeModified, false);
        $t .= "</small><br /><a class='q'" . substr($a, 3)
            . ">" . htmlspecialchars($crow->shortComment);
        if (strlen($crow->shortComment) < strlen($crow->comment))
            $t .= "...";
        return $t . "</a></td></tr>";
    }


    public function save($req, $contact) {
        global $Conf, $Now;
        if (is_array($req))
            $req = (object) $req;
        list($Table, $LinkTable, $LinkColumn) = array($this->prow->comment_table_name(), $this->prow->table_name(), $this->prow->id_column());

        $is_response = !!($this->commentType & COMMENTTYPE_RESPONSE);
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
        else if ($this->commentId && !isset($req->visibility))
            $ctype = $this->commentType;
        else // $req->visibility == "r" || $req->visibility == "rev"
            $ctype = COMMENTTYPE_REVIEWER;
        if ($is_response ? $this->prow->blind : $Conf->is_review_blind(!!@$req->blind))
            $ctype |= COMMENTTYPE_BLIND;

        // tags
        if ($is_response) {
            $ctags = " response ";
            if (($rname = $Conf->resp_round_name($this->commentRound)) != "1")
                $ctags .= "{$rname}response ";
        } else if (@$req->tags
                 && preg_match_all(',\S+,', $req->tags, $m)) {
            $tagger = new Tagger($contact);
            $ctags = array();
            foreach ($m[0] as $text)
                if (($text = $tagger->check($text, Tagger::NOVALUE))
                    && !stri_ends_with($text, "response"))
                    $ctags[strtolower($text)] = $text;
            $tagger->sort($ctags);
            $ctags = count($ctags) ? " " . join(" ", $ctags) . " " : null;
        } else
            $ctags = null;

        // query
        $text = (string) @$req->text;
        $q = "";
        $qv = array();
        if ($text === "" && $this->commentId) {
            $change = true;
            $q = "delete from $Table where commentId=$this->commentId";
        } else if ($text === "")
            /* do nothing */;
        else if (!$this->commentId) {
            $change = true;
            $qa = array("contactId, $LinkColumn, commentType, comment, timeModified, timeNotified, replyTo");
            $qb = array("$contact->contactId, {$this->prow->$LinkColumn}, $ctype, ?, $Now, $Now, 0");
            $qv[] = $text;
            if ($ctags !== null) {
                $qa[] = "commentTags";
                $qb[] = "?";
                $qv[] = $ctags;
            }
            if ($is_response) {
                $qa[] = "commentRound";
                $qb[] = $this->commentRound;
            }
            $q = "insert into $Table (" . join(", ", $qa) . ") select " . join(", ", $qb) . "\n";
            if ($is_response) {
                // make sure there is exactly one response
                $q .= " from (select $LinkTable.$LinkColumn, coalesce(commentId, 0) commentId
                from $LinkTable
                left join $Table on ($Table.$LinkColumn=$LinkTable.$LinkColumn and (commentType&" . COMMENTTYPE_RESPONSE . ")!=0 and commentRound=$this->commentRound)
                where $LinkTable.$LinkColumn={$this->prow->$LinkColumn} group by $LinkTable.$LinkColumn) t
        where t.commentId=0";
            }
        } else {
            $change = ($this->commentType >= COMMENTTYPE_AUTHOR) != ($ctype >= COMMENTTYPE_AUTHOR);
            if ($this->timeModified >= $Now)
                $Now = $this->timeModified + 1;
            // do not notify on updates within 3 hours
            $qa = "";
            if ($this->timeNotified + 10800 < $Now
                || (($ctype & COMMENTTYPE_RESPONSE)
                    && !($ctype & COMMENTTYPE_DRAFT)
                    && ($this->commentType & COMMENTTYPE_DRAFT)))
                $qa = ", timeNotified=$Now";
            $q = "update $Table set timeModified=$Now$qa, commentType=$ctype, comment=?, commentTags=? where commentId=$this->commentId";
            $qv[] = $text;
            $qv[] = $ctags;
        }

        $result = Dbl::qe_apply($q, $qv);
        if (!$result)
            return false;
        $cmtid = $this->commentId ? : $result->insert_id;
        if (!$cmtid)
            return false;

        // log
        $contact->log_activity("Comment $cmtid " . ($text !== "" ? "saved" : "deleted"), $this->prow->$LinkColumn);

        // ordinal
        if ((!$this->commentId || !$this->ordinal
             || ($this->commentType >= COMMENTTYPE_AUTHOR) != ($ctype >= COMMENTTYPE_AUTHOR))
            && !($ctype & (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT))
            && ($ctype & COMMENTTYPE_VISIBILITY) != COMMENTTYPE_ADMINONLY
            && $text !== "") {
            $q = "update $Table,
	(select coalesce(count(commentId),0) commentCount,
		coalesce(max($Table.ordinal),0) maxOrdinal
	     from $LinkTable
	     left join $Table on ($Table.$LinkColumn=$LinkTable.$LinkColumn and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and ";
            if ($ctype >= COMMENTTYPE_AUTHOR)
                $q .= "commentType>=" . COMMENTTYPE_AUTHOR;
            else
                $q .= "commentType>=" . COMMENTTYPE_PCONLY . " and commentType<" . COMMENTTYPE_AUTHOR;
            $q .= " and commentId!=$cmtid)
	     where $LinkTable.$LinkColumn={$this->prow->$LinkColumn}
             group by $LinkTable.$LinkColumn) t
	set ordinal=greatest(t.commentCount+1,t.maxOrdinal+1)
	where commentId=$cmtid";
            Dbl::qe($q);
        }

        // reload
        if ($text !== "") {
            $comments = $this->prow->fetch_comments("commentId=$cmtid");
            $this->merge($comments[$cmtid]);
            if ($this->timeNotified == $this->timeModified) {
                self::$watching = $this;
                genericWatch($this->prow, WATCHTYPE_COMMENT, "CommentInfo::watch_callback", $contact);
                self::$watching = null;
            }
        } else {
            $this->commentId = 0;
            $this->comment = "";
            $this->commentTags = null;
        }

        return true;
    }

    static function watch_callback($prow, $minic) {
        $ctype = self::$watching->commentType;
        if (($ctype & COMMENTTYPE_RESPONSE) && ($ctype & COMMENTTYPE_DRAFT))
            $tmpl = "@responsedraftnotify";
        else if ($ctype & COMMENTTYPE_RESPONSE)
            $tmpl = "@responsenotify";
        else
            $tmpl = "@commentnotify";
        if ($minic->can_view_comment($prow, self::$watching, false)
            // Don't send notifications about draft responses to the chair,
            // even though the chair can see draft responses.
            && ($tmpl !== "@responsedraftnotify" || $minic->actAuthorView($prow)))
            HotCRPMailer::send_to($minic, $tmpl, $prow, array("comment_row" => self::$watching));
    }
}
