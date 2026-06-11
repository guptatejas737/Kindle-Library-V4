<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/covers.php';

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

// Handle actions

// Rate
if (isset($_GET['rate'])) {
    $rating = max(0, min(5, (int)$_GET['rate']));
    $db->prepare('UPDATE books SET rating = ? WHERE id = ?')->execute([$rating, $id]);
    header('Location: book.php?id=' . $id);
    exit;
}

// Change status
if (isset($_GET['set_status'])) {
    $newStatus = $_GET['set_status'];
    $allowed = ['unread', 'reading', 'read'];
    if (in_array($newStatus, $allowed) && $newStatus !== $book['status']) {
        $oldDir = ($book['status'] === 'read') ? READ_DIR : UNREAD_DIR;
        $newDir = ($newStatus === 'read') ? READ_DIR : UNREAD_DIR;

        $oldPath = $oldDir . '/' . $book['filename'];
        $newPath = $newDir . '/' . $book['filename'];

        if ($oldDir !== $newDir && file_exists($oldPath)) {
            rename($oldPath, $newPath);
        }

        $db->prepare('UPDATE books SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
    }
    header('Location: book.php?id=' . $id);
    exit;
}

// Save notes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_notes'])) {
    $notes = $_POST['notes'] ?? '';
    $db->prepare('UPDATE books SET notes = ? WHERE id = ?')->execute([$notes, $id]);
    header('Location: book.php?id=' . $id . '&msg=' . urlencode('Notes saved.'));
    exit;
}

// Update collections
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_collections'])) {
    $selectedIds = $_POST['coll'] ?? [];
    $db->prepare('DELETE FROM book_collections WHERE book_id = ?')->execute([$id]);
    $ins = $db->prepare('INSERT INTO book_collections (book_id, collection_id) VALUES (?, ?)');
    foreach ($selectedIds as $cid) {
        $ins->execute([$id, (int)$cid]);
    }
    header('Location: book.php?id=' . $id . '&msg=' . urlencode('Collections updated.'));
    exit;
}

// Refresh cover
if (isset($_GET['action']) && $_GET['action'] === 'refresh_cover') {
    generateCover($id, getBookFilePath($book), $book['title'], $book['author']);
    header('Location: book.php?id=' . $id . '&msg=' . urlencode('Cover refreshed.'));
    exit;
}

// Delete book
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    $filepath = getBookFilePath($book);
    if (file_exists($filepath)) unlink($filepath);
    if (!empty($book['cover_path'])) {
        $coverFull = APP_ROOT . '/' . $book['cover_path'];
        if (file_exists($coverFull)) unlink($coverFull);
    }
    $db->prepare('DELETE FROM books WHERE id = ?')->execute([$id]);
    header('Location: index.php?msg=' . urlencode('Deleted: ' . $book['title']));
    exit;
}

// Re-fetch after possible updates
$book = getBookById($id);
$filepath = getBookFilePath($book);

// Get collections for this book
$bookCollections = [];
$stmt = $db->prepare('SELECT collection_id FROM book_collections WHERE book_id = ?');
$stmt->execute([$id]);
while ($row = $stmt->fetch()) $bookCollections[] = $row['collection_id'];

$allCollections = $db->query('SELECT * FROM collections ORDER BY name')->fetchAll();

// Try to get Open Library info for synopsis
$olInfo = getOpenLibraryInfo($book['title'], $book['author']);

layoutHeader($book['title'], 'index.php');
layoutBreadcrumb(['Library' => 'index.php', $book['title'] => '']);
?>

