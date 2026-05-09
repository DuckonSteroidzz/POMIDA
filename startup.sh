#!/bin/bash

cp -r /home/site/wwwroot/* /tmp

cd /tmp

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan serve --host=0.0.0.0 --port=8080