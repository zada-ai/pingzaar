<?php
/**
 * Part of the Lex Template Parser.
 *
 * @author     Dan Horrigan
 * @license    MIT License
 * @copyright  2011 - 2012 Dan Horrigan
 */

namespace Lex;

use IteratorAggregate;

class Parser
{
    protected bool $allowPhp = false;
    protected bool $regexSetup = false;
    protected string $scopeGlue = '.';
    protected string $tagRegex = '';
    protected bool $cumulativeNoparse = false;

    protected bool $inCondition = false;

    protected string $variableRegex = '';
    protected string $variableLoopRegex = '';
    protected string $variableTagRegex = '';

    protected string $callbackNameRegex = '';
    protected string $callbackBlockRegex = '';

    protected string $recursiveRegex = '';

    protected string $noparseRegex = '';

    protected string $conditionalRegex = '';
    protected string $conditionalElseRegex = '';
    protected string $conditionalEndRegex = '';
    protected string $conditionalNotRegex = '';
    protected string $conditionalExistsRegex = '';
    protected array $conditionalData = [];

    protected static array $extractions = [
        'noparse' => [],
    ];

    protected static array|object $data;
    protected static array $callbackData = [];

    /**
     * The main Lex parser method.  Essentially acts as dispatcher to
     * all of the helper parser methods.
     *
     * @param string         $text     Text to parse
     * @param array|object   $data     Array or object to use
     * @param callable|false $callback Callback to use for Callback Tags
     * @param bool           $allowPhp
     *
     * @return string
     */
    public function parse(string $text, array|object $data = [], callable|false $callback = false, bool $allowPhp = false) : string
    {
        $this->setupRegex();
        $this->allowPhp = $allowPhp;

        // Only convert objects to arrays
        if (is_object($data)) {
            $data = $this->toArray($data);
        }

        // Is this the first time parse() is called?
        if (!isset(self::$data)) {
            // Let's store the local data array for later use.
            self::$data = $data;
        } else {
            // Let's merge the current data array with the local scope variables
            // So you can call local variables from within blocks.
            $data = array_merge((array) self::$data, $data);

            // Since this is not the first time parse() is called, it's most definately a callback,
            // let's store the current callback data with the the local data
            // so we can use it straight after a callback is called.
            self::$callbackData = $data;
        }

        // The parseConditionals method executes any PHP in the text, so clean it up.
        if (!$allowPhp) {
            $text = str_replace(['<?', '?>'], ['&lt;?', '?&gt;'], $text);
        }

        $text = $this->parseComments($text);
        $text = $this->extractNoparse($text);
        $text = $this->extractLoopedTags($text, $data, $callback);

        // Order is important here.  We parse conditionals first as to avoid
        // unnecessary code from being parsed and executed.
        $text = $this->parseConditionals($text, $data, $callback);
        $text = $this->injectExtractions($text, 'looped_tags');
        $text = $this->parseVariables($text, $data, $callback);
        $text = $this->injectExtractions($text, 'callback_blocks');

        if ($callback) {
            $text = $this->parseCallbackTags($text, $data, $callback);
        }

        // To ensure that {{ noparse }} is never parsed even during consecutive parse calls
        // set $cumulativeNoparse to true and use self::injectNoparse($text); immediately
        // before the final output is sent to the browser
        if (!$this->cumulativeNoparse) {
            $text = $this->injectExtractions($text);
        }

        return $text;
    }

    /**
     * Removes all of the comments from the text.
     *
     * @param string $text Text to remove comments from
     *
     * @return string
     */
    public function parseComments(string $text) : string
    {
        $this->setupRegex();

        return preg_replace('/\{\{#.*?#\}\}/s', '', $text);
    }

