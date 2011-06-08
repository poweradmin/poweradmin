#!/bin/sh

result=`msgfmt 2>/dev/null`

if [ "$?" = "127" ]
then
	echo "Error: can't find <msgfmt> executable!"
	exit
fi

# get list of available locales, excluding english
dirs=`ls ../locale`

# update every messages.mo for every locale
for locale in $dirs; do
	echo "Updating $locale locale"

	cd ../locale/$locale/LC_MESSAGES

	name=`echo $locale | cut -c1-2`
	msgfmt -c $name.po

	if [ -f $name"_db.po" ];
	then
		msgfmt -c $name"_db.po"
		msgcat $name.po $name"_db.po" | msgfmt - -o messages.mo
	else
		msgfmt $name.po -o messages.mo
	fi


	cd ../../
done 
