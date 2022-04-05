<?php
// contactcountmatcher.php -- HotCRP helper class for matching with users
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ContactCountMatcher extends CountMatcher {
    /** @var ?list<int> */
    private $_contacts; // null means ‘matches all contacts’

    /** @param string $countexpr
     * @param null|int|list<int> $contacts */
    function __construct($countexpr, $contacts) {
        parent::__construct($countexpr);
        $this->set_contacts($contacts);
    }

    /** @return ?list<int> */
    function contact_set() {
        return $this->_contacts;
    }

    /** @return bool */
    function has_contacts() {
        return $this->_contacts !== null;
    }

    /** @return ?int */
    function single_cid() {
        if ($this->_contacts !== null && count($this->_contacts) === 1) {
            return $this->_contacts[0];
        } else {
            return null;
        }
    }

    /** @param string $fieldname
     * @return string */
    function contact_match_sql($fieldname) {
        if ($this->_contacts === null) {
            return "true";
        } else {
            return $fieldname . sql_in_int_list($this->_contacts);
        }
    }

    function tautology() {
        if ($this->_contacts !== null
            && ($x = $this->test(0)) === $this->test(count($this->_contacts))) {
            return $x;
        } else {
            return parent::tautology();
        }
    }

    /** @param int $cid
     * @return bool */
    function test_contact($cid) {
        return $this->_contacts === null || in_array($cid, $this->_contacts, true);
    }

    /** @param int $cid */
    function add_contact($cid) {
        $this->_contacts = $this->_contacts ?? [];
        if (!in_array($cid, $this->_contacts, true)) {
            $this->_contacts[] = $cid;
        }
    }

    /** @param null|int|list<int> $contacts */
    function set_contacts($contacts) {
        assert($contacts === null || is_array($contacts) || is_int($contacts));
        $this->_contacts = is_int($contacts) ? [$contacts] : $contacts;
    }
}
