<?php
// contactcolumns.php -- HotCRP class for producing columnar contact display
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class ContactColumns {
    private $ncol;
    private $checkbox;
    private $min_color_index = -1;
    private $a = array();
    private $tagger;
    public function __construct($ncol = 3, $checkbox = null) {
        $this->ncol = $ncol;
        $this->checkbox = $checkbox;
        $this->tagger = new Tagger;
    }
    public function add($pc, $after, $nextrow = null) {
        $count = count($this->a) + 1;
        $color = $this->tagger->viewable_color_classes($pc->all_contact_tags());
        if (TagInfo::classes_have_colors($color) && $this->min_color_index < 0)
            $this->min_color_index = $count - 1;
        $color = $color ? ' class="' . $color . '"' : "";
        $t = "  <tr$color>";
        if ($this->checkbox) {
            $name = $this->checkbox["name"];
            $checked = !!@$this->checkbox["checked"][$pc->contactId];
            $js = $this->checkbox;
            foreach ($js as $k => &$v)
                if (is_string($v))
                    $v = str_replace('{{count}}', $count, $v);
            unset($js["name"], $js["checked"], $v);
            $t .= '<td class="pctbl">'
                . Ht::checkbox($name, $pc->contactId, $checked, $js)
                . '&nbsp;</td>';
        }
        $name = $pc->name_html();
        if ($this->checkbox)
            $name = Ht::label($name);
        $t .= '<td class="' . ($this->checkbox ? '' : 'pctbl ') . 'pctbname">'
            . '<span class="taghl">' . $name . '</span>';
        if ($after)
            $t .= $after;
        $t .= '</td></tr>';
        if ($nextrow) {
            $t .= '<tr' . $color . '>';
            if ($this->checkbox)
                $t .= '<td class="pctbl"></td><td class="pctbnrev">';
            else
                $t .= '<td class="pctbl pctbnrev">';
            $t .= $nextrow . '</td></tr>';
        }
        $this->a[] = $t . "\n";
    }
    public function render() {
        $n = intval((count($this->a) + ($this->ncol - 1)) / $this->ncol);
        $tbclass = "pctb" . ($this->min_color_index >= 0 ? " pctb_colored" : "");
        $leftmin = $this->min_color_index >= 0 && $this->min_color_index < $n ? 0 : $n;
        $x = array('<table class="' . $tbclass . '"><tbody><tr><td class="pctbcolleft"><table><tbody>' . "\n");
        for ($i = 0; $i < count($this->a); ++$i) {
            if (($i % $n) == 0 && $i)
                $x[] = '</tbody></table></td><td class="pctbcolmid"><table><tbody>' . "\n";
            $t = $this->a[$i];
            if ($i < $leftmin)
                $t = str_replace('<td class="pctbl', '<td class="', $t);
            $x[] = $t;
        }
        $x[] = '</tbody></table></td></tr></tbody></table>';
        return join("", $x);
    }
}
