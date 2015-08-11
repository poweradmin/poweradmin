<?php

class RecordBuilder {
    public static function make($id, $domain_id, $name, $type, $content, $prio, $ttl, $change_date)
    {
        $args = array(
            'id'          => $id,
            'domain_id'   => $domain_id,
            'name'        => $name,
            'type'        => $type,
            'content'     => $content,
            'prio'        => $prio,
            'ttl'         => $ttl,
            'change_date' => $change_date
        );
        return new Record($args);
    }
}

class Record
{
    private $id;
    private $domain_id;
    private $name;
    private $type;
    private $content;
    private $prio;
    private $ttl;
    private $change_date;

    /**
     * Record constructor.
     * @param array $record
     */
    public function __construct($record)
    {
        # Some fields have different names sometimes
        if (isset($record['rid'])) {
            $record['id'] = $record['rid'];
            unset($record['rid']);
        }
        if (isset($record['zid'])) {
            $record['domain_id'] = $record['zid'];
            unset($record['zid']);
        }

        // Append zone name to record if it is not
        $zone = get_zone_name_from_id($record['domain_id']);
        if (!(preg_match("/$zone$/i", $record['name']))) {
            if (isset($record) && $record['name'] != "") {
                $record['name'] = $record['name'] . "." . $zone;
            } else {
                $record['name'] = $zone;
            }
        }

        # Remove invalid fields
        $valid_fields = array('id', 'domain_id', 'name', 'type', 'content', 'ttl', 'prio', 'change_date');
        foreach ($record as $field) {
            if (!array_key_exists($field, $valid_fields)) {
                log_notice($field . " is not a valid record field.");
            }
        }

        $this->id = $record['id'];
        $this->domain_id = $record['domain_id'];
        $this->name = $record['name'];
        $this->type = $record['type'];
        $this->content = $record['content'];
        $this->prio = $record['prio'];
        $this->ttl = $record['ttl'];
        $this->change_date = $record['change_date'];
    }

    ###########################################################################
    # GETTERS AND SETTERS

    /**
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return integer
     */
    public function getZone()
    {
        return $this->getDomainId();
    }

    /**
     * @return integer
     */
    public function getDomainId()
    {
        return $this->domain_id;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * @return integer
     */
    public function getPrio()
    {
        return $this->prio;
    }

    /**
     * @return integer
     */
    public function getTtl()
    {
        return $this->ttl;
    }

    /**
     * @return string
     */
    public function getChangeDate()
    {
        return $this->change_date;
    }

    /**
     * @param mixed $change_date
     */
    public function setChangeDate($change_date)
    {
        $this->change_date = $change_date;
    }

    public function as_array()
    {
        return array(
            'id'          => $this->getId(),
            'domain_id'   => $this->getDomainId(),
            'name'        => $this->getName(),
            'type'        => $this->getType(),
            'content'     => $this->getContent(),
            'prio'        => $this->getPrio(),
            'ttl'         => $this->getTtl(),
            'change_date' => $this->getChangeDate(),
        );
    }
}
