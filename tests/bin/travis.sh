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
if [[ -n "$GITHUB_TOKEN" ]]; then
	WCS_GIT_URI="https://$GITHUB_TOKEN@github.com/woocommerce/woocommerce-subscriptions.git"
else
	WCS_GIT_URI="git@github.com:woocommerce/woocommerce-subscriptions.git"
fi
git clone --depth=1 --branch="${WCS_VERSION}" "$WCS_GIT_URI" "${WP_CORE_DIR}/wp-content/plugins/woocommerce-subscriptions"
