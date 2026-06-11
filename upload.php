<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/covers.php';

$db = getDb();

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['bookFile'])) {
    $file = $_FILES['bookFile'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL => 'File only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file.',
        ];
        $errMsg = $errors[$file['error']] ?? 'Unknown upload error.';
        header('Location: upload.php?msg=' . urlencode($errMsg));
        exit;
    }

    $originalName = basename($file['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExts = ['mobi', 'epub', 'pdf', 'azw', 'azw3', 'fb2', 'djvu', 'txt', 'doc', 'docx', 'cbz', 'cbr'];

    if (!in_array($ext, $allowedExts)) {
        header('Location: upload.php?msg=' . urlencode('Unsupported file type: .' . $ext));
        exit;
    }

    $filename = sanitizeFileName($originalName);
    $targetPath = UNREAD_DIR . '/' . $filename;

    // Avoid overwriting
    if (file_exists($targetPath)) {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $counter = 1;
        while (file_exists(UNREAD_DIR . '/' . $base . '_' . $counter . '.' . $ext)) {
            $counter++;
        }
        $filename = $base . '_' . $counter . '.' . $ext;
        $targetPath = UNREAD_DIR . '/' . $filename;
    }

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $bookId = addBook($filename, 'unread');
        header('Location: book.php?id=' . $bookId . '&msg=' . urlencode('Uploaded successfully: ' . $filename));
        exit;
    } else {
        header('Location: upload.php?msg=' . urlencode('Failed to save uploaded file.'));
        exit;
    }
}

layoutHeader('Upload Book', 'upload.php');
?>

<table width="100%"><tr><td class="p-12">
    <b style="font-size:20px;">Upload a Book</b>

    <div style="margin:12px 0;padding:10px;background:#f4f4f4;border:1px solid #ddd;">
        Upload ebook files directly from your device. Supported formats:
        MOBI, EPUB, PDF, AZW, AZW3, FB2, TXT, and more.
    </div>

    <form method="post" action="upload.php" enctype="multipart/form-data">
        <table width="100%" cellpadding="8">
        <tr>
            <td width="120"><b>Select file:</b></td>
            <td>
                <input type="file" name="bookFile" id="bookFile"
                       accept=".mobi,.epub,.pdf,.azw,.azw3,.fb2,.djvu,.txt,.doc,.docx,.cbz,.cbr"
                       style="font-size:16px;">
            </td>
        </tr>
        <tr>
            <td></td>
            <td>
                <input type="submit" value="Upload Book" class="btn btn-primary" style="font-size:18px;padding:12px 32px;">
            </td>
        </tr>
        </table>
    </form>
</td></tr></table>

<!-- Quick link to search -->
<table width="100%"><tr><td class="p-12" style="border-top:1px solid #ddd;">
    <b>Looking for a book?</b> <a href="search.php" class="btn btn-small">Search Online</a>
</td></tr></table>

<?php layoutFooter(); ?>
