<?php

declare(strict_types=1);

/**
 * Extraction texte PDF/DOCX (pretraitement avant envoi a Groq).
 * PyMuPDF en priorite pour les PDF — aucun algorithme ML (TF-IDF, Word2Vec, XGBoost).
 */
final class CvTextExtractor
{
    /** @var list<string>|null */
    private ?array $pythonCommand = null;
    private bool $pythonChecked = false;

    public function __construct(private readonly ?Config $config = null)
    {
    }

    public function extractFromPath(string $path, string $format, ?GroqClient $groq = null): string
    {
        $format = strtolower($format);
        $text = trim($this->extractViaPython($path, $format));

        if ($text === '') {
            $data = file_get_contents($path);
            if ($data === false) {
                throw new RuntimeException('Impossible de lire le fichier CV.');
            }
            $text = trim($this->extractFromBytes($data, $format));
        }

        if ($text === '' && $format === 'pdf' && $groq !== null) {
            $images = $this->exportPdfPagesAsBase64($path);
            if ($images !== []) {
                $text = trim($groq->extractCvTextFromPdfImages($images));
            }
        }

        return $text;
    }

    /** @return list<string> */
    public function exportPdfPagesAsBase64(string $path, int $maxPages = 4): array
    {
        $output = $this->runPythonScript($path, 'pdf', ['--pages-base64']);
        if ($output === '') {
            return [];
        }

        $decoded = json_decode($output, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
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

    private function extractViaPython(string $path, string $format): string
    {
        return trim($this->runPythonScript($path, $format));
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
        if ($configured !== '') {
            if ($this->commandResponds([$configured, '--version'])) {
                $this->pythonCommand = [$configured];

                return $this->pythonCommand;
            }
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
            return [
                ['py', '-3'],
                ['py', '-3.14'],
                ['py', '-3.12'],
                ['python'],
                ['python3'],
            ];
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
