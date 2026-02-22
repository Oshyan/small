#!/bin/zsh
# Keep /Volumes/RAID Store mounted at one stable path.
# Prefers LAN (.local / Bonjour), else Tailscale (no MagicDNS).
# Adds pre-clean so you never get "/Volumes/RAID Store-1".

ENC_SHARE_NAME="RAID%20Store"
MOUNT_POINT="/Volumes/RAID Store"

# Lock file to prevent concurrent runs
LOCKFILE="/tmp/mount_raid_store.lock"
if ! mkdir "$LOCKFILE" 2>/dev/null; then
  # Check if stale (older than 120s)
  if [ -d "$LOCKFILE" ] && [ "$(( $(date +%s) - $(stat -f%m "$LOCKFILE") ))" -gt 120 ]; then
    rmdir "$LOCKFILE" 2>/dev/null
    mkdir "$LOCKFILE" 2>/dev/null || exit 0
  else
    exit 0
  fi
fi
trap 'rmdir "$LOCKFILE" 2>/dev/null' EXIT

# Rate-limit "not reachable" log messages (once per 10 minutes)
UNREACHABLE_FLAG="/tmp/mount_raid_store_unreachable"

# Local names (try either)
HOST_LOCAL1="Mac-Server.local"
HOST_LOCAL2="Mac Server._smb._tcp.local"
HOST_LOCAL2_ENC="Mac%20Server._smb._tcp.local"

# Tailscale device + fallback IP
TS_DEVICE_NAME="mac-server"
LAST_KNOWN_TS_IP="100.76.199.85"
TSCLI="/Applications/Tailscale.app/Contents/MacOS/Tailscale"

# Check if mounted at exact path
is_mounted() { mount | grep -F "on $MOUNT_POINT " >/dev/null; }
mounted_at() { mount | grep -F "on $1 " >/dev/null; }

# Check if the share is mounted ANYWHERE (including -1, -2, etc.)
is_share_mounted_anywhere() {
  mount | grep -E "/$ENC_SHARE_NAME on " >/dev/null
}

# Get ALL mount points for this share (may be multiple)
get_all_mount_points() {
  mount | grep -E "/$ENC_SHARE_NAME on " | sed -E 's|.* on (.*) \(.*|\1|'
}

# Get the first mount point (may be suffixed)
get_actual_mount_point() {
  get_all_mount_points | head -1
}

# Unmount ALL instances of this share
unmount_all_share_mounts() {
  local mp
  get_all_mount_points | while read mp; do
    [ -n "$mp" ] && diskutil unmount "$mp" >/dev/null 2>&1
  done
}

current_host() {
  mount | awk -v MP="$MOUNT_POINT" '$0 ~ "on " MP " " {print $1}' \
  | sed -E 's|^//[^@]+@([^/]+)/.*$|\1|'
}

reach445() { nc -z -G 1 "$1" 445 >/dev/null 2>&1 || nc -z -w 1 "$1" 445 >/dev/null 2>&1; }

# Remove any empty leftover mount dirs (… and …-1, …-2)
preclean_mount_dirs() {
  local d
  for d in "$MOUNT_POINT" "$MOUNT_POINT-1" "$MOUNT_POINT-2" "$MOUNT_POINT-3"; do
    mounted_at "$d" || rmdir "$d" 2>/dev/null
  done
}

get_ts_ip() {
  [ -x "$TSCLI" ] || return
  "$TSCLI" status 2>/dev/null | awk -v n="$TS_DEVICE_NAME" '$2==n {print $1; exit}'
}

# ── Finder window/tab save & restore ──────────────────────────────
# Saves full paths of ALL Finder tabs (all windows) open under a given
# mount point prefix. Uses System Events (requires Accessibility once).
# Returns newline-separated list: "WINDOW<idx>\tTAB\t<path>" per tab,
# with WINDOW boundaries so we can regroup tabs into the same windows.

SAVED_FINDER_PATHS=""

