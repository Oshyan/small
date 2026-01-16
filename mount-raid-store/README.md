# mount-raid-store

A macOS LaunchAgent that automatically mounts an SMB share (`RAID Store`) and keeps it connected. Prefers LAN connection (Bonjour/`.local`), falls back to Tailscale.

## Features

- Auto-mounts at login and reconnects if disconnected
- Prefers local network, falls back to Tailscale VPN
- Fixes suffixed mount points (`RAID Store-1`, `-2`) automatically
- Logs actions for troubleshooting

## Installation

1. Copy the script to your local bin:
   ```bash
   cp mount_raid_store.sh ~/.local/bin/
   chmod +x ~/.local/bin/mount_raid_store.sh
   ```

2. Edit the script to match your server/share names:
   ```bash
   SHARE_NAME="RAID Store"
   HOST_LOCAL1="Mac-Server.local"
   TS_DEVICE_NAME="mac-server"
   LAST_KNOWN_TS_IP="100.76.199.85"
   ```

3. Copy and load the LaunchAgent:
   ```bash
   cp com.oshyan.mount-raidstore.plist ~/Library/LaunchAgents/
   launchctl load ~/Library/LaunchAgents/com.oshyan.mount-raidstore.plist
   ```

## Configuration

### Script (`mount_raid_store.sh`)

| Variable | Description |
|----------|-------------|
| `SHARE_NAME` | SMB share name |
| `MOUNT_POINT` | Where to mount (default: `/Volumes/RAID Store`) |
| `HOST_LOCAL1` | Primary Bonjour hostname |
| `HOST_LOCAL2` | Secondary Bonjour hostname |
| `TS_DEVICE_NAME` | Tailscale device name |
| `LAST_KNOWN_TS_IP` | Fallback Tailscale IP |

### LaunchAgent (`com.oshyan.mount-raidstore.plist`)

| Key | Value | Description |
|-----|-------|-------------|
| `RunAtLoad` | true | Run when user logs in |
| `StartInterval` | 60 | Check every 60 seconds |
| `ThrottleInterval` | 30 | Minimum 30s between runs |

## Logging

Logs are written to:
- **stdout**: `~/Library/Logs/mount_raidstore.out`
- **stderr**: `~/Library/Logs/mount_raidstore.err`

Watch logs in real-time:
```bash
tail -f ~/Library/Logs/mount_raidstore.err
```

Example log entries:
```
2024-01-15T10:30:00-08:00 Found mount at /Volumes/RAID Store-1, fixing to /Volumes/RAID Store
2024-01-15T10:30:02-08:00 Successfully remounted at /Volumes/RAID Store
```

## Management

**Stop the agent:**
```bash
launchctl unload ~/Library/LaunchAgents/com.oshyan.mount-raidstore.plist
```

**Start the agent:**
```bash
launchctl load ~/Library/LaunchAgents/com.oshyan.mount-raidstore.plist
```

**Check status:**
```bash
launchctl list | grep mount-raidstore
```

**Run manually:**
```bash
~/.local/bin/mount_raid_store.sh
```

## Troubleshooting

**Mount keeps appearing at `-1` suffix:**
The script auto-fixes this. Check logs for "fixing to" messages.

**Stale directory blocking mount point:**

If you see this error in the logs:
```
ERROR: Stale directory at /Volumes/RAID Store cannot be removed (needs sudo)
Run: sudo rmdir "/Volumes/RAID Store"
```

This happens when a previous mount was disconnected but left an empty directory behind. macOS won't mount over an existing directory, so it creates `-1`, `-2`, etc. suffixes instead.

To fix:
```bash
# 1. Stop the agent
launchctl unload ~/Library/LaunchAgents/com.oshyan.mount-raidstore.plist

# 2. Unmount any existing mounts
diskutil unmount "/Volumes/RAID Store-1" 2>/dev/null
diskutil unmount "/Volumes/RAID Store-2" 2>/dev/null

# 3. Remove the stale directory (requires sudo)
sudo rmdir "/Volumes/RAID Store"

# 4. Restart the agent
launchctl load ~/Library/LaunchAgents/com.oshyan.mount-raidstore.plist
```

The stale directory typically has unusual permissions (`d--x--x--x`) and cannot be listed or removed without sudo.

**Not connecting:**
1. Check if server is reachable: `nc -z Mac-Server.local 445`
2. Check Tailscale status: `/Applications/Tailscale.app/Contents/MacOS/Tailscale status`
3. Review error log: `cat ~/Library/Logs/mount_raidstore.err`

**Agent not running:**
```bash
launchctl list | grep mount-raidstore
# If not listed, reload:
launchctl load ~/Library/LaunchAgents/com.oshyan.mount-raidstore.plist
```
