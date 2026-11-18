<?php namespace DE\RUB\SEG\NEDCaptcha2ExternalModule;

use \REDCap as REDCap;

/**
 * Class ActionTagHelper
 *
 * A class for helping to parse custom action tags
 */
class ActionTagHelper
{
    // Cache the search results to speed complex queries or queries with many hooks
    static $cache = array();

    /**
     * This is a utility function to return action tags, fields, and parameters for specific action-tag scripts
     *
     * @param null $tags        a single or array of action_tags that you are looking for
     * @param null $fields      a single or array of fields that you want to look in
     * @param null $instruments a single or array of instruments to look in
     * @param null $context     an array with context ("project_id", "record", "instrument", "event_id", "instance") 
     *                          needed to evaluate @IF action tags
     * @throws \Exception
     * @return array            [ "tag1": [
     *                              "field_name": [
     *                                  "params": any parameters next to tag (supports string, list, or json)
     */
    static function getActionTags($project_id ,$tags = NULL, $fields = NULL, $instruments = NULL, $context = NULL, $tryDraftMode = false) {

        // Check to see if this search has been cached
        $arg_key = md5(json_encode(func_get_args()));
        if (isset(self::$cache[$arg_key])) {
            // \Plugin::log($arg_key, "DEBUG", "Using Cache");
            return self::$cache[$arg_key];
        }

        // Preserve case: callers must use the exact action-tag name.
        if (!empty($tags)) {
            if (!is_array($tags)) $tags = array($tags);
        }
        $Proj = new \Project($project_id);
        $metadata = ($tryDraftMode && $Proj->isDraftMode()) ? $Proj->metadata_temp : $Proj->metadata;
        $forms = ($tryDraftMode && $Proj->isDraftMode()) ? $Proj->forms_temp : $Proj->forms;

        if ($instruments !== null && !is_array($instruments)) $instruments = array($instruments);
        if ($fields !== null && !is_array($fields)) $fields = array($fields);
        if ($fields === null) {
            if (is_array($instruments)) {
                $fields = [];
                foreach ($instruments as $instrument) {
                    $fields = array_merge($fields, array_keys($forms[$instrument]['fields']));
                }
            }
        }
        // Build action_tag array
        $action_tags = array();
        foreach ($fields as $field_name) {
            $field = $metadata[$field_name];
            $field_annotation = $field['misc'];
            if (is_array($context) && strpos($field_annotation, "@IF") !== false) {
                $field_annotation = \Form::replaceIfActionTag($field_annotation, $context['project_id'] ?? null, $context['record'] ?? null, $context['event_id'] ?? null, $context['instrument'] ?? null, $context['instance'] ?? 1);
            }
            $parsed_tags = self::parseActionTags($field_annotation) ?: [];
            // Plugin::log($tags, "DEBUG", "TAGS for $field_name");
            foreach ($parsed_tags as $tag) {
                $action_tag = $tag['actiontag'];

                // If we are filtering, skip non-specified tags
                if ($tags AND !in_array($action_tag, $tags, true)) continue;

                // Initialize the action_tag node
                if (!isset($action_tags[$action_tag])) $action_tags[$action_tag] = array();

                // Merge action_tag into action_tags
                $action_tags[$action_tag][$field_name][] = 
                    $tag['params'];
            }
        }

        // Cache this search
        self::$cache[$arg_key] = $action_tags;

        return $action_tags;
    }


    /**
     * Parses a string for arrays of actiontags (optionally filtering by the supplied tag)
     * Examples of valid action tags are:
     * @TAG1
     * @TAG2=1,2,3
     * @TAG3={
     *   "key":"value",
     *   "key2","value2"
     * }
     *
     * The results are an array where each match is an actiontag with a key of
     * [0] => [
     *   "actiontag" => "@TAG1"
     * ],
     * [1] => [
     *   "actiontag" => "@TAG2",
     *   "params"    => "1,2,3"
     * ],
     * [2] => [
     *   "actiontag" => "@TAG3",
     *   "params"    => "{"key":"value","key2","value2"}
     *
     * https://regex101.com/r/fL2rM8/5
     * 
     * An updated version of the regex is here - but is not used. It doesn't work as intended in all cases.
     * https://regex101.com/r/dWEkmN/2
     *
     * @param $string           The string to be parsed for actiontags (in the format of <code>@FOO=BAR or @FOO={"param":"bar"}</code>
     * @param null $tag_only    If you wish to select a single tag
     * @return array|bool       Parsed tags with actiontag, params, matched text, and byte offset; false if none are found.
     */
    static function parseActionTags($string, $tag_only = null) {
        $re = "/(?(DEFINE)
          (?<number>    -?  (?= [1-9]|0(?!\d) ) \d+ (\.\d+)? ([eE] [+-]? \d+)? )
          (?<boolean>   true | false | null )
          (?<string>    \" ([^\"\\\\]++ | \\\\ [\"\\\\bfnrt\/] | \\\\ u [0-9a-fA-F]{4} )* \" )
          (?<array>     \[  (?:  (?&json)  (?: , (?&json)  )*  )?  \s* \] )
          (?<pair>      \s* (?&string) \s* : (?&json)  )
          (?<object>    \{  (?:  (?&pair)  (?: , (?&pair)  )*  )?  \s* \} )
          (?<fieldname> [a-zA-Z0-9\_\-]+ )
          (?<json>      \s* (?: (?&number) | (?&boolean) | (?&string) | (?&array) | (?&object) )  )
          (?<fieldlist> (?: (?&fieldname) (?: , (?&fieldname) )+ )+ )  
        )
        (?'actiontag'
          \@(?&fieldname)
        )
        (?:\=
          (?'params'
            (?:
              (?'match_list'(?&fieldlist))
              |
              (?'match_json'(?&json))
              |
              (?'match_string'(?:[[:alnum:]\_\-]+))
            )
          )
        )?/xm";

        preg_match_all($re, $string, $matches, PREG_OFFSET_CAPTURE);

        // Return false if none are found
        if (count($matches['actiontag']) == 0) return false;

        $results = array();

        $consumed = 0;
        foreach ($matches['actiontag'] as $i => [$tag, $offset]) {
            if ($offset < $consumed) continue;
            $match = $matches[0][$i][0];
            $params = $matches['params'][$i][0];
            $end = $offset + strlen($match);
            if ($tag === NEDCaptcha2ExternalModule::AT_SETUP && ($string[$end] ?? '') === '=') {
                // The JSON matcher stopped before an assignment. Recover only complete
                // JSON tokens ending before the next tag or the end of the annotation.
                // Free text or an unterminated quote makes the replacement span ambiguous.
                $tokens = '~\G=(?:\s++|[{}\[\],:]|"(?:[^"\\\\]++|\\\\.)*+"|true|false|null|[-+.0-9eE]++)*+(?=@[A-Za-z0-9_-]|\z)~s';
                if (preg_match($tokens, $string, $tail, 0, $end)) {
                    $suffix = rtrim($tail[0]);
                    $match .= $suffix;
                    $params = substr($match, strlen($tag) + 1);
                    if ($params === '') $params = '='; // Empty assignment is invalid, unlike a bare tag.
                } else {
                    $params = null; // Survey uses math; editor must not guess where to stop replacing.
                }
            }
            $consumed = $offset + strlen($match);
            if ($tag_only && $tag !== $tag_only) continue;
            $results[] = ['actiontag' => $tag, 'params' => $params, 'match' => $match, 'offset' => $offset];
        }
        return $results;
    }
}
