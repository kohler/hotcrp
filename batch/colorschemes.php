<?php
// colorschemes.php -- HotCRP script for analyzing color schemes
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(ColorSchemes_Batch::make_args($argv)->run());
}

class ColorSchemes_Batch {
    /** @var array<string,array{0|1|2,int,?string}> */
    public $schemes = [];
    /** @var array<string,list<string>> */
    public $colors = [];
    /** @var ?list<string> */
    public $gradient;
    /** @var bool */
    public $php = false;

    function __construct($arg) {
        if (isset($arg["gradient"])) {
            $this->gradient = $arg["gradient"];
        } else if (isset($arg["php"])) {
            $this->php = true;
        }
        foreach (Discrete_ReviewField::$scheme_info as $name => $sch) {
            $this->schemes[$name] = $sch;
            if ($sch[0] !== 1) {
                $this->colors[$name] = array_fill(0, $sch[1], null);
            }
        }
        $this->colors["none"][0] = "222222";
    }

    /** @param string $s
     * @return OklchColor */
    function from_hashcolor($s) {
        $p = strlen($s) === 7 ? 1 : 0;
        return OklchColor::from_rgb(intval(substr($s, $p, 2), 16),
                                    intval(substr($s, $p + 2, 2), 16),
                                    intval(substr($s, $p + 4, 2), 16));
    }

    /** @return int */
    function run_gradient() {
        $mode = $this->gradient[3] ?? "shorter";
        if (count($this->gradient) < 3
            || count($this->gradient) > 4
            || !ctype_digit($this->gradient[0])
            || ($n = intval($this->gradient[0])) < 2
            || !preg_match('/\A#?([0-9a-fA-F]{6})\z/', $this->gradient[1], $m1)
            || !preg_match('/\A#?([0-9a-fA-F]{6})\z/', $this->gradient[2], $m2)
            || !in_array($mode, ["shorter", "longer", "increasing", "decreasing"])) {
            throw new CommandLineException("`--gradient` expects NSTOPS COLOR1 COLOR2 [longer]");
        }
        $c1 = self::from_hashcolor($m1[1]);
        $c2 = self::from_hashcolor($m2[1]);
        if (is_nan($c1->okh) && is_nan($c2->okh)) {
            $c1->okh = $c2->okh = 0;
        } else if (is_nan($c1->okh)) {
            $c1->okh = $c2->okh;
        } else if (is_nan($c2->okh)) {
            $c2->okh = $c1->okh;
        }
        $hi = HclColor::hue_interpolate($c1->okh, $c2->okh, $mode);
        for ($i = 0; $i < $n; ++$i) {
            if ($i === 0) {
                $c = $c1;
            } else if ($i === $n - 1) {
                $c = $c2;
            } else {
                $c = $c1->interpolate($i / ($n - 1), $c2, $hi);
            }
            fwrite(STDOUT, strtolower($c->hashcolor()) . "\n");
        }
        return 0;
    }

    private function write_php($j) {
        $col = [];
        $cat = [];
        $rev = [];
        foreach ($this->schemes as $name => $sch) {
            if ($sch[0] === 2) {
                $cat[] = "\"{$name}\" => true";
            }
            if ($sch[0] !== 1) {
                $col[] = "\"{$name}\" => \"{$j[$name]->colors}\"";
            }
            if ($sch[2]) {
                $rev[] = "\"{$name}\" => \"{$sch[2]}\"";
            }
        }
        fwrite(STDOUT, "    public static \$scheme_colors = [" . join(", ", $col) . "];\n"
            . "    public static \$scheme_categorical = [" . join(", ", $cat) . "];\n"
            . "    public static \$scheme_reverse = [" . join(", ", $rev) . "];\n");
    }

    /** @return int */
    function run() {
        if (isset($this->gradient)) {
            return $this->run_gradient();
        }
        $css = file_get_contents(SiteLoader::$root . "/stylesheets/style.css");
        preg_match_all('/^\.sv-?([a-z]*)(\d+)\s*\{\s*color\s*:\s*#([0-9a-fA-F]{6})\s*;(?:\s|\/\*.*?\*\/)*\}/m', $css, $ms, PREG_SET_ORDER);
        foreach ($ms as $mx) {
            $name = $mx[1] === "" ? "sv" : $mx[1];
            $idx = intval($mx[2]);
            $color = strtolower($mx[3]);
            if (!isset($this->schemes[$name])
                || $this->schemes[$name][0] === 1
                || $idx <= 0
                || $idx > $this->schemes[$name][1]
                || isset($this->colors[$name][$idx - 1])) {
                fwrite(STDERR, "Unexpected color {$mx[0]}\n");
            } else {
                $this->colors[$name][$idx - 1] = $color;
            }
        }
        $j = [];
        foreach ($this->schemes as $name => $sch) {
            if ($sch[0] === 1) {
                continue;
            }
            if (!isset($this->colors[$name])
                || count(array_filter($this->colors[$name])) !== $sch[1]) {
                fwrite(STDERR, "Some colors not set for scheme {$name}\n");
            } else {
                $j[$name] = $jx = (object) [];
                if ($sch[0] === 2) {
                    $jx->categorical = true;
                }
                $jx->colors = join("", $this->colors[$name]);
            }
        }
        if ($this->php) {
            $this->write_php($j);
        } else {
            foreach ($this->schemes as $name => $sch) {
                if ($sch[0] === 1 && isset($j[$sch[2]])) {
                    $j[$sch[2]]->reverse = $name;
                }
            }
            fwrite(STDOUT, json_encode($j, JSON_PRETTY_PRINT) . "\n");
        }
        return 0;
    }

    /** @return ColorSchemes_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "help,h !",
            "php",
            "gradient[]+,g[]+ Compute gradient"
        )->description("Analyze HotCRP CSS color schemes.
Usage: php batch/colorschemes.php")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        return new ColorSchemes_Batch($arg);
    }
}
