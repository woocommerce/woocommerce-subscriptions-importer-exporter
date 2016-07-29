#! /bin/bash
#
# Deploy WooCommerce Subscriptions Importer Exporter to github.com/prospress/woocommerce-subscriptions-importer-exporter/
#
# A "_deployment_" in our sense means:
#  * generating a `.pot` file for all translatable strings in the development repository
#  * tagging a new version in the main development repository and pushing it to `/prospress` (which must have a remote with the name `prospress`)
#  * exporting a copy of all the files in the `/prospress/woocommerce-subscriptions/` repo to the `/woothemes/` repo
#  * removing all development related assets, like this file, unit tests and configuration files from that repo

# main config
PLUGINSLUG="woocommerce-subscriptions-importer-exporter"
CURRENTDIR=`pwd`
MAINFILE="wcs-importer-exporter.php"

# git config
GITPATH="$CURRENTDIR/"

# svn config
TMPPATH="/tmp/$PLUGINSLUG"
DEPLOYURL="git@github.com:prospress/woocommerce-subscriptions-importer-exporter.git"

# Let's begin...
echo ".........................................."
echo 
echo "Preparing to deploy..."
echo 
echo ".........................................."
echo 

# Check version in readme.txt is the same as plugin file after translating both to unix line breaks to work around grep's failure to identify mac line breaks
NEWVERSION1=$(grep "^ \* Version:" $MAINFILE | awk -F' ' '{print $NF}')
echo "Header version:   $NEWVERSION1"
NEWVERSION2=$(grep "^\tpublic static \$version" $MAINFILE | awk -F"'" '{print $2}')
echo "Internal version: $NEWVERSION2"

if [ "$NEWVERSION1" != "$NEWVERSION2" ]; then echo "Versions in $MAINFILE don't match. Exiting...."; exit 1; fi

echo "Versions match. Let's proceed..."

if git show-ref --tags --quiet --verify -- "refs/tags/$NEWVERSION1"
	then 
		echo "Version $NEWVERSION1 already exists as git tag. Exiting....";
		exit 1; 
	else
		echo "Git version does not exist. Let's proceed..."
fi

grunt i18n

echo "New .pot file generated."

cd $GITPATH
echo -e "Enter a commit message for this new version: \c"
read COMMITMSG
git commit -am "$COMMITMSG"

echo "Tagging new version in git"
git tag -a "$NEWVERSION1" -m "Version $NEWVERSION1"

echo "Pushing latest commit to prospress, with tags"
git push prospress master
git push prospress master --tags

echo 
echo ".........................................."
echo 
echo "Creating local copy of $DEPLOYURL ..."
git clone $DEPLOYURL $TMPPATH

echo "Changing directory to $TMPPATH ..."
cd $TMPPATH/

echo "Clearing repo so we can start from a clean slate ..."
git rm -fr *

echo "Changing directory to $GITPATH ..."
cd $GITPATH/

echo "Exporting the HEAD of master to $TMPPATH ..."
git checkout-index -a -f --prefix=$TMPPATH/

echo "Removing development specific files in $TMPPATH ..."
rm -fr $TMPPATH/tests/
rm -fr $TMPPATH/.tx
rm $TMPPATH/*.dist
rm $TMPPATH/*.js
rm $TMPPATH/*.json
rm $TMPPATH/*.sh
rm $TMPPATH/*.xml
rm $TMPPATH/*.yml
rm $TMPPATH/.travis.yml

echo "Changing directory to $TMPPATH ..."
cd $TMPPATH/

echo "Commiting to master in $TMPPATH ..."
git add .
git commit -m "Version $NEWVERSION1"
git checkout -b release/$NEWVERSION1

echo "Creating new tag & committing it"
git tag -a "$NEWVERSION1" -m "Version $NEWVERSION1"
git push origin release/$NEWVERSION1
git push origin --tags

echo "Removing temporary directory $TMPPATH"
rm -fr $TMPPATH/

echo ".........................................."
echo 
echo "*** Now: "
echo "*** 1. go make a PR on https://github.com/prospress/woocommerce-subscriptions-importer/compare/release/$NEWVERSION1?expand=1"
echo ".........................................."
echo 
