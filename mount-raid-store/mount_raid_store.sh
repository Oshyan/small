#!/bin/zsh
# Keep /Volumes/RAID Store mounted at one stable path.
# Prefers LAN (.local / Bonjour), else Tailscale (no MagicDNS).
# Adds pre-clean so you never get "/Volumes/RAID Store-1".

SHARE_NAME="RAID Store"
ENC_SHARE_NAME="RAID%20Store"
MOUNT_POINT="/Volumes/RAID Store"

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

# If mounted at a suffixed path, unmount it
unsuffix_any_mounts() {
  local mp
  for mp in "$MOUNT_POINT-1" "$MOUNT_POINT-2" "$MOUNT_POINT-3"; do
    mounted_at "$mp" && diskutil unmount "$mp" >/dev/null 2>&1
  done
}

get_ts_ip() {
  [ -x "$TSCLI" ] || return
  "$TSCLI" status 2>/dev/null | awk -v n="$TS_DEVICE_NAME" '$2==n {print $1; exit}'
}

mount_url_wait() {
  local url="$1"
  open -g "$url"
  for i in {1..20}; do
    # Check if mounted at correct path
    is_mounted && return 0
    # Also check if it mounted somewhere (even suffixed) - still counts as success
    # We'll fix the path in the next run
    is_share_mounted_anywhere && return 0
    sleep 1
  done
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
      diskutil unmount "$MOUNT_POINT" >/dev/null 2>&1
      sleep 1
      preclean_mount_dirs
      mount_url_wait "smb://$HOST_LOCAL1/$ENC_SHARE_NAME" || \
      mount_url_wait "smb://$HOST_LOCAL2_ENC/$ENC_SHARE_NAME"
    fi
  fi
}

main() {
  # Check if share is already mounted anywhere
  if is_share_mounted_anywhere; then
    local actual_mp="$(get_actual_mount_point)"

    if [ "$actual_mp" = "$MOUNT_POINT" ]; then
      # Mounted at correct path - check if we should switch to LAN
      ensure_preferred
      exit 0
    fi

    # Mounted at wrong path (suffixed) - fix it
    echo "$(date -Iseconds) Found mount(s) at wrong path, unmounting all and fixing to $MOUNT_POINT" >&2
    unmount_all_share_mounts
    sleep 2
    preclean_mount_dirs

    # Check for stale directory before remounting
    check_stale_mount_dir || exit 1

    # Remount at correct path
    if try_local; then
      echo "$(date -Iseconds) Successfully remounted at $MOUNT_POINT" >&2
      exit 0
    fi

    # Try Tailscale if local failed
    local TSIP="$(get_ts_ip)"; [ -z "$TSIP" ] && TSIP="$LAST_KNOWN_TS_IP"
    if [ -n "$TSIP" ] && reach445 "$TSIP"; then
      mount_url_wait "smb://$TSIP/$ENC_SHARE_NAME" && {
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
  try_local && exit 0

  # Else Tailscale (dynamic IP, then fallback)
  local TSIP="$(get_ts_ip)"; [ -z "$TSIP" ] && TSIP="$LAST_KNOWN_TS_IP"
  if [ -n "$TSIP" ] && reach445 "$TSIP"; then
    mount_url_wait "smb://$TSIP/$ENC_SHARE_NAME" && exit 0
  fi

  echo "Neither local nor Tailscale host reachable on 445." >&2
  exit 1
}
main
