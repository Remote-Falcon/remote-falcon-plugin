<?php
function getBaseUrl() {
  $host = getenv('HTTP_HOST');
  if($host == "localhost:8090") {
    return "https://remotefalcon.me";
  }else {
    return "https://remotefalcon.com";
  }
}
?>