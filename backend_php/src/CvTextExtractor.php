<?php

declare(strict_types=1);

/**
 * Extraction texte CV — Groq Vision en priorite (PDF), PHP natif pour DOCX.
 * Compatible Hostinger : Imagick / ImageMagick CLI, sans Python obligatoire.
 */
final class CvTextExtractor
{
    private const PDF_MAX_VISION_PAGES = 4;

    /** @var list<string>|null */
    private ?array $pythonCommand = null;
    private bool $pythonChecked = false;

    public function __construct(private readonly ?Config $config = null)
    {
    }

    public function extractFromPath(string $path, string $format, ?GroqClient $groq = null): string
    {
        $format = strtolower($format);

        return match ($format) {
            'pdf' => $this->extractPdfPath($path, $groq),
            'docx' => $this->extractDocxPath($path, $groq),
            default => throw new HttpException(400, 'Format non supporte. PDF ou DOCX requis.'),
        };
    }

    /** @return list<string> */
    public function exportPdfPagesAsBase64(string $path, int $maxPages = self::PDF_MAX_VISION_PAGES): array
    {
        $maxPages = max(1, min(8, $maxPages));

        $images = $this->exportPdfViaImagick($path, $maxPages);
        if ($images !== []) {
            return $images;
        }

        $images = $this->exportPdfViaImageMagickCli($path, $maxPages);
        if ($images !== []) {
            return $images;
        }

        $images = $this->exportPdfViaPython($path, $maxPages);
        if ($images !== []) {
            return $images;
        }

        return [];
    }

    public function extractFromBytes(string $data, string $format): string
    {
        return match (strtolower($format)) {
            'pdf' => $this->extractPdf($data),
            'docx' => $this->extractDocx($data),
            default => throw new HttpException(400, 'Format non supporte. PDF ou DOCX requis.'),
        };
    }

    public function detectFormat(string $filename): string
    {
        $lower = strtolower($filename);
        if (str_ends_with($lower, '.pdf')) {
            return 'pdf';
        }
        if (str_ends_with($lower, '.docx')) {
            return 'docx';
        }
        throw new HttpException(400, 'Format non supporte. PDF ou DOCX requis.');
    }

    private function extractPdfPath(string $path, ?GroqClient $groq): string
    {
        if ($groq === null || !$groq->isAvailable()) {
            throw new HttpException(
                503,
                'GROQ_API_KEY requise pour extraire et analyser les CV PDF.'
            );
        }

        $images = $this->exportPdfPagesAsBase64($path);
        if ($images !== []) {
            $text = trim($groq->extractCvTextFromPdfImages($images));
            if ($text !== '') {
                return $text;
            }
        }

        $data = file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException('Impossible de lire le fichier CV.');
        }

        $native = trim($this->extractPdf($data));
        if (strlen($native) >= 80) {
            return $native;
        }