save_finder_windows() {
  local prefix="$1"  # e.g. "/Volumes/RAID Store"
  SAVED_FINDER_PATHS="$(osascript <<APPLESCRIPT 2>/dev/null
set allPaths to ""
set matchPrefix to "$prefix"

-- Iterate all Finder windows by index
tell application "Finder"
  set wCount to count of Finder windows
end tell

repeat with wIdx from 1 to wCount
  set windowHasMatch to false
  set windowPaths to ""

  -- Try to enumerate tabs via System Events
  set hasTabs to false
  tell application "System Events"
    tell process "Finder"
      try
        set tg to tab group 1 of window wIdx
        set tabButtons to every radio button of tg
        set tabCount to count of tabButtons
        if tabCount > 1 then set hasTabs to true
      on error
        set hasTabs to false
      end try
    end tell
  end tell

  if hasTabs then
    -- Multi-tab window: find original active tab, click through each
    set originalTab to 1
    tell application "System Events"
      tell process "Finder"
        set tg to tab group 1 of window wIdx
        set tabButtons to every radio button of tg
        repeat with i from 1 to (count of tabButtons)
          try
            if value of (item i of tabButtons) is true then
              set originalTab to i
            end if
          end try
        end repeat
      end tell
    end tell

    tell application "System Events"
      tell process "Finder"
        set tg to tab group 1 of window wIdx
        set tabCount to count of (every radio button of tg)
      end tell
    end tell

    repeat with i from 1 to tabCount
      -- Click tab i by window index (no focus steal)
      tell application "System Events"
        tell process "Finder"
          click (radio button i of tab group 1 of window wIdx)
        end tell
      end tell
      delay 0.2
      tell application "Finder"
        try
          set p to POSIX path of (target of (Finder window wIdx) as alias)
          if p starts with matchPrefix then
            set windowHasMatch to true
            set windowPaths to windowPaths & "TAB" & tab & p & linefeed
          end if
        end try
      end tell
    end repeat

    -- Restore original tab
    tell application "System Events"
      tell process "Finder"
        click (radio button originalTab of tab group 1 of window wIdx)
      end tell
    end tell
  else
    -- Single-tab window
    tell application "Finder"
      try
        set p to POSIX path of (target of (Finder window wIdx) as alias)
        if p starts with matchPrefix then
          set windowHasMatch to true
          set windowPaths to "TAB" & tab & p & linefeed
        end if
      end try
    end tell
  end if

  if windowHasMatch then
    set allPaths to allPaths & "WINDOW" & linefeed & windowPaths
  end if
end repeat

return allPaths
APPLESCRIPT
  )"
}

