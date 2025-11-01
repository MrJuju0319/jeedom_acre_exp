#!/bin/bash

set -euo pipefail

VENV_DIR="/opt/spc-venv"
PYTHON_BIN="${PYTHON_BIN:-$(command -v python3 || true)}"
PROGRESS_FILE="${JEEDOM_DEPENDENCY_PROGRESS_FILE:-}"
LOG_PREFIX="[acreexp][install]"

log() {
    local level="$1"
    shift || true
    echo "${LOG_PREFIX} ${level}: $*"
}

update_progress() {
    local value="$1"
    if [[ -n "${PROGRESS_FILE}" ]]; then
        echo "${value}" > "${PROGRESS_FILE}" 2>/dev/null || true
    fi
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --progress-file)
            shift
            PROGRESS_FILE="${1:-}"
            ;;
        -p)
            shift
            PROGRESS_FILE="${1:-}"
            ;;
        --progress-file=*)
            PROGRESS_FILE="${1#*=}"
            ;;
        *)
            if [[ -z "${PROGRESS_FILE}" ]]; then
                PROGRESS_FILE="$1"
            fi
            ;;
    esac
    shift || true
done

if [[ -z "${PYTHON_BIN}" ]]; then
    log "ERROR" "Python3 introuvable. Abandon."
    exit 1
fi

log "INFO" "Initialisation de l'environnement Python (${VENV_DIR})"
update_progress 5

mkdir -p "${VENV_DIR}"
"${PYTHON_BIN}" -m venv "${VENV_DIR}"

update_progress 25

if [[ ! -x "${VENV_DIR}/bin/pip" ]]; then
    log "ERROR" "pip introuvable dans l'environnement virtuel"
    exit 1
fi

source "${VENV_DIR}/bin/activate"

log "INFO" "Mise à jour de pip et des outils de build"
"${VENV_DIR}/bin/pip" install --upgrade pip setuptools wheel
update_progress 55

log "INFO" "Installation / mise à jour des dépendances Python"
"${VENV_DIR}/bin/pip" install --upgrade \
    requests \
    PyYAML \
    beautifulsoup4 \
    'paho-mqtt>=1.6'
update_progress 90

deactivate || true

update_progress 100
log "INFO" "Installation des dépendances terminée"

exit 0
