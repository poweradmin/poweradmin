<?php

class RecordLog {

    private $record_prior;
    private $record_after;

    private $record_changed = false;

    public function log_prior($rid) {
        $this->record_prior = $this->getRecord($rid);
    }

    public function log_after($rid) {
        $this->record_after = $this->getRecord($rid);
    }

    private function getRecord($rid) {
        return get_record_from_id($rid);
    }

    public function has_changed(array $record) {
        // Arrays are assigned by copy.
        // Copy arrays to avoid side effects caused by unset().
        $record_copy = $record;
        $record_prior_copy = $this->record_prior;

        // Don't compare the 'change_date'
        unset($record_copy["change_date"]);
        unset($record_prior_copy["change_date"]);

        // PowerDNS only searches for lowercase records
        $record_copy['name'] = strtolower($record_copy['name']);
        $record_prior_copy['name'] = strtolower($record_prior_copy['name']);

        // Quotes are special for SPF and TXT
        $type = $record_prior_copy['type'];
        if ($type == "SPF" || $type == "TXT") {
            $record_prior_copy['content'] = trim($record_prior_copy['content'], '"');
            $record_copy['content'] = trim($record_copy['content'], '"');
        }

        // Make $record_copy and $record_prior_copy compatible
        $record_copy['id'] = $record_copy['rid'];
        $record_copy['domain_id'] = $record_copy['zid'];
        unset($record_copy['zid']);
        unset($record_copy['rid']);

        // Do the comparison
        $this->record_changed = ($record_copy != $record_prior_copy);
        return $this->record_changed;
    }

    public function write() {
        global $db;

        $this->writeStdout();

        $prior_id = $this->log_records_data($this->record_prior);
        $after_id = $this->log_records_data($this->record_after);
        $record_type_id = $db->queryOne("SELECT id FROM log_records_type WHERE name = 'record_edit'");
        $now = date('Y-m-d H:i:s');
        $fullname = get_fullname_from_userid_local($_SESSION['userid']);

        // TODO: Log approving user (col                                                    v here)
        $log_insert_record = "INSERT INTO log_records (log_records_type_id, timestamp, user, prior, after) VALUES ("
            . $db->quote($record_type_id, 'integer') . ","
            . $db->quote($now, 'text') . ","
            . $db->quote($fullname, 'text') . ","
            . $db->quote($prior_id, 'integer') . ","
            . $db->quote($after_id, 'integer') . ")";
        $db->exec($log_insert_record);
    }

    private function log_records_data($record) {
        global $db;

        $query = "INSERT INTO log_records_data (domain_id, name, type, content, ttl, prio, change_date) VALUES ("
            . $db->quote($record['domain_id'], 'integer') . ","
            . $db->quote($record['name'], 'text') . ","
            . $db->quote($record['type'], 'text') . ","
            . $db->quote(trim($record['content'], '"'), 'text') . ","
            . $db->quote($record['ttl'], 'integer') . ","
            . $db->quote($record['prio'], 'integer') . ","
            . $db->quote($record['change_date'], 'integer') . ")";
        $db->exec($query);
        return $db->lastInsertId();


    }
}
