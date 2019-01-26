<?php
// helpers.php -- HotCRP non-class helper functions
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

function mkarray($value) {
    if (is_array($value))
        return $value;
    else
        return array($value);
}


// string helpers

function cvtint($value, $default = -1) {
    $v = trim((string) $value);
    if (is_numeric($v)) {
        $ival = intval($v);
        if ($ival == floatval($v))
            return $ival;
    }
    return $default;
}

function cvtnum($value, $default = -1) {
    $v = trim((string) $value);
    if (is_numeric($v))
        return floatval($v);
    return $default;
}


// web helpers

function hoturl_add_raw($url, $component) {
    if (($pos = strpos($url, "#")) !== false) {
        $component .= substr($url, $pos);
        $url = substr($url, 0, $pos);
    }
    return $url . (strpos($url, "?") === false ? "?" : "&") . $component;
}

function hoturl($page, $options = null) {
    global $Conf;
    return $Conf->hoturl($page, $options);
}

function hoturl_post($page, $options = null) {
    global $Conf;
    return $Conf->hoturl($page, $options, Conf::HOTURL_POST);
}


class JsonResult {
    public $status;
    public $content;
    public $has_messages = false;

    function __construct($values = null) {
        if (is_int($values)) {
            $this->status = $values;
            if (func_num_args() === 2)
                $values = func_get_arg(1);
            else
                $values = null;
        }
        if ($values === true || $values === false)
            $this->content = ["ok" => $values];
        else if ($values === null)
            $this->content = [];
        else if (is_object($values)) {
            assert(!($values instanceof JsonResult));
            $this->content = (array) $values;
        } else if (is_string($values)) {
            if ($this->status && $this->status > 299)
                $this->content = ["ok" => false, "error" => $values];
            else
                $this->content = ["ok" => true, "response" => $values];
        } else
            $this->content = $values;
    }
    static function make($json, Contact $user = null, $arg2 = null) {
        if (is_int($json))
            $json = new JsonResult($json, $arg2);
        else if (!is_object($json) || !($json instanceof JsonResult))
            $json = new JsonResult($json);
        if (!$json->has_messages && $user)
            $json->take_messages($user);
        return $json;
    }
    function take_messages(Contact $user, $div = false) {
        if (session_id() !== ""
            && ($msgs = $user->session("msgs", []))) {
            $user->save_session("msgs", null);
            $t = "";
            foreach ($msgs as $msg) {
                if (($msg[0] === "merror" || $msg[0] === "xmerror")
                    && !isset($this->content["error"]))
                    $this->content["error"] = $msg[1];
                if ($div)
                    $t .= Ht::msg($msg[1], $msg[0]);
                else
                    $t .= "<span class=\"$msg[0]\">$msg[1]</span>";
            }
            if ($t !== "")
                $this->content["response"] = $t . get_s($this->content, "response");
            $this->has_messages = true;
        }
    }
    function export_errors() {
        if (isset($this->content["error"]))
            Conf::msg_error($this->content["error"]);
        if (isset($this->content["errf"])) {
            foreach ($this->content["errf"] as $f => $x)
                Ht::error_at($f);
        }
    }
}

class JsonResultException extends Exception {
    public $result;
    static public $capturing = false;
    function __construct($j) {
        $this->result = $j;
    }
}

function json_exit($json, $arg2 = null) {
    global $Me, $Qreq;
    $json = JsonResult::make($json, $Me ? : null, $arg2);
    if (JsonResultException::$capturing)
        throw new JsonResultException($json);
    else {
        if ($json->status)
            http_response_code($json->status);
        if (isset($_GET["text"]) && $_GET["text"])
            header("Content-Type: text/plain; charset=utf-8");
        else
            header("Content-Type: application/json; charset=utf-8");
        if ($Qreq && $Qreq->post_ok())
            header("Access-Control-Allow-Origin: *");
        echo json_encode_browser($json->content);
        exit;
    }
}

