<?php
// icons.php -- HotCRP icon classes
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Icons {
    static function ui_triangle($direction) {
        $t = '<svg class="licon" width="0.75em" height="0.75em" viewBox="0 0 16 16" preserveAspectRatio="none"><path d="';
        if ($direction == 0)
            $t .= 'M1 15L8 1L15 15z';
        else if ($direction == 1)
            $t .= 'M1 1L15 8L1 15z';
        else if ($direction == 2)
            $t .= 'M1 1L8 15L15 1z';
        else if ($direction == 3)
            $t .= 'M15 1L1 8L15 15z';
        return $t . '" /></svg>';
    }
    static function ui_upperleft() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 16 16" preserveAspectRatio="none"><path d="M16 10L16 13C9 15 5 12 6 6L2 7L8 1L14 7L10 6C9 9 10 11 16 10z" /></svg>';
    }
    static function ui_linkarrow($direction) {
        $t = '<svg class="licon-s" width="0.75em" height="0.75em" viewBox="0 0 16 16" preserveAspectRatio="none"><path d="';
        if ($direction == 0)
            $t .= 'M2 11L8 1L14 11';
        else if ($direction == 1)
            $t .= 'M5 3L15 9L5 15';
        else if ($direction == 2)
            $t .= 'M2 1L8 11L14 1';
        else if ($direction == 3)
            $t .= 'M11 3L1 9L11 15';
        return $t . '" /></svg>';
    }
}
