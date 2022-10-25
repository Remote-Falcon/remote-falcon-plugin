<?php
function getBaseUrl() {
  $host = gethostname();
  if($host == "fpplocal") {
    return "http://host.docker.internal:8080/remotefalcon/api";
  }else if($host == "FPP-TEST") {
    return "http://192.168.68.78:8080/remotefalcon/api";
  }
  return "https://remotefalcon.com/remotefalcon/api";
}

function getBaseUrlDomain() {
  $host = gethostname();
  if($host == "fpplocal") {
    return "http://host.docker.internal:8080/";
  }else if($host == "FPP-TEST") {
    return "http://192.168.68.78:8080";
  }
  return "https://remotefalcon.com";
}
?>