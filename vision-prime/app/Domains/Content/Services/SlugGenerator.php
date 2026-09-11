<?php

namespace App\Domains\Content\Services;

class SlugGenerator
{
    /**
     * Unicode codepoint → English transliteration
     * Much more reliable than hex-byte matching
     */
    private static array $map = [
        // Arabic/Persian letters (U+0600-U+06FF)
        0x0621 => '',     // ء hamza (skip)
        0x0622 => 'a',    // آ alef with madda
        0x0623 => 'a',    // أ alef with hamza above
        0x0624 => 'o',    // ؤ waw with hamza
        0x0625 => 'e',    // إ alef with hamza below
        0x0626 => 'y',    // ئ ya with hamza
        0x0627 => 'a',    // ا alef
        0x0628 => 'b',    // ب
        0x0629 => 'e',    // ة ta marbuta → e
        0x062A => 't',    // ت
        0x062B => 's',    // ث
        0x062C => 'j',    // ج
        0x062D => 'h',    // ح
        0x062E => 'kh',   // خ
        0x062F => 'd',    // د
        0x0630 => 'z',    // ذ
        0x0631 => 'r',    // ر
        0x0632 => 'z',    // ز
        0x0633 => 's',    // س
        0x0634 => 'sh',   // ش
        0x0635 => 's',    // ص
        0x0636 => 'z',    // ض
        0x0637 => 't',    // ط
        0x0638 => 'z',    // ظ
        0x0639 => 'a',    // ع
        0x063A => 'gh',   // غ
        0x0640 => '',     // ـ tatweel (skip)
        0x0641 => 'f',    // ف
        0x0642 => 'gh',   // ق (Persian gh)
        0x0643 => 'k',    // ك
        0x0644 => 'l',    // ل
        0x0645 => 'm',    // م
        0x0646 => 'n',    // ن
        0x0647 => 'h',    // ه (initial/medial = h)
        0x0648 => 'o',    // و (Persian waw = o)
        0x0649 => 'a',    // ى alef maksura
        0x064A => 'y',    // ي ya

        // Extended Arabic (U+0750-U+077F)
        0x0750 => 'gh',

        // Arabic supplemental (U+08A0-U+08FF)
        0x08A0 => 'g',

        // Persian-specific (U+06F0-U+06FF range)
        0x06F0 => '0',    // ۰
        0x06F1 => '1',    // ۱
        0x06F2 => '2',    // ۲
        0x06F3 => '3',    // ۳
        0x06F4 => '4',    // ۴
        0x06F5 => '5',    // ۵
        0x06F6 => '6',    // ۶
        0x06F7 => '7',    // ۷
        0x06F8 => '8',    // ۸
        0x06F9 => '9',    // ۹

        // Persian letters with dots (U+0680-U+06FF)
        0x067E => 'p',    // پ
        0x0686 => 'ch',   // چ
        0x0698 => 'zh',   // ژ
        0x06A9 => 'k',    // ک
        0x06AF => 'g',    // گ
        0x06BE => 'h',    // ھ
        0x06CC => 'y',    // ی
        0x06D0 => 'e',    // ې

        // Combining marks (U+064B-U+065F) — skip
        0x064B => '', 0x064C => '', 0x064D => '',
        0x064E => '', 0x064F => '', 0x0650 => '',
        0x0651 => '', 0x0652 => '', 0x0653 => '',
        0x0654 => '', 0x0655 => '', 0x0656 => '',
        0x0657 => '', 0x0658 => '', 0x0659 => '',
        0x065A => '', 0x065B => '', 0x065C => '',
        0x065D => '', 0x065E => '', 0x065F => '',

        // Arabic Extended-A combining (U+08D0-U+08FF) — skip
        0x08D0 => '', 0x08D1 => '', 0x08D2 => '',
    ];

    public static function transliterate(string $text, int $maxLen = 60): string
    {
        $text = trim($text);
        if ($text === '') return 'category';

        // Remove zero-width chars
        $text = preg_replace('/[\x{200C}\x{200D}\x{200E}\x{200F}\x{FEFF}]/u', '', $text);
        $text = str_replace(chr(0xC2) . chr(0xA0), ' ', $text);

        $result = '';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($chars as $char) {
            $ord = mb_ord($char, 'UTF-8');
            if ($ord === false) continue;

            // Check our map
            if (isset(self::$map[$ord])) {
                $mapped = self::$map[$ord];
                if ($mapped !== '') {
                    $result .= $mapped;
                }
                continue;
            }

            // Latin alphanumeric — keep as-is
            if (($ord >= 0x41 && $ord <= 0x5A) || ($ord >= 0x61 && $ord <= 0x7A) || ($ord >= 0x30 && $ord <= 0x39)) {
                $result .= strtolower($char);
                continue;
            }

            // Space / separator → hyphen
            if (in_array($ord, [0x20, 0x5F, 0x2F, 0x2D], true)) {
                $result .= '-';
                continue;
            }
        }

        // Clean up
        $result = preg_replace('/-+/', '-', $result);
        $result = trim($result, '-');
        $result = strtolower($result);
        $result = preg_replace('/[^a-z0-9-]/', '', $result);
        $result = trim($result, '-');
        $result = mb_substr($result, 0, $maxLen);

        return $result ?: 'category';
    }

    public static function generate(string $name, string $parentSlug = ''): string
    {
        $slug = self::transliterate($name);
        if ($parentSlug !== '') {
            $slug = $parentSlug . '-' . $slug;
        }
        return $slug;
    }
}
