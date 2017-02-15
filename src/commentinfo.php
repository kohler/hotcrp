<?php
// commentinfo.php -- HotCRP helper class for comments
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CommentInfo {
    public $conf;
    public $prow;
    public $commentId = 0;
    public $paperId;
    public $timeModified;
    public $timeNotified;
    public $timeDisplayed;
    public $comment;
    public $commentType = COMMENTTYPE_REVIEWER;
    public $replyTo;
    public $paperStorageId;
    public $ordinal;
    public $authorOrdinal;
    public $commentTags;
    public $commentRound;
    public $commentFormat;
    public $commentOverflow;

    static private $watching;
    static private $visibility_map = array(COMMENTTYPE_ADMINONLY => "admin", COMMENTTYPE_PCONLY => "pc", COMMENTTYPE_REVIEWER => "rev", COMMENTTYPE_AUTHOR => "au");


    function __construct($x, PaperInfo $prow = null, Conf $conf = null) {
        $this->merge(is_object($x) ? $x : null, $prow, $conf);
    }

    private function merge($x, PaperInfo $prow = null, Conf $conf = null) {
        assert(($prow || $conf) && (!$prow || !$conf || $prow->conf === $conf));
        $this->conf = $prow ? $prow->conf : $conf;
        $this->prow = $prow;
        if ($x)
            foreach ($x as $k => $v)
                $this->$k = $v;
        $this->commentId = (int) $this->commentId;
        $this->paperId = (int) $this->paperId;
        $this->commentType = (int) $this->commentType;
        $this->commentRound = (int) $this->commentRound;
        if ($this->conf->sversion < 107 && $this->commentType >= COMMENTTYPE_AUTHOR)
            $this->authorOrdinal = $this->ordinal;
    }

    static function fetch($result, PaperInfo $prow = null, Conf $conf = null) {
        $cinfo = null;
        if ($result)
            $cinfo = $result->fetch_object("CommentInfo", [null, $prow, $conf]);
        if ($cinfo && !is_int($cinfo->commentId))
            $cinfo->merge(null, $prow, $conf);
        return $cinfo;
    }

    function set_prow(PaperInfo $prow) {
        assert(!$this->prow && $this->paperId === $prow->paperId && $this->conf === $prow->conf);
        $this->prow = $prow;
    }


    static function echo_script($prow) {
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
                $t[] = "papercomment.set_resp_round(" . json_encode($rname) . "," . json_encode($j) . ")";
            }
            echo Ht::unstash_script(join($t, ";"));
        }
    }

    static private function commenttype_needs_ordinal($ctype) {
        return !($ctype & (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT))
            && ($ctype & COMMENTTYPE_VISIBILITY) != COMMENTTYPE_ADMINONLY;
    }

    private function ordinal_missing($ctype) {
        return self::commenttype_needs_ordinal($ctype)
            && !($ctype >= COMMENTTYPE_AUTHOR ? $this->authorOrdinal : $this->ordinal);
    }

    function unparse_ordinal() {
        $is_author = $this->commentType >= COMMENTTYPE_AUTHOR;
        $o = $is_author ? $this->authorOrdinal : $this->ordinal;
        if (self::commenttype_needs_ordinal($this->commentType) && $o)
            return ($is_author ? "A" : "") . $o;
        else
            return null;
    }

    static function unparse_html_id($cr) {
        global $Conf;
        $is_author = $cr->commentType >= COMMENTTYPE_AUTHOR;
        $o = $is_author ? $cr->authorOrdinal : $cr->ordinal;
        if (self::commenttype_needs_ordinal($cr->commentType) && $o)
            return ($is_author ? "cA" : "c") . $o;
        else if ($cr->commentType & COMMENTTYPE_RESPONSE)
            return $Conf->resp_round_text($cr->commentRound) . "response";
        else
            return "cx" . $cr->commentId;
    }

    private static function _user($x) {
        if (isset($x->reviewEmail))
            return (object) ["firstName" => get($x, "reviewFirstName"), "lastName" => get($x, "reviewLastName"), "email" => $x->reviewEmail];
        else
            return $x;
    }

    function user() {
        return self::_user($this);
    }

    function unparse_response_text() {
        if ($this->commentType & COMMENTTYPE_RESPONSE) {
            $rname = $this->conf->resp_round_name($this->commentRound);
            $t = $rname == "1" ? "Response" : "$rname Response";
            if ($this->commentType & COMMENTTYPE_DRAFT)
                $t = "Draft $t";
            return $t;
        } else
            return null;
    }

    static function group_by_identity($crows, Contact $user, $separateColors,
                                      $forceShow = null) {
        $known_cids = $result = [];
        foreach ($crows as $cr) {
            $open = $user->can_view_comment_identity($cr->prow, $cr, $forceShow);
            $cid = $open ? $cr->contactId : 0;
            if ($cr->commentType & COMMENTTYPE_RESPONSE) {
                if (!empty($result))
                    $result[count($result) - 1][2] = ";";
                $connector = ";";
                $known_cids = [];
                $include = true;
                $record = false;
            } else {
                $connector = ",";
                $include = !isset($known_cids[$cid]);
                $record = true;
            }
            if ($separateColors
                && ($tags = $cr->viewable_tags($user, $forceShow))
                && ($color = $cr->conf->tags()->color_classes($tags))) {
                $include = true;
                $record = false;
            }
            if ($include)
                $result[] = [$cr, 1, $connector];
            else
                ++$result[$known_cids[$cid]][1];
            if ($record)
                $known_cids[$cid] = count($result) - 1;
        }
        if (!empty($result))
            $result[count($result) - 1][2] = "";
        return $result;
    }

    function unparse_user_html(Contact $user, $forceShow = null) {
        if ($user->can_view_comment_identity($this->prow, $this, $forceShow))
            $n = Text::abbrevname_html($this->user());
        else
            $n = "anonymous";
        if ($this->commentType & COMMENTTYPE_RESPONSE)
            $n = "<i>" . $this->unparse_response_text() . "</i>"
                . ($n === "anonymous" ? "" : " ($n)");
        return $n;
    }

    function unparse_user_text(Contact $user, $forceShow = null) {
        if ($user->can_view_comment_identity($this->prow, $this, $forceShow))
            $n = Text::abbrevname_text($this->user());
        else
            $n = "anonymous";
        if ($this->commentType & COMMENTTYPE_RESPONSE)
            $n = $this->unparse_response_text()
                . ($n === "anonymous" ? "" : " ($n)");
        return $n;
    }

    function viewable_tags(Contact $user, $forceShow = null) {
        if ($this->commentTags
            && $user->can_view_comment_tags($this->prow, $this, null))
            return Tagger::strip_nonviewable($this->commentTags, $user);
        else
            return null;
    }

    function unparse_json($contact, $include_displayed_at = false) {
        if ($this->commentId && !$contact->can_view_comment($this->prow, $this, null))
            return false;

        // placeholder for new comment
        if (!$this->commentId) {
            if (!$contact->can_comment($this->prow, $this))
                return false;
            $cj = (object) array("pid" => $this->prow->paperId, "is_new" => true, "editable" => true);
            if ($this->commentType & COMMENTTYPE_RESPONSE)
                $cj->response = $this->conf->resp_round_name($this->commentRound);
            return $cj;
        }

        // otherwise, viewable comment
        $cj = (object) array("pid" => $this->prow->paperId, "cid" => $this->commentId);
        $cj->ordinal = $this->unparse_ordinal();
        $cj->visibility = self::$visibility_map[$this->commentType & COMMENTTYPE_VISIBILITY];
        if ($this->commentType & COMMENTTYPE_BLIND)
            $cj->blind = true;
        if ($this->commentType & COMMENTTYPE_DRAFT)
            $cj->draft = true;
        if ($this->commentType & COMMENTTYPE_RESPONSE)
            $cj->response = $this->conf->resp_round_name($this->commentRound);
        if ($contact->can_comment($this->prow, $this))
            $cj->editable = true;

        // tags
        if (($tags = $this->viewable_tags($contact))) {
            $cj->tags = TagInfo::split($tags);
            if ($tags && ($cc = $this->conf->tags()->color_classes($tags)))
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
        if ($this->timeModified > 0 && $idable_override) {
            $cj->modified_at = (int) $this->timeModified;
            $cj->modified_at_text = $this->conf->printableTime($cj->modified_at);
        } else if ($this->timeModified > 0) {
            $cj->modified_at = $this->conf->obscure_time($this->timeModified);
            $cj->modified_at_text = $this->conf->unparse_time_obscure($cj->modified_at);
            $cj->modified_at_obscured = true;
        }
        if ($include_displayed_at)
            // XXX exposes information, should hide before export
            $cj->displayed_at = (int) $this->timeDisplayed;

        // text
        $cj->text = $this->commentOverflow ? : $this->comment;

        // format
        if (($fmt = $this->commentFormat) === null)
            $fmt = $this->conf->default_format;
        if ($fmt)
            $cj->format = (int) $fmt;
        return $cj;
    }

    function unparse_text($contact, $no_title = false) {
        $x = "===========================================================================\n";
        if (!($this->commentType & COMMENTTYPE_RESPONSE))
            $n = "Comment";
        else if (($rname = $this->conf->resp_round_text($this->commentRound)))
            $n = "$rname Response";
        else
            $n = "Response";
        if ($contact->can_view_comment_identity($this->prow, $this, false))
            $n .= " by " . Text::user_text($this->user());
        $x .= center_word_wrap($n);
        if (!$no_title)
            $x .= $this->prow->pretty_text_title();
        if (($tags = $this->viewable_tags($contact))) {
            $tagger = new Tagger($contact);
            $x .= center_word_wrap($tagger->unparse_hashed($tags));
        }
        $x .= "---------------------------------------------------------------------------\n";
        if ($this->commentOverflow)
            $x .= $this->commentOverflow;
        else
            $x .= $this->comment;
        return $x . "\n";
    }

    static function unparse_flow_entry(Contact $contact, $crow) {
        // See also ReviewForm::reviewFlowEntry
        global $Conf;
        $a = "<a href=\"" . hoturl("paper", "p=$crow->paperId#" . self::unparse_html_id($crow)) . "\"";
        $t = '<tr class="pl"><td class="pl_activityicon">' . $a . ">"
            . Ht::img("comment48.png", "[Comment]", ["class" => "dlimg", "width" => 24, "height" => 24])
            . '</a></td><td class="pl_activityid pl_rowclick">'
            . $a . ' class="pnum">#' . $crow->paperId . '</a></td>'
            . '<td class="pl_activitymain pl_rowclick"><small>'
            . $a . ' class="ptitle">'
            . htmlspecialchars(UnicodeHelper::utf8_abbreviate($crow->title, 80))
            . "</a>";
        $idable = $contact->can_view_comment_identity($crow, $crow, false);
        if ($idable || $contact->can_view_comment_time($crow, $crow))
            $time = $Conf->parseableTime($crow->timeModified, false);
        else
            $time = $Conf->unparse_time_obscure($Conf->obscure_time($crow->timeModified));
        $t .= ' <span class="barsep">·</span> ' . $time;
        if ($idable)
            $t .= ' <span class="barsep">·</span> <span class="hint">comment by</span> ' . Text::user_html(self::_user($crow));
        return $t . "</small><br />"
            . htmlspecialchars(UnicodeHelper::utf8_abbreviate($crow->commentOverflow ? : $crow->comment, 300))
            . "</td></tr>";
    }


    private function save_ordinal($cmtid, $ctype, $Table, $LinkTable, $LinkColumn) {
        $okey = "ordinal";
        if ($ctype >= COMMENTTYPE_AUTHOR && $this->conf->sversion >= 107)
            $okey = "authorOrdinal";
        $q = "update $Table, (select coalesce(max($Table.$okey),0) maxOrdinal
    from $LinkTable
    left join $Table on ($Table.$LinkColumn=$LinkTable.$LinkColumn)
    where $LinkTable.$LinkColumn={$this->prow->$LinkColumn}
    group by $LinkTable.$LinkColumn) t
set $okey=(t.maxOrdinal+1) where commentId=$cmtid";
        Dbl::qe($q);
    }

    function save($req, $contact) {
        global $Now;
        if (is_array($req))
            $req = (object) $req;
        $Table = $this->prow->comment_table_name();
        $LinkTable = $this->prow->table_name();
        $LinkColumn = $this->prow->id_column();
        $req_visibility = get($req, "visibility");

        $is_response = !!($this->commentType & COMMENTTYPE_RESPONSE);
        if ($is_response && get($req, "submit"))
            $ctype = COMMENTTYPE_RESPONSE | COMMENTTYPE_AUTHOR;
        else if ($is_response)
            $ctype = COMMENTTYPE_RESPONSE | COMMENTTYPE_AUTHOR | COMMENTTYPE_DRAFT;
        else if ($req_visibility == "a" || $req_visibility == "au")
            $ctype = COMMENTTYPE_AUTHOR;
        else if ($req_visibility == "p" || $req_visibility == "pc")
            $ctype = COMMENTTYPE_PCONLY;
        else if ($req_visibility == "admin")
            $ctype = COMMENTTYPE_ADMINONLY;
        else if ($this->commentId && $req_visibility === null)
            $ctype = $this->commentType;
        else // $req->visibility == "r" || $req->visibility == "rev"
            $ctype = COMMENTTYPE_REVIEWER;
        if ($is_response ? $this->prow->blind : $this->conf->is_review_blind(!!get($req, "blind")))
            $ctype |= COMMENTTYPE_BLIND;

        // tags
        if ($is_response) {
            $ctags = " response ";
            if (($rname = $this->conf->resp_round_name($this->commentRound)) != "1")
                $ctags .= "{$rname}response ";
        } else if (get($req, "tags")
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

        // notifications
        $displayed = !($ctype & COMMENTTYPE_DRAFT);

        // query
        $text = get_s($req, "text");
        $q = "";
        $qv = array();
        if ($text === "" && $this->commentId) {
            $change = true;
            $q = "delete from $Table where commentId=$this->commentId";
        } else if ($text === "")
            /* do nothing */;
        else if (!$this->commentId) {
            $change = true;
            $qa = ["contactId, $LinkColumn, commentType, comment, commentOverflow, timeModified, replyTo"];
            $qb = [$contact->contactId, $this->prow->$LinkColumn, $ctype, "?", "?", $Now, 0];
            if (strlen($text) <= 32000)
                array_push($qv, $text, null);
            else
                array_push($qv, UnicodeHelper::utf8_prefix($text, 200), $text);
            if ($ctags !== null) {
                $qa[] = "commentTags";
                $qb[] = "?";
                $qv[] = $ctags;
            }
            if ($is_response) {
                $qa[] = "commentRound";
                $qb[] = $this->commentRound;
            }
            if ($displayed) {
                $qa[] = "timeDisplayed, timeNotified";
                $qb[] = "$Now, $Now";
            }
            $q = "insert into $Table (" . join(", ", $qa) . ") select " . join(", ", $qb) . "\n";
            if ($is_response) {
                // make sure there is exactly one response
                $q .= " from (select $LinkTable.$LinkColumn, coalesce(commentId, 0) commentId
                from $LinkTable
                left join $Table on ($Table.$LinkColumn=$LinkTable.$LinkColumn and (commentType&" . COMMENTTYPE_RESPONSE . ")!=0 and commentRound=$this->commentRound)
                where $LinkTable.$LinkColumn={$this->prow->$LinkColumn} limit 1) t
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
                $qa .= ", timeNotified=$Now";
            // reset timeDisplayed if you change the comment type
            if ((!$this->timeDisplayed || $this->ordinal_missing($ctype))
                && $text !== "" && $displayed)
                $qa .= ", timeDisplayed=$Now";
            $q = "update $Table set timeModified=$Now$qa, commentType=$ctype, comment=?, commentOverflow=?, commentTags=? where commentId=$this->commentId";
            if (strlen($text) <= 32000)
                array_push($qv, $text, null);
            else
                array_push($qv, UnicodeHelper::utf8_prefix($text, 200), $text);
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
        if ($text !== "" && $this->ordinal_missing($ctype))
            $this->save_ordinal($cmtid, $ctype, $Table, $LinkTable, $LinkColumn);

        // reload
        if ($text !== "") {
            $comments = $this->prow->fetch_comments("commentId=$cmtid");
            $this->merge($comments[$cmtid], $this->prow);
            if ($this->timeNotified == $this->timeModified) {
                self::$watching = $this;
                $this->prow->notify(WATCHTYPE_COMMENT, "CommentInfo::watch_callback", $contact);
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
            && ($tmpl !== "@responsedraftnotify" || $minic->act_author_view($prow)))
            HotCRPMailer::send_to($minic, $tmpl, $prow, array("comment_row" => self::$watching));
    }
}
