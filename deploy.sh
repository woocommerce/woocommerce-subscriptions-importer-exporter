#! /bin/bash
#
# Deploy WooCommerce Subscriptions Importer Exporter to github.com/prospress/woocommerce-subscriptions-importer-exporter/
#
# A "_deployment_" in our sense means:
#  * generating a `.pot` file for all translatable strings in the development repository
#  * tagging a new version in the main development repository and pushing it to `/prospress` (which must have a remote with the name `prospress`)
#  * removing all development related assets, like this file, unit tests and configuration files from that repo

# main config
PLUGINSLUG="woocommerce-subscriptions-importer-exporter"
CURRENTDIR=`pwd`
MAINFILE="wcs-importer-exporter.php"

# git config
GITPATH="$CURRENTDIR/"

# svn config
TMPPATH="/tmp/$PLUGINSLUG"
DEPLOYURL="git@github.com:Prospress/woocommerce-subscriptions-importer-exporter.git"
# SVNURL="http://plugins.svn.wordpress.org/subscriptions-importer-exporter-for-woocommerce/" # Remote SVN repo on wordpress.org, with no trailing slash
# SVNUSER="prospress" # your svn username

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
NEWVERSION3=`grep "^Stable tag" $GITPATH/readme.txt | awk -F' ' '{print $3}'`
echo "readme version: $NEWVERSION1"

if [ "$NEWVERSION1" != "$NEWVERSION2" != "$NEWVERSION3" ]; then echo "Versions in $MAINFILE don't match. Exiting...."; exit 1; fi

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

echo "Creating local copy of SVN repo ..."
svn co $SVNURL $TMPPATH

echo "Ignoring github specific & deployment script"
svn propset svn:ignore "deploy.sh
README.md
*.psd
.git
.gitignore" "$TMPPATH/trunk/"

echo "Changing directory to SVN"
cd $TMPPATH/trunk/
# Add all new files that are not set to be ignored
svn status | grep -v "^.[ \t]*\..*" | grep "^?" | awk '{print $2}' | xargs svn add
echo "committing to trunk"
svn commit --username=$SVNUSER -m "$COMMITMSG"

echo "Updating WP plugin repo assets & committing"
cd $TMPPATH/assets/
# Add all new files that are not set to be ignored
svn status | grep -v "^.[ \t]*\..*" | grep "^?" | awk '{print $2}' | xargs svn add
svn commit --username=$SVNUSER -m "Updating assets repo"

echo "Creating new SVN tag & committing it"
cd $TMPPATH
svn copy trunk/ tags/$NEWVERSION1/
cd $TMPPATH/tags/$NEWVERSION1
svn commit --username=$SVNUSER -m "Tagging version $NEWVERSION1"

echo "Removing temporary directory $TMPPATH"
rm -fr $TMPPATH/

echo ".........................................."
echo 
echo "*** Now: "
echo "*** 1. go make a PR on https://github.com/Prospress/woocommerce-subscriptions-importer-exporter/compare/release/$NEWVERSION1?expand=1"
echo ".........................................."
echo 