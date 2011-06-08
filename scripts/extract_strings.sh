#!/bin/sh
YEAR=`date "+%Y"`
VERSION=`cat ../inc/version.inc.php | grep VERSION | cut -d '"' -f2`
DATE=`date "+%Y-%m-%d %H:%M%z"`

cd ..
# extract strings from php files
find . -name "*.php" | \
	xargs xgettext \
		--no-wrap \
		-L PHP \
		--copyright-holder="Poweradmin Development Team" \
		--msgid-bugs-address="edmondas@poweradmin.org" \
		-o docs/i18n-template-php.pot \
		--package-name=Poweradmin \
		--package-version=$VERSION \
&& sed -i -e 's/SOME DESCRIPTIVE TITLE/Poweradmin translation/' docs/i18n-template-php.pot \
&& sed -i -e 's/Language: /Language: en_EN/' docs/i18n-template-php.pot \
&& sed -i -e 's/PACKAGE/Poweradmin/' docs/i18n-template-php.pot \
&& sed -i -e 's/(C) YEAR/(C) '$YEAR'/' docs/i18n-template-php.pot \
&& sed -i -e 's/FIRST AUTHOR <EMAIL@ADDRESS>, YEAR/Rejo Zenger <rejo@poweradmin.org>, 2008/' docs/i18n-template-php.pot \
&& sed -i -e 's/CHARSET/UTF-8/' docs/i18n-template-php.pot

# add header to db strings file
cat >docs/i18n-template-db.pot <<EOF
# Poweradmin translation.
# Copyright (C) $YEAR Poweradmin Development Team
# This file is distributed under the same license as the Poweradmin package.
# Rejo Zenger <rejo@poweradmin.org>, 2008.
#
#, fuzzy
msgid ""
msgstr ""
"Project-Id-Version: Poweradmin $VERSION\n"
"Report-Msgid-Bugs-To: edmondas@poweradmin.org\n"
"POT-Creation-Date: $DATE\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\n"
"Last-Translator: FULL NAME <EMAIL@ADDRESS>\n"
"Language-Team: LANGUAGE <LL@li.org>\n"
"Language: en_EN\n"
"MIME-Version: 1.0\n"
"Content-Type: text/plain; charset=UTF-8\n"
"Content-Transfer-Encoding: 8bit\n"

EOF

# extract strings from database structure 
cat install/database-structure.inc.php | grep "array([0-9]" | \
	awk -F\' '{ print "msgid \""$4"\"\nmsgstr \"\"\n"; }' >>docs/i18n-template-db.pot 