function csv_exit(CsvGenerator $csv) {
    $csv->download_headers();
    $csv->download();
    exit;
}

function foldupbutton($foldnum = 0, $content = "", $js = null) {
    if ($foldnum)
        $js["data-fold-target"] = $foldnum;
    $js["class"] = "ui q js-foldup";
    return Ht::link(expander(null, $foldnum) . $content, "#", $js);
}

function expander($open, $foldnum = null) {
    $f = $foldnum !== null;
    $foldnum = ($foldnum !== 0 ? $foldnum : "");
    $t = '<span class="expander">';
    if ($open === null || !$open)
        $t .= '<span class="in0' . ($f ? " fx$foldnum" : "") . '">' . Icons::ui_triangle(2) . '</span>';
    if ($open === null || $open)
        $t .= '<span class="in1' . ($f ? " fn$foldnum" : "") . '">' . Icons::ui_triangle(1) . '</span>';
    return $t . '</span>';
}

function actas_link($cid, $contact = null) {
    global $Conf;
    $contact = !$contact && is_object($cid) ? $cid : $contact;
    $cid = is_object($contact) ? $contact->email : $cid;
    return '<a href="' . $Conf->selfurl(null, ["actas" => $cid])
        . '" tabindex="-1">' . Ht::img("viewas.png", "[Act as]", array("title" => "Act as " . Text::name_text($contact))) . '</a>';
}

function decorateNumber($n) {
    if ($n < 0)
        return "&#8722;" . (-$n);
    else if ($n > 0)
        return $n;
    else
        return 0;
}


function _one_quicklink($id, $baseUrl, $urlrest, $listtype, $isprev) {
    if ($listtype == "u") {
        $result = Dbl::ql("select email from ContactInfo where contactId=?", $id);
        $row = edb_row($result);
        Dbl::free($result);
        $paperText = htmlspecialchars($row ? $row[0] : $id);
        $urlrest["u"] = urlencode($id);
    } else {
        $paperText = "#$id";
        $urlrest["p"] = $id;
    }
    return "<a id=\"quicklink-" . ($isprev ? "prev" : "next")
        . "\" class=\"x pnum\" href=\"" . hoturl($baseUrl, $urlrest) . "\">"
        . ($isprev ? Icons::ui_linkarrow(3) : "")
        . $paperText
        . ($isprev ? "" : Icons::ui_linkarrow(1))
        . "</a>";
}

function goPaperForm($baseUrl = null, $args = array()) {
    global $Conf, $Me;
    if ($Me->is_empty())
        return "";
    $list = $Conf->active_list();
    $x = Ht::form($Conf->hoturl($baseUrl ? : "paper"), ["method" => "get", "class" => "gopaper"]);
    if ($baseUrl == "profile")
        $x .= Ht::entry("u", "", array("id" => "quicklink-search", "size" => 15, "placeholder" => "User search", "aria-label" => "User search", "class" => "usersearch need-autogrow"));
    else
        $x .= Ht::entry("p", "", array("id" => "quicklink-search", "size" => 10, "placeholder" => "(All)", "aria-label" => "Search", "class" => "papersearch need-suggest need-autogrow"));
    foreach ($args as $k => $v)
        $x .= Ht::hidden($k, $v);
    $x .= "&nbsp; " . Ht::submit("Search") . "</form>";
    return $x;
}

function rm_rf_tempdir($tempdir) {
    assert(substr($tempdir, 0, 1) === "/");
    exec("/bin/rm -rf " . escapeshellarg($tempdir));
}

function clean_tempdirs() {
    $dir = sys_get_temp_dir() ? : "/";
    while (substr($dir, -1) === "/")
        $dir = substr($dir, 0, -1);
    $dirh = opendir($dir);
    $now = time();
    while (($fname = readdir($dirh)) !== false)
        if (preg_match('/\Ahotcrptmp\d+\z/', $fname)
            && is_dir("$dir/$fname")
            && ($mtime = @filemtime("$dir/$fname")) !== false
            && $mtime < $now - 1800)
            rm_rf_tempdir("$dir/$fname");
    closedir($dirh);
}

