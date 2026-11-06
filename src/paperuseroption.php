<?php
// paperuseroption.php -- HotCRP per-submission, per-user options
// Copyright (c) 2026 Eddie Kohler; see LICENSE.

/** A per-submission, per-user option: a kind of value that users can have
 * for submissions, such as committee members’ opinions. Values are
 * `PaperUserValue`s.
 *
 * Options are components from `etc/paperuseroptions.json` and
 * `$Opt["paperUserOptions"]`, with these properties:
 *
 * * `id`: The option’s ID, used as `puoId` in the `PaperUserValue` table; a
 *   positive integer.
 * * `name`: A keyword naming the option, such as `opinion`.
 * * `title`: A display name.
 * * `values`: Optional named values, a list of objects with `value` (a
 *   nonzero integer), `name` (a keyword), and optional `title`.
 * * `visibility`: Who can see other users’ values: `pc` (the default) means
 *   PC members who can view the submission and have no conflict with it,
 *   and `admin` means the submission’s administrators. Users can always see
 *   their own values.
 *
 * PC members who can view a submission and have no conflict with it can set
 * their own values; administrators can set any PC member’s value. */
final class PaperUserOption {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var int
     * @readonly */
    public $id;
    /** @var string
     * @readonly */
    public $name;
    /** @var string
     * @readonly */
    public $title;
    /** @var 'pc'|'admin'
     * @readonly */
    public $visibility;
    /** @var list<object> */
    private $values = [];

    /** @param object $j */
    function __construct(Conf $conf, $j) {
        $this->conf = $conf;
        $this->id = $j->id;
        $this->name = $j->name;
        $this->title = $j->title ?? $j->name;
        $this->visibility = ($j->visibility ?? "pc") === "admin" ? "admin" : "pc";
        foreach ($j->values ?? [] as $vj) {
            if (is_object($vj)
                && is_int($vj->value ?? null)
                && $vj->value !== 0
                && is_string($vj->name ?? null)) {
                $this->values[] = $vj;
            }
        }
    }

    /** @param mixed $j
     * @return bool */
    static function valid_json($j) {
        return is_object($j)
            && is_int($j->id ?? null)
            && $j->id > 0
            && is_string($j->name ?? null)
            && preg_match('/\A[a-z][-a-z0-9_]*\z/', $j->name);
    }

    /** Parse `$s` as a value of this option: a value name or integer. Return
     * 0 for no value (`none`, `clear`, or empty), and null if `$s` is not a
     * valid value.
     * @param string|int $s
     * @return ?int */
    function parse_value($s) {
        if (is_int($s)) {
            return $s;
        }
        $s = trim($s);
        if ($s === "" || strcasecmp($s, "none") === 0 || strcasecmp($s, "clear") === 0) {
            return 0;
        }
        foreach ($this->values as $vj) {
            if (strcasecmp($s, $vj->name) === 0)
                return $vj->value;
        }
        return stoi($s);
    }

    /** Return the name of value `$v`, or its decimal representation if it
     * has no name.
     * @param int $v
     * @return string */
    function value_name($v) {
        foreach ($this->values as $vj) {
            if ($vj->value === $v)
                return $vj->name;
        }
        return (string) $v;
    }

    /** @param int $v
     * @return string */
    function value_title($v) {
        foreach ($this->values as $vj) {
            if ($vj->value === $v)
                return $vj->title ?? $vj->name;
        }
        return (string) $v;
    }

    /** @return list<object> */
    function named_values() {
        return $this->values;
    }

    /** Return true if `$user` can see user `$cid`’s value for `$prow`.
     * @param int $cid
     * @return bool */
    function can_view(Contact $user, PaperInfo $prow, $cid) {
        if ($user->contactId > 0 && $user->contactId === $cid) {
            return true;
        } else if ($user->is_admin($prow)) {
            return true;
        }
        return $this->visibility === "pc"
            && $user->isPC
            && $user->can_view_paper($prow)
            && !$prow->has_conflict($user);
    }

    /** Return true if `$user` can set `$target`’s value for `$prow`.
     * @return bool */
    function can_edit(Contact $user, PaperInfo $prow, Contact $target) {
        if (!$target->isPC
            || !$target->can_view_paper($prow)) {
            return false;
        } else if ($target->contactId === $user->contactId) {
            return !$prow->has_conflict($user) || $user->is_admin($prow);
        }
        return $user->is_admin($prow);
    }
}
