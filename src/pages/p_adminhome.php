<?php
// pages/p_adminhome.php -- HotCRP home page fragments for administrators
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class AdminHome_Page {
    static function check_admin(Contact $user, Qrequest $qreq) {
        assert($user->privChair && $qreq->valid_token());
        if (isset($qreq->clearbug)
            && !$qreq->is_head()) {
            $user->conf->save_setting("bug_" . $qreq->clearbug, null);
        }
        if (isset($qreq->clearnewpcrev)
            && ctype_digit($qreq->clearnewpcrev)
            && ($user->conf->setting("pcrev_informtime") ?? 0) <= $qreq->clearnewpcrev
            && !$qreq->is_head()) {
            $user->conf->save_setting("pcrev_informtime", intval($qreq->clearnewpcrev));
        }
        if (isset($qreq->clearbug) || isset($qreq->clearnewpcrev)) {
            unset($qreq->clearbug, $qreq->clearnewpcrev);
            $user->conf->redirect_self($qreq);
        }
    }
    static function print(Contact $user) {
        $conf = $user->conf;
        $ml = [];
        if (preg_match("/^(?:[1-5]\\.)/", phpversion())) {
            $ml[] = new MessageItem(null, "<0>HotCRP requires PHP version 7.0 or higher.  You are running PHP version " . phpversion(), 2);
        }
        $result = Dbl::qx($conf->dblink, "show variables like 'max_allowed_packet'");
        $max_file_size = ini_get_bytes("upload_max_filesize");
        if (($row = $result->fetch_row())
            && $row[1] < $max_file_size
            && !$conf->opt("dbNoPapers")) {
            $ml[] = new MessageItem(null, "<5>MySQL’s <code>max_allowed_packet</code> setting, which is " . htmlspecialchars($row[1]) . "&nbsp;bytes, is less than the PHP upload file limit, which is {$max_file_size}&nbsp;bytes.  You should update <code>max_allowed_packet</code> in the system-wide <code>my.cnf</code> file or the system may not be able to handle large papers", MessageSet::URGENT_NOTE);
        }
        if ($max_file_size < ini_get_bytes(null, $conf->opt("upload_max_filesize") ?? "10M")) {
            $ml[] = new MessageItem(null, "<5>PHP’s <code>upload_max_filesize</code> setting, which is <code>" . htmlspecialchars(ini_get("upload_max_filesize")) . "</code>, will limit submissions to at most $max_file_size&nbsp;bytes. Usually a larger limit, such as <code>10M</code>, is appropriate. Change this setting in HotCRP’s <code>.htaccess</code> and <code>.user.ini</code> files, change it in your global <code>php.ini</code> file, or silence this message by setting <code>\$Opt[\"upload_max_filesize\"] = \"" . htmlspecialchars(ini_get("upload_max_filesize")) . "\"</code> in <code>conf/options.php</code>", MessageSet::URGENT_NOTE);
        }
        $post_max_size = ini_get_bytes("post_max_size");
        if ($post_max_size < $max_file_size) {
            $ml[] = new MessageItem(null, "<5>PHP’s <code>post_max_size</code> setting is smaller than its <code>upload_max_filesize</code> setting. The <code>post_max_size</code> value should be at least as big. Change this setting in HotCRP’s <code>.htaccess</code> and <code>.user.ini</code> files or change it in your global <code>php.ini</code> file", MessageSet::WARNING_NOTE);
        }
        $memory_limit = ini_get_bytes("memory_limit");
        if ($post_max_size >= $memory_limit) {
            $ml[] = new MessageItem(null, "<5>PHP’s <code>memory_limit</code> setting is smaller than its <code>post_max_size</code> setting. The <code>memory_limit</code> value should be at least as big. Change this setting in HotCRP’s <code>.htaccess</code> and <code>.user.ini</code> files or change it in your global <code>php.ini</code> file", MessageSet::URGENT_NOTE);
        }
        if (defined("JSON_HOTCRP")) {
            $ml[] = new MessageItem(null, "<0>Your PHP was built without JSON functionality. HotCRP is using its built-in replacements; the native functions would be faster", MessageSet::WARNING_NOTE);
        }
        if ((int) ini_get("session.gc_maxlifetime") < ($conf->opt("sessionLifetime") ?? 86400)
            && !isset($conf->opt["sessionHandler"])) {
            $ml[] = new MessageItem(null, "<5>PHP’s systemwide <code>session.gc_maxlifetime</code> setting, which is " . htmlspecialchars(ini_get("session.gc_maxlifetime")) . " seconds, is less than HotCRP’s preferred session expiration time, which is " . ($conf->opt("sessionLifetime") ?? 86400) . " seconds.  You should update <code>session.gc_maxlifetime</code> in the <code>php.ini</code> file or users may be booted off the system earlier than you expect", MessageSet::WARNING_NOTE);
        }
        if (!function_exists("imagecreate") && $conf->setting("__gd_required")) {
            $ml[] = new MessageItem(null, "<5>This PHP installation lacks support for the GD library, so HotCRP can’t generate backup score charts for old browsers. Some of your users require this backup. You should update your PHP installation. For example, on Ubuntu Linux, install the <code>php" . PHP_MAJOR_VERSION . "-gd</code> package", MessageSet::URGENT_NOTE);
        }
        // Conference names
        if ($conf->opt("shortNameDefaulted")) {
            $ml[] = new MessageItem(null, "<5><a href=\"" . $conf->hoturl("settings", "group=basics") . "\">Set the conference abbreviation</a> to a short name for your conference, such as “OSDI ’14”", MessageSet::WARNING_NOTE);
        } else if (simplify_whitespace($conf->short_name) != $conf->short_name) {
            $ml[] = new MessageItem(null, "<5>The <a href=\"" . $conf->hoturl("settings", "group=basics") . "\">conference abbreviation</a> setting has a funny value. To fix it, remove leading and trailing spaces, use only space characters (no tabs or newlines), and make sure words are separated by single spaces (never two or more)", MessageSet::WARNING);
        }
        $site_contact = $conf->site_contact();
        if (!$site_contact->email || $site_contact->email == "you@example.com") {
            $ml[] = new MessageItem(null, "<5><a href=\"" . $conf->hoturl("settings", "group=basics") . "\">Set the conference contact’s name and email</a> so submitters can reach someone if things go wrong", MessageSet::URGENT_NOTE);
        }
        // Any -100 preferences around?
        $result = $conf->preference_conflict_result("s", "limit 1");
        if (($row = $result->fetch_row())) {
            $ml[] = new MessageItem(null, '<5>PC members have indicated paper conflicts (using review preferences of &#8722;100 or less) that aren’t yet confirmed. <a href="' . $conf->hoturl("=conflictassign") . '" class="nw">Confirm these conflicts</a>', MessageSet::MARKED_NOTE);
        }
        // Weird URLs?
        foreach (["conferenceSite", "paperSite"] as $k) {
            if (($url = $conf->opt($k))
                && !preg_match('/\Ahttps?:\/\/(?:[-.~\w:\/?#\[\]@!$&\'()*+,;=]|%[0-9a-fA-F][0-9a-fA-F])*\z/', $url))
                $ml[] = new MessageItem(null, "<5>The <code>\$Opt[\"$k\"]</code> setting, ‘<code>" . htmlspecialchars($url) . "</code>’, is not a valid URL.  Edit the <code>conf/options.php</code> file to fix this problem", MessageSet::URGENT_NOTE);
        }
        // Unnotified reviews?
        if (($conf->setting("pcrev_assigntime") ?? 0) > ($conf->setting("pcrev_informtime") ?? 0)) {
            $assigntime = $conf->setting("pcrev_assigntime");
            $result = $conf->qe("select paperId from PaperReview where reviewType>" . REVIEW_PC . " and timeRequested>timeRequestNotified and reviewSubmitted is null and reviewNeedsSubmit!=0 limit 1");
            if ($result->num_rows) {
                $ml[] = new MessageItem(null, "<5>PC review assignments have changed.&nbsp; <a href=\"" . $conf->hoturl("mail", "template=newpcrev") . "\">Send review assignment notifications</a> <span class=\"barsep\">·</span> <a href=\"" . $conf->hoturl("=index", "clearnewpcrev={$assigntime}") . "\">Clear this message</a>", MessageSet::MARKED_NOTE);
            } else {
                $conf->save_setting("pcrev_informtime", $assigntime);
            }
        }
        // Review round expired?
        if (count($conf->round_list()) > 1
            && $conf->time_review_open()
            && $conf->missed_review_deadline($conf->assignment_round(false), true, false)) {
            $any_rounds_open = false;
            foreach ($conf->defined_round_list() as $i => $rname) {
                if (!$conf->missed_review_deadline($i, true, false)
                    && $conf->setting($conf->review_deadline_name($i, true, false))) {
                    $ml[] = new MessageItem(null, "<5>The deadline for review round " . htmlspecialchars($conf->assignment_round_option(false)) . " has passed. You may want to <a href=\"" . $conf->hoturl("settings", "group=reviews") . "\">change the round for new assignments</a> to " . htmlspecialchars($rname) . ".", MessageSet::MARKED_NOTE);
                    break;
                }
            }
        }

        if (!empty($ml)) {
            $ml[] = new MessageItem(null, "", MessageSet::WARNING);
            $conf->feedback_msg($ml);
        }
    }
}
