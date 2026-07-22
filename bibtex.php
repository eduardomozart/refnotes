<?php

/**
 * Plugin RefNotes: BibTeX parser
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Mykola Ostrovskyy <dwpforge@gmail.com>
 */

////////////////////////////////////////////////////////////////////////////////////////////////////
class refnotes_bibtex_parser extends \dokuwiki\Parsing\Parser {

    private static $instance = NULL;

    /**
     *
     */
    public static function getInstance() {
        if (self::$instance == NULL) {
            self::$instance = new refnotes_bibtex_parser();
        }

        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        $this->handler = new refnotes_bibtex_handler();
        $this->lexer = new refnotes_bibtex_lexer($this->handler, 'base', true);

        $this->addBibtexMode(new refnotes_bibtex_outside_mode());
        $this->addBibtexMode(new refnotes_bibtex_entry_mode('parented'));
        $this->addBibtexMode(new refnotes_bibtex_entry_mode('braced'));
        $this->addBibtexMode(new refnotes_bibtex_field_mode());
        $this->addBibtexMode(new refnotes_bibtex_integer_value_mode());
        $this->addBibtexMode(new refnotes_bibtex_string_value_mode('quoted'));
        $this->addBibtexMode(new refnotes_bibtex_string_value_mode('braced'));
        $this->addBibtexMode(new refnotes_bibtex_nested_braces_mode('quoted'));
        $this->addBibtexMode(new refnotes_bibtex_nested_braces_mode('braced'));
        $this->addBibtexMode(new refnotes_bibtex_concatenation_mode());
    }

    /**
     *
     */
    private function addBibtexMode($mode) {
        $this->addMode($mode->getName(), $mode);
    }

    /**
     * Override addMode to avoid DokuWiki Mort dependency injection
     * which expects a DokuWiki ModeRegistry and Handler.
     */
    public function addMode($name, \dokuwiki\Parsing\ParserMode\AbstractMode $Mode) {
        $Mode->setLexer($this->lexer);
        $this->modes[$name] = $Mode;
    }

    /**
     *
     */
    public function connectModes() {
        if (!$this->connected) {
            $this->modes['outside']->connectTo('base');
            $this->modes['entry_parented']->connectTo('base');
            $this->modes['entry_braced']->connectTo('base');

            parent::connectModes();
        }
    }

