#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
VERSION="${VERSION:-1.0.0-rc.1}"
DIST_DIR="${ROOT_DIR}/dist"
PLUGIN_DIR_NAME="aurora-enterprise"
ZIP_FILE="${DIST_DIR}/${PLUGIN_DIR_NAME}-${VERSION}.zip"
SHA_FILE="${ZIP_FILE}.sha256"
SIZE_FILE="${ZIP_FILE}.size.txt"

mkdir -p "${DIST_DIR}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

if ! command -v zip >/dev/null 2>&1; then
  echo "zip command not found" >&2
  exit 1
fi

if ! command -v shasum >/dev/null 2>&1; then
  echo "shasum command not found" >&2
  exit 1
fi

cd "${ROOT_DIR}"
git archive --format=tar --prefix="${PLUGIN_DIR_NAME}/" HEAD | tar -xf - -C "${TMP_DIR}"

cd "${TMP_DIR}"
rm -f "${ZIP_FILE}"
zip -qr "${ZIP_FILE}" "${PLUGIN_DIR_NAME}"

shasum -a 256 "${ZIP_FILE}" > "${SHA_FILE}"
wc -c "${ZIP_FILE}" > "${SIZE_FILE}"

echo "zip=${ZIP_FILE}"
echo "sha256=$(cat "${SHA_FILE}")"
echo "size=$(cat "${SIZE_FILE}")"