function tempdir($mode = 0700) {
    $dir = sys_get_temp_dir() ? : "/";
    while (substr($dir, -1) === "/")
        $dir = substr($dir, 0, -1);
    for ($i = 0; $i < 100; $i++) {
        $path = $dir . "/hotcrptmp" . mt_rand(0, 9999999);
        if (mkdir($path, $mode)) {
            register_shutdown_function("rm_rf_tempdir", $path);
            return $path;
        }
    }
    return false;
}


// text helpers
function commajoin($what, $joinword = "and") {
    $what = array_values($what);
    $c = count($what);
    if ($c == 0)
        return "";
    else if ($c == 1)
        return $what[0];
    else if ($c == 2)
        return $what[0] . " " . $joinword . " " . $what[1];
    else
        return join(", ", array_slice($what, 0, -1)) . ", " . $joinword . " " . $what[count($what) - 1];
}

function prefix_commajoin($what, $prefix, $joinword = "and") {
    return commajoin(array_map(function ($x) use ($prefix) {
        return $prefix . $x;
    }, $what), $joinword);
}

function numrangejoin($range) {
    $a = [];
    $format = null;
    foreach ($range as $current) {
        if ($format !== null
            && sprintf($format, $intval + 1) === (string) $current) {
            ++$intval;
            $last = $current;
            continue;
        } else {
            if ($format !== null && $first === $last)
                $a[] = $first;
            else if ($format !== null)
                $a[] = $first . "–" . substr($last, $plen);
            if ($current !== "" && ctype_digit($current)) {
                $format = "%0" . strlen($current) . "d";
                $plen = 0;
                $first = $last = $current;
                $intval = intval($current);
            } else if (preg_match('/\A(\D*)(\d+)\z/', $current, $m)) {
                $format = str_replace("%", "%%", $m[1]) . "%0" . strlen($m[2]) . "d";
                $plen = strlen($m[1]);
                $first = $last = $current;
                $intval = intval($m[2]);
            } else {
                $format = null;
                $a[] = $current;
            }
        }
    }
    if ($format !== null && $first === $last)
        $a[] = $first;
    else if ($format !== null)
        $a[] = $first . "–" . substr($last, $plen);
    return commajoin($a);
}

function pluralx($n, $what) {
    if (is_array($n))
        $n = count($n);
    return $n == 1 ? $what : pluralize($what);
}

function pluralize($what) {
    if ($what == "this")
        return "these";
    else if ($what == "has")
        return "have";
    else if ($what == "is")
        return "are";
    else if (str_ends_with($what, ")") && preg_match('/\A(.*?)(\s*\([^)]*\))\z/', $what, $m))
        return pluralize($m[1]) . $m[2];
    else if (preg_match('/\A.*?(?:s|sh|ch|[bcdfgjklmnpqrstvxz]y)\z/', $what)) {
        if (substr($what, -1) == "y")
            return substr($what, 0, -1) . "ies";
        else
            return $what . "es";
    } else
        return $what . "s";
}

function plural($n, $what) {
    return (is_array($n) ? count($n) : $n) . ' ' . pluralx($n, $what);
}

function ordinal($n) {
    $x = $n;
    if ($x > 100)
        $x = $x % 100;
    if ($x > 20)
        $x = $x % 10;
    return $n . ($x < 1 || $x > 3 ? "th" : ($x == 1 ? "st" : ($x == 2 ? "nd" : "rd")));
}

function tabLength($text, $all) {
    $len = 0;
    for ($i = 0; $i < strlen($text); $i++)
        if ($text[$i] == ' ')
            $len++;
        else if ($text[$i] == '\t')
            $len += 8 - ($len % 8);
        else if (!$all)
            break;
        else
            $len++;
    return $len;
}

