# WooCommerce Subscriptions Importer Exporter Tests

## Initial Setup

1) Install [PHPUnit](http://phpunit.de/) by following their [installation guide](https://phpunit.de/getting-started.html). If you've installed it correctly, this should display the version:

    $ phpunit --version
    
If using MAMP for local development follow the instructions above but place a copy of the `phpunit.phar` within each of the MAMP specific bin directories you intend to use. For example:

	/Applications/MAMP/bin/php/php5.2.17/bin/
	/Applications/MAMP/bin/php/php5.3.27/bin/
	/Applications/MAMP/bin/php/php5.4.19/bin/
	/Applications/MAMP/bin/php/php5.5.3/bin/
    
2) Ensure that you can use the `mysqladmin` command and that your local database is running. 

For MAMP users you may need to add `/Applications/MAMP/Library/bin` to your path so mysqladmin can be used. 

To do this, open your `~/.bash_profile` file and add something like (modifying the path to suit your local installation):

	export PATH=/Applications/MAMP/Library/bin:$PATH

3) Make sure you have the core WooCommerce and WooCommerce Subscriptions plugins/repositories  installed in the same parent directory as your WooCommerce Subscriptions Importer extension (i.e. `/wp-content/plugins/`). 

You will need WooCommerce 2.3 and WooCommerce Subscriptions 2.0 or newer from the official github repo as it includes unit testing framework and helper methods relied upon by these tests.

4) Create a github personal access token following the standard [github instructions](https://help.github.com/articles/creating-an-access-token-for-command-line-use/) with the `Access private repositories` scope. This token does NOT need any other scopes/permissions to delete, write etc.

Once you have an access token, add a local environment variable on your system named `GITHUB_TOKEN` with the access token as the value.

To do this, open your `~/.bash_profile` file and add something like (modifying the path to suit your local installation):

	export GITHUB_TOKEN=<access-token>

The reason we use this access token is because Subscriptions is hosted in a private repository and we need to authorise the requests. It also helps get around some of the github rate limiting when accessing github resources. 

Similarly, Travis has been configured with a a `GITHUB_TOKEN` environment variable within its settings that is used when running builds/jobs.

5) Change to the WCSIE plugin root directory. 

To install simply use the `install.sh` script by typing:

    $ tests/bin/install.sh <db-name> <db-user> <db-password> [db-host]

Sample usage:

    $ tests/bin/install.sh wcsie_tests root root 127.0.0.1
    
This will install WordPress, the WP Unit Test library, PHP_CodeSniffer and the WordPress Coding Standards components to a `tmp` directory within the WCSIE plugin root directory. 

**Important**: The `<db-name>` database will be created if it doesn't exist and all data will be removed during testing.

**Important**: Be sure to use a different database to any other test database you may have setup e.g. for WooCommerce Subscriptions.

If the database exists, an error will be displayed but the installation will still have completed successfully. The error will look something like:

	mysqladmin: CREATE DATABASE failed; error: 'Can't create database 'wcsie_tests'; database exists'
	
## Running PHPUnit Tests

Simply change to the WCSIE plugin root directory and type:

    $ phpunit

The tests will execute and you'll be presented with a summary.

You can run specific tests by providing the path and filename to the test class:

    $ phpunit tests/unit-tests/wcsie-functions.php
    
## Running PHP_CodeSniffer