    /**
     *
     */
    public function parse($text) {
        $this->connectModes();

        $this->handler->reset();
        $this->lexer->parse(str_replace("\r\n", "\n", $text));

        return $this->handler->finalize();
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////
class refnotes_bibtex_lexer extends \dokuwiki\Parsing\Lexer\Lexer {

    /**
     *
     */
    public function parse($text) {
        $lastMode = '';
        // Offset tracking is required by DokuWiki Mort Lexer which doesn't slice the input string
        $offset = 0;

        // Reset the mode stack for DokuWiki Mort lexer
        $this->modeStack = new \dokuwiki\Parsing\Lexer\StateStack('base');

        while (is_array($parsed = $this->reduce($text, $offset))) {
            list($unmatched, $matched, $mode) = $parsed;
            $matchPos = $offset + strlen($unmatched);

            if (!$this->dispatchTokens($unmatched, $matched, $mode, $offset, $matchPos)) {
                return false;
            }

            $newOffset = $matchPos + strlen($matched);

            if (empty($unmatched) && empty($matched) && ($lastMode == $this->modeStack->getCurrent())) {
                return false;
            }

            $lastMode = $this->modeStack->getCurrent();
            $offset = $newOffset;
        }

        if (!$parsed) {
            return false;
        }

        return $this->invokeHandler(substr($text, $offset), DOKU_LEXER_UNMATCHED, $offset);
    }

    /**
     *
     */
    protected function invokeHandler($text, $state, $pos) {
        if ($text == "" && $state == DOKU_LEXER_UNMATCHED) {
            return true;
        }

        $mode = $this->modeStack->getCurrent();
        $handler = isset($this->mode_handlers[$mode]) ? $this->mode_handlers[$mode] : $mode;

        return $this->handler->$handler($text, $state, $pos);
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////
class refnotes_bibtex_mode extends \dokuwiki\Parsing\ParserMode\AbstractMode {

    protected $name;
    protected $handler;
    protected $specialPattern;
    protected $entryPattern;
    protected $exitPattern;

    /**
     * Constructor
     */
    public function __construct() {
        $this->name = preg_replace('/refnotes_bibtex_(\w+)_mode/', '$1', get_class($this));
        $this->handler = '';

        $this->specialPattern = array();
        $this->entryPattern = array();
        $this->exitPattern = array();
    }

    /**
     * Required by AbstractMode but not used by refnotes custom parser.
     */
    public function handle($match, $state, $pos, Doku_Handler $handler) {
        return false;
    }

    /**
     *
     */
    public function getSort() {
        return 0;
    }

    /**
     *
     */
    public function getName() {
        return $this->name;
    }

    /**
     *
     */
    public function connectTo($mode) {
        foreach ($this->specialPattern as $pattern) {
            $this->Lexer->addSpecialPattern($pattern, $mode, $this->name);
        }

        foreach ($this->entryPattern as $pattern) {
            $this->Lexer->addEntryPattern($pattern, $mode, $this->name);
        }

        if ($this->handler != '') {
            $this->Lexer->mapHandler($this->name, $this->handler);
        }
    }

    /**
     *
     */
    public function postConnect() {
        foreach ($this->exitPattern as $pattern) {
            $this->Lexer->addExitPattern($pattern, $this->name);
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////
class refnotes_bibtex_outside_mode extends refnotes_bibtex_mode {

    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();

        $this->specialPattern[] = '[^@]+(?=@)';
    }

    /**
     *
     */
    public function connectTo($mode) {
        parent::connectTo($mode);

        $this->Lexer->mapHandler('base', $this->name);
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////
class refnotes_bibtex_entry_mode extends refnotes_bibtex_mode {

    /**
     * Constructor
     */
    public function __construct($type) {
        parent::__construct();

        $this->handler = $this->name;
        $this->name .= '_' . $type;

        list($open, $close) = ($type == 'parented') ? array('\(', '\)') : array('{', '}');

        $this->entryPattern[] = '@\w+\s*' . $open . '(?=.*' . $close . ')';
        $this->exitPattern[] = '\s*(?:' . $close . '|(?=@))';

        $this->allowedModes = array('field');
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////
class refnotes_bibtex_field_mode extends refnotes_bibtex_mode {

    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();

        $this->entryPattern[] = '\s*\w[\w-]+\s*=\s*';
        $this->exitPattern[] = '\s*(?:,|(?=[\)}@]))';

        $this->allowedModes = array('integer_value', 'string_value_quoted', 'string_value_braced', 'concatenation');
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////
class refnotes_bibtex_integer_value_mode extends refnotes_bibtex_mode {

    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();

        $this->specialPattern[] = '^\d+';
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////
class refnotes_bibtex_string_value_mode extends refnotes_bibtex_mode {

    /**
     * Constructor
     */
    public function __construct($type) {
        parent::__construct();

        $this->handler = $this->name;
        $this->name .= '_' . $type;

        list($open, $close, $exit) = ($type == 'quoted') ? array('"', '"', '"') : array('{', '}', '(?:}|(?=@))');

        $this->entryPattern[] = $open . '(?=.*' . $close . ')';
        $this->exitPattern[] = $exit;

        $this->allowedModes = array('nested_braces_' . $type);
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////
class refnotes_bibtex_nested_braces_mode extends refnotes_bibtex_mode {

    /**
     * Constructor
     */
    public function __construct($type) {
        parent::__construct();

        $this->handler = $this->name;
        $this->name .= '_' . $type;

        $this->entryPattern[] = '{(?=.*})';
        $this->exitPattern[] = ($type == 'quoted') ? '}' : '(?:}|(?=@))';

        $this->allowedModes = array($this->name);
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////
class refnotes_bibtex_concatenation_mode extends refnotes_bibtex_mode {

    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();

        $this->specialPattern[] = '\s*#\s*';
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////
class refnotes_bibtex_handler {

    private $entries;
    private $entry;
    private $field;

    /**
     * Constructor
     */
    public function __construct() {
        $this->reset();
    }

    /**
     *
     */
    public function reset() {
        $this->entries = new refnotes_bibtex_entry_stash();
        $this->entry = NULL;
        $this->field = NULL;
    }

    /**
     *
     */
    public function finalize() {
        $entries = $this->entries->getEntries();

        foreach ($entries as &$entry) {
            if (array_key_exists('author', $entry)) {
                $authors = explode(' and ', $entry['author']);

                foreach ($authors as &$author) {
                    $author = implode(' ', array_reverse(explode(', ', $author)));
                }

                $entry['author'] = implode(', ', $authors);
            }
        }

        return $entries;
    }

    /**
     *
     */
    public function outside($match, $state) {
        /* Ignore everything outside the entries */
        return true;
    }

    /**
     *
     */
    public function entry($match, $state) {
        switch ($state) {
            case DOKU_LEXER_ENTER:
                $this->entry = new refnotes_bibtex_entry(preg_replace('/@(\w+)\W+/', '$1', $match));
                break;

            case DOKU_LEXER_UNMATCHED:
                $this->entry->handleUnmatched($match);
                break;

            case DOKU_LEXER_EXIT:
                $this->entries->add($this->entry);
                $this->entry = NULL;
                break;
        }

        return true;
    }

    /**
     *
     */
    public function field($match, $state) {
        switch ($state) {
            case DOKU_LEXER_ENTER:
                $this->field = new refnotes_bibtex_field(preg_replace('/\W*(\w[\w-]+)\W*/', '$1', $match));
                break;

            case DOKU_LEXER_UNMATCHED:
                $this->field->addToken('unmatched', $match);
                break;

            case DOKU_LEXER_EXIT:
                $this->entry->addField($this->field);
                $this->field = NULL;
                break;
        }

        return true;
    }

    /**
     *
     */
    public function integer_value($match, $state) {
        $this->field->addToken('integer', $match);

        return true;
    }

    /**
     *
     */
    public function string_value($match, $state) {
        if ($state == DOKU_LEXER_UNMATCHED) {
            $this->field->addToken('string', $match);
        }

        return true;
    }

    /**
     *
     */
    public function nested_braces($match, $state) {
        if ($state == DOKU_LEXER_UNMATCHED) {
            $this->field->addToken('braces', $match);
        }

        return true;
    }

    /**
     *
     */
    public function concatenation($match, $state) {
        /* Nothing special to do, concatenation will happen anyway */
        return true;
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////
class refnotes_bibtex_entry_stash {

    private $entry;
    private $strings;
    private $namespace;

    /**
     * Constructor
     */
    public function __construct() {
        $this->entry = array();
        $this->strings = new refnotes_bibtex_strings();
        $this->namespace = ':';
    }

    /**
     *
     */
    public function getEntries() {
        return $this->entry;
    }

    /**
     *
     */
    public function add($entry) {
        static $entryType = array(
            'article', 'book', 'booklet', 'conference', 'inbook', 'incollection', 'inproceedings', 'manual',
            'mastersthesis', 'misc', 'phdthesis', 'proceedings', 'techreport', 'unpublished');

        $type = $entry->getType();
        $name = $entry->getName();

        if (in_array($type, $entryType)) {
            if ($this->isValidRefnotesName($name)) {
                if ($name[0] != ':') {
                    $name = $this->namespace . $name;
                }

                $this->entry[] = array_merge(array('note-name' => $name), $entry->getData($this->strings));
            }
        }
        elseif ($type == 'string') {
            $data = $entry->getData($this->strings);
            $name = reset(array_keys($data));

            if ($this->isValidStringName($name)) {
                $this->strings->add($name, $data[$name]);
            }
        }
        elseif (($type == 'comment') && (strtolower($name) == 'refnotes')) {
            $data = $entry->getData($this->strings);

            if (isset($data['namespace']) && $this->isValidRefnotesName($data['namespace'])) {
                $this->namespace = refnotes_namespace::canonizeName($data['namespace']);
            }
        }
    }

    /**
     *
     */
    private function isValidRefnotesName($name) {
        return preg_match('/^' . refnotes_note::getNamePattern('full-extended') . '$/', $name) == 1;
    }

    /**
     *
     */
    private function isValidStringName($name) {
        return preg_match('/^[[:alpha:]]\w*$/', $name) == 1;
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////
class refnotes_bibtex_entry {

    private $type;
    private $name;
    private $field;

    /**
     * Constructor
     */
    public function __construct($type) {
        $this->type = strtolower($type);
        $this->name = '';
        $this->field = array();
    }

    /**
     *
     */
    public function getType() {
        return $this->type;
    }

    /**
     *
     */
    public function getName() {
        return $this->name;
    }

    /**
     *
     */
    public function getData($strings) {
        $data = array();

        foreach ($this->field as $field) {
            $data[$field->getName()] = $field->getValue($strings);
        }

        return $data;
    }

    /**
     *
     */
    public function handleUnmatched($token) {
        if (($this->name == '') && (preg_match('/\s*([^\s,]+)\s*,/', $token, $match) == 1)) {
            $this->name = $match[1];
        }
    }

    /**
     *
     */
    public function addField($field) {
        $this->field[] = $field;
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////
class refnotes_bibtex_field {

    private $name;
    private $token;

    /**
     * Constructor
     */
    public function __construct($name) {
        $this->name = strtolower($name);
        $this->token = array();
    }

    /**
     *
     */
    public function getName() {
        return $this->name;
    }

    /**
     *
     */
    public function getValue($strings) {
        $value = '';

        foreach ($this->token as $token) {
            $text = $token->text;

            if ($token->type == 'unmatched') {
                $text = $strings->lookup(strtolower(trim($text)));
            }

            $value .= $text;
        }

        return preg_replace('/\s+/', ' ', trim($value));
    }

    /**
     *
     */
    public function addToken($type, $text) {
        $this->token[] = new refnotes_bibtex_field_token($type, $text);
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////
class refnotes_bibtex_field_token {

    public $type;
    public $text;

    /**
     * Constructor
     */
    public function __construct($type, $text) {
        $this->type = $type;
        $this->text = $text;
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////
class refnotes_bibtex_strings {

    private $string;

    /**
     * Constructor
     */
    public function __construct() {
        $this->string = array();
    }

    /**
     *
     */
    public function add($name, $value) {
        $this->string[$name] = $value;
    }

    /**
     *
     */
    public function lookup($name) {
        return array_key_exists($name, $this->string) ? $this->string[$name] : '';
    }
}
