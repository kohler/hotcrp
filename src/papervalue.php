<?php
// papervalue.php -- HotCRP helper class for paper options
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

final class PaperValue implements JsonSerializable {
    /** @var PaperInfo
     * @readonly */
    public $prow;
    /** @var PaperOption
     * @readonly */
    public $option;
    /** @var ?int */
    public $value;
    /** @var ?array<string,mixed> */
    private $_anno;
    /** @var list<int> */
    private $_values = [];
    /** @var list<?string> */
    private $_data = [];
    /** @var null|false|DocumentInfoSet */
    private $_docset;
    /** @var ?MessageSet */
    private $_ms;

    const NEWDOC_VALUE = -142398;

    /** @param PaperInfo $prow
     * @suppress PhanDeprecatedProperty */
    function __construct($prow, PaperOption $o) { // XXX should be private
        $this->prow = $prow;
        $this->option = $o;
    }
    /** @param PaperInfo $prow
     * @param ?int $value
     * @param ?string $data
     * @return PaperValue */
    static function make($prow, PaperOption $o, $value = null, $data = null) {
        $ov = new PaperValue($prow, $o);
        if ($value !== null) {
            $ov->set_value_data([$value], [$data]);
        }
        return $ov;
    }
    /** @param PaperInfo $prow
     * @param list<int> $values
     * @param list<?string> $datas
     * @return PaperValue */
    static function make_multi($prow, PaperOption $o, $values, $datas) {
        $ov = new PaperValue($prow, $o);
        $ov->set_value_data($values, $datas);
        return $ov;
    }
    /** @param PaperInfo $prow
     * @param string $msg
     * @return PaperValue */
    static function make_estop($prow, PaperOption $o, $msg) {
        $ov = new PaperValue($prow, $o);
        $ov->estop($msg);
        return $ov;
    }
    /** @param PaperInfo $prow
     * @return PaperValue */
    static function make_force($prow, PaperOption $o) {
        $ov = new PaperValue($prow, $o);
        $o->value_force($ov);
        return $ov;
    }

    function load_value_data() {
        $ovd = $this->prow->option_value_data($this->option->id);
        $this->set_value_data($ovd[0], $ovd[1]);
    }
    /** @param list<int> $values
     * @param list<?string> $datas */
    function set_value_data($values, $datas) {
        if ($this->_values != $values) {
            $this->_values = $values;
            if ($this->_docset !== false) {
                $this->_docset = null;
            }
        }
        $this->value = $this->_values[0] ?? null;
        $this->_data = $datas;
    }

    /** @return int */
    function option_id() {
        return $this->option->id;
    }
    /** @return int */
    function value_count() {
        return count($this->_values);
    }
    /** @return list<int> */
    function value_list() {
        return $this->_values;
    }
    /** @return ?string */
    function data() {
        if ($this->_data === null) {
            $this->load_value_data();
        }
        if ($this->value !== null) {
            return $this->_data[0] ?? null;
        }
        return null;
    }
    /** @return list<?string> */
    function data_list() {
        if ($this->_data === null) {
            $this->load_value_data();
        }
        return $this->_data;
    }
    /** @param int $index
     * @return ?string */
    function data_by_index($index) {
        return ($this->data_list())[$index] ?? null;
    }

    /** @param PaperValue $x
     * @return bool */
    function equals($x) {
        return $this->value_list() === $x->value_list()
            && $this->data_list() === $x->data_list();
    }

