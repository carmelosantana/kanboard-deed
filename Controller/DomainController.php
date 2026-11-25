<?php

namespace Kanboard\Plugin\Deed\Controller;

use Kanboard\Core\Security\Role;
use InvalidArgumentException;
use Kanboard\Controller\BaseController;
use Kanboard\Plugin\Deed\Client\RdapClient;
use Kanboard\Plugin\Deed\Model\SnapshotModel;

class DomainController extends BaseController
{
    private const BULK_RESULT_FLASH = 'deed_bulk_result';

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
                    $this->flash->success(t('Domain added.'));
                    $this->redirectTo('index');
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
        $input = '';
        $error = '';
        if ($this->request->isPost()) {
            $this->checkCSRFForm();
            $raw = $this->request->getRawValue('domains');
            $input = is_string($raw) ? $raw : '';
            $result = $this->deedDomainModel->bulkAdd($input, $this->userSession->getId());
            if ($result['added'] !== [] || $result['existing'] !== []) {
                $this->flash->setMessage(self::BULK_RESULT_FLASH, $result);
                $this->redirectTo('bulk');
                return;
            }
            $error = t('Enter at least one valid domain name.');
        } else {
            $result = $this->flash->getMessage(self::BULK_RESULT_FLASH) ?: null;
        }

