#!/bin/sh
set -eu

SRC=/dokuwiki-src
DST=/var/www/html

if [ ! -f "$DST/doku.php" ]; then
    echo "Seeding DokuWiki from $SRC ..."
    # .git and the 76MB test vendor tree are not needed to serve the wiki.
    # lib/plugins/s3presigned is excluded because it is a separate bind mount
    # (the plugin source) layered on top of $DST; seeding must never write
    # through it, whatever $SRC happens to contain at that path.
    tar -C "$SRC" \
        --exclude=./.git \
        --exclude=./_test/vendor \
        --exclude=./lib/plugins/s3presigned \
        -cf - . | tar -C "$DST" -xf -
fi

if [ ! -f "$DST/conf/local.php" ]; then
    echo "Installing wiki ..."
    cat > "$DST/conf/local.php" <<'EOF'
<?php
$conf['title'] = 's3presigned dev';
$conf['lang'] = 'en';
$conf['license'] = 'cc-by-sa';
$conf['useacl'] = 1;
$conf['superuser'] = 'admin';
$conf['disableactions'] = 'register';
EOF
    # password is "admin"; this wiki is a throwaway dev container
    printf 'admin:%s:admin:admin@example.com:admin,user\n' \
        "$(php -r 'echo password_hash("admin", PASSWORD_DEFAULT);')" \
        > "$DST/conf/users.auth.php"
    printf '*  @ALL  8\n' > "$DST/conf/acl.auth.php"
fi

chown -R www-data:www-data "$DST/conf" "$DST/data"

exec apache2-foreground
