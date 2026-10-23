<?php

declare(strict_types=1);

namespace viesrood\cachekit;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\utilities\ClearCaches;
use craft\web\Response;
use craft\web\twig\variables\CraftVariable;
use viesrood\cachekit\models\Settings;
use viesrood\cachekit\services\CacheService;
use viesrood\cachekit\services\InvalidationService;
use viesrood\cachekit\services\RequestService;
use viesrood\cachekit\services\SnapshotService;
use viesrood\cachekit\utilities\CacheUtility;
use viesrood\cachekit\variables\CacheKitVariable;
use yii\base\Event;

/**
 * @property-read CacheService $cache
 * @property-read InvalidationService $invalidation
 * @property-read RequestService $requestCache
 * @property-read SnapshotService $snapshot
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'cache' => CacheService::class,
                'invalidation' => InvalidationService::class,
                'requestCache' => RequestService::class,
                'snapshot' => SnapshotService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'viesrood\\cachekit\\console\\controllers';
        }

        $this->registerVariable();
        $this->registerResponseCaching();
        $this->registerEntryInvalidation();
        $this->registerUtility();
        $this->registerPermissions();
        $this->registerClearCaches();

        Craft::$app->onAfterRequest(fn() => $this->getInvalidation()->flush());
    }

    public function getCache(): CacheService
    {
        /** @var CacheService */
        return $this->get('cache');
    }

    public function getInvalidation(): InvalidationService
    {
        /** @var InvalidationService */
        return $this->get('invalidation');
    }

    public function getRequestCache(): RequestService
    {
        /** @var RequestService */
        return $this->get('requestCache');
    }

    public function getSnapshot(): SnapshotService
    {
        /** @var SnapshotService */
        return $this->get('snapshot');
    }

    public function getSettings(): Settings
    {
        /** @var Settings */
        return parent::getSettings();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        $sections = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $sections[] = ['label' => $section->name, 'value' => $section->handle];
        }

        return Craft::$app->getView()->renderTemplate('cachekit/settings', [
            'settings' => $this->getSettings(),
            'sectionOptions' => $sections,
        ]);
    }

    private function registerVariable(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, static function(Event $event): void {
            $event->sender->set('cachekit', CacheKitVariable::class);
        });
    }

    private function registerResponseCaching(): void
    {
        Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function(Event $event): void {
            if ($event->sender instanceof Response) {
                $this->getRequestCache()->cacheResponse($event->sender);
            }
        });
    }

    private function registerEntryInvalidation(): void
    {
        Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, function(ElementEvent $event): void {
            if ($event->element instanceof Entry) {
                $this->getInvalidation()->rememberOldUri($event->element);
            }
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event): void {
            if ($event->element instanceof Entry) {
                $this->getInvalidation()->invalidate($event->element);
            }
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, function(ElementEvent $event): void {
            if ($event->element instanceof Entry) {
                $this->getInvalidation()->invalidate($event->element, true);
            }
        });
    }

    private function registerUtility(): void
    {
        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, static function(RegisterComponentTypesEvent $event): void {
            $event->types[] = CacheUtility::class;
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, static function(RegisterUserPermissionsEvent $event): void {
            $event->permissions[] = [
                'heading' => 'CacheKit',
                // Bekijken loopt via Craft's eigen utility:cachekit-permissie.
                'permissions' => [
                    'cachekit:manage' => ['label' => 'Cache wissen en verversen'],
                    'cachekit:clearAll' => ['label' => 'Volledige cache legen'],
                    'cachekit:snapshot' => ['label' => 'Noodkopie (snapshot) bouwen'],
                ],
            ];
        });
    }

    private function registerClearCaches(): void
    {
        Event::on(ClearCaches::class, ClearCaches::EVENT_REGISTER_CACHE_OPTIONS, function(RegisterCacheOptionsEvent $event): void {
            $event->options[] = [
                'key' => 'cachekit',
                'label' => 'CacheKit paginacache',
                'action' => fn() => $this->getCache()->clearAll(),
            ];
        });
    }
}