    /**
     * Recursivly parses all of the variables in the given text and
     * returns the parsed text.
     *
     * @param string         $text     Text to parse
     * @param array|object   $data     Array or object to use
     * @param callable|false $callback
     *
     * @return string
     */
    public function parseVariables(string $text, $data, callable|false $callback = false) : string
    {
        $this->setupRegex();

        /*
         * $data_matches[][0][0] is the raw data loop tag
         * $data_matches[][0][1] is the offset of raw data loop tag
         * $data_matches[][1][0] is the data variable (dot notated)
         * $data_matches[][1][1] is the offset of data variable
         * $data_matches[][2][0] is the content to be looped over
         * $data_matches[][2][1] is the offset of content to be looped over
         */
        if (preg_match_all($this->variableLoopRegex, $text, $dataMatches, PREG_SET_ORDER + PREG_OFFSET_CAPTURE)) {
            foreach ($dataMatches as $index => $match) {
                $loopData = $this->getVariable($match[1][0], $data);

                if ($loopData) {
                    $loopedText = '';
                    if (is_array($loopData) || ($loopData instanceof IteratorAggregate)) {
                        foreach ($loopData as $itemData) {
                            $str = $this->extractLoopedTags($match[2][0], $itemData, $callback);
                            $str = $this->parseConditionals($str, $itemData, $callback);
                            $str = $this->injectExtractions($str, 'looped_tags');
                            $str = $this->parseVariables($str, $itemData, $callback);
                            if ($callback !== null) {
                                $str = $this->parseCallbackTags($str, $itemData, $callback);
                            }

                            $loopedText .= $str;
                        }
                    }
                    $text = preg_replace('/' . preg_quote($match[0][0], '/') . '/m', addcslashes($loopedText, '\\$'), $text, 1);
                } else { // It's a callback block.
                    // Let's extract it so it doesn't conflict
                    // with the local scope variables in the next step.
                    $text = $this->createExtraction('callback_blocks', $match[0][0], $match[0][0], $text);
                }
            }
        }

        /**
         * $dataMatches[0] is the raw data tag
         * $dataMatches[1] is the data variable (dot notated)
         */
        if (preg_match_all($this->variableTagRegex, $text, $dataMatches)) {
            foreach ($dataMatches[1] as $index => $var) {
                $val = $this->getVariable($var, $data, '__lex_no_value__');

                if ($val !== '__lex_no_value__') {
                    $text = str_replace($dataMatches[0][$index], $val ?? '', $text);
                }
            }
        }

        return $text;
    }

    /**
     * Parses all Callback tags, and sends them through the given $callback.
     *
     * @param string         $text     Text to parse
     * @param mixed          $data
     * @param callable|false $callback Callback to apply to each tag
     *
     * @return string
     */
    public function parseCallbackTags(string $text, $data, callable|false $callback) : string
    {
        $this->setupRegex();
        $inCondition = $this->inCondition;

        if ($inCondition) {
            $regex = '/\{\s*(' . $this->variableRegex . ')(\s+.*?)?\s*\}/ms';
        } else {
            $regex = '/\{\{\s*(' . $this->variableRegex . ')(\s+.*?)?\s*(\/)?\}\}/ms';
        }

        /**
         * $match[0][0] is the raw tag
         * $match[0][1] is the offset of raw tag
         * $match[1][0] is the callback name
         * $match[1][1] is the offset of callback name
         * $match[2][0] is the parameters
         * $match[2][1] is the offset of parameters
         * $match[3][0] is the self closure
         * $match[3][1] is the offset of closure
         */
        while (preg_match($regex, $text, $match, PREG_OFFSET_CAPTURE)) {
            $selfClosed = false;
            $parameters = [];
            $tag = $match[0][0];
            $start = $match[0][1];
            $name = $match[1][0];
            if (isset($match[2])) {
                $cbData = $data;
                if (!empty(self::$callbackData)) {
                    $data = $this->toArray($data);
                    $cbData = array_merge(self::$callbackData, $data);
                }
                $rawParams = $this->injectExtractions($match[2][0], '__cond_str');
                $parameters = $this->parseParameters($rawParams, $cbData, $callback);
            }

            if (isset($match[3])) {
                $selfClosed = true;
            }

            $content = '';

            $tempText = substr($text, $start + strlen($tag));

            if (preg_match('/\{\{\s*\/' . preg_quote($name, '/') . '\s*\}\}/m', $tempText, $match, PREG_OFFSET_CAPTURE) && !$selfClosed) {
                $content = substr($tempText, 0, $match[0][1]);
                $tag .= $content . $match[0][0];

                // Is there a nested block under this one existing with the same name?
                $nestedRegex = '/\{\{\s*(' . preg_quote($name, '/') . ')(\s.*?)\}\}(.*?)\{\{\s*\/\1\s*\}\}/ms';
                if (preg_match($nestedRegex, $content . $match[0][0], $nestedMatches)) {
                    $nestedContent = preg_replace('/\{\{\s*\/' . preg_quote($name, '/') . '\s*\}\}/m', '', $nestedMatches[0]);
                    $content = $this->createExtraction('nested_looped_tags', $nestedContent, $nestedContent, $content);
                }
            }

            $replacement = call_user_func_array($callback, [$name, $parameters, $content]);
            $replacement = $this->parseRecursives($replacement, $content, $callback);

            if ($inCondition) {
                $replacement = $this->valueToLiteral($replacement);
            }

            $text = preg_replace('/' . preg_quote($tag, '/') . '/m', addcslashes($replacement, '\\$'), $text, 1);
            $text = $this->injectExtractions($text, 'nested_looped_tags');
        }

        return $text;
    }

