<?php

namespace oberon\previewplugin;

use Craft;
use yii\base\Event;
use craft\controllers\EntriesController;
use craft\events\ElementEvent;
use craft\elements\db\ElementQuery;
use craft\events\PopulateElementEvent;
use craft\elements\Entry;
use craft\elements\db\EntryQuery;
use craft\helpers\Json;
use craft\models\EntryVersion;
use craft\web\View;
use craft\events\TemplateEvent;
use craft\base\Plugin;

class PreviewPlugin extends Plugin
{

    // Static Properties
    // =========================================================================

    /**
     * @var PreviewPlugin
     */
    public static $instance;

    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public $schemaVersion = '1.0.0';

    // Public Methods
    // =========================================================================

    public function __construct($id, $parent = null, array $config = [])
    {
        Craft::setAlias('@modules/preview', $this->getBasePath());

        // Set this as the global instance of this module class
        static::setInstance($this);

        parent::__construct($id, $parent, $config);
    }

    public function init()
    {
        parent::init();
        self::$instance = $this;

        if (Craft::$app->request->getIsLivePreview()) {
            Event::on(
                EntriesController::class,
                EntriesController::EVENT_PREVIEW_ENTRY,
                function (ElementEvent $event) {
                    $entry = $event->element;
                    $previewId = uniqid();
                    Craft::$app->cache->set('headless-preview-' . $previewId, $entry, 180);

                    echo '<iframe onload="this.style.backgroundImage = \'none\'" style="width: 100%; height: 100%; border: none; position: absolute; top: 0; left: 0; background-image: url(https://media2.giphy.com/media/3oEjI6SIIHBdRxXI40/giphy.gif); background-repeat: no-repeat; background-position: center center;" src="' . $entry->getUrl() . '?draftId=' . $previewId . '"></iframe>';
                    exit;
                }
            );
        }

        $draftId = Craft::$app->request->headers->get('X-Craft-DraftId');
        if ($draftId) {
            $requestedDraft = Craft::$app->cache->get('headless-preview-' . $draftId);
            Event::on(
                ElementQuery::class,
                ElementQuery::EVENT_AFTER_POPULATE_ELEMENT,
                function (PopulateElementEvent $event) use ($requestedDraft) {
                    if ($event->element instanceof Entry && $requestedDraft) {
                        if ($requestedDraft->getId() == $event->element->getId()
                            && $requestedDraft->siteId == $event->element->siteId) {
                            $standardFields = ['id', 'author', 'title', 'slug', 'expiryDate', 'postDate', 'uri', 'structureId'];
                            foreach ($standardFields as $field) {
                                $event->element->$field = $requestedDraft->$field;
                            }
                            if(isset($requestedDraft->newParent) && $requestedDraft->newParent != $event->element->getParent()->getId()){
                                $event->element->setParent($requestedDraft->newParent);
                            }
                            $event->element->setFieldValues($requestedDraft->getFieldValues());
                        }
                    }
                }
            );
        }

        Event::on(
            ElementQuery::class,
            ElementQuery::EVENT_BEFORE_PREPARE,
            function ($event) {
                if ($event->sender instanceof EntryQuery) {
                    $event->sender->anyStatus();
                }
            }
        );

        Craft::$app->view->hook('cp.entries.edit.details', function (array $context) {
            $entry = $context['entry'];

            if ($entry instanceof Entry) {
                Craft::$app->view->registerJs('Craft.LivePreview.init(' . Json::encode([
                        'fields' => '#title-field, #fields > div > div > .field',
                        'extraFields' => '#settings',
                        'previewUrl' => '/',
                        'previewAction' => 'entries/preview-entry',
                        'previewParams' => [
                            'sectionId' => $entry->getSection()->id,
                            'entryId' => $entry->id,
                            'siteId' => $entry->siteId,
                            'versionId' => $entry instanceof EntryVersion ? $entry->versionId : null,
                        ]
                    ]) . ');');
            }
        });

        Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function (TemplateEvent $event) {
            $event->variables['showPreviewBtn'] = true;
        });

        Craft::info(
            Craft::t(
                'preview-plugin',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }
}