#!/bin/sh
YEAR=`date "+%Y"`
VER=`cat ../inc/version.inc.php | grep VERSION | cut -d '"' -f2`

cd .. && \
find . -name "*.php" | \
	xargs xgettext \
		--no-wrap \
		-L PHP \
		--copyright-holder="Poweradmin Development Team" \
		--msgid-bugs-address="edmondas@poweradmin.org" \
		-o docs/i18n-template-php.pot \
		--package-name=Poweradmin \
		--package-version=$VER \
&& sed -i -e 's/SOME DESCRIPTIVE TITLE/Poweradmin translation/' docs/i18n-template-php.pot \
&& sed -i -e 's/Language: /Language: en_EN/' docs/i18n-template-php.pot \
&& sed -i -e 's/PACKAGE/Poweradmin/' docs/i18n-template-php.pot \
&& sed -i -e 's/(C) YEAR/(C) '$YEAR'/' docs/i18n-template-php.pot \
&& sed -i -e 's/FIRST AUTHOR <EMAIL@ADDRESS>, YEAR/Rejo Zenger <rejo@poweradmin.org>, 2008/' docs/i18n-template-php.pot \
&& sed -i -e 's/CHARSET/UTF-8/' docs/i18n-template-php.pot
