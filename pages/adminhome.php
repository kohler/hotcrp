<?php
// adminhome.php -- HotCRP home page administrative messages
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function admin_home_messages() {
    global $Opt, $Conf;
    $m = array();
    $errmarker = "<span class=\"error\">Error:</span> ";
    if (preg_match("/^(?:[1-4]\\.|5\\.[0123])/", phpversion()))
        $m[] = $errmarker . "HotCRP requires PHP version 5.4 or higher.  You are running PHP version " . htmlspecialchars(phpversion()) . ".";
    if (get_magic_quotes_gpc())
        $m[] = $errmarker . "The PHP <code>magic_quotes_gpc</code> feature is on, which is a bad idea.  Check that your Web server is using HotCRP’s <code>.htaccess</code> file.  You may also want to disable <code>magic_quotes_gpc</code> in your <code>php.ini</code> configuration file.";
    if (get_magic_quotes_runtime())
        $m[] = $errmarker . "The PHP <code>magic_quotes_runtime</code> feature is on, which is a bad idea.  Check that your Web server is using HotCRP’s <code>.htaccess</code> file.  You may also want to disable <code>magic_quotes_runtime</code> in your <code>php.ini</code> configuration file.";
    if (defined("JSON_HOTCRP"))
        $m[] = "Your PHP was built without JSON functionality. HotCRP is using its built-in replacements; the native functions would be faster.";
    if ((int) $Opt["globalSessionLifetime"] < $Opt["sessionLifetime"])
        $m[] = "PHP’s systemwide <code>session.gc_maxlifetime</code> setting, which is " . htmlspecialchars($Opt["globalSessionLifetime"]) . " seconds, is less than HotCRP’s preferred session expiration time, which is " . $Opt["sessionLifetime"] . " seconds.  You should update <code>session.gc_maxlifetime</code> in the <code>php.ini</code> file or users may be booted off the system earlier than you expect.";
    if (!function_exists("imagecreate") && $Conf->setting("__gd_required"))
        $m[] = $errmarker . "This PHP installation lacks support for the GD library, so HotCRP can’t generate backup score charts for old browsers. Some of your users require this backup. You should update your PHP installation. For example, on Ubuntu Linux, install the <code>php" . PHP_MAJOR_VERSION . "-gd</code> package.";
    $result = Dbl::qx_raw("show variables like 'max_allowed_packet'");
    $max_file_size = ini_get_bytes("upload_max_filesize");
    if (($row = edb_row($result))
        && $row[1] < $max_file_size
        && !get($Opt, "dbNoPapers"))
        $m[] = $errmarker . "MySQL’s <code>max_allowed_packet</code> setting, which is " . htmlspecialchars($row[1]) . "&nbsp;bytes, is less than the PHP upload file limit, which is $max_file_size&nbsp;bytes.  You should update <code>max_allowed_packet</code> in the system-wide <code>my.cnf</code> file or the system may not be able to handle large papers.";
    // Conference names
    if (get($Opt, "shortNameDefaulted"))
        $m[] = "<a href=\"" . hoturl("settings", "group=basics") . "\">Set the conference abbreviation</a> to a short name for your conference, such as “OSDI ’14”.";
    else if (simplify_whitespace(Conf::$gShortName) != Conf::$gShortName)
        $m[] = "The <a href=\"" . hoturl("settings", "group=basics") . "\">conference abbreviation</a> setting has a funny value. To fix it, remove leading and trailing spaces, use only space characters (no tabs or newlines), and make sure words are separated by single spaces (never two or more).";
    $site_contact = Contact::site_contact();
    if (!$site_contact->email || $site_contact->email == "you@example.com")
        $m[] = "<a href=\"" . hoturl("settings", "group=basics") . "\">Set the conference contact’s name and email</a> so submitters can reach someone if things go wrong.";
    // Any -100 preferences around?
    $result = Dbl::ql_raw($Conf->preferenceConflictQuery(false, "limit 1"));
    if (($row = edb_row($result)))
        $m[] = "PC members have indicated paper conflicts (using review preferences of &#8722;100 or less) that aren’t yet confirmed. <a href='" . hoturl_post("autoassign", "a=prefconflict&amp;assign=1") . "' class='nowrap'>Confirm these conflicts</a>";
    // Weird URLs?
    foreach (array("conferenceSite", "paperSite") as $k)
        if (isset($Opt[$k]) && $Opt[$k] && !preg_match('`\Ahttps?://(?:[-.~\w:/?#\[\]@!$&\'()*+,;=]|%[0-9a-fA-F][0-9a-fA-F])*\z`', $Opt[$k]))
            $m[] = $errmarker . "The <code>\$Opt[\"$k\"]</code> setting, ‘<code>" . htmlspecialchars($Opt[$k]) . "</code>’, is not a valid URL.  Edit the <code>conf/options.php</code> file to fix this problem.";
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
        && $Conf->missed_review_deadline($Conf->current_round(), true, false)) {
        $any_rounds_open = false;
        foreach ($Conf->defined_round_list() as $i => $rname)
            if (!$any_rounds_open && !$Conf->missed_review_deadline($i, true, false)
                && $Conf->setting($Conf->review_deadline($i, true, false)))
                $any_rounds_open = $rname;
        if ($any_rounds_open)
            $m[] = "The deadline for the current review round, " . htmlspecialchars($Conf->current_round_name()) . ", has passed. You may want to <a href=\"" . hoturl("settings", "group=reviews") . "\">change the current round</a> to " . htmlspecialchars($any_rounds_open) . ".";
    }

    if (count($m))
        $Conf->warnMsg('<div class="multimessage"><div>' . join('</div><div>', $m) . "</div></div>");
}

assert($Me->privChair);

if (isset($_REQUEST["clearbug"]) && check_post())
    $Conf->save_setting("bug_" . $_REQUEST["clearbug"], null);
if (isset($_REQUEST["clearnewpcrev"]) && ctype_digit($_REQUEST["clearnewpcrev"])
    && check_post() && $Conf->setting("pcrev_informtime", 0) <= $_REQUEST["clearnewpcrev"])
    $Conf->save_setting("pcrev_informtime", $_REQUEST["clearnewpcrev"]);
if (isset($_REQUEST["clearbug"]) || isset($_REQUEST["clearnewpcrev"]))
    redirectSelf(array("clearbug" => null, "clearnewpcrev" => null));
admin_home_messages();
