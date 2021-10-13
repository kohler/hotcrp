<?php
require_once(dirname(__DIR__) . "/src/siteloader.php");

$arg = Getopt::rest($argv, "hn:f:Vuto:",
    ["help", "name:", "file:", "verbose", "unparse", "time", "output:"]);
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/updateutf8trans.php CODEPOINT STRING...\n");
    fwrite(STDOUT, "       php batch/updateutf8trans.php -f UnicodeData.txt\n");
    fwrite(STDOUT, "       php batch/updateutf8trans.php -t [STRING...] [-o OUTPUTFILE]\n");
    exit(0);
}

require_once(SiteLoader::$root . "/lib/unicodehelper.php");

function quote_key($k) {
    if (strlen($k) == 2) {
        return sprintf("\\x%02X\\x%02X", ord($k[0]), ord($k[1]));
    } else {
        return sprintf("\\x%02X\\x%02X\\x%02X", ord($k[0]), ord($k[1]), ord($k[2]));;
    }
}

function numeric_to_utf8($x, $base = 10) {
    $x = is_int($x) ? $x : intval($x, $base);
    if ($x < 0x110000) {
        return UnicodeHelper::utf8_chr($x);
    } else {
        return "";
    }
}


class Batch_UpdateUTF8Trans {
    const OUTL2 = 2;
    const OUTL3 = 3;

    /** @var array<2|3,array<string,string>> */
    public $trans = [2 => [], 3 => []];

    function assign_it($sin, $sout, $override, $description = false) {
        if (is_int($sin)) {
            $sin = UnicodeHelper::utf8_chr($sin);
        }
        if (is_int($sout)) {
            $sout = UnicodeHelper::utf8_chr($sout);
        }
        $sxin = sprintf("U+%04X", UnicodeHelper::utf8_ord($sin));
        if ($description) {
            $sxin .= ";" . $description;
        }
        $l = strlen($sin);
        $soutch = $sout === "*" ? "" : $sout;
        if ($sin === ""
            || ord(substr($sin, 0, 1)) < 0xC0
            || ord(substr($sin, 0, 1)) >= 0xF0) {
            fwrite(STDERR, "input argument {$sxin}=>{$sout} is bad\n");
        } else if (strlen($sout) > ($l > 2 ? self::OUTL3 : self::OUTL2)) {
            fwrite(STDERR, "output argument {$sxin}=>{$sout} too long\n");
        } else if (($sout === "" && !isset($this->trans[$l][$sin]))
                   || (isset($this->trans[$l][$sin]) && $this->trans[$l][$sin] === $soutch)) {
            /* skip */
        } else if (!$override && isset($this->trans[$l][$sin])) {
            fwrite(STDERR, "ignore $sin {$sxin}=>{$sout}, have {$sxin}=>" . sprintf("U+%04X", UnicodeHelper::utf8_ord($this->trans[$l][$sin])) . "\n");
        } else if ($sout === "") {
            fwrite(STDERR, "change $sin {$sxin}=>\n");
            unset($this->trans[strlen($sin)][$sin]);
        } else {
            fwrite(STDERR, "change $sin {$sxin}=>{$sout}\n");
            $this->trans[strlen($sin)][$sin] = $soutch;
        }
    }

    function run_time($arg) {
        if (isset($arg["f"]) || isset($arg["file"])) {
            $filename = $arg["f"] ?? $arg["file"] ?? "";
            if ($filename === "-") {
                $text = stream_get_contents(STDIN);
            } else {
                $text = file_get_contents($filename);
            }
            if ($text === false) {
                fwrite(STDERR, "$filename: Cannot read\n");
                exit(1);
            }
        } else if (!empty($arg["_"])) {
            $text = join(" ", $arg["_"]) . "\n";
        } else {
            $text = stream_get_contents(STDIN);
        }

        $n = 10000000 / strlen($text);
        $begint = microtime(true);
        $textlet = "";
        for ($i = 0; $i < $n; ++$i) {
            $textlet = UnicodeHelper::deaccent($text);
        }
        $endt = microtime(true);

        if (isset($arg["o"]) || isset($arg["output"])) {
            if (str_starts_with($textlet, "\xEF\xBB\xBF")) {
                $textlet = substr($textlet, 3);
            }
            $filename = $arg["o"] ?? $arg["output"] ?? null;
            if ($filename === "-") {
                fwrite(STDOUT, $textlet);
            } else {
                file_put_contents($filename, $textlet);
            }
        }

        fwrite(STDERR, sprintf("%f Mchars/sec\n", (strlen($text) * $i / 1000000.0) / ($endt - $begint)));
        return 0;
    }

    function parse_utf8_alpha_trans() {
        $l = strlen(UTF8_ALPHA_TRANS_2);
        assert($l % 2 == 0);
        $outl = 2;
        assert(strlen(UTF8_ALPHA_TRANS_2_OUT) === ($l / 2) * $outl);
        for ($i = 0; $i < $l; $i += $outl) {
            $this->trans[2][substr(UTF8_ALPHA_TRANS_2, $i, 2)] = rtrim(substr(UTF8_ALPHA_TRANS_2_OUT, $i, $outl));
        }
        $l = strlen(UTF8_ALPHA_TRANS_3);
        assert($l % 3 == 0);
        $outl = 3;
        assert(strlen(UTF8_ALPHA_TRANS_3_OUT) === ($l / 3) * $outl);
        for ($i = $j = 0; $i < $l; $i += 3, $j += $outl) {
            $this->trans[3][substr(UTF8_ALPHA_TRANS_3, $i, 3)] = rtrim(substr(UTF8_ALPHA_TRANS_3_OUT, $j, $outl));
        }
    }

