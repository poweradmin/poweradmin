#!/bin/sh

# get list of available locales, excluding english
dirs=`ls ../locale | grep -v en_EN`

# update every messages.mo for every locale
for locale in $dirs; do
	echo "Updating $locale locale"

	cd ../locale/$locale/LC_MESSAGES

	name=`echo $locale | cut -c1-2`
	msgfmt $name.po -o messages.mo

	cd ../../
done 