    /**
     * Parses all conditionals, then executes the conditionals.
     *
     * @param string         $text     Text to parse
     * @param mixed          $data     Data to use when executing conditionals
     * @param callable|false $callback The callback to be used for tags
     *
     * @return string
     */
    public function parseConditionals(string $text, $data, callable|false $callback) : string
    {
        $this->setupRegex();
        preg_match_all($this->conditionalRegex, $text, $matches, PREG_SET_ORDER);

        $this->conditionalData = $data;

        /**
         * $matches[][0] = Full Match
         * $matches[][1] = Either 'if', 'unless', 'elseif', 'elseunless'
         * $matches[][2] = Condition
         */
        foreach ($matches as $match) {
            $this->inCondition = true;

            $condition = $match[2];

            // Extract all literal string in the conditional to make it easier
            if (preg_match_all('/(["\']).*?(?<!\\\\)\1/', $condition, $strMatches)) {
                foreach ($strMatches[0] as $m) {
                    $condition = $this->createExtraction('__cond_str', $m, $m, $condition);
                }
            }
            $condition = preg_replace($this->conditionalNotRegex, '$1!$2', $condition);

            if (preg_match_all($this->conditionalExistsRegex, $condition, $existsMatches, PREG_SET_ORDER)) {
                foreach ($existsMatches as $m) {
                    $exists = 'true';
                    if ($this->getVariable($m[2], $data, '__doesnt_exist__') === '__doesnt_exist__') {
                        $exists = 'false';
                    }
                    $condition = $this->createExtraction('__cond_exists', $m[0], $m[1] . $exists . $m[3], $condition);
                }
            }

            $condition = preg_replace_callback('/\b(' . $this->variableRegex . ')\b/', [$this, 'processConditionVar'], $condition);

            if ($callback) {
                $condition = preg_replace('/\b(?!\{\s*)(' . $this->callbackNameRegex . ')(?!\s+.*?\s*\})\b/', '{$1}', $condition);
                $condition = $this->parseCallbackTags($condition, $data, $callback);
            }

            // Re-extract the strings that have now been possibly added.
            if (preg_match_all('/(["\']).*?(?<!\\\\)\1/', $condition, $strMatches)) {
                foreach ($strMatches[0] as $m) {
                    $condition = $this->createExtraction('__cond_str', $m, $m, $condition);
                }
            }

            // Re-process for variables, we trick processConditionVar so that it will return null
            $this->inCondition = false;
            $condition = preg_replace_callback('/\b(' . $this->variableRegex . ')\b/', [$this, 'processConditionVar'], $condition);
            $this->inCondition = true;

            // Re-inject any strings we extracted
            $condition = $this->injectExtractions($condition, '__cond_str');
            $condition = $this->injectExtractions($condition, '__cond_exists');

            $conditional = '<?php ';

            if ($match[1] === 'unless') {
                $conditional .= 'if ( ! (' . $condition . '))';
            } elseif ($match[1] === 'elseunless') {
                $conditional .= 'elseif ( ! (' . $condition . '))';
            } else {
                $conditional .= $match[1] . ' (' . $condition . ')';
            }

            $conditional .= ': ?>';

            $text = preg_replace('/' . preg_quote($match[0], '/') . '/m', addcslashes($conditional, '\\$'), $text, 1);
        }

        $text = preg_replace($this->conditionalElseRegex, '<?php else: ?>', $text);
        $text = preg_replace($this->conditionalEndRegex, '<?php endif; ?>', $text);

        $text = $this->parsePhp($text);
        $this->inCondition = false;

        return $text;
    }

