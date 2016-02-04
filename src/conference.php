<?php
// conference.php -- HotCRP central helper class (singleton)
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Conf {
    public $dblink = null;

    var $settings;
    var $settingTexts;
    public $sversion;
    private $_pc_seeall_cache = null;
    private $_pc_see_pdf = null;
    public $au_seerev;
    public $tag_au_seerev;

    private $save_messages = true;
    var $headerPrinted = false;
    private $_save_logs = false;

    private $usertimeId = 1;

    private $rounds = null;
    private $_defined_rounds = null;
    private $tracks = null;
    private $_track_tags = null;
    private $_track_review_sensitivity = false;
    private $_decisions = null;
    private $_topic_separator_cache = null;
    public $dsn = null;

    public $paper = null; // current paper row

    static public $g;
    static public $gShortName;
    static public $gLongName;
    static public $gDefaultFormat;
    static private $gFormatInfo;
    static public $no_invalidate_caches = false;

    const BLIND_NEVER = 0;
    const BLIND_OPTIONAL = 1;
    const BLIND_ALWAYS = 2;
    const BLIND_UNTILREVIEW = 3;

    const SEEDEC_ADMIN = 0;
    const SEEDEC_REV = 1;
    const SEEDEC_ALL = 2;
    const SEEDEC_NCREV = 3;

    const AUSEEREV_NO = 0;
    const AUSEEREV_UNLESSINCOMPLETE = 1;
    const AUSEEREV_YES = 2;
    const AUSEEREV_TAGS = 3;

    const PCSEEREV_IFCOMPLETE = 0;
    const PCSEEREV_YES = 1;
    const PCSEEREV_UNLESSINCOMPLETE = 3;
    const PCSEEREV_UNLESSANYINCOMPLETE = 4;

    static public $review_deadlines = array("pcrev_soft", "pcrev_hard", "extrev_soft", "extrev_hard");

    function __construct($dsn) {
        global $Opt;
        // unpack dsn, connect to database, load current settings
        if (($this->dsn = $dsn))
            list($this->dblink, $Opt["dbName"]) = Dbl::connect_dsn($this->dsn);
        if (!isset($Opt["confid"]))
            $Opt["confid"] = get($Opt, "dbName");
        if ($this->dblink) {
            Dbl::set_default_dblink($this->dblink);
            Dbl::set_error_handler(array($this, "query_error_handler"));
            Dbl::$landmark_sanitizer = "/^(?:Dbl::|Conf::q|call_user_func)/";
            $this->load_settings();
        } else
            $this->crosscheck_options();
    }


    //
    // Initialization functions
    //

    function load_settings() {
        global $Opt, $OptOverride, $Now, $OK;

        // load settings from database
        $this->settings = array();
        $this->settingTexts = array();

        $result = $this->q("select name, value, data from Settings");
        while ($result && ($row = $result->fetch_row())) {
            $this->settings[$row[0]] = (int) $row[1];
            if ($row[2] !== null)
                $this->settingTexts[$row[0]] = $row[2];
            if (substr($row[0], 0, 4) == "opt.") {
                $okey = substr($row[0], 4);
                if (!array_key_exists($okey, $OptOverride))
                    $OptOverride[$okey] = get($Opt, $okey);
                $Opt[$okey] = ($row[2] === null ? (int) $row[1] : $row[2]);
            }
        }
        Dbl::free($result);

        // update schema
        $this->sversion = $this->settings["allowPaperOption"];
        if ($this->sversion < 127) {
            require_once("updateschema.php");
            $oldOK = $OK;
            updateSchema($this);
            $OK = $oldOK;
        }
        if ($this->sversion < 73)
            self::msg_error("Warning: The database could not be upgraded to the current version; expect errors. A system administrator must solve this problem.");

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
        if (defval($this->settings, "__capability_gc", 0) < $Now - 86400) {
            foreach (array($this->dblink, Contact::contactdb()) as $db)
                if ($db)
                    Dbl::ql($db, "delete from Capability where timeExpires>0 and timeExpires<$Now");
            $this->q("insert into Settings (name, value) values ('__capability_gc', $Now) on duplicate key update value=values(value)");
            $this->settings["__capability_gc"] = $Now;
        }

        $this->crosscheck_settings();
        $this->crosscheck_options();
    }

    private function crosscheck_settings() {
        global $Opt, $Now;

        // enforce invariants
        foreach (array("pcrev_any", "extrev_view") as $x)
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
        if (@$this->settings["pc_seeallrev"] == 2) {
            $this->settings["pc_seeblindrev"] = 1;
            $this->settings["pc_seeallrev"] = self::PCSEEREV_YES;
        }

        // rounds
        $this->rounds = array("");
        if (isset($this->settingTexts["tag_rounds"])) {
            foreach (explode(" ", $this->settingTexts["tag_rounds"]) as $r)
                if ($r != "")
                    $this->rounds[] = $r;
        }

        // review times
        foreach ($this->rounds as $i => $rname) {
            $suf = $i ? "_$i" : "";
            if (!isset($this->settings["extrev_soft$suf"]) && isset($this->settings["pcrev_soft$suf"]))
                $this->settings["extrev_soft$suf"] = $this->settings["pcrev_soft$suf"];
            if (!isset($this->settings["extrev_hard$suf"]) && isset($this->settings["pcrev_hard$suf"]))
                $this->settings["extrev_hard$suf"] = $this->settings["pcrev_hard$suf"];
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
        $this->tracks = $this->_track_tags = null;
        $this->_track_review_sensitivity = false;
        if (@($j = $this->settingTexts["tracks"]))
            $this->crosscheck_track_settings($j);

        // clear caches
        $this->_decisions = null;
        $this->_pc_seeall_cache = null;
        $this->_defined_rounds = null;
        // digested settings
        $this->_pc_see_pdf = true;
        if (+@$this->settings["sub_freeze"] <= 0
            && ($so = +@$this->settings["sub_open"]) > 0
            && $so < $Now
            && ($ss = +@$this->settings["sub_sub"]) > 0
            && $ss > $Now)
            $this->_pc_see_pdf = false;

        $this->au_seerev = @+$this->settings["au_seerev"];
        if (!$this->au_seerev
            && @+$this->settings["resp_active"] > 0
            && $this->time_author_respond_all_rounds())
            $this->au_seerev = self::AUSEEREV_YES;
        $this->tag_au_seerev = null;
        if ($this->au_seerev == self::AUSEEREV_TAGS)
            $this->tag_au_seerev = explode(" ", (string) @$this->settingTexts["tag_au_seerev"]);
    }

    private function crosscheck_track_settings($j) {
        if (is_string($j) && !($j = json_decode($j)))
            return;
        $this->tracks = $j;
        $this->_track_tags = array();
        foreach ($this->tracks as $k => $v) {
            if ($k !== "_")
                $this->_track_tags[] = $k;
            if (!isset($v->viewpdf) && isset($v->view))
                $v->viewpdf = $v->view;
            if (@$v->unassrev || @$v->assrev)
                $this->_track_review_sensitivity = true;
        }
    }

    private function crosscheck_options() {
        global $Opt, $ConfSitePATH;

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
        self::$gShortName = $Opt["shortName"];
        self::$gLongName = $Opt["longName"];

        // expand ${confid}, ${confshortname}
        foreach (array("sessionName", "downloadPrefix", "conferenceSite",
                       "paperSite", "defaultPaperSite", "contactName",
                       "contactEmail", "docstore") as $k)
            if (isset($Opt[$k]) && is_string($Opt[$k])
                && strpos($Opt[$k], "$") !== false) {
                $Opt[$k] = preg_replace(',\$\{confid\}|\$confid\b,', $confid, $Opt[$k]);
                $Opt[$k] = preg_replace(',\$\{confshortname\}|\$confshortname\b,', $Opt["shortName"], $Opt[$k]);
            }
        foreach (array("emailFrom", "emailSender", "emailCc", "emailReplyTo") as $k)
            if (isset($Opt[$k]) && is_string($Opt[$k])
                && strpos($Opt[$k], "$") !== false) {
                $Opt[$k] = preg_replace(',\$\{confid\}|\$confid\b,', $confid, $Opt[$k]);
                if (strpos($Opt[$k], "confshortname") !== false) {
                    $v = rfc2822_words_quote($Opt["shortName"]);
                    if ($v[0] === "\"" && strpos($Opt[$k], "\"") !== false)
                        $v = substr($v, 1, strlen($v) - 2);
                    $Opt[$k] = preg_replace(',\$\{confshortname\}|\$confshortname\b,', $v, $Opt[$k]);
                }
            }

        // remove final slash from $Opt["paperSite"]
        if (!isset($Opt["paperSite"]) || $Opt["paperSite"] == "")
            $Opt["paperSite"] = Navigation::site_absolute();
        if ($Opt["paperSite"] == "" && isset($Opt["defaultPaperSite"]))
            $Opt["paperSite"] = $Opt["defaultPaperSite"];
        $Opt["paperSite"] = preg_replace('|/+\z|', "", $Opt["paperSite"]);

        // option name updates (backwards compatibility)
        foreach (array("assetsURL" => "assetsUrl",
                       "jqueryURL" => "jqueryUrl", "jqueryCDN" => "jqueryCdn",
                       "disableCSV" => "disableCsv") as $kold => $knew)
            if (isset($Opt[$kold]) && !isset($Opt[$knew]))
                $Opt[$knew] = $Opt[$kold];

        // set assetsUrl and scriptAssetsUrl
        if (!isset($Opt["scriptAssetsUrl"]) && isset($_SERVER["HTTP_USER_AGENT"])
            && strpos($_SERVER["HTTP_USER_AGENT"], "MSIE") !== false)
            $Opt["scriptAssetsUrl"] = Navigation::siteurl();
        if (!isset($Opt["assetsUrl"]))
            $Opt["assetsUrl"] = Navigation::siteurl();
        if ($Opt["assetsUrl"] !== "" && !str_ends_with($Opt["assetsUrl"], "/"))
            $Opt["assetsUrl"] .= "/";
        if (!isset($Opt["scriptAssetsUrl"]))
            $Opt["scriptAssetsUrl"] = $Opt["assetsUrl"];
        Ht::$img_base = $Opt["assetsUrl"] . "images/";

        // set docstore
        if (get($Opt, "docstore") === true)
            $Opt["docstore"] = "docs";
        else if (!get($Opt, "docstore") && get($Opt, "filestore")) { // backwards compat
            if (($Opt["docstore"] = $Opt["filestore"]) === true)
                $Opt["docstore"] = "filestore";
            $Opt["docstoreSubdir"] = get($Opt, "filestoreSubdir");
        }
        if (get($Opt, "docstore") && $Opt["docstore"][0] !== "/")
            $Opt["docstore"] = $ConfSitePATH . "/" . $Opt["docstore"];

        // handle timezone
        if (function_exists("date_default_timezone_set")) {
            if (isset($Opt["timezone"])) {
                if (!date_default_timezone_set($Opt["timezone"])) {
                    self::msg_error("Timezone option “" . htmlspecialchars($Opt["timezone"]) . "” is invalid; falling back to “America/New_York”.");
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
        if (!isset($Opt["contactdb_safePasswords"]))
            $Opt["contactdb_safePasswords"] = $Opt["safePasswords"];

        // set defaultFormat
        self::$gDefaultFormat = (int) @$Opt["defaultFormat"];
        self::$gFormatInfo = null;
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



    function decision_map() {
        if ($this->_decisions === null) {
            $this->_decisions = array();
            if (@($j = $this->settingTexts["outcome_map"])
                && @($j = json_decode($j, true))
                && is_array($j))
                $this->_decisions = $j;
            $this->_decisions[0] = "Unspecified";
        }
        return $this->_decisions;
    }

    function decision_name($dnum) {
        if ($this->_decisions === null)
            $this->decision_map();
        if (($dname = @$this->_decisions[$dnum]))
            return $dname;
        else
            return false;
    }

    static function decision_name_error($dname) {
        $dname = simplify_whitespace($dname);
        if ((string) $dname === "")
            return "Empty decision name.";
        else if (preg_match(',\A(?:yes|no|any|none|unknown|unspecified)\z,i', $dname))
            return "Decision name “{$dname}” is reserved.";
        else
            return false;
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

    function topic_separator() {
        if ($this->_topic_separator_cache === null) {
            $this->_topic_separator_cache = ", ";
            foreach ($this->topic_map() as $tname)
                if (strpos($tname, ",") !== false) {
                    $this->_topic_separator_cache = "; ";
                    break;
                }
        }
        return $this->_topic_separator_cache;
    }

    function invalidate_topics() {
        unset($this->settingTexts["topic_map"]);
        unset($this->settingTexts["topic_order_map"]);
        $this->_topic_separator_cache = null;
    }



    function review_form_json() {
        $x = @$this->settingTexts["review_form"];
        if (is_string($x))
            $x = $this->settingTexts["review_form"] = json_decode($x);
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
            if ($prow)
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

    function check_track_sensitivity($type) {
        if ($this->tracks)
            foreach ($this->tracks as $k => $v)
                if (@($perm = $v->$type) !== null)
                    return true;
        return false;
    }

    function check_track_review_sensitivity() {
        return $this->_track_review_sensitivity;
    }



    function has_rounds() {
        return count($this->rounds) > 1;
    }

    function round_list() {
        return $this->rounds;
    }

    function round0_defined() {
        return isset($this->defined_round_list()[0]);
    }

    function defined_round_list() {
        if ($this->_defined_rounds === null) {
            $r = $dl = [];
            foreach ($this->rounds as $i => $rname)
                if (!$i || $rname !== ";") {
                    foreach (self::$review_deadlines as $rd)
                        if (($dl[$i] = get($this->settings, $rd . ($i ? "_$i" : ""))))
                            break;
                    $i && ($r[$i] = $rname);
                }
            if (!$dl[0]) {
                $result = Dbl::qe("select reviewId from PaperReview where reviewRound=0 limit 1");
                if (!$result || !$result->num_rows)
                    unset($dl[0]);
                Dbl::free($result);
            }
            array_key_exists(0, $dl) && ($r[0] = "unnamed");
            uasort($r, function ($a, $b) use ($dl) {
                $adl = get($dl, $a);
                $bdl = get($dl, $b);
                if ($adl && $bdl && $adl != $bdl)
                    return $adl < $bdl ? -1 : 1;
                else if (!$adl != !$bdl)
                    return $adl ? -1 : 1;
                else
                    return strcmp($a !== "unnamed" ? $a : "",
                                  $b !== "unnamed" ? $b : "");
            });
            $this->_defined_rounds = $r;
        }
        return $this->_defined_rounds;
    }

    function round_name($roundno, $expand = false) {
        if ($roundno > 0) {
            if (($rname = get($this->rounds, $roundno)) && $rname !== ";")
                return $rname;
            else if ($expand)
                return "?$roundno?"; /* should not happen */
        }
        return "";
    }

    function round_suffix($roundno) {
        if ($roundno > 0) {
            if (($rname = get($this->rounds, $roundno)) && $rname !== ";")
                return "_$rname";
        }
        return "";
    }

    static function round_name_error($rname) {
        if ((string) $rname === "")
            return "Empty round name.";
        else if (!preg_match('/\A[a-zA-Z][a-zA-Z0-9]*\z/', $rname))
            return "Round names must start with a letter and contain only letters and numbers.";
        else if (preg_match('/\A(?:none|any|default|unnamed|.*response)\z/i', $rname))
            return "Round name $rname is reserved.";
        else
            return false;
    }

    function sanitize_round_name($rname) {
        if ($rname === null)
            return $this->current_round_name();
        else if ($rname === "" || preg_match('/\A(?:\(none\)|none|default|unnamed)\z/i', $rname))
            return "";
        else if (self::round_name_error($rname))
            return false;
        else
            return $rname;
    }

    function current_round_name() {
        return (string) get($this->settingTexts, "rev_roundtag");
    }

    function current_round($add = false) {
        return $this->round_number($this->current_round_name(), $add);
    }

    function round_number($name, $add) {
        if (!$name || !strcasecmp($name, "default") || !strcasecmp($name, "unnamed"))
            return 0;
        for ($i = 1; $i != count($this->rounds); ++$i)
            if (!strcasecmp($this->rounds[$i], $name))
                return $i;
        if ($add) {
            $rtext = $this->setting_data("tag_rounds", "");
            $rtext = ($rtext ? "$rtext$name " : " $name ");
            $this->save_setting("tag_rounds", 1, $rtext);
            return $this->round_number($name, false);
        } else
            return 0;
    }

    function round_selector_options() {
        $opt = array();
        foreach ($this->defined_round_list() as $rname)
            $opt[$rname] = $rname;
        $crname = $this->current_round_name() ? : "unnamed";
        if ($crname && !get($opt, $crname))
            $opt[$crname] = $crname;
        return $opt;
    }

    function round_selector_name($roundno) {
        if ($roundno === null)
            return $this->current_round_name() ? : "unnamed";
        else if ($roundno > 0 && ($rname = get($this->rounds, $roundno))
                 && $rname !== ";")
            return $rname;
        else
            return "unnamed";
    }



    function resp_round_list() {
        if (($x = get($this->settingTexts, "resp_rounds")))
            return explode(" ", $x);
        else
            return array(1);
    }

    function resp_round_name($rnum) {
        if (($x = get($this->settingTexts, "resp_rounds"))) {
            $x = explode(" ", $x);
            if (($n = get($x, $rnum)))
                return $n;
        }
        return "1";
    }

    function resp_round_text($rnum) {
        $rname = $this->resp_round_name($rnum);
        return $rname == "1" ? "" : $rname;
    }

    static function resp_round_name_error($rname) {
        if ((string) $rname === "")
            return "Empty round name.";
        else if (!strcasecmp($rname, "none") || !strcasecmp($rname, "any")
                 || stri_ends_with($rname, "response"))
            return "Round name “{$rname}” is reserved.";
        else if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $rname))
            return "Round names must start with a letter and contain letters and numbers.";
        else
            return false;
    }

    function resp_round_number($rname) {
        if (!$rname || $rname === 1 || $rname === "1" || $rname === true
            || !strcasecmp($rname, "none"))
            return 0;
        $rtext = (string) get($this->settingTexts, "resp_rounds");
        foreach (explode(" ", $rtext) as $i => $x)
            if (!strcasecmp($x, $rname))
                return $i;
        return false;
    }


    static function format_info($format) {
        global $Opt;
        if (self::$gFormatInfo === null) {
            if (is_array(get($Opt, "formatInfo")))
                self::$gFormatInfo = $Opt["formatInfo"];
            else if (is_string(get($Opt, "formatInfo")))
                self::$gFormatInfo = json_decode($Opt["formatInfo"], true);
            if (!self::$gFormatInfo)
                self::$gFormatInfo = array();
        }
        return get(self::$gFormatInfo, $format);
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
        if (!isset($_SESSION[$this->dsn][$name])
            || !is_array($_SESSION[$this->dsn][$name]))
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
    function update_papersub_setting($forsubmit) {
        $papersub = defval($this->settings, "papersub");
        if ($papersub === null && $forsubmit)
            $this->q("insert into Settings (name, value) values ('papersub',1) on duplicate key update value=value");
        else if ($papersub <= 0 || !$forsubmit)
            // see also settings.php
            $this->q("update Settings set value=(select ifnull(min(paperId),0) from Paper where " . ($this->can_pc_see_all_submissions() ? "timeWithdrawn<=0" : "timeSubmitted>0") . ") where name='papersub'");
        $this->settings["papersub"] = Dbl::fetch_ivalue("select value from Settings where name='papersub'");
    }

    function update_paperacc_setting($foraccept) {
        if (!isset($this->settings["paperacc"]) && $foraccept)
            $this->q("insert into Settings (name, value) values ('paperacc', " . time() . ") on duplicate key update value=value");
        else if (defval($this->settings, "paperacc") <= 0 || !$foraccept)
            $this->q("update Settings set value=(select max(outcome) from Paper where timeSubmitted>0 group by paperId>0) where name='paperacc'");
        $this->settings["paperacc"] = Dbl::fetch_ivalue("select value from Settings where name='paperacc'");
    }

    function update_rev_tokens_setting($always) {
        if ($always || defval($this->settings, "rev_tokens", 0) < 0) {
            $this->qe("insert into Settings (name, value) select 'rev_tokens', count(reviewId) from PaperReview where reviewToken!=0 on duplicate key update value=values(value)");
            $this->settings["rev_tokens"] = Dbl::fetch_ivalue("select value from Settings where name='rev_tokens'");
        }
    }

    function update_paperlead_setting() {
        $this->qe("insert into Settings (name, value) select 'paperlead', count(paperId) from Paper where leadContactId>0 or shepherdContactId>0 limit 1 on duplicate key update value=values(value)");
        $this->settings["paperlead"] = Dbl::fetch_ivalue("select value from Settings where name='paperlead'");
    }

    function update_papermanager_setting() {
        $this->qe("insert into Settings (name, value) select 'papermanager', count(paperId) from Paper where managerContactId>0 limit 1 on duplicate key update value=values(value)");
        $this->settings["papermanager"] = Dbl::fetch_ivalue("select value from Settings where name='papermanager'");
    }


    static private $invariant_row = null;

    private function invariantq($q, $args = []) {
        $result = Dbl::ql_apply($this->dblink, $q, $args);
        if ($result) {
            self::$invariant_row = $result->fetch_row();
            $result->close();
            return !!self::$invariant_row;
        } else
            return null;
    }

    function check_invariants() {
        global $Opt;

        $any = $this->invariantq("select paperId from Paper where " . ($this->can_pc_see_all_submissions() ? "timeWithdrawn<=0" : "timeSubmitted>0") . " limit 1");
        if ($any !== !!@$this->settings["papersub"])
            trigger_error($Opt["dbName"] . " invariant error: papersub");

        $any = $this->invariantq("select paperId from Paper where outcome>0 and timeSubmitted>0 limit 1");
        if ($any !== !!@$this->settings["paperacc"])
            trigger_error($Opt["dbName"] . " invariant error: paperacc");

        $any = $this->invariantq("select reviewId from PaperReview where reviewToken!=0 limit 1");
        if ($any !== !!@$this->settings["rev_tokens"])
            trigger_error($Opt["dbName"] . " invariant error: rev_tokens");

        $any = $this->invariantq("select paperId from Paper where leadContactId>0 or shepherdContactId>0 limit 1");
        if ($any !== !!@$this->settings["paperlead"])
            trigger_error($Opt["dbName"] . " invariant error: paperlead");

        $any = $this->invariantq("select paperId from Paper where managerContactId>0 limit 1");
        if ($any !== !!@$this->settings["papermanager"])
            trigger_error($Opt["dbName"] . " invariant error: papermanager");

        // no empty text options
        $text_options = array();
        foreach (PaperOption::option_list($this) as $ox)
            if ($ox->type === "text")
                $text_options[] = $ox->id;
        if (count($text_options)) {
            $q = Dbl::format_query("select paperId from PaperOption where optionId ?a and data='' limit 1", $text_options);
            $any = $this->invariantq($q);
            if ($any)
                trigger_error($Opt["dbName"] . " invariant error: text option with empty text");
        }

        // no funky PaperConflict entries
        $any = $this->invariantq("select paperId from PaperConflict where conflictType<=0 limit 1");
        if ($any)
            trigger_error($Opt["dbName"] . " invariant error: PaperConflict with zero conflictType");

        // no null submitted reviewWordCounts
        $any = $this->invariantq("select reviewId from PaperReview where reviewSubmitted>0 and reviewWordCount is null limit 1");
        if ($any)
            trigger_error($Opt["dbName"] . " invariant error: submitted PaperReview with null reviewWordCount");

        // reviewNeedsSubmit is defined correctly
        $any = $this->invariantq("select r.paperId, r.reviewId from PaperReview r
            left join (select paperId, requestedBy, count(reviewId) ct, count(reviewSubmitted) cs
                       from PaperReview where reviewType<" . REVIEW_SECONDARY . "
                       group by paperId, requestedBy) q
                on (q.paperId=r.paperId and q.requestedBy=r.contactId)
            where r.reviewType=" . REVIEW_SECONDARY . " and reviewSubmitted is null
            and if(coalesce(q.ct,0)=0,1,if(q.cs=0,-1,0))!=r.reviewNeedsSubmit
            limit 1");
        if ($any)
            trigger_error($Opt["dbName"] . " invariant error: bad reviewNeedsSubmit for review #" . self::$invariant_row[0] . "/" . self::$invariant_row[1]);

        // anonymous users are disabled
        $any = $this->invariantq("select email from ContactInfo where email regexp '^anonymous[0-9]*\$' and not disabled limit 1");
        if ($any)
            trigger_error($Opt["dbName"] . " invariant error: anonymous user is not disabled");

        // no one has password '*'
        $any = $this->invariantq("select email from ContactInfo where password='*' limit 1");
        if ($any)
            trigger_error($Opt["dbName"] . " invariant error: password '*'");
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
            $this->crosscheck_settings();
            if (str_starts_with($name, "opt."))
                $this->crosscheck_options();
        }
        return $change;
    }

    function update_schema_version($n) {
        if (!$n)
            $n = Dbl::fetch_ivalue("select value from Settings where name='allowPaperOption'");
        if ($n && Dbl::ql("update Settings set value=$n where name='allowPaperOption'")) {
            $this->sversion = $this->settings["allowPaperOption"] = $n;
            return true;
        } else
            return false;
    }

    function invalidateCaches($caches = null) {
        global $OK;
        if (self::$no_invalidate_caches)
            return;
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
        if (!$caches || isset($caches["rf"])) {
            ReviewForm::clear_cache();
            $this->_defined_rounds = null;
        }
        $ok = true;
        if (count($inserts))
            $ok = $ok && ($this->qe("insert into Settings (name, value) values " . join(",", $inserts) . " on duplicate key update value=values(value)") !== false);
        if (count($removes))
            $ok = $ok && ($this->qe("delete from Settings where name in (" . join(",", $removes) . ")") !== false);
        return $ok;
    }

    function ql($query) {
        $result = $this->dblink->query($query);
        if (!$result)
            error_log(caller_landmark() . ": " . $this->dblink->error);
        return $result;
    }

    function q($query) {
        global $OK;
        $result = $this->dblink->query($query);
        if ($result === false)
            $OK = false;
        return $result;
    }

    function db_error_html($getdb = true, $while = "") {
        $text = "<p>Database error";
        if ($while)
            $text .= " $while";
        if ($getdb)
            $text .= ": " . htmlspecialchars($this->dblink->error);
        return $text . "</p>";
    }

    function db_error_text($getdb = true, $while = "") {
        $text = "Database error";
        if ($while)
            $text .= " $while";
        if ($getdb)
            $text .= ": " . $this->dblink->error;
        return $text;
    }

    function query_error_handler($dblink, $query) {
        global $OK;
        $landmark = caller_landmark(1, "/^(?:Dbl::|Conf::q|call_user_func)/");
        if (PHP_SAPI == "cli")
            fwrite(STDERR, "$landmark: database error: $dblink->error in $query\n");
        else {
            error_log("$landmark: database error: $dblink->error in $query");
            self::msg_error("<p>" . htmlspecialchars($landmark) . ": database error: " . htmlspecialchars($this->dblink->error) . " in " . Ht::pre_text_wrap($query) . "</p>");
        }
        $OK = false;
    }

    function qe($query) {
        return Dbl::qe_raw($this->dblink, $query);
    }


    // times

    static function _dateFormat($type) {
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
            if (!isset($Opt["dateFormatObscure"]))
                $Opt["dateFormatObscure"] = "j M Y";
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
        if ($type == "timestamp")
            return $Opt["timestampFormat"];
        else if ($type == "obscure")
            return $Opt["dateFormatObscure"];
        else if ($type)
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
        $d = preg_replace('/\butc([-+])/i', 'GMT$1', $d);
        return strtotime($d, $reference);
    }

    function _printableTime($value, $type, $useradjust, $preadjust = null) {
        global $Opt;
        if ($value <= 0)
            return "N/A";
        $t = date(self::_dateFormat($type), $value);
        if ($Opt["dateFormatSimplifier"])
            $t = preg_replace($Opt["dateFormatSimplifier"], "", $t);
        if ($type !== "obscure") {
            if ($Opt["dateFormatTimezone"] === null)
                $t .= " " . date("T", $value);
            else if ($Opt["dateFormatTimezone"])
                $t .= " " . $Opt["dateFormatTimezone"];
        }
        if ($preadjust)
            $t .= $preadjust;
        if ($useradjust) {
            $sp = strpos($useradjust, " ");
            $t .= "<$useradjust class=\"usertime\" id=\"usertime$this->usertimeId\" style=\"display:none\"></" . ($sp ? substr($useradjust, 0, $sp) : $useradjust) . ">";
            Ht::stash_script("setLocalTime('usertime$this->usertimeId',$value)");
            ++$this->usertimeId;
        }
        return $t;
    }
    function printableTime($value, $useradjust = false, $preadjust = null) {
        return $this->_printableTime($value, true, $useradjust, $preadjust);
    }
    function obscure_time($timestamp) {
        $timestamp = (int) ($timestamp + 0.5);
        $offset = 0;
        if (($zone = timezone_open(date_default_timezone_get())))
            $offset = $zone->getOffset(new DateTime("@$timestamp"));
        return $timestamp - (($timestamp + $offset) % 86400) + 43200;
    }
    function unparse_time_obscure($value, $useradjust = false, $preadjust = null) {
        return $this->_printableTime($value, "obscure", $useradjust, $preadjust);
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
        global $Now;
        $t = @$this->settings[$name];
        return $t !== null && $t > 0 && $t <= $Now;
    }
    function deadlinesAfter($name, $grace = null) {
        global $Now;
        $t = @$this->settings[$name];
        if ($t !== null && $t > 0 && $grace && ($g = @$this->settings[$grace]))
            $t += $g;
        return $t !== null && $t > 0 && $t <= $Now;
    }
    function deadlinesBetween($name1, $name2, $grace = null) {
        global $Now;
        $t = @$this->settings[$name1];
        if (($t === null || $t <= 0 || $t > $Now) && $name1)
            return false;
        $t = @$this->settings[$name2];
        if ($t !== null && $t > 0 && $grace && ($g = @$this->settings[$grace]))
            $t += $g;
        return $t === null || $t <= 0 || $t >= $Now;
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
        return $this->setting("final_open") > 0;
    }
    function timeSubmitFinalPaper() {
        return $this->timeAuthorViewDecision()
            && $this->deadlinesBetween("final_open", "final_done", "final_grace");
    }
    function timeAuthorViewReviews($reviewsOutstanding = false) {
        // also used to determine when authors can see review counts
        // and comments.  see also mailtemplate.php and PaperInfo::notify
        return $this->au_seerev > 0
            && ($this->au_seerev != self::AUSEEREV_UNLESSINCOMPLETE || !$reviewsOutstanding);
    }
    private function time_author_respond_all_rounds() {
        $allowed = array();
        foreach ($this->resp_round_list() as $i => $rname) {
            $isuf = $i ? "_$i" : "";
            if ($this->deadlinesBetween("resp_open$isuf", "resp_done$isuf", "resp_grace$isuf"))
                $allowed[$i] = $rname;
        }
        return $allowed;
    }
    function time_author_respond($round = null) {
        if (!$this->au_seerev || !$this->setting("resp_active"))
            return $round === null ? array() : false;
        if ($round === null)
            return $this->time_author_respond_all_rounds();
        $isuf = $round ? "_$round" : "";
        return $this->deadlinesBetween("resp_open$isuf", "resp_done$isuf", "resp_grace$isuf");
    }
    function timeAuthorViewDecision() {
        return $this->setting("seedec") == self::SEEDEC_ALL;
    }
    function time_review_open() {
        global $Now;
        $rev_open = +get($this->settings, "rev_open");
        return 0 < $rev_open && $rev_open <= $Now;
    }
    function review_deadline($round, $isPC, $hard) {
        $dn = ($isPC ? "pcrev_" : "extrev_") . ($hard ? "hard" : "soft");
        if ($round === null)
            $round = $this->current_round(false);
        else if (is_object($round))
            $round = $round->reviewRound ? : 0;
        if ($round && isset($this->settings["{$dn}_$round"]))
            $dn .= "_$round";
        return $dn;
    }
    function missed_review_deadline($round, $isPC, $hard) {
        global $Now;
        $rev_open = +get($this->settings, "rev_open");
        if (!(0 < $rev_open && $rev_open <= $Now))
            return "rev_open";
        $dn = $this->review_deadline($round, $isPC, $hard);
        $dv = +get($this->settings, $dn);
        if ($dv > 0 && $dv + +get($this->settings, "rev_grace") < $Now)
            return $dn;
        return false;
    }
    function time_review($round, $isPC, $hard) {
        return !$this->missed_review_deadline($round, $isPC, $hard);
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
    function timePCViewPaper($prow, $pdf) {
        if ($prow->timeWithdrawn > 0)
            return false;
        else if ($prow->timeSubmitted > 0)
            return !$pdf || $this->_pc_see_pdf;
        else
            return !$pdf && $this->can_pc_see_all_submissions();
    }
    function timeReviewerViewSubmittedPaper() {
        return true;
    }
    function timeEmailChairAboutReview() {
        return @$this->settings["rev_notifychair"] > 0;
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

    function has_any_accepts() {
        return !!@$this->settings["paperacc"];
    }

    function has_any_lead_or_shepherd() {
        return !!@$this->settings["paperlead"];
    }

    function has_any_manager() {
        return !!@$this->settings["papermanager"];
    }

    function can_pc_see_all_submissions() {
        if ($this->_pc_seeall_cache === null) {
            $this->_pc_seeall_cache = @$this->settings["pc_seeall"] ? : 0;
            if ($this->_pc_seeall_cache > 0 && !$this->timeFinalizePaper())
                $this->_pc_seeall_cache = 0;
        }
        return $this->_pc_seeall_cache > 0;
    }


    function echoScript($script = "") {
        echo Ht::take_stash();
        if ($script)
            echo "<script>", $script, "</script>";
    }

    function footerScript($script, $uniqueid = null) {
        Ht::stash_script($script, $uniqueid);
    }

    function footerHtml($html, $uniqueid = null) {
        Ht::stash_html($html, $uniqueid);
    }


    function set_siteurl($base) {
        global $Opt;
        $old_siteurl = Navigation::siteurl();
        $base = Navigation::set_siteurl($base);
        if ($Opt["assetsUrl"] === $old_siteurl) {
            $Opt["assetsUrl"] = $base;
            Ht::$img_base = $Opt["assetsUrl"] . "images/";
        }
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
            if ($o->has_document())
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
        $q .= "s.filename, s.infoJson, $documentType documentType, s.paperStorageId from Paper p";
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
        global $Opt, $Me;

        if (is_object($docid))
            $docs = array($docid);
        else {
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
                    $doc->mimetype = Mimetype::PDF;
                $doc->filename = HotCRPDocument::filename($doc);
                $docs[] = $doc;
            }
            Dbl::free($result);
        }

        if (count($docs) == 1 && $docs[0]->paperStorageId <= 1)
            return set_error_html("Paper #" . $docs[0]->paperId . " hasn’t been uploaded yet.");
        $downloadname = false;
        if (count($docs) > 1)
            $downloadname = $Opt["downloadPrefix"] . pluralx(2, HotCRPDocument::unparse_dtype($documentType)) . ".zip";
        return Filer::multidownload($docs, $downloadname, $attachment);
    }

    function downloadPaper($paperId, $attachment, $documentType = DTYPE_SUBMISSION, $docid = null) {
        global $Me;
        $result = $this->__downloadPaper($paperId, $attachment, $documentType, $docid);
        if ($result->error) {
            self::msg_error($result->error_html);
            return false;
        } else
            return true;
    }


    //
    // Paper search
    //

    static private function _cvt_numeric_set($optarr) {
        $ids = array();
        foreach (mkarray($optarr) as $x)
            if (($x = cvtint($x)) > 0)
                $ids[] = $x;
        return $ids;
    }

    function query_all_reviewer_preference() {
        return "group_concat(concat(contactId,' ',preference,' ',coalesce(expertise,'.')) separator ',')";
    }

    function query_topic_interest($table = "") {
        return $table . "interest";
    }

    function query_topic_interest_score() {
        return "interest";
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
        //   "tags"             Include paperTags
        //   "tagIndex" => $tag Include tagIndex of named tag
        //   "tagIndex" => tag array -- include tagIndex, tagIndex1, ...
        //   "topics"
        //   "options"
        //   "scores" => array(fields to score)
        //   "assignments"
        //   "order" => $sql    $sql is SQL 'order by' clause (or empty)

        $reviewerQuery = isset($options["myReviews"]) || isset($options["allReviews"]) || isset($options["myReviewRequests"]) || isset($options["myReviewsOpt"]) || isset($options["myOutstandingReviews"]);
        $allReviewerQuery = isset($options["allReviews"]) || isset($options["allReviewScores"]);
        $scoresQuery = !$reviewerQuery && isset($options["allReviewScores"]);
        if (is_object($contact))
            $contactId = $contact->contactId;
        else {
            $contactId = (int) $contact;
            $contact = null;
        }
        if (isset($options["reviewer"]) && is_object($options["reviewer"]))
            $reviewerContactId = $options["reviewer"]->contactId;
        else if (isset($options["reviewer"]))
            $reviewerContactId = $options["reviewer"];
        else
            $reviewerContactId = $contactId;
        if (get($options, "author"))
            $myPaperReview = null;
        else if ($allReviewerQuery)
            $myPaperReview = "MyPaperReview";
        else
            $myPaperReview = "PaperReview";

        // paper selection
        $paperset = array();
        if (isset($options["paperId"]))
            $paperset[] = self::_cvt_numeric_set($options["paperId"]);
        if (isset($options["reviewId"])) {
            if (is_numeric($options["reviewId"])) {
                $result = Dbl::qe("select paperId from PaperReview where reviewId=" . $options["reviewId"]);
                $paperset[] = self::_cvt_numeric_set(edb_first_columns($result));
            } else if (preg_match('/^(\d+)([A-Z][A-Z]?)$/i', $options["reviewId"], $m)) {
                $result = Dbl::qe("select paperId from PaperReview where paperId=$m[1] and reviewOrdinal=" . parseReviewOrdinal($m[2]));
                $paperset[] = self::_cvt_numeric_set(edb_first_columns($result));
            } else
                $paperset[] = array();
        }
        if (isset($options["commentId"])) {
            $result = Dbl::qe("select paperId from PaperComment where commentId" . sql_in_numeric_set(self::_cvt_numeric_set($options["commentId"])));
            $paperset[] = self::_cvt_numeric_set(edb_first_columns($result));
        }
        if (count($paperset) > 1)
            $paperset = array(call_user_func_array("array_intersect", $paperset));
        $papersel = "";
        if (count($paperset))
            $papersel = "paperId" . sql_in_numeric_set($paperset[0]) . " and ";

        // prepare query: basic tables
        $where = array();

        $joins = array("Paper");

        $cols = array("Paper.*, PaperConflict.conflictType");

        $aujoinwhere = null;
        if (get($options, "author") && $contact
            && ($aujoinwhere = $contact->actAuthorSql("PaperConflict", true)))
            $where[] = $aujoinwhere;
        if (get($options, "author") && !$aujoinwhere)
            $joins[] = "join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$contactId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")";
        else
            $joins[] = "left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$contactId)";

        // my review
        $qr = "";
        if ($contact && ($tokens = $contact->review_tokens()))
            $qr = " or PaperReview.reviewToken in (" . join(", ", $tokens) . ")";
        if (get($options, "myReviewRequests"))
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.requestedBy=$contactId and PaperReview.reviewType=" . REVIEW_EXTERNAL . ")";
        else if (get($options, "myReviews"))
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$contactId$qr))";
        else if (get($options, "myOutstandingReviews"))
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$contactId$qr) and PaperReview.reviewNeedsSubmit!=0)";
        else if (get($options, "myReviewsOpt"))
            $joins[] = "left join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$contactId$qr))";
        else if (get($options, "allReviews") || get($options, "allReviewScores")) {
            $x = (get($options, "reviewLimitSql") ? " and (" . $options["reviewLimitSql"] . ")" : "");
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId$x)";
        } else if (!get($options, "author"))
            $joins[] = "left join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$contactId$qr))";

        // started reviews
        if (get($options, "startedReviewCount")) {
            $joins[] = "left join (select paperId, count(*) count from PaperReview where {$papersel}(reviewSubmitted or reviewNeedsSubmit>0) group by paperId) R_started on (R_started.paperId=Paper.paperId)";
            $cols[] = "coalesce(R_started.count,0) startedReviewCount";
        }

        // submitted reviews
        $j = "select paperId, count(*) count";
        $before_ncols = count($cols);
        if (get($options, "startedReviewCount"))
            $cols[] = "coalesce(R_submitted.count,0) reviewCount";
        if (get($options, "scores"))
            foreach ($options["scores"] as $fid) {
                $cols[] = "R_submitted.{$fid}Scores";
                if ($myPaperReview)
                    $cols[] = "$myPaperReview.$fid";
                $j .= ", group_concat($fid order by reviewId) {$fid}Scores";
            }
        if (get($options, "reviewTypes") || get($options, "reviewIdentities")) {
            $cols[] = "R_submitted.reviewTypes";
            $j .= ", group_concat(reviewType order by reviewId) reviewTypes";
        }
        if (get($options, "reviewIdentities")) {
            $cols[] = "R_submitted.reviewRequestedBys";
            $j .= ", group_concat(requestedBy order by reviewId) reviewRequestedBys";
            if ($this->review_blindness() == self::BLIND_OPTIONAL) {
                $cols[] = "R_submitted.reviewBlinds";
                $j .= ", group_concat(reviewBlind order by reviewId) reviewBlinds";
            }
            if ($contact && $contact->review_tokens()) {
                $cols[] = "R_submitted.reviewTokens";
                $j .= ", group_concat(reviewToken order by reviewId) reviewTokens";
            }
        }
        if (get($options, "reviewRounds")) {
            $cols[] = "R_submitted.reviewRounds";
            $j .= ", group_concat(reviewRound order by reviewId) reviewRounds";
        }
        if (get($options, "reviewWordCounts") && $this->sversion >= 99) {
            $cols[] = "R_submitted.reviewWordCounts";
            $j .= ", group_concat(reviewWordCount order by reviewId) reviewWordCounts";
        }
        if (get($options, "reviewOrdinals")) {
            $cols[] = "R_submitted.reviewOrdinals";
            $j .= ", group_concat(reviewOrdinal order by reviewId) reviewOrdinals";
        }
        if (get($options, "reviewTypes") || get($options, "scores") || get($options, "reviewContactIds") || get($options, "reviewOrdinals") || get($options, "reviewIdentities")) {
            $cols[] = "R_submitted.reviewContactIds";
            $j .= ", group_concat(contactId order by reviewId) reviewContactIds";
        }
        if (count($cols) != $before_ncols)
            $joins[] = "left join ($j from PaperReview where {$papersel}reviewSubmitted>0 group by paperId) R_submitted on (R_submitted.paperId=Paper.paperId)";

        // assignments
        if (get($options, "assignments")) {
            $j = "select paperId, group_concat(contactId order by reviewId) assignmentContactIds, group_concat(reviewType order by reviewId) assignmentReviewTypes, group_concat(reviewRound order by reviewId) assignmentReviewRounds";
            $cols[] = "Ass.assignmentContactIds, Ass.assignmentReviewTypes, Ass.assignmentReviewRounds";
            $joins[] = "left join ($j from PaperReview where {$papersel}true group by paperId) Ass on (Ass.paperId=Paper.paperId)";
        }

        // fields
        if (get($options, "author"))
            $cols[] = "null reviewType, null reviewId, null myReviewType";
        else {
            // see also papercolumn.php
            array_push($cols, "PaperReview.reviewType, PaperReview.reviewId",
                       "PaperReview.reviewModified, PaperReview.reviewSubmitted",
                       "PaperReview.reviewNeedsSubmit, PaperReview.reviewOrdinal",
                       "PaperReview.reviewBlind, PaperReview.reviewToken",
                       "PaperReview.contactId as reviewContactId, PaperReview.requestedBy",
                       "max($myPaperReview.reviewType) as myReviewType",
                       "max($myPaperReview.reviewSubmitted) as myReviewSubmitted",
                       "min($myPaperReview.reviewNeedsSubmit) as myReviewNeedsSubmit",
                       "$myPaperReview.contactId as myReviewContactId",
                       "PaperReview.reviewRound");
        }

        if ($reviewerQuery || $scoresQuery) {
            $cols[] = "PaperReview.reviewEditVersion as reviewEditVersion";
            $cols[] = ($this->sversion >= 105 ? "PaperReview.reviewFormat" : "null") . " as reviewFormat";
            foreach (ReviewForm::all_fields() as $f)
                if ($reviewerQuery || $f->has_options)
                    $cols[] = "PaperReview.$f->id as $f->id";
        }

        if ($myPaperReview == "MyPaperReview")
            $joins[] = "left join PaperReview as MyPaperReview on (MyPaperReview.paperId=Paper.paperId and MyPaperReview.contactId=$contactId)";

        if (get($options, "topics") || get($options, "topicInterest") || get($options, "topicInterestScore")) {
            $j = "left join (select paperId";
            if (get($options, "topics") || get($options, "topicInterest")) {
                $j .= ", group_concat(PaperTopic.topicId) as topicIds";
                $cols[] = "PaperTopics.topicIds";
            }
            if (get($options, "topicInterest")) {
                $j .= ", group_concat(ifnull(" . $this->query_topic_interest("TopicInterest.") . ",0)) as topicInterest";
                $cols[] = "PaperTopics.topicInterest";
            }
            if (get($options, "topicInterestScore")) {
                $j .= ", sum(" . $this->query_topic_interest_score() . ") as topicInterestScore";
                $cols[] = "coalesce(PaperTopics.topicInterestScore,0) as topicInterestScore";
            }
            $j .= " from PaperTopic";
            if (get($options, "topicInterest") || get($options, "topicInterestScore"))
                $j .= " left join TopicInterest on (TopicInterest.topicId=PaperTopic.topicId and TopicInterest.contactId=$reviewerContactId)";
            $j .= " where {$papersel}true group by paperId) as PaperTopics on (PaperTopics.paperId=Paper.paperId)";
            $joins[] = $j;
        }

        if (get($options, "options") && get($this->settingTexts, "options")) {
            $joins[] = "left join (select paperId, group_concat(PaperOption.optionId, '#', value) as optionIds from PaperOption where {$papersel}true group by paperId) as PaperOptions on (PaperOptions.paperId=Paper.paperId)";
            $cols[] = "PaperOptions.optionIds";
        } else if (get($options, "options"))
            $cols[] = "'' as optionIds";

        if (get($options, "tags")) {
            $joins[] = "left join (select paperId, group_concat(' ', tag, '#', tagIndex order by tag separator '') as paperTags from PaperTag where {$papersel}true group by paperId) as PaperTags on (PaperTags.paperId=Paper.paperId)";
            $cols[] = "PaperTags.paperTags";
        }
        if (get($options, "tagIndex") && !is_array($options["tagIndex"]))
            $options["tagIndex"] = array($options["tagIndex"]);
        if (get($options, "tagIndex"))
            for ($i = 0; $i < count($options["tagIndex"]); ++$i) {
                $joins[] = "left join PaperTag as TagIndex$i on (TagIndex$i.paperId=Paper.paperId and TagIndex$i.tag='" . sqlq($options["tagIndex"][$i]) . "')";
                $cols[] = "TagIndex$i.tagIndex as tagIndex" . ($i ? : "");
            }

        if (get($options, "reviewerPreference")) {
            $joins[] = "left join PaperReviewPreference on (PaperReviewPreference.paperId=Paper.paperId and PaperReviewPreference.contactId=$reviewerContactId)";
            $cols[] = "coalesce(PaperReviewPreference.preference, 0) as reviewerPreference";
            $cols[] = "PaperReviewPreference.expertise as reviewerExpertise";
        }

        if (get($options, "allReviewerPreference") || get($options, "desirability")) {
            $subq = "select paperId";
            if (get($options, "allReviewerPreference")) {
                $subq .= ", " . $this->query_all_reviewer_preference() . " as allReviewerPreference";
                $cols[] = "APRP.allReviewerPreference";
            }
            if (get($options, "desirability")) {
                $subq .= ", sum(if(preference<=-100,0,greatest(least(preference,1),-1))) as desirability";
                $cols[] = "coalesce(APRP.desirability,0) as desirability";
            }
            $subq .= " from PaperReviewPreference where {$papersel}true group by paperId";
            $joins[] = "left join ($subq) as APRP on (APRP.paperId=Paper.paperId)";
        }

        if (get($options, "allConflictType")) {
            $joins[] = "left join (select paperId, group_concat(concat(contactId,' ',conflictType) separator ',') as allConflictType from PaperConflict where {$papersel}conflictType>0 group by paperId) as AllConflict on (AllConflict.paperId=Paper.paperId)";
            $cols[] = "AllConflict.allConflictType";
        }

        if (get($options, "reviewer")) {
            $joins[] = "left join PaperConflict RPC on (RPC.paperId=Paper.paperId and RPC.contactId=$reviewerContactId)";
            $joins[] = "left join PaperReview RPR on (RPR.paperId=Paper.paperId and RPR.contactId=$reviewerContactId)";
            $cols[] = "RPC.conflictType reviewerConflictType, RPR.reviewType reviewerReviewType";
        }

        if (get($options, "allComments")) {
            $joins[] = "join PaperComment on (PaperComment.paperId=Paper.paperId)";
            $joins[] = "left join PaperConflict as CommentConflict on (CommentConflict.paperId=PaperComment.paperId and CommentConflict.contactId=PaperComment.contactId)";
            array_push($cols, "PaperComment.commentId, PaperComment.contactId as commentContactId",
                       "CommentConflict.conflictType as commentConflictType",
                       "PaperComment.timeModified, PaperComment.comment",
                       "PaperComment.replyTo, PaperComment.commentType");
        }

        if (get($options, "reviewerName")) {
            if ($options["reviewerName"] === "lead" || $options["reviewerName"] === "shepherd")
                $joins[] = "left join ContactInfo as ReviewerContactInfo on (ReviewerContactInfo.contactId=Paper.{$options['reviewerName']}ContactId)";
            else if (get($options, "allComments"))
                $joins[] = "left join ContactInfo as ReviewerContactInfo on (ReviewerContactInfo.contactId=PaperComment.contactId)";
            else if (get($options, "reviewerName"))
                $joins[] = "left join ContactInfo as ReviewerContactInfo on (ReviewerContactInfo.contactId=PaperReview.contactId)";
            array_push($cols, "ReviewerContactInfo.firstName as reviewFirstName",
                       "ReviewerContactInfo.lastName as reviewLastName",
                       "ReviewerContactInfo.email as reviewEmail",
                       "ReviewerContactInfo.lastLogin as reviewLastLogin");
        }

        if (get($options, "foldall"))
            $cols[] = "1 as folded";

        // conditions
        if (count($paperset))
            $where[] = "Paper.paperId" . sql_in_numeric_set($paperset[0]);
        if (get($options, "finalized"))
            $where[] = "timeSubmitted>0";
        else if (get($options, "unsub"))
            $where[] = "timeSubmitted<=0";
        if (get($options, "accepted"))
            $where[] = "outcome>0";
        if (get($options, "undecided"))
            $where[] = "outcome=0";
        if (get($options, "active") || get($options, "myReviews")
            || get($options, "myReviewRequests"))
            $where[] = "timeWithdrawn<=0";
        if (get($options, "myLead"))
            $where[] = "leadContactId=$contactId";
        if (get($options, "unmanaged"))
            $where[] = "managerContactId=0";

        $pq = "select " . join(",\n    ", $cols)
            . "\nfrom " . join("\n    ", $joins);
        if (count($where))
            $pq .= "\nwhere " . join("\n    and ", $where);

        // grouping and ordering
        if (get($options, "allComments"))
            $pq .= "\ngroup by Paper.paperId, PaperComment.commentId";
        else if ($reviewerQuery || $scoresQuery)
            $pq .= "\ngroup by Paper.paperId, PaperReview.reviewId";
        else
            $pq .= "\ngroup by Paper.paperId";
        if (get($options, "order") && $options["order"] != "order by Paper.paperId")
            $pq .= "\n" . $options["order"];
        else {
            $pq .= "\norder by Paper.paperId";
            if ($reviewerQuery || $scoresQuery)
                $pq .= ", PaperReview.reviewOrdinal";
            if (isset($options["allComments"]))
                $pq .= ", PaperComment.commentId";
        }

        //$this->infoMsg(Ht::pre_text_wrap($pq));
        return $pq . "\n";
    }

    function paperRow($sel, $contact, &$whyNot = null) {
        $ret = null;
        $whyNot = array();

        if (!is_array($sel))
            $sel = array("paperId" => $sel);
        if (isset($sel["paperId"]))
            $whyNot["paperId"] = $sel["paperId"];
        if (isset($sel["reviewId"]))
            $whyNot["reviewId"] = $sel["reviewId"];

        if (isset($sel['paperId']) && cvtint($sel['paperId']) < 0)
            $whyNot['invalidId'] = 'paper';
        else if (isset($sel['reviewId']) && cvtint($sel['reviewId']) < 0
                 && !preg_match('/^\d+[A-Z][A-Z]?$/i', $sel['reviewId']))
            $whyNot['invalidId'] = 'review';
        else {
            $q = $this->paperQuery($contact, $sel);
            $result = $this->q($q);

            if (!$result)
                $whyNot["dbError"] = "Database error while fetching paper (" . htmlspecialchars($q) . "): " . htmlspecialchars($this->dblink->error);
            else if ($result->num_rows == 0)
                $whyNot["noPaper"] = 1;
            else
                $ret = PaperInfo::fetch($result, $contact);

            Dbl::free($result);
        }

        return $ret;
    }

    function review_rows($q, $contact) {
        $result = $this->qe($q);
        $rrows = array();
        while (($row = PaperInfo::fetch($result, $contact)))
            $rrows[$row->reviewId] = $row;
        Dbl::free($result);
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
        Dbl::free($result);
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

        $q = "select PaperReview.*,
                ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, ContactInfo.roles as contactRoles,
                ContactInfo.contactTags,
                ReqCI.firstName as reqFirstName, ReqCI.lastName as reqLastName, ReqCI.email as reqEmail";
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
        if (get($selector, "rev_tokens"))
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
                order by " . join(", ", $order) . ", reviewOrdinal, timeRequested, reviewType desc, reviewId";

        $result = $this->q($q);
        if (!$result) {
            $whyNot['dbError'] = "Database error while fetching review (" . htmlspecialchars($q) . "): " . htmlspecialchars($this->dblink->error);
            return null;
        }

        $x = array();
        while (($row = edb_orow($result)))
            $x[] = $row;
        Dbl::free($result);

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
                join ContactInfo c on (c.contactId=PRP.contactId and (c.roles&" . Contact::ROLE_PC . ")!=0)
                join Paper P on (P.paperId=PRP.paperId)
                left join PaperConflict PC on (PC.paperId=PRP.paperId and PC.contactId=PRP.contactId)
                where PRP.preference<=-100 and coalesce(PC.conflictType,0)<=0
                  and P.timeWithdrawn<=0";
        if ($type != "all" && ($type || !$this->can_pc_see_all_submissions()))
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
        return "          Paper.title,
                substring(Paper.title from 1 for 80) as shortTitle,
                Paper.timeSubmitted,
                Paper.timeWithdrawn,
                Paper.blind as paperBlind,
                Paper.outcome,
                Paper.managerContactId,
                Paper.leadContactId,
                ContactInfo.firstName as reviewFirstName,
                ContactInfo.lastName as reviewLastName,
                ContactInfo.email as reviewEmail,
                PaperConflict.conflictType,
                MyPaperReview.reviewType as myReviewType,
                MyPaperReview.reviewSubmitted as myReviewSubmitted,
                MyPaperReview.reviewNeedsSubmit as myReviewNeedsSubmit,
                MyPaperReview.contactId as myReviewContactId\n";
    }

    private function _commentFlowQuery($contact, $t0, $limit) {
        // XXX review tokens
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
        // XXX review tokens
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

    static function _activity_compar($a, $b) {
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
        $last_time = INF;
        // We read new comment/review rows when the current set is empty.

        while (1) {
            // load $curcr with most recent viewable comment
            if ($curcr)
                /* do nothing */;
            else if (($curcr = array_pop($crows))) {
                if (!$contact->can_view_comment($curcr, $curcr, false)) {
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
                if (!$contact->can_view_review($currr, $currr, false)) {
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
            // if above limit, ran out of activity
            if (count($activity) >= $limit
                && (!$curcr || $curcr->timeModified < $last_time)
                && (!$currr || $currr->reviewSubmitted < $last_time))
                break;

            // otherwise, choose the later one first
            if (self::_activity_compar($curcr, $currr) < 0) {
                $curcr->isComment = true;
                $activity[] = $curcr;
                $last_time = $curcr->timeModified;
                $curcr = null;
            } else {
                $currr->isComment = false;
                $activity[] = $currr;
                $last_time = $currr->reviewSubmitted;
                $currr = null;
            }
        }

        return $activity;
    }


    //
    // Message routines
    //

    function msg($type, $text) {
        if (PHP_SAPI == "cli") {
            if ($type === "xmerror" || $type === "merror")
                fwrite(STDERR, "$text\n");
            else if ($type === "xwarning" || $type === "mxwarning"
                     || !defined("HOTCRP_TESTHARNESS"))
                fwrite(STDOUT, "$text\n");
        } else if ($this->save_messages) {
            ensure_session();
            $this->save_session_array("msgs", true, array($type, $text));
        } else if ($type[0] == "x")
            echo Ht::xmsg($text, $type);
        else
            echo "<div class=\"$type\">$text</div>";
    }

    function infoMsg($text, $minimal = false) {
        $this->msg($minimal ? "xinfo" : "info", $text);
    }

    static public function msg_info($text, $minimal = false) {
        self::$g->msg($minimal ? "xinfo" : "info", $text);
    }

    function warnMsg($text, $minimal = false) {
        $this->msg($minimal ? "xwarning" : "warning", $text);
    }

    static public function msg_warning($text, $minimal = false) {
        self::$g->msg($minimal ? "xwarning" : "warning", $text);
    }

    function confirmMsg($text, $minimal = false) {
        $this->msg($minimal ? "xconfirm" : "confirm", $text);
    }

    static public function msg_confirm($text, $minimal = false) {
        self::$g->msg($minimal ? "xconfirm" : "confirm", $text);
    }

    function errorMsg($text, $minimal = false) {
        $this->msg($minimal ? "xmerror" : "merror", $text);
        return false;
    }

    static public function msg_error($text, $minimal = false) {
        self::$g->msg($minimal ? "xmerror" : "merror", $text);
        return false;
    }

    function post_missing_msg() {
        $this->msg("merror", "Your uploaded data wasn’t received. This can happen on unusually slow connections, or if you tried to upload a file larger than I can accept.");
    }


    //
    // Conference header, footer
    //

    function make_css_link($url, $media = null) {
        global $ConfSitePATH, $Opt;
        $t = '<link rel="stylesheet" type="text/css" href="';
        if (str_starts_with($url, "stylesheets/")
            || !preg_match(',\A(?:https?:|/),i', $url))
            $t .= $Opt["assetsUrl"];
        $t .= $url;
        if (($mtime = @filemtime("$ConfSitePATH/$url")) !== false)
            $t .= "?mtime=$mtime";
        if ($media)
            $t .= '" media="' . $media;
        return $t . '" />';
    }

    function make_script_file($url, $no_strict = false) {
        global $ConfSitePATH, $Opt;
        if (str_starts_with($url, "scripts/")) {
            $post = "";
            if (($mtime = @filemtime("$ConfSitePATH/$url")) !== false)
                $post = "mtime=$mtime";
            if (get($Opt, "strictJavascript") && !$no_strict)
                $url = $Opt["scriptAssetsUrl"] . "cacheable.php?file=" . urlencode($url)
                    . "&strictjs=1" . ($post ? "&$post" : "");
            else
                $url = $Opt["scriptAssetsUrl"] . $url . ($post ? "?$post" : "");
            if ($Opt["scriptAssetsUrl"] === Navigation::siteurl())
                return Ht::script_file($url);
        }
        return Ht::script_file($url, array("crossorigin" => "anonymous"));
    }

    private function header_head($title) {
        global $Me, $ConfSitePATH, $Opt;
        // load session list and clear its cookie
        $list = SessionList::active();
        SessionList::set_requested(0);

        echo "<!DOCTYPE html>
<html>
<head>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
<meta http-equiv=\"Content-Language\" content=\"en\" />
<meta name=\"google\" content=\"notranslate\" />\n";

        if (isset($Opt["fontScript"]))
            echo $Opt["fontScript"];

        echo $this->make_css_link("stylesheets/style.css"), "\n";
        if (get($Opt, "mobileStylesheet")) {
            echo '<meta name="viewport" content="width=device-width, initial-scale=1">', "\n";
            echo $this->make_css_link("stylesheets/mobile.css", "screen and (max-width: 768px)"), "\n";
        }
        if (isset($Opt["stylesheets"]))
            foreach (mkarray($Opt["stylesheets"]) as $css)
                echo $this->make_css_link($css), "\n";

        // favicon
        if (($favicon = defval($Opt, "favicon", "images/review24.png"))) {
            if (strpos($favicon, "://") === false && $favicon[0] != "/") {
                if (get($Opt, "assetsUrl") && substr($favicon, 0, 7) === "images/")
                    $favicon = $Opt["assetsUrl"] . $favicon;
                else
                    $favicon = Navigation::siteurl() . $favicon;
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

        // title
        echo "<title>";
        if ($title) {
            $title = preg_replace("/<([^>\"']|'[^']*'|\"[^\"]*\")*>/", "", $title);
            $title = preg_replace(",(?: |&nbsp;|\302\240)+,", " ", $title);
            $title = str_replace("&#x2215;", "-", $title);
        }
        if ($title)
            echo $title, " - ";
        echo htmlspecialchars($Opt["shortName"]), "</title>\n</head>\n";

        // jQuery
        $stash = Ht::take_stash();
        if (isset($Opt["jqueryUrl"]))
            $jquery = $Opt["jqueryUrl"];
        else if (get($Opt, "jqueryCdn"))
            $jquery = "//code.jquery.com/jquery-1.12.0.min.js";
        else
            $jquery = "scripts/jquery-1.12.0.min.js";
        Ht::stash_html($this->make_script_file($jquery, true) . "\n");

        // Javascript settings to set before script.js
        Ht::stash_script("siteurl=" . json_encode(Navigation::siteurl()) . ";siteurl_suffix=\"" . Navigation::php_suffix() . "\"");
        if (session_id() !== "")
            Ht::stash_script("siteurl_postvalue=\"" . post_value() . "\"");
        if ($list)
            Ht::stash_script("hotcrp_list=" . json_encode(["num" => $list->listno, "id" => $list->listid]) . ";");
        if (($urldefaults = hoturl_defaults()))
            Ht::stash_script("siteurl_defaults=" . json_encode($urldefaults) . ";");
        $huser = (object) array();
        if ($Me && $Me->email)
            $huser->email = $Me->email;
        if ($Me && $Me->is_pclike())
            $huser->is_pclike = true;
        if ($Me && $Me->has_database_account())
            $huser->cid = $Me->contactId;
        Ht::stash_script("hotcrp_user=" . json_encode($huser) . ";");

        $pid = @$_REQUEST["paperId"];
        $pid = $pid && ctype_digit($pid) ? (int) $pid : 0;
        if (!$pid && $this->paper)
            $pid = $this->paper->paperId;
        if ($pid)
            Ht::stash_script("hotcrp_paperid=$pid");
        if ($pid && $Me && $Me->privChair
            && ($forceShow = @$_REQUEST["forceShow"]) && $forceShow != "0")
            Ht::stash_script("hotcrp_want_override_conflict=true");

        // script.js
        if (!@$Opt["noDefaultScript"])
            Ht::stash_html($this->make_script_file("scripts/script.js") . "\n");

        // other scripts
        if (@$Opt["scripts"])
            foreach ($Opt["scripts"] as $file)
                Ht::stash_html($this->make_script_file($file) . "\n");

        if ($stash)
            Ht::stash_html($stash);
    }

    static function echo_header($conf, $is_home, $site_div, $title_div,
                                $profile_html, $actions_html) {
        echo $site_div,
            '<div id="header_right">', $profile_html,
            '<div id="maindeadline" style="display:none"></div></div>',
            $title_div, $actions_html;
    }

    function header($title, $id, $actionBar, $title_div = null) {
        global $ConfSitePATH, $Me, $Now, $Opt;
        if ($this->headerPrinted)
            return;

        // <head>
        if ($title === "Home")
            $title = "";
        $this->header_head($title);

        // <body>
        $body_class = "";
        if ($id === "paper_view" || $id === "paper_edit"
            || $id === "review" || $id === "assign")
            $body_class = "paper";

        echo "<body";
        if ($id)
            echo ' id="', $id, '"';
        if ($body_class)
            echo ' class="', $body_class, '"';
        echo ">\n";

        // initial load (JS's timezone offsets are negative of PHP's)
        Ht::stash_script("hotcrp_load.time(" . (-date("Z", $Now) / 60) . "," . (@$Opt["time24hour"] ? 1 : 0) . ")");

        // deadlines settings
        if ($Me)
            Ht::stash_script("hotcrp_deadlines.init(" . json_encode($Me->my_deadlines($this->paper)) . ")");
        if (self::$gDefaultFormat)
            Ht::stash_script("render_text.set_default_format(" . self::$gDefaultFormat . ")");

        // meeting tracker
        $trackerowner = $Me && $Me->privChair
            && ($trackerstate = $this->setting_json("tracker"))
            && $trackerstate->sessionid == session_id();
        if ($trackerowner)
            Ht::stash_script("hotcrp_deadlines.tracker(0)");

        echo '<div id="prebody"><div id="header">';

        // $header_site
        $is_home = $id === "home";
        $site_div = '<div id="header_site" class="'
            . ($is_home ? "header_site_home" : "header_site_page")
            . '"><h1><a class="qq" href="' . hoturl("index") . '">'
            . htmlspecialchars($Opt["shortName"]);
        if (!$is_home)
            $site_div .= ' <span style="font-weight:normal">Home</span>';
        $site_div .= '</a></h1></div>';

        // $header_profile
        $profile_html = "";
        if ($Me && !$Me->is_empty()) {
            // profile link
            $xsep = ' <span class="barsep">·</span> ';
            if ($Me->has_email()) {
                $profile_html .= '<a class="q" href="' . hoturl("profile") . '"><strong>'
                    . htmlspecialchars($Me->email)
                    . '</strong></a> &nbsp; <a href="' . hoturl("profile") . '">Profile</a>'
                    . $xsep;
            }

            // "act as" link
            if (($actas = @$_SESSION["last_actas"]) && @$_SESSION["trueuser"]
                && ($Me->privChair || Contact::$trueuser_privChair === $Me)) {
                // Link becomes true user if not currently chair.
                if (!$Me->privChair || strcasecmp($Me->email, $actas) == 0)
                    $actas = $_SESSION["trueuser"]->email;
                if (strcasecmp($Me->email, $actas) != 0)
                    $profile_html .= "<a href=\"" . selfHref(array("actas" => $actas)) . "\">"
                        . ($Me->privChair ? htmlspecialchars($actas) : "Admin")
                        . "&nbsp;" . Ht::img("viewas.png", "Act as " . htmlspecialchars($actas))
                        . "</a>" . $xsep;
            }

            // help, sign out
            $x = ($id == "search" ? "t=$id" : ($id == "settings" ? "t=chair" : ""));
            $profile_html .= '<a href="' . hoturl("help", $x) . '">Help</a>';
            if (!$Me->has_email() && !isset($Opt["httpAuthLogin"]))
                $profile_html .= $xsep . '<a href="' . hoturl("index", "signin=1") . '">Sign&nbsp;in</a>';
            if (!$Me->is_empty() || isset($Opt["httpAuthLogin"]))
                $profile_html .= $xsep . '<a href="' . hoturl_post("index", "signout=1") . '">Sign&nbsp;out</a>';
        }

        if (!$title_div && $title)
            $title_div = '<div id="header_page"><h1>' . $title . '</h1></div>';
        if (!$title_div && $actionBar)
            $title_div = '<hr class="c" />';

        $renderf = @$Opt["headerRenderer"];
        if (!$renderf)
            $renderf = "Conf::echo_header";
        if (is_array($renderf)) {
            require_once($renderf[0]);
            $renderf = $renderf[1];
        }
        call_user_func($renderf, $this, $is_home, $site_div, $title_div, $profile_html, $actionBar);

        echo "  <hr class=\"c\" /></div>\n";

        echo "<div id=\"initialmsgs\">\n";
        if (@$Opt["maintenance"])
            echo "<div class=\"merror\"><strong>The site is down for maintenance.</strong> ", (is_string($Opt["maintenance"]) ? $Opt["maintenance"] : "Please check back later."), "</div>";
        $this->save_messages = false;
        if (($msgs = $this->session("msgs")) && count($msgs)) {
            $this->save_session("msgs", null);
            foreach ($msgs as $m)
                $this->msg($m[0], $m[1]);
        }
        echo "</div>\n";

        $this->headerPrinted = true;
        echo "</div>\n<div class='body'>\n";

        // If browser owns tracker, send it the script immediately
        if ($trackerowner)
            $this->echoScript();

        // Callback for version warnings
        if ($Me && $Me->privChair
            && (!isset($_SESSION["updatecheck"])
                || $_SESSION["updatecheck"] + 20 <= $Now)
            && (!isset($Opt["updatesSite"]) || $Opt["updatesSite"])) {
            $m = defval($Opt, "updatesSite", "//hotcrp.lcdf.org/updates");
            $m .= (strpos($m, "?") === false ? "?" : "&")
                . "addr=" . urlencode($_SERVER["SERVER_ADDR"])
                . "&base=" . urlencode(Navigation::siteurl())
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
            $_SESSION["updatecheck"] = $Now;
        }
    }

    function footer() {
        global $Opt, $Me, $ConfSitePATH;
        echo "</div>\n", // class='body'
            "<div id='footer'>\n  <div id='footer_crp'>",
            defval($Opt, "extraFooter", ""),
            "<a href='http://read.seas.harvard.edu/~kohler/hotcrp/'>HotCRP</a>";
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
        echo "</div>\n  <hr class=\"c\" /></div>\n";
        echo Ht::take_stash(), "</body>\n</html>\n";
    }

    function output_ajax($values = null, $div = false) {
        if ($values === false || $values === true)
            $values = array("ok" => $values);
        else if ($values === null)
            $values = array();
        else if (is_object($values))
            $values = get_object_vars($values);
        $t = "";
        if (session_id() !== ""
            && ($msgs = $this->session("msgs", array()))) {
            $this->save_session("msgs", null);
            foreach ($msgs as $msg) {
                if (($msg[0] === "merror" || $msg[0] === "xmerror")
                    && !isset($values["error"]))
                    $values["error"] = $msg[1];
                if ($div)
                    $t .= Ht::xmsg($msg[0], $msg[1]);
                else
                    $t .= "<span class=\"$msg[0]\">$msg[1]</span>";
            }
        }
        if ($t !== "")
            $values["response"] = $t . get_s($values, "response");
        if (isset($_REQUEST["jsontext"]) && $_REQUEST["jsontext"])
            header("Content-Type: text/plain");
        else
            header("Content-Type: application/json");
        if (check_post())
            header("Access-Control-Allow-Origin: *");
        echo json_encode($values);
    }

    function ajaxExit($values = null, $div = false) {
        $this->output_ajax($values, $div);
        exit;
    }


    //
    // Action recording
    //

    function save_logs($on) {
        if ($on && $this->_save_logs === false)
            $this->_save_logs = array();
        else if (!$on && $this->_save_logs !== false) {
            $x = $this->_save_logs;
            $this->_save_logs = false;
            foreach ($x as $cid_text => $pids) {
                $pos = strpos($cid_text, "|");
                $this->log(substr($cid_text, $pos + 1),
                           substr($cid_text, 0, $pos), $pids);
            }
        }
    }

    function log($text, $who, $pids = null) {
        if (!$who)
            $who = 0;
        else if (!is_numeric($who)) {
            if ($who->email && !$who->contactId)
                $text .= " <{$who->email}>";
            $who = $who->contactId;
        }

        if (is_object($pids))
            $pids = array($pids->paperId);
        else if (!is_array($pids))
            $pids = $pids > 0 ? array($pids) : array();
        $ps = array();
        foreach ($pids as $p)
            $ps[] = is_object($p) ? $p->paperId : $p;

        if ($this->_save_logs !== false) {
            foreach ($ps as $p)
                $this->_save_logs["$who|$text"][] = $p;
            return;
        }

        if (count($ps) == 0)
            $ps = null;
        else if (count($ps) == 1)
            $ps = $ps[0];
        else {
            $text .= " (papers " . join(", ", $ps) . ")";
            $ps = null;
        }
        $result = Dbl::q("insert into ActionLog set ipaddr=?, contactId=?, paperId=?, action=?", @$_SERVER["REMOTE_ADDR"], (int) $who, $ps, substr($text, 0, 4096));
        Dbl::free($result);
    }


    //
    // Miscellaneous
    //

    public function capability_manager($for = null) {
        global $Opt;
        if ($for && substr($for, 0, 1) === "U") {
            if (($cdb = Contact::contactdb()))
                return new CapabilityManager($cdb, "U");
            else
                return null;
        } else
            return new CapabilityManager($this->dblink, "");
    }


    public function message_name($name) {
        if (str_starts_with($name, "msg."))
            $name = substr($name, 4);
        if ($name === "revprefdescription" && $this->has_topics())
            $name .= ".withtopics";
        else if (str_starts_with($name, "resp_instrux") && $this->setting("resp_words", 500) > 0)
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
                $html = str_ireplace("%$k%", $v, $html);
        return $html;
    }

    public function message_default_html($name) {
        return Message::default_html($this->message_name($name));
    }
}


function setting($name, $defval = false) {
    return Conf::$g->setting($name, $defval);
}

function setting_data($name, $defval = false) {
    return Conf::$g->setting_data($name, $defval);
}

function setting_json($name, $defval = false) {
    return Conf::$g->setting_json($name, $defval);
}
