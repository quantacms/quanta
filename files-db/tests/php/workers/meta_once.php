<?php
/** Worker: print one node's path + father as JSON. Usage: meta_once.php <node> */
$m = QuantaDb::meta($argv[1]);
echo json_encode($m === null ? null : ['path' => $m['path'], 'father' => $m['father']]);