function ini_get_bytes($varname, $value = null) {
    $val = trim($value !== null ? $value : ini_get($varname));
    $last = strlen($val) ? strtolower($val[strlen($val) - 1]) : ".";
    return (int) ceil(floatval($val) * (1 << (+strpos(".kmg", $last) * 10)));
}

function filter_whynot($whyNot, $keys) {
    $revWhyNot = [];
    foreach ($whyNot as $k => $v) {
        if ($k === "fail" || $k === "paperId" || $k === "conf" || in_array($k, $keys))
            $revWhyNot[$k] = $v;
    }
    return $revWhyNot;
}

function whyNotText($whyNot, $text_only = false) {
    global $Conf, $Now;
    if (is_string($whyNot))
        $whyNot = array($whyNot => 1);
    $conf = get($whyNot, "conf") ? : $Conf;
    $paperId = (isset($whyNot["paperId"]) ? $whyNot["paperId"] : -1);
    $reviewId = (isset($whyNot["reviewId"]) ? $whyNot["reviewId"] : -1);
    $ms = [];
    $quote = $text_only ? function ($x) { return $x; } : "htmlspecialchars";
    if (isset($whyNot["invalidId"])) {
        $x = $whyNot["invalidId"] . "Id";
        if (isset($whyNot[$x]))
            $ms[] = $conf->_("Invalid " . $whyNot["invalidId"] . " number “%s”.", $quote($whyNot[$x]));
        else
            $ms[] = $conf->_("Invalid " . $whyNot["invalidId"] . " number.");
    }
    if (isset($whyNot["noPaper"]))
        $ms[] = $conf->_("No such submission #%d.", $paperId);
    if (isset($whyNot["dbError"]))
        $ms[] = $whyNot["dbError"];
    if (isset($whyNot["administer"]))
        $ms[] = $conf->_("You can’t administer submission #%d.", $paperId);
    if (isset($whyNot["permission"])) {
        if ($whyNot["permission"] === "view_option")
            $ms[] = $conf->_c("eperm", "Permission error.", $whyNot["permission"], $paperId, $quote($whyNot["optionPermission"]->message_title));
        else
            $ms[] = $conf->_c("eperm", "Permission error.", $whyNot["permission"], $paperId);
    }
    if (isset($whyNot["optionNotAccepted"]))
        $ms[] = $conf->_("%2\$s is reserved for accepted submissions.", $paperId, $quote($whyNot["optionNotAccepted"]->message_title));
    if (isset($whyNot["documentNotFound"]))
        $ms[] = $conf->_("No such document “%s”.", $quote($whyNot["documentNotFound"]));
    if (isset($whyNot["signin"]))
        $ms[] = $conf->_c("eperm", "You have been signed out.", $whyNot["signin"], $paperId);
    if (isset($whyNot["withdrawn"]))
        $ms[] = $conf->_("Submission #%d has been withdrawn.", $paperId);
    if (isset($whyNot["notWithdrawn"]))
        $ms[] = $conf->_("Submission #%d is not withdrawn.", $paperId);
    if (isset($whyNot["notSubmitted"]))
        $ms[] = $conf->_("Submission #%d is only a draft.", $paperId);
    if (isset($whyNot["rejected"]))
        $ms[] = $conf->_("Submission #%d was not accepted for publication.", $paperId);
    if (isset($whyNot["reviewsSeen"]))
        $ms[] = $conf->_("You cannot withdraw a submission after seeing its reviews.", $paperId);
    if (isset($whyNot["decided"]))
        $ms[] = $conf->_("The review process for submission #%d has completed.", $paperId);
    if (isset($whyNot["updateSubmitted"]))
        $ms[] = $conf->_("Submission #%d can no longer be updated.", $paperId);
    if (isset($whyNot["notUploaded"]))
        $ms[] = $conf->_("A PDF upload is required to submit.");
    if (isset($whyNot["reviewNotSubmitted"]))
        $ms[] = $conf->_("This review is not yet ready for others to see.");
    if (isset($whyNot["reviewNotComplete"]))
        $ms[] = $conf->_("Your own review for #%d is not complete, so you can’t view other people’s reviews.", $paperId);
    if (isset($whyNot["responseNotReady"]))
        $ms[] = $conf->_("The authors’ response is not yet ready for reviewers to view.");
    if (isset($whyNot["reviewsOutstanding"])) {
        $ms[] = $conf->_("You will get access to the reviews once you complete your assigned reviews. If you can’t complete your reviews, please let the organizers know via the “Refuse review” links.");
        if (!$text_only)
            $ms[] = $conf->_("<a href=\"%s\">List assigned reviews</a>", hoturl("search", "q=&amp;t=r"));
    }
    if (isset($whyNot["reviewNotAssigned"]))
        $ms[] = $conf->_("You are not assigned to review submission #%d.", $paperId);
    if (isset($whyNot["deadline"])) {
        $dname = $whyNot["deadline"];
        if ($dname[0] === "s")
            $open_dname = "sub_open";
        else if ($dname[0] === "p" || $dname[0] === "e")
            $open_dname = "rev_open";
        else
            $open_dname = false;
        $start = $open_dname ? $conf->setting($open_dname, -1) : 1;
        if ($dname === "extrev_chairreq")
            $end_dname = $conf->review_deadline(get($whyNot, "reviewRound"), false, true);
        else
            $end_dname = $dname;
        $end = $conf->setting($end_dname, -1);
        if ($dname == "au_seerev") {
            if ($conf->au_seerev == Conf::AUSEEREV_UNLESSINCOMPLETE) {
                $ms[] = $conf->_("You will get access to the reviews for this submission when you have completed your own reviews.");
                if (!$text_only)
                    $ms[] = $conf->_("<a href=\"%s\">List your incomplete reviews</a>", hoturl("search", "t=rout&amp;q="));
            } else
                $ms[] = $conf->_c("etime", "Action not available.", $dname, $paperId);
        } else if ($start <= 0 || $start == $end) {
            $ms[] = $conf->_c("etime", "Action not available.", $open_dname, $paperId);
        } else if ($start > 0 && $Now < $start) {
            $ms[] = $conf->_c("etime", "Action not available until %3$s.", $open_dname, $paperId, $conf->unparse_time($start, "span"));
        } else if ($end > 0 && $Now > $end) {
            $ms[] = $conf->_c("etime", "Deadline passed.", $dname, $paperId, $conf->unparse_time($end, "span"));
        } else {
            $ms[] = $conf->_c("etime", "Action not available.", $dname, $paperId);
        }
    }
    if (isset($whyNot["override"]))
        $ms[] = $conf->_("“Override deadlines” can override this restriction.");
    if (isset($whyNot["blindSubmission"]))
        $ms[] = $conf->_("Submission to this conference is blind.");
    if (isset($whyNot["author"]))
        $ms[] = $conf->_("You aren’t a contact for #%d.", $paperId);
    if (isset($whyNot["conflict"]))
        $ms[] = $conf->_("You have a conflict with #%d.", $paperId);
    if (isset($whyNot["externalReviewer"]))
        $ms[] = $conf->_("External reviewers cannot view other reviews.");
    if (isset($whyNot["differentReviewer"]))
        $ms[] = $conf->_("You didn’t write this review, so you can’t change it.");
    if (isset($whyNot["unacceptableReviewer"]))
        $ms[] = $conf->_("That user can’t be assigned to review #%d.", $paperId);
    if (isset($whyNot["clickthrough"]))
        $ms[] = $conf->_("You can’t do that until you agree to the terms.");
    if (isset($whyNot["otherTwiddleTag"]))
        $ms[] = $conf->_("Tag “#%s” doesn’t belong to you.", $quote($whyNot["tag"]));
    if (isset($whyNot["chairTag"]))
        $ms[] = $conf->_("Tag “#%s” can only be changed by administrators.", $quote($whyNot["tag"]));
    if (isset($whyNot["voteTag"]))
        $ms[] = $conf->_("The voting tag “#%s” shouldn’t be changed directly. To vote for this paper, change the “#~%1\$s” tag.", $quote($whyNot["tag"]));
    if (isset($whyNot["voteTagNegative"]))
        $ms[] = $conf->_("Negative votes aren’t allowed.");
    if (isset($whyNot["autosearchTag"]))
        $ms[] = $conf->_("Tag “#%s” cannot be changed since the system sets it automatically.", $quote($whyNot["tag"]));
    if (empty($ms) && isset($whyNot["fail"]))
        $ms[] = $conf->_c("eperm", "Permission error.", "unknown", $paperId);
    // finish it off
    if (isset($whyNot["forceShow"]) && !$text_only)
        $ms[] = $conf->_("<a class=\"nw\" href=\"%s\">Override conflict</a>", $conf->selfurl(null, ["forceShow" => 1]));
    if (!empty($ms) && isset($whyNot["listViewable"]) && !$text_only)
        $ms[] = $conf->_("<a href=\"%s\">List the submissions you can view</a>", hoturl("search", "q="));
    return join(" ", $ms);
}

