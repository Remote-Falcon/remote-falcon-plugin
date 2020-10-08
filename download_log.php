<?php
if (isset($argc)) {
    $fullFile = '/home/fpp/media/plugins/remote-falcon/logs/' . $argv[1] . '.txt';
    echo $fullFile;

    if(!file_exists($fullFile)){
        die('file not found');
    } else {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=' . $fullFile);
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($fullFile));
        readfile($fullFile);
    }
}else {
    echo "Args not set";
}
?>