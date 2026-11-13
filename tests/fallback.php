<?php
// Run with imagecreate disabled; also run with mt_rand disabled to test the final fallback.
require_once __DIR__ . '/../classes/Color.php';
require_once __DIR__ . '/../classes/CaptchaGenerator.php';
use DE\RUB\SEG\NEDCaptcha2ExternalModule\CaptchaGenerator;
use DE\RUB\SEG\NEDCaptcha2ExternalModule\Color;

$settings = [
    'type' => 'image', 'length' => 5, 'angleVariation' => 'medium', 'sizeVariation' => 'medium', 'noiseDensity' => 'medium',
    'bgColor' => Color::Parse('#ffffff'), 'textColor' => Color::Parse('#000000'), 'noiseColor' => Color::Parse('#333333'),
    'complexity' => 'simple', 'minValue' => 1, 'maxValue' => 10, 'debug' => false, 'showAsText' => false,
];
foreach (['image', 'math'] as $type) {
    $settings['type'] = $type;
    $captcha = new CaptchaGenerator($settings);
    if ($captcha->type !== 'math' || $captcha->error === null || !preg_match('/^(\d+) \+ (\d+)$/', $captcha->challenge, $terms)
        || (string)($terms[1] + $terms[2]) !== $captcha->expected) {
        throw new RuntimeException('Image failure must produce a solvable text math puzzle');
    }
}
echo "Rendering fallback regressions passed.\n";
