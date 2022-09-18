<?php
/**
 * created by bnch
 *  
 */
 
use Poweradmin\AppFactory;
use Poweradmin\SQLlog;

require_once 'inc/toolkit.inc.php';
require_once 'inc/pagination.inc.php';
include_once 'inc/header.inc.php';

$app = AppFactory::create();
$pdnssec_use = $app->config('pdnssec_use');
$iface_zonelist_serial = $app->config('iface_zonelist_serial');
$iface_rowamount = $app->config('iface_rowamount');

/**
 * check if user is superadmin
 */
if (do_hook('verify_permission', 'user_is_ueberuser')) {}else{
    echo "<p>You do not have the permission to see any logs</p>\n";
    die();
}

/**
 * check is page is numeric and > 0
 */
$selected_page=1;
if (isset($_GET['page'])){
    is_numeric($_GET['page']) ? $selected_page=$_GET['page'] : die("Unknown page.");
    if ($selected_page < 0) die('Unknown page.');
}

/**
 * check if auth is = 1
 */
if (isset($_GET['auth'])){
    if ( $_GET['auth'] != 1 ){
        die('Unknown auth flag.');
    } 
}


$number_of_logs=0;
$logs_per_page = 50;
$number_of_page=0;
if (isset($_GET['name'])){
    /**
    * search by domain
    */

    //get number of logs for domain
    $number_of_logs =SQLlog::count_logs_by_domain($_GET['name']); 
    //count pages for domain
    $number_of_pages=round ($number_of_logs/$logs_per_page +0.5 );
    //check page
    if ($selected_page > $number_of_pages ) die('Unknown page');
    //get logs for domain
    $data = SQLlog::get_logs_for_domain($_GET['name'], $logs_per_page, ($selected_page-1)*$logs_per_page );
    
}elseif(isset($_GET['auth'])){
    /**
     * show auth log
     */

    //get number of auth logs
    $number_of_logs = SQLlog::count_auth_logs();
    //get number of pager for auth logs
    $number_of_pages=round ($number_of_logs/$logs_per_page +0.5 );
    //check page
    if ($selected_page > $number_of_pages ) die('Unknown page');
    //get auth logs
    $data = SQLlog::get_auth_logs($logs_per_page, ($selected_page-1)*$logs_per_page );

}else{
    /**
     * show all logs
     */

    //get number of all logs
    $number_of_logs = SQLlog::count_all_logs();
    //get number of pages for all logs
    $number_of_pages=round ($number_of_logs/$logs_per_page +0.5 );
    //check page
    if ($selected_page > $number_of_pages ) die('Unknown page');
    //get all logs
    $data = SQLlog::get_all_logs($logs_per_page, ($selected_page-1)*$logs_per_page );
}


echo "    <h2>List logs</h2>\n";
echo "    <div class=\"\"> Total number of logs : " . $number_of_logs ."</div><br>\n";
/**
 *Search logs by zone 
 */
echo '
        <div class="input-group-append" style="margin-right: 5px;">
            <a  href="list_logs.php"  class="btn btn-secondary btn-sm" role="button"><i class="bi bi-eraser"></i> clear</a>
            <a  href="list_logs.php?auth=1"  class="btn btn-secondary btn-sm" role="button"><i class="bi bi-person"></i> auth logs</a>
        </div>
        <form><div class="input-group mb-3" style="width: 40%; margin-top:10px;">  
        <input name="name" id="name" type="text" class="form-control form-control-sm"';
//show value in searh bar if exists
if (isset($_GET['name'])){
    echo 'value="'.$_GET['name'].'"';
}

        echo 'placeholder="Search logs by domain" aria-label="Search logs by domain" aria-describedby="basic-addon1">
        <div class="input-group-append">
            <button for="name" type="submit" class="btn btn-secondary btn-sm" type="button"><i class="bi bi-search"></i> search</button>
            
        </div>
    </div></form>';



