#!/bin/zsh
# Keep /Volumes/RAID Store mounted at one stable SMB mount point.
# Home network uses Mac-Server.local (LAN speed); away uses Tailscale.
# Uses mount_smbfs (non-GUI) to avoid Finder connection dialog pileups.

set -u

SHARE_NAME="RAID Store"
ENC_SHARE_NAME="${SHARE_NAME// /%20}"
MOUNT_ESC_SHARE_NAME="${SHARE_NAME// /\\\\040}"
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

LOCKDIR="/tmp/mount_raid_store.lock"
UNREACHABLE_FLAG="/tmp/mount_raid_store_unreachable"
PASSWORD_FALLBACK_COOLDOWN=1800
PASSWORD_FALLBACK_BLOCK_FLAG="/tmp/mount_raid_store_password_fallback_block"

log() {
  print -r -- "$(date -Iseconds) $*" >&2
}

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

run_with_timeout() {
  local seconds="$1"
  shift
  perl -e 'alarm shift; exec @ARGV' "$seconds" "$@"
}

lower() {
  print -r -- "$1" | tr '[:upper:]' '[:lower:]'
}

url_encode() {
  local raw="$1"
  local out="" i ch hex

  for (( i = 1; i <= ${#raw}; i++ )); do
    ch="${raw[i]}"
    case "$ch" in
      [a-zA-Z0-9.~_-])
        out+="$ch"
        ;;
      *)
        printf -v hex '%02X' "'$ch"
        out+="%$hex"
        ;;
    esac
  done

  print -r -- "$out"
}

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

host_reachable_445() {
  local host="$1"
  [ -n "$host" ] || return 1
  nc -z -G 2 "$host" 445 >/dev/null 2>&1 || nc -z -w 2 "$host" 445 >/dev/null 2>&1
}

host_matches_mode() {
  local host mode hl
  host="$1"
  mode="$2"
  hl="$(lower "$host")"

  if [ "$mode" = "home" ]; then
    [[ "$hl" == *.local || "$hl" == 192.168.* ]]
  else
    [[ "$hl" == 100.* || "$hl" == *.ts.net ]]
  fi
}

share_mount_lines() {
  mount | awk -v enc="/$ENC_SHARE_NAME on " -v plain="/$SHARE_NAME on " -v esc="/$MOUNT_ESC_SHARE_NAME on " '
    index($0, enc) || index($0, plain) || index($0, esc) { print }
  '
}

list_share_mounts() {
  share_mount_lines | awk '
    {
      src=$1
      mp=$0
      sub(/^.* on /, "", mp)
      sub(/ \(.*$/, "", mp)
      host=src
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

canonical_mount_host() {
  list_share_mounts | awk -F '\t' -v mp="$MOUNT_POINT" '$2 == mp { print $1; exit }'
}

mounted_at() {
  mount | grep -F "on $1 (" >/dev/null
}

unmount_mountpoint() {
  local mp="$1"
  [ -n "$mp" ] || return 0
  diskutil unmount "$mp" >/dev/null 2>&1 || umount "$mp" >/dev/null 2>&1
}

unmount_all_except() {
  local keep="$1"
  local mp
  while IFS= read -r mp; do
    [ -n "$mp" ] || continue
    [ "$mp" = "$keep" ] && continue
    unmount_mountpoint "$mp"
  done < <(list_share_mounts | awk -F '\t' '!seen[$2]++ { print $2 }')
}

unmount_all_share_mounts() {
  unmount_all_except ""
}

unmount_noncanonical_mounts() {
  local mp
  while IFS= read -r mp; do
    [ -n "$mp" ] || continue
    unmount_mountpoint "$mp"
  done < <(list_share_mounts | awk -F '\t' -v target="$MOUNT_POINT" '$2 != target && !seen[$2]++ { print $2 }')
}

cleanup_stale_mount_dirs() {
  local d suffix
  # Keep the canonical mount point directory; mount_smbfs needs it to persist.
  for suffix in "-1" "-2" "-3" "-4" "-5"; do
    d="${MOUNT_POINT}${suffix}"
    mounted_at "$d" && continue
    [ -d "$d" ] || continue
    rmdir "$d" 2>/dev/null || true
  done
}

ensure_mount_point_dir() {
  if mounted_at "$MOUNT_POINT" || [ -d "$MOUNT_POINT" ]; then
    return 0
  fi

  if [ -e "$MOUNT_POINT" ]; then
    log "ERROR: mount point path exists but is not a directory: $MOUNT_POINT"
    return 1
  fi

  mkdir -p "$MOUNT_POINT" 2>/dev/null || {
    log "ERROR: failed to create mount point $MOUNT_POINT"
    log "Run once manually: sudo mkdir -p \"$MOUNT_POINT\" && sudo chown \"$USER\" \"$MOUNT_POINT\""
    return 1
  }
}

get_keychain_user() {
  security find-internet-password -s "$KEYCHAIN_HOST" -r "smb " 2>/dev/null | awk -F '"' '/acct/ { print $4; exit }'
}

get_keychain_password() {
  run_with_timeout 6 security find-internet-password -s "$KEYCHAIN_HOST" -r "smb " -w 2>/dev/null
}

password_fallback_allowed() {
  if [ ! -f "$PASSWORD_FALLBACK_BLOCK_FLAG" ]; then
    return 0
  fi

  local now mtime age
  now="$(date +%s)"
  mtime="$(stat -f%m "$PASSWORD_FALLBACK_BLOCK_FLAG" 2>/dev/null || echo 0)"
  age=$(( now - mtime ))

  if (( age >= PASSWORD_FALLBACK_COOLDOWN )); then
    rm -f "$PASSWORD_FALLBACK_BLOCK_FLAG"
    return 0
  fi

  return 1
}

mark_password_fallback_failure() {
  touch "$PASSWORD_FALLBACK_BLOCK_FLAG"
}

clear_password_fallback_failure() {
  rm -f "$PASSWORD_FALLBACK_BLOCK_FLAG"
}

mount_with_url_password() {
  local host="$1"
  local user="$2"
  local password="$3"
  local enc_user enc_password

  enc_user="$(url_encode "$user")"
  enc_password="$(url_encode "$password")"

  run_with_timeout 20 mount_smbfs -o soft,nopassprompt "//$enc_user:$enc_password@$host/$ENC_SHARE_NAME" "$MOUNT_POINT" >/dev/null 2>&1
}

mount_share() {
  local host="$1"
  local user password

  user="$(get_keychain_user)"
  [ -z "$user" ] && user="$USER"

  ensure_mount_point_dir || return 1

  if run_with_timeout 20 mount_smbfs -N -o soft,nopassprompt "//$user@$host/$ENC_SHARE_NAME" "$MOUNT_POINT" >/dev/null 2>&1; then
    clear_password_fallback_failure
    return 0
  fi

  if ! password_fallback_allowed; then
    log "Keychain password fallback is in cooldown; skipping retry."
    return 1
  fi

  password="$(get_keychain_password)"
  if [ -z "$password" ]; then
    mark_password_fallback_failure
    log "ERROR: keychain password lookup failed for $KEYCHAIN_HOST."
    return 1
  fi

  if mount_with_url_password "$host" "$user" "$password"; then
    clear_password_fallback_failure
    unset password
    return 0
  fi

  mark_password_fallback_failure
  unset password
  return 1
}

log_unreachable_once() {
  local mode="$1"
  local host="$2"
  if [ ! -f "$UNREACHABLE_FLAG" ]; then
    log "Target SMB host unreachable (mode=$mode host=$host)."
    touch "$UNREACHABLE_FLAG"
  fi
}

clear_unreachable_flag() {
  rm -f "$UNREACHABLE_FLAG"
}

main() {
  local mode desired reachable count canonical keep

  mode="$(network_mode)"
  desired="$(desired_host_for_mode "$mode")"
  reachable=0
  host_reachable_445 "$desired" && reachable=1

  count="$(mount_count)"
  canonical="$(canonical_mount_host)"

  if (( count > 1 )); then
    log "Detected $count mounts for this share; removing duplicates."
    if [ -n "$canonical" ]; then
      unmount_noncanonical_mounts
    elif [ "$reachable" -eq 0 ]; then
      keep="$(list_share_mounts | awk -F '\t' 'NR==1 { print $2 }')"
      [ -n "$keep" ] && unmount_all_except "$keep"
    fi
  fi

  count="$(mount_count)"
  canonical="$(canonical_mount_host)"

  if (( count == 1 )) && [ -n "$canonical" ] && host_matches_mode "$canonical" "$mode"; then
    clear_unreachable_flag
    cleanup_stale_mount_dirs
    return 0
  fi

  if [ "$reachable" -eq 0 ]; then
    if (( count > 0 )); then
      if [ -n "$canonical" ]; then
        unmount_noncanonical_mounts
      else
        keep="$(list_share_mounts | awk -F '\t' 'NR==1 { print $2 }')"
        [ -n "$keep" ] && unmount_all_except "$keep"
      fi
      cleanup_stale_mount_dirs
      log "Target $desired unreachable; keeping existing mount state."
      clear_unreachable_flag
      return 0
    fi

    log_unreachable_once "$mode" "$desired"
    return 1
  fi

  if (( count > 0 )); then
    log "Reconciling existing mount(s) before remount."
    unmount_all_share_mounts
    sleep 1
  fi

  cleanup_stale_mount_dirs
  ensure_mount_point_dir || return 1

  if mount_share "$desired"; then
    clear_unreachable_flag
    unmount_noncanonical_mounts
    cleanup_stale_mount_dirs
    log "Mounted //$desired/$SHARE_NAME at $MOUNT_POINT (mode=$mode)."
    return 0
  fi

  log "ERROR: mount failed for host $desired."
  log_unreachable_once "$mode" "$desired"
  return 1
}

if ! acquire_lock; then
  exit 0
fi
trap 'release_lock' EXIT INT TERM

main
exit $?