function actionBar($mode = null, $qreq = null) {
    global $Me, $Conf;
    $forceShow = ($Me->is_admin_force() ? "&amp;forceShow=1" : "");

    $paperArg = "p=*";
    $xmode = array();
    $listtype = "p";

    $goBase = "paper";
    if ($mode == "assign")
        $goBase = "assign";
    else if ($mode == "re")
        $goBase = "review";
    else if ($mode == "account") {
        $listtype = "u";
        if ($Me->privChair) {
            $goBase = "profile";
            $xmode["search"] = 1;
        }
    } else if ($qreq && ($qreq->m || $qreq->mode))
        $xmode["m"] = $qreq->m ? : $qreq->mode;

    // quicklinks
    $x = "";
    if (($list = $Conf->active_list())) {
        $x .= '<td class="vbar quicklinks">';
        if (($prev = $list->neighbor_id(-1)) !== false)
            $x .= _one_quicklink($prev, $goBase, $xmode, $listtype, true) . " ";
        if ($list->description) {
            $url = $list->full_site_relative_url();
            if ($url)
                $x .= '<a id="quicklink-list" class="x" href="' . htmlspecialchars(Navigation::siteurl() . $url) . "\">" . $list->description . "</a>";
            else
                $x .= '<span id="quicklink-list">' . $list->description . '</span>';
        }
        if (($next = $list->neighbor_id(1)) !== false)
            $x .= " " . _one_quicklink($next, $goBase, $xmode, $listtype, false);
        $x .= '</td>';

        if ($Me->is_track_manager() && $listtype == "p")
            $x .= '<td id="tracker-connect" class="vbar"><a id="tracker-connect-btn" class="ui js-tracker tbtn need-tooltip" href="" aria-label="Start meeting tracker">&#9759;</a><td>';
    }

    // paper search form
    if ($Me->isPC || $Me->is_reviewer() || $Me->is_author())
        $x .= '<td class="vbar gopaper">' . goPaperForm($goBase, $xmode) . '</td>';

    return $x ? '<table class="vbar"><tr>' . $x . '</tr></table>' : '';
}

