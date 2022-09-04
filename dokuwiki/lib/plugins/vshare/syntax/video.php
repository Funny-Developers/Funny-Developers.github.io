<?php

/**
 * Easily embed videos from various Video Sharing sites
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
class syntax_plugin_vshare_video extends DokuWiki_Syntax_Plugin
{
    protected $sites;

    protected $sizes = [
        'small' => [255, 143],
        'medium' => [425, 239],
        'large' => [520, 293],
        'full' => ['100%', ''],
        'half' => ['50%', ''],
    ];

    protected $alignments = [
        0 => 'none',
        1 => 'right',
        2 => 'left',
        3 => 'center',
    ];

    /**
     * Constructor.
     * Intitalizes the supported video sites
     */
    public function __construct()
    {
        $this->sites = helper_plugin_vshare::loadSites();
    }

    /** @inheritdoc */
    public function getType()
    {
        return 'substition';
    }

    /** @inheritdoc */
    public function getPType()
    {
        return 'block';
    }

    /** @inheritdoc */
    public function getSort()
    {
        return 159;
    }

    /** @inheritdoc */
    public function connectTo($mode)
    {
        $pattern = join('|', array_keys($this->sites));
        $this->Lexer->addSpecialPattern('\{\{\s?(?:' . $pattern . ')>[^}]*\}\}', $mode, 'plugin_vshare_video');
    }

    /** @inheritdoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $command = substr($match, 2, -2);

        // title
        list($command, $title) = array_pad(explode('|', $command), 2, '');
        $title = trim($title);

        // alignment
        $align = 0;
        if (substr($command, 0, 1) == ' ') $align += 1;
        if (substr($command, -1) == ' ') $align += 2;
        $command = trim($command);

        // get site and video
        list($site, $vid) = explode('>', $command);
        if (!$this->sites[$site]) return null; // unknown site
        if (!$vid) return null; // no video!?

        // what size?
        list($vid, $pstr) = array_pad(explode('?', $vid, 2), 2, '');
        parse_str($pstr, $userparams);
        list($width, $height) = $this->parseSize($userparams);

        // get URL
        $url = $this->insertPlaceholders($this->sites[$site]['url'], $vid, $width, $height);
        list($url, $urlpstr) = array_pad(explode('?', $url, 2), 2, '');
        parse_str($urlpstr, $urlparams);

        // merge parameters
        $params = array_merge($urlparams, $userparams);
        $url = $url . '?' . buildURLparams($params, '&');

        return array(
            'site' => $site,
            'domain' => parse_url($url, PHP_URL_HOST),
            'video' => $vid,
            'url' => $url,
            'align' => $this->alignments[$align],
            'width' => $width,
            'height' => $height,
            'title' => $title,
        );
    }

    /** @inheritdoc */
    public function render($mode, Doku_Renderer $R, $data)
    {
        if ($mode != 'xhtml') return false;
        if (is_null($data)) return false;

        if (is_a($R, 'renderer_plugin_dw2pdf')) {
            $R->doc .= $this->pdf($data);
        } else {
            $R->doc .= $this->iframe($data, $this->getConf('gdpr') ? 'div' : 'iframe');
        }
        return true;
    }

    /**
     * Prepare the HTML for output of the embed iframe
     * @param array $data
     * @param string $element Can be used to not directly embed the iframe
     * @return string
     */
    public function iframe($data, $element = 'iframe')
    {
        return "<$element "
            . buildAttributes(array(
                'src' => $data['url'],
                'width' => $data['width'],
                'height' => $data['height'],
                'style' => $this->sizeToStyle($data['width'], $data['height']),
                'class' => 'vshare vshare__' . $data['align'],
                'allowfullscreen' => '',
                'frameborder' => 0,
                'scrolling' => 'no',
                'data-domain' => $data['domain'],
            ))
            . '><h3>' . hsc($data['title']) . "</h3></$element>";
    }

    /**
     * Create a style attribute for the given size
     *
     * @param int|string $width
     * @param int|string $height
     * @return string
     */
    public function sizeToStyle($width, $height)
    {
        // no unit? use px
        if ($width && $width == (int)$width) {
            $width = $width . 'px';
        }
        // no unit? use px
        if ($height && $height == (int)$height) {
            $height = $height . 'px';
        }

        $style = '';
        if ($width) $style .= 'width:' . $width . ';';
        if ($height) $style .= 'height:' . $height . ';';
        return $style;
    }

    /**
     * Prepare the HTML for output in PDF exports
     *
     * @param array $data
     * @return string
     */
    public function pdf($data)
    {
        $html = '<div class="vshare vshare__' . $data['align'] . '"
                      width="' . $data['width'] . '"
                      height="' . $data['height'] . '">';

        $html .= '<a href="' . $data['url'] . '" class="vshare">';
        $html .= '<img src="' . DOKU_BASE . 'lib/plugins/vshare/video.png" />';
        $html .= '</a>';

        $html .= '<br />';

        $html .= '<a href="' . $data['url'] . '" class="vshare">';
        $html .= ($data['title'] ? hsc($data['title']) : 'Video');
        $html .= '</a>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Fill the placeholders in the given URL
     *
     * @param string $url
     * @param string $vid
     * @param int|string $width
     * @param int|string $height
     * @return string
     */
    public function insertPlaceholders($url, $vid, $width, $height)
    {
        global $INPUT;
        $url = str_replace('@VIDEO@', rawurlencode($vid), $url);
        $url = str_replace('@DOMAIN@', rawurlencode($INPUT->server->str('HTTP_HOST')), $url);
        $url = str_replace('@WIDTH@', $width, $url);
        $url = str_replace('@HEIGHT@', $height, $url);

        return $url;
    }

    /**
     * Extract the wanted size from the parameter list
     *
     * @param array $params
     * @return int[]
     */
    public function parseSize(&$params)
    {
        $known = join('|', array_keys($this->sizes));

        foreach ($params as $key => $value) {
            if (preg_match("/^((\d+)x(\d+))|($known)\$/i", $key, $m)) {
                unset($params[$key]);
                if (isset($m[4])) {
                    return $this->sizes[strtolower($m[4])];
                } else {
                    return [$m[2], $m[3]];
                }
            }
        }

        // default
        return $this->sizes['medium'];
    }
}
