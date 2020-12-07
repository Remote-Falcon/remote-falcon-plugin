<?php
function getBaseUrl() {
  $host = gethostname();
  if($host == "fpplocal") {
    return "https://remotefalcon.me";
  }
  return "https://remotefalcon.com";
}
?>