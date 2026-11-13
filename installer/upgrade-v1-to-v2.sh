#!/bin/sh
set -eu

# Upgrade a PetKit W7H or YumShare Dual (D4SH) from LocalKit v1 (go2rtc +
# watchdog) or a partial v2 install to v2.0.0 (Raptor daemons + dynamically
# patched ctrl).
#
# Embedded target notes: runs under minimal BusyBox sh using Linux /proc;
# /opt is tiny flash storage, and /tmp is RAM (tmpfs, lost on power loss).
# Refuses ambiguous states (no lazy umount -l, no name-wide killall).

LOCALKIT_DIR=/opt/localkit
# Keep system dirs ahead of inherited paths, and retain firmware dirs (e.g., /app/bin for pktool).
sanitize_path() (
    base=/usr/sbin:/usr/bin:/sbin:/bin:/app/bin
    IFS=:
    set -f
    for dir in ${PATH:-}; do
        [ -n "$dir" ] || continue
        [ "$dir" = / ] || dir=${dir%/}
        case "$dir" in
            "$LOCALKIT_DIR"|"$LOCALKIT_DIR"/*|/tmp|/tmp/*) continue ;;
            /?*)
                case ":$base:" in *":$dir:"*) ;; *) base="$base:$dir" ;; esac
                ;;
        esac
    done
    printf '%s\n' "$base"
)
PATH=$(sanitize_path)
LC_ALL=C
export PATH LC_ALL

APP_INIT_FILE=/opt/app_init.sh
STOCK_APP_START_FILE=/opt/app_start-stock.sh
# Read-only stock app; v1 bind-mounts over it, and v2 patches its CA cert from it.
STOCK_CTRL=/app/bin/ctrl
TARGET_AUTO=auto
TARGET_W7H=w7h
TARGET_D4SH2=d4sh2
V2_BASE_URL=http://tool.localkit.io/scripts
# Exact SHA-256 digests of installer/<target>/2.0.0.sh; update together with those files.
W7H_MODELS='W7H'
W7H_SHA256=6e19b8b6c8a960bb55a492deeecd277ed7524cee42b3d4b683263010ab0f8c30
D4SH2_MODELS='D4SH'
D4SH2_SHA256=a948c4a17a05249665d88c9f4cd7fa617e9f62f8fb41c5a0e08728d9748b5ff4
TARGET=$TARGET_AUTO
TARGET_EXPLICIT=0
RESOLVED_TARGET=
V2_INSTALLER_URL=
V2_INSTALLER_SHA256=
LOCK_DIR=/tmp/localkit-upgrade.lock
WORK_DIR=
# Flash dir holding a hard link to the old ctrl inode during rollback.
CTRL_HOLD_DIR=
LOCAL_INSTALLER=
# Default off so /opt stays intact on flash until 2.0.0.sh stages /tmp/lk.
PRE_CLEAN=0
NO_REBOOT=0
USAGE="usage: sh upgrade-v1-to-v2.sh [--target $TARGET_AUTO|$TARGET_W7H|$TARGET_D4SH2] [--installer FILE] [--pre-clean] [--no-reboot]"
LOCK_HELD=0
PHASE=preflight
CHILD_PID=
PROTECTED_PIDS=" $$ "
STOP_GRACE_SECONDS=5
MAX_STOP_PASSES=3

die() { printf '[FAIL] %s\n' "$*" >&2; exit 1; }

# BusyBox builds vary by firmware; fail early if any required applet is missing.
require_commands() {
    missing_commands=
    for required_command in awk cat chmod cp cut df grep head id ln mkdir mktemp \
        mv pktool readlink reboot rm rmdir sed sha256sum sh sleep sort \
        sync tar timeout tr umount wget; do
        command -v "$required_command" >/dev/null 2>&1 || missing_commands="$missing_commands $required_command"
    done
    [ -z "$missing_commands" ] || die "missing required command:$missing_commands"
}

# Subshell bodies `( ... )` isolate helper variables since POSIX sh has no `local`.
# Strip `pid (comm) ` from /proc/<pid>/stat and return starttime ($20); ignores
# zombies (Z) and dead tasks (X). Pairing PID + starttime avoids killing a recycled PID.
process_identity() (
    [ -r "/proc/$1/stat" ] || exit 1
    awk '
        { sub(/^[0-9]+ \(.*\) /, "") }
        NF >= 20 && $1 != "Z" && $1 != "X" && $20 ~ /^[0-9]+$/ { print $20; ok = 1 }
        END { exit !ok }
    ' "/proc/$1/stat" 2>/dev/null
)

# Walk parent PIDs up to PID 1 (init) so stopping scripts never kills our own shell.
protect_ancestors() {
    ancestor=$$
    ancestor_count=0
    while [ "$ancestor" -gt 1 ]; do
        PROTECTED_PIDS="$PROTECTED_PIDS$ancestor "
        ancestor=$(awk '{ sub(/^[0-9]+ \(.*\) /, ""); print $2 }' "/proc/$ancestor/stat" 2>/dev/null) \
            || die "cannot identify session ancestors"
        case "$ancestor" in ''|*[!0-9]*) die "cannot identify session ancestors" ;; esac
        ancestor_count=$((ancestor_count + 1))
        [ "$ancestor_count" -lt 128 ] || die "invalid process ancestry"
    done
}

# Keep remote login daemons (so our session stays open), init, and ctrl
# (the vendor app that keeps Wi-Fi up until reboot).
is_protected_process() (
    case "$1" in ''|*[!0-9]*) exit 0 ;; esac
    [ "$1" -gt 1 ] || exit 0
    case " $PROTECTED_PIDS " in *" $1 "*) exit 0 ;; esac
    comm=$(cat "/proc/$1/comm" 2>/dev/null || :)
    case "$comm" in telnetd|dropbear|sshd|login|init|ctrl) exit 0 ;; esac
    exit 1
)

# Classify a PID by its binary or script path so we never kill unrelated
# system processes (e.g., the firmware's own /sbin/watchdog).
process_role() (
    is_protected_process "$1" && { echo keep; exit 0; }
    exe=$(readlink "/proc/$1/exe" 2>/dev/null) || exit 1
    # Linux appends " (deleted)" if the binary was unlinked while running.
    exe=${exe% (deleted)}
    case "$exe" in
        "$STOCK_CTRL"|"$LOCALKIT_DIR/ctrl"|"$LOCALKIT_DIR/files/ctrl") echo keep; exit 0 ;;
        "$LOCALKIT_DIR/watchdog"|"$LOCALKIT_DIR/files/watchdog") echo supervisor; exit 0 ;;
        "$LOCALKIT_DIR/"*) echo daemon; exit 0 ;;
    esac
    # For shells, inspect the executed script path only (ignore strings in grep/editors).
    case "${exe##*/}" in sh|ash|bash|dash|busybox) ;; *) exit 1 ;; esac
    args=$(tr '\000' '\n' < "/proc/$1/cmdline" 2>/dev/null) || exit 1
    script=$(printf '%s\n' "$args" | awk '
        NR == 1 { sub(/^.*\//, ""); if ($0 == "busybox") { box = 1; next }
                  if ($0 !~ /^(sh|ash|bash|dash)$/) exit; shell = 1; next }
        box && NR == 2 { if ($0 !~ /^(sh|ash)$/) exit; shell = 1; next }
        shell && $0 == "--" { next }
        shell && /^-/ { if ($0 !~ /^-[eux]+$/) exit; next }
        shell { print; exit }
    ')
    [ -n "$script" ] || exit 1
    case "$script" in
        /*) ;;
        *) cwd=$(readlink "/proc/$1/cwd" 2>/dev/null) || exit 1; script="$cwd/$script" ;;
    esac
    script=$(readlink -f "$script" 2>/dev/null) || exit 1
    case "$script" in
        /opt/app_init.sh|"$LOCALKIT_DIR/app_start.sh"|"$LOCALKIT_DIR/watchdog"|"$LOCALKIT_DIR/files/raptor.sh") echo supervisor ;;
        "$LOCALKIT_DIR/"*.sh) echo daemon ;;
        *) exit 1 ;;
    esac
)

localkit_pids() (
    filter=${1:-all}
    for proc in /proc/[0-9]*; do
        [ -d "$proc" ] || continue
        pid=${proc##*/}
        identity=$(process_identity "$pid") || continue
        role=$(process_role "$pid") || continue
        case "$role" in supervisor|daemon) ;; *) continue ;; esac
        [ "$filter" = all ] || [ "$filter" = "$role" ] || continue
        printf '%s:%s\n' "$pid" "$identity"
    done
)

