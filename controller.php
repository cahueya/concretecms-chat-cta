<?php
namespace Concrete\Package\ChatCta;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\ResponseFactoryInterface;
use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Single as SinglePage;
use Symfony\Component\HttpFoundation\Request;
use Concrete\Core\Routing\Router;
use Concrete\Core\Support\Facade\Events;
use Concrete\Core\View\View;

class Controller extends Package
{
    protected $pkgHandle = 'chat_cta';
    protected $appVersionRequired = '9.0.0';
    protected $pkgVersion = '1.0.0';
    protected $pkgAutoloaderRegistries = [
        'src' => '\\Concrete\\Package\\ChatCta\\Src',
    ];

    public function getPackageName()
    {
        return t('Chat CTA');
    }

    public function getPackageDescription()
    {
        return t('Adds a global chat call-to-action with random selection from multiple active contacts. Currently supports WhatsApp links.');
    }

    public function install()
    {
        $pkg = parent::install();
        $this->installOrUpgrade($pkg);
        return $pkg;
    }


    public function uninstall()
    {
        parent::uninstall();

        $this->dropPackageTables();
    }

    protected function dropPackageTables(): void
    {
        try {
            $db = $this->app->make(Connection::class);

            // Current package tables plus legacy table from earlier tracking versions.
            // Use plain DROP TABLE IF EXISTS for broad ConcreteCMS/MySQL compatibility.
            foreach (['ChatCtaClicks', 'ChatCtaNumbers', 'ChatCtaSettings'] as $table) {
                try {
                    $db->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
                } catch (\Throwable $e) {
                    // Continue trying to remove the remaining package tables.
                }
            }
        } catch (\Throwable $e) {
            // Never prevent package uninstall because cleanup failed.
        }
    }

