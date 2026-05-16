<?php
namespace Concrete\Package\ChatCta\Controller\SinglePage\Dashboard\System;

use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\Database\Connection\Connection;

class ChatCta extends DashboardPageController
{
    public function view()
    {
        $db = $this->app->make(Connection::class);

        if ($this->request->isMethod('POST')) {
            $token = $this->app->make('token');
            if (!$token->validate('save_chat_cta')) {
                $this->error->add($token->getErrorMessage());
            } else {
                $this->savePost($db);
                $this->flash('success', t('Chat CTA settings saved.'));
                return $this->buildRedirect($this->action(''));
            }
        }

        $numbers = $db->fetchAllAssociative('SELECT * FROM ChatCtaNumbers ORDER BY sortOrder ASC, id ASC');
        $this->set('numbers', $numbers);
        $this->set('settings', $this->getSettings($db));
    }

    protected function getSettings(Connection $db): array
    {
        $rows = $db->fetchAllKeyValue('SELECT settingKey, settingValue FROM ChatCtaSettings');
        return [
            'enabled' => isset($rows['enabled']) ? (bool) (int) $rows['enabled'] : true,
            'default_message' => (string) ($rows['default_message'] ?? 'Hello, I would like to ask a question.'),
            'button_label' => (string) ($rows['button_label'] ?? 'Chat with us'),
            'position' => in_array(($rows['position'] ?? 'bottom-right'), ['bottom-right', 'bottom-left'], true) ? $rows['position'] : 'bottom-right',
            'button_color' => (string) ($rows['button_color'] ?? '#25D366'),
        ];
    }

    protected function saveSetting(Connection $db, string $key, string $value): void
    {
        $exists = $db->fetchOne('SELECT settingKey FROM ChatCtaSettings WHERE settingKey = ?', [$key]);
        if ($exists) {
            $db->update('ChatCtaSettings', ['settingValue' => $value], ['settingKey' => $key]);
        } else {
            $db->insert('ChatCtaSettings', ['settingKey' => $key, 'settingValue' => $value]);
        }
    }

    protected function savePost(Connection $db): void
    {
        $this->saveSetting($db, 'enabled', $this->request->request->has('enabled') ? '1' : '0');
        $this->saveSetting($db, 'default_message', trim((string) $this->post('default_message')));
        $this->saveSetting($db, 'button_label', trim((string) $this->post('button_label')) ?: 'Chat with us');
        $position = (string) $this->post('position');
        $this->saveSetting($db, 'position', in_array($position, ['bottom-right', 'bottom-left'], true) ? $position : 'bottom-right');
        $buttonColor = trim((string) $this->post('button_color'));
        $this->saveSetting($db, 'button_color', preg_match('/^#[0-9a-fA-F]{3,8}$/', $buttonColor) ? $buttonColor : '#25D366');

        $ids = (array) $this->post('id');
        $labels = (array) $this->post('label');
        $phones = (array) $this->post('phone');
        $weights = (array) $this->post('weight');
        $active = (array) $this->post('isActive');
        $sort = (array) $this->post('sortOrder');
        $delete = (array) $this->post('delete');

        foreach ($phones as $index => $phoneRaw) {
            $id = isset($ids[$index]) ? (int) $ids[$index] : 0;
            if ($id > 0 && in_array((string) $id, $delete, true)) {
                $db->delete('ChatCtaNumbers', ['id' => $id]);
                continue;
            }

            $phone = preg_replace('/[^0-9+]/', '', (string) $phoneRaw);
            $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
            if ($phoneDigits === '') {
                continue;
            }

            $data = [
                'label' => trim((string) ($labels[$index] ?? '')),
                'phone' => $phone,
                'weight' => max(1, (int) ($weights[$index] ?? 1)),
                'isActive' => array_key_exists((string) $index, $active) || array_key_exists($index, $active) ? 1 : 0,
                'sortOrder' => (int) ($sort[$index] ?? 0),
                'updatedAt' => date('Y-m-d H:i:s'),
            ];

            if ($id > 0) {
                $db->update('ChatCtaNumbers', $data, ['id' => $id]);
            } else {
                $data['createdAt'] = date('Y-m-d H:i:s');
                $db->insert('ChatCtaNumbers', $data);
            }
        }
    }
}
