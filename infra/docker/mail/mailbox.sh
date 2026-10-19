#!/bin/sh
# Mailboxes and aliases of the mail domain, kept in /var/mail/config (volume mail-data).
#   mailbox list
#   mailbox add <address>            create a mailbox; asks for the password
#   mailbox passwd <address>         change its password
#   mailbox del <address>            remove the login; the stored letters stay in /var/mail/vhosts
#   mailbox alias <address> <target> [<target> ...]   deliver <address> to the targets; their owners may also send as it
#   mailbox unalias <address>
set -eu

DIR=/var/mail/config
USERS="$DIR/users"
ALIASES="$DIR/aliases"
DOMAIN="${MAIL_DOMAIN:?MAIL_DOMAIN is required}"

mkdir -p "$DIR"
touch "$USERS" "$ALIASES"
chown root:dovecot "$USERS"
chmod 640 "$USERS"

fail() {
    echo "$*" >&2
    exit 1
}

address() {
    value="$(printf '%s' "${1:-}" | tr 'A-Z' 'a-z')"
    case "$value" in
        *[!a-z0-9._+@-]* | @* | *@*@* | *..*) fail "Invalid address: ${1:-}" ;;
        *@"$DOMAIN") printf '%s' "$value" ;;
        *) fail "The address must end with @$DOMAIN: ${1:-}" ;;
    esac
}

exists() {
    grep -q "^$1[: ]" "$2"
}

password() {
    [ -t 0 ] || fail "Run it in a terminal: docker compose exec mail mailbox $1 <address>"
    stty -echo
    printf 'Password: ' >&2
    read -r first
    printf '\nRepeat: ' >&2
    read -r second
    stty echo
    printf '\n' >&2
    [ "$first" = "$second" ] || fail "The passwords differ."
    [ "${#first}" -ge 12 ] || fail "Use at least 12 characters."
    doveadm pw -s SHA512-CRYPT -p "$first"
}

without() {
    grep -v "^$1[: ]" "$2" > "$2.new" || true
    cat "$2.new" > "$2"
    rm "$2.new"
}

rebuild() {
    awk -F: 'NF { print $1 " OK" }' "$USERS" > "$DIR/mailboxes"
    {
        awk -F: 'NF { print $1 " " $1 }' "$USERS"
        awk 'NF { line = $1 " "; sep = ""; for (i = 2; i <= NF; i++) { gsub(",", "", $i); line = line sep $i; sep = "," } print line }' "$ALIASES"
    } > "$DIR/senders"
    if postfix status >/dev/null 2>&1; then
        postfix reload >/dev/null 2>&1
    fi
}

command="${1:-list}"
[ "$#" -gt 0 ] && shift

case "$command" in
    list)
        echo "Mailboxes:"
        awk -F: 'NF { print "  " $1 }' "$USERS"
        echo "Aliases:"
        awk 'NF { print "  " $0 }' "$ALIASES"
        ;;
    add)
        user="$(address "${1:-}")"
        exists "$user" "$USERS" && fail "$user already exists."
        exists "$user" "$ALIASES" && fail "$user is an alias; remove it first."
        hash="$(password add)"
        printf '%s:%s\n' "$user" "$hash" >> "$USERS"
        rebuild
        echo "Created $user."
        ;;
    passwd)
        user="$(address "${1:-}")"
        exists "$user" "$USERS" || fail "No mailbox $user."
        hash="$(password passwd)"
        without "$user" "$USERS"
        printf '%s:%s\n' "$user" "$hash" >> "$USERS"
        doveadm kick "$user" >/dev/null 2>&1 || true
        echo "Password of $user changed."
        ;;
    del)
        user="$(address "${1:-}")"
        exists "$user" "$USERS" || fail "No mailbox $user."
        without "$user" "$USERS"
        rebuild
        doveadm kick "$user" >/dev/null 2>&1 || true
        echo "Removed the login $user; its letters stay in /var/mail/vhosts/$DOMAIN."
        ;;
    alias)
        name="$(address "${1:-}")"
        shift || true
        [ "$#" -gt 0 ] || fail "Name at least one target."
        exists "$name" "$USERS" && fail "$name is a mailbox."
        targets=""
        for target in "$@"; do
            case "$target" in
                *@"$DOMAIN") target="$(address "$target")"; exists "$target" "$USERS" || fail "No mailbox $target." ;;
                *@*.*) ;;
                *) fail "Invalid target: $target" ;;
            esac
            targets="${targets:+$targets, }$target"
        done
        without "$name" "$ALIASES"
        printf '%s %s\n' "$name" "$targets" >> "$ALIASES"
        rebuild
        echo "$name → $targets"
        ;;
    unalias)
        name="$(address "${1:-}")"
        exists "$name" "$ALIASES" || fail "No alias $name."
        without "$name" "$ALIASES"
        rebuild
        echo "Removed the alias $name."
        ;;
    rebuild)
        rebuild
        ;;
    *)
        fail "Unknown command: $command"
        ;;
esac