token_alive() (
    pid=${1%%:*}
    old_identity=${1#*:}
    identity=$(process_identity "$pid") || exit 1
    [ "$identity" = "$old_identity" ]
)

signal_tokens() (
    signal=$1
    shift
    for token in "$@"; do
        token_alive "$token" || continue
        role=$(process_role "${token%%:*}") || continue
        case "$role" in supervisor|daemon) ;; *) continue ;; esac
        # Linux has no portable shell pidfd API. Recheck immediately before kill.
        token_alive "$token" || continue
        kill "-$signal" "${token%%:*}" 2>/dev/null || :
    done
)

wait_for_exit() (
    waited=0
    while :; do
        alive=0
        for token in "$@"; do token_alive "$token" && alive=1; done
        [ "$alive" -eq 0 ] && exit 0
        [ "$waited" -lt "$STOP_GRACE_SECONDS" ] || exit 1
        sleep 1
        waited=$((waited + 1))
    done
)

stop_group() (
    tokens=$(localkit_pids "$1")
    [ -n "$tokens" ] || exit 0
    printf '[STOP] %s\n' "$tokens"
    # Tokens are generated numeric PID:starttime pairs, with no pathname data.
    # shellcheck disable=SC2086
    signal_tokens TERM $tokens
    # shellcheck disable=SC2086
    if ! wait_for_exit $tokens; then
        # shellcheck disable=SC2086
        signal_tokens KILL $tokens
        # shellcheck disable=SC2086
        wait_for_exit $tokens || die "LocalKit processes did not exit; files retained"
    fi
)

