#!/bin/bash

case "$DB_IMAGE" in
  mysql*)
    mysqladmin ping -h "localhost"
    ;;
  mariadb*)
    healthcheck.sh --su-mysql --connect --innodb_initialized
    ;;
  *)
    echo "Unsupported database image"
    exit 1
    ;;
esac
