<?php
// hclcolor.php -- HotCRP helper class for CIEHcl color space
// Translated from Mike Bostock's d3-color by Eddie Kohler in 2023

class HclColor implements JsonSerializable {
    /** @var float */
    public $h;
    /** @var float */
    public $c;
    /** @var float */
    public $l;

    /** @param float $h
     * @param float $c
     * @param float $l */
    function __construct($h, $c, $l) {
        $this->h = $h;
        $this->c = $c;
        $this->l = $l;
    }

    /** @param int|float $r
     * @param int|float $g
     * @param int|float $b
     * @return HclColor */
    static function from_rgb($r, $g, $b) {
        return self::from_lab(LabColor::from_rgb($r, $g, $b));
    }

    /** @param LabColor $lab
     * @return HclColor */
    static function from_lab($lab) {
        if ($lab->a == 0.0 && $lab->b == 0.0) {
            return new HclColor(NAN, 0.0, $lab->l);
        }
        $h = atan2($lab->b, $lab->a) * 180 / M_PI;
        return new HclColor($h < 0 ? $h + 360 : $h, sqrt($lab->a * $lab->a + $lab->b * $lab->b), $lab->l);
    }

    /** @param ?float $k
     * @return HclColor */
    function brighter($k = null) {
        return new HclColor($this->h, $this->c, $this->l + LabColor::K * ($k ?? 1));
    }

    /** @param ?float $k
     * @return HclColor */
    function darker($k = null) {
        return new HclColor($this->h, $this->c, $this->l - LabColor::K * ($k ?? 1));
    }

    /** @param float $h1
     * @param float $h2
     * @param null|'shorter'|'longer'|'increasing'|'decreasing' $method
     * @return float */
    static function hue_interpolate($h1, $h2, $method = null) {
        if (is_float($method)) {
            return $method;
        }
        if (is_nan($h1)) {
            $h1 = is_nan($h2) ? 0.0 : $h2;
        }
        if (is_nan($h2)) {
            $h2 = $h1;
        }
        if ($h1 >= 0 && $h1 < 360) {
            $nh1 = $h1;
        } else if (($nh1 = fmod($h1, 360.0)) < 0) {
            $nh1 += 360.0;
        }
        if ($h2 >= 0 && $h2 < 360) {
            $nh2 = $h2;
        } else if (($nh2 = fmod($h2, 360.0)) < 0) {
            $nh2 += 360.0;
        }
        $dinc = $nh2 > $nh1 ? $nh2 - $nh1 : $nh2 + 360.0 - $nh1;
        $ddec = $nh1 > $nh2 ? $nh2 - $nh1 : $nh2 - 360.0 - $nh1;
        if ($method === null || $method === "shorter") {
            if ($dinc < -$ddec || ($dinc === -$ddec && $h1 < $h2)) {
                return $dinc;
            } else {
                return $ddec;
            }
        } else if ($method === "longer") {
            if ($nh1 === $nh2) {
                return $h1 <= $h2 ? 360.0 : -360.0;
            } else if ($dinc > -$ddec || ($dinc === -$ddec && $h1 < $h2)) {
                return $dinc;
            } else {
                return $ddec;
            }
        } else if ($method === "increasing") {
            return $dinc;
        } else {
            return $ddec;
        }
    }

    /** @param float $f
     * @param HclColor $end
     * @param null|float|'shorter'|'longer'|'increasing'|'decreasing' $hue_method
     * @return HclColor */
    function interpolate($f, $end, $hue_method = null) {
        if (is_nan($this->h)) {
            $h = $end->h;
        } else {
            $dh = is_float($hue_method) ? $hue_method : self::hue_interpolate($this->h, $end->h, $hue_method);
            $h = $this->h + $dh * $f;
        }

        $d = $end->c - $this->c;
        if ($d && $d === $d) {
            $c = $this->c + $f * $d;
        } else {
            $c = is_nan($this->c) ? $end->c : $this->c;
        }

        $d = $end->l - $this->l;
        if ($d && $d === $d) {
            $l = $this->l + $f * $d;
        } else {
            $l = is_nan($this->l) ? $end->l : $this->l;
        }

        return new HclColor($h, $c, $l);
    }

    /** @return array{float,float,float} */
    function rgb() {
        return $this->lab()->rgb();
    }

    /** @return string */
    function hashcolor() {
        return $this->lab()->hashcolor();
    }

    /** @return LabColor */
    function lab() {
        if ($this->h !== $this->h) {
            return new LabColor($this->l, 0.0, 0.0);
        }
        $h = $this->h * M_PI / 180;
        return new LabColor($this->l, cos($h) * $this->c, sin($h) * $this->c);
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return [
            "h" => is_nan($this->h) ? "NaN" : $this->h,
            "c" => is_nan($this->c) ? "NaN" : $this->c,
            "l" => is_nan($this->l) ? "NaN" : $this->l
        ];
    }
}
