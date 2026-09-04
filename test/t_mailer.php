<?php
// t_mailer.php -- HotCRP tests
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Mailer_Tester {
    /** @var Conf
     * @readonly */
    public $conf;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    function run_send_template(MailRecipients $mr, $template, $qreq = []) {
        if (!($qreq instanceof Qrequest)) {
            $qreq = (new Qrequest("POST", $qreq))->set_user($mr->user)->approve_token();
        }
        ob_start();
        try {
            $ms = new MailSender($mr, $qreq, MailSender::PHASE_SEND);
            $ms->set_template($template);
            $ms->set_no_print(true)->set_send_all(true);
            $ms->prepare_sending_mailid();
            $ms->run();
        } catch (PageCompletion $unused) {
        }
        ob_end_clean();
    }

    /** @return string */
    function run_check_template(MailRecipients $mr, $template, $qreq = []) {
        if (!($qreq instanceof Qrequest)) {
            $qreq = (new Qrequest("POST", $qreq))->set_user($mr->user)->approve_token();
        }
        ob_start();
        try {
            $ms = new MailSender($mr, $qreq, MailSender::PHASE_PREVIEW);
            $ms->set_template($template);
            $ms->run();
        } catch (PageCompletion $unused) {
        }
        return ob_get_clean();
    }

    function test_send() {
        MailChecker::clear();
        $user = $this->conf->checked_user_by_email("chair@_.com");
        $mr = (new MailRecipients($user))->set_recipients("au")->set_paper_ids([13, 14, 15, 16]);
        $this->run_send_template($mr, "@authors");
        MailChecker::check_db("t_mailer-send-1");
    }

    function test_accept_mail_marks_notified() {
        MailChecker::clear();
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        // accept paper 13 and clear any prior notification mark
        xassert_assign($chair, "paper,action,decision\n13,decision,yes\n");
        $this->conf->qe("update Paper set timeAcceptNotified=0 where paperId=13");
        xassert_eqq($this->conf->checked_paper_by_id(13)->timeAcceptNotified, 0);

        // sending to accept-class authors marks the paper notified
        $mr = (new MailRecipients($chair))->set_recipients("dec:yes")->set_paper_ids([13]);
        $this->run_send_template($mr, "@authors");
        xassert_gt($this->conf->checked_paper_by_id(13)->timeAcceptNotified, 0);

        // a second accept mail does not move the timestamp
        $t1 = $this->conf->checked_paper_by_id(13)->timeAcceptNotified;
        $mr = (new MailRecipients($chair))->set_recipients("dec:yes")->set_paper_ids([13]);
        $this->run_send_template($mr, "@authors");
        xassert_eqq($this->conf->checked_paper_by_id(13)->timeAcceptNotified, $t1);

        // a generic all-authors mail does NOT mark a non-notified accepted paper
        $this->conf->qe("update Paper set timeAcceptNotified=0 where paperId=13");
        $mr = (new MailRecipients($chair))->set_recipients("au")->set_paper_ids([13]);
        $this->run_send_template($mr, "@authors");
        xassert_eqq($this->conf->checked_paper_by_id(13)->timeAcceptNotified, 0);

        // clean up: clear decision so no papers remain accepted
        xassert_assign($chair, "paper,action,decision\n13,cleardecision,yes\n");
        MailChecker::clear();
    }

    function test_mailtext_hides_decision_and_shepherd() {
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");
        $shepherd = $conf->checked_user_by_email("estrin@usc.edu");
        $probe = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");

        // paper 13 is accepted and shepherded; the probe is conflicted with it
        xassert_assign($chair, "paper,action,decision\n13,decision,yes\n");
        xassert_assign($chair, "paper,action,user\n13,shepherd,{$shepherd->email}\n");
        xassert_assign($chair, "paper,action,user\n13,conflict,{$probe->email}\n");

        $prow = $conf->checked_paper_by_id(13);
        $decname = $prow->decision()->name;
        xassert_gt($prow->outcome, 0);
        xassert_eqq($prow->shepherdContactId, $shepherd->contactId);

        $probe = $conf->checked_user_by_email($probe->email);
        xassert($probe->isPC);
        xassert($probe->can_view_paper($prow));
        xassert(!$probe->can_view_decision($prow));
        xassert(!$probe->can_view_shepherd($prow));

        $args = ["p" => 13, "email" => "probe@example.invalid", "text" => "", "subject" => "",
                 "body" => "D[{{DECISION}}] S[{{SHEPHERDEMAIL}}] T[{{TITLE}}]"];

        // control: the keywords do expand for someone who may see them
        $cbody = call_api_result("mailtext", $chair, $args, $prow)->content["body"];
        xassert_str_contains($cbody, $decname);
        xassert_str_contains($cbody, $shepherd->email);

        // the probe expands the same text, but learns neither secret
        $pbody = call_api_result("mailtext", $probe, $args, $prow)->content["body"];
        xassert_str_contains($pbody, $prow->title); // expansion ran at all
        xassert_not_str_contains($pbody, $decname);
        xassert_not_str_contains($pbody, $shepherd->email);

        xassert_assign($chair, "paper,action,user\n13,noconflict,{$probe->email}\n");
        xassert_assign($chair, "paper,action,user\n13,clearshepherd,{$shepherd->email}\n");
        xassert_assign($chair, "paper,action,decision\n13,cleardecision,yes\n");
    }

    /** Find a complete review whose text distinguishes one field from the rest,
     * plus its author and a PC member who may read it but does not administer it.
     * @return ?array{PaperInfo,ReviewInfo,Contact,Contact,ReviewField,string} */
    private function find_distinctive_review() {
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");
        foreach ($conf->paper_set(["finalized" => true], $chair) as $prow) {
            $prow->ensure_full_reviews();
            foreach ($prow->reviews_as_display() as $rrow) {
                if ($rrow->reviewStatus < ReviewInfo::RS_COMPLETED) {
                    continue;
                }
                // find a PC-visible field with a word that appears nowhere else
                // in the review
                $field = $word = null;
                foreach ($conf->review_form()->all_fields() as $f) {
                    if ($f->view_score < VIEWSCORE_PC
                        || !is_string($fv = $rrow->fval($f))) {
                        continue;
                    }
                    preg_match_all('/[A-Za-z]{8,}/', $fv, $m);
                    foreach ($m[0] as $w) {
                        if (self::review_word_count($conf, $rrow, $w) === 1) {
                            $field = $f;
                            $word = $w;
                            break 2;
                        }
                    }
                }
                if (!$field) {
                    continue;
                }
                foreach ($conf->pc_members() as $pc) {
                    if ($pc->contactId !== $rrow->contactId
                        && !$pc->allow_admin($prow)
                        && !$pc->act_author_view($prow) // read it as PC, not as author
                        && $pc->can_view_review($prow, $rrow)) {
                        $reviewer = $conf->user_by_id($rrow->contactId);
                        xassert(!!$reviewer);
                        return [$prow, $rrow, $reviewer, $pc, $field, $word];
                    }
                }
            }
        }
        return null;
    }

    /** @param string $word
     * @return int */
    static private function review_word_count(Conf $conf, ReviewInfo $rrow, $word) {
        $n = 0;
        foreach ($conf->review_form()->all_fields() as $f) {
            if (is_string($fv = $rrow->fval($f))) {
                $n += preg_match_all('/\b' . preg_quote($word) . '\b/', $fv);
            }
        }
        return $n;
    }

    function test_censored_review_expansion_narrows_fields() {
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");

        $x = $this->find_distinctive_review();
        xassert(!!$x);
        if (!$x) {
            return;
        }
        list($prow, $rrow, $reviewer, $reader, $field, $secret) = $x;
        $visibility = $field->unparse_visibility();

        // make that field visible only to the review’s author and administrators
        $sv = SettingValues::make_request($chair, [
            "has_rf" => 1,
            "rf/1/id" => $field->short_id,
            "rf/1/visibility" => "admin"
        ]);
        xassert($sv->execute());

        $prow = $conf->checked_paper_by_id($prow->paperId);
        $prow->ensure_full_reviews();
        $rrow = $prow->review_by_id($rrow->reviewId);
        xassert(!!$rrow);
        xassert($reader->can_view_review($prow, $rrow));
        xassert_lt($reviewer->view_score_bound($prow, $rrow), $reader->view_score_bound($prow, $rrow));

        $rest = ["prow" => $prow, "rrow" => $rrow, "width" => 10000];

        // the mail that will actually be sent shows the reviewer their own field
        $mailer = new HotCRPMailer($reader, $reviewer, $rest);
        $sent = $mailer->expand("{{REVIEWS}}", "body");
        xassert_str_contains($sent, $secret);

        // the sender’s preview of that same mail does not
        $mailer = new HotCRPMailer($reader, $reviewer, $rest + ["censor" => Mailer::CENSOR_PREVIEW]);
        $shown = $mailer->expand("{{REVIEWS}}", "body");
        xassert_str_contains($shown, "Review"); // the review itself is still expanded
        xassert_not_str_contains($shown, $secret);

        // a chair sees everything they would send
        $mailer = new HotCRPMailer($chair, $reviewer, $rest + ["censor" => Mailer::CENSOR_PREVIEW]);
        xassert_str_contains($mailer->expand("{{REVIEWS}}", "body"), $secret);

        $sv = SettingValues::make_request($chair, [
            "has_rf" => 1,
            "rf/1/id" => $field->short_id,
            "rf/1/visibility" => $visibility
        ]);
        xassert($sv->execute());
    }

    function test_sender_visible_cc_narrows_expansion() {
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");

        $x = $this->find_distinctive_review();
        xassert(!!$x);
        if (!$x) {
            return;
        }
        list($prow, $rrow, $reviewer, $reader, $field, $secret) = $x;
        $visibility = $field->unparse_visibility();

        // make that field visible only to the review’s author and administrators
        $sv = SettingValues::make_request($chair, [
            "has_rf" => 1,
            "rf/1/id" => $field->short_id,
            "rf/1/visibility" => "admin"
        ]);
        xassert($sv->execute());

        $prow = $conf->checked_paper_by_id($prow->paperId);
        $prow->ensure_full_reviews();
        $rrow = $prow->review_by_id($rrow->reviewId);
        xassert(!!$rrow);
        xassert_lt($reviewer->view_score_bound($prow, $rrow), $reader->view_score_bound($prow, $rrow));

        $rest = ["prow" => $prow, "rrow" => $rrow, "width" => 10000];

        // A conference-configured cc censors credentials, but does not bound
        // the content: the recipient still reads their own reviewer-only field.
        $mailer = new HotCRPMailer($reader, $reviewer, $rest + ["cc" => "archive@example.invalid"]);
        $t = $mailer->expand("R[{{REVIEWS}}] A[{{REVIEWACCEPTOR}}]", "body");
        xassert_str_contains($t, $secret);
        xassert_str_contains($t, "{{REVIEWACCEPTOR}}"); // suppressed, so left unexpanded

        // A cc the sender chose also bounds the content by the sender.
        $mailer = new HotCRPMailer($reader, $reviewer,
            $rest + ["cc" => "friend@example.invalid", "sender_visible" => true]);
        $t = $mailer->expand("R[{{REVIEWS}}] A[{{REVIEWACCEPTOR}}]", "body");
        xassert_str_contains($t, "Review"); // the review itself is still expanded
        xassert_not_str_contains($t, $secret);
        xassert_str_contains($t, "{{REVIEWACCEPTOR}}");

        $sv = SettingValues::make_request($chair, [
            "has_rf" => 1,
            "rf/1/id" => $field->short_id,
            "rf/1/visibility" => $visibility
        ]);
        xassert($sv->execute());
    }

    function test_configured_cc_is_not_sender_supplied() {
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");
        $old_cc = $conf->opt("emailCc");
        $conf->set_opt("emailCc", "  archive@example.invalid  ");

        // MailSender treats a cc as sender-supplied when it differs from the
        // configured one, so the two must be normalized the same way
        $qreq = (new Qrequest("POST", []))->set_user($chair)->approve_token();
        MailSender::clean_request($qreq);
        xassert_eqq(trim($qreq->cc), trim($conf->opt("emailCc")));

        $qreq = (new Qrequest("POST", ["cc" => " friend@example.invalid "]))
            ->set_user($chair)->approve_token();
        MailSender::clean_request($qreq);
        xassert_eqq($qreq->cc, "friend@example.invalid");

        $conf->set_opt("emailCc", $old_cc);
    }

    function test_censored_comment_expansion() {
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");
        $probe = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $prow = $conf->checked_paper_by_id(13);

        // add a PC-visible comment, and conflict the probe with its submission
        $j = call_api("=comment", $chair, ["text" => "SECRETCOMMENTARY", "visibility" => "pc"], $prow);
        xassert($j->ok);
        $cid = $j->comment->cid;
        xassert_assign($chair, "paper,action,user\n13,conflict,{$probe->email}\n");

        $prow = $conf->checked_paper_by_id(13);
        $crow = ($prow->fetch_comments("commentId={$cid}"))[0];
        xassert(!!$crow);

        // find a PC member who may read the comment
        $reader = null;
        foreach ($conf->pc_members() as $pc) {
            if ($pc->contactId !== $probe->contactId
                && $pc->can_view_comment($prow, $crow)) {
                $reader = $pc;
                break;
            }
        }
        xassert(!!$reader);

        xassert($probe->isPC);
        xassert(!$probe->privChair);
        xassert(!$probe->can_view_comment($prow, $crow));

        $rest = ["prow" => $prow, "width" => 10000];

        // the mail that will actually be sent shows the reader the comment
        $mailer = new HotCRPMailer($probe, $reader, $rest);
        xassert_str_contains($mailer->expand("{{COMMENTS}}", "body"), "SECRETCOMMENTARY");

        // the conflicted sender’s preview of that same mail does not
        $mailer = new HotCRPMailer($probe, $reader, $rest + ["censor" => Mailer::CENSOR_PREVIEW]);
        xassert_not_str_contains($mailer->expand("{{COMMENTS}}", "body"), "SECRETCOMMENTARY");

        // a chair sees everything they would send
        $mailer = new HotCRPMailer($chair, $reader, $rest + ["censor" => Mailer::CENSOR_PREVIEW]);
        xassert_str_contains($mailer->expand("{{COMMENTS}}", "body"), "SECRETCOMMENTARY");

        xassert_assign($chair, "paper,action,user\n13,noconflict,{$probe->email}\n");
        $j = call_api("=comment", $chair, ["c" => $cid, "delete" => 1], $prow);
        xassert($j->ok);
        MailChecker::clear();
    }

    function test_censored_comment_expansion_renders_tags_and_mentions() {
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");
        $mentioned = $conf->checked_user_by_email("lixia@cs.ucla.edu");
        $prow = $conf->checked_paper_by_id(1);

        // a comment with both tags and a mention exercises CommentInfo::content()
        // and the tag machinery, neither of which is a permission predicate
        $j = call_api("=comment", $chair, ["c" => "new", "visibility" => "pc", "tags" => "hot",
                                           "text" => "SPICYCOMMENTARY @Lixia Zhang"], $prow);
        xassert($j->ok);
        $cid = $j->comment->cid;

        $prow = $conf->checked_paper_by_id(1);
        $crow = ($prow->fetch_comments("commentId={$cid}"))[0];
        xassert(!!$crow);
        xassert_neqq($crow->commentTags, null);
        xassert(!empty($crow->data("mentions")));

        // two non-chair PC readers, so neither end of the intersection is root
        $readers = [];
        foreach ($conf->pc_members() as $pc) {
            if (!$pc->privChair && $pc->can_view_comment($prow, $crow)) {
                $readers[] = $pc;
            }
        }
        xassert_gt(count($readers), 1);
        if (count($readers) < 2) {
            return;
        }

        $mailer = new HotCRPMailer($readers[0], $readers[1],
            ["prow" => $prow, "width" => 10000, "censor" => Mailer::CENSOR_PREVIEW]);
        $t = $mailer->expand("{{COMMENTS}}", "body");
        xassert_str_contains($t, "SPICYCOMMENTARY");
        xassert_str_contains($t, "#hot");
        xassert_str_contains($t, "@" . $mentioned->firstName);

        $j = call_api("=comment", $chair, ["c" => (string) $cid, "delete" => 1], $prow);
        xassert($j->ok);
        MailChecker::clear();
    }

    /** Find an ordinaled complete review that the submission’s authors may read.
     * @return ?array{PaperInfo,ReviewInfo} */
    private function find_author_readable_review() {
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");
        foreach ($conf->paper_set(["finalized" => true], $chair) as $prow) {
            $prow->ensure_full_reviews();
            $au = $prow->author_user();
            foreach ($prow->reviews_as_display() as $rrow) {
                if ($rrow->reviewStatus >= ReviewInfo::RS_COMPLETED
                    && $rrow->reviewOrdinal > 0
                    && $au->can_view_review($prow, $rrow)) {
                    return [$prow, $rrow];
                }
            }
        }
        return null;
    }

    /** @param int $reviewId
     * @return int */
    private function author_seen_after_shutdown($reviewId) {
        $this->conf->call_shutdown_function("ReviewAuthorSeenUpdate");
        return (int) $this->conf->fetch_ivalue("select reviewAuthorSeen from PaperReview where reviewId=?", $reviewId);
    }

    function test_mail_preview_does_not_mark_review_author_seen() {
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");

        $x = $this->find_author_readable_review();
        xassert(!!$x);
        if (!$x) {
            return;
        }
        list($prow, $rrow) = $x;
        $pid = $prow->paperId;
        $rid = $rrow->reviewId;
        $rtext = "Review #" . $rrow->unparse_ordinal_id();

        $unseen = ~ReviewInfo::RF_AUSEEN;
        $conf->qe("update PaperReview set reviewAuthorSeen=0, rflags=rflags&{$unseen} where reviewId=?", $rid);
        xassert_eqq($this->author_seen_after_shutdown($rid), 0);

        MailChecker::clear();
        $qreq = ["template" => "acceptnotify"];
        $mr = (new MailRecipients($chair))->set_recipients("au")->set_paper_ids([$pid]);
        xassert($mr->is_authors());

        // previewing the mail shows the review to its authors...
        $out = $this->run_check_template($mr, "@acceptnotify", $qreq);
        xassert_str_contains($out, $rtext);

        // ...but does not record that the authors have seen it
        xassert_eqq($this->author_seen_after_shutdown($rid), 0);

        // previewing through the mail API does not either. (The API renders for
        // the requested recipient, who is not an author, so it shows no review.)
        $j = call_api("mailtext", $chair,
            ["p" => $pid, "template" => "acceptnotify", "email" => "probe@example.invalid"], $prow);
        xassert($j->ok);
        xassert_not_str_contains($j->body ?? "", $rtext);
        xassert_eqq($this->author_seen_after_shutdown($rid), 0);

        // sending the same mail does record it
        $mr = (new MailRecipients($chair))->set_recipients("au")->set_paper_ids([$pid]);
        $this->run_send_template($mr, "@acceptnotify", $qreq);
        xassert_gt($this->author_seen_after_shutdown($rid), 0);

        $conf->qe("update PaperReview set reviewAuthorSeen=0, rflags=rflags&{$unseen} where reviewId=?", $rid);
        MailChecker::clear();
    }

    function test_recipient_address_curly_quotes() {
        // A display name containing curly quotes and address syntax must
        // reach the To: header as one mailbox: MimeText straightens curly
        // quotes that users type as delimiters, but must not do so inside
        // the quoted-string that Text::name produces.
        $u = Contact::make_keyed($this->conf, [
            "email" => "victim@_.com",
            "firstName" => "Eve",
            "lastName" => "” <evil@example.net>, “Christian"
        ]);
        $addr = MailPreparation::recipient_address($u);
        xassert_str_starts_with($addr, "\"");
        $mt = new MimeText;
        $to = $mt->encode_email_header("To", $addr);
        xassert(is_string($to));
        xassert_str_contains($to, "<victim@_.com>");
        xassert_not_str_contains($to, "evil@example.net");
        xassert_eqq(substr_count($to, "@"), 1);
        // decode_email_header re-quotes a name that would not re-parse as one
        xassert_eqq(MimeText::decode_email_header(substr($to, 4)),
                    "\"Eve ” <evil@example.net>, “Christian\" <victim@_.com>");

        // an unbalanced curly quote is quoted too, and does not disturb
        // other recipients merged into the same header
        $u2 = Contact::make_keyed($this->conf, [
            "email" => "other@_.com", "firstName" => "Bob”", "lastName" => "Jones"
        ]);
        $addr2 = MailPreparation::recipient_address($u2);
        xassert_str_starts_with($addr2, "\"");
        $to = $mt->encode_email_header("To", "{$addr2}, {$addr}, {$addr2}");
        xassert(is_string($to));
        xassert_eqq(substr_count($to, "<other@_.com>"), 2);
        xassert_eqq(substr_count($to, "<victim@_.com>"), 1);
        xassert_not_str_contains($to, "evil@example.net");
        xassert_eqq(substr_count($to, "@"), 3);

        // nor do many curly quotes in one name
        $u3 = Contact::make_keyed($this->conf, [
            "email" => "victim@_.com",
            "firstName" => "Eve",
            "lastName" => str_repeat("a”", 250) . " <evil@example.net>, “Christian"
        ]);
        $to = $mt->encode_email_header("To", MailPreparation::recipient_address($u3));
        xassert(is_string($to));
        xassert_str_contains($to, "<victim@_.com>");
        xassert_not_str_contains($to, "evil@example.net");
        xassert_eqq(substr_count($to, "@"), 1);

        // curly quotes typed as delimiters are still straightened
        $to = $mt->encode_email_header("To", "“Jones, Bob” <other@_.com>, “Eve” <victim@_.com>");
        xassert_eqq($to, "To: \"Jones, Bob\" <other@_.com>, \"Eve\" <victim@_.com>");
    }

    function test_recipient_address_encoded_word() {
        // A display name that looks like an encoded-word must not be
        // decoded into raw control characters (extra header lines)
        $evil = "=?utf-8?q?V=0D=0ABcc=3A_evil=40example=2Enet?=";
        // decode_email_header leaves a word that decodes to control characters alone
        xassert_eqq(MimeText::decode_email_header($evil), $evil);
        $u = Contact::make_keyed($this->conf, [
            "email" => "mimevictim@_.com", "lastName" => $evil
        ]);
        $addr = MailPreparation::recipient_address($u);
        xassert_str_starts_with($addr, "\"=?");
        $mt = new MimeText;
        $to = $mt->encode_email_header("To", $addr);
        xassert_eqq(Unit_Tester::mail_header_skeleton($to), "To: <mimevictim@_.com>");

        // same for a name that has been stored, in a folded header
        $u->store();
        $u = $this->conf->fresh_user_by_email("mimevictim@_.com");
        xassert_eqq($u->lastName, $evil);
        $addr = MailPreparation::recipient_address($u);
        $to = $mt->encode_email_header("To", "{$addr}, {$addr}");
        xassert(is_string($to));
        xassert_str_contains($to, "\r\n ");
        xassert(!preg_match('/\r(?!\n )|[^\r]\n/', $to));
        xassert_eqq(substr_count($to, "@"), 2);
        $this->conf->qe("delete from ContactInfo where email='mimevictim@_.com'");
        $this->conf->invalidate_user($u);

        // an unquoted encoded-word is decoded, but control characters
        // are encoded again
        $to = $mt->encode_email_header("To", "{$evil} <victim@_.com>, =?utf-8?Q?=22x=0Ay=3A=22?= <other@_.com>, =?utf-8?q?a=09b?= <tab@_.com>");
        // (a tab is whitespace between words, so it becomes a space)
        xassert_eqq($to, "To: {$evil} <victim@_.com>, \r\n =?utf-8?q?=22x=0Ay=3A=22?= <other@_.com>, a b <tab@_.com>");
        xassert(!preg_match('/\r(?!\n )|[^\r]\n/', $to));
    }

    function test_mailtext_without_recipient() {
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");
        $probe = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $prow = $conf->checked_paper_by_id(1);

        // No name or email arguments, so the mail has no recipient. Paper
        // keywords do not expand without one, but expansion must not fail.
        $args = ["p" => 1, "text" => "", "subject" => "",
                 "body" => "T[{{TITLE}}] A[{{AUTHORS}}] R[{{REVIEWS}}] C[{{COMMENTS}}]"];
        foreach ([$chair, $probe] as $user) {
            $j = call_api("mailtext", $user, $args, $prow);
            xassert($j->ok);
            xassert_str_contains($j->body ?? "", "{{TITLE}}");
        }

        // ... and with no submission either
        foreach ([$chair, $probe] as $user) {
            $j = call_api("mailtext", $user, ["template" => "all"]);
            xassert($j->ok);
            xassert(!empty($j->templates));
        }
    }
}
