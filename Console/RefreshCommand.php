<?php

namespace Kanboard\Plugin\Deed\Console;

use InvalidArgumentException;
use Kanboard\Console\BaseCommand;
use Kanboard\Plugin\Deed\Model\DomainModel;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RefreshCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('deed:refresh')
            ->setDescription('Refresh due domains on the Deed watchlist')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Refresh one watched domain by name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domain = $input->getOption('domain');
        if ($domain === null) {
            $counts = $this->deedRefreshService->sweep();
        } else {
            try {
                $name = DomainModel::normalise($domain);
            } catch (InvalidArgumentException) {
                $output->writeln('<error>Invalid domain name.</error>');
                return self::FAILURE;
            }

            $id = $this->db->table('deed_domains')->eq('name', $name)->findOneColumn('id');
            if ($id === false) {
                $output->writeln("<error>Domain is not on the watchlist: {$name}</error>");
                return self::FAILURE;
            }

            $result = $this->deedRefreshService->refresh((int) $id);
            $counts = ['refreshed' => 0, 'failed' => 0, 'skipped' => 0];
            ++$counts[match ($result['status'] ?? null) {
                'ok', 'not_found' => 'refreshed',
                'error' => 'failed',
                default => 'skipped',
            }];
        }

        $output->writeln(sprintf(
            'Refreshed %d, failed %d, skipped %d',
            $counts['refreshed'],
            $counts['failed'],
            $counts['skipped']
        ));

        return self::SUCCESS;
    }
}
