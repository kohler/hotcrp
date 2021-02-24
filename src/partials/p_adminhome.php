<?php
// src/partials/p_adminhome.php -- HotCRP home page partials for administrators
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class AdminHome_Partial {
    static function check_admin(Contact $user, Qrequest $qreq) {
        assert($user->privChair && $qreq->valid_token());
        if (isset($qreq->clearbug)
            && !$qreq->is_head()) {
            $user->conf->save_setting("bug_" . $qreq->clearbug, null);
        }
        if (isset($qreq->clearnewpcrev)
            && ctype_digit($qreq->clearnewpcrev)
            && $user->conf->setting("pcrev_informtime", 0) <= $qreq->clearnewpcrev
            && !$qreq->is_head()) {
            $user->conf->save_setting("pcrev_informtime", $qreq->clearnewpcrev);
        }
        if (isset($qreq->clearbug) || isset($qreq->clearnewpcrev)) {
            unset($qreq->clearbug, $qreq->clearnewpcrev);
            $user->conf->redirect_self($qreq);
        }
    }
    static function render(Contact $user) {
        $conf = $user->conf;
        $m = [];
        $errmarker = "<span class=\"is-error\">Error:</span> ";
        if (preg_match("/^(?:[1-5]\\.)/", phpversion())) {
            $m[] = $errmarker . "HotCRP requires PHP version 7.0 or higher.  You are running PHP version " . htmlspecialchars(phpversion()) . ".";
        }
        $result = Dbl::qx($conf->dblink, "show variables like 'max_allowed_packet'");
        $max_file_size = ini_get_bytes("upload_max_filesize");
        if (($row = $result->fetch_row())
            && $row[1] < $max_file_size
            && !$conf->opt("dbNoPapers")) {
            $m[] = $errmarker . "MySQL’s <code>max_allowed_packet</code> setting, which is " . htmlspecialchars($row[1]) . "&nbsp;bytes, is less than the PHP upload file limit, which is $max_file_size&nbsp;bytes.  You should update <code>max_allowed_packet</code> in the system-wide <code>my.cnf</code> file or the system may not be able to handle large papers.";
        }
        if ($max_file_size < ini_get_bytes(null, $conf->opt("upload_max_filesize", "10M"))) {
            $m[] = $errmarker . "PHP’s <code>upload_max_filesize</code> setting, which is <code>" . htmlspecialchars(ini_get("upload_max_filesize")) . "</code>, will limit submissions to at most $max_file_size&nbsp;bytes. Usually a larger limit, such as <code>10M</code>, is appropriate. Change this setting in HotCRP’s <code>.htaccess</code> and <code>.user.ini</code> files, change it in your global <code>php.ini</code> file, or silence this message by setting <code>\$Opt[\"upload_max_filesize\"] = \"" . htmlspecialchars(ini_get("upload_max_filesize")) . "\"</code> in <code>conf/options.php</code>.";
        }
        $post_max_size = ini_get_bytes("post_max_size");
        if ($post_max_size < $max_file_size) {
            $m[] = $errmarker . "PHP’s <code>post_max_size</code> setting is smaller than its <code>upload_max_filesize</code> setting. The <code>post_max_size</code> value should be at least as big. Change this setting in HotCRP’s <code>.htaccess</code> and <code>.user.ini</code> files or change it in your global <code>php.ini</code> file.";
        }
        $memory_limit = ini_get_bytes("memory_limit");
        if ($post_max_size >= $memory_limit) {
            $m[] = $errmarker . "PHP’s <code>memory_limit</code> setting is smaller than its <code>post_max_size</code> setting. The <code>memory_limit</code> value should be at least as big. Change this setting in HotCRP’s <code>.htaccess</code> and <code>.user.ini</code> files or change it in your global <code>php.ini</code> file.";
        }
        if (defined("JSON_HOTCRP")) {
            $m[] = "Your PHP was built without JSON functionality. HotCRP is using its built-in replacements; the native functions would be faster.";
        }
        if ((int) ini_get("session.gc_maxlifetime") < $conf->opt("sessionLifetime", 86400)
            && !isset($conf->opt["sessionHandler"])) {
            $m[] = "PHP’s systemwide <code>session.gc_maxlifetime</code> setting, which is " . htmlspecialchars(ini_get("session.gc_maxlifetime")) . " seconds, is less than HotCRP’s preferred session expiration time, which is " . $conf->opt("sessionLifetime", 86400) . " seconds.  You should update <code>session.gc_maxlifetime</code> in the <code>php.ini</code> file or users may be booted off the system earlier than you expect.";
        }
        if (!function_exists("imagecreate") && $conf->setting("__gd_required")) {
            $m[] = $errmarker . "This PHP installation lacks support for the GD library, so HotCRP can’t generate backup score charts for old browsers. Some of your users require this backup. You should update your PHP installation. For example, on Ubuntu Linux, install the <code>php" . PHP_MAJOR_VERSION . "-gd</code> package.";
        }
        // Conference names
        if ($conf->opt("shortNameDefaulted")) {
            $m[] = "<a href=\"" . $conf->hoturl("settings", "group=basics") . "\">Set the conference abbreviation</a> to a short name for your conference, such as “OSDI ’14”.";
        } else if (simplify_whitespace($conf->short_name) != $conf->short_name) {
            $m[] = "The <a href=\"" . $conf->hoturl("settings", "group=basics") . "\">conference abbreviation</a> setting has a funny value. To fix it, remove leading and trailing spaces, use only space characters (no tabs or newlines), and make sure words are separated by single spaces (never two or more).";
        }
        $site_contact = $conf->site_contact();
        if (!$site_contact->email || $site_contact->email == "you@example.com") {
            $m[] = "<a href=\"" . $conf->hoturl("settings", "group=basics") . "\">Set the conference contact’s name and email</a> so submitters can reach someone if things go wrong.";
        }
        // Any -100 preferences around?
        $result = $conf->preference_conflict_result("s", "limit 1");
        if (($row = $result->fetch_row())) {
            $m[] = 'PC members have indicated paper conflicts (using review preferences of &#8722;100 or less) that aren’t yet confirmed. <a href="' . $conf->hoturl_post("conflictassign") . '" class="nw">Confirm these conflicts</a>';
        }
        // Weird URLs?
        foreach (array("conferenceSite", "paperSite") as $k) {
            if (($url = $conf->opt($k))
                && !preg_match('`\Ahttps?://(?:[-.~\w:/?#\[\]@!$&\'()*+,;=]|%[0-9a-fA-F][0-9a-fA-F])*\z`', $url))
                $m[] = $errmarker . "The <code>\$Opt[\"$k\"]</code> setting, ‘<code>" . htmlspecialchars($url) . "</code>’, is not a valid URL.  Edit the <code>conf/options.php</code> file to fix this problem.";
        }
        // Unnotified reviews?
        if ($conf->setting("pcrev_assigntime", 0) > $conf->setting("pcrev_informtime", 0)) {
            $assigntime = $conf->setting("pcrev_assigntime");
            $result = $conf->qe("select paperId from PaperReview where reviewType>" . REVIEW_PC . " and timeRequested>timeRequestNotified and reviewSubmitted is null and reviewNeedsSubmit!=0 limit 1");
            if ($result->num_rows) {
                $m[] = "PC review assignments have changed.&nbsp; <a href=\"" . $conf->hoturl("mail", "template=newpcrev") . "\">Send review assignment notifications</a> <span class=\"barsep\">·</span> <a href=\"" . $conf->hoturl_post("index", "clearnewpcrev=$assigntime") . "\">Clear this message</a>";
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
                    $m[] = "The deadline for review round " . htmlspecialchars($conf->assignment_round_option(false)) . " has passed. You may want to <a href=\"" . $conf->hoturl("settings", "group=reviews") . "\">change the round for new assignments</a> to " . htmlspecialchars($rname) . ".";
                    break;
                }
            }
        }

        if (!empty($m)) {
            Conf::msg_warning($m, true);
        }
    }
}
