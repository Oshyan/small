# RAID Store Mount Script — Research & Analysis

## Current Script Goals

1. Keep `/Volumes/RAID Store` mounted via SMB to `Mac-Server` (a local Mac)
2. Runs every 60s via launchd (`com.oshyan.mount-raidstore.plist`)
3. **At home (on home WiFi)**: connect via `Mac-Server.local` (Bonjour/mDNS) — direct LAN, full speed
4. **Away (any other network)**: connect via Tailscale IP `100.76.199.85` — encrypted tunnel
5. If mounted via Tailscale but home WiFi becomes available, switch to LAN (prefer local)
6. Handle macOS's annoying `/Volumes/RAID Store-1`, `-2` suffix problem
7. Save/restore Finder windows when remounting

## The Bug (Feb 27, 2026)

### What happened
After returning home and opening laptop, 6+ simultaneous "Connecting to Server" Finder dialogs piled up, all trying to mount the RAID Store.

### Root cause (multi-layered)
1. **`open -g smb://...` spawns persistent Finder GUI dialogs** that survive after the script exits
2. **Old script tries up to 3 hosts per run**: LOCAL1 (Mac-Server.local), LOCAL2 (mDNS service name), Tailscale — each creates a separate dialog
3. **Dialogs pile up across runs**: script runs every 60s via launchd, each run adds 1-3 more dialogs
4. **Secondary damage**: too many piled-up SMB connection attempts **broke Finder's SMB handler entirely**, causing `open -g smb://...` to fail with error `-1712` (`kLSServerCommunicationErr`). This persisted even after closing all dialogs — `open -g` for SMB URLs was permanently broken until the Finder/system state was cleared.

### Why it got worse over time
Each previous "fix" to the script added more fallback paths (LOCAL1, LOCAL2, Tailscale) that all used `open -g`, increasing the number of dialogs per failed run from 1 to 3.

## Key Research Findings

### Mounting Methods

| Method | Pros | Cons |
|--------|------|------|
| `open -g smb://...` | Uses Keychain automatically, simple | **DANGEROUS**: Creates persistent GUI dialogs that can't be cancelled. Can corrupt Finder's SMB handler. |
| `mount_smbfs //user:pass@host/share /path` | No GUI ever, synchronous, precise mount point control | Must extract creds from Keychain via `security` command. Mount point dir must exist. |
| `osascript mount volume` | Uses Keychain, may not show Finder window | Blocks indefinitely if server unreachable. Tested: hangs for 25+ seconds even on reachable server. |

**Conclusion**: `mount_smbfs` is the right primary method. `open -g` can be kept as a last-resort fallback. `osascript mount volume` is not viable (hangs too long).

### Keychain Credential Access

Credentials for Mac-Server.local are stored in the login keychain:
```bash
# Get username
security find-internet-password -s "Mac-Server.local" -r "smb " 2>/dev/null | awk -F'"' '/acct/{print $4}'
# Returns: Oshyan

# Get password
security find-internet-password -s "Mac-Server.local" -r "smb " -w 2>/dev/null
# Returns: the password (verified working)
```

The same username/password works for both Mac-Server.local and the Tailscale IP (same physical server). Keychain entry is under `Mac-Server.local` — used for all connections.

### WiFi SSID Detection — BLOCKED by macOS Privacy

Modern macOS (Sonoma/Sequoia/Tahoe) **redacts WiFi SSID from CLI tools** without Location Services permission:
- `networksetup -getairportnetwork en0` → "You are not associated with an AirPort network" (incorrect)
- `ipconfig getsummary en0` → `SSID: <data> 0x00` (redacted)
- `system_profiler SPAirPortDataType` → `<redacted>` for network name
- `scutil State:/Network/Interface/en0/AirPort` → `SSID_STR:` (empty)
- Swift `CWWiFiClient.shared().interface()?.ssid()` → empty (needs Location Services)

**Workaround implemented**: Use **gateway IP + gateway MAC address** as home-network identifier:
```bash
# Gateway IP (fast to get)
route -n get default | awk '/gateway:/{print $2}'  # → 192.168.4.1

# Gateway MAC (globally unique)
arp -n 192.168.4.1 | grep -oE '([0-9a-f]{2}:){5}[0-9a-f]{2}'  # → c4:a8:16:2c:ff:94
```