Simply change to the WCSIE plugin root directory and type:

	tmp/php-codesniffer/scripts/phpcs -p -s -v -n . --standard=Prospress --extensions=php --ignore=*/tmp/*,*/tests/*,*/node_modules/*,*/libraries/*,*/woo-includes/*
	
This will test the plugin scripts against the WordPress-Extra rule set with some additional Prospress tweaks and present a summary.

You can run phpcs tests against specific files by using a command like:

    $ tmp/php-codesniffer/scripts/phpcs -p -s -v -n includes/wcs-importer-exporter.php --standard=Prospress
    
Similarly, you can use the "code beautifier" (phpcbf) that comes bundled with PHP_CodeSniffer by replacing `phpcs` with `phpcbf` in the commands above like:

    $ tmp/php-codesniffer/scripts/phpcbf -p -s -v -n includes/wcs-importer-exporter.php --standard=Prospress
    
**Note:** you will want to double check the automated fixes - mainly for off whitespace edits.

You can choose the following other rule sets to test against by adjusting the standard option in the above command.

* WordPress
* WordPress-Core
* WordPress-Extra
* WordPress-VIP

**Updating Rule Sets:** The rule sets are occasionally updated. The easiest way to update your local rule sets is to simply run the `install.sh` script again listed under  *Initial Setup* above.


## Search for PHP Syntax Errors

Simply change to the WCSIE plugin root directory and type:

	find . \( -path ./tmp -o -path ./tests \) -prune -o \( -name '*.php' \) -exec php -lf {} \;
	
This will find all the `.php` files excluding the `tmp` and `tests` directories.


## Writing Tests

* Each test file should roughly correspond to an associated source file
* Each test method should cover a single method or function with one or more assertions
* A single method or function can have multiple associated test methods if it's a large or complex method
* In addition to covering each line of a method/function, make sure to test common input and edge cases.
* Prefer `assertsEquals()` where possible as it tests both type & equality
* Remember that only methods prefixed with `test` will be run so use helper methods liberally to keep test methods small and reduce code duplication.
* Filters persist between test cases so be sure to remove them in your test method or in the `tearDown()` method.

## Automated Tests

Tests are automatically run with [Travis-CI](https://travis-ci.org) for each pull request.

## Code Coverage

Code coverage is available on [Codecov](https://codecov.io/) which receives updated data from Travis builds.

## Using Grunt to run the Tests

1) Install [Node.js / NPM](https://nodejs.org/) and [Grunt](http://gruntjs.com/getting-started) by following the standard installation instructions.

If you've installed NPM correctly, this should display the version:

    $ npm --version

If you've install Grunt correctly, this should display the version:

	$ grunt --version
	
2) Change to the WCSIE plugin root directory. 

Install the WCS grunt dependencies by running the npm installer with the following command:

	$ npm install --dev
	
3) Run the tests with

	$ grunt test --force
	
The `--force` simply tells grunt to continue next tasks even if one fails.

## Troubleshooting

1) When you run the install.sh script, if you see `tests/bin/install.sh: line 81: mysqladmin: command not found`, either:
* make sure your local server is running. 
* be sure to add `/Applications/MAMP/Library/bin` to your path so mysqladmin can be used (important for MAMP users)

If the error still persists, just continue and follow the steps in 2 below.

2) When running `phpunit`, if you come across an error such as `Error establishing a database connection` you might need to modify your `wp-tests-config.php` file.

Changes known to fix this issue are as follows:
  - Try using `127.0.0.1` as the database host when running `install.sh`
  - If using MAMP you might want to try set DB_HOST to `define( 'DB_HOST', 'localhost:/Applications/MAMP/tmp/mysql/mysql.sock' );` in your `wp-tests-config.php` file
  - Ensure ABSPATH is set to `/path/to/your/plugin/tmp/wordpress/`. For instance, mine is set to `/Users/Matt/Sites/Subs2.0/wp-content/plugins/woocommerce-subscriptions/tmp/wordpress/`
  - Make sure the Database specified in `wp-tests-config.php` exists. If not, create it and try run `phpunit` once again.

3) When running `phpunit`, if you receive an error in terminal like the one below, you likely have a version of WooCommerce in your `/wp-content/plugins/` directory prior to 2.3 (or WooCommerce is not installed).

```
Warning: require_once(/site_root/wp-content/plugins/woocommerce/tests/framework/helpers/class-wc-helper-product.php): failed to open stream: No such file or directory in /Users/thenbrent/Dropbox/MAMP/htdocs/subs20/wp-content/plugins/woocommerce-subscriptions/tests/bootstrap.php on line 139
```

