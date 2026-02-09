#!/usr/bin/env bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

# JetBrains Remote Dev + Remote Dev Containers prerequisites checker (Ubuntu 22.04, APT)
# + Auto-install missing packages (APT) when started with --install
#
# Usage:
#   bash check_jetbrains_devcontainers_remote.sh
#   sudo bash check_jetbrains_devcontainers_remote.sh --install
#
# Optional:
#   CHECK_USER=origamiv bash check_jetbrains_devcontainers_remote.sh
#   sudo CHECK_USER=origamiv bash check_jetbrains_devcontainers_remote.sh --install

CHECK_USER="${CHECK_USER:-${SUDO_USER:-$USER}}"
DO_INSTALL=0
[[ "${1:-}" == "--install" ]] && DO_INSTALL=1

# ---------- UI helpers ----------
if [[ -t 1 ]]; then
  RED=$'\e[31m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; BLUE=$'\e[34m'; RESET=$'\e[0m'
else
  RED=""; GREEN=""; YELLOW=""; BLUE=""; RESET=""
fi

PASS_COUNT=0
FAIL_COUNT=0
WARN_COUNT=0

ok()   { echo "${GREEN}[OK]${RESET}   $*"; PASS_COUNT=$((PASS_COUNT+1)); }
fail() { echo "${RED}[FAIL]${RESET} $*"; FAIL_COUNT=$((FAIL_COUNT+1)); }
warn() { echo "${YELLOW}[WARN]${RESET} $*"; WARN_COUNT=$((WARN_COUNT+1)); }
info() { echo "${BLUE}[INFO]${RESET} $*"; }

need_cmd() { command -v "$1" >/dev/null 2>&1; }

ver_ge() {
  local a="$1" b="$2"
  [[ "$(printf '%s\n%s\n' "$b" "$a" | sort -V | head -n1)" == "$b" ]]
}

num_ge() { [[ "${1:-0}" -ge "${2:-0}" ]]; }

is_root() { [[ "${EUID}" -eq 0 ]]; }

apt_install() {
  # install packages with apt (Ubuntu 22)
  local pkgs=("$@")
  if ! is_root; then
    warn "Not root -> cannot auto-install. Run: sudo apt-get update && sudo apt-get install -y ${pkgs[*]}"
    return 1
  fi
  apt-get update -y
  apt-get install -y "${pkgs[@]}"
}

pkg_installed() { dpkg -s "$1" >/dev/null 2>&1; }

ensure_pkg() {
  local pkg="$1" reason="${2:-}"
  if pkg_installed "$pkg"; then
    ok "Package installed: $pkg"
    return 0
  fi

  if [[ "$DO_INSTALL" -eq 1 ]]; then
    info "Installing missing package: $pkg ${reason:+($reason)}"
    apt_install "$pkg"
    if pkg_installed "$pkg"; then
      ok "Installed: $pkg"
      return 0
    fi
    fail "Failed to install: $pkg"
    return 1
  else
    fail "Missing package: $pkg ${reason:+($reason)}"
    return 1
  fi
}

ensure_pkgs() {
  local reason="$1"; shift
  local p
  for p in "$@"; do
    ensure_pkg "$p" "$reason" || true
  done
}

get_home() { getent passwd "$1" 2>/dev/null | cut -d: -f6 || true; }

enable_ssh_forwarding() {
  # Ensure AllowTcpForwarding yes (JetBrains remote dev needs port forwarding)
  local sshd_cfg="/etc/ssh/sshd_config"
  if ! is_root; then
    warn "Not root -> cannot change sshd_config (AllowTcpForwarding)."
    return 0
  fi
  if [[ -f "$sshd_cfg" ]]; then
    if grep -qE '^\s*AllowTcpForwarding\s+' "$sshd_cfg"; then
      sed -i 's/^\s*AllowTcpForwarding\s\+.*/AllowTcpForwarding yes/g' "$sshd_cfg"
    else
      echo "AllowTcpForwarding yes" >> "$sshd_cfg"
    fi
    systemctl restart ssh >/dev/null 2>&1 || systemctl restart sshd >/dev/null 2>&1 || true
    ok "SSHD: AllowTcpForwarding set to yes (service restarted if possible)"
  else
    warn "sshd_config not found at $sshd_cfg"
  fi
}

ensure_swap_4g_if_none() {
  if swapon --show 2>/dev/null | grep -q .; then
    ok "Swap enabled (recommended)"
    return 0
  fi

  if [[ "$DO_INSTALL" -ne 1 ]]; then
    warn "Swap is not enabled (recommended). Run with --install to create 4G swapfile."
    return 0
  fi
  if ! is_root; then
    warn "Not root -> cannot create swapfile."
    return 0
  fi

  local swapfile="/swapfile"
  info "Creating 4G swapfile at ${swapfile}..."
  fallocate -l 4G "$swapfile" 2>/dev/null || dd if=/dev/zero of="$swapfile" bs=1M count=4096
  chmod 600 "$swapfile"
  mkswap "$swapfile" >/dev/null
  swapon "$swapfile"
  if ! grep -qF "$swapfile none swap sw 0 0" /etc/fstab; then
    echo "$swapfile none swap sw 0 0" >> /etc/fstab
  fi
  ok "Swap enabled (4G swapfile)"
}

# ---------- header ----------
echo "JetBrains Remote Dev + Remote Dev Containers: system check (Ubuntu 22.04 / APT)"
echo "User to check: ${CHECK_USER}"
echo "Mode: $([[ "$DO_INSTALL" -eq 1 ]] && echo 'CHECK + INSTALL missing' || echo 'CHECK only')"
echo

# ---------- OS / distro ----------
if [[ -f /etc/os-release ]]; then
  # shellcheck disable=SC1091
  . /etc/os-release
  info "OS: ${PRETTY_NAME:-unknown}"
  if [[ "${ID:-}" == "ubuntu" && "${VERSION_ID:-}" == "22.04" ]]; then
    ok "Ubuntu 22.04 detected"
  else
    warn "This script is targeted at Ubuntu 22.04. Detected: ${ID:-unknown} ${VERSION_ID:-unknown}"
  fi
else
  warn "Cannot read /etc/os-release"
fi

# ---------- CPU / RAM ----------
ARCH="$(uname -m 2>/dev/null || echo unknown)"
CORES="$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 0)"
MEM_KB="$(awk '/MemTotal:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)"
MEM_GB=$(( MEM_KB / 1024 / 1024 ))

info "Arch: ${ARCH}; vCPU: ${CORES}; RAM: ~${MEM_GB} GB"

if [[ "$ARCH" == "x86_64" || "$ARCH" == "aarch64" || "$ARCH" == "arm64" ]]; then ok "CPU architecture acceptable (${ARCH})"
else warn "Architecture unusual (expected x86_64/arm64). Detected: ${ARCH}"
fi

if num_ge "$CORES" 4; then ok "CPU cores >= 4"
else fail "CPU cores < 4 (found: $CORES, need: 4)"
fi

if num_ge "$MEM_GB" 8; then ok "RAM >= 8 GB"
else fail "RAM < 8 GB (found: ${MEM_GB} GB, need: 8 GB)"
fi

# ---------- Disk / FS ----------
ROOT_FREE_GB="$(df -PB1G / 2>/dev/null | awk 'NR==2{print $4}' | tr -d 'G' || echo 0)"
ROOT_FS="$(df -T / 2>/dev/null | awk 'NR==2{print $2}' || echo unknown)"
info "Disk free on / : ${ROOT_FREE_GB} GB (fs: ${ROOT_FS})"

if num_ge "$ROOT_FREE_GB" 10; then ok "Free space on / >= 10 GB"
else fail "Free space on / < 10 GB (found: ${ROOT_FREE_GB} GB, need: 10 GB)"
fi

case "$ROOT_FS" in
  nfs|nfs4|cifs|smbfs|fuse.sshfs) fail "Filesystem for / is network-based (${ROOT_FS}) — not recommended" ;;
  *) ok "Filesystem for / is not NFS/SMB (${ROOT_FS})" ;;
