<?php
// scorechart.php -- HotCRP chart generator
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

// Generates a PNG image of a bar chat.
// Arguments are passed in as v; s is graph style.
// Don't forget to change the width and height calculations in
// ReviewField::unparse_graph if you change the width and height here.

if (!isset($_GET["v"])) {
    header("HTTP/1.0 400 Bad Request");
    exit;
}

// fail if no GD support so the browser displays alt text
if (!function_exists("imagecreate")) {
    require_once("src/init.php");
    Dbl::q("insert into Settings set name='__gd_required', value=1 on duplicate key update value=1");
    header("HTTP/1.0 503 Service Unavailable");
    exit;
}

function quality_color($c1, $c2, $f) {
    return [$c2[0] * $f + $c1[0] * (1 - $f),
            $c2[1] * $f + $c1[1] * (1 - $f),
            $c2[2] * $f + $c1[2] * (1 - $f)];
}

class Scorechart {
    private $valMax = 1;
    private $values = [];
    private $maxY;
    private $sum;
    private $valLight;
    private $levelChar;

    private function set_request($args) {
        $this->valMax = 1;
        $this->values = [];
        $this->maxY = $this->sum = 0;
        foreach (explode(",", $args["v"] ?? "") as $value) {
            $value = ctype_digit($value) && $value > 0 ? intval($value) : 0;
            $this->values[$this->valMax++] = $value;
            $this->maxY = max($value, $this->maxY);
            $this->sum += $value;
        }

        $this->valLight = 0;
        if (is_numeric($args["h"] ?? "x")) {
            $h = intval($args["h"]);
            if ($h >= 1 && $h < $this->valMax) {
                $this->valLight = $h;
            }
        }

        $this->levelChar = 0;
        if (isset($args["c"]) && ord($args["c"]) >= 65 && ord($args["c"]) <= 90) {
            $this->levelChar = ord($args["c"]);
        }
    }

    private function make_s01($s) {
        // set shape constants
        if ($s == 0) {
            list($blockHeight, $blockWidth, $blockSkip, $blockPad, $textWidth)
                = array(8, 8, 2, 4, 12);
        } else {
            list($blockHeight, $blockWidth, $blockSkip, $blockPad, $textWidth)
                = array(3, 3, 2, 2, 0);
        }

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

        $cv_black = array(0, 0, 0);
        $cv_bad = array(200, 128, 128);
        $cv_good = array(0, 232, 0);
        $pos = 0;

        for ($value = 1; $value < $this->valMax; $value++) {
            $vpos = ($this->levelChar ? $this->valMax - $value : $value);
            $height = $this->values[$vpos];
            $frac = ($vpos - 1) / ($this->valMax - 1);
            $cv_cur = quality_color($cv_bad, $cv_good, $frac);
            $cFill = imagecolorallocate($pic, $cv_cur[0], $cv_cur[1], $cv_cur[2]);

            $curX = $blockWidth * ($value - 1)
                + $blockPad * $value + $textWidth;
            $curY = $picHeight - ($blockHeight + $blockSkip) * $height + $blockHeight;

            for ($h = 1; $h <= $height; $h++) {
                if ($h == $height && $vpos == $this->valLight) {
                    $cv_cur = quality_color($cv_black, $cv_cur, 0.5);
                    $cFill = imagecolorallocate($pic, $cv_cur[0], $cv_cur[1], $cv_cur[2]);
                }
                imagefilledrectangle($pic, $curX, $curY - $blockHeight,
                                     $curX + $blockWidth, $curY, $cFill);
                $curY += ($blockHeight + $blockSkip);
            }
        }

        if ($s == 0) {
            imagestringup($pic, 2, 0, 30, "Bad", $cBlack);
            imagestringup($pic, 2, $picWidth-$textWidth, 30, "Good", $cBlack);
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

        $cv_black = array(0, 0, 0);
        $cv_bad = array(200, 128, 128);
        $cv_good = array(0, 232, 0);
        $pos = 0;

        for ($value = 1; $value < $this->valMax; $value++) {
            $vpos = ($this->levelChar ? $this->valMax - $value : $value);
            $height = $this->values[$vpos];
            if ($height > 0) {
                $frac = ($vpos - 1) / ($this->valMax - 1);
                $cv_cur = quality_color($cv_bad, $cv_good, $frac);
                $cFill = imagecolorallocate($pic, $cv_cur[0], $cv_cur[1], $cv_cur[2]);
                imagefilledrectangle($pic, ($picWidth + 1) * $pos / $this->sum, 0,
                                     ($picWidth + 1) * ($pos + $height) / $this->sum - 2, $picHeight,
                                     $cFill);
                $pos += $height;
            }
        }

        return $pic;
    }

    static function make_gd($args) {
        $sc = new Scorechart;
        $sc->set_request($args);
        $s = $args["s"] ?? 0;
        if ($s == 0 || $s == 1) {
            return $sc->make_s01($s);
        } else {
            return $sc->make_s2();
        }
    }
}

session_cache_limiter("");
header("Cache-Control: max-age=31557600, public");
header("Expires: " . gmdate("D, d M Y H:i:s", time() + 31557600) . " GMT");
header("Content-Type: image/png");
imagepng(Scorechart::make_gd($_GET));
exit();
