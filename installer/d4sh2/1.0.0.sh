#!/bin/sh

# Clean up for Update
rm -rf /opt/app_init.sh 
rm -rf /opt/localkit

set -eu

#install wget -qO- http://tool.localkit.io/scripts/d4sh/1.0.0/install | sh

# ── Config ────────────────────────────────────────────────────────────────────
LOCALKIT_DIR="/opt/localkit"
FILES_DIR="$LOCALKIT_DIR/files"
VERSION=895
FIRMWARE=$(sed -n 's/.*"firmwareVer":[[:space:]]*"\([^"]*\)".*/\1/p' /opt/version | head -1)

if [ "$FIRMWARE" != "$VERSION" ]; then
    echo "Firmware mismatch: got '$FIRMWARE', expected '$VERSION'" >&2
    exit 1
fi


# ── URLs (one per line) ───────────────────────────────────────────────────────
URLS="
{{files.ctrl_patched}}
http://tool.localkit.io/scripts/d4sh2/1.0.0/install/files/ca.crt
{{files.go2rtc}}
http://tool.localkit.io/scripts/d4sh2/1.0.0/install/files/go2rtc.yaml
http://tool.localkit.io/scripts/d4sh2/1.0.0/install/files/app_init.sh
http://tool.localkit.io/scripts/d4sh2/1.0.0/install/files/app_start.sh
{{files.watchdog}}
http://tool.localkit.io/scripts/d4sh2/1.0.0/install/files/watchdog.yaml

"

# ── Setup ─────────────────────────────────────────────────────────────────────
if [ ! -d "$LOCALKIT_DIR" ]; then
    echo "Creating LOCALKIT_DIR: $LOCALKIT_DIR"
    mkdir -p "$LOCALKIT_DIR"
fi

if [ ! -d "$LOCALKIT_DIR/logs" ]; then
    echo "Creating LOCALKIT_DIR: $LOCALKIT_DIR/logs"
    mkdir -p "$LOCALKIT_DIR/logs"
fi

if [ ! -d "$FILES_DIR" ]; then
    echo "Creating FILES_DIR: $FILES_DIR"
    mkdir -p "$FILES_DIR"
fi

# ── Download ──────────────────────────────────────────────────────────────────
echo ""
echo "Starting downloads to $FILES_DIR ..."
echo ""

for url in $URLS; do
    # Skip empty lines
    [ -z "$url" ] && continue

    filename="$(basename "$url")"
    dest="$FILES_DIR/$filename"

    echo "[DOWN] $filename"
    if wget -q -O "$dest" "$url"; then
        chmod +x "$dest"
        echo "[ OK ] $filename"
    else
        echo "[FAIL] $url" >&2
        rm -f "$dest"
    fi
done

echo "[MOVE] Scripts to Dir"

cd $FILES_DIR;
mv app_init.sh /opt/app_init.sh 
mv app_start.sh "$LOCALKIT_DIR/app_start.sh"
mv go2rtc "$LOCALKIT_DIR/go2rtc"
mv go2rtc.yaml "$LOCALKIT_DIR/go2rtc.yaml"
mv ctrl_patched "$LOCALKIT_DIR/ctrl"
mv watchdog "$LOCALKIT_DIR/watchdog"
mv watchdog.yaml "$LOCALKIT_DIR/watchdog.yaml"

echo ""
echo "Done. Files are located in: $FILES_DIR"

# First we should reset the api and dns data
pktool cfg dump /opt/user.conf /tmp/cfg
sed -i 's|\("apiServers":[[:space:]]*\)\[[^]]*\]|\1["http://api-eu.petkt.com/6/", "", ""]|' /tmp/cfg
sed -i 's|"ipServers":.*|"ipServers":\t["", "", ""],|' /tmp/cfg
sed -i 's|"dns":.*|"dns":\t["", "", ""],|' /tmp/cfg
pktool cfg save /tmp/cfg /opt/user.conf

echo ""
echo "Reset System API Service done"