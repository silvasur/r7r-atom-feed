#!/usr/bin/env bash

rm -f tpls/*~

r7r-plugin-packer \
    --output=atom_feed.rpk \
    --codefile=plugin.php \
    --classname=atom_feed \
    --pluginname=atom_feed \
    --author='Laria Carolin Chabowski <laria@laria.me>' \
    --versiontext="0.5.1" \
    --versioncount=4 \
    --api=5 \
    --shortdesc="Power up your website with some Atom Feeds!" \
    --helpfile=help.html \
    --licensefile=COPYING \
    --tpldir=tpls
