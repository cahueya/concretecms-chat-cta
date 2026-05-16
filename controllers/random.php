<?php
namespace Concrete\Package\ChatCta\Controller;

use Concrete\Core\Controller\Controller;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\ResponseFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

class Random extends Controller
{
    public function random()
    {
        $app = app();
        $db = $app->make(Connection::class);
        $request = $app->make(Request::class);
        $responseFactory = $app->make(ResponseFactoryInterface::class);
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

    protected function getSettings(Connection $db): array
    {
        try {
            $rows = $db->fetchAllKeyValue('SELECT settingKey, settingValue FROM ChatCtaSettings');
        } catch (\Throwable $e) {
            $rows = [];
        }
        return [
            'enabled' => isset($rows['enabled']) ? (bool) (int) $rows['enabled'] : true,
            'default_message' => (string) ($rows['default_message'] ?? ''),
        ];
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
}
