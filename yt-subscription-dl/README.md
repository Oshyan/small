# YT Subscription Downloader

Fetches videos from YouTube subscriptions within a date range and adds them to a Google Sheet.

## Live URL

https://oshyan.com/tools/ytupdate/yt-subscription-dl.php

## Deployment

### SSH Access

Uses the `cookindex` SSH config alias:

```bash
ssh cookindex
```

Config details (in `~/.ssh/config`):
- Host: `ssh.oshyan.com`
- Port: `18765`
- User: `u1698-inffg4l0kuxe`
- Key: `~/.ssh/cookindex_deploy`

### Remote Path

```
~/www/oshyan.com/public_html/tools/ytupdate/
```

### Deploy Command

```bash
scp yt-subscription-dl.php cookindex:~/www/oshyan.com/public_html/tools/ytupdate/
ssh cookindex "chmod 644 ~/www/oshyan.com/public_html/tools/ytupdate/yt-subscription-dl.php"
```

## Architecture (v2.0)

Uses AJAX-based processing for reliable progress updates:

1. Form submits, initializes state, redirects to progress page
2. Progress page polls `?action=process` endpoint via JavaScript
3. Each poll processes 5 channels, returns JSON progress
4. JavaScript updates UI and automatically triggers next batch
5. Continues until all channels are processed

No more timeout issues or manual re-runs required.
