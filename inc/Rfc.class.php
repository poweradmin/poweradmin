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

    public static function make()
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

    /**
     * Creates a new Rfc with an empty list of changes.
     * @param string $serial
     */
    public function __construct($serial)
    {
        $this->serial = $serial;
        $this->elements = array();
    }

    /**
     * Adds a change to the RFC.
     * @param array $prior The record before the change.
     * @param array $after The record after the change.
     */
    public function add($prior, $after)
    {
        $prior = new Record($prior);
        $after = new Record($after);

        $zone = $prior->getZone();
        $serial = get_serial_by_zid($zone);

        $this->changes[] = new RfcChange($zone, $serial, $prior, $after);
    }

    private function getUser()
    {
        // TODO: Move to utility class
        return $_SESSION['userlogin'];
    }

    /**
     * @param PDOLayer $db A connection to the database.
     * @return bool True, if a RFC was created. False otherwise.
     */
    public function create($db)
    {
        if(count($this->changes) === 0) { return false; }

        $th = new TimeHelper();

        $timestamp = $th->now()->format($th->format);
        $initiator = $this->getUser();

        # Metadata
        $query = "INSERT INTO rfc (timestamp, initiator) VALUES ("
            . $db->quote($timestamp, 'text') . ","
            . $db->quote($initiator, 'text') . ")";
        $db->exec($query);

        #

        return true;
    }
}