esac

# ---------- Required base utilities (install if missing) ----------
info "Checking base utilities..."
ensure_pkgs "base utils" \
  ca-certificates curl wget jq unzip zip xz-utils \
  tar gzip coreutils findutils procps util-linux \
  openssh-client openssh-server

# ---------- Home/cache writable ----------
USER_HOME="$(get_home "$CHECK_USER")"
if [[ -n "$USER_HOME" && -d "$USER_HOME" ]]; then
  info "User home: ${USER_HOME}"
  if sudo -u "$CHECK_USER" bash -lc "mkdir -p '$USER_HOME/.cache' && test -w '$USER_HOME/.cache'"; then
    ok "\$HOME/.cache is writable for ${CHECK_USER}"
  else
    fail "\$HOME/.cache is NOT writable for ${CHECK_USER}"
  fi
else
  fail "Cannot determine home directory for ${CHECK_USER}"
fi

# ---------- SSH daemon + forwarding ----------
if need_cmd sshd; then ok "sshd present"; else fail "sshd missing"; fi

if systemctl is-active --quiet ssh 2>/dev/null || systemctl is-active --quiet sshd 2>/dev/null; then
  ok "SSH service is running"
else
  if [[ "$DO_INSTALL" -eq 1 ]]; then
    if is_root; then
      systemctl enable --now ssh >/dev/null 2>&1 || systemctl enable --now sshd >/dev/null 2>&1 || true
    fi
  fi
  warn "SSH service not running (or different unit name)"
fi

