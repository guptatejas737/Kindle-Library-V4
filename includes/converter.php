<?php
require_once __DIR__ . '/config.php';

/**
 * Converts an EPUB file to MOBI using pure PHP.
 *
 * Strategy:
 * 1. Open the EPUB (which is a ZIP archive)
 * 2. Parse container.xml to find the OPF file
 * 3. Parse the OPF to find the spine (reading order)
 * 4. Extract and concatenate all HTML content files
 * 5. Use wallabag/php-mobi to create a MOBI file
 *
 * Returns the path to the new MOBI file on success, or an error string on failure.
 */
function convertEpubToMobi(string $epubPath, string $outputPath = ''): array {
    if (!file_exists($epubPath)) {
        return ['success' => false, 'error' => 'EPUB file not found.'];
    }

    // Determine output path
    if (!$outputPath) {
        $outputPath = preg_replace('/\.epub$/i', '.mobi', $epubPath);
    }

    // Step 1: Open EPUB as ZIP
    $zip = new ZipArchive();
    if ($zip->open($epubPath) !== true) {
        return ['success' => false, 'error' => 'Could not open EPUB file (invalid ZIP).'];
    }

    // Step 2: Find the OPF file
    $containerXml = $zip->getFromName('META-INF/container.xml');
    if (!$containerXml) {
        $zip->close();
        return ['success' => false, 'error' => 'Invalid EPUB: missing container.xml.'];
    }

    $opfPath = '';
    $dom = new DOMDocument();
    @$dom->loadXML($containerXml);
    $rootfiles = $dom->getElementsByTagName('rootfile');
    if ($rootfiles->length > 0) {
        $opfPath = $rootfiles->item(0)->getAttribute('full-path');
    }

    if (!$opfPath) {
        $zip->close();
        return ['success' => false, 'error' => 'Invalid EPUB: could not locate OPF file.'];
    }

    $opfDir = dirname($opfPath);
    if ($opfDir === '.') $opfDir = '';
    else $opfDir .= '/';

    // Step 3: Parse OPF for manifest + spine
    $opfContent = $zip->getFromName($opfPath);
    if (!$opfContent) {
        $zip->close();
        return ['success' => false, 'error' => 'Invalid EPUB: could not read OPF.'];
    }

    $opfDom = new DOMDocument();
    @$opfDom->loadXML($opfContent);

    // Build manifest map: id => href
    $manifest = [];
    $manifestNodes = $opfDom->getElementsByTagName('item');
    for ($i = 0; $i < $manifestNodes->length; $i++) {
        $item = $manifestNodes->item($i);
        $manifest[$item->getAttribute('id')] = $item->getAttribute('href');
    }

    // Get spine order
    $spineIds = [];
    $spineNodes = $opfDom->getElementsByTagName('itemref');
    for ($i = 0; $i < $spineNodes->length; $i++) {
        $spineIds[] = $spineNodes->item($i)->getAttribute('idref');
    }

    if (empty($spineIds)) {
        $zip->close();
        return ['success' => false, 'error' => 'Invalid EPUB: empty spine.'];
    }

    // Extract title/author from OPF metadata
    $titleNodes = $opfDom->getElementsByTagName('title');
    $bookTitle = $titleNodes->length > 0 ? $titleNodes->item(0)->nodeValue : 'Untitled';
    $creatorNodes = $opfDom->getElementsByTagName('creator');
    $bookAuthor = $creatorNodes->length > 0 ? $creatorNodes->item(0)->nodeValue : '';

    // Step 4: Extract and concatenate HTML content
    $combinedHtml = '';
    foreach ($spineIds as $sid) {
        if (!isset($manifest[$sid])) continue;
        $href = $opfDir . $manifest[$sid];
        $content = $zip->getFromName($href);
        if (!$content) continue;

        // Strip the outer HTML/head/body tags and just keep the body content
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $content, $m)) {
            $bodyContent = $m[1];
        } else {
            $bodyContent = $content;
        }

        // Convert relative image references to base64 inline
        $bodyContent = preg_replace_callback(
            '/(<img[^>]+src=")([^"]+)(")/i',
            function ($matches) use ($zip, $opfDir, $href) {
                $imgSrc = $matches[2];
                // Resolve relative path
                $imgDir = dirname($href);
                if ($imgDir === '.') $imgDir = '';
                else $imgDir .= '/';
                $imgPath = $imgDir . $imgSrc;
                // Normalize path
                $imgPath = preg_replace('#[^/]+/\.\./#', '', $imgPath);

                $imgData = $zip->getFromName($imgPath);
                if ($imgData) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->buffer($imgData);
                    return $matches[1] . 'data:' . $mime . ';base64,' . base64_encode($imgData) . $matches[3];
                }
                return $matches[0];
            },
            $bodyContent
        );

        $combinedHtml .= $bodyContent . "\n<mbp:pagebreak/>\n";
    }

    $zip->close();

    if (empty(trim($combinedHtml))) {
        return ['success' => false, 'error' => 'No content found in EPUB.'];
    }

    // Wrap in basic HTML
    $fullHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
        . htmlspecialchars($bookTitle) . '</title></head><body>'
        . $combinedHtml . '</body></html>';

    // Step 5: Create MOBI using wallabag/php-mobi
    $autoload = APP_ROOT . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        return ['success' => false, 'error' => 'Composer dependencies not installed. Run: composer install'];
    }
    require_once $autoload;

    // php-mobi uses different class names across versions
    $mobiClass = null;
    foreach (['MOBIClass\\MOBI', 'MOBI'] as $cls) {
        if (class_exists($cls)) { $mobiClass = $cls; break; }
    }
    if (!$mobiClass) {
        return ['success' => false, 'error' => 'php-mobi library not available. Run: composer install'];
    }

    try {
        $mobi = new $mobiClass();
        $tmpMobi = tempnam(sys_get_temp_dir(), 'mobi_');

        // Try the ContentProvider approach (wallabag fork)
        $providerSet = false;
        foreach (['MOBIClass\\ContentProvider', 'ContentProvider'] as $pCls) {
            if (class_exists($pCls)) {
                $content = new $pCls();
                if (method_exists($content, 'setHtml')) $content->setHtml($fullHtml);
                if (method_exists($content, 'setTitle')) $content->setTitle($bookTitle);
                if (method_exists($content, 'setAuthor')) $content->setAuthor($bookAuthor);
                if (method_exists($mobi, 'setContentProvider')) {
                    $mobi->setContentProvider($content);
                    $providerSet = true;
                }
                break;
            }
        }

        if (!$providerSet) {
            // Fallback: try direct methods on the MOBI object
            if (method_exists($mobi, 'setData')) {
                $mobi->setData($fullHtml);
            }
        }

        $mobi->save($tmpMobi);

        if (!file_exists($tmpMobi) || filesize($tmpMobi) < 100) {
            @unlink($tmpMobi);
            return ['success' => false, 'error' => 'MOBI creation failed (empty output).'];
        }

        rename($tmpMobi, $outputPath);

        return [
            'success' => true,
            'path' => $outputPath,
            'filename' => basename($outputPath),
        ];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => 'Conversion error: ' . $e->getMessage()];
    }
}
