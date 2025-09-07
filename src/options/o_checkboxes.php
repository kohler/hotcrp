<?php
// o_checkboxes.php -- HotCRP helper class for checkboxes options
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Checkboxes_PaperOption extends CheckboxesBase_PaperOption {
    use Multivalue_OptionTrait;
    /** @var ?TopicSet */
    private $topics;

    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->assign_values($args->values ?? [], $args->ids ?? null);
        $this->compact = true;
    }


    function jsonSerialize() {
        $j = parent::jsonSerialize();
        $j->values = $this->values();
        if ($this->is_ids_nontrivial()) {
            $j->ids = $this->ids();
        }
        return $j;
    }

    function export_setting() {
        $sfs = parent::export_setting();
        $this->unparse_values_setting($sfs);
        return $sfs;
    }

    /** @return TopicSet */
    function topic_set() {
        return $this->values_topic_set();
    }


    function search_examples(Contact $viewer, $context) {
        $a = [$this->has_search_example()];
        if (($q = $this->value_search_keyword(2))) {
            $a[] = $this->make_search_example(
                $this->search_keyword() . ":{value}",
                "<0>submission’s {title} field has value ‘{value}’",
                new FmtArg("value", $this->values[1], 0)
            );
        }
        return $a;
    }
}
