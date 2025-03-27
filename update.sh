#!/bin/bash

echo "START UPDATE"
cd /var/www/html/
/usr/bin/git checkout main -f && git pull
echo "DONE!"
