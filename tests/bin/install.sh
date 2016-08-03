#!/usr/bin/env bash
# see https://github.com/wp-cli/wp-cli/blob/master/templates/install-wp-tests.sh

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}

# TODO: allow environment vars for WP_TESTS_DIR & WP_CORE_DIR
WP_TESTS_DIR="${PWD}/tmp/wordpress-tests-lib"
WP_CORE_DIR="${PWD}/tmp/wordpress/"

if [[ $WP_VERSION =~ [0-9]+\.[0-9]+(\.[0-9]+)? ]]; then
	WP_TESTS_TAG="tags/$WP_VERSION"
else
	# http serves a single offer, whereas https serves multiple. we only want one
	curl http://api.wordpress.org/core/version-check/1.7/ --output /tmp/wp-latest.json
	grep '[0-9]+\.[0-9]+(\.[0-9]+)?' /tmp/wp-latest.json
	LATEST_VERSION=$(grep -o '"version":"[^"]*' /tmp/wp-latest.json | sed 's/"version":"//')
	if [[ -z "$LATEST_VERSION" ]]; then
		echo "Latest WordPress version could not be found"
		exit 1
	fi
	WP_TESTS_TAG="tags/$LATEST_VERSION"
fi

set -e

say() {
  echo -e "$1"
}

install_wp() {

	# make wordpress dirtectory
	mkdir -p "$WP_CORE_DIR"

	# corect WP archive to grab
	if [ $WP_VERSION == 'latest' ]; then
		local ARCHIVE_NAME='latest'
	else
		local ARCHIVE_NAME="wordpress-$WP_VERSION"
	fi

	# grab the archive
	curl https://wordpress.org/${ARCHIVE_NAME}.tar.gz --output /tmp/wordpress.tar.gz --silent

	# unconpress it
	tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"

	# get a test db config
	curl https://raw.github.com/markoheijnen/wp-mysqli/master/db.php?access_token=$GITHUB_TOKEN --output "$WP_CORE_DIR/wp-content/db.php" --silent

	say "WordPress Installed"

}

install_test_suite() {

	# portable in-place argument for both GNU sed and Mac OSX sed
	if [[ $(uname -s) == 'Darwin' ]]; then
		local ioption='-i .bak'
	else
		local ioption='-i'
	fi

	# set up testing suite in wordpress test libary directory
	mkdir -p "$WP_TESTS_DIR"
	cd "$WP_TESTS_DIR"
	svn co --quiet https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/

	curl http://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php --output wp-tests-config.php --silent

	# test configuration
	sed $ioption "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR':" wp-tests-config.php
	sed $ioption "s/youremptytestdbnamehere/$DB_NAME/" wp-tests-config.php
	sed $ioption "s/yourusernamehere/$DB_USER/" wp-tests-config.php
	sed $ioption "s/yourpasswordhere/$DB_PASS/" wp-tests-config.php
	sed $ioption "s|localhost|${DB_HOST}|" wp-tests-config.php
	sed $ioption "s/wptests_/wctests_/" wp-tests-config.php
	sed $ioption "s/example.org/woocommerce.com/" wp-tests-config.php
	sed $ioption "s/admin@example.org/tests@woocommerce.com/" wp-tests-config.php
	sed $ioption "s/Test Blog/WooCommerce Unit Tests/" wp-tests-config.php

	say "Test Suite Installed"

}

install_cs() {

	# ensure we are in tmp directory instead of the wordpress test direcory
	cd ../

	# make a directory for codesniffer
	mkdir -p "php-codesniffer"

	# uncompress codesniffer into the directory we created
	curl -L https://api.github.com/repos/squizlabs/PHP_CodeSniffer/tarball/2.3.3?access_token=$GITHUB_TOKEN --silent | tar --strip-components=1 -zx -C "php-codesniffer"

	say "PHP_CodeSniffer Installed"

	# make a directory for the WP coding standard rules
	mkdir -p "wordpress-coding-standards"

	# uncompress the coding standards into the directory we created
	curl -L https://api.github.com/repos/WordPress-Coding-Standards/WordPress-Coding-Standards/tarball/0.6.0?access_token=$GITHUB_TOKEN --silent | tar --strip-components=1 -zx -C "wordpress-coding-standards"

	# make a directory for the Prospress coding standard rules
	mkdir -p "prospress-coding-standards"

	# uncompress the coding standards into the directory we created
	curl -L https://api.github.com/repos/Prospress/prospress-coding-standards/tarball/master?access_token=$GITHUB_TOKEN --silent | tar --strip-components=1 -zx -C "prospress-coding-standards"

	# move in the codesniffer directory
	cd php-codesniffer

 	# install the WP coding standard rules
 	scripts/phpcs --config-set installed_paths ../wordpress-coding-standards,../prospress-coding-standards

	say "Coding Standards Installed"

 	# for consistency move back into the tmp directory
 	cd ../

}

install_db() {

	# parse DB_HOST for port or socket references
	local PARTS=(${DB_HOST//\:/ })
	local DB_HOSTNAME=${PARTS[0]};
	local DB_SOCK_OR_PORT=${PARTS[1]};
	local EXTRA=""

	if ! [ -z $DB_HOSTNAME ] ; then
		if [[ "$DB_SOCK_OR_PORT" =~ ^[0-9]+$ ]] ; then
			EXTRA=" --host=$DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
		elif ! [ -z $DB_SOCK_OR_PORT ] ; then
			EXTRA=" --socket=$DB_SOCK_OR_PORT"
		elif ! [ -z $DB_HOSTNAME ] ; then
			EXTRA=" --host=$DB_HOSTNAME --protocol=tcp"
		fi
	fi

	# create database - more generic than MAMP for use on multple system
	#/Applications/MAMP/Library/bin/mysqladmin create $DB_NAME --user="$DB_USER" --password="$DB_PASS"$EXTRA
	mysqladmin create $DB_NAME --user="$DB_USER" --password="$DB_PASS"$EXTRA

	say "Database Created"

}

install_wp
install_test_suite
install_cs
install_db