restore_finder_windows() {
  [ -z "$SAVED_FINDER_PATHS" ] && return

  local old_prefix="$1"   # previous mount path (may be suffixed)
  local new_prefix="$2"   # new mount path (correct)
  [ -z "$new_prefix" ] && new_prefix="$MOUNT_POINT"

  local current_window_tabs=()
  local line path adjusted

  while IFS= read -r line; do
    if [[ "$line" == "WINDOW" ]]; then
      # Open previous window's tabs (if any)
      if (( ${#current_window_tabs[@]} > 0 )); then
        _open_finder_window_with_tabs "${current_window_tabs[@]}"
      fi
      current_window_tabs=()
    elif [[ "$line" == TAB$'\t'* ]]; then
      path="${line#TAB	}"
      # Remap from old mount path to new mount path
      if [ "$old_prefix" != "$new_prefix" ]; then
        adjusted="${new_prefix}${path#$old_prefix}"
      else
        adjusted="$path"
      fi
      # Only restore if the path exists on the new mount
      [ -d "$adjusted" ] && current_window_tabs+=("$adjusted")
    fi
  done <<< "$SAVED_FINDER_PATHS"

  # Open last window's tabs
  if (( ${#current_window_tabs[@]} > 0 )); then
    _open_finder_window_with_tabs "${current_window_tabs[@]}"
  fi
}

_open_finder_window_with_tabs() {
  local tabs=("$@")
  (( ${#tabs[@]} == 0 )) && return

  # Open first tab as a new Finder window (bypasses "open in tab" setting)
  osascript -e "tell application \"Finder\" to make new Finder window to POSIX file \"${tabs[1]}\"" 2>/dev/null
  sleep 0.5

  # Open remaining tabs into the same window (Cmd+T then navigate)
  local i
  for (( i=2; i<=${#tabs[@]}; i++ )); do
    osascript <<APPLESCRIPT 2>/dev/null
tell application "Finder"
  activate
end tell
tell application "System Events"
  keystroke "t" using command down
  delay 0.3
end tell
tell application "Finder"
  set target of front Finder window to POSIX file "${tabs[$i]}"
end tell
APPLESCRIPT
    sleep 0.3
  done
}

# ── Mount helpers ─────────────────────────────────────────────────

mount_url_wait() {
  local url="$1"
  # Final guard: if something mounted between our check and now, don't open again
  is_share_mounted_anywhere && return 0
  open -g "$url"
  for i in {1..20}; do
    is_mounted && return 0
    sleep 1
  done
  # Check if it ended up at a suffixed path and fix immediately
  if is_share_mounted_anywhere; then
    local actual="$(get_actual_mount_point)"
    if [ "$actual" != "$MOUNT_POINT" ]; then
      echo "$(date -Iseconds) Mounted at $actual instead of $MOUNT_POINT, fixing" >&2
      # Don't save Finder windows here — caller already saved if needed
      diskutil unmount "$actual" >/dev/null 2>&1
      sleep 1
      preclean_mount_dirs
      check_stale_mount_dir || return 1
      open -g "$url"
      for i in {1..20}; do
        is_mounted && return 0
        sleep 1
      done
    fi
    return 0
  fi
  return 1
}

# Check if stale directory blocking mount point
check_stale_mount_dir() {
  if [ -d "$MOUNT_POINT" ] && ! mounted_at "$MOUNT_POINT"; then
    # Directory exists but nothing mounted there - it's stale
    if ! rmdir "$MOUNT_POINT" 2>/dev/null; then
      echo "$(date -Iseconds) ERROR: Stale directory at $MOUNT_POINT cannot be removed (needs sudo)" >&2
      echo "$(date -Iseconds) Run: sudo rmdir \"$MOUNT_POINT\"" >&2
      return 1
    fi
  fi
  return 0
}

try_local() {
  if reach445 "$HOST_LOCAL1"; then
    mount_url_wait "smb://$HOST_LOCAL1/$ENC_SHARE_NAME" && return 0
  fi
  if reach445 "$HOST_LOCAL2"; then
    mount_url_wait "smb://$HOST_LOCAL2_ENC/$ENC_SHARE_NAME" && return 0
  fi
  return 1
}

ensure_preferred() {
  local cur="$(current_host)"
  if [ -n "$cur" ] && [ "$cur" != "$HOST_LOCAL1" ] && [ "$cur" != "$HOST_LOCAL2_ENC" ]; then
    if reach445 "$HOST_LOCAL1" || reach445 "$HOST_LOCAL2"; then
      # Save Finder windows before unmounting
      save_finder_windows "$MOUNT_POINT"
      diskutil unmount "$MOUNT_POINT" >/dev/null 2>&1
      sleep 1
      preclean_mount_dirs
      if mount_url_wait "smb://$HOST_LOCAL1/$ENC_SHARE_NAME" || \
         mount_url_wait "smb://$HOST_LOCAL2_ENC/$ENC_SHARE_NAME"; then
        restore_finder_windows "$MOUNT_POINT" "$MOUNT_POINT"
      else
        # LAN failed — fall back to original Tailscale connection
        echo "$(date -Iseconds) LAN switch failed, re-mounting via $cur" >&2
        preclean_mount_dirs
        if mount_url_wait "smb://$cur/$ENC_SHARE_NAME"; then
          restore_finder_windows "$MOUNT_POINT" "$MOUNT_POINT"
        else
          echo "$(date -Iseconds) ERROR: Failed to re-mount via Tailscale after LAN switch failure" >&2
        fi
      fi
    fi
  fi
}

log_unreachable() {
  # Only log once per outage (flag file tracks state)
  if [ ! -f "$UNREACHABLE_FLAG" ]; then
    echo "$(date -Iseconds) Neither local nor Tailscale host reachable on 445." >&2
    touch "$UNREACHABLE_FLAG"
  fi
}

clear_unreachable() {
  rm -f "$UNREACHABLE_FLAG"
}

main() {
  # Check if share is already mounted anywhere
  if is_share_mounted_anywhere; then
    clear_unreachable
    local actual_mp="$(get_actual_mount_point)"

    if [ "$actual_mp" = "$MOUNT_POINT" ]; then
      # Mounted at correct path - check if we should switch to LAN
      ensure_preferred
      exit 0
    fi

    # Mounted at wrong path (suffixed) - save windows, fix it
    echo "$(date -Iseconds) Found mount(s) at wrong path, fixing to $MOUNT_POINT" >&2
    save_finder_windows "$actual_mp"
    unmount_all_share_mounts
    sleep 2
    preclean_mount_dirs

    # Check for stale directory before remounting
    check_stale_mount_dir || exit 1

    # Remount at correct path
    if try_local; then
      restore_finder_windows "$actual_mp" "$MOUNT_POINT"
      echo "$(date -Iseconds) Successfully remounted at $MOUNT_POINT" >&2
      exit 0
    fi

    # Try Tailscale if local failed
    local TSIP="$(get_ts_ip)"; [ -z "$TSIP" ] && TSIP="$LAST_KNOWN_TS_IP"
    if [ -n "$TSIP" ] && reach445 "$TSIP"; then
      mount_url_wait "smb://$TSIP/$ENC_SHARE_NAME" && {
        restore_finder_windows "$actual_mp" "$MOUNT_POINT"
        echo "$(date -Iseconds) Successfully remounted at $MOUNT_POINT via Tailscale" >&2
        exit 0
      }
    fi

    echo "$(date -Iseconds) Failed to remount after fixing suffix" >&2
    exit 1
  fi

  # Not mounted anywhere - clean up empty dirs and mount fresh
  preclean_mount_dirs

  # Check for stale directory that would cause suffixed mount
  check_stale_mount_dir || exit 1

  # Prefer LAN
  if try_local; then
    clear_unreachable
    exit 0
  fi

  # Else Tailscale (dynamic IP, then fallback)
  local TSIP="$(get_ts_ip)"; [ -z "$TSIP" ] && TSIP="$LAST_KNOWN_TS_IP"
  if [ -n "$TSIP" ] && reach445 "$TSIP"; then
    if mount_url_wait "smb://$TSIP/$ENC_SHARE_NAME"; then
      clear_unreachable
      exit 0
    fi
  fi

  log_unreachable
  exit 1
}
main
