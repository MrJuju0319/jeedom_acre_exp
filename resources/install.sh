#!/bin/bash
set -euo pipefail

ACTION="${1:-}" 
BASE_DIR="$(cd "$(dirname "$0")" && pwd)"
DATA_DIR="$(cd "${BASE_DIR}/.." && pwd)/data"
LOG_PREFIX="[acreexp]"

log() {
  echo "${LOG_PREFIX} $*"
}

ensure_python() {
  if command -v python3 >/dev/null 2>&1; then
    PY_BIN=$(command -v python3)
  elif command -v python >/dev/null 2>&1; then
    PY_BIN=$(command -v python)
  else
    log "Python 3 est requis pour le plugin."
    return 1
  fi
  log "Binaire Python détecté : ${PY_BIN}"
  if ! "${PY_BIN}" -m venv --help >/dev/null 2>&1; then
    log "Le module venv est indisponible pour ${PY_BIN}."
    return 1
  fi
  return 0
}

install() {
  mkdir -p "${DATA_DIR}"
  ensure_python
}

remove() {
  log "Nettoyage non nécessaire (les environnements seront supprimés en même temps que le plugin)."
}

case "${ACTION}" in
  --install)
    install
    ;;
  --remove)
    remove
    ;;
  "")
    echo "Usage: $0 --install|--remove" >&2
    exit 1
    ;;
  *)
    echo "Option inconnue : ${ACTION}" >&2
    exit 1
    ;;
esac
