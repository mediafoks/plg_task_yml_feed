[![GitHub Release](https://img.shields.io/github/v/release/mediafoks/plg_task_yml_feed?display_name=release&style=flat-square&color=blue)](https://github.com/mediafoks/plg_task_yml_feed/releases)
[![Static Badge](https://img.shields.io/badge/Joomla-5-orange?style=flat-square&logo=joomla&logoColor=white)](https://github.com/joomla/joomla-cms) ![Static Badge](https://img.shields.io/badge/type-plugin-yellow?style=flat-square) ![Static Badge](https://img.shields.io/badge/group-task-violet?style=flat-square)

# YML-фид

Планировщик задач (WebCron) для Joomla 5.\
Генерирует YML-фид из материалов в выбранных категориях в формате XML. \
Возможно указать собственное имя фида, описание и ссылку на фид. В случае, если поля оставить пустыми, информация автоматически подгрузится из категории. Возможно указать сразу несколько категорий, учитывать подкатегории, а также удалять отдельные материалы из генерации. На данный момент поддерживаетя кастомное поле материала для формирования цены с алиасом price. \
Сгенерированный файл будет находится в папке `media/yandex/my-file.feed.yml` \
Поддерживает переменные плагина [Revars](https://github.com/Delo-Design/revars 'Revars').

Для того, чтобы выводилась цена, в материалах должно присутствовать кастомное поле с алиасом `price`, в примечании к полю _(находится в настройках поля, в правой колонке, внизу)_ указать код валюты, например `RUB`. \
При необходимости, для тега `sales_notes` в фиде можно создать кастомное поле типа `список` с алиасом `salesnotes` и задать опции, например `за штуку, за услугу, за комплект...`

---

Плагин делал исключительно под свои нужды, и не претендует на истину в последней инстанции :), если вам не хватает каких-то полей или функций, придется править код самим или пишите.