echo "     <form method=\"post\" action=\"delete_domains.php\">\n";
    //if exists logs
    if (sizeof($data)> 0 ){
        showPages($number_of_pages, $selected_page,  isset($_GET['name']) ? $_GET['name'] : "", isset($_GET['auth']) ? $_GET['auth'] : "" );
        echo "<table class=\"table table-striped table-bordered\" style=\"margin-top : 10px;\">\n";
        echo "      <thead><tr style=\"text-align: center;\">\n";
        echo "          <th style=\"width: 3%;\"> #</th>\n";
        echo "          <th style=\"width: 82%;\">log</th>\n";
        echo "          <th style=\"width: 15%;\">created at</th>\n";
        echo "      </tr></thead>";

        $log_number=($selected_page-1)*$logs_per_page;
        foreach ($data as $row) {
            $log_number++;
            echo "  <tr onclick=\"showLog('log_".$log_number."')\" id=tr_".$log_number." style=\"cursor: pointer;\">";
            echo "       <td>".$log_number."</th>\n";
            echo "       <td ><a id=log_".$log_number." href='#!' style='color: inherit;  text-decoration: none;' 
                            >".str_replace(array("'","\\"),"",$row['log'])."</a></td>\n";
            echo "       <td>".$row['created_at']."</td>\n";
            echo "  </tr>";
                
        }
        echo "</table>\n";
        showPages($number_of_pages, $selected_page, isset($_GET['name']) ? $_GET['name'] : "", isset($_GET['auth']) ? $_GET['auth'] : "" );
    }else{
        echo 'No logs found'.(isset($_GET['name']) ? ' for '.$_GET['name']."." : "." );
    }
echo "     </form>\n";


include_once('inc/footer.inc.php');

/**
 * function for pagination
 */
function showPages($number_of_pages, $selected_page, $zone, $auth){
    echo '<div class="row"  style="padding-left: 14px;">     Page:        <select class="form-select form-select-sm" style="width: 7%; height:30px; margin-left: 5px;">';
    
    for ($i=1; $i<=$number_of_pages; $i++){
        if ($i == $selected_page ){
            echo "<option selected onclick=\"go2page('".$i."','".$zone."','".$auth."')\" >".$i."</option>";
        }else{
            echo "<option onclick=\"go2page('".$i."','".$zone."','".$auth."')\" >".$i."</option>";
        }
    }

    echo '</select></div>';
}



?>

<script>
    function go2page(page, name, auth){
        if (name != ""){
            window.location.href="?page="+page+"&name="+name;
        }else if(auth!= ""){
            window.location.href="?page="+page+"&auth="+auth;
        }else{
            window.location.href="?page="+page;
        }
    }

    function showLog(log_id){
        

        //logParser = new LOGParser(document.getElementById(log_id).innerHTML);
        logString= document.getElementById(log_id).innerHTML;
        logArraySplitBySpace = logString.split(" ");

        if ( logArraySplitBySpace[0] == "Failed" ){
            parseFailedAuthentication(log_id, logString);
            return;
        }

        if (logArraySplitBySpace[0] == "Successful"  ){
            parseAuthentication(log_id, logString);
            return;
        }
        
        html=logString+'<br>&emsp;&emsp;';

        
        for (i=0; i< logArraySplitBySpace.length; i++){
            tag = logArraySplitBySpace[i].split(":")[0];
            val = logArraySplitBySpace[i].split(":")[1];
            html+=' <b>'+tag+'</b> : '+val+'<br>&emsp;&emsp;';
        }        
        document.getElementById(log_id).innerHTML=html;
        
        document.getElementById("tr_"+log_id.split('_')[1]).setAttribute( "onClick", "javascript: hideLog('"+log_id+"');" );
        
    }

    function parseAuthentication(log_id, logString){
        html=logString+'<br>&emsp;&emsp;';

        //append ip
        html+='<b>from :</b>'+logString.split(']')[0].split('[')[1]+'<br>&emsp;&emsp;';
        html+='<b>user :</b>'+logString.split('user')[1]+'<br>&emsp;&emsp;';
        document.getElementById(log_id).innerHTML=html;
        
        document.getElementById("tr_"+log_id.split('_')[1]).setAttribute( "onClick", "javascript: hideLog('"+log_id+"');" );
    }

    function parseFailedAuthentication(log_id, logString){
        html=logString+'<br>&emsp;&emsp;';

        html+='<b>from :</b>'+logString.split(']')[0].split('[')[1]+'<br>&emsp;&emsp;';
        document.getElementById(log_id).innerHTML=html;
        
        document.getElementById("tr_"+log_id.split('_')[1]).setAttribute( "onClick", "javascript: hideLog('"+log_id+"');" );
    }

    function hideLog(log_id){
        
        logString= document.getElementById(log_id).innerHTML;
        document.getElementById(log_id).innerHTML= logString.split('<br>')[0];
        document.getElementById("tr_"+log_id.split('_')[1]).setAttribute( "onClick", "javascript: showLog('"+log_id+"');" );
       
    }


    
</script>
