<?php

/** Print paging menu
 *
 * Display the page option: [ < ][ 1 ] .. [ 8 ][ 9 ][ 10 ][ 11 ][ 12 ][ 13 ][ 14 ][ 15 ][ 16 ] .. [ 34 ][ > ]
 *
 * @param int $amount Total number of items
 * @param int $rowamount Per page number of items
 * @param int $id Page specific ID (Zone ID, Template ID, etc)
 *
 * @return null
 */
function show_pages($amount, $rowamount, $id = '') {
    if ($amount > $rowamount) {
        $num = 8;
        $poutput = '';
        $lastpage = ceil($amount / $rowamount);
        $startpage = 1;

        if (!isset($_GET["start"]))
            $_GET["start"] = 1;
        $start = $_GET["start"];

        if ($lastpage > $num & $start > ($num / 2)) {
            $startpage = ($start - ($num / 2));
        }

        echo _('Show page') . ":<br>";

        if ($lastpage > $num & $start > 1) {
            $poutput .= '<a href=" ' . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES);
            $poutput .= '?start=' . ($start - 1);
            if ($id != '')
                $poutput .= '&id=' . $id;
            $poutput .= '">';
            $poutput .= '[ < ]';
            $poutput .= '</a>';
        }
        if ($start != 1) {
            $poutput .= '<a href=" ' . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES);
            $poutput .= '?start=1';
            if ($id != '')
                $poutput .= '&id=' . $id;
            $poutput .= '">';
            $poutput .= '[ 1 ]';
            $poutput .= '</a>';
            if ($startpage > 2)
                $poutput .= ' .. ';
        }

        for ($i = $startpage; $i <= min(($startpage + $num), $lastpage); $i++) {
            if ($start == $i) {
                $poutput .= '[ <b>' . $i . '</b> ]';
            } elseif ($i != $lastpage & $i != 1) {
                $poutput .= '<a href=" ' . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES);
                $poutput .= '?start=' . $i;
                if ($id != '')
                    $poutput .= '&id=' . $id;
                $poutput .= '">';
                $poutput .= '[ ' . $i . ' ]';
                $poutput .= '</a>';
            }
        }

        if ($start != $lastpage) {
            if (min(($startpage + $num), $lastpage) < ($lastpage - 1))
                $poutput .= ' .. ';
            $poutput .= '<a href=" ' . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES);
            $poutput .= '?start=' . $lastpage;
            if ($id != '')
                $poutput .= '&id=' . $id;
            $poutput .= '">';
            $poutput .= '[ ' . $lastpage . ' ]';
            $poutput .= '</a>';
        }

        if ($lastpage > $num & $start < $lastpage) {
            $poutput .= '<a href=" ' . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES);
            $poutput .= '?start=' . ($start + 1);
            if ($id != '')
                $poutput .= '&id=' . $id;
            $poutput .= '">';
            $poutput .= '[ > ]';
            $poutput .= '</a>';
        }

        echo $poutput;
    }
}
