<?php

class RfcResolver
{
    /**
     * @var PDOLayer db A connection to the database
     */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function get_html()
    {
    }

    # TODO: Fix duplication in get_own_active_rfcs / get_other_active_rfcs. There is only 1 char difference!

    public function get_own_active_rfcs($user)
    {
        $query = "
SELECT
    r.id as 'rfc', c.zone, c.serial
FROM
    rfc r
INNER JOIN
	rfc_change c ON r.id   = c.rfc
WHERE r.initiator = :user
;";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":user", $user, PDO::PARAM_STR);
        $success = $stmt->execute();

        $rfcs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        # Filter active
        $active_rfcs = array();
        foreach ($rfcs as $rfc) {
            $current_serial = get_serial_by_zid($rfc['zone']);
            if($current_serial == $rfc['serial']) { # Change based on current db state
                $active_rfcs[] = $rfc['rfc'];
            }
        }

        return count(array_unique($active_rfcs));
    }

    public function get_other_active_rfcs($user)
    {
        $query = "
SELECT
    r.id as 'rfc', c.zone, c.serial
FROM
    rfc r
INNER JOIN
	rfc_change c ON r.id   = c.rfc
WHERE r.initiator != :user
;";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":user", $user, PDO::PARAM_STR);
        $success = $stmt->execute();

        $rfcs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        # Filter active
        $active_rfcs = array();
        foreach ($rfcs as $rfc) {
            $current_serial = get_serial_by_zid($rfc['zone']);
            if($current_serial == $rfc['serial']) { # Change based on current db state
                $active_rfcs[] = $rfc['rfc'];
            }
        }

        return count(array_unique($active_rfcs));
    }
}
