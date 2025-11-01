#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VENV_DIR="/opt/spc-venv"
PYTHON_BIN="${PYTHON_BIN:-$(command -v python3 || true)}"
PROGRESS_FILE=""
LOG_PREFIX="[acreexp][install]"

show_help() {
    cat <<'USAGE'
Usage: install.sh --install [--progress-file <path>]
       install.sh --remove [--progress-file <path>]
USAGE
}

log() {
    local level="$1"; shift || true
    echo "${LOG_PREFIX} ${level}: $*"
}

update_progress() {
    local value="$1"
    if [[ -n "${PROGRESS_FILE}" ]]; then
        echo "${value}" > "${PROGRESS_FILE}" 2>/dev/null || true
    fi
}

ensure_python() {
    if [[ -n "${PYTHON_BIN}" && -x "${PYTHON_BIN}" ]]; then
        return 0
    fi
    log "ERROR" "Python3 introuvable. Veuillez installer python3."
    return 1
}

create_venv() {
    update_progress 10
    log "INFO" "Création / mise à jour de l'environnement virtuel ${VENV_DIR}"

    mkdir -p "${VENV_DIR}"
    "${PYTHON_BIN}" -m venv "${VENV_DIR}"

    source "${VENV_DIR}/bin/activate"
    update_progress 40

    log "INFO" "Mise à jour de pip"
    pip install --upgrade pip setuptools wheel >/dev/null
    update_progress 60

    log "INFO" "Installation des dépendances Python"
    pip install --upgrade \
        requests \
        PyYAML \
        beautifulsoup4 \
        paho-mqtt >/dev/null
    update_progress 90

    deactivate || true
    log "INFO" "Environnement virtuel prêt"
}

remove_venv() {
    if [[ -d "${VENV_DIR}" ]]; then
        log "INFO" "Suppression de l'environnement virtuel ${VENV_DIR}"
        rm -rf "${VENV_DIR}"
    else
        log "INFO" "Aucun environnement virtuel à supprimer"
    fi
    update_progress 100
}

main() {
    local action=""

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --install|--reinstall)
                action="install"
                ;;
            --remove|--uninstall)
                action="remove"
                ;;
            --progress-file)
                shift
                PROGRESS_FILE="${1:-}"
                ;;
            -h|--help)
                show_help
                exit 0
                ;;
            *)
                log "WARNING" "Option inconnue: $1"
                ;;
        esac
        shift || true
    done

    if [[ "${action}" == "install" ]]; then
        update_progress 0
        ensure_python
        create_venv
        update_progress 100
        return 0
    elif [[ "${action}" == "remove" ]]; then
        remove_venv
        return 0
    else
        show_help
        return 1
    fi
}

main "$@"
