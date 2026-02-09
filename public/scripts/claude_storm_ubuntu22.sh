#!/usr/bin/env bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

# ============================================================
# install_claude_code_and_phpstorm.sh (Ubuntu 22.04/24.04)
# - installs Claude Code CLI (official installer)
# - installs PhpStorm (official JetBrains tar.gz) if missing
# - installs PhpStorm plugins:
#     - Claude Code [Beta]  (com.anthropic.code.plugin)
#     - Classic UI          (com.intellij.classic.ui)
# - forces PhpStorm UI theme to LIGHT
#
# Usage:
#   sudo bash install_claude_code_and_phpstorm.sh
#
# Optional env:
#   MAIN_USER=origamiv
# ============================================================

LOG="[claude+phpstorm]"
MAIN_USER="${MAIN_USER:-origamiv}"

# JetBrains Marketplace plugin IDs:
PLUGIN_CLAUDE_CODE="com.anthropic.code.plugin"
PLUGIN_CLASSIC_UI="com.intellij.classic.ui"

# Install location for PhpStorm tar.gz
JB_DIR="/opt/jetbrains"
PS_DIR="${JB_DIR}/phpstorm"
PS_BIN="${PS_DIR}/bin/phpstorm.sh"
PS_SYMLINK="/usr/local/bin/phpstorm"

# ------------------------------------------------------------
# helpers
# ------------------------------------------------------------
need_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    echo "$LOG Run as root (sudo)." >&2
    exit 1
  fi
}

cmd_exists() { command -v "$1" >/dev/null 2>&1; }

home_of() { getent passwd "$1" 2>/dev/null | cut -d: -f6 || true; }

apt_install_min() {
  apt-get update -y
  apt-get install -y --no-install-recommends "$@"
}

apt_install_full() {
  apt-get update -y
  apt-get install -y "$@"
}

kill_phpstorm_if_running() {
  local user="$1"
  pkill -u "$user" -f "phpstorm" >/dev/null 2>&1 || true
  pkill -u "$user" -f "PhpStorm" >/dev/null 2>&1 || true
}

find_phpstorm_sh() {
  local candidates=(
    "${PS_BIN}"
    "/opt/jetbrains/PhpStorm/bin/phpstorm.sh"
    "/usr/local/bin/phpstorm"
    "/usr/bin/phpstorm"
    "/snap/bin/phpstorm"
  )
  local p
  for p in "${candidates[@]}"; do
    if [[ -x "$p" ]]; then
      echo "$p"
      return 0
    fi
  done

  if cmd_exists find; then
    p="$(find /opt -maxdepth 6 -type f -name phpstorm.sh -perm -111 2>/dev/null | head -n 1 || true)"
    if [[ -n "$p" && -x "$p" ]]; then
      echo "$p"
      return 0
    fi
  fi
  return 1
}

phpstorm_root_from_sh() {
  local sh="$1"
  sh="$(readlink -f "$sh" || echo "$sh")"
  echo "$(cd "$(dirname "$sh")/.." && pwd)"
}

phpstorm_data_dirname_from_product_info() {
  local root="$1"
  local pi="${root}/product-info.json"
  if [[ -f "$pi" ]]; then
    jq -r '.dataDirectoryName // empty' "$pi" 2>/dev/null || true
  fi
}

pick_config_dir() {
  local user="$1"
  local root="$2"
  local home; home="$(home_of "$user")"
  local jb="${home}/.config/JetBrains"
  local found=""

  if [[ -d "$jb" ]]; then
    found="$(ls -1d "${jb}/PhpStorm"* 2>/dev/null | sort -V | tail -n 1 || true)"
  fi
  if [[ -n "$found" && -d "$found" ]]; then
    echo "$found"
    return 0
  fi

  local ddn; ddn="$(phpstorm_data_dirname_from_product_info "$root")"
  if [[ -n "$ddn" ]]; then
    echo "${jb}/${ddn}"
    return 0
  fi

  echo "${jb}/PhpStorm"
}

set_phpstorm_light_theme() {
  local user="$1"
  local root="$2"
  local cfg; cfg="$(pick_config_dir "$user" "$root")"
  local opt="${cfg}/options"
  mkdir -p "$opt"

  cat > "${opt}/laf.xml" <<'EOF'
<application>
  <component name="LafManager" autodetect="false">
    <laf class-name="com.intellij.ide.ui.laf.IntelliJLaf" themeId="JetBrainsLightTheme" />
    <preferred-light-laf class-name="com.intellij.ide.ui.laf.IntelliJLaf" themeId="JetBrainsLightTheme" />
  </component>
</application>
EOF

  chown -R "${user}:${user}" "${cfg}" || true
  echo "$LOG PhpStorm UI theme forced to Light: ${opt}/laf.xml"
}

create_user_desktop_entry() {
  local user="$1"
  local file="$2"
  local name="$3"
  local exec="$4"
  local icon="$5"
  local categories="$6"

  local home; home="$(home_of "$user")"
  local apps="${home}/.local/share/applications"
  mkdir -p "$apps"

  cat > "${apps}/${file}" <<EOF
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

  chown -R "${user}:${user}" "${home}/.local" || true
  sudo -u "$user" update-desktop-database "${apps}" >/dev/null 2>&1 || true
}

