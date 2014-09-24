<?php
// helpers.php -- HotCRP non-class helper functions
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function defappend(&$var, $str) {
    if (!isset($var))
        $var = "";
    $var .= $str;
}

function arrayappend(&$var, $value) {
    if (isset($var))
        $var[] = $value;
    else
        $var = array($value);
}

function set_error_html($x, $error_html = null) {
    if (!$error_html) {
        $error_html = $x;
        $x = (object) array();
    }
    $x->error = true;
    $x->error_html = $error_html;
    return $x;
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

function mkarray($value) {
    if (is_array($value))
        return $value;
    else
        return array($value);
}

if (function_exists("mb_check_encoding")) {
    function is_valid_utf8($str) {
        return @mb_check_encoding($str, "UTF-8");
    }
} else if (function_exists("iconv")) {
    // Aren't these hoops delicious?
    function _is_valid_utf8_error_handler($errno, $errstr) {
        global $_is_valid_utf8_result;
        $_is_valid_utf8_result = false;
        return false;
    }
    function is_valid_utf8($str) {
        global $_is_valid_utf8_result;
        $_is_valid_utf8_result = true;
        set_error_handler("_is_valid_utf8_error_handler");
        @iconv("UTF-8", "UTF-8", $str); // possible E_NOTICE captured above
        restore_error_handler();
        return $_is_valid_utf8_result;
        // While it might also work to compare iconv's return value to the
        // original string, who knows whether iconv canonicalizes composed
        // Unicode character sequences or something?  Safer to check for
        // errors.
    }
} else {
    function is_valid_utf8($str) {
        return true;            // give up
    }
}

if (function_exists("iconv")) {
    function windows_1252_to_utf8($str) {
        return iconv("Windows-1252", "UTF-8//IGNORE", $str);
    }
    function mac_os_roman_to_utf8($str) {
        return iconv("Mac", "UTF-8//IGNORE", $str);
    }
} else {
    function windows_1252_to_utf8($str) {
        return $str;            // give up
    }
    function mac_os_roman_to_utf8($str) {
        return $str;            // give up
    }
}

function convert_to_utf8($str) {
    if (substr_count(substr($str, 0, 5000), "\r")
        > 1.5 * substr_count(substr($str, 0, 5000), "\n"))
        return mac_os_roman_to_utf8($str);
    else
        return windows_1252_to_utf8($str);
}

if (function_exists("iconv")) {
    function utf8_substr($str, $off, $len) {
        return iconv_substr($str, $off, $len, "UTF-8");
    }
} else if (function_exists("mb_substr")) {
    function utf8_substr($str, $off, $len) {
        return mb_substr($str, $off, $len, "UTF-8");
    }
} else {
    function utf8_substr($str, $off, $len) {
        $x = substr($str, $off, $len);
        $poff = 0;
        while (($n = preg_match_all("/[\200-\277]/", $x, $m, PREG_PATTERN_ORDER, $poff))) {
            $poff = strlen($x);
            $x .= substr($str, $poff, $n);
        }
        if (preg_match("/\\A([\200-\277]+)/", substr($str, strlen($x)), $m))
            $x .= $m[1];
        return $x;
    }
}


// web helpers

global $_hoturl_defaults;
$_hoturl_defaults = null;

function hoturl_defaults($options = array()) {
    global $_hoturl_defaults;
    foreach ($options as $k => $v)
        if ($v !== null)
            $_hoturl_defaults[$k] = urlencode($v);
        else
            unset($_hoturl_defaults[$k]);
    $ret = array();
    if ($_hoturl_defaults)
        foreach ($_hoturl_defaults as $k => $v)
            $ret[$k] = urldecode($v);
    return $ret;
}

function hoturl_site_relative($page, $options = null) {
    global $ConfSiteSuffix, $Opt, $Me, $paperTable, $CurrentList, $_hoturl_defaults;
    $t = $page . $ConfSiteSuffix;
    // parse options, separate anchor; see also redirectSelf
    $anchor = "";
    if ($options && is_array($options)) {
        $x = "";
        foreach ($options as $k => $v)
            if ($v !== null && $k !== "anchor")
                $x .= ($x === "" ? "" : "&amp;") . $k . "=" . urlencode($v);
            else if ($v !== null)
                $anchor = "#" . urlencode($v);
        $options = $x;
    } else if (preg_match('/\A(.*?)(#.*)\z/', $options, $m))
        list($options, $anchor) = array($m[1], $m[2]);
    // append defaults
    $are = '/\A(|.*?(?:&|&amp;))';
    $zre = '(?:&(?:amp;)?|\z)(.*)\z/';
    if ($_hoturl_defaults)
        foreach ($_hoturl_defaults as $k => $v)
            if (!preg_match($are . preg_quote($k) . '=/', $options))
                $options .= "&amp;" . $k . "=" . $v;
    // append forceShow to links to same paper if appropriate
    $is_paper_page = preg_match('/\A(?:paper|review|comment|assign)\z/', $page);
    if (@$paperTable && $paperTable->prow && $is_paper_page
        && preg_match($are . 'p=' . $paperTable->prow->paperId . $zre, $options)
        && $Me->can_administer($paperTable->prow)
        && $paperTable->prow->has_conflict($Me)
        && !preg_match($are . 'forceShow=/', $options))
        $options .= "&amp;forceShow=1";
    if (@$paperTable && $paperTable->prow && $is_paper_page
        && @$CurrentList && $CurrentList > 0
        && !preg_match($are . 'ls=/', $options))
        $options .= "&amp;ls=$CurrentList";
    // create slash-based URLs if appropriate
    if ($options && !@$Opt["disableSlashURLs"]) {
        if ($page == "review"
            && preg_match($are . 'r=(\d+[A-Z]+)' . $zre, $options, $m)) {
            $t .= "/" . $m[2];
            $options = $m[1] . $m[3];
            if (preg_match($are . 'p=\d+' . $zre, $options, $m))
                $options = $m[1] . $m[2];
        } else if (($is_paper_page
                    && preg_match($are . 'p=(\d+|%\w+%|new)' . $zre, $options, $m))
                   || ($page == "profile"
                       && preg_match($are . 'u=([^&]+)' . $zre, $options, $m))
                   || ($page == "help"
                       && preg_match($are . 't=(\w+)' . $zre, $options, $m))
                   || ($page == "settings"
                       && preg_match($are . 'group=(\w+)' . $zre, $options, $m))
                   || ($page == "doc"
                       && preg_match($are . 'file=([^&]+)' . $zre, $options, $m))
                   || preg_match($are . '__PATH__=([^&]+)' . $zre, $options, $m)) {
            $t .= "/" . str_replace("%2F", "/", $m[2]);
            $options = $m[1] . $m[3];
        }
        $options = preg_replace('/&(?:amp;)?\z/', "", $options);
    }
    if ($options && preg_match('/\A&(?:amp;)?(.*)\z/', $options, $m))
        $options = $m[1];
    if ($options)
        return $t . "?" . $options . $anchor;
    else
        return $t . $anchor;
}

function hoturl($page, $options = null) {
    global $ConfSiteBase, $ConfSiteSuffix;
    $t = hoturl_site_relative($page, $options);
    if ($page !== "index" || substr($t, 5 + strlen($ConfSiteSuffix), 1) == "/")
        return $ConfSiteBase . $t;
    else {
        $trail = substr($t, 5 + strlen($ConfSiteSuffix));
        if ($ConfSiteBase !== "")
            return $ConfSiteBase . $trail;
        else
            return Navigation::site_path() . $trail;
    }
}

function hoturl_post($page, $options = null) {
    if (is_array($options))
        $options["post"] = post_value();
    else if ($options)
        $options .= "&amp;post=" . post_value();
    else
        $options = "post=" . post_value();
    return hoturl($page, $options);
}

function hoturl_absolute($page, $options = null) {
    global $Opt;
    return $Opt["paperSite"] . "/" . hoturl_site_relative($page, $options);
}

function hoturl_absolute_nodefaults($page, $options = null) {
    global $Opt, $_hoturl_defaults;
    $defaults = $_hoturl_defaults;
    $_hoturl_defaults = null;
    $url = hoturl_absolute($page, $options);
    $_hoturl_defaults = $defaults;
    return $url;
}

function hoturl_site_relative_raw($page, $options = null) {
    return htmlspecialchars_decode(hoturl_site_relative($page, $options));
}

function hoturl_raw($page, $options = null) {
    return htmlspecialchars_decode(hoturl($page, $options));
}

function hoturl_post_raw($page, $options = null) {
    return htmlspecialchars_decode(hoturl_post($page, $options));
}


function fileUploaded(&$var) {
    global $Conf;
    if (!isset($var) || ($var['error'] != UPLOAD_ERR_OK && !$Conf))
        return false;
    switch ($var['error']) {
    case UPLOAD_ERR_OK:
        return is_uploaded_file($var['tmp_name']);
    case UPLOAD_ERR_NO_FILE:
        return false;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
        $Conf->errorMsg("You tried to upload a file that’s too big for our system to accept.  The maximum size is " . ini_get("upload_max_filesize") . "B.");
        return false;
    case UPLOAD_ERR_PARTIAL:
        $Conf->errorMsg("You appear to have interrupted the upload process; I am not storing that file.");
        return false;
    default:
        $Conf->errorMsg("Internal upload error " . $var['error'] . "!");
        return false;
    }
}

function selfHref($extra = array(), $options = null) {
    global $CurrentList, $ConfSiteSuffix, $Opt;
    // clean parameters from pathinfo URLs
    foreach (array("paperId" => "p", "pap" => "p", "reviewId" => "r", "commentId" => "c") as $k => $v)
        if (isset($_REQUEST[$k]) && !isset($_REQUEST[$v]))
            $_REQUEST[$v] = $_REQUEST[$k];

    $param = "";
    foreach (array("p", "r", "c", "m", "u", "mode", "forceShow", "validator", "ls", "list", "t", "q", "qa", "qo", "qx", "qt", "tab", "atab", "group", "sort", "monreq", "noedit", "contact", "reviewer") as $what)
        if (isset($_REQUEST[$what]) && !array_key_exists($what, $extra)
            && !is_array($_REQUEST[$what]))
            $param .= "&$what=" . urlencode($_REQUEST[$what]);
    foreach ($extra as $key => $value)
        if ($key != "anchor" && $value !== null)
            $param .= "&$key=" . urlencode($value);
    if (isset($CurrentList) && $CurrentList > 0
        && !isset($_REQUEST["ls"]) && !array_key_exists("ls", $extra))
        $param .= "&ls=" . $CurrentList;

    $param = $param ? substr($param, 1) : "";
    if (!$options || !@$options["site_relative"])
        $uri = hoturl(Navigation::page(), $param);
    else
        $uri = hoturl_site_relative(Navigation::page(), $param);
    if (isset($extra["anchor"]))
        $uri .= "#" . $extra["anchor"];
    $uri = str_replace("&amp;", "&", $uri);
    if (!$options || @$options["raw"])
        return $uri;
    else
        return htmlspecialchars($uri);
}

function redirectSelf($extra = array()) {
    go(selfHref($extra, array("raw" => true)));
}

function foldbutton($foldtype, $foldnum = 0) {
    $foldnumid = ($foldnum ? ",$foldnum" : "");
    return '<a href="#" class="q" onclick="return fold(\''
        . $foldtype . '\',null' . $foldnumid . ')">'
        . expander(null, $foldnum) . '</a>';
}

function expander($open, $foldnum = null) {
    $f = $foldnum !== null;
    $foldnum = ($foldnum !== 0 ? $foldnum : "");
    $t = '<span class="expander">';
    if ($open === null || !$open)
        $t .= '<span class="in0' . ($f ? " fx$foldnum" : "") . '">&#x25BC;</span>';
    if ($open === null || $open)
        $t .= '<span class="in1' . ($f ? " fn$foldnum" : "") . '">&#x25B6;</span>';
    return $t . '</span>';
}

function reviewType($paperId, $row, $long = 0) {
    if ($row->reviewType == REVIEW_PRIMARY)
        return "<span class='rtype rtype_pri'>Primary</span>";
    else if ($row->reviewType == REVIEW_SECONDARY)
        return "<span class='rtype rtype_sec'>Secondary</span>";
    else if ($row->reviewType == REVIEW_EXTERNAL)
        return "<span class='rtype rtype_req'>External</span>";
    else if ($row->conflictType >= CONFLICT_AUTHOR)
        return "<span class='author'>Author</span>";
    else if ($row->conflictType > 0)
        return "<span class='conflict'>Conflict</span>";
    else if (!($row->reviewId === null) || $long)
        return "<span class='rtype rtype_pc'>PC</span>";
    else
        return "";
}

function documentDownload($doc, $dlimg_class = "dlimg", $text = null) {
    global $Conf;
    $p = HotCRPDocument::url($doc);
    $finalsuffix = ($doc->documentType == DTYPE_FINAL ? "f" : "");
    $sp = "&nbsp;";
    $imgsize = ($dlimg_class[0] == "s" ? "" : "24");
    if ($doc->mimetype == "application/postscript")
        $x = "<a href=\"$p\" class='q nowrap'>" . Ht::img("postscript${finalsuffix}${imgsize}.png", "[PS]", $dlimg_class);
    else if ($doc->mimetype == "application/pdf")
        $x = "<a href=\"$p\" class='q nowrap'>" . Ht::img("pdf${finalsuffix}${imgsize}.png", "[PDF]", $dlimg_class);
    else
        $x = "<a href=\"$p\" class='q nowrap'>" . Ht::img("generic${finalsuffix}${imgsize}.png", "[Download]", $dlimg_class);
    if ($text)
        $x .= $sp . $text;
    if (isset($doc->size) && $doc->size > 0) {
        $x .= "&nbsp;<span class='dlsize'>" . ($text ? "(" : "");
        if ($doc->size > 921)
            $x .= round($doc->size / 1024);
        else
            $x .= max(round($doc->size / 102.4), 1) / 10;
        $x .= "kB" . ($text ? ")" : "") . "</span>";
    }
    return $x . "</a>";
}

function paperDocumentData($prow, $documentType = DTYPE_SUBMISSION, $paperStorageId = 0) {
    global $Conf, $Opt;
    assert($paperStorageId || $documentType == DTYPE_SUBMISSION || $documentType == DTYPE_FINAL);
    if ($documentType == DTYPE_FINAL && $prow->finalPaperStorageId <= 0)
        $documentType = DTYPE_SUBMISSION;
    if ($paperStorageId == 0 && $documentType == DTYPE_FINAL)
        $paperStorageId = $prow->finalPaperStorageId;
    else if ($paperStorageId == 0)
        $paperStorageId = $prow->paperStorageId;
    if ($paperStorageId <= 1)
        return null;

    // pre-load document object from paper
    $doc = (object) array("paperId" => $prow->paperId,
                          "mimetype" => defval($prow, "mimetype", ""),
                          "size" => defval($prow, "size", 0),
                          "timestamp" => defval($prow, "timestamp", 0),
                          "sha1" => defval($prow, "sha1", ""));
    if ($prow->finalPaperStorageId > 0) {
        $doc->paperStorageId = $prow->finalPaperStorageId;
        $doc->documentType = DTYPE_FINAL;
    } else {
        $doc->paperStorageId = $prow->paperStorageId;
        $doc->documentType = DTYPE_SUBMISSION;
    }

    // load document object from database if pre-loaded version doesn't work
    if ($paperStorageId > 0
        && ($doc->documentType != $documentType
            || $paperStorageId != $doc->paperStorageId)) {
        $size = $Conf->sversion >= 74 ? "size" : "length(paper) as size";
        $result = $Conf->qe("select paperStorageId, paperId, $size, mimetype, timestamp, sha1, filename, documentType from PaperStorage where paperStorageId=$paperStorageId");
        $doc = edb_orow($result);
    }

    return $doc;
}

function paperDownload($prow, $final = false) {
    global $Conf, $Me;
    // don't let PC download papers in progress
    if ($prow->timeSubmitted <= 0 && !$Me->canDownloadPaper($prow))
        return "";
    $doc = paperDocumentData($prow, $final ? DTYPE_FINAL : DTYPE_SUBMISSION);
    return $doc ? documentDownload($doc) : "";
}

function topicTable($prow, $active = 0) {
    global $Conf;
    $paperId = ($prow ? $prow->paperId : -1);

    // read from paper row if appropriate
    if ($paperId > 0 && $active < 0 && isset($prow->topicIds)) {
        $top = PaperInfo::unparse_topics($prow->topicIds, defval($prow, "topicInterest"));
        return join(' <span class="sep">&nbsp;</span> ', $top);
    }

    // get current topics
    $paperTopic = array();
    $tmap = $Conf->topic_map();
    if ($paperId > 0) {
        $result = $Conf->q("select topicId from PaperTopic where paperId=$paperId");
        while ($row = edb_row($result))
            $paperTopic[$row[0]] = $tmap[$row[0]];
    }
    $allTopics = ($active < 0 ? $paperTopic : $tmap);
    if (count($allTopics) == 0)
        return "";

    $out = "<table><tr><td class='pad'>";
    $colheight = (int) ((count($allTopics) + 1) / 2);
    $i = 0;
    foreach ($tmap as $tid => $tname) {
        if (!isset($allTopics[$tid]))
            continue;
        if ($i > 0 && ($i % $colheight) == 0)
            $out .= "</td><td>";
        $tname = htmlspecialchars($tname);
        if ($paperId <= 0 || $active >= 0) {
            $out .= Ht::checkbox_h("top$tid", 1, ($active > 0 ? isset($_REQUEST["top$tid"]) : isset($paperTopic[$tid])),
                                    array("disabled" => $active < 0))
                . "&nbsp;" . Ht::label($tname) . "<br />\n";
        } else
            $out .= $tname . "<br />\n";
        $i++;
    }
    return $out . "</td></tr></table>";
}

function viewas_link($cid, $contact = null) {
    global $Conf;
    $contact = !$contact && is_object($cid) ? $cid : $contact;
    $cid = is_object($contact) ? $cid->email : $cid;
    return '<a href="' . selfHref(array("actas" => $cid))
        . '">' . Ht::img("viewas.png", "[Act as]", array("title" => "Act as " . Text::name_text($contact))) . '</a>';
}

function authorTable($aus, $viewAs = null) {
    global $Conf;
    $out = "";
    if (!is_array($aus))
        $aus = explode("\n", $aus);
    foreach ($aus as $aux) {
        $au = trim(is_array($aux) ? Text::user_html($aux) : $aux);
        if ($au != '') {
            if (strlen($au) > 30)
                $out .= "<span class='autblentry_long'>";
            else
                $out .= "<span class='autblentry'>";
            $out .= $au;
            if ($viewAs !== null && is_array($aux) && count($aux) >= 2 && $viewAs->email != $aux[2] && $viewAs->privChair)
                $out .= " " . viewas_link($aux[2], $aux);
            $out .= "</span> ";
        }
    }
    return $out;
}

function decorateNumber($n) {
    if ($n < 0)
        return "&#8722;" . (-$n);
    else if ($n > 0)
        return $n;
    else
        return 0;
}


class SessionList {
    static function lookup($idx) {
        global $Conf;
        $lists = $Conf->session("l", array());
        $x = @($lists[$idx]);
        return $x ? (object) $x : null;
    }
    static function change($idx, $delta) {
        global $Conf;
        $l = self::lookup($idx);
        $l = $l ? $l : (object) array();
        foreach ($delta as $k => $v)
            $l->$k = $v;
        $Conf->save_session_array("l", $idx, $l);
    }
    static function allocate($listid) {
        global $Conf;
        $lists = $Conf->session("l", array());
        $oldest = $empty = 0;
        for ($i = 1; $i <= 8; ++$i)
            if (($l = self::lookup($i))) {
                if ($listid && @($l->listid == $listid))
                    return $i;
                else if (!$oldest || @($lists[$oldest]->timestamp < $l->timestamp))
                    $oldest = $i;
            } else if (@$_REQUEST["ls"] == $i)
                return $i;
            else if (!$empty)
                $empty = $i;
        return $empty ? $empty : $oldest;
    }
    static function create($listid, $ids, $description, $url) {
        global $Me, $Now;
        return (object) array("listid" => $listid, "ids" => $ids,
                              "description" => $description,
                              "url" => $url, "timestamp" => $Now,
                              "cid" => $Me ? $Me->contactId : 0);
    }
}

function _tryNewList($opt, $listtype, $sort = null) {
    global $Conf, $ConfSiteSuffix, $Me;
    if ($listtype == "u" && $Me->privChair) {
        $searchtype = (defval($opt, "t") === "all" ? "all" : "pc");
        $q = "select email from ContactInfo";
        if ($searchtype == "pc")
            $q .= " join PCMember using (contactId)";
        $result = $Conf->ql("$q order by lastName, firstName, email");
        $a = array();
        while (($row = edb_row($result)))
            $a[] = $row[0];
        return SessionList::create("u/" . $searchtype, $a,
                                   ($searchtype == "pc" ? "Program committee" : "Users"),
                                   "users$ConfSiteSuffix?t=$searchtype");
    } else {
        $search = new PaperSearch($Me, $opt);
        $x = $search->session_list_object($sort);
        if ($sort || $search->has_sort()) {
            $pl = new PaperList($search, array("sort" => $sort));
            $x->ids = $pl->text("s", array("idarray" => true));
        }
        return $x;
    }
}

function _one_quicklink($id, $baseUrl, $urlrest, $listtype, $isprev) {
    global $Conf;
    if ($listtype == "u") {
        $result = $Conf->ql("select email from ContactInfo where email='" . sqlq($id) . "'");
        $row = edb_row($result);
        $paperText = htmlspecialchars($row ? $row[0] : $id);
        $urlrest = "u=" . urlencode($id) . $urlrest;
    } else {
        $paperText = "#$id";
        $urlrest = "p=" . $id . $urlrest;
    }
    return "<a id=\"quicklink_" . ($isprev ? "prev" : "next")
        . "\" href=\"" . hoturl($baseUrl, $urlrest)
        . "\" onclick=\"return !Miniajax.isoutstanding('revprevform', make_link_callback(this))\">"
        . ($isprev ? Ht::img("_.gif", "<-", "prev") : "")
        . $paperText
        . ($isprev ? "" : Ht::img("_.gif", "->", "next"))
        . "</a>";
}

function quicklinks($id, $baseUrl, $args, $listtype) {
    global $Me, $Conf, $ConfSiteBase, $CurrentList, $Now;

    $list = false;
    $CurrentList = 0;
    if (isset($_REQUEST["ls"])
        && ($listno = cvtint(@$_REQUEST["ls"])) > 0
        && ($xlist = SessionList::lookup($listno))
        && str_starts_with($xlist->listid, $listtype)
        && (!@$xlist->cid || $xlist->cid == ($Me ? $Me->contactId : 0))) {
        $list = $xlist;
        $CurrentList = $listno;
    } else if (isset($_REQUEST["ls"]) && $listtype == "p") {
        $l = $_REQUEST["ls"];
        if (preg_match('_\Ap/([^/]*)/([^/]*)/?(.*)\z_', $l, $m))
            $list = _tryNewList(array("t" => $m[1],
                                      "q" => urldecode($m[2])),
                                $listtype, $m[3]);
        if (!$list && preg_match('/\A[a-z]+\z/', $l))
            $list = _tryNewList(array("t" => $l), $listtype);
        if (!$list && preg_match('/\A(all|s):(.*)\z/s', $l, $m))
            $list = _tryNewList(array("t" => $m[1], "q" => $m[2]), $listtype);
        if (!$list)
            $list = _tryNewList(array("q" => $l), $listtype);
    }

    $k = false;
    if ($list)
        $k = array_search($id, $list->ids);

    if ($k === false && !isset($_REQUEST["list"])) {
        $CurrentList = 0;
        $list = _tryNewList(array(), $listtype);
        $k = array_search($id, $list->ids);
        if ($k === false && $Me->privChair) {
            $list = _tryNewList(array("t" => "all"), $listtype);
            $k = array_search($id, $list->ids);
        }
        if ($k === false)
            $list = false;
    }

    if (!$list)
        return "";

    if ($CurrentList == 0) {
        $CurrentList = SessionList::allocate($list->listid);
        SessionList::change($CurrentList, $list);
    }
    SessionList::change($CurrentList, array("timestamp" => $Now));

    $urlrest = "&amp;ls=" . $CurrentList;
    foreach ($args as $what => $val)
        $urlrest .= "&amp;" . urlencode($what) . "=" . urlencode($val);

    $x = "";
    if ($k > 0)
        $x .= _one_quicklink($list->ids[$k - 1], $baseUrl, $urlrest, $listtype, true);
    if (@$list->description) {
        $x .= ($k > 0 ? "&nbsp;&nbsp;" : "");
        if (@$list->url)
            $x .= '<a id="quicklink_list" href="' . $ConfSiteBase . htmlspecialchars($list->url) . "\">" . $list->description . "</a>";
        else
            $x .= '<span id="quicklink_list">' . $list->description . '</span>';
    }
    if (isset($list->ids[$k + 1])) {
        $x .= ($k > 0 || @$list->description ? "&nbsp;&nbsp;" : "");
        $x .= _one_quicklink($list->ids[$k + 1], $baseUrl, $urlrest, $listtype, false);
    }
    return $x;
}

function goPaperForm($baseUrl = null, $args = array()) {
    global $Conf, $Me, $CurrentList;
    if ($Me->is_empty())
        return "";
    $x = Ht::form_div(hoturl($baseUrl ? : "paper"), array("method" => "get", "class" => "gopaper"));
    if ($baseUrl == "profile")
        $x .= Ht::entry("u", "(User)", array("id" => "quicksearchq", "size" => 10, "hottemptext" => "(User)"));
    else
        $x .= Ht::entry("p", "(All)", array("id" => "quicksearchq", "size" => 10, "hottemptext" => "(All)"));
    foreach ($args as $what => $val)
        $x .= Ht::hidden($what, $val);
    if (isset($CurrentList) && $CurrentList > 0)
        $x .= Ht::hidden("ls", $CurrentList);
    $x .= "&nbsp; " . Ht::submit("Search") . "</div></form>";
    return $x;
}

function clean_tempdirs() {
    $dir = null;
    if (function_exists("sys_get_temp_dir"))
        $dir = sys_get_temp_dir();
    if (!$dir)
        $dir = "/tmp";
    while (substr($dir, -1) == "/")
        $dir = substr($dir, 0, -1);
    $dirh = opendir($dir);
    $now = time();
    while (($fname = readdir($dirh)) !== false)
        if (preg_match('/\Ahotcrptmp\d+\z/', $fname)
            && is_dir("$dir/$fname")
            && ($mtime = @filemtime("$dir/$fname")) !== false
            && $mtime < $now - 1800) {
            $xdirh = @opendir("$dir/$fname");
            while (($xfname = readdir($xdirh)) !== false)
                @unlink("$dir/$fname/$xfname");
            @closedir("$dir/$fname");
            @rmdir("$dir/$fname");
        }
    closedir($dirh);
}

function tempdir($mode = 0700) {
    $dir = null;
    if (function_exists("sys_get_temp_dir"))
        $dir = sys_get_temp_dir();
    if (!$dir)
        $dir = "/tmp";
    while (substr($dir, -1) == "/")
        $dir = substr($dir, 0, -1);
    for ($i = 0; $i < 100; $i++) {
        $path = $dir . "/hotcrptmp" . mt_rand(0, 9999999);
        if (mkdir($path, $mode))
            return $path;
    }
    return false;
}


function setCommentType($crow) {
    if ($crow && !isset($crow->commentType)) {
        if ($crow->forAuthors == 2)
            $crow->commentType = COMMENTTYPE_RESPONSE | COMMENTTYPE_AUTHOR
                | ($crow->forReviewers ? 0 : COMMENTTYPE_DRAFT);
        else if ($crow->forAuthors == 1)
            $crow->commentType = COMMENTTYPE_AUTHOR;
        else if ($crow->forReviewers == 2)
            $crow->commentType = COMMENTTYPE_ADMINONLY;
        else if ($crow->forReviewers)
            $crow->commentType = COMMENTTYPE_REVIEWER;
        else
            $crow->commentType = COMMENTTYPE_PCONLY;
        if (isset($crow->commentBlind) ? $crow->commentBlind : $crow->blind)
            $crow->commentType |= COMMENTTYPE_BLIND;
    }
}

// watch functions
function saveWatchPreference($paperId, $contactId, $watchtype, $on) {
    global $Conf, $OK;
    $explicit = ($watchtype << WATCHSHIFT_EXPLICIT);
    $selected = ($watchtype << WATCHSHIFT_NORMAL);
    $onvalue = $explicit | ($on ? $selected : 0);
    $Conf->qe("insert into PaperWatch (paperId, contactId, watch)
                values ($paperId, $contactId, $onvalue)
                on duplicate key update watch = (watch & ~" . ($explicit | $selected) . ") | $onvalue");
    return $OK;
}

function genericWatch($prow, $watchtype, $callback, $contact) {
    global $Conf;

    $q = "select ContactInfo.contactId, firstName, lastName, email,
                password, roles, defaultWatch,
                PaperReview.reviewType myReviewType,
                PaperReview.reviewSubmitted myReviewSubmitted,
                PaperReview.reviewNeedsSubmit myReviewNeedsSubmit,
                conflictType, watch, preferredEmail, disabled
        from ContactInfo
        left join PaperConflict on (PaperConflict.paperId=$prow->paperId and PaperConflict.contactId=ContactInfo.contactId)
        left join PaperWatch on (PaperWatch.paperId=$prow->paperId and PaperWatch.contactId=ContactInfo.contactId)
        left join PaperReview on (PaperReview.paperId=$prow->paperId and PaperReview.contactId=ContactInfo.contactId)
        left join PaperComment on (PaperComment.paperId=$prow->paperId and PaperComment.contactId=ContactInfo.contactId)
        where watch is not null
        or conflictType>=" . CONFLICT_AUTHOR . "
        or reviewType is not null or commentId is not null
        or (defaultWatch & " . ($watchtype << WATCHSHIFT_ALL) . ")!=0";
    if ($prow->managerContactId > 0)
        $q .= " or ContactInfo.contactId=" . $prow->managerContactId;

    $result = $Conf->qe($q);
    $watchers = array();
    $lastContactId = 0;
    while (($row = edb_orow($result))) {
        if ($row->contactId == $lastContactId
            || ($contact && $row->contactId == $contact->contactId)
            || preg_match('/\Aanonymous\d*\z/', $row->email))
            continue;
        $lastContactId = $row->contactId;

        if ($row->watch
            && ($row->watch & ($watchtype << WATCHSHIFT_EXPLICIT))) {
            if (!($row->watch & ($watchtype << WATCHSHIFT_NORMAL)))
                continue;
        } else {
            if (!($row->defaultWatch & (($watchtype << WATCHSHIFT_NORMAL) | ($watchtype << WATCHSHIFT_ALL))))
                continue;
        }

        $watchers[$row->contactId] = $row;
    }

    // Need to check for outstanding reviews if the settings might prevent a
    // person with outstanding reviews from seeing a comment.
    if (count($watchers)
        && (($Conf->timePCViewAllReviews(false, false) && !$Conf->timePCViewAllReviews(false, true))
            || ($Conf->timeAuthorViewReviews(false) && !$Conf->timeAuthorViewReviews(true)))) {
        $result = $Conf->qe("select ContactInfo.contactId, PaperReview.contactId, max(reviewNeedsSubmit) from ContactInfo
                left join PaperReview on (PaperReview.contactId=ContactInfo.contactId)
                where ContactInfo.contactId in (" . join(",", array_keys($watchers)) . ")
                group by ContactInfo.contactId");
        while (($row = edb_row($result))) {
            $watchers[$row[0]]->has_review = $row[1] > 0;
            $watchers[$row[0]]->has_outstanding_review = $row[2] > 0;
        }
    }

    foreach ($watchers as $row) {
        $minic = Contact::make($row);
        $prow->assign_contact_info($row, $row->contactId);
        call_user_func($callback, $prow, $minic);
    }
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

function numrangejoin($range) {
    $i = 0;
    $a = array();
    while ($i < count($range)) {
        for ($j = $i + 1;
             $j < count($range) && $range[$j-1] == $range[$j] - 1;
             $j++)
            /* nada */;
        if ($j == $i + 1)
            $a[] = $range[$i];
        else
            $a[] = $range[$i] . "&ndash;" . $range[$j - 1];
        $i = $j;
    }
    return commajoin($a);
}

function pluralx($n, $what) {
    if (is_array($n))
        $n = count($n);
    if ($n == 1)
        return $what;
    if ($what == "this")
        return "these";
    if (preg_match('/\A.*?(?:s|sh|ch|[bcdfgjklmnpqrstvxz][oy])\z/', $what)) {
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
    if ($n >= 1 && $n <= 3)
        return $n . ($n == 1 ? "st" : ($n == 2 ? "nd" : "rd"));
    else
        return $n . "th";
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

function ini_get_bytes($varname) {
    // from PHP manual
    $val = trim(ini_get($varname));
    $last = strtolower($val[strlen($val)-1]);
    switch ($last) {
    case 'g':
        $val *= 1024; // fallthru
    case 'm':
        $val *= 1024; // fallthru
    case 'k':
        $val *= 1024;
    }
    return $val;
}

function whyNotText($whyNot, $action) {
    global $Conf, $Now;
    if (!is_array($whyNot))
        $whyNot = array($whyNot => 1);
    $paperId = (isset($whyNot['paperId']) ? $whyNot['paperId'] : -1);
    $reviewId = (isset($whyNot['reviewId']) ? $whyNot['reviewId'] : -1);
    $thisPaper = ($paperId < 0 ? "this paper" : "paper #$paperId");
    $text = '';
    if (isset($whyNot['invalidId'])) {
        $x = $whyNot['invalidId'] . "Id";
        $xid = (isset($whyNot[$x]) ? " \"" . $whyNot[$x] . "\"" : "");
        $text .= "Invalid " . $whyNot['invalidId'] . " number" . htmlspecialchars($xid) . ". ";
    }
    if (isset($whyNot['noPaper']))
        $text .= "No such paper" . ($paperId < 0 ? "" : " #$paperId") . ". ";
    if (isset($whyNot['noReview']))
        $text .= "No such review" . ($reviewId < 0 ? "" : " #$reviewId") . ". ";
    if (isset($whyNot['dbError']))
        $text .= $whyNot['dbError'] . " ";
    if (isset($whyNot['permission']))
        $text .= "You don’t have permission to $action $thisPaper. ";
    if (isset($whyNot["signin"]))
        $text .= "You must sign in to $action $thisPaper. ";
    if (isset($whyNot["withdrawn"]))
        $text .= ucfirst($thisPaper) . " has been withdrawn. ";
    if (isset($whyNot['notWithdrawn']))
        $text .= ucfirst($thisPaper) . " has not been withdrawn. ";
    if (isset($whyNot['notSubmitted']))
        $text .= ucfirst($thisPaper) . " was never officially submitted. ";
    if (isset($whyNot["rejected"]))
        $text .= ucfirst($thisPaper) . " was not accepted for publication. ";
    if (isset($whyNot["decided"]))
        $text .= "The review process for $thisPaper has completed. ";
    if (isset($whyNot['updateSubmitted']))
        $text .= ucfirst($thisPaper) . " has already been submitted and can no longer be updated. ";
    if (isset($whyNot['notUploaded']))
        $text .= ucfirst($thisPaper) . " can’t be submitted because you haven’t yet uploaded the paper itself. Upload the paper and try again. ";
    if (isset($whyNot['reviewNotSubmitted']))
        $text .= "This review is not yet ready for others to see. ";
    if (isset($whyNot['reviewNotComplete']))
        $text .= "Your own review for $thisPaper is not complete, so you can’t view other people’s reviews. ";
    if (isset($whyNot['responseNotReady']))
        $text .= "The authors’ response for $thisPaper is not yet ready for reviewers to view. ";
    if (isset($whyNot['reviewsOutstanding']))
        $text .= "You will get access to the reviews once you complete <a href=\"" . hoturl("search", "q=&amp;t=r") . "\">your assigned reviews for other papers</a>.  If you can’t complete your reviews, please let the conference organizers know via the “Refuse review” links. ";
    if (isset($whyNot['reviewNotAssigned']))
        $text .= "You are not assigned to review $thisPaper. ";
    if (isset($whyNot['deadline'])) {
        $dname = $whyNot['deadline'];
        if ($dname[0] == "s")
            $start = $Conf->setting("sub_open", -1);
        else if ($dname[0] == "p" || $dname[0] == "e")
            $start = $Conf->setting("rev_open", -1);
        else
            $start = 1;
        $end = $Conf->setting($dname, -1);
        if ($start <= 0 || $start == $end)
            $text .= "You can’t $action $thisPaper yet. ";
        else if ($start > 0 && $Now < $start)
            $text .= "You can’t $action $thisPaper until " . $Conf->printableTime($start, "span") . ". ";
        else if ($end > 0 && $Now > $end) {
            if ($dname == "sub_reg")
                $text .= "The paper registration deadline has passed. ";
            else if ($dname == "sub_update")
                $text .= "The deadline to update papers has passed. ";
            else if ($dname == "sub_sub")
                $text .= "The paper submission deadline has passed. ";
            else if ($dname == "extrev_hard")
                $text .= "The external review deadline has passed. ";
            else if ($dname == "pcrev_hard")
                $text .= "The PC review deadline has passed. ";
            else if ($dname == "final_done")
                $text .= "The deadline to update final versions has passed. ";
            else
                $text .= "The deadline to $action $thisPaper has passed. ";
            $text .= "It was " . $Conf->printableTime($end, "span") . ". ";
        } else if ($dname == "au_seerev") {
            if ($Conf->setting("au_seerev") == AU_SEEREV_YES)
                $text .= "Authors who are also reviewers can’t see reviews for their papers while they still have <a href='" . hoturl("search", "t=rout&amp;q=") . "'>incomplete reviews</a> of their own. ";
            else
                $text .= "Authors can’t view paper reviews at the moment. ";
        } else
            $text .= "You can’t $action $thisPaper at the moment. ";
        $text .= "(<a class='nowrap' href='" . hoturl("deadlines") . "'>View deadlines</a>) ";
    }
    if (@$whyNot["override"])
        $text .= "“Override deadlines” can override this restriction. ";
    if (isset($whyNot['blindSubmission']))
        $text .= "Submission to this conference is blind. ";
    if (isset($whyNot['author']))
        $text .= "You aren’t a contact for $thisPaper. ";
    if (isset($whyNot['conflict']))
        $text .= "You have a conflict with $thisPaper. ";
    if (isset($whyNot['externalReviewer']))
        $text .= "External reviewers may not view other reviews for the papers they review. ";
    if (isset($whyNot['differentReviewer']))
        $text .= "You didn’t write this review, so you can’t change it. ";
    if (isset($whyNot['reviewToken']))
        $text .= "If you know a valid review token, enter it above to edit that review. ";
    if (@$whyNot["clickthrough"])
        $text .= "You can’t do that until you agree to the current terms. ";
    // finish it off
    if (isset($whyNot['chairMode']))
        $text .= "(<a class='nowrap' href=\"" . selfHref(array("forceShow" => 1)) . "\">" . ucfirst($action) . " the paper anyway</a>) ";
    if (isset($whyNot['forceShow']))
        $text .= "(<a class='nowrap' href=\"". selfHref(array("forceShow" => 1)) . "\">Override conflict</a>) ";
    if ($text && $action == "view")
        $text .= "Enter a paper number above, or <a href='" . hoturl("search", "q=") . "'>list the papers you can view</a>. ";
    return rtrim($text);
}

function actionTab($text, $url, $default) {
    if ($default)
        return "    <td><div class='vbtab1'><div class='vbtab1x'><div class='vbtab1y'><a href='$url'>$text</a></div></div></div></td>\n";
    else
        return "    <td><div class='vbtab'><a href='$url'>$text</a></div></td>\n";
}

function actionBar($mode = null, $prow = null) {
    global $Me, $Conf, $CurrentList;
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
        if ($Me->privChair)
            $goBase = "profile";
        else
            $prow = null;
    } else if (($wantmode = defval($_REQUEST, "m", defval($_REQUEST, "mode"))))
        $xmode["m"] = $wantmode;

    $listarg = $forceShow;
    $quicklinks_txt = "";
    if ($prow) {
        $id = ($listtype === "u" ? $prow->email : $prow->paperId);
        $quicklinks_txt = quicklinks($id, $goBase, $xmode, $listtype);
        if (isset($CurrentList) && $CurrentList > 0)
            $listarg .= "&amp;ls=$CurrentList";
    }

    // collect actions
    $x = "<div class='nvbar'><table class='vbar'><tr><td class='spanner'></td>\n";

    if ($quicklinks_txt)
        $x .= "  <td class='quicklinks nowrap'>" . $quicklinks_txt . "</td>\n";
    if ($quicklinks_txt && $Me->privChair && $listtype == "p")
        $x .= "  <td id=\"trackerconnect\" class=\"nowrap\"><a id=\"trackerconnectbtn\" href=\"#\" onclick=\"return hotcrp_deadlines.tracker(1)\" class=\"btn btn-default\" title=\"Start meeting tracker\">&#9759;</a><td>\n";

    $x .= "  <td class='gopaper nowrap'>" . goPaperForm($goBase, $xmode) . "</td>\n";

    return $x . "</tr></table></div>";
}

function parseReviewOrdinal($text) {
    $text = strtoupper($text);
    if (preg_match('/^[A-Z]$/', $text))
        return ord($text) - 64;
    else if (preg_match('/^([A-Z])([A-Z])$/', $text, $m))
        return (ord($m[0]) - 64) * 26 + ord($m[1]) - 64;
    else
        return -1;
}

function unparseReviewOrdinal($ord) {
    if ($ord === null)
        return "x";
    else if (is_object($ord)) {
        if ($ord->reviewOrdinal)
            return $ord->paperId . unparseReviewOrdinal($ord->reviewOrdinal);
        else
            return $ord->reviewId;
    } else if ($ord <= 26)
        return chr($ord + 64);
    else
        return chr(intval(($ord - 1) / 26) + 65) . chr(($ord % 26) + 64);
}

function titleWords($title, $chars = 40) {
    // assume that title whitespace has been simplified
    if (strlen($title) <= $chars)
        return $title;
    // don't over-shorten due to UTF-8
    $xtitle = utf8_substr($title, 0, $chars);
    if (($pos = strrpos($xtitle, " ")) > 0
        && substr($title, strlen($xtitle), 1) != " ")
        $xtitle = substr($xtitle, 0, $pos);
    return $xtitle . "...";
}

function downloadCSV($info, $header, $filename, $options = array()) {
    global $Opt;
    $iscsv = defval($options, "type", "csv") == "csv" && !isset($Opt["disableCSV"]);
    $csvg = new CsvGenerator($iscsv ? CsvGenerator::TYPE_COMMA : CsvGenerator::TYPE_TAB);
    if ($header)
        $csvg->set_header($header, true);
    if (@$options["selection"])
        $csvg->set_selection($options["selection"] === true ? $header : $options["selection"]);
    $csvg->add($info);
    $csvg->download_headers($Opt["downloadPrefix"] . $filename . $csvg->extension(), !defval($options, "inline"));
    $csvg->download();
}

function downloadText($text, $filename, $inline = false) {
    global $Opt;
    $csvg = new CsvGenerator(CsvGenerator::TYPE_TAB);
    $csvg->download_headers($Opt["downloadPrefix"] . $filename, !$inline);
    if ($text !== false) {
        $csvg->add($text);
        $csvg->download();
    }
}

function parse_preference($n) {
    $n = trim($n);
    if (preg_match(',\A(-+|\++|[-+]?\d+(?:\.\d*)?|)\s*([xyz]|)\z,i', $n, $m)) {
        if ($m[1] === "")
            $p = 0;
        else if (is_numeric($m[1])) {
            if ($m[1] <= 1000000)
                $p = round($m[1]);
            else
                return null;
        } else if ($m[1][0] === "-")
            $p = -strlen($m[1]);
        else
            $p = strlen($m[1]);
        if ($m[2] === "")
            $e = null;
        else
            $e = 9 - (ord($m[2]) & 15);
        return array($p, $e);
    } else if (strpos($n, "\xE2") !== false)
        // Translate UTF-8 for minus sign into a real minus sign ;)
        return parse_preference(str_replace("\xE2\x88\x92", '-', $n));
    else if (strcasecmp($n, "none") == 0 || strcasecmp($n, "n/a") == 0)
        return array(0, null);
    else if (strcasecmp($n, "conflict") == 0)
        return array(-100, null);
    else
        return null;
}

function unparse_expertise($expertise) {
    if ($expertise === null)
        return "";
    else
        return $expertise > 0 ? "X" : ($expertise == 0 ? "Y" : "Z");
}

function unparse_preference($preference, $expertise = null) {
    if (is_object($preference))
        list($preference, $expertise) = array(@$preference->reviewerPreference,
                                              @$preference->reviewerExpertise);
    else if (is_array($preference))
        list($preference, $expertise) = $preference;
    if ($preference === null || $preference === false)
        $preference = "0";
    return $preference . unparse_expertise($expertise);
}

function unparse_preference_span($preference) {
    if (is_object($preference))
        $preference = array(@$preference->reviewerPreference,
                            @$preference->reviewerExpertise,
                            @$preference->topicInterestScore);
    $type = 1;
    if ($preference[0] < 0 || (!$preference[0] && @($preference[2] < 0)))
        $type = -1;
    $t = "";
    if ($preference[0] || $preference[1] !== null)
        $t .= "P" . decorateNumber($preference[0]) . unparse_expertise($preference[1]);
    if (@$preference[2])
        $t .= ($t ? " " : "") . "T" . decorateNumber($preference[2]);
    if ($t !== "")
        $t = " <span class='asspref$type'>$t</span>";
    return $t;
}

function decisionSelector($curOutcome = 0, $id = null, $extra = "") {
    global $Conf;
    $text = "<select" . ($id === null ? "" : " id='$id'") . " name='decision'$extra>\n";
    $decs = $Conf->decision_map();
    if (!isset($decs[$curOutcome]))
        $curOutcome = null;
    $outcomes = array_keys($decs);
    if ($curOutcome === null)
        $text .= "    <option value='' selected='selected'><b>Set decision...</b></option>\n";
    foreach ($decs as $dnum => $dname)
        $text .= "    <option value='$dnum'" . ($curOutcome == $dnum && $curOutcome !== null ? " selected='selected'" : "") . ">" . htmlspecialchars($dname) . "</option>\n";
    return $text . "  </select>";
}

function pcMembers() {
    global $Conf, $Opt, $PcMembersCache;
    if (!@$PcMembersCache
        || $Conf->setting("pc") <= 0
        || $PcMembersCache[0] < $Conf->setting("pc")
        || $PcMembersCache[2] != @$Opt["sortByLastName"]) {
        $pc = array();
        $result = $Conf->q("select firstName, lastName, affiliation, email, ContactInfo.contactId contactId, roles, contactTags, disabled from ContactInfo join PCMember using (contactId)");
        $by_name_text = array();
        while (($row = edb_orow($result))) {
            $row = Contact::make($row);
            $pc[$row->contactId] = $row;
            if ($row->firstName || $row->lastName) {
                $name_text = Text::name_text($row);
                if (isset($by_name_text[$name_text]))
                    $row->nameAmbiguous = $by_name_text[$name_text]->nameAmbiguous = true;
                $by_name_text[$name_text] = $row;
            }
        }
        uasort($pc, "Contact::compare");
        $order = 0;
        foreach ($pc as $row) {
            $row->sort_position = $order;
            ++$order;
        }
        $PcMembersCache = array($Conf->setting("pc"), $pc, @$Opt["sortByLastName"]);
    }
    return $PcMembersCache[1];
}

function pcTags() {
    $tags = array("pc" => "pc");
    foreach (pcMembers() as $pc)
        if (isset($pc->contactTags) && $pc->contactTags) {
            foreach (explode(" ", $pc->contactTags) as $t)
                if ($t !== "")
                    $tags[strtolower($t)] = $t;
        }
    ksort($tags);
    return $tags;
}

function pcByEmail($email) {
    foreach (pcMembers() as $id => $row)
        if (strcasecmp($row->email, $email) == 0)
            return $row;
    return null;
}

function review_type_icon($revtype, $unfinished = null, $title = null) {
    static $revtypemap = array(-3 => array("&minus;", "Refused"),
                               -2 => array("A", "Author"),
                               -1 => array("X", "Conflict"),
                               1 => array("R", "External review"),
                               2 => array("R", "PC review"),
                               3 => array("2", "Secondary review"),
                               4 => array("1", "Primary review"));
    if (!$revtype)
        return '<span class="rt0"></span>';
    $x = $revtypemap[$revtype];
    return '<span class="rt' . $revtype
        . ($revtype > 0 && $unfinished ? "n" : "")
        . '" title="' . ($title ? $title : $revtypemap[$revtype][1])
        . '"><span class="rti">' . $revtypemap[$revtype][0] . '</span></span>';
}

function matchContact($pcm, $firstName, $lastName, $email) {
    $lastmax = $firstmax = false;
    if (!$lastName) {
        $lastName = $email;
        $lastmax = true;
    }
    if (!$firstName) {
        $firstName = $lastName;
        $firstmax = true;
    }
    assert(is_string($email) && is_string($firstName) && is_string($lastName));

    $cid = -2;
    $matchprio = 0;
    foreach ($pcm as $pcid => $pc) {
        // Match full email => definite match.
        // Otherwise, sum priorities as follows:
        //   Entire front of email, or entire first or last name => +10 each
        //   Part of word in email, first, or last name          => +1 each
        // If a string is used for more than one of email, first, and last,
        // don't count a match more than once.  Pick closest match.

        $emailprio = $firstprio = $lastprio = 0;
        if ($email !== "") {
            if ($pc->email === $email)
                return $pcid;
            if (($pos = stripos($pc->email, $email)) !== false) {
                if ($pos === 0 && $pc->email[strlen($email)] == "@")
                    $emailprio = 10;
                else if ($pos === 0 || !ctype_alnum($pc->email[$pos - 1]))
                    $emailprio = 1;
            }
        }
        if ($firstName != "") {
            if (($pos = stripos($pc->firstName, $firstName)) !== false) {
                if ($pos === 0 && strlen($pc->firstName) == strlen($firstName))
                    $firstprio = 10;
                else if ($pos === 0 || !ctype_alnum($pc->firstName[$pos - 1]))
                    $firstprio = 1;
            }
        }
        if ($lastName != "") {
            if (($pos = stripos($pc->lastName, $lastName)) !== false) {
                if ($pos === 0 && strlen($pc->lastName) == strlen($lastName))
                    $lastprio = 10;
                else if ($pos === 0 || !ctype_alnum($pc->lastName[$pos - 1]))
                    $lastprio = 1;
            }
        }
        if ($lastmax && $firstmax)
            $thisprio = max($emailprio, $firstprio, $lastprio);
        else if ($lastmax)
            $thisprio = max($emailprio, $lastprio) + $firstprio;
        else if ($firstmax)
            $thisprio = $emailprio + max($firstprio, $lastprio);
        else
            $thisprio = $emailprio + $firstprio + $lastprio;

        if ($thisprio && $matchprio <= $thisprio) {
            $cid = ($matchprio < $thisprio ? $pcid : -1);
            $matchprio = $thisprio;
        }
    }
    return $cid;
}

function matchValue($a, $word, $allowKey = false) {
    $outa = array();
    $outb = array();
    $outc = array();
    foreach ($a as $k => $v)
        if (strcmp($word, $v) == 0
            || ($allowKey && strcmp($word, $k) == 0))
            $outa[] = $k;
        else if (strcasecmp($word, $v) == 0)
            $outb[] = $k;
        else if (stripos($v, $word) !== false)
            $outc[] = $k;
    if (count($outa) > 0)
        return $outa;
    else if (count($outb) > 0)
        return $outb;
    else
        return $outc;
}

function scoreCounts($text, $max = null) {
    $merit = ($max ? array_fill(1, $max, 0) : array());
    $n = $sum = $sumsq = 0;
    foreach (preg_split('/[\s,]+/', $text) as $i)
        if (($i = cvtint($i)) > 0) {
            while ($i > count($merit))
                $merit[count($merit) + 1] = 0;
            $merit[$i]++;
            $sum += $i;
            $sumsq += $i * $i;
            $n++;
        }
    $avg = ($n > 0 ? $sum / $n : 0);
    $dev = ($n > 1 ? sqrt(($sumsq - $sum*$sum/$n) / ($n - 1)) : 0);
    return (object) array("v" => $merit, "max" => count($merit),
                          "n" => $n, "avg" => $avg, "stddev" => $dev);
}

function displayOptionsSet($sessionvar, $var = null, $val = null) {
    global $Conf;
    if (($x = $Conf->session($sessionvar)) !== null)
        /* use session value */;
    else if ($sessionvar == "pldisplay")
        $x = $Conf->setting_data("pldisplay_default", "");
    else if ($sessionvar == "ppldisplay")
        $x = $Conf->setting_data("ppldisplay_default", "");
    else
        $x = "";
    if ($x == null || strpos($x, " ") === false) {
        if ($sessionvar == "pldisplay")
            $x = " overAllMerit ";
        else if ($sessionvar == "ppldisplay")
            $x = " tags ";
        else
            $x = " ";
    }

    // set $var to $val in list
    if ($var) {
        $x = str_replace(" $var ", " ", $x);
        if ($val)
            $x .= "$var ";
    }

    // store list in $_SESSION
    $Conf->save_session($sessionvar, $x);
    return $x;
}


function cleanAuthor($row) {
    if (!$row || isset($row->authorTable))
        return;
    $row->authorTable = array();
    if (strpos($row->authorInformation, "\t") === false) {
        foreach (explode("\n", $row->authorInformation) as $line)
            if ($line != "") {
                $email = $aff = "";
                if (($p1 = strpos($line, '<')) !== false) {
                    $p2 = strpos($line, '>', $p1);
                    if ($p2 === false)
                        $p2 = strlen($line);
                    $email = substr($line, $p1 + 1, $p2 - ($p1 + 1));
                    $line = substr($line, 0, $p1) . substr($line, $p2 + 1);
                }
                if (($p1 = strpos($line, '(')) !== false) {
                    $p2 = strpos($line, ')', $p1);
                    if ($p2 === false)
                        $p2 = strlen($line);
                    $aff = substr($line, $p1 + 1, $p2 - ($p1 + 1));
                    $line = substr($line, 0, $p1) . substr($line, $p2 + 1);
                    if (!$email && strpos($aff, '@') !== false
                        && preg_match('_^\S+@\S+\.\S+$_', $aff)) {
                        $email = $aff;
                        $aff = '';
                    }
                }
                $a = Text::split_name($line);
                $a[2] = $email;
                $a[3] = $aff;
                $row->authorTable[] = $a;
            }
    } else {
        $info = "";
        foreach (explode("\n", $row->authorInformation) as $line)
            if ($line != "") {
                $row->authorTable[] = $a = explode("\t", $line);
                if ($a[0] && $a[1])
                    $info .= "$a[0] $a[1]";
                else if ($a[0] || $a[1])
                    $info .= $a[0] . $a[1];
                else
                    $info .= $a[2];
                if ($a[3])
                    $info .= " (" . $a[3] . ")";
                else if ($a[2] && ($a[0] || $a[1]))
                    $info .= " <" . $a[2] . ">";
                $info .= "\n";
            }
        $row->authorInformation = $info;
    }
}

function reviewForm() {
    return ReviewForm::get(0);
}


function hotcrp_random_bytes($length = 16, $secure_only = false) {
    $key = false;
    if (function_exists("openssl_random_pseudo_bytes")) {
        $key = openssl_random_pseudo_bytes($length, $strong);
        $key = ($strong ? $key : false);
    }
    if ($key === false || $key === "")
        $key = @file_get_contents("/dev/urandom", false, null, 0, $length);
    if (($key === false || $key === "") && !$secure_only) {
        $key = "";
        while (strlen($key) < $length)
            $key .= pack("V", mt_rand());
        $key = substr($key, 0, $length);
    }
    if ($key === false || $key === "")
        return false;
    else
        return $key;
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
    if ($format == "V")
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
        else if ($o == 46 /*.*/ || $o == 34 /*"*/)
            continue;
        else
            return false;
        $n += $o << $have;
        $have += 5;
        while ($have >= 8 || ($n && $i == strlen($x) - 1)) {
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
