<?php
// conference.php -- HotCRP central helper class (singleton)
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Conf {
    /** @var ?mysqli
     * @readonly */
    public $dblink;
    /** @var string
     * @readonly */
    public $dbname;
    /** @var ?string
     * @readonly */
    public $session_key;

    /** @var array<string,int> */
    public $settings;
    /** @var array<string,?string> */
    private $settingTexts;
    /** @var int */
    public $sversion;
    /** @var array<string,mixed> */
    public $opt;
    /** @var array<string,mixed> */
    public $opt_override;
    /** @var string */
    public $lang = "en";
    /** @var ?int */
    private $_opt_timestamp;

    /** @var string
     * @readonly */
    public $short_name;
    /** @var string
     * @readonly */
    public $long_name;
    /** @var int
     * @readonly */
    public $default_format;
    /** @var string */
    public $download_prefix;
    /** @var int */
    private $permbits;
    const PB_ALL_PDF_VIEWABLE = 1;
    const PB_SOME_INCOMPLETE_VIEWABLE = 2;
    const PB_ALL_INCOMPLETE_VIEWABLE = 4;
    /** @var bool
     * @readonly */
    public $rev_open;
    /** @var ?SearchTerm
     * @readonly */
    public $_au_seerev;
    /** @var ?SearchTerm
     * @readonly */
    public $_au_seedec;
    /** @var bool
     * @readonly */
    public $disable_non_pc;
    /** @var bool
     * @readonly */
    public $tag_seeall;
    /** @var int
     * @readonly */
    public $ext_subreviews;
    /** @var int
     * @readonly */
    public $any_response_open;
    /** @var bool */
    public $sort_by_last;
    /** @var array{string,string,string,string} */
    public $snouns;
    /** @var ?string */
    private $_site_locks;
    /** @var PaperOptionList */
    private $_paper_opts;

    /** @var bool */
    public $_header_printed = false;
    /** @var ?list<array{string,int}> */
    private $_save_msgs;
    /** @var bool */
    private $_mx_auto = false;
    /** @var int */
    private $_save_logs_depth = 0;
    /** @var ?array<string,list<int>> */
    private $_save_logs;
    /** @var string */
    private $_assets_url;
    /** @var ?string */
    private $_script_assets_url;
    /** @var bool */
    private $_script_assets_site;

    /** @var ?Collator */
    private $_collator;
    /** @var ?Collator */
    private $_pcollator;
    /** @var ?SubmissionRound */
    private $_main_sub_round;
    /** @var ?list<SubmissionRound> */
    private $_sub_rounds;
    /** @var list<string> */
    private $rounds;
    /** @var ?array<int,string> */
    private $_defined_rounds;
    private $_round_settings;
    /** @var ?list<ResponseRound> */
    private $_resp_rounds;
    /** @var ?list<Track> */
    private $_tracks;
    /** @var ?list<string> */
    private $_track_tags;
    /** @var int */
    private $_track_sensitivity = 0;
    /** @var ?TagMap */
    private $_tag_map;
    /** @var bool */
    private $_maybe_automatic_tags;
    /** @var ?DecisionSet */
    private $_decision_set;
    /** @var DecisionInfo
     * @readonly */
    public $unspecified_decision;
    /** @var bool
     * @readonly */
    public $has_complex_decision;
    /** @var ?TopicSet */
    private $_topic_set;
    /** @var ?Conflict */
    private $_conflict_set;
    /** @var ?array<int,?Contact> */
    private $_user_cache;
    /** @var ?list<int|string> */
    private $_user_cache_missing;
    /** @var ?array<string,Contact> */
    private $_user_email_cache;
    /** @var int */
    private $_slice = Contact::SLICE_MINIMAL;
    /** @var ?ContactSet */
    private $_pc_set;
    /** @var ?array<int,Contact> */
    private $_pc_members_cache;
    /** @var ?array<string,string> */
    private $_pc_tags_cache;
    /** @var bool */
    private $_pc_members_all_enabled = true;
    /** @var ?array<int|string,Contact> */
    private $_cdb_user_cache;
    /** @var ?list<int|string> */
    private $_cdb_user_cache_missing;
    /** @var ?Contact */
    private $_root_user;
    /** @var ?Author */
    private $_site_contact;
    /** @var ?ReviewForm */
    private $_review_form;
    /** @var ?AbbreviationMatcher<PaperOption|ReviewField|Formula> */
    private $_abbrev_matcher;
    /** @var bool */
    private $_date_format_initialized = false;
    /** @var ?DateTimeZone */
    private $_dtz;
    /** @var array<int,FormatSpec> */
    private $_formatspec_cache = [];
    /** @var ?non-empty-string */
    private $_docstore;
    /** @var array<int,Formula> */
    private $_defined_formulas;
    private $_emoji_codes;
    /** @var S3Client|null|false */
    private $_s3_client = false;
    /** @var ?Fmt */
    private $_fmt;
    /** @var ?list<string> */
    private $_fmt_override_names;
    /** @var ?array<int,TextFormat> */
    private $_format_info;
    /** @var bool */
    private $_updating_automatic_tags = false;

    /** @var ?array<string,list<object>> */
    private $_xtbuild_map;
    /** @var ?list<object> */
    private $_xtbuild_factories;

    /** @var ?array<string,list<object>> */
    private $_formula_functions;
    /** @var ?array<string,list<object>> */
    private $_search_keyword_base;
    /** @var ?list<object> */
    private $_search_keyword_factories;
    /** @var ?array<string,list<object>> */
    private $_assignment_parsers;
    /** @var ?array<string,list<object>> */
    private $_api_map;
    /** @var ?array<string,list<object>> */
    private $_paper_column_map;
    /** @var ?list<object> */
    private $_paper_column_factories;
    /** @var ?array<string,object> */
    private $_option_type_map;
    /** @var ?array<string,object> */
    private $_rfield_type_map;
    /** @var ?list<object> */
    private $_token_factories;
    /** @var ?array<int,object> */
    private $_token_types;
    /** @var ?array<string,object> */
    private $_oauth_providers;
    private $_hook_map;
    private $_hook_factories;
    /** @var ?array<string,FileFilter> */
    public $_file_filters; // maintained externally
    /** @var ?SettingInfoSet */
    public $_setting_info; // maintained externally
    /** @var ?array<string,list<object>> */
    private $_mail_keyword_map;
    /** @var ?list<object> */
    private $_mail_keyword_factories;
    /** @var ?array<string,list<object>> */
    private $_mail_template_map;
    /** @var ?array<string,object> */
    private $_autoassigners;
    /** @var DKIMSigner|null|false */
    private $_dkim_signer = false;
    /** @var ?ComponentSet */
    private $_page_components;

    /** @var ?PaperInfo */
    public $paper; // current paper row

    /** @var Conf */
    static public $main;
    /** @var int */
    static public $now;
    /** @var int|float */
    static public $unow;
    /** @var float */
    static public $blocked_time = 0.0;
    /** @var false|null|\mysqli */
    static private $_cdb = false;

    /** @var bool */
    static public $test_mode;
    /** @var bool */
    static public $no_invalidate_caches = false;
    /** @var int */
    static public $next_xt_source_order = 0;
    static private $xt_require_resolved = [];

    const BLIND_NEVER = 0;          // these values are used in `msgs.json`
    const BLIND_OPTIONAL = 1;
    const BLIND_ALWAYS = 2;
    const BLIND_UNTILREVIEW = 3;

    const SEEDEC_REV = 1;
    const SEEDEC_NCREV = 3;

    const AUSEEREV_NO = 0;          // these values matter
    const AUSEEREV_YES = 2;
    const AUSEEREV_SEARCH = 3;

    const VIEWREV_NEVER = -1;
    const VIEWREV_AFTERREVIEW = 0;
    const VIEWREV_ALWAYS = 1;
    const VIEWREV_UNLESSINCOMPLETE = 3;
    const VIEWREV_UNLESSANYINCOMPLETE = 4;
    const VIEWREV_IFASSIGNED = 5;

    static public $review_deadlines = ["pcrev_soft", "pcrev_hard", "extrev_soft", "extrev_hard"];

    /** @param ?array<string,mixed> $options
     * @param bool $connect */
    function __construct($options, $connect) {
        global $Opt;
        $this->opt = $options ?? $Opt;
        // unpack dsn, connect to database, load current settings
        if (($cp = Dbl::parse_connection_params($this->opt))) {
            $this->dblink = $connect ? $cp->connect() : null;
            $this->dbname = $cp->name;
            $this->session_key = "@{$this->dbname}";
        }
        $this->opt["confid"] = $this->opt["confid"] ?? $this->dbname;
        $this->_paper_opts = new PaperOptionList($this);
        $this->unspecified_decision = new DecisionInfo(0, "Unspecified");
        if ($this->dblink && !Dbl::$default_dblink) {
            Dbl::set_default_dblink($this->dblink);
            Dbl::set_error_handler([$this, "query_error_handler"]);
        }
        if ($this->dblink) {
            Dbl::$landmark_sanitizer = "/\A(?:Dbl::|Conf::q|Conf::fetch|call_user_func)/";
            $this->load_settings();
        } else {
            $this->refresh_options();
        }
    }

    /** @param int|float $t */
    static function set_current_time($t) {
        global $Now;
        self::$unow = $t;
        $Now = Conf::$now = (int) $t;
        if (Conf::$main) {
            Conf::$main->refresh_time_settings();
        }
    }

    /** @param int $advance_past */
    static function advance_current_time($advance_past) {
        if ($advance_past + 1 > Conf::$now) {
            self::set_current_time($advance_past + 1);
        }
    }


    //
    // Initialization functions
    //

    function __load_settings() {
        // load settings from database
        $this->settings = [];
        $this->settingTexts = [];
        foreach ($this->opt_override ?? [] as $k => $v) {
            if ($v === null) {
                unset($this->opt[$k]);
            } else {
                $this->opt[$k] = $v;
            }
        }
        $this->opt_override = [];
        if ($this->_fmt) {
            $this->_fmt->remove_overrides();
        }
        $this->_fmt_override_names = null;

        $result = $this->q_raw("select name, value, data from Settings");
        while (($row = $result->fetch_row())) {
            $this->settings[$row[0]] = (int) $row[1];
            if ($row[2] !== null) {
                $this->settingTexts[$row[0]] = $row[2];
            }
            if (str_starts_with($row[0], "opt.")) {
                $okey = substr($row[0], 4);
                $this->opt_override[$okey] = $this->opt[$okey] ?? null;
                $this->opt[$okey] = ($row[2] === null ? (int) $row[1] : $row[2]);
            } else if (str_starts_with($row[0], "msg.")) {
                $this->_fmt_override_names = $this->_fmt_override_names ?? [];
                $this->_fmt_override_names[] = $row[0];
            }
        }
        Dbl::free($result);

        $this->sversion = $this->settings["allowPaperOption"] ?? 0;
    }

    function load_settings() {
        $this->__load_settings();
        if ($this->sversion < 291) {
            $old_nerrors = Dbl::$nerrors;
            while ((new UpdateSchema($this))->run()) {
                usleep(50000);
            }
            Dbl::$nerrors = $old_nerrors;
        }
        if ($this->sversion < 200) {
            $this->error_msg("<0>Warning: The database could not be upgraded to the current version; expect errors. A system administrator must solve this problem.");
        }

        // refresh after loading from backup
        if (isset($this->settings["frombackup"])) {
            // in current code, refresh_settings() suffices
            $this->qe_raw("delete from Settings where name='frombackup' and value=" . $this->settings["frombackup"]);
            unset($this->settings["frombackup"]);
        }

        $this->refresh_settings();
        $this->refresh_options();

        // GC old capabilities
        if (($this->settings["__capability_gc"] ?? 0) < Conf::$now - 86400) {
            $this->clean_tokens();
        }

        // might need to redo automatic tags
        if ($this->settings["__recompute_automatic_tags"] ?? 0) {
            $this->qe("delete from Settings where name='__recompute_automatic_tags' and value=?", $this->settings["__recompute_automatic_tags"]);
            unset($this->settings["__recompute_automatic_tags"], $this->settingTexts["__recompute_automatic_tags"]);
            $this->update_automatic_tags();
        }
    }

    /** @suppress PhanAccessReadOnlyProperty */
    function refresh_settings() {
        // enforce invariants
        $this->settings["pcrev_any"] = $this->settings["pcrev_any"] ?? 0;
        $this->settings["sub_blind"] = $this->settings["sub_blind"] ?? self::BLIND_ALWAYS;
        $this->settings["rev_blind"] = $this->settings["rev_blind"] ?? self::BLIND_ALWAYS;

        // rounds
        $this->refresh_round_settings();

        // tracks settings
        $this->_tracks = $this->_track_tags = null;
        $this->_track_sensitivity = 0;
        if (($j = $this->settingTexts["tracks"] ?? null)) {
            $this->refresh_track_settings($j);
        }

        // clear caches
        $this->_paper_opts->invalidate_options();
        $this->_review_form = null;
        $this->_main_sub_round = null;
        $this->_sub_rounds = null;
        $this->_defined_rounds = null;
        $this->_resp_rounds = null;
        $this->_formatspec_cache = [];
        $this->_abbrev_matcher = null;
        $this->_tag_map = null;
        $this->_formula_functions = null;
        $this->_assignment_parsers = null;
        $this->_decision_set = null;
        /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
        $this->has_complex_decision = strpos($this->settingTexts["outcome_map"] ?? "{", "{", 1) !== false;
        $this->_topic_set = null;

        // digested settings
        $au_seerev = $this->settings["au_seerev"] ?? 0;
        $this->_au_seerev = null;
        if ($au_seerev === self::AUSEEREV_SEARCH) {
            if (($q = $this->settingTexts["au_seerev"] ?? null) !== null) {
                $srch = new PaperSearch($this->root_user(), $q);
                $this->_au_seerev = $srch->full_term();
            } else if (($tags = $this->settingTexts["tag_au_seerev"] ?? "") !== "") {
                $tsm = new TagSearchMatcher($this->root_user());
                foreach (explode(" ", $tags) as $t) {
                    if ($t !== "")
                        $tsm->add_tag($t);
                }
                $this->_au_seerev = new Tag_SearchTerm($tsm);
            }
        } else if ($au_seerev > 0) {
            $this->_au_seerev = new True_SearchTerm;
        }
        if ($this->_au_seerev instanceof False_SearchTerm) {
            $this->_au_seerev = null;
        }
        $au_seedec = $this->settings["au_seedec"] ?? 0;
        $this->_au_seedec = null;
        if ($au_seedec === 1 && ($q = $this->settingTexts["au_seedec"] ?? null) !== null) {
            $srch = new PaperSearch($this->root_user(), $q);
            $this->_au_seedec = $srch->full_term();
        } else if ($au_seedec > 0) {
            $this->_au_seedec = new True_SearchTerm;
        }
        if ($this->_au_seedec instanceof False_SearchTerm) {
            $this->_au_seedec = null;
        }
        $this->tag_seeall = ($this->settings["tag_seeall"] ?? 0) > 0;
        $this->ext_subreviews = $this->settings["pcrev_editdelegate"] ?? 0;
        $this->_maybe_automatic_tags = ($this->settings["tag_vote"] ?? 0) > 0
            || ($this->settings["tag_approval"] ?? 0) > 0
            || ($this->settings["tag_autosearch"] ?? 0) > 0
            || !!$this->opt("definedTags");
        $this->_site_locks = $this->settingTexts["site_locks"] ?? null;
        $this->refresh_time_settings();
    }

    /** @suppress PhanAccessReadOnlyProperty */
    private function refresh_time_settings() {
        $this->permbits = self::PB_ALL_PDF_VIEWABLE | self::PB_ALL_INCOMPLETE_VIEWABLE;
        foreach ($this->submission_round_list() as $sr) {
            if (!$sr->pdf_viewable) {
                $this->permbits &= ~self::PB_ALL_PDF_VIEWABLE;
            }
            if ($sr->incomplete_viewable) {
                $this->permbits |= self::PB_SOME_INCOMPLETE_VIEWABLE;
            } else {
                $this->permbits &= ~self::PB_ALL_INCOMPLETE_VIEWABLE;
            }
        }

        $rot = $this->settings["rev_open"] ?? 0;
        $this->rev_open = $rot > 0 && $rot <= Conf::$now;

        $this->any_response_open = 0;
        if (($this->settings["resp_active"] ?? 0) > 0) {
            foreach ($this->response_rounds() as $rrd) {
                if ($rrd->time_allowed(true)) {
                    if ($rrd->condition !== null) {
                        $this->any_response_open = 1;
                    } else {
                        $this->any_response_open = 2;
                        break;
                    }
                }
            }
        }
    }

    /** @param int $sr1
     * @param int $sr2
     * @return -1|0|1 */
    static function viewrev_compare($sr1, $sr2) {
        if ($sr1 == $sr2) {
            return 0;
        } else if ($sr1 == self::VIEWREV_ALWAYS || $sr2 == self::VIEWREV_ALWAYS) {
            return $sr1 == self::VIEWREV_ALWAYS ? 1 : -1;
        } else {
            return $sr1 > $sr2 ? 1 : -1;
        }
    }

    private function refresh_round_settings() {
        $this->rounds = [""];
        if (isset($this->settingTexts["tag_rounds"])) {
            foreach (explode(" ", $this->settingTexts["tag_rounds"]) as $r) {
                if ($r !== "")
                    $this->rounds[] = $r;
            }
        }
        $this->_round_settings = null;
        if (isset($this->settingTexts["round_settings"])) {
            $this->_round_settings = json_decode($this->settingTexts["round_settings"]);
            $max_rs = [];
            foreach ($this->_round_settings as $rs) {
                if (!$rs) {
                    continue;
                }
                foreach (["viewrev", "viewrev_ext", "viewrevid", "viewrevid_ext"] as $k) {
                    if (isset($rs->$k)
                        && self::viewrev_compare($rs->$k, $max_rs[$k] ?? -1) > 0) {
                        $max_rs[$k] = $rs->$k;
                    }
                }
            }
            $this->_round_settings["max"] = (object) $max_rs;
        }

        // review times
        foreach ($this->rounds as $i => $rname) {
            $suf = $i ? "_{$i}" : "";
            if (!isset($this->settings["extrev_soft{$suf}"])
                && isset($this->settings["pcrev_soft{$suf}"])) {
                $this->settings["extrev_soft{$suf}"] = $this->settings["pcrev_soft{$suf}"];
            }
            if (!isset($this->settings["extrev_hard{$suf}"])
                && isset($this->settings["pcrev_hard{$suf}"])) {
                $this->settings["extrev_hard{$suf}"] = $this->settings["pcrev_hard{$suf}"];
            }
        }
    }

    private function refresh_track_settings($j) {
        if (is_string($j) && !($j = json_decode($j))) {
            return;
        }
        $this->_tracks = [];
        $this->_track_tags = [];
        $trest = new Track("");
        foreach ((array) $j as $tag => $v) {
            if ($tag === "" || $tag === "_") {
                $tr = $trest;
            } else if (!($tr = $this->track($tag))) {
                $this->_tracks[] = $tr = new Track($tag);
                $this->_track_tags[] = $tag;
            }
            if (!isset($v->viewpdf) && isset($v->view)) {
                $v->viewpdf = $v->view;
            }
            foreach (Track::$perm_name_map as $tname => $idx) {
                if (isset($v->$tname)) {
                    $tr->perm[$idx] = $v->$tname;
                    $this->_track_sensitivity |= 1 << $idx;
                }
            }
        }
        $this->_tracks[] = $trest;
    }

    /** @suppress PhanAccessReadOnlyProperty */
    function refresh_options() {
        // set longName, downloadPrefix, etc.
        $confid = $this->opt["confid"];
        if (($this->opt["longName"] ?? "") === "") {
            if (($this->opt["shortName"] ?? "") === "") {
                $this->opt["shortNameDefaulted"] = true;
                $this->opt["shortName"] = $confid;
            }
            $this->opt["longName"] = $this->opt["shortName"];
        } else if (($this->opt["shortName"] ?? "") === "") {
            $this->opt["shortName"] = $this->opt["longName"];
        }
        if (($this->opt["downloadPrefix"] ?? "") === "") {
            $this->opt["downloadPrefix"] = $confid . "-";
        }
        $this->short_name = $this->opt["shortName"];
        $this->long_name = $this->opt["longName"];
        $this->lang = $this->opt["lang"] ?? "en";

        // set submission nouns
        if (isset($this->opt["submissionNouns"])
            && is_string_list($this->opt["submissionNouns"])
            && count($this->opt["submissionNouns"]) === 4) {
            $this->snouns = $this->opt["submissionNouns"];
        } else {
            $this->snouns = ["submission", "submissions", "Submission", "Submissions"];
        }

        // expand ${confid}, ${confshortname}
        foreach (["sessionName", "downloadPrefix", "conferenceSite",
                  "paperSite", "defaultPaperSite", "contactName",
                  "contactEmail", "docstore"] as $k) {
            if (isset($this->opt[$k])
                && is_string($this->opt[$k])
                && strpos($this->opt[$k], "\$") !== false) {
                $this->opt[$k] = preg_replace('/\$\{confid\}|\$confid\b/', $confid, $this->opt[$k]);
                $this->opt[$k] = preg_replace('/\$\{confshortname\}|\$confshortname\b/', $this->short_name, $this->opt[$k]);
            }
        }
        $this->download_prefix = $this->opt["downloadPrefix"];

        foreach (["emailFrom", "emailSender", "emailCc", "emailReplyTo"] as $k) {
            if (is_string(($s = $this->opt[$k] ?? null))
                && strpos($s, "\$") !== false) {
                $this->opt[$k] = preg_replace('/\$\{confid\}|\$confid\b/', $confid, $s);
                if (strpos($this->opt[$k], "confshortname") !== false) {
                    $v = rfc2822_words_quote($this->short_name);
                    if ($v[0] === "\"" && strpos($this->opt[$k], "\"") !== false) {
                        $v = substr($v, 1, strlen($v) - 2);
                    }
                    $this->opt[$k] = preg_replace('/\$\{confshortname\}|\$confshortname\b/', $v, $this->opt[$k]);
                }
            }
        }

        // remove final slash from $Opt["paperSite"]
        $nav = Navigation::get();
        if (!isset($this->opt["paperSite"]) || $this->opt["paperSite"] === "") {
            $this->opt["paperSite"] = $nav->base_absolute();
        }
        if ($this->opt["paperSite"] == "" && isset($this->opt["defaultPaperSite"])) {
            $this->opt["paperSite"] = $this->opt["defaultPaperSite"];
        }
        while (str_ends_with($this->opt["paperSite"], "/")) {
            $this->opt["paperSite"] = substr($this->opt["paperSite"], 0, -1);
        }

        // assert URLs (general assets, scripts, jQuery)
        $baseurl = $nav->base_path_relative ?? "";
        $this->_assets_url = $this->opt["assetsUrl"] ?? $this->opt["assetsURL"] ?? $baseurl;
        if ($this->_assets_url !== "" && !str_ends_with($this->_assets_url, "/")) {
            $this->_assets_url .= "/";
        }
        $this->_script_assets_url = $this->opt["scriptAssetsUrl"]
            ?? (strpos($_SERVER["HTTP_USER_AGENT"] ?? "", "MSIE") === false ? $this->_assets_url : $baseurl);
        $this->_script_assets_site = $this->_script_assets_url === $baseurl;

        // check passwordHashMethod
        if (isset($this->opt["passwordHashMethod"])
            && !in_array($this->opt["passwordHashMethod"], password_algos())) {
            unset($this->opt["passwordHashMethod"]);
        }

        // docstore
        $docstore = $this->opt["docstore"] ?? null;
        $dpath = "";
        $dpsubdir = $this->opt["docstoreSubdir"] ?? null;
        if (is_string($docstore)) {
            $dpath = $docstore;
        } else if ($docstore === true) {
            $dpath = "docs";
        } else if ($docstore === null && isset($this->opt["filestore"])) {
            if (is_string($this->opt["filestore"])) {
                $dpath = $this->opt["filestore"];
            } else if ($this->opt["filestore"] === true) {
                $dpath = "filestore";
            }
            $dpsubdir = $this->opt["filestoreSubdir"] ?? null;
        }
        if ($dpath !== "") {
            if ($dpath[0] !== "/") {
                $dpath = SiteLoader::$root . "/" . $dpath;
            }
            if (strpos($dpath, "%") === false) {
                $dpath .= ($dpath[strlen($dpath) - 1] === "/" ? "" : "/");
                if ($dpsubdir && ($dpsubdir === true || $dpsubdir > 0)) {
                    $dpath .= "%" . ($dpsubdir === true ? 2 : $dpsubdir) . "h/";
                }
                $dpath .= "%h%x";
            }
            $this->_docstore = $dpath;
        } else {
            $this->_docstore = null;
        }

        // S3 settings and dbNoPapers
        if (($this->opt["dbNoPapers"] ?? null)
            && !$this->_docstore
            && !($this->opt["s3_bucket"] ?? null)) {
            unset($this->opt["dbNoPapers"]);
        }
        if ($this->_s3_client !== false) {
            $this->_s3_client = $this->_refresh_s3_client();
        }

        // defaultFormat
        $this->default_format = (int) ($this->opt["defaultFormat"] ?? 0);
        $this->_format_info = null;

        // defaultScoreSort should be long
        if (isset($this->opt["defaultScoreSort"])
            && strlen($this->opt["defaultScoreSort"]) === 1) {
            $this->opt["defaultScoreSort"] = ScoreInfo::parse_score_sort($this->opt["defaultScoreSort"]);
        }

        // emails
        if (($eol = $this->opt["postfixMailer"] ?? $this->opt["postfixEOL"] ?? false)) {
            $this->opt["postfixEOL"] = is_string($eol) ? $eol : PHP_EOL;
        }
        $this->_dkim_signer = false;

        // other caches
        $sort_by_last = !!($this->opt["sortByLastName"] ?? false);
        if (!$this->sort_by_last != !$sort_by_last) {
            $this->invalidate_caches(["pc" => true]);
        }
        $this->sort_by_last = $sort_by_last;
        $this->disable_non_pc = !!$this->opt("disableNonPC");

        $this->_api_map = null;
        $this->_file_filters = null;
        $this->_site_contact = null;
        $this->_date_format_initialized = false;
        $this->_dtz = null;

        if ($this === Conf::$main) {
            $this->refresh_globals();
        }
    }

    function refresh_globals() {
        Ht::$img_base = $this->_assets_url . "images/";

        if (isset($this->opt["timezone"])) {
            if (!date_default_timezone_set($this->opt["timezone"])) {
                $this->error_msg("<0>Timezone option ‘" . $this->opt["timezone"] . "’ is invalid; falling back to ‘America/New_York’");
                date_default_timezone_set("America/New_York");
            }
        } else if (!ini_get("date.timezone") && !getenv("TZ")) {
            date_default_timezone_set("America/New_York");
        }
    }

    /** @return Conf */
    static function set_main_instance(Conf $conf) {
        global $Conf;
        $Conf = Conf::$main = $conf;
        $conf->refresh_globals();
        return $conf;
    }


    /** @return bool */
    function has_setting($name) {
        return isset($this->settings[$name]);
    }

    /** @param string $name
     * @return ?int */
    function setting($name) {
        return $this->settings[$name] ?? null;
    }

    /** @param string $name
     * @return ?string */
    function setting_data($name) {
        return $this->settingTexts[$name] ?? null;
    }

    /** @param string $name
     * @return mixed */
    function setting_json($name) {
        $x = $this->settingTexts[$name] ?? null;
        return $x !== null ? json_decode($x) : null;
    }

    /** @param string $name
     * @param ?int $value
     * @param null|string|array|object $data
     * @return bool */
    function change_setting($name, $value, $data = null) {
        if ($value === null && $data === null) {
            if (($change = isset($this->settings[$name]))) {
                unset($this->settings[$name], $this->settingTexts[$name]);
            }
        } else {
            $value = (int) $value;
            $dval = is_array($data) || is_object($data) ? json_encode_db($data) : $data;
            if (($change = ($this->settings[$name] ?? null) !== $value
                           || ($this->settingTexts[$name] ?? null) !== $dval)) {
                $this->settings[$name] = $value;
                $this->settingTexts[$name] = $dval;
            }
        }
        if ($change && str_starts_with($name, "opt.")) {
            $oname = substr($name, 4);
            if (!array_key_exists($oname, $this->opt_override)) {
                $this->opt_override[$oname] = $this->opt[$oname] ?? null;
            }
            if ($value === null && $data === null) {
                $this->opt[$oname] = $this->opt_override[$oname] ?? null;
            } else {
                $this->opt[$oname] = $data === null ? $value : $data;
            }
        }
        return $change;
    }

    /** @param string $name
     * @param ?int $value
     * @param null|string|array|object $data
     * @return bool */
    function save_setting($name, $value, $data = null) {
        if ($value === null && $data === null) {
            $result = $this->qe("delete from Settings where name=?", $name);
            $change = $result->affected_rows !== 0;
        } else {
            $value = (int) $value;
            $dval = is_array($data) || is_object($data) ? json_encode_db($data) : $data;
            $result = $this->qe("insert into Settings (name, value, data) values (?, ?, ?) ?U on duplicate key update value=?U(value), data=?U(data)", $name, $value, $dval);
            $change = $result->affected_rows !== 0;
        }
        // return if changed EITHER in database OR in this instance
        return $this->change_setting($name, $value, $data) || $change;
    }

    /** @param string $name
     * @param ?int $value
     * @return bool */
    function save_refresh_setting($name, $value, $data = null) {
        $change = $this->save_setting($name, $value, $data);
        if ($change) {
            $this->refresh_settings();
            if (str_starts_with($name, "opt.")) {
                $this->refresh_options();
            }
        }
        return $change;
    }


    /** @param string $name
     * @return mixed */
    function opt($name) {
        return $this->opt[$name] ?? null;
    }

    /** @param string $name
     * @param mixed $value */
    function set_opt($name, $value) {
        global $Opt;
        $Opt[$name] = $this->opt[$name] = $value;
    }

    /** @return int */
    function opt_timestamp() {
        if ($this->_opt_timestamp === null) {
            $this->_opt_timestamp = 1;
            foreach ($this->opt["loaded"] ?? [] as $fn) {
                $this->_opt_timestamp = max($this->_opt_timestamp, +@filemtime($fn));
            }
        }
        return $this->_opt_timestamp;
    }


    /** @param non-empty-string $name
     * @return int */
    function site_lock($name) {
        if ($this->_site_locks === null
            || ($p = strpos($this->_site_locks, " {$name}#")) === false) {
            return 0;
        } else {
            return (int) substr($this->_site_locks, $p + strlen($name) + 2);
        }
    }


    // database

    /** @return Dbl_Result */
    function q(...$args /* $qstr, ... */) {
        return Dbl::do_query_on($this->dblink, $args, 0);
    }
    /** @return Dbl_Result */
    function q_raw(...$args /* $qstr */) {
        return Dbl::do_query_on($this->dblink, $args, Dbl::F_RAW);
    }
    /** @return Dbl_Result */
    function q_apply(...$args /* $qstr, $argv */) {
        return Dbl::do_query_on($this->dblink, $args, Dbl::F_APPLY);
    }

    /** @return Dbl_Result */
    function ql(...$args /* $qstr, ... */) {
        return Dbl::do_query_on($this->dblink, $args, Dbl::F_LOG);
    }
    /** @return Dbl_Result */
    function ql_raw(...$args /* $qstr */) {
        return Dbl::do_query_on($this->dblink, $args, Dbl::F_RAW | Dbl::F_LOG);
    }
    /** @return Dbl_Result */
    function ql_apply(...$args /* $qstr, $args */) {
        return Dbl::do_query_on($this->dblink, $args, Dbl::F_APPLY | Dbl::F_LOG);
    }
    /** @return ?Dbl_Result */
    function ql_ok(...$args /* $qstr, ... */) {
        $result = Dbl::do_query_on($this->dblink, $args, Dbl::F_LOG);
        return Dbl::is_error($result) ? null : $result;
    }

    /** @return Dbl_Result */
    function qe(...$args /* $qstr, ... */) {
        return Dbl::do_query_on($this->dblink, $args, Dbl::F_ERROR);
    }
    /** @return Dbl_Result */
    function qe_raw(...$args /* $qstr */) {
        return Dbl::do_query_on($this->dblink, $args, Dbl::F_RAW | Dbl::F_ERROR);
    }
    /** @return Dbl_Result */
    function qe_apply(...$args /* $qstr, $args */) {
        return Dbl::do_query_on($this->dblink, $args, Dbl::F_APPLY | Dbl::F_ERROR);
    }

    /** @return list<list<?string>> */
    function fetch_rows(...$args /* $qstr, ... */) {
        return Dbl::fetch_rows(Dbl::do_query_on($this->dblink, $args, Dbl::F_ERROR));
    }
    /** @return ?list<?string> */
    function fetch_first_row(...$args /* $qstr, ... */) {
        return Dbl::fetch_first_row(Dbl::do_query_on($this->dblink, $args, Dbl::F_ERROR));
    }
    /** @return ?object */
    function fetch_first_object(...$args /* $qstr, ... */) {
        return Dbl::fetch_first_object(Dbl::do_query_on($this->dblink, $args, Dbl::F_ERROR));
    }
    /** @return ?string */
    function fetch_value(...$args /* $qstr, ... */) {
        return Dbl::fetch_value(Dbl::do_query_on($this->dblink, $args, Dbl::F_ERROR));
    }
    /** @return ?int */
    function fetch_ivalue(...$args /* $qstr, ... */) {
        return Dbl::fetch_ivalue(Dbl::do_query_on($this->dblink, $args, Dbl::F_ERROR));
    }

    function db_error_html($getdb = true) {
        $text = "<p>Database error";
        if ($getdb) {
            $text .= ": " . htmlspecialchars($this->dblink->error);
        }
        return $text . "</p>";
    }

    function db_error_text($getdb = true) {
        $text = "Database error";
        if ($getdb) {
            $text .= ": " . $this->dblink->error;
        }
        return $text;
    }

    function query_error_handler($dblink, $query) {
        $landmark = caller_landmark(1, "/^(?:Dbl::|Conf::q|call_user_func)/");
        if (PHP_SAPI === "cli") {
            fwrite(STDERR, "{$landmark}: database error: {$dblink->error} in {$query}\n" . debug_string_backtrace());
        } else {
            error_log("{$landmark}: database error: {$dblink->error} in {$query}\n" . debug_string_backtrace());
            $this->error_msg("<5><p>" . htmlspecialchars($landmark) . ": database error: " . htmlspecialchars($this->dblink->error) . " in " . Ht::pre_text_wrap($query) . "</p>");
        }
    }


    // collation

    /** @return Collator */
    function collator() {
        if (!$this->_collator) {
            $this->_collator = new Collator("en_US.utf8");
            $this->_collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
        }
        return $this->_collator;
    }

    /** @return Collator */
    static function make_name_collator() {
        $coll = new Collator("en_US.utf8");
        $coll->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
        //$coll->setAttribute(Collator::ALTERNATE_HANDLING, Collator::SHIFTED);
        $coll->setStrength(Collator::QUATERNARY);
        return $coll;
    }

    /** @return Collator */
    function name_collator() {
        $this->_pcollator = $this->_pcollator ?? self::make_name_collator();
        return $this->_pcollator;
    }

    /** @param int|bool $sortspec
     * @param ?Collator $pcollator
     * @return callable(Contact|Author,Contact|Author):int */
    static function make_user_comparator($sortspec, $pcollator = null) {
        if (!is_int($sortspec)) {
            $sortspec = $sortspec ? 0312 : 0321;
        }
        $pcollator = $pcollator ?? self::make_name_collator();
        return function ($a, $b) use ($sortspec, $pcollator) {
            $as = Contact::get_sorter($a, $sortspec);
            $bs = Contact::get_sorter($b, $sortspec);
            return $pcollator->compare($as, $bs);
        };
    }

    /** @return callable(Contact|Author,Contact|Author):int */
    function user_comparator($sort_by_last = null) {
        return self::make_user_comparator($sort_by_last ?? $this->sort_by_last,
                                          $this->name_collator());
    }


    // name

    /** @return string */
    function full_name() {
        if ($this->short_name && $this->short_name != $this->long_name) {
            return "{$this->long_name} ({$this->short_name})";
        } else {
            return $this->long_name;
        }
    }


    /** @return FormatSpec */
    function format_spec($dtype) {
        if (!isset($this->_formatspec_cache[$dtype])) {
            $o = $this->option_by_id($dtype);
            $spec = ($o ? $o->format_spec() : null) ?? new FormatSpec;
            if (!$spec->timestamp && $this->opt("banalAlways")) {
                $spec->timestamp = 1;
            }
            $this->_formatspec_cache[$dtype] = $spec;
        }
        return $this->_formatspec_cache[$dtype];
    }

    /** @return ?non-empty-string */
    function docstore() {
        return $this->_docstore;
    }

    /** @return ?S3Client */
    private function _refresh_s3_client() {
        if (!($k = $this->opt["s3_key"] ?? null)
            || !($s = $this->opt["s3_secret"] ?? null)
            || !($b = $this->opt["s3_bucket"] ?? null)) {
            return null;
        } else if ($this->_s3_client
                   && $this->_s3_client->check_key_secret_bucket($k, $s, $b)) {
            return $this->_s3_client;
        } else {
            return S3Client::make([
                "key" => $k, "secret" => $s, "bucket" => $b,
                "region" => $this->opt["s3_region"] ?? null,
                "setting_cache" => $this, "setting_cache_prefix" => "__s3"
            ]);
        }
    }

    /** @return ?S3Client */
    function s3_client() {
        if ($this->_s3_client === false) {
            $this->_s3_client = $this->_refresh_s3_client();
        }
        return $this->_s3_client;
    }


    /** @param ?object $xt
     * @return int|float */
    static function xt_priority($xt) {
        return $xt ? $xt->priority ?? 0 : -INF;
    }
    /** @param ?object $xta
     * @param ?object $xtb
     * @return -1|0|1 */
    static function xt_priority_compare($xta, $xtb) {
        // Return -1 if $xta is higher priority, 1 if $xtb is.
        $ap = self::xt_priority($xta);
        $bp = self::xt_priority($xtb);
        if ($ap == $bp) {
            $ap = $xta ? $xta->__source_order ?? 0 : -INF;
            $bp = $xtb ? $xtb->__source_order ?? 0 : -INF;
        }
        return $bp <=> $ap;
    }
    /** @param object $xta
     * @param object $xtb
     * @return -1|0|1 */
    static function xt_order_compare($xta, $xtb) {
        $ap = $xta->order ?? 0;
        $ap = $ap !== false ? $ap : INF;
        $bp = $xtb->order ?? 0;
        $bp = $bp !== false ? $bp : INF;
        if ($ap == $bp) {
            if (isset($xta->name)
                && isset($xtb->name)
                && ($namecmp = strnatcasecmp($xta->name, $xtb->name)) !== 0) {
                return $namecmp;
            }
            $ap = $xta->__source_order ?? 0;
            $bp = $xtb->__source_order ?? 0;
        }
        return $ap <=> $bp;
    }
    /** @param object $xta
     * @param object $xtb
     * @return -1|0|1 */
    static function xt_pure_order_compare($xta, $xtb) {
        $ap = $xta->order ?? 0;
        $ap = $ap !== false ? $ap : INF;
        $bp = $xtb->order ?? 0;
        $bp = $bp !== false ? $bp : INF;
        if ($ap == $bp) {
            $ap = $xta->__source_order ?? 0;
            $bp = $xtb->__source_order ?? 0;
        }
        return $ap <=> $bp;
    }
    /** @param array<string|int,list<object>> &$a
     * @param object $xt
     * @return true */
    static function xt_add(&$a, $name, $xt) {
        $a[$name][] = $xt;
        return true;
    }
    /** @param ?object $xt
     * @return bool */
    static function xt_enabled($xt) {
        return $xt && (!isset($xt->disabled) || !$xt->disabled);
    }
    /** @param ?object $xt
     * @return ?object */
    static function xt_resolve_require($xt) {
        if ($xt
            && isset($xt->require)
            && !isset(self::$xt_require_resolved[$xt->require])) {
            foreach (SiteLoader::expand_includes(null, $xt->require, ["autoload" => true]) as $f) {
                require_once($f);
            }
            self::$xt_require_resolved[$xt->require] = true;
        }
        return $xt && (!isset($xt->disabled) || !$xt->disabled) ? $xt : null;
    }


    // emoji codes
    function _add_emoji_code($val, $key) {
        if (is_string($val) && str_starts_with($key, ":") && str_ends_with($key, ":")) {
            $this->_emoji_codes->emoji[$key] = $val;
            return true;
        } else {
            return false;
        }
    }
    /** @return array<string,string> */
    function emoji_code_map() {
        if ($this->_emoji_codes === null) {
            $this->_emoji_codes = json_decode(file_get_contents(SiteLoader::find("scripts/emojicodes.json")));
            $this->_emoji_codes->emoji = (array) $this->_emoji_codes->emoji;
            if (($olist = $this->opt("emojiCodes")))
                expand_json_includes_callback($olist, [$this, "_add_emoji_code"]);
        }
        return $this->_emoji_codes->emoji;
    }


    /** @return PaperOptionList */
    function options() {
        return $this->_paper_opts;
    }

    /** @param int $id
     * @return ?PaperOption */
    function option_by_id($id) {
        return $this->_paper_opts->option_by_id($id);
    }

    /** @param int $id
     * @return PaperOption */
    function checked_option_by_id($id) {
        return $this->_paper_opts->checked_option_by_id($id);
    }


    /** @return array<int,Formula> */
    function named_formulas() {
        if ($this->_defined_formulas === null) {
            $this->_defined_formulas = [];
            if ($this->setting("formulas")) {
                $result = $this->q("select * from Formula");
                while ($result && ($f = Formula::fetch($this, $result))) {
                    $this->_defined_formulas[$f->formulaId] = $f;
                }
                Dbl::free($result);
                uasort($this->_defined_formulas, function ($a, $b) {
                    return strnatcasecmp($a->name, $b->name);
                });
            }
        }
        return $this->_defined_formulas;
    }

    /** @param array<int,Formula> $formula_map */
    function replace_named_formulas($formula_map) {
        $this->_defined_formulas = $formula_map;
        $this->_abbrev_matcher = null;
    }

    /** @return ?Formula */
    function find_named_formula($text) {
        return $this->abbrev_matcher()->find1($text, self::MFLAG_FORMULA);
    }

    /** @return array<int,Formula> */
    function viewable_named_formulas(Contact $user) {
        return array_filter($this->named_formulas(), function ($f) use ($user) {
            return $user->can_view_formula($f);
        });
    }


    /** @return DecisionSet */
    function decision_set() {
        if ($this->_decision_set === null) {
            $this->_decision_set = DecisionSet::make_main($this);
        }
        return $this->_decision_set;
    }

    /** @param int $decid
     * @return string */
    function decision_name($decid) {
        return $this->decision_set()->get($decid)->name;
    }


    /** @return bool */
    function has_topics() {
        return ($this->settings["has_topics"] ?? 0) !== 0;
    }

    /** @return TopicSet */
    function topic_set() {
        if ($this->_topic_set === null) {
            $this->_topic_set = TopicSet::make_main($this);
        }
        return $this->_topic_set;
    }

    function invalidate_topics() {
        $this->_topic_set = null;
        $this->_paper_opts->invalidate_intrinsic_option(PaperOption::TOPICSID);
    }


    /** @return Conflict */
    function conflict_set() {
        if ($this->_conflict_set === null) {
            $this->_conflict_set = new Conflict($this);
        }
        return $this->_conflict_set;
    }


    const MFLAG_OPTION = 1;
    const MFLAG_REVIEW = 2;
    const MFLAG_FORMULA = 4;

    /** @return AbbreviationMatcher<PaperOption|ReviewField|Formula> */
    function abbrev_matcher() {
        if (!$this->_abbrev_matcher) {
            $this->_abbrev_matcher = new AbbreviationMatcher;
            $this->_abbrev_matcher->set_priority(self::MFLAG_FORMULA, -1);
            // XXX exposes invisible paper options, review fields
            $this->_paper_opts->populate_abbrev_matcher($this->_abbrev_matcher);
            $this->review_form()->populate_abbrev_matcher($this->_abbrev_matcher);
            foreach ($this->named_formulas() as $f) {
                if ($f->name) {
                    $this->_abbrev_matcher->add_phrase($f->name, $f, self::MFLAG_FORMULA);
                }
            }
            $this->_paper_opts->assign_search_keywords(false, $this->_abbrev_matcher);
            $this->review_form()->assign_search_keywords($this->_abbrev_matcher);
            foreach ($this->named_formulas() as $f) {
                if ($f->name) {
                    $f->assign_search_keyword($this->_abbrev_matcher);
                }
            }
        }
        return $this->_abbrev_matcher;
    }

    /** @return list<PaperOption|ReviewField|Formula> */
    function find_all_fields($text, $tflags = 0) {
        return $this->abbrev_matcher()->find_all($text, $tflags);
    }


    /** @return null|array|object */
    function review_form_json() {
        $x = $this->settingTexts["review_form"] ?? null;
        if (is_string($x)) {
            $x = json_decode($x);
        }
        return is_array($x) || is_object($x) ? $x : null;
    }

    /** @return ReviewForm */
    function review_form() {
        if ($this->_review_form === null) {
            $this->_review_form = new ReviewForm($this, $this->review_form_json());
        }
        return $this->_review_form;
    }

    /** @return array<string,ReviewField> */
    function all_review_fields() {
        return $this->review_form()->all_fields();
    }

    /** @param string $fid
     * @return ?ReviewField */
    function review_field($fid) {
        return $this->review_form()->field($fid);
    }

    /** @param string $text
     * @return ?ReviewField */
    function find_review_field($text) {
        if ($text !== "" && $text[strlen($text) - 1] === ")") {
            $text = ReviewField::clean_name($text);
        }
        return $this->abbrev_matcher()->find1($text, self::MFLAG_REVIEW);
    }

    /** @param string $fid
     * @return ReviewField */
    function checked_review_field($fid) {
        if (($f = $this->review_form()->field($fid))) {
            return $f;
        } else {
            throw new Exception("Unknown review field ‘{$fid}’");
        }
    }


    /** @return TagMap */
    function tags() {
        if (!$this->_tag_map) {
            $this->_tag_map = TagMap::make($this, true);
        }
        return $this->_tag_map;
    }


    /** @return bool */
    function has_tracks() {
        return $this->_tracks !== null;
    }

    /** @return list<string> */
    function track_tags() {
        return $this->_track_tags ?? [];
    }

    /** @param string $tag
     * @return ?Track */
    function track($tag) {
        foreach ($this->_tracks ?? [] as $tr) {
            if (strcasecmp($tr->tag, $tag) === 0)
                return $tr;
        }
        return null;
    }

    /** @return list<Track> */
    function all_tracks() {
        return $this->_tracks ?? [];
    }

    /** @param int $ttype
     * @return ?string */
    function permissive_track_tag_for(Contact $user, $ttype) {
        foreach ($this->_tracks ?? [] as $tr) {
            if ($user->has_permission($tr->perm[$ttype]) && !$tr->is_default) {
                return $tr->tag;
            }
        }
        return null;
    }

    /** @param int $ttype
     * @return bool */
    function check_tracks(PaperInfo $prow, Contact $user, $ttype) {
        $unmatched = true;
        if ($this->_tracks !== null) {
            foreach ($this->_tracks as $tr) {
                if ($tr->is_default ? $unmatched : $prow->has_tag($tr->ltag)) {
                    $unmatched = false;
                    if ($user->has_permission($tr->perm[$ttype])) {
                        return true;
                    }
                }
            }
        }
        return $unmatched;
    }

    /** @param int $ttype
     * @return bool */
    function check_reviewer_tracks(PaperInfo $prow, Contact $user, $ttype) {
        $unmatched = true;
        if ($this->_tracks !== null) {
            foreach ($this->_tracks as $tr) {
                if ($tr->is_default ? $unmatched : $prow->has_tag($tr->ltag)) {
                    $unmatched = false;
                    if ($user->isPC
                        ? $user->has_permission($tr->perm[$ttype])
                        : $tr->perm[$ttype] !== "+none") {
                        return true;
                    }
                }
            }
        }
        return $unmatched;
    }

    /** @param int $ttype
     * @return bool */
    function check_required_tracks(PaperInfo $prow, Contact $user, $ttype) {
        if (($this->_track_sensitivity & (1 << $ttype)) !== 0) {
            $unmatched = true;
            foreach ($this->_tracks as $tr) {
                if ($tr->is_default ? $unmatched : $prow->has_tag($tr->ltag)) {
                    $unmatched = false;
                    if ($tr->perm[$ttype] && $user->has_permission($tr->perm[$ttype])) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** @return bool */
    function check_admin_tracks(PaperInfo $prow, Contact $user) {
        return $this->check_required_tracks($prow, $user, Track::ADMIN);
    }

    /** @param int $ttype
     * @return bool */
    function check_default_track(Contact $user, $ttype) {
        return !$this->_tracks
            || $user->has_permission($this->_tracks[count($this->_tracks) - 1]->perm[$ttype]);
    }

    /** @param int $ttype
     * @return bool */
    function check_any_tracks(Contact $user, $ttype) {
        if ($this->_tracks) {
            foreach ($this->_tracks as $tr) {
                if (($ttype === Track::VIEW
                     || $user->has_permission($tr->perm[Track::VIEW]))
                    && $user->has_permission($tr->perm[$ttype])) {
                    return true;
                }
            }
        }
        return !$this->_tracks;
    }

    /** @param int $ttype
     * @return bool */
    function check_any_required_tracks(Contact $user, $ttype) {
        if (($this->_track_sensitivity & (1 << $ttype)) !== 0) {
            foreach ($this->_tracks as $tr) {
                if ($tr->perm[$ttype]
                    && $user->has_permission($tr->perm[$ttype])) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return bool */
    function check_any_admin_tracks(Contact $user) {
        return $this->check_any_required_tracks($user, Track::ADMIN);
    }

    /** @param int $ttype
     * @return bool */
    function check_track_sensitivity($ttype) {
        return ($this->_track_sensitivity & (1 << $ttype)) !== 0;
    }
    /** @return bool */
    function check_track_view_sensitivity() {
        return ($this->_track_sensitivity & Track::BITS_VIEW) !== 0;
    }
    /** @return bool */
    function check_track_review_sensitivity() {
        return ($this->_track_sensitivity & Track::BITS_REVIEW) !== 0;
    }
    /** @return bool */
    function check_track_admin_sensitivity() {
        return ($this->_track_sensitivity & Track::BITS_ADMIN) !== 0;
    }

    /** @return bool */
    function check_paper_track_sensitivity(PaperInfo $prow, $ttype) {
        if ($this->_track_sensitivity & (1 << $ttype)) {
            $unmatched = true;
            foreach ($this->_tracks as $tr) {
                if ($tr->is_default ? $unmatched : $prow->has_tag($tr->ltag)) {
                    $unmatched = false;
                    if ($tr->perm[$ttype]) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** @param string $tag
     * @param int $ttype
     * @return ?string */
    function track_permission($tag, $ttype) {
        foreach ($this->_tracks ?? [] as $tr) {
            if (strcasecmp($tr->tag, $tag) === 0
                || $tr->is_default /* always last */) {
                return $tr->perm[$ttype];
            }
        }
        return null;
    }

    /** @return int */
    function dangerous_track_mask(Contact $user) {
        $m = 0;
        $nonchair = !$user->privChair;
        if ($this->_tracks && ($nonchair || $user->contactTags)) {
            foreach ($this->_tracks as $tr) {
                foreach ($tr->perm as $i => $perm) {
                    if ($perm
                        && ($nonchair || $perm[0] === "-")
                        && !$user->has_permission($perm)) {
                        $m |= 1 << $i;
                    }
                }
            }
        }
        return $m;
    }

    /** @return bool */
    function rights_need_tags() {
        return $this->_track_tags !== null;
    }


    /** @return bool */
    function has_rounds() {
        return count($this->rounds) > 1;
    }

    /** @return list<string> */
    function round_list() {
        return $this->rounds;
    }

    /** @return array<int,string> */
    function defined_rounds() {
        if ($this->_defined_rounds === null) {
            $dl = $r = [];
            foreach ($this->rounds as $i => $rname) {
                if ($i === 0 || $rname !== ";") {
                    if ($i === 0) {
                        $sfx = "";
                    } else {
                        $sfx = "_{$i}";
                        $dl[$i] = 0;
                    }
                    foreach (self::$review_deadlines as $rd) {
                        if (($t = $this->settings["{$rd}{$sfx}"] ?? null) !== null) {
                            $dl[$i] = max($dl[$i] ?? 0, $t);
                        }
                    }
                    if (isset($dl[$i])) {
                        $r[$i] = $i === 0 ? "unnamed" : $rname;
                    }
                }
            }
            uksort($r, function ($a, $b) use ($r, $dl) {
                $adl = $dl[$a] ?? null;
                $bdl = $dl[$b] ?? null;
                if ($adl && $bdl && $adl != $bdl) {
                    return $adl < $bdl ? -1 : 1;
                } else if (!$adl != !$bdl) {
                    return $adl ? -1 : 1;
                } else {
                    return strcasecmp($a ? $r[$a] : "~", $b ? $r[$b] : "~");
                }
            });
            $this->_defined_rounds = $r;
        }
        return $this->_defined_rounds;
    }

    /** @param int $roundno
     * @return string */
    function round_name($roundno) {
        if ($roundno > 0) {
            $rname = $this->rounds[$roundno] ?? null;
            if ($rname === null) {
                error_log("{$this->dbname}: round #{$roundno} undefined");
                while (count($this->rounds) <= $roundno) {
                    $this->rounds[] = null;
                }
                $this->rounds[$roundno] = ";";
            } else if ($rname !== ";") {
                return $rname;
            }
        }
        return "";
    }

    /** @param int $roundno
     * @return string */
    function round_suffix($roundno) {
        if ($roundno > 0
            && ($rname = $this->rounds[$roundno] ?? null)
            && $rname !== ";") {
            return "_{$rname}";
        }
        return "";
    }

    /** @param string $rname
     * @return string|false */
    static function round_name_error($rname) {
        // Must return HTML-safe plaintext
        // Also see `settings.js`
        if ((string) $rname === "") {
            return "Round name required";
        } else if (!preg_match('/\A[a-zA-Z](?:[a-zA-Z0-9]|[-_][a-zA-Z0-9])*\z/', $rname)) {
            return "Invalid round name (must start with a letter and contain only letters, numbers, and dashes)";
        } else if (preg_match('/\A(?:none|any|all|span|default|undefined|unnamed|.*(?:draft|response|review)|(?:draft|response).*|pri(?:mary)|sec(?:ondary)|opt(?:ional)|pc|ext(?:ernal)|meta)\z/i', $rname)) {
            return "Round name ‘{$rname}’ is reserved";
        } else {
            return false;
        }
    }

    /** @param ?string $rname
     * @return string|false */
    function sanitize_round_name($rname) {
        if ($rname === null) {
            return (string) ($this->settingTexts["rev_roundtag"] ?? null);
        } else if ($rname === ""
                   || strcasecmp($rname, "(none)") === 0
                   || strcasecmp($rname, "none") === 0
                   || strcasecmp($rname, "unnamed") === 0) {
            return "";
        } else if (self::round_name_error($rname)) {
            return false;
        } else {
            return $rname;
        }
    }

    /** @param int|bool $external
     * @return string */
    function assignment_round_option($external) {
        $v = $this->settingTexts["rev_roundtag"] ?? "";
        if (is_int($external) ? $external < REVIEW_PC : $external) {
            $v = $this->settingTexts["extrev_roundtag"] ?? $v;
        }
        return $v === "" ? "unnamed" : $v;
    }

    /** @param int|bool $external
     * @return int */
    function assignment_round($external) {
        return $this->round_number($this->assignment_round_option($external)) ?? 0;
    }

    /** @param string $rname
     * @return ?int */
    function round_number($rname) {
        if (!$rname) {
            return 0;
        }
        for ($i = 1; $i != count($this->rounds); ++$i) {
            if (strcasecmp($this->rounds[$i], $rname) === 0) {
                return $i;
            }
        }
        if (strcasecmp($rname, "none") === 0
            || strcasecmp($rname, "unnamed") === 0) {
            return 0;
        }
        return null;
    }

    /** @return array<string,string> */
    function round_selector_options($isexternal) {
        $opt = [];
        foreach ($this->defined_rounds() as $rname) {
            $opt[$rname] = $rname;
        }
        if (($isexternal === null || $isexternal === true)
            && ($r = $this->settingTexts["rev_roundtag"] ?? null) !== null
            && !isset($opt[$r ? : "unnamed"])) {
            $opt[$r ? : "unnamed"] = $r ? : "unnamed";
        }
        if (($isexternal === null || $isexternal === false)
            && ($r = $this->settingTexts["extrev_roundtag"] ?? null) !== null
            && !isset($opt[$r ? : "unnamed"])) {
            $opt[$r ? : "unnamed"] = $r ? : "unnamed";
        }
        return $opt;
    }

    /** @param string $name
     * @param ?int $round */
    function round_setting($name, $round) {
        if ($this->_round_settings !== null
            && $round !== null
            && isset($this->_round_settings[$round])
            && isset($this->_round_settings[$round]->$name)) {
            return $this->_round_settings[$round]->$name;
        } else {
            return $this->settings[$name] ?? null;
        }
    }



    /** @return list<ResponseRound> */
    function response_rounds() {
        if ($this->_resp_rounds === null) {
            $this->_resp_rounds = $this->_response_rounds();
        }
        return $this->_resp_rounds;
    }

    /** @return list<ResponseRound> */
    private function _response_rounds() {
        $rrds = [];
        $active = ($this->settings["resp_active"] ?? 0) > 0;
        $resptext = $this->settingTexts["responses"] ?? null;
        $jresp = $resptext ? json_decode($resptext) : null;
        foreach ($jresp ?? [(object) []] as $i => $rrj) {
            $rrd = new ResponseRound;
            $rrd->id = $i + 1;
            $rrd->unnamed = $i === 0 && !isset($rrj->name);
            $rrd->name = $rrj->name ?? "1";
            $rrd->active = $active;
            $rrd->done = $rrj->done ?? 0;
            $rrd->grace = $rrj->grace ?? 0;
            $rrd->open = $rrj->open
                ?? ($rrd->done && $rrd->done + $rrd->grace >= self::$now ? 1 : 0);
            $rrd->wordlimit = $rrj->wl ?? $rrj->words ?? 500;
            $rrd->hard_wordlimit = $rrj->hwl
                ?? ($rrj->truncate ?? false ? $rrd->wordlimit : 0);
            if (($rrj->condition ?? "") !== "") {
                $rrd->condition = $rrj->condition;
            }
            $rrd->instructions = $rrj->instructions ?? null;
            $rrds[] = $rrd;
        }
        return $rrds;
    }

    /** @param string $rname
     * @return string|false */
    static function response_round_name_error($rname) {
        return self::round_name_error($rname);
    }

    /** @param string $rname
     * @return ?ResponseRound */
    function response_round($rname) {
        $rrds = $this->response_rounds();
        foreach ($rrds as $rrd) {
            if (strcasecmp($rname, $rrd->name) === 0)
                return $rrd;
        }
        if ($rrds[0]->unnamed
            && ($rname === ""
                || strcasecmp($rname, "unnamed") === 0
                || strcasecmp($rname, "none") === 0)) {
            return $rrds[0];
        }
        return null;
    }

    /** @param int $round
     * @return ?ResponseRound */
    function response_round_by_id($round) {
        $rrds = $this->response_rounds();
        return $rrds[$round - 1] ?? null;
    }


    /** @param ?int $format
     * @return ?TextFormat */
    function format_info($format) {
        if ($this->_format_info === null) {
            $this->_format_info = [];
            if (!isset($this->opt["formatInfo"])) {
                // ok
            } else if (is_array($this->opt["formatInfo"])) {
                $this->_format_info = $this->opt["formatInfo"];
            } else if (is_string($this->opt["formatInfo"])) {
                $this->_format_info = json_decode($this->opt["formatInfo"], true);
            }
            foreach ($this->_format_info as $format => &$fi) {
                $fi = new TextFormat($format, $fi);
            }
        }
        return $this->_format_info[$format ?? $this->default_format] ?? null;
    }

    /** @param ?int $format
     * @param ?string $text
     * @return int */
    function check_format($format, $text = null) {
        $format = $format ?? $this->default_format;
        if ($format
            && $text !== null
            && ($f = $this->format_info($format))
            && $f->simple_regex
            && preg_match($f->simple_regex, $text)) {
            $format = 0;
        }
        return $format;
    }


    /** @return list<object> */
    function named_searches() {
        $j = $this->setting_json("named_searches");
        return is_array($j) ? $j : [];
    }


    /** @return null|'ldap'|'htauth'|'none'|'oauth' */
    function login_type() {
        if (!array_key_exists("loginType", $this->opt)) {
            if ($this->opt["ldapLogin"] ?? false) {
                $this->opt["loginType"] = "ldap";
            } else if ($this->opt["httpAuthLogin"] ?? false) {
                $this->opt["loginType"] = "htauth";
            } else {
                $this->opt["loginType"] = null;
            }
        }
        return $this->opt["loginType"];
    }

    /** @return bool */
    function external_login() {
        $lt = $this->login_type();
        return $lt === "ldap" || $lt === "htauth";
    }

    /** @return bool */
    function allow_local_signin() {
        $lt = $this->login_type();
        return $lt !== "none" && $lt !== "oauth";
    }

    /** @return bool */
    function allow_user_self_register() {
        return !$this->disable_non_pc && !$this->opt("disableNewUsers");
    }

    /** @return array<string,object> */
    function oauth_providers() {
        if ($this->_oauth_providers === null) {
            $k = isset($this->opt["oAuthProviders"]) ? "oAuthProviders" : "oAuthTypes";
            $this->_oauth_providers = $this->_xtbuild_resolve([], $k);
        }
        return $this->_oauth_providers;
    }


    // root user, site contact

    /** @return Contact */
    function root_user() {
        if (!$this->_root_user) {
            $this->_root_user = Contact::make_root_user($this);
            $this->_root_user->set_overrides(Contact::OVERRIDE_CONFLICT);
        }
        return $this->_root_user;
    }

    /** @return Author */
    function default_site_contact() {
        $result = $this->ql("select firstName, lastName, affiliation, email from ContactInfo where roles!=0 and (roles&" . (Contact::ROLE_CHAIR | Contact::ROLE_ADMIN) . ")!=0 order by (roles&" . Contact::ROLE_CHAIR . ") desc, contactId asc limit 1");
        $chair = $result->fetch_object("Author");
        Dbl::free($result);
        return $chair;
    }

    /** @return Author */
    function site_contact() {
        if (!$this->_site_contact) {
            $e = $this->opt("contactEmail") ?? "";
            if (($e === "" || $e === "you@example.com")
                && ($dsc = $this->default_site_contact())) {
                $this->_site_contact = $dsc;
            } else {
                $this->_site_contact = Author::make_keyed([
                    "email" => $e, "name" => $this->opt("contactName")
                ]);
            }
        }
        return $this->_site_contact;
    }

    /** @param int $disabled
     * @param int $roles
     * @return int */
    function disablement_for($disabled, $roles) {
        if ($this->disable_non_pc && ($roles & Contact::ROLE_PCLIKE) === 0) {
            $disabled |= Contact::CF_ROLEDISABLED;
        }
        return $disabled;
    }


    // database users

    /** @param int $slice
     * @param string $prefix
     * @return string */
    function user_query_fields($slice = Contact::SLICE_MINIMAL, $prefix = "") {
        if (($slice & Contact::SLICEBIT_REST) !== 0) {
            $f = "{$prefix}contactId, {$prefix}email, {$prefix}firstName, {$prefix}lastName, {$prefix}affiliation, {$prefix}roles, {$prefix}disabled, {$prefix}primaryContactId, {$prefix}contactTags, {$prefix}cflags";
            if (($slice & Contact::SLICEBIT_COLLABORATORS) === 0) {
                $f .= ", {$prefix}collaborators";
            }
            if (($slice & Contact::SLICEBIT_PASSWORD) === 0) {
                $f .= ", {$prefix}password";
            }
            if (($slice & Contact::SLICEBIT_COUNTRY) === 0) {
                $f .= ", {$prefix}country";
            }
            if (($slice & Contact::SLICEBIT_ORCID) === 0) {
                $f .= ", {$prefix}orcid";
            }
            return "{$f}, {$slice} _slice";
        } else {
            return "{$prefix}*, 0 _slice";
        }
    }

    /** @param string $prefix
     * @return string */
    function deleted_user_query_fields($prefix = "") {
        return "{$prefix}contactId, {$prefix}email, {$prefix}firstName, {$prefix}lastName, {$prefix}affiliation, 0 roles, " . Contact::CF_DELETED . " disabled, 0 primaryContactId, '' contactTags, " . Contact::CF_DELETED . " cflags, 0 _slice";
    }

    /** @param int $slice
     * @param string $prefix
     * @return string */
    function contactdb_user_query_fields($slice = Contact::SLICE_MINIMAL, $prefix = "") {
        if (($slice & Contact::SLICEBIT_REST) !== 0) {
            $f = "{$prefix}contactDbId, {$prefix}email, {$prefix}firstName, {$prefix}lastName, {$prefix}affiliation, {$prefix}disabled";
            if (($slice & Contact::SLICEBIT_COLLABORATORS) === 0) {
                $f .= ", {$prefix}collaborators";
            }
            if (($slice & Contact::SLICEBIT_PASSWORD) === 0) {
                $f .= ", {$prefix}password";
            }
            if (($slice & Contact::SLICEBIT_COUNTRY) === 0) {
                $f .= ", {$prefix}country";
            }
            if (($slice & Contact::SLICEBIT_ORCID) === 0) {
                $f .= ", {$prefix}orcid";
            }
            return "{$f}, {$slice} _slice";
        } else {
            return "{$prefix}*, 0 _slice";
        }
    }


    /** @param int $id
     * @return ?Contact */
    function fresh_user_by_id($id) {
        $result = $this->qe("select * from ContactInfo where contactId=?", $id);
        $u = Contact::fetch($result, $this);
        $result->close();
        return $u;
    }

    /** @param string $email
     * @return ?Contact */
    function fresh_user_by_email($email) {
        $email = trim((string) $email);
        if ($email === "" || !is_valid_utf8($email)) {
            return null;
        }
        $result = $this->qe("select * from ContactInfo where email=?", $email);
        $u = Contact::fetch($result, $this);
        $result->close();
        return $u;
    }

    /** @param string $email
     * @return Contact */
    function checked_user_by_email($email) {
        $acct = $this->fresh_user_by_email($email);
        if (!$acct) {
            throw new Exception("Contact::checked_user_by_email({$email}) failed");
        }
        return $acct;
    }


    // user cache

    private function _ensure_user_cache() {
        if ($this->_user_cache !== null) {
            return;
        }
        $this->_user_cache = $this->_pc_set ? $this->_pc_set->as_map() : [];
    }

    private function _ensure_user_email_cache() {
        if ($this->_user_email_cache !== null) {
            return;
        }
        $this->_ensure_user_cache();
        $this->_user_email_cache = [];
        foreach ($this->_user_cache as $u) {
            if ($u !== null) {
                $this->_user_email_cache[strtolower($u->email)] = $u;
            }
        }
    }

    /** @param int $id */
    function prefetch_user_by_id($id) {
        if (!array_key_exists($id, $this->_user_cache ?? [])) {
            $this->_user_cache_missing[] = $id;
        }
    }

    /** @param iterable<int> $ids */
    function prefetch_users_by_id($ids) {
        $uc = $this->_user_cache ?? [];
        foreach ($ids as $id) {
            if (!array_key_exists($id, $uc)) {
                $this->_user_cache_missing[] = $id;
            }
        }
    }

    /** @param string $email */
    function prefetch_user_by_email($email) {
        if (!array_key_exists($email, $this->_user_email_cache ?? [])) {
            $this->_user_cache_missing[] = $email;
        }
    }

    /** @param list<string> $emails */
    function prefetch_users_by_email($emails) {
        $uec = $this->_user_email_cache ?? [];
        foreach ($emails as $email) {
            if (!array_key_exists($email, $uec)) {
                $this->_user_cache_missing[] = $email;
            }
        }
    }

    /** @param int|string $req
     * @return null|int|string */
    static private function clean_user_cache_request($req) {
        if (is_int($req) && $req > 0) {
            return $req;
        } else if (is_string($req) && $req !== "" && is_valid_utf8($req)) {
            return strtolower($req);
        } else {
            return null;
        }
    }

    /** @param bool $require_pc */
    private function _refresh_user_cache($require_pc) {
        $this->_ensure_user_cache();
        $reqids = $reqemails = [];
        foreach ($this->_user_cache_missing ?? [] as $req) {
            $req = self::clean_user_cache_request($req);
            if (is_int($req)) {
                if (!array_key_exists($req, $this->_user_cache)) {
                    $this->_user_cache[$req] = null;
                    $reqids[] = $req;
                }
            } else if (is_string($req)) {
                $this->_ensure_user_email_cache();
                if (!array_key_exists($req, $this->_user_email_cache)) {
                    $this->_user_email_cache[$req] = null;
                    $reqemails[] = $req;
                }
            }
        }
        $this->_user_cache_missing = null;
        $qf = $qv = [];
        if (!empty($reqids)) {
            $qf[] = "contactId?a";
            $qv[] = $reqids;
        }
        if (!empty($reqemails)) {
            $qf[] = "email?a";
            $qv[] = $reqemails;
        }
        $require_pc = $require_pc || ($this->_pc_set === null && !$this->opt("largePC"));
        if ($require_pc) {
            $qf[] = "(roles!=0 and (roles&" . Contact::ROLE_PCLIKE . ")!=0)";
        }
        if (empty($qf)) {
            return;
        }
        $result = $this->qe("select " . $this->user_query_fields($this->_slice) . " from ContactInfo where " . join(" or ", $qf), ...$qv);
        foreach (ContactSet::make_result($result, $this) as $u) {
            $this->_user_cache[$u->contactId] = $u;
            if ($this->_user_email_cache !== null) {
                $this->_user_email_cache[strtolower($u->email)] = $u;
            }
        }
        if ($require_pc) {
            $this->_postprocess_pc_set();
        }
    }

    /** @param int $id
     * @param 0|1 $sliced
     * @return ?Contact */
    function user_by_id($id, $sliced = 0) {
        $id = (int) $id;
        if ($id === 0) {
            return null;
        } else if (Contact::$main_user !== null
                   && Contact::$main_user->conf === $this
                   && Contact::$main_user->contactId === $id) {
            return Contact::$main_user;
        }
        if (!array_key_exists($id, $this->_user_cache ?? [])) {
            $this->_user_cache_missing[] = $id;
            $this->_refresh_user_cache(false);
        }
        $u = $this->_user_cache[$id] ?? null;
        if ($u && $sliced !== 1 && $u->_slice !== 0) {
            $u->unslice();
        }
        return $u;
    }

    /** @param string $email
     * @param 0|1 $sliced
     * @return ?Contact */
    function user_by_email($email, $sliced = 0) {
        if ($email === "") {
            return null;
        }
        if (Contact::$main_user !== null
            && Contact::$main_user->conf === $this
            && strcasecmp(Contact::$main_user->email, $email) === 0
            && Contact::$main_user->contactId > 0) {
            return Contact::$main_user;
        }
        $this->_ensure_user_email_cache();
        $lemail = strtolower($email);
        if (!array_key_exists($lemail, $this->_user_email_cache)) {
            $this->_user_cache_missing[] = $lemail;
            $this->_refresh_user_cache(false);
        }
        $u = $this->_user_email_cache[$lemail] ?? null;
        if ($u && $sliced !== 1 && $u->_slice !== 0) {
            $u->unslice();
        }
        return $u;
    }

    function ensure_cached_user_collaborators() {
        $this->_slice &= ~Contact::SLICEBIT_COLLABORATORS;
    }

    /** @param ?Contact $u
     * @param bool $saved */
    function invalidate_user($u, $saved = false) {
        if ($u === null) {
            return;
        } else if ($u->cdb_confid === 0) {
            if ($this->_user_email_cache !== null
                || $this->_user_cache !== null) {
                $this->invalidate_local_user($u, $saved);
            }
        } else {
            if ($this->_cdb_user_cache !== null) {
                $this->invalidate_cdb_user($u, $saved);
            }
        }
    }

    /** @param Contact $u
     * @param bool $saved */
    private function invalidate_local_user($u, $saved) {
        if ($this->_user_email_cache !== null) {
            $lemail = strtolower($u->email);
            if ($u->contactId <= 0
                && ($cu = $this->_user_email_cache[$lemail] ?? null)
                && $cu->contactId > 0) {
                $u->contactId = $u->contactXid = $cu->contactId;
            }
            if ($saved) {
                $this->_user_email_cache[$lemail] = $u;
            } else {
                unset($this->_user_email_cache[$lemail]);
            }
        }
        if ($this->_user_cache !== null
            && $u->contactId > 0) {
            if ($saved) {
                $this->_user_cache[$u->contactId] = $u;
            } else {
                unset($this->_user_cache[$u->contactId]);
            }
        }
    }

    /** @param Contact $u
     * @param bool $saved */
    private function invalidate_cdb_user($u, $saved) {
        $lemail = strtolower($u->email);
        if ($u->contactDbId <= 0
            && ($cu = $this->_cdb_user_cache[$lemail] ?? null)
            && $cu->contactDbId > 0) {
            $u->contactDbId = $cu->contactDbId;
        }
        if ($saved) {
            $this->_cdb_user_cache[$lemail] = $u;
        } else {
            unset($this->_cdb_user_cache[$lemail]);
        }
        if ($u->contactDbId > 0) {
            if ($saved) {
                $this->_cdb_user_cache[$u->contactDbId] = $u;
            } else {
                unset($this->_cdb_user_cache[$u->contactDbId]);
            }
        }
    }

    /** @param Contact $u */
    function unslice_user($u) {
        if ($this->_user_cache !== null
            && $u === $this->_user_cache[$u->contactId]
            && $this->_slice !== 0) {
            // assume we'll need to unslice all cached users (likely the PC)
            $result = $this->qe("select * from ContactInfo where contactId?a", array_keys($this->_user_cache));
            while (($m = $result->fetch_object())) {
                $this->_user_cache[intval($m->contactId)]->unslice_using($m);
            }
            Dbl::free($result);
            $this->_slice = 0;
        } else if (($m = $this->fetch_first_object("select * from ContactInfo where contactId=?", $u->contactId))) {
            $u->unslice_using($m);
        } else {
            // will rarely happen -- unslicing a user who has been deleted
            $u->_slice = 0;
        }
    }


    // primary emails

    /** @param list<string> $emails
     * @return list<string> */
    function resolve_primary_emails($emails) {
        $cdb = $this->contactdb();

        // load local sliced users
        foreach ($emails as $email) {
            $this->prefetch_user_by_email($email);
        }
        $missing = $redirect = $oemails = [];
        foreach ($emails as $i => $email) {
            $u = $this->user_by_email($email, USER_SLICE);
            if (!$u && $cdb) {
                $missing[] = $i;
                $this->prefetch_cdb_user_by_email($email);
            } else if ($u && $u->primaryContactId > 0) {
                $redirect[$i] = $u;
            }
            $oemails[] = $u ? $u->email : $email;
        }

        // load cdb users
        foreach ($missing as $i) {
            if (($u = $this->cdb_user_by_email($emails[$i]))) {
                $oemails[$i] = $u->email;
                if ($u->primaryContactId > 0) {
                    $redirect[$i] = $u;
                }
            }
        }

        // resolve indirected users
        for ($round = 0; !empty($redirect) && $round !== 3; ++$round) {
            // redirection chains stop at explicitly disabled users
            $this_redirect = [];
            foreach ($redirect as $i => $u) {
                if (!$u->is_explicitly_disabled()) {
                    if ($u->cdb_confid !== 0) {
                        $this->prefetch_cdb_user_by_id($u->primaryContactId);
                    } else {
                        $this->prefetch_user_by_id($u->primaryContactId);
                    }
                    $this_redirect[$i] = $u;
                }
            }

            $redirect = [];
            foreach ($this_redirect as $i => $u) {
                if ($u->cdb_confid !== 0) {
                    $u2 = $this->cdb_user_by_id($u->primaryContactId);
                } else {
                    $u2 = $this->user_by_id($u->primaryContactId, USER_SLICE);
                }
                if ($u2) {
                    $oemails[$i] = $u2->email;
                    if ($u2->primaryContactId > 0) {
                        $redirect[$i] = $u2;
                    }
                }
            }
        }

        return $oemails;
    }


    // program committee

    /** @return ContactSet */
    function pc_set() {
        if ($this->_pc_set === null) {
            $this->_refresh_user_cache(true);
        }
        return $this->_pc_set;
    }

    private function _postprocess_pc_set() {
        $this->_pc_set = new ContactSet;
        foreach ($this->_user_cache as $u) {
            if (($u->roles & Contact::ROLE_PCLIKE) !== 0) {
                $this->_pc_set->add_user($u, true);
            }
        }

        // analyze set for ambiguous names, disablement
        $this->_pc_members_all_enabled = true;
        $by_name_text = [];
        $expected_by_name_count = 0;
        foreach ($this->_pc_set as $u) {
            if (($name = $u->name()) !== "") {
                $by_name_text[strtolower($name)][] = $u;
                ++$expected_by_name_count;
            }
            if ($u->is_disabled()) {
                $this->_pc_members_all_enabled = false;
            }
        }
        if ($expected_by_name_count !== count($by_name_text)) {
            foreach ($by_name_text as $us) {
                if (count($us) === 1) {
                    continue;
                }
                $npcus = 0;
                foreach ($us as $u) {
                    $npcus += ($u->roles & Contact::ROLE_PC ? 1 : 0);
                }
                foreach ($us as $u) {
                    if ($npcus > 1 || ($u->roles & Contact::ROLE_PC) === 0) {
                        $u->nameAmbiguous = true;
                    }
                }
            }
        }

        // sort
        $this->_pc_set->sort_by($this->user_comparator());

        // populate other caches
        $this->_pc_members_cache = [];
        $next_pc_index = 0;
        foreach ($this->_pc_set as $u) {
            if (($u->roles & Contact::ROLE_PC) !== 0) {
                $u->pc_index = $next_pc_index;
                ++$next_pc_index;
                $this->_pc_members_cache[$u->contactId] = $u;
            }
        }
    }

    /** @return array<int,Contact> */
    function pc_members() {
        $this->_pc_set || $this->pc_set();
        return $this->_pc_members_cache;
    }

    /** @return bool */
    function has_disabled_pc_members() {
        $this->_pc_set || $this->pc_set();
        return !$this->_pc_members_all_enabled;
    }

    /** @return array<int,Contact> */
    function enabled_pc_members() {
        $this->_pc_set || $this->pc_set();
        if ($this->_pc_members_all_enabled) {
            return $this->_pc_members_cache;
        }
        $pcm = [];
        foreach ($this->_pc_members_cache as $cid => $u) {
            if (!$u->is_disabled())
                $pcm[$cid] = $u;
        }
        return $pcm;
    }

    /** @param int $uid
     * @return ?Contact */
    function pc_member_by_id($uid) {
        $u = $this->pc_set()->get($uid);
        return $u && ($u->roles & Contact::ROLE_PC) !== 0 ? $u : null;
    }

    /** @param string $email
     * @return ?Contact */
    function pc_member_by_email($email) {
        $this->_pc_set || $this->pc_set();
        if ($this->_user_email_cache === null) {
            $this->_ensure_user_email_cache();
        }
        /** @phan-suppress-next-line PhanTypeArraySuspiciousNullable */
        $u = $this->_user_email_cache[strtolower($email)] ?? null;
        return $u && ($u->roles & Contact::ROLE_PC) !== 0 ? $u : null;
    }

    /** @return array<int,Contact> */
    function pc_users() {
        return $this->pc_set()->as_map();
    }

    /** @param int $uid
     * @return ?Contact */
    function pc_user_by_id($uid) {
        return $this->pc_set()->get($uid);
    }

    /** @return array<string,string> */
    private function pc_tagmap() {
        if ($this->_pc_tags_cache !== null) {
            return $this->_pc_tags_cache;
        }
        $this->_pc_tags_cache = ["pc" => "pc"];
        foreach ($this->pc_users() as $u) {
            if ($u->contactTags !== null) {
                foreach (explode(" ", $u->contactTags) as $tv) {
                    list($tag, $unused) = Tagger::unpack($tv);
                    if ($tag) {
                        $this->_pc_tags_cache[strtolower($tag)] = $tag;
                    }
                }
            }
        }
        $this->collator()->asort($this->_pc_tags_cache);
        return $this->_pc_tags_cache;
    }

    /** @return list<string> */
    function pc_tags() {
        return array_values($this->pc_tagmap());
    }

    /** @param string $tag
     * @return bool */
    function pc_tag_exists($tag) {
        return isset(($this->pc_tagmap())[strtolower($tag)]);
    }

    /** @return list<string> */
    function viewable_user_tags(Contact $viewer) {
        if ($viewer->privChair) {
            return $this->pc_tags();
        } else if ($viewer->can_view_user_tags()) {
            $t = " " . join("#0 ", $this->pc_tags()) . "#0";
            $t = $this->tags()->censor(TagMap::CENSOR_VIEW, $t, $viewer, null);
            return explode("#0 ", substr($t, 1, -2));
        } else {
            return [];
        }
    }


    // contactdb

    /** @return ?\mysqli */
    static function main_contactdb() {
        global $Opt;
        if (self::$_cdb === false) {
            self::$_cdb = null;
            $opt = Conf::$main ? Conf::$main->opt : $Opt;
            if (($dsn = $opt["contactdbDsn"] ?? null)
                && ($cp = Dbl::parse_connection_params([
                        "dsn" => $dsn,
                        "dbSocket" => $opt["contactdbSocket"] ?? $opt["dbSocket"] ?? null
                    ]))) {
                self::$_cdb = $cp->connect();
            }
        }
        return self::$_cdb;
    }

    /** @return Dbl_Result */
    static function main_cdb_qe(...$args) {
        if (($cdb = self::main_contactdb())) {
            return Dbl::do_query_on($cdb, $args, Dbl::F_ERROR);
        } else {
            return Dbl_Result::make_error();
        }
    }

    /** @return ?\mysqli */
    function contactdb() {
        return self::$_cdb === false ? self::main_contactdb() : self::$_cdb;
    }

    /** @return string */
    function cdb_confuid() {
        return $this->opt["contactdbConfuid"] ?? $this->dbname;
    }

    /** @return int */
    function cdb_confid() {
        $confid = $this->opt["contactdbConfid"] ?? null;
        if ($confid === null) {
            if (($cdb = $this->contactdb())) {
                $confid = Dbl::fetch_ivalue($cdb, "select confid from Conferences where confuid=?", $this->cdb_confuid());
            }
            $this->opt["contactdbConfid"] = $confid = $confid ?? -1;
        }
        return $confid;
    }

    /** @param ?list<int> $ids
     * @param ?list<string> $emails
     * @return list<Contact> */
    private function _fresh_cdb_user_list($ids, $emails) {
        $cdb = $this->contactdb();
        if (!$cdb || (empty($ids) && empty($emails))) {
            return [];
        }
        $q = "select ContactInfo.*, roles, " . Contact::ROLE_CDBMASK . " role_mask, activity_at";
        if (($confid = $this->opt("contactdbConfid") ?? 0) > 0) {
            $q .= ", ? cdb_confid from ContactInfo left join Roles on (Roles.contactDbId=ContactInfo.contactDbId and Roles.confid=?)";
            $qv = [$confid, $confid];
        } else {
            $q .= ", coalesce(Conferences.confid,-1) cdb_confid from ContactInfo left join Conferences on (Conferences.confuid=?) left join Roles on (Roles.contactDbId=ContactInfo.contactDbId and Roles.confid=Conferences.confid)";
            $qv = [$this->cdb_confuid()];
        }
        $q .= " where ";
        if (!empty($ids)) {
            $q .= "ContactInfo.contactDbId?a";
            $qv[] = $ids;
        }
        if (!empty($emails)) {
            $q .= (empty($ids) ? "" : " or ") . "email?a";
            $qv[] = $emails;
        }
        $result = Dbl::qe($cdb, $q, ...$qv);
        $us = [];
        while (($u = Contact::fetch($result, $this))) {
            if ($confid <= 0 && $u->cdb_confid > 0) {
                $confid = $this->opt["contactdbConfid"] = $u->cdb_confid;
            }
            $us[] = $u;
        }
        Dbl::free($result);
        return $us;
    }

    private function _refresh_cdb_user_cache() {
        $reqids = $reqemails = [];
        $this->_cdb_user_cache = $this->_cdb_user_cache ?? [];
        foreach ($this->_cdb_user_cache_missing as $req) {
            $req = self::clean_user_cache_request($req);
            if ($req !== null
                && !array_key_exists($req, $this->_cdb_user_cache)) {
                $this->_cdb_user_cache[$req] = null;
                if (is_int($req)) {
                    $reqids[] = $req;
                } else {
                    $reqemails[] = $req;
                }
            }
        }
        $this->_cdb_user_cache_missing = null;
        foreach ($this->_fresh_cdb_user_list($reqids, $reqemails) as $u) {
            $this->_cdb_user_cache[$u->contactDbId] = $u;
            $this->_cdb_user_cache[strtolower($u->email)] = $u;
        }
    }

    /** @param int $id */
    function prefetch_cdb_user_by_id($id) {
        if (!array_key_exists($id, $this->_cdb_user_cache ?? [])) {
            $this->_cdb_user_cache_missing[] = $id;
        }
    }

    /** @param string $email
     * @return bool */
    function prefetch_cdb_user_by_email($email) {
        if (!array_key_exists($email, $this->_cdb_user_cache ?? [])) {
            $this->_cdb_user_cache_missing[] = $email;
            return true;
        } else {
            return false;
        }
    }

    /** @param iterable<string> $emails */
    function prefetch_cdb_users_by_email($emails) {
        $cuc = $this->_cdb_user_cache ?? [];
        foreach ($emails as $email) {
            if (!array_key_exists($email, $cuc)) {
                $this->_cdb_user_cache_missing[] = $email;
            }
        }
    }

    /** @param int $id
     * @return ?Contact */
    function cdb_user_by_id($id) {
        if ($id <= 0) {
            return null;
        }
        if (!array_key_exists($id, $this->_cdb_user_cache ?? [])) {
            $this->_cdb_user_cache_missing[] = $id;
            $this->_refresh_cdb_user_cache();
        }
        return $this->_cdb_user_cache[$id] ?? null;
    }

    /** @param string $email
     * @return ?Contact */
    function cdb_user_by_email($email) {
        if ($email === "" || Contact::is_anonymous_email($email)) {
            return null;
        }
        $lemail = strtolower($email);
        if (!array_key_exists($lemail, $this->_cdb_user_cache ?? [])) {
            $this->_cdb_user_cache_missing[] = $lemail;
            $this->_refresh_cdb_user_cache();
        }
        return $this->_cdb_user_cache[$lemail] ?? null;
    }

    /** @param string $email
     * @return ?Contact */
    function fresh_cdb_user_by_email($email) {
        if ($email !== "" && is_valid_utf8($email)) {
            $us = $this->_fresh_cdb_user_list(null, [$email]);
            return $us[0] ?? null;
        } else {
            return null;
        }
    }

    /** @param string $email
     * @return Contact */
    function checked_cdb_user_by_email($email) {
        $acct = $this->fresh_cdb_user_by_email($email);
        if (!$acct) {
            throw new Exception("Contact::checked_cdb_user_by_email({$email}) failed");
        }
        return $acct;
    }


    /** @param string $name
     * @param string $existsq
     * @param int $adding */
    private function update_setting_exists($name, $existsq, $adding) {
        if ($adding >= 0) {
            $this->qe_raw("insert into Settings (name, value) select '{$name}', 1 from dual where {$existsq} on duplicate key update value=1");
        }
        if ($adding <= 0) {
            $this->qe_raw("delete from Settings where name='{$name}' and not ({$existsq})");
        }
        $this->settings[$name] = (int) $this->fetch_ivalue("select value from Settings where name='{$name}'");
    }

    // update the 'papersub' setting: are there any submitted papers?
    /** @param int $adding */
    function update_papersub_setting($adding) {
        if (($this->setting("no_papersub") ?? 0) <= 0 ? $adding <= 0 : $adding >= 0) {
            $this->update_setting_exists("no_papersub", "not exists (select * from Paper where timeSubmitted>0)", -$adding);
        }
    }

    /** @param int $adding */
    function update_paperacc_setting($adding) {
        if (($this->setting("paperacc") ?? 0) <= 0 ? $adding >= 0 : $adding <= 0) {
            $this->update_setting_exists("paperacc", "exists (select * from Paper where outcome>0 and timeSubmitted>0)", $adding);
        }
    }

    /** @param int $adding */
    function update_rev_tokens_setting($adding) {
        if (($this->setting("rev_tokens") ?? 0) === -1) {
            $adding = 0;
        }
        if (($this->setting("rev_tokens") ?? 0) <= 0 ? $adding >= 0 : $adding <= 0) {
            $this->update_setting_exists("rev_tokens", "exists (select * from PaperReview where reviewToken!=0)", $adding);
        }
    }

    /** @param int $adding */
    function update_paperlead_setting($adding) {
        if (($this->setting("paperlead") ?? 0) <= 0 ? $adding >= 0 : $adding <= 0) {
            $this->update_setting_exists("paperlead", "exists (select * from Paper where leadContactId>0 or shepherdContactId>0)", $adding);
        }
    }

    /** @param int $adding */
    function update_papermanager_setting($adding) {
        if (($this->setting("papermanager") ?? 0) <= 0 ? $adding >= 0 : $adding <= 0) {
            $this->update_setting_exists("papermanager", "exists (select * from Paper where managerContactId>0)", $adding);
        }
    }

    /** @param int $adding */
    function update_metareviews_setting($adding) {
        if (($this->setting("metareviews") ?? 0) <= 0 ? $adding >= 0 : $adding <= 0) {
            $this->update_setting_exists("metareviews", "exists (select * from PaperReview where reviewType=" . REVIEW_META . ")", $adding);
        }
    }

    /** @param null|int|list<int>|PaperInfo $paper
     * @param null|string|list<string> $types */
    function update_automatic_tags($paper = null, $types = null) {
        if (!$this->_maybe_automatic_tags || $this->_updating_automatic_tags) {
            return;
        }
        $csv = ["paper,tag,tag value"];
        if ($paper === null) {
            foreach ($this->tags()->entries_having(TagInfo::TF_AUTOMATIC) as $dt) {
                $csv[] = CsvGenerator::quote("#{$dt->tag}") . "," . CsvGenerator::quote($dt->tag) . ",clear";
                $csv[] = CsvGenerator::quote("searchcontrol:expand_automatic " . $dt->automatic_search()) . "," . CsvGenerator::quote($dt->tag) . "," . CsvGenerator::quote($dt->automatic_formula_expression());
            }
        } else if (!empty($paper)) {
            if (is_int($paper)) {
                $pids = [$paper];
            } else if (is_object($paper)) {
                $pids = [$paper->paperId];
            } else {
                $pids = $paper;
            }
            $qopt = [];
            foreach ($this->tags()->entries_having(TagInfo::TF_AUTOMATIC) as $dt) {
                $dt->automatic_search_term()->paper_requirements($qopt);
            }
            $qopt["paperId"] = $pids;
            $rowset = $this->paper_set($qopt);
            foreach ($this->tags()->entries_having(TagInfo::TF_AUTOMATIC) as $dt) {
                $fexpr = $dt->automatic_formula_expression();
                foreach ($rowset as $prow) {
                    $test = $dt->automatic_search_term()->test($prow, null);
                    $value = $prow->tag_value($dt->tag);
                    if ($test
                        ? $fexpr !== "0" || $value !== 0.0
                        : $value !== null) {
                        $csv[] = "{$prow->paperId}," . CsvGenerator::quote($dt->tag) . "," . ($test ? CsvGenerator::quote($fexpr) : "clear");
                    }
                }
            }
        }
        $this->_update_automatic_tags_csv($csv);
    }

    /** @param list<string> $csv */
    function _update_automatic_tags_csv($csv) {
        if (count($csv) > 1) {
            $this->_updating_automatic_tags = true;
            $aset = new AssignmentSet($this->root_user());
            $aset->set_override_conflicts(true);
            $aset->set_search_type("all");
            $aset->parse($csv);
            $aset->execute();
            $this->_updating_automatic_tags = false;
        }
    }

    /** @return bool */
    function is_updating_automatic_tags() {
        return $this->_updating_automatic_tags;
    }


    /** @param int $n
     * @return bool */
    function update_schema_version($n) {
        if (!$n) {
            $n = $this->fetch_ivalue("select value from Settings where name='allowPaperOption'");
        }
        if ($n && $this->ql_ok("update Settings set value=? where name='allowPaperOption'", $n)) {
            $this->sversion = $this->settings["allowPaperOption"] = $n;
            return true;
        } else {
            return false;
        }
    }

    /** @param array<string,true> $caches */
    function invalidate_caches($caches) {
        if (!self::$no_invalidate_caches) {
            if (!$caches || isset($caches["pc"]) || isset($caches["users"])) {
                $this->_pc_set = null;
                $this->_pc_members_cache = $this->_pc_tags_cache = null;
                $this->_user_cache = $this->_user_email_cache = null;
            }
            if (!$caches || isset($caches["users"]) || isset($caches["cdb"])) {
                $this->_cdb_user_cache = null;
            }
            if (isset($caches["cdb"])) {
                unset($this->opt["contactdbConfid"]);
                self::$_cdb = false;
            }
            // NB All setting-related caches cleared here should also be cleared
            // in refresh_settings().
            if (!$caches || isset($caches["options"])) {
                $this->_paper_opts->invalidate_options();
                $this->_formatspec_cache = [];
                $this->_abbrev_matcher = null;
            }
            if (!$caches || isset($caches["rf"])) {
                $this->_review_form = null;
                $this->_defined_rounds = null;
                $this->_abbrev_matcher = null;
            }
            if (!$caches || isset($caches["tags"]) || isset($caches["tracks"])) {
                $this->_tag_map = null;
            }
            if (!$caches || isset($caches["formulas"])) {
                $this->_formula_functions = null;
            }
            if (!$caches || isset($caches["assigners"])) {
                $this->_assignment_parsers = null;
            }
            if (!$caches || isset($caches["topics"])) {
                $this->invalidate_topics();
            }
            if (!$caches || isset($caches["tracks"])) {
                Contact::update_rights();
            }
            if (isset($caches["autosearch"])) {
                $this->update_automatic_tags();
            }
        }
    }


    // times

    /** @return DateTimeZone */
    function timezone() {
        if ($this->_dtz === null) {
            $this->_dtz = timezone_open($this->opt["timezone"] ?? date_default_timezone_get());
        }
        return $this->_dtz;
    }

    /** @param int|float $t
     * @param string $format
     * @return string */
    function format_time($t, $format) {
        if ($this !== self::$main && !$this->_dtz && isset($this->opt["timezone"])) {
            $this->timezone();
        }
        if ($this->_dtz) {
            $dt = new DateTime("@" . (int) $t);
            $dt->setTimeZone($this->_dtz);
            return $dt->format($format);
        } else {
            return date($format, $t);
        }
    }

    /** @param string $type
     * @param int|float $t
     * @return string */
    private function _date_unparse($type, $t) {
        if (!$this->_date_format_initialized) {
            if (!isset($this->opt["time24hour"]) && isset($this->opt["time24Hour"])) {
                $this->opt["time24hour"] = $this->opt["time24Hour"];
            }
            if (!isset($this->opt["dateFormatLong"]) && isset($this->opt["dateFormat"])) {
                $this->opt["dateFormatLong"] = $this->opt["dateFormat"];
            }
            if (!isset($this->opt["dateFormat"])) {
                $this->opt["dateFormat"] = ($this->opt["time24hour"] ?? false) ? "M j, Y, H:i:s" : "M j, Y, g:i:s A";
            }
            if (!isset($this->opt["dateFormatLong"])) {
                $this->opt["dateFormatLong"] = "l " . $this->opt["dateFormat"];
            }
            if (!isset($this->opt["dateFormatObscure"])) {
                $this->opt["dateFormatObscure"] = "M j, Y";
            }
            if (!isset($this->opt["timestampFormat"])) {
                $this->opt["timestampFormat"] = $this->opt["dateFormat"];
            }
            if (!isset($this->opt["dateFormatSimplifier"])) {
                $this->opt["dateFormatSimplifier"] = ($this->opt["time24hour"] ?? false) ? "/:00(?!:)/" : "/:00(?::00|)(?= ?[AaPp][Mm])/";
            }
            $this->_date_format_initialized = true;
        }
        if ($type === "timestamp") {
            $f = $this->opt["timestampFormat"];
        } else if ($type === "obscure") {
            $f = $this->opt["dateFormatObscure"];
        } else if ($type === "long") {
            $f = $this->opt["dateFormatLong"];
        } else if ($type === "zone") {
            $f = "T";
        } else {
            $f = $this->opt["dateFormat"];
        }
        return $this->format_time($t, $f);
    }

    /** @param int|float $value
     * @return string */
    private function _unparse_timezone($value) {
        $z = $this->opt["dateFormatTimezone"] ?? null;
        if ($z === null) {
            $z = $this->_date_unparse("zone", $value);
            if ($z === "-12") {
                $z = "AoE";
            } else if ($z && ($z[0] === "+" || $z[0] === "-")) {
                $z = "UTC" . $z;
            }
        }
        return $z;
    }

    /** @param int $value
     * @param bool $include_zone
     * @return string */
    function parseableTime($value, $include_zone) {
        $d = $this->_date_unparse("short", $value);
        if ($this->opt["dateFormatSimplifier"]) {
            $d = preg_replace($this->opt["dateFormatSimplifier"], "", $d);
        }
        if ($include_zone && ($z = $this->_unparse_timezone($value))) {
            $d .= " $z";
        }
        return $d;
    }

    /** @param string $d
     * @param ?int $reference
     * @return int|float|false */
    function parse_time($d, $reference = null) {
        $reference = $reference ?? Conf::$now;
        if (!isset($this->opt["dateFormatTimezoneRemover"])) {
            $x = [];
            if (function_exists("timezone_abbreviations_list")) {
                $mytz = date_default_timezone_get();
                foreach (timezone_abbreviations_list() as $tzname => $tzinfo) {
                    foreach ($tzinfo as $tz) {
                        if ($tz["timezone_id"] == $mytz) {
                            $x[] = preg_quote($tzname);
                        }
                    }
                }
            }
            if (empty($x)) {
                $x[] = preg_quote($this->_unparse_timezone($reference));
            }
            $this->opt["dateFormatTimezoneRemover"] =
                "/(?:\\s|\\A)(?:" . join("|", $x) . ")(?:\\s|\\z)/i";
        }
        if ($this->opt["dateFormatTimezoneRemover"]) {
            $d = preg_replace($this->opt["dateFormatTimezoneRemover"], " ", $d);
        }
        if (preg_match('/\A(.*)\b(utc(?=[-+])|aoe(?=\s|\z))(.*)\z/i', $d, $m)) {
            if (strcasecmp($m[2], "aoe") === 0) {
                $d = strtotime($m[1] . "GMT-1200" . $m[3], $reference);
                if ($d !== false
                    && $d % 86400 == 43200
                    && ($dx = strtotime($m[1] . " T23:59:59 GMT-1200" . $m[3], $reference)) === $d + 86399) {
                    return $dx;
                } else {
                    return $d;
                }
            } else {
                return strtotime($m[1] . "GMT" . $m[3], $reference);
            }
        } else {
            return strtotime($d, $reference);
        }
    }

    // NB must return HTML-safe plaintext
    /** @param int $timestamp
     * @return string */
    private function _unparse_time($timestamp, $type) {
        if ($timestamp <= 0) {
            return "N/A";
        }
        $t = $this->_date_unparse($type, $timestamp);
        if ($this->opt["dateFormatSimplifier"]) {
            $t = preg_replace($this->opt["dateFormatSimplifier"], "", $t);
        }
        if ($type !== "obscure" && ($z = $this->_unparse_timezone($timestamp))) {
            $t .= " {$z}";
        }
        return $t;
    }

    /** @param int|float|null $timestamp
     * @return ?int */
    function obscure_time($timestamp) {
        if ($timestamp !== null) {
            $timestamp = (int) ($timestamp + 0.5);
        }
        if ($timestamp > 0) {
            $offset = 0;
            if (($zone = $this->timezone())) {
                $offset = $zone->getOffset(new DateTime("@{$timestamp}"));
            }
            $timestamp += 43200 - ($timestamp + $offset) % 86400;
        }
        return $timestamp;
    }

    /** @param int $timestamp
     * @return string */
    function unparse_time_long($timestamp) {
        return $this->_unparse_time($timestamp, "long");
    }

    /** @param int $timestamp
     * @param string $suffix
     * @return string */
    function unparse_time_with_local_span($timestamp, $suffix = "") {
        $s = $this->_unparse_time($timestamp, "long");
        return "<span class=\"need-usertime\" data-ts=\"{$timestamp}\">{$s}{$suffix}</span>";
    }

    /** @param int $timestamp
     * @return string */
    function unparse_time($timestamp) {
        return $this->_unparse_time($timestamp, "timestamp");
    }

    /** @param int $timestamp
     * @return string */
    function unparse_time_obscure($timestamp) {
        return $this->_unparse_time($timestamp, "obscure");
    }

    /** @param int $timestamp
     * @return string */
    function unparse_time_point($timestamp) {
        return $this->format_time($timestamp, "M j, Y");
    }

    /** @param int $timestamp
     * @return string */
    function unparse_time_log($timestamp) {
        return $this->format_time($timestamp, "Y-m-d H:i:s O");
    }

    /** @param int $timestamp
     * @return string */
    function unparse_time_iso($timestamp) {
        return $this->format_time($timestamp, "Ymd\\THis");
    }

    /** @param int $timestamp
     * @param int $now
     * @return string */
    function unparse_time_relative($timestamp, $now = 0, $format = 0) {
        $d = abs($timestamp - ($now ? : Conf::$now));
        if ($d >= 5227200) {
            if (!($format & 1)) {
                return ($format & 8 ? "on " : "") . $this->_date_unparse("obscure", $timestamp);
            }
            $unit = 5;
        } else if ($d >= 259200) {
            $unit = 4;
        } else if ($d >= 28800) {
            $unit = 3;
        } else if ($d >= 3630) {
            $unit = 2;
        } else if ($d >= 180.5) {
            $unit = 1;
        } else if ($d >= 1) {
            $unit = 0;
        } else {
            return "now";
        }
        $units = [1, 60, 1800, 3600, 86400, 604800];
        $x = $units[$unit];
        $d = ceil(($d - $x / 2) / $x);
        if ($unit === 2) {
            $d /= 2;
        }
        if ($format & 4) {
            $d .= substr("smhhdw", $unit, 1);
        } else {
            $unit_names = ["second", "minute", "hour", "hour", "day", "week"];
            $d .= " " . $unit_names[$unit] . ($d == 1 ? "" : "s");
        }
        if ($format & 2) {
            return $d;
        } else {
            return $timestamp < ($now ? : Conf::$now) ? $d . " ago" : "in " . $d;
        }
    }

    /** @param string $lo
     * @param ?int $time
     * @return bool */
    function time_after_setting($lo, $time = null) {
        $time = $time ?? Conf::$now;
        $t = $this->settings[$lo] ?? 0;
        return $t > 0 && $time >= $t;
    }

    /** @param ?int $lo
     * @param int $hi
     * @param ?int $grace
     * @param ?int $time
     * @return 0|1|2 */
    function time_between($lo, $hi, $grace = null, $time = null) {
        // see also ResponseRound::time_allowed
        $time = $time ?? Conf::$now;
        if ($lo !== null && ($lo <= 0 || $time < $lo)) {
            return 0;
        } else if ($hi <= 0 || $time <= $hi) {
            return 1;
        } else if ($grace !== null && $time <= $hi + $grace) {
            return 2;
        } else {
            return 0;
        }
    }

    /** @param string $lo
     * @param string $hi
     * @param ?string $grace
     * @param ?int $time
     * @return 0|1|2 */
    function time_between_settings($lo, $hi, $grace = null, $time = null) {
        // see also ResponseRound::time_allowed
        $time = $time ?? Conf::$now;
        $t0 = $this->settings[$lo] ?? null;
        if (($t0 === null || $t0 <= 0 || $time < $t0) && $lo !== "") {
            return 0;
        }
        $t1 = $this->settings[$hi] ?? null;
        if ($t1 === null || $t1 <= 0 || $time <= $t1) {
            return 1;
        } else if ($grace && $time <= $t1 + ($this->settings[$grace] ?? 0)) {
            return 2;
        } else {
            return 0;
        }
    }


    /** @return bool */
    function has_named_submission_rounds() {
        return isset($this->settingTexts["submission_rounds"]);
    }

    /** @return SubmissionRound */
    function unnamed_submission_round() {
        if (!$this->_main_sub_round) {
            $this->_main_sub_round = SubmissionRound::make_main($this);
        }
        return $this->_main_sub_round;
    }

    /** @return list<SubmissionRound> */
    function submission_round_list() {
        if ($this->_sub_rounds === null) {
            $this->_sub_rounds = [];
            $main_sr = $this->unnamed_submission_round();
            if (($t = $this->settingTexts["submission_rounds"] ?? null)
                && ($j = json_decode($t))
                && is_array($j)) {
                foreach ($j as $jx) {
                    if (($sr = SubmissionRound::make_json($jx, $main_sr, $this)))
                        $this->_sub_rounds[] = $sr;
                }
            }
            $this->_sub_rounds[] = $main_sr;
        }
        return $this->_sub_rounds;
    }

    /** @param ?string $t
     * @return ?SubmissionRound */
    function submission_round_by_tag($t) {
        if ($t === null || $t === "") {
            return $this->unnamed_submission_round();
        }
        foreach ($this->submission_round_list() as $sr) {
            if (strcasecmp($t, $sr->tag) === 0)
                return $sr;
        }
        if (strcasecmp($t, "unnamed") === 0
            || strcasecmp($t, "none") === 0) {
            return $this->unnamed_submission_round();
        }
        return null;
    }

    /** @return bool */
    function allow_final_versions() {
        return $this->setting("final_open") > 0;
    }
    /** @return bool */
    function time_edit_final_paper() {
        return $this->time_between_settings("final_open", "final_done", "final_grace") > 0;
    }
    /** @return bool */
    function time_some_author_view_review() {
        return $this->any_response_open || $this->_au_seerev;
    }
    /** @return bool */
    function time_some_author_view_decision() {
        return $this->_au_seedec !== null;
    }
    /** @return bool */
    function time_all_author_view_decision() {
        return $this->_au_seedec instanceof True_SearchTerm;
    }
    /** @return bool */
    function time_review_open() {
        return $this->rev_open;
    }
    /** @param ?int $round
     * @param bool|int $reviewType
     * @param bool $hard
     * @return string */
    function review_deadline_name($round, $reviewType, $hard) {
        $isPC = is_bool($reviewType) ? $reviewType : $reviewType >= REVIEW_PC;
        if ($round === null) {
            $round = $this->assignment_round(!$isPC);
        } else if (is_object($round)) { /* XXX backward compat */
            $round = $round->reviewRound ? : 0;
        }
        return ($isPC ? "pcrev_" : "extrev_") . ($hard ? "hard" : "soft")
            . ($round ? "_{$round}" : "");
    }
    /** @param ?int $round
     * @param bool|int $reviewType
     * @param bool $hard
     * @return string|false */
    function missed_review_deadline($round, $reviewType, $hard) {
        if (!$this->time_review_open()) {
            return "rev_open";
        }
        $dn = $this->review_deadline_name($round, $reviewType, $hard);
        $dv = $this->settings[$dn] ?? 0;
        if ($dv > 0 && $dv < Conf::$now) {
            return $dn;
        }
        return false;
    }
    /** @param ?int $round
     * @param bool|int $reviewType
     * @param bool $hard
     * @return bool */
    function time_review($round, $reviewType, $hard) {
        return !$this->missed_review_deadline($round, $reviewType, $hard);
    }
    /** @return bool */
    function timePCReviewPreferences() {
        return $this->can_pc_view_some_incomplete()
            || $this->has_any_submitted();
    }
    /** @param bool $pdf
     * @return bool */
    function time_pc_view(PaperInfo $prow, $pdf) {
        if ($prow->timeSubmitted > 0) {
            return !$pdf
                || ($this->permbits & self::PB_ALL_PDF_VIEWABLE) !== 0
                || (($sr = $prow->submission_round())
                    && ($sr->pdf_viewable
                        || $prow->timeSubmitted < $sr->open));
        } else if ($prow->timeWithdrawn <= 0) {
            return !$pdf
                && ($this->permbits & self::PB_SOME_INCOMPLETE_VIEWABLE) !== 0
                && $prow->submission_round()->incomplete_viewable;
        } else {
            return false;
        }
    }
    /** @param bool $pc
     * @return bool */
    function time_some_reviewer_view_authors($pc) {
        return $this->submission_blindness() !== self::BLIND_ALWAYS
            || (($pc || ($this->setting("viewrev_ext") ?? 0) >= 0)
                && $this->has_any_accepted()
                && $this->time_some_author_view_decision()
                && !$this->setting("seedec_hideau"));
    }

    /** @return int */
    function submission_blindness() {
        return $this->settings["sub_blind"];
    }
    /** @return bool */
    function subBlindAlways() {
        return $this->settings["sub_blind"] === self::BLIND_ALWAYS;
    }

    /** @param ?bool $rrow_blind
     * @return bool */
    function is_review_blind($rrow_blind) {
        $rb = $this->settings["rev_blind"];
        if ($rb === self::BLIND_NEVER) {
            return false;
        } else if ($rb !== self::BLIND_OPTIONAL) {
            return true;
        } else {
            return $rrow_blind !== false;
        }
    }
    /** @return int */
    function review_blindness() {
        return $this->settings["rev_blind"];
    }
    /** @return bool */
    function time_some_external_reviewer_view_comment() {
        return ($this->settings["viewrev_ext"] ?? 0) >= 0
            && (($this->settings["cmt_revid"] ?? 0) > 0
                || ($this->settings["viewrevid_ext"] ?? 0) >= 0);
    }

    /** @return bool */
    function has_any_submitted() {
        return !($this->settings["no_papersub"] ?? false);
    }
    /** @return bool */
    function has_any_accepted() {
        return !!($this->settings["paperacc"] ?? false);
    }

    /** @return array{int,int} */
    function count_submitted_accepted() {
        $dlt = $this->setting("sub_sub");
        $result = $this->qe("select outcome, count(paperId) from Paper where timeSubmitted>0 " . ($dlt ? "or (timeSubmitted=-100 and timeWithdrawn>=$dlt) " : "") . "group by outcome");
        $n = $nyes = 0;
        while (($row = $result->fetch_row())) {
            $n += $row[1];
            if ($row[0] > 0) {
                $nyes += $row[1];
            }
        }
        Dbl::free($result);
        return [$n, $nyes];
    }

    /** @return bool */
    function has_any_lead_or_shepherd() {
        return !!($this->settings["paperlead"] ?? false);
    }

    /** @return bool */
    function has_any_manager() {
        return ($this->_track_sensitivity & Track::BITS_ADMIN)
            || !!($this->settings["papermanager"] ?? false);
    }

    /** @return bool */
    function has_any_explicit_manager() {
        return !!($this->settings["papermanager"] ?? false);
    }

    /** @return bool */
    function has_any_metareviews() {
        return !!($this->settings["metareviews"] ?? false);
    }

    /** @return bool */
    function can_pc_view_some_incomplete() {
        return ($this->permbits & self::PB_SOME_INCOMPLETE_VIEWABLE) !== 0;
    }

    /** @return bool */
    function can_pc_view_all_incomplete() {
        return ($this->permbits & self::PB_ALL_INCOMPLETE_VIEWABLE) !== 0;
    }


    /** @param NavigationState $nav
     * @param string $url */
    function set_site_path_relative($nav, $url) {
        if ($nav->site_path_relative !== $url) {
            $old_baseurl = $nav->base_path_relative;
            $nav->set_site_path_relative($url);
            if ($this->_assets_url === $old_baseurl) {
                $this->_assets_url = $nav->base_path_relative;
                Ht::$img_base = $this->_assets_url . "images/";
            }
            if ($this->_script_assets_site) {
                $this->_script_assets_url = $nav->base_path_relative;
            }
        }
    }

    const HOTURL_RAW = 1;
    const HOTURL_POST = 2;
    const HOTURL_ABSOLUTE = 4;
    const HOTURL_SITEREL = 8;
    const HOTURL_SITE_RELATIVE = 8;
    const HOTURL_SERVERREL = 16;
    const HOTURL_NO_DEFAULTS = 32;
    const HOTURL_REDIRECTABLE = 64;
    const HOTURL_MAYBE_POST = 128;

    /** @param string $page
     * @param null|string|array $params
     * @param int $flags
     * @return string */
    function hoturl($page, $params = null, $flags = 0) {
        $qreq = Qrequest::$main_request;
        $amp = ($flags & self::HOTURL_RAW ? "&" : "&amp;");
        if (str_starts_with($page, "=")) {
            if ($page[1] === "?") {
                $flags |= self::HOTURL_MAYBE_POST;
                $page = substr($page, 2);
            } else {
                $page = substr($page, 1);
            }
            $flags |= self::HOTURL_POST;
        }
        $t = $page;
        $are = '/\A(|.*?(?:&|&amp;))';
        $zre = '(?:&(?:amp;)?|\z)(.*)\z/';
        // parse options, separate anchor
        $anchor = "";
        $defaults = [];
        if (($flags & self::HOTURL_NO_DEFAULTS) === 0
            && $qreq
            && $qreq->user()) {
            $defaults = $qreq->user()->hoturl_defaults();
        }
        if (is_array($params)) {
            $param = $sep = "";
            foreach ($params as $k => $v) {
                if ($v === null || $v === false) {
                    continue;
                }
                $v = urlencode($v);
                if ($k === "anchor" /* XXX deprecated */ || $k === "#") {
                    $anchor = "#{$v}";
                } else {
                    $param .= "{$sep}{$k}={$v}";
                    $sep = $amp;
                }
            }
            foreach ($defaults as $k => $v) {
                if (!array_key_exists($k, $params)) {
                    $param .= "{$sep}{$k}={$v}";
                    $sep = $amp;
                }
            }
        } else {
            $param = (string) $params;
            if (($pos = strpos($param, "#"))) {
                $anchor = substr($param, $pos);
                $param = substr($param, 0, $pos);
            }
            $sep = $param === "" ? "" : $amp;
            foreach ($defaults as $k => $v) {
                if (!preg_match($are . preg_quote($k) . '=/', $param)) {
                    $param .= "{$sep}{$k}={$v}";
                    $sep = $amp;
                }
            }
        }
        if (($flags & (self::HOTURL_POST | self::HOTURL_MAYBE_POST)) !== 0) {
            if (($flags & self::HOTURL_MAYBE_POST) !== 0) {
                $post = $qreq->maybe_post_value();
            } else {
                $post = $qreq->post_value();
            }
            $param .= "{$sep}post={$post}";
            $sep = $amp;
        }
        // append forceShow to links to same paper if appropriate
        $is_paper_page = preg_match('/\A(?:paper|review|comment|assign)\z/', $page);
        if ($is_paper_page
            && $this->paper
            && Contact::$main_user
            && Contact::$main_user->conf === $this
            && Contact::$main_user->can_administer($this->paper)
            && $this->paper->has_conflict(Contact::$main_user)
            && preg_match("{$are}p={$this->paper->paperId}{$zre}", $param)
            && (is_array($params) ? !array_key_exists("forceShow", $params) : !preg_match($are . 'forceShow=/', $param))) {
            $param .= $amp . "forceShow=1";
        }
        // create slash-based URLs if appropriate
        if ($param) {
            if ($page === "review"
                && preg_match($are . 'p=(\d+)' . $zre, $param, $m)) {
                $tp = "/" . $m[2];
                $param = $m[1] . $m[3];
                if (preg_match($are . 'r=(\d+)([A-Z]+|r\d+|rnew)' . $zre, $param, $mm)
                    && $mm[2] === $m[2]) {
                    $tp .= $mm[3];
                    $param = $mm[1] . $mm[4];
                }
            } else if (($is_paper_page
                        && preg_match($are . 'p=(\d+|%\w+%|new)' . $zre, $param, $m))
                       || ($page === "help"
                           && preg_match($are . 't=(\w+)' . $zre, $param, $m))
                       || (($page === "settings" || $page === "graph")
                           && preg_match($are . 'group=(\w+)' . $zre, $param, $m))) {
                $tp = "/" . $m[2];
                $param = $m[1] . $m[3];
                if ($param !== ""
                    && $page === "paper"
                    && preg_match($are . 'm=(\w+)' . $zre, $param, $m)) {
                    $tp .= "/" . $m[2];
                    $param = $m[1] . $m[3];
                }
            } else if ($page === "doc"
                       && preg_match($are . 'file=([^&]+)' . $zre, $param, $m)) {
                $tp = "/" . str_replace("%2F", "/", $m[2]);
                $param = $m[1] . $m[3];
            } else if ($page === "profile"
                       && preg_match($are . 'u=([^&?]+)' . $zre, $param, $m)) {
                $tp = "/" . str_replace("%2F", "/", $m[2]);
                $param = $m[1] . $m[3];
                if ($param !== ""
                    && preg_match($are . 't=(\w+)' . $zre, $param, $m)) {
                    $tp .= "/" . $m[2];
                    $param = $m[1] . $m[3];
                }
            } else if ($page === "profile"
                       && preg_match($are . 't=(\w+)' . $zre, $param, $m)) {
                $tp = "/" . $m[2];
                $param = $m[1] . $m[3];
            } else if ($page === "api"
                       && preg_match($are . 'fn=(\w+)' . $zre, $param, $m)) {
                $tp = "/" . $m[2];
                $param = $m[1] . $m[3];
                if (preg_match($are . 'p=(\d+)' . $zre, $param, $m)) {
                    $tp = "/" . $m[2] . $tp;
                    $param = $m[1] . $m[3];
                }
            } else if ($page === "users"
                       && preg_match($are . 't=(\w+)' . $zre, $param, $m)) {
                $tp = "/" . $m[2];
                $param = $m[1] . $m[3];
            } else if (preg_match($are . '__PATH__=([^&]+)' . $zre, $param, $m)) {
                $tp = "/" . urldecode($m[2]);
                $param = $m[1] . $m[3];
            } else {
                $tp = "";
            }
            if ($tp !== "") {
                $t .= $tp;
                if (preg_match($are . '__PATH__=([^&]+)' . $zre, $param, $m)
                    && $tp === "/" . urldecode($m[2])) {
                    $param = $m[1] . $m[3];
                }
            }
            $param = preg_replace('/&(?:amp;)?\z/', "", $param);
        }
        $nav = $qreq ? $qreq->navigation() : Navigation::get();
        if ($nav->php_suffix !== "") {
            if (($slash = strpos($t, "/")) !== false) {
                $a = substr($t, 0, $slash);
                $b = substr($t, $slash);
            } else {
                $a = $t;
                $b = "";
            }
            if (!str_ends_with($a, $nav->php_suffix)) {
                $a .= $nav->php_suffix;
            }
            $t = $a . $b;
        }
        if ($param !== "" && preg_match('/\A&(?:amp;)?(.*)\z/', $param, $m)) {
            $param = $m[1];
        }
        if ($param !== "") {
            $t .= "?" . $param;
        }
        if ($anchor !== "") {
            $t .= $anchor;
        }
        if ($flags & self::HOTURL_SITEREL) {
            return $t;
        } else if ($flags & self::HOTURL_SERVERREL) {
            return $nav->site_path . $t;
        }
        $need_site_path = false;
        if ($page === "index") {
            $expect = "index" . $nav->php_suffix;
            $lexpect = strlen($expect);
            if (str_starts_with($t, $expect)
                && ($t === $expect || $t[$lexpect] === "?" || $t[$lexpect] === "#")) {
                $need_site_path = true;
                $t = substr($t, $lexpect);
            }
        }
        if (($flags & self::HOTURL_ABSOLUTE) || $this !== Conf::$main) {
            return $this->opt("paperSite") . "/" . $t;
        } else {
            $siteurl = $nav->site_path_relative;
            if ($need_site_path && $siteurl === "") {
                $siteurl = $nav->site_path;
            }
            return $siteurl . $t;
        }
    }

    /** @param string $page
     * @param null|string|array $param
     * @param int $flags
     * @return string */
    function hoturl_raw($page, $param = null, $flags = 0) {
        return $this->hoturl($page, $param, self::HOTURL_RAW | $flags);
    }

    /** @param string $page
     * @param null|string|array $param
     * @return string */
    function hoturl_post($page, $param = null) {
        return $this->hoturl($page, $param, self::HOTURL_POST);
    }

    /** @param string $html
     * @param string $page
     * @param null|string|array $param
     * @param ?array $js
     * @return string */
    function hotlink($html, $page, $param = null, $js = null) {
        return Ht::link($html, $this->hoturl($page, $param), $js);
    }


    static $selfurl_safe = [
        "p" => true, "paperId" => "p", "pap" => "p",
        "r" => true, "reviewId" => "r",
        "c" => true, "commentId" => "c",
        "m" => true, "mode" => true, "u" => true, "g" => true,
        "q" => true, "t" => true, "qa" => true, "qo" => true, "qx" => true, "qt" => true,
        "fx" => true, "fy" => true,
        "forceShow" => true, "tab" => true, "atab" => true, "sort" => true,
        "group" => true, "monreq" => true, "noedit" => true,
        "contact" => true, "reviewer" => true,
        "editcomment" => true
    ];

    /** @param Qrequest $qreq
     * @param array $param
     * @param int $flags
     * @return string */
    private function qrequrl($qreq, $param, $flags) {
        if (($flags & self::HOTURL_REDIRECTABLE) !== 0
            && ($url = $this->qreq_redirect_url($qreq))) {
            if (($flags & self::HOTURL_RAW) === 0) {
                $url = htmlspecialchars($url);
            }
            return $url;
        }
        $x = [];
        foreach ($qreq as $k => $v) {
            $ak = self::$selfurl_safe[$k] ?? false;
            if ($ak === true) {
                $ak = $k;
            }
            if ($ak
                && ($ak === $k || !isset($qreq[$ak]))
                && !array_key_exists($ak, $param)) {
                $x[$ak] = $v;
            }
        }
        foreach ($param as $k => $v) {
            $x[$k] = $v;
        }
        return $this->hoturl($qreq->page(), $x, $flags);
    }

    /** @param Qrequest $qreq
     * @param ?array $param
     * @param int $flags
     * @return string */
    function selfurl(Qrequest $qreq, $param = null, $flags = 0) {
        if (!$qreq->page() || $qreq->page() === "api") {
            error_log("selfurl for bad page: " . debug_string_backtrace());
        }
        return $this->qrequrl($qreq, $param ?? [], $flags);
    }

    /** @param Qrequest $qreq
     * @param ?array $param
     * @param int $flags
     * @return string
     * @deprecated */
    function site_referrer_url(Qrequest $qreq, $param = null, $flags = 0) {
        if (($r = $qreq->referrer()) && ($rf = parse_url($r))) {
            $sup = $qreq->navigation()->siteurl_path();
            $path = $rf["path"] ?? "";
            if ($path !== "" && str_starts_with($path, $sup)) {
                $xqreq = new Qrequest("GET");
                $xqreq->set_user($qreq->user());
                $p = substr($path, strlen($sup));
                if (($slash = strpos($p, "/"))) {
                    $xqreq->set_page(substr($p, $slash), substr($p, $slash + 1));
                } else {
                    $xqreq->set_page($p);
                }
                preg_match_all('/([^=;&]+)=([^;&]+)/', $rf["query"] ?? "", $m, PREG_SET_ORDER);
                foreach ($m as $mx) {
                    $xqreq[urldecode($mx[1])] = urldecode($mx[2]);
                }
                return $this->qrequrl($xqreq, $param ?? [], $flags);
            }
        }
        return $this->selfurl($qreq, $param, $flags);
    }


    /** @return int */
    function saved_messages_status() {
        $st = 0;
        foreach ($this->_save_msgs ?? [] as $mx) {
            $st = max($st, $mx[1]);
        }
        return $st;
    }

    /** @return list<array{string,int}> */
    function take_saved_messages() {
        $ml = $this->_save_msgs ?? [];
        $this->_save_msgs = null;
        return $ml;
    }

    /** @return int */
    function report_saved_messages() {
        $st = $this->saved_messages_status();
        foreach ($this->take_saved_messages() as $mx) {
            self::msg_on($this, $mx[0], $mx[1]);
        }
        return $st;
    }

    /** @param ?string $url
     * @return never
     * @throws Redirection */
    function redirect($url = null) {
        if (self::$test_mode) {
            $nav = Navigation::get();
            throw new Redirection($nav->resolve($url ?? $this->hoturl("index")));
        } else {
            $qreq = Qrequest::$main_request;
            if ($this->_save_msgs) {
                $qreq->open_session();
                $qreq->set_csession("msgs", $this->_save_msgs);
            }
            $qreq->qsession()->commit();
            Navigation::redirect_absolute($qreq->navigation()->resolve($url ?? $this->hoturl("index")));
        }
    }

    /** @param string $page
     * @param null|string|array $param
     * @return never
     * @throws Redirection */
    function redirect_hoturl($page, $param = null) {
        $this->redirect($this->hoturl($page, $param, self::HOTURL_RAW));
    }

    /** @param Qrequest $qreq
     * @param ?array $param
     * @return never
     * @throws Redirection */
    function redirect_self(Qrequest $qreq, $param = null) {
        $this->redirect($this->selfurl($qreq, $param, self::HOTURL_RAW));
    }

    /** @param string $siteurl
     * @return string
     * @deprecated */
    function make_absolute_site($siteurl) {
        $nav = Navigation::get();
        if (str_starts_with($siteurl, "u/")) {
            return $nav->resolve($siteurl, $nav->base_path);
        } else {
            return $nav->resolve($siteurl, $nav->site_path);
        }
    }

    /** @param Qrequest $qreq
     * @return ?string */
    function qreq_redirect_url($qreq) {
        if (($r = $qreq->redirect ?? "") !== "" && $r !== "1") {
            $nav = $qreq->navigation();
            return $nav->resolve_within($r, $nav->siteurl());
        } else {
            return null;
        }
    }


    /** @param string $basename
     * @param int $flags
     * @return CsvGenerator */
    function make_csvg($basename, $flags = 0) {
        $csv = new CsvGenerator($flags);
        $csv->set_filename($this->download_prefix . $basename . $csv->extension());
        return $csv;
    }


    /** @return 'sha1'|'sha256' */
    function content_hash_algorithm() {
        $sha1 = ($this->opt["contentHashMethod"] ?? "") === "sha1";
        return $sha1 ? "sha1" : "sha256";
    }


    //
    // Paper search
    //

    /** @return string */
    function rating_signature_query() {
        if ($this->setting("rev_ratings") !== REV_RATINGS_NONE) {
            return "coalesce((select group_concat(contactId, ' ', rating) from ReviewRating force index (primary) where paperId=PaperReview.paperId and reviewId=PaperReview.reviewId),'')";
        } else {
            return "''";
        }
    }

    /** @return string */
    function all_reviewer_preference_query() {
        return "group_concat(contactId,' ',preference,' ',coalesce(expertise,'.'))";
    }

    /** @return string */
    function document_query_fields() {
        return "paperId, paperStorageId, timestamp, mimetype, sha1, crc32, documentType, filename, infoJson, size, filterType, originalStorageId, inactive"
            . ($this->sversion >= 276 ? ", npages, width, height" : "");
    }

    /** @return string */
    function document_metadata_query_fields() {
        return "infoJson" . ($this->sversion >= 276 ? ", npages, width, height" : "");
    }


    /** @param array{paperId?:list<int>|PaperID_SearchTerm,where?:string} $options
     * @return \mysqli_result|Dbl_Result */
    function paper_result($options, Contact $user = null) {
        // Options:
        //   "paperId" => $pids Only papers in list<int> $pids
        //   "finalized"        Only submitted papers
        //   "unsub"            Only unsubmitted papers
        //   "dec:yes"          Only accepted papers
        //   "dec:no"           Only rejected papers — also dec:none, dec:any, dec:maybe
        //   "active"           Only nonwithdrawn papers
        //   "author"           Only papers authored by $user
        //   "myReviewRequests" Only reviews requested by $user
        //   "myReviews"        All reviews authored by $user
        //   "myOutstandingReviews" All unsubmitted reviews auth by $user
        //   "myConflicts"      Only conflicted papers
        //   "commenterName"    Include commenter names
        //   "tags"             Include paperTags
        //   "minimal"          Only include minimal paper fields
        //   "topics"
        //   "options"
        //   "scores" => list<ReviewField>
        //   "assignments"
        //   "where" => $sql    SQL 'where' clause
        //   "order" => $sql    $sql is SQL 'order by' clause (or empty)
        //   "limit" => $sql    SQL 'limit' clause

        $cxid = $user ? $user->contactXid : -2;
        assert($cxid > 0 || $cxid < -1);

        // paper selection
        $paperset = null;
        '@phan-var null|list<int>|PaperID_SearchTerm $paperset';
        if (isset($options["paperId"])) {
            $paperset = $options["paperId"];
        }
        if (isset($options["reviewId"]) || isset($options["commentId"])) {
            throw new Exception("unexpected reviewId/commentId argument to Conf::paper_result");
        }

        // return known-empty result
        if (($cxid < 0
             && (($options["myReviewRequests"] ?? false)
                 || ($options["myLead"] ?? false)
                 || ($options["myShepherd"] ?? false)
                 || ($options["myManaged"] ?? false)
                 || ($options["myWatching"] ?? false)
                 || ($options["myConflicts"] ?? false)))
            || $paperset === []) {
            return Dbl_Result::make_empty();
        }

        // prepare query: basic tables
        // * Every table in `$joins` can have at most one row per paperId,
        //   except for `PaperReview`.
        $where = $qv = [];
        $joins = ["Paper"];

        if ($options["minimal"] ?? false) {
            $cols = ["Paper.paperId, Paper.timeSubmitted, Paper.timeWithdrawn, Paper.outcome, Paper.leadContactId, Paper.managerContactId"];
            if ($this->submission_blindness() === self::BLIND_OPTIONAL) {
                $cols[] = "Paper.blind";
            }
            foreach (["title", "authorInformation", "shepherdContactId"] as $k) {
                if ($options[$k] ?? false)
                    $cols[] = "Paper.{$k}";
            }
        } else {
            $cols = ["Paper.*"];
        }

        // author options
        $author = $options["author"] ?? false;
        $aucondition = $user && $author ? $user->act_author_view_sql("PaperConflict", true) : null;
        if ($aucondition) {
            $where[] = $aucondition;
        } else if ($author && $cxid < 0) {
            return Dbl_Result::make_empty();
        }
        if ($cxid > 0) {
            $j = $author && !$aucondition ? "join" : "left join";
            $t = $author && !$aucondition ? " and PaperConflict.conflictType>=" . CONFLICT_AUTHOR : "";
            $joins[] = "{$j} PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId={$cxid}{$t})";
            $cols[] = "PaperConflict.conflictType";
        } else {
            $cols[] = "null as conflictType";
        }

        // reviewer options
        $recondition = $user ? $user->act_reviewer_sql("PaperReview") : "false";
        $no_paperreview = $paperreview_is_my_reviews = false;
        if ($options["myReviews"] ?? false) {
            if ($recondition === "false") {
                return Dbl_Result::make_empty();
            }
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId and {$recondition})";
            $paperreview_is_my_reviews = true;
        } else if ($options["myOutstandingReviews"] ?? false) {
            if ($recondition === "false") {
                return Dbl_Result::make_empty();
            }
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId and {$recondition} and reviewNeedsSubmit!=0)";
        } else if ($options["myReviewRequests"] ?? false) {
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId and requestedBy={$cxid} and reviewType=" . REVIEW_EXTERNAL . ")";
        } else {
            $no_paperreview = true;
        }

        // review signatures
        if (($options["reviewSignatures"] ?? false)
            || ($options["scores"] ?? null)
            || ($options["reviewWordCounts"] ?? false)) {
            $cols[] = "coalesce((select " . ReviewInfo::review_signature_sql($this, $options["scores"] ?? null) . " from PaperReview r force index (primary) where r.paperId=Paper.paperId), '') reviewSignatures";
            if ($options["reviewWordCounts"] ?? false) {
                $cols[] = "coalesce((select group_concat(coalesce(reviewWordCount,'.') order by reviewId) from PaperReview force index (primary) where PaperReview.paperId=Paper.paperId), '') reviewWordCountSignature";
            }
        } else if (!$user) {
            // no myReviewPermissions required
        } else if ($recondition === "false") {
            $cols[] = "'' as myReviewPermissions";
        } else {
            // need myReviewPermissions
            if ($no_paperreview) {
                $joins[] = "left join PaperReview on (PaperReview.paperId=Paper.paperId and {$recondition})";
                $no_paperreview = false;
                $paperreview_is_my_reviews = true;
            }
            if ($paperreview_is_my_reviews) {
                $cols[] = "coalesce(" . PaperInfo::my_review_permissions_sql("PaperReview.") . ", '') myReviewPermissions";
            } else {
                $cols[] = "coalesce((select " . PaperInfo::my_review_permissions_sql() . " from PaperReview force index (primary) where PaperReview.paperId=Paper.paperId and {$recondition} group by paperId), '') myReviewPermissions";
            }
        }

        // fields
        if ($options["topics"] ?? false) {
            if ($this->has_topics()) {
                $cols[] = "coalesce((select group_concat(topicId) from PaperTopic force index (primary) where PaperTopic.paperId=Paper.paperId), '') topicIds";
            } else {
                $cols[] = "'' as topicIds";
            }
        }

        if ($options["options"] ?? false) {
            if ((isset($this->settingTexts["options"]) || isset($this->opt["fixedOptions"]))
                && $this->_paper_opts->has_universal()) {
                $cols[] = "coalesce((select group_concat(PaperOption.optionId, '#', value) from PaperOption force index (primary) where paperId=Paper.paperId), '') optionIds";
            } else {
                $cols[] = "'' as optionIds";
            }
        }

        if (($options["tags"] ?? false)
            || ($user && $user->isPC)
            || $this->rights_need_tags()) {
            $cols[] = "coalesce((select group_concat(' ', tag, '#', tagIndex separator '') from PaperTag force index (primary) where PaperTag.paperId=Paper.paperId), '') paperTags";
        }

        if ($options["reviewerPreference"] ?? false) {
            if ($cxid > 0) {
                $joins[] = "left join PaperReviewPreference force index (primary) on (PaperReviewPreference.paperId=Paper.paperId and PaperReviewPreference.contactId={$cxid})";
                $cols[] = "coalesce(PaperReviewPreference.preference, 0) as myReviewerPreference";
                $cols[] = "PaperReviewPreference.expertise as myReviewerExpertise";
            } else {
                $cols[] = "0 as myReviewerPreference";
                $cols[] = "null as myReviewerExpertise";
            }
        }

        if ($options["allReviewerPreference"] ?? false) {
            $cols[] = "coalesce((select " . $this->all_reviewer_preference_query() . " from PaperReviewPreference force index (primary) where PaperReviewPreference.paperId=Paper.paperId), '') allReviewerPreference";
        }

        if ($options["allConflictType"] ?? false) {
            // See also SearchQueryInfo::add_allConflictType_column
            $cols[] = "coalesce((select group_concat(contactId, ' ', conflictType) from PaperConflict force index (paperId) where PaperConflict.paperId=Paper.paperId), '') allConflictType";
        }

        if ($options["myWatch"] ?? false) {
            if ($cxid > 0) {
                $cols[] = "coalesce((select watch from PaperWatch force index (primary) where PaperWatch.paperId=Paper.paperId and PaperWatch.contactId={$cxid}), 0) watch";
            } else {
                $cols[] = "0 as watch";
            }
        }

        // conditions
        if ($paperset !== null) {
            if (is_array($paperset)) {
                $where[] = "Paper.paperId?a";
                $qv[] = $paperset;
            } else{
                $where[] = $paperset->sql_predicate("Paper.paperId");
            }
        }
        if ($options["finalized"] ?? false) {
            $where[] = "timeSubmitted>0";
        }
        if ($options["unsub"] ?? false) {
            $where[] = "timeSubmitted<=0";
        }
        if ($options["active"] ?? false) {
            $where[] = "timeWithdrawn<=0";
        }
        foreach (["yes", "no", "any", "none", "maybe", "active"] as $word) {
            if ($options["dec:{$word}"] ?? false) {
                $where[] = $this->decision_set()->sqlexpr($word);
            }
        }
        if ($options["myLead"] ?? false) {
            $where[] = "leadContactId={$cxid}";
        } else if ($options["anyLead"] ?? false) {
            $where[] = "leadContactId!=0";
        }
        if ($options["myShepherd"] ?? false) {
            $where[] = "shepherdContactId={$cxid}";
        } else if ($options["anyShepherd"] ?? false) {
            $where[] = "shepherdContactId!=0";
        }
        if ($options["myManaged"] ?? false) {
            $where[] = "managerContactId={$cxid}";
        }
        if ($options["myWatching"] ?? false) {
            assert($paperreview_is_my_reviews);
            // return the papers with explicit or implicit WATCH_REVIEW
            // (i.e., author/reviewer/commenter); or explicitly managed
            // papers
            $owhere = [
                "PaperConflict.conflictType>=" . CONFLICT_AUTHOR,
                "PaperReview.reviewType>0",
                "exists (select * from PaperComment where paperId=Paper.paperId and contactId={$cxid})",
                "exists (select * from PaperWatch where paperId=Paper.paperId and contactId={$cxid} and (watch&" . Contact::WATCH_REVIEW . ")!=0)"
            ];
            if ($this->has_any_lead_or_shepherd()) {
                $owhere[] = "leadContactId={$cxid}";
            }
            if ($this->has_any_manager() && $user->is_explicit_manager()) {
                $owhere[] = "managerContactId={$cxid}";
            }
            $where[] = "(" . join(" or ", $owhere) . ")";
        }
        if ($options["myConflicts"] ?? false) {
            $where[] = "PaperConflict.conflictType>" . CONFLICT_MAXUNCONFLICTED;
        }
        if (isset($options["where"]) && $options["where"]) {
            assert(strpos($options["where"], "?") === false);
            $where[] = $options["where"];
        }

        $pq = "select " . join(",\n    ", $cols)
            . "\nfrom " . join("\n    ", $joins);
        if (!empty($where)) {
            $pq .= "\nwhere " . join("\n    and ", $where);
        }

        $pq .= "\ngroup by Paper.paperId\n";
        // This `having` is probably faster than a `where exists` if most papers
        // have at least one tag.
        if (($options["tags"] ?? false) === "require") {
            $pq .= "having paperTags!=''\n";
        }
        $pq .= ($options["order"] ?? "order by Paper.paperId") . "\n"
            . ($options["limit"] ?? "");

        //Conf::msg_debugt($pq);
        return $this->qe_apply($pq, $qv);
    }

    /** @param array{paperId?:list<int>|PaperID_SearchTerm} $options
     * @return PaperInfoSet|Iterable<PaperInfo> */
    function paper_set($options, Contact $user = null) {
        $result = $this->paper_result($options, $user);
        return PaperInfoSet::make_result($result, $user, $this);
    }

    /** @param int $pid
     * @return ?PaperInfo */
    function paper_by_id($pid, Contact $user = null, $options = []) {
        $options["paperId"] = [$pid];
        $result = $this->paper_result($options, $user);
        $prow = PaperInfo::fetch($result, $user, $this);
        Dbl::free($result);
        return $prow;
    }

    /** @param int $pid
     * @return PaperInfo */
    function checked_paper_by_id($pid, Contact $user = null, $options = []) {
        $prow = $this->paper_by_id($pid, $user, $options);
        if (!$prow) {
            throw new Exception("Conf::checked_paper_by_id($pid) failed");
        }
        return $prow;
    }

    /** @return ?PaperInfo */
    function set_paper_request(Qrequest $qreq, Contact $user) {
        $this->paper = $prow = null;
        if ($qreq->p) {
            if (ctype_digit($qreq->p)) {
                $prow = $this->paper_by_id(intval($qreq->p), $user);
            }
            if (($whynot = $user->perm_view_paper($prow, false, $qreq->p))) {
                $qreq->set_annex("paper_whynot", $whynot);
                $prow = null;
            }
        }
        return ($this->paper = $prow);
    }


    //
    // Message routines
    //

    /** @param string $text
     * @param int $type */
    static function msg_on(Conf $conf = null, $text, $type) {
        assert(is_int($type) && is_string($text ?? ""));
        if (($text ?? "") === "") {
            // do nothing
        } else if (PHP_SAPI === "cli") {
            if ($type >= 2) {
                fwrite(STDERR, "{$text}\n");
            } else if ($type === 1 || !defined("HOTCRP_TESTHARNESS")) {
                fwrite(STDOUT, "{$text}\n");
            }
        } else if ($conf && !$conf->_header_printed) {
            $conf->_save_msgs[] = [$text, $type];
        } else {
            $k = Ht::msg_class($type) . ($conf && $conf->_mx_auto ? " mx-auto" : "");
            echo "<div class=\"{$k}\">{$text}</div>";
        }
    }

    /** @param MessageItem|iterable<MessageItem>|MessageSet ...$mls */
    function feedback_msg(...$mls) {
        $ms = Ht::feedback_msg_content(...$mls);
        $ms[0] === "" || self::msg_on($this, $ms[0], $ms[1]);
    }

    /** @param string $msg */
    function error_msg($msg) {
        $this->feedback_msg(MessageItem::error($msg));
    }

    /** @param string $msg */
    function warning_msg($msg) {
        $this->feedback_msg(MessageItem::warning($msg));
    }

    /** @param string $msg */
    function success_msg($msg) {
        $this->feedback_msg(MessageItem::success($msg));
    }

    /** @param mixed $text */
    static function msg_debugt($text) {
        if (is_object($text) || is_array($text) || $text === null || $text === false || $text === true) {
            $text = json_encode_browser($text);
        }
        self::msg_on(self::$main, Ht::pre_text_wrap($text), 2);
        return false;
    }

    function post_missing_msg() {
        $this->feedback_msg(
            MessageItem::error("<0>Uploaded data was not received"),
            MessageItem::inform("<0>This can happen on unusually slow connections, or if you tried to upload a file larger than I can accept.")
        );
    }


    //
    // Conference header, footer
    //

    /** @param array $x
     * @return string */
    function make_css_link($x) {
        $x["rel"] = $x["rel"] ?? "stylesheet";
        $url = $x["href"];
        if ($url[0] !== "/"
            && (($url[0] !== "h" && $url[0] !== "H")
                || (strtolower(substr($url, 0, 5)) !== "http:"
                    && strtolower(substr($url, 0, 6)) !== "https:"))) {
            if (($mtime = @filemtime(SiteLoader::find($url))) !== false) {
                $url .= "?mtime={$mtime}";
            }
            $x["href"] = $this->_assets_url . $url;
        }
        return "<link" . Ht::extra($x) . ">";
    }

    /** @param non-empty-string $url
     * @return string */
    function make_script_file($url, $no_strict = false, $integrity = null) {
        if (str_starts_with($url, "scripts/")) {
            $post = "";
            if (($mtime = @filemtime(SiteLoader::find($url))) !== false) {
                $post = "mtime={$mtime}";
            }
            if (($this->opt["strictJavascript"] ?? false) && !$no_strict) {
                $url = $this->_script_assets_url . "cacheable.php/"
                    . str_replace("%2F", "/", urlencode($url))
                    . "?strictjs=1" . ($post ? "&{$post}" : "");
            } else {
                $url = $this->_script_assets_url . $url . ($post ? "?{$post}" : "");
            }
            if ($this->_script_assets_site) {
                return Ht::script_file($url, ["integrity" => $integrity]);
            }
        }
        return Ht::script_file($url, ["crossorigin" => "anonymous", "integrity" => $integrity]);
    }

    private function make_jquery_script_file($jqueryVersion) {
        $integrity = null;
        if ($jqueryVersion === "3.7.1") {
            $integrity = "sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=";
        } else if ($jqueryVersion === "3.6.4") {
            $integrity = "sha256-oP6HI9z1XaZNBrJURtCoUT5SUnxFr8s3BzRl+cbzUq8=";
        } else if ($jqueryVersion === "3.6.0") {
            $integrity = "sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=";
        } else if ($jqueryVersion === "1.12.4") {
            $integrity = "sha256-ZosEbRLbNQzLpnKIkEdrPv7lOy9C27hHQ+Xp8a4MxAQ=";
        }
        if ($this->opt["jqueryCdn"] ?? $this->opt["jqueryCDN"] ?? false) {
            $jquery = "//code.jquery.com/jquery-{$jqueryVersion}.min.js";
        } else {
            $jquery = "scripts/jquery-{$jqueryVersion}.min.js";
        }
        '@phan-var non-empty-string $jquery';
        return $this->make_script_file($jquery, true, $integrity);
    }

    function prepare_security_headers() {
        if (($csp = $this->opt("contentSecurityPolicy"))) {
            if (is_string($csp)) {
                $csp = [$csp];
            } else if ($csp === true) {
                $csp = [];
            }
            $report_only = false;
            if (($pos = array_search("'report-only'", $csp)) !== false) {
                $report_only = true;
                array_splice($csp, $pos, 1);
            }
            if (empty($csp)) {
                array_push($csp, "script-src", "'nonce'");
            }
            if (($pos = array_search("'nonce'", $csp)) !== false) {
                $nonceval = base64_encode(random_bytes(16));
                $csp[$pos] = "'nonce-{$nonceval}'";
                Ht::set_script_nonce($nonceval);
            }
            header("Content-Security-Policy"
                   . ($report_only ? "-Report-Only: " : ": ")
                   . join(" ", $csp));
        }
        if ($this->opt("crossOriginIsolation") !== false) {
            header("Cross-Origin-Opener-Policy: same-origin");
        }
        if (($sts = $this->opt("strictTransportSecurity"))) {
            header("Strict-Transport-Security: $sts");
        }
    }

    /** @param Qrequest $qreq
     * @param string|list<string> $title */
    function print_head_tag($qreq, $title, $extra = []) {
        // clear session list cookies
        foreach ($_COOKIE as $k => $v) {
            if (str_starts_with($k, "hotlist-info")
                || str_starts_with($k, "hc-uredirect-"))
                $qreq->set_cookie($k, "", Conf::$now - 86400);
        }

        echo "<!DOCTYPE html>\n<html lang=\"{$this->lang}\">\n<head>\n",
            "<meta http-equiv=\"content-type\" content=\"text/html; charset=utf-8\">\n";

        // gather stylesheets
        $cssx = [];
        $has_default_css = $has_media = false;
        foreach ($this->opt("stylesheets") ?? [] as $css) {
            if (is_string($css)) {
                $css = ["href" => $css];
            }
            $cssx[] = $this->make_css_link($css);
            $has_default_css = $has_default_css || $css["href"] === "stylesheets/style.css";
            $has_media = $has_media || ($css["media"] ?? null) !== null;
        }

        // meta elements
        $meta = $this->opt("metaTags") ?? [];
        if ($has_media) {
            $meta["viewport"] = $meta["viewport"] ?? "width=device-width, initial-scale=1";
        }
        foreach ($meta as $key => $value) {
            if ($value === false) {
                // nothing
            } else if (is_int($key)) {
                assert(str_starts_with($value, "<meta"));
                echo $value, "\n";
            } else if ($key === "default-style" || $key === "content-security-policy") {
                echo "<meta http-equiv=\"", $key, "\" content=\"", htmlspecialchars($value), "\">\n";
            } else {
                echo "<meta name=\"", htmlspecialchars($key), "\" content=\"", htmlspecialchars($value), "\">\n";
            }
        }

        // css references
        if (!$has_default_css) {
            echo $this->make_css_link(["href" => "stylesheets/style.css"]), "\n";
        }
        foreach ($cssx as $css) {
            echo $css, "\n";
        }

        // favicon
        $favicon = $this->opt("favicon") ?? "images/review48.png";
        if ($favicon) {
            if (strpos($favicon, "://") === false && $favicon[0] != "/") {
                if (substr($favicon, 0, 7) === "images/") {
                    $favicon = $this->_assets_url . $favicon;
                } else {
                    $favicon = $qreq->navigation()->siteurl() . $favicon;
                }
            }
            if (substr($favicon, -4) == ".png") {
                echo "<link rel=\"icon\" type=\"image/png\" href=\"{$favicon}\">\n";
            } else if (substr($favicon, -4) == ".ico") {
                echo "<link rel=\"shortcut icon\" href=\"{$favicon}\">\n";
            } else if (substr($favicon, -4) == ".gif") {
                echo "<link rel=\"icon\" type=\"image/gif\" href=\"{$favicon}\">\n";
            } else {
                echo "<link rel=\"icon\" href=\"{$favicon}\">\n";
            }
        }

        // title
        echo "<title>";
        if ($title) {
            if (is_array($title)) {
                if (count($title) === 3 && $title[2]) {
                    $title = $title[1] . " - " . $title[0];
                } else {
                    $title = $title[0];
                }
            }
            $title = preg_replace("/<([^>\"']|'[^']*'|\"[^\"]*\")*>/", "", $title);
        }
        if ($title && $title !== "Home" && $title !== "Sign in") {
            echo $title, " - ";
        }
        echo htmlspecialchars($this->short_name), "</title>\n</head>\n";

        // jQuery
        $stash = Ht::unstash();
        if (($jqurl = $this->opt["jqueryUrl"] ?? $this->opt["jqueryURL"] ?? null)) {
            Ht::stash_html($this->make_script_file($jqurl, true) . "\n");
        } else {
            $jqueryVersion = $this->opt["jqueryVersion"] ?? "3.7.1";
            if ($jqueryVersion[0] === "3") {
                Ht::stash_html("<!--[if lt IE 9]>" . $this->make_jquery_script_file("1.12.4") . "<![endif]-->\n");
                Ht::stash_html("<![if !IE|gte IE 9]>" . $this->make_jquery_script_file($jqueryVersion) . "<![endif]>\n");
            } else {
                Ht::stash_html($this->make_jquery_script_file($jqueryVersion) . "\n");
            }
        }
        if ($this->opt("jqueryMigrate")) {
            Ht::stash_html($this->make_script_file("//code.jquery.com/jquery-migrate-3.0.0.js", true));
        }

        // Javascript settings to set before script.js
        $nav = $qreq->navigation();
        $siteinfo = [
            "site_relative" => $nav->site_path_relative,
            "base" => $nav->base_path,
            "suffix" => $nav->php_suffix,
            "assets" => $this->_assets_url,
            "cookie_params" => "",
            "postvalue" => $qreq->maybe_post_value(),
            "snouns" => $this->snouns
        ];
        $userinfo = [];
        if (($x = $this->opt("sessionDomain"))) {
            $siteinfo["cookie_params"] .= "; Domain=$x";
        }
        if ($this->opt("sessionSecure")) {
            $siteinfo["cookie_params"] .= "; Secure";
        }
        if (($samesite = $this->opt("sessionSameSite") ?? "Lax")) {
            $siteinfo["cookie_params"] .= "; SameSite={$samesite}";
        }
        if (($user = $qreq->user())) {
            if ($user->email) {
                $userinfo["email"] = $user->email;
            }
            if ($user->is_pclike()) {
                $userinfo["is_pclike"] = true;
            }
            if ($user->has_account_here()) {
                $userinfo["cid"] = $user->contactId; // XXX backward compat
                $userinfo["uid"] = $user->contactId;
            }
            if ($user->is_actas_user()) {
                $userinfo["is_actas"] = true;
            }
            if ($user->tracker_kiosk_state > 0) {
                $userinfo["tracker_kiosk"] = true;
            }
            if (($uindex = $user->session_index()) > 0
                || $qreq->navigation()->shifted_path !== "") {
                $userinfo["session_index"] = $uindex;
            }
            $susers = Contact::session_users($qreq);
            if ($user->is_actas_user() || count($susers) > 1) {
                $userinfo["session_users"] = $susers;
            }
            if (($defaults = $user->hoturl_defaults())) {
                $siteinfo["defaults"] = [];
                foreach ($defaults as $k => $v) {
                    $siteinfo["defaults"][$k] = urldecode($v);
                }
            }
        }
        $siteinfo["user"] = $userinfo;

        $pid = $extra["paperId"] ?? 0;
        if (!is_int($pid)) {
            $pid = $pid && ctype_digit($pid) ? intval($pid) : 0;
        }
        if ($pid > 0 && $this->paper) {
            $pid = $this->paper->paperId;
        }
        if ($pid > 0) {
            $siteinfo["paperid"] = $pid;
        }
        if ($pid > 0 && $user && $user->is_admin_force()) {
            $siteinfo["want_override_conflict"] = true;
        }

        Ht::stash_script("window.siteinfo=" . json_encode_browser($siteinfo));

        // script.js
        if (!$this->opt("noDefaultScript")) {
            Ht::stash_html($this->make_script_file("scripts/script.js") . "\n");
        }

        // other scripts
        foreach ($this->opt("scripts") ?? [] as $s) {
            if ($s[0] === "{") {
                Ht::stash_script($s);
            } else {
                Ht::stash_html($this->make_script_file($s) . "\n");
            }
        }

        if ($stash) {
            Ht::stash_html($stash);
        }
    }

    /** @return bool */
    function has_active_tracker() {
        return (($this->settings["__tracker"] ?? 0) & 1) !== 0;
    }

    /** @return bool */
    function has_interesting_deadline($my_deadlines) {
        if ($my_deadlines->sub->open ?? false) {
            if (Conf::$now <= ($my_deadlines->sub->reg ?? 0)
                || Conf::$now <= ($my_deadlines->sub->update ?? 0)
                || Conf::$now <= ($my_deadlines->sub->sub ?? 0)
                || ($my_deadlines->sub->reg_ingrace ?? false)
                || ($my_deadlines->sub->update_ingrace ?? false)
                || ($my_deadlines->sub->sub_ingrace ?? false)) {
                return true;
            }
        }
        if (($my_deadlines->is_author ?? false)
            && ($my_deadlines->resps ?? false)) {
            foreach ($my_deadlines->resps as $r) {
                if ($r->open && (Conf::$now <= $r->done || ($r->ingrace ?? false)))
                    return true;
            }
        }
        return false;
    }

    /** @param Contact $user
     * @param string $html
     * @param string $page
     * @param null|string|array $args */
    private function _print_profilemenu_link_if_enabled($user, $html, $page, $args = null) {
        if (!$user->is_disabled()) {
            echo '<li class="has-link">', Ht::link($html, $this->hoturl($page, $args)), '</li>';
        } else {
            echo '<li class="dim">', $html, '</li>';
        }
    }

    /** @param ComponentSet $pagecs */
    function print_profilemenu_item(Contact $user, Qrequest $qreq, $pagecs, $gj) {
        $itemid = substr($gj->name, 14); // NB 14 === strlen("__profilemenu/")
        if ($itemid === "me") {
            if (!$user->has_email()) {
                return;
            }
            $ouser = $user;
            if ($user->is_actas_user()) {
                echo '<li class="has-quiet-link">', Ht::link("Acting as " . htmlspecialchars($user->email), $this->hoturl("profile")), '</li>';
                echo '<li class="has-link">', Ht::link("Switch to <strong>" . htmlspecialchars($user->base_user()->email), $this->selfurl($qreq, ["actas" => null])), '</strong></li>';
            } else if (!$user->is_disabled() && !$user->is_anonymous_user()) {
                echo '<li class="has-quiet-link">', Ht::link("Signed in as <strong>" . htmlspecialchars($user->email) . "</strong>", $this->hoturl("profile")), '</li>';
            } else {
                echo '<li>Signed in as <strong>', htmlspecialchars($user->email), '</strong></li>';
            }
        } else if ($itemid === "other_accounts") {
            $base_email = $user->base_user()->email;
            $actas_email = null;
            if ($user->privChair && !$user->is_actas_user()) {
                $actas_email = $qreq->gsession("last_actas");
            }
            $nav = $qreq->navigation();
            $sfx = $this->selfurl($qreq, ["actas" => null], self::HOTURL_SITEREL);
            if ($sfx === "index" . $nav->php_suffix) {
                $sfx = "";
            }
            foreach (Contact::session_users($qreq) as $i => $email) {
                if ($actas_email !== null && strcasecmp($email, $actas_email) === 0) {
                    $actas_email = null;
                }
                if ($email !== "" && strcasecmp($email, $base_email) !== 0) {
                    echo '<li class="has-link">', Ht::link("Switch to " . htmlspecialchars($email), "{$nav->base_path_relative}u/{$i}/{$sfx}"), '</li>';
                }
            }
            if ($actas_email !== null) {
                echo '<li class="has-link">', Ht::link("Act as ". htmlspecialchars($actas_email), $this->selfurl($qreq, ["actas" => $actas_email])), '</li>';
            }
            $t = $user->is_empty() ? "Sign in" : "Add account";
            echo '<li class="has-link">', Ht::link($t, $this->hoturl("signin")), '</li>';
        } else if ($itemid === "profile") {
            if ($user->has_email()) {
                $this->_print_profilemenu_link_if_enabled($user, "Your profile", "profile");
            }
        } else if ($itemid === "my_submissions") {
            $this->_print_profilemenu_link_if_enabled($user, "Your submissions", "search", "t=a");
        } else if ($itemid === "my_reviews") {
            $this->_print_profilemenu_link_if_enabled($user, "Your reviews", "search", "t=r");
        } else if ($itemid === "search") {
            $this->_print_profilemenu_link_if_enabled($user, "Search", "search");
        } else if ($itemid === "help") {
            if (!$user->is_disabled()) {
                $this->_print_profilemenu_link_if_enabled($user, "Help", "help");
            }
        } else if ($itemid === "settings") {
            $this->_print_profilemenu_link_if_enabled($user, "Settings", "settings");
        } else if ($itemid === "users") {
            $this->_print_profilemenu_link_if_enabled($user, "Users", "users");
        } else if ($itemid === "assignments") {
            $this->_print_profilemenu_link_if_enabled($user, "Assignments", "autoassign");
        } else if ($itemid === "signout") {
            if (!$user->has_email()) {
                return;
            }
            if ($user->is_actas_user()) {
                echo '<li class="has-link">', Ht::link("Return to main account", $this->selfurl($qreq, ["actas" => null])), '</li>';
                return;
            }
            echo '<li class="has-link">',
                Ht::form($this->hoturl("=signout", ["cap" => null])),
                Ht::button("Sign out", ["type" => "submit", "class" => "link"]),
                '</form></li>';
        }
    }

    /** @param string $id */
    private function print_header_profile($id, Qrequest $qreq, Contact $user) {
        assert($user && !$user->is_empty());

        if ($user->is_actas_user()) {
            $details_class = " header-actas need-banner-offset";
            $details_prefix = "<span class=\"warning-mark\"></span> Acting as ";
            $details_suffix = "";
            $button_class = "q";
        } else {
            $details_class = "";
            $details_prefix = "";
            $details_suffix = '<svg class="licon ml-1" width="1em" height="1em" viewBox="0 0 16 16" preserveAspectRatio="none" role="none"><path d="M2 3h12M2 8h12M2 13h12" stroke="#222" stroke-width="2" /></svg>';
            $button_class = "btn-t";
        }
        $user_html = $user->has_email() ? htmlspecialchars($user->email) : "Not signed in";

        $pagecs = $this->page_components($user, $qreq);
        $old_separator = $pagecs->swap_separator('<li class="separator"></li>');
        echo '<details class="dropmenu-details', $details_class, '" role="menu">',
            '<summary class="profile-dropmenu-summary">',
            '<button type="button" class="ui js-dropmenu-open ', $button_class, '">',
            $details_prefix, $user_html, $details_suffix,
            '</button></summary><div class="dropmenu-container dropmenu-sw"><ul class="uic dropmenu">';
        $pagecs->print_members("__profilemenu");
        $pagecs->swap_separator($old_separator);
        echo '</ul></div></details>';

        if ($user->is_actas_user()) {
            // reserve space in the header so JS can prepend a deadline notification
            // (the actas button is fixed-position, so does not reserve space)
            echo '<details class="invisible dropmenu-details" role="none">',
                '<summary class="profile-dropmenu-summary ml-1"><button type="button">',
                $details_prefix, $user_html, $details_suffix,
                '</button></summary></details>';
        }
    }

    /** @param $round_ini bool
     * @return int|float */
    function upload_max_filesize($round_ini = false) {
        if (($x = $this->opt["uploadMaxFilesize"] ?? null) !== null) {
            return ini_get_bytes(null, $x);
        } else if ($round_ini) {
            return ini_get_bytes("upload_max_filesize") / 1.024;
        } else {
            return ini_get_bytes("upload_max_filesize");
        }
    }

    /** @param Qrequest $qreq
     * @param string|list<string> $title */
    private function print_body_header($qreq, $title, $id, $extra) {
        if ($id === "home" || ($extra["hide_title"] ?? false)) {
            echo '<div id="h-site" class="header-site-home">',
                '<h1><a class="q" href="', $this->hoturl("index", ["cap" => null]),
                '">', htmlspecialchars($this->short_name), '</a></h1></div>';
        } else {
            echo '<div id="h-site" class="header-site-page">',
                '<a class="q" href="', $this->hoturl("index", ["cap" => null]),
                '"><span class="header-site-name">', htmlspecialchars($this->short_name),
                '</span> Home</a></div>';
        }

        echo '<div id="h-right">';
        if (($user = $qreq->user()) && !$user->is_empty()) {
            $this->print_header_profile($id, $qreq, $user);
        }
        echo '</div>';
        if (!($extra["hide_title"] ?? false)) {
            $title_div = $extra["title_div"] ?? null;
            if ($title_div === null) {
                if (($subtitle = $extra["subtitle"] ?? null)) {
                    $title .= " &nbsp;&#x2215;&nbsp; <strong>{$subtitle}</strong>";
                }
                if ($title && $title !== "Home") {
                    $title_div = "<div id=\"h-page\"><h1>{$title}</h1></div>";
                }
            }
            echo $title_div ?? "";
        }
        echo $extra["action_bar"] ?? QuicklinksRenderer::make($qreq),
            "<hr class=\"c\">\n";
    }

    /** @param Qrequest $qreq
     * @param string|list<string> $title */
    function print_body_entry($qreq, $title, $id, $extra = []) {
        $user = $qreq->user();
        echo "<body";
        if ($id) {
            echo ' id="t-', $id, '"';
        }
        $class = $extra["body_class"] ?? "";
        $this->_mx_auto = strpos($class, "error") !== false;
        if (($list = $qreq->active_list())) {
            $class = $class === "" ? "has-hotlist" : "{$class} has-hotlist";
        }
        if ($class !== "") {
            echo ' class="', $class, '"';
        }
        if ($list) {
            echo ' data-hotlist="', htmlspecialchars($list->info_string()), '"';
        }
        echo ' data-upload-limit="', ini_get_bytes("upload_max_filesize"), '"';
        if (($x = $this->opt["uploadMaxFilesize"] ?? null) !== null) {
            echo ' data-document-max-size="', ini_get_bytes(null, $x), '"';
        }
        echo '><div id="p-page" class="need-banner-offset"><div id="p-header">';

        // initial load (JS's timezone offsets are negative of PHP's)
        Ht::stash_script("hotcrp.onload.time(" . (-(int) date("Z", Conf::$now) / 60) . "," . ($this->opt("time24hour") ? 1 : 0) . ")");

        // deadlines settings
        $my_deadlines = null;
        if ($user) {
            $my_deadlines = $user->my_deadlines($this->paper ? [$this->paper] : []);
            Ht::stash_script("hotcrp.init_deadlines(" . json_encode_browser($my_deadlines) . ")");
        }

        if (!($extra["hide_header"] ?? false)) {
            $this->print_body_header($qreq, $title, $id, $extra);
        }
        $this->_header_printed = true;

        echo "<div id=\"h-messages\" class=\"msgs-wide\">\n";
        if (($x = $this->opt("maintenance"))) {
            echo Ht::msg(is_string($x) ? $x : "<strong>This site is down for maintenance.</strong> Please check back later.", 2);
        }
        if ($this->_save_msgs && !($extra["save_messages"] ?? false)) {
            $this->report_saved_messages();
        }
        if ($qreq && $qreq->has_annex("upload_errors")) {
            $this->feedback_msg($qreq->annex("upload_errors"));
        }
        echo "</div></div>\n";

        echo "<div id=\"p-body\">\n";

        // If browser owns tracker, send it the script immediately
        if ($this->has_active_tracker()
            && MeetingTracker::session_owns_tracker($this, $qreq)) {
            echo Ht::unstash();
        }

        // Callback for version warnings
        if ($user
            && $user->privChair
            && $this->session_key !== null
            && (!$qreq->has_gsession("updatecheck")
                || $qreq->gsession("updatecheck") + 3600 <= Conf::$now)
            && (!isset($this->opt["updatesSite"]) || $this->opt["updatesSite"])) {
            $m = isset($this->opt["updatesSite"]) ? $this->opt["updatesSite"] : "//hotcrp.lcdf.org/updates";
            $m .= (strpos($m, "?") === false ? "?" : "&")
                . "addr=" . urlencode($_SERVER["SERVER_ADDR"])
                . "&base=" . urlencode($qreq->navigation()->siteurl())
                . "&version=" . HOTCRP_VERSION;
            $v = HOTCRP_VERSION;
            if (is_dir(SiteLoader::find(".git"))) {
                $args = [];
                exec("export GIT_DIR=" . escapeshellarg(SiteLoader::$root) . "/.git; git rev-parse HEAD 2>/dev/null; git merge-base origin/master HEAD 2>/dev/null", $args);
                if (count($args) >= 1) {
                    $m .= "&git-head=" . urlencode($args[0]);
                    $v .= " " . $args[0];
                }
                if (count($args) >= 2) {
                    $m .= "&git-upstream=" . urlencode($args[1]);
                    $v .= " " . $args[1];
                }
            }
            Ht::stash_script("hotcrp.check_version(\"$m\",\"$v\")");
            $qreq->set_gsession("updatecheck", Conf::$now);
        }
    }

    static function git_status() {
        $args = [];
        if (is_dir(SiteLoader::find(".git"))) {
            exec("export GIT_DIR=" . escapeshellarg(SiteLoader::$root) . "/.git; git rev-parse HEAD 2>/dev/null; git rev-parse v" . HOTCRP_VERSION . " 2>/dev/null", $args);
        }
        return count($args) == 2 ? $args : null;
    }

    /** @param Contact $viewer
     * @param Contact $user */
    private function pc_json_item($viewer, $user) {
        $name = $viewer->name_text_for($user);
        $j = (object) [
            "name" => $name !== "" ? $name : $user->email,
            "email" => $user->email
        ];
        if (($color_classes = $user->viewable_color_classes($viewer))) {
            $j->color_classes = $color_classes;
        }
        if ($this->sort_by_last && $user->lastName !== "") {
            self::pc_json_sort_by_last($j, $user);
        }
        return $j;
    }

    /** @param Contact $viewer
     * @param ReviewInfo $rrow
     * @return stdClass */
    private function pc_json_reviewer_item($viewer, $rrow) {
        $user = $rrow->reviewer();
        $j = (object) [
            "name" => Text::nameo($user, NAME_P),
            "email" => $user->email
        ];
        if ($this->sort_by_last && $user->lastName !== "") {
            self::pc_json_sort_by_last($j, $user);
        }
        return $j;
    }

    /** @param Contact|ReviewInfo $r */
    static private function pc_json_sort_by_last($j, $r) {
        if (strlen($r->lastName) !== strlen($j->name)) {
            $j->lastpos = UnicodeHelper::utf16_strlen($r->firstName) + 1;
        }
        if (($r->nameAmbiguous ?? false) && $j->name !== "" && $r->email !== "") {
            $j->emailpos = UnicodeHelper::utf16_strlen($j->name) + 1;
        }
    }

    /** @return array<string,mixed> */
    function hotcrp_pc_json(Contact $viewer) {
        $hpcj = $list = $otherj = [];
        foreach ($this->pc_members() as $pcm) {
            $hpcj[$pcm->contactId] = $this->pc_json_item($viewer, $pcm);
            $list[] = $pcm->contactId;
        }
        $hpcj["__order__"] = $list;
        if ($this->sort_by_last) {
            $hpcj["__sort__"] = "last";
        }
        if ($viewer->can_view_user_tags()) {
            $hpcj["__tags__"] = $this->viewable_user_tags($viewer);
        }
        if ($this->paper
            && ($viewer->privChair || $viewer->allow_administer($this->paper))) {
            $list = [];
            foreach ($this->pc_members() as $pcm) {
                if ($pcm->can_accept_review_assignment($this->paper)) {
                    $list[] = $pcm->contactId;
                }
            }
            $hpcj["__assignable__"] = [$this->paper->paperId => $list];
            if ($this->setting("extrev_shepherd")) {
                $this->paper->ensure_reviewer_names();
                $erlist = [];
                foreach ($this->paper->reviews_as_display() as $rrow) {
                    if ($rrow->reviewType == REVIEW_EXTERNAL
                        && !$rrow->reviewToken
                        && !in_array($rrow->contactId, $erlist)) {
                        $otherj[$rrow->contactId] = $this->pc_json_reviewer_item($viewer, $rrow);
                        $erlist[] = $rrow->contactId;
                    }
                }
                if (!empty($erlist)) {
                    $hpcj["__extrev__"] = [$this->paper->paperId => $erlist];
                }
            }
        }
        if (!empty($otherj)) {
            $hpcj["__other__"] = $otherj;
        }
        return $hpcj;
    }

    function stash_hotcrp_pc(Contact $viewer, $always = false) {
        if (($always || !$this->opt("largePC"))
            && Ht::mark_stash("hotcrp_pc")) {
            Ht::stash_script("hotcrp.demand_load.pc(" . json_encode_browser($this->hotcrp_pc_json($viewer)) . ");");
        }
    }


    //
    // Action recording
    //

    const action_log_query = "insert into ActionLog (ipaddr, contactId, destContactId, trueContactId, paperId, timestamp, action)";
    const action_log_query_action_index = 6;

    function delay_logs() {
        ++$this->_save_logs_depth;
    }

    function release_logs() {
        if ($this->_save_logs_depth > 0) {
            --$this->_save_logs_depth;
        }
        if ($this->_save_logs_depth > 0 || empty($this->_save_logs)) {
            return;
        }
        $qv = [];
        '@phan-var-force list<list<string>> $qv';
        $last_pids = null;
        foreach ($this->_save_logs as $cid_text => $pids) {
            $pos = strpos($cid_text, "|");
            list($user, $dest_user, $true_user) = explode(",", substr($cid_text, 0, $pos));
            $what = substr($cid_text, $pos + 1);
            array_sort_unique($pids);

            // Combine `Tag` messages
            if (str_starts_with($what, "Tag ")
                && ($n = count($qv)) > 0
                && str_starts_with($qv[$n-1][self::action_log_query_action_index], "Tag ")
                && $last_pids === $pids) {
                $qv[$n-1][self::action_log_query_action_index] .= substr($what, 3);
            } else {
                foreach (self::format_log_values($what, $user, $dest_user, $true_user, $pids) as $x) {
                    $qv[] = $x;
                }
                $last_pids = $pids;
            }
        }
        if (!empty($qv)) {
            $this->qe(self::action_log_query . " values ?v", $qv);
        }
        $this->_save_logs = null;
    }

    private static function log_clean_user($user, &$text) {
        if (!$user) {
            return 0;
        } else if (!is_numeric($user)) {
            if ($user->email
                && !$user->contactId
                && !$user->is_root_user()) {
                $suffix = " <{$user->email}>";
                if (!str_ends_with($text, $suffix)) {
                    $text .= $suffix;
                }
            }
            return $user->contactId;
        } else {
            return $user;
        }
    }

    /** @param null|int|Contact $user
     * @param null|int|Contact $dest_user
     * @param string $text
     * @param null|int|PaperInfo|list<int|PaperInfo> $pids
     * @param bool $dedup */
    function log_for($user, $dest_user, $text, $pids = null, $dedup = false) {
        if (is_object($pids)) {
            $pids = [$pids->paperId];
        } else if (is_array($pids)) {
            foreach ($pids as &$p) {
                $p = is_object($p) ? $p->paperId : $p;
            }
            unset($p);
        } else if ($pids === null || $pids <= 0) {
            $pids = [];
        } else {
            $pids = [$pids];
        }
        '@phan-var-force list<int> $pids';

        $true_user = 0;
        if ($user && is_object($user)) {
            if ($user->is_actas_user()) {
                $true_user = $user->base_user()->contactId;
            } else if (!$user->contactId
                       && !empty($pids)
                       && $user->has_capability_for($pids[0])) {
                $true_user = -1; // indicate download via link
            } else if ($user->is_bearer_authorized()) {
                $true_user = -2; // indicate bearer token
            } else if (PHP_SAPI === "cli") {
                $true_user = -3; // indicate command line
            }
        }
        $user = self::log_clean_user($user, $text);
        $dest_user = self::log_clean_user($dest_user, $text);

        if ($this->_save_logs_depth > 0) {
            $key = "{$user},{$dest_user},{$true_user}|{$text}";
            $this->_save_logs = $this->_save_logs ?? [];
            $pl = &$this->_save_logs[$key];
            $pl = $pl ?? [];
            if (!empty($pids)) {
                array_push($pl, ...$pids);
            }
            return;
        }

        $values = self::format_log_values($text, $user, $dest_user, $true_user, $pids);
        if ($dedup && count($values) === 1) {
            $result = Dbl::qx_apply($this->dblink,
                self::action_log_query
                . " select ?, ?, ?, ?, ?, ?, ? from dual where not exists (select * from ActionLog where logId>=coalesce((select max(logId) from ActionLog),0)-199 and ipaddr<=>? and contactId<=>? and destContactId<=>? and trueContactId<=>? and paperId<=>? and timestamp>=?-3600 and action<=>?)",
                array_merge($values[0], $values[0]));
            if (!$result->is_error()) {
                return;
            }
        }
        $this->qe(self::action_log_query . " values ?v", $values);
    }

    /** @return list<list<string>> */
    private static function format_log_values($text, $user, $dest_user, $true_user, $pids) {
        if (empty($pids)) {
            $pids = [null];
        }
        $addr = $_SERVER["REMOTE_ADDR"] ?? null;
        $user = (int) $user;
        $dest_user = (int) $dest_user;
        if ($dest_user === 0 || $dest_user === $user) {
            $dest_user = null;
        }
        $true_user = (int) $true_user;
        if ($true_user === 0) {
            $true_user = null;
        }
        $l = 0;
        $n = count($pids);
        $result = [];
        while ($l < $n) {
            $t = $text;
            $r = $n;
            while ($l + 1 !== $r) {
                $t = $text . " (papers ";
                if ($l === 0 && $r === $n) {
                    $t .= join(", ", $pids);
                } else {
                    $t .= join(", ", array_slice($pids, $l, $r - $l, true));
                }
                $t .= ")";
                if (strlen($t) <= 4096) {
                    break;
                }
                $r = $l + max(1, ($r - $l) >> 1);
            }
            if ($l + 1 === $r) {
                $pid = $pids[$l];
                $t = substr($text, 0, 4096);
            } else {
                $pid = null;
                $t = substr($t, 0, 4096);
            }
            $result[] = [$addr, $user, $dest_user, $true_user, $pid, Conf::$now, $t];
            $l = $r;
        }
        return $result;
    }


    // messages

    /** @return Fmt */
    function fmt() {
        if (!$this->_fmt) {
            $this->_fmt = new Fmt($this);
            $this->_fmt->add_requirement_resolver([$this, "resolve_fmt_requirement"]);
            $m = ["?etc/msgs.json"];
            if ($this->lang !== "en") {
                $m[] = "?etc/msgs.{$this->lang}.json";
            }
            $this->_fmt->set_default_priority(-1.0);
            expand_json_includes_callback($m, [$this->_fmt, "addj"]);
            $this->_fmt->set_default_priority(0.0);
            if (($mlist = $this->opt("messageOverrides"))) {
                expand_json_includes_callback($mlist, [$this->_fmt, "addj"]);
            }
            $this->_fmt->define("site", FmtItem::make_template("<0>" . $this->opt["paperSite"], FmtItem::EXPAND_NONE));
            if (($n = $this->opt["messageRecordSources"] ?? null)) {
                $this->_fmt->record_sources($n);
            }
        }
        if ($this->_fmt_override_names !== null) {
            foreach ($this->_fmt_override_names as $id) {
                $this->_fmt->define_override(substr($id, 4), $this->settingTexts[$id]);
            }
            $this->_fmt_override_names = null;
        }
        return $this->_fmt;
    }

    /** @param string $out
     * @return string */
    function _x($out, ...$args) {
        return $this->fmt()->_x($out, ...$args);
    }

    /** @param string $in
     * @return string */
    function _($in, ...$args) {
        return $this->fmt()->_($in, ...$args);
    }

    /** @param string $context
     * @param string $in
     * @return string */
    function _c($context, $in, ...$args) {
        return $this->fmt()->_c($context, $in, ...$args);
    }

    /** @param string $id
     * @return ?string */
    function _i($id, ...$args) {
        return $this->fmt()->_i($id, ...$args);
    }

    /** @param string $context
     * @param string $id
     * @return ?string */
    function _ci($context, $id, ...$args) {
        return $this->fmt()->_ci($context, $id, ...$args);
    }

    /** @param string $in
     * @return string */
    function _5($in, ...$args) {
        return Ftext::as(5, $this->fmt()->_($in, ...$args));
    }

    /** @param string $context
     * @param string $in
     * @return string */
    function _c5($context, $in, ...$args) {
        return Ftext::as(5, $this->fmt()->_c($context, $in, ...$args));
    }

    /** @param string $id
     * @return ?string */
    function _i5($id, ...$args) {
        return Ftext::as(5, $this->fmt()->_i($id, ...$args));
    }

    /** @param string $s
     * @return false|array{true,mixed} */
    function resolve_fmt_requirement($s) {
        if (str_starts_with($s, "setting.")) {
            return [true, $this->setting(substr($s, 8))];
        } else if (str_starts_with($s, "opt.")) {
            return [true, $this->opt(substr($s, 4))];
        } else if ($s === "lang") {
            return [true, $this->lang];
        } else {
            return false;
        }
    }


    // search keywords

    function _xtbuild_add($j) {
        if (is_string($j->name ?? null)) {
            $this->_xtbuild_map[$j->name][] = $j;
            return true;
        } else if (is_string($j->match ?? null)) {
            $this->_xtbuild_factories[] = $j;
            return true;
        } else {
            return false;
        }
    }
    /** @param list<string> $defaults
     * @param ?string $optname
     * @return array{array<string,list<object>>,list<object>} */
    private function _xtbuild($defaults, $optname) {
        $this->_xtbuild_map = $this->_xtbuild_factories = [];
        expand_json_includes_callback($defaults, [$this, "_xtbuild_add"]);
        if ($optname && ($olist = $this->opt($optname))) {
            expand_json_includes_callback($olist, [$this, "_xtbuild_add"]);
        }
        usort($this->_xtbuild_factories, "Conf::xt_priority_compare");
        $a = [$this->_xtbuild_map, $this->_xtbuild_factories];
        $this->_xtbuild_map = $this->_xtbuild_factories = null;
        return $a;
    }
    /** @param list<string> $defaults
     * @param ?string $optname
     * @return array<string,object> */
    function _xtbuild_resolve($defaults, $optname) {
        list($x, $unused) = $this->_xtbuild($defaults, $optname);
        $a = [];
        $xtp = new XtParams($this, null);
        foreach (array_keys($x) as $name) {
            if (($uf = $xtp->search_name($x, $name)))
                $a[$name] = $uf;
        }
        uasort($a, "Conf::xt_pure_order_compare");
        return $a;
    }

    private function make_search_keyword_map() {
        list($this->_search_keyword_base, $this->_search_keyword_factories) =
            $this->_xtbuild(["etc/searchkeywords.json"], "searchKeywords");
    }
    /** @return ?object */
    function search_keyword($keyword, Contact $user = null) {
        if ($this->_search_keyword_base === null) {
            $this->make_search_keyword_map();
        }
        $xtp = new XtParams($this, $user);
        $uf = $xtp->search_name($this->_search_keyword_base, $keyword);
        $ufs = $xtp->search_factories($this->_search_keyword_factories, $keyword, $uf);
        return self::xt_resolve_require($ufs[0]);
    }


    // assignment parsers

    /** @param string $keyword
     * @return ?AssignmentParser */
    function assignment_parser($keyword, Contact $user = null) {
        require_once("assignmentset.php");
        if ($this->_assignment_parsers === null) {
            list($this->_assignment_parsers, $unused) =
                $this->_xtbuild(["etc/assignmentparsers.json"], "assignmentParsers");
        }
        $xtp = new XtParams($this, $user);
        $uf = $xtp->search_name($this->_assignment_parsers, $keyword);
        $uf = self::xt_resolve_require($uf);
        if ($uf && !isset($uf->__parser)) {
            $p = $uf->parser_class;
            $uf->__parser = new $p($this, $uf);
        }
        return $uf ? $uf->__parser : null;
    }


    // autoassigners

    /** @return array<string,object> */
    function autoassigner_map() {
        if ($this->_autoassigners === null) {
            $this->_autoassigners = $this->_xtbuild_resolve(["etc/autoassigners.json"], "autoassigners");
        }
        return $this->_autoassigners;
    }

    /** @param string $name
     * @return ?object */
    function autoassigner($name) {
        return ($this->autoassigner_map())[$name] ?? null;
    }


    // formula functions

    /** @return ?object */
    function formula_function($fname, Contact $user) {
        if ($this->_formula_functions === null) {
            list($this->_formula_functions, $unused) =
                $this->_xtbuild(["etc/formulafunctions.json"], "formulaFunctions");
        }
        $xtp = new XtParams($this, $user);
        $uf = $xtp->search_name($this->_formula_functions, $fname);
        return self::xt_resolve_require($uf);
    }


    // API

    /** @return array<string,list<object>> */
    function api_map() {
        if ($this->_api_map === null) {
            list($this->_api_map, $unused) =
                $this->_xtbuild(["etc/apifunctions.json"], "apiFunctions");
        }
        return $this->_api_map;
    }
    function has_api($fn, Contact $user = null, $method = null) {
        return !!$this->api($fn, $user, $method);
    }
    function api($fn, Contact $user = null, $method = null) {
        $xtp = (new XtParams($this, $user))->add_allow_checker_method($method);
        $uf = $xtp->search_name($this->api_map(), $fn);
        return self::xt_enabled($uf) ? $uf : null;
    }
    /** @return JsonResult */
    function call_api_on($uf, $fn, Contact $user, Qrequest $qreq, $prow) {
        // NOTE: Does not check $user->can_view_paper($prow)
        $method = $qreq->method();
        if ($method !== "GET"
            && $method !== "HEAD"
            && $method !== "OPTIONS"
            && !$qreq->valid_token()
            && (!$uf || ($uf->post ?? false))
            && (!$uf || ($uf->check_token ?? null) !== false)) {
            return JsonResult::make_error(403, "<0>Missing credentials");
        } else if ($user->is_disabled()
                   && (!$uf || !($uf->allow_disabled ?? false))) {
            return JsonResult::make_error(403, "<0>Disabled account");
        } else if (!$uf) {
            if ($this->has_api($fn, $user, null)) {
                return JsonResult::make_error(405, "<0>Method not supported");
            } else if ($this->has_api($fn, null, $qreq->method())) {
                return JsonResult::make_error(403, "<0>Permission error");
            } else {
                return JsonResult::make_error(404, "<0>Function not found");
            }
        } else if (!$prow && ($uf->paper ?? false)) {
            return self::paper_error_json_result($qreq->annex("paper_whynot"));
        } else if (!is_string($uf->function)) {
            return JsonResult::make_error(404, "<0>Function not found");
        } else {
            try {
                self::xt_resolve_require($uf);
                $j = call_user_func($uf->function, $user, $qreq, $prow, $uf);
                if ($j instanceof JsonResult) {
                    return $j;
                } else {
                    return new JsonResult($j);
                }
            } catch (JsonCompletion $ex) {
                return $ex->result;
            }
        }
    }
    /** @param ?PermissionProblem $whynot
     * @return JsonResult */
    static function paper_error_json_result($whynot) {
        $result = ["ok" => false, "message_list" => []];
        if ($whynot) {
            $status = isset($whynot["noPaper"]) ? 404 : 403;
            array_push($result["message_list"], ...$whynot->message_list(null, 2));
            if (isset($whynot["signin"])) {
                $result["loggedout"] = true;
            }
        } else {
            $status = 400;
            $result["message_list"][] = MessageItem::error("<0>Bad request, missing submission");
        }
        return new JsonResult($status, $result);
    }


    // paper columns

    /** @return array<string,list<object>> */
    function paper_column_map() {
        if ($this->_paper_column_map === null) {
            require_once("papercolumn.php");
            list($this->_paper_column_map, $this->_paper_column_factories) =
                $this->_xtbuild(["etc/papercolumns.json"], "paperColumns");
        }
        return $this->_paper_column_map;
    }
    /** @return list<object> */
    function paper_column_factories() {
        if ($this->_paper_column_map === null) {
            $this->paper_column_map();
        }
        return $this->_paper_column_factories;
    }
    /** @return ?object */
    function basic_paper_column($name, Contact $user = null) {
        $xtp = new XtParams($this, $user);
        $uf = $xtp->search_name($this->paper_column_map(), $name);
        return self::xt_enabled($uf) ? $uf : null;
    }
    /** @param string $name
     * @param Contact|XtParams $ctx
     * @return list<object> */
    function paper_columns($name, $ctx) {
        if ($name === "" || $name[0] === "?") {
            return [];
        }
        if ($ctx instanceof Contact) {
            $xtp = new XtParams($this, $ctx);
            $xtp->reflags = "i";
        } else {
            $xtp = $ctx;
            $xtp->last_match = null;
            assert($xtp->reflags === "i");
        }
        $uf = $xtp->search_name($this->paper_column_map(), $name);
        $ufs = $xtp->search_factories($this->paper_column_factories(), $name, $uf);
        return array_values(array_filter($ufs, "Conf::xt_resolve_require"));
    }


    // option types

    /** @return array<string,object> */
    function option_type_map() {
        if ($this->_option_type_map === null) {
            require_once("paperoption.php");
            $this->_option_type_map = $this->_xtbuild_resolve(["etc/optiontypes.json"], "optionTypes");
        }
        return $this->_option_type_map;
    }

    /** @param string $name
     * @return ?object */
    function option_type($name) {
        return ($this->option_type_map())[$name] ?? null;
    }


    // review field types

    /** @return array<string,object> */
    function review_field_type_map() {
        if ($this->_rfield_type_map === null) {
            $this->_rfield_type_map = $this->_xtbuild_resolve(["etc/reviewfieldtypes.json"], "reviewFieldTypes");
        }
        return $this->_rfield_type_map;
    }

    /** @param string $name
     * @return ?object */
    function review_field_type($name) {
        return ($this->review_field_type_map())[$name] ?? null;
    }


    // tokens

    function _add_token_json($j) {
        if (is_string($j->match ?? null) || is_int($j->type ?? null)) {
            $this->_token_factories[] = $j;
            return true;
        } else {
            return false;
        }
    }

    private function load_token_types() {
        if ($this->_token_factories === null) {
            $this->_token_types = $this->_token_factories = [];
            expand_json_includes_callback(["etc/capabilityhandlers.json"], [$this, "_add_token_json"]);
            if (($olist = $this->opt("capabilityHandlers"))) {
                expand_json_includes_callback($olist, [$this, "_add_token_json"]);
            }
            usort($this->_token_factories, "Conf::xt_priority_compare");
        }
    }

    /** @param string $token
     * @return ?object */
    function token_handler($token) {
        if ($this->_token_factories === null) {
            $this->load_token_types();
        }
        $xtp = new XtParams($this, null);
        $ufs = $xtp->search_factories($this->_token_factories, $token, null);
        return $ufs[0];
    }

    /** @param int $type
     * @return ?object */
    function token_type($type) {
        if ($this->_token_factories === null) {
            $this->load_token_types();
        }
        if (!array_key_exists($type, $this->_token_types)) {
            $m = [];
            foreach ($this->_token_factories as $tf) {
                if (($tf->type ?? null) === $type)
                    $m[$type][] = $tf;
            }
            $xtp = new XtParams($this, null);
            /** @phan-suppress-next-line PhanTypeMismatchArgument */
            $this->_token_types[$type] = $xtp->search_name($m, $type);
        }
        return $this->_token_types[$type];
    }

    private function clean_tokens() {
        if ($this->_token_factories === null) {
            $this->load_token_types();
        }
        $ct_cleanups = [];
        foreach ($this->_token_factories as $tf) {
            if (isset($tf->type) && is_int($tf->type) && isset($tf->cleanup_function))
                $ct_cleanups[$tf->type] = true;
        }
        if (!empty($ct_cleanups)) {
            $result = TokenInfo::expired_tokens_result($this, array_keys($ct_cleanups));
            while (($tok = TokenInfo::fetch($result, $this, false))) {
                if (($tf = $this->token_type($tok->capabilityType))
                    && isset($tf->cleanup_function))
                    call_user_func($tf->cleanup_function, $tok, $this);
            }
            Dbl::free($result);
        }
        $this->ql("delete from Capability where timeExpires>0 and timeExpires<".Conf::$now);
        $this->ql("insert into Settings set name='__capability_gc', value=? on duplicate key update value=?", Conf::$now, Conf::$now);
        $this->settings["__capability_gc"] = Conf::$now;
    }


    // mail: keywords, templates, DKIM

    /** @return array<string,list<object>> */
    private function mail_keyword_map() {
        if ($this->_mail_keyword_map === null) {
            list($this->_mail_keyword_map, $this->_mail_keyword_factories) =
                $this->_xtbuild(["etc/mailkeywords.json"], "mailKeywords");
        }
        return $this->_mail_keyword_map;
    }

    /** @param string $name
     * @return list<object> */
    function mail_keywords($name) {
        $xtp = new XtParams($this, null);
        $uf = $xtp->search_name($this->mail_keyword_map(), $name);
        $ufs = $xtp->search_factories($this->_mail_keyword_factories, $name, $uf);
        return array_values(array_filter($ufs, "Conf::xt_resolve_require"));
    }


    /** @return array<string,list<object>> */
    function mail_template_map() {
        if ($this->_mail_template_map === null) {
            $this->_mail_template_map =
                ($this->_xtbuild(["etc/mailtemplates.json"], "mailTemplates"))[0];
            foreach ($this->_mail_template_map as $olist) {
                foreach ($olist as $j) {
                    if (isset($j->body) && is_array($j->body)) {
                        $j->body = join("", $j->body);
                    }
                    if (!isset($j->allow_template) && ($j->title ?? null)) {
                        $j->allow_template = true;
                    }
                }
            }
        }
        return $this->_mail_template_map;
    }

    /** @param string $name
     * @param bool $default_only
     * @param ?Contact $user
     * @return ?object */
    function mail_template($name, $default_only = false, $user = null) {
        $xtp = new XtParams($this, $user);
        $uf = $xtp->search_name($this->mail_template_map(), $name);
        if (!$uf || !Conf::xt_resolve_require($uf)) {
            return null;
        }
        if (!$default_only) {
            $se = $this->has_setting("mailsubj_{$name}");
            $s = $se ? $this->setting_data("mailsubj_{$name}") : null;
            $be = $this->has_setting("mailbody_{$name}");
            $b = $be ? $this->setting_data("mailbody_{$name}") : null;
            if (($se && $s !== $uf->subject)
                || ($be && $b !== $uf->body)) {
                $uf = clone $uf;
                if ($se) {
                    $uf->subject = $s;
                }
                if ($be) {
                    $uf->body = $b;
                }
            }
        }
        return $uf;
    }

    /** @return ?DKIMSigner */
    function dkim_signer() {
        if ($this->_dkim_signer === false) {
            if (($dkim = $this->opt("dkimConfig"))) {
                $this->_dkim_signer = DKIMSigner::make($dkim);
            } else {
                $this->_dkim_signer = null;
            }
        }
        return $this->_dkim_signer;
    }


    // hooks

    function _add_hook_json($fj) {
        if (isset($fj->function) && is_string($fj->function)) {
            if (isset($fj->event) && is_string($fj->event)) {
                return self::xt_add($this->_hook_map, $fj->event, $fj);
            } else if (isset($fj->match) && is_string($fj->match)) {
                $this->_hook_factories[] = $fj;
                return true;
            }
        }
        return false;
    }
    function add_hook($name, $function = null, $priority = null) {
        if ($this->_hook_map === null) {
            $this->hook_map();
        }
        $fj = is_object($name) ? $name : $function;
        if (is_string($fj)) {
            $fj = (object) ["function" => $fj];
        }
        if (is_string($name)) {
            $fj->event = $name;
        }
        if ($priority !== null) {
            $fj->priority = $priority;
        }
        return $this->_add_hook_json($fj) ? $fj : false;
    }
    function remove_hook($fj) {
        if (isset($fj->event) && is_string($fj->event)
            && isset($this->_hook_map[$fj->event])
            && ($i = array_search($fj, $this->_hook_map[$fj->event], true)) !== false) {
            array_splice($this->_hook_map[$fj->event], $i, 1);
            return true;
        } else if (isset($fj->match) && is_string($fj->match)
                   && ($i = array_search($fj, $this->_hook_factories, true)) !== false) {
            array_splice($this->_hook_factories, $i, 1);
            return true;
        }
        return false;
    }
    private function hook_map() {
        if ($this->_hook_map === null) {
            $this->_hook_map = $this->_hook_factories = [];
            if (($hlist = $this->opt("hooks"))) {
                expand_json_includes_callback($hlist, [$this, "_add_hook_json"]);
            }
        }
        return $this->_hook_map;
    }
    function call_hooks($name, Contact $user = null, ...$args) {
        $hs = ($this->hook_map())[$name] ?? null;
        foreach ($this->_hook_factories as $fj) {
            if ($fj->match === ".*"
                || preg_match("\1\\A(?:{$fj->match})\\z\1", $name, $m)) {
                $xfj = clone $fj;
                $xfj->event = $name;
                $xfj->match_data = $m;
                $hs = $hs ?? [];
                $hs[] = $xfj;
            }
        }
        if ($hs !== null) {
            usort($hs, "Conf::xt_priority_compare");
            $ids = [];
            foreach ($hs as $fj) {
                if ((!isset($fj->id) || !isset($ids[$fj->id]))
                    && XtParams::static_allowed($fj, $this, $user)) {
                    if (isset($fj->id)) {
                        $ids[$fj->id] = true;
                    }
                    if (self::xt_enabled($fj)) {
                        $fj->conf = $this;
                        $fj->user = $user;
                        $x = call_user_func($fj->function, $fj, ...$args);
                        unset($fj->conf, $fj->user);
                        if ($x === false) {
                            return false;
                        }
                    }
                }
            }
        }
    }


    // pages

    /** @return ComponentSet */
    function page_components(Contact $viewer, Qrequest $qreq) {
        $pc = $this->_page_components;
        if (!$pc
            || $pc->viewer() !== $viewer
            || $pc->arg(1) !== $qreq) {
            $pc = new ComponentSet($viewer, ["etc/pages.json"], $this->opt("pages"));
            $pc->set_context_args($viewer, $qreq, $pc);
            $pc->add_xt_checker([$qreq, "xt_allow"]);
            $this->_page_components = $pc;
        }
        return $pc;
    }


    // setting info

    /** @return SettingInfoSet */
    function si_set() {
        $this->_setting_info = $this->_setting_info ?? SettingInfoSet::make_conf($this);
        return $this->_setting_info;
    }

    /** @param string $name
     * @return ?Si */
    function si($name) {
        return $this->si_set()->get($name);
    }
}
