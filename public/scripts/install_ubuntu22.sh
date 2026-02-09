#!/usr/bin/env bash
# install_ubuntu22.sh — Ubuntu 22.04 (Jammy) full dev desktop for VDS
# - APT priority (Snap only as fallback for Chromium if enabled)
# - FULL GNOME + FULL Cinnamon + LightDM, default Cinnamon for user
# - Docker (official repo), Git/Make/MC, SSH tuned for JetBrains Gateway
# - XRDP connects to ONE shared TigerVNC session (:1)
# - Telegram/Chrome/Firefox/Chromium + menu shortcuts
# - PhpStorm + PyCharm PRO via official JetBrains tar.gz (NOT snap)
#
# Usage:
#   sudo bash install_ubuntu22.sh
#
# Optional env:
#   MAIN_USER=origamiv
#   VNC_PASS=9030404
#   INSTALL_SNAP_FALLBACK=1   # allow snap fallback for Chromium if apt deb isn't available (default 0)

set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

LOG="[install]"

# ============================================================
# CONFIG
# ============================================================
MAIN_USER="${MAIN_USER:-origamiv}"
MAIN_HOME="/home/${MAIN_USER}"

PASS_PRIMARY="Ori9030404"
PASS_FALLBACK="Ori_9030404"

VNC_PASS="${VNC_PASS:-9030404}"

INSTALL_SNAP_FALLBACK="${INSTALL_SNAP_FALLBACK:-0}"

# ============================================================
# HELPERS
# ============================================================
need_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    echo "$LOG Run as root (sudo)." >&2
    exit 1
  fi
}

