#!/bin/sh
# Local security scans, every tool as a throw-away container (only Docker is needed).
# Usage: tools/security/run.sh [secrets|code|images|zap|all]
# Reports go to tools/security/reports (ignored by git). Exit code 1 if a scan finds something.
set -u
cd "$(dirname "$0")/../.."
ROOT=$(pwd)
OUT="$ROOT/tools/security/reports"
mkdir -p "$OUT"
UID_GID="$(id -u):$(id -g)"
GITLEAKS_IMAGE=ghcr.io/gitleaks/gitleaks:latest
SEMGREP_IMAGE=semgrep/semgrep:latest
TRIVY_IMAGE=aquasec/trivy:latest
ZAP_IMAGE=ghcr.io/zaproxy/zaproxy:stable
status=0

secrets() {
  echo "== gitleaks: secrets in the files and the git history"
  docker run --rm --user "$UID_GID" -v "$ROOT":/repo -w /repo "$GITLEAKS_IMAGE" \
    git --no-banner --redact -c tools/security/gitleaks.toml \
    --report-format json --report-path /repo/tools/security/reports/gitleaks.json /repo || status=1
}

code() {
  echo "== semgrep: static analysis of api/app and web/src"
  docker run --rm --user "$UID_GID" -v "$ROOT":/src -w /src -e HOME=/tmp "$SEMGREP_IMAGE" \
    semgrep scan --config p/php --config p/typescript --config p/react --config p/owasp-top-ten \
    --config p/secrets --error --metrics=off --exclude node_modules --exclude vendor \
    --exclude tests --exclude e2e --exclude '*.test.*' \
    --json --output tools/security/reports/semgrep.json api/app web/src docker installers || status=1
}

images() {
  echo "== trivy: dependency files, Dockerfiles, and the production web image"
  docker run --rm --user "$UID_GID" -e TRIVY_CACHE_DIR=/tmp/trivy -v "$ROOT":/src -w /src "$TRIVY_IMAGE" \
    fs --scanners vuln,secret,misconfig --ignore-unfixed --severity HIGH,CRITICAL --exit-code 1 \
    --skip-dirs node_modules --skip-dirs api/vendor --skip-dirs installers/bundle/stage --skip-dirs web/.next \
    --format json --output tools/security/reports/trivy-fs.json . || status=1
  if docker image inspect "${SCAN_WEB_IMAGE:-votesystem-web:prod}" >/dev/null 2>&1; then
    docker run --rm -e TRIVY_CACHE_DIR=/tmp/trivy -v /var/run/docker.sock:/var/run/docker.sock \
      -v "$OUT":/out "$TRIVY_IMAGE" image --ignore-unfixed --severity HIGH,CRITICAL --exit-code 1 \
      --format json --output /out/trivy-web-image.json "${SCAN_WEB_IMAGE:-votesystem-web:prod}" || status=1
  else
    echo "   (skipped the web image: run make build first)"
  fi
}

zap() {
  echo "== OWASP ZAP baseline against the running stack (passive scan, no attack payloads)"
  PORT=$(grep -E '^HTTP_PORT=' .env 2>/dev/null | cut -d= -f2); PORT=${PORT:-8080}
  chmod 777 "$OUT"
  docker run --rm --network host -v "$OUT":/zap/wrk:rw "$ZAP_IMAGE" \
    zap-baseline.py -t "http://127.0.0.1:$PORT" -J zap.json -r zap.html -I -m 3 || status=1
}

case "${1:-all}" in
  secrets) secrets ;;
  code) code ;;
  images) images ;;
  zap) zap ;;
  all) secrets; code; images; zap ;;
  *) echo "usage: $0 [secrets|code|images|zap|all]"; exit 2 ;;
esac
echo; [ "$status" -eq 0 ] && echo "security scans: clean" || echo "security scans: findings, see $OUT"
exit "$status"
