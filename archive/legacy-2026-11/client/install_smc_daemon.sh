#!/bin/bash
# Install SMC temperature daemon as a root LaunchDaemon
# Run in Terminal: bash /Users/bernd/.openclaw/workspace/projects/mac-monitor/client/install_smc_daemon.sh
set -e

BINARY="/usr/local/bin/smc_daemon"
LABEL="com.openclaw.smc-daemon"
PLIST="/Library/LaunchDaemons/${LABEL}.plist"
SOCKET="/tmp/smc_helper.sock"

echo "=== Installing SMC temperature daemon ==="

# 1. Copy binary
echo "1. Copying binary to ${BINARY}..."
sudo cp /tmp/smc_daemon "${BINARY}"
sudo chmod 755 "${BINARY}"

# 2. Create LaunchDaemon plist
echo "2. Creating LaunchDaemon plist..."
sudo tee "${PLIST}" > /dev/null << PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>${LABEL}</string>
    <key>ProgramArguments</key>
    <array>
        <string>${BINARY}</string>
    </array>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>UserName</key>
    <string>root</string>
</dict>
</plist>
PLIST

# 3. Load the daemon
echo "3. Loading LaunchDaemon..."
sudo launchctl bootstrap system "${PLIST}" 2>/dev/null || sudo launchctl load "${PLIST}" 2>/dev/null || true

# 4. Wait for socket to appear
sleep 1
if [ -S "${SOCKET}" ]; then
    echo "4. Socket found at ${SOCKET}"
else
    echo "4. Socket not found — daemon may need a moment..."
    sleep 2
fi

# 5. Test by connecting to the socket
echo "5. Testing temp readings..."
if [ -S "${SOCKET}" ]; then
    echo "" | nc -U "${SOCKET}" 2>/dev/null
else
    echo "   Socket not ready. Trying oneshot mode..."
    sudo -n "${BINARY}" --oneshot 2>&1
fi

echo ""
echo "=== Done ==="
echo "Daemon runs as root via LaunchDaemon, listens on ${SOCKET}"
echo "monitor.py can read temps via: nc -U ${SOCKET}"