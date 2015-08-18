<?php

require_once('Rfc.class.php');
require_once('Record.class.php');
require_once('RfcChangeRenderer.class.php');

class RFCRender
{
    /**
     * @param Rfc[] $rfcs
     */
    public function __construct($rfcs)
    {
        $this->rfcs = $rfcs;
    }

    public function get_html()
    {
        $s = '';
        foreach ($this->rfcs as $rfc) {
            $action = $this->get_action($rfc->getId());
            $method = $this->get_method();

            $style = '';
            $user = PoweradminUtil::get_username();
            if($rfc->getExpired()) {
                $style = 'background-color: grey';

                if($user !== $rfc->getInitiator()) {
                    continue;
                }
            }

            $s .= sprintf('<form action="%s" method="%s" style="%s">', $action, $method, $style);
            $s .= $this->get_rfc_html($rfc);
            $s .= '<hr>';
        }
        return $s;
    }

    /**
     * @param Rfc $rfc The RFC to render
     * @return string
     */
    private function get_rfc_html(Rfc $rfc)
    {
        $s = '<fieldset>';
        $s .= '    <legend>RFC Meta</legend>';
        $s .= '    <label for="rfc_id" style="display: block">ID';
        $s .= '        <input readonly id="rfc_id" name="rfc_id" value="' . $rfc->getId() . '" />';
        $s .= '    </label>';
        $s .= '    <label for="rfc_timestamp" style="display: block">Timestamp';
        $s .= '        <input readonly id="rfc_timestamp" name="rfc_timestamp" value="' . $rfc->getTimestamp() . '" />';
        $s .= '    </label>';
        $s .= '    <label for="rfc_initiator" style="display: block">Initiator';
        $s .= '        <input readonly id="rfc_initiator" name="rfc_initiator" value="' . $rfc->getInitiator() . '" />';
        $s .= '    </label>';
        $s .= '</fieldset>';

        $s .= '<fieldset>';
        $s .= '    <legend>RFC Data</legend>';
        $s .= '    <table>';
        $s .= '        <thead>';
        $s .= '            <tr>';
        $s .= '                <th>Zone</th>';
        $s .= '                <th>Serial</th>';
        $s .= '                <th>Change type</th>';
        $s .= '                <th>Name</th>';
        $s .= '                <th>Type</th>';
        $s .= '                <th>Content</th>';
        $s .= '                <th>TTL</th>';
        $s .= '                <th>Prio</th>';
        $s .= '                <th>Change date</th>';
        $s .= '            </tr>';
        $s .= '        </thead>';

        $s .= '        <tbody>';
        $changes = $rfc->getChanges();
        foreach ($changes as $change) {
            $renderer = new RfcChangeRenderer($change);
            $s .= $renderer->get_change_html();
        }

        $s .= '</tbody>';
        $s .= '</table>';
        $s .= '</fieldset>';

        # Show button to submit RFC only if it is not my own.
        if($rfc->getInitiator() !== PoweradminUtil::get_username()) {
            $s .= '  <input type="submit" value="' . sprintf(_('Accept RFC as %s'), PoweradminUtil::get_username()) . '" />';
            $s .= '</form>';
        } else {
            $s .= '</form>';
            $s .= '<form action="delete_rfc.php" method="POST">';
            $s .= '   <input type="hidden" name="id" value="' . $rfc->getId() . '"/>';
            $s .= '   <input type="submit" value="' . _('Delete my RFC') . '"/>';
            $s .= '</form>';
        }
        $s .= '</br></br>';

        return $s;
    }

    private function get_action($id)
    {
        return "accept_rfc.php?id=" . $id;
    }

    private function get_method()
    {
        return "POST";
    }
}
