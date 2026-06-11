<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/libgen.php';
require_once __DIR__ . '/includes/dedup.php';
require_once __DIR__ . '/includes/covers.php';

$db = getDb();

$bookName = $_GET['book_name'] ?? '';
$extension = $_GET['file_extension'] ?? 'mobi';
$showAll = $_GET['expand'] ?? '';
$submitted = isset($_GET['submit']);

layoutHeader('Search Books', 'search.php');
?>

<table width="100%"><tr><td class="p-12">
<form method="get" action="search.php">
    <table width="100%" cellpadding="4" cellspacing="0">
    <tr>
        <td colspan="3"><b style="font-size:20px;">Search for Books</b></td>
    </tr>
    <tr>
        <td width="70%">
            <input type="text" name="book_name"
                   value="<?php echo h($bookName); ?>"
                   placeholder="Enter book title or author..."
                   style="width:98%;font-size:18px;padding:10px;">
        </td>
        <td width="15%">
            <select name="file_extension" style="width:100%;font-size:16px;padding:8px;">
                <?php foreach (['mobi', 'epub', 'pdf', 'azw3', 'djvu', 'fb2'] as $ext): ?>
                <option value="<?php echo $ext; ?>"<?php if ($extension === $ext) echo ' selected'; ?>>
                    <?php echo strtoupper($ext); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td width="15%">
            <input type="submit" name="submit" value="Search" style="width:100%;font-size:18px;padding:10px;">
        </td>
    </tr>
    </table>
</form>
</td></tr></table>

<?php if ($submitted && !empty($bookName)):
    $rawResults = searchLibgen($bookName, $extension);

    if (empty($rawResults)):
?>
<table width="100%"><tr><td class="p-12 text-center">
    <p><b>No results found for "<?php echo h($bookName); ?>"</b></p>
    <p>Try a different search term or file format.</p>
</td></tr></table>
<?php
    else:
        $groups = deduplicateResults($rawResults, $extension);

        // Try to fetch Open Library info for up to 5 unique titles (to keep page load fast)
        $olCache = [];
        $olCount = 0;
        foreach ($groups as $group) {
            if ($olCount >= 5) break;
            $b = $group['best'];
            $key = md5(strtolower($b['title']) . '|' . strtolower($b['author']));
            if (!isset($olCache[$key])) {
                $info = getOpenLibraryInfo($b['title'], $b['author']);
                $olCache[$key] = $info;
                $olCount++;
            }
        }
?>

<table width="100%"><tr><td class="p-8">
    <b><?php echo count($rawResults); ?></b> results found,
    <b><?php echo count($groups); ?></b> unique titles after deduplication
</td></tr></table>

<table class="data-table">
<?php foreach ($groups as $gi => $group):
    $best = $group['best'];
    $editions = $group['editions'];
    $groupKey = $group['group_key'];
    $isExpanded = ($showAll === md5($groupKey));

    $olKey = md5(strtolower($best['title']) . '|' . strtolower($best['author']));
    $olInfo = $olCache[$olKey] ?? null;
?>
<tr>
    <td style="width:90px;padding:8px;">
        <?php if ($olInfo && !empty($olInfo['cover_url'])): ?>
            <img src="<?php echo h($olInfo['cover_url']); ?>"
                 alt="Cover" class="search-cover"
                 width="80" height="120">
        <?php else: ?>
            <img src="covers/placeholder.php?t=<?php echo urlencode($best['title']); ?>&a=<?php echo urlencode($best['author']); ?>"
                 alt="Cover" class="search-cover"
                 width="80" height="120">
        <?php endif; ?>
    </td>
    <td style="padding:8px;">
        <div class="search-title"><?php echo h($best['title']); ?></div>
        <div class="search-author">by <?php echo h($best['author'] ?: 'Unknown'); ?></div>
        <div class="search-meta">
            <?php echo h(strtoupper($best['format'])); ?> &middot; <?php echo h($best['size']); ?>
            <?php if ($editions > 1): ?>
                &middot;
                <?php if ($isExpanded): ?>
                    <a href="<?php echo h(currentUrl(['expand' => null])); ?>"><?php echo $editions; ?> editions (collapse)</a>
                <?php else: ?>
                    <a href="<?php echo h(currentUrl(['expand' => md5($groupKey)])); ?>"><?php echo $editions; ?> editions</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($olInfo && !empty($olInfo['synopsis'])): ?>
            <div class="search-synopsis"><?php echo h(truncate($olInfo['synopsis'], 200)); ?></div>
        <?php endif; ?>
        <div style="margin-top:6px;">
            <a href="download.php?mode=save&url=<?php echo urlencode($best['mirror_url']); ?>&title=<?php echo urlencode($best['title']); ?>&format=<?php echo urlencode($best['format']); ?>"
               class="btn btn-primary btn-small">Save to Library</a>
            <a href="download.php?mode=direct&url=<?php echo urlencode($best['mirror_url']); ?>&title=<?php echo urlencode($best['title']); ?>&format=<?php echo urlencode($best['format']); ?>"
               class="btn btn-small">Download to Device</a>
        </div>
    </td>
</tr>
<?php
    // Show expanded editions
    if ($isExpanded && $editions > 1):
        foreach ($group['all'] as $ei => $edition):
            if ($ei === 0) continue; // skip best, already shown
?>
<tr style="background:#f4f4f4;">
    <td style="padding:4px 8px;"></td>
    <td style="padding:6px 8px;border-top:1px dashed #ccc;">
        <span class="search-meta">
            <?php echo h(strtoupper($edition['format'])); ?> &middot; <?php echo h($edition['size']); ?>
            &middot; <?php echo h(truncate($edition['title'], 60)); ?>
        </span>
        <a href="download.php?mode=save&url=<?php echo urlencode($edition['mirror_url']); ?>&title=<?php echo urlencode($edition['title']); ?>&format=<?php echo urlencode($edition['format']); ?>"
           class="btn btn-small" style="padding:4px 10px;font-size:13px;">Save</a>
        <a href="download.php?mode=direct&url=<?php echo urlencode($edition['mirror_url']); ?>&title=<?php echo urlencode($edition['title']); ?>&format=<?php echo urlencode($edition['format']); ?>"
           class="btn btn-small" style="padding:4px 10px;font-size:13px;">Direct</a>
    </td>
</tr>
<?php
        endforeach;
    endif;
endforeach;
?>
</table>

<?php
    endif; // empty results
endif; // submitted
?>

<?php layoutFooter(); ?>
