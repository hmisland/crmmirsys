<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

/**
 * HTML → PDF, the one place the app touches mPDF (vendor/, loaded
 * lazily so pages that never print don't pay for it). The font follows
 * the document language:
 *  - fa: Vazirmatn (the UI font; static TTFs in
 *    themes/default/assets/fonts/vazirmatn — mPDF can't read the
 *    variable woff2), for Persian and Latin text alike;
 *  - ar: XB Riyaz, a standard Arabic Naskh face;
 *  - en/tr/fr: DejaVu Sans, with XB Riyaz for any Arabic-script run.
 * XB Riyaz and DejaVu Sans are bundled in vendor/mpdf/mpdf/ttfonts.
 * mPDF picks per text run (autoScriptToLang/autoLangToFont), so mixed
 * scripts shape correctly in one document.
 */
final class PdfRenderer
{
    public const PERSIAN_FONT = 'vazirmatn';

    public function __construct(
        private readonly string $basePath,
        private readonly string $tempDir,
    ) {
    }

    /** @return string the PDF bytes */
    public function render(string $html, string $direction = 'ltr', string $title = '', string $locale = 'en'): string
    {
        require_once $this->basePath . '/vendor/autoload.php';

        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }

        $rtl = $direction === 'rtl';
        $persian = $locale === 'fa';

        $config = [
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 12,
            'tempDir' => $this->tempDir,
            'fontDir' => [
                $this->basePath . '/themes/default/assets/fonts/vazirmatn',
                $this->basePath . '/vendor/mpdf/mpdf/ttfonts',
            ],
            'fontdata' => [
                self::PERSIAN_FONT => ['R' => 'Vazirmatn-Regular.ttf', 'B' => 'Vazirmatn-Bold.ttf', 'useOTL' => 0xFF],
                'dejavusans' => ['R' => 'DejaVuSans.ttf', 'B' => 'DejaVuSans-Bold.ttf', 'useOTL' => 0xFF],
                'xbriyaz' => ['R' => 'XB Riyaz.ttf', 'B' => 'XB RiyazBd.ttf', 'useOTL' => 0xFF, 'useKashida' => 75],
            ],
            'default_font' => $persian ? self::PERSIAN_FONT : ($rtl ? 'xbriyaz' : 'dejavusans'),
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'directionality' => $rtl ? 'rtl' : 'ltr',
        ];

        if ($persian) {
            // Arabic-script runs would otherwise go to mPDF's XB Riyaz.
            $config['languageToFont'] = new class extends \Mpdf\Language\LanguageToFont {
                public function getLanguageOptions($llcc, $adobeCJK)
                {
                    [$coreSuitable, $font] = parent::getLanguageOptions($llcc, $adobeCJK);

                    return [$coreSuitable, $font === 'xbriyaz' ? PdfRenderer::PERSIAN_FONT : $font];
                }
            };
        }

        $mpdf = new \Mpdf\Mpdf($config);

        // Images may only come from local files the caller put in the
        // HTML (the organization logo); never fetch remote URLs.
        $mpdf->showImageErrors = false;
        $mpdf->SetTitle($title);
        $mpdf->SetCreator('CRM GROUP');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}
