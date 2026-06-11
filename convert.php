<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/covers.php';
require_once __DIR__ . '/includes/converter.php';

$db = getDb();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    header('Location: index.php');
    exit;
}

$book = getBookById($id);
if (!$book) {
    header('Location: index.php?msg=' . urlencode('Book not found.'));
    exit;
}

$filepath = getBookFilePath($book);
$format = strtolower($book['format']);

// Only EPUB conversion is supported in pure PHP
if ($format === 'pdf') {
    header('Location: book.php?id=' . $id . '&msg=' . urlencode('PDF to MOBI conversion is not available in pure PHP. Try searching for an EPUB or MOBI version instead.'));
    exit;
}

if ($format !== 'epub') {
    header('Location: book.php?id=' . $id . '&msg=' . urlencode('This book is already in ' . strtoupper($format) . ' format.'));
    exit;
}

// Perform conversion
if (isset($_GET['confirm'])) {
    $dir = ($book['status'] === 'read') ? READ_DIR : UNREAD_DIR;
    $mobiFilename = pathinfo($book['filename'], PATHINFO_FILENAME) . '.mobi';
    $mobiPath = $dir . '/' . $mobiFilename;

    $result = convertEpubToMobi($filepath, $mobiPath);

    if ($result['success']) {
        // Add the new MOBI file to the library
        $newBookId = addBook($mobiFilename, $book['status']);

        // Copy over metadata from the original
        if ($newBookId) {
            $db->prepare('UPDATE books SET
                title = ?, author = ?, rating = ?, notes = ?,
                series = ?, series_index = ?
                WHERE id = ?'
            )->execute([
                $book['title'], $book['author'], $book['rating'], $book['notes'],
                $book['series'], $book['series_index'],
                $newBookId
            ]);

            // Copy collection assignments
            $stmt = $db->prepare('SELECT collection_id FROM book_collections WHERE book_id = ?');
            $stmt->execute([$id]);
            $ins = $db->prepare('INSERT OR IGNORE INTO book_collections (book_id, collection_id) VALUES (?, ?)');
            while ($row = $stmt->fetch()) {
                $ins->execute([$newBookId, $row['collection_id']]);
            }
        }

        header('Location: book.php?id=' . ($newBookId ?: $id) . '&msg=' . urlencode('Converted to MOBI successfully!'));
        exit;
    } else {
        header('Location: book.php?id=' . $id . '&msg=' . urlencode('Conversion failed: ' . $result['error']));
        exit;
    }
}

// Show confirmation page
layoutHeader('Convert to MOBI', 'index.php');
layoutBreadcrumb(['Library' => 'index.php', $book['title'] => 'book.php?id=' . $id, 'Convert' => '']);
?>

<table width="100%"><tr><td class="p-12">
    <b style="font-size:20px;">Convert to MOBI</b>

    <table class="detail-table" style="margin:12px 0;">
    <tr>
        <td class="detail-cover-cell">
            <img src="<?php echo h(coverUrl($book)); ?>" alt="Cover" class="detail-cover" width="130" height="195">
        </td>
        <td class="detail-info-cell">
            <div class="detail-title"><?php echo h($book['title']); ?></div>
            <div class="detail-author">by <?php echo h($book['author'] ?: 'Unknown'); ?></div>
            <div class="detail-meta">Current format: <?php echo h(strtoupper($book['format'])); ?></div>
            <div class="detail-meta">Size: <?php echo formatFileSize($book['file_size']); ?></div>
        </td>
    </tr>
    </table>

    <div style="padding:10px;background:#f4f4f4;border:1px solid #ddd;margin:8px 0;">
        <b>Note:</b> This will create a new MOBI copy alongside the existing EPUB.
        The conversion uses a pure PHP approach; complex formatting may not convert perfectly.
        The original EPUB file will be kept.
    </div>

    <div style="margin-top:12px;">
        <a href="convert.php?id=<?php echo $id; ?>&confirm=1" class="btn btn-primary">Convert Now</a>
        <a href="book.php?id=<?php echo $id; ?>" class="btn">Cancel</a>
    </div>
</td></tr></table>

<?php layoutFooter(); ?>