        throw new HttpException(
            400,
            'Impossible d\'extraire le texte du PDF via Groq Vision. '
            . 'Verifiez GROQ_API_KEY dans .env et activez l\'extension Imagick sur le serveur (Hostinger : PHP → Extensions).'
        );
    }

    private function extractDocxPath(string $path, ?GroqClient $groq): string
    {
        $data = file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException('Impossible de lire le fichier CV.');
        }

        $text = trim($this->extractDocx($data));
        if ($text !== '') {
            return $text;
        }

        if ($groq !== null && $groq->isAvailable()) {
            $text = trim($groq->extractCvTextFromPlainContent(
                'Contenu brut DOCX illisible — extraction echouee cote serveur.',
                $data
            ));
            if ($text !== '') {
                return $text;
            }
        }

        throw new HttpException(400, 'Impossible d\'extraire le texte du fichier DOCX.');
    }

    private function extractDocx(string $data): string
    {
        if (!class_exists(ZipArchive::class)) {
            return '';
        }

        $zip = new ZipArchive();
        $tmp = tempnam(sys_get_temp_dir(), 'cvdocx_');
        if ($tmp === false) {
            throw new RuntimeException('Impossible de creer un fichier temporaire.');
        }

        try {
            file_put_contents($tmp, $data);
            if ($zip->open($tmp) !== true) {
                return '';
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml === false || $xml === '') {
                return '';
            }

            $root = simplexml_load_string($xml);
            if ($root === false) {
                return '';
            }

            $root->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $nodes = $root->xpath('//w:t') ?: [];
            $parts = [];
            foreach ($nodes as $node) {
                $text = trim((string) $node);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            return implode("\n", $parts);
        } finally {
            @unlink($tmp);
        }
    }

    /** @return list<string> */
    private function exportPdfViaImagick(string $path, int $maxPages): array
    {
        if (!class_exists(\Imagick::class)) {
            return [];
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(144, 144);
            $imagick->readImage($path . '[0-' . ($maxPages - 1) . ']');
            $imagick->setImageFormat('png');

            $images = [];
            foreach ($imagick as $page) {
                $page->setImageFormat('png');
                $page->setImageBackgroundColor('white');
                if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
                    $page->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                }
                $blob = $page->getImageBlob();
                if ($blob !== '') {
                    $encoded = base64_encode($blob);
                    if (strlen($encoded) <= 3_500_000) {
                        $images[] = $encoded;
                    }
                }
            }
            $imagick->clear();
            $imagick->destroy();

            return $images;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    private function exportPdfViaImageMagickCli(string $path, int $maxPages): array
    {
        if (!function_exists('exec') && !function_exists('shell_exec')) {
            return [];
        }

        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cv_pdf_' . bin2hex(random_bytes(4));
        if (!@mkdir($tmpDir, 0700) && !is_dir($tmpDir)) {
            return [];
        }

        try {
            $pageSpec = $path . '[0-' . ($maxPages - 1) . ']';
            $outPattern = $tmpDir . DIRECTORY_SEPARATOR . 'page.png';
            $escapedIn = $this->escapeCliArg($pageSpec);
            $escapedOut = $this->escapeCliArg($outPattern);

            $commands = [
                "magick convert -density 150 {$escapedIn} -quality 92 {$escapedOut}",
                "convert -density 150 {$escapedIn} -quality 92 {$escapedOut}",
            ];

            $ran = false;
            foreach ($commands as $command) {
                if (function_exists('exec')) {
                    @exec($command . ' 2>/dev/null', $_, $code);
                    if ($code === 0) {
                        $ran = true;
                        break;
                    }
                } elseif (function_exists('shell_exec')) {
                    @shell_exec($command . ' 2>/dev/null');
                    if (glob($tmpDir . DIRECTORY_SEPARATOR . 'page*.png') !== []) {
                        $ran = true;
                        break;
                    }
                }
            }

            if (!$ran) {
                return [];
            }

            $files = glob($tmpDir . DIRECTORY_SEPARATOR . '*.png') ?: [];
            sort($files);
            $images = [];
            foreach (array_slice($files, 0, $maxPages) as $file) {
                $blob = file_get_contents($file);
                if ($blob !== false && $blob !== '') {
                    $encoded = base64_encode($blob);
                    if (strlen($encoded) <= 3_500_000) {
                        $images[] = $encoded;
                    }
                }
            }

            return $images;
        } finally {
            foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmpDir);
        }
    }

    /** @return list<string> */
    private function exportPdfViaPython(string $path, int $maxPages): array
    {
        $output = $this->runPythonScript($path, 'pdf', ['--pages-base64', '--max-pages', (string) $maxPages]);
        if ($output === '') {
            return [];
        }

        $decoded = json_decode($output, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function escapeCliArg(string $value): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return '"' . str_replace(['"', '%'], ['""', '%%'], $value) . '"';
        }

        return escapeshellarg($value);
    }

    private function extractPdf(string $data): string
    {
        $fragments = array_merge(
            $this->extractPdfFromStreams($data),
            $this->extractPdfOperators($data)
        );

        return $this->normalizePdfText(implode("\n", $fragments));
    }

    private function extractPdfFromStreams(string $data): array
    {
        if (!preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $data, $matches)) {
            return [];
        }

        $fragments = [];
        foreach ($matches[1] as $stream) {
            $payload = $this->decompressPdfStream($stream);
            $fragments = array_merge($fragments, $this->extractPdfOperators($payload));
        }

        return $fragments;
    }

    private function extractPdfOperators(string $data): array
    {
        $fragments = [];

        if (preg_match_all('/\(((?:\\\\.|[^\\\\])*?)\)\s*Tj/s', $data, $matches)) {
            foreach ($matches[1] as $part) {
                $text = trim($this->decodePdfString($part));
                if ($text !== '') {
                    $fragments[] = $text;
                }
            }
        }

        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $data, $matches)) {
            foreach ($matches[1] as $segment) {
                if (!preg_match_all('/\(((?:\\\\.|[^\\\\])*?)\)/s', $segment, $parts)) {
                    continue;
                }
                $text = '';
                foreach ($parts[1] as $part) {
                    $text .= $this->decodePdfString($part);
                }
                $text = trim($text);
                if ($text !== '') {
                    $fragments[] = $text;
                }
            }
        }

        if (preg_match_all('/<([0-9A-Fa-f\s]+)>\s*Tj/', $data, $matches)) {
            foreach ($matches[1] as $hex) {
                $text = trim($this->decodePdfHexString($hex));
                if ($text !== '') {
                    $fragments[] = $text;
                }
            }
        }

        return $fragments;
    }

    private function decompressPdfStream(string $stream): string
    {
        $attempts = [
            static fn (string $s): string|false => @gzuncompress($s),
            static fn (string $s): string|false => @zlib_decode($s),
            static fn (string $s): string|false => @gzinflate($s),
            static fn (string $s): string|false => strlen($s) > 2 ? @gzinflate(substr($s, 2)) : false,
        ];

        foreach ($attempts as $attempt) {
            $decoded = $attempt($stream);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $stream;
    }

    private function decodePdfHexString(string $hex): string
    {
        $hex = preg_replace('/\s+/', '', $hex) ?? '';
        if ($hex === '' || strlen($hex) % 2 !== 0) {
            return '';
        }

        $bytes = '';
        for ($i = 0; $i < strlen($hex); $i += 2) {
            $bytes .= chr(hexdec(substr($hex, $i, 2)));
        }

        if (str_starts_with($bytes, "\xFE\xFF")) {
            $converted = @mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
            if (is_string($converted)) {
                return $converted;
            }
        }

        if (str_starts_with($bytes, "\xFF\xFE")) {
            $converted = @mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
            if (is_string($converted)) {
                return $converted;
            }
        }

        return preg_replace('/[^\P{C}\n\r\t]+/u', '', $bytes) ?? $bytes;
    }

    private function decodePdfString(string $value): string
    {
        $output = '';
        $length = strlen($value);
        $index = 0;

        while ($index < $length) {
            $current = ord($value[$index]);
            if ($current === 92 && $index + 1 < $length) {
                $index++;
                $escaped = ord($value[$index]);
                $replacements = [
                    110 => "\n",
                    114 => "\r",
                    116 => "\t",
                    98 => "\b",
                    102 => "\f",
                    40 => '(',
                    41 => ')',
                    92 => '\\',
                ];
                if (isset($replacements[$escaped])) {
                    $output .= $replacements[$escaped];
                } elseif ($escaped >= 48 && $escaped <= 55) {
                    $octal = (string) $escaped;
                    for ($i = 0; $i < 2 && $index + 1 < $length; $i++) {
                        $next = ord($value[$index + 1]);
                        if ($next >= 48 && $next <= 55) {
                            $index++;
                            $octal .= (string) $next;
                        } else {
                            break;
                        }
                    }
                    $output .= chr((int) octdec($octal));
                } else {
                    $output .= chr($escaped);
                }
            } else {
                $output .= $value[$index];
            }
            $index++;
        }

        return $output;
    }

    private function normalizePdfText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** @param list<string> $extraArgs */
    private function runPythonScript(string $path, string $format, array $extraArgs = []): string
    {
        $command = $this->resolvePythonCommand();
        if ($command === null) {
            return '';
        }

        $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'extract_cv_text.py';
        if (!is_file($script)) {
            return '';
        }

        $args = [...$command, $script, $path, $format, ...$extraArgs];
        $stdout = $this->runPythonProcOpen($args);
        if ($stdout !== '') {
            return $stdout;
        }

        return $this->runPythonShellExec($args);
    }

    /** @param list<string> $args */
    private function runPythonProcOpen(array $args): string
    {
        $process = @proc_open(
            $args,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return '';
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? trim((string) $stdout) : '';
    }

    /** @param list<string> $args */
    private function runPythonShellExec(array $args): string
    {
        if (!function_exists('shell_exec')) {
            return '';
        }

        $quoted = array_map(
            static fn (string $arg): string => '"' . str_replace(['"', '%'], ['""', '%%'], $arg) . '"',
            $args
        );
        $output = shell_exec(implode(' ', $quoted) . ' 2>nul');

        return trim((string) $output);
    }

    /** @return list<string>|null */
    private function resolvePythonCommand(): ?array
    {
        if ($this->pythonChecked) {
            return $this->pythonCommand;
        }

        $this->pythonChecked = true;
        $configured = trim((string) ($this->config?->get('PYTHON_BINARY') ?? ''));
        if ($configured !== '' && $this->commandResponds([$configured, '--version'])) {
            $this->pythonCommand = [$configured];

            return $this->pythonCommand;
        }

        foreach ($this->defaultPythonCandidates() as $candidate) {
            if ($this->commandResponds($candidate)) {
                $this->pythonCommand = $candidate;

                return $this->pythonCommand;
            }
        }

        $this->pythonCommand = null;

        return null;
    }

    /** @return list<list<string>> */
    private function defaultPythonCandidates(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [['py', '-3'], ['py', '-3.14'], ['py', '-3.12'], ['python'], ['python3']];
        }

        return [['python3'], ['python']];
    }

    /** @param list<string> $command */
    private function commandResponds(array $command): bool
    {
        $process = @proc_open(
            [...$command, '--version'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return false;
        }

        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }
}