    /** @param bool $full
     * @return DocumentInfoSet */
    function document_set($full = false) {
        assert($this->prow || empty($this->_values));
        assert($this->option->has_document());
        assert($this->_docset !== false);
        // must recreate document set with full information if requested
        if ($full
            && $this->_docset !== null
            && ($this->option->id === DTYPE_SUBMISSION || $this->option->id === DTYPE_FINAL)
            && ($doc = $this->_docset->document_by_index(0))
            && $doc->compression === -1) {
            $this->_docset = null;
        }
        // create document set if doesn't exist
        if ($this->_docset === null) {
            $this->_docset = false;
            $docset = new DocumentInfoSet;
            // NB value_dids() might call set_value_data
            foreach ($this->option->value_dids($this) as $did) {
                $d = $this->prow->document($this->option->id, $did, $full);
                $docset->add($d ?? DocumentInfo::make_empty($this->prow->conf, $this->prow));
            }
            assert($this->_docset === false);
            $this->_docset = $docset;
        }
        return $this->_docset;
    }
    /** @return list<DocumentInfo> */
    function documents() {
        return $this->document_set()->as_list();
    }
    /** @param int $index
     * @return ?DocumentInfo */
    function document($index) {
        return $this->document_set()->document_by_index($index);
    }
    /** @param int $index
     * @return string|false */
    function document_content($index) {
        $doc = $this->document($index);
        return $doc ? $doc->content() : false;
    }
    /** @param int $did
     * @return ?DocumentInfo */
    function document_by_id($did) {
        return $this->prow->document($this->option->id, $did);
    }
    /** @param string $name
     * @return ?DocumentInfo */
    function attachment($name) {
        return $this->option->attachment($this, $name);
    }
    function invalidate() {
        $this->prow->invalidate_options(true);
        $this->load_value_data();
        $this->_anno = null;
    }

    /** @param string $name
     * @return bool */
    function has_anno($name) {
        return $this->_anno !== null && array_key_exists($name, $this->_anno);
    }
    /** @param string $name
     * @return mixed */
    function anno($name) {
        return $this->_anno[$name] ?? null;
    }
    /** @param string $name
     * @param mixed $value */
    function set_anno($name, $value) {
        $this->_anno[$name] = $value;
    }
    /** @param string $name
     * @param mixed $value */
    function push_anno($name, $value) {
        $this->_anno = $this->_anno ?? [];
        $this->_anno[$name][] = $value;
    }

    /** @return MessageSet */
    function message_set() {
        if ($this->_ms === null) {
            $this->_ms = new MessageSet;
            $this->_ms->set_want_ftext(true, 5);
        }
        return $this->_ms;
    }
    /** @param MessageItem $mi
     * @return MessageItem */
    function append_item($mi) {
        return $this->message_set()->append_item($mi);
    }
    /** @param string $field
     * @param ?string $msg
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status
     * @deprecated */
    function msg_at($field, $msg, $status) {
        return $this->append_item(new MessageItem($status, $field, $msg));
    }
    /** @param ?string $msg
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status
     * @deprecated */
    function msg($msg, $status) {
        return $this->append_item(new MessageItem($status, $this->option->field_key(), $msg));
    }
    /** @param ?string $msg
     * @return MessageItem */
    function estop($msg) {
        return $this->append_item(MessageItem::estop_at($this->option->field_key(), $msg));
    }
    /** @param ?string $msg
     * @return MessageItem */
    function error($msg) {
        return $this->append_item(MessageItem::error_at($this->option->field_key(), $msg));
    }
    /** @param ?string $msg
     * @return MessageItem */
    function warning($msg) {
        return $this->append_item(MessageItem::warning_at($this->option->field_key(), $msg));
    }
    /** @param ?string $msg
     * @return MessageItem */
    function inform($msg) {
        return $this->append_item(MessageItem::inform_at($this->option->field_key(), $msg));
    }
    /** @return int */
    function problem_status() {
        return $this->_ms ? $this->_ms->problem_status() : 0;
    }
    /** @return bool */
    function has_error() {
        return $this->_ms && $this->_ms->has_error();
    }
    /** @return list<MessageItem> */
    function message_list() {
        return $this->_ms ? $this->_ms->message_list() : [];
    }
    /** @return string */
    function field_key() {
        return $this->option->field_key();
    }
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        if ($this->_data === null
            || $this->_data === []
            || (count($this->_data) === 1 && $this->_data[0] === null)) {
            $x = $this->value;
        } else {
            $x = [];
            for ($i = 0; $i !== count($this->_values); ++$i) {
                $x[] = [$this->_values[$i], $this->_data[$i]];
            }
        }
        return [$this->option->json_key() => $x];
    }
}
