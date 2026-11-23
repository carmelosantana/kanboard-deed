<?php

namespace Kanboard\Plugin\Deed\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Deed\Client\RdapClient;

class ProjectDomainsController extends BaseController
{
    public function index(): void
    {
        $projectId = $this->request->getIntegerParam('project_id');
        if ($projectId < 1) {
            $this->response->text(t('Page not found'), 404);
            return;
        }
        if (!$this->projectPermissionModel->isMember($projectId, $this->userSession->getId())) {
            throw new AccessForbiddenException();
        }

        $project = $this->getProject();
        $domains = $this->deedDomainModel->listForProject($projectId);
        foreach ($domains as &$domain) {
            $domain['expiry'] = RdapClient::effectiveExpiry($domain['registry_expires_at'], $domain['registrar_expires_at']);
        }
        unset($domain);

        $this->response->html($this->helper->layout->project('Deed:project/domains', [
            'title' => t('Domains'),
            'project' => $project,
            'domains' => $domains,
        ]));
    }
}
