#!/bin/sh

# files and directories to exclude
EXCLUDE_FILES_DIRS=".todo tests scripts db phpunit.xml.dist config.inc.php \
	test.sh .orig .rej .patch .diff .new"

# TODO: check if there is parameter, then checkout from svn specified version

# get release version
VERSION=`cat ../inc/version.inc.php | grep VERSION | cut -d '"' -f2`
if [ "$VERSION" = "" ]
then
	echo "Error: couldn't get release version!"
	exit
fi

# check if output file already exists
RELEASE_NAME="poweradmin-$VERSION"
RELEASE_FILE=$RELEASE_NAME".tgz"

if [ -e $RELEASE_FILE ]
then
	echo "Error: output file <$RELEASE_FILE> already exists!"
	exit
fi

# build exclude pattersn
for pattern in $EXCLUDE_FILES_DIRS; do
	OTHER_EXCLUDES=$OTHER_EXCLUDES" --exclude="$pattern
done 

# revert changes in header.inc.php file, minor changes for developer mode
# ignore_install_dir = true)
cp ../inc/header.inc.php ../inc/header.inc.php.orig
svn revert ../inc/header.inc.php

# check if temporary release directory exists
if [ -e $RELEASE_NAME ]
then
	echo "Error: output release directory <$RELEASE_NAME> already exists!"
	mv ../inc/header.inc.php.orig ../inc/header.inc.php
	exit
fi

# copy all files to new release directory
cp -r ../ $RELEASE_NAME

# create tgz archive, exclude some directories & files
tar cvzf $RELEASE_FILE $RELEASE_NAME --exclude=$RELEASE_FILE --exclude-vcs $OTHER_EXCLUDES
if [ $? -ne 0 ]
then
	echo "Error: creation of release failed!"

	# restore previous file system state
	mv ../inc/header.inc.php.orig ../inc/header.inc.php
	rm -rf $RELEASE_NAME

	exit
fi

# check if file has correct attributes, because previously there were some
# issues with release uploaded file
FILE_ATTR=`ls -al $RELEASE_FILE | cut -d " " -f1`
if [ "$FILE_ATTR" != "-rw-r--r--" ]
then
	chmod 644 $RELEASE_FILE
fi

# remove temporary release directory
rm -rf $RELEASE_NAME

# restore header.inc.php if developer mode was enabled
mv ../inc/header.inc.php.orig ../inc/header.inc.php

