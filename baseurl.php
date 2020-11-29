<?php
function getBaseUrl() {
  $host = getenv('HTTP_HOST');
  $useTest = false;
  if($useTest) {
    return "https://remotefalcon.me";
  }else {
    return "https://remotefalcon.com";
  }
}
?>