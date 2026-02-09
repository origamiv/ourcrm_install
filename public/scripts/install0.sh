#!/usr/bin/env bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

LOG="[install]"

need_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    echo "$LOG Run as root (sudo)." >&2
    exit 1
  fi
}

apt_install_min() {
  apt-get update -y
  apt-get install -y --no-install-recommends "$@"
}

apt_install_full() {
  apt-get update -y
  apt-get install -y "$@"
}

apt_install_optional() {
  # installs packages if they exist; never fails the script
  apt-get update -y
  local pkgs=()
  for p in "$@"; do
    if apt-cache show "$p" >/dev/null 2>&1; then
      pkgs+=("$p")
    else
      echo "$LOG Optional package not found (skip): $p"
    fi
  done
  if (( ${#pkgs[@]} )); then
    apt-get install -y "${pkgs[@]}"
  fi
}

ensure_line() {
  local line="$1" file="$2"
  touch "$file"
  grep -qF "$line" "$file" || echo "$line" >> "$file"
}

set_user_password_with_fallback() {
  local user="$1"
  local p1="Ori9030404"
  local p2="Ori_9030404"

  echo "$LOG Setting password for ${user}..."
  if echo "${user}:${p1}" | chpasswd; then
    echo "${p1}"
    return 0
  fi

  echo "$LOG First password rejected by policy, trying fallback..."
  if echo "${user}:${p2}" | chpasswd; then
    echo "${p2}"
    return 0
  fi

  echo "$LOG ERROR: Could not set password (both variants rejected)." >&2
  return 1
}

need_root

MAIN_USER="origamiv"
MAIN_HOME="/home/${MAIN_USER}"

# VNC password must be exactly this (VNC-compatible, <=8 is best)
VNC_PASS="9030404"

# ------------------------------------------------------------
# Enable Universe (some desktop packages/themes live there)
# ------------------------------------------------------------
echo "$LOG Enabling Ubuntu repositories..."
apt_install_min software-properties-common
add-apt-repository -y universe || true
apt-get update -y

# ------------------------------------------------------------
# Base packages + SSH + tools
# ------------------------------------------------------------
echo "$LOG Installing base packages..."
apt_install_min \
  ca-certificates curl wget gnupg lsb-release \
  git make mc \
  openssh-server openssh-client \
  e2fsprogs \
  net-tools unzip zip xz-utils \
  fontconfig \
  ufw

command -v resize2fs >/dev/null && echo "$LOG resize2fs OK"

# ------------------------------------------------------------
# Create user origamiv + sudo
# ------------------------------------------------------------
if id -u "${MAIN_USER}" >/dev/null 2>&1; then
  echo "$LOG User ${MAIN_USER} already exists"
else
  echo "$LOG Creating user ${MAIN_USER}..."
  adduser --disabled-password --gecos "" "${MAIN_USER}"
fi

usermod -aG sudo "${MAIN_USER}"

FINAL_PASS="$(set_user_password_with_fallback "${MAIN_USER}")"
echo "$LOG System password set for ${MAIN_USER}"

# ------------------------------------------------------------
# System tuning (JetBrains / big projects)
# ------------------------------------------------------------
echo "$LOG Tuning inotify limits..."
cat > /etc/sysctl.d/99-jetbrains.conf <<'EOF'
fs.inotify.max_user_watches=1048576
fs.inotify.max_user_instances=8192
fs.inotify.max_queued_events=32768
EOF
sysctl --system >/dev/null

echo "$LOG Ensuring swap (4G if none)..."
if ! swapon --show | grep -q .; then
  SWAPFILE="/swapfile"
  fallocate -l 4G "$SWAPFILE" || dd if=/dev/zero of="$SWAPFILE" bs=1M count=4096
  chmod 600 "$SWAPFILE"
  mkswap "$SWAPFILE" >/dev/null
  swapon "$SWAPFILE"
  ensure_line "$SWAPFILE none swap sw 0 0" /etc/fstab
fi

# ------------------------------------------------------------
# SSH config for JetBrains Gateway Remote Development
# ------------------------------------------------------------
echo "$LOG Configuring SSH for JetBrains Gateway..."
SSHD_CONFIG="/etc/ssh/sshd_config"

if ! grep -qE '^\s*Subsystem\s+sftp\s+' "$SSHD_CONFIG"; then
  echo "Subsystem sftp internal-sftp" >> "$SSHD_CONFIG"
else
  sed -i 's/^\s*Subsystem\s\+sftp\s\+.*/Subsystem sftp internal-sftp/g' "$SSHD_CONFIG"
fi

if grep -qE '^\s*AllowTcpForwarding\s+' "$SSHD_CONFIG"; then
  sed -i 's/^\s*AllowTcpForwarding\s\+.*/AllowTcpForwarding yes/g' "$SSHD_CONFIG"
else
  echo "AllowTcpForwarding yes" >> "$SSHD_CONFIG"
fi

systemctl enable ssh
systemctl restart ssh

# ------------------------------------------------------------
# Docker (official repo) + compose plugin
# ------------------------------------------------------------
echo "$LOG Installing Docker..."
install -m 0755 -d /etc/apt/keyrings
if [[ ! -f /etc/apt/keyrings/docker.gpg ]]; then
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg
fi

UBUNTU_CODENAME="$(. /etc/os-release && echo "${VERSION_CODENAME}")"
echo \
"deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
https://download.docker.com/linux/ubuntu ${UBUNTU_CODENAME} stable" \
> /etc/apt/sources.list.d/docker.list

apt-get update -y
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

systemctl enable docker
systemctl start docker

usermod -aG docker "${MAIN_USER}"

# ------------------------------------------------------------
# GUI: FULL Cinnamon + GNOME base + LightDM
# NOTE: removed mint-themes / mint-x-icons (not in Ubuntu repos)
# Added safe alternatives: gnome-themes-extra, adwaita-icon-theme-full, papirus-icon-theme
# ------------------------------------------------------------
echo "$LOG Installing FULL Cinnamon + GNOME base + LightDM..."
apt_install_full \
  ubuntu-desktop-minimal \
  cinnamon-desktop-environment cinnamon \
  cinnamon-control-center \
  nemo nemo-fileroller \
  lightdm lightdm-gtk-greeter \
  gnome-themes-extra \
  adwaita-icon-theme-full \
  papirus-icon-theme

# If you want extra icon packs when available (won't fail if missing)
apt_install_optional \
  yaru-theme-icon \
  yaru-theme-gtk \
  breeze-icon-theme

echo "$LOG Setting LightDM as default display manager..."
echo "lightdm shared/default-x-display-manager select lightdm" | debconf-set-selections
dpkg-reconfigure lightdm || true

echo "$LOG Setting Cinnamon as default session for ${MAIN_USER}..."
install -d -m 0755 /var/lib/AccountsService/users || true
USER_ACCOUNTS_FILE="/var/lib/AccountsService/users/${MAIN_USER}"
touch "$USER_ACCOUNTS_FILE"
ensure_line "[User]" "$USER_ACCOUNTS_FILE"
if grep -qE '^\s*XSession=' "$USER_ACCOUNTS_FILE"; then
  sed -i 's/^\s*XSession=.*/XSession=cinnamon/g' "$USER_ACCOUNTS_FILE"
else
  echo "XSession=cinnamon" >> "$USER_ACCOUNTS_FILE"
fi
chown root:root "$USER_ACCOUNTS_FILE"
chmod 0644 "$USER_ACCOUNTS_FILE"

# ------------------------------------------------------------
# XRDP + TigerVNC: ONE shared session
# - TigerVNC provides the desktop (:1 => 5901)
# - XRDP connects to it via libvnc (Xvnc backend)
# ------------------------------------------------------------
echo "$LOG Installing XRDP + TigerVNC..."
apt_install_full xrdp xorgxrdp tigervnc-standalone-server tigervnc-common

echo "$LOG Configuring TigerVNC for ${MAIN_USER} (display :1)..."
sudo -u "${MAIN_USER}" mkdir -p "${MAIN_HOME}/.vnc"

cat > "${MAIN_HOME}/.vnc/xstartup" <<'EOF'
#!/bin/sh
unset SESSION_MANAGER
unset DBUS_SESSION_BUS_ADDRESS
exec cinnamon-session
EOF
chown "${MAIN_USER}:${MAIN_USER}" "${MAIN_HOME}/.vnc/xstartup"
chmod 0755 "${MAIN_HOME}/.vnc/xstartup"

# Set VNC password strictly 9030404
echo "${VNC_PASS}" | sudo -u "${MAIN_USER}" vncpasswd -f > "${MAIN_HOME}/.vnc/passwd"
chown "${MAIN_USER}:${MAIN_USER}" "${MAIN_HOME}/.vnc/passwd"
chmod 0600 "${MAIN_HOME}/.vnc/passwd"

# TigerVNC systemd service (port 5901)
cat > /etc/systemd/system/tigervnc-${MAIN_USER}.service <<EOF
[Unit]
Description=TigerVNC Server for ${MAIN_USER} on display :1
After=network.target

[Service]
Type=forking
User=${MAIN_USER}
PAMName=login
WorkingDirectory=${MAIN_HOME}
ExecStartPre=-/usr/bin/tigervncserver -kill :1
ExecStart=/usr/bin/tigervncserver :1 -geometry 1920x1080 -depth 24 -localhost no
ExecStop=/usr/bin/tigervncserver -kill :1
Restart=on-failure
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable "tigervnc-${MAIN_USER}.service"
systemctl restart "tigervnc-${MAIN_USER}.service"

echo "$LOG Configuring XRDP to connect to the SAME TigerVNC session (:1 / 5901)..."
cp -a /etc/xrdp/xrdp.ini /etc/xrdp/xrdp.ini.bak.$(date +%s) 2>/dev/null || true
cp -a /etc/xrdp/sesman.ini /etc/xrdp/sesman.ini.bak.$(date +%s) 2>/dev/null || true

cat > /etc/xrdp/xrdp.ini <<EOF
[Globals]
ini_version=1
fork=true
port=3389
tcp_nodelay=true
tcp_keepalive=true
security_layer=negotiate
crypt_level=high
channel_code=1
allow_channels=true
max_bpp=32
new_cursors=true

[Logging]
LogFile=xrdp.log
LogLevel=INFO
EnableSyslog=true

[Xvnc]
name=Shared Cinnamon Desktop (TigerVNC :1)
lib=libvnc.so
username=
password=${VNC_PASS}
ip=127.0.0.1
port=5901
EOF

# Disable Xorg backend to avoid separate RDP sessions
if grep -qE '^\s*XorgEnable=' /etc/xrdp/sesman.ini; then
  sed -i 's/^\s*XorgEnable=.*/XorgEnable=false/g' /etc/xrdp/sesman.ini
else
  echo "XorgEnable=false" >> /etc/xrdp/sesman.ini
fi

# Enable Xvnc backend
if grep -qE '^\s*XvncEnable=' /etc/xrdp/sesman.ini; then
  sed -i 's/^\s*XvncEnable=.*/XvncEnable=true/g' /etc/xrdp/sesman.ini
else
  echo "XvncEnable=true" >> /etc/xrdp/sesman.ini
fi

systemctl enable xrdp
systemctl restart xrdp
systemctl restart xrdp-sesman || true

# ------------------------------------------------------------
# Optional: x11vnc (share :0). Installed but NOT enabled.
# ------------------------------------------------------------
echo "$LOG Installing x11vnc (optional, disabled)..."
apt_install_full x11vnc
cat > /etc/systemd/system/x11vnc.service <<'EOF'
[Unit]
Description=x11vnc server (share :0)
After=display-manager.service
Wants=display-manager.service

[Service]
Type=simple
ExecStart=/usr/bin/x11vnc -display :0 -forever -shared -rfbport 5900 -nopw -xkb
Restart=on-failure
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload

# ------------------------------------------------------------
# snap + apps
# ------------------------------------------------------------
echo "$LOG Installing snapd + apps..."
apt_install_full snapd
systemctl enable snapd || true
systemctl start snapd || true
snap install core || true

snap install telegram-desktop --classic || true
snap install chromium || true
snap install firefox || true

# ------------------------------------------------------------
# GParted
# ------------------------------------------------------------
echo "$LOG Installing gparted..."
apt_install_full gparted

# ------------------------------------------------------------
# Google Chrome (deb)
# ------------------------------------------------------------
echo "$LOG Installing Google Chrome..."
TMP_DEB="/tmp/google-chrome-stable_current_amd64.deb"
wget -qO "$TMP_DEB" https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
apt-get install -y "$TMP_DEB" || (apt-get -f install -y && apt-get install -y "$TMP_DEB")

# ------------------------------------------------------------
# JetBrains IDEs (snap classic)
# ------------------------------------------------------------
echo "$LOG Installing JetBrains IDEs..."
snap install phpstorm --classic || true
snap install pycharm-professional --classic || true

echo "$LOG Installing JetBrains runtime deps..."
apt_install_full \
  libxext6 libxrender1 libxtst6 libxi6 libxrandr2 libxfixes3 libgtk-3-0 \
  libdbus-1-3 libasound2t64 \
  fonts-dejavu fonts-liberation

# ------------------------------------------------------------
# UFW firewall
# ------------------------------------------------------------
echo "$LOG Configuring UFW..."
ufw allow 22/tcp >/dev/null || true
ufw allow 3389/tcp >/dev/null || true
ufw allow 5901/tcp >/dev/null || true
# ufw allow 5900/tcp >/dev/null || true  # only if enabling x11vnc
ufw --force enable >/dev/null || true

echo
echo "$LOG DONE."
echo
echo "Main user:"
echo " - login:    ${MAIN_USER}"
echo " - password: ${FINAL_PASS}"
echo " - groups:   sudo, docker"
echo
echo "Shared desktop session (ONE for both RDP and VNC):"
echo " - TigerVNC:  port 5901 (display :1)  password: ${VNC_PASS}"
echo " - XRDP:      port 3389 (Windows mstsc) -> connects to the same VNC session"
echo
echo "Recommended now:"
echo " - sudo reboot"
echo
