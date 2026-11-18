#!/bin/sh
# Builds DUC-Website-cPanel.zip – upload it to public_html in cPanel File Manager and extract.
set -e
cd "$(dirname "$0")"
rm -rf dist/cpanel && mkdir -p dist/cpanel/app dist/cpanel/assets
cp cpanel/index.php cpanel/.htaccess cpanel/INSTALL.txt dist/cpanel/
cp -R cpanel/app/. dist/cpanel/app/
cp web/site.html dist/cpanel/app/site.html
cp cpanel/assets/* dist/cpanel/assets/
cp web/duc-logo.webp dist/cpanel/assets/duc-logo.webp
rm -f DUC-Website-cPanel.zip
(cd dist/cpanel && zip -qr ../../DUC-Website-cPanel.zip . -x '.DS_Store')
echo "Built DUC-Website-cPanel.zip"
