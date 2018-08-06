<?php
// icons.php -- HotCRP icon classes
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

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
    static function ui_movearrow($direction) {
        $t = '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64" preserveAspectRatio="none"><path d="';
        if ($direction == 0)
            $t .= 'M26 64L28 28L10 46L6 44L32 1L58 44L54 46L36 28L38 64';
        else if ($direction == 2)
            $t .= 'M26 0L28 36L10 18L6 20L32 63L58 20L54 18L36 36L38 0';
        return $t . 'Z" /></svg>';
    }
    static function ui_display() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64" preserveAspectRatio="none"><path d="M 10 11 L 54 11 C 55 11 56 12 56 13 L 56 35 C 56 36 55 37 54 37 L 10 37 C 9 37 8 36 8 35 L 8 13 C 8 12 9 11 10 11 Z M 4 12 L 4 36 C 4 40 6 42 10 42 L 29 42 L 28 50 C 28 50 28 53 23 54 C 18 55 14 56 12 57 C 11 59 12 59 16 59 L 48 59 C 52 59 53 59 52 57 C 50 56 46 55 41 54 C 36 53 36 50 36 50 L 35 42 L 54 42 C 58 42 60 40 60 36 L 60 12 C 60 8 58 6 54 6 L 10 6 C 6 6 4 8 4 12 Z" /></svg>';
    }
    static function ui_trash() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64" preserveAspectRatio="none"><path d="M 48 55 C 48 58 46 60 43 60 L 21 60 C 18 60 16 58 16 55 L 16 20 C 14 20 13 19 13 17 L 13 14 C 13 12 15 10 17 10 L 21 10 L 21 8 C 21 6 23 4 25 4 L 39 4 C 41 4 43 6 43 8 L 43 10 L 47 10 C 49 10 51 12 51 14 L 51 17 C 51 19 50 20 48 20 Z M 15 14 L 15 18 L 49 18 L 49 14 C 49 13 48 12 47 12 L 17 12 C 16 12 15 13 15 14 Z M 23 8 L 23 10 L 41 10 L 41 8 C 41 7 40 6 39 6 L 25 6 C 24 6 23 7 23 8 Z M 19 54 C 19 55.7 20.3 57 22 57 L 42 57 C 43.7 57 45 55.7 45 54 L 45 20 L 19 20 Z M 39 22 L 40 22 C 40.6 22 41 22.4 41 23 L 41 53 C 41 53.6 40.6 54 40 54 L 39 54 C 38.4 54 38 53.6 38 53 L 38 23 C 38 22.4 38.4 22 39 22 Z M 31.5 22 L 32.5 22 C 33.1 22 33.5 22.4 33.5 23 L 33.5 53 C 33.5 53.6 33.1 54 32.5 54 L 31.5 54 C 30.9 54 30.5 53.6 30.5 53 L 30.5 23 C 30.5 22.4 30.9 22 31.5 22 Z M 24 22 L 25 22 C 25.6 22 26 22.4 26 23 L 26 53 C 26 53.6 25.6 54 25 54 L 24 54 C 23.4 54 23 53.6 23 53 L 23 23 C 23 22.4 23.4 22 24 22 Z" /></svg>';
    }
    static function ui_visibility_hide() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64" preserveAspectRatio="none">
  <path d="M 32 30 C 50 30 59 44 59 44 C 59 44 50 59 32 59 C 14 59 5 44 5 44 C 5 44 14 30 32 30 Z M 10 44 C 10 44 17 54 32 54 C 47 54 54 44 54 44 C 54 44 47 35 32 35 C 17 35 10 44 10 44 Z M 40 44 C 40 48 36 52 32 52 C 28 52 24 48 24 44 C 24 40 28 36 32 36 C 36 36 40 40 40 44 Z M 46.1 3.6 C 49.4 3.8 51.9 4.4 53.6 5.4 C 55.4 6.5 56.3 7.9 56.3 9.6 C 56.3 10.6 56 11.6 55.4 12.5 C 54.8 13.4 54 14.1 52.9 14.7 L 50.2 16.3 L 50.1 16.5 L 50.4 19.7 L 46.9 20.1 L 46.6 19.8 L 46.2 15.3 L 46.4 15 L 49.7 13.3 C 51.1 12.5 51.8 11.6 51.8 10.4 C 51.8 9.4 51.3 8.7 50.2 8.1 C 49.2 7.6 47.4 7.3 45.1 7.1 L 44.7 6.6 L 45.6 3.9 L 46.1 3.6 Z M 52 26 C 52 27.7 50.7 29 49 29 C 47.3 29 46 27.7 46 26 C 46 24.3 47.3 23 49 23 C 50.7 23 52 24.3 52 26 Z"/>
</svg>';
    }
    static function ui_edit_hide() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64" preserveAspectRatio="none">
  <path d="M 52 26 C 52 27.7 50.7 29 49 29 C 47.3 29 46 27.7 46 26 C 46 24.3 47.3 23 49 23 C 50.7 23 52 24.3 52 26 Z M 46.1 3.6 C 49.4 3.8 51.9 4.4 53.6 5.4 C 55.4 6.5 56.3 7.9 56.3 9.6 C 56.3 10.6 56 11.6 55.4 12.5 C 54.8 13.4 54 14.1 52.9 14.7 L 50.2 16.3 L 50.1 16.5 L 50.4 19.7 L 46.9 20.1 L 46.6 19.8 L 46.2 15.3 L 46.4 15 L 49.7 13.3 C 51.1 12.5 51.8 11.6 51.8 10.4 C 51.8 9.4 51.3 8.7 50.2 8.1 C 49.2 7.6 47.4 7.3 45.1 7.1 L 44.7 6.6 L 45.6 3.9 L 46.1 3.6 Z M 51.5 53.4 C 52.5 53.4 55 51 55 50 L 53.2 46.2 C 53.1 47.6 52 49.6 50 50 C 50 50 49.3 51.9 48.3 51.9 L 51.5 53.4 Z M 52 45 L 15 14 C 14 17 12 19 10 20 L 47 51 C 48 51 49 50 49 49 C 51 48.3 52 47 52 45 Z M 3 16 C 2 13 7 8 10 8 L 54 45 L 61 59 L 47 53 L 3 16 Z"/>
</svg>';
    }
    static function ui_description() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64" preserveAspectRatio="none">
  <path d="M 3 8 L 57 8 L 57 13 L 3 13 Z M 3 18 L 56 18 L 56 23 L 3 23 Z M 3 28 L 62 28 L 62 33 L 3 33 Z M 3 38 L 58 38 L 58 43 L 3 43 Z M 3 48 L 25 48 L 25 53 L 3 53 Z"/>
</svg>';
    }
}
