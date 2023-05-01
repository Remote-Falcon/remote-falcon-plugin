<?
include_once "/opt/fpp/www/common.php";

function getEndpointsremotefalcon() {
  $rfLogEndpoint = array(
    'method' => "PUT",
    'endpoint' => "log",
    'callback' => "putLog"
  );
  
  $endpoints = array (
    $rfLogEndpoint
  );

  return $endpoints;
}

function putLog() {
  $logFile = "/home/fpp/media/logs/remote_falcon_listener.log";

  $data = file_get_contents('php://input');
	
	$logWrite = fopen($logFile, "a") or die("Unable to open file!");
	fwrite($logWrite, date('Y-m-d h:i:s A',time()).": ".$data."\n");
	fclose($logWrite);
}

?>