# Stop supervisors (watchdog, app_start.sh, and raptor.sh) before daemons so they
# cannot respawn children mid-shutdown.
stop_all_daemons() {
    PHASE=stopping
    stop_pass=0
    while [ "$stop_pass" -lt "$MAX_STOP_PASSES" ]; do
        stop_pass=$((stop_pass + 1))
        stop_group supervisor
        stop_group all
        remaining=$(localkit_pids all)
        [ -n "$remaining" ] || return 0
    done
    die "LocalKit processes keep respawning; files retained"
}

# Reject symlinks on /opt, /tmp, and key LocalKit paths before modifying files.
assert_install_paths() {
    for checked_dir in /opt /tmp "$LOCALKIT_DIR"; do
        [ ! -L "$checked_dir" ] || die "refusing symlinked directory $checked_dir"
        if [ -e "$checked_dir" ]; then
            [ -d "$checked_dir" ] || die "not a directory: $checked_dir"
        fi
    done
    for checked_file in /opt/app_init.sh /opt/app_start-stock.sh /opt/user.conf /opt/version; do
        [ ! -L "$checked_file" ] || die "refusing symlinked file $checked_file"
        if [ -e "$checked_file" ]; then
            [ -f "$checked_file" ] || die "not a regular file: $checked_file"
        fi
    done
}

