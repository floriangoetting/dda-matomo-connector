<?php

namespace DDA\MatomoConnector\Support;

final class ConfigWriter
{
    /** @param array<string, mixed> $values */
    public static function write(string $path, array $values): void
    {
        if (is_file($path)) {
            throw new \RuntimeException('Connector config.php already exists.');
        }

        $directory = dirname($path);
        if (!is_writable($directory)) {
            throw new \RuntimeException('The connector directory is not writable.');
        }

        $content = "<?php\n\nreturn " . var_export($values, true) . ";\n";
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Connector config.php could not be written.');
        }

        @chmod($path, 0640);
    }
}
