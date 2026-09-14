<?php
/** Worker: print one document as JSON. Usage: get_once.php <node> */
echo json_encode(QuantaDb::get($argv[1]));
