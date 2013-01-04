<?php
/*
Plugin Name: Sheetmusic
Description: Handles your sheet music archive
Version: 1.0
Author: Sven Axelsson
Author URI: http://svenax.net
License: GPLv2 or later
*/
/*
Copyright 2013  Sven Axelsson  (email: sven@axelsson.name)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

require_once 'lib/class-admin-form.php';
require_once 'lib/class-options.php';

/**
 * Sheetmusic options wrapper.
 */
final class SOpts extends Options
{
    /**
     * Initialize and get the class singleton.
     *
     * @return SOpts
     */
    static public function instance()
    {
        static $me = null;

        if (is_null($me)) {
            $defaults = array(
                'musicPath' => ABSPATH . 'music',
                'musicUrl' => '/music'
            );
            $me = new self('sheetmusic-options', $defaults);
        }

        return $me;
    }

    public function hasPostData()
    {
        return !(@empty($_POST['music_path']) || @empty($_POST['music_url']));
    }

    public function setFromPost()
    {
        $this->musicPath = @$_POST['music_path'];
        $this->musicUrl = @$_POST['music_url'];
    }
}

/**
 * Track info about one music item for easy reference.
 */
class SheetmusicInfo
{
    public $pathPart;
    public $name;
    public $updated;

    public function __construct($name, $folder, $updated)
    {
        $this->pathPart = "{$folder}/{$name}";
        $this->name = $this->humanize($name);
        $this->updated = $updated;
    }

    /**
     * Create a readable version of a file or folder name.
     *
     * @param  string $text
     * @return string
     */
    public function humanize($text)
    {
        $repl = array(
            '0-0' => ' other', // Leading space for sort order
            '-'   => '/',
            '_'   => ' ',
            '@'   => '&',
            '!'   => ''
        );

        return ucwords(str_replace(array_keys($repl), array_values($repl), $text));
    }

    /**
     * Return a link with image content to be used in the music list.
     *
     * @return string
     */
    public function makeLink()
    {
        $filePrefix = SOpts::instance()->musicUrl . '/' . $this->pathPart;
        $title = $this->name . "\nUpdated at: " . date('F j, Y, G:i:s', $this->updated);
        return sprintf(
            '<a href="%1$s.pdf" title="%2$s"><img src="%1$s.preview.png"></a>',
            $filePrefix,
            $title
        );
    }
}

/**
 * Plugin for the sheetmusic functionality.
 *
 * Provides the shortcode [sheetmusic] with optional parameters show and menu.
 *
 * show:
 * - total: Total number of sheet music items
 * - updated: Date of last update
 *
 * menu - Uses these query variables:
 * - type: last10, category, name
 * - subtype: depends on the above
 *
 * No parameters at all displays the music list depending on the menu query
 * variables.
 */
class Sheetmusic
{
    const VERSION = '1.0';

    private $data;

    /**
     * Register actions, filters and shortcodes.
     */
    public function run()
    {
        add_action('init', array($this, 'initAction'));
        add_action('admin_menu', array($this, 'adminMenuAction'));
        // Load at the end of the scripts chain.
        add_action('wp_enqueue_scripts', array($this, 'addScriptsAction'), 99);
        add_shortcode('sheetmusic', array($this, 'sheetmusicShortcode'));
    }

    // Actions ===============================================================

    /**
     * Set up the environment and create the cache files if needed.
     */
    public function initAction()
    {
        // We haven't configured the plugin yet
        if (!is_dir(SOpts::instance()->musicPath)) return;

        $isPrivate = is_user_logged_in();
        if (!file_exists($this->getCacheFileName($isPrivate))) {
            $this->scanAndBuildInfo($isPrivate);
        }
        $this->data = unserialize(
            file_get_contents($this->getCacheFileName($isPrivate))
        );
    }

    public function adminMenuAction()
    {
        add_options_page(
            'Sheetmusic',
            'Sheetmusic',
            'manage_options',
            'sheetmusic-options',
            array($this, 'adminMenuPage'));
    }

