<?php
class SpiderParser {
	
    private $offset         = 0;
    private $lines          = array();
    private $currentLineNb  = -1;
    private $currentLine    = '';
    private $refs           = array();

  
    public function __construct($offset = 0){
        $this->offset = $offset;
    }

    public function parse($value){
		
        $this->currentLineNb = -1;
        $this->currentLine = '';
        $this->lines = explode("\n", $this->cleanup($value));

        if (function_exists('mb_detect_encoding') && false === mb_detect_encoding($value, 'UTF-8', true)) {
            throw new ParseException('The YAML value does not appear to be valid UTF-8.');
        }

        if (function_exists('mb_internal_encoding') && ((int) ini_get('mbstring.func_overload')) & 2) {
            $mbEncoding = mb_internal_encoding();
            mb_internal_encoding('UTF-8');
        }

        $data = array();
        $context = null;
        while ($this->moveToNextLine()) {
            if ($this->isCurrentLineEmpty()) {
                continue;
            }

            if ("\t" === $this->currentLine[0]) {
                throw new ParseException('A YAML file cannot contain tabs as indentation.', $this->getRealCurrentLineNb() + 1, $this->currentLine);
            }

            $isRef = $isInPlace = $isProcessed = false;
            if (preg_match('#^\-((?P<leadspaces>\s+)(?P<value>.+?))?\s*$#u', $this->currentLine, $values)) {
                if ($context && 'mapping' == $context) {
                    throw new ParseException('You cannot define a sequence item when in a mapping');
                }
                $context = 'sequence';

                if (isset($values['value']) && preg_match('#^&(?P<ref>[^ ]+) *(?P<value>.*)#u', $values['value'], $matches)) {
                    $isRef = $matches['ref'];
                    $values['value'] = $matches['value'];
                }
                if (!isset($values['value']) || '' == trim($values['value'], ' ') || 0 === strpos(ltrim($values['value'], ' '), '#')) {
                    $c = $this->getRealCurrentLineNb() + 1;
                    $parser = new Parser($c);
                    $parser->refs =& $this->refs;
                    $data[] = $parser->parse($this->getNextEmbedBlock());
                } else {
                    if (isset($values['leadspaces'])
                        && ' ' == $values['leadspaces']
                        && preg_match('#^(?P<key>'.Inline::REGEX_QUOTED_STRING.'|[^ \'"\{\[].*?) *\:(\s+(?P<value>.+?))?\s*$#u', $values['value'], $matches)
                    ) {
                        
                        $c = $this->getRealCurrentLineNb();
                        $parser = new Parser($c);
                        $parser->refs =& $this->refs;

                        $block = $values['value'];
                        if (!$this->isNextLineIndented()) {
                            $block .= "\n".$this->getNextEmbedBlock($this->getCurrentLineIndentation() + 2);
                        }

                        $data[] = $parser->parse($block);
                    } else {
                        $data[] = $this->parseValue($values['value']);
                    }
                }
            } elseif (preg_match('#^(?P<key>'.Inline::REGEX_QUOTED_STRING.'|[^ \'"\[\{].*?) *\:(\s+(?P<value>.+?))?\s*$#u', $this->currentLine, $values)) {
                if ($context && 'sequence' == $context) {
                    throw new ParseException('You cannot define a mapping item when in a sequence');
                }
                $context = 'mapping';

                try {
                    $key = Inline::parseScalar($values['key']);
                } catch (ParseException $e) {
                    $e->setParsedLine($this->getRealCurrentLineNb() + 1);
                    $e->setSnippet($this->currentLine);

                    throw $e;
                }

                if ('<<' === $key) {
                    if (isset($values['value']) && 0 === strpos($values['value'], '*')) {
                        $isInPlace = substr($values['value'], 1);
                        if (!array_key_exists($isInPlace, $this->refs)) {
                            throw new ParseException(sprintf('Reference "%s" does not exist.', $isInPlace), $this->getRealCurrentLineNb() + 1, $this->currentLine);
                        }
                    } else {
                        if (isset($values['value']) && $values['value'] !== '') {
                            $value = $values['value'];
                        } else {
                            $value = $this->getNextEmbedBlock();
                        }
                        $c = $this->getRealCurrentLineNb() + 1;
                        $parser = new Parser($c);
                        $parser->refs =& $this->refs;
                        $parsed = $parser->parse($value);

                        $merged = array();
                        if (!is_array($parsed)) {
                            throw new ParseException('YAML merge keys used with a scalar value instead of an array.', $this->getRealCurrentLineNb() + 1, $this->currentLine);
                        } elseif (isset($parsed[0])) {
                            foreach (array_reverse($parsed) as $parsedItem) {
                                if (!is_array($parsedItem)) {
                                    throw new ParseException('Merge items must be arrays.', $this->getRealCurrentLineNb() + 1, $parsedItem);
                                }
                                $merged = array_merge($parsedItem, $merged);
                            }
                        } else {
                            $merged = array_merge($merged, $parsed);
                        }

                        $isProcessed = $merged;
                    }
                } elseif (isset($values['value']) && preg_match('#^&(?P<ref>[^ ]+) *(?P<value>.*)#u', $values['value'], $matches)) {
                    $isRef = $matches['ref'];
                    $values['value'] = $matches['value'];
                }

                if ($isProcessed) {
					
                    $data = $isProcessed;
					
                } elseif (!isset($values['value']) || '' == trim($values['value'], ' ') || 0 === strpos(ltrim($values['value'], ' '), '#')) {
                    if ($this->isNextLineIndented() && !$this->isNextLineUnIndentedCollection()) {
                        $data[$key] = null;
                    } else {
                        $c = $this->getRealCurrentLineNb() + 1;
                        $parser = new Parser($c);
                        $parser->refs =& $this->refs;
                        $data[$key] = $parser->parse($this->getNextEmbedBlock());
                    }
                } else {
                    if ($isInPlace) {
                        $data = $this->refs[$isInPlace];
                    } else {
                        $data[$key] = $this->parseValue($values['value']);
                    }
                }
            } else {
                if (2 == count($this->lines) && empty($this->lines[1])) {
                    try {
                        $value = Inline::parse($this->lines[0]);
                    } catch (ParseException $e) {
                        $e->setParsedLine($this->getRealCurrentLineNb() + 1);
                        $e->setSnippet($this->currentLine);

                        throw $e;
                    }

                    if (is_array($value)) {
                        $first = reset($value);
                        if (is_string($first) && 0 === strpos($first, '*')) {
                            $data = array();
                            foreach ($value as $alias) {
                                $data[] = $this->refs[substr($alias, 1)];
                            }
                            $value = $data;
                        }
                    }

                    if (isset($mbEncoding)) {
                        mb_internal_encoding($mbEncoding);
                    }

                    return $value;
                }

                switch (preg_last_error()) {
                    case PREG_INTERNAL_ERROR:
                        $error = 'Internal PCRE error.';
                        break;
                    case PREG_BACKTRACK_LIMIT_ERROR:
                        $error = 'pcre.backtrack_limit reached.';
                        break;
                    case PREG_RECURSION_LIMIT_ERROR:
                        $error = 'pcre.recursion_limit reached.';
                        break;
                    case PREG_BAD_UTF8_ERROR:
                        $error = 'Malformed UTF-8 data.';
                        break;
                    case PREG_BAD_UTF8_OFFSET_ERROR:
                        $error = 'Offset doesn\'t correspond to the begin of a valid UTF-8 code point.';
                        break;
                    default:
                        $error = 'Unable to parse.';
                }

                throw new ParseException($error, $this->getRealCurrentLineNb() + 1, $this->currentLine);
            }

            if ($isRef) {
                $this->refs[$isRef] = end($data);
            }
        }

        if (isset($mbEncoding)) {
            mb_internal_encoding($mbEncoding);
        }

        return empty($data) ? null : $data;
    }