function parseReviewOrdinal($t) {
    $t = strtoupper($t);
    if (!ctype_alpha($t))
        return -1;
    $l = strlen($t) - 1;
    $ord = 0;
    $base = 1;
    while (true) {
        $ord += (ord($t[$l]) - 64) * $base;
        if ($l === 0)
            break;
        --$l;
        $base *= 26;
    }
    return $ord;
}

function unparseReviewOrdinal($ord) {
    if (!$ord)
        return ".";
    else if (is_object($ord)) {
        if ($ord->reviewOrdinal)
            return $ord->paperId . unparseReviewOrdinal($ord->reviewOrdinal);
        else
            return $ord->reviewId;
    } else if ($ord <= 26) {
        return chr($ord + 64);
    } else {
        $t = "";
        while (true) {
            $t = chr((($ord - 1) % 26) + 65) . $t;
            if ($ord <= 26)
                return $t;
            $ord = intval(($ord - 1) / 26);
        }
    }
}

function downloadText($text, $filename, $inline = false) {
    global $Conf;
    $csvg = new CsvGenerator(CsvGenerator::TYPE_TAB);
    $csvg->set_filename($Conf->download_prefix . $filename . $csvg->extension());
    $csvg->set_inline($inline);
    $csvg->download_headers();
    if ($text !== false) {
        $csvg->add_string($text);
        $csvg->download();
        exit;
    }
}

