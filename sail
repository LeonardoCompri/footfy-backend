#!/usr/bin/env bash

if [ -f vendor/bin/sail ]; then
    bash vendor/bin/sail "$@"
else
    echo "Laravel Sail não encontrado. Execute composer install."
    exit 1
fi
