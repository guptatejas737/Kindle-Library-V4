<?php
require_once __DIR__ . '/config.php';

function getDb(): PDO {
    static $db = null;
    if ($db !== null) return $db;

    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $isNew = !file_exists(DB_PATH);
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');

    if ($isNew) {
        runMigrations($db);
    } else {
        ensureSchema($db);
    }

    return $db;
}

function runMigrations(PDO $db): void {
    $db->exec('
        CREATE TABLE IF NOT EXISTS books (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            author TEXT DEFAULT "",
            filename TEXT NOT NULL UNIQUE,
            format TEXT DEFAULT "",
            file_size INTEGER DEFAULT 0,
            cover_path TEXT DEFAULT "",
            rating INTEGER DEFAULT 0,
            status TEXT DEFAULT "unread",
            notes TEXT DEFAULT "",
            series TEXT DEFAULT "",
            series_index REAL DEFAULT 0,
            date_added DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');
    $db->exec('
        CREATE TABLE IF NOT EXISTS collections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT DEFAULT "",
            sort_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');
    $db->exec('
        CREATE TABLE IF NOT EXISTS book_collections (
            book_id INTEGER,
            collection_id INTEGER,
            PRIMARY KEY (book_id, collection_id),
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
            FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE
        )
    ');
    $db->exec('
        CREATE TABLE IF NOT EXISTS search_cache (
            cache_key TEXT PRIMARY KEY,
            data TEXT,
            created_at INTEGER
        )
    ');
}

function ensureSchema(PDO $db): void {
    $tables = [];
    $res = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
    while ($row = $res->fetch()) $tables[] = $row['name'];

    if (!in_array('books', $tables) || !in_array('collections', $tables) || !in_array('search_cache', $tables)) {
        runMigrations($db);
    }
}

function scanExistingBooks(PDO $db): int {
    $added = 0;
    $dirs = ['unread' => UNREAD_DIR, 'read' => READ_DIR];

    foreach ($dirs as $status => $dir) {
        if (!is_dir($dir)) continue;
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $filepath = $dir . '/' . $file;
            if (!is_file($filepath)) continue;

            $existing = $db->prepare('SELECT id FROM books WHERE filename = ?');
            $existing->execute([$file]);
            if ($existing->fetch()) continue;

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $title = str_replace('_', ' ', pathinfo($file, PATHINFO_FILENAME));
            $title = preg_replace('/\.[^.]+$/', '', $title);
            $size = filesize($filepath);

            $meta = extractBookMeta($filepath);
            if ($meta) {
                $title = $meta['title'] ?: $title;
            }

            $stmt = $db->prepare('
                INSERT INTO books (title, author, filename, format, file_size, status, cover_path)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $title,
                $meta['author'] ?? '',
                $file,
                $ext,
                $size,
                $status === 'read' ? 'read' : 'unread',
                ''
            ]);

            $bookId = $db->lastInsertId();
            generateCover($bookId, $filepath, $title, $meta['author'] ?? '');
            $added++;
        }
    }
    return $added;
}

function extractBookMeta(string $filepath): ?array {
    if (!class_exists('Kiwilan\\Ebook\\Ebook')) {
        $autoload = APP_ROOT . '/vendor/autoload.php';
        if (file_exists($autoload)) require_once $autoload;
        else return null;
    }

    try {
        $ebook = \Kiwilan\Ebook\Ebook::read($filepath);
        return [
            'title' => $ebook->getTitle() ?? '',
            'author' => $ebook->getAuthorMain()?->getName() ?? '',
            'series' => $ebook->getSeries() ?? '',
            'series_index' => $ebook->getVolume() ?? 0,
            'description' => $ebook->getDescription() ?? '',
        ];
    } catch (\Throwable $e) {
        return null;
    }
}

function generateCover(int $bookId, string $filepath, string $title, string $author): void {
    require_once __DIR__ . '/covers.php';
    $coverPath = fetchCoverForBook($bookId, $filepath, $title, $author);
    if ($coverPath) {
        $db = getDb();
        $stmt = $db->prepare('UPDATE books SET cover_path = ? WHERE id = ?');
        $stmt->execute([$coverPath, $bookId]);
    }
}

function getBookById(int $id): ?array {
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM books WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getBookFilePath(array $book): string {
    $status = $book['status'];
    $dir = ($status === 'read') ? READ_DIR : UNREAD_DIR;
    return $dir . '/' . $book['filename'];
}

function getBooks(array $filters = [], string $sort = 'date_added', string $order = 'DESC', int $page = 1): array {
    $db = getDb();
    $where = [];
    $params = [];

    if (!empty($filters['status'])) {
        $where[] = 'b.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['format'])) {
        $where[] = 'b.format = ?';
        $params[] = $filters['format'];
    }
    if (!empty($filters['collection_id'])) {
        $where[] = 'b.id IN (SELECT book_id FROM book_collections WHERE collection_id = ?)';
        $params[] = $filters['collection_id'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(b.title LIKE ? OR b.author LIKE ?)';
        $term = '%' . $filters['search'] . '%';
        $params[] = $term;
        $params[] = $term;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $allowedSorts = ['date_added', 'title', 'author', 'rating', 'file_size'];
    if (!in_array($sort, $allowedSorts)) $sort = 'date_added';
    $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
    $offset = ($page - 1) * BOOKS_PER_PAGE;

    $countStmt = $db->prepare("SELECT COUNT(*) FROM books b $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "SELECT b.* FROM books b $whereClause ORDER BY b.$sort $order LIMIT ? OFFSET ?";
    $params[] = BOOKS_PER_PAGE;
    $params[] = $offset;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $books = $stmt->fetchAll();

    return [
        'books' => $books,
        'total' => $total,
        'page' => $page,
        'pages' => max(1, ceil($total / BOOKS_PER_PAGE)),
    ];
}

function addBook(string $filename, string $status = 'unread'): int {
    $db = getDb();
    $dir = ($status === 'read') ? READ_DIR : UNREAD_DIR;
    $filepath = $dir . '/' . $filename;
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $size = file_exists($filepath) ? filesize($filepath) : 0;
    $title = str_replace('_', ' ', pathinfo($filename, PATHINFO_FILENAME));

    $meta = extractBookMeta($filepath);
    if ($meta) {
        $title = $meta['title'] ?: $title;
    }

    $stmt = $db->prepare('
        INSERT OR IGNORE INTO books (title, author, filename, format, file_size, status, series, series_index)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $title,
        $meta['author'] ?? '',
        $filename,
        $ext,
        $size,
        $status,
        $meta['series'] ?? '',
        $meta['series_index'] ?? 0,
    ]);

    $bookId = $db->lastInsertId();
    if ($bookId) {
        generateCover((int)$bookId, $filepath, $title, $meta['author'] ?? '');
    } else {
        $stmt2 = $db->prepare('SELECT id FROM books WHERE filename = ?');
        $stmt2->execute([$filename]);
        $row = $stmt2->fetch();
        $bookId = $row ? $row['id'] : 0;
    }

    return (int)$bookId;
}
