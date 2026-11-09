#!/usr/bin/env bash
set -euo pipefail

smoke_dir="$(mktemp -d)"
smoke_port=18765

MYSQLAPI_ENV_FILE="${smoke_dir}/.env" \
MYSQLAPI_ALLOW_HTTP=1 \
php -S "127.0.0.1:${smoke_port}" -t . >"${smoke_dir}/server.log" 2>&1 &
smoke_server_pid=$!
trap 'kill "$smoke_server_pid" 2>/dev/null || true' EXIT

for attempt in 1 2 3 4 5 6 7 8 9 10; do
  if curl -fsS "http://127.0.0.1:${smoke_port}/" -o "${smoke_dir}/before.html"; then
    break
  fi
  sleep 1
done

grep -q 'Instalação' "${smoke_dir}/before.html"
shopt -s nullglob
smoke_token_files=("${smoke_dir}"/.mysqlapi-*-install-token)
test "${#smoke_token_files[@]}" = 1
smoke_token="$(<"${smoke_token_files[0]}")"

curl -fsS -X POST "http://127.0.0.1:${smoke_port}/?install" \
  --data-urlencode "install_token=${smoke_token}" \
  --data-urlencode 'allowed_origins=https://app.example.com' \
  -o "${smoke_dir}/result.html"
grep -q 'Instalação concluída' "${smoke_dir}/result.html"
test ! -e "${smoke_token_files[0]}"

curl -fsS "http://127.0.0.1:${smoke_port}/" -o "${smoke_dir}/after.html"
grep -q 'Seu banco de dados' "${smoke_dir}/after.html"

curl -fsS "http://127.0.0.1:${smoke_port}/?public-key" -o "${smoke_dir}/key.json"
node -e 'const fs=require("fs"); const v=JSON.parse(fs.readFileSync(process.argv[1],"utf8")); if(!v.publicKey || !v.kid || v.version!==1)process.exit(1)' "${smoke_dir}/key.json"

auth_status="$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d '{}' "http://127.0.0.1:${smoke_port}/")"
test "$auth_status" = 401

echo 'OK: GET installer -> POST install -> GET token generator and public key'
