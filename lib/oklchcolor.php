<?php
// oklchcolor.php -- HotCRP helper class for Oklch color space
// From https://bottosson.github.io/posts/oklab/ by Eddie Kohler in 2023

class OklchColor implements JsonSerializable {
    /** @var float */
    public $okl;
    /** @var float */
    public $okc;
    /** @var float */
    public $okh;

    /** @param float $okl
     * @param float $okc
     * @param float $okh */
    function __construct($okl, $okc, $okh) {
        $this->okl = $okl;
        $this->okc = $okc;
        $this->okh = $okh;
    }

    /** @param int|float $r
     * @param int|float $g
     * @param int|float $b
     * @return OklchColor */
    static function from_rgb($r, $g, $b) {
        return self::from_oklab(OklabColor::from_rgb($r, $g, $b));
    }

    /** @param OklabColor $oklab
     * @return OklchColor */
    static function from_oklab($oklab) {
        if ($oklab->oka == 0.0 && $oklab->okb == 0.0) {
            return new OklchColor($oklab->okl, 0.0, NAN);
        }
        $h = atan2($oklab->okb, $oklab->oka) * 180 / M_PI;
        return new OklchColor($oklab->okl, sqrt($oklab->oka * $oklab->oka + $oklab->okb * $oklab->okb), $h < 0 ? $h + 360 : $h);
    }

    /** @param ?float $k
     * @return OklchColor */
    function brighter($k = null) {
        return new OklchColor($this->okl + OklabColor::K * ($k ?? 1), $this->okc, $this->okh);
    }

    /** @param ?float $k
     * @return OklchColor */
    function darker($k = null) {
        return new OklchColor($this->okl - OklabColor::K * ($k ?? 1), $this->okc, $this->okh);
    }

    /** @param float $f
     * @param OklchColor $end
     * @param null|float|'shorter'|'longer'|'increasing'|'decreasing' $hue_method
     * @return OklchColor */
    function interpolate($f, $end, $hue_method = null) {
        if (is_nan($this->okh)) {
            $h = $end->okh;
        } else {
            $dh = is_float($hue_method) ? $hue_method : HclColor::hue_interpolate($this->okh, $end->okh, $hue_method);
            $h = $this->okh + $dh * $f;
        }

        $d = $end->okc - $this->okc;
        if ($d && $d === $d) {
            $c = $this->okc + $f * $d;
        } else {
            $c = is_nan($this->okc) ? $end->okc : $this->okc;
        }

        $d = $end->okl - $this->okl;
        if ($d && $d === $d) {
            $l = $this->okl + $f * $d;
        } else {
            $l = is_nan($this->okl) ? $end->okl : $this->okl;
        }

        return new OklchColor($l, $c, $h);
    }

    /** @return array{float,float,float} */
    function rgb() {
        return $this->oklab()->rgb();
    }

    /** @return string */
    function hashcolor() {
        return $this->oklab()->hashcolor();
    }

    /** @return OklabColor */
    function oklab() {
        if ($this->okh !== $this->okh) {
            return new OklabColor($this->okl, 0.0, 0.0);
        }
        $h = $this->okh * M_PI / 180;
        return new OklabColor($this->okl, cos($h) * $this->okc, sin($h) * $this->okc);
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return [
            "okl" => is_nan($this->okl) ? "NaN" : $this->okl,
            "okc" => is_nan($this->okc) ? "NaN" : $this->okc,
            "okh" => is_nan($this->okh) ? "NaN" : $this->okh
        ];
    }
}