    public function upgrade()
    {
        // Some ConcreteCMS 9.x installations may still have a stale route/controller
        // target from a previous package version while the package is being upgraded.
        // That can throw "Target class [] does not exist" from inside parent::upgrade().
        // The actual package migration below is still safe to run, and fresh runtime
        // routes are registered only in on_start().
        try {
            parent::upgrade();
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'Target class [] does not exist') === false) {
                throw $e;
            }
        }

        $pkg = Package::getByHandle($this->pkgHandle);
        $this->installOrUpgrade($pkg ?: $this);
    }

    protected function installOrUpgrade($pkg): void
    {
        $page = SinglePage::add('/dashboard/system/chat_cta', $pkg);
        if ($page) {
            $page->update(['cName' => t('Chat CTA'), 'cDescription' => t('Manage global Chat CTA settings and contact numbers.')]);
        }

        $db = $this->app->make(Connection::class);
        $this->ensureNumberColumns($db);
        $this->saveDefaultSetting($db, 'enabled', '1');
        $this->saveDefaultSetting($db, 'default_message', 'Hello, I would like to ask a question.');
        $this->saveDefaultSetting($db, 'button_label', 'Chat with us');
        $this->saveDefaultSetting($db, 'position', 'bottom-right');
        $this->saveDefaultSetting($db, 'button_color', '#25D366');
    }

    protected function ensureNumberColumns(Connection $db): void
    {
        try {
            $columns = array_map('strtolower', $db->fetchFirstColumn('SHOW COLUMNS FROM ChatCtaNumbers'));
            if (!in_array('clicks', $columns, true)) {
                $db->executeStatement('ALTER TABLE ChatCtaNumbers ADD clicks INT NOT NULL DEFAULT 0');
            }
            if (!in_array('lastclicked', $columns, true)) {
                $db->executeStatement('ALTER TABLE ChatCtaNumbers ADD lastClicked DATETIME DEFAULT NULL');
            }
        } catch (\Throwable $e) {
            // The table may not exist yet, or the database driver may not support SHOW COLUMNS during install.
        }

        try {
            // Migrate legacy detailed click rows into the per-number counter, if a previous version created them.
            $rows = $db->fetchAllAssociative('SELECT numberId, COUNT(*) AS total, MAX(clickedAt) AS lastClicked FROM ChatCtaClicks GROUP BY numberId');
            foreach ($rows as $row) {
                $numberId = (int) ($row['numberId'] ?? 0);
                if ($numberId > 0) {
                    $db->update('ChatCtaNumbers', [
                        'clicks' => (int) ($row['total'] ?? 0),
                        'lastClicked' => $row['lastClicked'] ?: null,
                    ], ['id' => $numberId]);
                }
            }
        } catch (\Throwable $e) {
            // No legacy click table, or no legacy data to migrate.
        }
    }

    protected function saveDefaultSetting(Connection $db, string $key, string $value): void
    {
        try {
            $exists = $db->fetchOne('SELECT settingValue FROM ChatCtaSettings WHERE settingKey = ?', [$key]);
            if ($exists === false || $exists === null) {
                $db->insert('ChatCtaSettings', ['settingKey' => $key, 'settingValue' => $value]);
            }
        } catch (\Throwable $e) {
            // The table may not exist yet during early install stages.
        }
    }

    public function on_start()
    {
        $router = $this->app->make(Router::class);
        $pkg = $this;

        // Use a closure route instead of a controller target string. This avoids
        // container resolution errors such as "Target class [] does not exist"
        // on some ConcreteCMS 9.x installations.
        $router->register('/chat-cta/random', function () use ($pkg) {
            return $pkg->randomNumberResponse();
        });

        Events::addListener('on_before_render', function () {
            $this->injectGlobalCta();
        });
    }

    public function randomNumberResponse()
    {
        $db = $this->app->make(Connection::class);
        $request = $this->app->make(Request::class);
        $responseFactory = $this->app->make(ResponseFactoryInterface::class);
        $settings = $this->getSettings($db);

        if (empty($settings['enabled'])) {
            return $responseFactory->json(['success' => false, 'error' => 'disabled'], 403);
        }

        $numbers = $db->fetchAllAssociative('SELECT * FROM ChatCtaNumbers WHERE isActive = 1 ORDER BY sortOrder ASC, id ASC');
        if (!$numbers) {
            return $responseFactory->json(['success' => false, 'error' => 'no_active_numbers'], 404);
        }

        $selected = $this->weightedRandom($numbers);
        $phone = preg_replace('/[^0-9]/', '', (string) $selected['phone']);
        if ($phone === '') {
            return $responseFactory->json(['success' => false, 'error' => 'invalid_number'], 500);
        }

        $message = trim((string) $request->query->get('message', ''));
        if ($message === '') {
            $message = (string) $settings['default_message'];
        }

        try {
            $db->executeStatement('UPDATE ChatCtaNumbers SET clicks = COALESCE(clicks, 0) + 1, lastClicked = ? WHERE id = ?', [
                date('Y-m-d H:i:s'),
                (int) $selected['id'],
            ]);
        } catch (\Throwable $e) {
            // Click counting must never prevent opening the chat link.
        }

        return $responseFactory->json([
            'success' => true,
            'phone' => $phone,
            'label' => (string) $selected['label'],
            'url' => 'https://wa.me/' . $phone . ($message !== '' ? '?text=' . rawurlencode($message) : ''),
        ]);
    }

    protected function weightedRandom(array $numbers): array
    {
        $total = 0;
        foreach ($numbers as $number) {
            $total += max(1, (int) $number['weight']);
        }
        $pick = random_int(1, max(1, $total));
        $running = 0;
        foreach ($numbers as $number) {
            $running += max(1, (int) $number['weight']);
            if ($pick <= $running) {
                return $number;
            }
        }
        return $numbers[array_key_first($numbers)];
    }

    public function injectGlobalCta(): void
    {
        try {
            $c = Page::getCurrentPage();
            if (!is_object($c) || $c->isError()) {
                return;
            }

            if (method_exists($c, 'isAdminArea') && $c->isAdminArea()) {
                return;
            }

            if (method_exists($c, 'isEditMode') && $c->isEditMode()) {
                return;
            }

            $path = (string) $c->getCollectionPath();
            if ($path === '/dashboard' || strpos($path, '/dashboard/') === 0) {
                return;
            }

            $db = $this->app->make(Connection::class);
            $settings = $this->getSettings($db);
            if (empty($settings['enabled'])) {
                return;
            }

            $activeNumberCount = (int) $db->fetchOne('SELECT COUNT(*) FROM ChatCtaNumbers WHERE isActive = 1');
            if ($activeNumberCount < 1) {
                return;
            }

            $view = View::getInstance();
            if (!is_object($view)) {
                return;
            }

            $view->addHeaderItem($this->getInlineCss($settings));
            $view->addFooterItem($this->getButtonHtml($settings));
            $view->addFooterItem($this->getInlineJs());
        } catch (\Throwable $e) {
            // Never break page rendering because of the CTA.
        }
    }

    protected function getSettings(Connection $db): array
    {
        try {
            $rows = $db->fetchAllKeyValue('SELECT settingKey, settingValue FROM ChatCtaSettings');
        } catch (\Throwable $e) {
            $rows = [];
        }

        return [
            'enabled' => isset($rows['enabled']) ? (bool) (int) $rows['enabled'] : true,
            'default_message' => (string) ($rows['default_message'] ?? 'Hello, I would like to ask a question.'),
            'button_label' => (string) ($rows['button_label'] ?? 'Chat with us'),
            'position' => in_array(($rows['position'] ?? 'bottom-right'), ['bottom-right', 'bottom-left'], true) ? $rows['position'] : 'bottom-right',
            'button_color' => (string) ($rows['button_color'] ?? '#25D366'),
        ];
    }

    protected function getButtonHtml(array $settings): string
    {
        $id = 'chat-cta-global';
        $position = $settings['position'] === 'bottom-left' ? 'bottom-left' : 'bottom-right';
        $label = h($settings['button_label'] ?: t('Chat with us'));
        $message = h((string) $settings['default_message']);
        $randomUrl = h((string) $this->app->make('url/manager')->resolve(['/chat-cta/random']));

        return '<button id="' . $id . '" class="chat-cta chat-cta--' . h($position) . '" data-message="' . $message . '" data-random-url="' . $randomUrl . '" type="button" aria-label="' . $label . '"><i class="fab fa-whatsapp" aria-hidden="true"></i><span class="chat-cta__label">' . $label . '</span></button>';
    }

    protected function getInlineCss(array $settings): string
    {
        $color = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $settings['button_color']) ? $settings['button_color'] : '#25D366';
        return '<style id="chat-cta-global-css">
