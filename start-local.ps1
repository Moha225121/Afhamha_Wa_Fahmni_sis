$php = 'C:\php-8.4.12\php.exe'

& $php `
    -d extension=fileinfo `
    -d extension=zip `
    -d extension=pdo_sqlite `
    -d extension=sqlite3 `
    -S 127.0.0.1:5500 `
    -t public `
    start-local-router.php
