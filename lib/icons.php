<?php
// icons.php -- HotCRP icon classes
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Icons {
    /** @readonly */
    static public $svg_open = '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64" preserveAspectRatio="none">';
    /** @readonly */
    static public $move_handle_horizontal_open = '<svg class="move-handle-icon" width="1em" height="0.666em" viewBox="0 0 18 12" preserveAspectRatio="none">';

    static function svg_contents($name) {
        switch ($name) {
        case "triangle0":
            return '<path d="M4 60L32 4L60 60z" />';
        case "triangle1":
            return '<path d="M4 4L60 32L4 60z" />';
        case "triangle2":
            return '<path d="M4 4L32 60L60 4z" />';
        case "triangle3":
            return '<path d="M60 4L4 32L60 60z" />';
        case "movearrow0":
            return '<path d="M26 64L28 28L10 46L6 44L32 1L58 44L54 46L36 28L38 64Z" />';
        case "movearrow2";
            return '<path d="M26 0L28 36L10 18L6 20L32 63L58 20L54 18L36 36L38 0Z" />';
        case "display":
            return '<path d="M 10 11L54 11C55 11 56 12 56 13L56 35C56 36 55 37 54 37L10 37C9 37 8 36 8 35L8 13C8 12 9 11 10 11ZM4 12L4 36C4 40 6 42 10 42L29 42L28 50C28 50 28 53 23 54C18 55 14 56 12 57C11 59 12 59 16 59L48 59C52 59 53 59 52 57C50 56 46 55 41 54C36 53 36 50 36 50L35 42L54 42C58 42 60 40 60 36L60 12C60 8 58 6 54 6L10 6C6 6 4 8 4 12Z" />';
        case "trash":
            return '<path d="M 48 55C48 58 46 60 43 60L21 60C18 60 16 58 16 55L16 20C14 20 13 19 13 17L13 14C13 12 15 10 17 10L21 10L21 8C21 6 23 4 25 4L39 4C41 4 43 6 43 8L43 10L47 10C49 10 51 12 51 14L51 17C51 19 50 20 48 20ZM15 14L15 18L49 18L49 14C49 13 48 12 47 12L17 12C16 12 15 13 15 14ZM23 8L23 10L41 10L41 8C41 7 40 6 39 6L25 6C24 6 23 7 23 8ZM19 54C19 55.7 20.3 57 22 57L42 57C43.7 57 45 55.7 45 54L45 20L19 20ZM39 22L40 22C40.6 22 41 22.4 41 23L41 53C41 53.6 40.6 54 40 54L39 54C38.4 54 38 53.6 38 53L38 23C38 22.4 38.4 22 39 22ZM31.5 22L32.5 22C33.1 22 33.5 22.4 33.5 23L33.5 53C33.5 53.6 33.1 54 32.5 54L31.5 54C30.9 54 30.5 53.6 30.5 53L30.5 23C30.5 22.4 30.9 22 31.5 22ZM24 22L25 22C25.6 22 26 22.4 26 23L26 53C26 53.6 25.6 54 25 54L24 54C23.4 54 23 53.6 23 53L23 23C23 22.4 23.4 22 24 22Z" />';
        case "eye":
            return '<path d="M32 18C50 18 59 32 59 32C59 32 50 47 32 47C14 47 5 32 5 32C5 32 14 18 32 18ZM10 32C10 32 17 42 32 42C47 42 54 32 54 32C54 32 47 23 32 23C17 23 10 32 10 32ZM40 32C40 36 36 40 32 40C28 40 24 36 24 32C24 28 28 24 32 24C36 24 40 28 40 32Z" />';
        case "pencil":
            return '<path d="M51.5 53.4C52.5 53.4 55 51 55 50L53.2 46.2C53.1 47.6 52 49.6 50 50C50 50 49.3 51.9 48.3 51.9L51.5 53.4ZM52 45L15 14C14 17 12 19 10 20L47 51C48 51 49 50 49 49C51 48.3 52 47 52 45ZM3 16C2 13 7 8 10 8L54 45L61 59L47 53L3 16Z" />';
        case "description":
            return '<path d="M3 8L57 8L57 13L3 13ZM3 18L56 18L56 23L3 23ZM3 28L62 28L62 33L3 33ZM3 38L58 38L58 43L3 43ZM3 48L25 48L25 53L3 53Z" />';
        case "upload":
            return '<path d="M 13 15 L 22 15 L 20 18 L 16 18 L 16 57 C 16 57 30 57 34 57 C 36 57 36 55 36 54 C 36 51 36 45 36 45 C 36 45 44 45 46 45 C 48 45 48 43 48 42 C 48 38 48 34 48 34 L 51 34 C 51 34 51 42 51 47 C 51 49 50 51 49 52 C 46 55 45 56 43 58 C 42 59 41 60 38 60 C 30 60 13 60 13 60 L 13 15 Z M 38 47 L 38 57 L 48 47 L 38 47 Z M 36 35 L 37 15 L 27 26 L 25 25 L 39 2 L 53 25 L 51 26 L 41 15 L 42 35 L 36 35 Z" />';
        case "attachment":
            return '<path d="M 35 2 C 29.8 2.1 25.5 6.4 25.5 11.7 L 25.5 48 C 25.5 51.3 28.5 53.9 31.8 53.9 C 35.4 53.8 38.3 51.3 38.3 48 L 38.3 23.7 C 38.3 23.7 38.3 22.6 37.4 22.6 C 37 22.6 36.5 22.6 36 22.6 C 35.6 22.6 35.2 23.2 35.2 23.7 L 35.4 47.4 C 35.3 49.3 33.8 50.9 31.9 50.9 C 30.1 50.9 28.6 49.4 28.6 47.5 L 28.6 11.6 C 28.6 8.1 31.5 5.1 35.1 5.1 C 38.7 5 41.7 8 41.7 11.6 L 41.7 49.4 C 41.7 54.6 37.3 59 31.9 59 C 26.7 59.1 22.4 54.8 22.4 49.5 L 22.4 28.5 C 22.4 28 21.9 27.6 21.5 27.6 C 21 27.6 20.5 27.6 19.9 27.6 C 19.3 27.6 19.2 28.6 19.2 28.6 L 19.2 49.5 C 19.2 56.5 25 62.1 32 62 C 39 61.9 44.7 56.3 44.7 49.3 L 44.7 11.5 C 44.7 6.2 40.4 1.9 35 2 Z" />';
        case "tag":
            return '<path d="M 9.6 21.9 C 9.6 21.9 9.2 23.9 9.9 24.9 L 31.2 59.1 C 32.1 60.6 33.9 61 35.3 60.1 L 54 48.5 C 55.5 47.6 55.9 45.8 55 44.4 L 33.7 10.1 C 33.5 9.6 32.8 9 31.6 8.5 L 17 3.5 C 15.3 2.9 14.1 2.9 13.1 3.5 C 12.2 4.1 11.6 5.2 11.4 6.9 C 11.4 6.9 9.7 21.9 9.6 21.9 Z M 52 45.4 L 33.5 56.9 L 13.2 24.2 L 31.7 12.7 Z M 18.1 8 C 20.2 8 21.9 9.7 21.9 11.8 C 21.9 13.9 20.2 15.6 18.1 15.6 C 16 15.6 14.3 13.9 14.3 11.8 C 14.3 9.7 16 8 18.1 8 Z" />';
        case "solid_question":
            return '<path d="M 63.5 32 C 63.5 49.396 49.396 63.5 32 63.5 C 14.604 63.5 0.5 49.396 0.5 32 C 0.5 14.604 14.604 0.5 32 0.5 C 49.396 0.5 63.5 14.604 63.5 32 Z M 36.212 39.584 L 36.212 38.464 C 36.152 35.684 37.152 33.264 39.372 30.724 C 41.732 28.124 44.712 25.024 44.712 20.244 C 44.712 15.164 40.208 10.334 32.088 10.334 C 27.608 10.334 22.852 11.998 19.112 16.54 L 24.206 21.862 C 25.588 20.524 27.42 17.934 31.34 17.934 C 34.38 17.934 36.056 19.624 36.056 21.784 C 36.056 23.844 33.552 25.884 31.572 28.244 C 28.772 31.584 27.772 34.804 27.912 37.984 L 28.032 39.584 Z M 31.932 55.018 C 34.562 55.01 37.452 52.598 37.452 49.378 C 37.392 46.038 35.232 43.738 31.872 43.738 C 28.652 43.738 26.352 46.038 26.352 49.378 C 26.352 52.598 29.288 55.026 31.932 55.018 Z" />';
        case "move_handle_horizontal":
            return '<circle cx="4" cy="4" r="1.5" /><circle cx="9" cy="4" r="1.5" /><circle cx="14" cy="4" r="1.5" /><circle cx="4" cy="9" r="1.5" /><circle cx="9" cy="9" r="1.5" /><circle cx="14" cy="9" r="1.5" />';
        default:
            throw new InvalidArgumentException("bad icon {$name}");
        }
    }
    /** @param string ...$names */
    static function stash_defs(...$names) {
        $svgs = [];
        foreach ($names as $name) {
            if (Ht::mark_stash("i-def-{$name}")) {
                $t = self::svg_contents($name);
                $svgs[] = "<g id=\"i-def-{$name}\">{$t}</g>";
            }
        }
        if (!empty($svgs)) {
            Ht::stash_html("<svg class=\"hidden\"><defs>" . join("", $svgs) . "</defs></svg>");
        }
    }
    /** @param string $name
     * @return string */
    static function ui_use($name) {
        if ($name === "move_handle_horizontal") {
            $open = self::$move_handle_horizontal_open;
        } else {
            $open = self::$svg_open;
        }
        return "{$open}<use href=\"#i-def-{$name}\" /></svg>";
    }
    /** @param 0|1|2|3 $direction
     * @return string */
    static function ui_triangle($direction) {
        // see also script.js
        return '<svg class="licon" width="0.75em" height="0.75em" viewBox="0 0 64 64" preserveAspectRatio="none">' . self::svg_contents("triangle{$direction}") . '</svg>';
    }
    /** @return string */
    static function ui_upperleft() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 16 16" preserveAspectRatio="none"><path d="M16 10L16 13C9 15 5 12 6 6L2 7L8 1L14 7L10 6C9 9 10 11 16 10z" /></svg>';
    }
    /** @param 0|1|2|3 $direction
     * @return string */
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
    /** @param 0|2 $direction
     * @return string */
    static function ui_movearrow($direction) {
        return self::$svg_open . self::svg_contents("movearrow{$direction}") . '</svg>';
    }
    /** @return string */
    static function ui_display() {
        return self::$svg_open . self::svg_contents("display") . '</svg>';
    }
    /** @return string */
    static function ui_trash() {
        return self::$svg_open . self::svg_contents("trash") . '</svg>';
    }
    /** @return string */
    static function ui_eye() {
        return self::$svg_open . self::svg_contents("eye") . '</svg>';
    }
    /** @return string */
    static function ui_visibility_hide() {
        return self::ui_eye();
    }
    /** @return string */
    static function ui_pencil() {
        return self::$svg_open . self::svg_contents("pencil") . '</svg>';
    }
    /** @return string */
    static function ui_edit_hide() {
        return self::ui_pencil();
    }
    /** @return string */
    static function ui_description() {
        return self::$svg_open . self::svg_contents("description") . '</svg>';
    }
    /** @return string */
    static function ui_upload() {
        return self::$svg_open . self::svg_contents("upload") . '</svg>';
    }
    /** @return string */
    static function ui_check_format() {
        return '<svg class="licon" width="1em" height="1em" viewBox="0 0 64 64" preserveAspectRatio="none">
  <path d="M 54 10 L 37 34 L 24 25 L 26 22 L 36 27 L 51 7 L 54 10 Z M 13 15 L 21 15 L 20 18 L 16 18 L 16 57 C 16 57 30 57 34 57 C 36 57 36 55 36 54 C 36 51 36 45 36 45 C 36 45 44 45 46 45 C 48 45 48 43 48 42 C 48 38 48 34 48 34 L 51 34 C 51 34 51 42 51 47 C 51 49 50 51 49 52 C 46 55 45 56 43 58 C 42 59 41 60 38 60 C 30 60 13 60 13 60 L 13 15 Z M 38 47 L 38 57 L 48 47 L 38 47 Z"></path>
</svg>';
    }
    /** @return string */
    static function ui_attachment() {
        return self::$svg_open . self::svg_contents("attachment") . '</svg>';
    }
    /** @return string */
    static function ui_tag() {
        return self::$svg_open . self::svg_contents("tag") . '</svg>';
//<path d="M 19.9 15.7 C 19.9 15.7 18.5 17.2 18.5 18.5 L 18.5 58.8 C 18.5 60.5 19.8 61.8 21.5 61.7 L 43.5 61.8 C 45.2 61.8 46.5 60.4 46.5 58.8 L 46.5 18.4 C 46.6 17.9 46.4 17 45.7 15.9 L 35.9 4 C 34.7 2.6 33.7 2 32.6 2 C 31.5 2 30.4 2.6 29.3 4 C 29.3 4 20 15.8 19.9 15.7 Z M 43.4 58.1 L 21.6 58.1 L 21.7 19.6 L 43.5 19.6 Z M 34.5 8.4 C 36.3 9.5 36.7 11.8 35.7 13.6 C 34.6 15.4 32.2 16 30.4 14.9 C 28.6 13.8 28.1 11.5 29.3 9.7 C 30.4 7.9 32.7 7.3 34.5 8.4 Z" /></svg>';
    }
    /** @return string */
    static function ui_solid_question() {
        return '<svg class="licon" width="0.75em" height="0.75em" viewBox="0 0 64 64" preserveAspectRatio="none">' . self::svg_contents("solid_question") . '</svg>';
    }
    /** @return string */
    static function ui_move_handle_horizontal() {
        return self::$move_handle_horizontal_open . self::svg_contents("move_handle_horizontal") . '</svg>';

    }
    /** @return string */
    static function ui_graph_scatter() {
        return '<svg class="licon-s" width="3em" height="2em" viewBox="0 0 96 64" preserveAspectRatio="none"><path stroke-linejoin="miter" d="M7 12V60H89" /><circle cx="22" cy="22" r="4" class="gdot" /><circle cx="39" cy="41" r="6" class="gdot" /><circle cx="54" cy="22" r="2" class="gdot" /><circle cx="64" cy="50" r="3" class="gdot" /><circle cx="64" cy="20" r="2" class="gdot" /><circle cx="75" cy="39" r="3" class="gdot" /></svg>';
    }
    /** @return string */
    static function ui_graph_bars() {
        return '<svg class="licon-s" width="3em" height="2em" viewBox="0 0 96 64" preserveAspectRatio="none"><path d="M18 59V29H25V59" class="gbar" /><path d="M35 59V22H42V59" class="gbar" /><path d="M70 59V41H77V59" class="gbar" /><path d="M53 59V33H60V59" class="gbar" /><path stroke-linejoin="miter" d="M7 12V60H89" /></svg>';
    }
    /** @return string */
    static function ui_graph_box() {
        return '<svg class="licon-s" width="3em" height="2em" viewBox="0 0 96 64" preserveAspectRatio="none"><path d="M19 50V27H25V50Z M22 18V27 M22 50V53" class="gbox" /><path d="M37 43V29H43V43Z M40 13V29 M40 43V49" class="gbox" /><path d="M70 40V20H76V40Z M73 17V20 M73 40V56" class="gbox" /><path d="M53 47V44H59V47Z M56 36V41 M56 47V53" class="gbox" /><path stroke-linejoin="miter" d="M7 12V60H89" /></svg>';
    }
    /** @return string */
    static function ui_graph_cdf() {
        return '<svg class="licon-s" width="3em" height="2em" viewBox="0 0 96 64" preserveAspectRatio="none"><path d="M21 60V54H33V46H50V32H60V28H66V15H71V12H89" class="gcdf" /><path stroke-linejoin="miter" d="M7 12V60H89" /></svg>';
    }
    /** @param string $name */
    static function stash_licon($name) {
        if (Ht::mark_stash("i-{$name}")) {
            $xname = str_replace("_", "-", $name);
            if (str_starts_with($xname, "ui-")) {
                $xname = substr($xname, 3);
            }
            $body = Icons::$name();
            Ht::stash_html("<div id=\"i-{$xname}\" class=\"hidden\">{$body}</div>");
        }
    }
}
