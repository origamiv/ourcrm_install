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

snap_install_retry() {
  local name="$1"; shift || true
  local args=("$@")
  local i
  for i in 1 2 3 4 5; do
    if snap list "$name" >/dev/null 2>&1; then
      echo "$LOG snap already installed: $name"
      return 0
    fi
    echo "$LOG snap install attempt $i: $name"
    if snap install "$name" "${args[@]}"; then
      return 0
    fi
    sleep 5
    snap wait system seed.loaded >/dev/null 2>&1 || true
  done
  echo "$LOG WARNING: snap install failed: $name"
  return 0
}

# Create a user-local .desktop entry that Cinnamon reliably shows
create_user_desktop_entry() {
  local user="$1"
  local file="$2"         # e.g. phpstorm.desktop
  local name="$3"         # shown name
  local exec="$4"         # command
  local icon="$5"         # icon path
  local categories="$6"   # e.g. Development;IDE;

  local home_dir
  home_dir="$(getent passwd "$user" | cut -d: -f6)"
  local apps_dir="${home_dir}/.local/share/applications"
  local dest="${apps_dir}/${file}"

  sudo -u "$user" mkdir -p "$apps_dir"

  sudo -u "$user" tee "$dest" >/dev/null <<EOF
[Desktop Entry]
Type=Application
Name=${name}
Comment=${name}
Exec=${exec}
Icon=${icon}
Terminal=false
Categories=${categories}
StartupNotify=true
EOF

  sudo -u "$user" update-desktop-database "$apps_dir" >/dev/null 2>&1 || true
}

clear_cinnamon_caches() {
  local user="$1"
  local home_dir
  home_dir="$(getent passwd "$user" | cut -d: -f6)"
  rm -rf \
    "${home_dir}/.cache/menus" \
    "${home_dir}/.cache/cinnamon" \
    "${home_dir}/.cache/gio-2.0/menu-cache" \
    "${home_dir}/.cache/gio-2.0" 2>/dev/null || true
  chown -R "${user}:${user}" "${home_dir}/.cache" 2>/dev/null || true
}

need_root

MAIN_USER="origamiv"
MAIN_HOME="/home/${MAIN_USER}"

# VNC password must be VNC-compatible
VNC_PASS="9030404"

# ------------------------------------------------------------
# Enable Universe
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
# FULL GNOME + FULL Cinnamon + LightDM
# - GNOME: ubuntu-desktop (full, not minimal)
# - Cinnamon: cinnamon-desktop-environment (full)
# - Extra common components to feel "complete"
# ------------------------------------------------------------
echo "$LOG Installing FULL GNOME + FULL Cinnamon + LightDM..."
apt_install_full \
  ubuntu-desktop \
  cinnamon-desktop-environment \
  cinnamon-control-center \
  nemo nemo-fileroller \
  network-manager-gnome \
  gnome-terminal \
  gnome-system-monitor \
  gnome-disk-utility \
  file-roller \
  xdg-user-dirs xdg-user-dirs-gtk \
  lightdm lightdm-gtk-greeter \
  gnome-themes-extra \
  adwaita-icon-theme-full \
  papirus-icon-theme \
  dbus-x11 dbus-user-session xauth \
  x11-xserver-utils \
  xfonts-base fonts-dejavu-core \
  desktop-file-utils xdg-utils

# Optional extras if exist
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
# ------------------------------------------------------------
echo "$LOG Installing XRDP + TigerVNC..."
apt_install_full xrdp xorgxrdp tigervnc-standalone-server tigervnc-common

echo "$LOG Configuring TigerVNC for ${MAIN_USER} (display :1)..."
sudo -u "${MAIN_USER}" mkdir -p "${MAIN_HOME}/.vnc"

cat > "${MAIN_HOME}/.vnc/xstartup" <<'EOF'
#!/bin/sh
unset SESSION_MANAGER
unset DBUS_SESSION_BUS_ADDRESS

if command -v dbus-launch >/dev/null 2>&1; then
  exec dbus-launch --exit-with-session cinnamon-session
fi

exec cinnamon-session
EOF
chown "${MAIN_USER}:${MAIN_USER}" "${MAIN_HOME}/.vnc/xstartup"
chmod 0755 "${MAIN_HOME}/.vnc/xstartup"

echo "${VNC_PASS}" | sudo -u "${MAIN_USER}" vncpasswd -f > "${MAIN_HOME}/.vnc/passwd"
chown "${MAIN_USER}:${MAIN_USER}" "${MAIN_HOME}/.vnc/passwd"
chmod 0600 "${MAIN_HOME}/.vnc/passwd"

cat > /etc/systemd/system/tigervnc-${MAIN_USER}.service <<EOF
[Unit]
Description=TigerVNC Server for ${MAIN_USER} on display :1
After=network.target

[Service]
Type=oneshot
User=${MAIN_USER}
WorkingDirectory=${MAIN_HOME}
RemainAfterExit=yes