check_ubuntu_2204() {
  if [[ -f /etc/os-release ]]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    if [[ "${ID:-}" != "ubuntu" ]]; then
      echo "$LOG WARNING: This script is for Ubuntu. Detected: ${ID:-unknown}"
    fi
    if [[ "${VERSION_ID:-}" != "22.04" ]]; then
      echo "$LOG WARNING: This script is adapted for Ubuntu 22.04. Detected VERSION_ID=${VERSION_ID:-unknown}"
    fi
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

cmd_exists() { command -v "$1" >/dev/null 2>&1; }

set_user_password_with_fallback() {
  local user="$1"
  echo "$LOG Setting password for ${user}..."
  if echo "${user}:${PASS_PRIMARY}" | chpasswd; then
    echo "$PASS_PRIMARY"
    return 0
  fi
  echo "$LOG First password rejected by policy, trying fallback..."
  if echo "${user}:${PASS_FALLBACK}" | chpasswd; then
    echo "$PASS_FALLBACK"
    return 0
  fi
  echo "$LOG ERROR: Could not set password (both variants rejected)." >&2
  return 1
}

create_user_desktop_entry() {
  local user="$1"
  local file="$2"
  local name="$3"
  local exec="$4"
  local icon="$5"
  local categories="$6"

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

install_jetbrains_ide() {
  # JetBrains tar.gz install (NOT snap)
  local code="$1"        # PS, PCP
  local idename="$2"     # phpstorm, pycharm
  local title="$3"
  local categories="$4"

  local dest_dir="/opt/jetbrains/${idename}"
  local tmp_json="/tmp/jb_${idename}.json"
  local url=""
  local tarball="/tmp/${idename}.tar.gz"

  mkdir -p /opt/jetbrains

  echo "$LOG Resolving ${title} download URL (code=${code})..."
  curl -fsSL "https://data.services.jetbrains.com/products/releases?code=${code}&latest=true&type=release" -o "$tmp_json"

  # Response format: { "PS": [ {...} ] } or { "PCP": [ {...} ] }
  url="$(jq -r --arg code "$code" '.[$code][0].downloads.linux.link // empty' "$tmp_json")"
  if [[ -z "$url" || "$url" == "null" ]]; then
    echo "$LOG ERROR: Could not resolve URL for ${title} (code=${code}). JSON saved at: ${tmp_json}" >&2
    return 1
  fi

  echo "$LOG Downloading ${title}..."
  curl -fL "$url" -o "$tarball"

  echo "$LOG Installing ${title} to ${dest_dir}..."
  rm -rf "$dest_dir"
  mkdir -p "$dest_dir"
  tar -xzf "$tarball" -C "$dest_dir" --strip-components=1

  ln -sf "${dest_dir}/bin/${idename}.sh" "/usr/local/bin/${idename}"

  local icon_path="applications-development"
  if [[ -f "${dest_dir}/bin/${idename}.png" ]]; then
    icon_path="${dest_dir}/bin/${idename}.png"
  elif [[ -f "${dest_dir}/bin/${idename}.svg" ]]; then
    icon_path="${dest_dir}/bin/${idename}.svg"
  fi

  # user-local shortcut (Cinnamon reliable)
  create_user_desktop_entry "${MAIN_USER}" "${idename}.desktop" "${title}" "/usr/local/bin/${idename}" "${icon_path}" "${categories}"

  # system-wide shortcut too
  cat > "/usr/share/applications/${idename}.desktop" <<EOF
[Desktop Entry]
Type=Application
Name=${title}
Comment=${title}
Exec=/usr/local/bin/${idename}
Icon=${icon_path}
Terminal=false
Categories=${categories}
StartupNotify=true
EOF

  update-desktop-database /usr/share/applications >/dev/null 2>&1 || true
}

# Snap fallback only (Chromium) if enabled
ensure_snap_ready() {
  apt_install_full snapd
  systemctl enable --now snapd.socket >/dev/null 2>&1 || true
  systemctl start snapd >/dev/null 2>&1 || true
  snap wait system seed.loaded >/dev/null 2>&1 || true
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
  return 1
}

# ============================================================
# START
# ============================================================
need_root
check_ubuntu_2204

echo "$LOG Enabling Universe..."
apt_install_min software-properties-common
add-apt-repository -y universe || true
apt-get update -y

# ------------------------------------------------------------
# Base packages (APT priority)
# ------------------------------------------------------------
echo "$LOG Installing base packages..."
apt_install_min \
  ca-certificates curl wget gnupg lsb-release \
  git make mc \
  openssh-server openssh-client \
  e2fsprogs \
  net-tools unzip zip xz-utils \
  fontconfig \
  ufw \
  jq \
  desktop-file-utils xdg-utils \
  dbus-x11 dbus-user-session xauth

if cmd_exists resize2fs; then
  echo "$LOG resize2fs OK"
else
  echo "$LOG WARNING: resize2fs not found (should be provided by e2fsprogs)"
fi

# ------------------------------------------------------------
# Create user + sudo
# ------------------------------------------------------------
if id -u "${MAIN_USER}" >/dev/null 2>&1; then
  echo "$LOG User ${MAIN_USER} already exists"
else
  echo "$LOG Creating user ${MAIN_USER}..."
  adduser --disabled-password --gecos "" "${MAIN_USER}"
fi

usermod -aG sudo "${MAIN_USER}"
FINAL_PASS="$(set_user_password_with_fallback "${MAIN_USER}")"
echo "$LOG Password set for ${MAIN_USER}"

# ------------------------------------------------------------
# System tuning (JetBrains / large projects)
# ------------------------------------------------------------
echo "$LOG Tuning inotify..."
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
# SSH for JetBrains Gateway
# ------------------------------------------------------------
echo "$LOG Configuring SSH..."
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
# Docker (official repo)
# ------------------------------------------------------------
echo "$LOG Installing Docker..."
install -m 0755 -d /etc/apt/keyrings
if [[ ! -f /etc/apt/keyrings/docker.gpg ]]; then
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg
fi

UBUNTU_CODENAME="$(. /etc/os-release && echo "${VERSION_CODENAME}")"  # jammy
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
  x11-xserver-utils \
  xfonts-base fonts-dejavu-core fonts-liberation \
  libgtk-3-0 libxext6 libxrender1 libxtst6 libxi6 libxrandr2 libxfixes3 \
  libdbus-1-3 libasound2

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
# XRDP + TigerVNC: one shared session (:1) for RDP and VNC
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

echo "$LOG Configuring XRDP to connect to the SAME TigerVNC session (5901)..."
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

# Optional x11vnc (installed, disabled)
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
# Apps — APT priority
# ------------------------------------------------------------
echo "$LOG Installing apps via APT..."

# Telegram (APT)
apt_install_full telegram-desktop

# GParted (APT)
apt_install_full gparted

# Google Chrome (DEB from Google)
echo "$LOG Installing Google Chrome (deb)..."
TMP_DEB="/tmp/google-chrome-stable_current_amd64.deb"
wget -qO "$TMP_DEB" https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
apt-get install -y "$TMP_DEB" || (apt-get -f install -y && apt-get install -y "$TMP_DEB")

# Firefox as DEB from Mozilla APT repo
echo "$LOG Installing Firefox (DEB from Mozilla APT repo)..."
install -d -m 0755 /etc/apt/keyrings
if [[ ! -f /etc/apt/keyrings/packages.mozilla.org.asc ]]; then
  wget -qO /etc/apt/keyrings/packages.mozilla.org.asc https://packages.mozilla.org/apt/repo-signing-key.gpg
  chmod 0644 /etc/apt/keyrings/packages.mozilla.org.asc
fi

cat > /etc/apt/sources.list.d/mozilla.list <<'EOF'
deb [signed-by=/etc/apt/keyrings/packages.mozilla.org.asc] https://packages.mozilla.org/apt mozilla main
EOF

cat > /etc/apt/preferences.d/mozilla-firefox <<'EOF'
Package: *
Pin: origin packages.mozilla.org
Pin-Priority: 1000
EOF

apt-get update -y
apt-get remove -y firefox || true
apt-get install -y firefox

# Chromium: try deb via XtraDeb PPA. If fails, optional snap fallback.
echo "$LOG Installing Chromium (DEB preferred)..."
CHROMIUM_OK=0

add-apt-repository -y ppa:xtradeb/apps || true
apt-get update -y

if apt-cache show chromium >/dev/null 2>&1; then
  if apt-get install -y chromium; then
    CHROMIUM_OK=1
  fi
fi

if [[ "$CHROMIUM_OK" -ne 1 ]]; then
  echo "$LOG WARNING: Could not install Chromium as deb from apt."
  if [[ "${INSTALL_SNAP_FALLBACK}" == "1" ]]; then
    echo "$LOG Snap fallback enabled -> installing Chromium via snap..."
    ensure_snap_ready
    snap_install_retry chromium || true
  else
    echo "$LOG INSTALL_SNAP_FALLBACK=0 -> skipping Chromium snap."
  fi
fi

# ------------------------------------------------------------
# JetBrains IDEs — official tar.gz (NOT snap, NOT apt)
# ------------------------------------------------------------
echo "$LOG Installing JetBrains IDEs (official tar.gz)..."
apt_install_full curl jq tar xz-utils

install_jetbrains_ide "PS"  "phpstorm" "PhpStorm"  "Development;IDE;"
install_jetbrains_ide "PCP" "pycharm"  "PyCharm"   "Development;IDE;"

# ------------------------------------------------------------
# Force Cinnamon menu shortcuts (user-local) — helps when menu won't refresh
# ------------------------------------------------------------
echo "$LOG Forcing menu shortcuts for ${MAIN_USER}..."

# Telegram
if [[ -x /usr/bin/telegram-desktop ]]; then
  create_user_desktop_entry "${MAIN_USER}" "telegram-desktop.desktop" "Telegram Desktop" "/usr/bin/telegram-desktop" "telegram" "Network;InstantMessaging;"
fi

# Chrome
if [[ -x /usr/bin/google-chrome-stable ]]; then
  create_user_desktop_entry "${MAIN_USER}" "google-chrome.desktop" "Google Chrome" "/usr/bin/google-chrome-stable" "/usr/share/icons/hicolor/256x256/apps/google-chrome.png" "Network;WebBrowser;"
fi

# Firefox
if [[ -x /usr/bin/firefox ]]; then
  create_user_desktop_entry "${MAIN_USER}" "firefox.desktop" "Firefox" "/usr/bin/firefox" "firefox" "Network;WebBrowser;"
fi

# Chromium (deb or snap)
if [[ -x /usr/bin/chromium ]]; then
  create_user_desktop_entry "${MAIN_USER}" "chromium.desktop" "Chromium" "/usr/bin/chromium" "chromium-browser" "Network;WebBrowser;"
elif [[ -x /snap/bin/chromium ]]; then
  create_user_desktop_entry "${MAIN_USER}" "chromium.desktop" "Chromium" "/snap/bin/chromium" "/snap/chromium/current/meta/gui/icon.png" "Network;WebBrowser;"
fi

update-desktop-database /usr/share/applications >/dev/null 2>&1 || true
sudo -u "${MAIN_USER}" update-desktop-database "${MAIN_HOME}/.local/share/applications" >/dev/null 2>&1 || true
clear_cinnamon_caches "${MAIN_USER}"

# ------------------------------------------------------------
# Firewall
# ------------------------------------------------------------
echo "$LOG Configuring UFW..."
ufw allow 22/tcp >/dev/null || true
ufw allow 3389/tcp >/dev/null || true
ufw allow 5901/tcp >/dev/null || true
# ufw allow 5900/tcp >/dev/null || true  # only if you enable x11vnc
ufw --force enable >/dev/null || true

# ------------------------------------------------------------
# Summary
# ------------------------------------------------------------
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
echo "APT priority:"
echo " - Telegram/Chrome/Firefox: apt/deb"
echo " - Chromium: deb via PPA (snap only if INSTALL_SNAP_FALLBACK=1)"
echo " - JetBrains IDEs: official tar.gz to /opt/jetbrains (launchers in /usr/local/bin)"
echo
echo "Recommended now:"
echo " - sudo reboot"
echo
