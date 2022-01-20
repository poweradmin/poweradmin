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

        // PowerDNS only searches for lowercase records
        $record_copy['name'] = strtolower($record_copy['name']);
        $record_prior_copy['name'] = strtolower($record_prior_copy['name']);

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
        log_info(sprintf('client_ip:%s user:%s operation:edit_record'
            . ' old_record_type:%s old_record:%s old_content:%s old_ttl:%s old_priority:%s'
            . ' record_type:%s record:%s content:%s ttl:%s priority:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
            $this->record_prior['type'], $this->record_prior['name'],
            $this->record_prior['content'], $this->record_prior['ttl'], $this->record_prior['prio'],
            $this->record_after['type'], $this->record_after['name'],
            $this->record_after['content'], $this->record_after['ttl'], $this->record_after['prio']));
    }
}
