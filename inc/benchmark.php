<?php

$start_memory = memory_get_usage();
$start_time = microtime(true);

function get_human_readable_usage($size) {
	$units = array('B', 'KB', 'MB', 'GB');
        $result = $size . ' B';

        if ($size < 1024) return $result;

        $index = floor(log($size, 1024));
        if ($index < sizeof($units)) {
            $result = round($size / pow(1024, ($index)), 2) . ' ' . $units[$index];
        }

	return $result;
}


function display_current_stats() {
	global $start_time, $start_memory;
	$memory_usage = get_human_readable_usage(memory_get_usage() - $start_memory);
	$elapsed_time = sprintf("%.5f", microtime(true) - $start_time);
	echo "Memory usage: " . $memory_usage . ", elapsed time: " . $elapsed_time;
}

