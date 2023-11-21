<?php
// contactset.php -- HotCRP class for sets of users
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class ContactSet implements IteratorAggregate, Countable {
    /** @var list<Contact> */
    private $urows = [];
    /** @var array<int,Contact> */
    private $by_uid = [];

    /** @param Contact $user
     * @return ContactSet */
    static function make_singleton($user) {
        $set = new ContactSet;
        $set->add_user($user);
        return $set;
    }
    /** @param Dbl_Result $result
     * @param Conf $conf
     * @return ContactSet */
    static function make_result($result, $conf) {
        $set = new ContactSet;
        $set->add_result($result, $conf);
        return $set;
    }
    /** @param bool $claim */
    function add_user(Contact $u, $claim = false) {
        $this->urows[] = $this->by_uid[$u->contactXid] = $u;
        if ($claim) {
            $u->_row_set = $this;
        }
    }
    /** @param Dbl_Result $result
     * @param Conf $conf */
    function add_result($result, $conf) {
        while (($u = Contact::fetch($result, $conf))) {
            $this->add_user($u, true);
        }
        Dbl::free($result);
    }
    /** @return list<Contact> */
    function as_list() {
        return $this->urows;
    }
    /** @return array<int,Contact> */
    function as_map() {
        return $this->by_uid;
    }
    /** @return array<int,Contact>
     * @deprecated */
    function all() {
        return $this->by_uid;
    }
    /** @return list<int> */
    function user_ids() {
        return array_keys($this->by_uid);
    }
    /** @return int */
    function size() {
        return count($this->urows);
    }
    #[\ReturnTypeWillChange]
    /** @return int */
    function count() {
        return count($this->urows);
    }
    /** @param callable(Contact,Contact):int $compare */
    function sort_by($compare) {
        usort($this->urows, $compare);
        $this->by_uid = [];
        foreach ($this->urows as $u) {
            $this->by_uid[$u->contactXid] = $u;
        }
    }
    /** @param int $uid
     * @return ?Contact */
    function user_by_id($uid) {
        return $this->by_uid[$uid] ?? null;
    }
    /** @param int $uid
     * @return Contact */
    function checked_user_by_id($uid) {
        $u = $this->by_uid[$uid] ?? null;
        if (!$u) {
            throw new Exception("ContactSet::checked_user_by_id({$uid}) failure");
        }
        return $u;
    }
    /** @param int $uid
     * @return ?Contact */
    function get($uid) {
        return $this->by_uid[$uid] ?? null;
    }
    /** @param int $uid
     * @return bool */
    function contains($uid) {
        return isset($this->by_uid[$uid]);
    }
    #[\ReturnTypeWillChange]
    /** @return Iterator<Contact> */
    function getIterator() {
        return new ArrayIterator($this->urows);
    }
}
