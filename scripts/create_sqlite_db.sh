#!/bin/sh

DB_PATH="../db"
DB_FILE="powerdns.db"

# check if directory exists
if [ ! -e $DB_PATH ]
then
	mkdir -p $DB_PATH
fi

# check if db file exists
if [ -e $DB_PATH/$DB_FILE ]
then
	echo "Error: database file <$DB_PATH/$DB_FILE> already exists!"
	exit
fi

# import db scheme and data
cat ../docs/poweradmin-sqlite-db-structure.sql | sqlite $DB_PATH/$DB_FILE
cat ../docs/powerdns-sqlite-db-structure.sql | sqlite $DB_PATH/$DB_FILE

# change access rights
chmod 777 $DB_PATH
chmod 666 $DB_PATH/$DB_FILE