if is_root && need_cmd sshd; then
  ATF="$(sshd -T 2>/dev/null | awk '/^allowtcpforwarding /{print $2}' | tail -n1 || true)"
  if [[ -n "$ATF" ]]; then
    info "sshd AllowTcpForwarding: ${ATF}"
    if [[ "$ATF" == "yes" ]]; then ok "SSH port forwarding enabled"
    else
      if [[ "$DO_INSTALL" -eq 1 ]]; then
        enable_ssh_forwarding
      else
        fail "SSH port forwarding disabled (AllowTcpForwarding ${ATF})"
      fi
    fi
  else
    warn "Could not read AllowTcpForwarding via sshd -T"
  fi
else
  warn "Run as root to validate/auto-fix AllowTcpForwarding (sshd -T)"
fi

# SSH keys (hint)
if [[ -n "$USER_HOME" && -d "$USER_HOME/.ssh" ]]; then
  if [[ -s "$USER_HOME/.ssh/authorized_keys" ]]; then
    ok "authorized_keys exists and not empty for ${CHECK_USER}"
  else
    warn "authorized_keys missing/empty for ${CHECK_USER} (SSH keys recommended)"
  fi
else
  warn "~/.ssh not found for ${CHECK_USER}"
fi

# ---------- Git >= 2.20.1 ----------
ensure_pkg "git" "required for remote dev" || true
if need_cmd git; then
  GIT_VER="$(git --version | awk '{print $3}' || echo 0)"
  info "Git version: ${GIT_VER}"
  if ver_ge "$GIT_VER" "2.20.1"; then ok "Git >= 2.20.1"
  else
    if [[ "$DO_INSTALL" -eq 1 ]]; then
      info "Upgrading git (from Ubuntu repos)..."
      apt_install git
      GIT_VER="$(git --version | awk '{print $3}' || echo 0)"
      if ver_ge "$GIT_VER" "2.20.1"; then ok "Git >= 2.20.1 (after install/upgrade)"
      else fail "Git still < 2.20.1 (found: ${GIT_VER})"
      fi
    else
      fail "Git < 2.20.1 (found: ${GIT_VER})"
    fi
  fi
else
  fail "git not available"
fi

# ---------- Java 17+ (remote host requirement) ----------
# Install JRE headless; JDK is also ok if you prefer.
ensure_pkgs "java 17+ (required)" openjdk-17-jre-headless

if need_cmd java; then
  JAVA_LINE="$(java -version 2>&1 | head -n1 || true)"
  info "Java: ${JAVA_LINE}"
  JAVA_MAJ="$(echo "$JAVA_LINE" | sed -nE 's/.*version "([0-9]+).*/\1/p' | head -n1 || true)"
  if [[ -n "$JAVA_MAJ" ]] && num_ge "$JAVA_MAJ" 17; then ok "Java major version >= 17"
  else fail "Java 17+ required (detected: ${JAVA_LINE})"
  fi
else
  fail "Java not installed"
fi

# ---------- Docker (required for Dev Containers remote) ----------
# APT option (ubuntu): docker.io + containerd + compose plugin
# If you use docker-ce instead — that's fine too; this script just ensures "docker" works.
if ! need_cmd docker; then
  if [[ "$DO_INSTALL" -eq 1 ]]; then
    info "Installing Docker from Ubuntu APT (docker.io + compose plugin)..."
    apt_install docker.io containerd docker-compose-plugin
    systemctl enable --now docker >/dev/null 2>&1 || true
    # add user to docker group (recommended)
    if id -u "$CHECK_USER" >/dev/null 2>&1; then
      usermod -aG docker "$CHECK_USER" 2>/dev/null || true
      ok "Added ${CHECK_USER} to docker group (re-login required)"
    fi
  else
    fail "Docker not installed (required for Dev Containers)"
  fi
else
  ok "Docker CLI present"
fi

if need_cmd docker; then
  if docker info >/dev/null 2>&1; then
    ok "Docker daemon reachable (docker info OK)"
  else
    warn "Docker daemon not reachable as current user. If installed, check: systemctl status docker; user in docker group; re-login."
  fi
fi

# ---------- Common GUI libs often needed in container images (informational) ----------
# On Ubuntu host it's not strictly required, but good to have for IDE helper processes.
info "Checking common X/GUI runtime libs (recommended)..."
ensure_pkgs "recommended libs" \
  libxext6 libxrender1 libxtst6 libxi6 libfreetype6

# ---------- Swap (recommended) ----------
ensure_swap_4g_if_none

# ---------- Network notes ----------
warn "Network bandwidth/latency cannot be validated server-side."
info "JetBrains guideline: server internet down >=50 Mbps; client<->server >=20 Mbps; latency <200ms."

# ---------- Summary ----------
echo
echo "Summary: ${GREEN}${PASS_COUNT} OK${RESET}, ${YELLOW}${WARN_COUNT} WARN${RESET}, ${RED}${FAIL_COUNT} FAIL${RESET}"
if (( FAIL_COUNT > 0 )); then
  echo
  echo "Tip: run with --install to auto-install missing packages via APT:"
  echo "  sudo bash $0 --install"
  exit 2
fi
exit 0
