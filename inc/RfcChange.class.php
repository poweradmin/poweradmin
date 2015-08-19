<?php

class RfcChange
{
    private $zone;
    private $serial;
    private $prior;
    private $after;

    private $rfc_id = null;
    private $affected_record_id = null;

    /**
     * RfcChange constructor.
     * @param int $zone The id of the zone / domain
     * @param string $serial The serial based on which the change is valid.
     * @param Record $prior The record before the change.
     * @param Record $after The record after the change.
     * @param int|null $affected_record_id The record id this change is based upon
     */
    public function __construct($zone, $serial, $prior, $after, $affected_record_id = null)
    {
        $this->zone = $zone;
        $this->serial = $serial;
        $this->prior = $prior;
        $this->after = $after;
        $this->affected_record_id = $affected_record_id;
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

        $stmt = $db->prepare("INSERT INTO rfc_change (zone, serial, prior, after, rfc, affected_record_id) VALUES (:zone, :serial, :prior, :after, :rfc, :affected_record_id)");
        $stmt->bindParam(":zone", $this->zone, PDO::PARAM_INT);
        $stmt->bindParam(":serial", $this->serial);
        $stmt->bindParam(":prior", $rfc_data_prior_id, PDO::PARAM_INT);
        $stmt->bindParam(":after", $rfc_data_after_id, PDO::PARAM_INT);
        $stmt->bindParam(":rfc", $this->rfc_id, PDO::PARAM_INT);
        $stmt->bindValue(":affected_record_id", $this->affected_record_id,PDO::PARAM_INT);

        $success = $stmt->execute();
        $rfc_change_id = $db->lastInsertId(); // TODO: Fix PosgreSQL

        return $rfc_change_id;
    }

    /**
     * @param PDOLayer $db A connection to the database.
     * @param Record $record Inserts a record in the rfc_data shadow table.
     * @return int The id of the inserted row. Returns null if $record was null.
     */
    private function insert_record($db, $record)
    {
        if($record === null) { return null; }

        if($record->getChangeDate() === null) {
            $record->setChangeDate(time());
        }

        $stmt = $db->prepare("INSERT INTO rfc_data (domain_id, name, type, content, ttl, prio, change_date)
                              VALUES (:domain, :name, :type, :content, :ttl, :prio, :change_date)");
        $stmt->bindParam(":domain", $record->getDomainId(), PDO::PARAM_INT);
        $stmt->bindParam(":name", $record->getName());
        $stmt->bindParam(":type", $record->getType());
        $stmt->bindParam(":content", $record->getContent());
        $stmt->bindParam(":ttl", $record->getTtl(), PDO::PARAM_INT);
        $stmt->bindParam(":prio", $record->getPrio(), PDO::PARAM_INT);
        $stmt->bindParam(":change_date", $record->getChangeDate(), PDO::PARAM_INT);
        $success = $stmt->execute();

        $result_id = $db->lastInsertId(); // TODO: Fix PosgreSQL

        return $result_id;
    }

    /**
     * @return int
     */
    public function getZone()
    {
        return $this->zone;
    }

    /**
     * @return string
     */
    public function getSerial()
    {
        return $this->serial;
    }

    /**
     * @return Record
     */
    public function getPrior()
    {
        return $this->prior;
    }

    /**
     * @return Record
     */
    public function getAfter()
    {
        return $this->after;
    }

    /**
     * @return int|null
     */
    public function getAffectedRecordId()
    {
        return $this->affected_record_id;
    }
}
