<?php
// Emergency scan stopper
$source_id = 22;
$status_file = sys_get_temp_dir() . "/scan_missing_{$source_id}.json";

$data = [
    'running' => false,
    'finished' => true,
    'stopped' => true,
    'phase' => 'Scan force stopped'
];

file_put_contents($status_file, json_encode($data));
echo "Scan stopped. Status file updated: $status_file\n";
echo json_encode($data, JSON_PRETTY_PRINT);
