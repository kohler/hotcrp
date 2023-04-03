<?php
// settings/s_rf.php -- HotCRP review field settings object
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Rf_Setting {
    // used by ReviewForm constructor
    public $id;
    public $name;
    public $order;
    public $type;
    public $description;
    public $visibility;
    public $required;
    public $exists_if;
    /** @var list<string> */
    public $values;
    public $ids;
    public $start;
    public $flip;
    public $scheme;

    // internal
    public $presence;
    /** @var list<RfValue_Setting> */
    public $xvalues;
    /** @var bool */
    public $existed = true;
    /** @var bool */
    public $deleted = false;
}

class RfValue_Setting {
    public $id;
    public $name;
    public $order;
    public $symbol;

    // internal
    public $old_value;
    /** @var bool */
    public $deleted = false;
}