    private function getRealCurrentLineNb(){
		
        return $this->currentLineNb + $this->offset;
    }

    private function getCurrentLineIndentation(){
		
        return strlen($this->currentLine) - strlen(ltrim($this->currentLine, ' '));
    }

    private function getNextEmbedBlock($indentation = null){
		
        $this->moveToNextLine();

        if (null === $indentation) {
            $newIndent = $this->getCurrentLineIndentation();

            $unindentedEmbedBlock = $this->isStringUnIndentedCollectionItem($this->currentLine);

            if (!$this->isCurrentLineEmpty() && 0 === $newIndent && !$unindentedEmbedBlock) {
                throw new ParseException('Indentation problem.', $this->getRealCurrentLineNb() + 1, $this->currentLine);
            }
        } else {
            $newIndent = $indentation;
        }

        $data = array(substr($this->currentLine, $newIndent));

        $isItUnindentedCollection = $this->isStringUnIndentedCollectionItem($this->currentLine);

        while ($this->moveToNextLine()) {

            if ($isItUnindentedCollection && !$this->isStringUnIndentedCollectionItem($this->currentLine)) {
                $this->moveToPreviousLine();
                break;
            }

            if ($this->isCurrentLineEmpty()) {
                if ($this->isCurrentLineBlank()) {
                    $data[] = substr($this->currentLine, $newIndent);
                }

                continue;
            }

            $indent = $this->getCurrentLineIndentation();

            if (preg_match('#^(?P<text> *)$#', $this->currentLine, $match)) {
                $data[] = $match['text'];
            } elseif ($indent >= $newIndent) {
                $data[] = substr($this->currentLine, $newIndent);
            } elseif (0 == $indent) {
                $this->moveToPreviousLine();

                break;
            } else {
                throw new ParseException('Indentation problem.', $this->getRealCurrentLineNb() + 1, $this->currentLine);
            }
        }

        return implode("\n", $data);
    }

