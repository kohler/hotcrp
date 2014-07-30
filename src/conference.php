<?php
// conference.php -- HotCRP central helper class (singleton)
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Conference {

    var $dblink = null;

    var $settings;
    var $settingTexts;
    var $sversion;
    private $deadline_cache = null;

    private $save_messages = true;
    var $headerPrinted = 0;

    private $scriptStuff = "";
    private $usertimeId = 1;

    private $rounds = null;
    private $tracks = null;
    private $_track_tags = null;
    public $dsn = null;

    const BLIND_NEVER = 0;
    const BLIND_OPTIONAL = 1;
    const BLIND_ALWAYS = 2;
    const BLIND_UNTILREVIEW = 3;

    const SEEDEC_ADMIN = 0;
    const SEEDEC_REV = 1;
    const SEEDEC_ALL = 2;
    const SEEDEC_NCREV = 3;

    const PCSEEREV_IFCOMPLETE = 0;
    const PCSEEREV_YES = 1;
    const PCSEEREV_UNLESSINCOMPLETE = 3;
    const PCSEEREV_UNLESSANYINCOMPLETE = 4;

    function __construct($dsn) {
        global $Opt;
        // unpack dsn, connect to database, load current settings
        if (($this->dsn = $dsn))
            list($this->dblink, $Opt["dbName"]) = self::connect_dsn($this->dsn);
        if (!@$Opt["confid"])
            $Opt["confid"] = @$Opt["dbName"];
        if ($this->dblink)
            $this->load_settings();
        else
            $this->crosscheck_options();
    }

    static function make_dsn($opt) {
        if (isset($opt["dsn"])) {
            if (is_string($opt["dsn"]))
                return $opt["dsn"];
        } else {
            list($user, $password, $host, $name) =
                array(@$opt["dbUser"], @$opt["dbPassword"], @$opt["dbHost"], @$opt["dbName"]);
            $user = ($user !== null ? $user : $name);
            $password = ($password !== null ? $password : $name);
            $host = ($host !== null ? $host : "localhost");
            if (is_string($user) && is_string($password) && is_string($host) && is_string($name))
                return "mysql://" . urlencode($user) . ":" . urlencode($password) . "@" . urlencode($host) . "/" . urlencode($name);
        }
        return null;
    }

    static function sanitize_dsn($dsn) {
        return preg_replace('{\A(\w+://[^/:]*:)[^\@/]+([\@/])}', '$1PASSWORD$2', $dsn);
    }

    static function connect_dsn($dsn) {
        global $Opt;

        $dbhost = $dbuser = $dbpass = $dbname = $dbport = $dbsocket = null;
        if ($dsn && preg_match('|^mysql://([^:@/]*)/(.*)|', $dsn, $m)) {
            $dbhost = urldecode($m[1]);
            $dbname = urldecode($m[2]);
        } else if ($dsn && preg_match('|^mysql://([^:@/]*)@([^/]*)/(.*)|', $dsn, $m)) {
            $dbhost = urldecode($m[2]);
            $dbuser = urldecode($m[1]);
            $dbname = urldecode($m[3]);
        } else if ($dsn && preg_match('|^mysql://([^:@/]*):([^@/]*)@([^/]*)/(.*)|', $dsn, $m)) {
            $dbhost = urldecode($m[3]);
            $dbuser = urldecode($m[1]);
            $dbpass = urldecode($m[2]);
            $dbname = urldecode($m[4]);
        } else
            return array(null, null);

        if ($dbhost === null)
            $dbhost = ini_get("mysqli.default_host");
        if ($dbuser === null)
            $dbuser = ini_get("mysqli.default_user");
        if ($dbpass === null)
            $dbpass = ini_get("mysqli.default_pw");
        if ($dbport === null)
            $dbport = ini_get("mysqli.default_port");
        if ($dbsocket === null && @$Opt["dbSocket"])
            $dbsocket = $Opt["dbSocket"];
        else if ($dbsocket === null)
            $dbsocket = ini_get("mysqli.default_socket");

        $dblink = new mysqli($dbhost, $dbuser, $dbpass, "", $dbport, $dbsocket);
        if ($dblink && !mysqli_connect_errno()) {
            // disallow reserved databases
            if ($dbname == "mysql" || substr($dbname, -7) === "_schema") {
                $dblink->close();
                $dblink = null;
            } else if ($dblink->select_db($dbname))
                $dblink->set_charset("utf8");
        } else
            $dblink = null;

        return array($dblink, $dbname);
    }


    //
    // Initialization functions
    //

    function load_settings() {
        global $Opt, $OptOverride, $OK;

        // load settings from database
        $this->settings = array();
        $this->settingTexts = array();
        $this->deadline_cache = null;

        $result = $this->q("select name, value, data from Settings");
        while ($result && ($row = $result->fetch_row())) {
            $this->settings[$row[0]] = (int) $row[1];
            if ($row[2] !== null)
                $this->settingTexts[$row[0]] = $row[2];
            if (substr($row[0], 0, 4) == "opt.") {
                $okey = substr($row[0], 4);
                if (!array_key_exists($okey, $OptOverride))
                    $OptOverride[$okey] = @$Opt[$okey];
                $Opt[$okey] = ($row[2] === null ? $row[1] : $row[2]);
            }
        }

        // update schema
        if ($this->settings["allowPaperOption"] < 79) {
            require_once("updateschema.php");
            $oldOK = $OK;
            updateSchema($this);
            $OK = $oldOK;
        }
        $this->sversion = $this->settings["allowPaperOption"];

        // invalidate caches after loading from backup
        if (isset($this->settings["frombackup"])
            && $this->invalidateCaches()) {
            $this->qe("delete from Settings where name='frombackup' and value=" . $this->settings["frombackup"]);
            unset($this->settings["frombackup"]);
        } else
            $this->invalidateCaches(array("rf" => true));

        // update options
        if (isset($Opt["ldapLogin"]) && !$Opt["ldapLogin"])
            unset($Opt["ldapLogin"]);
        if (isset($Opt["httpAuthLogin"]) && !$Opt["httpAuthLogin"])
            unset($Opt["httpAuthLogin"]);

        // set conferenceKey
        if (!isset($Opt["conferenceKey"])) {
            if (!isset($this->settingTexts["conf_key"])
                && ($key = hotcrp_random_bytes(32)) !== false)
                $this->save_setting("conf_key", 1, $key);
            $Opt["conferenceKey"] = defval($this->settingTexts, "conf_key", "");
        }

        // set capability key
        if (!@$this->settings["cap_key"]
            && !@$Opt["disableCapabilities"]
            && !(($key = hotcrp_random_bytes(16)) !== false
                 && ($key = base64_encode($key))
                 && $this->save_setting("cap_key", 1, $key)))
            $Opt["disableCapabilities"] = true;

        // GC old capabilities
        if ($this->sversion >= 58
            && defval($this->settings, "__capability_gc", 0) < time() - 86400) {
            $now = time();
            $this->q("delete from Capability where timeExpires>0 and timeExpires<$now");
            $this->q("delete from CapabilityMap where timeExpires>0 and timeExpires<$now");
            $this->q("insert into Settings (name, value) values ('__capability_gc', $now) on duplicate key update value=values(value)");
            $this->settings["__capability_gc"] = $now;
        }

        $this->crosscheck_settings();
        $this->crosscheck_options();
    }

    private function crosscheck_settings() {
        global $Opt;
        // enforce invariants
        foreach (array("pc_seeall", "pcrev_any", "extrev_view", "rev_notifychair") as $x)
            if (!isset($this->settings[$x]))
                $this->settings[$x] = 0;
        if (!isset($this->settings["sub_blind"]))
            $this->settings["sub_blind"] = self::BLIND_ALWAYS;
        if (!isset($this->settings["rev_blind"]))
            $this->settings["rev_blind"] = self::BLIND_ALWAYS;
        if (!isset($this->settings["seedec"])) {
            if (@$this->settings["au_seedec"])
                $this->settings["seedec"] = self::SEEDEC_ALL;
            else if (@$this->settings["rev_seedec"])
                $this->settings["seedec"] = self::SEEDEC_REV;
        }
        if ($this->settings["pc_seeall"] && !$this->timeFinalizePaper())
            $this->settings["pc_seeall"] = -1;
        if (@$this->settings["pc_seeallrev"] == 2) {
            $this->settings["pc_seeblindrev"] = 1;
            $this->settings["pc_seeallrev"] = self::PCSEEREV_YES;
        }
        $this->rounds = array("");
        if (isset($this->settingTexts["tag_rounds"])) {
            foreach (explode(" ", $this->settingTexts["tag_rounds"]) as $r)
                if ($r != "")
                    $this->rounds[] = $r;
        }

        // S3 settings
        foreach (array("s3_bucket", "s3_key", "s3_secret") as $k)
            if (!@$this->settingTexts[$k] && @$Opt[$k])
                $this->settingTexts[$k] = $Opt[$k];
        if (!@$this->settingTexts["s3_bucket"]
            || !@$this->settingTexts["s3_key"]
            || !@$this->settingTexts["s3_secret"])
            unset($this->settingTexts["s3_bucket"], $this->settingTexts["s3_key"],
                  $this->settingTexts["s3_secret"]);
        if (@$Opt["dbNoPapers"] && !@$Opt["docstore"] && !@$Opt["filestore"]
            && !@$this->settingTexts["s3_bucket"])
            unset($Opt["dbNoPapers"]);

        // tracks settings
        if (@($j = $this->settingTexts["tracks"])
            && @($j = json_decode($j))) {
            $this->tracks = $j;
            $this->_track_tags = array();
            foreach ($this->tracks as $k => $v)
                if ($k !== "_")
                    $this->_track_tags[] = $k;
        } else
            $this->tracks = $this->_track_tags = null;
    }

    private function crosscheck_options() {
        global $Opt, $ConfSiteBase;

        // set longName, downloadPrefix, etc.
        $confid = $Opt["confid"];
        if ((!isset($Opt["longName"]) || $Opt["longName"] == "")
            && (!isset($Opt["shortName"]) || $Opt["shortName"] == "")) {
            $Opt["shortNameDefaulted"] = true;
            $Opt["longName"] = $Opt["shortName"] = $confid;
        } else if (!isset($Opt["longName"]) || $Opt["longName"] == "")
            $Opt["longName"] = $Opt["shortName"];
        else if (!isset($Opt["shortName"]) || $Opt["shortName"] == "")
            $Opt["shortName"] = $Opt["longName"];
        if (!isset($Opt["downloadPrefix"]) || $Opt["downloadPrefix"] == "")
            $Opt["downloadPrefix"] = $confid . "-";

        // expand ${confid}, ${confshortname}
        foreach (array("sessionName", "downloadPrefix", "conferenceSite",
                       "paperSite", "defaultPaperSite", "contactName",
                       "contactEmail", "emailFrom", "emailSender",
                       "emailCc", "emailReplyTo") as $k)
            if (isset($Opt[$k]) && is_string($Opt[$k])
                && strpos($Opt[$k], "$") !== false) {
                $Opt[$k] = preg_replace(',\$\{confid\}|\$confid\b,', $confid, $Opt[$k]);
                $Opt[$k] = preg_replace(',\$\{confshortname\}|\$confshortname\b,', $Opt["shortName"], $Opt[$k]);
            }

        // remove final slash from $Opt["paperSite"]
        if (!isset($Opt["paperSite"]) || $Opt["paperSite"] == "")
            $Opt["paperSite"] = Navigation::site_absolute();
        if ($Opt["paperSite"] == "" && isset($Opt["defaultPaperSite"]))
            $Opt["paperSite"] = $Opt["defaultPaperSite"];
        $Opt["paperSite"] = preg_replace('|/+\z|', "", $Opt["paperSite"]);

        // set assetsURL
        if (!@$Opt["assetsURL"])
            $Opt["assetsURL"] = $ConfSiteBase;
        if ($Opt["assetsURL"] !== "" && !str_ends_with($Opt["assetsURL"], "/"))
            $Opt["assetsURL"] .= "/";
        Ht::$img_base = $Opt["assetsURL"] . "images/";

        // set docstore from filestore
        if (!@$Opt["docstore"] && @$Opt["filestore"]) {
            if (($Opt["docstore"] = $Opt["filestore"]) === true)
                $Opt["docstore"] = "filestore";
            $Opt["docstoreSubdir"] = @$Opt["filestoreSubdir"];
        }

        // handle timezone
        if (function_exists("date_default_timezone_set")) {
            if (isset($Opt["timezone"])) {
                if (!date_default_timezone_set($Opt["timezone"])) {
                    $this->errorMsg("Timezone option “" . htmlspecialchars($Opt["timezone"]) . "” is invalid; falling back to “America/New_York”.");
                    date_default_timezone_set("America/New_York");
                }
            } else if (!ini_get("date.timezone") && !getenv("TZ"))
                date_default_timezone_set("America/New_York");
        }

        // set safePasswords
        if (!@$Opt["safePasswords"] || (is_int($Opt["safePasswords"]) && $Opt["safePasswords"] < 1))
            $Opt["safePasswords"] = 0;
        else if ($Opt["safePasswords"] === true)
            $Opt["safePasswords"] = 1;
    }

    function setting($name, $defval = false) {
        return defval($this->settings, $name, $defval);
    }

    function setting_data($name, $defval = false) {
        return defval($this->settingTexts, $name, $defval);
    }

    function setting_json($name, $defval = false) {
        $x = defval($this->settingTexts, $name, $defval);
        return (is_string($x) ? json_decode($x) : $x);
    }

    function outcome_map() {
        $x = @$this->settingTexts["outcome_map"];
        if (is_string($x))
            $x = $this->settingTexts["outcome_map"] = json_decode($x, true);
        return (is_array($x) ? $x : array());
    }

    function topic_map() {
        $x = @$this->settingTexts["topic_map"];
        if (!$x) {
            $result = $this->qe("select topicId, topicName from TopicArea order by topicName");
            $to = $tx = array();
            while (($row = edb_row($result))) {
                if (strcasecmp(substr($row[1], 0, 7), "none of") == 0)
                    $tx[(int) $row[0]] = $row[1];
                else
                    $to[(int) $row[0]] = $row[1];
            }
            foreach ($tx as $tid => $tname)
                $to[$tid] = $tname;
            $x = $this->settingTexts["topic_map"] = $to;
        }
        if (is_string($x))
            $x = $this->settingTexts["topic_map"] = json_decode($x, true);
        return is_array($x) ? $x : array();
    }

    function topic_order_map() {
        $x = @$this->settingTexts["topic_order_map"];
        if (!$x) {
            $to = array();
            foreach ($this->topic_map() as $tid => $tname)
                $to[$tid] = count($to);
            $x = $this->settingTexts["topic_order_map"] = $to;
        }
        if (is_string($x))
            $x = $this->settingTexts["topic_order_map"] = json_decode($x, true);
        return is_array($x) ? $x : array();
    }

    function has_topics() {
        return count($this->topic_map()) != 0;
    }

    function topic_count() {
        return count($this->topic_map());
    }

    function review_form_json($round) {
        $key = $round ? "review_form.$round" : "review_form";
        $x = @$this->settingTexts[$key];
        if (is_string($x))
            $x = $this->settingTexts[$key] = json_decode($x);
        return is_object($x) ? $x : null;
    }

    function has_tracks() {
        return $this->tracks !== null;
    }

    function has_track_tags() {
        return $this->_track_tags !== null;
    }

    function track_tags() {
        return $this->_track_tags ? $this->_track_tags : array();
    }

    function check_tracks($prow, $contact, $type) {
        if ($this->tracks) {
            $checked = false;
            foreach ($this->_track_tags as $t)
                if (@($perm = $this->tracks->$t->$type)
                    && $prow->has_tag($t)) {
                    $has_tag = $contact->has_tag(substr($perm, 1));
                    if ($perm[0] == "-" ? $has_tag : !$has_tag)
                        return false;
                    $checked = true;
                }
            if (!$checked
                && @($perm = $this->tracks->_->$type)) {
                $has_tag = $contact->has_tag(substr($perm, 1));
                if ($perm[0] == "-" ? $has_tag : !$has_tag)
                    return false;
            }
        }
        return true;
    }

    function check_any_tracks($contact, $type) {
        if ($this->tracks)
            foreach ($this->tracks as $k => $v)
                if (@($perm = $v->$type) === null)
                    return true;
                else {
                    $has_tag = $contact->has_tag(substr($perm, 1));
                    if ($perm[0] == "-" ? !$has_tag : $has_tag)
                        return true;
                }
        return !$this->tracks;
    }

    function check_all_tracks($contact, $type) {
        if ($this->tracks)
            foreach ($this->tracks as $k => $v)
                if (@($perm = $v->$type) !== null) {
                    $has_tag = $contact->has_tag(substr($perm, 1));
                    if ($perm[0] == "-" ? $has_tag : !$has_tag)
                        return false;
                }
        return true;
    }

    function has_rounds() {
        return count($this->rounds) > 1;
    }

    function round_list() {
        return $this->rounds;
    }

    function round_name($roundno, $expand) {
        if ($roundno > 0) {
            if (($rtext = @$this->rounds[$roundno]))
                return $rtext;
            else if ($expand)
                return "?$roundno?"; /* should not happen */
        }
        return "";
    }

    function round_number($name, $add) {
        $r = 0;
        if ($name
            && !($r = array_search($name, $this->rounds))
            && $add) {
            $rtext = $this->setting_data("tag_rounds", "");
            $rtext = ($rtext ? "$rtext$name " : " $name ");
            $this->save_setting("tag_rounds", 1, $rtext);
            $r = array_search($name, $this->rounds);
        }
        return $r ? $r : 0;
    }

    function session($name, $defval = null) {
        if (isset($_SESSION[$this->dsn][$name]))
            return $_SESSION[$this->dsn][$name];
        else
            return $defval;
    }

    function save_session($name, $value) {
        if ($value !== null)
            $_SESSION[$this->dsn][$name] = $value;
        else
            unset($_SESSION[$this->dsn][$name]);
    }

    function save_session_array($name, $index, $value) {
        if (!is_array(@$_SESSION[$this->dsn][$name]))
            $_SESSION[$this->dsn][$name] = array();
        if ($index !== true)
            $_SESSION[$this->dsn][$name][$index] = $value;
        else
            $_SESSION[$this->dsn][$name][] = $value;
    }

    function capability_text($prow, $capType) {
        // A capability has the following representation (. is concatenation):
        //    capFormat . paperId . capType . hashPrefix
        // capFormat -- Character denoting format (currently 0).
        // paperId -- Decimal representation of paper number.
        // capType -- Capability type (e.g. "a" for author view).
        // To create hashPrefix, calculate a SHA-1 hash of:
        //    capFormat . paperId . capType . paperCapVersion . capKey
        // where paperCapVersion is a decimal representation of the paper's
        // capability version (usually 0, but could allow conference admins
        // to disable old capabilities paper-by-paper), and capKey
        // is a random string specific to the conference, stored in Settings
        // under cap_key (created in load_settings).  Then hashPrefix
        // is the base-64 encoding of the first 8 bytes of this hash, except
        // that "+" is re-encoded as "-", "/" is re-encoded as "_", and
        // trailing "="s are removed.
        //
        // Any user who knows the conference's cap_key can construct any
        // capability for any paper.  Longer term, one might set each paper's
        // capVersion to a random value; but the only way to get cap_key is
        // database access, which would give you all the capVersions anyway.

        if (!isset($this->settingTexts["cap_key"]))
            return false;
        $start = "0" . $prow->paperId . $capType;
        $hash = sha1($start . $prow->capVersion . $this->settingTexts["cap_key"], true);
        $suffix = str_replace(array("+", "/", "="), array("-", "_", ""),
                              base64_encode(substr($hash, 0, 8)));
        return $start . $suffix;
    }

    // update the 'papersub' setting: are there any submitted papers?
    function updatePapersubSetting($forsubmit) {
        $papersub = defval($this->settings, "papersub");
        if ($papersub === null && $forsubmit)
            $this->q("insert into Settings (name, value) values ('papersub',1) on duplicate key update name=name");
        else if ($papersub <= 0 || !$forsubmit)
            // see also settings.php
            $this->q("update Settings set value=(select ifnull(min(paperId),0) from Paper where " . (defval($this->settings, "pc_seeall") <= 0 ? "timeSubmitted>0" : "timeWithdrawn<=0") . ") where name='papersub'");
    }

    function updatePaperaccSetting($foraccept) {
        if (!isset($this->settings["paperacc"]) && $foraccept)
            $this->q("insert into Settings (name, value) values ('paperacc', " . time() . ") on duplicate key update name=name");
        else if (defval($this->settings, "paperacc") <= 0 || !$foraccept)
            $this->q("update Settings set value=(select max(outcome) from Paper where timeSubmitted>0 group by paperId>0) where name='paperacc'");
    }

    function updateRevTokensSetting($always) {
        if ($always || defval($this->settings, "rev_tokens", 0) < 0)
            $this->qe("insert into Settings (name, value) select 'rev_tokens', count(reviewId) from PaperReview where reviewToken!=0 on duplicate key update value=values(value)");
    }

    function update_paperlead_setting() {
        $this->qe("insert into Settings (name, value) select 'paperlead', count(paperId) from Paper where leadContactId>0 or shepherdContactId>0 limit 1 on duplicate key update value=values(value)");
    }

    function update_papermanager_setting() {
        $this->qe("insert into Settings (name, value) select 'papermanager', count(paperId) from Paper where managerContactId>0 limit 1 on duplicate key update value=values(value)");
    }

    function save_setting($name, $value, $data = null) {
        $qname = $this->dblink->escape_string($name);
        $change = false;
        if ($value === null && $data === null) {
            if ($this->qe("delete from Settings where name='$qname'")) {
                unset($this->settings[$name]);
                unset($this->settingTexts[$name]);
                $change = true;
            }
        } else {
            if ($data === null)
                $dval = "null";
            else if (is_string($data))
                $dval = "'" . $this->dblink->escape_string($data) . "'";
            else
                $dval = "'" . $this->dblink->escape_string(json_encode($data)) . "'";
            if ($this->qe("insert into Settings (name, value, data) values ('$qname', $value, $dval) on duplicate key update value=values(value), data=values(data)")) {
                $this->settings[$name] = $value;
                $this->settingTexts[$name] = $data;
                $change = true;
            }
        }
        if ($change) {
            $this->deadline_cache = null;
            $this->crosscheck_settings();
            if (str_starts_with($name, "opt."))
                $this->crosscheck_options();
        }
        return $change;
    }

    function invalidateCaches($caches = null) {
        global $OK;
        $inserts = array();
        $removes = array();
        $time = time();
        if ($caches ? isset($caches["pc"]) : $this->setting("pc") > 0) {
            if (!$caches || $caches["pc"]) {
                $inserts[] = "('pc',$time)";
                $this->settings["pc"] = $time;
            } else {
                $removes[] = "'pc'";
                unset($this->settings["pc"]);
            }
        }
        if (!$caches || isset($caches["paperOption"]))
            PaperOption::invalidate_option_list();
        if (!$caches || isset($caches["rf"]))
            ReviewForm::clear_cache();
        $ok = true;
        if (count($inserts))
            $ok = $ok && ($this->qe("insert into Settings (name, value) values " . join(",", $inserts) . " on duplicate key update value=values(value)") !== false);
        if (count($removes))
            $ok = $ok && ($this->qe("delete from Settings where name in (" . join(",", $removes) . ")") !== false);
        return $ok;
    }

    function qx($query) {
        return $this->dblink->query($query);
    }

    function ql($query) {
        $result = $this->dblink->query($query);
        if (!$result)
            error_log($this->dblink->error);
        return $result;
    }

    function q($query) {
        global $OK;
        $result = $this->dblink->query($query);
        if ($result === false)
            $OK = false;
        return $result;
    }

    function db_error_html($getdb = true, $while = "", $suggestRetry = true) {
        global $Opt;
        $text = "<p>Database error";
        if ($while)
            $text .= " $while";
        if ($getdb)
            $text .= ": " . htmlspecialchars($this->dblink->error);
        $text .= "</p>";
        if ($suggestRetry)
            $text .= "\n<p>Please try again or contact us at " . Text::user_html(Contact::site_contact()) . ".</p>";
        return $text;
    }

    function db_error_text($getdb = true, $while = "") {
        $text = "Database error";
        if ($while)
            $text .= " $while";
        if ($getdb)
            $text .= ": " . $this->dblink->error;
        return $text;
    }

    function qe($query, $while = "", $suggestRetry = false) {
        global $OK;
        if ($while || $suggestRetry) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            error_log($backtrace[1]["file"] . ":" . $backtrace[1]["line"] . ": bad call to Conference::qe");
        }
        $result = $this->dblink->query($query);
        if ($result === false) {
            if (PHP_SAPI == "cli")
                fwrite(STDERR, $this->db_error_text(true, "$while ($query)") . "\n");
            else
                $this->errorMsg($this->db_error_html(true, $while . " (" . htmlspecialchars($query) . ")", $suggestRetry));
            $OK = false;
        }
        return $result;
    }

    function lastInsertId($ignore_errors = false) {
        global $OK;
        $result = $this->dblink->insert_id;
        if (!$result && !$ignore_errors) {
            if (PHP_SAPI == "cli")
                fwrite(STDERR, $this->db_error_text($result === false) . "\n");
            else
                $this->errorMsg($this->db_error_html($result === false));
            $OK = false;
        }
        return $result;
    }


    // times

    function deadlines() {
        global $Now;
        // Return all deadline-relevant settings as integers.
        if (!$this->deadline_cache) {
            $dl = array("now" => $Now);
            foreach (array("sub_open", "sub_reg", "sub_update", "sub_sub",
                           "sub_close", "sub_grace",
                           "resp_open", "resp_done", "resp_grace",
                           "rev_open", "pcrev_soft", "pcrev_hard",
                           "extrev_soft", "extrev_hard", "rev_grace",
                           "final_open", "final_soft", "final_done",
                           "final_grace") as $x)
                $dl[$x] = isset($this->settings[$x]) ? +$this->settings[$x] : 0;
            $this->deadline_cache = $dl;
        }
        return $this->deadline_cache;
    }

    function printableInterval($amt) {
        if ($amt > 259200 /* 3 days */) {
            $amt = ceil($amt / 86400);
            $what = "day";
        } else if ($amt > 28800 /* 8 hours */) {
            $amt = ceil($amt / 3600);
            $what = "hour";
        } else if ($amt > 3600 /* 1 hour */) {
            $amt = ceil($amt / 1800) / 2;
            $what = "hour";
        } else if ($amt > 180) {
            $amt = ceil($amt / 60);
            $what = "minute";
        } else if ($amt > 0) {
            $amt = ceil($amt);
            $what = "second";
        } else
            return "past";
        return plural($amt, $what);
    }

    static function _dateFormat($long) {
        global $Opt;
        if (!isset($Opt["_dateFormatInitialized"])) {
            if (!isset($Opt["time24hour"]) && isset($Opt["time24Hour"]))
                $Opt["time24hour"] = $Opt["time24Hour"];
            if (!isset($Opt["dateFormatLong"]) && isset($Opt["dateFormat"]))
                $Opt["dateFormatLong"] = $Opt["dateFormat"];
            if (!isset($Opt["dateFormat"])) {
                if (isset($Opt["time24hour"]) && $Opt["time24hour"])
                    $Opt["dateFormat"] = "j M Y H:i:s";
                else
                    $Opt["dateFormat"] = "j M Y g:i:sa";
            }
            if (!isset($Opt["dateFormatLong"]))
                $Opt["dateFormatLong"] = "l " . $Opt["dateFormat"];
            if (!isset($Opt["timestampFormat"]))
                $Opt["timestampFormat"] = $Opt["dateFormat"];
            if (!isset($Opt["dateFormatSimplifier"])) {
                if (isset($Opt["time24hour"]) && $Opt["time24hour"])
                    $Opt["dateFormatSimplifier"] = "/:00(?!:)/";
                else
                    $Opt["dateFormatSimplifier"] = "/:00(?::00|)(?= ?[ap]m)/";
            }
            if (!isset($Opt["dateFormatTimezone"]))
                $Opt["dateFormatTimezone"] = null;
            $Opt["_dateFormatInitialized"] = true;
        }
        if ($long == "timestamp")
            return $Opt["timestampFormat"];
        else if ($long)
            return $Opt["dateFormatLong"];
        else
            return $Opt["dateFormat"];
    }

    function parseableTime($value, $include_zone) {
        global $Opt;
        $f = self::_dateFormat(false);
        $d = date($f, $value);
        if ($Opt["dateFormatSimplifier"])
            $d = preg_replace($Opt["dateFormatSimplifier"], "", $d);
        if ($include_zone) {
            if ($Opt["dateFormatTimezone"] === null)
                $d .= " " . date("T", $value);
            else if ($Opt["dateFormatTimezone"])
                $d .= " " . $Opt["dateFormatTimezone"];
        }
        return $d;
    }
    function parse_time($d, $reference = null) {
        global $Now, $Opt;
        if ($reference === null)
            $reference = $Now;
        if (!isset($Opt["dateFormatTimezoneRemover"])
            && function_exists("timezone_abbreviations_list")) {
            $mytz = date_default_timezone_get();
            $x = array();
            foreach (timezone_abbreviations_list() as $tzname => $tzinfo) {
                foreach ($tzinfo as $tz)
                    if ($tz["timezone_id"] == $mytz)
                        $x[] = preg_quote($tzname);
            }
            if (count($x) == 0)
                $x[] = preg_quote(date("T", $reference));
            $Opt["dateFormatTimezoneRemover"] =
                "/(?:\\s|\\A)(?:" . join("|", $x) . ")(?:\\s|\\z)/i";
        }
        if (@$Opt["dateFormatTimezoneRemover"])
            $d = preg_replace($Opt["dateFormatTimezoneRemover"], " ", $d);
        return strtotime($d, $reference);
    }

    function _printableTime($value, $long, $useradjust, $preadjust = null) {
        global $Opt;
        if ($value <= 0)
            return "N/A";
        $t = date(self::_dateFormat($long), $value);
        if ($Opt["dateFormatSimplifier"])
            $t = preg_replace($Opt["dateFormatSimplifier"], "", $t);
        if ($Opt["dateFormatTimezone"] === null)
            $t .= " " . date("T", $value);
        else if ($Opt["dateFormatTimezone"])
            $t .= " " . $Opt["dateFormatTimezone"];
        if ($preadjust)
            $t .= $preadjust;
        if ($useradjust) {
            $sp = strpos($useradjust, " ");
            $t .= "<$useradjust class='usertime' id='usertime$this->usertimeId' style='display:none'></" . ($sp ? substr($useradjust, 0, $sp) : $useradjust) . ">";
            Ht::stash_script("setLocalTime('usertime$this->usertimeId',$value)");
            ++$this->usertimeId;
        }
        return $t;
    }
    function printableTime($value, $useradjust = false, $preadjust = null) {
        return $this->_printableTime($value, true, $useradjust, $preadjust);
    }
    function printableTimestamp($value, $useradjust = false, $preadjust = null) {
        return $this->_printableTime($value, "timestamp", $useradjust, $preadjust);
    }
    function printableTimeShort($value, $useradjust = false, $preadjust = null) {
        return $this->_printableTime($value, false, $useradjust, $preadjust);
    }
    function unparse_time_log($value) {
        return date("d/M/Y:H:i:s O", $value);
    }

    function printableTimeSetting($what, $useradjust = false, $preadjust = null) {
        return $this->printableTime(defval($this->settings, $what, 0), $useradjust, $preadjust);
    }
    function printableDeadlineSetting($what, $useradjust = false, $preadjust = null) {
        if (!isset($this->settings[$what]) || $this->settings[$what] <= 0)
            return "No deadline";
        else
            return "Deadline: " . $this->printableTime($this->settings[$what], $useradjust, $preadjust);
    }

    function settingsAfter($name) {
        $dl = $this->deadlines();
        $t = defval($this->settings, $name, null);
        return ($t !== null && $t > 0 && $t <= $dl["now"]);
    }
    function deadlinesAfter($name, $grace = null) {
        $dl = $this->deadlines();
        $t = defval($dl, $name, null);
        if ($t !== null && $t > 0 && $grace && isset($dl[$grace]))
            $t += $dl[$grace];
        return ($t !== null && $t > 0 && $t <= $dl["now"]);
    }
    function deadlinesBetween($name1, $name2, $grace = null) {
        $dl = $this->deadlines();
        $t = defval($dl, $name1, null);
        if (($t === null || $t <= 0 || $t > $dl["now"]) && $name1)
            return false;
        $t = defval($dl, $name2, null);
        if ($t !== null && $t > 0 && $grace && isset($dl[$grace]))
            $t += $dl[$grace];
        return ($t === null || $t <= 0 || $t >= $dl["now"]);
    }

    function timeStartPaper() {
        return $this->deadlinesBetween("sub_open", "sub_reg", "sub_grace");
    }
    function timeUpdatePaper($prow = null) {
        return $this->deadlinesBetween("sub_open", "sub_update", "sub_grace")
            && (!$prow || $prow->timeSubmitted <= 0 || $this->setting('sub_freeze') <= 0);
    }
    function timeFinalizePaper($prow = null) {
        return $this->deadlinesBetween("sub_open", "sub_sub", "sub_grace")
            && (!$prow || $prow->timeSubmitted <= 0 || $this->setting('sub_freeze') <= 0);
    }
    function collectFinalPapers() {
        return $this->setting('final_open') > 0;
    }
    function timeSubmitFinalPaper() {
        return $this->timeAuthorViewDecision()
            && $this->deadlinesBetween("final_open", "final_done", "final_grace");
    }
    function timeAuthorViewReviews($reviewsOutstanding = false) {
        // also used to determine when authors can see review counts
        // and comments.  see also mailtemplate.php and genericWatch
        $s = $this->setting("au_seerev");
        return $s == AU_SEEREV_ALWAYS || ($s > 0 && !$reviewsOutstanding);
    }
    function timeAuthorRespond() {
        return $this->deadlinesBetween("resp_open", "resp_done", "resp_grace");
    }
    function timeAuthorViewDecision() {
        return $this->setting("seedec") == self::SEEDEC_ALL;
    }
    function timeReviewOpen() {
        $dl = $this->deadlines();
        return $dl["rev_open"] > 0 && $dl["now"] >= $dl["rev_open"];
    }
    function time_review($isPC, $hard, $assume_open = false) {
        $od = ($assume_open ? "" : "rev_open");
        $d = ($isPC ? "pcrev_" : "extrev_") . ($hard ? "hard" : "soft");
        return $this->deadlinesBetween($od, $d, "rev_grace") > 0;
    }
    function timePCReviewPreferences() {
        return defval($this->settings, "papersub") > 0;
    }
    function timePCViewAllReviews($myReviewNeedsSubmit = false, $reviewsOutstanding = false) {
        return ($this->settingsAfter("pc_seeallrev")
                && (!$myReviewNeedsSubmit
                    || $this->settings["pc_seeallrev"] != self::PCSEEREV_UNLESSINCOMPLETE)
                && (!$reviewsOutstanding
                    || $this->settings["pc_seeallrev"] != self::PCSEEREV_UNLESSANYINCOMPLETE));
    }
    function timePCViewDecision($conflicted) {
        $s = $this->setting("seedec");
        if ($conflicted)
            return $s == self::SEEDEC_ALL || $s == self::SEEDEC_REV;
        else
            return $s >= self::SEEDEC_REV;
    }
    function timeReviewerViewDecision() {
        return $this->setting("seedec") >= self::SEEDEC_REV;
    }
    function timeReviewerViewAcceptedAuthors() {
        return $this->setting("seedec") == self::SEEDEC_ALL;
    }
    function timePCViewPaper($prow, $download) {
        if ($prow->timeWithdrawn > 0)
            return false;
        else if ($prow->timeSubmitted > 0)
            return true;
            //return !$download || $this->setting('sub_freeze') > 0
            //  || $this->deadlinesAfter("sub_sub", "sub_grace")
            //  || $this->setting('sub_open') <= 0;
        else
            return !$download && $this->setting('pc_seeall') > 0;
    }
    function timeReviewerViewSubmittedPaper() {
        return true;
    }
    function timeEmailChairAboutReview() {
        return $this->settings['rev_notifychair'] > 0;
    }
    function timeEmailAuthorsAboutReview() {
        return $this->settingsAfter('au_seerev');
    }

    function submission_blindness() {
        return $this->settings["sub_blind"];
    }
    function subBlindAlways() {
        return $this->settings["sub_blind"] == self::BLIND_ALWAYS;
    }
    function subBlindNever() {
        return $this->settings["sub_blind"] == self::BLIND_NEVER;
    }
    function subBlindOptional() {
        return $this->settings["sub_blind"] == self::BLIND_OPTIONAL;
    }
    function subBlindUntilReview() {
        return $this->settings["sub_blind"] == self::BLIND_UNTILREVIEW;
    }

    function is_review_blind($rrow) {
        if (is_object($rrow))
            $rrow = (bool) $rrow->reviewBlind;
        $rb = $this->settings["rev_blind"];
        return $rb == self::BLIND_ALWAYS
            || ($rb == self::BLIND_OPTIONAL
                && ($rrow === null || $rrow));
    }
    function review_blindness() {
        return $this->settings["rev_blind"];
    }

    function has_managed_submissions() {
        $result = $this->q("select paperId from Paper where timeSubmitted>0 and managerContactId!=0 limit 1");
        return !!edb_row($result);
    }


    function echoScript($script) {
        if ($this->scriptStuff)
            echo $this->scriptStuff;
        $this->scriptStuff = "";
        echo "<script>", $script, "</script>";
    }

    function footerScript($script, $uniqueid = null) {
        Ht::stash_script($script, $uniqueid);
    }

    function footerHtml($html, $uniqueid = null) {
        Ht::stash_html($html, $uniqueid);
    }


    //
    // Paper storage
    //

    function active_document_ids() {
        $q = array("select paperStorageId from Paper where paperStorageId>1",
            "select finalPaperStorageId from Paper where finalPaperStorageId>1",
            "select paperStorageId from PaperComment where paperStorageId>1");
        $document_option_ids = array();
        foreach (PaperOption::option_list() as $id => $o)
            if ($o->value_is_document())
                $document_option_ids[] = $id;
        if (count($document_option_ids))
            $q[] = "select value from PaperOption where optionId in ("
                . join(",", $document_option_ids) . ") and value>1";

        $result = $this->qe(join(" UNION ", $q));
        $ids = array();
        while (($row = edb_row($result)))
            $ids[(int) $row[0]] = true;
        ksort($ids);
        return array_keys($ids);
    }

    function storeDocument($uploadId, $paperId, $documentType) {
        return DocumentHelper::upload(new HotCRPDocument($documentType),
                                      $uploadId,
                                      (object) array("paperId" => $paperId));
    }

    function storePaper($uploadId, $prow, $final) {
        global $ConfSiteSuffix, $Opt;
        $paperId = (is_numeric($prow) ? $prow : $prow->paperId);

        $doc = $this->storeDocument($uploadId, $paperId, $final ? DTYPE_FINAL : DTYPE_SUBMISSION);
        if (isset($doc->error_html)) {
            $this->errorMsg($doc->error_html);
            return false;
        }

        if (!$this->qe("update Paper set "
                . ($final ? "finalPaperStorageId" : "paperStorageId") . "=" . $doc->paperStorageId
                . ", size=" . $doc->size
                . ", mimetype='" . sqlq($doc->mimetype)
                . "', timestamp=" . $doc->timestamp
                . ", sha1='" . sqlq($doc->sha1)
                . "' where paperId=$paperId and timeWithdrawn<=0"))
            return false;

        return $doc->size;
    }

    function document_result($prow, $documentType, $docid = null) {
        global $Opt;
        if (is_array($prow) && count($prow) <= 1)
            $prow = (count($prow) ? $prow[0] : -1);
        if (is_numeric($prow))
            $paperMatch = "=" . $prow;
        else if (is_array($prow))
            $paperMatch = " in (" . join(",", $prow) . ")";
        else
            $paperMatch = "=" . $prow->paperId;
        $q = "select p.paperId, s.mimetype, s.sha1, s.timestamp, ";
        if (!@$Opt["docstore"] && !is_array($prow))
            $q .= "s.paper as content, ";
        if ($this->sversion >= 45)
            $q .= "s.filename, ";
        if ($this->sversion >= 55)
            $q .= "s.infoJson, ";
        $q .= "$documentType documentType, s.paperStorageId from Paper p";
        if ($docid)
            $sjoin = $docid;
        else if ($documentType == DTYPE_SUBMISSION)
            $sjoin = "p.paperStorageId";
        else if ($documentType == DTYPE_FINAL)
            $sjoin = "p.finalPaperStorageId";
        else {
            $q .= " left join PaperOption o on (o.paperId=p.paperId and o.optionId=$documentType)";
            $sjoin = "o.value";
        }
        return $this->q($q . " left join PaperStorage s on (s.paperStorageId=$sjoin) where p.paperId$paperMatch");
    }

    function document_row($result, $dtype = DTYPE_SUBMISSION) {
        if (!($doc = edb_orow($result)))
            return $doc;
        // type doesn't matter
        if ($dtype === null && isset($doc->documentType))
            $dtype = $doc->documentType = (int) $doc->documentType;
        $doc->docclass = new HotCRPDocument($dtype);
        // in modern versions sha1 is set at storage time; before it wasn't
        if ($doc->paperStorageId && $doc->sha1 == "") {
            if (!$doc->docclass->load_content($doc))
                return false;
            $doc->sha1 = sha1($doc->content, true);
            $this->q("update PaperStorage set sha1='" . sqlq($doc->sha1) . "' where paperStorageId=" . $doc->paperStorageId);
        }
        return $doc;
    }

    private function __downloadPaper($paperId, $attachment, $documentType, $docid) {
        global $Opt, $Me, $zlib_output_compression;

        $result = $this->document_result($paperId, $documentType, $docid);
        if (!$result) {
            $this->log("Download error: " . $this->dblink->error, $Me, $paperId);
            return set_error_html("Database error while downloading paper.");
        } else if (edb_nrows($result) == 0)
            return set_error_html("No such document.");

        // Check data
        $docs = array();
        while (($doc = $this->document_row($result, $documentType))) {
            if (!$doc->mimetype)
                $doc->mimetype = MIMETYPEID_PDF;
            $doc->filename = HotCRPDocument::filename($doc);
            $docs[] = $doc;
        }
        if (count($docs) == 1 && $docs[0]->paperStorageId <= 1)
            return set_error_html("Paper #" . $docs[0]->paperId . " hasn’t been uploaded yet.");
        $downloadname = false;
        if (count($docs) > 1)
            $downloadname = $Opt["downloadPrefix"] . pluralx(2, HotCRPDocument::unparse_dtype($documentType)) . ".zip";
        return DocumentHelper::download($docs, $downloadname, $attachment);
    }

    function downloadPaper($paperId, $attachment, $documentType = DTYPE_SUBMISSION, $docid = null) {
        global $Me;
        $result = $this->__downloadPaper($paperId, $attachment, $documentType, $docid);
        if ($result->error) {
            $this->errorMsg($result->error_html);
            return false;
        } else
            return true;
    }


    //
    // Paper search
    //

    function _paperQuery_where($optarr, $field) {
        $ids = array();
        foreach (mkarray($optarr) as $id)
            if (($id = cvtint($id)) > 0)
                $ids[] = "$field=$id";
        if (is_array($optarr) && count($ids) == 0)
            $ids[] = "$field=0";
        return (count($ids) ? "(" . join(" or ", $ids) . ")" : "false");
    }

    function query_all_reviewer_preference() {
        if ($this->sversion >= 69)
            return "group_concat(concat(contactId,' ',preference,' ',coalesce(expertise,'.')) separator ',')";
        else
            return "group_concat(concat(contactId,' ',preference,' .') separator ',')";
    }

    function query_topic_interest($table = "") {
        if ($this->sversion >= 73)
            return $table . "interest";
        else
            return "if(" . $table . "interest=2,4,(" . $table . "interest-1)*2)";
    }

    function query_topic_interest_score() {
        if ($this->sversion >= 73)
            return "interest";
        else
            return "(if(interest=2,2,interest-1)*2)";
    }

    function paperQuery($contact, $options = array()) {
        // Options:
        //   "paperId" => $pid  Only paperId $pid (if array, any of those)
        //   "reviewId" => $rid Only paper reviewed by $rid
        //   "commentId" => $c  Only paper where comment is $c
        //   "finalized"        Only submitted papers
        //   "unsub"            Only unsubmitted papers
        //   "accepted"         Only accepted papers
        //   "active"           Only nonwithdrawn papers
        //   "author"           Only papers authored by $contactId
        //   "myReviewRequests" Only reviews requested by $contactId
        //   "myReviews"        All reviews authored by $contactId
        //   "myOutstandingReviews" All unsubmitted reviews auth by $contactId
        //   "myReviewsOpt"     myReviews, + include papers not yet reviewed
        //   "allReviews"       All reviews (multiple rows per paper)
        //   "allReviewScores"  All review scores (multiple rows per paper)
        //   "allComments"      All comments (multiple rows per paper)
        //   "reviewerName"     Include reviewer names
        //   "commenterName"    Include commenter names
        //   "reviewer" => $cid Include reviewerConflictType/reviewerReviewType
        //   "joins"            Table(s) to join
        //   "tags"             Include paperTags
        //   "tagIndex" => $tag Include tagIndex of named tag
        //   "tagIndex" => tag array -- include tagIndex, tagIndex1, ...
        //   "topics"
        //   "options"
        //   "scores" => array(fields to score)
        //   "order" => $sql    $sql is SQL 'order by' clause (or empty)

        $reviewerQuery = isset($options['myReviews']) || isset($options['allReviews']) || isset($options['myReviewRequests']) || isset($options['myReviewsOpt']) || isset($options['myOutstandingReviews']);
        $allReviewerQuery = isset($options['allReviews']) || isset($options['allReviewScores']);
        $scoresQuery = !$reviewerQuery && isset($options['allReviewScores']);
        if (is_object($contact))
            $contactId = $contact->contactId;
        else {
            $contactId = $contact;
            $contact = null;
        }
        if (isset($options["reviewer"]) && is_object($options["reviewer"]))
            $reviewerContactId = $options["reviewer"]->contactId;
        else if (isset($options["reviewer"]))
            $reviewerContactId = $options["reviewer"];
        else
            $reviewerContactId = $contactId;
        $where = array();

        // fields
        $pq = "select Paper.*, PaperConflict.conflictType,
                count(AllReviews.reviewSubmitted) as reviewCount,
                count(if(AllReviews.reviewNeedsSubmit<=0,AllReviews.reviewSubmitted,AllReviews.reviewId)) as startedReviewCount";
        if ($this->sversion < 51)
            $pq .= ",\n\t\t0 as managerContactId";
        $myPaperReview = null;
        if (!isset($options["author"])) {
            if ($allReviewerQuery)
                $myPaperReview = "MyPaperReview";
            else
                $myPaperReview = "PaperReview";
            // see also papercolumn.php
            $pq .= ",
                PaperReview.reviewType,
                PaperReview.reviewId,
                PaperReview.reviewModified,
                PaperReview.reviewSubmitted,
                PaperReview.reviewNeedsSubmit,
                PaperReview.reviewOrdinal,
                PaperReview.reviewBlind,
                PaperReview.contactId as reviewContactId,
                PaperReview.requestedBy,
                max($myPaperReview.reviewType) as myReviewType,
                max($myPaperReview.reviewSubmitted) as myReviewSubmitted,
                min($myPaperReview.reviewNeedsSubmit) as myReviewNeedsSubmit,
                PaperReview.reviewRound";
        } else
            $pq .= ",\nnull reviewType, null reviewId, null myReviewType";
        if (@$options["reviewerName"])
            $pq .= ",
                ReviewerContactInfo.firstName as reviewFirstName,
                ReviewerContactInfo.lastName as reviewLastName,
                ReviewerContactInfo.email as reviewEmail,
                ReviewerContactInfo.lastLogin as reviewLastLogin";
        if ($reviewerQuery || $scoresQuery) {
            $pq .= ",\n\t\tPaperReview.reviewEditVersion as reviewEditVersion";
            foreach (ReviewForm::field_list_all_rounds() as $f)
                if ($reviewerQuery || $f->has_options)
                    $pq .= ",\n\t\tPaperReview.$f->id as $f->id";
        }
        if (@$options["allComments"]) {
            $pq .= ",
                PaperComment.commentId,
                PaperComment.contactId as commentContactId,
                CommentConflict.conflictType as commentConflictType,
                PaperComment.timeModified,
                PaperComment.comment,
                PaperComment.replyTo";
            if ($this->sversion >= 53)
                $pq .= ",\n\t\tPaperComment.commentType";
            else
                $pq .= ",\n\t\tPaperComment.forReviewers,
                PaperComment.forAuthors,
                PaperComment.blind as commentBlind";
        }
        if (@$options["topics"])
            $pq .= ",
                PaperTopics.topicIds,
                PaperTopics.topicInterest";
        if (@$options["options"] && @$this->settingTexts["options"])
            $pq .= ",
                PaperOptions.optionIds";
        else if (@$options["options"])
            $pq .= ",
                '' as optionIds";
        if (@$options["tags"])
            $pq .= ",
                PaperTags.paperTags";
        if (@$options["tagIndex"] && !is_array($options["tagIndex"]))
            $options["tagIndex"] = array($options["tagIndex"]);
        if (@$options["tagIndex"])
            for ($i = 0; $i < count($options["tagIndex"]); ++$i)
                $pq .= ",\n\t\tTagIndex$i.tagIndex as tagIndex" . ($i?$i:"");
        if (@$options["scores"]) {
            foreach ($options["scores"] as $field) {
                $pq .= ",\n             PaperScores.${field}Scores";
                if ($myPaperReview)
                    $pq .= ",\n         $myPaperReview.$field";
            }
            $pq .= ",\n         PaperScores.numScores";
        }
        if (@$options["reviewTypes"])
            $pq .= ",\n         PaperScores.reviewTypes";
        if (@$options["topicInterestScore"])
            $pq .= ",
                coalesce(PaperTopics.topicInterestScore, 0) as topicInterestScore";
        if (@$options["reviewerPreference"]) {
            $pq .= ",
                coalesce(PaperReviewPreference.preference, 0) as reviewerPreference";
            if ($this->sversion >= 69)
                $pq .= ", PaperReviewPreference.expertise as reviewerExpertise";
            else
                $pq .= ", NULL as reviewerExpertise";
        }
        if (@$options["allReviewerPreference"])
            $pq .= ",
                APRP.allReviewerPreference";
        if (@$options["desirability"])
            $pq .= ",
                coalesce(APRP.desirability, 0) as desirability";
        if (@$options["allConflictType"])
            $pq .= ",
                AllConflict.allConflictType";
        if (@$options["reviewer"])
            $pq .= ",
                RPC.conflictType reviewerConflictType, RPR.reviewType reviewerReviewType";
        if (@$options["foldall"])
            $pq .= ",
                1 as folded";

        // tables
        $pq .= "
                from Paper\n";

        if (@$options["reviewId"])
            $pq .= "            join PaperReview as ReviewSelector on (ReviewSelector.paperId=Paper.paperId)\n";
        if (@$options["commentId"])
            $pq .= "            join PaperComment as CommentSelector on (CommentSelector.paperId=Paper.paperId)\n";

        $aujoinwhere = null;
        if (@$options["author"] && $contact
            && ($aujoinwhere = $contact->actAuthorSql("PaperConflict", true)))
            $where[] = $aujoinwhere;
        if (@$options["author"] && !$aujoinwhere)
            $pq .= "            join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . " and PaperConflict.contactId=$contactId)\n";
        else
            $pq .= "            left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$contactId)\n";

        if (@$options["joins"])
            foreach ($options["joins"] as $jt)
                $pq .= "                $jt\n";

        $pq .= "                left join PaperReview as AllReviews on (AllReviews.paperId=Paper.paperId)\n";

        $qr = "";
        if ($contact && ($tokens = $contact->review_tokens()))
            $qr = " or PaperReview.reviewToken in (" . join(", ", $tokens) . ")";
        if (@$options["myReviewRequests"])
            $pq .= "            join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.requestedBy=$contactId and PaperReview.reviewType=" . REVIEW_EXTERNAL . ")\n";
        else if (@$options["myReviews"])
            $pq .= "            join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$contactId$qr))\n";
        else if (@$options["myOutstandingReviews"])
            $pq .= "            join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$contactId$qr) and PaperReview.reviewNeedsSubmit!=0)\n";
        else if (@$options["myReviewsOpt"])
            $pq .= "            left join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$contactId$qr))\n";
        else if (@$options["allReviews"] || @$options["allReviewScores"]) {
            $x = (@$options["reviewLimitSql"] ? " and (" . $options["reviewLimitSql"] . ")" : "");
            $pq .= "            join PaperReview on (PaperReview.paperId=Paper.paperId$x)\n";
        } else if (!@$options["author"])
            $pq .= "            left join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$contactId$qr))\n";
        if ($myPaperReview == "MyPaperReview")
            $pq .= "            left join PaperReview as MyPaperReview on (MyPaperReview.paperId=Paper.paperId and MyPaperReview.contactId=$contactId)\n";
        if (@$options["allComments"])
            $pq .= "            join PaperComment on (PaperComment.paperId=Paper.paperId)
                left join PaperConflict as CommentConflict on (CommentConflict.paperId=PaperComment.paperId and CommentConflict.contactId=PaperComment.contactId)\n";

        if (@$options["reviewerName"] === "lead" || @$options["reviewerName"] === "shepherd")
            $pq .= "            left join ContactInfo as ReviewerContactInfo on (ReviewerContactInfo.contactId=Paper.{$options['reviewerName']}ContactId)\n";
        else if (@$options["reviewerName"] && @$options["allComments"])
            $pq .= "            left join ContactInfo as ReviewerContactInfo on (ReviewerContactInfo.contactId=PaperComment.contactId)\n";
        else if (@$options["reviewerName"])
            $pq .= "            left join ContactInfo as ReviewerContactInfo on (ReviewerContactInfo.contactId=PaperReview.contactId)\n";

        if (@$options["topics"] || @$options["topicInterestScore"]) {
            $pq .= "            left join (select paperId";
            if (@$options["topics"])
                $pq .= ", group_concat(PaperTopic.topicId) as topicIds, group_concat(ifnull(" . $this->query_topic_interest("TopicInterest.") . ",0)) as topicInterest";
            if (@$options["topicInterestScore"])
                $pq .= ", sum(" . $this->query_topic_interest_score() . ") as topicInterestScore";
            $pq .= " from PaperTopic left join TopicInterest on (TopicInterest.topicId=PaperTopic.topicId and TopicInterest.contactId=$reviewerContactId) group by paperId) as PaperTopics on (PaperTopics.paperId=Paper.paperId)\n";
        }

        if (@$options["options"] && @$this->settingTexts["options"])
            $pq .= "            left join (select paperId, group_concat(PaperOption.optionId, '#', value) as optionIds from PaperOption group by paperId) as PaperOptions on (PaperOptions.paperId=Paper.paperId)\n";

        if (@$options["tags"])
            $pq .= "            left join (select paperId, group_concat(' ', tag, '#', tagIndex order by tag separator '') as paperTags from PaperTag group by paperId) as PaperTags on (PaperTags.paperId=Paper.paperId)\n";
        if (@$options["tagIndex"])
            for ($i = 0; $i < count($options["tagIndex"]); ++$i)
                $pq .= "                left join PaperTag as TagIndex$i on (TagIndex$i.paperId=Paper.paperId and TagIndex$i.tag='" . sqlq($options["tagIndex"][$i]) . "')\n";

        if (@$options["scores"] || @$options["reviewTypes"]) {
            $pq .= "            left join (select paperId";
            if (@$options["scores"])
                foreach ($options["scores"] as $field)
                    $pq .= ", group_concat($field) as ${field}Scores";
            if (@$options["reviewTypes"])
                $pq .= ", group_concat(reviewType) as reviewTypes";
            $pq .= ", count(*) as numScores";
            $pq .= " from PaperReview where reviewSubmitted>0 group by paperId) as PaperScores on (PaperScores.paperId=Paper.paperId)\n";
        }

        if (@$options["reviewerPreference"])
            $pq .= "            left join PaperReviewPreference on (PaperReviewPreference.paperId=Paper.paperId and PaperReviewPreference.contactId=$reviewerContactId)\n";
        if (@$options["allReviewerPreference"] || @$options["desirability"]) {
            $subq = "select paperId";
            if (@$options["allReviewerPreference"])
                $subq .= ", " . $this->query_all_reviewer_preference() . " as allReviewerPreference";
            if (@$options["desirability"])
                $subq .= ", sum(if(preference<=-100,0,greatest(least(preference,1),-1))) as desirability";
            $subq .= " from PaperReviewPreference group by paperId";
            $pq .= "            left join ($subq) as APRP on (APRP.paperId=Paper.paperId)\n";
        }
        if (@$options["allConflictType"])
            $pq .= "            left join (select paperId, group_concat(concat(contactId,' ',conflictType) separator ',') as allConflictType from PaperConflict where conflictType>0 group by paperId) as AllConflict on (AllConflict.paperId=Paper.paperId)\n";
        if (@$options["reviewer"])
            $pq .= "            left join PaperConflict RPC on (RPC.paperId=Paper.paperId and RPC.contactId=$reviewerContactId)
                left join PaperReview RPR on (RPR.paperId=Paper.paperId and RPR.contactId=$reviewerContactId)\n";


        // conditions
        if (@$options["paperId"])
            $where[] = $this->_paperQuery_where($options["paperId"], "Paper.paperId");
        if (@$options["reviewId"]) {
            if (is_numeric($options["reviewId"]))
                $where[] = $this->_paperQuery_where($options["reviewId"], "ReviewSelector.reviewId");
            else if (preg_match('/^(\d+)([A-Z][A-Z]?)$/i', $options["reviewId"], $m)) {
                $where[] = $this->_paperQuery_where($m[1], "Paper.paperId");
                $where[] = $this->_paperQuery_where(parseReviewOrdinal($m[2]), "ReviewSelector.reviewOrdinal");
            } else
                $where[] = $this->_paperQuery_where(-1, "Paper.paperId");
        }
        if (@$options["commentId"])
            $where[] = $this->_paperQuery_where($options['commentId'], "CommentSelector.commentId");
        if (@$options["finalized"])
            $where[] = "timeSubmitted>0";
        else if (@$options["unsub"])
            $where[] = "timeSubmitted<=0";
        if (@$options["accepted"])
            $where[] = "outcome>0";
        if (@$options["undecided"])
            $where[] = "outcome=0";
        if (@$options["active"] || @$options["myReviews"]
            || @$options["myReviewRequests"])
            $where[] = "timeWithdrawn<=0";
        if (@$options["myLead"])
            $where[] = "leadContactId=$contactId";
        if (@$options["unmanaged"])
            $where[] = "managerContactId=0";

        if (count($where))
            $pq .= "            where " . join(" and ", $where) . "\n";

        // grouping and ordering
        if (@$options["allComments"])
            $pq .= "            group by Paper.paperId, PaperComment.commentId\n";
        else if ($reviewerQuery || $scoresQuery)
            $pq .= "            group by Paper.paperId, PaperReview.reviewId\n";
        else
            $pq .= "            group by Paper.paperId\n";
        if (@$options["order"] && $options["order"] != "order by Paper.paperId")
            $pq .= "            " . $options["order"];
        else {
            $pq .= "            order by Paper.paperId";
            if ($reviewerQuery || $scoresQuery)
                $pq .= ", PaperReview.reviewOrdinal";
            if (isset($options["allComments"]))
                $pq .= ", PaperComment.commentId";
        }

        //$this->infoMsg("<pre>" . htmlspecialchars($pq) . "</pre>");
        return $pq . "\n";
    }

    function paperRow($sel, $contact, &$whyNot = null) {
        $whyNot = array();
        if (!is_array($sel))
            $sel = array('paperId' => $sel);
        if (isset($sel['paperId']))
            $whyNot['paperId'] = $sel['paperId'];
        if (isset($sel['reviewId']))
            $whyNot['reviewId'] = $sel['reviewId'];

        if (isset($sel['paperId']) && cvtint($sel['paperId']) < 0)
            $whyNot['invalidId'] = 'paper';
        else if (isset($sel['reviewId']) && cvtint($sel['reviewId']) < 0
                 && !preg_match('/^\d+[A-Z][A-Z]?$/i', $sel['reviewId']))
            $whyNot['invalidId'] = 'review';
        else {
            $q = $this->paperQuery($contact, $sel);
            $result = $this->q($q);

            if (!$result)
                $whyNot['dbError'] = "Database error while fetching paper (" . htmlspecialchars($q) . "): " . htmlspecialchars($this->dblink->error);
            else if (edb_nrows($result) == 0)
                $whyNot['noPaper'] = 1;
            else
                return PaperInfo::fetch($result, $contact);
        }

        return null;
    }

    function review_rows($q, $contact) {
        $result = $this->qe($q);
        $rrows = array();
        while (($row = PaperInfo::fetch($result, $contact)))
            $rrows[$row->reviewId] = $row;
        return $rrows;
    }

    function comment_query($where) {
        return "select PaperComment.*, firstName reviewFirstName, lastName reviewLastName, email reviewEmail
                from PaperComment join ContactInfo on (ContactInfo.contactId=PaperComment.contactId)
                where $where order by commentId";
    }

    function comment_rows($q, $contact) {
        $result = $this->qe($q);
        $crows = array();
        while (($row = PaperInfo::fetch($result, $contact))) {
            if (!isset($row->commentType))
                setCommentType($row);
            $crows[$row->commentId] = $row;
            if (isset($row->commentContactId))
                $cid = $row->commentContactId;
            else
                $cid = $row->contactId;
            $row->threadContacts = array($cid => 1);
            for ($r = $row; defval($r, "replyTo", 0) && isset($crows[$r->replyTo]); $r = $crows[$r->replyTo])
                /* do nothing */;
            $row->threadHead = $r->commentId;
            $r->threadContacts[$cid] = 1;
        }
        foreach ($crows as $row)
            if ($row->threadHead != $row->commentId)
                $row->threadContacts = $crows[$row->threadHead]->threadContacts;
        return $crows;
    }


    function reviewRow($selector, &$whyNot = null) {
        $whyNot = array();

        if (!is_array($selector))
            $selector = array('reviewId' => $selector);
        if (isset($selector['reviewId'])) {
            $whyNot['reviewId'] = $selector['reviewId'];
            if (($reviewId = cvtint($selector['reviewId'])) <= 0) {
                $whyNot['invalidId'] = 'review';
                return null;
            }
        }
        if (isset($selector['paperId'])) {
            $whyNot['paperId'] = $selector['paperId'];
            if (($paperId = cvtint($selector['paperId'])) <= 0) {
                $whyNot['invalidId'] = 'paper';
                return null;
            }
        }
        $contactTags = "NULL as contactTags";
        if ($this->sversion >= 35)
            $contactTags = "ContactInfo.contactTags";

        $q = "select PaperReview.*,
                ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, ContactInfo.roles as contactRoles,
                $contactTags,
                ReqCI.firstName as reqFirstName, ReqCI.lastName as reqLastName, ReqCI.email as reqEmail, ReqCI.contactId as reqContactId";
        if (isset($selector["ratings"]))
            $q .= ",
                group_concat(ReviewRating.rating order by ReviewRating.rating desc) as allRatings,
                count(ReviewRating.rating) as numRatings";
        if (isset($selector["myRating"]))
            $q .= ",
                MyRating.rating as myRating";
        $q .= "\n               from PaperReview
                join ContactInfo using (contactId)
                left join ContactInfo as ReqCI on (ReqCI.contactId=PaperReview.requestedBy)\n";
        if (isset($selector["ratings"]))
            $q .= "             left join ReviewRating on (ReviewRating.reviewId=PaperReview.reviewId)\n";
        if (isset($selector["myRating"]))
            $q .= "             left join ReviewRating as MyRating on (MyRating.reviewId=PaperReview.reviewId and MyRating.contactId=" . $selector["myRating"] . ")\n";

        $where = array();
        $order = array("paperId");
        if (isset($reviewId))
            $where[] = "PaperReview.reviewId=$reviewId";
        if (isset($paperId))
            $where[] = "PaperReview.paperId=$paperId";
        $cwhere = array();
        if (isset($selector["contactId"]))
            $cwhere[] = "PaperReview.contactId=" . cvtint($selector["contactId"]);
        if (@$selector["rev_tokens"])
            $cwhere[] = "PaperReview.reviewToken in (" . join(",", $selector["rev_tokens"]) . ")";
        if (count($cwhere))
            $where[] = "(" . join(" or ", $cwhere) . ")";
        if (count($cwhere) > 1)
            $order[] = "(PaperReview.contactId=" . cvtint($selector["contactId"]) . ") desc";
        if (isset($selector['reviewOrdinal']))
            $where[] = "PaperReview.reviewSubmitted>0 and reviewOrdinal=" . cvtint($selector['reviewOrdinal']);
        else if (isset($selector['submitted']))
            $where[] = "PaperReview.reviewSubmitted>0";
        if (!count($where)) {
            $whyNot['internal'] = 1;
            return null;
        }

        $q = $q . " where " . join(" and ", $where) . " group by PaperReview.reviewId
                order by " . join(", ", $order) . ", reviewOrdinal, reviewType desc, reviewId";

        $result = $this->q($q);
        if (!$result) {
            $whyNot['dbError'] = "Database error while fetching review (" . htmlspecialchars($q) . "): " . htmlspecialchars($this->dblink->error);
            return null;
        }

        $x = array();
        while (($row = edb_orow($result)))
            $x[] = $row;

        if (isset($selector["array"]))
            return $x;
        else if (count($x) == 1 || defval($selector, "first"))
            return $x[0];
        if (count($x) == 0)
            $whyNot['noReview'] = 1;
        else
            $whyNot['multipleReviews'] = 1;
        return null;
    }


    function preferenceConflictQuery($type, $extra) {
        $q = "select PRP.paperId, PRP.contactId, PRP.preference
                from PaperReviewPreference PRP
                join PCMember PCM on (PCM.contactId=PRP.contactId)
                join Paper P on (P.paperId=PRP.paperId)
                left join PaperConflict PC on (PC.paperId=PRP.paperId and PC.contactId=PRP.contactId)
                where PRP.preference<=-100 and coalesce(PC.conflictType,0)<=0
                  and P.timeWithdrawn<=0";
        if ($type != "all" && ($type || $this->setting("pc_seeall") <= 0))
            $q .= " and P.timeSubmitted>0";
        if ($extra)
            $q .= " " . $extra;
        return $q;
    }


    // Activity

    private static function _flowQueryWheres(&$where, $table, $t0) {
        $time = $table . ($table == "PaperReview" ? ".reviewSubmitted" : ".timeModified");
        if (is_array($t0))
            $where[] = "($time<$t0[0] or ($time=$t0[0] and $table.contactId>$t0[1]) or ($time=$t0[0] and $table.contactId=$t0[1] and $table.paperId>$t0[2]))";
        else if ($t0)
            $where[] = "$time<$t0";
    }

    private function _flowQueryRest() {
        $q = "          Paper.title,
                substring(Paper.title from 1 for 80) as shortTitle,
                Paper.timeSubmitted,
                Paper.timeWithdrawn,
                Paper.blind as paperBlind,
                Paper.outcome,\n";
        if ($this->sversion >= 51)
            $q .= "             Paper.managerContactId,\n";
        else
            $q .= "             0 as managerContactId,\n";
        return $q . "           ContactInfo.firstName as reviewFirstName,
                ContactInfo.lastName as reviewLastName,
                ContactInfo.email as reviewEmail,
                PaperConflict.conflictType,
                MyPaperReview.reviewType as myReviewType,
                MyPaperReview.reviewSubmitted as myReviewSubmitted,
                MyPaperReview.reviewNeedsSubmit as myReviewNeedsSubmit\n";
    }

    private function _commentFlowQuery($contact, $t0, $limit) {
        $q = "select PaperComment.*,
                substring(PaperComment.comment from 1 for 300) as shortComment,\n"
            . $this->_flowQueryRest()
            . "\t\tfrom PaperComment
                join ContactInfo on (ContactInfo.contactId=PaperComment.contactId)
                join Paper on (Paper.paperId=PaperComment.paperId)
                left join PaperConflict on (PaperConflict.paperId=PaperComment.paperId and PaperConflict.contactId=$contact->contactId)
                left join PaperReview as MyPaperReview on (MyPaperReview.paperId=PaperComment.paperId and MyPaperReview.contactId=$contact->contactId)\n";
        $where = $contact->canViewCommentReviewWheres();
        self::_flowQueryWheres($where, "PaperComment", $t0);
        if (count($where))
            $q .= " where " . join(" and ", $where);
        $q .= " order by PaperComment.timeModified desc, PaperComment.contactId asc, PaperComment.paperId asc";
        if ($limit)
            $q .= " limit $limit";
        return $q;
    }

    private function _reviewFlowQuery($contact, $t0, $limit) {
        $q = "select PaperReview.*,\n"
            . $this->_flowQueryRest()
            . "\t\tfrom PaperReview
                join ContactInfo on (ContactInfo.contactId=PaperReview.contactId)
                join Paper on (Paper.paperId=PaperReview.paperId)
                left join PaperConflict on (PaperConflict.paperId=PaperReview.paperId and PaperConflict.contactId=$contact->contactId)
                left join PaperReview as MyPaperReview on (MyPaperReview.paperId=PaperReview.paperId and MyPaperReview.contactId=$contact->contactId)\n";
        $where = $contact->canViewCommentReviewWheres();
        self::_flowQueryWheres($where, "PaperReview", $t0);
        $where[] = "PaperReview.reviewSubmitted>0";
        $q .= " where " . join(" and ", $where);
        $q .= " order by PaperReview.reviewSubmitted desc, PaperReview.contactId asc, PaperReview.paperId asc";
        if ($limit)
            $q .= " limit $limit";
        return $q;
    }

    function _activity_compar($a, $b) {
        if (!$a || !$b)
            return !$a && !$b ? 0 : ($a ? -1 : 1);
        $at = isset($a->timeModified) ? $a->timeModified : $a->reviewSubmitted;
        $bt = isset($b->timeModified) ? $b->timeModified : $b->reviewSubmitted;
        if ($at != $bt)
            return $at > $bt ? -1 : 1;
        else if ($a->contactId != $b->contactId)
            return $a->contactId < $b->contactId ? -1 : 1;
        else if ($a->paperId != $b->paperId)
            return $a->paperId < $b->paperId ? -1 : 1;
        else
            return 0;
    }

    function reviewerActivity($contact, $t0, $limit) {
        // Return the $limit most recent pieces of activity on or before $t0.
        // Requires some care, since comments and reviews are loaded from
        // different queries, and we want to return the results sorted.  So we
        // load $limit comments and $limit reviews -- but if the comments run
        // out before the $limit is reached (because some comments cannot be
        // seen by the current user), we load additional comments & try again,
        // and the same for reviews.

        if ($t0 && preg_match('/\A(\d+)\.(\d+)\.(\d+)\z/', $t0, $m))
            $ct0 = $rt0 = array($m[1], $m[2], $m[3]);
        else
            $ct0 = $rt0 = $t0;
        $activity = array();

        $crows = $rrows = array(); // comment/review rows being worked through
        $curcr = $currr = null;    // current comment/review row
        // We read new comment/review rows when the current set is empty.

        while (count($activity) < $limit) {
            // load $curcr with most recent viewable comment
            if ($curcr)
                /* do nothing */;
            else if (($curcr = array_pop($crows))) {
                if (!$contact->canViewComment($curcr, $curcr, false)) {
                    $curcr = null;
                    continue;
                }
            } else if ($ct0) {
                $crows = array_reverse($this->comment_rows(self::_commentFlowQuery($contact, $ct0, $limit), $contact));
                if (count($crows) == $limit)
                    $ct0 = array($crows[0]->timeModified, $crows[0]->contactId, $crows[0]->paperId);
                else
                    $ct0 = null;
                continue;
            }

            // load $currr with most recent viewable review
            if ($currr)
                /* do nothing */;
            else if (($currr = array_pop($rrows))) {
                if (!$contact->canViewReview($currr, $currr, false)) {
                    $currr = null;
                    continue;
                }
            } else if ($rt0) {
                $rrows = array_reverse($this->review_rows(self::_reviewFlowQuery($contact, $rt0, $limit), $contact));
                if (count($rrows) == $limit)
                    $rt0 = array($rrows[0]->reviewSubmitted, $rrows[0]->contactId, $rrows[0]->paperId);
                else
                    $rt0 = null;
                continue;
            }

            // if neither, ran out of activity
            if (!$curcr && !$currr)
                break;

            // otherwise, choose the later one first
            if (self::_activity_compar($curcr, $currr) < 0) {
                $curcr->isComment = true;
                $activity[] = $curcr;
                $curcr = null;
            } else {
                $currr->isComment = false;
                $activity[] = $currr;
                $currr = null;
            }
        }

        return $activity;
    }


    //
    // Message routines
    //

    function msg($text, $type) {
        if (PHP_SAPI == "cli") {
            if ($type === "xmerror" || $type === "merror")
                fwrite(STDERR, "$text\n");
            else if ($type === "xwarning" || $type === "mxwarning"
                     || !defined("HOTCRP_TESTHARNESS"))
                fwrite(STDOUT, "$text\n");
        } else {
            $text = "<div class=\"$type\">$text</div>\n";
            if ($this->save_messages) {
                ensure_session();
                $this->save_session_array("msgs", true, $text);
            } else
                echo $text;
        }
    }

    function infoMsg($text, $minimal = false) {
        $this->msg($text, $minimal ? "xinfo" : "info");
    }

    function warnMsg($text, $minimal = false) {
        $this->msg($text, $minimal ? "xwarning" : "warning");
    }

    function confirmMsg($text, $minimal = false) {
        $this->msg($text, $minimal ? "xconfirm" : "confirm");
    }

    function errorMsg($text, $minimal = false) {
        $this->msg($text, $minimal ? "xmerror" : "merror");
        return false;
    }

    function errorMsgExit($text) {
        if ($text)
            $this->msg($text, 'merror');
        $this->footer();
        exit;
    }

    function tagRoundLocker($dolocker) {
        if (!$dolocker)
            return "";
        else if (!defval($this->settings, "rev_roundtag", ""))
            return ", Settings write";
        else
            return ", Settings write, PaperTag write";
    }


    //
    // Conference header, footer
    //

    function header_css_link($css) {
        global $ConfSitePATH, $Opt;
        echo '<link rel="stylesheet" type="text/css" href="';
        if (str_starts_with($css, "stylesheets/")
            || !preg_match(',\A(?:https?:|/),i', $css))
            echo $Opt["assetsURL"];
        echo $css;
        if (($mtime = @filemtime("$ConfSitePATH/$css")) !== false)
            echo "?mtime=", $mtime;
        echo "\" />\n";
    }

    function header_head($title) {
        global $ConfSiteBase, $ConfSiteSuffix, $ConfSitePATH, $Opt;
        if (!$this->headerPrinted) {
            echo "<!DOCTYPE html>
<html>
<head>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
<meta http-equiv=\"Content-Style-Type\" content=\"text/css\" />
<meta http-equiv=\"Content-Script-Type\" content=\"text/javascript\" />
<meta http-equiv=\"Content-Language\" content=\"en\" />
<meta name=\"google\" content=\"notranslate\" />\n";
            if (strstr($title, "<") !== false)
                $title = preg_replace("/<([^>\"']|'[^']*'|\"[^\"]*\")*>/", "", $title);

            if (isset($Opt["fontScript"]))
                echo $Opt["fontScript"];

            $this->header_css_link("stylesheets/style.css");
            if (isset($Opt["stylesheets"]))
                foreach ($Opt["stylesheets"] as $css)
                    $this->header_css_link($css);

            // favicon
            if (($favicon = defval($Opt, "favicon", "images/review24.png"))) {
                if (strpos($favicon, "://") === false && $favicon[0] != "/") {
                    if (@$Opt["assetsURL"] && substr($favicon, 0, 7) === "images/")
                        $favicon = $Opt["assetsURL"] . $favicon;
                    else
                        $favicon = $ConfSiteBase . $favicon;
                }
                if (substr($favicon, -4) == ".png")
                    echo "<link rel=\"icon\" type=\"image/png\" href=\"$favicon\" />\n";
                else if (substr($favicon, -4) == ".ico")
                    echo "<link rel=\"shortcut icon\" href=\"$favicon\" />\n";
                else if (substr($favicon, -4) == ".gif")
                    echo "<link rel=\"icon\" type=\"image/gif\" href=\"$favicon\" />\n";
                else
                    echo "<link rel=\"icon\" href=\"$favicon\" />\n";
            }

            if (isset($Opt["jqueryURL"]))
                $jquery = $Opt["jqueryURL"];
            else if (@$Opt["jqueryCDN"])
                $jquery = "//code.jquery.com/jquery-1.11.1.min.js";
            else
                $jquery = $Opt["assetsURL"] . "scripts/jquery-1.11.1.min.js";
            $this->scriptStuff = Ht::script_file($jquery) . "\n";

            if (@$Opt["strictJavascript"])
                $this->scriptStuff .= Ht::script_file($Opt["assetsURL"] . "cacheable.php?file=scripts/script.js&strictjs=1&mtime=" . filemtime("$ConfSitePATH/scripts/script.js")) . "\n";
            else
                $this->scriptStuff .= Ht::script_file($Opt["assetsURL"] . "scripts/script.js?mtime=" . filemtime("$ConfSitePATH/scripts/script.js")) . "\n";

            $this->scriptStuff .= "<!--[if lte IE 6]> " . Ht::script_file($Opt["assetsURL"] . "scripts/supersleight.js") . " <![endif]-->\n";

            echo "<title>", $title, " - ", htmlspecialchars($Opt["shortName"]), "</title>\n";
            $this->headerPrinted = 1;
        }
    }

    function header($title, $id = "", $actionBar = null, $showTitle = true) {
        global $ConfSiteBase, $ConfSiteSuffix, $ConfSitePATH, $Me, $Now, $Opt,
            $CurrentList;
        if ($this->headerPrinted >= 2)
            return;
        if ($actionBar === null)
            $actionBar = actionBar();

        $this->header_head($title);
        echo "</head><body", ($id ? " id='$id'" : ""), ($Me ? " onload='hotcrp_load()'" : ""), ">\n";

        $this->scriptStuff .= "<script>"
            . "hotcrp_base=\"$ConfSiteBase\""
            . ";hotcrp_postvalue=\"" . post_value() . "\""
            . ";hotcrp_suffix=\"" . $ConfSiteSuffix . "\"";
        if (@$CurrentList
            && ($list = SessionList::lookup($CurrentList)))
            $this->scriptStuff .= ";hotcrp_list={num:$CurrentList,id:\"" . addcslashes($list->listid, "\n\r\\\"/") . "\"}";

        $pid = @$_REQUEST["paperId"];
        $pid = $pid && ctype_digit($pid) ? (int) $pid : 0;
        if ($pid)
            $this->scriptStuff .= ";hotcrp_paperid=$pid";

        // JavaScript's timezone offsets are the negative of PHP's
        $this->scriptStuff .= ";hotcrp_load.time(" . (-date("Z", $Now) / 60) . "," . (@$Opt["time24hour"] ? 1 : 0) . ")";

        if ($Me) {
            $dl = $Me->deadlines();
            $this->scriptStuff .= ";hotcrp_deadlines.init(" . json_encode($dl) . ",\"" . hoturl("deadlines") . "\")";
        } else
            $dl = array();

        // Register meeting tracker
        $trackerowner = $Me && $Me->privChair
            && ($trackerstate = $this->setting_json("tracker"))
            && $trackerstate->sessionid == session_id();
        if ($trackerowner)
            $this->scriptStuff .= ";hotcrp_deadlines.tracker(0)";

        if ($Me && $Me->isPC)
            $this->scriptStuff .= ";alltags.url=\"" . hoturl("search", "alltags=1") . "\"";
        $this->scriptStuff .= "</script>";

        // If browser owns tracker, send it the script immediately
        if ($trackerowner)
            $this->echoScript("");

        echo "<div id='prebody'>\n";

        echo "<div id='header'>\n<div id='header_left_conf'><h1>";
        if ($title && $showTitle && $title == "Home")
            echo "<a class='q' href='", hoturl("index"), "' title='Home'>", htmlspecialchars($Opt["shortName"]), "</a>";
        else
            echo "<a class='x' href='", hoturl("index"), "' title='Home'>", htmlspecialchars($Opt["shortName"]), "</a></h1></div><div id='header_left_page'><h1>", $title;
        echo "</h1></div><div id='header_right'>";
        if ($Me && !$Me->is_empty()) {
            // profile link
            $xsep = ' <span class="barsep">&nbsp;|&nbsp;</span> ';
            if ($Me->has_email()) {
                echo '<a class="q" href="', hoturl("profile"), '"><strong>',
                    htmlspecialchars($Me->email),
                    '</strong></a> &nbsp; <a href="', hoturl("profile"), '">Profile</a>',
                    $xsep;
            }

            // "act as" link
            if (($actas = @$_SESSION["last_actas"]) && @$_SESSION["trueuser"]) {
                // Become true user if not currently chair.
                if (!$Me->privChair || strcasecmp($Me->email, $actas) == 0)
                    $actas = $_SESSION["trueuser"]->email;
                if (strcasecmp($Me->email, $actas) != 0)
                    echo "<a href=\"", selfHref(array("actas" => $actas)), "\">", ($Me->privChair ? htmlspecialchars($actas) : "Admin"), "&nbsp;", Ht::img("viewas.png", "Act as " . htmlspecialchars($actas)), "</a>", $xsep;
            }

            // help, sign out
            $x = ($id == "search" ? "t=$id" : ($id == "settings" ? "t=chair" : ""));
            echo '<a href="', hoturl("help", $x), '">Help</a>', $xsep;
            if ($Me->has_email() || isset($Opt["httpAuthLogin"]))
                echo '<a href="', hoturl_post("index", "signout=1"), '">Sign&nbsp;out</a>';
            else
                echo '<a href="', hoturl("index", "signin=1"), '">Sign&nbsp;in</a>';
        }
        echo "<div id='maindeadline' style='display:none'>";

        // This is repeated in script.js:hotcrp_deadlines
        $dlname = "";
        $dltime = 0;
        if (@$dl["sub_open"]) {
            foreach (array("sub_reg" => "registration", "sub_update" => "update", "sub_sub" => "submission") as $subtype => $subname)
                if (isset($dl["${subtype}_ingrace"]) || $Now <= defval($dl, $subtype, 0)) {
                    $dlname = "Paper $subname deadline";
                    $dltime = defval($dl, $subtype, 0);
                    break;
                }
        }
        if ($dlname) {
            $s = "<a href=\"" . hoturl("deadlines") . "\">$dlname</a> ";
            if (!$dltime || $dltime <= $Now)
                $s .= "is NOW";
            else
                $s .= "in " . $this->printableInterval($dltime - $Now);
            if (!$dltime || $dltime - $Now <= 180)
                $s = "<span class='impending'>$s</span>";
            echo $s;
        }

        echo "</div></div>\n";

        echo "  <div class='clear'></div>\n";

        echo $actionBar;

        echo "</div>\n<div id=\"initialmsgs\">\n";
        if (@$Opt["maintenance"])
            echo "<div class=\"merror\"><strong>The site is down for maintenance.</strong> ", (is_string($Opt["maintenance"]) ? $Opt["maintenance"] : "Please check back later."), "</div>";
        if (($msgs = $this->session("msgs")) && count($msgs)) {
            foreach ($msgs as $m)
                echo $m;
            $this->save_session("msgs", null);
            echo "<div id=\"initialmsgspacer\"></div>";
        }
        $this->save_messages = false;
        echo "</div>\n";

        $this->headerPrinted = 2;
        echo "</div>\n<div class='body'>\n";

        // Callback for version warnings
        if ($Me && $Me->privChair
            && (!isset($Me->_updatecheck) || $Me->_updatecheck + 20 <= $Now)
            && (!isset($Opt["updatesSite"]) || $Opt["updatesSite"])) {
            $m = defval($Opt, "updatesSite", "//hotcrp.lcdf.org/updates");
            $m .= (strpos($m, "?") === false ? "?" : "&")
                . "addr=" . urlencode($_SERVER["SERVER_ADDR"])
                . "&base=" . urlencode($ConfSiteBase)
                . "&version=" . HOTCRP_VERSION;
            $v = HOTCRP_VERSION;
            if (is_dir("$ConfSitePATH/.git")) {
                $args = array();
                exec("export GIT_DIR=" . escapeshellarg($ConfSitePATH) . "/.git; git rev-parse HEAD 2>/dev/null; git merge-base origin/master HEAD 2>/dev/null", $args);
                if (count($args) >= 1) {
                    $m .= "&git-head=" . urlencode($args[0]);
                    $v .= " " . $args[0];
                }
                if (count($args) >= 2) {
                    $m .= "&git-upstream=" . urlencode($args[1]);
                    $v .= " " . $args[1];
                }
            }
            Ht::stash_script("check_version(\"$m\",\"$v\")");
            $Me->_updatecheck = $Now;
        }
    }

    function footer() {
        global $Opt, $Me, $ConfSitePATH;
        echo "</div>\n", // class='body'
            "<div id='footer'>\n  <div id='footer_crp'>",
            defval($Opt, "extraFooter", ""),
            "<a href='http://read.seas.harvard.edu/~kohler/hotcrp/'>HotCRP</a> Conference Management Software";
        if (!defval($Opt, "noFooterVersion", 0)) {
            if ($Me && $Me->privChair) {
                echo " v", HOTCRP_VERSION;
                if (is_dir("$ConfSitePATH/.git")) {
                    $args = array();
                    exec("export GIT_DIR=" . escapeshellarg($ConfSitePATH) . "/.git; git rev-parse HEAD 2>/dev/null; git rev-parse v" . HOTCRP_VERSION . " 2>/dev/null", $args);
                    if (count($args) == 2 && $args[0] != $args[1])
                        echo " [", substr($args[0], 0, 7), "...]";
                }
            } else
                echo "<!-- Version ", HOTCRP_VERSION, " -->";
        }
        echo "</div>\n  <div class='clear'></div></div>\n";
        echo $this->scriptStuff, Ht::take_stash(), "</body>\n</html>\n";
        $this->scriptStuff = "";
    }

    function output_ajax($values = null, $div = false) {
        if ($values === false || $values === true)
            $values = array("ok" => $values);
        else if ($values === null)
            $values = array();
        else if (is_object($values))
            $values = get_object_vars($values);
        $t = "";
        $msgs = $this->session("msgs", array());
        $this->save_session("msgs", null);
        foreach ($msgs as $msg)
            if (preg_match('|\A<div class="(.*?)">([\s\S]*)</div>\s*\z|', $msg, $m)) {
                if ($m[1] == "merror" && !isset($values["error"]))
                    $values["error"] = $m[2];
                if ($div)
                    $t .= "<div class=\"x$m[1]\">$m[2]</div>\n";
                else
                    $t .= "<span class=\"$m[1]\">$m[2]</span>\n";
            }
        if (!isset($values["response"]) && $t !== "")
            $values["response"] = $t;
        if (isset($_REQUEST["jsontext"]) && $_REQUEST["jsontext"])
            header("Content-Type: text/plain");
        else
            header("Content-Type: application/json");
        echo json_encode($values);
    }

    function ajaxExit($values = null, $div = false) {
        $this->output_ajax($values, $div);
        exit;
    }


    //
    // Action recording
    //

    function log($text, $who, $paperId = null) {
        if (!is_array($paperId))
            $paperId = $paperId ? array($paperId) : array();
        foreach ($paperId as &$p)
            if (is_object($p))
                $p = $p->paperId;
        if (count($paperId) == 0)
            $paperId = "null";
        else if (count($paperId) == 1)
            $paperId = $paperId[0];
        else {
            $text .= " (papers " . join(", ", $paperId) . ")";
            $paperId = "null";
        }

        if (!$who)
            $who = 0;
        else if (!is_numeric($who))
            $who = $who->contactId;

        $this->q("insert into ActionLog (ipaddr, contactId, paperId, action) values ('" . sqlq(@$_SERVER["REMOTE_ADDR"]) . "', " . (int) $who . ", $paperId, '" . sqlq(substr($text, 0, 4096)) . "')");
    }


    //
    // Miscellaneous
    //

    function allowEmailTo($email) {
        global $Opt;
        return $Opt["sendEmail"]
            && ($at = strpos($email, "@")) !== false
            && substr($email, $at) != "@_.com";
    }


    public function encode_capability($capid, $salt, $timeExpires, $save) {
        global $Opt;
        list($keyid, $key) = Contact::password_hmac_key(null, true);
        if (($hash_method = defval($Opt, "capabilityHashMethod")))
            /* OK */;
        else if (($hash_method = $this->setting_data("capabilityHashMethod")))
            /* OK */;
        else {
            $hash_method = (PHP_INT_SIZE == 8 ? "sha512" : "sha256");
            $this->save_setting("capabilityHashMethod", 1, $hash_method);
        }
        $text = substr(hash_hmac($hash_method, $capid . " " . $timeExpires . " " . $salt, $key, true), 0, 16);
        if ($save)
            $this->q("insert ignore into CapabilityMap (capabilityValue, capabilityId, timeExpires) values ('" . sqlq($text) . "', $capid, $timeExpires)");
        return "1" . str_replace(array("+", "/", "="), array("-", "_", ""),
                                 base64_encode($text));
    }

    public function create_capability($capabilityType, $options = array()) {
        $contactId = defval($options, "contactId", 0);
        $paperId = defval($options, "paperId", 0);
        $timeExpires = defval($options, "timeExpires", time() + 259200);
        $salt = hotcrp_random_bytes(24);
        $data = defval($options, "data");
        $this->q("insert into Capability (capabilityType, contactId, paperId, timeExpires, salt, data) values ($capabilityType, $contactId, $paperId, $timeExpires, '" . sqlq($salt) . "', " . ($data === null ? "null" : "'" . sqlq($data) . "'") . ")");
        $capid = $this->lastInsertId();
        if (!$capid || !function_exists("hash_hmac"))
            return false;
        return $this->encode_capability($capid, $salt, $timeExpires, true);
    }

    public function check_capability($capabilityText) {
        if ($capabilityText[0] != "1")
            return false;
        $value = base64_decode(str_replace(array("-", "_"), array("+", "/"),
                                           substr($capabilityText, 1)));
        if (strlen($value) >= 16
            && ($result = $this->q("select * from CapabilityMap where capabilityValue='" . sqlq($value) . "'"))
            && ($row = edb_orow($result))
            && ($row->timeExpires == 0 || $row->timeExpires >= time())) {
            $result = $this->q("select * from Capability where capabilityId=" . $row->capabilityId);
            if (($row = edb_orow($result))) {
                $row->capabilityValue = $value;
                return $row;
            }
        }
        return false;
    }

    public function delete_capability($capdata) {
        if ($capdata) {
            $this->q("delete from CapabilityMap where capabilityValue='" . sqlq($capdata->capabilityValue) . "'");
            $this->q("delete from Capability where capabilityId=" . $capdata->capabilityId);
        }
    }

    public function message_name($name) {
        if (str_starts_with($name, "msg."))
            $name = substr($name, 4);
        if ($name === "revprefdescription" && $this->has_topics())
            $name .= ".withtopics";
        else if ($name === "responseinstructions" && $this->setting("resp_words", 500) > 0)
            $name .= ".wordlimit";
        return $name;
    }

    public function message_html($name, $expansions = null) {
        $name = $this->message_name($name);
        $html = @$this->settingTexts["msg.$name"];
        if ($html === null && ($p = strrpos($name, ".")) !== false)
            $html = @$this->settingTexts["msg." . substr($name, 0, $p)];
        if ($html === null)
            $html = Message::default_html($name);
        if ($html && $expansions)
            foreach ($expansions as $k => $v)
                $html = str_replace("%$k%", $v, $html);
        return $html ? $html : "";
    }

    public function message_default_html($name) {
        return Message::default_html($this->message_name($name));
    }

}
