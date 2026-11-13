#!/bin/sh
set -eu

LOCALKIT_DIR="/opt/localkit"
FILES_DIR="$LOCALKIT_DIR/files"

URLS="
http://tool.localkit.io/scripts/d4sh2/2.0.0/install/files/ca.crt
http://tool.localkit.io/scripts/d4sh2/2.0.0/install/files/ctrl-cert.sh
http://tool.localkit.io/scripts/d4sh2/2.0.0/install/files/vendor-ca.crt
http://tool.localkit.io/scripts/d4sh2/2.0.0/install/files/app_init.sh
http://tool.localkit.io/scripts/d4sh2/2.0.0/install/files/app_start.sh
http://tool.localkit.io/scripts/d4sh2/2.0.0/install/files/raptor.conf
http://tool.localkit.io/scripts/d4sh2/2.0.0/install/files/raptor-lib.sh
http://tool.localkit.io/scripts/d4sh2/2.0.0/install/files/rpd.sh
http://tool.localkit.io/scripts/d4sh2/2.0.0/install/files/rsd.sh
http://tool.localkit.io/scripts/d4sh2/2.0.0/install/files/raptor.sh
http://tool.localkit.io/scripts/d4sh2/2.0.0/install/files/rpd
http://tool.localkit.io/scripts/d4sh2/2.0.0/install/files/rsd
http://tool.localkit.io/scripts/d4sh2/2.0.0/install/files/rac
http://tool.localkit.io/scripts/d4sh2/2.0.0/install/files/ringdump
"

die() { echo "[FAIL] $*" >&2; exit 1; }
templated() { case "$1" in *'{{'*) return 1 ;; '') return 1 ;; *) return 0 ;; esac; }

echo ""
echo "=== device ==="
FW=$(sed -n 's/.*"firmwareVer":[[:space:]]*"\([^"]*\)".*/\1/p' /opt/version 2>/dev/null | sed -n 1p)
if [ -z "$FW" ]; then
    die "cannot read firmwareVer from /opt/version -- is /opt mounted?"
fi
echo "[ OK ] firmwareVer $FW"

echo ""
echo "=== download ==="
STAGE=/tmp/lk
rm -rf "$STAGE"
mkdir -p "$STAGE"

N=0
for url in $URLS; do
    [ -z "$url" ] && continue
    templated "$url" || { echo "[SKIP] unresolved placeholder: $url"; continue; }

    filename=$(basename "$url")
    echo "[DOWN] $filename"
    wget -q -O "$STAGE/$filename" "$url" \
        || die "download of $filename failed -- /opt untouched, nothing changed.
 Is the file present in this version's files/ directory? Binary uploads have to
 be added through the admin UI."
    N=$((N + 1))
done
echo "[ OK ] $N files staged in $STAGE"