# /proc/self/mountinfo exposes the bind source path inside /opt (/proc/mounts only
# shows the block device), so we can safely unmount only LocalKit bind targets
# (e.g., /app/bin/ctrl and CA certs) deepest-path first. Refuse unknown mounts.
mount_plan() (
    [ -r /proc/self/mountinfo ] && [ -s /proc/self/mountinfo ] || {
        # procfs files normally report size zero; test content separately.
        [ -r /proc/self/mountinfo ] || die "cannot read /proc/self/mountinfo"
    }
    awk -v lk="$LOCALKIT_DIR" -v ctrl="$STOCK_CTRL" '
        function beneath(p, base) { return p == base || index(p, base "/") == 1 }
        function fail(message) { print "[FAIL] " message > "/dev/stderr"; bad = 1 }
        function known(p) { return p == ctrl || p == "/app/bin/ca.crt" || p == "/app/etc/ca.crt" || p == "/etc/ca.crt" || p == "/app/script/default.script" }
        {
            separator = 0
            for (i = 7; i <= NF; i++) if ($i == "-") { separator = i; break }
            if ($1 !~ /^[0-9]+$/ || $2 !~ /^[0-9]+$/ || $4 !~ /^\// || $5 !~ /^\// || !separator || NF < separator + 3) {
                fail("malformed mountinfo"); next
            }
            n++; dev[n] = $3; root[n] = $4; point[n] = $5
            if ($5 == "/" || beneath("/opt", $5)) {
                if (length($5) >= length(optpoint)) { optpoint = $5; optroot = $4; optdev = $3 }
            }
        }
        END {
            if (!n || !optpoint) fail("cannot identify filesystem containing /opt")
            relative = optpoint == "/" ? lk : substr(lk, length(optpoint) + 1)
            source = (optroot == "/" ? "" : optroot) relative
            for (i = 1; i <= n; i++) {
                p = point[i]
                owned = dev[i] == optdev && beneath(root[i], source)
                if (beneath(p, lk) || beneath(p, "/opt/app_init.sh") || beneath(p, "/opt/app_start-stock.sh") || beneath(p, "/opt/user.conf") || beneath(p, "/opt/version")) {
                    fail("mount inside installation/backup paths: " p); continue
                }
                if (known(p) || owned) {
                    if (!known(p) || !owned) { fail("unrecognized bind mount: " p); continue }
                    if (p ~ /\\/ || root[i] ~ /\\/) { fail("escaped bind path requires manual unmount: " p); continue }
                    points[p] = 1
                }
            }
            if (bad) exit 1
            for (p in points) print length(p), p
        }
    ' /proc/self/mountinfo > "$WORK_DIR/mount-plan.unsorted" || exit 1
    sort -rn "$WORK_DIR/mount-plan.unsorted" > "$WORK_DIR/mount-plan.sorted" || exit 1
    cut -d " " -f 2- "$WORK_DIR/mount-plan.sorted"
)

is_mount_point() (
    [ -r /proc/self/mountinfo ] || exit 2
    awk -v point="$1" '$5 == point { found = 1 } END { exit !found }' /proc/self/mountinfo
)

release_mount() (
    point=$1
    attempts=0
    while is_mount_point "$point"; do
        attempts=$((attempts + 1))
        [ "$attempts" -le 32 ] || die "too many stacked mounts on $point"
        # Revalidate every layer before unmounting a stack; the next layer may
        # belong to something other than LocalKit.
        plan=$(mount_plan) || exit 1
        printf '%s\n' "$plan" | awk -v point="$point" '$0 == point { found = 1 } END { exit !found }' || die "mount ownership changed: $point"
        printf '[UMNT] %s\n' "$point"
        umount "$point" || die "cannot unmount $point; close users of the mount and retry"
    done
)

release_all_mounts() {
    PHASE=unmounting
    mount_pass=0
    while :; do
        mount_plan > "$WORK_DIR/mount-plan" || die "unsafe mount layout; files retained"
        [ -s "$WORK_DIR/mount-plan" ] || break
        mount_pass=$((mount_pass + 1))
        [ "$mount_pass" -le 3 ] || die "mounts keep reappearing; files retained"
        while IFS= read -r mount_target; do
            release_mount "$mount_target" || die "mount cleanup failed; files retained"
        done < "$WORK_DIR/mount-plan"
    done
    sync
}

assert_ready_to_apply() {
    assert_install_paths
    mount_plan > "$WORK_DIR/mount-plan" || die "unsafe mount layout before installation"
    [ ! -s "$WORK_DIR/mount-plan" ] || die "LocalKit bind mounts reappeared"
    remaining=$(localkit_pids all)
    [ -z "$remaining" ] || die "LocalKit processes restarted; files retained"
}

# Snapshot existing /opt LocalKit files to /tmp (RAM) before stopping services.
backup_installation() {
    set -- version
    for backup_entry in localkit app_init.sh app_start-stock.sh user.conf; do
        if [ -e "/opt/$backup_entry" ]; then set -- "$@" "$backup_entry"; fi
    done
    printf '[BACKUP] %s/previous-installation.tar\n' "$WORK_DIR"
    tar -cf "$WORK_DIR/previous-installation.tar" -C /opt "$@" || die "backup failed (possibly insufficient /tmp space); files retained"
    tar -tf "$WORK_DIR/previous-installation.tar" > "$WORK_DIR/backup-list" || die "cannot verify backup archive"
    sha256sum "$WORK_DIR/previous-installation.tar" > "$WORK_DIR/backup.sha256" || die "cannot checksum backup"
    sync
}

