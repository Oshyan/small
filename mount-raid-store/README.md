# mount-raid-store

A macOS LaunchAgent that keeps one SMB share mounted at one stable path:
`/Volumes/RAID Store`.

It hard-gates by network mode:
- Home network: `Mac-Server.local` (direct LAN path)
- Away network: Tailscale IP for the same server

## Current approach

- Uses `mount_smbfs` (non-GUI) instead of `open smb://...`
- Makes only one target-host decision per run (no local + Tailscale chain)
- Detects and removes duplicate/suffixed mounts (`RAID Store-1`, `-2`, ...)
- Preserves the canonical `/Volumes/RAID Store` directory and only prunes stale suffixed mount dirs
- Uses a lock directory to prevent concurrent runs
- Uses timed credential fallback so keychain glitches do not hang the job

## Installation

1. Install script:
   ```bash
   cp mount_raid_store.sh ~/.local/bin/
   chmod +x ~/.local/bin/mount_raid_store.sh
   ```

2. Adjust config in `mount_raid_store.sh`:
   ```bash
   SHARE_NAME="RAID Store"
   MOUNT_POINT="/Volumes/RAID Store"
   HOST_LOCAL="Mac-Server.local"
   TS_DEVICE_NAME="mac-server"
   LAST_KNOWN_TS_IP="100.76.199.85"
   HOME_GATEWAY_IP="192.168.4.1"
   HOME_GATEWAY_MAC="c4:a8:16:2c:ff:94"
   ```

3. Create the canonical mount point once:
   ```bash
   sudo mkdir -p "/Volumes/RAID Store"
   sudo chown "$USER" "/Volumes/RAID Store"
   ```

4. Install LaunchAgent:
   ```bash
   cp com.oshyan.mount-raidstore.plist ~/Library/LaunchAgents/
   launchctl bootout gui/$(id -u) ~/Library/LaunchAgents/com.oshyan.mount-raidstore.plist 2>/dev/null || true
   launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.oshyan.mount-raidstore.plist
   ```

## Configuration reference

| Variable | Description |
|----------|-------------|
| `SHARE_NAME` | SMB share name |
| `MOUNT_POINT` | Canonical mount path |
| `HOST_LOCAL` | Home/LAN hostname |
| `KEYCHAIN_HOST` | Host used to query keychain SMB credential |
| `TS_DEVICE_NAME` | Tailscale peer name |
| `LAST_KNOWN_TS_IP` | Fallback Tailscale IP |
| `HOME_GATEWAY_IP` | Expected home default gateway IP |
| `HOME_GATEWAY_MAC` | Expected home gateway MAC |

LaunchAgent defaults:
- `RunAtLoad=true`
- `StartInterval=60`
- `ThrottleInterval=30`

## Logs

- stdout: `~/Library/Logs/mount_raidstore.out`
- stderr: `~/Library/Logs/mount_raidstore.err`

Tail:
```bash
tail -f ~/Library/Logs/mount_raidstore.err
```

## Management

- Check status:
  ```bash
  launchctl print gui/$(id -u)/com.oshyan.mount-raidstore
  ```
- Kick a manual run:
  ```bash
  launchctl kickstart -k gui/$(id -u)/com.oshyan.mount-raidstore
  ```
- Run script directly:
  ```bash
  ~/.local/bin/mount_raid_store.sh
  ```

## Troubleshooting

Duplicate mounts still present:
```bash
mount | grep "RAID Store"
```

Stale mountpoint directory:
```bash
sudo rmdir "/Volumes/RAID Store"
```

Credential/keychain behavior looks odd (double prompts elsewhere):
```bash
security find-internet-password -s "Mac-Server.local" -r "smb "
```
This script first tries non-interactive `mount_smbfs -N`; if needed it uses a timed keychain password lookup fallback so the agent does not block forever. Failed fallback attempts are cooled down to avoid repeated prompt loops.

Connectivity checks:
```bash
nc -z Mac-Server.local 445
/Applications/Tailscale.app/Contents/MacOS/Tailscale status
```
