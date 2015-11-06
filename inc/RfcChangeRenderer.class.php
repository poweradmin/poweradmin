<?php

class ChangeAction
{
    const RECORD_EDIT = 'record_edit';
    const RECORD_DELETE = 'record_delete';
    const RECORD_INSERT = 'record_insert';
    const ZONE_DELETE = 'zone_delete';
}

class RfcChangeRenderer
{
    private $change;

    /**
     * RfcChangeRenderer constructor.
     * @param RfcChange $change
     */
    public function __construct($change)
    {
        $this->change = $change;
    }

    private function get_action_type()
    {
        $prior = $this->change->getPrior();
        $after = $this->change->getAfter();
        if ($prior && $after) {
            return ChangeAction::RECORD_EDIT;
        } elseif ($prior) {
            return ChangeAction::RECORD_DELETE;
        } elseif ($after) {
            return ChangeAction::RECORD_INSERT;
        } else {
            return ChangeAction::ZONE_DELETE;
        }
    }

    public function get_change_html()
    {
        $s = '';
        switch ($this->get_action_type()) {
            case ChangeAction::RECORD_INSERT:
                $s .= $this->get_insert_row($this->change->getAfter());
                break;
            case ChangeAction::RECORD_DELETE:
                $s .= $this->get_delete_row($this->change->getPrior());
                break;
            case ChangeAction::RECORD_EDIT:
                $s .= $this->get_edit_row($this->change->getPrior(), $this->change->getAfter());
                break;
            case ChangeAction::ZONE_DELETE:
                $s .= '<tr class="domain-delete">';
                $s .= '    <td>' . get_zone_name_from_id($this->change->getZone()) . '</td>';
                $s .= '    <td>' . $this->change->getSerial() . '</td>';
                $s .= '    <td colspan="7">' . 'zone_delete' . '</td>';
                $s .= '</tr>';
                break;
        }
        return $s;
    }

    private function get_rfc_meta($rowspan)
    {
        $rowspan_attr = $rowspan > 0 ? " rowspan=" . $rowspan : "";
        $s = '<td ' . $rowspan_attr . '>' . get_zone_name_from_id($this->change->getZone()) . '</td>';
        $s .= '<td ' . $rowspan_attr . '>' . $this->change->getSerial() . '</td>';
        $s .= '<td ' . $rowspan_attr . '>' . $this->get_action_type() . '</td>';
        return $s;
    }

    private function get_rfc_data(Record $row, $prefix = '', array $colorize = array())
    {
        $text = ' class="record-edit-cell-' . $prefix . '"';

        $prefix = array();
        $prefix['name'] = (array_key_exists('name', $colorize) ? $text : '');
        $prefix['type'] = (array_key_exists('type', $colorize) ? $text : '');
        $prefix['content'] = (array_key_exists('content', $colorize) ? $text : '');
        $prefix['ttl'] = (array_key_exists('ttl', $colorize) ? $text : '');
        $prefix['prio'] = (array_key_exists('prio', $colorize) ? $text : '');
        $prefix['change_date'] = (array_key_exists('change_date', $colorize) ? $text : '');

        $s = '<td' . $prefix['name'] . '>' . $row->getName() . '</td>';
        $s .= '<td' . $prefix['type'] . '>' . $row->getType() . '</td>';
        $s .= '<td' . $prefix['content'] . '>' . $row->getContent() . '</td>';
        $s .= '<td' . $prefix['ttl'] . '>' . $row->getTtl() . '</td>';
        $s .= '<td' . $prefix['prio'] . '>' . $row->getPrio() . '</td>';
        $s .= '<td' . $prefix['change_date'] . '>' . ($row->getChangeDate() ? $row->getChangeDate() : 0) . '</td>';
        return $s;
    }

    private function get_insert_row($after)
    {
        $class = "record-create";
        $rowspan = 1;

        $s = '<tr class="' . $class . '">';
        $s .= $this->get_rfc_meta($rowspan);
        $s .= $this->get_rfc_data($after, 'after');
        $s .= '</tr>';
        return $s;
    }

    private function get_delete_row($prior)
    {
        $class = "record-delete";
        $rowspan = 1;

        $s = '<tr class="' . $class . '">';
        $s .= $this->get_rfc_meta($rowspan);
        $s .= $this->get_rfc_data($prior, 'prior');
        $s .= '</tr>';
        return $s;
    }

    private function get_edit_row(Record $prior, Record $after)
    {
        $class = "record-edit";
        $rowspan = 2;

        $s = '<tr class="' . $class . '">';
        $s .= $this->get_rfc_meta($rowspan);
        $s .= $this->get_rfc_data($prior, 'prior', self::get_changed_fields($prior, $after));
        $s .= '</tr>';

        $s .= '<tr class="' . $class . '">';
        $s .= $this->get_rfc_data($after, 'after', self::get_changed_fields($prior, $after));
        $s .= '</tr>';
        return $s;
    }

    private static function get_changed_fields(Record $prior, Record $after)
    {
        $a = array_fill_keys(array('name', 'type', 'content', 'ttl', 'prio', 'change_date'), false);
        if($prior->getName() !== $after->getName()) $a['name'] = true;
        if($prior->getType() !== $after->getType()) $a['type'] = true;
        if($prior->getContent() !== $after->getContent()) $a['content'] = true;
        if($prior->getTtl() !== $after->getTtl()) $a['ttl'] = true;
        if($prior->getPrio() !== $after->getPrio()) $a['prio'] = true;
        if($prior->getChangeDate() !== $after->getChangeDate()) $a['change_date'] = true;
        return $a;
    }
}
