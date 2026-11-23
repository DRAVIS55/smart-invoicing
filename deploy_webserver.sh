#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

APP_NAME="Ledger sales invoicing"
DEFAULT_PORT=8081
SUDO=()
DOCKER=(docker)

info() { printf '\n\033[1;34m[deploy]\033[0m %s\n' "$*"; }
warn() { printf '\n\033[1;33m[deploy warning]\033[0m %s\n' "$*" >&2; }
fail() { printf '\n\033[1;31m[deploy error]\033[0m %s\n' "$*" >&2; exit 1; }
run_docker() { "${DOCKER[@]}" "$@"; }
run_compose() { run_docker compose "$@"; }

on_error() {
    local status=$?
    trap - ERR
    printf '\n\033[1;31m[deploy error]\033[0m Setup stopped on line %s (exit %s).\n' "${BASH_LINENO[0]:-unknown}" "$status" >&2
    if command -v docker >/dev/null 2>&1 && [[ -f "$PROJECT_DIR/docker-compose.yml" ]]; then
        printf '\nRecent Compose status:\n' >&2
        run_compose ps >&2 || true
        printf '\nRecent web/database logs:\n' >&2
        run_compose logs --tail=35 web db >&2 || true
    fi
    exit "$status"
}
trap on_error ERR

[[ -f "$PROJECT_DIR/docker-compose.yml" ]] || fail "docker-compose.yml is missing from $PROJECT_DIR. Run this script from the project checkout."
[[ -f "$PROJECT_DIR/Dockerfile" ]] || fail "Dockerfile is missing from the project checkout."

if [[ "$EUID" -ne 0 ]]; then
    command -v sudo >/dev/null 2>&1 || fail "Install sudo or run this script as root."
    SUDO=(sudo)
    info "Administrator access is required to install/start Docker and configure an active firewall."
    sudo -v
fi

