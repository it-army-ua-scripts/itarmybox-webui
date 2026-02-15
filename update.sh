#!/bin/bash

echo "START UPDATE"
cd /var/www/html/itarmybox-webui/
/usr/bin/git fetch origin main
/usr/bin/git reset --hard origin/main
/usr/bin/git clean -fd
echo "DONE!"