function unparse_expertise($expertise) {
    if ($expertise === null)
        return "";
    else
        return $expertise > 0 ? "X" : ($expertise == 0 ? "Y" : "Z");
}

function unparse_preference($preference, $expertise = null) {
    if (is_object($preference))
        list($preference, $expertise) = array(get($preference, "reviewerPreference"),
                                              get($preference, "reviewerExpertise"));
    else if (is_array($preference))
        list($preference, $expertise) = $preference;
    if ($preference === null || $preference === false)
        $preference = "0";
    return $preference . unparse_expertise($expertise);
}

function unparse_preference_span($preference, $always = false) {
    if (is_object($preference))
        $preference = array(get($preference, "reviewerPreference"),
                            get($preference, "reviewerExpertise"),
                            get($preference, "topicInterestScore"));
    else if (!is_array($preference))
        $preference = array($preference, null, null);
    $pv = (int) get($preference, 0);
    $ev = get($preference, 1);
    $tv = (int) get($preference, 2);
    $type = 1;
    if ($pv < 0 || (!$pv && $tv < 0))
        $type = -1;
    $t = "";
    if ($pv || $ev !== null || $always)
        $t .= "P" . decorateNumber($pv) . unparse_expertise($ev);
    if ($tv && !$pv)
        $t .= ($t ? " " : "") . "T" . decorateNumber($tv);
    if ($t !== "")
        $t = " <span class=\"asspref$type\">$t</span>";
    return $t;
}

function review_type_icon($revtype, $unfinished = null, $title = null,
                          $classes = null) {
    // see also script.js:review_form
    static $revtypemap = [
        -3 => ["&minus;", "Refused"],
        -2 => ["A", "Author"],
        -1 => ["C", "Conflict"],
        1 => ["E", "External review"],
        2 => ["P", "PC review"],
        3 => ["2", "Secondary review"],
        4 => ["1", "Primary review"],
        5 => ["M", "Metareview"]
    ];
    if (!$revtype) {
        if ($classes)
            return '<span class="rt0"></span>';
        else
            return '<span class="rt0 ' . $classes . '"></span>';
    }
    $x = $revtypemap[$revtype];
    return '<span class="rto rt' . $revtype
        . ($revtype > 0 && $unfinished ? "n" : "")
        . ($classes ? " " . $classes : "")
        . '" title="' . ($title ? : $x[1])
        . '"><span class="rti">' . $x[0] . '</span></span>';
}

function review_lead_icon() {
    return '<span class="rto rtlead" title="Lead"><span class="rti">L</span></span>';
}

function review_shepherd_icon() {
    return '<span class="rto rtshep" title="Shepherd"><span class="rti">S</span></span>';
}

