<?php
$name= $_GET['name'];
header('Content-Description: File Transfer');
header('Content-Type: application/force-download');
header("Content-Disposition: attachment; filename=\"" . basename($name) . "\";");
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($name));
ob_clean();
flush();
readfile("/home/fpp/media/plugins/remote-falcon/logs/".$name);
exit;
?>