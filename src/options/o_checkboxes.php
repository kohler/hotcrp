<?php
// o_checkboxes.php -- HotCRP helper class for checkboxes options
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Checkboxes_PaperOption extends CheckboxesBase_PaperOption {
    use Multivalue_OptionTrait;
    /** @var ?TopicSet */
    private $topics;

    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->assign_values($args->values ?? [], $args->ids ?? null);
    }


    function jsonSerialize() {
        $j = parent::jsonSerialize();
        $j->values = $this->values();
        if ($this->is_ids_nontrivial()) {
            $j->ids = $this->ids();
        }
        return $j;
    }

    function unparse_setting($sfs) {
        parent::unparse_setting($sfs);
        $this->unparse_values_setting($sfs);
    }

    /** @return TopicSet */
    function topic_set() {
        if ($this->topics === null) {
            $this->topics = new TopicSet($this->conf);
            foreach ($this->values() as $i => $s) {
                if ($s !== null)
                    $this->topics->__add($i, $s);
            }
        }
        return $this->topics;
    }


    function search_examples(Contact $viewer, $context) {
        $a = [$this->has_search_example()];
        if (($q = $this->value_search_keyword(2))) {
            $a[] = new SearchExample(
                $this->search_keyword() . ":<value>", $q,
                "<0>submission’s {title} field has value ‘{}’", $this->values[1]
            );
        }
        return $a;
    }
}
