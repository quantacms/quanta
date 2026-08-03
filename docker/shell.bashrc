# ── Quanta container: interactive shell ───────────────────────────────────────
# Sourced from /root/.bashrc and /etc/profile.d/00-quanta-shell.sh, for
# interactive shells only — scripts, `docker exec <c> <cmd>` and the entrypoint
# never reach the code below.
#
# Why: the container's own processes need root (nginx binds :80, the entrypoint
# chowns the site tree), but a human at a shell does not. A `composer install`,
# a `php doctor` or an editor run as root leaves root-owned files behind in the
# site and static volumes, which the php-fpm workers (www-data) can then no
# longer write — the classic "worked in the shell, 500s in the browser". So an
# interactive session drops to www-data, the user the workers run as.
#
# Root stays one word away: the www-data shell is started as a *child* of the
# root shell the exec created, so leaving it lands back in that root shell
# instead of ending the session — which is also the only way to get root under
# `kubectl exec`, where there is no `-u 0` flag. Nothing setuid is installed and
# there is no sudo, so this adds no path from a compromised php-fpm worker to
# uid 0: the root shell is one the daemon never had.

# Interactive shells only.
case $- in
    *i*) ;;
    *) return 0 ;;
esac

QUANTA_SHELL_RC=/etc/quanta/shell.bashrc

if [ -t 1 ]; then
    _q_dim=$(printf '\033[2m')
    _q_grn=$(printf '\033[1;32m')
    _q_red=$(printf '\033[1;31m')
    _q_off=$(printf '\033[0m')
else
    _q_dim= _q_grn= _q_red= _q_off=
fi

# gosu is what steps between the two users, and is installed by the base image;
# a downstream image that drops it (or the www-data user) just gets plain root
# shells, hence the check rather than an error at every exec.
quanta_can_switch() {
    command -v gosu >/dev/null 2>&1 && id www-data >/dev/null 2>&1
}

# A www-data shell as a child of the current (root) one, so that exiting it
# returns here. QUANTA_SHELL marks it as ours: without the marker this file
# cannot know whether leaving the shell means "back to root" or "goodbye".
# HOME comes from passwd — gosu changes the user, not the environment, and a
# www-data shell left pointing at /root cannot read or write its own home.
quanta_www_shell() {
    local home
    home=$(getent passwd www-data | cut -d: -f6)
    QUANTA_SHELL=www-data HOME="${home:-/var/www}" \
        gosu www-data bash --rcfile "$QUANTA_SHELL_RC" -i
}

# A login shell sources this file twice — once via /etc/profile.d, then again
# via ~/.bashrc — with the stock ~/.bashrc (which sets its own PS1) in between.
# So PS1 is set on every pass, to win that race, while the greeting is printed
# on the first one only. The flag is deliberately not exported: a nested shell
# is a new session and gets the reminder again.
quanta_shell_note() {
    [ -n "$_quanta_shell_noted" ] && return 0
    _quanta_shell_noted=1
    printf '%s\n' "$@"
}

if [ "$(id -u)" -eq 0 ]; then
    if [ -z "$QUANTA_SHELL" ] && quanta_can_switch; then
        quanta_www_shell
    fi

    # Reached either by coming back from the www-data shell above, or directly
    # when this is already a root shell we started (nested `bash`) or when gosu
    # is unavailable. Mark it so a nested shell does not drop again.
    QUANTA_SHELL=root
    export QUANTA_SHELL

    _q_back=
    if quanta_can_switch; then
        www() { quanta_www_shell; }
        _q_back='; `www` returns to www-data'
    fi

    [ -n "$BASH_VERSION" ] && PS1="${_q_red}\u${_q_off}@quanta:\w# "
    quanta_shell_note "$(printf '%sroot shell (uid 0) — what you create here is root-owned%s.%s' \
        "$_q_dim" "$_q_back" "$_q_off")"
    unset _q_back
else
    _q_me=$(id -un)

    [ -n "$BASH_VERSION" ] && PS1="${_q_grn}\u${_q_off}@quanta:\w\$ "
    # $HOME (/var/www) is root-owned, so keep the history somewhere writable
    # rather than have bash complain about it on the way out.
    [ -w "${HOME:-/}" ] || HISTFILE="/tmp/.bash_history-$_q_me"

    _q_hello=$(printf '%sQuanta container — you are %s%s%s, the user php-fpm runs as, so files you create stay writable.%s' \
        "$_q_dim" "$_q_grn" "$_q_me" "$_q_dim" "$_q_off")
    if [ "$QUANTA_SHELL" = www-data ]; then
        # We are the child of a root shell: leaving drops back into it.
        root() { exit; }
        quanta_shell_note "$_q_hello" \
            "$(printf '%sRoot shell: type %sroot%s (or Ctrl-D). From there `www` comes back here, `exit` leaves the container.%s' \
                "$_q_dim" "$_q_off" "$_q_dim" "$_q_off")"
    else
        quanta_shell_note "$_q_hello" \
            "$(printf '%sRoot shell: reconnect with `docker exec -u 0 -it %s bash`.%s' \
                "$_q_dim" "$(hostname)" "$_q_off")"
    fi
    unset _q_me _q_hello
fi

unset _q_dim _q_grn _q_red _q_off
