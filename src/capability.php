<?php
// capability.php -- HotCRP capability management
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CapabilityManager {
    private $dblink;
    private $prefix;

    public function __construct($dblink, $prefix) {
        $this->dblink = $dblink;
        $this->prefix = $prefix;
    }

    public function create($capabilityType, $options = array()) {
        $contactId = get($options, "contactId", 0);
        if (!$contactId && ($user = get($options, "user")))
            $contactId = $this->prefix === "U" ? $user->contactDbId : $user->contactId;
        $paperId = get($options, "paperId", 0);
        $timeExpires = get($options, "timeExpires", time() + 259200);
        $data = get($options, "data");
        $ok = false;

        for ($tries = 0; !$ok && $tries < 4; ++$tries)
            if (($salt = random_bytes(16)) !== false) {
                $result = Dbl::ql($this->dblink, "insert into Capability set capabilityType=?, contactId=?, paperId=?, timeExpires=?, salt=?, data=?", $capabilityType, $contactId, $paperId, $timeExpires, $salt, $data);
                $ok = $result && $result->affected_rows > 0;
            }

        if (!$ok)
            return false;
        return $this->prefix . "1"
            . str_replace(array("+", "/", "="),
                          array("-a", "-b", ""), base64_encode($salt));
    }

    public function check($capabilityText) {
        if (substr($capabilityText, 0, strlen($this->prefix) + 1) !== $this->prefix . "1")
            return false;
        $value = base64_decode(str_replace(array("-a", "-b", "-", "_"), // -, _ obsolete
                                           array("+", "/", "+", "/"),
                                           substr($capabilityText, strlen($this->prefix) + 1)));
        if (strlen($value) >= 16
            && ($result = Dbl::ql($this->dblink, "select * from Capability where salt=?", $value))
            && ($row = Dbl::fetch_first_object($result))
            && ($row->timeExpires == 0 || $row->timeExpires >= time()))
            return $row;
        else
            return false;
    }

    public function delete($capdata) {
        assert(!$capdata || is_string($capdata->salt));
        if ($capdata)
            Dbl::ql($this->dblink, "delete from Capability where salt=?", $capdata->salt);
    }
}
