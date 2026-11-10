#!/bin/sh
set -eu

# Selenium shares this container's network namespace, including the local CMS server.
attempt=0
until php -d default_socket_timeout=1 -r '
    $status = @file_get_contents("http://127.0.0.1:4444/status");
    exit(json_decode($status ?: "{}", true)["value"]["ready"] ?? false ? 0 : 1);
'; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 60 ]; then
        echo 'Selenium did not become ready.' >&2
        exit 1
    fi
    sleep 1
done

exec composer test:behat:chrome