<!-- Book detail layout -->
<table class="detail-table">
<tr>
    <td class="detail-cover-cell">
        <img src="<?php echo h(coverUrl($book)); ?>"
             alt="Cover" class="detail-cover"
             width="130" height="195">
    </td>
    <td class="detail-info-cell">
        <div class="detail-title"><?php echo h($book['title']); ?></div>
        <div class="detail-author">by <?php echo h($book['author'] ?: 'Unknown Author'); ?></div>

        <?php if (!empty($book['series'])): ?>
            <div class="detail-meta">Series: <?php echo h($book['series']); ?>
                <?php if ($book['series_index'] > 0): ?> #<?php echo $book['series_index']; ?><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="detail-meta">
            Format: <?php echo h(strtoupper($book['format'])); ?>
            &middot; Size: <?php echo formatFileSize($book['file_size']); ?>
        </div>
        <div class="detail-meta">Added: <?php echo h($book['date_added']); ?></div>
        <div class="detail-meta">Status: <?php echo statusBadge($book['status']); ?></div>

        <div style="margin:8px 0;">
            <b>Rating:</b> <?php echo ratingLinks($id, $book['rating']); ?>
        </div>

        <?php if ($olInfo && !empty($olInfo['synopsis'])): ?>
            <div style="margin:8px 0;padding:8px;background:#f4f4f4;border:1px solid #ddd;">
                <b>Synopsis:</b> <?php echo h($olInfo['synopsis']); ?>
            </div>
        <?php endif; ?>

        <?php if ($olInfo && !empty($olInfo['subjects'])): ?>
            <div class="detail-meta">
                Subjects: <?php echo h(implode(', ', $olInfo['subjects'])); ?>
            </div>
        <?php endif; ?>
    </td>
</tr>
</table>

<!-- Actions -->
<table width="100%"><tr><td class="p-12">
    <b style="font-size:16px;">Actions</b><br><br>

    <!-- Status changes -->
    <?php if ($book['status'] !== 'unread'): ?>
        <a href="book.php?id=<?php echo $id; ?>&set_status=unread" class="btn btn-small">Mark Unread</a>
    <?php endif; ?>
    <?php if ($book['status'] !== 'reading'): ?>
        <a href="book.php?id=<?php echo $id; ?>&set_status=reading" class="btn btn-small">Mark Reading</a>
    <?php endif; ?>
    <?php if ($book['status'] !== 'read'): ?>
        <a href="book.php?id=<?php echo $id; ?>&set_status=read" class="btn btn-small">Mark Read</a>
    <?php endif; ?>

    <br><br>

    <!-- Download to device -->
    <?php if (file_exists($filepath)): ?>
        <?php
        $dlDir = ($book['status'] === 'read') ? 'read' : 'unread';
        $dlUrl = 'books/' . $dlDir . '/' . rawurlencode($book['filename']);
        ?>
        <a href="<?php echo $dlUrl; ?>" class="btn btn-primary">Download to Device</a>
    <?php endif; ?>

    <!-- Convert to MOBI (if epub/pdf) -->
    <?php if (in_array($book['format'], ['epub', 'pdf'])): ?>
        <a href="convert.php?id=<?php echo $id; ?>" class="btn">Convert to MOBI</a>
    <?php endif; ?>

    <!-- Re-fetch cover -->
    <a href="book.php?id=<?php echo $id; ?>&action=refresh_cover" class="btn btn-small">Refresh Cover</a>

    <br><br>

    <!-- Delete -->
    <a href="book.php?id=<?php echo $id; ?>&action=delete"
       class="btn btn-danger"
       onclick="return confirm('Delete this book permanently?');">Delete Book</a>
</td></tr></table>

<!-- Notes -->
<table width="100%"><tr><td class="p-12">
    <b style="font-size:16px;">Notes</b>
    <form method="post" action="book.php?id=<?php echo $id; ?>">
        <textarea name="notes" style="width:98%;height:100px;font-size:14px;"><?php echo h($book['notes']); ?></textarea>
        <br>
        <input type="submit" name="save_notes" value="Save Notes" class="btn btn-small" style="margin-top:4px;">
    </form>
</td></tr></table>

<!-- Collections -->
<table width="100%"><tr><td class="p-12">
    <b style="font-size:16px;">Collections</b>
    <?php if (empty($allCollections)): ?>
        <p>No collections yet. <a href="collections.php">Create one</a>.</p>
    <?php else: ?>
        <form method="post" action="book.php?id=<?php echo $id; ?>">
        <?php foreach ($allCollections as $coll): ?>
            <div style="padding:4px 0;">
                <label style="font-size:16px;">
                    <input type="checkbox" name="coll[]" value="<?php echo $coll['id']; ?>"
                        <?php if (in_array($coll['id'], $bookCollections)) echo 'checked'; ?>
                        style="width:20px;height:20px;">
                    <?php echo h($coll['name']); ?>
                </label>
            </div>
        <?php endforeach; ?>
        <input type="submit" name="save_collections" value="Update Collections" class="btn btn-small" style="margin-top:6px;">
        </form>
    <?php endif; ?>
</td></tr></table>

<?php layoutFooter(); ?>
