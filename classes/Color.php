<?php namespace DE\RUB\SEG\NEDCaptcha2ExternalModule;

use JsonSerializable;

class Color implements JsonSerializable
{
    public $R;
    public $G;
    public $B;
    public $A = 1;

    public function __construct($r = 255, $g = 255, $b = 255, $a = 1.0)
    {
        $this->R = $r;
        $this->G = $g;
        $this->B = $b;
        $this->A = $a;
    }

    /**
     * Parses a color value in the formats r,g,b[,a] or #rrggbb[aa] into a Color.
     * 
     * @param  string $input    The input to parse.
     * @return Color      The corresponding color, or the default color (fallback = red)
     */
    public static function Parse($input, $default = null) {
        return self::tryParse($input) ?? self::tryParse($default) ?? new Color(255, 0, 0);
    }

    public static function tryParse($input) {
        if (!is_string($input)) return null;
        $input = trim($input);
        if (preg_match('/^#?([a-f0-9]{2})([a-f0-9]{2})([a-f0-9]{2})([a-f0-9]{2})?$/iD', $input, $m)) {
            return new Color(hexdec($m[1]), hexdec($m[2]), hexdec($m[3]), hexdec($m[4] ?? 'ff') / 255);
        }
        if (preg_match('/^(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*(1|0(?:\.\d+)?))?$/D', $input, $m)
            && max((int)$m[1], (int)$m[2], (int)$m[3]) <= 255) {
            return new Color((int)$m[1], (int)$m[2], (int)$m[3], (float)($m[4] ?? 1));
        }
        return null;
    }

	public function getHex() {
		if ($this->A < 1) {
			return sprintf('#%02X%02X%02X%02X', $this->R, $this->G, $this->B, (int)round($this->A * 255));
		}
		return sprintf('#%02X%02X%02X', $this->R, $this->G, $this->B);
	}

	public function getRgb() {
		if ($this->A < 1) {
			return sprintf('rgba(%d, %d, %d, %f)', $this->R, $this->G, $this->B, $this->A);
		}
		return sprintf('rgb(%d, %d, %d)', $this->R, $this->G, $this->B);
	}

	public function __toString() {
		return $this->getHex();
	}

	public function jsonSerialize():string {
		return (string)$this;
	}

}
