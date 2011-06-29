#!/bin/sh

# Example usages:
# ./create_release.sh 			- uses current directory
# ./create_release.sh tags 2.1.5	- uses selected svn tag 
# ./create_release.sh branches smarty	- uses selected svn branch
# ./create_release.sh trunk		- uses svn trunk 

# files and directories to exclude
EXCLUDE_FILES_DIRS=".svn .todo tests scripts db phpunit.xml.dist \
	config.inc.php test.sh *.orig *.rej *.patch *.diff *.new scripts \
	.metadata .settings .buildpath .project *.php.diff"

WORK_DIR=".."

# check command usage
if [ "$0" != "./create_release.sh" ]
then
	echo "Usage: ./create_release [trunk|tags x.x.x|branches name]"
fi

# check if svn executable exists on the system
if [ $# -ne 0 ]
then
	result=`svn --version 2>/dev/null`
	if [ "$?" = "127" ]
	then
		echo "Error: can't find <svn> executable!"
		exit
	fi
fi

# check if there is parameter, then checkout from svn specified version
if [ $# -eq 1 ]
then 
	if [ "$1" != "trunk" ]
	then
		echo "Error: unknow svn branch <$1>, use trunk!"
		exit
	fi

	WORK_DIR="trunk"
	VERSION="trunk"

	if [ -e $WORK_DIR ]
	then
		echo "Error: checkout directory <$WORK_DIR> already exists!"
		exit
	fi

	svn co https://www.poweradmin.org/svn/$1 $WORK_DIR 

	if [ $? -ne 0 ]
	then
		echo "Error: svn checkout failed!"
		exit
	fi
fi

if [ $# -eq 2 ]
then
	SVN_BRANCH=$1
	SVN_PROJ_OR_TAG=$2

	WORK_DIR=$SVN_BRANCH-$SVN_PROJ_OR_TAG
	if [ "$SVN_BRANCH" = "branches" ]
	then
		IS_BUGFIX_REL=`echo $SVN_PROJ_OR_TAG | grep ^[0-9]`
		if [ "$IS_BUGFIX_REL" = "" ]
		then
			VERSION=$SVN_PROJ_OR_TAG
		fi
	fi

	if [ -e $WORK_DIR ]
	then
		echo "Error: checkout directory <$WORK_DIR> already exists!"
		exit
	fi

	svn co https://www.poweradmin.org/svn/$SVN_BRANCH/$SVN_PROJ_OR_TAG \
		$WORK_DIR 

	if [ $? -ne 0 ]
	then
		echo "Error: svn checkout failed!"
		exit
	fi
fi

# get release version if current directory is used
if [ "$VERSION" = "" ]
then
	if [ -e $WORK_DIR"/inc/version.inc.php" ]
	then
		VERSION=`cat $WORK_DIR/inc/version.inc.php | grep VERSION | \
			cut -d '"' -f2`
	else
		if [ $# -eq 2 ]
		then
			VERSION=$SVN_PROJ_OR_TAG
		fi
	fi

	if [ "$VERSION" = "" ]
	then
		echo "Error: couldn't get release version!"
		exit
	fi
fi

# check if output file already exists
RELEASE_NAME="poweradmin-$VERSION"
RELEASE_FILE=$RELEASE_NAME".tgz"

if [ -e $RELEASE_FILE ]
then
	echo "Error: output file <$RELEASE_FILE> already exists!"
	exit
fi

# build exclude patterns
for pattern in $EXCLUDE_FILES_DIRS; do
	OTHER_EXCLUDES=$OTHER_EXCLUDES" --exclude="$pattern
done 

# revert changes in header.inc.php file, minor changes for developer mode
# ignore_install_dir = true)
cp $WORK_DIR/inc/header.inc.php $WORK_DIR/inc/header.inc.php.orig
svn revert $WORK_DIR/inc/header.inc.php

# check if temporary release directory exists
if [ -e $RELEASE_NAME ]
then
	echo "Error: output release directory <$RELEASE_NAME> already exists!"
	mv $WORK_DIR/inc/header.inc.php.orig $WORK_DIR/inc/header.inc.php
	exit
fi

# copy/move all files to new release directory, deal with recursive copy safely
if [ "$WORK_DIR" = ".." ]
then
	OLDPATH=`pwd`

	cd ../
	TOPPATH=`pwd`
	BASENAME=`basename $TOPPATH`

	cd ../
	CURRDIR=`pwd`

	if [ -e $RELEASE_NAME ]
	then
		echo "Error: temporary directory <$CURRDIR/$RELEASE_NAME> \
already exists"
		exit
	fi

	cp -r $CURRDIR/$BASENAME $CURRDIR/$RELEASE_NAME
	mv $CURRDIR/$RELEASE_NAME $OLDPATH/$RELEASE_NAME

	cd $OLDPATH
else
	mv $WORK_DIR/ $RELEASE_NAME
fi

# create tgz archive, exclude some directories & files
tar --exclude=$RELEASE_FILE $OTHER_EXCLUDES -ccvzf $RELEASE_FILE $RELEASE_NAME
if [ $? -ne 0 ]
then
	echo "Error: creation of release failed!"

	# restore previous file system state
	mv $WORK_DIR/inc/header.inc.php.orig $WORK_DIR/inc/header.inc.php
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
if [ -e $WORK_DIR"/inc/header.inc.php.orig" ]
then
	mv $WORK_DIR/inc/header.inc.php.orig $WORK_DIR/inc/header.inc.php
fi

