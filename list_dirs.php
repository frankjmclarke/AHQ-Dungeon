<?php
header('Content-Type: application/json');
$subdirs = array();
foreach (scandir('.') as $item) {
    if ($item === '.' || $item === '..') continue;
    if (is_dir($item)) {
        $subdirs[] = $item;
    }
}
echo json_encode($subdirs);
?>
