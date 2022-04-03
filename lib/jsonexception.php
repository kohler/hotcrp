<?php
// jsonexception.php -- HotCRP JSON exception handler (if PHP JsonException not available)
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.
/** @phan-file-suppress PhanRedefineFunction, PhanRedefineFunctionInternal, PhanRedefineClassInternal */

class JsonException extends Exception {
    function __construct($message = "", $code = 0, $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}
