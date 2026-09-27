#!/usr/bin/env sh
#
# The two RSA keypairs this application signs tokens with (#1309).
#
# Lexik signs the PWA session JWT; `league/oauth2-server` signs the access tokens that open
# /mcp. ADR-079 keeps them apart on purpose: a session token and an agent token are the same
# shape from the same issuer, and what tells them apart is the KEY. So the two pairs are
# generated independently here, never derived from one another — the property "they differ"
# holds by construction rather than by care.
#
# This exists because the same four openssl lines were written out nine times — in the
# Makefile, in four CI jobs, in the lighthouse workflow — with the passphrase, the output
# directory and even the presence of a passphrase drifting between copies.
#
# POSIX sh, no bashisms: some callers run on the GitHub runner, others inside
# docker.io/esoledad/php, and this should not depend on which of them ships bash.
#
# One site is deliberately NOT a caller: .docker/php/docker-entrypoint.sh. Its JWT half is
# `lexik:jwt:generate-keypair`, not openssl, and this script lives in the repository rather
# than in the image — copying it in would be a Dockerfile change reaching production for four
# lines.
#
# Usage:
#   generate-keypairs.sh [--jwt <dir>] [--oauth <dir>] [--passphrase <p>] [--force]
#
#   At least one of --jwt / --oauth. Without --passphrase the keys are unencrypted (which is
#   what the PHPStan job wants: it only needs a file League\OAuth2\Server\CryptKey can open).
#   Without --force an existing pair is left alone.
#
# --passphrase really encrypts, with -aes256. Worth saying because the nine call sites this
# replaces did NOT: `openssl genpkey -pass pass:x` without a cipher flag silently ignores the
# passphrase and writes `-----BEGIN PRIVATE KEY-----`. Every dev and CI key in this repository
# is unencrypted today despite the flag, and the matching JWT_PASSPHRASE / OAUTH_PASSPHRASE are
# decorative there. Only the Vault recipe, which spells out -aes256, produces a real one — so
# the shape production actually runs on has never been exercised by a test. It is now.

set -eu

BITS=4096

jwt_dir=""
oauth_dir=""
passphrase=""
force=0

usage() {
	echo "usage: $0 [--jwt <dir>] [--oauth <dir>] [--passphrase <p>] [--force]" >&2
	exit 2
}

while [ $# -gt 0 ]; do
	case "$1" in
		--jwt) [ $# -ge 2 ] || usage; jwt_dir="$2"; shift 2 ;;
		--oauth) [ $# -ge 2 ] || usage; oauth_dir="$2"; shift 2 ;;
		--passphrase) [ $# -ge 2 ] || usage; passphrase="$2"; shift 2 ;;
		--force) force=1; shift ;;
		-h|--help) usage ;;
		*) echo "$0: unknown argument '$1'" >&2; usage ;;
	esac
done

if [ -z "$jwt_dir" ] && [ -z "$oauth_dir" ]; then
	echo "$0: nothing to do — pass --jwt, --oauth, or both" >&2
	usage
fi

# One pair in one directory. Called at most twice, and the two calls never share state, so
# the two pairs cannot end up being the same key.
generate_pair() {
	dir="$1"

	if [ "$force" -eq 0 ] && [ -f "$dir/private.pem" ] && [ -f "$dir/public.pem" ]; then
		echo "$dir: keypair already present, left untouched" >&2
		return 0
	fi

	mkdir -p "$dir"

	if [ -n "$passphrase" ]; then
		openssl genpkey -algorithm RSA -out "$dir/private.pem" -pkeyopt "rsa_keygen_bits:$BITS" \
			-aes256 -pass "pass:$passphrase"
		openssl rsa -pubout -in "$dir/private.pem" -out "$dir/public.pem" -passin "pass:$passphrase"
	else
		openssl genpkey -algorithm RSA -out "$dir/private.pem" -pkeyopt "rsa_keygen_bits:$BITS"
		openssl rsa -pubout -in "$dir/private.pem" -out "$dir/public.pem"
	fi

	echo "$dir: keypair written" >&2
}

# Plain `if`, not `[ … ] && …`: under `set -e` a failing test at the head of an AND-OR list
# leaves the list's status non-zero, and an empty --jwt would take the whole script out before
# the OAuth pair was ever generated.
if [ -n "$jwt_dir" ]; then
	generate_pair "$jwt_dir"
fi

if [ -n "$oauth_dir" ]; then
	generate_pair "$oauth_dir"
fi
