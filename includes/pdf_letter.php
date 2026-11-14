<?php
// Minimal PDF writer for one-column business letters (A4, DIN 5008-like layout).
// No external library: text only, built-in Helvetica fonts, WinAnsi encoding.

class LetterPdf
{
    private const WIDTH = 595.28;
    private const HEIGHT = 841.89;
    private const MARGIN_LEFT = 70.87;   // 25 mm
    private const MARGIN_RIGHT = 56.69;  // 20 mm
    private const MARGIN_TOP = 56.69;
    private const MARGIN_BOTTOM = 62.0;

    // Helvetica advance widths (1/1000 em) for ASCII 32..126.
    private const WIDTHS = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];
    private const WIDTH_EXTRA = [0xC4 => 667, 0xD6 => 778, 0xDC => 722, 0xDF => 611];

    private array $pages = [];
    private float $y = 0.0;

    public function __construct()
    {
        $this->newPage();
    }

    private function newPage(): void
    {
        $this->pages[] = '';
        $this->y = self::MARGIN_TOP;
    }

    private static function encode(string $text): string
    {
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        }
        return (string) iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $text);
    }

    private function width(string $encoded, float $size, bool $bold): float
    {
        $sum = 0;
        for ($i = 0, $n = strlen($encoded); $i < $n; $i++) {
            $code = ord($encoded[$i]);
            $sum += ($code >= 32 && $code <= 126) ? self::WIDTHS[$code - 32] : (self::WIDTH_EXTRA[$code] ?? 556);
        }
        // Helvetica-Bold is about 7 % wider; err on the side of wrapping early.
        return $sum / 1000 * $size * ($bold ? 1.07 : 1.0);
    }

    private function put(float $x, float $y, string $text, float $size = 11.0, bool $bold = false): void
    {
        $encoded = self::encode($text);
        $this->pages[count($this->pages) - 1] .= sprintf(
            "BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1', $size, $x, self::HEIGHT - $y, self::escape($encoded)
        );
    }

    private function rule(float $x1, float $x2, float $y): void
    {
        $this->pages[count($this->pages) - 1] .= sprintf("0.4 w %.2F %.2F m %.2F %.2F l S\n", $x1, self::HEIGHT - $y, $x2, self::HEIGHT - $y);
    }

    /** @return string[] lines that fit into $maxWidth */
    private function wrap(string $paragraph, float $maxWidth, float $size, bool $bold): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && $this->width(self::encode($candidate), $size, $bold) > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines ?: [''];
    }

    /**
     * Builds the letter and returns the PDF bytes.
     * @param string[] $senderLines     name + address lines of the sender
     * @param string[] $recipientLines  name + address lines of the recipient
     */
    public function letter(array $senderLines, array $recipientLines, string $dateLine, string $subject, string $body, string $title): string
    {
        $textWidth = self::WIDTH - self::MARGIN_LEFT - self::MARGIN_RIGHT;

        // Sender block (top left).
        $y = self::MARGIN_TOP + 4;
        foreach ($senderLines as $i => $line) {
            $this->put(self::MARGIN_LEFT, $y, $line, 10.0, $i === 0);
            $y += 12.5;
        }

        // Return-address line above the recipient window, then the recipient.
        $this->put(self::MARGIN_LEFT, 142, implode(' · ', array_filter($senderLines)), 7.5);
        $this->rule(self::MARGIN_LEFT, self::MARGIN_LEFT + 240, 146);
        $y = 162;
        foreach ($recipientLines as $line) {
            $this->put(self::MARGIN_LEFT, $y, $line, 11.0);
            $y += 14;
        }

        // Date, right aligned.
        $dateWidth = $this->width(self::encode($dateLine), 11.0, false);
        $this->put(self::WIDTH - self::MARGIN_RIGHT - $dateWidth, 268, $dateLine, 11.0);

        // Subject (bold), then body.
        $this->y = 304;
        foreach ($this->wrap($subject, $textWidth, 11.5, true) as $line) {
            $this->put(self::MARGIN_LEFT, $this->y, $line, 11.5, true);
            $this->y += 15;
        }
        $this->y += 14;

        foreach (preg_split('/\R/u', str_replace("\r\n", "\n", $body)) as $paragraph) {
            if (trim($paragraph) === '') {
                $this->y += 9;
                continue;
            }
            foreach ($this->wrap($paragraph, $textWidth, 11.0, false) as $line) {
                if ($this->y > self::HEIGHT - self::MARGIN_BOTTOM) {
                    $this->newPage();
                }
                $this->put(self::MARGIN_LEFT, $this->y, $line, 11.0);
                $this->y += 15;
            }
        }
        return $this->output($title);
    }

    private function output(string $title): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        foreach ($this->pages as $i => $content) {
            $pageId = 5 + 2 * $i;
            $kids[] = $pageId . ' 0 R';
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::WIDTH, self::HEIGHT, $pageId + 1
            );
            $objects[$pageId + 1] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $infoId = 5 + 2 * count($this->pages);
        // Title as UTF-16BE hex string so umlauts survive.
        $objects[$infoId] = '<< /Title <FEFF' . strtoupper(bin2hex(mb_convert_encoding($title, 'UTF-16BE', 'UTF-8'))) . '> /Producer (Wallos Extended) /CreationDate (D:' . date('YmdHis') . ') >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($infoId + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $infoId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size " . ($infoId + 1) . " /Root 1 0 R /Info $infoId 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }
}
