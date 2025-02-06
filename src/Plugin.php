<?php

namespace esign\craftblitzvercel;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use esign\craftblitzvercel\models\Settings;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\web\View;
use putyourlightson\blitz\helpers\CachePurgerHelper;
use yii\base\Event;
/**
 * Blitz Vercel Purger plugin
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @author dieter vanhove <support@esign.eu>
 * @copyright dieter vanhove
 * @license MIT
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();
    }

    public static function displayName(): string
    {
        return Craft::t('blitz', 'Vercel Cache Purger');
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('blitz-vercel/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            CachePurgerHelper::class,
            CachePurgerHelper::EVENT_REGISTER_PURGER_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = VercelCachePurger::class;
            }
        );
    }
}