Home network = gateway IP is `192.168.4.1` AND gateway MAC is `c4:a8:16:2c:ff:94`. This is unique to the specific Eero router (gateway IP alone is not unique since `192.168.4.1` is Eero's default).

### Tailscale Unified Address (Considered & Rejected)

Using `smb://mac-server.tailnet-name.ts.net/RAID%20Store` everywhere would simplify to one address. Tailscale routes same-LAN traffic directly. **However:**

- **~10-15% throughput penalty** on 1GbE from WireGuard userspace encryption on macOS
- Known macOS packet loss issue (Tailscale GitHub #10356)
- User requires UNTHROTTLED local LAN speed with zero outside dependency
- If Tailscale is down, no fallback

**Verdict**: Keep separate LAN vs Tailscale paths, hard-gated by network identity.

### Finder Connection Dialog Cleanup

"Connecting to Server" windows (NOT user browsing windows) can be closed via System Events:
```applescript
tell application "System Events"
  tell process "Finder"
    repeat with w in (every window)
      if name of w starts with "Connecting to" then
        click button 1 of w
      end if
    end repeat
  end tell
end tell
```

Called at script start (cleanup from previous runs) and in EXIT trap. **Note**: These windows show up as "Connecting to Server" in System Events, NOT "Connecting to smb://..." as shown in the visual UI.

### Other Improvements (Not Yet Implemented)

- **launchd WatchPaths**: Add `/Library/Preferences/SystemConfiguration` watch for faster reaction to network changes
- **`/etc/nsmb.conf`**: Create with `soft=yes` to prevent SMB operations from hanging when server goes away
- **Script timeout wrapper**: Prevent script from blocking indefinitely

## What Was Changed (Current State of Script)

The script (`mount_raid_store.sh`) has been **rewritten** with these changes:

### Completed
1. **Home network detection**: `is_home_network()` checks gateway IP + MAC address
2. **Hard network gate**: `get_mount_url()` returns ONE URL — Mac-Server.local if home, Tailscale IP if away. Never both.
3. **Removed**: `try_local()`, `ensure_preferred()`, HOST_LOCAL2 fallback, sequential LOCAL→Tailscale chain
4. **Added**: `cancel_pending_connection_dialogs()` — cleans up stale Finder spinner windows at script start, exit, and before each mount attempt
5. **Added**: `is_correct_host()` — detects if current mount is via the right host for the current network (e.g., mounted via Tailscale but now home → switch to local)
6. **Simplified main()**: one clear flow with no fallback chains
7. **Kept intact**: All Finder window/tab save/restore code, lock file, preclean, suffix fixing
8. **Added**: `KEYCHAIN_HOST` variable for credential lookup

### In Progress / Not Yet Tested
- **`mount_smbfs` as primary mount method**: Code partially written but not deployed. Would replace `open -g` to eliminate all GUI dialog issues permanently. Needs testing when server is back.
  - Credentials: extracted from keychain via `security find-internet-password`
  - Mount point: created via `mkdir` (user has permission in `/Volumes`)
  - Password passed via URL to `mount_smbfs` (security concern: visible in `ps` briefly)

### Still Uses `open -g` (Current)
The deployed script still uses `open -g` for the actual mount. However, it's now much safer:
- Only ONE `open -g` per script run (was up to 3 before)
- Stale dialogs cleaned up at script start
- Network hard-gate prevents trying wrong host

## What Remains To Verify

1. **Server connectivity**: The Mac Server itself appears to be having issues (can't connect via Screen Share either). This is independent of the script.
2. **`mount_smbfs` method**: Test when server is back. If it works, deploy as replacement for `open -g`.
3. **`open -g` error -1712**: May clear after rebooting Finder or the MacBook. Caused by Finder's SMB handler getting corrupted from dialog pileup.
4. **Home network detection**: Verified working (`is_home_network` returns YES when on home WiFi).
5. **mount_url_wait with `open -g`**: Verified that it correctly selects Mac-Server.local (not Tailscale) when on home network, BUT the actual mount fails due to the -1712 / server issue.

## Files

- `mount_raid_store.sh` — the rewritten script (deployed to `~/.local/bin/mount_raid_store.sh`)
- `com.oshyan.mount-raidstore.plist` — launchd config (unchanged)
- `RESEARCH.md` — this file
