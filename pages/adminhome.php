<?php
// adminhome.php -- HotCRP home page administrative messages
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

function admin_home_messages() {
    global $Conf;
    $m = array();
    $errmarker = "<span class=\"error\">Error:</span> ";
    if (preg_match("/^(?:[1-4]\\.|5\\.[012345])/", phpversion()))
        $m[] = $errmarker . "HotCRP requires PHP version 5.6 or higher.  You are running PHP version " . htmlspecialchars(phpversion()) . ".";
    $result = Dbl::qx_raw("show variables like 'max_allowed_packet'");
    $max_file_size = ini_get_bytes("upload_max_filesize");
    if (($row = edb_row($result))
        && $row[1] < $max_file_size
        && !$Conf->opt("dbNoPapers"))
        $m[] = $errmarker . "MySQL’s <code>max_allowed_packet</code> setting, which is " . htmlspecialchars($row[1]) . "&nbsp;bytes, is less than the PHP upload file limit, which is $max_file_size&nbsp;bytes.  You should update <code>max_allowed_packet</code> in the system-wide <code>my.cnf</code> file or the system may not be able to handle large papers.";
    if ($max_file_size < ini_get_bytes(null, $Conf->opt("upload_max_filesize", "10M")))
        $m[] = $errmarker . "PHP’s <code>upload_max_filesize</code> setting, which is <code>" . htmlspecialchars(ini_get("upload_max_filesize")) . "</code>, will limit submissions to at most $max_file_size&nbsp;bytes. Usually a larger limit, such as <code>10M</code>, is appropriate. Change this setting in HotCRP’s <code>.htaccess</code> and <code>.user.ini</code> files, change it in your global <code>php.ini</code> file, or silence this message by setting <code>\$Opt[\"upload_max_filesize\"] = \"" . htmlspecialchars(ini_get("upload_max_filesize")) . "\"</code> in <code>conf/options.php</code>.";
    $post_max_size = ini_get_bytes("post_max_size");
    if ($post_max_size < $max_file_size)
        $m[] = $errmarker . "PHP’s <code>post_max_size</code> setting is smaller than its <code>upload_max_filesize</code> setting. The <code>post_max_size</code> value should be at least as big. Change this setting in HotCRP’s <code>.htaccess</code> and <code>.user.ini</code> files or change it in your global <code>php.ini</code> file.";
    $memory_limit = ini_get_bytes("memory_limit");
    if ($post_max_size >= $memory_limit)
        $m[] = $errmarker . "PHP’s <code>memory_limit</code> setting is smaller than its <code>post_max_size</code> setting. The <code>memory_limit</code> value should be at least as big. Change this setting in HotCRP’s <code>.htaccess</code> and <code>.user.ini</code> files or change it in your global <code>php.ini</code> file.";
    if (get_magic_quotes_gpc())
        $m[] = $errmarker . "The PHP <code>magic_quotes_gpc</code> feature is on, which is a bad idea.  Check that your Web server is using HotCRP’s <code>.htaccess</code> file.  You may also want to disable <code>magic_quotes_gpc</code> in your <code>php.ini</code> configuration file.";
    if (get_magic_quotes_runtime())
        $m[] = $errmarker . "The PHP <code>magic_quotes_runtime</code> feature is on, which is a bad idea.  Check that your Web server is using HotCRP’s <code>.htaccess</code> file.  You may also want to disable <code>magic_quotes_runtime</code> in your <code>php.ini</code> configuration file.";
    if (defined("JSON_HOTCRP"))
        $m[] = "Your PHP was built without JSON functionality. HotCRP is using its built-in replacements; the native functions would be faster.";
    if ((int) ini_get("session.gc_maxlifetime") < $Conf->opt("sessionLifetime", 86400)
        && !isset($Conf->opt["sessionHandler"]))
        $m[] = "PHP’s systemwide <code>session.gc_maxlifetime</code> setting, which is " . htmlspecialchars(ini_get("session.gc_maxlifetime")) . " seconds, is less than HotCRP’s preferred session expiration time, which is " . $Conf->opt("sessionLifetime", 86400) . " seconds.  You should update <code>session.gc_maxlifetime</code> in the <code>php.ini</code> file or users may be booted off the system earlier than you expect.";
    if (!function_exists("imagecreate") && $Conf->setting("__gd_required"))
        $m[] = $errmarker . "This PHP installation lacks support for the GD library, so HotCRP can’t generate backup score charts for old browsers. Some of your users require this backup. You should update your PHP installation. For example, on Ubuntu Linux, install the <code>php" . PHP_MAJOR_VERSION . "-gd</code> package.";
    // Conference names
    if ($Conf->opt("shortNameDefaulted"))
        $m[] = "<a href=\"" . hoturl("settings", "group=basics") . "\">Set the conference abbreviation</a> to a short name for your conference, such as “OSDI ’14”.";
    else if (simplify_whitespace($Conf->short_name) != $Conf->short_name)
        $m[] = "The <a href=\"" . hoturl("settings", "group=basics") . "\">conference abbreviation</a> setting has a funny value. To fix it, remove leading and trailing spaces, use only space characters (no tabs or newlines), and make sure words are separated by single spaces (never two or more).";
    $site_contact = $Conf->site_contact();
    if (!$site_contact->email || $site_contact->email == "you@example.com")
        $m[] = "<a href=\"" . hoturl("settings", "group=basics") . "\">Set the conference contact’s name and email</a> so submitters can reach someone if things go wrong.";
    // Any -100 preferences around?
    $result = Dbl::ql_raw($Conf->preferenceConflictQuery(false, "limit 1"));
    if (($row = edb_row($result)))
        $m[] = "PC members have indicated paper conflicts (using review preferences of &#8722;100 or less) that aren’t yet confirmed. <a href='" . hoturl_post("conflictassign") . "' class='nw'>Confirm these conflicts</a>";
    // Weird URLs?
    foreach (array("conferenceSite", "paperSite") as $k)
        if (($url = $Conf->opt($k))
            && !preg_match('`\Ahttps?://(?:[-.~\w:/?#\[\]@!$&\'()*+,;=]|%[0-9a-fA-F][0-9a-fA-F])*\z`', $url))
            $m[] = $errmarker . "The <code>\$Opt[\"$k\"]</code> setting, ‘<code>" . htmlspecialchars($url) . "</code>’, is not a valid URL.  Edit the <code>conf/options.php</code> file to fix this problem.";
    // Unnotified reviews?
    if ($Conf->setting("pcrev_assigntime", 0) > $Conf->setting("pcrev_informtime", 0)) {
        $assigntime = $Conf->setting("pcrev_assigntime");
        $result = Dbl::qe_raw("select paperId from PaperReview where reviewType>" . REVIEW_PC . " and timeRequested>timeRequestNotified and reviewSubmitted is null and reviewNeedsSubmit!=0 limit 1");
        if (edb_nrows($result))
            $m[] = "PC review assignments have changed.&nbsp; <a href=\"" . hoturl("mail", "template=newpcrev") . "\">Send review assignment notifications</a> <span class=\"barsep\">·</span> <a href=\"" . hoturl_post("index", "clearnewpcrev=$assigntime") . "\">Clear this message</a>";
        else
            $Conf->save_setting("pcrev_informtime", $assigntime);
    }
    // Review round expired?
    if (count($Conf->round_list()) > 1 && $Conf->time_review_open()
        && $Conf->missed_review_deadline($Conf->assignment_round(false), true, false)) {
        $any_rounds_open = false;
        foreach ($Conf->defined_round_list() as $i => $rname)
            if (!$Conf->missed_review_deadline($i, true, false)
                && $Conf->setting($Conf->review_deadline($i, true, false))) {
                $m[] = "The deadline for review round " . htmlspecialchars($Conf->assignment_round_name(false) ? : "unnamed") . " has passed. You may want to <a href=\"" . hoturl("settings", "group=reviews") . "\">change the round for new assignments</a> to " . htmlspecialchars($rname) . ".";
                break;
            }
    }

    if (count($m))
        Conf::msg_warning($m, true);
}

assert($Me->privChair);

if (isset($Qreq->clearbug) && $Qreq->post_ok())
    $Conf->save_setting("bug_" . $Qreq->clearbug, null);
if (isset($Qreq->clearnewpcrev)
    && ctype_digit($Qreq->clearnewpcrev)
    && $Qreq->post_ok()
    && $Conf->setting("pcrev_informtime", 0) <= $Qreq->clearnewpcrev)
    $Conf->save_setting("pcrev_informtime", $Qreq->clearnewpcrev);
if (isset($Qreq->clearbug) || isset($Qreq->clearnewpcrev)) {
    unset($Qreq->clearbug, $Qreq->clearnewpcrev);
    SelfHref::redirect($Qreq);
}
admin_home_messages();