    private function moveToNextLine(){
		
        if ($this->currentLineNb >= count($this->lines) - 1) {
            return false;
        }

        $this->currentLine = $this->lines[++$this->currentLineNb];

        return true;
    }

    private function moveToPreviousLine(){
		
        $this->currentLine = $this->lines[--$this->currentLineNb];
    }

    private function parseValue($value){
		
        if (0 === strpos($value, '*')) {
            if (false !== $pos = strpos($value, '#')) {
                $value = substr($value, 1, $pos - 2);
            } else {
                $value = substr($value, 1);
            }

            if (!array_key_exists($value, $this->refs)) {
                throw new ParseException(sprintf('Reference "%s" does not exist.', $value), $this->currentLine);
            }

            return $this->refs[$value];
        }

        if (preg_match('/^(?P<separator>\||>)(?P<modifiers>\+|\-|\d+|\+\d+|\-\d+|\d+\+|\d+\-)?(?P<comments> +#.*)?$/', $value, $matches)) {
            $modifiers = isset($matches['modifiers']) ? $matches['modifiers'] : '';

            return $this->parseFoldedScalar($matches['separator'], preg_replace('#\d+#', '', $modifiers), intval(abs($modifiers)));
        }

        try {
            return Inline::parse($value);
        } catch (ParseException $e) {
            $e->setParsedLine($this->getRealCurrentLineNb() + 1);
            $e->setSnippet($this->currentLine);

            throw $e;
        }
    }
	
