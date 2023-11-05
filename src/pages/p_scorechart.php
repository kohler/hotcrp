<?php
// pages/scorechart.php -- HotCRP chart generator
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

// Generates a PNG image of a bar chart.
// Arguments are passed in as v; s is graph style.
// Don't forget to change the width and height calculations in
// ReviewField::unparse_graph if you change the width and height here.

class Scorechart_Page {
    /** @var int */
    private $valMax = 1;
    /** @var list<int> */
    private $values = [0];
    /** @var int */
    private $maxY = 0;
    /** @var int */
    private $sum = 0;
    /** @var int */
    private $valLight = 0;
    /** @var string */
    private $loLabel;
    /** @var string */
    private $hiLabel;
    /** @var bool */
    private $flip;
    /** @var string */
    private $scheme;
    /** @var int */
    private $scheme_max;
    /** @var bool */
    private $categorical;
    /** @var bool */
    private $svflip;
    /** @var int */
    private $scale;

    public static $scheme_colors = ["sv" => "9c3131a04b00a26300a179009d8f00929e007fad005fbd0000cc00", "bupu" => "4b8bc14181be3b76bb396bb73b5fb24053ab4646a34d389a54278f", "pkrd" => "e14da0d7448bcc3b76c13363b52b50a9243e9c1e2c8f1819821201", "viridis" => "440154472c7a3b518b2c718e21908d27ad815cc863aadc32dbcb39", "orbu" => "fca636f68443e86659d14d6fb23a818e2c8f6721963e15940d0887", "turbo" => "23171b4569ee26bce13ff3936be619ecd12eff821dcb2f0d900c00", "catx" => "1f77b4ff7f0e2ca02cd627289467bd8c564be377c27f7f7fbcbd2217becf", "none" => "222222"];
    public static $scheme_categorical = ["catx" => true, "none" => true];
    public static $scheme_reverse = ["sv" => "svr", "svr" => "sv", "bupu" => "pubu", "pubu" => "bupu", "rdpk" => "pkrd", "pkrd" => "rdpk", "viridisr" => "viridis", "viridis" => "viridisr", "orbu" => "buor", "buor" => "orbu", "turbo" => "turbor", "turbor" => "turbo"];

    /** @param array{int,int,int} $c1
     * @param array{int,int,int} $c2
     * @param float $f
     * @return array{int,int,int} */
    static function quality_color($c1, $c2, $f) {
        return [(int) ($c2[0] * $f + $c1[0] * (1 - $f) + 0.5),
                (int) ($c2[1] * $f + $c1[1] * (1 - $f) + 0.5),
                (int) ($c2[2] * $f + $c1[2] * (1 - $f) + 0.5)];
    }

    function __construct($v, $h, $lo, $hi, $flip, $sv, $scale) {
        foreach (explode(",", $v) as $value) {
            if ($value !== "") {
                $value = intval($value);
                $this->values[] = $value;
                ++$this->valMax;
                $this->maxY = max($value, $this->maxY);
                $this->sum += $value;
            }
        }
        if ($h !== null && $h >= 1 && $h < $this->valMax) {
            $this->valLight = $h;
        }
        $this->loLabel = $lo ?? "1";
        $this->hiLabel = $hi ?? (string) ($this->valMax - 1);
        $this->flip = $this->svflip = $flip;
        if (!isset(self::$scheme_colors[$sv]) && isset(self::$scheme_reverse[$sv])) {
            $this->svflip = !$this->svflip;
            $sv = self::$scheme_reverse[$sv];
        } else if (!isset(self::$scheme_colors[$sv])) {
            $sv = "sv";
        }
        $this->scheme = $sv;
        $this->scheme_max = strlen(self::$scheme_colors[$sv]) / 6;
        $this->categorical = self::$scheme_categorical[$sv] ?? false;
        $this->scale = intval($scale);
    }

    static function cacheable_headers() {
        header("Cache-Control: max-age=315576000, public");
        header("Expires: " . gmdate("D, d M Y H:i:s", time() + 315576000) . " GMT");
    }

    /** @param string $status
     * @param string $text
     * @param bool $cacheable */
    static function fail($status, $text, $cacheable) {
        header("HTTP/1.0 {$status}");
        header("Content-Type: text/plain; charset=utf-8");
        if ($cacheable) {
            self::cacheable_headers();
        }
        echo $text, "\r\n";
    }

    /** @return array{int,int,int} */
    private function rgb_array($i) {
        $n = $this->valMax - 1;
        if ($n <= 1 || $this->scheme_max <= 1) {
            $f9 = $this->svflip ? 1 : $this->scheme_max;
        } else if ($this->categorical && $this->svflip) {
            $f9 = ($n - $i) % $this->scheme_max + 1;
        } else if ($this->categorical) {
            $f9 = ($i - 1) % $this->scheme_max + 1;
        } else {
            $f = ($this->scheme_max - 1) / ($n - 1);
            if ($this->svflip) {
                $f9 = max(min(round(($n - $i) * $f) + 1, $this->scheme_max), 1);
            } else {
                $f9 = max(min(round(($i - 1) * $f) + 1, $this->scheme_max), 1);
            }
        }
        $k = intval(substr(self::$scheme_colors[$this->scheme], ($f9 - 1) * 6, 6), 16);
        return [$k >> 16, ($k >> 8) & 255, $k & 255];
    }

