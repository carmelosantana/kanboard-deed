<?php

namespace Kanboard\Plugin\Deed\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\Deed\Client\Bootstrap;
use RuntimeException;

class DeedSettingsController extends BaseController
{
    // Kanboard's PostgreSQL settings.value column is VARCHAR(255).
    private const MAX_THRESHOLD_LENGTH = 255;

    private const DEFAULTS = [
        'deed_default_project_id' => '0',
        'deed_thresholds' => '30,14,7',
        'deed_refresh_hour' => '6',
        'deed_batch_size' => '25',
    ];

    public function index(): void
    {
        $values = array_intersect_key($this->configModel->getAll(), self::DEFAULTS);
        $this->renderSettings($values + self::DEFAULTS, $this->projectOptions());
    }

    public function save(): void
    {
        if (!$this->checkPost()) {
            return;
        }

        $projects = $this->projectOptions();
        $values = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $input = $this->request->getRawValue($key);
            $values[$key] = is_string($input) || is_int($input) ? trim((string) $input) : '';
        }

        $errors = [];
        $projectId = $this->integerInRange($values['deed_default_project_id'], 0, PHP_INT_MAX);
        if ($projectId === false || !isset($projects[$projectId])) {
            $errors['deed_default_project_id'][] = t('Select an existing project or None.');
        }
        if (strlen($values['deed_thresholds']) > self::MAX_THRESHOLD_LENGTH) {
            $errors['deed_thresholds'][] = t('Enter no more than %d characters.', self::MAX_THRESHOLD_LENGTH);
        } elseif (!$this->validThresholds($values['deed_thresholds'])) {
            $errors['deed_thresholds'][] = t('Enter positive whole numbers in descending order, separated by commas.');
        }
        if ($this->integerInRange($values['deed_refresh_hour'], 0, 23) === false) {
            $errors['deed_refresh_hour'][] = t('Enter a whole number from 0 to 23.');
        }
        if ($this->integerInRange($values['deed_batch_size'], 1, 200) === false) {
            $errors['deed_batch_size'][] = t('Enter a whole number from 1 to 200.');
        }

        if ($errors !== []) {
            $this->renderSettings($values, $projects, $errors);
            return;
        }

        if ($this->configModel->save($values)) {
            $this->flash->success(t('Settings saved successfully.'));
        } else {
            $this->flash->failure(t('Unable to save your settings.'));
        }
        $this->redirectToSettings();
    }

    public function refreshBootstrap(): void
    {
        if (!$this->checkPost()) {
            return;
        }

        // A lookup earlier in this request can leave the shared instance pinned to a failure.
        $bootstrap = new Bootstrap($this->deedHttp, $this->deedCache);
        $lookups = [
            'dns' => fn () => $bootstrap->baseUrlsForDomain('com'),
            'ipv4' => fn () => $bootstrap->baseUrlsForIp('8.8.8.8'),
            'ipv6' => fn () => $bootstrap->baseUrlsForIp('2001:4860:4860::8888'),
            'asn' => fn () => $bootstrap->baseUrlsForAsn(15169),
        ];
        $unavailable = [];
        foreach ($lookups as $kind => $lookup) {
            // Expire the cached copy so Bootstrap can revalidate with its saved validators.
            $this->db->table('deed_cache')->eq('cache_key', 'bootstrap:'.$kind)->update(['expires_at' => 0]);
            try {
                $lookup();
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'bootstrap_unavailable') {
                    throw $exception;
                }
                $unavailable[] = $kind.'.json';
            }
        }

        if ($unavailable === []) {
            $this->flash->success(t('Bootstrap refresh complete. Previously cached data is used if a file could not be refreshed.'));
        } else {
            $this->flash->failure(t('RDAP bootstrap files unavailable: %s', implode(', ', $unavailable)));
        }
        $this->redirectToSettings();
    }

    private function checkPost(): bool
    {
        if (!$this->request->isPost()) {
            $this->response->withHeader('Allow', 'POST')->text(t('Use POST for this action.'), 405);
            return false;
        }

        $this->checkCSRFForm();
        return true;
    }

    private function integerInRange(string $value, int $min, int $max): int|false
    {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    }

    private function validThresholds(string $value): bool
    {
        $maximum = PHP_INT_MAX;
        foreach (explode(',', $value) as $part) {
            $threshold = $this->integerInRange(trim($part), 1, $maximum);
            if ($threshold === false) {
                return false;
            }
            $maximum = $threshold - 1;
        }
        return true;
    }

    private function projectOptions(): array
    {
        return [0 => t('None')] + $this->projectModel->getList(false, false);
    }

    private function renderSettings(array $values, array $projects, array $errors = []): void
    {
        $this->response->html($this->helper->layout->config('Deed:config/settings', [
            'title' => t('Deed'),
            'values' => $values,
            'projects' => $projects,
            'errors' => $errors,
        ]), $errors === [] ? 200 : 400);
    }

    private function redirectToSettings(): void
    {
        $this->response->withStatusCode(303)->redirect(
            $this->helper->url->to('DeedSettingsController', 'index', ['plugin' => 'Deed'])
        );
    }
}
