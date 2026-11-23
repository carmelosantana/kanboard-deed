<?php

namespace Kanboard\Plugin\Deed\Test;

use Kanboard\Core\Controller\Runner;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Security\Role;
use Kanboard\Middleware\ApplicationAuthorizationMiddleware;
use Kanboard\Plugin\Deed\Client\HttpFetcher;
use Kanboard\Plugin\Deed\Client\HttpResponse;
use Kanboard\Plugin\Deed\Plugin;
use KanboardTests\units\Base;
use function Kanboard\Plugin\Deed\Schema\version_1;

require_once 'tests/units/Base.php';
require_once __DIR__.'/ReleasesConnection.php';

class DomainControllerTest extends Base
{
    use ReleasesConnection;

    private const DOMAIN = 'madeinnewburgh.com';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        version_1($this->container['db']->getConnection());
        $this->container['route']->enable();
        (new Plugin($this->container))->initialize();

        $this->container['configModel']->save(['deed_last_sweep' => date('Y-m-d')]);
        $fetcher = $this->createMock(HttpFetcher::class);
        $fetcher->expects($this->never())->method('fetch');
        $this->container['deedHttp'] = $fetcher;
    }

    /** @dataProvider roleRoutes */
    public function testRoutePermissions(string $role, string $path, int $status): void
    {
        $post = $path === 'deed/domains/refresh'
            ? ['domain_id' => $this->container['deedDomainModel']->create(self::DOMAIN, 1)]
            : null;

        if ($post !== null && $status === 303) {
            $this->allowRecordedRefresh();
        }

        [$actual, $body] = $this->dispatch($path, $role, $post);
        $this->assertSame($status, $actual);
        if ($status === 200) {
            $this->assertStringContainsString('>Domains</a>', $body);
            $this->assertStringContainsString('Assets/css/deed.css', $body);
            $this->assertStringContainsString('Assets/js/deed.js', $body);
        } elseif ($status === 403) {
            $this->assertStringNotContainsString('>Domains</a>', $body);
        }
    }

    public static function roleRoutes(): iterable
    {
        foreach ([Role::APP_ADMIN => 200, Role::APP_MANAGER => 200, Role::APP_USER => 403] as $role => $status) {
            foreach (['', '/add', '/bulk', '/refresh'] as $suffix) {
                yield $role.$suffix => [$role, 'deed/domains'.$suffix, $status === 200 && $suffix === '/refresh' ? 303 : $status];
            }
        }
    }

    /** @dataProvider writeRoutes */
    public function testPostRejectsMissingAndInvalidCsrf(string $path, array $post, ?string $token): void
    {
        $post['csrf_token'] = $token;
        [$status] = $this->dispatch($path, Role::APP_MANAGER, $post);
        $this->assertSame(403, $status);
        $this->assertSame([], $this->container['deedDomainModel']->listAll());
    }

    public static function writeRoutes(): iterable
    {
        foreach ([null, 'invalid'] as $token) {
            yield ['deed/domains/add', ['domain' => 'example.com'], $token];
            yield ['deed/domains/bulk', ['domains' => 'example.com'], $token];
            yield ['deed/domains/refresh', ['domain_id' => 1], $token];
        }
    }

    public function testAddNormalisesAndPersistsTheDomain(): void
    {
        $this->assertRedirectTo('index', $this->dispatch('deed/domains/add', Role::APP_ADMIN, [
            'domain' => 'HTTPS://Example.COM/path',
        ]));
        [, $body] = $this->dispatch('deed/domains', Role::APP_ADMIN);
        $this->assertStringContainsString('Domain added.', $body);
        [, $reloaded] = $this->dispatch('deed/domains', Role::APP_ADMIN);
        $this->assertStringNotContainsString('Domain added.', $reloaded);
        $domains = $this->container['deedDomainModel']->listAll();
        $this->assertSame(['example.com'], array_column($domains, 'name'));
        $this->assertSame(1, (int) $domains[0]['created_by']);
        $this->assertSame('pending', $domains[0]['status']);
    }

    public function testBulkReportsAddedExistingAndInvalidCounts(): void
    {
        $domains = $this->container['deedDomainModel'];
        $domains->create('existing.com', 1);

        $this->assertRedirectTo('bulk', $this->dispatch('deed/domains/bulk', Role::APP_MANAGER, [
            'domains' => "new.com\nNEW.com.\nsecond.com\nexisting.com\n<script>",
        ]));

        [$status, $body] = $this->dispatch('deed/domains/bulk', Role::APP_MANAGER);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Added: 2', $body);
        $this->assertStringContainsString('Existing: 1', $body);
        $this->assertStringContainsString('Invalid: 1', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
        $this->assertStringContainsString('Paste one domain per line', $body);
        $this->assertSame(['existing.com', 'new.com', 'second.com'], array_column($domains->listAll(), 'name'));
        [, $reloaded] = $this->dispatch('deed/domains/bulk', Role::APP_MANAGER);
        $this->assertStringNotContainsString('Added: 2', $reloaded);
    }

    public function testValidationFailuresRenderTheFormsWithoutRedirecting(): void
    {
        foreach ([
            ['add', ['domain' => 'bad_name'], 'Enter a valid domain name.'],
            ['bulk', ['domains' => ' '], 'Enter at least one valid domain name.'],
            ['bulk', ['domains' => 'bad_name'], 'Enter at least one valid domain name.'],
        ] as [$action, $input, $message]) {
            [$status, $body, $location] = $this->dispatch('deed/domains/'.$action, Role::APP_MANAGER, $input);
            $this->assertSame(200, $status);
            $this->assertNull($location);
            $this->assertStringContainsString('<form method="post"', $body);
            $this->assertStringContainsString($message, $body);
        }
        $this->assertSame([], $this->container['deedDomainModel']->listAll());
    }

    public function testRefreshNowUpdatesOnlyTheRequestedDomain(): void
    {
        $domains = $this->container['deedDomainModel'];
        $other = $domains->create('other.com', 1);
        $target = $domains->create(self::DOMAIN, 1);
        $this->allowRecordedRefresh();

        $this->assertRedirectTo('index', $this->dispatch('deed/domains/refresh', Role::APP_MANAGER, ['domain_id' => $target]));

        [, $body] = $this->dispatch('deed/domains', Role::APP_MANAGER);
        $this->assertStringContainsString('Domain refreshed.', $body);
        [, $reloaded] = $this->dispatch('deed/domains', Role::APP_MANAGER);
        $this->assertStringNotContainsString('Domain refreshed.', $reloaded);
        $this->assertSame('ok', $domains->get($target)['status']);
        $this->assertSame('pending', $domains->get($other)['status']);
        $this->assertNull($domains->get($other)['last_checked_at']);
    }

    public function testGetCannotTriggerARefresh(): void
    {
        $this->container['deedDomainModel']->create(self::DOMAIN, 1);
        [$status] = $this->dispatch('deed/domains/refresh', Role::APP_MANAGER);
        $this->assertSame(405, $status);
    }

    public function testRefreshReturnsNotFoundForAnUnknownDomainId(): void
    {
        [$status, $body, $location] = $this->dispatch('deed/domains/refresh', Role::APP_MANAGER, ['domain_id' => '123']);

        $this->assertSame(404, $status);
        $this->assertStringContainsString('Domain not found.', $body);
        $this->assertNull($location);
    }

    public function testListAndDetailShowRegistrationStatuses(): void
    {
        $domains = $this->container['deedDomainModel'];
        $statement = $this->container['db']->getConnection()->prepare(
            'UPDATE deed_domains SET status = ?, last_error = ? WHERE id = ?'
        );
        $missing = $domains->create('missing.com', 1);
        $failed = $domains->create('failed.com', 1);
        $referral = $domains->create('referral.com', 1);
        $healthy = $domains->create('healthy.com', 1);
        $statement->execute(['not_found', null, $missing]);
        $statement->execute(['error', 'RDAP "timeout" <details>', $failed]);
        $statement->execute(['ok', 'http_503', $referral]);
        $statement->execute(['ok', null, $healthy]);

        [, $list] = $this->dispatch('deed/domains', Role::APP_MANAGER);
        $badge = '<span class="deed-badge deed-badge--red">Not registered</span>';
        $this->assertStringContainsString($badge, $list);
        $this->assertStringNotContainsString('>Not found<', $list);
        $escapedError = 'RDAP &quot;timeout&quot; &lt;details&gt;';
        $this->assertStringContainsString('title="'.$escapedError.'">Error</span>', $list);
        $this->assertSame(1, substr_count($list, 'Registrar data unavailable'));

        [, $detail] = $this->dispatch('deed/domains/show/'.$missing, Role::APP_MANAGER);
        $this->assertStringContainsString($badge, $detail);
        [, $detail] = $this->dispatch('deed/domains/show/'.$failed, Role::APP_MANAGER);
        $this->assertMatchesRegularExpression('~>Error</span>\s*<div>'.preg_quote($escapedError, '~').'</div>~', $detail);
        $this->assertStringNotContainsString('Registrar data unavailable', $detail);
        [, $detail] = $this->dispatch('deed/domains/show/'.$referral, Role::APP_MANAGER);
        $this->assertStringContainsString('Registrar data unavailable', $detail);
        [, $detail] = $this->dispatch('deed/domains/show/'.$healthy, Role::APP_MANAGER);
        $this->assertStringNotContainsString('Registrar data unavailable', $detail);
    }

    public function testTableMarksExpiryThresholdsAndStaleRecords(): void
    {
        $domains = $this->container['deedDomainModel'];
        $pdo = $this->container['db']->getConnection();
        $statement = $pdo->prepare(
            'UPDATE deed_domains SET registry_expires_at = ?, last_success_at = ?, last_checked_at = ? WHERE id = ?'
        );
        $now = strtotime('today UTC');
        $ids = [];
        foreach ([7 => 'red.com', 8 => 'amber-low.com', 30 => 'amber-high.com', 31 => 'neutral.com'] as $days => $name) {
            $ids[$name] = $domains->create($name, 1);
            $success = $days === 7 ? $now - 4 * 86400 : time();
            $statement->execute([$now + $days * 86400, $success, time(), $ids[$name]]);
        }

        [$status, $body] = $this->dispatch('deed/domains', Role::APP_MANAGER);

        $this->assertSame(200, $status);
        $link = $this->container['helper']->url->link('red.com', 'DomainController', 'show', [
            'plugin' => 'Deed', 'domain_id' => $ids['red.com'],
        ]);
        $this->assertStringContainsString($link, $body);
        $this->assertMatchesRegularExpression('~red\.com(?:(?!</tr>).)*deed-badge--red(?:(?!</tr>).)*deed-stale~s', $body);
        foreach (['amber-low', 'amber-high'] as $name) {
            $this->assertMatchesRegularExpression('~'.$name.'\.com(?:(?!</tr>).)*deed-badge--amber~s', $body);
        }
        $this->assertDoesNotMatchRegularExpression('~neutral\.com(?:(?!</tr>).)*deed-badge--(?:amber|red)~s', $body);
        $this->assertSame(1, substr_count($body, 'deed-stale'));
    }

    private function allowRecordedRefresh(): void
    {
        $fixtures = [
            'https://data.iana.org/rdap/dns.json' => 'iana-dns.json',
            'https://rdap.verisign.com/com/v1/domain/'.self::DOMAIN => 'verisign-com.json',
            'https://cart-before.porkbun.horse/rdap/domain/MADEINNEWBURGH.COM' => 'porkbun-referral.json',
        ];
        $fetcher = $this->createMock(HttpFetcher::class);
        $fetcher->expects($this->exactly(count($fixtures)))->method('fetch')
            ->willReturnCallback(static function (string $url) use ($fixtures): HttpResponse {
                return new HttpResponse(200, file_get_contents(__DIR__.'/fixtures/'.$fixtures[$url]));
            });
        $this->container['deedHttp'] = $fetcher;
    }

    private function assertRedirectTo(string $action, array $response): void
    {
        [$status, , $location] = $response;
        $this->assertSame(303, $status);
        $this->assertSame($this->container['helper']->url->to('DomainController', $action, ['plugin' => 'Deed']), $location);
    }

    private function dispatch(string $path, string $role, ?array $post = null): array
    {
        $user = $this->container['userModel']->getById(1);
        $user['role'] = $role;
        $this->container['userSession']->initialize($user);
        if ($post !== null && !array_key_exists('csrf_token', $post)) {
            $post['csrf_token'] = $this->container['token']->getCSRFToken();
        }
        $this->container['request'] = new Request($this->container, [
            'REQUEST_URI' => '/'.$path,
            'REQUEST_METHOD' => $post === null ? 'GET' : 'POST',
            'SCRIPT_NAME' => '/index.php',
        ], [], $post ?? []);
        $response = new class($this->container) extends Response {
            public int $code = 200;
            public string $body = '';
            public ?string $location = null;

            public function withHeader($header, $value)
            {
                if ($header === 'Location') {
                    $this->location = $value;
                }
                return parent::withHeader($header, $value);
            }

            public function withStatusCode($statusCode)
            {
                $this->code = $statusCode;
                return parent::withStatusCode($statusCode);
            }

            public function withBody($body)
            {
                $this->body = $body;
                return parent::withBody($body);
            }

            public function send()
            {
            }
        };
        $this->container['response'] = $response;

        $router = $this->container['router'];
        $router->dispatch();
        $this->assertSame('Deed', $router->getPlugin());

        $runner = new class($this->container) extends Runner {
            protected function executeMiddleware()
            {
                // The test session is already authenticated.
                (new ApplicationAuthorizationMiddleware($this->container))->execute();
            }
        };
        $runner->execute();

        return [$response->code, $response->body, $response->location];
    }
}
