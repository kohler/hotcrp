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

    /** @param float $f
     * @param HclColor $end
     * @return HclColor */
    function interpolate($f, $end) {
        $d = $end->h - $this->h;
        if ($d && $d === $d) {
            if ($d > 180 || $d < -180) {
                $d -= 360 * round($d / 360);
            }
            $h = $this->h + $f * $d;
        } else {
            $h = is_nan($this->h) ? $end->h : $this->h;
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
