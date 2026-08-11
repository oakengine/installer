#!/usr/bin/env bash
#
# Docker Compose control script.
#
# Loads .env (defaults) and .env.local (local overrides) and runs docker compose.
# `up` always runs `down` first for a clean rebuild (avoids container/user-state
# issues like the webdevops "failed switching to root" error).
#
# Usage:  docker/run.sh up | down | build | logs | ps | shell | ...
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_DIR"

# ---- env loading -----------------------------------------------------------
# Values are parsed verbatim (NOT sourced through bash) so that values like
# "en|de" or "Oak\Core\IndexBundle" survive unchanged. Later files override
# earlier ones; exported vars take precedence over compose's auto-loaded .env.
load_env_file() {
    [ -f "$1" ] || return 0
    local line key val
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in
            ''|\#*) continue ;;            # blank line / comment
            *=*) ;;                          # KEY=VALUE
            *) continue ;;
        esac
        key="${line%%=*}"
        val="${line#*=}"
        key="${key#export }"                 # tolerate "export KEY=..."
        case "$key" in
            ''|*[[:space:]]*) continue ;;    # skip invalid keys
        esac
        export "$key=$val"
    done < "$1"
}

load_env_file "$PROJECT_DIR/.env"
load_env_file "$PROJECT_DIR/.env.local"

export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-$(basename "$PROJECT_DIR")}"

DC=(docker compose)

# ---- helpers ---------------------------------------------------------------
print_ports() {
    [ -n "${WEB_PORT:-}" ]         && printf '  WEB_PORT=%s\n'         "$WEB_PORT"
    [ -n "${WEB_PORT_HTTPS:-}" ]   && printf '  WEB_PORT_HTTPS=%s\n'   "$WEB_PORT_HTTPS"
    [ -n "${MAIL_SMTP_PORT:-}" ]   && printf '  MAIL_SMTP_PORT=%s\n'   "$MAIL_SMTP_PORT"
    [ -n "${MAIL_UI_PORT:-}" ]     && printf '  MAIL_UI_PORT=%s\n'     "$MAIL_UI_PORT"
}

usage() {
    cat <<EOF
$(basename "$0") - Docker Compose control for: $(basename "$PROJECT_DIR")
(compose project: $COMPOSE_PROJECT_NAME)

Effective ports (.env + .env.local overrides):
$(print_ports)

Commands:
  up            clean start: down -> up -d --build   (alias: start)
  down          stop & remove containers             (alias: stop)
  restart       down -> up -d --build
  build         build images only
  logs [svc]    follow logs (default: web)
  ps            show container status
  shell         bash into web container (user: application)
  root-shell    bash into web container (user: root)
  exec -- CMD   run CMD in web container
  clean         down -v  (WARNING: removes named volumes)
  ports         show effective ports
  help          this help

Any other argument is forwarded to: docker compose
EOF
}

# ---- commands --------------------------------------------------------------
case "${1:-help}" in
    up|start)
        "${DC[@]}" down >/dev/null 2>&1 || true
        "${DC[@]}" up -d --build
        "${DC[@]}" ps
        ;;
    down|stop)
        "${DC[@]}" down
        ;;
    restart)
        "${DC[@]}" down >/dev/null 2>&1 || true
        "${DC[@]}" up -d --build
        ;;
    build)
        "${DC[@]}" build
        ;;
    logs)
        shift || true
        if [ "$#" -gt 0 ]; then "${DC[@]}" logs -f "$@"; else "${DC[@]}" logs -f web; fi
        ;;
    ps)
        "${DC[@]}" ps
        ;;
    shell|bash)
        "${DC[@]}" exec web bash
        ;;
    root-shell|root)
        "${DC[@]}" exec --user=root web bash
        ;;
    exec)
        shift || true
        "${DC[@]}" exec web "$@"
        ;;
    clean)
        "${DC[@]}" down -v
        ;;
    ports)
        print_ports
        ;;
    help|-h|--help|"")
        usage
        ;;
    *)
        "${DC[@]}" "$@"
        ;;
esac