    private function make_s1() {
        $scale = $this->scale;

        // set shape constants
        $blockHeight = $blockWidth = 3 * $scale;
        $blockSkip = 2 * $scale;
        $blockPad = 2 * $scale;

        $maxY = max($this->maxY, 3);
        $picWidth = ($blockWidth + $blockPad) * ($this->valMax - 1)
            + $blockPad;
        $picHeight = $blockHeight * $maxY + $blockSkip * ($maxY + 1);
        $pic = @imagecreate($picWidth + 2 * $scale, $picHeight + $scale);

        $cWhite = imagecolorallocate($pic, 255, 255, 255);
        $cBlack = imagecolorallocate($pic, 0, 0, 0);
        $cgray = imagecolorallocate($pic, 190, 190, 255);

        imagecolortransparent($pic, $cWhite);
        imagefilledrectangle($pic, 0, $picHeight, $picWidth + 2 * $scale, $picHeight + $scale, $cgray);
        imagefilledrectangle($pic, 0, $picHeight - $blockHeight - $blockPad, $scale - 1, $picHeight + $scale, $cgray);
        imagefilledrectangle($pic, $picWidth + $scale, $picHeight - $blockHeight - $blockPad, $picWidth + 2 * $scale, $picHeight + $scale, $cgray);

        $cv_black = [0, 0, 0];
        $cv_bad = [200, 128, 128];
        $cv_good = [0, 232, 0];
        $pos = 0;

        for ($value = 1; $value < $this->valMax; $value++) {
            $vpos = $this->flip ? $this->valMax - $value : $value;
            $height = $this->values[$vpos];
            $cv_cur = $this->rgb_array($vpos);
            $cFill = imagecolorallocate($pic, $cv_cur[0], $cv_cur[1], $cv_cur[2]);

            $curX = $blockWidth * ($value - 1) + $blockPad * $value + $scale - 1;
            $curY = $picHeight - ($blockHeight + $blockSkip) * $height + $blockHeight;

            for ($h = 1; $h <= $height; $h++) {
                if ($h == $height && $vpos == $this->valLight) {
                    $cv_cur = self::quality_color($cv_black, $cv_cur, 0.5);
                    $cFill = imagecolorallocate($pic, $cv_cur[0], $cv_cur[1], $cv_cur[2]);
                }
                imagefilledrectangle($pic, $curX, $curY - $blockHeight,
                                     $curX + $blockWidth, $curY, $cFill);
                $curY += ($blockHeight + $blockSkip);
            }
        }

        $font = $scale > 2 ? 5 : 1;
        $lx = $blockPad + $scale - 1;
        $rx = $picWidth - $blockWidth - $blockPad + $scale - 1;
        $y = $picHeight - $blockSkip + 2 - imagefontheight($font);
        if ($this->values[$this->flip ? $this->valMax - 1 : 1] === 0) {
            imagestring($pic, $font, $lx, $y, $this->loLabel, $cgray);
        }
        if ($this->values[$this->flip ? 1 : $this->valMax - 1] === 0) {
            imagestring($pic, $font, $rx, $y, $this->hiLabel, $cgray);
        }

        return $pic;
    }

    private function make_s2() {
        $picWidth = 64 * $this->scale;
        $picHeight = 8 * $this->scale;
        $pic = @imagecreate($picWidth, $picHeight);

        $bg = imagecolorallocate($pic, 255, 255, 255);
        imagecolortransparent($pic, $bg);

        $cv_bad = [200, 128, 128];
        $cv_good = [0, 232, 0];
        $pos = 0;

        for ($value = 1; $value < $this->valMax; $value++) {
            $vpos = $this->flip ? $this->valMax - $value : $value;
            $height = $this->values[$vpos];
            if ($height > 0) {
                $cv_cur = $this->rgb_array($vpos);
                $cFill = imagecolorallocate($pic, $cv_cur[0], $cv_cur[1], $cv_cur[2]);
                imagefilledrectangle($pic, ($picWidth + $this->scale) * $pos / $this->sum, 0,
                                     ($picWidth + $this->scale) * ($pos + $height) / $this->sum - 2 * $this->scale, $picHeight,
                                     $cFill);
                $pos += $height;
            }
        }

        return $pic;
    }

    static function go_param($params) {
        $v = $params["v"] ?? null;
        $s = $params["s"] ?? "1";
        $h = $params["h"] ?? null;
        $lo = $params["lo"] ?? null;
        $hi = $params["hi"] ?? null;
        $flip = !!($params["flip"] ?? null);
        $sv = $params["sv"] ?? "sv";
        $scale = $params["scale"] ?? "1";

        if ($v === null
            || ($v !== "" && !preg_match('/\A\d+(,\d+)*\z/', $v))
            || $s === ""
            || !ctype_digit($s)
            || ($sn = intval($s)) < 1
            || $sn > 2
            || ($h !== null && !ctype_digit($h))
            || !ctype_digit($scale)) {
            self::fail("400 Bad Request", "Invalid parameters", true);
            return;
        }

        // fail if no GD support so the browser displays alt text
        if (!function_exists("imagecreate")) {
            require_once("src/init.php");
            initialize_conf();
            Dbl::q("insert into Settings set name='__gd_required', value=1 on duplicate key update value=1");
            self::fail("503 Service Unavailable", "PHP gd support required", false);
            return;
        }

        if (session_id() === "") {
            session_cache_limiter("");
        }
        self::cacheable_headers();
        header("Content-Type: image/png");

        $sc = new Scorechart_Page($v, $h !== null ? intval($h) : null, $lo, $hi, $flip, $sv, $scale);
        if ($sn !== 2) {
            imagepng($sc->make_s1());
        } else {
            imagepng($sc->make_s2());
        }
    }

    static function go($user, $qreq) {
        self::go_param($qreq);
    }
}