    function parse_file($filename, $unparse, $verbose) {
        $ignore_latin = [0x212B /* ANGSTROM SIGN */ => true];
        if ($filename === "-") {
            $unidata = stream_get_contents(STDIN);
        } else {
            $unidata = file_get_contents($filename);
        }
        if ($unidata === false) {
            fwrite(STDERR, "$filename: Cannot read\n");
            return 1;
        }
        foreach (explode("\n", $unidata) as $line) {
            $line = rtrim($line);
            if ($line === "") {
                continue;
            }
            $uc = explode(";", $line);
            if (count($uc) < 10) {
                continue;
            }
            $c = intval($uc[0], 16);
            $ch = numeric_to_utf8($c);
            if ($unparse) {
                if (isset($this->trans[strlen($ch)][$ch])) {
                    fwrite(STDOUT, "U+$uc[0];" . quote_key($ch) . ";$uc[1] -> " . $this->trans[strlen($ch)][$ch] . "\n");
                }
            } else if ($uc[2][0] === "M") {
                if ($c < 0x363) {
                    $this->assign_it($c, "*", false, $uc[1]);
                }
            } else if ($uc[2][0] === "L" && $uc[5]) {
                $comb = preg_replace('/\A<(?:compat|wide|narrow|circle)>\s*/', "", $uc[5]);
                $latin = $latinmark = $all = [];
                foreach (explode(" ", $comb) as $comp) {
                    if ($comp !== "") {
                        $compc = intval($comp, 16);
                        $compch = UnicodeHelper::utf8_chr($compc);
                        if (($compc >= 0x41 && $compc <= 0x5A)
                            || ($compc >= 0x61 && $compc <= 0x7A)) {
                            $latin[] = $latinmark[] = $all[] = $compch;
                        } else if ($compc === 0xB7 || ($compc >= 0x300 && $compc <= 0x362)) {
                            $latinmark[] = $all[] = $compch;
                        } else if (isset($this->trans[strlen($compch)][$compch])) {
                            $latin[] = $latinmark[] = $all[] = $this->trans[strlen($compch)][$compch];
                        } else {
                            $all[] = $compch;
                        }
                    }
                }
                if (!isset($ignore_latin[$c])
                    && !empty($latin)
                    && count($latinmark) === count($all)) {
                    $this->assign_it($c, join("", $latin), false, $uc[1]);
                } else if ($verbose) {
                    fwrite(STDERR, "ignoring $ch U+{$uc[0]};{$uc[1]};{$uc[2]};{$uc[5]}\n");
                }
            } else if (str_starts_with($uc[1], "LATIN ") && $c > 127) {
                if (preg_match('{\ALATIN (SMALL CAPITAL|CAPITAL|SMALL) LETTER ([A-Z])(?:| WITH HOOK| WITH STROKE)\z}', $uc[1], $m) && $c <= 0x181) {
                    $this->assign_it($c, $m[1] === "SMALL" ? strtolower($m[2]) : $m[2], false, $uc[1]);
                } else if ($verbose && !isset($this->trans[strlen($ch)][$ch])) {
                    fwrite(STDERR, "ignoring $ch U+{$uc[0]};{$uc[1]};{$uc[2]};{$uc[5]}\n");
                }
            }
        }
        return 0;
    }

    function run($arg) {
        $verbose = isset($arg["V"]) || isset($arg["verbose"]);
        $unparse = isset($arg["u"]) || isset($arg["unparse"]);
        if (isset($arg["t"]) || isset($arg["time"])) {
            return $this->run_time($arg);
        }

        $this->parse_utf8_alpha_trans();

        if (isset($arg["f"]) || isset($arg["file"])) {
            $filename = $arg["f"] ?? $arg["file"] ?? null;
            if (($status = $this->parse_file($filename, $unparse, $verbose))
                || $unparse) {
                return $status;
            }
        }

        for ($i = 0; $i < count($arg["_"]); $i += 2) {
            $sin = $arg["_"][$i];
            $sout = trim($arg["_"][$i + 1] ?? "");
            if ($sin !== "" && is_numeric($sin)) {
                $sin = numeric_to_utf8($sin, 0);
            }
            $this->assign_it($sin, $sout, true);
        }

        ksort($this->trans[2], SORT_STRING);
        ksort($this->trans[3], SORT_STRING);

        $unicode_helper = file_get_contents(SiteLoader::find("lib/unicodehelper.php"));
        fwrite(STDOUT, substr($unicode_helper, 0, strpos($unicode_helper, "const ")));

        $m = $n = "";
        foreach ($this->trans[2] as $k => $v) {
            $m .= quote_key($k);
            $n .= addcslashes(substr($v . "   ", 0, self::OUTL2), "\\\"");
        }
        fwrite(STDOUT, "const UTF8_ALPHA_TRANS_2 = \"$m\";\n\nconst UTF8_ALPHA_TRANS_2_OUT = \"$n\";\n\n");

        $m = $n = "";
        foreach ($this->trans[3] as $k => $v) {
            $m .= quote_key($k);
            $n .= addcslashes(substr($v . "   ", 0, self::OUTL3), "\\\"");
        }
        fwrite(STDOUT, "const UTF8_ALPHA_TRANS_3 = \"$m\";\n\nconst UTF8_ALPHA_TRANS_3_OUT = \"$n\";\n\n");

        return 0;
    }
}

exit((new Batch_UpdateUTF8Trans)->run($arg));
