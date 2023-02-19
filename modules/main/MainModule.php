<?php

namespace modules\main;

use Craft;
use craft\base\Element;
use craft\elements\Entry;
use craft\events\BlockTypesEvent;
use craft\events\ElementEvent;
use craft\events\ModelEvent;
use craft\fields\Matrix;
use craft\helpers\ElementHelper;
use craft\services\Elements;
use modules\base\BaseModule;
use modules\main\behaviors\EntryBehavior;
use modules\main\conditions\HasDraftsConditionRule;
use modules\main\fields\EnvironmentVariableField;
use modules\main\fields\IncludeField;
use modules\main\fields\SiteField;
use modules\main\resources\CpAssetBundle;
use modules\main\services\EntriesService;
use modules\main\twigextensions\TwigExtension;
use modules\main\validators\BodyContentValidator;
use modules\main\widgets\MyProvisionalDraftsWidget;
use yii\base\Event;
use function in_array;

/**
 * @property-read EntriesService $entriesService
 */
class MainModule extends BaseModule
{

    public $handle = 'main';

    public function init(): void
    {

        parent::init();

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function() {
            $this->attachEventHandlers();
        });
    }

    private function attachEventHandlers(): void
    {
        // Register event handlers here ...
        // (see https://craftcms.com/docs/4.x/extend/events.html to get started)

        $this->registerServices([
            'entriesService' => EntriesService::class
        ]);

        $this->registerTranslationCategory();

        $this->registerBehaviors(Entry::class, [
            EntryBehavior::class
        ]);

        $this->registerFieldTypes([
            SiteField::class,
            EnvironmentVariableField::class,
            IncludeField::class
        ]);

        $this->registerTwigExtensions([
            TwigExtension::class
        ]);

        if (Craft::$app->request->isCpRequest) {
            $this->registerTemplateRoots(false, true);

            $this->registerConditionRuleTypes([
                HasDraftsConditionRule::class,
            ]);

            $this->registerWidgetTypes([
                MyProvisionalDraftsWidget::class,
            ]);

            $this->restrictSearchIndex();

            $this->validateAllSites();

            $this->createHooks();

            $this->registerAssetBundles([
                CpAssetBundle::class
            ]);

            $this->registerEntryValidators([
                [['bodyContent'], BodyContentValidator::class, 'on' => [Element::SCENARIO_LIVE]]
            ]);


            $this->hideBlockTypes();

            $this->updateFrontPages();
        }
    }

    protected function validateAllSites()
    {
        // Validate entries on all sites (fixes open Craft bug)
        Event::on(
            Entry::class,
            Entry::EVENT_BEFORE_SAVE, function($event): void {

            if (Craft::$app->sites->getTotalSites() === 1) {
                return;
            }

            /** @var Entry $entry */
            $entry = $event->sender;

            // TODO: Check conditionals

            if ($entry->resaving || $entry->propagating || $entry->getScenario() != Entry::STATUS_LIVE) {
                return;
            }

            $entry->validate();

            if ($entry->hasErrors()) {
                return;
            }

            foreach ($entry->getLocalized()->all() as $localizedEntry) {
                $localizedEntry->scenario = Entry::SCENARIO_LIVE;

                if (!$localizedEntry->validate()) {
                    $entry->addError(
                        $entry->getType()->hasTitleField ? 'title' : 'slug',
                        Craft::t('site', 'Error validating entry in') .
                        ' "' . $localizedEntry->site->name . '". ' .
                        implode(' ', $localizedEntry->getErrorSummary(false)));
                    $event->isValid = false;
                }
            }
        });
    }


    protected function restrictSearchIndex()
    {
        // Don't update search index for drafts
        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_UPDATE_SEARCH_INDEX,
            function(ElementEvent $event) {
                if (ElementHelper::isDraftOrRevision($event->element)) {
                    $event->isValid = false;
                }
            }
        );
    }

    protected function createHooks()
    {
        // Prevent password managers like Bitdefender Wallet from falsely inserting credentials into user form
        Craft::$app->view->hook('cp.users.edit.content', function(array &$context) {
            return '<input type="text" name="dummy-first-name" value="wtf" style="display: none">';
        });
    }

    protected function hideBlockTypes()
    {
        // Hide bodyContent block types not relevant for the current entry
        Event::on(
            Matrix::class,
            Matrix::EVENT_SET_FIELD_BLOCK_TYPES,
            function(BlockTypesEvent $event) {
                if (!$event->element instanceof Entry || $event->sender->handle !== 'bodyContent') {
                    return;
                }

                $entry = $event->element;

                // TODO: Make that configurable
                if ($entry->section->handle !== 'page' || in_array($entry->type->handle, ['faqs', 'sitemap'])) {
                    foreach ($event->blockTypes as $i => $blockType) {
                        if (in_array($blockType->handle, ['dynamicBlock', 'contentComponents'])) {
                            unset($event->blockTypes[$i]);
                        }
                    }
                }
            });
    }

    /**
     * PROVISIONAL
     *
     * Update sitemap frontpages when contant has possibly changed
     *
     * @return void
     */
    protected function updateFrontPages()
    {
        Event::on(
            Entry::class,
            Entry::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                /** @var Entry $entry */
                $entry = $event->sender;
                if (
                    !$event->sender->resaving &&
                    $entry->scenario === Element::SCENARIO_LIVE &&
                    ($event->sender->enabled && $event->sender->getEnabledForSite()) &&
                    !ElementHelper::isDraftOrRevision($entry)
                ) {
                    $this->entriesService->updateFrontPages($entry);
                }
            }
        );
    }
}
