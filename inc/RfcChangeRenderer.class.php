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

    /**
     * @param int $rowspan
     * @return string
     */
    private function get_rfc_meta($rowspan)
    {
        if ($rowspan > 0) {
            $rowspan_attr = " rowspan=" . $rowspan;
        } else {
            $rowspan_attr = "";
        }

        $s  = '<td' . $rowspan_attr . '>' . get_zone_name_from_id($this->change->getZone()) . '</td>';
        $s .= '<td' . $rowspan_attr . '>' . $this->change->getSerial() . '</td>';
        $s .= '<td' . $rowspan_attr . '>' . $this->get_action_type() . '</td>';
        return $s;
    }

    private function get_rfc_data(Record $row, $attributes = '', array $colorize = array())
    {
        $th = new TimeHelper();
        $time = $th->from_epoch($row->getChangeDate())->format($th->format);

        $attributes = $this->get_attributes($attributes, $colorize);

        $s  = '<td' . $attributes['name'] . '>'    . $row->getName()    . '</td>';
        $s .= '<td' . $attributes['type'] . '>'    . $row->getType()    . '</td>';
        $s .= '<td' . $attributes['content'] . '>' . $row->getContent() . '</td>';
        $s .= '<td' . $attributes['ttl'] . '>'     . $row->getTtl()     . '</td>';
        $s .= '<td' . $attributes['prio'] . '>'    . $row->getPrio()    . '</td>';
        $s .= '<td' . $attributes['change_date']   . '>' . $time        . '</td>';
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

    private function get_attributes($css_class_postfix, $array)
    {
        $css_class = ' class="record-edit-cell-' . $css_class_postfix . '"';

        $prefixes = array();
        $keys = array('name', 'type', 'content', 'ttl', 'prio', 'change_date');
        foreach($keys as $key) {
            if($this->exists_and_true($array, $key)) {
                $prefixes[$key] = $css_class;
            } else {
                $prefixes[$key] = '';
            }
        }
        return $prefixes;
    }

    private function exists_and_true($array, $key)
    {
        $is_array = is_array($array);
        $key_exists = array_key_exists($key, $array);
        $value_is_true = $array[$key] === true;
        return $is_array && $key_exists && $value_is_true;
    }
}
