#!/bin/sh
apt install tar unzip wget git
name="LivCore"
git clone https://github.com/psevdonimux/$name.git
wget https://github.com/pmmp/PHP-Binaries/releases/download/php-8.0-latest/PHP-Linux-x86_64-PM4.tar.gz
mv $name/* $PWD
unzip src.zip
mv PHP-Linux-x86_64-PM4.tar.gz bin.gz
tar -xvf bin.gz
chmod 777 ./start.sh
rm -r $name src.zip bin.gz LICENSE README.md installer.sh installer.sh.zip