    /**
     * Goes recursively through a callback tag with a passed child array.
     *
     * @param string   $text     - The replaced text after a callback
     * @param string   $origText - The original text, before a callback is called
     * @param callable $callback
     *
     * @return string $text
     */
    public function parseRecursives(string $text, string $origText, callable $callback) : string
    {
        // Is there a {{ *recursive [array_key]* }} tag here, let's loop through it.
        if (preg_match($this->recursiveRegex, $text, $match)) {
            $arrayKey = $match[1];
            $tag = $match[0];
            $nextTag = null;
            $children = self::$callbackData[$arrayKey];
            $childCount = count($children);
            $count = 1;

            // Is the array not multi-dimensional? Let's make it multi-dimensional.
            if ($childCount === count($children, COUNT_RECURSIVE)) {
                $children = [$children];
                $childCount = 1;
            }

            foreach ($children as $child) {
                $hasChildren = true;

                // If this is a object let's convert it to an array.
                $child = $this->toArray($child);

                // Does this child not contain any children?
                // Let's set it as empty then to avoid any errors.
                if (!array_key_exists($arrayKey, $child)) {
                    $child[$arrayKey] = [];
                    $hasChildren = false;
                }

                $replacement = $this->parse($origText, $child, $callback, $this->allowPhp);

                // If this is the first loop we'll use $tag as reference, if not
                // we'll use the previous tag ($next_tag)
                $currentTag = ($nextTag !== null) ? $nextTag : $tag;

                // If this is the last loop set the next tag to be empty
                // otherwise hash it.
                $nextTag = ($count === $childCount) ? '' : md5($tag . $replacement);

                $text = str_replace($currentTag, $replacement . $nextTag, $text);

                if ($hasChildren) {
                    $text = $this->parseRecursives($text, $origText, $callback);
                }
                ++$count;
            }
        }

        return $text;
    }

    /**
     * Gets or sets the Scope Glue
     *
     * @param null|string $glue The Scope Glue
     *
     * @return string
     */
    public function scopeGlue(?string $glue = null) : string
    {
        if ($glue !== null) {
            $this->regexSetup = false;
            $this->scopeGlue = $glue;
        }

        return $this->scopeGlue;
    }

    /**
     * Sets the noparse style. Immediate or cumulative.
     *
     * @param bool $mode
     *
     * @return void
     */
    public function cumulativeNoparse(bool $mode) : void
    {
        $this->cumulativeNoparse = $mode;
    }

    /**
     * Injects noparse extractions.
     *
     * This is so that multiple parses can store noparse
     * extractions and all noparse can then be injected right
     * before data is displayed.
     *
     * @param string $text Text to inject into
     *
     * @return string
     */
    public static function injectNoparse(string $text) : string
    {
        if (isset(self::$extractions['noparse'])) {
            foreach (self::$extractions['noparse'] as $hash => $replacement) {
                if (strpos($text, "noparse_{$hash}") !== false) {
                    $text = str_replace("noparse_{$hash}", $replacement, $text);
                }
            }
        }

        return $text;
    }

    /**
     * Convert objects to arrays
     *
     * @param mixed $data
     *
     * @return array
     */
    public function toArray($data = []) : array
    {
        if ($data instanceof ArrayableInterface) {
            $data = $data->toArray();
        }

        // Objects to arrays
        is_array($data) || $data = (array) $data;

        // lower case array keys
        if (is_array($data)) {
            $data = array_change_key_case($data, CASE_LOWER);
        }

        return $data;
    }

    /**
     * This is used as a callback for the conditional parser.  It takes a variable
     * and returns the value of it, properly formatted.
     *
     * @param array $match A match from preg_replace_callback
     *
     * @return string
     */
    protected function processConditionVar(array $match) : string
    {
        $var = is_array($match) ? $match[0] : $match;

        if (in_array(strtolower($var), ['true', 'false', 'null', 'or', 'and'], true)
            || strpos($var, '__cond_str') === 0
            || strpos($var, '__cond_exists') === 0
            || is_numeric($var)) {
            return $var;
        }

        $value = $this->getVariable($var, $this->conditionalData, '__processConditionVar__');

        if ($value === '__processConditionVar__') {
            return $this->inCondition ? $var : 'null';
        }

        return $this->valueToLiteral($value);
    }

    /**
     * This is used as a callback for the conditional parser.  It takes a variable
     * and returns the value of it, properly formatted.
     *
     * @param array $match A match from preg_replace_callback
     *
     * @return string
     */
    protected function processParamVar(array $match) : string
    {
        return $match[1] . $this->processConditionVar($match[2]);
    }

