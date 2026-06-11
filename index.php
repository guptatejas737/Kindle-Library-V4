<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

$db = getDb();

// First-run: scan existing books into DB
$bookCount = $db->query('SELECT COUNT(*) FROM books')->fetchColumn();
if ($bookCount == 0) {
    require_once __DIR__ . '/includes/covers.php';
    $added = scanExistingBooks($db);
}

// Parse filters
$status = $_GET['status'] ?? '';
$format = $_GET['format'] ?? '';
$sort = $_GET['sort'] ?? 'date_added';
$order = $_GET['order'] ?? 'DESC';
$page = max(1, (int)($_GET['page'] ?? 1));
$searchTerm = $_GET['q'] ?? '';

$filters = [];
if ($status) $filters['status'] = $status;
if ($format) $filters['format'] = $format;
if ($searchTerm) $filters['search'] = $searchTerm;

$result = getBooks($filters, $sort, $order, $page);
$books = $result['books'];
$totalPages = $result['pages'];
$total = $result['total'];

layoutHeader('My Library', 'index.php');
?>

<!-- Filter bar -->
<form method="get" action="index.php">
<table width="100%" class="filter-bar" cellpadding="0" cellspacing="0">
<tr>
    <td>
        <select name="status">
            <option value="">All Status</option>
            <option value="unread"<?php if ($status === 'unread') echo ' selected'; ?>>Unread</option>
            <option value="reading"<?php if ($status === 'reading') echo ' selected'; ?>>Reading</option>
            <option value="read"<?php if ($status === 'read') echo ' selected'; ?>>Read</option>
        </select>
    </td>
    <td>
        <select name="sort">
            <option value="date_added"<?php if ($sort === 'date_added') echo ' selected'; ?>>Date Added</option>
            <option value="title"<?php if ($sort === 'title') echo ' selected'; ?>>Title</option>
            <option value="author"<?php if ($sort === 'author') echo ' selected'; ?>>Author</option>
            <option value="rating"<?php if ($sort === 'rating') echo ' selected'; ?>>Rating</option>
        </select>
    </td>
    <td>
        <select name="order">
            <option value="DESC"<?php if ($order === 'DESC') echo ' selected'; ?>>Newest First</option>
            <option value="ASC"<?php if ($order === 'ASC') echo ' selected'; ?>>Oldest First</option>
        </select>
    </td>
    <td>
        <input type="text" name="q" value="<?php echo h($searchTerm); ?>" placeholder="Filter..." style="width:90%;">
    </td>
    <td>
        <input type="submit" value="Filter">
    </td>
</tr>
</table>
</form>

<table width="100%"><tr><td class="p-8">
    <b><?php echo $total; ?></b> book<?php echo $total !== 1 ? 's' : ''; ?> in library
    <?php if ($status || $format || $searchTerm): ?>
        &mdash; <a href="index.php">Clear filters</a>
    <?php endif; ?>
</td></tr></table>

<?php if (empty($books)): ?>
<table width="100%"><tr><td class="p-12 text-center">
    <p>No books found.</p>
    <p><a href="search.php" class="btn btn-primary">Search for Books</a></p>
    <p><a href="upload.php" class="btn">Upload a Book</a></p>
</td></tr></table>
<?php else: ?>

<!-- Bookshelf grid -->
<table class="shelf-table" cellpadding="0" cellspacing="0">
<?php
$i = 0;
foreach ($books as $book):
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
        <div><?php echo statusBadge($book['status']); ?></div>
    </td>
<?php
    $i++;
    if ($i % BOOKS_PER_ROW === 0) {
        echo '</tr>';
        echo '<tr class="shelf-divider"><td colspan="' . BOOKS_PER_ROW . '"></td></tr>';
    }
endforeach;

// Close incomplete row
if ($i % BOOKS_PER_ROW !== 0) {
    $remaining = BOOKS_PER_ROW - ($i % BOOKS_PER_ROW);
    for ($r = 0; $r < $remaining; $r++) echo '<td></td>';
    echo '</tr>';
    echo '<tr class="shelf-divider"><td colspan="' . BOOKS_PER_ROW . '"></td></tr>';
}
?>
</table>

<?php echo buildPagination($page, $totalPages); ?>
<?php endif; ?>

<?php layoutFooter(); ?>
