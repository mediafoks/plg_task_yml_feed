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
        $dateFactory = Factory::getDate($date);
        $dateFactory->setTimezone($timezone);
        $newDate = $dateFactory->toRFC822(true); // дата в формате RFC822

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

    private function tagRender($name, $value, $attr = '')
    {
        $attr = !empty($attr) ? ' ' . $attr : '';
        return '<' . $name . $attr . '>' . $value . '</' . $name . '>' . PHP_EOL;
    }

    private function tagParamRender($name, $value)
    {
        return '<param name="' . $name . '">' . $value . '</param>' . PHP_EOL;
    }

    private function itemRender($item, $params)
    {
        $app = $this->getApplication();
        $appParams = ComponentHelper::getParams('com_article');

        $factory = $app->bootComponent('com_content')->getMVCFactory();
        $article = $factory->createModel('Article', 'Site', ['ignore_request' => true]);
        $article->setState('params', $appParams);
        $article->setState('article.id', (int) $item->id);
        $articleItem = $article->getItem();

        $itemRating = !empty($articleItem->rating) ?: 0; // рейтинг
        $itemRatingCount = !empty($articleItem->rating_count) ?: 0; // счетчик рейтинга

        $fields = FieldsHelper::getFields('com_content.article', $item, true); // все custrom fields
        $itemCurrence = ''; // валюта
        $itemPrice = ''; // прайс
        $itemSalesNotes = ''; // цена за

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

        $itemLink = $sitePath . $item->category_route . '/' . $item->alias; // адрес фида

        $images = json_decode($item->images); // массив изображений
        $image_intro = $images->image_intro; // изображение вступительного текста
        $image_fulltext = $images->image_fulltext; // изображение полного текста
        $itemImageLink = $this->setImage($this->realCleanImageURL($image_intro ?: $image_fulltext)); // изображение фида

        $itemDescription = $item->introtext ?: $item->metadesc; // description фида

        $current_date = (new Date('now'))->format('Y'); // текущий год
        $yearcom = $params->get('year_com'); // год начала работы компании
        $experience = (int) $current_date - (int) $yearcom; // стаж

        $city = $params->get('city'); // город

        return '
        <offer id="' . $item->id . '">'
            . $this->tagRender('name', htmlspecialchars($item->title, ENT_COMPAT, 'UTF-8', false))
            . $this->tagRender('categoryId', $item->catid)
            . $this->tagRender('url', $itemLink)
            . $this->tagRender('price', $itemPrice, 'from="true"')
            . $this->tagRender('currencyId', $itemCurrence)
            . $this->tagRender('sales_notes', $itemSalesNotes)
            . $this->tagRender('delivery', true)
            . $this->tagRender('picture', $itemImageLink)
            . $this->tagRender('description', htmlspecialchars(str_replace('&nbsp;', ' ', strip_tags($this->getRevars($itemDescription))), ENT_COMPAT, 'UTF-8', false))
            . $this->tagRender('vendor', htmlspecialchars($item->author, ENT_COMPAT, 'UTF-8', false))
            . $this->tagParamRender('Рейтинг', $itemRating)
            . $this->tagParamRender('Число отзывов', $itemRatingCount)
            . $this->tagParamRender('Регион', $city)
            . $this->tagParamRender('Годы опыта', $experience)
            . $this->tagParamRender('Конверсия', $itemRatingCount)
            . $this->tagParamRender('Число отзывов', 1.935)
            . $this->tagParamRender('Выезд на дом', 'да')
            . $this->tagParamRender('Бригада', 'да')
            . $this->tagParamRender('Работа по договору', 'да')
            . $this->tagParamRender('Наличный расчет', 'да')
            . $this->tagParamRender('Безналичный расчет', 'да') .
            '</offer>';
    }

    private function feedInfoRender($data)
    {
        $siteName = Factory::getConfig()->get('sitename'); // имя сайта
        $sitePath = Path::check($this->siteDirectory . '/'); // адрес сайта
        $siteEmail = Factory::getConfig()->get('mailfrom'); // email сайта

        $app = $this->getApplication();
        $categoryFactory = $app->bootComponent('com_content')->getCategory();
        $category = $categoryFactory->get($data['catids'][0]);

        $params = $data['params'];

        $feedName = $params->get('feed_name') ?: $category->title; // имя фида
        $feedLink = $params->get('feed_link') ?: $category->alias; // ссылка на фид
        $feedDescription = $params->get('feed_description') ?: strip_tags($category->description); // описание фида
        $feedCurrency = $params->get('currency'); // валюта фида


        return $this->tagRender('name', htmlspecialchars($feedName, ENT_COMPAT, 'UTF-8', false))
            . $this->tagRender('company', $siteName)
            . $this->tagRender('url', $sitePath . trim($feedLink, '/'))
            . $this->tagRender('email', $siteEmail)
            . $this->tagRender('description', htmlspecialchars(str_replace('&nbsp;', ' ', $this->getRevars($feedDescription)), ENT_COMPAT, 'UTF-8', false)) .
            '<currencies>
            <currency id="' . $feedCurrency . '" rate="1"/>
        </currencies>';
    }

    private function categoryRender($catid)
    {
        $app = $this->getApplication();

        $categoryFactory = $app->bootComponent('com_content')->getCategory();
        $category = $categoryFactory->get($catid);
        $categoryParentId = $category->getParent()->id !== 'root' ? ' parentId="' . $category->getParent()->id . '"' : '';

        return '<category id="' . $category->id . '"' . $categoryParentId . '>' . $category->title . '</category>';
    }

    private function feedRender($data)
    {
        $current_date = new Date('now'); // текущая дата

        $params = $data['params'];

        $feedCategories = '';
        foreach ($data['catids'] as $catid) {
            $feedCategories .= $this->categoryRender($catid);
        }

        $items = '';
        foreach ($data['items'] as $item) {
            $items .= $this->itemRender($item, $params);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>
        <yml_catalog date="' . $this->dateConvert($current_date) . '">
            <shop>'
            . $this->feedInfoRender($data) .
            '<categories>' . $feedCategories . '</categories>
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

        $app = $this->getApplication();
        $categoryFactory = $app->bootComponent('com_content')->getCategory();
        $category = $categoryFactory->get($data['catids'][0]);

        if (!is_dir($path)) mkdir($path); // проверяем есть ли папка yandex, если нет, создаем ее
        if (isset($data) && !empty($data)) { // если есть данные
            file_put_contents($path  . '/' . trim($category->alias, '/') . '.feed.xml', $this->feedRender($data)); // то записываем в файл
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

        $data = [
            'catids' => $catids,
            'items' => $items,
            'params' => $params
        ];

        $this->fileSave($data);

        return TaskStatus::OK;
    }
}
