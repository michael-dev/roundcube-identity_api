# version of the archives: the tag of the current commit (e.g. 2.3), otherwise a git description
VERSION ?= $(shell git describe --tags --always)

.PHONY: all archive test integration-test composer-test postfix-test clean

all: archive

# Plugin archives for the release: the files Composer installs (everything not marked
# export-ignore in .gitattributes) in identity_api/, the same bytes for the same commit
archive:
	mkdir -p dist
	git archive --format=tar.gz --prefix=identity_api/ -o dist/identity_api-$(VERSION).tar.gz HEAD
	git archive --format=zip --prefix=identity_api/ -o dist/identity_api-$(VERSION).zip HEAD

test:
	php tests/generator_test.php

# Downloads Roundcube and tests the API against it (needs php-sqlite, curl)
integration-test:
	tests/integration.sh

# Installs the plugin with Composer into Roundcube, from an archive like the one Packagist uses
composer-test: archive
	tests/composer.sh dist/identity_api-$(VERSION).zip

# Tests docs/postfix.md with postmap against MySQL/MariaDB (see script for settings)
postfix-test:
	tests/postfix-guide.sh

clean:
	rm -rf dist
