#!/bin/zsh
# Keep /Volumes/RAID Store mounted at one stable SMB mount point.
# Home network uses Mac-Server.local (LAN); away uses Tailscale IP.
#
# Mount method: mount_smbfs (non-GUI, kernel-level).
#   - No Finder dialogs, no NetAuth hangs
#   - Credentials from keychain via `security` command
#   - Mount point directory managed explicitly
#   - All mount attempts wrapped with timeout to prevent hangs
#
# Liveness: stat-checks the mount with a timeout to detect stale mounts.
#
# Requires: keychain credentials saved (Cmd+K) for both LAN host and
# Tailscale IP. nsmb.conf with soft=yes recommended.

set -u

SHARE_NAME="RAID Store"
ENC_SHARE_NAME="${SHARE_NAME// /%20}"
MOUNT_POINT="/Volumes/RAID Store"

# Home network identity (gateway IP + MAC).
HOME_GATEWAY_IP="192.168.4.1"
HOME_GATEWAY_MAC="c4:a8:16:2c:ff:94"

# Server targets.
HOST_LOCAL="Mac-Server.local"
KEYCHAIN_HOST="Mac-Server.local"
TS_DEVICE_NAME="mac-server"
LAST_KNOWN_TS_IP="100.76.199.85"
TSCLI="/Applications/Tailscale.app/Contents/MacOS/Tailscale"

MOUNT_TIMEOUT=45
LOCKDIR="/tmp/mount_raid_store.lock"
UNREACHABLE_FLAG="/tmp/mount_raid_store_unreachable"

# --- Logging ---

log() {
  print -r -- "$(date -Iseconds) $*" >&2
}

# --- Locking ---

acquire_lock() {
  if mkdir "$LOCKDIR" 2>/dev/null; then
    return 0
  fi
  if [ -d "$LOCKDIR" ]; then
    local now mtime age
    now="$(date +%s)"
    mtime="$(stat -f%m "$LOCKDIR" 2>/dev/null || echo 0)"
    age=$(( now - mtime ))
    if (( age > 180 )); then
      rmdir "$LOCKDIR" 2>/dev/null || true
      mkdir "$LOCKDIR" 2>/dev/null && return 0
    fi
  fi
  return 1
}

release_lock() {
  rmdir "$LOCKDIR" 2>/dev/null || true
}

# --- Helpers ---

lower() {
  print -r -- "$1" | tr '[:upper:]' '[:lower:]'
}

run_with_timeout() {
  local seconds="$1"
  shift
  # Run command in foreground but kill it if it exceeds the timeout.
  # perl alarm doesn't reliably kill mount_smbfs (kernel mount op),
  # so we use a background watchdog instead.
  "$@" &
  local cmd_pid=$!
  (
    sleep "$seconds"
    kill "$cmd_pid" 2>/dev/null
    sleep 2
    kill -9 "$cmd_pid" 2>/dev/null
  ) &
  local watchdog_pid=$!
  wait "$cmd_pid" 2>/dev/null
  local rc=$?
  kill "$watchdog_pid" 2>/dev/null
  wait "$watchdog_pid" 2>/dev/null
  return $rc
}

