<?php

namespace Kanboard\Plugin\Deed\Controller;

use InvalidArgumentException;
use Kanboard\Controller\BaseController;
use Kanboard\Plugin\Deed\Model\DomainModel;

class LookupController extends BaseController
{
    public function index(): void
    {
        $query = '';
        $result = null;
        $message = null;
        $httpStatus = 200;

        if ($this->request->isPost()) {
            $this->checkCSRFForm();
            $input = $this->request->getRawValue('query');
            $query = is_string($input) ? trim($input) : '';

            try {
                [$type, $value] = $this->classify($query);
                $record = match ($type) {
                    'domain' => $this->deedRdap->lookupDomain($value),
                    'network' => $this->deedRdap->lookupIp($value),
                    'autnum' => $this->deedRdap->lookupAsn($value),
                };
                $result = [
                    'type' => $type,
                    'value' => $value,
                    'record' => $record,
                    'canAdd' => $type === 'domain' && !in_array(
                        $value,
                        array_column($this->deedDomainModel->listAll(), 'name'),
                        true
                    ),
                ];
                $message = $this->resultMessage($type, $value, $record);
            } catch (InvalidArgumentException $exception) {
                $httpStatus = 400;
                $message = $exception->getMessage() === 'idn_unsupported'
                    ? t('Enter internationalized domain names in Punycode.')
                    : t('Enter a domain name, IP address or ASN.');
            }
        }

        $this->response->html($this->helper->layout->dashboard('Deed:lookup/index', [
            'title' => t('Lookup'),
            'user' => $this->userSession->getAll(),
            'query' => $query,
            'result' => $result,
            'message' => $message,
        ]), $httpStatus);
    }

    private function classify(string $query): array
    {
        if (filter_var($query, FILTER_VALIDATE_IP) !== false) {
            return ['network', $query];
        }

        if (preg_match('/\A(?:AS)?([0-9]+)\z/i', $query, $parts) && (int) $parts[1] <= 4294967295) {
            return ['autnum', 'AS'.(int) $parts[1]];
        }

        $domain = DomainModel::normalise($query);
        if (preg_match('/\.[0-9]+\z/', $domain)) {
            throw new InvalidArgumentException('invalid_domain');
        }

        return ['domain', $domain];
    }

    private function resultMessage(string $type, string $value, array $record): ?string
    {
        return match ($record['status']) {
            'ok' => null,
            'not_found' => $type === 'domain'
                ? t('The registry reports that this domain is not registered.')
                : t('The registry returned no RDAP record.'),
            'unsupported' => match ($type) {
                'domain' => t('No RDAP service is listed for %s', strrchr($value, '.')),
                'network' => t('No RDAP service is listed for this IP address'),
                'autnum' => t('No RDAP service is listed for this ASN'),
            },
            default => ($record['error'] ?? null) === 'bootstrap_unavailable'
                ? t('RDAP directory unavailable')
                : t('RDAP lookup failed.'),
        };
    }
}
