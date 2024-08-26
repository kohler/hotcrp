<?php
// cleancountries.php -- HotCRP maintenance script
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class CleanCountries_Batch {
    /** @var \mysqli */
    public $dblink;
    /** @var bool */
    public $dry_run;

    static public $synonyms = [
        "USA" => "US",
        "united states of america" => "US",
        "United States of America" => "US",
        "South Korea" => "KR",
        "Côte d’Ivoire" => "CI",
        "Korea, North – Democratic People’s Republic of Korea" => "KP",
        "Türkiye" => "TR",
        "Perú" => "PE",
        "Belgique" => "BE",
        "Brunei" => "BN",
        "Brasil" => "BR",
        "Russia" => "RU",
        "Macedonia" => "MK",
        "Tanzania" => "TZ",
        "UK" => "GB",
        "UAE" => "AE",
        "Korea, North – Democratic People's Republic of Korea" => "KP",
        "Korea, South – Republic of Korea" => "KR",
        "China 中国" => "CN",
        "China 中国!" => "CN",
        "中国" => "CN",
        "中国大陆" => "CN",
        "中國" => "CN",
        "台灣" => "TW",
        "德国" => "DE",
        "新加坡" => "SG",
        "Singapore 新加坡" => "SG",
        "日本" => "JP",
        "澳大利亚" => "AU",
        "瑞典" => "SE",
        "美国" => "US",
        "英国" => "GB",
        "西班牙" => "ES",
        "韩国，南韩 – 大韩民国" => "KR",
        "대한민국" => "KR",
        "일본" => "JP",
        "한국, 남한 – 대한민국" => "KR",
        "한국, 대한민국 – 대한민국" => "KR",
        "Burma" => "MM",
        "Cape Verde" => "CV",
        "Federated States of Micronesia" => "FM",
        "Gambia" => "GM",
        "Hong Kong" => "HK",
        "Hong Kong Special Administrative Region of China" => "HK",
        "MalaysiaǢ0" => "MY",
        "Moldova" => "MD",
        "O'zbekiston" => "UZ",
        "Palestine" => "PS",
        "Swaziland" => "SZ",
        "The Netherlands" => "NL",
        "United States of America美国" => "US",
        "Соединенное Королевство" => "GB",
        "Соединенные Штаты Америки" => "US",
        "Украина" => "UA",
        "Швеция" => "SE",
        "الإمارات العربية المتحدة" => "AE",
        "اليمن" => "YE",
        "ایران" => "IR",
        "مصر" => "EG",
        "ประเทศไทย" => "TH"
    ];

    function __construct(?Dbl_ConnectionParams $cp, $arg) {
        if (!$cp || !($this->dblink = $cp->connect())) {
            throw new CommandLineException("Cannot connect to database");
        }
        $this->dry_run = isset($arg["d"]);
    }

    /** @param \mysqli $dblink
     * @param bool $save
     * @return bool|list<string> */
    static function clean($dblink, $save) {
        // load countries
        $list = Dbl::fetch_first_columns($dblink, "select distinct country from ContactInfo");

        // translate
        $translate = $list2 = [];
        foreach ($list as $c) {
            if ($c === null || $c === "" || isset(Countries::$map[$c])) {
                /* OK as is */
            } else if (($code = array_search($c, Countries::$map))) {
                $translate[$c] = $code;
            } else if (($code = self::$synonyms[$c] ?? null) !== null) {
                assert(isset(Countries::$map[$code]));
                $translate[$c] = $code;
            } else {
                $list2[] = $c;
            }
        }

        if ($save) {
            $updatef = Dbl::make_multi_ql_stager($dblink);
            foreach ($translate as $text => $code) {
                $updatef("update ContactInfo set country=? where country=?", $code, $text);
            }
            $updatef(null);
        }
        return $list2 ? : true;
    }

    /** @return int */
    function run() {
        $list = self::clean($this->dblink, !$this->dry_run);
        if ($list !== true) {
            echo join("\n", $list), "\n";
        }
        return 0;
    }

    /** @param list<string> $argv
     * @return CleanCountries_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "cdb",
            "d,dry-run"
        )->description("Clean countries in a HotCRP database.
Usage: php batch/cleancountries.php [-n CONFID] [--cdb] [--dry-run]")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        global $Opt;
        $Opt["__no_main"] = true;
        initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        if (isset($arg["cdb"])) {
            $cp = Conf::contactdb_connection_params($Opt);
        } else {
            $cp = Dbl::parse_connection_params($Opt);
        }
        return new CleanCountries_Batch($cp, $arg);
    }
}

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(CleanCountries_Batch::make_args($argv)->run());
}
