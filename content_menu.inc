<?php
$pages = Array(
	'name' => "Remote Falcon",
	'type' => "content",
	'page' => "pluginPage.html");
?>

<li><a href="<?php echo preg_replace('/.*\/plugins\/(.*)/', 'plugin.php?plugin=$1&page=pluginPage.html', dirname(__FILE__)); ?>">Remote Falcon</a></li>