url_encode() {
  local raw="$1"
  local out="" i ch hex
  for (( i = 1; i <= ${#raw}; i++ )); do
    ch="${raw[i]}"
    case "$ch" in
      [a-zA-Z0-9.~_-]) out+="$ch" ;;
      *) printf -v hex '%02X' "'$ch"; out+="%$hex" ;;
    esac
  done
  print -r -- "$out"
}

get_keychain_user() {
  security find-internet-password -s "$KEYCHAIN_HOST" -r "smb " 2>/dev/null \
    | awk -F '"' '/acct/ { print $4; exit }'
}

get_keychain_password() {
  run_with_timeout 6 security find-internet-password -s "$KEYCHAIN_HOST" -r "smb " -w 2>/dev/null
}

# --- Network detection ---

get_default_gateway() {
  local gw
  gw="$(netstat -rn -f inet 2>/dev/null | awk '$1=="default" && $NF !~ /^utun/ {print $2; exit}')"
  if [ -n "$gw" ]; then
    print -r -- "$gw"
    return
  fi
  route -n get default 2>/dev/null | awk '/gateway:/{print $2; exit}'
}

is_home_network() {
  local gw mac
  gw="$(get_default_gateway)"
  [ "$gw" = "$HOME_GATEWAY_IP" ] || return 1
  mac="$(arp -n "$HOME_GATEWAY_IP" 2>/dev/null | grep -oE '([0-9a-f]{2}:){5}[0-9a-f]{2}' | head -1)"
  [ "$(lower "$mac")" = "$(lower "$HOME_GATEWAY_MAC")" ]
}

network_mode() {
  if is_home_network; then
    print -r -- "home"
  else
    print -r -- "away"
  fi
}

get_ts_ip() {
  [ -x "$TSCLI" ] || return 1
  "$TSCLI" status 2>/dev/null | awk -v n="$TS_DEVICE_NAME" '$2==n {print $1; exit}'
}

desired_host_for_mode() {
  local mode="$1"
  if [ "$mode" = "home" ]; then
    print -r -- "$HOST_LOCAL"
    return
  fi
  local tsip
  tsip="$(get_ts_ip)"
  if [ -n "$tsip" ]; then
    print -r -- "$tsip"
  else
    print -r -- "$LAST_KNOWN_TS_IP"
  fi
}

host_reachable_smb() {
  local host="$1"
  [ -n "$host" ] || return 1
  nc -z -G 3 "$host" 445 >/dev/null 2>&1 || nc -z -w 3 "$host" 445 >/dev/null 2>&1
}

# --- Mount inspection ---

# Find all mount lines for our share (any host, any mount point).
list_share_mounts() {
  mount | awk -v share="/$ENC_SHARE_NAME " -v plain="/$SHARE_NAME " '
    (index($0, share) || index($0, plain)) {
      src = $1
      # Extract mount point: everything between " on " and " ("
      mp = $0
      sub(/^.* on /, "", mp)
      sub(/ \(.*$/, "", mp)
      # Extract host from source
      host = src
      sub(/^\/\//, "", host)
      sub(/^[^@]*@/, "", host)
      sub(/\/.*$/, "", host)
      printf "%s\t%s\n", host, mp
    }
  '
}

mount_count() {
  list_share_mounts | awk 'END { print NR + 0 }'
}

# Host of the mount at our canonical mount point, if any.
canonical_mount_host() {
  list_share_mounts | awk -F '\t' -v mp="$MOUNT_POINT" '$2 == mp { print $1; exit }'
}

mounted_at() {
  mount | grep -qF "on $1 ("
}

# --- Liveness check ---
# A stale SMB mount appears in mount output but any I/O hangs.
# Use perl alarm to timeout stat() calls that would hang on stale mounts.
# IMPORTANT: We test BOTH stat (-d) AND actual directory read (opendir/readdir).
# A mount can pass stat but fail to list contents (broken SMB session after
# server reboot or sleep/wake), which causes Finder "permission denied" errors.

is_mount_alive() {
  local mp="$1"
  mounted_at "$mp" || { log "  liveness: not in mount table"; return 1; }

  local rc diag
  diag="$(perl -e '
    $SIG{ALRM} = sub { print "TIMEOUT"; exit 2 };
    alarm 8;
    unless (-d $ARGV[0]) { print "not_a_dir"; exit 1 }
    # stat passed — now try to actually read directory contents.
    # A stale mount often passes stat but hangs or errors on readdir.
    unless (opendir(my $dh, $ARGV[0])) { print "opendir_failed:$!"; exit 1 }
    my @entries = readdir($dh);
    closedir($dh);
    if (@entries < 2) { print "empty_readdir"; exit 1 }
    print "ok";
    exit 0;
  ' "$mp" 2>&1)"
  rc=$?
  if (( rc != 0 )); then
    log "  liveness: $diag (rc=$rc)"
  fi
  return $rc
}

# --- Unmount helpers ---
# diskutil unmount force is the most reliable on macOS.
# It WILL delete the mount point directory under /Volumes — that's expected;
# we recreate it before mounting with mount_smbfs.

force_unmount() {
  local mp="$1"
  [ -n "$mp" ] || return 0
  diskutil unmount force "$mp" >/dev/null 2>&1 || umount -f "$mp" >/dev/null 2>&1
}

unmount_all_share_mounts() {
  local host mp
  while IFS=$'\t' read -r host mp; do
    [ -n "$mp" ] || continue
    force_unmount "$mp"
  done < <(list_share_mounts)
}

# Remove only non-canonical mounts (suffixed duplicates like "RAID Store-1").
unmount_noncanonical() {
  local host mp
  while IFS=$'\t' read -r host mp; do
    [ -n "$mp" ] || continue
    [ "$mp" = "$MOUNT_POINT" ] && continue
    log "Removing duplicate mount at $mp"
    force_unmount "$mp"
  done < <(list_share_mounts)
}

# Clean up empty suffixed directories left behind.
cleanup_stale_dirs() {
  local d
  for d in "${MOUNT_POINT}"-{1,2,3,4,5}; do
    mounted_at "$d" && continue
    [ -d "$d" ] || continue
    rmdir "$d" 2>/dev/null || true
  done
}

# --- Mount ---

ensure_mount_point_dir() {
  if mounted_at "$MOUNT_POINT"; then
    return 0
  fi
  # Remove stale empty dir if present (from previous force unmount).
  # Try user-level first, then sudo (Volumes is root-owned).
  if [ -d "$MOUNT_POINT" ] && ! mounted_at "$MOUNT_POINT"; then
    rmdir "$MOUNT_POINT" 2>/dev/null || sudo rmdir "$MOUNT_POINT" 2>/dev/null || true
  fi
  if [ ! -d "$MOUNT_POINT" ]; then
    sudo mkdir -p "$MOUNT_POINT" 2>/dev/null && sudo chown "$USER" "$MOUNT_POINT" 2>/dev/null || {
      log "ERROR: cannot create $MOUNT_POINT"
      return 1
    }
  fi
}

do_mount() {
  local host="$1"
  local user password enc_user enc_password

  ensure_mount_point_dir || return 1

  # Always use explicit credentials from keychain.
  # mount_smbfs -N (keychain-implicit) is unreliable with multiple SMB
  # keychain entries — it picks the wrong one or fails authentication.
  user="$(get_keychain_user)"
  [ -z "$user" ] && user="$USER"
  password="$(get_keychain_password)"
  if [ -z "$password" ]; then
    log "ERROR: keychain password lookup failed for $KEYCHAIN_HOST."
    return 1
  fi

  enc_user="$(url_encode "$user")"
  enc_password="$(url_encode "$password")"

  run_with_timeout "$MOUNT_TIMEOUT" mount_smbfs -o soft "//$enc_user:$enc_password@$host/$ENC_SHARE_NAME" "$MOUNT_POINT" >/dev/null 2>&1
}

# --- Finder notification ---
# After a remount, Finder's cached volume state is stale.
# Poke Finder so it picks up the new mount and doesn't show "permission denied."
notify_finder() {
  # Tell Finder to refresh its cached state for this volume.
  # Only runs if Finder is already active — won't launch it if it isn't.
  if pgrep -qx Finder; then
    osascript -e 'tell application "Finder" to update item (POSIX file "/Volumes/RAID Store")' 2>/dev/null || true
  fi
}

# --- Unreachable flag (throttle log spam to every 10 minutes) ---

UNREACHABLE_LOG_INTERVAL=600

log_unreachable() {
  local mode="$1" host="$2"
  local should_log=0

  if [ ! -f "$UNREACHABLE_FLAG" ]; then
    should_log=1
  else
    local now mtime age
    now="$(date +%s)"
    mtime="$(stat -f%m "$UNREACHABLE_FLAG" 2>/dev/null || echo 0)"
    age=$(( now - mtime ))
    (( age >= UNREACHABLE_LOG_INTERVAL )) && should_log=1
  fi

  if (( should_log )); then
    log "Host unreachable (mode=$mode host=$host). Will retry next cycle."
    touch "$UNREACHABLE_FLAG"
  fi
}

clear_unreachable_flag() {
  rm -f "$UNREACHABLE_FLAG"
}

# --- Main ---

main() {
  local mode desired count canonical

  mode="$(network_mode)"
  desired="$(desired_host_for_mode "$mode")"
  count="$(mount_count)"
  canonical="$(canonical_mount_host)"

  log "Check: mode=$mode desired=$desired count=$count canonical=${canonical:-none}"

  # --- Clean up duplicates first ---
  if (( count > 1 )); then
    log "Detected $count mounts; removing duplicates."
    unmount_noncanonical
    cleanup_stale_dirs
    count="$(mount_count)"
    canonical="$(canonical_mount_host)"
  fi

  # --- If we have exactly one mount at the canonical point ---
  if (( count == 1 )) && [ -n "$canonical" ]; then
    if is_mount_alive "$MOUNT_POINT"; then
      # Mount is alive and healthy.
      # Should we switch to the preferred host?
      if [ "$canonical" = "$desired" ]; then
        # Already on the right host. Done.
        clear_unreachable_flag
        cleanup_stale_dirs
        return 0
      fi

      # On a different host. Only switch if preferred is actually reachable.
      if host_reachable_smb "$desired"; then
        log "Switching from $canonical to preferred host $desired."
        force_unmount "$MOUNT_POINT"
        sleep 1
        cleanup_stale_dirs
        # Fall through to mount below.
      else
        # Preferred host unreachable but current mount works. Keep it.
        log "Preferred host $desired unreachable; keeping working mount via $canonical."
        clear_unreachable_flag
        cleanup_stale_dirs
        return 0
      fi
    else
      # Mount is stale/hung.
      log "Mount via $canonical is stale; forcing unmount."
      force_unmount "$MOUNT_POINT"
      sleep 1
      cleanup_stale_dirs
      # Fall through to mount below.
    fi
  elif (( count > 0 )); then
    # Mounts exist but not at canonical point. Clean up.
    log "Non-canonical mount state; cleaning up."
    unmount_all_share_mounts
    sleep 1
    cleanup_stale_dirs
  fi

  # --- Nothing mounted. Try to mount. ---

  local mounted=0

  # Try preferred host first.
  if host_reachable_smb "$desired"; then
    log "Attempting mount to $desired..."
    if do_mount "$desired"; then
      sleep 1
      if mounted_at "$MOUNT_POINT"; then
        mounted=1
      else
        log "ERROR: mount to $desired did not appear at $MOUNT_POINT."
        unmount_all_share_mounts
        cleanup_stale_dirs
      fi
    else
      log "ERROR: mount_smbfs to $desired failed."
    fi
  else
    log "Preferred host $desired not reachable on port 445."
  fi

  if (( mounted )); then
    clear_unreachable_flag
    cleanup_stale_dirs
    notify_finder
    log "Mounted via $desired (mode=$mode)."
    return 0
  fi

  # Try fallback host.
  local fallback=""
  if [ "$mode" = "home" ]; then
    fallback="$(get_ts_ip)"
  else
    fallback="$HOST_LOCAL"
  fi

  if [ -n "$fallback" ] && [ "$fallback" != "$desired" ]; then
    if host_reachable_smb "$fallback"; then
      log "Trying fallback host $fallback..."
      if do_mount "$fallback" && mounted_at "$MOUNT_POINT"; then
        clear_unreachable_flag
        cleanup_stale_dirs
        notify_finder
        log "Mounted via fallback $fallback (mode=$mode)."
        return 0
      fi
      unmount_all_share_mounts
      cleanup_stale_dirs
      log "ERROR: fallback mount to $fallback also failed."
    else
      log "Fallback host $fallback also not reachable."
    fi
  fi

  log_unreachable "$mode" "$desired"
  return 1
}

# --- Entry point ---

if ! acquire_lock; then
  exit 0
fi
trap 'release_lock' EXIT INT TERM

main
exit $?
