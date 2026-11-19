<?php

declare(strict_types=1);

namespace App\Services;

use PhpOffice\PhpWord\Shared\XMLReader;
use RuntimeException;
use ZipArchive;

final class DocxReader
{
    private const WORD_NAMESPACES = [
        'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
        'http://purl.oclc.org/ooxml/wordprocessingml/main',
    ];
    private const PART_LIMIT = 12_000_000;
    private const PARAGRAPH_TYPES = [
        'sceneheading' => 'heading', 'slugline' => 'heading', 'slug' => 'heading',
        'action' => 'action', 'character' => 'character', 'charactercue' => 'character',
        'dialogue' => 'dialogue', 'dialog' => 'dialogue', 'parenthetical' => 'parenthetical',
        'transition' => 'transition', 'scriptnote' => 'note', 'screenplaynote' => 'note',
    ];

    public static function read(string $path): array
    {
        if (!class_exists(XMLReader::class) || !class_exists(ZipArchive::class)) {
            throw new RuntimeException('DOCX import needs the Composer dependencies and the PHP zip extension.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('This file is not a readable .docx document.');
        }
        try {
            $types = self::part($zip, '[Content_Types].xml', 250_000, true);
            if (!str_contains($types, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml')) {
                throw new RuntimeException('Save the file as a Word .docx document and try again.');
            }
            $body = self::part($zip, 'word/document.xml', self::PART_LIMIT, true);
            $styles = self::part($zip, 'word/styles.xml', 1_500_000, false);
        } finally {
            $zip->close();
        }

        $reader = self::xml($body);
        $styleNames = self::styles($styles);
        $lines = [];
        $hints = [];
        $textBytes = 0;
        $paragraphs = $reader->getElements('/w:document/w:body//w:p[not(ancestor::w:p or ancestor::w:del or ancestor::w:moveFrom)]');
        foreach ($paragraphs as $paragraph) {
            $style = $reader->getAttribute('w:val', $paragraph, './w:pPr/w:pStyle');
            $styleName = $styleNames[$style] ?? $style;
            $type = self::styleType($styleName) ?? self::styleType($style);
            $nodes = $reader->getElements(
                './/*[self::w:t or self::w:tab or self::w:br or self::w:cr or self::w:noBreakHyphen]
                 [not(ancestor::w:drawing or ancestor::w:pict or ancestor::w:del or ancestor::w:moveFrom)]',
                $paragraph
            );
            $pieces = [];
            foreach ($nodes as $node) {
                $pieces[] = match ($node->localName) {
                    'tab' => "\t",
                    'br', 'cr' => "\n",
                    'noBreakHyphen' => '‑',
                    default => $node->textContent,
                };
            }
            $text = implode('', $pieces);
            $textBytes += strlen($text) + 1;
            if ($textBytes > 2_000_000 || count($lines) >= 20_000) {
                throw new RuntimeException('The Word document contains too much text to review in one import.');
            }
            foreach (preg_split('/\R/u', $text) as $line) {
                if ($type !== null && trim($line) !== '') {
                    $hints[count($lines)] = ['type' => $type, 'style' => $styleName];
                }
                $lines[] = $line;
            }
        }
        $source = implode("\n", $lines);
        if (trim($source) === '') {
            throw new RuntimeException('No editable text was found in the Word document body.');
        }
        return ['text' => $source, 'hints' => $hints];
    }

    private static function part(ZipArchive $zip, string $name, int $limit, bool $required): ?string
    {
        $part = $zip->statName($name);
        if ($part === false) {
            if (!$required) {
                return null;
            }
            throw new RuntimeException('The Word document is missing its main content.');
        }
        if ($part['size'] > $limit) {
            throw new RuntimeException('An expanded Word document part exceeds the import limit.');
        }
        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw new RuntimeException('A Word document part could not be read.');
        }
        try {
            $bytes = stream_get_contents($stream, $limit + 1);
        } finally {
            fclose($stream);
        }
        if ($bytes === false || strlen($bytes) !== $part['size'] || strlen($bytes) > $limit) {
            throw new RuntimeException('A Word document part is damaged or too large.');
        }
        return $bytes;
    }

    private static function xml(string $bytes): XMLReader
    {
        if (!mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0") || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $bytes)) {
            throw new RuntimeException('The Word document contains unsupported XML. Resave it as a standard .docx.');
        }
        $reader = new XMLReader();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = $reader->getDomFromString($bytes);
            $namespace = $dom->documentElement?->namespaceURI;
            if (!in_array($namespace, self::WORD_NAMESPACES, true)) {
                throw new RuntimeException('The Word document contains an invalid XML part.');
            }
            $reader->registerNamespace('w', $namespace);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $reader;
    }

    private static function styles(?string $bytes): array
    {
        if ($bytes === null) {
            return [];
        }
        $reader = self::xml($bytes);
        $names = [];
        foreach ($reader->getElements('/w:styles/w:style') as $style) {
            $id = $reader->getAttribute('w:styleId', $style);
            $name = $reader->getAttribute('w:val', $style, './w:name');
            if ($id && $name) {
                $names[$id] = $name;
            }
        }
        return $names;
    }

    private static function styleType(?string $style): ?string
    {
        $key = preg_replace('/[^a-z]/', '', strtolower($style ?? ''));
        return self::PARAGRAPH_TYPES[$key] ?? null;
    }
}
