<?php
// settings/s_sf.php -- HotCRP submission field setting object
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Sf_Setting {
    public $id;
    public $name;
    public $order;
    public $type;
    public $description;
    public $display;
    public $visibility;
    public $required;
    public $presence;
    public $selector;

    public $exists_if;
    public $final;
    /** @var list<SfValue_Setting> */
    public $xvalues;
}

class SfValue_Setting {
    public $id;
    public $name;
    public $order;
}
