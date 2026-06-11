<?php
define('APP_ROOT', dirname(__DIR__));
define('DB_PATH', APP_ROOT . '/data/library.db');
define('BOOKS_DIR', APP_ROOT . '/books');
define('COVERS_DIR', APP_ROOT . '/covers');
define('UNREAD_DIR', BOOKS_DIR . '/unread');
define('READ_DIR', BOOKS_DIR . '/read');
define('BOOKS_PER_PAGE', 12);
define('BOOKS_PER_ROW', 3);
define('COVER_WIDTH', 120);
define('COVER_HEIGHT', 180);
define('SEARCH_CACHE_TTL', 604800); // 7 days in seconds
define('LIBGEN_BASE', 'https://libgen.li');
define('OPENLIBRARY_SEARCH', 'https://openlibrary.org/search.json');
define('OPENLIBRARY_COVERS', 'https://covers.openlibrary.org/b/id/');
