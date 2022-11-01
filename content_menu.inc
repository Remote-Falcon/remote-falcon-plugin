<?php
$pages = Array(
	'name' => "Remote Falcon",
	'type' => "content",
	'page' => "index.js");
?>

<li><a href="<?php echo preg_replace('/.*\/plugins\/(.*)/', 'plugin.php?plugin=$1&page=index.js', dirname(__FILE__)); ?>">Remote Falcon</a></li>