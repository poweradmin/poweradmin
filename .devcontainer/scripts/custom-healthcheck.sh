#!/bin/bash

case "$MYSQL_IMAGE" in
  mysql*|percona*)
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