    public function adminMenuPage()
    {
        if (SOpts::instance()->hasPostData()) {
            SOpts::instance()->setFromPost();
            echo '<div class="updated"><p><strong>Settings saved.</strong></p></div>';
        }

        $frm = new AdminForm();
        $frm->addTextField('Music File Path:', 'musicPath', SOpts::instance()->musicPath, 'code')
            ->addTextField('Music Url:', 'musicUrl', SOpts::instance()->musicUrl, 'code')
            ->addSubmitButton('Save Changes');

        echo <<<HTML
            <div class="wrap">
            <div id="icon-options-general" class="icon32"><br></div>
            <h2>Sheetmusic Settings</h2>
            {$frm}
            </div>
HTML;
    }

    /**
     * Add our css to the page.
     */
    public function addScriptsAction()
    {
        wp_register_style('sheetmusic', plugins_url('sheetmusic') . '/sheetmusic.css');
        wp_enqueue_style('sheetmusic');
    }

    /**
     * Handle our shortcode variants.
     *
     * @param  mixed  $atts Attributes array. Note that this is an
     *                      empty string if no parameters are present.
     * @return string Shortcode expansion.
     */
    public function sheetmusicShortcode($atts)
    {
        $type = @$_GET['type'] ?: @$atts['type'];
        $subtype = @$_GET['subtype'];
        $atts = empty($atts) ? array() : $atts;

        if (array_key_exists('show', $atts)) {
            switch ($atts['show']) {
            case 'total':
                return $this->data['total'];
            case 'updated':
                return date('F j, Y', $this->data['lastUpdated']);
            }
        }

        return <<<HTML
            <div>
                <ul class='music-menu' id='music-menu'>{$this->makeMenu($type, $subtype)}</ul>
                <div class='music-list'>{$this->makeList($type, $subtype)}</div>
            </div>
HTML;
    }

    // Builders ==============================================================

    /**
     * Refresh a cache file by scanning the sheet music library, building the
     * data structure, and serializing it to a file.
     *
     * @param  bool $isPrivate Are we building the private cache?
     */
    private function scanAndBuildInfo($isPrivate)
    {
        $music = $this->scanFiles($isPrivate);

        $data['last10']      = $this->last10($music);
        $data['category']    = $this->byCategory($music);
        $data['name']        = $this->byName($music);
        $data['total']       = count($music);
        $data['lastUpdated'] = $data['last10'][0]->updated;

        file_put_contents($this->getCacheFileName($isPrivate), serialize($data));
    }

    /**
     * A flat array of SheetmusicInfo objects for all our music files.
     *
     * @param  bool  $isPrivate Should we include private files?
     * @return array All the files as SheetmusicInfo objects.
     */
    private function scanFiles($isPrivate)
    {
        $music = array();
        $iter = new RecursiveDirectoryIterator(SOpts::instance()->musicPath);
        foreach (new RecursiveIteratorIterator($iter) as $fs) {
            // Skip uninteresting files and dirs
            if ($fs->isDir() || substr($fs->getFilename(), -4) !== '.pdf') {
                continue;
            }
            // Skip private files
            if (!$isPrivate && substr($fs->getFilename(), 0, 1) === '!') {
                continue;
            }

            $music[] = new SheetmusicInfo(
                $fs->getBasename('.pdf'), // Strip off the file extension
                basename($fs->getPath()),
                $fs->getMtime()
            );
        }

        return $music;
    }

    /**
     * The last 10 files.
     *
     * @param  array $music All the files from scanFiles.
     * @return array The last 10 files in descending order.
     */
    private function last10($music)
    {
        usort($music, function($a, $b) {
            return strcmp((string)$b->updated, (string)$a->updated);
        });

        return array_slice($music, 0, 10);
    }

    /**
     * All the files grouped by category.
     *
     * @param  array $music All the files from scanFiles.
     * @return array [category][file] All files grouped by category.
     */
    private function byCategory($music)
    {
        usort($music, function($a, $b) {
            return strcmp(
                $a->humanize($a->pathPart),
                $b->humanize($b->pathPart)
            );
        });

        $data = array();
        $category = '';
        foreach($music as $m) {
            $folder = $m->humanize(dirname($m->pathPart));
            if ($category !== $folder) {
                $category = $folder;
            }
            $data[$category][] = $m;
        }

        return $data;
    }

