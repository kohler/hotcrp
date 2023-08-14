<?php
// tagmessagereport.php -- HotCRP tags API class
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class TagMessageReport implements JsonSerializable {
    /** @var ?bool */
    public $ok;
    /** @var ?int */
    public $pid;
    /** @var ?list<MessageItem> */
    public $message_list;
    /** @var ?list<string> */
    public $tags;
    /** @var ?list<string> */
    public $tags_conflicted;
    /** @var ?string */
    public $tags_edit_text;
    /** @var ?string */
    public $tags_view_html;
    /** @var ?string */
    public $tag_decoration_html;
    /** @var ?string */
    public $color_classes;
    /** @var ?string */
    public $color_classes_conflicted;
    /** @var ?string */
    public $status_html;

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $r = [];
        foreach (get_object_vars($this) as $k => $v) {
            if ($v !== null)
                $r[$k] = $v;
        }
        return $r;
    }
}
