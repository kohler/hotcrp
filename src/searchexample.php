<?php
// searchexample.php -- HotCRP helper class for search examples
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class SearchExample {
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
     * @param ?string $description
     * @param null|string|list<string> $params
     * @param ?string $param_q */
    function __construct($q, $description = null, $params = null, $param_q = null) {
        $this->q = $q;
        $this->description = $description;
        if ($params === null) {
            $this->params = [];
        } else if (is_string($params)) {
            $this->params = [$params];
        } else {
            $this->params = $params;
        }
        $this->param_q = $param_q;
    }
}
