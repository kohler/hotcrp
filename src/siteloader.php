<?php
// siteloader.php -- HotCRP autoloader
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class SiteLoader {
    static $map = [
        "AbbreviationEntry" => "lib/abbreviationmatcher.php",
        "Assignable" => "src/assignmentset.php",
        "AssignmentParser" => "src/assignmentset.php",
        "AutoassignerCosts" => "src/autoassigner.php",
        "Collator" => "lib/collatorshim.php",
        "CommandLineException" => "lib/getopt.php",
        "CsvGenerator" => "lib/csv.php",
        "CsvParser" => "lib/csv.php",
        "Discrete_ReviewField" => "src/reviewfield.php",
        "DiscreteValues_ReviewField" => "src/reviewfield.php",
        "Document_PaperOption" => "src/paperoption.php",
        "False_SearchTerm" => "src/searchterm.php",
        "Fexpr" => "src/formula.php",
        "FmtArg" => "lib/fmt.php",
        "FormulaCall" => "src/formula.php",
        "FormatChecker" => "src/formatspec.php",
        "JsonSerializable" => "lib/json.php",
        "Limit_SearchTerm" => "src/searchterm.php",
        "LogEntryGenerator" => "src/logentry.php",
        "LoginHelper" => "lib/login.php",
        "MessageItem" => "lib/messageset.php",
        "PaperInfoSet" => "src/paperinfo.php",
        "QrequestFile" => "lib/qrequest.php",
        "ReviewFieldInfo" => "src/reviewfield.php",
        "ReviewValues" => "src/reviewform.php",
        "StreamS3Result" => "lib/s3result.php",
        "TagAnno" => "lib/tagger.php",
        "TagInfo" => "lib/tagger.php",
        "TagMap" => "lib/tagger.php",
        "TextPregexes" => "lib/text.php",
        "Text_PaperOption" => "src/paperoption.php",
        "True_SearchTerm" => "src/searchterm.php",
        "XlsxGenerator" => "lib/xlsx.php",
        "dmp\\diff_match_patch" => "lib/diff_match_patch.php"
    ];

    static $suffix_map = [
        "_api.php" => ["api_", "src/api"],
        "_assignable.php" => ["a_", "src/assigners"],
        "_assigner.php" => ["a_", "src/assigners"],
        "_assignmentparser.php" => ["a_", "src/assigners"],
        "_autoassigner.php" => ["aa_", "src/autoassigners"],
        "_batch.php" => ["", "batch"],
        "_capability.php" => ["cap_", "src/capabilities"],
        "_fexpr.php" =>  ["f_", "src/formulas"],
        "_helptopic.php" => ["h_", "src/help"],
        "_listaction.php" => ["la_", "src/listactions"],
        "_papercolumn.php" => ["pc_", "src/papercolumns"],
        "_papercolumnfactory.php" => ["pc_", "src/papercolumns"],
        "_paperoption.php" => ["o_", "src/options"],
        "_page.php" => ["p_", "src/pages"],
        "_partial.php" => ["p_", "src/pages"],
        "_reviewfield.php" => ["rf_", "src/reviewfields"],
        "_reviewfieldsearch.php" => ["rf_", "src/reviewfields"],
        "_searchterm.php" => ["st_", "src/search"],
        "_setting.php" => ["s_", "src/settings"],
        "_settingrenderer.php" => ["s_", "src/settings"],
        "_settingparser.php" => ["s_", "src/settings"],
        "_sitype.php" => ["si_", "src/settings"],
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
    /** @param string $file
     * @param list<string> $includepath
     * @param bool $globby
     * @return list<string> */
    static private function expand_includes_once($file, $includepath, $globby) {
        foreach ($file[0] === "/" ? [""] : $includepath as $idir) {
            if ($file[0] === "/") {
                $try = $file;
            } else if (!$idir) {
                continue;
            } else if (str_ends_with($idir, "/")) {
                $try = "{$idir}{$file}";
            } else {
                $try = "{$idir}/{$file}";
            }
            if (!$globby && is_readable($try)) {
                return [$try];
            } else if ($globby && ($m = glob($try, GLOB_BRACE))) {
                return $m;
            } else if ($file[0] === "/") {
                return [];
            }
        }
        return [];
    }

    /** @param string $s
     * @param array<string,string> $expansions
     * @param int $pos
     * @param bool $useOpt
     * @return string */
    static function substitute($s, $expansions, $pos = 0, $useOpt = false) {
        global $Opt;
        while (($pos = strpos($s, '${', $pos)) !== false) {
            $rbrace = strpos($s, '}', $pos + 2);
            if ($rbrace === false
                || ($key = substr($s, $pos + 2, $rbrace - $pos - 2)) === "") {
                $pos += 2;
                continue;
            }
            if (array_key_exists($key, $expansions)) {
                $value = $expansions[$key];
            } else if ($useOpt && ($key === "confid" || $key === "confname")) {
                $value = $Opt["confid"] ?? $Opt["dbName"] ?? null;
            } else if ($useOpt && $key === "siteclass") {
                $value = $Opt["siteclass"] ?? null;
            } else {
                $pos += 2;
                continue;
            }
            if ($value === false || $value === null) {
                return "";
            }
            $s = substr($s, 0, $pos) . $value . substr($s, $rbrace + 1);
            $pos += strlen($value);
        }
        return $s;
    }

    /** @param ?string $root
     * @param string|list<string> $files
     * @param array<string,string> $expansions
     * @return list<string> */
    static function expand_includes($root, $files, $expansions = []) {
        global $Opt;

        $root = $root ?? self::$root;
        $autoload = $expansions["autoload"] ?? 0;
        $includepath = null;

        $results = [];
        foreach (is_array($files) ? $files : [$files] as $f) {
            $f = (string) $f;
            if ($f !== "" && !$autoload && ($pos = strpos($f, '${')) !== false) {
                $f = self::substitute($f, $expansions, $pos, true);
            }
            if ($f === "") {
                continue;
            }

            $ignore_not_found = !$autoload && $f[0] === "?";
            $globby = false;
            if ($ignore_not_found) {
                $f = substr($f, 1);
            }
            if (!$autoload && strpbrk($f, "[]*?{}") !== false) {
                $ignore_not_found = $globby = true;
            }

            $f2 = null;
            $matches = [];
            if ($autoload && strpos($f, "/") === false) {
                if (($underscore = strrpos($f, "_"))
                    && ($pfxsubdir = SiteLoader::$suffix_map[substr($f, $underscore)] ?? null)) {
                    $f2 = $pfxsubdir[0] . substr($f, 0, $underscore) . ".php";
                    if (is_readable(($fx = "{$root}/{$pfxsubdir[1]}/{$f2}"))) {
                        return [$fx];
                    }
                }
                if (is_readable(($fx = "{$root}/lib/{$f}"))
                    || is_readable(($fx = "{$root}/src/{$f}"))) {
                    return [$fx];
                }
            } else if (!$globby
                       && !str_starts_with($f, "/")
                       && is_readable(($fx = "{$root}/{$f}"))) {
                $matches = [$fx];
            } else {
                $matches = self::expand_includes_once($f, [$root], $globby);
            }
            if (empty($matches) && $includepath === null) {
                global $Opt;
                $includepath = $Opt["includePath"] ?? $Opt["includepath"] /* XXX */ ?? [];
            }
            if (empty($matches) && !empty($includepath)) {
                if ($f2 !== null) {
                    $matches = self::expand_includes_once($f2, $includepath, false);
                }
                if (empty($matches)) {
                    $matches = self::expand_includes_once($f, $includepath, $globby);
                }
            }
            if (empty($matches) && !$ignore_not_found) {
                $matches = [$f[0] === "/" ? $f : "{$root}/{$f}"];
            }
            $results = array_merge($results, $matches);
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

    /** @param ?string $file
     * @param ?string $confid */
    static function read_main_options($file, $confid) {
        global $Opt;
        $Opt = $Opt ?? [];
        $file = $file ?? (defined("HOTCRP_OPTIONS") ? HOTCRP_OPTIONS : "conf/options.php");
        if (!str_starts_with($file, "/")) {
            $file = self::$root . "/{$file}";
        }
        self::read_options_file($file);
        if ($Opt["multiconference"] ?? null) {
            Multiconference::init($confid);
        } else if ($confid !== null) {
            if (!isset($Opt["confid"])) {
                $Opt["confid"] = $confid;
            } else if ($Opt["confid"] !== $confid) {
                $Opt["missing"][] = "__invalid__";
            }
        }
        if (empty($Opt["missing"]) && !empty($Opt["include"])) {
            self::read_included_options();
        }
    }

    /** @param ?string $root */
    static function read_included_options($root = null) {
        global $Opt;
        '@phan-var array<string,mixed> $Opt';
        if (is_string($Opt["include"])) {
            $Opt["include"] = [$Opt["include"]];
        }
        $root = $root ?? self::$root;
        for ($i = 0; $i !== count($Opt["include"]); ++$i) {
            foreach (self::expand_includes($root, $Opt["include"][$i]) as $f) {
                if (!in_array($f, $Opt["loaded"])) {
                    self::read_options_file($f);
                }
            }
        }
    }

    /** @param string $class_name */
    static function autoload($class_name) {
        $f = self::$map[$class_name] ?? strtolower($class_name) . ".php";
        foreach (self::expand_includes(self::$root, $f, ["autoload" => true]) as $fx) {
            require_once($fx);
        }
    }
}

SiteLoader::set_root();
spl_autoload_register("SiteLoader::autoload");
