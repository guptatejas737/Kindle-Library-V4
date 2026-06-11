<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

$db = getDb();

$action = $_GET['action'] ?? '';
$collId = (int)($_GET['id'] ?? 0);

// Create collection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if ($name) {
        $stmt = $db->prepare('INSERT OR IGNORE INTO collections (name, description) VALUES (?, ?)');
        $stmt->execute([$name, $desc]);
    }
    header('Location: collections.php?msg=' . urlencode('Collection created: ' . $name));
    exit;
}

// Edit collection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update']) && $collId) {
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if ($name) {
        $stmt = $db->prepare('UPDATE collections SET name = ?, description = ? WHERE id = ?');
        $stmt->execute([$name, $desc, $collId]);
    }
    header('Location: collections.php?msg=' . urlencode('Collection updated.'));
    exit;
}

// Delete collection
if ($action === 'delete' && $collId) {
    $db->prepare('DELETE FROM book_collections WHERE collection_id = ?')->execute([$collId]);
    $db->prepare('DELETE FROM collections WHERE id = ?')->execute([$collId]);
    header('Location: collections.php?msg=' . urlencode('Collection deleted.'));
    exit;
}

// View a specific collection
if ($action === 'view' && $collId) {
    $coll = $db->prepare('SELECT * FROM collections WHERE id = ?');
    $coll->execute([$collId]);
    $collection = $coll->fetch();

    if (!$collection) {
        header('Location: collections.php?msg=' . urlencode('Collection not found.'));
        exit;
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $result = getBooks(['collection_id' => $collId], $_GET['sort'] ?? 'date_added', $_GET['order'] ?? 'DESC', $page);

    layoutHeader($collection['name'], 'collections.php');
    layoutBreadcrumb(['Collections' => 'collections.php', $collection['name'] => '']);
    ?>

    <table width="100%"><tr><td class="p-12">
        <b style="font-size:20px;"><?php echo h($collection['name']); ?></b>
        <?php if ($collection['description']): ?>
            <div class="detail-meta"><?php echo h($collection['description']); ?></div>
        <?php endif; ?>
        <div class="detail-meta"><?php echo $result['total']; ?> book<?php echo $result['total'] !== 1 ? 's' : ''; ?></div>
        <div style="margin-top:6px;">
            <a href="collections.php?action=edit&id=<?php echo $collId; ?>" class="btn btn-small">Edit</a>
            <a href="collections.php?action=delete&id=<?php echo $collId; ?>" class="btn btn-danger btn-small"
               onclick="return confirm('Delete this collection?');">Delete</a>
        </div>
    </td></tr></table>

    <?php if (empty($result['books'])): ?>
        <table width="100%"><tr><td class="p-12 text-center">
            <p>No books in this collection yet.</p>
            <p>Open a book and add it to this collection.</p>
        </td></tr></table>
    <?php else: ?>
        <table class="shelf-table" cellpadding="0" cellspacing="0">
        <?php
        $i = 0;
        foreach ($result['books'] as $book):
            if ($i % BOOKS_PER_ROW === 0) echo '<tr class="shelf-row">';
        ?>
            <td class="book-cell">
                <a href="book.php?id=<?php echo $book['id']; ?>">
                    <img src="<?php echo h(coverUrl($book)); ?>"
                         alt="<?php echo h($book['title']); ?>"
                         class="book-cover"
                         width="<?php echo COVER_WIDTH; ?>"
                         height="<?php echo COVER_HEIGHT; ?>">
                </a>
                <div class="book-title">
                    <a href="book.php?id=<?php echo $book['id']; ?>" style="text-decoration:none;">
                        <?php echo h(truncate($book['title'], 35)); ?>
                    </a>
                </div>
                <div class="book-author"><?php echo h(truncate($book['author'] ?: 'Unknown', 25)); ?></div>
                <?php if ($book['rating'] > 0): ?>
                    <div class="book-rating"><?php echo ratingDisplay($book['rating']); ?></div>
                <?php endif; ?>
            </td>
        <?php
            $i++;
            if ($i % BOOKS_PER_ROW === 0) {
                echo '</tr>';
                echo '<tr class="shelf-divider"><td colspan="' . BOOKS_PER_ROW . '"></td></tr>';
            }
        endforeach;
        if ($i % BOOKS_PER_ROW !== 0) {
            $remaining = BOOKS_PER_ROW - ($i % BOOKS_PER_ROW);
            for ($r = 0; $r < $remaining; $r++) echo '<td></td>';
            echo '</tr><tr class="shelf-divider"><td colspan="' . BOOKS_PER_ROW . '"></td></tr>';
        }
        ?>
        </table>
        <?php echo buildPagination($result['page'], $result['pages']); ?>
    <?php endif; ?>

    <?php layoutFooter(); ?>
    <?php
    exit;
}

// Edit form
if ($action === 'edit' && $collId) {
    $coll = $db->prepare('SELECT * FROM collections WHERE id = ?');
    $coll->execute([$collId]);
    $collection = $coll->fetch();

    if (!$collection) {
        header('Location: collections.php?msg=' . urlencode('Collection not found.'));
        exit;
    }

    layoutHeader('Edit: ' . $collection['name'], 'collections.php');
    layoutBreadcrumb(['Collections' => 'collections.php', 'Edit: ' . $collection['name'] => '']);
    ?>
    <table width="100%"><tr><td class="p-12">
        <b style="font-size:20px;">Edit Collection</b>
        <form method="post" action="collections.php?id=<?php echo $collId; ?>">
            <table width="100%" cellpadding="4">
            <tr>
                <td width="100"><b>Name:</b></td>
                <td><input type="text" name="name" value="<?php echo h($collection['name']); ?>" style="width:90%;"></td>
            </tr>
            <tr>
                <td><b>Description:</b></td>
                <td><textarea name="description" style="width:90%;"><?php echo h($collection['description']); ?></textarea></td>
            </tr>
            <tr>
                <td></td>
                <td><input type="submit" name="update" value="Save Changes" class="btn btn-primary"></td>
            </tr>
            </table>
        </form>
    </td></tr></table>
    <?php
    layoutFooter();
    exit;
}

// Default: List all collections
layoutHeader('Collections', 'collections.php');
?>

<table width="100%"><tr><td class="p-12">
    <b style="font-size:20px;">Your Collections</b>
</td></tr></table>

<?php
$collections = $db->query('
    SELECT c.*, COUNT(bc.book_id) AS book_count
    FROM collections c
    LEFT JOIN book_collections bc ON c.id = bc.collection_id
    GROUP BY c.id
    ORDER BY c.sort_order, c.name
')->fetchAll();

if (empty($collections)):
?>
<table width="100%"><tr><td class="p-12 text-center">
    <p>No collections yet. Create your first bookshelf below.</p>
</td></tr></table>
<?php else: ?>
<table class="data-table">
<?php foreach ($collections as $coll): ?>
<tr>
    <td style="padding:12px;">
        <div class="collection-name">
            <a href="collections.php?action=view&id=<?php echo $coll['id']; ?>" style="font-size:18px;">
                <?php echo h($coll['name']); ?>
            </a>
        </div>
        <?php if ($coll['description']): ?>
            <div style="font-size:13px;color:#555;"><?php echo h($coll['description']); ?></div>
        <?php endif; ?>
        <div class="collection-count"><?php echo $coll['book_count']; ?> book<?php echo $coll['book_count'] != 1 ? 's' : ''; ?></div>
    </td>
    <td style="width:180px;padding:12px;text-align:right;">
        <a href="collections.php?action=view&id=<?php echo $coll['id']; ?>" class="btn btn-small">View</a>
        <a href="collections.php?action=edit&id=<?php echo $coll['id']; ?>" class="btn btn-small">Edit</a>
        <a href="collections.php?action=delete&id=<?php echo $coll['id']; ?>" class="btn btn-danger btn-small"
           onclick="return confirm('Delete this collection?');">Delete</a>
    </td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<!-- Create new collection form -->
<table width="100%"><tr><td class="p-12">
    <b style="font-size:18px;">Create New Collection</b>
    <form method="post" action="collections.php">
        <table width="100%" cellpadding="4">
        <tr>
            <td width="100"><b>Name:</b></td>
            <td><input type="text" name="name" placeholder="e.g. Fantasy, Science, To Read..." style="width:90%;"></td>
        </tr>
        <tr>
            <td><b>Description:</b></td>
            <td><textarea name="description" placeholder="Optional description..." style="width:90%;"></textarea></td>
        </tr>
        <tr>
            <td></td>
            <td><input type="submit" name="create" value="Create Collection" class="btn btn-primary"></td>
        </tr>
        </table>
    </form>
</td></tr></table>

<?php layoutFooter(); ?>
