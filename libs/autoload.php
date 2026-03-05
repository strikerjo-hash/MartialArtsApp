<?php
/**
 * libs/autoload.php — PSR-4-style autoloader for DomPDF and its dependencies.
 *
 * This replaces the need for Composer when only DomPDF is needed.
 * Maps namespace prefixes to the corresponding source directories.
 */

// Guard against double-registration
if (defined('DOMPDF_AUTOLOADER_REGISTERED')) {
    return;
}
define('DOMPDF_AUTOLOADER_REGISTERED', true);

$libsDir = __DIR__;

spl_autoload_register(function ($class) use ($libsDir) {
    // Namespace prefix => base directory mapping
    $prefixes = [
        'Dompdf\\'       => $libsDir . DIRECTORY_SEPARATOR . 'dompdf' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR,
        'FontLib\\'      => $libsDir . DIRECTORY_SEPARATOR . 'php-font-lib' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'FontLib' . DIRECTORY_SEPARATOR,
        'Svg\\'          => $libsDir . DIRECTORY_SEPARATOR . 'php-svg-lib' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Svg' . DIRECTORY_SEPARATOR,
        'Masterminds\\'  => $libsDir . DIRECTORY_SEPARATOR . 'html5-php' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR,
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        // Get the relative class name
        $relativeClass = substr($class, $len);

        // Build the file path (use DIRECTORY_SEPARATOR for Windows compatibility)
        $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});

// Also require Cpdf which is in lib/ not src/ and has no namespace
$cpdfFile = $libsDir . DIRECTORY_SEPARATOR . 'dompdf' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Cpdf.php';
if (file_exists($cpdfFile) && !class_exists('Dompdf\Cpdf', false)) {
    require_once $cpdfFile;
}
