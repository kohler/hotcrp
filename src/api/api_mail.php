<?php
// api_mail.php -- HotCRP mail API calls
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class Mail_API {
    static function mailtext(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        if (!$user->isPC
            || ($prow && !$user->can_view_paper($prow))) {
            return new JsonResult(403, "Permission error");
        }

        $recipient = null;
        if (($args = $qreq->subset_as_array("firstName", "first", "lastName", "last", "affiliation", "email"))) {
            $recipient = Contact::make_keyed($user->conf, $args);
        }

        $mailinfo = [
            "prow" => $prow,
            "requester_contact" => $user,
            "censor" => Mailer::CENSOR_DISPLAY
        ];
        if (isset($qreq->reason)) {
            $mailinfo["reason"] = $qreq->reason;
        }
        $rid = $qreq->r;
        if (isset($rid)
            && ctype_digit($rid)
            && $prow
            && ($rrow = $prow->review_by_id((int) $rid))
            && $user->can_view_review($prow, $rrow)) {
            $mailinfo["rrow"] = $rrow;
        }

        $mailer = new HotCRPMailer($user->conf, $recipient, $mailinfo);
        $j = ["ok" => true];
        if (isset($qreq->text) || isset($qreq->subject) || isset($qreq->body)) {
            foreach (["text", "subject", "body"] as $k) {
                $j[$k] = $mailer->expand($qreq[$k], $k);
            }
            return $j;
        } else if (isset($qreq->template)) {
            if (!($mt = $user->conf->mail_template($qreq->template, false, $user))) {
                return new JsonResult(404, "Template not found");
            }
            $j["subject"] = $mailer->expand($mt->subject, "subject");
            $j["body"] = $mailer->expand($mt->body, "body");
            if ($mt->default_recipients ?? null) {
                $recip = new MailRecipients($user);
                $j["recipients"] = $recip->canonical_recipients($mt->default_recipients);
            }
            if ($mt->default_search_type ?? null) {
                $j["t"] = $mt->default_search_type;
            }
            return $j;
        } else {
            return new JsonResult(400, "Parameter error");
        }
    }

    /** @param Contact $user
     * @return bool */
    static function can_view_maillog($user, $logrow) {
        return $user->privChair || $user->contactId === (int) $logrow->contactId;
    }

    static function maillog(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        if (!$qreq->mailid || !ctype_digit($qreq->mailid)) {
            return new JsonResult(400, "Parameter error");
        } else if (!$user->privChair) {
            return new JsonResult(403, "Permission error");
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
                return new JsonResult(403, "Permission error");
            }
        } else {
            return ["ok" => false, "error" => "Email not found"];
        }
    }
}
