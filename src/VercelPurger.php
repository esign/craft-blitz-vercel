<?php

namespace esign\craftblitzvercel;

use Craft;
use craft\base\Event;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\events\RegisterTemplateRootsEvent;
use craft\helpers\App;
use craft\web\View;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\drivers\purgers\BaseCachePurger;
use putyourlightson\blitz\events\RefreshCacheEvent;
use putyourlightson\blitz\helpers\CachePurgerHelper;
use putyourlightson\blitz\helpers\SiteUriHelper;
use yii\log\Logger;

class VercelPurger extends BaseCachePurger
{
    public string $bypassToken = '';

    public static function displayName(): string
    {
        return Craft::t('blitz', 'Vercel Purger');
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('blitz-vercel/settings', [
            'purger' => $this,
        ]);
    }

    public function init(): void
    {
        Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['blitz-vercel'] = __DIR__ . '/templates/';
            }
        );
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['parser'] = [
            'class' => EnvAttributeParserBehavior::class,
            'attributes' => [
                'bypassToken',
            ],
        ];

        return $behaviors;
    }

    public function attributeLabels(): array
    {
        return [
            'bypassToken' => Craft::t('blitz', 'Bypass Token'),
        ];
    }

    public function rules(): array
    {
        return [
            [['bypassToken'], 'required'],
        ];
    }

    public function purgeUris(array $siteUris, callable $setProgressHandler = null, bool $queue = true): void
    {
        $event = new RefreshCacheEvent(['siteUris' => $siteUris]);
        $this->trigger(self::EVENT_BEFORE_PURGE_CACHE, $event);

        if (!$event->isValid) {
            return;
        }

        if ($queue) {
            CachePurgerHelper::addPurgerJob($siteUris, 'purgeUrisWithProgress');
        } else {
            $this->purgeUrisWithProgress($siteUris, $setProgressHandler);
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_PURGE_CACHE)) {
            $this->trigger(self::EVENT_AFTER_PURGE_CACHE, $event);
        }
    }

    public function purgeSite(int $siteId, callable $setProgressHandler = null, bool $queue = true): void
    {
        $this->purgeUris(SiteUriHelper::getSiteUrisForSite($siteId), $setProgressHandler, $queue);
    }

    public function purgeAll(callable $setProgressHandler = null, bool $queue = true): void
    {
        $event = new RefreshCacheEvent();
        $this->trigger(self::EVENT_BEFORE_PURGE_ALL_CACHE, $event);

        if (!$event->isValid) {
            return;
        }

        $sites = Craft::$app->getSites()->getAllSites();

        foreach ($sites as $site) {
            $this->purgeSite($site->id, $setProgressHandler, $queue);
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_PURGE_ALL_CACHE)) {
            $this->trigger(self::EVENT_AFTER_PURGE_ALL_CACHE, $event);
        }
    }

    public function purgeUrisWithProgress(array $siteUris, callable $setProgressHandler = null): void
    {
        $count = 0;
        $total = count($siteUris);
        $label = 'Purging {total} pages.';

        if (is_callable($setProgressHandler)) {
            $progressLabel = Craft::t('blitz', $label, ['total' => $total]);
            call_user_func($setProgressHandler, $count, $total, $progressLabel);
        }

        $groupedSiteUris = SiteUriHelper::getSiteUrisGroupedBySite($siteUris);

        foreach ($groupedSiteUris as $siteId => $siteUriGroup) {
            $this->_sendRequest($siteId,
                SiteUriHelper::getUrlsFromSiteUris($siteUriGroup)
            );

            $count = $count + count($groupedSiteUris);

            if (is_callable($setProgressHandler)) {
                $progressLabel = Craft::t('blitz', $label, ['total' => $total]);
                call_user_func($setProgressHandler, $count, $total, $progressLabel);
            }
        }
    }

    private function _sendRequest($siteId, array $urls = []): bool
    {
        if (empty($urls)) {
            return false;
        }

        $requests = [];
        $response = false;
        $batches = array_chunk($urls, 25);
        
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $client = Craft::createGuzzleClient([
            'base_uri' => $site->getBaseUrl(),
        ]);

        // Build request array
        foreach ($batches as $batch) {
            foreach ($batch as $uri) {
                $requests[] = new Request('HEAD', $uri, [
                    'x-prerender-revalidate' => App::parseEnv($this->bypassToken),
                ]);
            }
        }

        // Create a pool of requests
        $pool = new Pool($client, $requests, [
            'fulfilled' => function() use (&$response) {
                $response = true;
            },
            'rejected' => function(RequestException $reason) {
                preg_match('/^(.*?)\R/', $reason->getMessage(), $matches);
                if (!empty($matches[1])) {
                    Blitz::getInstance()->log(
                        trim($matches[1], ':'),
                        [],
                        Logger::LEVEL_ERROR
                    );
                }
            },
        ]);

        // Initiate the transfers and wait for the pool of requests to complete
        $pool->promise()->wait();

        return $response;
    }
}