echo ""
echo "=== ctrl certificate ==="
[ -s "$STAGE/ctrl-cert.sh" ] || die "ctrl-cert.sh was not staged"
[ -s "$STAGE/ca.crt" ]       || die "ca.crt was not staged"
[ -s "$STAGE/vendor-ca.crt" ] || die "vendor-ca.crt was not staged"
sh "$STAGE/ctrl-cert.sh" "$STAGE/ca.crt" "$STAGE/vendor-ca.crt" \
   "$STAGE/ctrl" /app/bin/ctrl \
    || { rm -f "$STAGE/ctrl"
         die "the certificate cannot be patched into this firmware's ctrl -- /opt
 untouched, nothing changed. The device keeps running what it runs now. Either
 this ctrl embeds a different CA than vendor-ca.crt, or our certificate does not
 fit the room it has."; }
[ -s "$STAGE/ctrl" ] || die "ctrl-cert.sh reported success but left no $STAGE/ctrl"
echo "[ OK ] ctrl patched"

MISSING=
for f in ca.crt ctrl-cert.sh vendor-ca.crt app_init.sh app_start.sh \
         raptor.conf raptor-lib.sh rpd.sh rsd.sh \
         raptor.sh rpd rsd rac ringdump; do
    [ -s "$STAGE/$f" ] || MISSING="$MISSING $f"
done
[ -z "$MISSING" ] \
    || die "incomplete download, missing:$MISSING
 /opt is untouched. Either the files are not in this version's files/ directory,
 or their {{files.*}} placeholders were not substituted."
echo "[ OK ] all required files present"

echo ""
echo "=== localkit ==="

for d in rsd rpd; do
    if pidof "$d" >/dev/null 2>&1; then
        echo "[STOP] $d"
        killall "$d" 2>/dev/null || true
    fi
done

rm -rf "$LOCALKIT_DIR" /opt/app_init.sh /opt/app_start-stock.sh
mkdir -p "$FILES_DIR" "$LOCALKIT_DIR/logs"
sync

OPTFREE=$(df /opt | awk 'NR==2{print $4}')
NEED=$(du -sk "$STAGE" 2>/dev/null | awk '{print $1}')
[ -n "$NEED" ] || NEED=5200          # no du on the device: rough estimate with reserve
echo "[INFO] /opt free: ${OPTFREE} KiB, needed ${NEED} KiB"
[ "$OPTFREE" -gt $((NEED + 256)) ] \
    || die "/opt has only ${OPTFREE} KiB free, ${NEED} KiB needed"

rm -f "$STAGE/ctrl-cert.sh" "$STAGE/vendor-ca.crt"

cp "$STAGE"/* "$FILES_DIR"/ || die "copy to $FILES_DIR failed"

for f in "$FILES_DIR"/*.sh "$FILES_DIR"/rpd "$FILES_DIR"/rsd \
         "$FILES_DIR"/rac "$FILES_DIR"/ringdump "$FILES_DIR"/ctrl; do
    [ -f "$f" ] && chmod 755 "$f"
done
for f in "$FILES_DIR"/ca.crt "$FILES_DIR"/raptor.conf; do
    [ -f "$f" ] && chmod 644 "$f"
done

echo "[MOVE] launcher and daemons into place"
cd "$FILES_DIR"
mv app_init.sh    /opt/app_init.sh
mv app_start.sh   "$LOCALKIT_DIR/app_start.sh"
sync
rm -rf "$STAGE"

echo ""
echo "=== prune ==="
KEEP_ROOT="files logs app_start.sh"
KEEP_FILES="ca.crt ctrl raptor.conf raptor-lib.sh rpd.sh rsd.sh raptor.sh rpd rsd rac ringdump"

prune() {
    _dir=$1
    _keep=$2
    for _p in "$_dir"/*; do
        [ -e "$_p" ] || continue
        _n=${_p##*/}
        case " $_keep " in
            *" $_n "*) continue ;;
        esac
        echo "[PRUN] ${_p#$LOCALKIT_DIR/}"
        rm -rf "$_p"
    done
}
prune "$LOCALKIT_DIR" "$KEEP_ROOT"
prune "$FILES_DIR" "$KEEP_FILES"
sync
echo "[ OK ] $LOCALKIT_DIR holds exactly the expected set"

echo "[INFO] /opt free: $(df /opt | awk 'NR==2{print $4}') KiB"

echo ""
echo "=== system api ==="
if pktool cfg dump /opt/user.conf /tmp/cfg 2>/dev/null && [ -s /tmp/cfg ]; then
    sed -i 's|\("apiServers":[[:space:]]*\)\[[^]]*\]|\1["http://api-eu.petkt.com/6/", "", ""]|' /tmp/cfg
    sed -i 's|"ipServers":.*|"ipServers":\t["", "", ""],|' /tmp/cfg
    sed -i 's|"dns":.*|"dns":\t["", "", ""],|' /tmp/cfg
    if pktool cfg save /tmp/cfg /opt/user.conf; then
        echo "[ OK ] api and dns entries reset"
    else
        echo "[WARN] pktool cfg save failed -- api and dns entries unchanged"
    fi
    rm -f /tmp/cfg
else
    echo "[WARN] cannot read /opt/user.conf -- api and dns entries unchanged"
fi
sync

echo ""
echo "Done. Files are in $FILES_DIR"
echo "After the reboot:"
echo "  rtsp://<ip>:8554/stream0 video + audio, with the ONVIF back channel"
echo "  $FILES_DIR/raptor.sh status"
echo "REBOOT"
reboot
