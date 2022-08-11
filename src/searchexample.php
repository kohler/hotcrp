<?php
// searchexample.php -- HotCRP helper class for search examples
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class SearchExample {
    /** @var ?PaperOption */
    public $opt;
    /** @var string */
    public $q;
    /** @var ?string */
    public $t;
    /** @var ?string */
    public $description;
    /** @var list<string> */
    public $params;
    /** @var ?string */
    public $param_q;

    /** @param string $q
     * @param ?string $param_q
     * @param string $description
     * @param string|FmtArg ...$params */
    function __construct($q, $param_q, $description, ...$params) {
        $this->q = $q;
        $this->param_q = $param_q;
        $this->description = $description;
        $this->params = $params;
    }
}
