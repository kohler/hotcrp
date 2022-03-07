<?php
// siteloader.php -- HotCRP autoloader
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class SiteLoader {
    static $map = [
        "AbbreviationEntry" => "lib/abbreviationmatcher.php",
        "Assignable" => "src/assignmentset.php",
        "AssignmentParser" => "src/assignmentset.php",
        "AutoassignerCosts" => "src/autoassigner.php",
        "Collator" => "lib/collatorshim.php",
        "CsvGenerator" => "lib/csv.php",
        "CsvParser" => "lib/csv.php",
        "Fexpr" => "src/formula.php",
        "FormulaCall" => "src/formula.php",
        "FormatChecker" => "src/formatspec.php",
        "GroupedExtensions" => "src/componentset.php", /* XXX backward compat */
        "HashAnalysis" => "lib/filer.php",
        "JsonSerializable" => "lib/json.php",
        "LogEntryGenerator" => "src/logentry.php",
        "LoginHelper" => "lib/login.php",
        "MessageItem" => "lib/messageset.php",
        "PaperInfoSet" => "src/paperinfo.php",
        "PaperOptionList" => "src/paperoption.php",
        "PaperValue" => "src/paperoption.php",
        "ReviewField" => "src/review.php",
        "ReviewFieldInfo" => "src/review.php",
        "ReviewForm" => "src/review.php",
        "ReviewSearchMatcher" => "src/search/st_review.php",
        "ReviewValues" => "src/review.php",
        "SearchTerm" => "src/papersearch.php",
        "SearchWord" => "src/papersearch.php",
        "SettingInfoSet" => "src/si.php",
        "SettingParser" => "src/settingvalues.php",
        "StreamS3Result" => "lib/s3result.php",
        "TagAnno" => "lib/tagger.php",
        "TagInfo" => "lib/tagger.php",
        "TagMap" => "lib/tagger.php",
        "TextPregexes" => "lib/text.php",
        "Text_PaperOption" => "src/paperoption.php",
        "XlsxGenerator" => "lib/xlsx.php"
    ];

    static $suffix_map = [
        "_api.php" => ["api_", "src/api"],
        "_assignable.php" => ["a_", "src/assigners"],
        "_assigner.php" => ["a_", "src/assigners"],
        "_assignmentparser.php" => ["a_", "src/assigners"],
        "_capability.php" => ["cap_", "src/capabilities"],
        "_fexpr.php" =>  ["f_", "src/formulas"],
        "_helptopic.php" => ["h_", "src/help"],
        "_listaction.php" => ["la_", "src/listactions"],
        "_papercolumn.php" => ["pc_", "src/papercolumns"],
        "_papercolumnfactory.php" => ["pc_", "src/papercolumns"],
        "_paperoption.php" => ["o_", "src/options"],
        "_page.php" => ["p_", "src/pages"],
        "_partial.php" => ["p_", "src/pages"],
        "_searchterm.php" => ["st_", "src/search"],
        "_settingrenderer.php" => ["s_", "src/settings"],
        "_settingparser.php" => ["s_", "src/settings"],
        "_sitype.php" => ["s_", "src/settings"],
        "_tester.php" => ["t_", "test"],
        "_userinfo.php" => ["u_", "src/userinfo"]
    ];

    /** @var string */
    static public $root;

    static function set_root() {
        self::$root = __DIR__;
        while (self::$root !== ""
               && !file_exists(self::$root . "/src/init.php")) {
            self::$root = substr(self::$root, 0, strrpos(self::$root, "/"));
        }
        if (self::$root === "") {
            self::$root = "/var/www/html";
        }
    }

    /** @param non-empty-string $suffix
     * @return string */
    static function find($suffix) {
        if ($suffix[0] === "/") {
            return self::$root . $suffix;
        } else {
            return self::$root . "/" . $suffix;
        }
    }

    // Set up conference options
    /** @return list<string> */
    static private function expand_includes_once($file, $includepath, $globby) {
        foreach ($file[0] === "/" ? [""] : $includepath as $idir) {
            $try = $idir . $file;
            if (!$globby && is_readable($try)) {
                return [$try];
            } else if ($globby && ($m = glob($try, GLOB_BRACE))) {
                return $m;
            }
        }
        return [];
    }

    /** @param string|list<string> $files
     * @return list<string> */
    static function expand_includes($files, $expansions = []) {
        global $Opt;
        if (!is_array($files)) {
            $files = [$files];
        }
        $confname = $Opt["confid"] ?? $Opt["dbName"] ?? null;
        $expansions["confid"] = $expansions["confname"] = $confname;
        $expansions["siteclass"] = $Opt["siteclass"] ?? null;
        $root = self::$root;

        if (isset($expansions["autoload"]) && strpos($files[0], "/") === false) {
            $includepath = ["{$root}/src/", "{$root}/lib/"];
        } else {
            $includepath = ["{$root}/"];
        }

        $oincludepath = $Opt["includePath"] ?? $Opt["includepath"] ?? null;
        if (is_array($oincludepath)) {
            foreach ($oincludepath as $i) {
                if ($i)
                    $includepath[] = str_ends_with($i, "/") ? $i : $i . "/";
            }
        }

        $results = [];
        foreach ($files as $f) {
            if (strpos((string) $f, '$') !== false) {
                foreach ($expansions as $k => $v) {
                    if ($v !== false && $v !== null) {
                        $f = preg_replace("/\\\$\\{{$k}\\}|\\\${$k}\\b/", $v, $f);
                    } else if (preg_match("/\\\$\\{{$k}\\}|\\\${$k}\\b/", $f)) {
                        $f = "";
                        break;
                    }
                }
            }
            if ((string) $f === "") {
                continue;
            }
            $matches = [];
            $ignore_not_found = $globby = false;
            if ($f[0] === "?") {
                $ignore_not_found = true;
                $f = substr($f, 1);
            }
            if (preg_match('/[\[\]\*\?\{\}]/', $f)) {
                $ignore_not_found = $globby = true;
            }
            $matches = self::expand_includes_once($f, $includepath, $globby);
            if (empty($matches)
                && isset($expansions["autoload"])
                && ($underscore = strrpos($f, "_"))
                && ($f2 = SiteLoader::$suffix_map[substr($f, $underscore)] ?? null)) {
                $xincludepath = array_merge($f2[1] ? ["{$root}/{$f2[1]}/"] : [], $includepath);
                $matches = self::expand_includes_once($f2[0] . substr($f, 0, $underscore) . ".php", $xincludepath, $globby);
            }
            $results = array_merge($results, $matches);
            if (empty($matches) && !$ignore_not_found) {
                $results[] = $f[0] === "/" ? $f : $includepath[0] . $f;
            }
        }
        return $results;
    }

    /** @param string $file */
    static function read_options_file($file) {
        global $Opt;
        if ((@include $file) !== false) {
            $Opt["loaded"][] = $file;
        } else {
            $Opt["missing"][] = $file;
        }
    }

    static function read_main_options() {
        $file = defined("HOTCRP_OPTIONS") ? HOTCRP_OPTIONS : self::$root . "/conf/options.php";
        self::read_options_file($file);
    }

    static function read_included_options() {
        global $Opt;
        '@phan-var array<string,mixed> $Opt';
        if (is_string($Opt["include"])) {
            $Opt["include"] = [$Opt["include"]];
        }
        for ($i = 0; $i !== count($Opt["include"]); ++$i) {
            foreach (self::expand_includes($Opt["include"][$i]) as $f) {
                if (!in_array($f, $Opt["loaded"])) {
                    self::read_options_file($f);
                }
            }
        }
    }

    static function autoloader($class_name) {
        $f = self::$map[$class_name] ?? strtolower($class_name) . ".php";
        foreach (self::expand_includes($f, ["autoload" => true]) as $fx) {
            require_once($fx);
        }
    }
}

SiteLoader::set_root();
spl_autoload_register("SiteLoader::autoloader");
