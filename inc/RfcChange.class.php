<?php

class RfcChange
{
    private $zone;
    private $serial;
    private $prior;
    private $after;

    private $rfc_id = null;

    /**
     * RfcChange constructor.
     * @param int $zone The id of the zone / domain
     * @param string $serial The serial based on which the change is valid.
     * @param Record $prior The record before the change.
     * @param Record $after The record after the change.
     */
    public function __construct($zone, $serial, $prior, $after)
    {
        $this->zone = $zone;
        $this->serial = $serial;
        $this->prior = $prior;
        $this->after = $after;
    }

    public function setRfcId($rfc_id)
    {
        $this->rfc_id = $rfc_id;
    }

    /**
     * @param PDOLayer $db A connection to the database.
     * @return integer
     */
    public function write($db)
    {
        $rfc_data_prior_id = $this->insert_record($db, $this->prior);
        $rfc_data_after_id = $this->insert_record($db, $this->after);

        $rfc_change_query = "INSERT INTO rfc_change (zone, serial, prior, after, rfc) VALUES ("
            . $db->quote($this->zone, 'integer') . ","
            . $db->quote($this->serial, 'text') . ","
            . $db->quote($rfc_data_prior_id, 'integer') . ","
            . $db->quote($rfc_data_after_id, 'integer') . ","
            . $db->quote($this->rfc_id, 'integer') . ")";
        $db->exec($rfc_change_query);
        $rfc_change_id = $db->lastInsertId(); // TODO: Fix PosgreSQL

        return $rfc_change_id;
    }

    /**
     * @param PDOLayer $db A connection to the database.
     * @param Record $record Inserts a record in the rfc_data shadow table.
     * @return int The id of the inserted row.
     */
    private function insert_record($db, $record)
    {
        $query = "INSERT INTO rfc_data (domain_id, name, type, content, ttl, prio, change_date) VALUES ("
            . $db->quote($record->getDomainId(), 'integer') . ","
            . $db->quote($record->getName(), 'text') . ","
            . $db->quote($record->getType(), 'text') . ","
            . $db->quote($record->getContent(), 'text') . ","
            . $db->quote($record->getTtl(), 'integer') . ","
            . $db->quote($record->getPrio(), 'integer') . ","
            . $db->quote($record->getChangeDate(), 'integer') . ")";
        $db->exec($query);
        $result_id = $db->lastInsertId(); // TODO: Fix PosgreSQL

        return $result_id;
    }
}
