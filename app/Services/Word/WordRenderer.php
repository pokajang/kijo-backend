<?php

namespace App\Services\Word;

use Illuminate\Http\Response;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

abstract class WordRenderer
{
    public const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    protected function createDocument(): PhpWord
    {
        Settings::setOutputEscapingEnabled(true);

        return new PhpWord;
    }

    protected function download(PhpWord $document, string $filename): Response
    {
        Settings::setOutputEscapingEnabled(true);

        $tempDirectory = sys_get_temp_dir();
        if (! is_dir($tempDirectory) || ! is_writable($tempDirectory)) {
            throw new \RuntimeException('The temporary document directory is not writable.');
        }

        $tempPath = tempnam($tempDirectory, 'kijo_docx_');
        if ($tempPath === false) {
            throw new \RuntimeException('Unable to create a temporary Word document.');
        }

        try {
            IOFactory::createWriter($document, 'Word2007')->save($tempPath);
            $contents = file_get_contents($tempPath);
            if ($contents === false || $contents === '') {
                throw new \RuntimeException('Generated Word document is empty.');
            }
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }

        $safeFilename = $this->safeFilename($filename);
        $encodedFilename = rawurlencode($safeFilename);

        return response($contents, 200, [
            'Content-Type' => self::MIME_TYPE,
            'Content-Disposition' => "attachment; filename=\"{$safeFilename}\"; filename*=UTF-8''{$encodedFilename}",
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function safeFilename(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $base), '._-');

        return ($base !== '' ? $base : 'document').'.docx';
    }
}