function displayOptionsSet($sessionvar, $var = null, $val = null) {
    global $Conf, $Me;
    if (($x = $Me->session($sessionvar)) !== null)
        /* use session value */;
    else if ($sessionvar === "pldisplay")
        $x = $Conf->setting_data("pldisplay_default", "");
    else
        $x = "";
    if ($x == null || strpos($x, " ") === false) {
        if ($sessionvar == "pldisplay")
            $x = $Conf->review_form()->default_display();
        else if ($sessionvar == "uldisplay")
            $x = " tags overAllMerit ";
        else
            $x = " ";
    }

    // set $var to $val in list
    if ($var) {
        $x = str_replace(" $var ", " ", $x);
        if ($val)
            $x .= "$var ";
        if (($sessionvar === "pldisplay" || $sessionvar === "pfdisplay")
            && ($f = $Conf->find_review_field($var))
            && $var !== $f->id)
            $x = str_replace(" {$f->id} ", " ", $x);
    }

    // store list in $_SESSION
    $Me->save_session($sessionvar, $x);
    return $x;
}


if (!function_exists("random_bytes")) {
    // PHP 5.6
    function random_bytes($length) {
        $x = @file_get_contents("/dev/urandom", false, null, 0, $length);
        if (($x === false || $x === "")
            && function_exists("openssl_random_pseudo_bytes")) {
            $x = openssl_random_pseudo_bytes($length, $strong);
            $x = $strong ? $x : false;
        }
        if ($x === false || $x === "")
            throw new Exception("Cannot obtain $length random bytes");
        return $x;
    }
}

function hotcrp_random_password($length = 14) {
    $bytes = random_bytes($length + 10);
    $l = "a e i o u y a e i o u y a e i o u y a e i o u y a e i o u y b c d g h j k l m n p r s t u v w trcrbrfrthdrchphwrstspswprslcl2 3 4 5 6 7 8 9 - @ _ + = ";
    $pw = "";
    $nvow = 0;
    for ($i = 0;
         $i < strlen($bytes) &&
             strlen($pw) < $length + max(0, ($nvow - 3) / 3);
         ++$i) {
        $x = ord($bytes[$i]) % (strlen($l) / 2);
        if ($x < 30)
            ++$nvow;
        $pw .= rtrim(substr($l, 2 * $x, 2));
    }
    return $pw;
}


function encode_token($x, $format = "") {
    $s = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    $t = "";
    if (is_int($x))
        $format = "V";
    if ($format)
        $x = pack($format, $x);
    $i = 0;
    $have = 0;
    $n = 0;
    while ($have > 0 || $i < strlen($x)) {
        if ($have < 5 && $i < strlen($x)) {
            $n += ord($x[$i]) << $have;
            $have += 8;
            ++$i;
        }
        $t .= $s[$n & 31];
        $n >>= 5;
        $have -= 5;
    }
    if ($format === "V")
        return preg_replace('/(\AA|[^A])A*\z/', '$1', $t);
    else
        return $t;
}

function decode_token($x, $format = "") {
    $map = "//HIJKLMNO///////01234567/89:;</=>?@ABCDEFG";
    $t = "";
    $n = $have = 0;
    $x = trim(strtoupper($x));
    for ($i = 0; $i < strlen($x); ++$i) {
        $o = ord($x[$i]);
        if ($o >= 48 && $o <= 90 && ($out = ord($map[$o - 48])) >= 48)
            $o = $out - 48;
        else if ($o === 46 /*.*/ || $o === 34 /*"*/)
            continue;
        else
            return false;
        $n += $o << $have;
        $have += 5;
        while ($have >= 8 || ($n && $i === strlen($x) - 1)) {
            $t .= chr($n & 255);
            $n >>= 8;
            $have -= 8;
        }
    }
    if ($format == "V") {
        $x = unpack("Vx", $t . "\x00\x00\x00\x00\x00\x00\x00");
        return $x["x"];
    } else if ($format)
        return unpack($format, $t);
    else
        return $t;
}
