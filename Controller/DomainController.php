<?php

namespace Kanboard\Plugin\Deed\Controller;

use InvalidArgumentException;
use Kanboard\Controller\BaseController;
use Kanboard\Model\TaskModel;
use Kanboard\Plugin\Deed\Client\RdapClient;
use Kanboard\Plugin\Deed\Model\SnapshotModel;

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

    public function show(): void
    {
        $domain = $this->requestedDomain();
        if ($domain !== null) {
            $this->renderDetail($domain);
        }
    }

    public function dns(): void
    {
        $domain = $this->requestedDomain();
        if ($domain !== null) {
            $this->response->html($this->template->render('Deed:domain/dns', [
                'groups' => $this->deedDnsLookup->lookup($domain['name']),
            ]));
        }
    }

    public function edit(): void
    {
        if ($this->request->isPost()) {
            $this->checkCSRFForm();
        }
        $domain = $this->requestedDomain();
        if ($domain === null) {
            return;
        }

        $projects = [0 => t('None')] + ($this->userSession->isAdmin()
            ? $this->projectModel->getList(false, false)
            : $this->projectUserRoleModel->getProjectsByUser($this->userSession->getId()));
        $assignees = [0 => t('Unassigned')] + $this->userModel->getActiveUsersList();
        $currentProjectId = (int) $domain['project_id'];
        $keepProject = !isset($projects[$currentProjectId]);

        if ($this->request->isPost()) {
            $selectedProject = $this->request->getRawValue('project_id');
            $projectId = $this->readId($selectedProject);
            $assigneeId = $this->readId($this->request->getRawValue('assignee_id'));
            if ($keepProject && $selectedProject === 'keep') {
                $projectId = $currentProjectId;
            } elseif ($projectId === null || !isset($projects[$projectId])) {
                $this->response->text(t('Select an accessible project.'), 400);
                return;
            }
            if ($assigneeId === null || !isset($assignees[$assigneeId])) {
                $this->response->text(t('Select an active assignee.'), 400);
                return;
            }
            $this->deedDomainModel->update((int) $domain['id'], $projectId, $assigneeId);
            $this->redirectTo('show', ['domain_id' => $domain['id']]);
            return;
        }

        $this->renderPage('show', t('Edit %s', $domain['name']), [
            'domain' => $domain,
            'view' => 'edit',
            'projects' => $keepProject ? ['keep' => t('Keep current project')] + $projects : $projects,
            'selection' => $keepProject ? array_replace($domain, ['project_id' => 'keep']) : $domain,
            'assignees' => $assignees,
        ]);
    }

    public function remove(): void
    {
        if ($this->request->isPost()) {
            $this->checkCSRFForm();
        }
        $domain = $this->requestedDomain();
        if ($domain === null) {
            return;
        }

        if ($this->request->isPost()) {
            $this->deedDomainModel->remove((int) $domain['id']);
            $this->redirectTo('index');
            return;
        }

        $this->renderPage('show', t('Remove %s', $domain['name']), ['domain' => $domain, 'view' => 'remove']);
    }

    private function readId(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $id === false ? null : $id;
    }

    private function requestedDomain(): ?array
    {
        $id = $this->readId($this->request->getStringParam('domain_id'));
        $domain = $id > 0 ? $this->deedDomainModel->get($id) : null;
        if ($domain === null) {
            $this->response->text(t('Domain not found.'), 404);
        }

        return $domain;
    }

    private function renderDetail(array $domain): void
    {
        $snapshots = $this->deedSnapshotModel->listForDomain((int) $domain['id']);
        $history = [];
        for ($i = 0, $count = count($snapshots); $i < $count; ++$i) {
            $snapshot = $snapshots[$i];
            $history[] = [
                'observed_at' => $snapshot['observed_at'],
                'changes' => isset($snapshots[$i + 1])
                    ? SnapshotModel::diff($snapshots[$i + 1]['data'], $snapshot['data'])
                    : [],
            ];
        }

        $hasRenewalProject = $this->projectModel->exists($domain['project_id'])
            || $this->projectModel->exists((int) $this->configModel->getOption('deed_default_project_id', 0));
        $this->renderPage('show', $domain['name'], [
            'domain' => $domain,
            'view' => 'detail',
            'history' => $history,
            'sources' => $this->sourceUrls($domain),
            'stale' => $this->deedRefreshService->isStale($domain),
            'renewal' => $this->openRenewal((int) $domain['id']),
            'hasRenewalProject' => $hasRenewalProject,
        ]);
    }

    private function sourceUrls(array $domain): array
    {
        $registry = $domain['rdap_url'];
        if ($registry === null) {
            return [];
        }

        // The watchlist stores the registry URL. The cached response also carries its referral.
        $cached = $this->deedCache->get('lookup:domain:'.hash('sha256', $domain['name']));
        $record = json_decode($cached['body'] ?? '', true);
        $sources = [t('Registry RDAP') => $registry];
        if (($record['status'] ?? null) === 'ok' && ($record['rdap_url'] ?? null) === $registry
            && ($record['referral_error'] ?? null) === null && ($record['related_url'] ?? null) !== null) {
            $sources[t('Registrar RDAP')] = $record['related_url'];
        }

        return array_filter($sources, static fn ($url) => is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false && parse_url($url, PHP_URL_SCHEME) === 'https');
    }

    private function openRenewal(int $domainId): ?array
    {
        $statement = $this->db->getConnection()->prepare(
            'SELECT tasks.id, tasks.title, tasks.project_id FROM deed_renewals
             JOIN tasks ON tasks.id = deed_renewals.task_id
             WHERE deed_renewals.domain_id = ? AND deed_renewals.closed_at IS NULL AND tasks.is_active = ?
             ORDER BY deed_renewals.cycle_expires_at DESC'
        );
        $statement->execute([$domainId, TaskModel::STATUS_OPEN]);
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $task) {
            if ($this->helper->user->hasProjectAccess('TaskViewController', 'show', $task['project_id'])) {
                return $task;
            }
        }

        return null;
    }

    private function redirectTo(string $action, array $params = []): void
    {
        $this->response->withStatusCode(303)->redirect(
            $this->helper->url->to('DomainController', $action, $params + ['plugin' => 'Deed'])
        );
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
