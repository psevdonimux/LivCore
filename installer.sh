#!/bin/sh
apt install tar unzip wget git
name="LivCore"
name2="PHP-Linux-x86_64-PM4.tar.gz"
git clone https://github.com/psevdonimux/$name.git
wget https://github.com/pmmp/PHP-Binaries/releases/download/php-8.0-latest/$name2
mv $name/* $PWD
unzip src.zip
tar -xvf $name2
chmod 777 ./start.sh
rm -r $name src.zip $name2 LICENSE README.md installer.sh
