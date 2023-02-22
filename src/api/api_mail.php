<?php
// api_mail.php -- HotCRP mail API calls
// Copyright (c) 2008-2023 Eddie Kohler; see LICENSE.

class Mail_API {
    static function mailtext(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        if (!$user->isPC
            || ($prow && !$user->can_view_paper($prow))) {
            return JsonResult::make_permission_error();
        }

        $recipient = null;
        if (($args = $qreq->subset_as_array("firstName", "first", "lastName", "last", "affiliation", "email"))) {
            $recipient = Contact::make_keyed($user->conf, $args);
        }

        $mailinfo = [
            "prow" => $prow,
            "requester_contact" => $user,
            "width" => $qreq->width ?? 10000,
            "censor" => Mailer::CENSOR_DISPLAY
        ];
        if (isset($qreq->reason)) {
            $mailinfo["reason"] = $qreq->reason;
        }
        $rid = $qreq->r;
        if ($prow) {
            if (isset($rid)
                && ctype_digit($rid)
                && ($rrow = $prow->review_by_id(intval($rid)))
                && $user->can_view_review($prow, $rrow)) {
                $mailinfo["rrow"] = $rrow;
            } else if ($qreq->template === "requestreview") {
                $rrow = ReviewInfo::make_blank($prow, $recipient ?? Contact::make_email($user->conf, "<EMAIL>"));
                $mailinfo["rrow"] = $rrow;
            }
        }
        $mailer = new HotCRPMailer($user->conf, $recipient, $mailinfo);

        if (isset($qreq->text) || isset($qreq->subject) || isset($qreq->body)) {
            $j = ["ok" => true];
            foreach (["text", "subject", "body"] as $k) {
                $j[$k] = $mailer->expand($qreq[$k], $k);
            }
            return $j;
        } else if (!$qreq->template) {
            return JsonResult::make_error(400, "<0>Parameter error");
        }

        $recip = new MailRecipients($user);
        if ($qreq->template === "all") {
            $mtjs = [];
            foreach (array_keys($user->conf->mail_template_map()) as $k) {
                if (($mt = $user->conf->mail_template($k, false, $user))
                    && ($mt->allow_template ?? false)
                    && isset($mt->title)
                    && !isset($mtjs[$mt->name])) {
                    $mtjs[$mt->name] = ["name" => $mt->name, "title" => $mt->title]
                        + self::expand_template($user, $mailer, $recip, $mt);
                }
            }
            return ["ok" => true, "templates" => array_values($mtjs)];
        } else if (($mt = $user->conf->mail_template($qreq->template, false, $user))) {
            return ["ok" => true] + self::expand_template($user, $mailer, $recip, $mt);
        } else {
            return JsonResult::make_error(404, "<0>Template not found");
        }
    }

    /** @param HotCRPMailer $mailer
     * @param MailRecipients $recip
     * @param object $mt
     * @return array */
    static private function expand_template(Contact $user, $mailer, $recip, $mt) {
        $mj = [
            "subject" => $mailer->expand($mt->subject, "subject"),
            "body" => $mailer->expand($mt->body, "body")
        ];
        if ($mt->default_recipients ?? null) {
            $rtype = $recip->canonical_recipients($mt->default_recipients);
            $mj["recipients"] = $rtype;
            if (($desc = $recip->recipient_description($rtype))) {
                $mj["recipient_description"] = $desc;
            }
        }
        if ($mt->default_search_type ?? null) {
            $mj["t"] = $mt->default_search_type;
        }
        return $mj;
    }

    /** @param Contact $user
     * @return bool */
    static function can_view_maillog($user, $logrow) {
        return $user->privChair || $user->contactId === (int) $logrow->contactId;
    }

    static function maillog(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        if (!$qreq->mailid || !ctype_digit($qreq->mailid)) {
            return JsonResult::make_missing_error("mailid");
        } else if (!$user->privChair) {
            return JsonResult::make_permission_error();
        } else if (($row = $user->conf->fetch_first_object("select * from MailLog where mailId=?", $qreq->mailid))) {
            if (self::can_view_maillog($user, $row)) {
                $j = ["ok" => true];
                foreach (["recipients", "q", "t", "cc", "subject"] as $field) {
                    if ($row->$field !== null && $row->$field !== "")
                        $j[$field] = $row->$field;
                }
                if ($row->replyto !== null) {
                    $j["reply-to"] = $row->replyto;
                }
                if ($row->emailBody !== null) {
                    $j["body"] = $row->emailBody;
                }
                return $j;
            } else {
                return JsonResult::make_permission_error();
            }
        } else {
            return JsonResult::make_error(404, "<0>Email not found");
        }
    }
}
