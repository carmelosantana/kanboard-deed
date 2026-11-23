<?php

namespace Kanboard\Plugin\Deed\Controller;

use InvalidArgumentException;
use Kanboard\Controller\BaseController;
use Kanboard\Plugin\Deed\Client\RdapClient;

class DomainController extends BaseController
{
    public function index(): void
    {
        $this->renderDomains();
    }

    public function add(): void
    {
        $domain = '';
        $error = '';
        if ($this->request->isPost()) {
            $this->checkCSRFForm();
            $input = $this->request->getRawValue('domain');
            $domain = is_string($input) ? $input : '';

            try {
                $id = $this->deedDomainModel->create($domain, $this->userSession->getId());
                if ($id !== null) {
                    $this->renderDomains(t('Domain added.'));
                    return;
                }
                $error = t('This domain is already on the watchlist.');
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage() === 'idn_unsupported'
                    ? t('Enter internationalized domain names in Punycode.')
                    : t('Enter a valid domain name.');
            }
        }

        $this->renderPage('create', t('Add domain'), ['domain' => $domain, 'error' => $error]);
    }

    public function bulk(): void
    {
        $result = null;
        if ($this->request->isPost()) {
            $this->checkCSRFForm();
            $input = $this->request->getRawValue('domains');
            $result = $this->deedDomainModel->bulkAdd(is_string($input) ? $input : '', $this->userSession->getId());
        }

        $this->renderPage('bulk', t('Bulk add'), ['result' => $result]);
    }

    public function refresh(): void
    {
        if (!$this->request->isPost()) {
            $this->response->withHeader('Allow', 'POST')->text(t('Use POST to refresh a domain.'), 405);
            return;
        }
        $this->checkCSRFForm();

        $id = $this->request->getRawValue('domain_id');
        if ((!is_string($id) && !is_int($id)) || !ctype_digit((string) $id) || (int) $id < 1) {
            $this->response->text(t('Domain not found.'), 404);
            return;
        }

        $updated = $this->deedRefreshService->refresh((int) $id);
        $message = match ($updated['status'] ?? null) {
            'ok' => t('Domain refreshed.'),
            'not_found' => t('The registry reports that this domain is not registered.'),
            'unsupported' => t('RDAP is unavailable for this domain.'),
            'error' => t('Domain refresh failed.'),
            default => t('The refresh could not be applied.'),
        };
        $this->renderDomains($message);
    }

    private function renderDomains(?string $message = null): void
    {
        $domains = $this->deedDomainModel->listAll();
        $projects = array_column(
            $this->projectModel->getAllByIds(array_unique(array_filter(array_column($domains, 'project_id')))),
            'name',
            'id'
        );
        $today = intdiv(time(), 86400);
        foreach ($domains as &$domain) {
            $expiry = RdapClient::effectiveExpiry($domain['registry_expires_at'], $domain['registrar_expires_at']);
            $domain['expiry'] = $expiry;
            $domain['days_left'] = $expiry === null ? null : intdiv($expiry, 86400) - $today;
            $domain['stale'] = $this->deedRefreshService->isStale($domain);
        }
        unset($domain);

        $this->renderPage('index', t('Domains'), compact('domains', 'projects', 'message'));
    }

    private function renderPage(string $template, string $title, array $params): void
    {
        $this->response->html($this->helper->layout->dashboard('Deed:domain/'.$template, $params + [
            'title' => $title,
            'user' => $this->userSession->getAll(),
        ]));
    }
}