install_host_tools() {
    local missing=()
    command -v curl >/dev/null 2>&1 || missing+=(curl)
    command -v openssl >/dev/null 2>&1 || missing+=(openssl)
    command -v ip >/dev/null 2>&1 || missing+=(iproute2)
    command -v ss >/dev/null 2>&1 || missing+=(iproute2)
    ((${#missing[@]} == 0)) && return 0
    command -v apt-get >/dev/null 2>&1 || fail "Missing host tools: ${missing[*]}. Install them with your distribution package manager."
    info "Installing host utilities: ${missing[*]}"
    "${SUDO[@]}" apt-get update
    "${SUDO[@]}" apt-get install -y ca-certificates curl openssl iproute2
}

docker_works() {
    command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1
}

install_docker_engine() {
    command -v apt-get >/dev/null 2>&1 || fail "Docker Engine or Docker Compose is missing. This installer currently supports Debian and Ubuntu hosts with apt. Install Docker Engine with the Compose plugin, then rerun this script."
    [[ -r /etc/os-release ]] || fail "Cannot identify this Linux distribution. Install Docker Engine and the Compose plugin, then rerun this script."
    # shellcheck disable=SC1091
    source /etc/os-release
    case "${ID:-}" in
        debian|ubuntu) ;;
        *) fail "Automatic Docker installation supports Debian and Ubuntu. Detected '${ID:-unknown}'. Install Docker Engine and Compose manually, then rerun this script." ;;
    esac
    local codename="${VERSION_CODENAME:-}"
    [[ -n "$codename" ]] || fail "Could not determine the OS release codename needed for Docker's package repository."
    info "Installing Docker Engine and the Docker Compose plugin for $ID $codename."
    "${SUDO[@]}" apt-get update
    "${SUDO[@]}" apt-get install -y ca-certificates curl
    "${SUDO[@]}" install -m 0755 -d /etc/apt/keyrings
    curl -fsSL "https://download.docker.com/linux/$ID/gpg" | "${SUDO[@]}" tee /etc/apt/keyrings/docker.asc >/dev/null
    "${SUDO[@]}" chmod a+r /etc/apt/keyrings/docker.asc
    local arch
    arch="$(dpkg --print-architecture)"
    printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/%s %s stable\n' "$arch" "$ID" "$codename" | "${SUDO[@]}" tee /etc/apt/sources.list.d/docker.list >/dev/null
    "${SUDO[@]}" apt-get update
    "${SUDO[@]}" apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    if command -v systemctl >/dev/null 2>&1; then
        "${SUDO[@]}" systemctl enable --now docker
    else
        "${SUDO[@]}" service docker start
    fi
}

install_host_tools
if ! docker_works; then install_docker_engine; fi

if docker info >/dev/null 2>&1; then
    DOCKER=(docker)
elif [[ ${#SUDO[@]} -gt 0 ]] && sudo docker info >/dev/null 2>&1; then
    DOCKER=(sudo docker)
else
    if command -v systemctl >/dev/null 2>&1; then "${SUDO[@]}" systemctl start docker; else "${SUDO[@]}" service docker start; fi
    if docker info >/dev/null 2>&1; then DOCKER=(docker); else DOCKER=("${SUDO[@]}" docker); fi
fi
run_docker info >/dev/null || fail "Docker daemon is installed but unavailable. Check `systemctl status docker` and rerun this script."
run_docker compose version

read_env() {
    local key="$1"
    [[ -f "$PROJECT_DIR/.env" ]] || return 0
    awk -F= -v wanted="$key" '$1 == wanted {sub(/^[^=]*=/, ""); gsub(/^"|"$/, ""); gsub(/^\047|\047$/, ""); print; exit}' "$PROJECT_DIR/.env"
}

write_env() {
    local key="$1" value="$2"
    if [[ -f "$PROJECT_DIR/.env" ]] && grep -q "^${key}=" "$PROJECT_DIR/.env"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$PROJECT_DIR/.env"
    else
        printf '%s=%s\n' "$key" "$value" >> "$PROJECT_DIR/.env"
    fi
}

if [[ ! -f "$PROJECT_DIR/.env" ]]; then
    info "Creating local deployment settings and a strong administrator recovery password."
    umask 077
    cat > "$PROJECT_DIR/.env" <<EOF
APP_USERNAME=admin
APP_PASSWORD=$(openssl rand -hex 24)
APP_CURRENCY=KES
APP_PORT=$DEFAULT_PORT
EOF
    chmod 600 "$PROJECT_DIR/.env"
fi

app_password="$(read_env APP_PASSWORD)"
if [[ -z "$app_password" || "$app_password" == "replace-before-deploy" || "$app_password" == "change-me-now" ]]; then
    app_password="$(openssl rand -hex 24)"
    write_env APP_PASSWORD "$app_password"
    chmod 600 "$PROJECT_DIR/.env"
    info "Replaced the sample recovery password with a generated password."
fi

app_port="$(read_env APP_PORT)"
app_port="${app_port:-$DEFAULT_PORT}"
[[ "$app_port" =~ ^[0-9]+$ ]] && ((app_port >= 1024 && app_port <= 65535)) || fail "APP_PORT must be a number between 1024 and 65535."

port_is_listening() {
    local port="$1"
    ss -H -ltn 2>/dev/null | awk -v pattern=":$port$" '$4 ~ pattern {found=1} END {exit !found}' && return 0
    run_docker ps --format '{{.Ports}}' 2>/dev/null | grep -Eq ":${port}->"
}
web_container="$(run_compose ps -q web 2>/dev/null || true)"
current_web_port=""
if [[ -n "$web_container" ]]; then
    current_web_port="$(run_docker port "$web_container" 80/tcp 2>/dev/null | sed -n 's/.*:\([0-9][0-9]*\)$/\1/p' | head -n1 || true)"
fi
if port_is_listening "$app_port" && [[ "$current_web_port" != "$app_port" ]]; then
    requested_port="$app_port"
    while port_is_listening "$app_port"; do ((app_port+=1)); done
    ((app_port <= 65535)) || fail "No free TCP port was found after $requested_port. Set APP_PORT in .env and rerun."
    write_env APP_PORT "$app_port"
    info "Port $requested_port is already occupied; using available port $app_port instead."
fi

info "Checking the Compose configuration."
run_compose config --quiet

info "Building and starting $APP_NAME. Apache and PHP run in the web container; MySQL runs in its own persistent container."
run_compose up -d --build

info "Waiting for MySQL to become healthy."
db_container="$(run_compose ps -q db)"
db_ready=0
for _ in $(seq 1 60); do
    db_status="$(run_docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$db_container" 2>/dev/null || true)"
    if [[ "$db_status" == "healthy" ]]; then db_ready=1; break; fi
    sleep 2
done
[[ "$db_ready" -eq 1 ]] || fail "MySQL did not become healthy within 120 seconds. See the database logs above."

info "Checking HTTP at localhost:$app_port."
http_ready=0
for _ in $(seq 1 45); do
    status="$(curl -sS --connect-timeout 2 --max-time 5 -o /dev/null -w '%{http_code}' "http://127.0.0.1:$app_port/" 2>/dev/null || true)"
    if [[ "$status" == "200" ]]; then http_ready=1; break; fi
    sleep 2
done
[[ "$http_ready" -eq 1 ]] || fail "The web service did not return HTTP 200 on port $app_port. Check the web logs above."

if command -v ufw >/dev/null 2>&1 && "${SUDO[@]}" ufw status 2>/dev/null | grep -q 'Status: active'; then
    info "Allowing TCP port $app_port through the active UFW firewall."
    "${SUDO[@]}" ufw allow "$app_port/tcp" comment 'Ledger invoicing web app'
elif command -v firewall-cmd >/dev/null 2>&1 && "${SUDO[@]}" firewall-cmd --state >/dev/null 2>&1; then
    info "Allowing TCP port $app_port through the active firewalld firewall."
    "${SUDO[@]}" firewall-cmd --permanent --add-port="$app_port/tcp"
    "${SUDO[@]}" firewall-cmd --reload
fi

lan_ip="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="src") {print $(i+1); exit}}')"
if [[ -z "$lan_ip" ]]; then lan_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"; fi
public_ip="$(curl -4fsS --connect-timeout 3 --max-time 7 https://api.ipify.org 2>/dev/null || true)"

printf '\n\033[1;32m%s is ready.\033[0m\n' "$APP_NAME"
printf '  On this machine:  http://localhost:%s/\n' "$app_port"
if [[ -n "$lan_ip" ]]; then printf '  On this network:  http://%s:%s/\n' "$lan_ip" "$app_port"; fi
if [[ -n "$public_ip" ]]; then
    printf '  Public address:   http://%s:%s/\n' "$public_ip" "$app_port"
    printf '  External access requires this host to have a reachable public address. If it is behind a router or carrier NAT, forward TCP %s to this machine or use a public tunnel/domain.\n' "$app_port"
else
    printf '  Public address:   Could not be detected. Check outbound internet access, then rerun to display it.\n'
fi
printf '  App login:        create the owner account on the homepage, or visit /register.\n'
printf '  Recovery login:   %s / %s (stored in .env).\n' "$(read_env APP_USERNAME)" "$app_password"
printf '\nKeep .env private. It contains the administrator recovery password.\n'
