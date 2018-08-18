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
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64" preserveAspectRatio="none"><path d="M 10 11L54 11C55 11 56 12 56 13L56 35C56 36 55 37 54 37L10 37C9 37 8 36 8 35L8 13C8 12 9 11 10 11ZM4 12L4 36C4 40 6 42 10 42L29 42L28 50C28 50 28 53 23 54C18 55 14 56 12 57C11 59 12 59 16 59L48 59C52 59 53 59 52 57C50 56 46 55 41 54C36 53 36 50 36 50L35 42L54 42C58 42 60 40 60 36L60 12C60 8 58 6 54 6L10 6C6 6 4 8 4 12Z" /></svg>';
    }
    static function ui_trash() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64" preserveAspectRatio="none"><path d="M 48 55C48 58 46 60 43 60L21 60C18 60 16 58 16 55L16 20C14 20 13 19 13 17L13 14C13 12 15 10 17 10L21 10L21 8C21 6 23 4 25 4L39 4C41 4 43 6 43 8L43 10L47 10C49 10 51 12 51 14L51 17C51 19 50 20 48 20ZM15 14L15 18L49 18L49 14C49 13 48 12 47 12L17 12C16 12 15 13 15 14ZM23 8L23 10L41 10L41 8C41 7 40 6 39 6L25 6C24 6 23 7 23 8ZM19 54C19 55.7 20.3 57 22 57L42 57C43.7 57 45 55.7 45 54L45 20L19 20ZM39 22L40 22C40.6 22 41 22.4 41 23L41 53C41 53.6 40.6 54 40 54L39 54C38.4 54 38 53.6 38 53L38 23C38 22.4 38.4 22 39 22ZM31.5 22L32.5 22C33.1 22 33.5 22.4 33.5 23L33.5 53C33.5 53.6 33.1 54 32.5 54L31.5 54C30.9 54 30.5 53.6 30.5 53L30.5 23C30.5 22.4 30.9 22 31.5 22ZM24 22L25 22C25.6 22 26 22.4 26 23L26 53C26 53.6 25.6 54 25 54L24 54C23.4 54 23 53.6 23 53L23 23C23 22.4 23.4 22 24 22Z" /></svg>';
    }
    static function ui_eye() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64" preserveAspectRatio="none"><path d="M32 18C50 18 59 32 59 32C59 32 50 47 32 47C14 47 5 32 5 32C5 32 14 18 32 18ZM10 32C10 32 17 42 32 42C47 42 54 32 54 32C54 32 47 23 32 23C17 23 10 32 10 32ZM40 32C40 36 36 40 32 40C28 40 24 36 24 32C24 28 28 24 32 24C36 24 40 28 40 32Z"/></svg>';
    }
    static function ui_visibility_hide() {
        return self::ui_eye();
    }
    static function ui_pencil() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64" preserveAspectRatio="none"><path d="M51.5 53.4C52.5 53.4 55 51 55 50L53.2 46.2C53.1 47.6 52 49.6 50 50C50 50 49.3 51.9 48.3 51.9L51.5 53.4ZM52 45L15 14C14 17 12 19 10 20L47 51C48 51 49 50 49 49C51 48.3 52 47 52 45ZM3 16C2 13 7 8 10 8L54 45L61 59L47 53L3 16Z"/></svg>';
    }
    static function ui_edit_hide() {
        return self::ui_pencil();
    }
    static function ui_description() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64" preserveAspectRatio="none"><path d="M3 8L57 8L57 13L3 13ZM3 18L56 18L56 23L3 23ZM3 28L62 28L62 33L3 33ZM3 38L58 38L58 43L3 43ZM3 48L25 48L25 53L3 53Z"/></svg>';
    }
    static function ui_upload() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64" preserveAspectRatio="none">
  <path d="M 13 15 L 22 15 L 20 18 L 16 18 L 16 57 C 16 57 30 57 34 57 C 36 57 36 55 36 54 C 36 51 36 45 36 45 C 36 45 44 45 46 45 C 48 45 48 43 48 42 C 48 38 48 34 48 34 L 51 34 C 51 34 51 42 51 47 C 51 49 50 51 49 52 C 46 55 45 56 43 58 C 42 59 41 60 38 60 C 30 60 13 60 13 60 L 13 15 Z M 38 47 L 38 57 L 48 47 L 38 47 Z M 36 35 L 37 15 L 27 26 L 25 25 L 39 2 L 53 25 L 51 26 L 41 15 L 42 35 L 36 35 Z"></path>
</svg>';
    }
    static function ui_check_format() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64" preserveAspectRatio="none">
  <path d="M 54 10 L 37 34 L 24 25 L 26 22 L 36 27 L 51 7 L 54 10 Z M 13 15 L 21 15 L 20 18 L 16 18 L 16 57 C 16 57 30 57 34 57 C 36 57 36 55 36 54 C 36 51 36 45 36 45 C 36 45 44 45 46 45 C 48 45 48 43 48 42 C 48 38 48 34 48 34 L 51 34 C 51 34 51 42 51 47 C 51 49 50 51 49 52 C 46 55 45 56 43 58 C 42 59 41 60 38 60 C 30 60 13 60 13 60 L 13 15 Z M 38 47 L 38 57 L 48 47 L 38 47 Z"></path>
</svg>';
    }
}
