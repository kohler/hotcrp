<?php
// pc_color.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Color_PaperColumn extends PaperColumn {
    /** @var array<string,HclColor> */
    private $colors = [];
    /** @var float */
    private $hdelta = 8.0;
    /** @var bool */
    private $hrev = false;

    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function view_option_schema() {
        return ["order!"];
    }
    function prepare(PaperList $pl, $visible) {
        if (($v = $this->view_option("order"))) {
            $knownv = ["rgb" => "+8", "gbr" => "+128", "brg" => "+248",
                       "rbg" => "-45", "grb" => "-165", "bgr" => "-285"];
            $v = $knownv[$v] ?? $v;
            if (preg_match('/\A[-+]?\d+\z/', $v)
                && ($num = (float) $v) >= -360
                && $num <= 360) {
                $this->hdelta = abs($num);
                $this->hrev = str_starts_with($v, "-");
            }
        }
        return $visible === self::PREP_SORT;
    }
    /** @return ?OklchColor */
    private function color(PaperInfo $a, PaperList $pl) {
        $x = $a->sorted_viewable_tags($pl->user);
        if (!array_key_exists($x, $this->colors)) {
            $n = 0;
            $lch = null;
            foreach ($pl->conf->tags()->unique_tagstyles($x, TagStyle::BG) as $ks) {
                ++$n;
                if ($lch === null) {
                    $lch = $ks->oklch();
                } else {
                    $lch = $lch->interpolate(1 / $n, $ks->oklch());
                }
            }
            $this->colors[$x] = $lch;
        }
        return $this->colors[$x];
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $alch = $this->color($a, $pl);
        $bhcl = $this->color($b, $pl);

        // null colors are sorted at end
        if ($alch === null || $bhcl === null) {
            return ($alch ? 0 : 1) <=> ($bhcl ? 0 : 1);
        }

        // gray colors are sorted after non-gray coors
        $agray = $alch->okl < 5 || $alch->okc < 5;
        $bgray = $bhcl->okl < 5 || $bhcl->okc < 5;
        if ($agray !== $bgray) {
            return $agray ? 1 : -1;
        }

        // sort by quantized hue first
        $ah = is_nan($alch->okh) ? 0.0 : $alch->okh;
        if (($ah -= $this->hdelta) < 0) {
            $ah += 360;
        }
        $bh = is_nan($bhcl->okh) ? 0.0 : $bhcl->okh;
        if (($bh -= $this->hdelta) < 0) {
            $bh += 360;
        }
        if ($agray) {
            $ahb = $bhb = 0;
        } else {
            $ahb = (int) ($ah / 24);
            $bhb = (int) ($bh / 24);
        }
        if ($ahb !== $bhb) {
            return $this->hrev ? $bhb <=> $ahb : $ahb <=> $bhb;
        }

        // then sort by quantized lightness
        $alb = (int) ($alch->okl / 2);
        $blb = (int) ($bhcl->okl / 2);
        if ($alb !== $blb) {
            return $blb <=> $alb;
        }

        // then sort by quantized chroma
        $acb = (int) ($alch->okc / 8);
        $bcb = (int) ($bhcl->okc / 8);
        if ($acb !== $bcb) {
            return $bcb <=> $acb;
        }

        // if all buckets match, do a fine-grained comparison
        if ($ah !== $bh) {
            return $this->hrev ? $bh <=> $ah : $ah <=> $bh;
        } else {
            return ($bhcl->okl <=> $alch->okl) ? : ($bhcl->okc <=> $alch->okc);
        }
    }
}
