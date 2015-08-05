<?php

require_once('TimeHelper.class.php');
require_once('Record.class.php');
require_once('RfcChange.class.php');
require_once('util/PoweradminUtil.class.php');

class RfcBuilder
{
    private $instance;

    private function __construct()
    {
        $this->instance = new Rfc();
    }

    public static function makeEmptyRfc()
    {
        return new RfcBuilder();
    }

    public function timestamp($timestamp)
    {
        $this->instance->setTimestamp($timestamp);
        return $this;
    }

    public function now()
    {
        $th = new TimeHelper();
        $this->instance->setTimestamp($th->now()->format($th->format));
        return $this;
    }

    public function myself()
    {
        $this->instance->setInitiator(PoweradminUtil::get_username());
        return $this;
    }

    public function initiator($initiator)
    {
        $this->instance->setInitiator($initiator);
        return $this;
    }

    public function build()
    {
        return $this->instance;
    }
}

/**
 * A RFC is a list of record inserts/updates/deletes in one zone.
 */
class Rfc
{
    /**
     * @var RfcChange[] A list of changes.
     */
    private $changes;

    private $timestamp;
    private $initiator;

    /**
     * **Use it only if you know what you are doing**. Use {@link RfcBuilder} instead.
     */
    public function __construct()
    {
        $this->changes = array();
    }

    /**
     * Adds a change to the RFC.
     * @param int $zone The zone id
     * @param string $serial The serial of zone this
     * @param Record $before The record before the change.
     * @param Record $after The record after the change.
     */
    public function add_change($zone, $serial, $before, $after)
    {
        $this->changes[] = new RfcChange($zone, $serial, $before, $after);
    }

    /**
     * @param PDOLayer $db A connection to the database.
     * @return bool True, if a RFC was created. False otherwise.
     */
    public function write($db)
    {
        if (count($this->changes) === 0) {
            return false;
        }

        $th = new TimeHelper();

        $timestamp = $th->now()->format($th->format);
        $initiator = PoweradminUtil::get_username();

        # Write RFC
        $query = "INSERT INTO rfc (timestamp, initiator) VALUES ("
            . $db->quote($timestamp, 'text') . ","
            . $db->quote($initiator, 'text') . ")";
        $db->exec($query);
        $rfc_id = $db->lastInsertId(); // TODO: Fix PosgreSQL

        # Write RFC-Data
        foreach ($this->changes as $change) {
            $change->setRfcId($rfc_id);
            $rfc_change_id = $change->write($db);
        }

        return true;
    }

    ###########################################################################
    # GETTERS AND SETTERS #
    #######################

    /**
     * @return RfcChange[]
     */
    public function getChanges()
    {
        return $this->changes;
    }

    /**
     * @param RfcChange[] $changes
     */
    public function setChanges($changes)
    {
        $this->changes = $changes;
    }

    /**
     * @return string
     */
    public function getTimestamp()
    {
        return $this->timestamp;
    }

    /**
     * @param string $timestamp
     */
    public function setTimestamp($timestamp)
    {
        $this->timestamp = $timestamp;
    }

    /**
     * @return string
     */
    public function getInitiator()
    {
        return $this->initiator;
    }

    /**
     * @param string $initiator
     */
    public function setInitiator($initiator)
    {
        $this->initiator = $initiator;
    }
}