        $this->renderPage('bulk', t('Bulk add'), compact('result', 'input', 'error'));
    }

    public function refresh(): void
    {
        if (!$this->request->isPost()) {
            $this->response->withHeader('Allow', 'POST')->text(t('Use POST to refresh a domain.'), 405);
            return;
        }
        $this->checkCSRFForm();

        $id = $this->readId($this->request->getRawValue('domain_id'));
        if ($id === null || $id < 1) {
            $this->response->text(t('Domain not found.'), 404);
            return;
        }

        // A refresh evaluates the renewal, which creates, comments on and closes tasks in the linked project
        // (or the default one): do not let a user without write access there trigger that.
        $domain = $this->deedDomainModel->get($id);
        if ($domain !== null && ! $this->canWriteRenewalProject($domain, $this->userSession->getId())) {
            $this->response->text(t('You cannot refresh a domain whose project you cannot write to.'), 403);
            return;
        }

        $updated = $this->deedRefreshService->refresh($id);
        if ($updated === null && $this->deedDomainModel->get($id) === null) {
            $this->response->text(t('Domain not found.'), 404);
            return;
        }

        match ($updated['status'] ?? null) {
            'ok' => $this->flash->success(t('Domain refreshed.')),
            'not_found' => $this->flash->success(t('Not registered')),
            'unsupported' => $this->flash->failure(t('RDAP is unavailable for this domain.')),
            'error' => $this->flash->failure(t('Domain refresh failed.')),
            default => $this->flash->failure(t('The refresh could not be applied.')),
        };
        $this->redirectTo('index');
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
            : $this->writableProjects($this->userSession->getId()));
        $assignees = [0 => t('Unassigned')] + $this->userModel->getActiveUsersList();
        $currentProjectId = (int) $domain['project_id'];
        $keepProject = !isset($projects[$currentProjectId]);
        $selection = [
            'project_id' => $keepProject ? 'keep' : $currentProjectId,
            'assignee_id' => $domain['assignee_id'],
        ];
        $error = null;

        if ($this->request->isPost()) {
            $selectedProject = $this->request->getRawValue('project_id');
            $projectId = $keepProject && $selectedProject === 'keep' ? $currentProjectId : $this->readId($selectedProject);
            $assigneeId = $this->readId($this->request->getRawValue('assignee_id'));
            // Keeping the linked project still needs write access to it: it is where renewal tasks are written.
            $validProject = $projectId !== null && (isset($projects[$projectId])
                || ($keepProject && $selectedProject === 'keep' && $this->canWriteProject($currentProjectId, $this->userSession->getId())));
            $validAssignee = $assigneeId !== null && isset($assignees[$assigneeId]);
            // Renewal tasks go to this project: their owner must be someone the project lets hold a task.
            $memberAssignee = ! $validProject || ! $validAssignee || $assigneeId === 0 || $projectId === 0
                || $this->projectPermissionModel->isAssignable($projectId, $assigneeId);

            if ($validProject && $validAssignee && $memberAssignee) {
                $this->deedDomainModel->update((int) $domain['id'], $projectId, $assigneeId);
                $this->redirectTo('show', ['domain_id' => $domain['id']]);
                return;
            }

            $error = ! $validProject ? t('Select an accessible project.')
                : (! $validAssignee ? t('Select an active assignee.') : t('Select an assignee who is a member of that project.'));
            if ($validProject) {
                $selection['project_id'] = $selectedProject === 'keep' ? 'keep' : $projectId;
            }
            if ($validAssignee) {
                $selection['assignee_id'] = $assigneeId;
            }
        }

        $this->renderPage('show', t('Edit %s', $domain['name']), [
            'domain' => $domain,
            'view' => 'edit',
            'projects' => $keepProject ? ['keep' => t('Keep current project')] + $projects : $projects,
            'selection' => $selection,
            'assignees' => $assignees,
            'error' => $error,
        ], $error === null ? 200 : 400);
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

    /**
     * Projects where the user may write tasks: Deed creates, comments on and closes renewal tasks
     * in the chosen project, so a project where the user is a viewer or holds a custom role is left out.
     */
    private function writableProjects(int $userId): array
    {
        return array_filter(
            $this->projectUserRoleModel->getProjectsByUser($userId),
            fn ($name, $projectId) => $this->hasWriteRole((int) $projectId, $userId),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function hasWriteRole(int $projectId, int $userId): bool
    {
        // Custom project roles can forbid creating or closing tasks, which Deed does through models:
        // only the built-in roles are known to allow it. getUserRole merges group-derived roles.
        return in_array($this->projectUserRoleModel->getUserRole($projectId, $userId), [Role::PROJECT_MANAGER, Role::PROJECT_MEMBER], true);
    }

    /** Same rule as the edit form's project list: app admins may write anywhere, others need a built-in manager or member role. */
    private function canWriteProject(int $projectId, int $userId): bool
    {
        return $this->userSession->isAdmin() || $this->hasWriteRole($projectId, $userId);
    }

    /** The project a refresh would write renewal tasks to, if any: the linked one, else the default. */
    private function canWriteRenewalProject(array $domain, int $userId): bool
    {
        $projectId = (int) $domain['project_id'];
        if (! $this->projectModel->exists($projectId)) {
            $projectId = (int) $this->configModel->getOption('deed_default_project_id', 0);
            if (! $this->projectModel->exists($projectId)) {
                return true;
            }
        }

        return $this->canWriteProject($projectId, $userId);
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
            'renewal' => $this->deedRenewalService->openRenewalFor(
                (int) $domain['id'],
                fn (int $projectId): bool => $this->helper->user->hasProjectAccess('TaskViewController', 'show', $projectId)
            ),
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
        $record = $this->deedRdap->cachedDomainSource($domain['name']);
        $sources = [t('Registry RDAP') => $registry];
        if (($record['rdap_url'] ?? null) === $registry && ($record['related_url'] ?? null) !== null) {
            $sources[t('Registrar RDAP')] = $record['related_url'];
        }

        return array_filter($sources, static fn ($url) => is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false && parse_url($url, PHP_URL_SCHEME) === 'https');
    }

    private function redirectTo(string $action, array $params = []): void
    {
        $this->response->withStatusCode(303)->redirect(
            $this->helper->url->to('DomainController', $action, $params + ['plugin' => 'Deed'])
        );
    }

    private function renderDomains(): void
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

        $this->renderPage('index', t('Domains'), compact('domains', 'projects'));
    }

    private function renderPage(string $template, string $title, array $params, int $status = 200): void
    {
        $this->response->html($this->helper->layout->dashboard('Deed:domain/'.$template, $params + [
            'title' => $title,
            'user' => $this->userSession->getAll(),
        ]), $status);
    }
}
