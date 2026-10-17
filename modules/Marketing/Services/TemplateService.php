<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use Modules\Marketing\MarketingPermission;
use Modules\Marketing\TemplateRepository;

/** Reusable SMS / email templates (marketing.manage_templates to change; marketing.view to use). */
final class TemplateService
{
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function all(array $user, ?string $channel = null): array
    {
        $this->authorize($user, MarketingPermission::VIEW);

        return $this->templates->all($channel);
    }

    public function find(array $user, int $id): array
    {
        $this->authorize($user, MarketingPermission::VIEW);
        $template = $this->templates->findById($id);
        if ($template === null) {
            throw new NotFoundException('Template not found.');
        }

        return $template;
    }

    public function save(array $user, ?int $id, array $input): int
    {
        $this->authorize($user, MarketingPermission::MANAGE_TEMPLATES);

        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        $channel = (string) ($input['channel'] ?? '');
        $subject = trim((string) ($input['subject'] ?? ''));
        $text = trim(str_replace("\r\n", "\n", (string) ($input['body_text'] ?? '')));
        $html = trim((string) ($input['body_html'] ?? ''));

        if ($name === '' || mb_strlen($name) > 150) {
            $errors['name'][] = 'marketing.error.name_required';
        }
        if (!in_array($channel, ['sms', 'email'], true)) {
            $errors['channel'][] = 'marketing.error.invalid_channel';
        }
        if ($channel === 'sms' && ($text === '' || mb_strlen($text) > CampaignService::SMS_MAX_LENGTH)) {
            $errors['body_text'][] = 'marketing.error.body_required';
        }
        if ($channel === 'email' && $html === '') {
            $errors['body_html'][] = 'marketing.error.body_required';
        }
        if (Personalizer::unknownVariables($subject, $text, $html) !== []) {
            $errors['body_text'][] = 'marketing.error.unknown_variable';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $data = [
            'name' => $name,
            'channel' => $channel,
            'subject' => $channel === 'email' && $subject !== '' ? mb_substr($subject, 0, 255) : null,
            'body_text' => $text !== '' ? $text : null,
            'body_html' => $channel === 'email' && $html !== '' ? $html : null,
        ];

        if ($id === null) {
            return $this->templates->create($data + ['owner_id' => (int) $user['id']]);
        }

        $this->find($user, $id);
        $this->templates->updateTemplate($id, $data);

        return $id;
    }

    public function delete(array $user, int $id): void
    {
        $this->authorize($user, MarketingPermission::MANAGE_TEMPLATES);
        $this->find($user, $id);
        $this->templates->deleteTemplate($id);
    }

    private function authorize(array $user, string $permission): void
    {
        if (!$this->authorization->can($user, $permission)) {
            throw new AuthorizationException();
        }
    }
}
