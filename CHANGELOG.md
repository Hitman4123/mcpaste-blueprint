# Changelog

Versions follow `info.version` in `extension/conf.yml`. A tag `v<version>`
publishes a release, with this file's section for that version as its notes.

## [1.0.0] - Unreleased

First version.

- **Share log** button next to Start / Restart / Stop in every server console.
  It sends up to the last 1,000 console lines to MCPaste, through the panel.
- Server details block on every paste: name, node, state, CPU / RAM, disk,
  Docker image and egg. The owner's email is added only if an admin turns it
  on.
- "Hide IP addresses" option in the share dialog, on by default.
- **Admin → Extensions → MCPaste**: `MCPASTE_DOMAIN` (any MCPaste site,
  default `https://mcpaste.com`) and `MCPASTE_TOKEN`. The token is stored
  encrypted with the panel's `APP_KEY` and never sent to browsers. Save
  checks the key with MCPaste.
- Shares need console access to the server (`websocket.connect`). They're
  refused for suspended or installing servers (except for admins) and limited
  to 5 a minute per user.
