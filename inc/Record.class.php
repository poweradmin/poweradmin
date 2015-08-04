<?php

class Record
{
    private $id;
    private $domain_id;
    private $name;
    private $type;
    private $content;
    private $ttl;
    private $prio;
    private $change_date;

    /**
     * Record constructor.
     * @param array $record
     */
    public function __construct($record)
    {
        # Some fields have different names sometimes
        if(isset($record['rid'])) { $record['id'] = $record['rid']; }
        if(isset($record['zid'])) { $record['domain_id'] = $record['zid']; }

        # Remove invalid fields
        $valid_fields = array('id', 'domain_id', 'name', 'type', 'content', 'ttl', 'prio', 'change_date');
        foreach($record as $field) {
            if(!in_array($field, $valid_fields, true)) {
                log_notice($field . " is not a valid record field.");
            }
        }

        $this->id = $record['id'];
        $this->domain_id = $record['domain_id'];
        $this->name = $record['name'];
        $this->type = $record['type'];
        $this->content = $record['content'];
        $this->ttl = $record['ttl'];
        $this->prio = $record['prio'];
        $this->change_date = $record['change_date'];
    }

    /**
     * @return integer
     */
    public function getId() {
        return $this->id;
    }

    /**
     * @return integer
     */
    public function getZone() {
        return $this->getDomainId();
    }

    /**
     * @return integer
     */
    public function getDomainId() {
        return $this->domain_id;
    }

    /**
     * @return string
     */
    public function getName() {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getType() {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getContent() {
        return $this->content;
    }

    /**
     * @return integer
     */
    public function getTtl() {
        return $this->ttl;
    }

    /**
     * @return integer
     */
    public function getPrio() {
        return $this->prio;
    }

    /**
     * @return string
     */
    public function getChangeDate() {
        return $this->change_date;
    }


}
