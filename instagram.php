<?php

namespace Grav\Plugin;

use Grav\Common\Cache;
use Grav\Common\Plugin;
use Grav\Common\Data\Data;
use Grav\Common\Page\Page;
use Grav\Common\GPM\Response;
use EspressoDev\InstagramBasicDisplay\InstagramBasicDisplay;

require __DIR__ . '/vendor/autoload.php';

class InstagramPlugin extends Plugin
{
    private const template_html = 'partials/instagram.html.twig';
    private $feeds = [];
    const HOUR_IN_SECONDS = 3600;

    /**
     * Return a list of subscribed events.
     *
     * @return array    The list of events of the plugin of the form
     *                      'name' => ['method_name', priority].
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    /**
     * Initialize configuration.
     */
    public function onPluginsInitialized()
    {
        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0]
        ]);
    }

    /**
     * Add Twig Extensions.
     */
    public function onTwigInitialized()
    {
        $this->grav['twig']->twig->addFunction(new \Twig_SimpleFunction('instagram_feed', [$this, 'getFeed']));
    }

    /**
     * Add current directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * @return string the rendered instagram feed
     */
    public function getFeed($params = [])
    {
        /** @var Page $page */
        $page = $this->grav['page'];
        /** @var Data $config */
        $config = $this->mergeConfig($page, TRUE);

        /* Get an instance of the Grav cache */
        /** @var Cache $cache */
        $cache = $this->grav['cache'];

        // Generate API url
        $instagram = new InstagramBasicDisplay($config->get('feed_parameters.access_token'));
        $numPosts = $config->get('feed_parameters.count');
        $cacheKey = 'instagram-' . md5($config->get('feed_parameters.access_token'));

        $template_vars = $cache->fetch($cacheKey);
        
        // Get the results from the live API, cached version not found
        if (!$template_vars) {
            try {
                $results = $instagram->getUserMedia("me", $numPosts);
            } catch (\RuntimeException $e) {
                $this->grav['log']->error($e->getMessage());
                return;
            }

            $posts = [];

            if (isset($results->data)) {
                $posts = array_map(function ($i) {
                    //$i->caption;
                    if (property_exists(get_class($i), 'caption')) { $caption = $i->caption; } else { $caption = "not set"; }
                    return [
                        'caption' => $caption,
                        'media_url' => $i->media_url,
                        'permalink' => $i->permalink,
                        'timestamp' => $i->timestamp,
                        'id' => $i->id,
                        'type' => $i->media_type,
                        //'raw' => $i,
                    ];
                }, $results->data);
            } else {
                $this->grav['log']->error("No Instagram posts found.");
            }
            
            if ($this->parseResponse($posts)) { // Successfully parsed the feed
                $template_vars = [
                    'token' => $config->get('feed_parameters.access_token'),
                    'feed' => $this->feeds,
                    'count' => $config->get('feed_parameters.count'),
                    'params' => $params
                ];
                $cache->save($cacheKey, $template_vars,
                    InstagramPlugin::HOUR_IN_SECONDS * $config->get('feed_parameters.cache_time'));
            } else { // Didn't return a feed or couldn't parse what was returned
                $this->grav['log']->error("getFeed(): Could not parse feed");
                return;
            }

        }

        //$output = print_r($this->feeds);
        $output = $this->grav['twig']->twig()->render(InstagramPlugin::template_html, $template_vars);
        return $output;
    }

    private function addFeed($result) {
        foreach ($result as $key => $val) {
            if (!isset($this->feeds[$key])) {
                $this->feeds[$key] = $val;
            }
        }
        //krsort($this->feeds);
    }

    private function parseResponse($arr) {
        $r = array();

        if ((is_array($arr) || $arr instanceof \Countable) && count($arr)) {
            foreach ($arr as $key => $val) {
                $id = (int)$val['id'];

                $r[$id] = array();
                $r[$id]['image'] = $val['media_url'];
                $r[$id]['type'] = $val['type'];
                $r[$id]['link'] = $val['permalink'];
                $r[$id]['caption'] = $val['caption'];
            }
            $this->addFeed($r);
            return true;
        }

        return false; //no feed found in the json
    }
}