    /**
     * All the files grouped by first letter in the name.
     *
     * @param  array $music All the files from scanFiles.
     * @return array [prefix][file] All files grouped by letter in the name.
     */
    private function byName($music)
    {
        usort($music, function($a, $b) {
            return strcmp($a->name, $b->name);
        });

        $data = array();
        $prefix = '';
        foreach($music as $m) {
            $namePrefix = substr($m->name, 0, 1);
            if ($prefix !== $namePrefix) {
                $prefix = $namePrefix;
            }
            $data[$prefix][] = $m;
        }

        return $data;
    }

    // Display ===============================================================

    /**
     * The menu list displayed by [sheetmusic menu].
     *
     * @param  string $type    Query param type
     * @param  string $subtype Query param subtype
     * @return string
     */
    private function makeMenu($type, $subtype)
    {
        $ret = $this->makeMenuLink('Last 10', 'last10', '', $type, $subtype)
             . $this->makeMenuLink('By Category', 'category', '', $type, $subtype)
             . $this->makeMenuLink('By Name', 'name', '', $type, $subtype);

        if ($type && $type !== 'last10') {
             $ret .= '<li>•</li>';
         }

        $data = array();
        switch ($type) {
        case 'category':
            $data = $this->data['category'];
            break;
        case 'name':
            $data = $this->data['name'];
            break;
        }

        foreach ($data as $name => $items) {
            $count = count($items);
            $ret .= $this->makeMenuLink("{$name} ({$count})", $type, $name, '', $subtype);
        }

        return $ret;
    }

    /**
     * Helper to generate a menu item.
     *
     * @param  string $name         Display name.
     * @param  string $type         Type used in url.
     * @param  string $subtype      Subtype used in url.
     * @param  string $queryType    Type from query data.
     * @param  string $querySubtype Subtype from query data.
     * @return string A menu list item from the given data.
     */
    private function makeMenuLink($name, $type, $subtype, $queryType, $querySubtype)
    {
        $ret = '';
        if ($type === $queryType) {
            $ret = "<b>{$name}</b>";
        } else if (!empty($querySubtype) && $subtype === $querySubtype) {
            $ret = $name;
        } else {
            $params = array('type' => $type);
            if (!empty($subtype)) {
                $params['subtype'] = $subtype;
            }
            $ret = sprintf(
                '<a href="%s?%s#music-menu">%s</a>',
                $this->getPageUrl(),
                http_build_query($params),
                $name
            );
        }

        return "<li>{$ret}</li>";
    }

    /**
     * The music list displayed by [sheetmusic].
     *
     * @param  string $type    Type from query data.
     * @param  string $subtype Subtype from query data.
     * @return string The items to display.
     */
    private function makeList($type, $subtype)
    {
        $data = array();
        switch ($type) {
        case 'last10':
            $data = $this->data['last10'];
            break;
        case 'category':
            $data = (array)$this->data['category'][$subtype];
            break;
        case 'name':
            $data = (array)$this->data['name'][$subtype];
            break;
        }

        $ret = '';
        foreach ($data as $item) {
            $ret .= "<div>{$item->makeLink()}</div>";
        }

        return $ret;
    }

    /**
     * Return the name of the current cache file.
     *
     * @param  bool   $isPrivate True if the private file, else false.
     * @return string Full path to the cache file.
     */
    private function getCacheFileName($isPrivate)
    {
        return SOpts::instance()->musicPath . '/cache.' . ($isPrivate ? 'priv' : 'pub');
    }

    /**
     * Return the real url (permalink) of the current page with any query
     * parameters stripped out.
     *
     * @return string
     */
    private function getPageUrl()
    {
        $url = $_SERVER['REDIRECT_URL'] ?: $_SERVER['REQUEST_URI'];
        list($url, ) = explode('?', $url, 2);

        return $url;
    }
}

// Do it =====================================================================

$me = new Sheetmusic();
$me->run();
