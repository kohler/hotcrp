<?php
// pages/scorechart.php -- HotCRP chart generator
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
    /** @var int */
    private $levelChar = 0;

    /** @param array{int,int,int} $c1
     * @param array{int,int,int} $c2
     * @param float $f
     * @return array{int,int,int} */
    static function quality_color($c1, $c2, $f) {
        return [(int) ($c2[0] * $f + $c1[0] * (1 - $f) + 0.5),
                (int) ($c2[1] * $f + $c1[1] * (1 - $f) + 0.5),
                (int) ($c2[2] * $f + $c1[2] * (1 - $f) + 0.5)];
    }

    function __construct($v, $h, $c) {
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
        if ($c !== null) {
            $this->levelChar = ord($c);
        }
    }

    static function cacheable_headers() {
        header("Cache-Control: max-age=315576000, public");
        header("Expires: " . gmdate("D, d M Y H:i:s", time() + 315576000) . " GMT");
    }

    /** @param string $status
     * @param string $text
     * @param bool $cacheable */
    static function fail($status, $text, $cacheable) {
        header("HTTP/1.0 $status");
        header("Content-Type: text/plain; charset=utf-8");
        if (!function_exists("zlib_get_coding_type")
            || zlib_get_coding_type() === false) {
            header("Content-Length: " . (strlen($text) + 2));
        }
        if ($cacheable) {
            self::cacheable_headers();
        }
        echo $text, "\r\n";
    }

    private function make_s01($s) {
        // set shape constants
        $blockHeight = $blockWidth = $s ? 3 : 8;
        $blockSkip = 2;
        $blockPad = $s ? 2 : 4;
        $textWidth = $s ? 0 : 12;

        $maxY = max($this->maxY, 3);
        $picWidth = ($blockWidth + $blockPad) * ($this->valMax - 1)
            + $blockPad
            + 2 * $textWidth;
        $picHeight = $blockHeight * $maxY + $blockSkip * ($maxY + 1);
        $pic = @imagecreate($picWidth + 1, $picHeight + 1);

        $cWhite = imagecolorallocate($pic, 255, 255, 255);
        $cBlack = imagecolorallocate($pic, 0, 0, 0);
        $cgray = imagecolorallocate($pic, 190, 190, 255);

        if ($s == 0) {
            imagefilledrectangle($pic, 0, 0, $picWidth + 1, $picHeight + 1, $cBlack);
            imagefilledrectangle($pic, 1, 1, $picWidth - 1, $picHeight - 1, $cWhite);
        } else {
            imagecolortransparent($pic, $cWhite);
            imagefilledrectangle($pic, 0, $picHeight, $picWidth + 1, $picHeight + 1, $cgray);
            imagefilledrectangle($pic, 0, $picHeight - $blockHeight - $blockPad, 0, $picHeight + 1, $cgray);
            imagefilledrectangle($pic, $picWidth, $picHeight - $blockHeight - $blockPad, $picWidth + 1, $picHeight + 1, $cgray);
        }

        $cv_black = [0, 0, 0];
        $cv_bad = [200, 128, 128];
        $cv_good = [0, 232, 0];
        $pos = 0;

        for ($value = 1; $value < $this->valMax; $value++) {
            $vpos = ($this->levelChar ? $this->valMax - $value : $value);
            $height = $this->values[$vpos];
            $frac = ($vpos - 1) / ($this->valMax - 1);
            $cv_cur = self::quality_color($cv_bad, $cv_good, $frac);
            $cFill = imagecolorallocate($pic, $cv_cur[0], $cv_cur[1], $cv_cur[2]);

            $curX = $blockWidth * ($value - 1)
                + $blockPad * $value + $textWidth;
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

        if ($s == 0) {
            imagestringup($pic, 2, 0, 30, "Bad", $cBlack);
            imagestringup($pic, 2, $picWidth - $textWidth, 30, "Good", $cBlack);
        } else {
            $lx = $textWidth + $blockPad;
            $rx = $picWidth - $blockWidth - $textWidth - $blockPad;
            $y = $picHeight - $blockHeight - $blockSkip - 3;
            if ($this->levelChar) {
                if ($this->values[1] == 0) {
                    imagestring($pic, 1, $rx, $y, chr($this->levelChar), $cgray);
                }
                if ($this->values[$this->valMax - 1] == 0) {
                    imagestring($pic, 1, $lx, $y, chr($this->levelChar - $this->valMax + 2), $cgray);
                }
            } else {
                if ($this->values[1] == 0) {
                    imagestring($pic, 1, $lx, $y, "1", $cgray);
                }
                if ($this->values[$this->valMax - 1] == 0) {
                    imagestring($pic, 1, $rx, $y, (string) ($this->valMax - 1), $cgray);
                }
            }
        }

        return $pic;
    }

    private function make_s2() {
        $picWidth = 64;
        $picHeight = 8;
        $pic = @imagecreate($picWidth, $picHeight);

        $bg = imagecolorallocate($pic, 255, 255, 255);
        imagecolortransparent($pic, $bg);

        $cv_bad = [200, 128, 128];
        $cv_good = [0, 232, 0];
        $pos = 0;

        for ($value = 1; $value < $this->valMax; $value++) {
            $vpos = ($this->levelChar ? $this->valMax - $value : $value);
            $height = $this->values[$vpos];
            if ($height > 0) {
                $frac = ($vpos - 1) / ($this->valMax - 1);
                $cv_cur = self::quality_color($cv_bad, $cv_good, $frac);
                $cFill = imagecolorallocate($pic, $cv_cur[0], $cv_cur[1], $cv_cur[2]);
                imagefilledrectangle($pic, ($picWidth + 1) * $pos / $this->sum, 0,
                                     ($picWidth + 1) * ($pos + $height) / $this->sum - 2, $picHeight,
                                     $cFill);
                $pos += $height;
            }
        }

        return $pic;
    }

    static function go_param($params) {
        $v = $params["v"] ?? null;
        $s = $params["s"] ?? "0";
        $h = $params["h"] ?? null;
        $c = $params["c"] ?? "";

        if ($v === null
            || ($v !== "" && !preg_match('/\A\d+(,\d+)*\z/', $v))
            || $s === ""
            || !ctype_digit($s)
            || ($sn = intval($s)) < 0
            || $sn > 2
            || ($h !== null && !ctype_digit($h))
            || ($c !== "" && (strlen($c) !== 1 || $c < "A" || $c > "Z"))) {
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

        $sc = new Scorechart_Page($v, $h !== null ? intval($h) : null, $c);
        if ($sn !== 2) {
            imagepng($sc->make_s01($sn));
        } else {
            imagepng($sc->make_s2());
        }
    }

    static function go($user, $qreq) {
        self::go_param($qreq);
    }
}
