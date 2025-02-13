#!/bin/bash

echo "START UPDATE"
cd /var/www/html/
/usr/bin/git checkout main -f && git pull
sudo /usr/sbin/service php8.2-fpm reload

echo "DONE!"
