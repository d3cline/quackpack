#!/usr/bin/env bash
# Run after extracting Quackpack as the Opalstack shell user. Never uses sudo or changes account resources.
set -euo pipefail
script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
project_dir="$(cd -- "$script_dir/.." && pwd)"
bash "$script_dir/install.sh" "$@"
printf '\nYour ordinary PHP + Nginx application directory is the document root:\n%s/\n' "${PUP_DOCROOT:-$project_dir}"
printf 'Assign the application to the root of your HTTPS domain. Add the cron command returned by the installer.\n'
printf 'Private runtime and data default to $HOME/quackpack. Use the private CLI path returned above.\n'
printf 'No .htaccess, Nginx edits, custom server, symlink app, or public/ mapping is needed.\n'
