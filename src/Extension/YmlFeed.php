<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.YmlFeed
 *
 * @copyright   (C) 2024 Sergey Kuznetsov. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\YmlFeed\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Factory;
use Joomla\Filesystem\Path;
use Joomla\Registry\Registry;
use Joomla\Component\Content\Administrator\Extension\ContentComponent;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\Utilities\ArrayHelper;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Date\Date;

/**
 * YML Feed plugin
 *
 * @since  1.0
 */
class YmlFeed extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    /**
     * @var string[]
     *
     * @since 4.1.0
     */
    protected const TASKS_MAP = [
        'ymlfeed' => [
            'langConstPrefix' => 'PLG_TASK_YMLFEED_CREATE',
            'form'            => 'feed',
            'method'          => 'feedCreate',
        ],
    ];

    /**
     * The root directory path
     *
     * @var    string
     * @since  4.2.0
     */
    private $rootDirectory;

    /**
     * The site directory path
     *
     * @var    string
     * @since  4.2.0
     */
    private $siteDirectory;

    /**
     * Constructor.
     *
     * @param   DispatcherInterface  $dispatcher     The dispatcher
     * @param   array                $config         An optional associative array of configuration settings
     * @param   string               $rootDirectory  The root directory
     * @param   string               $siteDirectory  The site directory
     *
     * @since   4.2.0
     */
    public function __construct(DispatcherInterface $dispatcher, array $config, string $rootDirectory, string $siteDirectory)
    {
        parent::__construct($dispatcher, $config);
        $this->rootDirectory = $rootDirectory;
        $this->siteDirectory = $siteDirectory;
    }

    /**
     * Load the language file on instantiation
     *
     * @var    boolean
     * @since  1.0
     */
    protected $autoloadLanguage = true;

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since   1.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    private function dateConvert($date)
    {
        $app = $this->getApplication();

        $timezone = new \DateTimeZone($app->get('offset', 'UTC'));
        $factory_date = Factory::getDate($date);
        $factory_date->setTimezone($timezone);
        $newDate = $factory_date->toRFC822(true); // дата в формате RFC822

        return $newDate;
    }

    private function getRevars($txt)
    {
        $plugin = PluginHelper::getPlugin('system', 'revars');

        if ($plugin) {
            $plugin->params = new Registry($plugin->params);
            $vars = (new Registry($plugin->params->get('variables')))->toArray();
            $nesting = (int) $plugin->params->get('nesting', 1);
            $allVariables = [];
            $txt_revars = '';

            if (!empty($vars)) {
                foreach ($vars as $variable) {
                    $allVariables[] = (object) $variable;
                }
            }

            $allVariables = array_reverse($allVariables);

            foreach ($allVariables as $variable) {
                $plugin->variables_prepare['keys'][]   = $variable->variable;
                $plugin->variables_prepare['values'][] = $variable->value;
            }

            $plugin->variables_all = array_reverse($allVariables);

            for ($i = 1; $i <= $nesting; $i++) {
                $txt_revars = str_replace($plugin->variables_prepare['keys'], $plugin->variables_prepare['values'], $txt);
            }

            return $txt_revars;
        } else return $txt;
    }

    function realCleanImageUrl($img)
    {
        $imgClean = HTMLHelper::cleanImageURL($img);
        if ($imgClean->url != '') $img = $imgClean->url;
        return $img;
    }

    private function setImage($image)
    {
        $sitePath = Path::check($this->siteDirectory . '/');

        $linkImg = $image;

        $absU = 0;
        // Test if this link is absolute http:// then do not change it
        $pos1 = strpos($image, 'http://');
        if ($pos1 === false) {
        } else {
            $absU = 1;
        }

        // Test if this link is absolute https:// then do not change it
        $pos2 = strpos($image, 'https://');
        if ($pos2 === false) {
        } else {
            $absU = 1;
        }

        if ($absU == 1) {
            $linkImg = $image;
        } else {
            $linkImg = $sitePath . $image;

            if ($image[0] == '/') {
                $myURI = new Uri(Uri::base(false));
                $myURI->setPath($image);
                $linkImg = $myURI->toString();
            } else {
                $linkImg = $sitePath . $image;
            }
        }

        return $linkImg;
    }

    private function itemRender($item, $city, $yearcom)
    {
        $app = $this->getApplication();
        $appParams = ComponentHelper::getParams('com_article');
        $factory = $app->bootComponent('com_content')->getMVCFactory();
        $article = $factory->createModel('Article', 'Site', ['ignore_request' => true]);
        $article->setState('params', $appParams);
        $article->setState('article.id', (int) $item->id);
        $articleItem = $article->getItem();

        $itemRating = !empty($articleItem->rating) ?: 0;
        $itemRatingCount = !empty($articleItem->rating_count) ?: 0;


        $fields = FieldsHelper::getFields('com_content.article', $item, true); // все custrom fields
        $itemCurrence = '';
        $itemPrice = '';
        $itemSalesNotes = '';
        if (!empty($fields)) {
            foreach ($fields as $field) {
                if ($field->name == 'price' && !empty($field->value)) {
                    $itemPrice = $field->value;
                    $itemCurrence = $field->note;
                }
                if ($field->name == 'salesnotes' && !empty($field->value)) {
                    $itemSalesNotes = $field->value !== 'unknow' ? $field->value : '';
                }
            }
        }

        $sitePath = Path::check($this->siteDirectory . '/');

        $itemLink = $sitePath . $item->category_route . '/' . $item->alias; // адрес канала

        $images = json_decode($item->images); // массив изображений
        $image_intro = $images->image_intro; // изображение вступительного текста
        $image_fulltext = $images->image_fulltext; // изображение полного текста
        $itemImageLink = $this->setImage($this->realCleanImageURL($image_intro ?: $image_fulltext));

        return '
        <offer id="' . $item->id . '">
            <name>' . htmlspecialchars($item->title, ENT_COMPAT, 'UTF-8', false) . '</name>
            <categoryId>' . $item->catid . '</categoryId>
            <url>' . $itemLink . '</url>
            <price from="true">' . $itemPrice . '</price>
            <currencyId>' . $itemCurrence . '</currencyId>
            <sales_notes>' . $itemSalesNotes . '</sales_notes>
            <delivery>true</delivery>
            <picture>' . $itemImageLink . '</picture>
            <description>' . htmlspecialchars(str_replace('&nbsp;', ' ', strip_tags($this->getRevars($item->introtext))), ENT_COMPAT, 'UTF-8', false) . '</description>
            <vendor>' . htmlspecialchars($item->author, ENT_COMPAT, 'UTF-8', false) . '</vendor>
            <param name="Рейтинг">' . $itemRating . '</param>
            <param name="Число отзывов">' . $itemRatingCount . '</param>'
            . $this->addInfoRender($city, $yearcom) .
            '</offer>';
    }

    private function addInfoRender($city, $yearcom)
    {
        $current_date = (new Date('now'))->format('Y'); // текущий год
        $experience = (int) $current_date - (int) $yearcom; // стаж

        return '
        <param name="Регион">' . $city . '</param>
        <param name="Годы опыта">' . $experience . '</param>
        <param name="Конверсия">1.935</param>
        <param name="Выезд на дом">да</param>
        <param name="Бригада">да</param>
        <param name="Работа по договору">да</param>
        <param name="Наличный расчет">да</param>
        <param name="Безналичный расчет">да</param>';
    }

    private function feedInfoRender($cat)
    {
        $siteName = Factory::getConfig()->get('sitename');
        $sitePath = Path::check($this->siteDirectory . '/');
        $siteEmail = Factory::getConfig()->get('mailfrom');

        return '
        <name>' . htmlspecialchars($cat['name'], ENT_COMPAT, 'UTF-8', false) . '</name>
        <company>' . $siteName . '</company>
        <url>' . $sitePath . trim($cat['link'], '/') . '</url>
        <email>' . $siteEmail . '</email>
        <description>' . htmlspecialchars(str_replace('&nbsp;', ' ', $this->getRevars($cat['description'])), ENT_COMPAT, 'UTF-8', false) . '</description>
        <currencies>
            <currency id="' . $cat['currency'] . '" rate="1"/>
        </currencies>';
    }

    private function categoryRender($catid)
    {
        $app = $this->getApplication();
        $categoryFactory = $app->bootComponent('com_content')->getCategory();
        $cat = $categoryFactory->get($catid);
        $catParentId = $cat->getParent()->id !== 'root' ? ' parentId="' . $cat->getParent()->id . '"' : '';

        return '<category id="' . $cat->id . '"' . $catParentId . '>' . $cat->title . '</category>';
    }

    private function feedRender($data)
    {
        $current_date = new Date('now');
        $city = $data['city'];
        $yearcom = $data['yearcom'];

        $categories = '';
        foreach ($data['catids'] as $catid) {
            $categories .= $this->categoryRender($catid);
        }

        $items = '';
        foreach ($data['items'] as $item) {
            $items .= $this->itemRender($item, $city, $yearcom);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>
        <yml_catalog date="' . $this->dateConvert($current_date) . '">
            <shop>'
            . $this->feedInfoRender($data) .
            '<categories>' . $categories . '</categories>
            <offers>'
            . $items .
            '</offers>
            </shop>
        </yml_catalog>
        ';
    }

    private function fileSave($data)
    {
        $path = Path::check($this->rootDirectory . 'yandex');

        if (!is_dir($path)) mkdir($path); // проверяем есть ли папка yandex, если нет, создаем ее
        if (isset($data) && !empty($data)) { // если есть данные
            file_put_contents($path  . '/' . trim($data['filename'], '/') . '.feed.xml', $this->feedRender($data)); // то записываем в файл
        }
    }

    /**
     * Plugin method for the 'onTaskYmlFeed' event.
     *
     * @param   string  $context  The context of the event
     * @param   mixed   $data     The data related to the event
     *
     * @return  void
     *
     * @since   1.0
     */
    protected function feedCreate(ExecuteTaskEvent $event): int
    {
        $app = $this->getApplication();
        $factory = $app->bootComponent('com_content')->getMVCFactory();
        $articles = $factory->createModel('Articles', 'Site', ['ignore_request' => true]);
        $appParams = ComponentHelper::getParams('com_article');
        $articles->setState('params', $appParams);
        $articles->setState('list.start', 0);
        $articles->setState('filter.published', ContentComponent::CONDITION_PUBLISHED);

        $params = new Registry($event->getArgument('params'));

        $articles->setState('list.limit', (int) $params->get('count', 0));

        $catids = $params->get('catid');
        $articles->setState('filter.category_id.include', (bool) $params->get('category_filtering_type', 1));

        if ($catids) {
            if ($params->get('show_child_category_articles', 0) && (int) $params->get('levels', 0) > 0) {
                $categories = $factory->createModel('Categories', 'Site', ['ignore_request' => true]);
                $categories->setState('params', $appParams);
                $levels = $params->get('levels', 1) ?: 9999;
                $categories->setState('filter.get_children', $levels);
                $categories->setState('filter.published', 1);
                $additional_catids = [];

                foreach ($catids as $catid) {
                    $categories->setState('filter.parentId', $catid);
                    $recursive = true;
                    $items = $categories->getItems($recursive);

                    if ($items) {
                        foreach ($items as $category) {
                            $condition = (($category->level - $categories->getParent()->level) <= $levels);

                            if ($condition) {
                                $additional_catids[] = $category->id;
                            }
                        }
                    }
                }

                $catids = array_unique(array_merge($catids, $additional_catids));
            }
            $articles->setState('filter.category_id', $catids);
        }

        $ex_or_include_articles = $params->get('ex_or_include_articles', 0);
        $filterInclude = true;
        $articlesList = [];

        $articlesListToProcess = $params->get('included_articles', '');

        if ($ex_or_include_articles === 0) {
            $filterInclude = false;
            $articlesListToProcess = $params->get('excluded_articles', '');
        }

        foreach (ArrayHelper::fromObject($articlesListToProcess) as $article) {
            $articlesList[] = (int) $article['id'];
        }

        if ($ex_or_include_articles === 1 && empty($articlesList)) {
            $filterInclude  = false;
            $articlesList[] = $currentArticleId;
        }

        if (!empty($articlesList)) {
            $articles->setState('filter.article_id', $articlesList);
            $articles->setState('filter.article_id.include', $filterInclude);
        }

        $items = $articles->getItems();

        $cat = [];
        if (
            empty($params->get('feed_name'))
            || empty($params->get('feed_link'))
            || empty($params->get('feed_description'))
        ) {
            $categoryFactory = $app->bootComponent('com_content')->getCategory();
            $cat = $categoryFactory->get($catids[0]);
        }

        $feedName = $params->get('feed_name') ?: $cat->title;
        $feedLink = $params->get('feed_link') ?: $cat->alias;
        $feedFileName = $cat->alias;
        $feedDescription = $params->get('feed_description') ?: strip_tags($cat->description);

        $data = [
            'name' => $feedName,
            'link' => $feedLink,
            'description' => $feedDescription,
            'filename' => $feedFileName,
            'currency' => $params->get('currency'),
            'city' => $params->get('city'),
            'yearcom' => $params->get('year_com'),
            'catids' => $catids,
            'items' => $items
        ];

        $this->fileSave($data);

        return TaskStatus::OK;
    }
}