# Hard-link (ln) the old ctrl inode on /opt flash so rollback reuses the same
# blocks without copying a second binary while ctrl is running. The tar still
# keeps a full copy for manual recovery if needed.
retain_ctrl_files() {
    printf '%s\n' version user.conf > "$WORK_DIR/restore-excludes"
    for ctrl_relative in localkit/ctrl localkit/files/ctrl; do
        ctrl_path="/opt/$ctrl_relative"
        [ -e "$ctrl_path" ] || [ -L "$ctrl_path" ] || continue
        [ -f "$ctrl_path" ] && [ ! -L "$ctrl_path" ] && [ "$(readlink -f "$ctrl_path")" = "$ctrl_path" ] \
            || die "ctrl must be a regular file without symlink components: $ctrl_path"
        if [ -z "$CTRL_HOLD_DIR" ]; then
            CTRL_HOLD_DIR=$(mktemp -d /opt/.localkit-rollback.XXXXXX) || die "cannot retain ctrl inode for recovery"
            printf '%s\n' "$CTRL_HOLD_DIR" > "$WORK_DIR/ctrl-hold-dir"
        fi
        case "$ctrl_relative" in localkit/ctrl) ctrl_slot=v1 ;; *) ctrl_slot=v2 ;; esac
        ln "$ctrl_path" "$CTRL_HOLD_DIR/$ctrl_slot" || die "cannot retain ctrl inode for recovery"
        printf '%s\n' "$ctrl_relative" >> "$WORK_DIR/restore-excludes"
    done
    # Verify this tar build supports the exclusion option before stopping services.
    tar -tf "$WORK_DIR/previous-installation.tar" -X "$WORK_DIR/restore-excludes" > "$WORK_DIR/restore-list" \
        || die "tar does not support safe restoration exclusions"
    sync
}

remove_install_files() {
    rm -rf "$LOCALKIT_DIR" || return 1
    rm -f "$APP_INIT_FILE" "$STOCK_APP_START_FILE" || return 1
}

# Skip pre-clean by default: 2.0.0.sh deletes /opt only after /tmp staging
# succeeds, keeping v1 intact on flash if power drops during download or patch.
pre_clean_installation() {
    if [ "$PRE_CLEAN" -ne 1 ]; then
        printf '[SKIP] /opt pre-clean skipped; 2.0.0.sh removes old files after /tmp staging (pass --pre-clean to force)\n'
        return 0
    fi
    printf '[CLEAN] removing %s, %s, and %s\n' "$LOCALKIT_DIR" "$APP_INIT_FILE" "$STOCK_APP_START_FILE"
    remove_install_files || return 1
    sync
}

restore_installation() (
    printf '[RECOVER] restoring previous files from %s\n' "$WORK_DIR" >&2
    assert_install_paths
    plan=$(mount_plan) || exit 1
    [ -z "$plan" ] || die "mounts reappeared; refusing automatic restoration"
    sha256sum -c "$WORK_DIR/backup.sha256" || exit 1
    remove_install_files || exit 1
    tar -xf "$WORK_DIR/previous-installation.tar" -C /opt -X "$WORK_DIR/restore-excludes" || exit 1
    if [ -n "$CTRL_HOLD_DIR" ]; then
        for slot in v1 v2; do
            [ -f "$CTRL_HOLD_DIR/$slot" ] || continue
            case "$slot" in v1) destination="$LOCALKIT_DIR/ctrl" ;; *) destination="$LOCALKIT_DIR/files/ctrl" ;; esac
            mkdir -p "${destination%/*}" || exit 1
            ln "$CTRL_HOLD_DIR/$slot" "$destination" || exit 1
        done
    fi
    sync
)

release_ctrl_hold() {
    [ -n "$CTRL_HOLD_DIR" ] || return 0
    rm -f "$CTRL_HOLD_DIR/v1" "$CTRL_HOLD_DIR/v2"
    rmdir "$CTRL_HOLD_DIR" || :
    CTRL_HOLD_DIR=
}

