<?php
// Help page for the Remote Falcon FPP plugin.
?>
<div class="row">
  <div class="col-12">
    <h2>Remote Falcon Plugin Help</h2>

    <h3>What does this plugin do?</h3>
    <p>
      The Remote Falcon plugin lets viewers control your light show in real time
      from <a href="https://remotefalcon.com" target="_blank">remotefalcon.com</a>.
      The plugin polls FPP for the currently playing sequence, fetches the next
      requested or voted sequence from Remote Falcon, and inserts it into the
      FPP playlist.
    </p>

    <h3>Setup</h3>
    <ol>
      <li>Create a Remote Falcon account at <a href="https://remotefalcon.com" target="_blank">remotefalcon.com</a> and copy your Show Token from the Account page.</li>
      <li>In the plugin's <em>Remote Falcon</em> page, paste your Show Token.</li>
      <li>Create a dedicated playlist in FPP that contains every sequence you want viewers to be able to request or vote for.</li>
      <li>Select that playlist as the <em>Remote Playlist</em> and click <em>Sync with RF</em>.</li>
      <li>Decide whether to enable <em>Interrupt Schedule</em> based on how you want viewer requests to behave during scheduled shows.</li>
    </ol>

    <h3>Common issues</h3>
    <ul>
      <li><strong>Listener not running</strong> &mdash; use the <em>Restart Listener</em> button on the Remote Falcon page.</li>
      <li><strong>Requests not playing</strong> &mdash; make sure the Remote Playlist has no lead-in or lead-out items and is not part of any FPP schedule. The <em>Run Checks</em> button reports both.</li>
      <li><strong>API unreachable</strong> &mdash; use <em>Test Connectivity</em> to verify FPP can reach the Remote Falcon API.</li>
    </ul>

    <h3>Links</h3>
    <ul>
      <li><a href="https://remotefalcon.com" target="_blank">Remote Falcon Control Panel</a></li>
      <li><a href="https://github.com/Remote-Falcon/remote-falcon-plugin" target="_blank">Plugin source on GitHub</a></li>
      <li><a href="https://github.com/Remote-Falcon/remote-falcon-issue-tracker/issues" target="_blank">Report a bug</a></li>
    </ul>
  </div>
</div>
