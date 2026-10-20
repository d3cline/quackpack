#!/usr/bin/env bash
set -euo pipefail
project_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
operation="${1:-install}"
[[ "$operation" == install || "$operation" == repair ]] || { echo 'Use install or repair.' >&2; exit 1; }
if [[ "$operation" == install ]]; then
    : "${PUP_URL:?Set PUP_URL to your public HTTPS domain}"
    : "${PUP_USERNAME:?Set PUP_USERNAME to your permanent federation username}"
    : "${PUP_PASSWORD:?Set PUP_PASSWORD to your owner password (14+ characters)}"
fi
if [[ -n "${PUP_PHP:-}" ]]; then php_bin="$PUP_PHP"; else
    php_bin=''
    for candidate in php85 php84 php83 php82 php; do
        if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -r 'exit(PHP_VERSION_ID>=80200?0:1);'; then php_bin="$(command -v "$candidate")"; break; fi
    done
    [[ -n "$php_bin" ]] || { echo 'PHP 8.2+ is required.' >&2; exit 1; }
fi
"$php_bin" -r 'exit(PHP_VERSION_ID>=80200?0:1);' || { echo 'PUP_PHP must select PHP 8.2+.' >&2; exit 1; }
if [[ "$operation" == install ]]; then
    install_args=(install "--url=$PUP_URL" "--username=$PUP_USERNAME" "--name=${PUP_NAME:-$PUP_USERNAME}")
    [[ -z "${PUP_DATA_DIR:-}" ]] || install_args+=("--data-dir=$PUP_DATA_DIR")
    [[ -z "${PUP_DOCROOT:-}" ]] || install_args+=("--docroot=$PUP_DOCROOT")
else
    install_args=(repair "--source=$project_dir")
fi
install_result="$("$php_bin" "$project_dir/bin/quackpack" "${install_args[@]}")"
printf '%s\n' "$install_result"
private_cli="$(printf '%s' "$install_result" | "$php_bin" -r '$r=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);echo $r["cli"];')"
"$php_bin" "$private_cli" work --seconds=5 --limit=1
"$php_bin" "$private_cli" doctor