install_phpstorm_tarball() {
  mkdir -p "$JB_DIR"
  apt_install_full curl jq tar xz-utils ca-certificates

  local tmp_json="/tmp/jb_phpstorm.json"
  local url=""
  local tgz="/tmp/phpstorm.tar.gz"

  echo "$LOG Resolving PhpStorm download URL (JetBrains API, code=PS)..."
  curl -fsSL "https://data.services.jetbrains.com/products/releases?code=PS&latest=true&type=release" -o "$tmp_json"
  url="$(jq -r '.PS[0].downloads.linux.link // empty' "$tmp_json")"
  if [[ -z "$url" || "$url" == "null" ]]; then
    echo "$LOG ERROR: Could not resolve PhpStorm URL. JSON saved: $tmp_json" >&2
    exit 1
  fi

  echo "$LOG Downloading PhpStorm..."
  curl -fL "$url" -o "$tgz"

  echo "$LOG Installing PhpStorm to ${PS_DIR}..."
  rm -rf "$PS_DIR"
  mkdir -p "$PS_DIR"
  tar -xzf "$tgz" -C "$PS_DIR" --strip-components=1

  ln -sf "${PS_BIN}" "$PS_SYMLINK"

  # system-wide desktop entry
  local icon="applications-development"
  [[ -f "${PS_DIR}/bin/phpstorm.png" ]] && icon="${PS_DIR}/bin/phpstorm.png"
  [[ -f "${PS_DIR}/bin/phpstorm.svg" ]] && icon="${PS_DIR}/bin/phpstorm.svg"

  cat > /usr/share/applications/phpstorm.desktop <<EOF
[Desktop Entry]
Type=Application
Name=PhpStorm
Comment=PhpStorm
Exec=${PS_SYMLINK}
Icon=${icon}
Terminal=false
Categories=Development;IDE;
StartupNotify=true
EOF
  update-desktop-database /usr/share/applications >/dev/null 2>&1 || true

  # user-local entry (Cinnamon обычно надежнее подхватывает)
  create_user_desktop_entry "$MAIN_USER" "phpstorm.desktop" "PhpStorm" "${PS_SYMLINK}" "${icon}" "Development;IDE;"
}

install_phpstorm_plugins() {
  local ps_sh="$1"

  echo "$LOG Closing PhpStorm if running (required for installPlugins)..."
  kill_phpstorm_if_running "$MAIN_USER"
  sleep 1

  echo "$LOG Installing plugins into PhpStorm..."
  sudo -u "$MAIN_USER" bash -lc "\"$ps_sh\" installPlugins ${PLUGIN_CLAUDE_CODE} ${PLUGIN_CLASSIC_UI}"
}

# ------------------------------------------------------------
# main
# ------------------------------------------------------------
need_root

# sanity: user must exist
if ! id -u "$MAIN_USER" >/dev/null 2>&1; then
  echo "$LOG ERROR: user '${MAIN_USER}' not found. Create it first or set MAIN_USER=..." >&2
  exit 1
fi

echo "$LOG Installing base deps (APT)..."
apt_install_min ca-certificates curl jq git unzip zip tar xz-utils desktop-file-utils xdg-utils update-notifier-common dbus-user-session dbus-x11

# 1) Claude Code CLI
echo "$LOG Installing Claude Code CLI (official installer)..."
sudo -u "$MAIN_USER" bash -lc 'curl -fsSL https://claude.ai/install.sh | bash'

if sudo -u "$MAIN_USER" bash -lc 'command -v claude >/dev/null 2>&1'; then
  echo "$LOG Claude Code is installed and in PATH for ${MAIN_USER}."
else
  echo "$LOG WARNING: 'claude' not found in PATH for ${MAIN_USER}. Re-login may be needed (PATH update)."
fi

# 2) PhpStorm (install if missing)
echo "$LOG Checking PhpStorm..."
PSH="$(find_phpstorm_sh || true)"
if [[ -z "${PSH:-}" ]]; then
  echo "$LOG PhpStorm not found -> installing (official tar.gz)..."
  install_phpstorm_tarball
  PSH="$(find_phpstorm_sh)"
else
  echo "$LOG PhpStorm found: $PSH"
fi

# 3) Plugins
install_phpstorm_plugins "$PSH"

# 4) Force LIGHT UI theme
echo "$LOG Switching PhpStorm UI theme to Light..."
PS_ROOT="$(phpstorm_root_from_sh "$PSH")"
set_phpstorm_light_theme "$MAIN_USER" "$PS_ROOT"

echo
echo "$LOG DONE."
echo "What you get:"
echo " - Claude Code CLI installed for user: ${MAIN_USER}"
echo " - PhpStorm installed/available at: ${PS_SYMLINK} (or existing installation)"
echo " - Plugins installed: Claude Code [Beta], Classic UI"
echo " - UI theme forced to: JetBrains Light (next start of PhpStorm)"
echo
echo "If plugins don't appear immediately: start PhpStorm once and restart it."
