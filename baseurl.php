<?php
function getBaseUrl() {
  $host = gethostname();
  if($host == "fpplocal") {
    return "http://host.docker.internal:8080/remotefalcon/api";
  }
  return "https://remotefalcon.com/remotefalcon/api";
}
?>