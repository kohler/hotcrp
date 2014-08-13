<?php
// capability.php -- HotCRP capability management
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CapabilityManager {

    private $dblink;
    private $prefix;

    public function __construct($dblink, $prefix) {
        $this->dblink = $dblink;
        $this->prefix = $prefix;
    }

    public function create($capabilityType, $options = array()) {
        global $Opt;
        $contactId = defval($options, "contactId", 0);
        $paperId = defval($options, "paperId", 0);
        $timeExpires = defval($options, "timeExpires", time() + 259200);
        $salt = hotcrp_random_bytes(24);
        $data = defval($options, "data");
        edb_ql($this->dblink, "insert into Capability set capabilityType=$capabilityType, contactId=??, paperId=??, timeExpires=??, salt=??, data=??",
               $contactId, $paperId, $timeExpires, $salt, $data);
        $capid = $this->dblink->insert_id;
        if (!$capid || !function_exists("hash_hmac"))
            return false;

        list($keyid, $key) = Contact::password_hmac_key(null, true);
        if (!($hash_method = @$Opt["capabilityHashMethod"]))
            $hash_method = (PHP_INT_SIZE == 8 ? "sha512" : "sha256");
        $text = substr(hash_hmac($hash_method, $capid . " " . $timeExpires . " " . $salt, $key, true), 0, 16);
        edb_ql($this->dblink, "insert ignore into CapabilityMap set capabilityValue=??, capabilityId=$capid, timeExpires=$timeExpires", $text);

        $text = str_replace(array("+", "/", "="), array("-", "_", ""), base64_encode($text));
        return $this->prefix . "1" . $text;
    }

    public function check($capabilityText) {
        if (substr($capabilityText, 0, strlen($this->prefix) + 1) !== $this->prefix . "1")
            return false;
        $value = base64_decode(str_replace(array("-", "_"), array("+", "/"),
                                           substr($capabilityText, strlen($this->prefix) + 1)));
        if (strlen($value) >= 16
            && ($result = edb_ql($this->dblink, "select * from CapabilityMap where capabilityValue=??", $value))
            && ($row = $result->fetch_object())
            && ($row->timeExpires == 0 || $row->timeExpires >= time())
            && ($result = edb_ql($this->dblink, "select * from Capability where capabilityId=" . $row->capabilityId))
            && ($row = $result->fetch_object())) {
            $row->capabilityValue = $value;
            return $row;
        } else
            return false;
    }

    public function delete($capdata) {
        if ($capdata) {
            edb_ql($this->dblink, "delete from CapabilityMap where capabilityValue=??", $capdata->capabilityValue);
            edb_ql($this->dblink, "delete from Capability where capabilityId=" . $capdata->capabilityId);
        }
    }

}
