ARCHIVE := poweradmin-$(shell git rev-parse HEAD | head -c 10)

# Creates a .tar.gz for deployment
zip:
	tar --create --gzip --verbose \
		--exclude-vcs --exclude="Makefile" --exclude=".idea" --exclude="TODO.md" --exclude="inc/config.inc.php" \
		--file ../${ARCHIVE}.tar.gz \
		.
	mv ../${ARCHIVE}.tar.gz .