    private function parseFoldedScalar($separator, $indicator = '', $indentation = 0){
        $separator = '|' == $separator ? "\n" : ' ';
        $text = '';

        $notEOF = $this->moveToNextLine();

        while ($notEOF && $this->isCurrentLineBlank()) {
            $text .= "\n";

            $notEOF = $this->moveToNextLine();
        }

        if (!$notEOF) {
            return '';
        }

        if (!preg_match('#^(?P<indent>'.($indentation ? str_repeat(' ', $indentation) : ' +').')(?P<text>.*)$#u', $this->currentLine, $matches)) {
            $this->moveToPreviousLine();

            return '';
        }

        $textIndent = $matches['indent'];
        $previousIndent = 0;

        $text .= $matches['text'].$separator;
        while ($this->currentLineNb + 1 < count($this->lines)) {
            $this->moveToNextLine();

            if (preg_match('#^(?P<indent> {'.strlen($textIndent).',})(?P<text>.+)$#u', $this->currentLine, $matches)) {
                if (' ' == $separator && $previousIndent != $matches['indent']) {
                    $text = substr($text, 0, -1)."\n";
                }
                $previousIndent = $matches['indent'];

                $text .= str_repeat(' ', $diff = strlen($matches['indent']) - strlen($textIndent)).$matches['text'].($diff ? "\n" : $separator);
            } elseif (preg_match('#^(?P<text> *)$#', $this->currentLine, $matches)) {
                $text .= preg_replace('#^ {1,'.strlen($textIndent).'}#', '', $matches['text'])."\n";
            } else {
                $this->moveToPreviousLine();

                break;
            }
        }

        if (' ' == $separator) {
            $text = preg_replace('/ (\n*)$/', "\n$1", $text);
        }

        switch ($indicator) {
            case '':
                $text = preg_replace('#\n+$#s', "\n", $text);
                break;
            case '+':
                break;
            case '-':
                $text = preg_replace('#\n+$#s', '', $text);
                break;
        }

        return $text;
    }

    private function isNextLineIndented(){
		
        $currentIndentation = $this->getCurrentLineIndentation();
        $notEOF = $this->moveToNextLine();

        while ($notEOF && $this->isCurrentLineEmpty()) {
            $notEOF = $this->moveToNextLine();
        }

        if (false === $notEOF) {
            return false;
        }

        $ret = false;
        if ($this->getCurrentLineIndentation() <= $currentIndentation) {
            $ret = true;
        }

        $this->moveToPreviousLine();

        return $ret;
    }

    private function isCurrentLineEmpty(){
		
        return $this->isCurrentLineBlank() || $this->isCurrentLineComment();
    }

    private function isCurrentLineBlank(){
		
        return '' == trim($this->currentLine, ' ');
    }

    private function isCurrentLineComment(){
		
        $ltrimmedLine = ltrim($this->currentLine, ' ');

        return $ltrimmedLine[0] === '#';
    }

    private function cleanup($value){
		
        $value = str_replace(array("\r\n", "\r"), "\n", $value);

        if (!preg_match("#\n$#", $value)) {
            $value .= "\n";
        }

        $count = 0;
        $value = preg_replace('#^\%YAML[: ][\d\.]+.*\n#su', '', $value, -1, $count);
        $this->offset += $count;

        $trimmedValue = preg_replace('#^(\#.*?\n)+#s', '', $value, -1, $count);
        if ($count == 1) {
            
            $this->offset += substr_count($value, "\n") - substr_count($trimmedValue, "\n");
            $value = $trimmedValue;
        }

        $trimmedValue = preg_replace('#^\-\-\-.*?\n#s', '', $value, -1, $count);
        if ($count == 1) {
            $this->offset += substr_count($value, "\n") - substr_count($trimmedValue, "\n");
            $value = $trimmedValue;
            $value = preg_replace('#\.\.\.\s*$#s', '', $value);
        }

        return $value;
    }

    private function isNextLineUnIndentedCollection(){
		
        $currentIndentation = $this->getCurrentLineIndentation();
        $notEOF = $this->moveToNextLine();

        while ($notEOF && $this->isCurrentLineEmpty()) {
            $notEOF = $this->moveToNextLine();
        }

        if (false === $notEOF) {
            return false;
        }

        $ret = false;
        if (
            $this->getCurrentLineIndentation() == $currentIndentation
            &&
            $this->isStringUnIndentedCollectionItem($this->currentLine)
        ) {
            $ret = true;
        }

        $this->moveToPreviousLine();

        return $ret;
    }

    private function isStringUnIndentedCollectionItem($string){
        return (0 === strpos($this->currentLine, '- '));
    }

}// end spider
