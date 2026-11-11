#!/bin/bash
# Install SMC temperature helper — run this in Terminal:
# bash /Users/bernd/.openclaw/workspace/projects/mac-monitor/client/install_smc_helper.sh

set -e

BINARY="/usr/local/bin/smc_helper"

echo "1. Copying binary to ${BINARY}..."
sudo cp /tmp/smc_helper "${BINARY}"
sudo chmod 755 "${BINARY}"

echo "2. Adding sudoers rule..."
echo "bernd ALL=(root) NOPASSWD: ${BINARY}" | sudo tee /etc/sudoers.d/smc-helper > /dev/null
sudo chmod 0440 /etc/sudoers.d/smc-helper

echo "3. Testing as root..."
sudo -n "${BINARY}"

echo ""
echo "Done! If you see temp readings above, we're good."