# Runs on any exit (trap 0): waits for any active installer child, rolls back
# /opt if failure occurred mid-apply, removes rollback hard links, and unlocks.
finish() {
    finish_status=$?
    trap - 0
    trap '' HUP INT TERM
    recovery_failed=0
    if [ -n "$CHILD_PID" ]; then
        # Do not restore while the installer might still be writing. A signal
        # handler marks interrupted work but waits for this bounded child phase.
        wait "$CHILD_PID" 2>/dev/null || :
        CHILD_PID=
    fi
    if [ "$PHASE" = applying ]; then
        if restore_installation; then
            printf '[RECOVER] previous files restored; services remain stopped. Reboot to restart them.\n' >&2
        else
            printf '[FAIL] automatic restoration failed; keep the session open and recover from %s/previous-installation.tar\n' "$WORK_DIR" >&2
            recovery_failed=1
        fi
        [ "$finish_status" -ne 0 ] || finish_status=1
    fi
    if [ "$recovery_failed" -eq 0 ]; then
        release_ctrl_hold
    fi
    case "$PHASE" in
        preflight|complete)
            if [ -n "$WORK_DIR" ]; then rm -rf "$WORK_DIR"; fi
            ;;
        *) printf '[RECOVERY] phase=%s; files and diagnostics retained in %s. Do not remove this directory until recovery succeeds.\n' "$PHASE" "$WORK_DIR" >&2 ;;
    esac
    if [ "$LOCK_HELD" -eq 1 ] && [ "$recovery_failed" -eq 0 ]; then
        rm -f "$LOCK_DIR/owner"
        rmdir "$LOCK_DIR" || :
    fi
    exit "$finish_status"
}

interrupted() {
    printf '\n[ABORT] interrupted; waiting for any active installer phase before recovery\n' >&2
    exit "$1"
}

# Stub reboot on PATH so 2.0.0.sh returns control before the real reboot runs.
create_reboot_wrapper() {
    mkdir -p "$WORK_DIR/bin" || die "cannot create wrapper bin directory"
    cat > "$WORK_DIR/bin/reboot" <<EOF
#!/bin/sh
: > "$WORK_DIR/reboot-requested"
exit 0
EOF
    chmod 755 "$WORK_DIR/bin/reboot" || die "cannot mark reboot wrapper executable"
}

run_installer() {
    # Intercept reboot from unmodified 2.0.0.sh so rollback artifacts and locks
    # are cleaned up before the real reboot runs.
    create_reboot_wrapper
    # No exec: keep the lock, backup, and recovery traps through the handoff.
    PATH="$WORK_DIR/bin:$PATH" sh "$WORK_DIR/install.sh" &
    CHILD_PID=$!
    installer_status=0
    wait "$CHILD_PID" || installer_status=$?
    CHILD_PID=
    return "$installer_status"
}

parse_device_model() (
    awk '
        { rest = $0
          while (match(rest, /"name"[[:space:]]*:/)) {
              count++
              rest = substr(rest, RSTART + RLENGTH)
              if (rest ~ /^[[:space:]]*"[^"]*"[[:space:]]*([},]|$)/) {
                  value = rest
                  sub(/^[[:space:]]*"/, "", value)
                  sub(/".*/, "", value)
                  model = value
              } else {
                  bad = 1
              }
          }
        }
        END {
            if (!bad && count == 1 && model != "") print model
            else if (count > 1) print "ambiguous"
        }
    ' "$1"
)

# Map a /opt/dev.conf model name to its installer target slug.
target_for_model() (
    [ -n "${1:-}" ] || exit 1
    case " $W7H_MODELS " in *" $1 "*) printf '%s\n' "$TARGET_W7H"; exit 0 ;; esac
    case " $D4SH2_MODELS " in *" $1 "*) printf '%s\n' "$TARGET_D4SH2"; exit 0 ;; esac
    exit 1
)

# Return the pinned 2.0.0.sh SHA-256 digest for a resolved target slug.
target_sha256() (
    case "${1:-}" in
        "$TARGET_W7H") printf '%s\n' "$W7H_SHA256" ;;
        "$TARGET_D4SH2") printf '%s\n' "$D4SH2_SHA256" ;;
        *) exit 1 ;;
    esac
)

# Accept the default auto mode or an explicit device target slug.
is_valid_target() {
    case "$1" in
        "$TARGET_AUTO"|"$TARGET_W7H"|"$TARGET_D4SH2") return 0 ;;
        *) return 1 ;;
    esac
}

