<?php
$pages = Array(
	'name' => "Remote Falcon",
	'type' => "content",
	'page' => "remote_falcon.php");
?>

<li><a href="<?php echo preg_replace('/.*\/plugins\/(.*)/', 'plugin.php?plugin=$1&page=remote_falcon.php', dirname(__FILE__)); ?>">Remote Falcon</a></li>