.chat-cta{--chat-cta-color:' . h($color) . ';position:fixed;z-index:1050;bottom:24px;display:inline-flex;align-items:center;gap:.55rem;border:0;border-radius:999px;background:var(--chat-cta-color);color:#fff;padding:13px 18px;font-size:16px;line-height:1.2;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.2);cursor:pointer;text-decoration:none;transition:transform .15s ease,box-shadow .15s ease}.chat-cta:hover,.chat-cta:focus{transform:translateY(-1px);box-shadow:0 10px 28px rgba(0,0,0,.24);color:#fff}.chat-cta--bottom-right{right:24px}.chat-cta--bottom-left{left:24px}.chat-cta .fab{font-size:22px;line-height:1}@media(max-width:640px){.chat-cta{bottom:16px;right:16px;left:auto;padding:13px 15px}.chat-cta--bottom-left{left:16px;right:auto}.chat-cta__label{display:none}.chat-cta .fab{font-size:25px}}</style>';
    }

    protected function getInlineJs(): string
    {
        return '<script id="chat-cta-global-js">
(function(){
  function ready(fn){if(document.readyState!=="loading"){fn();}else{document.addEventListener("DOMContentLoaded",fn);}}
  ready(function(){
    var btn=document.getElementById("chat-cta-global");
    if(!btn){return;}
    btn.addEventListener("click",function(){
      var endpoint=btn.getAttribute("data-random-url")||"/chat-cta/random";
      var message=btn.getAttribute("data-message")||"";
      var url=endpoint+(endpoint.indexOf("?")===-1?"?":"&")+"message="+encodeURIComponent(message)+"&referrer="+encodeURIComponent(window.location.href);
      btn.disabled=true;
      fetch(url,{credentials:"same-origin",headers:{"Accept":"application/json"}}).then(function(r){return r.json();}).then(function(data){
        if(data&&data.success&&data.url){window.open(data.url,"_blank","noopener");}
      }).catch(function(){}).finally(function(){btn.disabled=false;});
    });
  });
})();
</script>';
    }
}
