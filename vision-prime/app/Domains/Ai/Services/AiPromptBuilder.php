<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

/**
 * سازندهٔ پرامپت‌های تولید محتوا — از AiGateway تفکیک شد (سند STATUS).
 * فقط «متن» می‌سازد: بدون شبکه، کش یا fallback؛ مناسب تست واحد و
 * بازنویسی مستقل پرامپت‌ها بدون دست‌زدن به منطق failover.
 */
class AiPromptBuilder
{
    public function articlePrompts(array $context): array
    {
        $title = (string) ($context['title'] ?? '');
        $targetQuery = (string) ($context['target_query'] ?? '');
        $siteName = (string) ($context['site_name'] ?? '');
        $standard = (array) ($context['standard'] ?? []);
        $metrics = (array) ($context['metrics'] ?? []);
        $internalLinks = $context['internal_links'] ?? [];
        $guardrails = (array) ($context['guardrails'] ?? []);
        $customInstructions = (string) ($context['custom_instructions'] ?? '');
        $userWordCount = (int) ($context['word_count'] ?? 0);
        $userTone = (string) ($context['tone'] ?? '');

        // User word count overrides guardrails/standard
        if ($userWordCount > 0) {
            $wordMin = (int) max($userWordCount * 0.8, 200);
            $wordMax = (int) min($userWordCount * 1.2, 10000);
        } else {
            $wordMin = (int) ($guardrails['min_words'] ?? $standard['word_min'] ?? 400);
            $wordMax = (int) ($guardrails['max_words'] ?? $standard['word_max'] ?? 2000);
        }
        $minHeadings = max(2, (int) ($standard['min_headings'] ?? 2));
        $elements = (array) ($standard['required_elements'] ?? []);
        $tone = $userTone !== '' ? $userTone : (string) ($guardrails['allowed_tone'] ?? $standard['tone'] ?? 'informative');
        $schemaType = (string) ($standard['schema_type'] ?? 'Article');

        // Apply guardrail overrides
        $requireCta = (bool) ($guardrails['require_cta'] ?? true);
        $requireFaq = (bool) ($guardrails['require_faq'] ?? true);
        $requireLinks = (bool) ($guardrails['require_internal_links'] ?? true);
        $minLinks = (int) ($guardrails['min_internal_links'] ?? 2);
        $requireBrand = (bool) ($guardrails['require_brand_mention'] ?? true);
        $forbiddenWords = (array) ($guardrails['forbidden_words'] ?? []);
        $maxChars = (int) ($guardrails['max_characters'] ?? 8000);

        $metricsLine = sprintf(
            'کلیک‌ها: %d · نمایش‌ها: %d · نرخ کلیک: %s · میانگین جایگاه: %s',
            (int) ($metrics['clicks'] ?? 0),
            (int) ($metrics['impressions'] ?? 0),
            isset($metrics['ctr']) ? round((float) $metrics['ctr'] * 100, 1).'٪' : '—',
            isset($metrics['position']) ? round((float) $metrics['position'], 1) : '—',
        );

        // Related GSC queries for richer prompt context
        $relatedQueries = $metrics['related_queries'] ?? [];
        $relatedQueriesText = '';
        if (count($relatedQueries) > 0) {
            $queryLines = [];
            foreach (array_slice($relatedQueries, 0, 5) as $rq) {
                $queryLines[] = $rq['query'].' | clicks='.$rq['clicks'].' impressions='.$rq['impressions'].' position='.$rq['position'];
            }
            $relatedQueriesText = '

کوئری‌های مرتبط در GSC:
'.implode('
', $queryLines);
        }

        $linksText = '';
        if (is_array($internalLinks) && $internalLinks !== []) {
            $linkLines = [];
            foreach (array_slice($internalLinks, 0, 5) as $link) {
                $linkLines[] = "- لینک: {$link['url']} (anchor: {$link['anchor']})";
            }
            $linksText = '
لینک‌های داخلی پیشنهادی:
'.implode('
', $linkLines);
        }

        $elementLabels = [
            'h2_structure' => 'زیرعنوان‌های h2 (حداقل '.$minHeadings.' عدد)',
            'table_of_contents' => 'فهرست مطالب در ابتدای مقاله',
            'faq' => 'بخش سؤالات متداول (با تگ‌های strong برای پرسش/پاسخ)',
            'cta' => 'دعوت به اقدام در انتهای مقاله',
            'internal_links' => 'لینک‌های داخلی',
            'steps' => 'مراحل گام‌به‌گام (لیست مرتب <ol>)',
            'list' => 'لیست غیرمرتب <ul>',
            'table' => 'جدول مقایسه یا مشخصات',
            'pros_cons' => 'بخش مزایا و معایب',
            'rating' => 'امتیازدهی (مثلاً ۴ از ۵)',
            'specs' => 'مشخصات فنی جدولی',
            'social_proof' => 'نظرات یا رضایت مشتریان',
        ];
        $requiredText = '';
        foreach ($elements as $el) {
            if (isset($elementLabels[$el])) {
                $requiredText .= "
- {$elementLabels[$el]}";
            }
        }

        // Build guardrail rules for system prompt
        $guardrailRules = '';
        if ($requireCta) {
            $guardrailRules .= '
- حتماً در انتهای مقاله یک CTA (دعوت به اقدام) بنویس';
        }
        if ($requireFaq) {
            $guardrailRules .= '
- حتماً بخش سؤالات متداول (FAQ) با فرمت <strong>پرسش:</strong> و <strong>پاسخ:</strong> بنویس';
        }
        if ($requireLinks && $minLinks > 0) {
            $guardrailRules .= "
- حداقل {$minLinks} لینک داخلی در محتوا قرار بده";
        }
        if ($requireBrand && $siteName !== '') {
            $guardrailRules .= "
- نام برند {$siteName} را حداقل ۲ بار در محتوا ذکر کن";
        }
        if ($forbiddenWords !== []) {
            $forbiddenList = implode('، ', array_slice($forbiddenWords, 0, 20));
            $guardrailRules .= "
- هرگز از کلمات زیر استفاده نکن: {$forbiddenList}";
        }
        $guardrailRules .= "
- حداکثر طول محتوا: {$maxChars} کاراکتر";

        // Use custom system prompt if provided in guardrails, otherwise use default
        $customSystem = $guardrails['system_prompt'] ?? null;
        $system = $customSystem !== null && trim($customSystem) !== ''
            ? $customSystem.'

خروجی باید فقط HTML معتبر باشد.'
            : "تو یک تیم محتوایی حرفه‌ای هستی با ۳ تخصص: SEO Strategist + Content Editor + Conversion Writer.

=== هویت ===
- لحن: {$tone}
- مخاطب: کاربران ایرانی جستجوگر در Google
- زبان: فارسی روان، معیار، امروزی
- نوشته‌ها باید انسانی و طبیعی باشند (نه AI-ish)

=== خروجی ===
- فقط HTML معتبر خالص — بدون markdown، بدون علامت کد
- تگ‌های مجاز: h1/h2/h3/p/ul/ol/li/table/th/td/strong/em/a/blockquote
- h1 دقیقاً یکبار | فهرست مطالب ul/li/a با href=#id
- ساختار: h1 > h2 > h3 (هرگز h3 بدون h2)

=== قوانین سئو ===
- کلمه کلیدی: در h1 + اولین ۱۰۰ کلمه + حداقل ۲ h2 + conclusion
- LSI: حداقل ۵ کلمه معنایی طبیعی
- چگالی کیورد: ۱ تا ۲.۵٪
- مقدمه حداکثر ۱۰۰ کلمه، هر پاراگراف حداکثر ۳ جمله
- نوع اسکیما: {$schemaType}

=== Anti-AI Detection ===
- جملات متنوع (کوتاه+متوسط+بلند)
- مثال‌های واقعی و ملموس
- پرهیز از الگوهای تکراری فرمولی
- ممنوعیت: در دنیای امروز / همانطور که می‌دانید / لازم به ذکر است / بدون شک / قطعاً / امیدوارم مفید بوده باشد
- هر ۳۰۰ کلمه حداقل یک المان بصری (لیست، جدول، blockquote)

=== E-E-A-T ===
- تجربه: سناریوهای واقعی و ملموس بنویس
- تخصص: اصطلاحات دقیق + توضیح ساده
- اعتبار: لحن مطمئن ولی متواضع، ادعای بدون پشتوانه ممنوع
- اعتماد: نقاط ضعف را هم بگو، اغراق نکن
- آمار دقیق نداری؟ عدد نده. بگو بررسی‌ها نشان می‌دهد

=== Conversion ===
- CTA اصلی در انتهای مقاله (واضح و مرتبط با هدف)
- حداقل ۱ Micro-CTA در میانه مقاله (نرم و غیرمستقیم)

=== Featured Snippet ===
- حداقل ۱ پاراگراف ۴۰-۵۰ کلمه‌ای پاسخ مستقیم به سوال کلیدی
- حداقل ۱ لیست یا جدول قابل اسکن

=== قوانین محتوایی ===
- محتوای واقعی و عملی (نه پرکردن فضا)
- آمار ساختگی ممنوع
- جملات فعال (ما انجام می‌دهیم)
- bold حداقل ۵ بار برای نکات حیاتی
- لیست حداکثر ۷ آیتم | جدول حداکثر ۳ ستون
{$guardrailRules}"
        .($customInstructions !== '' ? "\n\n=== CUSTOM INSTRUCTIONS ===\n".$customInstructions : '')
        .($userWordCount > 0 ? "\n\n--- Target word count: ".$userWordCount.' words ---' : '');
        $user = '=== درخواست: تولید مقاله حرفه‌ای سئو شده ===

'
            .'عنوان مقاله: '.($title !== '' ? $title : $targetQuery).'
'
            .'کلمه کلیدی اصلی: '.$targetQuery.'
'
            .($siteName !== '' ? "نام برند/سایت: {$siteName}
" : '')
            ."نوع محتوا: {$schemaType}
"
            .'
=== داده‌های GSC (BoundingClientRect از Google Search Console) ===
'
            .$metricsLine.$relatedQueriesText.'
'
            .'
=== نکته مهم بر اساس داده‌ها ===
'
            .'- اگر position > 5 و CTR < 10%: عنوان و meta باید جذاب‌تر باشند
'
            .'- اگر impressions بالا ولی clicks پایین: محتوا باید دقیق‌تر به سوال کاربر پاسخ دهد
'
            .'- اگر کوئری‌های مرتبط زیاد است: مقاله باید جامع و گسترده باشد
'
            .'
=== ساختار اجباری مقاله ===
'
            .'- h1: عنوان اصلی (دقیقاً یکبار)
'
            .'- مقدمه جذاب (۲-۳ پاراگراف) با کلمه کلیدی در خط اول
'
            .'- فهرست مطالب (اختیاری ولی توصیه‌شده)
'
            .'- بدنه اصلی با '.$minHeadings.'+ بخش h2
'
            .'- هر h2 حداکثر ۳-۴ پاراگراف (خوانایی بالا)
'
            .'- جدول مقایسه یا مشخصات (حداقل ۱ جدول)
'
            .'- لیست‌های عددی یا غیرعددی برای نکات کلیدی
'
            .'- بخش FAQ (سؤالات متداول) با تگ strong برای پرسش
'
            .'- CTA (دعوت به اقدام) در انتهای مقاله
'
            .'- نتیجه‌گیری خلاصه و عملی
'
            .'
=== الزامات طول ===
'
            .'- حداقل: '.$wordMin.' کلمه
'
            .'- حداکثر: '.$wordMax.' کلمه
'
            .'- هر پاراگراف: ۲-۴ جمله کوتاه (۱۰-۲۰ کلمه)
'
            .$requiredText.$linksText.'

'
            .'=== استراتژی محتوا ===
'
            .'- محتوا باید عمیق‌تر و جامع‌تر از مقالات رقبا باشد (Skyscraper)
'
            .'- از مثال‌های عملی و واقعی استفاده کن
'
            .'- LSI keywords را طبیعی در متن پراکنده کن
'
            .'- خوانایی بالا: جملات کوتاه، پاراگراف‌های کوتاه، bullet points
'
            .'- Micro-CTA در میانه مقاله (نرم و غیرمستقیم)
'
            .'- حداقل یک پاراگراف Featured Snippet (پاسخ مستقیم ۴۰-۵۰ کلمه‌ای)
'
            .'- جدول مقایسه با حداکثر ۳ ستون (mobile-friendly)
'
            .'
=== پیشنهاد تصویر ===
برای هر H2 اصلی یک تگ img پیشنهاد بده با alt text SEO شده:
img src=UNSPLASH_KEYWORD alt=alt text با کلیدواژه loading=lazy

فقط خروجی HTML خالص بنویس (بدون markdown، بدون علامت‌های کد):';

        return [$system, $user];
    }

    public function metaPrompts(string $kind, array $context): array
    {
        $url = (string) ($context['url'] ?? '');
        $siteName = (string) ($context['site_name'] ?? '');
        $topQuery = (string) ($context['target_query'] ?? $context['top_query'] ?? '');
        $metrics = $context['metrics'] ?? [];
        $existing = (string) ($context['existing_meta'] ?? '');
        $snippet = mb_substr((string) ($context['content_snippet'] ?? ''), 0, 800);

        $metricsLine = sprintf(
            'کلیک‌ها: %d · نمایش‌ها: %d · نرخ کلیک: %s · میانگین جایگاه: %s',
            (int) ($metrics['clicks'] ?? 0),
            (int) ($metrics['impressions'] ?? 0),
            isset($metrics['ctr']) ? round((float) $metrics['ctr'] * 100, 1).'٪' : '—',
            isset($metrics['position']) ? round((float) $metrics['position'], 1) : '—',
        );

        $system = 'تو یک متخصص CTR optimization و کپی‌رایتر فارسی هستی. وظیفه تو نوشتن متا تگ‌هایی است که CTR (نرخ کلیک) را به حداکثر برسانند.

قوانین:
- فقط متن خواسته‌شده را برگردان (بدون توضیح، شماره، نقل‌قول)
- حتماً فارسی روان و طبیعی بنویس
- از اعداد، سال، و کلمات جذاب استفاده کن
- کلمه کلیدی را دقیقاً و طبیعی قرار بده';

        if ($kind === 'meta_title') {
            $user = 'برای صفحه زیر یک meta title عالی بنویس:

'
            .'آدرس: '.$url.'
'
            .'نام برند: '.$siteName.'
'
            .'کلمه کلیدی: '.$topQuery.'
'
            .'داده GSC: '.$metricsLine.'
'
            .'عنوان فعلی: '.($existing !== '' ? $existing : 'ندارد').'
'
            .'نمونه محتوا: '.($snippet !== '' ? $snippet : 'در دسترس نیست').'

'
            .'الزامات:
'
            .'- حداکثر ۶۰ کاراکتر
'
            .'- کلمه کلیدی دقیقاً در ابتدا
'
            .'- نام برند در انتها (با | جدا شده)
'
            .'- شامل عدد یا سال باشد (مثلاً ۲۰۲۶)
'
            .'- جذاب و کلیک‌خور باشد (نه خشک و رسمی)
'
            .'- فقط متن خروjتی، بدون عنوان یا توضیح';
        } else {
            $user = 'برای صفحه زیر یک meta description جذاب بنویس:

'
            .'آدرس: '.$url.'
'
            .'نام برند: '.$siteName.'
'
            .'کلمه کلیدی: '.$topQuery.'
'
            .'داده GSC: '.$metricsLine.'
'
            .'توضیح فعلی: '.($existing !== '' ? $existing : 'ندارد').'
'
            .'نمونه محتوا: '.($snippet !== '' ? $snippet : 'در دسترس نیست').'

'
            .'الزامات:
'
            .'- دقیقاً بین ۱۴۰ تا ۱۵۵ کاراکتر
'
            .'- شامل کلمه کلیدی به صورت طبیعی
'
            .'- شامل CTA (دعوت به اقدام): همین الان، رایگان، مشاوره، مقایسه
'
            .'- شامل مزیت اصلی یا وعده محتوا
'
            .'- جذاب و کنجکاوی‌برانگیز باشد
'
            .'- فقط متن خروjتی، بدون عنوان یا توضیح';
        }

        return [$system, $user];
    }
}
