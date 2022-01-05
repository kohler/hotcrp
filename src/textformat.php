<?php
// textformat.php -- HotCRP text format info class
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class TextFormat {
    public $format;
    public $description;
    public $description_text;
    public $simple_regex;
    public $has_preview;

    function __construct($format, $settings) {
        $this->format = $format;
        foreach ($settings as $k => $v)
            $this->$k = $v;
    }
    function description_text() {
        if ($this->description_text === null
            && $this->description !== null)
            $this->description_text = Text::html_to_text($this->description);
        return (string) $this->description_text;
    }
    function description_preview_html() {
        $d = [];
        if ((string) $this->description !== "")
            $d[] = $this->description;
        else if ((string) $this->description_text !== "")
            $d[] = htmlspecialchars($this->description_text);
        if ($this->has_preview)
            $d[] = '<a href="" class="ui js-togglepreview" data-format="'
                . $this->format . '" tabindex="-1">Preview</a>';
        if ($d)
            return '<div class="formatdescription">'
                . join(' <span class="barsep">·</span> ', $d) . '</div>';
        else
            return "";
    }
}
