<?php

return [
    // Максимальный размер файла в мегабайтах
    'max_mb' => (int) env('DOC_MAX_MB', 20),

    // В тестах можно подменить экстрактор фейком
    'fake' => (bool) env('DOC_EXTRACTOR_FAKE', env('APP_ENV') === 'testing'),

    // PDF
    'pdftotext_bin' => env('PDFTOTEXT_BIN', null), // null = autodetect in PATH

    // OCR
    'ocr_enabled' => (bool) env('DOC_OCR_ENABLED', false),
    'tesseract_bin' => env('TESSERACT_BIN', null), // null = autodetect in PATH
    'ocr_langs' => env('DOC_OCR_LANGS', 'eng'),    // e.g. "eng+pol+ces"
];
