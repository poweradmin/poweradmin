<?php

require_once('Rfc.class.php');
require_once('Record.class.php');

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
            $action = "….php";
            $method = "GET|POST";

            $s .= sprintf('<form action="%s" method="%s">', $action, $method);
            $s .= $this->get_rfc_html($rfc);
            $s .= '</form>';
            $s .= '</br>';
        }
    }

    /**
     * @param Rfc $rfc The RFC to render
     * @return string
     */
    private function get_rfc_html(Rfc $rfc)
    {
        return '';
    }
}