    /**
     * Takes a value and returns the literal value for it for use in a tag.
     *
     * @param mixed $value Value to convert
     *
     * @return string
     */
    protected function valueToLiteral($value) : string
    {
        if (is_object($value) && is_callable([$value, '__toString'])) {
            return var_export((string) $value, true);
        }
        if (is_array($value)) {
            return !empty($value) ? 'true' : 'false';
        } else {
            return var_export($value, true);
        }
    }

    /**
     * Sets up all the global regex to use the correct Scope Glue.
     *
     * @return void
     */
    protected function setupRegex() : void
    {
        if ($this->regexSetup) {
            return;
        }
        $glue = preg_quote($this->scopeGlue, '/');

        $this->variableRegex = $glue === '\\.' ? '[a-zA-Z0-9_' . $glue . ']+' : '[a-zA-Z0-9_\.' . $glue . ']+';
        $this->callbackNameRegex = $this->variableRegex . $glue . $this->variableRegex;
        $this->variableLoopRegex = '/\{\{\s*(' . $this->variableRegex . ')\s*\}\}(.*?)\{\{\s*\/\1\s*\}\}/ms';
        $this->variableTagRegex = '/\{\{\s*(' . $this->variableRegex . ')\s*\}\}/m';

        $this->callbackBlockRegex = '/\{\{\s*(' . $this->variableRegex . ')(\s.*?)\}\}(.*?)\{\{\s*\/\1\s*\}\}/ms';

        $this->recursiveRegex = '/\{\{\s*\*recursive\s*(' . $this->variableRegex . ')\*\s*\}\}/ms';

        $this->noparseRegex = '/\{\{\s*noparse\s*\}\}(.*?)\{\{\s*\/noparse\s*\}\}/ms';

        $this->conditionalRegex = '/\{\{\s*(if|unless|elseif|elseunless)\s*((?:\()?(.*?)(?:\))?)\s*\}\}/ms';
        $this->conditionalElseRegex = '/\{\{\s*else\s*\}\}/ms';
        $this->conditionalEndRegex = '/\{\{\s*endif\s*\}\}/ms';
        $this->conditionalExistsRegex = '/(\s+|^)exists\s+(' . $this->variableRegex . ')(\s+|$)/ms';
        $this->conditionalNotRegex = '/(\s+|^)not(\s+|$)/ms';

        $this->regexSetup = true;

        // This is important, it's pretty unclear by the documentation
        // what the default value is on <= 5.3.6
        ini_set('pcre.backtrack_limit', 1000000);
    }

    /**
     * Extracts the noparse text so that it is not parsed.
     *
     * @param string $text The text to extract from
     *
     * @return string
     */
    protected function extractNoparse(string $text) : string
    {
        /**
         * $matches[][0] is the raw noparse match
         * $matches[][1] is the noparse contents
         */
        if (preg_match_all($this->noparseRegex, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $text = $this->createExtraction('noparse', $match[0], $match[1], $text);
            }
        }

        return $text;
    }

    /**
     * Extracts the looped tags so that we can parse conditionals then re-inject.
     *
     * @param string         $text     The text to extract from
     * @param mixed          $data
     * @param callable|false $callback
     *
     * @return string
     */
    protected function extractLoopedTags(string $text, $data = [], callable|false $callback = false) : string
    {
        /**
         * $matches[][0] is the raw match
         */
        if (preg_match_all($this->callbackBlockRegex, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                // Does this callback block contain parameters?
                if ($this->parseParameters($match[2], $data, $callback)) {
                    // Let's extract it so it doesn't conflict with local variables when
                    // parseVariables() is called.
                    $text = $this->createExtraction('callback_blocks', $match[0], $match[0], $text);
                } else {
                    $text = $this->createExtraction('looped_tags', $match[0], $match[0], $text);
                }
            }
        }

        return $text;
    }

    /**
     * Extracts text out of the given text and replaces it with a hash which
     * can be used to inject the extractions replacement later.
     *
     * @param string $type        Type of extraction
     * @param string $extraction  The text to extract
     * @param string $replacement Text that will replace the extraction when re-injected
     * @param string $text        Text to extract out of
     *
     * @return string
     */
    protected function createExtraction(string $type, string $extraction, string $replacement, string $text) : string
    {
        $hash = md5($replacement);
        self::$extractions[$type][$hash] = $replacement;

        return str_replace($extraction, "{$type}_{$hash}", $text);
    }

