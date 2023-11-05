<?php
// labcolor.php -- HotCRP helper class for Lab color space
// Translated from Mike Bostock's d3-color by Eddie Kohler in 2023

class LabColor implements JsonSerializable {
    const K = 18;
    const Xn = 0.96422;
    const Yn = 1;
    const Zn = 0.82521;
    const t0 = 4 / 29;
    const t1 = 6 / 29;
    const t2 = 3 * self::t1 * self::t1;
    const t3 = self::t1 * self::t1 * self::t1;

    /** @var float */
    public $l;
    /** @var float */
    public $a;
    /** @var float */
    public $b;

    /** @param float $l
     * @param float $a
     * @param float $b */
    function __construct($l, $a, $b) {
        $this->l = $l;
        $this->a = $a;
        $this->b = $b;
    }

    /** @param int|float $r
     * @param int|float $g
     * @param int|float $b
     * @return LabColor */
    static function from_rgb($r, $g, $b) {
        $lr = self::rgb2lrgb($r);
        $lg = self::rgb2lrgb($g);
        $lb = self::rgb2lrgb($b);
        $y = self::xyz2lab((0.2225045 * $lr + 0.7168786 * $lg + 0.0606169 * $lb) / self::Yn);
        if ($lr === $lg && $lg === $lb) {
            $x = $z = $y;
        } else {
            $x = self::xyz2lab((0.4360747 * $lr + 0.3850649 * $lg + 0.1430804 * $lb) / self::Xn);
            $z = self::xyz2lab((0.0139322 * $lr + 0.0971045 * $lg + 0.7141733 * $lb) / self::Zn);
        }
        return new LabColor(116 * $y - 16, 500 * ($x - $y), 200 * ($y - $z));
    }

    /** @param HclColor $hcl
     * @return LabColor */
    static function from_hcl($hcl) {
        return $hcl->lab();
    }

    /** @param ?float $k
     * @return LabColor */
    function brighter($k = null) {
        return new LabColor($this->l + self::K * ($k ?? 1), $this->a, $this->b);
    }

    /** @param ?float $k
     * @return LabColor */
    function darker($k = null) {
        return new LabColor($this->l - self::K * ($k ?? 1), $this->a, $this->b);
    }

    /** @return array{float,float,float} */
    function rgb() {
        $y1 = ($this->l + 16) / 116;
        $x1 = $this->a !== $this->a ? $y1 : $y1 + $this->a / 500;
        $z1 = $this->b !== $this->b ? $y1 : $y1 - $this->b / 200;
        $x = self::Xn * self::lab2xyz($x1);
        $y = self::Yn * self::lab2xyz($y1);
        $z = self::Zn * self::lab2xyz($z1);
        return [
            self::lrgb2rgb(3.1338561 * $x - 1.6168667 * $y - 0.4906146 * $z),
            self::lrgb2rgb(-0.9787684 * $x + 1.9161415 * $y + 0.0334540 * $z),
            self::lrgb2rgb(0.0719453 * $x - 0.2289914 * $y + 1.4052427 * $z)
        ];
    }

    /** @return string */
    function hashcolor() {
        $rgb = $this->rgb();
        return sprintf("#%02X%02X%02X", round($rgb[0]), round($rgb[1]), round($rgb[2]));
    }

    /** @return HclColor */
    function hcl() {
        return HclColor::from_lab($this);
    }

    /** @param int|float $x
     * @return float */
    static function rgb2lrgb($x) {
        $sx = $x / 255;
        return $sx <= 0.04045 ? $sx / 12.92 : pow(($sx + 0.055) / 1.055, 2.4);
    }

    /** @param float $lx
     * @return float */
    static function lrgb2rgb($lx) {
        return 255 * ($lx <= 0.0031308 ? max($lx, 0) * 12.92 : 1.055 * pow($lx, 1 / 2.4) - 0.055);
    }

    /** @param float $t
     * @return float */
    static function xyz2lab($t) {
        return $t > self::t3 ? pow($t, 1 / 3) : $t / self::t2 + self::t0;
    }

    /** @param float $t
     * @return float */
    static function lab2xyz($t) {
        return $t > self::t1 ? $t * $t * $t : self::t2 * ($t - self::t0);
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return get_object_vars($this);
    }
}
