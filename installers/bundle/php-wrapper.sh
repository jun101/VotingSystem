#!/bin/sh
# bin/php of the Linux bundle: the command line is the FrankenPHP binary in php-cli mode, so server and CLI are one PHP.
# PHP_BINARY would be the frankenphp file itself, and a command that starts PHP again (artisan schedule:work runs
# schedule:run as a child) would call `frankenphp artisan ...`. Symfony's finder, which Laravel uses, honours this variable first.
self="$(readlink -f "$0")"
PHP_BINARY="$self"
export PHP_BINARY
exec "$(dirname "$self")/frankenphp" php-cli "$@"
