<?php
/*
 * CSV Plugin: displays a cvs formatted file or inline data as a table
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Steven Danz <steven-danz@kc.rr.com>
 * @author     Gert
 * @author     Andreas Gohr <gohr@cosmocode.de>
 * @author     Jerry G. Geiger <JerryGeiger@web.de>
 */

if(!defined('DOKU_INC')) die('meh');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_csv extends DokuWiki_Syntax_Plugin {

    /**
     * What kind of syntax are we?
     */
    function getType() {
        return 'substition';
    }

    /**
     * Where to sort in?
     */
    function getSort() {
        return 155;
    }

    /**
     * Paragraph Type
     */
    function getPType() {
        return 'block';
    }

    /**
     * @inheritdoc
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('<csv[^>]*>.*?(?:<\/csv>)', $mode, 'plugin_csv');
    }

    /**
     * @inheritdoc
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        global $INFO;
        $match = substr($match, 4, -6);

        //default options
        $opt = array(
            'hdr_rows' => 1,
            'hdr_cols' => 0,
            'span_empty_cols' => 0,
            'maxlines' => 0,
            'offset' => 0,
            'file' => '',
            'delim' => ',',
            'enclosure' => '"',
            'escape' => '"',
            'content' => ''
        );

        list($optstr, $opt['content']) = explode('>', $match, 2);
        unset($match);

        // parse options
        $optsin = explode(' ', $optstr);
        foreach($optsin as $o) {
            $o = trim($o);
            if(preg_match('/(\w+)=(.*)/', $o, $matches)) {
                $opt[$matches[1]] = $matches[2];
            } elseif($o) {
                if(preg_match('/^https?:\/\//i', $o)) {
                    $opt['file'] = $o;
                } else {
                    $opt['file'] = cleanID($o);
                    if(!strlen(getNS($opt['file'])))
                        $opt['file'] = $INFO['namespace'] . ':' . $opt['file'];
                }
            }
        }
        if($opt['delim'] == 'tab') $opt['delim'] = "\t";

        return $opt;
    }

    /**
     * @inheritdoc
     */
    function render($mode, Doku_Renderer $renderer, $opt) {
        if($mode == 'metadata') return false;

        // load file data
        if($opt['file']) {
            if(preg_match('/^https?:\/\//i', $opt['file'])) {
                $http = new DokuHTTPClient();
                $opt['content'] = $http->get($opt['file']);
                if($opt['content'] === false) {
                    $renderer->cdata('Failed to fetch remote CSV data');
                    return true;
                }
            } else {
                $renderer->info['cache'] = false;
                if(auth_quickaclcheck(getNS($opt['file']) . ':*') < AUTH_READ) {
                    $renderer->cdata('Access denied to CSV data');
                    return true;
                } else {
                    $file = mediaFN($opt['file']);
                    $opt['content'] = io_readFile($file);
                }
            }
            // if not valid UTF-8 is given we assume ISO-8859-1
            if(!utf8_check($opt['content'])) $opt['content'] = utf8_encode($opt['content']);
        }

        // check if there is content
        $content =& $opt['content'];
        $content = trim($content);
        if($content === '') {
            $renderer->cdata('No csv data found');
            return true;
        }

        // get the first row - it will define the structure
        $row = helper_plugin_csv::csv_explode_row($content, $opt['delim'], $opt['enclosure'], $opt['escape']);
        $maxcol = count($row);
        $line = 0;

        // use offset (only if offset is not default value 0)
        if($opt['offset'] >= 1) {
            $content = explode("\n", $content);
            $content = array_slice($content, $opt['offset'] + 1 - $opt['hdr_rows']);
            $content = implode("\n", $content);
        }

        // create the table and start rendering
        $renderer->table_open($maxcol);
        while($row !== false) {
            // make sure we have enough columns
            $row = array_pad($row, $maxcol, '');

            // render
            $renderer->tablerow_open();
            for($i = 0; $i < $maxcol;) {
                $span = 1;
                // lookahead to find spanning cells
                if($opt['span_empty_cols']) {
                    for($j = $i + 1; $j < $maxcol; $j++) {
                        if($row[$j] === '') {
                            $span++;
                        } else {
                            break;
                        }
                    }
                }

                // open cell
                if($line < $opt['hdr_rows'] || $i < $opt['hdr_cols']) {
                    $renderer->tableheader_open($span);
                } else {
                    $renderer->tablecell_open($span);
                }

                // print cell content, call linebreak() for newlines
                $lines = explode("\n", $row[$i]);
                $cnt = count($lines);
                for($k = 0; $k < $cnt; $k++) {
                    $renderer->cdata($lines[$k]);
                    if($k < $cnt - 1) $renderer->linebreak();
                }

                // close cell
                if($line < $opt['hdr_rows'] || $i < $opt['hdr_cols']) {
                    $renderer->tableheader_close();
                } else {
                    $renderer->tablecell_close();
                }

                $i += $span;
            }
            $renderer->tablerow_close();

            // get next row
            $row = helper_plugin_csv::csv_explode_row($content, $opt['delim'], $opt['enclosure'], $opt['escape']);
            $line++;

            // limit max lines (only if maxlines is not default value 0)
            if($opt['maxlines'] >= 1 and $opt['maxlines'] == ($line - $opt['hdr_rows'])) {
                $row = false;
            }
        }
        $renderer->table_close();

        return true;
    }

}
//Setup VIM: ex: et ts=4 enc=utf-8 :
