<?php

namespace App\Libraries\Knowledge;

use App\Exceptions\DomainRuleException;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use ZipArchive;

/** Plain text from uploaded documents. Never executes or renders the content. */
class TextExtractor
{
    public const EXTENSIONS = ['txt', 'md', 'csv', 'html', 'htm', 'docx', 'pdf'];

    public function extract(string $path, string $extension): string
    {
        $extension = strtolower($extension);
        $text = match ($extension) {
            'txt', 'md', 'csv' => (string) file_get_contents($path),
            'html', 'htm'      => $this->html((string) file_get_contents($path)),
            'docx'             => $this->docx($path),
            'pdf'              => $this->pdf($path),
            default            => throw new DomainRuleException('Format file tidak didukung. Gunakan: ' . implode(', ', self::EXTENSIONS) . '.'),
        };

        return $this->normalize($text);
    }

    public function normalize(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text;   // control characters
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function html(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\s*/?>#i', "\n", $html) ?? '';

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function docx(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new DomainRuleException('File .docx tidak dapat dibaca.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            throw new DomainRuleException('File .docx tidak berisi dokumen Word yang valid.');
        }
        $xml = preg_replace('#</w:p>#', "\n", $xml) ?? '';
        $xml = preg_replace('#<w:tab/>#', "\t", $xml) ?? '';

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function pdf(string $path): string
    {
        try {
            $text = (new PdfParser())->parseFile($path)->getText();
        } catch (Throwable $e) {
            throw new DomainRuleException('PDF tidak dapat dibaca (mungkin terenkripsi atau rusak).');
        }
        if (trim($text) === '') {
            throw new DomainRuleException('PDF tidak berisi teks (kemungkinan hasil scan). Unggah versi teks atau OCR terlebih dahulu.');
        }

        return $text;
    }
}
