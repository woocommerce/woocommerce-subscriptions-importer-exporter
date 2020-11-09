#!/usr/bin/env bash
# usage: travis.sh before|after

set -e

say() {
  echo -e "$1"
}

if [ $1 == 'before' ]; then

	# place a copy of woocommerce where the unit tests etc. expect it to be
	mkdir -p "../woocommerce"
	curl -L https://api.github.com/repos/woothemes/woocommerce/tarball/$WC_VERSION?access_token=$GITHUB_TOKEN --silent | tar --strip-components=1 -zx -C "../woocommerce"

	say "WooCommerce Installed"

fi

# Install WooCommerce Subscriptions.
git clone https://$GITHUB_TOKEN@github.com/woocommerce/woocommerce-subscriptions.git "../woocommerce-subscriptions" --branch $WCS_VERSION
