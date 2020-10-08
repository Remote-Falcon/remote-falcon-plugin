<?php
$file = basename($_GET['file']);
$file = '/home/fpp/media/plugins/remote-falcon/logs/' . $file . '.txt';
echo $file;

if(!file_exists($file)){
    die('file not found');
} else {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename=' . $file);
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file));
    readfile($file);
}
?>