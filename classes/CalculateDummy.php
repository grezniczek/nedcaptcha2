<?php namespace DE\RUB\SEG\NEDCaptcha2ExternalModule;

class CalculateDummy
{
	public function feedEquation($name, $string, $field_attr)
	{
	}

	public function feedBranchingEquation($name, $string)
	{
	}

	public function exportJS()
	{
		$calculations = [
			"displayErrors" => false,
			"initialExecution" => false,
			"triggerFields" => [],
			"errorTracker" => [],
			"jsCode" => [],
			"resultChecks" => [],
			"errorLastReported" => [],
			"errorLastReport" => [],
			"funcs" => [],
		];

		return "<!-- Calculations suppressed by nedCAPTCHA 2 -->\n"
			. "<script type=\"text/javascript\">\n"
			. "var Calculations = " . json_encode($calculations) . ";\n"
			. "</script>\n";
	}

	public function exportBranchingJS()
	{
		$branching_logic = [
			"displayErrors" => false,
			"initialExecution" => false,
			"overrideEraseValuePrompt" => false,
			"runAllAgain" => false,
			"triggerFields" => [],
			"errorTracker" => [],
			"jsCode" => [],
			"funcs" => [],
			"fieldsToErase" => [],
		];

		return "<!-- Branching logic suppressed by nedCAPTCHA 2 -->\n"
			. "<script type=\"text/javascript\">\n"
			. "var BranchingLogic = " . json_encode($branching_logic) . ";\n"
			. "</script>\n";
	}
}