ExecStartPre=-/usr/bin/tigervncserver -kill :1
ExecStart=/usr/bin/tigervncserver :1 -geometry 1920x1080 -depth 24 -localhost no -SecurityTypes VncAuth
ExecStop=/usr/bin/tigervncserver -kill :1

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

if grep -qE '^\s*XorgEnable=' /etc/xrdp/sesman.ini; then
  sed -i 's/^\s*XorgEnable=.*/XorgEnable=false/g' /etc/xrdp/sesman.ini
else
  echo "XorgEnable=false" >> /etc/xrdp/sesman.ini
fi

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
# snap + apps (robust) + JetBrains IDEs + snap desktop integration
# ------------------------------------------------------------
echo "$LOG Installing snapd + apps..."
apt_install_full snapd

systemctl enable --now snapd.socket >/dev/null 2>&1 || true
systemctl start snapd >/dev/null 2>&1 || true

if command -v snap >/dev/null 2>&1; then
  echo "$LOG Waiting for snap seed..."
  snap wait system seed.loaded >/dev/null 2>&1 || true
fi

snap_install_retry core || true
snap_install_retry snapd-desktop-integration || true

snap_install_retry telegram-desktop
snap_install_retry chromium
snap_install_retry firefox

snap_install_retry phpstorm --classic
snap_install_retry pycharm-professional --classic

# ------------------------------------------------------------
# Google Chrome (deb)
# ------------------------------------------------------------
echo "$LOG Installing Google Chrome..."
TMP_DEB="/tmp/google-chrome-stable_current_amd64.deb"
wget -qO "$TMP_DEB" https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
apt-get install -y "$TMP_DEB" || (apt-get -f install -y && apt-get install -y "$TMP_DEB")

# ------------------------------------------------------------
# JetBrains runtime deps
# ------------------------------------------------------------
echo "$LOG Installing JetBrains runtime deps..."
apt_install_full \
  libxext6 libxrender1 libxtst6 libxi6 libxrandr2 libxfixes3 libgtk-3-0 \
  libdbus-1-3 libasound2t64 \
  fonts-dejavu fonts-liberation

# ------------------------------------------------------------
# FORCE menu entries for Cinnamon (user-local .desktop)
# ------------------------------------------------------------
echo "$LOG Creating Cinnamon menu shortcuts for ${MAIN_USER}..."

create_user_desktop_entry "${MAIN_USER}" "phpstorm.desktop" "PhpStorm" "/snap/bin/phpstorm" "/snap/phpstorm/current/meta/gui/icon.png" "Development;IDE;"
create_user_desktop_entry "${MAIN_USER}" "pycharm-professional.desktop" "PyCharm Professional" "/snap/bin/pycharm-professional" "/snap/pycharm-professional/current/meta/gui/icon.png" "Development;IDE;"
create_user_desktop_entry "${MAIN_USER}" "telegram-desktop.desktop" "Telegram Desktop" "/snap/bin/telegram-desktop" "/snap/telegram-desktop/current/meta/gui/icon.png" "Network;InstantMessaging;"
create_user_desktop_entry "${MAIN_USER}" "chromium.desktop" "Chromium" "/snap/bin/chromium" "/snap/chromium/current/meta/gui/icon.png" "Network;WebBrowser;"
create_user_desktop_entry "${MAIN_USER}" "firefox.desktop" "Firefox" "/snap/bin/firefox" "/snap/firefox/current/meta/gui/icon.png" "Network;WebBrowser;"
create_user_desktop_entry "${MAIN_USER}" "google-chrome.desktop" "Google Chrome" "/usr/bin/google-chrome-stable" "/usr/share/icons/hicolor/256x256/apps/google-chrome.png" "Network;WebBrowser;"

update-desktop-database /usr/share/applications >/dev/null 2>&1 || true
update-desktop-database /var/lib/snapd/desktop/applications >/dev/null 2>&1 || true
sudo -u "${MAIN_USER}" update-desktop-database "/home/${MAIN_USER}/.local/share/applications" >/dev/null 2>&1 || true

clear_cinnamon_caches "${MAIN_USER}"

# ------------------------------------------------------------
# GParted
# ------------------------------------------------------------
echo "$LOG Installing gparted..."
apt_install_full gparted

# ------------------------------------------------------------
# UFW firewall
# ------------------------------------------------------------
echo "$LOG Configuring UFW..."
ufw allow 22/tcp >/dev/null || true
ufw allow 3389/tcp >/dev/null || true
ufw allow 5901/tcp >/dev/null || true
# ufw allow 5900/tcp >/dev/null || true  # only if enabling x11vnc
ufw --force enable >/dev/null || true

echo "$LOG Verifying snaps..."
snap list 2>/dev/null | egrep -i 'phpstorm|pycharm|telegram-desktop|chromium|firefox|snapd-desktop-integration|core' || true

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
echo "Menu shortcuts:"
echo " - Created .desktop files in /home/${MAIN_USER}/.local/share/applications"
echo " - Cinnamon caches cleared (logout/login may be required)"
echo
echo "Recommended now:"
echo " - sudo reboot"
echo