# Resolve hardware model against TARGET (auto, w7h, or d4sh2) and configure
# RESOLVED_TARGET, V2_INSTALLER_URL, and V2_INSTALLER_SHA256.
resolve_target() {
    detected_model=${1:-}
    detected_label=${detected_model:-unknown}
    model_target=$(target_for_model "$detected_model") || model_target=

    if [ "$TARGET" = "$TARGET_AUTO" ]; then
        [ -n "$model_target" ] \
            || die "cannot auto-detect target from hardware model: $detected_label (pass --target $TARGET_W7H|$TARGET_D4SH2 to override)"
        resolved_target=$model_target
    else
        if [ -n "$model_target" ] && [ "$model_target" != "$TARGET" ]; then
            die "hardware model $detected_label conflicts with --target $TARGET"
        fi
        if [ -z "$model_target" ]; then
            printf '[WARN] unverified hardware model %s with explicit --target %s; continuing\n' "$detected_label" "$TARGET" >&2
        fi
        resolved_target=$TARGET
    fi

    resolved_sha256=$(target_sha256 "$resolved_target") || die "unknown target: $resolved_target"
    RESOLVED_TARGET=$resolved_target
    V2_INSTALLER_URL="$V2_BASE_URL/$resolved_target/2.0.0/install"
    V2_INSTALLER_SHA256=$resolved_sha256
}

# Verify PetKit firmware files, hardware model via pktool, and writable flash.
preflight_checks() {
    verify_host_prereqs
    assert_install_paths
    [ -d /opt ] || die "missing /opt"
    [ -f /opt/version ] && [ -f /opt/dev.conf ] || die "missing PetKit firmware/device configuration"
    [ -r /proc/self/mountinfo ] || die "Linux mountinfo is required"
    [ -f "$STOCK_CTRL" ] || die "missing stock ctrl path"
    firmware=$(sed -n 's/.*"firmwareVer"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' /opt/version | head -n 1)
    [ -n "$firmware" ] || die "cannot read firmwareVer"
    timeout 15 pktool cfg dump /opt/dev.conf "$WORK_DIR/device.json" >/dev/null 2>&1 || die "cannot identify hardware with pktool"
    model=$(parse_device_model "$WORK_DIR/device.json")
    resolve_target "$model"
    rm -f "$WORK_DIR/device.json"
    # mktemp creates exclusively; never clobber an existing write-probe file.
    probe=$(mktemp /opt/.localkit-write.XXXXXX) || die "/opt is not writable"
    rm -f "$probe"
    mount_plan > "$WORK_DIR/mount-plan" || die "unsafe mount layout"
    protect_ancestors
    printf '[ OK ] %s firmware %s (target %s); preflight passed\n' "${model:-unknown}" "$firmware" "$RESOLVED_TARGET"
}

fetch_v2_installer() {
    [ -n "$RESOLVED_TARGET" ] && [ -n "$V2_INSTALLER_URL" ] && [ -n "$V2_INSTALLER_SHA256" ] \
        || die "target not resolved"
    if [ -n "$LOCAL_INSTALLER" ]; then
        [ -f "$LOCAL_INSTALLER" ] || die "installer file not found: $LOCAL_INSTALLER"
        cp "$LOCAL_INSTALLER" "$WORK_DIR/install.sh" || die "cannot stage local installer"
    else
        printf '[DOWN] %s\n' "$V2_INSTALLER_URL"
        timeout 120 wget -q -O "$WORK_DIR/install.sh" "$V2_INSTALLER_URL" || die "installer download failed; installation unchanged"
    fi
    # A checksum embedded in this trusted upgrade script rejects wrong versions,
    # syntactically valid truncation, error pages, and modified entrypoints.
    digest=$(sha256sum "$WORK_DIR/install.sh")
    digest=${digest%% *}
    [ "$digest" = "$V2_INSTALLER_SHA256" ] || die "installer checksum mismatch for target $RESOLVED_TARGET; use the matching $RESOLVED_TARGET/2.0.0.sh via --installer FILE or update the pinned checksum"
    sh -n "$WORK_DIR/install.sh" || die "installer has invalid shell syntax"
}

