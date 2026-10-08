// Listener Status panel text. Pure functions with no DOM or jQuery, so they
// run in Node for tests (tests/js/listener_status.test.js). Loaded before
// remote_falcon_ui.js, which renders the lines into #listenerStatus.

function formatAgo(seconds) {
  if (seconds == null || seconds < 0) {
    return '';
  }
  if (seconds < 60) {
    return seconds + 's ago';
  }
  if (seconds < 3600) {
    return Math.floor(seconds / 60) + ' min ago';
  }
  if (seconds < 86400) {
    return Math.floor(seconds / 3600) + ' h ago';
  }
  return Math.floor(seconds / 86400) + ' days ago';
}

function capitalize(text) {
  return text ? text.charAt(0).toUpperCase() + text.slice(1) : text;
}

// Builds the status lines from the listener's status file. Pure: takes the
// status.php response and returns [{text, level}] with level 'ok', 'warn' or
// '' so it can be read without a browser.
function buildListenerStatusLines(response, hasToken, listenerEnabled) {
  if (!listenerEnabled) {
    return [{ text: 'The listener is stopped. Use Restart Listener to start it.', level: 'warn' }];
  }
  if (!hasToken) {
    return [{ text: 'Add your Show Token to connect to Remote Falcon.', level: 'warn' }];
  }
  if (!response || response.ok !== true || !response.status) {
    return [{ text: 'No status yet. The listener writes it shortly after it starts. If this stays, use Restart Listener.', level: '' }];
  }

  const s = response.status;
  const now = response.now;
  const ago = (at) => (at ? formatAgo(now - at) : '');
  const lines = [];

  if (s.updatedAt && now - s.updatedAt > 90) {
    lines.push({ text: 'The listener has not updated its status for ' + formatAgo(now - s.updatedAt).replace(' ago', '') + '. It may have stopped. Try Restart Listener.', level: 'warn' });
  }

  const mode = capitalize(s.viewerControlMode || 'jukebox');
  if (s.modeConfirmed) {
    lines.push({ text: 'Viewer control mode: ' + mode + ' (checked with Remote Falcon ' + ago(s.modeCheckedAt) + ')', level: 'ok' });
  } else if (s.modeError) {
    const attempts = s.modeError.failures === 1 ? '1 attempt' : s.modeError.failures + ' attempts';
    lines.push({ text: 'Viewer control mode: could not be read from Remote Falcon (' + attempts + '). Using ' + mode + ' until it can, retrying every 30 seconds.', level: 'warn' });
  } else {
    lines.push({ text: 'Viewer control mode: checking with Remote Falcon...', level: '' });
  }

  if (s.lastFetch) {
    const label = s.lastFetch.kind === 'vote' ? 'Last vote check' : 'Last request check';
    if (s.lastFetch.error) {
      lines.push({ text: label + ' (' + ago(s.lastFetch.at) + '): could not reach Remote Falcon.', level: 'warn' });
    } else if (s.lastFetch.sequence) {
      lines.push({ text: label + ' (' + ago(s.lastFetch.at) + '): got "' + s.lastFetch.sequence + '".', level: '' });
    } else {
      const none = s.lastFetch.kind === 'vote' ? 'no votes waiting.' : 'no requests waiting.';
      lines.push({ text: label + ' (' + ago(s.lastFetch.at) + '): ' + none, level: '' });
    }
  } else {
    lines.push({ text: 'No request or vote check yet. The listener checks near the end of each song while a playlist is playing.', level: '' });
  }

  if (s.lastInsert) {
    if (s.lastInsert.ok) {
      lines.push({ text: 'Last song queued in FPP: "' + s.lastInsert.sequence + '" (' + ago(s.lastInsert.at) + ').', level: '' });
    } else {
      lines.push({ text: 'FPP did not accept the last song: "' + s.lastInsert.sequence + '" (' + ago(s.lastInsert.at) + '). Check that your remote playlist is synced.', level: 'warn' });
    }
  }

  if (s.remotePlaylistWarning) {
    lines.push({ text: s.remotePlaylistWarning.replace(/^WARNING - /, ''), level: 'warn' });
  }

  if (s.lastHeartbeat) {
    if (s.lastHeartbeat.ok) {
      lines.push({ text: 'Heartbeat to Remote Falcon: sent ' + ago(s.lastHeartbeat.at) + '.', level: '' });
    } else {
      lines.push({ text: 'Heartbeat to Remote Falcon failed ' + ago(s.lastHeartbeat.at) + '. Use Test Connectivity to check the connection.', level: 'warn' });
    }
  }

  return lines;
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = { formatAgo, capitalize, buildListenerStatusLines };
}
