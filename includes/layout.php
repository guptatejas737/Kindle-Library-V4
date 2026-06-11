<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

function layoutHeader(string $title = 'My Library', string $activePage = ''): void {
    $nav = [
        'index.php' => 'Library',
        'collections.php' => 'Collections',
        'search.php' => 'Search',
        'upload.php' => 'Upload',
    ];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($title); ?></title>
    <link rel="stylesheet" href="css/kindle.css">
</head>
<body>
<table class="nav-bar" width="100%" cellpadding="0" cellspacing="0">
<tr>
<?php foreach ($nav as $href => $label):
    $isCurrent = (basename($_SERVER['PHP_SELF']) === $href) || ($activePage === $href);
?>
    <td class="nav-item<?php echo $isCurrent ? ' nav-active' : ''; ?>">
        <a href="<?php echo $href; ?>"><?php echo $label; ?></a>
    </td>
<?php endforeach; ?>
</tr>
</table>
<?php if (!empty($_GET['msg'])): ?>
<table width="100%"><tr><td class="flash-msg"><?php echo h($_GET['msg']); ?></td></tr></table>
<?php endif; ?>
<?php
}

function layoutBreadcrumb(array $crumbs): void {
    if (empty($crumbs)) return;
    echo '<table width="100%"><tr><td class="breadcrumb">';
    $parts = [];
    foreach ($crumbs as $label => $href) {
        if ($href) {
            $parts[] = '<a href="' . h($href) . '">' . h($label) . '</a>';
        } else {
            $parts[] = '<b>' . h($label) . '</b>';
        }
    }
    echo implode(' &rsaquo; ', $parts);
    echo '</td></tr></table>';
}

function layoutFooter(): void {
?>
<table width="100%" class="footer-bar"><tr><td align="center">
    <small>Kindle Library</small>
</td></tr></table>
</body>
</html>
<?php
}