parse_args() {
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --target)
                [ "$#" -ge 2 ] || die "$USAGE"
                [ "$TARGET_EXPLICIT" -eq 0 ] || die "$USAGE"
                is_valid_target "$2" || die "$USAGE"
                TARGET=$2
                TARGET_EXPLICIT=1
                shift 2
                ;;
            --installer)
                [ "$#" -ge 2 ] || die "$USAGE"
                [ -z "$LOCAL_INSTALLER" ] || die "$USAGE"
                [ -f "$2" ] || die "installer file not found: $2"
                LOCAL_INSTALLER=$(readlink -f "$2") || die "cannot resolve installer path: $2"
                shift 2
                ;;
            --pre-clean)
                PRE_CLEAN=1
                shift
                ;;
            --no-reboot)
                NO_REBOOT=1
                shift
                ;;
            -h|--help)
                printf '%s\n' "$USAGE"
                exit 0
                ;;
            *)
                die "$USAGE"
                ;;
        esac
    done
}

verify_host_prereqs() {
    [ "$(id -u)" = 0 ] || die "run this script as root"
    umask 077
    require_commands
    [ -d /tmp ] && [ ! -L /tmp ] || die "/tmp must be a real directory"
}

acquire_lock() {
    # Atomic lock via mkdir; never auto-steal in case another installer is running.
    mkdir "$LOCK_DIR" 2>/dev/null || die "upgrade lock exists: $LOCK_DIR; inspect its owner before retrying"
    LOCK_HELD=1
    trap finish 0
    trap 'interrupted 129' HUP
    trap 'interrupted 130' INT
    trap 'interrupted 143' TERM
    printf '%s\n' "$$" > "$LOCK_DIR/owner"
}

create_workspace() {
    WORK_DIR=$(mktemp -d /tmp/localkit-upgrade.XXXXXX) || die "cannot create private workspace"
    chmod 700 "$WORK_DIR"
    cd "$WORK_DIR"
}

# Run the v2 installer with automatic /opt rollback armed (PHASE=applying).
apply_v2_installer() {
    assert_ready_to_apply
    PHASE=applying
    pre_clean_installation || die "pre-clean failed; restoring previous files"
    run_installer || die "v2 installation failed; restoring previous files"
    PHASE=installed
}

# Remove rollback hard links, flush flash writes, and reboot unless skipped.
finalize_upgrade() {
    release_ctrl_hold
    sync
    if [ "$NO_REBOOT" -eq 1 ]; then
        printf '[SKIP] reboot skipped (--no-reboot); reboot manually to start LocalKit 2.0.0\n'
        printf '[ OK ] LocalKit 2.0.0 installed\n'
        PHASE=complete
        return 0
    fi
    printf '[ OK ] LocalKit 2.0.0 installed; rebooting\n'
    # Keep the recovery archive if reboot cannot be requested.
    reboot || die "installation succeeded but reboot failed; reboot manually; backup retained"
    PHASE=complete
}

main() {
    # Parse CLI options (--target, --installer, --pre-clean, --no-reboot, and --help) first.
    parse_args "$@"

    # Check root privileges, required BusyBox applets, and a real /tmp directory.
    verify_host_prereqs

    # Hold an exclusive upgrade lock and arm exit/signal traps for safe recovery.
    acquire_lock

    # Enter a private /tmp workspace so deleting /opt/localkit never removes cwd.
    create_workspace

    # Confirm PetKit firmware, resolve device target, writable /opt, safe mounts, and PIDs.
    preflight_checks

    # Download (or stage local) 2.0.0.sh, check its pinned SHA-256, and run sh -n.
    fetch_v2_installer

    # Archive existing /opt LocalKit files into RAM (/tmp) before stopping services.
    backup_installation

    # Hard-link running ctrl binaries on flash so rollback will not double flash usage.
    retain_ctrl_files

    # Stop supervisors (watchdog, app_start.sh, and raptor.sh) and child daemons.
    stop_all_daemons

    # Unmount LocalKit bind mounts (/app/bin/ctrl and CA certs) deepest-path first.
    release_all_mounts

    # Re-verify state, arm /opt rollback, optionally pre-clean, and run 2.0.0.sh.
    apply_v2_installer

    # Delete rollback hard links, flush /opt flash writes, and reboot unless --no-reboot.
    finalize_upgrade
}

# main is fully parsed before invocation. This works for a saved script,
# symlink, or pipe even if its original file is removed during apply.
main "$@"
