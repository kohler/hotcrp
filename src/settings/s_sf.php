<?php
// settings/s_sf.php -- HotCRP submission field setting object
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Sf_Setting {
    /** @var int|string */
    public $id;
    public $name;
    public $order;
    public $type;
    public $description;
    public $display;
    public $visibility;
    public $required;
    public $exists_if;
    public $exists_disabled;
    public $editable_if;
    public $values;
    public $ids;
    public $min;
    public $max;

    /** @var ?int */
    public $option_id;
    /** @var ?string */
    public $json_key;
    /** @var ?string */
    public $function;
    /** @var PaperOption */
    public $source_option;
    /** @var bool */
    public $existed = true;
    /** @var bool */
    public $deleted = false;

    /** @var list<SfValue_Setting> */
    public $xvalues;
}

class SfValue_Setting {
    public $id;
    public $name;
    public $order;

    // internal
    public $old_value;
    /** @var bool */
    public $deleted = false;
}
