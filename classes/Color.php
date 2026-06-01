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
        if ($default === null) {
            $default = new Color(255, 0, 0);
        }
        else {
            $default = self::Parse($default);
        }
        if (strpos($input, ",")) {
            // RegEx for r,g,b[,a] format.
            $re = '/\s*(?\'r\'\d+)\s*,\s*(?\'g\'\d+)\s*,\s*(?\'b\'\d+)\s*(,\s*(?\'a\'(1|0\.?\d*)))?/m';
            preg_match_all($re, $input, $matches, PREG_SET_ORDER, 0);
            if (count($matches) == 1) {
                $r = $matches[0]["r"] * 1;
                $g = $matches[0]["g"] * 1;
                $b = $matches[0]["b"] * 1;
                $a = isset($matches[0]["a"]) ? $matches[0]["a"] * 1 : 1.0;
                $color = new Color($r, $g, $b, $a);
                return $color;
            }
        }
        else {
            // RegEx for #rrggbb[aa] format.
            $re = '/#?(?\'r\'[0-9a-fA-F]{2})(?\'g\'[0-9a-fA-F]{2})(?\'b\'[0-9a-fA-F]{2})(?\'a\'[0-9a-fA-F]{2})?/m';
            preg_match_all($re, $input, $matches, PREG_SET_ORDER, 0);
            if (count($matches) == 1) {
                $r = hexdec($matches[0]["r"]);
                $g = hexdec($matches[0]["g"]);
                $b = hexdec($matches[0]["b"]);
                $a = isset($matches[0]["a"]) ? hexdec($matches[0]["a"]) : 255;
                $a = $a / 255;
                $color = new Color($r, $g, $b, $a);
                return $color;
            }
        }        
        return $default;
    }

	public function getHex() {
		if ($this->A < 1) {
			return sprintf('#%02X%02X%02X%02X', $this->R, $this->G, $this->B, $this->A * 255);
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
