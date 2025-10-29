#!/bin/bash

DRIVER=$1;
shift;

echo "Starting PHPUNIT tests"
export DB_DRIVER=$DRIVER

if [[ "$@" == *"--with-coverage"* ]]; then
    vendor/bin/phpunit --coverage-clover=coverage.xml
else
    vendor/bin/phpunit
fi
