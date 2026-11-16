<?php
// Require working GD/FreeType and fail on any diagnostic, including deprecations.
require_once __DIR__ . '/../classes/Color.php';
require_once __DIR__ . '/../classes/CaptchaGenerator.php';
use DE\RUB\SEG\NEDCaptcha2ExternalModule\CaptchaGenerator;
use DE\RUB\SEG\NEDCaptcha2ExternalModule\Color;

if (!function_exists('imagecreate') || !function_exists('imagettftext') || !function_exists('imagepng')) {
    throw new RuntimeException('Rendering regressions require PHP GD with PNG and FreeType support.');
}
error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$settings = [
    'type' => 'math', 'length' => 5, 'angleVariation' => 'medium', 'sizeVariation' => 'medium', 'noiseDensity' => 'medium',
    'bgColor' => Color::Parse('#f3f3f3'), 'textColor' => Color::Parse('#800000'), 'noiseColor' => Color::Parse('#333333'),
    'complexity' => 'simple', 'minValue' => 1, 'maxValue' => 10, 'debug' => false, 'showAsText' => false,
];

function renderedImage($settings, $expected = null) {
    $captcha = new CaptchaGenerator($settings, $expected);
    $case = json_encode(array_intersect_key($settings, array_flip(['type', 'length', 'complexity', 'minValue', 'maxValue', 'angleVariation', 'sizeVariation', 'noiseDensity'])));
    if ($captcha->error !== null || $captcha->type !== $settings['type']) {
        throw new RuntimeException("Rendering fell back for $case: " . ($captcha->error ?? 'unexpected challenge type'));
    }
    if (!preg_match('/^<img src="data:image\/png;base64,([A-Za-z0-9+\/=]+)" alt="CAPTCHA" \/>$/', $captcha->challenge, $matches)) {
        throw new RuntimeException("Expected an inline PNG, not text math: $case");
    }
    $png = base64_decode($matches[1], true);
    $image = $png === false ? false : imagecreatefromstring($png);
    if ($image === false || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        throw new RuntimeException("Rendering produced invalid PNG data: $case");
    }
    if ($settings['type'] === 'image') {
        $length = $expected === null ? $settings['length'] : strlen($expected);
        if (imagesx($image) !== 50 * $length || imagesy($image) !== 80
            || strlen($captcha->expected) !== $length || strspn($captcha->expected, CaptchaGenerator::IMAGE_CHARS) !== $length
            || ($expected !== null && $captcha->expected !== $expected)) {
            throw new RuntimeException("Distorted image dimensions/answer changed: $case");
        }
    }
    elseif (imagesx($image) <= 0 || imagesy($image) !== 30 || !ctype_digit($captcha->expected)) {
        throw new RuntimeException("Math image dimensions/answer invalid: $case");
    }
    // In particular, noise-off cases must contain drawn text, not a blank image.
    $background = imagecolorat($image, 0, 0);
    $drawn = false;
    for ($y = 0; $y < imagesy($image) && !$drawn; $y++) {
        for ($x = 0; $x < imagesx($image); $x++) {
            if (imagecolorat($image, $x, $y) !== $background) {
                $drawn = true;
                break;
            }
        }
    }
    if (!$drawn) throw new RuntimeException("Rendered PNG is blank: $case");
    unset($image);
    return $captcha;
}

$count = 0;
foreach (['simple', 'complex'] as $complexity) {
    foreach ([[1, 1], [1, 10], [2, 12], [12, 99]] as [$min, $max]) {
        renderedImage(array_replace($settings, ['complexity' => $complexity, 'minValue' => $min, 'maxValue' => $max]));
        $count++;
    }
}
foreach (range(3, 8) as $length) {
    foreach (['none', 'slight', 'medium', 'strong'] as $angle) {
        foreach (['none', 'slight', 'medium', 'strong'] as $size) {
            foreach (['off', 'low', 'medium', 'high'] as $noise) {
                renderedImage(array_replace($settings, ['type' => 'image', 'length' => $length,
                    'angleVariation' => $angle, 'sizeVariation' => $size, 'noiseDensity' => $noise]));
                $count++;
            }
        }
    }
}
// Re-rendering a reused challenge must keep its answer and remain an image.
$imageSettings = array_replace($settings, ['type' => 'image']);
$first = renderedImage($imageSettings);
renderedImage($imageSettings, $first->expected);
$count += 2;

restore_error_handler();
echo "Passed $count GD/FreeType rendering cases with all PHP diagnostics treated as errors.\n";