    /**
     * Injects all of the extractions.
     *
     * @param string     $text Text to inject into
     * @param null|mixed $type
     *
     * @return string
     */
    protected function injectExtractions(string $text, $type = null) : string
    {
        if ($type === null) {
            foreach (self::$extractions as $type => $extractions) {
                foreach ($extractions as $hash => $replacement) {
                    if (strpos($text, "{$type}_{$hash}") !== false) {
                        $text = str_replace("{$type}_{$hash}", $replacement, $text);
                        unset(self::$extractions[$type][$hash]);
                    }
                }
            }
        } else {
            if (!isset(self::$extractions[$type])) {
                return $text;
            }

            foreach (self::$extractions[$type] as $hash => $replacement) {
                if (strpos($text, "{$type}_{$hash}") !== false) {
                    $text = str_replace("{$type}_{$hash}", $replacement, $text);
                    unset(self::$extractions[$type][$hash]);
                }
            }
        }

        return $text;
    }

    /**
     * Takes a dot-notated key and finds the value for it in the given
     * array or object.
     *
     * @param string       $key     Dot-notated key to find
     * @param array|object $data    Array or object to search
     * @param mixed        $default Default value to use if not found
     *
     * @return mixed
     */
    protected function getVariable(string $key, $data, $default = null) : mixed
    {
        if (strpos($key, $this->scopeGlue) === false) {
            $parts = explode('.', $key);
        } else {
            $parts = explode($this->scopeGlue, $key);
        }
        foreach ($parts as $keyPart) {
            if (is_array($data)) {
                if (!array_key_exists($keyPart, $data)) {
                    return $default;
                }

                $data = $data[$keyPart];
            } elseif (is_object($data)) {
                if (!isset($data->{$keyPart})) {
                    return $default;
                }

                $data = $data->{$keyPart};
            } else {
                return $default;
            }
        }

        return $data;
    }

    /**
     * Evaluates the PHP in the given string.
     *
     * @param string $text Text to evaluate
     *
     * @return string
     */
    protected function parsePhp(string $text) : string
    {
        ob_start();
        $result = eval('?>' . $text . '<?php ');

        if ($result === false) {
            $output = 'You have a syntax error in your Lex tags. The offending code: ';
            throw new ParsingException($output . str_replace(['?>', '<?php '], '', $text));
        }

        $result = ob_get_clean();

        if ($result === false) {
            $output = 'You have a syntax error in your Lex tags (2). The offending code: ';
            throw new ParsingException($output . str_replace(['?>', '<?php '], '', $text));
        }

        return $result;
    }

    /**
     * Parses a parameter string into an array
     *
     * @param string         $parameters
     * @param array          $data
     * @param callable|false $callback
     *
     * @return array
     */
    protected function parseParameters(string $parameters, array $data, callable|false $callback) : array
    {
        $this->conditionalData = $data;
        $this->inCondition = true;
        // Extract all literal string in the conditional to make it easier
        if (preg_match_all('/(["\']).*?(?<!\\\\)\1/', $parameters, $strMatches)) {
            foreach ($strMatches[0] as $m) {
                $parameters = $this->createExtraction('__param_str', $m, $m, $parameters);
            }
        }

        $parameters = preg_replace_callback(
            '/(.*?\s*=\s*(?!__))(' . $this->variableRegex . ')/is',
            [$this, 'processParamVar'],
            $parameters
        );

        if ($callback) {
            $parameters = preg_replace('/(.*?\s*=\s*(?!\{\s*)(?!__))(' . $this->callbackNameRegex . ')(?!\s*\})\b/', '$1{$2}', $parameters);
            $parameters = $this->parseCallbackTags($parameters, $data, $callback);
        }

        // Re-inject any strings we extracted
        $parameters = $this->injectExtractions($parameters, '__param_str');
        $this->inCondition = false;

        if (preg_match_all('/(.*?)\s*=\s*(\'|"|&#?\w+;)(.*?)(?<!\\\\)\2/s', trim($parameters), $matches)) {
            $return = [];
            foreach (array_keys($matches[1]) as $i) {
                $return[trim($matches[1][$i])] = stripslashes($matches[3][$i]);
            }

            return $return;
        }

        return [];
    }
}
